<?php
/**
 * ConditionCellParseException
 *
 * Thrown by ConditionCellCodec::decode() when a condition cell cannot be
 * parsed into a valid (operator, value) pair. Carries only the human-readable
 * French message; the Excel reader adds the row/column context when it
 * collects parse errors across the whole sheet.
 *
 * @package App\Exceptions
 */

namespace App\Exceptions;

class ConditionCellParseException extends \InvalidArgumentException
{
}
