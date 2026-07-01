<?php
/**
 * TokenNotFoundException
 *
 * Thrown by User::findByToken() when no user document contains the submitted token
 * value. Returns HTTP 404 with the 'token_not_found' error code. Together with
 * TokenExpiredException this gives clients two distinct failure modes for token
 * validation flows (email verification and password reset).
 *
 * @package App\Exceptions
 */

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class TokenNotFoundException extends NotFoundHttpException
{
    public function __construct()
    {
        parent::__construct('token_not_found');
    }
}
