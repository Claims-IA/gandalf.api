<?php
/**
 * PasswordChangeRequiredException
 *
 * Thrown by the 'oauth' route middleware while the caller's password is a
 * temporary one (User::mustChangePassword()): HTTP 403 with the
 * 'password_change_required' error code.
 *
 * @package App\Exceptions
 */
namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

class PasswordChangeRequiredException extends HttpException
{
    public function __construct()
    {
        parent::__construct(403, 'password_change_required');
    }
}
