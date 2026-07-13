<?php
/**
 * Exception Handler
 *
 * Global exception handler that translates PHP exceptions into structured JSON
 * error responses. Maps well-known exception types (validation failures, model not
 * found, access denied, etc.) to appropriate HTTP status codes and machine-readable
 * error codes. Unknown 500-level exceptions are reported to Bugsnag when enabled
 * and exposed as a full stack trace only when APP_DEBUG is true, otherwise a generic
 * internal_server_error response is returned.
 *
 * @package App\Exceptions
 */

namespace App\Exceptions;

use Exception;
use Illuminate\Contracts\Validation\ValidationException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Nebo15\LumenApplicationable\Exceptions\AccessDeniedException;
use Nebo15\LumenApplicationable\Exceptions\TryingToAddDuplicateUserException;
use Nebo15\LumenApplicationable\Exceptions\XApplicationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Laravel\Lumen\Exceptions\Handler as ExceptionHandler;

class Handler extends ExceptionHandler
{
    /**
     * A list of the exception types that should not be reported.
     *
     * @var array
     */
    protected $dontReport = [
        HttpException::class,
        ValidationException::class,
        ModelNotFoundException::class,
        AuthorizationException::class,
        AccessDeniedException::class,
        TryingToAddDuplicateUserException::class,
    ];

    /**
     * Report or log an exception.
     *
     * This is a great spot to send exceptions to Sentry, Bugsnag, etc.
     *
     * @param  \Exception $e
     * @return void
     */
    public function report(Exception $e)
    {
        parent::report($e);
    }

    /**
     * Render an exception into an HTTP response.
     *
     * @param  \Illuminate\Http\Request $request
     * @param  \Exception $e
     * @return \Illuminate\Http\Response
     */
    public function render($request, \Exception $e)
    {
        if (env('BUGSNAG_ENABLED')) {
            app('bugsnag')->notifyException($e, []);
        }

        $http_code = 500;
        $error_code = 'internal_server_error';

        $meta = [];

        if ($e instanceof ValidationException) {
            return response()->json([
                'meta' => [
                    'code' => 422,
                    'error' => 'validation',
                    'error_message' => 'Validation failed',
                ],
                'data' => $e->errors(),
            ], 422, ['Content-Type' => 'application/json']);
        } elseif ($e instanceof \App\Exceptions\FlowValidationException) {
            $data = ['errors' => $e->getErrors()];
            // A run-time failure records a partial FlowRun; expose its id so the
            // client can correlate this 422 with the persisted trace.
            if ($e->getFlowRunId() !== null) {
                $data['flow_run_id'] = $e->getFlowRunId();
            }

            return response()->json([
                'meta' => [
                    'code' => 422,
                    'error' => 'flow_validation',
                    'error_message' => 'Flow graph validation failed',
                ],
                'data' => $data,
            ], 422, ['Content-Type' => 'application/json']);
        } elseif ($e instanceof AuthorizationException) {
            $http_code = 401;
            $error_code = 'unauthorized';
        } elseif ($e instanceof ModelNotFoundException) {
            $http_code = 404;
            $error_code = $this->formatModelName($e->getModel()) . '_not_found';
        } elseif ($e instanceof HttpException) {
            $http_code = $e->getStatusCode();
            if (!$error_code = $e->getMessage()) {
                switch ($http_code) {
                    case 404:
                        $error_code = 'not_found';
                        break;
                    case 405:
                        $error_code = 'method_not_allowed';
                        break;
                    default:
                        $error_code = 'http';
                }
            }
        } elseif ($e instanceof AccessDeniedException) {
            $http_code = 403;
            $error_code = 'access_denied';
            $meta['error_message'] = $e->scopes ? 'Bad Scopes' : $e->getMessage();
            $meta['scopes'] = $e->scopes;
        } elseif ($e instanceof XApplicationException) {
            $http_code = 400;
            $error_code = 'invalid_app_header';
            $meta['error_message'] = $e->getMessage();
        } elseif ($e instanceof TryingToAddDuplicateUserException) {
            $http_code = 400;
            $error_code = 'duplicate_user';
            $meta['error_message'] = $e->getMessage();
        }

        if ($http_code === 500 and env('APP_DEBUG') === true) {
            return $e->__toString();
        }

        $meta['code'] = $http_code;
        $meta['error'] = $error_code;

        if (empty($meta['error_message']) and $error_msg = config("errors.$error_code")) {
            $meta['error_message'] = $error_msg;
        }

        return response()->json(['meta' => $meta], $http_code, ['Content-Type' => 'application/json']);
    }

    private function formatModelName($model)
    {
        $name = preg_replace('/\B([A-Z])/', '_$1', explode('\\', $model));

        return strtolower(end($name));
    }
}
