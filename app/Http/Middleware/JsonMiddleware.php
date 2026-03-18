<?php
/**
 * JsonMiddleware
 *
 * Global middleware that normalises every incoming request to use JSON content
 * negotiation. It sets the Accept and Content-Type headers to application/json
 * when they are absent, ensuring that Lumen's response macros and exception
 * handler always render JSON rather than HTML. Registered as a global middleware
 * in bootstrap/app.php so it applies to every route.
 *
 * @package App\Http\Middleware
 */

namespace App\Http\Middleware;

use Closure;

class JsonMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request $request
     * @param  \Closure $next
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        if (!$request->has('accept')) {
            $request->headers->set('Accept', 'application/json');
        }
        if (!$request->has('content-type')) {
            $request->headers->set('Content-Type', 'application/json');
        }

        return $next($request);
    }
}
