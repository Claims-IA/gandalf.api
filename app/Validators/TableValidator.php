<?php
/**
 * TableValidator
 *
 * Custom validation rules specific to decision table creation and update requests.
 * Registered globally by ValidationServiceProvider. Provides complex, cross-field
 * validations that cannot be expressed with built-in Lumen rules: condition value
 * type checking based on the selected operator, rule 'than' type checking based on
 * the table's decision_type, conditions count enforcement, field key existence
 * verification, variant probability sum validation, and decision type consistency
 * checks for scoring-type tables.
 *
 * @package App\Validators
 */

namespace App\Validators;

use App\Services\ConditionsTypes;
use Illuminate\Validation\Validator;
use App\Exceptions\ConditionException;

class TableValidator
{
    private $conditionsTypes;

    public function __construct(ConditionsTypes $conditionsTypes)
    {
        $this->conditionsTypes = $conditionsTypes;
    }

    /**
     * Validate that a condition's value is compatible with its operator's input type.
     *
     * Reads the companion 'condition' attribute (same path but 'value' replaced by
     * 'condition') to look up the operator definition. If the operator requires a
     * specific input type (e.g. $gt requires numeric), the value is validated against
     * that type. Operators with an empty input_type accept any value.
     *
     * @param  string    $attribute  The dot-path of the 'value' field being validated.
     * @param  mixed     $value      The condition threshold value.
     * @param  array     $parameters Not used.
     * @param  Validator $validator  The parent validator (provides access to all data).
     * @return bool
     */
    public function conditionType($attribute, $value, $parameters, Validator $validator)
    {
        try {
            // Look up the sibling 'condition' key at the same nesting level as 'value'
            $condition = $this->conditionsTypes->getCondition(
                array_get(
                    $validator->getData(),
                    str_replace('value', 'condition', $attribute)
                )
            );
        } catch (ConditionException $e) {
            // Unknown operator — fail validation cleanly rather than throwing
            return false;
        }

        // Only validate the input type when the operator requires a specific format
        if ($type = $condition['input_type']) {
            $validator = \Validator::make(
                ['value' => $value],
                ['value' => "required|$type"]
            );
            return !($validator->fails());
        }

        return true;
    }

    /**
     * Validate that a rule's 'than' value is compatible with the table's decision type.
     *
     * For scoring tables the 'than' must be numeric. For decision tables it must match
     * the table's decision_type (alpha_num, numeric, string, or json).
     *
     * @param  string    $attribute
     * @param  mixed     $value      The 'than' value being validated.
     * @param  array     $parameters Not used.
     * @param  Validator $validator  Parent validator.
     * @return bool
     */
    public function ruleThanType($attribute, $value, $parameters, Validator $validator)
    {
        if (in_array(array_get($validator->getData(), 'matching_type', 'first'), ['scoring_sum', 'scoring_max', 'scoring_min', 'scoring_count'])) {
            // Scoring tables must produce a numeric outcome so values can be accumulated
            $type = 'numeric';
        } else {
            $type = array_get($validator->getData(), 'decision_type');
            if (!in_array($type, ['alpha_num', 'numeric', 'string', 'json'])) {
                return false;
            }
        }

        $validator = \Validator::make(
            ['value' => $value],
            ['value' => "required|$type"]
        );

        return !($validator->fails());
    }

    /**
     * Validate that a rule has at least one condition per table field.
     *
     * Compares unique field keys defined in the table against unique field_key values
     * used in the rule's conditions. The number of distinct condition field keys must
     * be >= the number of distinct table field keys.
     *
     * @param  string    $attribute  Dot-path to the conditions array.
     * @param  array     $value      The conditions array.
     * @param  array     $parameters Not used.
     * @param  Validator $validator  Parent validator.
     * @return bool
     */
    public function conditionsCount($attribute, $value, $parameters, Validator $validator)
    {
        $fields = array_get($validator->getData(), 'fields');

        // Build a map of unique field keys (by key or by position as fallback)
        $unique_fields = [];
        $i = 0;
        foreach ($fields as $field) {
            $key = isset($field['key']) ? $field['key'] : $i;
            $unique_fields[$key] = $i;
            $i++;
        }

        // Build a map of unique field_key values used in conditions
        $unique_conditions = [];
        $n = 0;
        foreach ($value as $condition) {
            $key = isset($condition['field_key']) ? $condition['field_key'] : $n;
            $unique_conditions[$key] = $n;
            $n++;
        }

        // Each defined field should have at least one corresponding condition
        return count($unique_conditions) >= count($unique_fields);
    }

    /**
     * Validate that a condition's field_key references a field that exists in the table.
     *
     * Checks the table.fields array in the validator data for a field whose 'key'
     * matches the given value.
     *
     * @param  string    $attribute
     * @param  mixed     $value      The field_key to look up.
     * @param  array     $parameters Not used.
     * @param  Validator $validator  Parent validator.
     * @return bool
     */
    public function conditionsFieldKey($attribute, $value, $parameters, Validator $validator)
    {
        $data = $validator->getData();

        if (!isset($data['table']['fields'])
            or !is_array($data['table']['fields'])
            or count($data['table']['fields']) <= 0
        ) {
            return false;
        }

        foreach ($data['table']['fields'] as $field) {
            if (!isset($field['key'])) {
                return false;
            }
            if ($field['key'] == $value) {
                return true;
            }
        }

        return false;
    }

    /**
     * Validate that variant probabilities sum to exactly 100 when using percent mode.
     *
     * Only enforced when variants_probability is 'percent'. For 'first' and 'random'
     * modes the probability values are not used and this rule is skipped.
     *
     * @param  string    $attribute
     * @param  string    $value      The variants_probability value ('first', 'random', or 'percent').
     * @param  array     $parameters Not used.
     * @param  Validator $validator  Parent validator.
     * @return bool
     */
    public function probabilitySum($attribute, $value, $parameters, Validator $validator)
    {
        if ($value == 'percent') {
            $total = 0;
            foreach ($validator->getData()['variants'] as $variant) {
                $total += isset($variant['probability']) ? $variant['probability'] : 0;
            }
            // All variant probabilities must add up to exactly 100%
            return 100 == $total;
        }

        // Non-percent modes don't require probabilities to sum to 100
        return true;
    }

    /**
     * Validate that the decision_type is 'numeric' for scoring-type tables.
     *
     * Scoring tables accumulate numeric values so any other decision_type would be
     * inconsistent. For decision-type tables any valid decision_type is acceptable.
     *
     * @param  string    $attribute
     * @param  mixed     $value      The decision_type value.
     * @param  array     $parameters Not used.
     * @param  Validator $validator  Parent validator.
     * @return bool
     */
    public function decisionType($attribute, $value, $parameters, Validator $validator)
    {
        if (in_array(array_get($validator->getData(), 'matching_type', 'first'), ['scoring_sum', 'scoring_max', 'scoring_min', 'scoring_count'])) {
            // Scoring tables must use numeric decision type
            return 'numeric' == $value;
        }

        return true;
    }
}
