<?php
/**
 * ConditionException
 *
 * Thrown by ConditionsTypes::getCondition() when an unregistered condition operator
 * key is referenced (e.g. a typo like '$eqq'). This is a logic-level exception
 * (not an HTTP exception) and is caught by TableValidator::conditionType() to return
 * a validation failure rather than a 500 error, keeping the API response clean.
 *
 * @package App\Exceptions
 */

namespace App\Exceptions;

class ConditionException extends \Exception
{

}
