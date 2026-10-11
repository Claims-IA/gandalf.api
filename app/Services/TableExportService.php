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
 * carrying table/version/variant ids and the optimistic-lock token.
 * Re-importing an Excel export updates the original table, or the version it
 * comes from (TableImportService).
 *
 * Each format exports the table's content or one of its versions'
 * (VERSIONS.md, batch 2b): `$content` is the table or the version, the
 * identity (title, types) always the table's. There is no default variant:
 * CSV and Excel export the variant asked for, the first one otherwise. JSON
 * exports all variants: the first under the "table" key, the others under the
 * "variants" key.
 *
 * @package App\Services
 */

namespace App\Services;

use App\Exceptions\VariantNotFound;
use App\Models\Rule;
use App\Models\Table;
use App\Models\TableVersion;
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
     * @param  Table                   $table
     * @param  Table|TableVersion|null $content  The version to export; the table by default.
     * @return string  JSON string
     */
    public function toJson(Table $table, Table|TableVersion|null $content = null): string
    {
        $data = $table->toArray();
        if ($content instanceof TableVersion) {
            $version = $content->toArray();
            $data['fields'] = $version['fields'] ?? [];
            $data['variants'] = $version['variants'] ?? [];
            $data['variants_probability'] = $version['variants_probability'] ?? '';
        }
        $data = $this->stripIds($data);
        unset($data['applications'], $data['active_version_id']);

        // Split variants: first variant → "table" key; others → "variants" key
        $variants = array_values($data['variants'] ?? []);
        unset($data['variants']);
        $data['table']    = array_shift($variants);
        $data['variants'] = $variants;

        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Serialize one variant of the table, or of one of its versions, to a CSV
     * string.
     *
     * @param  Table                   $table
     * @param  Table|TableVersion|null $content    The version to export; the table by default.
     * @param  string|null             $variantId  Variant to export; null = first variant.
     * @return string  CSV content
     * @throws VariantNotFound  When $variantId does not exist in the content.
     */
    public function toCsv(Table $table, Table|TableVersion|null $content = null, ?string $variantId = null): string
    {
        $content   = $content ?: $table;
        $fieldKeys = $this->buildFieldKeys($content);
        $variant   = $this->findVariant($content, $variantId);

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
        foreach ($content->fields()->get() as $field) {
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
     * Serialize one variant of the table, or of one of its versions, to a
     * round-trip XLSX file and return the temporary file path. The caller is
     * responsible for streaming the file and deleting it afterward.
     *
     * The workbook embeds the table/version/variant ids and the exported
     * content's updated_at timestamp (hidden _meta sheet) so that re-importing
     * the file updates its origin with optimistic-lock protection.
     *
     * @param  Table                   $table
     * @param  string|null             $variantId  Variant to export; null = first variant.
     * @param  TableVersion|null       $version    The version exported, recorded in _meta.
     * @param  Table|TableVersion|null $content    Where the content comes from; the table by default.
     * @return string  Absolute path to the generated .xlsx temp file
     * @throws VariantNotFound  When $variantId does not exist in the content.
     */
    public function toExcel(
        Table $table,
        ?string $variantId = null,
        ?TableVersion $version = null,
        Table|TableVersion|null $content = null
    ): string {
        $content = $content ?: $table;

        return $this->excelWriter->write($table, $this->findVariant($content, $variantId), $version, $content);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * The variant with the given _id, or the first one when no id is given.
     *
     * @return \App\Models\Variant
     * @throws VariantNotFound  When the variant does not exist (404).
     */
    private function findVariant(Table|TableVersion $content, ?string $variantId)
    {
        foreach ($content->variants()->get() as $variant) {
            if ($variantId === null || (string) $variant->_id === $variantId) {
                return $variant;
            }
        }
        throw new VariantNotFound();
    }

    /**
     * Return an ordered array of field keys for the table.
     *
     * @return string[]
     */
    private function buildFieldKeys(Table|TableVersion $content): array
    {
        $keys = [];
        foreach ($content->fields()->get() as $field) {
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
                // Strip identity and system-managed fields
                unset($variant['_id'], $variant['created_at'], $variant['created_by'],
                      $variant['updated_at'], $variant['updated_by'], $variant['is_default']);
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
