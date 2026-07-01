<?php
/**
 * TokenExpiredException
 *
 * Thrown by User::findByToken() when a token is found in the database but its
 * expiry Unix timestamp has passed. Returns HTTP 422 (Unprocessable Entity) with
 * the 'token_expired' error code so clients can distinguish an expired token from
 * a non-existent one and prompt the user to request a new token.
 *
 * @package App\Exceptions
 */
namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

class TokenExpiredException extends HttpException
{
    public function __construct()
    {
        parent::__construct(422, 'token_expired');
    }
}
