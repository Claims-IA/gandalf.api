<?php
/**
 * FailedToSaveModel
 *
 * Thrown by Base::save() when the underlying MongoDB write returns false, indicating
 * that the document could not be persisted. Extends HttpException with a 400 status
 * code and the 'failed_to_save_model' error code so the exception handler renders a
 * consistent structured error response without leaking database details.
 *
 * @package App\Exceptions
 */

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

class FailedToSaveModel extends HttpException
{
    public function __construct()
    {
        parent::__construct(400, 'failed_to_save_model');
    }
}
