<?php
/**
 * SuperadminAuditMiddleware
 *
 * Global terminable middleware: records every API request made by a
 * superadmin in the admin log (ADMIN.md), reads included, once the response
 * is sent. terminate() receives the final response, error responses included,
 * so a refused or failed request is recorded with its status.
 *
 * Only requests carrying a user token are looked at, in the Authorization
 * header (Bearer) or in the access_token parameter the OAuth server also
 * accepts: a superadmin never acts with an API key (Basic authentication).
 *
 * @package App\Http\Middleware
 */

namespace App\Http\Middleware;

use App\Application as GandalfApplication;
use App\Models\User;
use App\Services\AdminLog;
use Closure;
use Illuminate\Contracts\Auth\Factory as Auth;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Nebo15\LumenApplicationable\Models\Application;

class SuperadminAuditMiddleware
{
    /**
     * @var Auth
     */
    protected $auth;

    /**
     * @var AdminLog
     */
    protected $log;

    public function __construct(Auth $auth, AdminLog $log)
    {
        $this->auth = $auth;
        $this->log = $log;
    }

    public function handle(Request $request, Closure $next)
    {
        return $next($request);
    }

    /**
     * Record the request when a superadmin made it. The response is already
     * sent: a failure to record is reported, not thrown.
     *
     * @param  Request $request
     * @param  \Symfony\Component\HttpFoundation\Response $response
     * @return void
     */
    public function terminate($request, $response)
    {
        if (!self::carriesUserToken($request)) {
            return;
        }
        try {
            $user = $this->auth->guard()->user();
            if (!$user instanceof User || !$user->isSuperadmin()) {
                return;
            }
            list($method, $path) = app()->bound(GandalfApplication::ROUTED_REQUEST)
                ? app(GandalfApplication::ROUTED_REQUEST)
                : [$request->getMethod(), $request->getPathInfo()];
            $applicationId = app()->bound(Application::class) ? (string) app(Application::class)->_id : null;

            $this->log->recordRequest($user, $method, $path, $applicationId, $response->getStatusCode(), $request->ip());
        } catch (\Exception $e) {
            app(ExceptionHandler::class)->report($e);
        }
    }

    /**
     * Whether the request carries a user token where the OAuth server reads
     * one: the Authorization header, or the access_token parameter (query or
     * body).
     *
     * @param  Request $request
     * @return bool
     */
    private static function carriesUserToken($request)
    {
        return stripos((string) $request->header('Authorization'), 'Bearer ') === 0
            || (string) $request->query('access_token', '') !== ''
            || (string) $request->input('access_token', '') !== '';
    }
}
