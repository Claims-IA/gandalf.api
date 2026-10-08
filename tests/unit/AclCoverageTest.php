<?php
/**
 * AclCoverageTest
 *
 * Route authorisation without database: every route behind the
 * 'applicationable.acl' middleware is matched against
 * config('applicationable.acl') with the package rule (first matching regex),
 * and ApplicationAclMiddleware is exercised with in-memory users.
 *
 * A route missing from the ACL used to be open to any authenticated user, member
 * of the project or not (the package middleware let it through): a new route
 * with an application context must now be added to the ACL, and the routes
 * without application context are listed explicitly.
 */

use App\Http\Middleware\ApplicationAclMiddleware;
use Helper\AclAuth;
use Helper\AclCaller;
use Helper\AclMember;
use Illuminate\Http\Request;
use Nebo15\LumenApplicationable\Exceptions\AccessDeniedException;
use Nebo15\LumenApplicationable\Models\Application;

class AclCoverageTest extends \Codeception\TestCase\Test
{
    const GUEST = 'guest';

    const ID = '0123456789abcdef01234567';

    /** Routes protected by the ACL without application context (no project to check). */
    const WITHOUT_APPLICATION = [
        'GET /api/v1/projects',
        'POST /api/v1/projects',
    ];

    /** Scopes required by sensitive routes (first matching regex). */
    const EXPECTED_SCOPES = [
        'POST /api/v1/admin/tables/{id}/copy' => ['tables_create'],
        'POST /api/v1/admin/flows/{id}/copy' => ['tables_create'],
        'POST /api/v1/admin/tables/import' => ['tables_create'],
        'POST /api/v1/admin/tables/{id:[0-9a-z]{24}}/copyto/{project_id:[0-9a-z]{24}}' => ['tables_create'],
        'POST /api/v1/admin/flows/{id:[0-9a-z]{24}}/copyto/{project_id:[0-9a-z]{24}}' => ['tables_create'],
        'POST /api/v1/admin/tables/{id:[0-9a-z]{24}}/moveto/{project_id:[0-9a-z]{24}}' => ['tables_create', 'tables_delete'],
        'POST /api/v1/admin/flows/{id:[0-9a-z]{24}}/moveto/{project_id:[0-9a-z]{24}}' => ['tables_create', 'tables_delete'],
        'POST /api/v1/admin/changelog/{table}/{model_id}/rollback/{changelog_id}' => ['tables_update'],
        'PUT /api/v1/admin/decisions/{id:[0-9a-z]{24}}/meta' => ['decisions_make'],
        'POST /api/v1/invite' => ['users_manage'],
        'POST /api/v1/projects/users/admin' => ['users_manage'],
        'PUT /api/v1/projects' => ['project_update'],
        'GET /api/v1/projects/export' => ['project_update'],
    ];

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../bootstrap/app.php';
        require_once __DIR__ . '/../_support/Helper/AclDoubles.php';
    }

    protected function tearDown(): void
    {
        app()->offsetUnset(Application::class);
        app()->offsetUnset('applicationable.consumer');
    }

    /**
     * "METHOD /uri" => [sample path, has application context], for the ACL routes.
     */
    private function aclRoutes(): array
    {
        $routes = [];
        foreach (app()->getRoutes() as $route) {
            $middleware = (array) ($route['action']['middleware'] ?? []);
            if (!in_array('applicationable.acl', $middleware, true)) {
                continue;
            }
            $uri = '/' . ltrim($route['uri'], '/');
            // Route parameters ({id} or {id:regex}) never contain a slash here.
            $path = preg_replace('~\{\w+(:[^/]*)?\}~', self::ID, $uri);
            $routes[$route['method'] . ' ' . $uri] = [$path, in_array('applicationable', $middleware, true)];
        }

        return $routes;
    }

    public function testEveryRouteWithApplicationIsCovered()
    {
        $uncovered = [];
        foreach ($this->aclRoutes() as $route => list($path, $withApplication)) {
            if ($withApplication && ApplicationAclMiddleware::aclScopes(strtok($route, ' '), $path) === null) {
                $uncovered[] = $route;
            }
        }

        $this->assertSame([], $uncovered, 'Routes with an application context and no ACL regex');
    }

    public function testRoutesWithoutApplicationAreListed()
    {
        $without = [];
        foreach ($this->aclRoutes() as $route => list($path, $withApplication)) {
            if (!$withApplication) {
                $without[] = $route;
            }
        }
        sort($without);

        $this->assertSame(self::WITHOUT_APPLICATION, $without);
    }

    public function testSensitiveRoutesRequireTheirScopes()
    {
        $routes = $this->aclRoutes();
        foreach (self::EXPECTED_SCOPES as $route => $scopes) {
            $this->assertArrayHasKey($route, $routes, "Unknown route $route");
            $this->assertSame($scopes, ApplicationAclMiddleware::aclScopes(strtok($route, ' '), $routes[$route][0]), $route);
        }
    }

    public function testAclMiddlewareIsReplaced()
    {
        $property = new ReflectionProperty(get_class(app()), 'routeMiddleware');
        $property->setAccessible(true);

        $this->assertSame(ApplicationAclMiddleware::class, $property->getValue(app())['applicationable.acl']);
    }

    /**
     * Run the middleware. $caller: self::GUEST, null for an authenticated
     * non-member, or the scopes of a member. Returns true when let through.
     */
    private function passes(string $method, string $path, $caller, bool $withApplication, array $server = []): bool
    {
        if ($withApplication) {
            app()->instance(Application::class, new Application());
        }
        $auth = new AclAuth($caller === self::GUEST ? null : new AclCaller($caller));

        try {
            return (new ApplicationAclMiddleware($auth))->handle(
                Request::create($path, $method, [], [], [], $server),
                function () {
                    return true;
                }
            );
        } catch (AccessDeniedException $e) {
            return false;
        }
    }

    public function testUncoveredRouteIsClosedToNonMembers()
    {
        $this->assertFalse($this->passes('POST', '/api/v1/admin/not-in-acl', null, true));
        $this->assertTrue($this->passes('POST', '/api/v1/admin/not-in-acl', ['tables_view'], true));
    }

    public function testUncoveredRouteWithoutApplicationStaysOpen()
    {
        $this->assertTrue($this->passes('GET', '/api/v1/projects', null, false));
    }

    public function testCoveredRouteChecksMembershipAndScopes()
    {
        $this->assertFalse($this->passes('GET', '/api/v1/admin/tables', null, true));
        $this->assertFalse($this->passes('GET', '/api/v1/admin/tables', ['decisions_view'], true));
        $this->assertTrue($this->passes('GET', '/api/v1/admin/tables', ['tables_view'], true));
    }

    public function testConsumerOfTheApplicationIsChecked()
    {
        $this->assertFalse($this->passes('POST', '/api/v1/admin/not-in-acl', self::GUEST, true));

        app()->instance('applicationable.consumer', new AclMember(['decisions_make']));
        $this->assertTrue($this->passes('POST', '/api/v1/admin/not-in-acl', self::GUEST, true));
        $this->assertTrue($this->passes('POST', '/api/v1/tables/' . self::ID . '/decisions', self::GUEST, true));
        $this->assertFalse($this->passes('GET', '/api/v1/decisions/' . self::ID, self::GUEST, true));
    }

    public function testTrailingSlashKeepsTheScopesOfTheRoute()
    {
        // Lumen routes "/api/v1/projects/" as "/api/v1/projects" (project_delete).
        $this->assertFalse($this->passes('DELETE', '/api/v1/projects/', ['tables_view'], true));
        $this->assertFalse($this->passes('DELETE', '/api/v1/projects/', null, true));
        $this->assertTrue($this->passes('DELETE', '/api/v1/projects/', ['project_delete'], true));
    }

    public function testMethodOverrideHeaderDoesNotSkipTheAcl()
    {
        // Symfony reads PATCH (no ACL entry), Lumen routes the POST create route.
        $server = ['HTTP_X_HTTP_METHOD_OVERRIDE' => 'PATCH'];
        $this->assertFalse($this->passes('POST', '/api/v1/admin/tables', ['tables_view'], true, $server));
        $this->assertTrue($this->passes('POST', '/api/v1/admin/tables', ['tables_view', 'tables_create'], true, $server));
    }

    public function testMethodParameterDoesNotSkipTheAcl()
    {
        // Lumen routes a form post with _method=DELETE as the DELETE route.
        $_POST['_method'] = 'delete';
        try {
            $path = '/api/v1/admin/tables/' . self::ID;
            $this->assertFalse($this->passes('POST', $path, ['tables_view', 'tables_create'], true));
            $this->assertTrue($this->passes('POST', $path, ['tables_view', 'tables_create', 'tables_delete'], true));
        } finally {
            unset($_POST['_method']);
        }
    }
}
