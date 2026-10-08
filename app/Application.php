<?php
/**
 * Application
 *
 * Custom Lumen application class that overrides three framework behaviours: it
 * keeps the method and path the router dispatches (for the ACL middleware), it
 * suppresses E_DEPRECATED notices (needed because illuminate 5.2 triggers them
 * on PHP 8+), and it swaps the Monolog handler in production to write
 * single-line JSON entries to stdout instead of the default file handler.
 *
 * @package App
 */
namespace App;

use Monolog\Handler\StreamHandler;
use Monolog\Formatter\LineFormatter;

class Application extends \Laravel\Lumen\Application
{
    /**
     * Container key of the [method, path] pair the router dispatches.
     */
    const ROUTED_REQUEST = 'gandalf.routed_request';

    /**
     * Keep the [method, path] pair the router dispatches, as Lumen computes it
     * (from the globals in run(), from the request in dispatch($request)).
     *
     * ApplicationAclMiddleware checks this pair: deriving it again from the
     * request object diverged from the routing (trailing slash, method override,
     * X-Original-URL rewriting the request URI, HEAD routed as GET).
     *
     * @param  \Symfony\Component\HttpFoundation\Request|null $request
     * @return array  [method, pathInfo]
     */
    protected function parseIncomingRequest($request)
    {
        $routed = parent::parseIncomingRequest($request);
        $this->instance(self::ROUTED_REQUEST, $routed);

        return $routed;
    }

    /**
     * Register the error handling for the application.
     *
     * Extends the parent implementation to re-apply E_DEPRECATED suppression
     * after the parent calls error_reporting(-1). Without this, PHP 8.1+
     * ArrayAccess return-type notices from illuminate/5.2 would be converted
     * to fatal errors.
     *
     * @return void
     */
    protected function registerErrorHandling()
    {
        parent::registerErrorHandling();
        // parent::registerErrorHandling() calls error_reporting(-1) which re-enables E_DEPRECATED.
        // Restore suppression so illuminate/5.2 ArrayAccess return type notices don't become exceptions.
        error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
    }

    /**
     * Get the Monolog handler for the application.
     *
     * In production, returns a StreamHandler that writes to stdout (or the path
     * defined by APP_LOG_PATH) using a compact single-line format suitable for
     * log aggregation tools. In all other environments the Lumen default handler
     * is used unchanged.
     *
     * @return \Monolog\Handler\HandlerInterface
     */
    protected function getMonologHandler()
    {
        if (env('APP_ENV') == 'prod') {
            // Use stdout for production so container orchestrators can capture logs
            return (new StreamHandler(env('APP_LOG_PATH', 'php://stdout')))
                ->setFormatter(new LineFormatter(null, null, true, true));
        } else {
            return parent::getMonologHandler();
        }
    }
}
