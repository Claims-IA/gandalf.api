<?php
/**
 * ApplicationAclMiddleware
 *
 * Replaces the 'applicationable.acl' route middleware of
 * nebo15/lumen.applicationable. Both check the scopes listed by the first
 * config('applicationable.acl.<method>') regex that matches the request, but the
 * package middleware had three gaps that let a request reach its route unchecked:
 *
 *   - no regex matching: the request went through, so a route missing from the
 *     ACL was open to any authenticated user, member of the project or not;
 *   - path: it matched Symfony's getPathInfo(), which keeps a trailing slash,
 *     while Lumen routes the path trimmed of slashes ("/api/v1/projects/" runs
 *     the "/api/v1/projects" route but escapes the "projects$" regex);
 *   - method: it read Symfony's getMethod(), which honours X-HTTP-Method-Override
 *     and _method, while Lumen routes $_POST['_method'] or the real method.
 *
 * Here the ACL looks at the method and path that Lumen routes (Application::run()
 * reads them from the globals), and also at Symfony's reading with the path
 * trimmed (used when a request object is dispatched): the request must pass for
 * each. When no regex matches, it goes through only if the route has no
 * application context (GET and POST /projects list or create the caller's own
 * projects) or if the caller belongs to the application: a member user, or a
 * consumer of the application. tests/unit/AclCoverageTest.php checks that every
 * route with an application context is covered with the expected scopes.
 *
 * Registered in AppServiceProvider::register(), after the package provider.
 *
 * @package App\Http\Middleware
 */

namespace App\Http\Middleware;

use Closure;
use Illuminate\Contracts\Auth\Factory as Auth;
use Illuminate\Http\Request;
use Nebo15\LumenApplicationable\Exceptions\AccessDeniedException;
use Nebo15\LumenApplicationable\Models\Application;

class ApplicationAclMiddleware
{
    /**
     * @var Auth
     */
    protected $auth;

    public function __construct(Auth $auth)
    {
        $this->auth = $auth;
    }

    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request $request
     * @param  \Closure $next
     * @return mixed
     * @throws AccessDeniedException  When the caller lacks a required scope, or
     *                                does not belong to the application.
     */
    public function handle(Request $request, Closure $next)
    {
        foreach (self::routedRequests($request) as list($method, $path)) {
            $this->authorize($method, $path);
        }

        return $next($request);
    }

    /**
     * The [method, path] pairs the request can be routed as: Lumen's reading of
     * the globals (Application::run()), then Symfony's reading with the path
     * trimmed of slashes as Lumen does (Application::dispatch($request)).
     *
     * @param  \Illuminate\Http\Request $request
     * @return array  Distinct [method, path] pairs.
     */
    public static function routedRequests(Request $request)
    {
        // Same formulas as RoutesRequests::getMethod() and getPathInfo().
        $method = isset($_POST['_method'])
            ? strtoupper($_POST['_method'])
            : strtoupper((string) $request->server->get('REQUEST_METHOD', 'GET'));
        $query = (string) $request->server->get('QUERY_STRING', '');
        $path = '/' . trim(str_replace('?' . $query, '', (string) $request->server->get('REQUEST_URI', '')), '/');

        $pairs = [[$method, $path]];
        $symfony = [strtoupper($request->getMethod()), '/' . trim($request->getPathInfo(), '/')];
        if ($symfony !== $pairs[0]) {
            $pairs[] = $symfony;
        }

        return $pairs;
    }

    /**
     * Scopes listed by the first config('applicationable.acl.<method>') regex
     * matching the path, or null when none matches.
     *
     * @param  string $method  HTTP method.
     * @param  string $path    Request path, e.g. "/api/v1/admin/tables".
     * @return array|null
     */
    public static function aclScopes($method, $path)
    {
        foreach (config('applicationable.acl.' . strtolower($method), []) as $regex => $scopes) {
            if (preg_match($regex, $path)) {
                return (array) $scopes;
            }
        }

        return null;
    }

    /**
     * Check one routed [method, path] pair.
     *
     * @param  string $method
     * @param  string $path
     * @return void
     * @throws AccessDeniedException
     */
    private function authorize($method, $path)
    {
        $scopes = self::aclScopes($method, $path);
        // The 'applicationable' middleware binds the application of the
        // X-Application header: without it, the route has no project to protect.
        if ($scopes === null && !app()->bound(Application::class)) {
            return;
        }

        $caller = $this->applicationCaller();
        if (!$caller) {
            throw new AccessDeniedException('You are not a member of this application.');
        }

        $denied = [];
        foreach ((array) $scopes as $scope) {
            if (!$caller->{'can' . ucfirst($scope)}()) {
                $denied[] = $scope;
            }
        }
        if ($denied) {
            throw new AccessDeniedException('', 0, null, $denied);
        }
    }

    /**
     * The caller as seen by the current application: the member entry of the
     * authenticated user, or the consumer of the application (null if none).
     *
     * @return mixed
     */
    private function applicationCaller()
    {
        $guard = $this->auth->guard();
        if (!$guard->guest()) {
            return $guard->user()->getApplicationUser();
        }

        return app()->bound('applicationable.consumer') ? app('applicationable.consumer') : null;
    }
}
