<?php
/**
 * SuperadminTest
 *
 * Two-level administration (ADMIN.md), without database:
 * - a superadmin is an admin holding every user scope in every application,
 *   member or not; the flag is neither fillable nor taken from the API;
 * - creating, deleting an application and reading the log are superadmin
 *   routes, and the package routes they replace are no longer used;
 * - every request of a superadmin is recorded once answered, with the details
 *   its controller noted; other callers are not recorded.
 */

use App\Application as GandalfApplication;
use App\Http\Middleware\SuperadminAuditMiddleware;
use App\Http\Middleware\SuperadminMiddleware;
use App\Models\User;
use Helper\AclAuth;
use Helper\FakeApplication;
use Helper\MemoryAdminLog;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Nebo15\LumenApplicationable\Exceptions\AccessDeniedException;
use Nebo15\LumenApplicationable\Models\Application;
use Nebo15\LumenApplicationable\Models\User as ApplicationUser;

class SuperadminTest extends \Codeception\TestCase\Test
{
    const USER_ID = '0123456789abcdef01234567';

    const APPLICATION_ID = '76543210fedcba9876543210';

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../bootstrap/app.php';
        require_once __DIR__ . '/../_support/Helper/AclDoubles.php';
        require_once __DIR__ . '/../_support/Helper/SuperadminDoubles.php';
    }

    protected function tearDown(): void
    {
        app()->offsetUnset(Application::class);
        app()->offsetUnset(GandalfApplication::ROUTED_REQUEST);
    }

    private function user($superadmin = false)
    {
        $user = new User;
        $user->setAttribute('_id', self::USER_ID);
        $user->setAttribute('email', 'someone@example.test');
        $user->setAttribute('username', 'someone');
        if ($superadmin) {
            $user->setAttribute('superadmin', true);
        }

        return $user;
    }

    private function application(ApplicationUser $member = null)
    {
        $application = new FakeApplication;
        $application->member = $member;

        return $application;
    }

    private function member(array $scopes, $role = 'user')
    {
        return new ApplicationUser(['user_id' => self::USER_ID, 'role' => $role, 'scope' => $scopes]);
    }

    public function testAUserIsWhatTheApplicationSays()
    {
        $member = $this->member(['tables_view']);

        $this->assertSame($member, $this->user()->memberOf($this->application($member)));
        $this->assertNull($this->user()->memberOf($this->application()));
    }

    public function testASuperadminIsAnAdminOfEveryApplication()
    {
        $entry = $this->user(true)->memberOf($this->application());

        $this->assertTrue($entry->isAdmin());
        $this->assertSame(config('applicationable.scopes.users'), $entry->scope);
        $this->assertTrue($entry->canUsers_manage());
        $this->assertTrue($entry->superadmin);
        $this->assertFalse($entry->member);
        $this->assertSame(self::USER_ID, (string) $entry->user_id);
    }

    public function testASuperadminMemberHoldsEveryScopeToo()
    {
        $entry = $this->user(true)->memberOf($this->application($this->member(['tables_view'])));

        $this->assertTrue($entry->isAdmin());
        $this->assertTrue($entry->canTables_delete());
        $this->assertTrue($entry->member);
    }

    public function testTheCurrentApplicationEntryFollows()
    {
        $user = $this->user(true);
        $user->setCurrentApplication($this->application())->getAndSetApplicationUser();

        $this->assertTrue($user->getApplicationUser()->isAdmin());
    }

    public function testTheFlagIsNotFillable()
    {
        $user = (new User)->fill(['superadmin' => true, 'username' => 'someone']);

        $this->assertFalse($user->isSuperadmin());
        $this->assertArrayNotHasKey('superadmin', $user->toArray());
        $this->assertTrue($this->user(true)->toArray()['superadmin']);
    }

    public function testProjectDeleteIsNoLongerAScope()
    {
        $this->assertNotContains('project_delete', config('applicationable.scopes.users'));
    }

    private function passes($caller)
    {
        try {
            return (new SuperadminMiddleware(new AclAuth($caller)))->handle(Request::create('/'), function () {
                return true;
            });
        } catch (AccessDeniedException $e) {
            return false;
        }
    }

    public function testOnlyASuperadminGoesThrough()
    {
        $this->assertFalse($this->passes(null));
        $this->assertFalse($this->passes($this->user()));
        $this->assertTrue($this->passes($this->user(true)));
    }

    /**
     * "METHOD /uri" => route action, for every route.
     */
    private function routes()
    {
        $routes = [];
        foreach (app()->getRoutes() as $route) {
            $routes[$route['method'] . ' /' . ltrim($route['uri'], '/')] = $route['action'];
        }

        return $routes;
    }

    public function testSuperadminRoutes()
    {
        $routes = $this->routes();
        foreach (['POST /api/v1/projects', 'DELETE /api/v1/projects', 'GET /api/v1/superadmin/log'] as $route) {
            $middleware = (array) ($routes[$route]['middleware'] ?? []);
            $this->assertContains('superadmin', $middleware, $route);
            $this->assertLessThan(
                array_search('superadmin', $middleware, true),
                array_search('oauth', $middleware, true),
                "$route authenticates the user first"
            );
        }
    }

    public function testThePackageRoutesAreReplaced()
    {
        $routes = $this->routes();

        $this->assertSame('App\Http\Controllers\ApplicationsController@create', $routes['POST /api/v1/projects']['uses']);
        $this->assertSame('App\Http\Controllers\ApplicationsController@projectsList', $routes['GET /api/v1/projects']['uses']);
        $this->assertSame(
            'App\Http\Controllers\ApplicationsController@updateConsumer',
            $routes['PUT /api/v1/projects/consumers']['uses']
        );
    }

    private function audit($caller, $authorization, $status = 200)
    {
        $log = new MemoryAdminLog;
        $log->note(['title' => 'Created']);
        $request = Request::create('/api/v1/projects', 'DELETE', [], [], [], [
            'HTTP_AUTHORIZATION' => $authorization,
            'REMOTE_ADDR' => '10.0.0.1',
        ]);
        $application = new Application;
        $application->setAttribute('_id', self::APPLICATION_ID);
        app()->instance(Application::class, $application);
        app()->instance(GandalfApplication::ROUTED_REQUEST, ['DELETE', '/api/v1/projects']);

        (new SuperadminAuditMiddleware(new AclAuth($caller), $log))->terminate($request, new Response('', $status));

        return $log->entries;
    }

    public function testASuperadminRequestIsRecorded()
    {
        $entries = $this->audit($this->user(true), 'Bearer token', 403);

        $this->assertCount(1, $entries);
        $this->assertSame([
            'user_id' => self::USER_ID,
            'email' => 'someone@example.test',
            'action' => 'request',
            'method' => 'DELETE',
            'path' => '/api/v1/projects',
            'application_id' => self::APPLICATION_ID,
            'status' => 403,
            'ip' => '10.0.0.1',
            'details' => ['title' => 'Created'],
        ], $entries[0]);
    }

    public function testOtherRequestsAreNotRecorded()
    {
        $this->assertSame([], $this->audit($this->user(), 'Bearer token'));
        $this->assertSame([], $this->audit(null, 'Bearer token'));
        // An API key authenticates with Basic: never a superadmin.
        $this->assertSame([], $this->audit($this->user(true), 'Basic a2V5OnNlY3JldA=='));
    }
}
