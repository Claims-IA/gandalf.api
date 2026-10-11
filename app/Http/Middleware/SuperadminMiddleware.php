<?php
/**
 * SuperadminMiddleware
 *
 * Route middleware 'superadmin': only a superadmin (ADMIN.md) goes through.
 * Placed after 'oauth', so the caller is an authenticated user: an API key
 * (consumer) never reaches a superadmin route, a client credentials token has
 * no user.
 *
 * Guards creating and deleting an application and reading the superadmin log.
 *
 * @package App\Http\Middleware
 */

namespace App\Http\Middleware;

use Closure;
use Illuminate\Contracts\Auth\Factory as Auth;
use Illuminate\Http\Request;
use Nebo15\LumenApplicationable\Exceptions\AccessDeniedException;

class SuperadminMiddleware
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
     * @param  Request $request
     * @param  Closure $next
     * @return mixed
     * @throws AccessDeniedException  When the caller is not a superadmin.
     */
    public function handle(Request $request, Closure $next)
    {
        $user = $this->auth->guard()->user();
        if (!$user || !method_exists($user, 'isSuperadmin') || !$user->isSuperadmin()) {
            throw new AccessDeniedException('Only a superadmin can do this.');
        }

        return $next($request);
    }
}
