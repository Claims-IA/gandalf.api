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
        // NB: headers must be checked with headers->has(), not $request->has()
        // ($request->has() looks up INPUT parameters). The old code always
        // overwrote Content-Type — including "multipart/form-data; boundary=…"
        // on file uploads, which made Lumen drop the form fields.
        if (!$request->headers->has('Accept') || $request->headers->get('Accept') === '') {
            $request->headers->set('Accept', 'application/json');
        }
        if (!$request->headers->has('Content-Type') || $request->headers->get('Content-Type') === '') {
            $request->headers->set('Content-Type', 'application/json');
        }

        return $next($request);
    }
}
