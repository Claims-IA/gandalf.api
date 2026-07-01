<?php
/**
 * Validator
 *
 * Extends Illuminate's Validator with a custom dot-notation flattener that is
 * aware of the decision table's nested structure. The standard Laravel dot()
 * helper recursively flattens all arrays, which loses the conditions array
 * context needed for the conditionsCount and conditionType validators. This
 * override preserves any key path that matches the pattern
 * "table.rules.N.conditions" so those validators receive the full conditions
 * array rather than individual condition scalars.
 *
 * @package App\Http\Services
 */

namespace App\Http\Services;

use Illuminate\Support\Str;

class Validator extends \Illuminate\Validation\Validator
{
    /**
     * Validate each element in a nested array attribute against a set of rules.
     *
     * Uses the custom dot() helper (below) to flatten the data while preserving
     * the conditions array at the rules level. Matches attribute paths against
     * the given pattern (which may contain wildcard '*' segments) and merges
     * the rules for each matched key.
     *
     * @param  string       $attribute  Dot-notation attribute path, e.g. "variants.*.rules.*.conditions".
     * @param  array|string $rules      Validation rule(s) to apply to each matching element.
     * @return void
     */
    public function each($attribute, $rules)
    {
        $data = $this->dot($this->initializeAttributeOnData($attribute));

        // Convert wildcard '*' segments into a regex fragment that matches any non-dot segment
        $pattern = str_replace('\*', '[^\.]+', preg_quote($attribute));

        foreach ($data as $key => $value) {
            if (Str::startsWith($key, $attribute) || (bool) preg_match('/^'.$pattern.'\z/', $key)) {
                foreach ((array) $rules as $ruleKey => $ruleValue) {
                    if (! is_string($ruleKey) || Str::endsWith($key, $ruleKey)) {
                        $this->mergeRules($key, $ruleValue);
                    }
                }
            }
        }
    }

    /**
     * Recursively flatten an array into dot-notation keys.
     *
     * Differs from the Laravel default in that any key path matching the pattern
     * "table.rules.N.conditions" is also preserved as a non-flattened entry in the
     * results. This is necessary because the conditionsCount validator needs to
     * receive the full conditions array (not just scalar leaf values) to count unique
     * field keys across all conditions in a rule.
     *
     * @param  array  $array    The array to flatten.
     * @param  string $prepend  Key prefix accumulated during recursion.
     * @return array
     */
    private function dot($array, $prepend = '')
    {
        $results = [];

        foreach ($array as $key => $value) {
            if (is_array($value)) {
                // Continue recursive flattening for child arrays
                $results = array_merge($results, $this->dot($value, $prepend.$key.'.'));
                // Also keep the conditions array itself intact at the parent key so
                // the conditionsCount validator can count all conditions in a rule
                if (preg_match("@table\.rules\.\d+\.conditions@", $prepend.$key)) {
                    $results[$prepend.$key] = $value;
                }
            } else {
                $results[$prepend.$key] = $value;
            }
        }

        return $results;
    }
}
