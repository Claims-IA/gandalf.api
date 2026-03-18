<?php
/**
 * ConditionsTypes
 *
 * The core condition evaluation engine for Gandalf. Defines all supported
 * comparison operators as named closures and provides methods to evaluate a
 * condition against a field value at scoring time, retrieve the operator
 * definitions for validation rule building, and parse comma-separated value
 * lists for the $in/$nin operators. Each operator entry includes an input_type
 * hint used by the TableValidator to ensure the stored condition value is of the
 * correct type before a table is saved.
 *
 * @package App\Services
 */

namespace App\Services;

use App\Exceptions\ConditionException;

class ConditionsTypes
{
    private $conditions;

    public function __construct()
    {
        $this->conditions = [
            '$is_set' => [
                'input_type' => '',
                'function' => function () {
                    return true;
                }
            ],
            '$is_null' => [
                'input_type' => '',
                'function' => function ($condition_value, $field_value) {
                    return null === $field_value;
                }
            ],
            '$eq' => [
                'input_type' => '',
                'function' => function ($condition_value, $field_value) {
                    return $condition_value == $field_value;
                }
            ],
            '$ne' => [
                'input_type' => '',
                'function' => function ($condition_value, $field_value) {
                    return $condition_value != $field_value;
                }
            ],
            '$gt' => [
                'input_type' => 'numeric',
                'function' => function ($condition_value, $field_value) {
                    return $field_value > $condition_value;
                }
            ],
            '$gte' => [
                'input_type' => 'numeric',
                'function' => function ($condition_value, $field_value) {
                    return $field_value >= $condition_value;
                }
            ],
            '$lt' => [
                'input_type' => 'numeric',
                'function' => function ($condition_value, $field_value) {
                    return $field_value < $condition_value;
                }
            ],
            '$lte' => [
                'input_type' => 'numeric',
                'function' => function ($condition_value, $field_value) {
                    return $field_value <= $condition_value;
                }
            ],
            '$between' => [
                'input_type' => 'betweenString',
                'function' => function ($condition_value, $field_value) {
                    $between = array_map(function ($item) {
                        return floatval(str_replace(',', '.', $item));
                    }, explode(';', $condition_value));
                    return ($between[0] <= $field_value and $between[1] >= $field_value);
                }
            ],
            '$in' => [
                'input_type' => '',
                'function' => function ($condition_value, $field_value) {
                    return in_array($field_value, $this->explodeValue($condition_value));
                }
            ],
            '$nin' => [
                'input_type' => '',
                'function' => function ($condition_value, $field_value) {
                    return !in_array($field_value, $this->explodeValue($condition_value));
                }
            ],
            '$any' => [
                // Toujours vrai, quelle que soit la valeur (y compris null)
                'input_type' => '',
                'function' => function () {
                    return true;
                }
            ],
            '$between_excl' => [
                // min < x < max (bornes exclues des deux côtés)
                'input_type' => 'betweenString',
                'function' => function ($condition_value, $field_value) {
                    $between = array_map(function ($item) {
                        return floatval(str_replace(',', '.', $item));
                    }, explode(';', $condition_value));
                    return ($between[0] < $field_value and $between[1] > $field_value);
                }
            ],
            '$between_lexcl' => [
                // min < x <= max (borne gauche exclue, borne droite incluse)
                'input_type' => 'betweenString',
                'function' => function ($condition_value, $field_value) {
                    $between = array_map(function ($item) {
                        return floatval(str_replace(',', '.', $item));
                    }, explode(';', $condition_value));
                    return ($between[0] < $field_value and $between[1] >= $field_value);
                }
            ],
            '$between_rexcl' => [
                // min <= x < max (borne gauche incluse, borne droite exclue)
                'input_type' => 'betweenString',
                'function' => function ($condition_value, $field_value) {
                    $between = array_map(function ($item) {
                        return floatval(str_replace(',', '.', $item));
                    }, explode(';', $condition_value));
                    return ($between[0] <= $field_value and $between[1] > $field_value);
                }
            ],
        ];
    }

    /**
     * Return a comma-separated string of all valid condition operator keys.
     *
     * Used by TablesController and ValidationServiceProvider to build the 'in:'
     * validation rule that restricts condition values to defined operators.
     *
     * @return string  e.g. "$is_set,$is_null,$eq,$ne,$gt,..."
     */
    public function getConditionsRules()
    {
        return implode(',', array_keys($this->conditions));
    }

    /**
     * Evaluate a single condition against a field value.
     *
     * Returns false immediately if the field value is null and the operator is not
     * '$is_null', because null values cannot satisfy any other condition meaningfully.
     * Delegates the actual comparison to the operator's closure.
     *
     * @param  string $condition_key    The operator key (e.g. '$eq', '$gt').
     * @param  mixed  $condition_value  The threshold/reference value from the rule.
     * @param  mixed  $field_value      The actual value from the incoming request.
     * @return bool   True if the condition is satisfied, false otherwise.
     */
    public function checkConditionValue($condition_key, $condition_value, $field_value)
    {
        // Null field values cannot satisfy any condition except $is_null and $any
        if ($field_value === null and $condition_key !== '$is_null' and $condition_key !== '$any') {
            return false;
        }
        $condition = $this->getCondition($condition_key);

        return $condition['function']($condition_value, $field_value);
    }

    /**
     * Retrieve a condition definition by its operator key.
     *
     * @param  string $condition_key  The operator key to look up.
     * @return array  Associative array with 'input_type' and 'function' keys.
     * @throws ConditionException  If the operator key is not registered.
     */
    public function getCondition($condition_key)
    {
        if (!array_key_exists($condition_key, $this->conditions)) {
            throw new ConditionException("Undefined condition rule '$condition_key'");
        }

        return $this->conditions[$condition_key];
    }

    /**
     * Parse a comma-separated value string into an array of trimmed tokens.
     *
     * Supports both bare tokens and single-quoted tokens (which may contain
     * commas or spaces): e.g. "'visa, mastercard', amex" parses to
     * ['visa, mastercard', 'amex']. Used by the $in and $nin operators.
     *
     * @param  string $value  The raw condition value string.
     * @return array  Array of string tokens.
     */
    private function explodeValue($value)
    {
        // Regex matches either a single-quoted string or a non-comma/non-space sequence
        preg_match_all("/'[^']+'|[^, ]+/", $value, $output);

        return array_map(function ($value) {
            // Strip surrounding single quotes from quoted tokens
            return trim($value, "'");
        }, $output[0]);
    }
}
