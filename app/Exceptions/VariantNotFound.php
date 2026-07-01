<?php
/**
 * VariantNotFound
 *
 * Thrown by Table::getVariantForCheck() when no variant can be selected for a
 * decision check — either because the explicitly requested variant_id does not
 * exist on the table, or because the probability-based selection algorithm fails
 * to pick a variant (which should not happen in a correctly configured table).
 * Returns HTTP 404 with the 'variant_not_found' error code.
 *
 * @package App\Exceptions
 */

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class VariantNotFound extends NotFoundHttpException
{
    public function __construct()
    {
        parent::__construct('variant_not_found');
    }
}
