<?php
/**
 * TableExportService
 *
 * Serializes a decision table into CSV, Excel (XLSX), or JSON format for download.
 *
 * CSV/Excel format uses three named sections:
 *   ## METADATA  — table-level properties (title, matching_type, etc.)
 *   ## FIELDS    — field definitions (key, title, type)
 *   ## RULES     — one row per rule; columns = field keys + "decision"
 *                  Conditions are encoded as "operator:value" (e.g. "$gte:18").
 *                  "*" means no condition for that field in the rule.
 *
 * Only the first variant is exported for CSV/Excel. JSON exports all variants.
 *
 * @package App\Services
 */

namespace App\Services;

use App\Models\Rule;
use App\Models\Table;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class TableExportService
{
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
        $variant   = $this->getFirstVariant($table);

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
     * Serialize the table to an XLSX file and return the temporary file path.
     * The caller is responsible for streaming the file and deleting it afterward.
     *
     * @param  Table  $table
     * @return string  Absolute path to the generated .xlsx temp file
     */
    public function toExcel(Table $table): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        // Sheet title: max 31 chars, no special characters
        $sheetTitle = preg_replace('/[\/\\\?\*\[\]:]/', '', $table->title ?? 'Table');
        $sheet->setTitle(mb_substr($sheetTitle ?: 'Table', 0, 31));

        // Build the same rows as the CSV and write them into the spreadsheet
        $csv     = $this->toCsv($table);
        $stream  = fopen('php://memory', 'r+');
        fwrite($stream, $csv);
        rewind($stream);

        $rowIndex = 1;
        while (($row = fgetcsv($stream)) !== false) {
            $colIndex = 1;
            foreach ($row as $cellValue) {
                $sheet->setCellValueByColumnAndRow($colIndex, $rowIndex, $cellValue);
                $colIndex++;
            }
            // Bold section header rows
            if (isset($row[0]) && str_starts_with((string)$row[0], '##')) {
                $sheet->getStyle('A' . $rowIndex)->getFont()->setBold(true);
            }
            $rowIndex++;
        }
        fclose($stream);

        // Auto-size columns for readability
        foreach (range('A', $sheet->getHighestColumn()) as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $tmpPath = sys_get_temp_dir() . '/' . uniqid('table_export_', true) . '.xlsx';
        $writer  = new Xlsx($spreadsheet);
        $writer->save($tmpPath);

        return $tmpPath;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Return the first variant of the table, or null if none exist.
     */
    private function getFirstVariant(Table $table)
    {
        $variants = $table->variants()->get();
        return count($variants) > 0 ? $variants[0] : null;
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
                unset($variant['_id']);
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
