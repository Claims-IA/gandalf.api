<?php
/**
 * GeneralValidator
 *
 * Provides reusable custom validation rules that are not specific to a single
 * domain model. Registered globally via ValidationServiceProvider::boot() so
 * these rules can be referenced in any validation ruleset. Currently provides:
 * 'mongoId' for validating 24-character hex MongoDB ObjectIDs, 'json' for
 * validating that a string is valid JSON, and 'betweenString' for validating
 * the "min;max" range format used by the $between condition operator.
 *
 * @package App\Validators
 */

namespace App\Validators;

class GeneralValidator
{
    /**
     * Validate that the value is a valid MongoDB ObjectID (24 hex chars with mixed digits/letters).
     *
     * The regex requires the string to be exactly 24 hex characters and to contain
     * at least one letter and at least one digit (pure-digit or pure-letter strings
     * cannot be valid ObjectIDs).
     *
     * @param  string $attribute  The attribute name being validated.
     * @param  mixed  $value      The value to test.
     * @return bool
     */
    public function mongoId($attribute, $value)
    {
        return preg_match('/^(?=[a-f\d]{24}$)(\d+[a-f]|[a-f]+\d)/', $value);
    }

    /**
     * Validate that the value is a valid, non-empty JSON string.
     *
     * Returns false for empty JSON objects/arrays or invalid JSON.
     *
     * @param  string $attribute
     * @param  mixed  $value
     * @return bool
     */
    public function json($attribute, $value)
    {
        return boolval(json_decode($value, true));
    }

    /**
     * Validate the "min;max" range format used by the $between condition operator.
     *
     * The value must contain exactly one semicolon separating two numeric values,
     * both must be valid numbers (commas accepted as decimal separator), and the
     * first must be strictly less than the second.
     *
     * @param  string $attribute
     * @param  mixed  $value     Expected format: "10;20" or "10,5;20,5".
     * @return bool
     */
    public function betweenString($attribute, $value)
    {
        if (strpos($value, ';') === false) {
            return false;
        }

        // Normalise comma as decimal separator (e.g. European locale "10,5" -> "10.5")
        $between = array_map(function ($item) {
            return floatval(str_replace(',', '.', $item));
        }, explode(';', $value));

        // Must be exactly two parts
        if (count($between) > 2) {
            return false;
        }
        if (!is_numeric($between[0]) or !is_numeric($between[1])) {
            return false;
        }

        // Lower bound must be strictly less than upper bound
        return $between[0] < $between[1];
    }
}
