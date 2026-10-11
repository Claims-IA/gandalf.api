<?php
/**
 * Account administration (ADMIN.md): temporary passwords, the obligation to
 * replace one, held by the 'oauth' route middleware, and the routes of the
 * superadmin accounts screen.
 */

use App\Application as GandalfApplication;
use App\Exceptions\PasswordChangeRequiredException;
use App\Http\Middleware\Authenticate;
use App\Models\User;
use App\Observers\UserObserver;
use App\Services\Accounts;
use App\Services\Mail;
use Helper\AclAuth;
use Helper\KeepingHandler;
use Helper\RefusingMail;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;

class AccountsTest extends \Codeception\TestCase\Test
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../bootstrap/app.php';
        require_once __DIR__ . '/../_support/Helper/AclDoubles.php';
        require_once __DIR__ . '/../_support/Helper/AccountsDoubles.php';
    }

    protected function tearDown(): void
    {
        app()->offsetUnset(GandalfApplication::ROUTED_REQUEST);
    }

    public function testATemporaryPasswordIsReadableAndRandom()
    {
        $passwords = [];
        for ($i = 0; $i < 20; $i++) {
            $password = (new Accounts)->temporaryPassword();
            // No look-alike characters: 0, O, o, 1, I, l
            $this->assertSame(1, preg_match('/^[A-HJ-NP-Za-km-np-z2-9]{12}$/', $password), $password);
            $passwords[] = $password;
        }

        $this->assertCount(20, array_unique($passwords));
    }

    public function testARefusedEmailDoesNotLoseThePassword()
    {
        $handler = app(ExceptionHandler::class);
        $enabled = config('services.email.enabled');
        $keeping = new KeepingHandler;
        app()->instance(Mail::class, new RefusingMail);
        app()->instance(ExceptionHandler::class, $keeping);
        config(['services.email.enabled' => true]);
        try {
            $user = new User;
            $user->setRawAttributes(['email' => 'someone@example.test', 'username' => 'someone'], true);
            $sent = (new Accounts)->mailTemporaryPassword($user, 'Temp0rary');
        } finally {
            app()->offsetUnset(Mail::class);
            app()->instance(ExceptionHandler::class, $handler);
            config(['services.email.enabled' => $enabled]);
        }

        $this->assertFalse($sent);
        $this->assertCount(1, $keeping->reported);
    }

    public function testNoEmailWhileSendingIsDisabled()
    {
        $enabled = config('services.email.enabled');
        app()->instance(Mail::class, new RefusingMail);
        config(['services.email.enabled' => false]);
        try {
            $sent = (new Accounts)->mailTemporaryPassword(new User, 'Temp0rary');
        } finally {
            app()->offsetUnset(Mail::class);
            config(['services.email.enabled' => $enabled]);
        }

        $this->assertFalse($sent);
    }

    private function withTemporaryPassword()
    {
        $user = new User;
        $user->setRawAttributes(['must_change_password' => true, 'password' => 'stored-hash'], true);

        return $user;
    }

    public function testATemporaryPasswordMustBeReplaced()
    {
        $user = new User;
        $user->setTemporaryPassword('Temp0rary');
        (new UserObserver)->saving($user);

        $this->assertTrue($user->mustChangePassword());
        $this->assertTrue($user->getPasswordHasher()->check('Temp0rary', $user->password));
        $this->assertTrue($user->toArray()['must_change_password']);
    }

    public function testAChosenPasswordClearsTheObligation()
    {
        $user = $this->withTemporaryPassword();
        $user->changePassword('Chosen123');
        (new UserObserver)->saving($user);

        $this->assertFalse($user->mustChangePassword());
    }

    public function testAnotherProfileChangeKeepsIt()
    {
        $user = $this->withTemporaryPassword();
        $user->first_name = 'Someone';
        (new UserObserver)->saving($user);

        $this->assertTrue($user->mustChangePassword());
    }

    public function testTheFlagIsNotFillable()
    {
        $user = $this->withTemporaryPassword()->fill(['must_change_password' => false]);

        $this->assertTrue($user->mustChangePassword());
    }

    private function passes($user, $method, $path)
    {
        app()->instance(GandalfApplication::ROUTED_REQUEST, [$method, $path]);
        $next = function () {
            return 'next';
        };

        return (new Authenticate(new AclAuth($user)))->handle(Request::create($path, $method), $next) === 'next';
    }

    public function testATemporaryPasswordOnlyOpensTheProfile()
    {
        $user = $this->withTemporaryPassword();
        $this->assertTrue($this->passes($user, 'GET', '/api/v1/users/current'));
        $this->assertTrue($this->passes($user, 'HEAD', '/api/v1/users/current'));
        $this->assertTrue($this->passes($user, 'PUT', '/api/v1/users/current'));

        $closed = [
            ['GET', '/api/v1/projects'],
            ['GET', '/api/v1/admin/tables'],
            ['GET', '/api/v1/users'],
            ['GET', '/api/v1/superadmin/users'],
            ['POST', '/api/v1/projects/collaborators/account'],
        ];
        foreach ($closed as list($method, $path)) {
            try {
                $this->passes($user, $method, $path);
                $this->fail("$method $path is closed while the password is temporary");
            } catch (PasswordChangeRequiredException $e) {
                $this->assertSame(403, $e->getStatusCode());
            }
        }
    }

    public function testOtherUsersGoThrough()
    {
        $this->assertTrue($this->passes(new User, 'GET', '/api/v1/projects'));
    }

    public function testAGuestIsStillRefused()
    {
        try {
            $this->passes(null, 'GET', '/api/v1/users/current');
            $this->fail('A guest goes through');
        } catch (AuthorizationException $e) {
            $this->assertInstanceOf(AuthorizationException::class, $e);
        }
    }

    public function testTheRefusalSaysWhy()
    {
        $response = app(ExceptionHandler::class)->render(Request::create('/api/v1/projects'), new PasswordChangeRequiredException);
        $meta = json_decode($response->getContent(), true)['meta'];

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('password_change_required', $meta['error']);
        $this->assertNotEmpty($meta['error_message']);
    }

    public function testTheOauthMiddlewareIsReplaced()
    {
        $aliases = new ReflectionProperty(app(), 'routeMiddleware');
        $aliases->setAccessible(true);

        $this->assertSame(Authenticate::class, $aliases->getValue(app())['oauth']);
    }

    public function testTheAccountRoutesAreForSuperadmins()
    {
        $routes = [];
        foreach (app()->getRoutes() as $route) {
            $routes[$route['method'] . ' /' . ltrim($route['uri'], '/')] = $route['action'];
        }
        $accountRoutes = [
            'GET /api/v1/superadmin/users' => 'users',
            'DELETE /api/v1/superadmin/users/{id:[0-9a-z]{24}}' => 'deleteUser',
            'POST /api/v1/superadmin/users/{id:[0-9a-z]{24}}/password' => 'resetPassword',
        ];
        foreach ($accountRoutes as $route => $method) {
            $this->assertSame('App\Http\Controllers\SuperadminController@' . $method, $routes[$route]['uses']);
            $this->assertSame(['oauth', 'superadmin'], (array) $routes[$route]['middleware'], $route);
        }
    }
}
