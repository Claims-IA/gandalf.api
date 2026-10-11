<?php
/**
 * Authenticate
 *
 * Replaces the 'oauth' route middleware of nebo15/lumen.oauth2-mongodb: the
 * caller must hold a valid user token, as before, and, while their password
 * is a temporary one (ADMIN.md), may only read their profile and choose a new
 * password. Every other route answers 403 'password_change_required', so the
 * obligation holds for any client, not only the web interface.
 *
 * Registered in AppServiceProvider::register(), after the package provider.
 *
 * @package App\Http\Middleware
 */

namespace App\Http\Middleware;

use App\Application as GandalfApplication;
use App\Exceptions\PasswordChangeRequiredException;
use App\Models\User;
use Closure;
use Nebo15\LumenOauth2\Middleware\Authenticate as PackageAuthenticate;

class Authenticate extends PackageAuthenticate
{
    /**
     * The routes left open while the password is temporary, "METHOD /path" as
     * routed: reading one's profile, and changing it, password included.
     */
    const OPEN_WITH_TEMPORARY_PASSWORD = [
        'GET /api/v1/users/current',
        'PUT /api/v1/users/current',
    ];

    public function handle($request, Closure $next, $guard = null)
    {
        return parent::handle($request, function ($request) use ($next, $guard) {
            $user = $this->auth->guard($guard)->user();
            if ($user instanceof User && $user->mustChangePassword() && !self::openWithTemporaryPassword($request)) {
                throw new PasswordChangeRequiredException;
            }

            return $next($request);
        }, $guard);
    }

    /**
     * Whether the routed request is one of OPEN_WITH_TEMPORARY_PASSWORD, HEAD
     * being routed as GET.
     *
     * @param  \Illuminate\Http\Request $request
     * @return bool
     */
    private static function openWithTemporaryPassword($request)
    {
        list($method, $path) = app()->bound(GandalfApplication::ROUTED_REQUEST)
            ? app(GandalfApplication::ROUTED_REQUEST)
            : [$request->getMethod(), $request->getPathInfo()];
        $method = strtoupper($method) === 'HEAD' ? 'GET' : strtoupper($method);

        return in_array($method . ' /' . trim($path, '/'), self::OPEN_WITH_TEMPORARY_PASSWORD, true);
    }
}
