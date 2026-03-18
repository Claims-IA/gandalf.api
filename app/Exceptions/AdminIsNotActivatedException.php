<?php
/**
 * AdminIsNotActivatedException
 *
 * Thrown by ConsumerController::tableCheck() when the application that owns the
 * requested decision table has no active (email-verified) admin users. This prevents
 * orphaned or unverified applications from consuming the decision engine and ensures
 * every active project has at least one responsible owner. Returns HTTP 403 with the
 * error code 'admin_not_activated'.
 *
 * @package App\Exceptions
 */
namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

class AdminIsNotActivatedException extends HttpException
{
    public function __construct()
    {
        parent::__construct(403, 'admin_not_activated');
    }
}
