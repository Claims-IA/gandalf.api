<?php
/**
 * TableImportService
 *
 * Parses an uploaded CSV, Excel (XLSX/XLS), or JSON file and creates a new
 * decision table in the current application (project).
 *
 * Expected CSV/Excel format — three named sections:
 *
 *   ## METADATA
 *   title,<table title>
 *   description,<optional description>
 *   matching_type,<first|scoring_sum|scoring_max|scoring_min|scoring_count>
 *   decision_type,<string|numeric|alpha_num|json>
 *   default_decision,<default outcome when no rule matches>
 *
 *   ## FIELDS
 *   key,title,type
 *   <key>,<title>,<numeric|boolean|string>
 *   ...
 *
 *   ## RULES
 *   <field_key1>,<field_key2>,...,decision
 *   <operator:value|*>,<operator:value|*>,...,<outcome>
 *   ...
 *
 * A cell value of "*" means no condition is generated for that field in the
 * rule (the rule can match regardless of that field's value). All other cells
 * use the format "operator:value" (e.g. "$gte:18", "$eq:FR").
 *
 * JSON format is the output of TableExportService::toJson() — a plain object
 * matching the table schema without _id fields.
 *
 * @package App\Services
 */

namespace App\Services;

use App\Models\Table;
use App\Repositories\TablesRepository;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\IOFactory;

class TableImportService
{
    private TablesRepository $repository;

    public function __construct(TablesRepository $repository)
    {
        $this->repository = $repository;
    }

    /**
     * Parse the uploaded file and persist it as a new decision table.
     *
     * Format is detected from the file extension. The caller must already have
     * validated that a file was uploaded and that its extension is one of
     * json, csv, xlsx, xls.
     *
     * @param  UploadedFile $file
     * @return Table         Newly created table
     * @throws \InvalidArgumentException  On unsupported format or parse failure
     * @throws \RuntimeException          On structural validation failure
     */
    public function fromFile(UploadedFile $file): Table
    {
        $ext = strtolower($file->getClientOriginalExtension());

        switch ($ext) {
            case 'json':
                $data = $this->fromJson(file_get_contents($file->getRealPath()));
                break;
            case 'csv':
                $data = $this->fromCsv(file_get_contents($file->getRealPath()));
                break;
            case 'xlsx':
            case 'xls':
                $data = $this->fromExcel($file->getRealPath());
                break;
            default:
                throw new \InvalidArgumentException("Format de fichier non supporté: $ext. Formats acceptés: json, csv, xlsx, xls.");
        }

        $this->validateTableData($data);

        return $this->repository->createOrUpdate($data);
    }

    // -------------------------------------------------------------------------
    // Format parsers
    // -------------------------------------------------------------------------

    /**
     * Parse a JSON string into a table data array.
     *
     * Supports the current export format where the default variant lives under
     * the "table" key and additional variants live under "variants". Also accepts
     * the legacy flat format where all variants are in a single "variants" array.
     *
     * _id fields are stripped so the import always creates a fresh document.
     *
     * @param  string $content
     * @return array
     */
    private function fromJson(string $content): array
    {
        $data = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \InvalidArgumentException('JSON invalide: ' . json_last_error_msg());
        }

        if (!is_array($data)) {
            throw new \InvalidArgumentException('Le fichier JSON doit contenir un objet à la racine.');
        }

        // Current format: default variant under "table", others under "variants"
        if (isset($data['table'])) {
            $defaultVariant             = $data['table'];
            $defaultVariant['is_default'] = true;
            $otherVariants              = $data['variants'] ?? [];
            foreach ($otherVariants as &$v) {
                $v['is_default'] = false;
            }
            $data['variants'] = array_merge([$defaultVariant], array_values($otherVariants));
            unset($data['table']);
        }

        return $this->stripIds($data);
    }

    /**
     * Parse a CSV string into a table data array.
     *
     * @param  string $content
     * @return array
     */
    private function fromCsv(string $content): array
    {
        // Normalize line endings
        $content = str_replace(["\r\n", "\r"], "\n", $content);

        $stream = fopen('php://memory', 'r+');
        fwrite($stream, $content);
        rewind($stream);

        $rows = [];
        while (($row = fgetcsv($stream)) !== false) {
            $rows[] = $row;
        }
        fclose($stream);

        return $this->parseCsvSections($rows);
    }

    /**
     * Parse an Excel file into a table data array by converting it to rows
     * and delegating to the same section parser used for CSV.
     *
     * @param  string $path  Absolute path to the Excel file
     * @return array
     */
    private function fromExcel(string $path): array
    {
        $spreadsheet = IOFactory::load($path);
        $sheet = $spreadsheet->getActiveSheet();

        $rows = [];
        foreach ($sheet->getRowIterator() as $row) {
            $cells = [];
            foreach ($row->getCellIterator() as $cell) {
                $cells[] = (string)($cell->getValue() ?? '');
            }
            // Drop trailing empty cells to keep rows clean
            while (!empty($cells) && trim(end($cells)) === '') {
                array_pop($cells);
            }
            // Skip completely empty rows
            if (!empty(array_filter($cells, fn($c) => trim($c) !== ''))) {
                $rows[] = $cells;
            }
        }

        return $this->parseCsvSections($rows);
    }

    // -------------------------------------------------------------------------
    // Core section parser (shared between CSV and Excel)
    // -------------------------------------------------------------------------

    /**
     * Parse the three-section format (METADATA / FIELDS / RULES) from an array
     * of rows and return a table data array ready for TablesRepository::createOrUpdate().
     *
     * @param  array $rows  Array of string arrays (one per row)
     * @return array
     */
    private function parseCsvSections(array $rows): array
    {
        $section  = null;
        $metadata = [];
        $fields   = [];
        $rules    = [];
        $headers  = [];  // field key columns from the RULES header row

        foreach ($rows as $row) {
            $first = trim($row[0] ?? '');

            // Detect section markers
            if ($first === '## METADATA') { $section = 'metadata'; continue; }
            if ($first === '## FIELDS')   { $section = 'fields';   continue; }
            if ($first === '## RULES')    { $section = 'rules';    continue; }

            // Skip blank rows
            if ($first === '') { continue; }

            switch ($section) {
                case 'metadata':
                    // Each row is a key => value pair
                    $metadata[trim($row[0])] = isset($row[1]) ? trim($row[1]) : '';
                    break;

                case 'fields':
                    // Skip the header row (key, title, type)
                    if ($first === 'key') {
                        continue 2;
                    }
                    $fields[] = [
                        'key'    => trim($row[0]),
                        'title'  => isset($row[1]) ? trim($row[1]) : trim($row[0]),
                        'type'   => isset($row[2]) ? trim($row[2]) : 'string',
                        'source' => 'request',
                        'preset' => [],
                    ];
                    break;

                case 'rules':
                    // The first row in the RULES section is the column header
                    if (empty($headers)) {
                        $headers = array_map('trim', $row);
                        continue 2;
                    }

                    // Data row: build a rule with its conditions
                    $conditions    = [];
                    $decisionValue = '';

                    foreach ($headers as $colIdx => $colName) {
                        $cell = isset($row[$colIdx]) ? trim($row[$colIdx]) : '*';

                        if ($colName === 'decision') {
                            $decisionValue = $cell;
                            continue;
                        }

                        $parsed = $this->parseConditionCell($cell);
                        if ($parsed !== null) {
                            $conditions[] = array_merge(
                                ['field_key' => $colName],
                                $parsed
                            );
                        }
                    }

                    if (!empty($conditions) || $decisionValue !== '') {
                        $rules[] = [
                            'than'       => $decisionValue,
                            'conditions' => $conditions,
                        ];
                    }
                    break;
            }
        }

        return [
            'title'               => $metadata['title']            ?? '',
            'description'         => $metadata['description']      ?? '',
            'matching_type'       => $metadata['matching_type']    ?? 'first',
            'decision_type'       => $metadata['decision_type']    ?? 'string',
            'variants_probability' => $metadata['variants_probability'] ?? '',
            'fields'              => $fields,
            'variants'            => [
                [
                    'title'               => 'Default Variant',
                    'description'         => '',
                    'default_decision'    => $metadata['default_decision'] ?? '',
                    'default_title'       => '',
                    'default_description' => '',
                    'is_default'          => true,
                    'rules'               => $rules,
                ]
            ],
        ];
    }

    /**
     * Parse a single condition cell from a CSV/Excel rule row.
     *
     * Returns null when the cell is "*" or empty (no condition for this field).
     * Otherwise splits "operator:value" on the first colon only, so that
     * compound values like "$in:visa,mastercard" are handled correctly.
     *
     * @param  string $cell
     * @return array|null  ['condition' => '...', 'value' => '...'] or null
     */
    private function parseConditionCell(string $cell): ?array
    {
        if ($cell === '*' || $cell === '') {
            return null;
        }

        // Split on first colon only to preserve values that contain colons
        $parts = explode(':', $cell, 2);

        return [
            'condition' => $parts[0],
            'value'     => $parts[1] ?? '',
        ];
    }

    // -------------------------------------------------------------------------
    // Validation
    // -------------------------------------------------------------------------

    /**
     * Lightweight structural validation before attempting to persist the table.
     * Throws a \RuntimeException with a JSON-encodable error array on failure.
     *
     * Full Laravel/Lumen field-level validation is not run here because the
     * import path bypasses the request validation rules in TablesController.
     * This pre-flight check catches the most common errors with user-friendly messages.
     *
     * @param  array $data  Parsed table data
     * @throws \RuntimeException
     */
    private function validateTableData(array $data): void
    {
        $errors = [];

        if (empty($data['title'])) {
            $errors[] = 'Le titre de la table est obligatoire (section ## METADATA, ligne title).';
        }

        $validMatchingTypes = ['first', 'scoring_sum', 'scoring_max', 'scoring_min', 'scoring_count'];
        if (empty($data['matching_type']) || !in_array($data['matching_type'], $validMatchingTypes)) {
            $errors[] = 'matching_type invalide ou absent. Valeurs acceptées: ' . implode(', ', $validMatchingTypes) . '.';
        }

        $validDecisionTypes = ['string', 'numeric', 'alpha_num', 'json'];
        if (empty($data['decision_type']) || !in_array($data['decision_type'], $validDecisionTypes)) {
            $errors[] = 'decision_type invalide ou absent. Valeurs acceptées: ' . implode(', ', $validDecisionTypes) . '.';
        }

        if (empty($data['fields']) || !is_array($data['fields'])) {
            $errors[] = 'Aucun champ défini (section ## FIELDS manquante ou vide).';
        } else {
            foreach ($data['fields'] as $i => $field) {
                if (empty($field['key'])) {
                    $errors[] = "Champ #$i : la clé (key) est obligatoire.";
                }
                $validTypes = ['numeric', 'boolean', 'string'];
                if (!empty($field['type']) && !in_array($field['type'], $validTypes)) {
                    $errors[] = "Champ '{$field['key']}' : type invalide '{$field['type']}'. Valeurs acceptées: " . implode(', ', $validTypes) . '.';
                }
            }
        }

        $variant = $data['variants'][0] ?? null;
        if (!$variant || empty($variant['rules'])) {
            $errors[] = 'Aucune règle définie (section ## RULES manquante ou vide).';
        }

        if (isset($data['fields']) && isset($variant['rules'])) {
            $validFieldKeys = array_column($data['fields'], 'key');
            foreach ($variant['rules'] as $ruleIdx => $rule) {
                foreach ($rule['conditions'] ?? [] as $condIdx => $cond) {
                    if (!in_array($cond['field_key'], $validFieldKeys, true)) {
                        $errors[] = "Règle #$ruleIdx, condition #$condIdx: champ inconnu '{$cond['field_key']}'.";
                    }
                }
            }
        }

        if (!empty($errors)) {
            throw new \RuntimeException(json_encode($errors));
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Remove _id fields at all nesting levels so the imported data always
     * creates a fresh document rather than attempting to reuse existing IDs.
     *
     * @param  array $data
     * @return array
     */
    private function stripIds(array $data): array
    {
        unset($data['_id'], $data['applications']);

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
                        unset($rule['_id']);
                        if (isset($rule['conditions']) && is_array($rule['conditions'])) {
                            foreach ($rule['conditions'] as &$cond) {
                                unset($cond['_id']);
                            }
                        }
                    }
                }
            }
        }

        return $data;
    }
}
