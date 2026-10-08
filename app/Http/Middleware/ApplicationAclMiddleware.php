<?php
/**
 * ApplicationAclMiddleware
 *
 * Replaces the 'applicationable.acl' route middleware of
 * nebo15/lumen.applicationable. Both check the scopes listed by the first
 * config('applicationable.acl.<method>') regex that matches the request, but the
 * package middleware let a request reach its route unchecked:
 *
 *   - when no regex matched: a route missing from the ACL was open to any
 *     authenticated user, member of the project or not;
 *   - when it matched another request than the routed one: it read Symfony's
 *     method and path, while Lumen routes $_POST['_method'] or the real method
 *     and the path trimmed of slashes. A trailing slash, X-HTTP-Method-Override,
 *     _method, an X-Original-URL header (Symfony rewrites the request URI with
 *     it) or HEAD (FastRoute runs the GET route) escaped the route's scopes.
 *
 * Here the ACL checks the [method, path] pair the router dispatches, kept by
 * App\Application::parseIncomingRequest(), with HEAD checked as GET. When no
 * regex matches, the request goes through only if the route has no application
 * context (GET and POST /projects list or create the caller's own projects) or
 * if the caller belongs to the application: a member user, or a consumer of the
 * application. tests/unit/AclCoverageTest.php checks that every route with an
 * application context is covered with the expected scopes.
 *
 * Registered in AppServiceProvider::register(), after the package provider.
 *
 * @package App\Http\Middleware
 */

namespace App\Http\Middleware;

use App\Application as GandalfApplication;
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
        if (!app()->bound(GandalfApplication::ROUTED_REQUEST)) {
            // Never the case when the router dispatches: refuse rather than guess.
            throw new AccessDeniedException('The routed request is unknown.');
        }
        list($method, $path) = app(GandalfApplication::ROUTED_REQUEST);
        $this->authorize($method, $path);

        return $next($request);
    }

    /**
     * Scopes listed by the first config('applicationable.acl.<method>') regex
     * matching the path, or null when none matches. HEAD is checked as GET, the
     * route FastRoute runs for it.
     *
     * @param  string $method  HTTP method, as routed.
     * @param  string $path    Path, as routed, e.g. "/api/v1/admin/tables".
     * @return array|null
     */
    public static function aclScopes($method, $path)
    {
        $method = strtolower($method) === 'head' ? 'get' : strtolower($method);
        foreach (config('applicationable.acl.' . $method, []) as $regex => $scopes) {
            if (preg_match($regex, $path)) {
                return (array) $scopes;
            }
        }

        return null;
    }

    /**
     * Check the routed [method, path] pair.
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
