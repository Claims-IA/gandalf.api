<?php
/**
 * Scoring Service
 *
 * The heart of the Gandalf decision engine. Orchestrates the full decision
 * evaluation lifecycle: loading and validating the table, selecting the appropriate
 * variant (for A/B testing), iterating over every rule, evaluating each condition
 * against the submitted field values (with optional preset transforms), accumulating
 * the result for scoring-type tables or stopping at the first match for decision-type
 * tables, persisting the Decision record, and firing the Make event for analytics.
 *
 * @package App\Services
 */

namespace App\Services;

use App\Models\Table;
use App\Models\Field;
use App\Models\Decision;
use App\Models\Condition;
use \MongoDB\BSON\ObjectID;
use App\Events\Decisions\Make;
use App\Repositories\TablesRepository;
use Illuminate\Contracts\Validation\ValidationException;

class Scoring
{
    private $presets = [];

    private $conditionsTypes;

    private $tablesRepository;

    /**
     * Inject the tables repository and create the conditions engine.
     *
     * @param TablesRepository $tablesRepository
     */
    public function __construct(TablesRepository $tablesRepository)
    {
        $this->tablesRepository = $tablesRepository;
        $this->conditionsTypes = new ConditionsTypes;
    }

    /**
     * Evaluate a decision table against submitted field values and return the result.
     *
     * The evaluation proceeds in these steps:
     * 1. Load the table and validate the submitted values against the field schema.
     * 2. Select the variant (explicit variant_id, or auto-selected via probability strategy).
     * 3. For each rule: evaluate all conditions, set condition.matched, determine the rule decision.
     * 4. Accumulate the final decision: for 'scoring' tables sum all matching rule 'than' values;
     *    for 'decision' tables stop at the first fully matching rule.
     * 5. Fall back to the variant's default_decision if no rule matched.
     * 6. Persist the Decision document and fire the Make event for analytics.
     * 7. Return the consumer-safe decision array (with or without rule details per $showMeta).
     *
     * @param  string $id        MongoDB ObjectID of the table to evaluate.
     * @param  array  $values    Submitted field values from the API request.
     * @param  mixed  $appId     The application ID (used for scoping the saved decision).
     * @param  bool   $showMeta  When true, include rule details in the response (controlled by app setting).
     * @return array             Consumer-safe decision array.
     * @throws \Illuminate\Contracts\Validation\ValidationException
     */
    public function check($id, $values, $appId, $showMeta = false)
    {
        $table = $this->tablesRepository->read($id);
        // Validate that all required fields are present and of the correct type
        $validator = \Validator::make($values, $this->createValidationRules($table));
        if ($validator->fails()) {
            throw new ValidationException($validator);
        }
        $fields = $table->fields();
        // Select the variant to evaluate (may be random or weighted depending on settings)
        $variant = $table->getVariantForCheck(isset($values['variant_id']) ? $values['variant_id'] : null);

        // Initialise the scoring data that will be persisted as a Decision document
        $scoring_data = [
            'table' => [
                '_id' => new ObjectID($table->getId()),
                'title' => $table->title,
                'description' => $table->description,
                'matching_type' => $table->matching_type,
                'variant' => [
                    '_id' => new ObjectID($variant->getId()),
                    'title' => $variant->title,
                    'description' => $variant->description,
                ]
            ],
            'application' => $appId,
            'applications' => $table->getApplications(),
            // Default title/description come from the variant; may be overridden by a matching rule
            'title' => $variant->default_title,
            'description' => $variant->default_description,
            'default_decision' => $variant->default_decision,
            'fields' => $fields->toArray(),
            'rules' => [],
            'request' => $values, // Snapshot the raw request for auditing
        ];
        $final_decision = null;
        $fieldsCollection = $fields->get();

        /** @var \App\Models\Rule $rule */
        foreach ($variant->rules()->get() as $rule) {
            // Snapshot the rule structure for the Decision document
            $scoring_rule = [
                '_id' => new ObjectID($rule->_id),
                'than' => $rule->than,
                'title' => $rule->title,
                'description' => $rule->description,
                'conditions' => [],
            ];
            // Assume all conditions match until one fails
            $conditions_matched = true;
            foreach ($rule->conditions as $condition) {
                $fieldKey = $condition->field_key;
                /** @var Field $field */
                // Find the field definition that corresponds to this condition's field_key
                $field = $fieldsCollection->filter(function ($item) use ($fieldKey) {
                    return $item->key == $fieldKey;
                })->first();

                if (!$field) {
                    // Skip conditions for fields that don't exist in the table definition
                    // (can happen if a field was removed after a decision was submitted)
                    continue;
                }
                // Apply preset transform (if any) then evaluate the condition
                $this->checkCondition($condition, $this->prepareFieldPreset($field, $values[$condition->field_key]));

                if (!$condition->matched) {
                    $conditions_matched = false;
                }
                // Capture the condition with its matched state for the Decision snapshot
                $condition = $condition->getAttributes();
                $scoring_rule['conditions'][] = $condition;
            }
            // Scoring modes: accumulate numeric values across all matching rules
            if ($table->matching_type == 'scoring_sum') {
                // Sum mode: add each matching rule's value to the running total
                if ($conditions_matched) {
                    $final_decision += floatval($rule->than);
                }
            } elseif ($table->matching_type == 'scoring_max') {
                // Max mode: keep the highest value among all matching rules
                if ($conditions_matched) {
                    $value = floatval($rule->than);
                    $final_decision = ($final_decision === null) ? $value : max($final_decision, $value);
                }
            } elseif ($table->matching_type == 'scoring_min') {
                // Min mode: keep the lowest value among all matching rules
                if ($conditions_matched) {
                    $value = floatval($rule->than);
                    $final_decision = ($final_decision === null) ? $value : min($final_decision, $value);
                }
            } elseif ($table->matching_type == 'scoring_count') {
                // Count mode: increment counter for each rule where all conditions matched
                if ($conditions_matched) {
                    $final_decision = ($final_decision === null) ? 1 : $final_decision + 1;
                }
            } else {
                // Decision mode: use the first matching rule's outcome (no further rules checked)
                if (!$final_decision and $conditions_matched) {
                    $final_decision = $rule->than;
                    // Override the default title/description with the matching rule's values
                    $scoring_data['title'] = $rule->title;
                    $scoring_data['description'] = $rule->description;
                }
            }

            // Record what this rule decided (null if no conditions matched)
            $scoring_rule['decision'] = $conditions_matched ? $rule->than : null;
            $scoring_data['rules'][] = $scoring_rule;
        }
        // Use the variant's default_decision when no rule matched (or score is zero)
        $scoring_data['final_decision'] = $final_decision ?: $variant->default_decision;

        // Persist the decision and notify analytics services
        $decision = (new Decision())->fill($scoring_data)->save();
        \Event::fire(new Make($decision));
        $response = $decision->toConsumerArray();
        // The 'rules' detail is gated by the application's show_meta setting
        if (!$showMeta) {
            unset($response['rules']);
        }

        return $response;
    }

    /**
     * Evaluate a single condition and set its 'matched' attribute.
     *
     * Delegates to ConditionsTypes::checkConditionValue() and stores the boolean
     * result on the condition model so it can be included in the Decision snapshot.
     *
     * @param  Condition $condition  The condition to evaluate.
     * @param  mixed     $value      The (possibly preset-transformed) field value.
     * @return void
     */
    private function checkCondition(Condition $condition, $value)
    {
        $condition->matched = $this->conditionsTypes->checkConditionValue(
            $condition->condition,
            $condition->value,
            $value
        );
    }

    /**
     * Apply the field's preset transform to the raw value if one is configured.
     *
     * Preset results are cached in $this->presets indexed by field key so the
     * transform is computed at most once per scoring run even if multiple conditions
     * reference the same field.
     *
     * @param  Field $field  The field whose preset (if any) should be applied.
     * @param  mixed $value  The raw value from the submitted request.
     * @return mixed         The transformed value (or the original if no preset).
     */
    private function prepareFieldPreset(Field $field, $value)
    {
        // Return cached preset result if this field has already been processed
        if (array_key_exists($field->key, $this->presets)) {
            $value = $this->presets[$field->key];
        } elseif ($preset = $field->preset and $preset->condition) {
            // Apply the preset condition (e.g. $is_set converts the value to true/false)
            $value = $this->conditionsTypes->checkConditionValue($preset->condition, $preset->value, $value);
            // Cache for subsequent conditions that reference the same field
            $this->presets[$field->key] = $value;
        }

        return $value;
    }

    /**
     * Build the Lumen validation ruleset for the submitted decision request.
     *
     * Creates a 'present|{type}' rule for each field defined in the table so that
     * missing fields are caught before evaluation. The optional 'variant_id' parameter
     * is also validated as a valid MongoDB ObjectID when present.
     *
     * @param  Table $table  The decision table whose fields define the expected inputs.
     * @return array         Associative array of field keys to validation rule strings.
     */
    private function createValidationRules(Table $table)
    {
        // variant_id is optional but must be a valid MongoDB ObjectID if supplied
        $rules = ['variant_id' => 'sometimes|required|MongoId'];
        if ($fields = $table->fields) {
            foreach ($fields as $item) {
                // 'present' (not 'required') allows null values; the condition evaluator
                // handles null via the $is_null/$is_set operators
                $rules[$item->key] = 'present|' . $this->getValidationRuleByType($item->type);
            }
        }

        return $rules;
    }

    /**
     * Map a field's type string to the corresponding Lumen validation rule.
     *
     * Handles multiple synonyms for the same type (e.g. 'number', 'integer', and
     * 'numeric' all map to the 'numeric' rule) to be tolerant of variations in
     * how field types are specified in the table definition.
     *
     * @param  string $type  The field type string (e.g. 'numeric', 'boolean', 'string').
     * @return string        The Lumen validation rule name.
     */
    private function getValidationRuleByType($type)
    {
        switch (strtolower($type)) {
            case 'number':
            case 'integer':
            case 'numeric':
                $rule = 'numeric';
                break;

            case 'bool':
            case 'boolean':
                $rule = 'boolean';
                break;

            default:
                $rule = 'string';
        }

        return $rule;
    }
}
