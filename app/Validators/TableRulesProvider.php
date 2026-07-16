<?php
/**
 * TableRulesProvider
 *
 * Single source of truth for the Lumen validation rules applied to a full
 * decision table payload. Used both by TablesController (create/update
 * endpoints) and by the Excel import path (TablesController::import), so that
 * imported tables go through exactly the same validation as UI-edited ones.
 *
 * Condition operator values (e.g. $eq, $gt) are generated dynamically from the
 * ConditionsTypes service so the allowed list stays in sync with the engine
 * without duplicating operator definitions here.
 *
 * @package App\Validators
 */

namespace App\Validators;

use App\Services\ConditionsTypes;

class TableRulesProvider
{
    /**
     * Field keys that would collide with reserved column headers of the Excel
     * export format (plus the historical 'variant_id' check-time parameter).
     */
    public const RESERVED_FIELD_KEYS = ['variant_id', 'decision', '_rule_id', 'rule_title', 'rule_description'];

    /**
     * Return the full validation rule set for a decision table payload.
     *
     * @param  ConditionsTypes $conditionsTypes
     * @return array
     */
    public static function rules(ConditionsTypes $conditionsTypes): array
    {
        // Build the comma-separated list of valid condition operators for use in "in:" rules
        $condRules = $conditionsTypes->getConditionsRules();

        return [
            'title' => 'sometimes|string',
            'description' => 'sometimes|string',
            'matching_type' => 'required|in:first,scoring_sum,scoring_max,scoring_min,scoring_count',
            'decision_type' => 'required|in:alpha_num,numeric,string,json|decision_type',
            'fields' => 'required|array',
            'fields.*._id' => 'sometimes|mongoId',
            'fields.*.title' => 'required|string',
            'fields.*.key' => 'required|string|not_in:' . implode(',', self::RESERVED_FIELD_KEYS),
            'fields.*.type' => 'required|in:numeric,boolean,string',
            'fields.*.source' => 'required|in:request',
            'fields.*.preset' => 'present|array',
            'fields.*.preset._id' => 'mongoId',
            'fields.*.preset.value' => 'required_with:fields.*.preset',
            'fields.*.preset.condition' => 'required_with:fields.*.preset|in:' . $condRules,
            'variants_probability' => 'sometimes|in:first,random,percent|probabilitySum',
            'variants' => 'required|array',
            'variants.*._id' => 'mongoId',
            'variants.*.is_default' => 'sometimes|boolean',
            'variants.*.default_decision' => 'required|ruleThanType',
            'variants.*.title' => 'sometimes|string|between:2,128',
            'variants.*.description' => 'sometimes|string|between:2,128',
            'variants.*.default_title' => 'sometimes|string|between:2,128',
            'variants.*.default_description' => 'sometimes|string|between:2,512',
            // Note: 0 is the model default (probability unused outside 'percent' mode);
            // probabilitySum still enforces sum == 100 when variants_probability=percent.
            'variants.*.probability' => 'sometimes|integer|between:0,100',
            'variants.*.rules' => 'required|array',
            'variants.*.rules.*._id' => 'mongoId',
            'variants.*.rules.*.than' => 'required|ruleThanType',
            'variants.*.rules.*.description' => 'string|between:2,128',
            'variants.*.rules.*.conditions' => 'required|array|conditionsCount',
            'variants.*.rules.*.conditions.*._id' => 'mongoId',
            'variants.*.rules.*.conditions.*.field_key' => 'required|string',
            'variants.*.rules.*.conditions.*.condition' => 'required|in:' . $condRules,
            'variants.*.rules.*.conditions.*.value' => 'required|conditionType',
        ];
    }
}
