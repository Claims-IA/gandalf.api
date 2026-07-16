<?php
/**
 * TableMergeService
 *
 * Builds the full table payload for TablesRepository::createOrUpdate() from a
 * parsed Excel import (one variant per file) merged into the existing table.
 *
 * Merge semantics:
 *  - optimistic lock: the file's exported_at must match the table's current
 *    updated_at unless force is set (TableConflictException → HTTP 409);
 *  - fields are table-level while the sheet is per-variant:
 *      · kept fields take title/type from the sheet but keep their _id and
 *        their preset from the database (presets never travel through Excel),
 *      · new sheet columns become new fields (preset null),
 *      · a field removed from the sheet is dropped only if no OTHER variant
 *        still references it — otherwise an addressed 422 error, never a
 *        silent mutation of a variant that is not in the file;
 *  - the target variant's rules are replaced by the sheet rows (rule _ids from
 *    the hidden column are passed through, preserving analytics continuity;
 *    ids absent from the sheet are deleted);
 *  - all other variants are re-submitted untouched with their _ids so that
 *    Table::setVariants preserves their created_* and is_default flags.
 *
 * @package App\Services\Excel
 */

namespace App\Services\Excel;

use App\Exceptions\ExcelImportException;
use App\Exceptions\TableConflictException;
use App\Models\Table;

class TableMergeService
{
    /**
     * Index (0-based) of the target variant in the payload built by
     * mergeIntoTable — needed by the error translator to rebase the reader's
     * "variants.0.*" cell-map dot-paths.
     */
    public int $targetVariantIndex = 0;

    /**
     * Merge the parsed Excel content into the existing table and return the
     * full payload for TablesRepository::createOrUpdate($payload, $tableId).
     *
     * @param  Table             $existing  The table loaded from the repository.
     * @param  ExcelImportResult $result    Parsed workbook.
     * @param  bool              $force     Skip the optimistic-lock check.
     * @return array
     * @throws TableConflictException  When the table changed since export (409).
     * @throws ExcelImportException    On variant-not-found / field-in-use (422).
     */
    public function mergeIntoTable(Table $existing, ExcelImportResult $result, bool $force = false): array
    {
        $this->checkOptimisticLock($existing, $result, $force);

        $existingVariants = $existing->variants()->get();
        $targetVariant = null;
        foreach ($existingVariants as $variant) {
            if ((string) $variant->_id === $result->variantId) {
                $targetVariant = $variant;
                break;
            }
        }
        if ($targetVariant === null) {
            throw new ExcelImportException([[
                'cell' => null, 'row' => null, 'column' => null, 'field' => null,
                'message' => 'La variante exportée n\'existe plus dans cette table — ré-exportez le fichier.',
            ]]);
        }

        $fields = $this->mergeFields($existing, $existingVariants, $targetVariant, $result);

        // New fields added via the sheet must not break the other variants:
        // their rules get an auto-added $any condition per new field (semantic
        // no-op — the field simply does not constrain those rules), otherwise
        // the conditionsCount validation would rightly reject them.
        $newFieldKeys = $this->collectNewFieldKeys($existing, $result);

        // Assemble the variants array: sheet content replaces the target
        // variant, all others are passed through verbatim (with their _ids).
        $variants = [];
        $index = 0;
        foreach ($existingVariants as $variant) {
            if ((string) $variant->_id === $result->variantId) {
                $this->targetVariantIndex = $index;
                $variants[] = $this->buildTargetVariant($variant, $result);
            } else {
                $variants[] = $this->serializeVariant($variant, $newFieldKeys);
            }
            $index++;
        }

        return [
            'title' => $result->tableTitle !== '' ? $result->tableTitle : (string) $existing->title,
            'description' => $result->tableDescription !== '' ? $result->tableDescription : (string) $existing->description,
            'matching_type' => $result->matchingType,
            'decision_type' => $result->decisionType,
            'variants_probability' => (string) ($existing->variants_probability ?? ''),
            'fields' => $fields,
            'variants' => $variants,
        ];
    }

    /**
     * Build a fresh single-variant create payload from a parsed workbook
     * (mode=create, or a v2 file without embedded ids). All ids are dropped.
     */
    public function buildCreatePayload(ExcelImportResult $result): array
    {
        $fields = [];
        foreach ($result->fields as $field) {
            $fields[] = [
                'key' => $field['key'],
                'title' => $field['title'],
                'type' => $field['type'],
                'source' => 'request',
                'preset' => null,
            ];
        }

        $rules = [];
        foreach ($result->rules as $rule) {
            unset($rule['_id']);
            $rules[] = $rule;
        }

        $this->targetVariantIndex = 0;

        return [
            'title' => $result->tableTitle,
            'description' => $result->tableDescription,
            'matching_type' => $result->matchingType,
            'decision_type' => $result->decisionType,
            'variants_probability' => '',
            'fields' => $fields,
            'variants' => [
                array_filter([
                    'title' => $result->variantTitle,
                    'description' => $result->variantDescription,
                ], fn ($v) => $v !== '') + [
                    'default_decision' => $result->defaultDecision,
                    'is_default' => true,
                    'rules' => $rules,
                ],
            ],
        ];
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function checkOptimisticLock(Table $existing, ExcelImportResult $result, bool $force): void
    {
        if ($force) {
            return;
        }

        // Carbon 1.x (Laravel 5.2) has no ->utc(); use setTimezone instead
        $serverUpdatedAt = $existing->updated_at
            ? $existing->updated_at->copy()->setTimezone('UTC')->toIso8601String()
            : '';
        $fileExportedAt = $result->exportedAt;

        // Compare as UTC instants, tolerating formatting differences
        $serverTs = $serverUpdatedAt !== '' ? strtotime($serverUpdatedAt) : false;
        $fileTs = $fileExportedAt !== '' ? strtotime($fileExportedAt) : false;

        if ($serverTs === false || $fileTs === false || $serverTs !== $fileTs) {
            throw new TableConflictException($serverUpdatedAt, $fileExportedAt);
        }
    }

    /**
     * Merge sheet columns with the table's existing fields.
     *
     * @param  \Illuminate\Support\Collection|array $existingVariants
     * @param  \App\Models\Variant                  $targetVariant
     * @return array  Field payload entries in sheet column order.
     * @throws ExcelImportException  When a removed field is still used by another variant.
     */
    private function mergeFields(Table $existing, $existingVariants, $targetVariant, ExcelImportResult $result): array
    {
        // Existing fields indexed by key
        $existingByKey = [];
        foreach ($existing->fields()->get() as $field) {
            $existingByKey[$field->key] = $field;
        }

        $sheetKeys = [];
        $fields = [];
        foreach ($result->fields as $sheetField) {
            $key = $sheetField['key'];
            $sheetKeys[$key] = true;

            if (isset($existingByKey[$key])) {
                $dbField = $existingByKey[$key];
                // Title/type come from the sheet; _id and preset from the DB
                $fields[] = [
                    '_id' => (string) $dbField->_id,
                    'key' => $key,
                    'title' => $sheetField['title'],
                    'type' => $sheetField['type'],
                    'source' => 'request',
                    'preset' => $dbField->preset ? [
                        'condition' => $dbField->preset->condition,
                        'value' => $dbField->preset->value,
                    ] : null,
                ];
            } else {
                $fields[] = [
                    'key' => $key,
                    'title' => $sheetField['title'],
                    'type' => $sheetField['type'],
                    'source' => 'request',
                    'preset' => null,
                ];
            }
        }

        // Removed fields: only allowed when no OTHER variant references them
        $errors = [];
        foreach ($existingByKey as $key => $dbField) {
            if (isset($sheetKeys[$key])) {
                continue;
            }
            foreach ($existingVariants as $variant) {
                if ((string) $variant->_id === (string) $targetVariant->_id) {
                    continue; // the file is authoritative for the target variant
                }
                $usage = $this->findFieldUsage($variant, $key);
                if ($usage !== null) {
                    $variantLabel = $variant->title ?: (string) $variant->_id;
                    $errors[] = [
                        'cell' => null, 'row' => null, 'column' => null, 'field' => $key,
                        'message' => "La colonne \"$key\" a été supprimée du fichier, mais la variante "
                            . "\"$variantLabel\" l'utilise encore (règle " . $usage . '). '
                            . 'Supprimez d\'abord ces conditions dans l\'interface web, ou remettez la colonne.',
                    ];
                    break;
                }
            }
        }
        if (!empty($errors)) {
            throw new ExcelImportException($errors);
        }

        return $fields;
    }

    /**
     * Return a 1-based rule number using the field, or null when unused.
     */
    private function findFieldUsage($variant, string $fieldKey): ?int
    {
        $ruleNumber = 1;
        foreach ($variant->rules()->get() as $rule) {
            foreach ($rule->conditions()->get() as $condition) {
                if ($condition->field_key === $fieldKey) {
                    return $ruleNumber;
                }
            }
            $ruleNumber++;
        }
        return null;
    }

    /**
     * Target variant: rules from the sheet, scalars from _meta (title,
     * description, default_decision), the rest carried over from the DB.
     * is_default / created_* are preserved by Table::setVariants via the _id.
     */
    private function buildTargetVariant($variant, ExcelImportResult $result): array
    {
        $payload = [
            '_id' => (string) $variant->_id,
            'default_decision' => $result->defaultDecision,
            'probability' => $variant->probability ?? 0,
            'rules' => $result->rules,
        ];

        // Optional scalars: only include when non-empty (they carry a
        // between:2,x validation constraint that rejects empty strings)
        foreach ([
            'title' => $result->variantTitle,
            'description' => $result->variantDescription,
            'default_title' => (string) ($variant->default_title ?? ''),
            'default_description' => (string) ($variant->default_description ?? ''),
        ] as $attr => $value) {
            if ($value !== '') {
                $payload[$attr] = $value;
            }
        }

        return $payload;
    }

    /**
     * Field keys present in the sheet but absent from the existing table.
     *
     * @return string[]
     */
    private function collectNewFieldKeys(Table $existing, ExcelImportResult $result): array
    {
        $existingKeys = [];
        foreach ($existing->fields()->get() as $field) {
            $existingKeys[$field->key] = true;
        }

        $newKeys = [];
        foreach ($result->fields as $sheetField) {
            if (!isset($existingKeys[$sheetField['key']])) {
                $newKeys[] = $sheetField['key'];
            }
        }
        return $newKeys;
    }

    /**
     * Serialize an untouched variant back into payload form, keeping every
     * _id (variant, rules, conditions) so setVariants preserves identity.
     * Fields newly added via the sheet get an auto-appended $any condition on
     * every rule so the variant keeps passing conditionsCount unchanged.
     *
     * @param  string[] $newFieldKeys
     */
    private function serializeVariant($variant, array $newFieldKeys = []): array
    {
        $payload = [
            '_id' => (string) $variant->_id,
            'default_decision' => $variant->default_decision,
            'probability' => $variant->probability ?? 0,
            'rules' => [],
        ];

        foreach ([
            'title' => (string) ($variant->title ?? ''),
            'description' => (string) ($variant->description ?? ''),
            'default_title' => (string) ($variant->default_title ?? ''),
            'default_description' => (string) ($variant->default_description ?? ''),
        ] as $attr => $value) {
            if ($value !== '') {
                $payload[$attr] = $value;
            }
        }

        foreach ($variant->rules()->get() as $rule) {
            $rulePayload = [
                '_id' => (string) $rule->_id,
                'title' => (string) ($rule->title ?? ''),
                'than' => $rule->than,
                'conditions' => [],
            ];
            $description = (string) ($rule->description ?? '');
            if ($description !== '') {
                $rulePayload['description'] = $description;
            }
            foreach ($rule->conditions()->get() as $condition) {
                $rulePayload['conditions'][] = [
                    '_id' => (string) $condition->_id,
                    'field_key' => $condition->field_key,
                    'condition' => $condition->condition,
                    'value' => $condition->value,
                ];
            }
            foreach ($newFieldKeys as $key) {
                $rulePayload['conditions'][] = [
                    'field_key' => $key,
                    'condition' => '$any',
                    'value' => ConditionCellCodec::VALUELESS_VALUE,
                ];
            }
            $payload['rules'][] = $rulePayload;
        }

        return $payload;
    }
}
