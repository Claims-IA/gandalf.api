<?php
/**
 * TableExportService
 *
 * Serializes a decision table into CSV, Excel (XLSX), or JSON format for download.
 *
 * CSV format uses three named sections (legacy, create-only round trip):
 *   ## METADATA  — table-level properties (title, matching_type, etc.)
 *   ## FIELDS    — field definitions (key, title, type)
 *   ## RULES     — one row per rule; columns = field keys + "decision"
 *                  Conditions are encoded as "operator:value" (e.g. "$gte:18").
 *                  "*" means no condition for that field in the rule.
 *
 * Excel format is the round-trip workbook built by Excel\ExcelTableWriter:
 * one variant per file, human-readable condition grammar, hidden _meta sheet
 * carrying table/variant ids and the optimistic-lock token. Re-importing an
 * Excel export updates the original table.
 *
 * The default variant (is_default = true) is exported for CSV (and for Excel
 * when no variant id is given). JSON exports all variants: the default variant
 * under the "table" key, and the others under the "variants" key.
 *
 * @package App\Services
 */

namespace App\Services;

use App\Exceptions\VariantNotFound;
use App\Models\Rule;
use App\Models\Table;
use App\Services\Excel\ExcelTableWriter;

class TableExportService
{
    private ExcelTableWriter $excelWriter;

    public function __construct(ExcelTableWriter $excelWriter)
    {
        $this->excelWriter = $excelWriter;
    }

    /**
     * Serialize the table to a pretty-printed JSON string.
     * _id fields and runtime analytics fields are stripped so the output
     * can be re-imported to create a fresh table.
     *
     * @param  Table  $table
     * @return string  JSON string
     */
    public function toJson(Table $table): string
    {
        $data = $table->toArray();
        $data = $this->stripIds($data);
        unset($data['applications']);

        // Split variants: default variant → "table" key; others → "variants" key
        $defaultVariant = null;
        $otherVariants  = [];
        foreach ($data['variants'] ?? [] as $variant) {
            if (!empty($variant['is_default'])) {
                $defaultVariant = $variant;
            } else {
                $otherVariants[] = $variant;
            }
        }

        // Fallback: if no variant is flagged, treat the first one as default
        if ($defaultVariant === null && !empty($data['variants'])) {
            $defaultVariant = array_shift($data['variants']);
            $otherVariants  = array_values($data['variants']);
        }

        unset($data['variants']);
        $data['table']    = $defaultVariant;
        $data['variants'] = $otherVariants;

        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Serialize the table to a CSV string (first variant only).
     *
     * @param  Table  $table
     * @return string  CSV content
     */
    public function toCsv(Table $table): string
    {
        $fieldKeys = $this->buildFieldKeys($table);
        $variant   = $this->getDefaultVariant($table);

        $lines = [];

        // METADATA section
        $lines[] = ['## METADATA'];
        $lines[] = ['title',            $table->title ?? ''];
        $lines[] = ['description',      $table->description ?? ''];
        $lines[] = ['matching_type',    $table->matching_type ?? 'first'];
        $lines[] = ['decision_type',    $table->decision_type ?? 'string'];
        $lines[] = ['default_decision', $variant ? ($variant->default_decision ?? '') : ''];
        $lines[] = [];

        // FIELDS section
        $lines[] = ['## FIELDS'];
        $lines[] = ['key', 'title', 'type'];
        foreach ($table->fields()->get() as $field) {
            $lines[] = [$field->key, $field->title, $field->type];
        }
        $lines[] = [];

        // RULES section
        $lines[] = ['## RULES'];
        $lines[] = array_merge($fieldKeys, ['decision']);

        if ($variant) {
            foreach ($variant->rules()->get() as $rule) {
                $lines[] = $this->buildRuleRow($rule, $fieldKeys);
            }
        }

        // Render to CSV string via a temp memory stream
        $output = fopen('php://temp', 'r+');
        foreach ($lines as $row) {
            fputcsv($output, $row);
        }
        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        return $csv;
    }

    /**
     * Serialize one variant of the table to a round-trip XLSX file and return
     * the temporary file path. The caller is responsible for streaming the
     * file and deleting it afterward.
     *
     * The workbook embeds the table/variant ids and the table's updated_at
     * timestamp (hidden _meta sheet) so that re-importing the file updates the
     * original table with optimistic-lock protection.
     *
     * @param  Table       $table
     * @param  string|null $variantId  Variant to export; null = default variant.
     * @return string  Absolute path to the generated .xlsx temp file
     * @throws VariantNotFound  When $variantId does not exist on the table.
     */
    public function toExcel(Table $table, ?string $variantId = null): string
    {
        $variant = $variantId === null
            ? $this->getDefaultVariant($table)
            : $this->getVariantById($table, $variantId);

        if (!$variant) {
            throw new VariantNotFound();
        }

        return $this->excelWriter->write($table, $variant);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Return the default variant (is_default = true), falling back to the first one.
     */
    private function getDefaultVariant(Table $table)
    {
        $variants = $table->variants()->get();
        foreach ($variants as $variant) {
            if ($variant->is_default) {
                return $variant;
            }
        }
        return count($variants) > 0 ? $variants[0] : null;
    }

    /**
     * Return the variant with the given _id, or null when it does not exist.
     *
     * @return \App\Models\Variant|null
     */
    private function getVariantById(Table $table, string $variantId)
    {
        foreach ($table->variants()->get() as $variant) {
            if ((string) $variant->_id === $variantId) {
                return $variant;
            }
        }
        return null;
    }

    /**
     * Return an ordered array of field keys for the table.
     *
     * @return string[]
     */
    private function buildFieldKeys(Table $table): array
    {
        $keys = [];
        foreach ($table->fields()->get() as $field) {
            $keys[] = $field->key;
        }
        return $keys;
    }

    /**
     * Build one CSV row representing a single rule.
     * Each field column contains "operator:value" or "*" if no condition exists
     * for that field in this rule.
     *
     * @param  Rule     $rule
     * @param  string[] $fieldKeys
     * @return string[]
     */
    private function buildRuleRow(Rule $rule, array $fieldKeys): array
    {
        // Index conditions by field_key for O(1) lookup
        $conditionMap = [];
        foreach ($rule->conditions()->get() as $condition) {
            $conditionMap[$condition->field_key] = $condition;
        }

        $row = [];
        foreach ($fieldKeys as $key) {
            if (isset($conditionMap[$key])) {
                $c     = $conditionMap[$key];
                $row[] = $c->condition . ':' . $c->value;
            } else {
                $row[] = '*';
            }
        }
        // Decision value is always the last column
        $row[] = $rule->than;

        return $row;
    }

    /**
     * Recursively strip _id fields (and analytics-only fields) from the
     * table array so it can be re-imported as a fresh document.
     *
     * @param  array $data
     * @return array
     */
    private function stripIds(array $data): array
    {
        unset($data['_id']);

        if (isset($data['fields']) && is_array($data['fields'])) {
            foreach ($data['fields'] as &$field) {
                unset($field['_id']);
                if (isset($field['preset']) && is_array($field['preset'])) {
                    unset($field['preset']['_id']);
                }
            }
        }

        if (isset($data['variants']) && is_array($data['variants'])) {
            foreach ($data['variants'] as &$variant) {
                // Strip identity and system-managed fields; keep is_default (portable)
                unset($variant['_id'], $variant['created_at'], $variant['created_by'],
                      $variant['updated_at'], $variant['updated_by']);
                if (isset($variant['rules']) && is_array($variant['rules'])) {
                    foreach ($variant['rules'] as &$rule) {
                        unset($rule['_id'], $rule['probability'], $rule['requests']);
                        if (isset($rule['conditions']) && is_array($rule['conditions'])) {
                            foreach ($rule['conditions'] as &$cond) {
                                unset($cond['_id'], $cond['probability'], $cond['requests'], $cond['matched']);
                            }
                        }
                    }
                }
            }
        }

        return $data;
    }
}
