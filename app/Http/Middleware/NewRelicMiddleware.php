<?php
/**
 * NewRelicMiddleware
 *
 * Assigns a human-readable transaction name to each request in New Relic APM.
 * Without this, New Relic groups all Lumen requests under a single generic
 * transaction name, making it impossible to isolate performance problems per
 * endpoint. The middleware is a no-op when the newrelic PHP extension is not
 * loaded, so it is safe to register globally even in environments without APM.
 *
 * @package App\Http\Middleware
 */

namespace App\Http\Middleware;

use Closure;

class NewRelicMiddleware
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
        if (extension_loaded('newrelic')) {
            newrelic_name_transaction(sprintf('%s (%s)', $request->getRequestUri(), $request->method()));
        }

        return $next($request);
    }
}
