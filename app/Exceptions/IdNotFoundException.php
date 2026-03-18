<?php
/**
 * IdNotFoundException
 *
 * Thrown by Base::findById() when an empty ID string is passed (before even hitting
 * the database). Extends NotFoundHttpException so the exception handler maps it to
 * HTTP 404 with the 'mongo_id_not_found' error code. Distinguishes the "empty ID"
 * case from Eloquent's ModelNotFoundException which covers "ID is valid but not in DB".
 *
 * @package App\Exceptions
 */
namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class IdNotFoundException extends NotFoundHttpException
{
    public function __construct()
    {
        parent::__construct('mongo_id_not_found');
    }
}
