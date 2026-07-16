<?php
/**
 * ExcelTableReader
 *
 * Parses a round-trip Excel workbook (format gandalf-xlsx-v2, produced by
 * ExcelTableWriter and possibly edited by the user) into an ExcelImportResult.
 *
 * Robustness rules:
 *  - the layout is re-validated, never trusted (protection is only a UX
 *    guardrail): row 2 must contain the DECISION sentinel exactly once;
 *  - fully blank rows are skipped, not treated as terminators;
 *  - all grammar/structure errors are collected in ONE pass and thrown
 *    together (ExcelImportException) so the user fixes everything at once;
 *  - cell values are read raw (getValue, never getCalculatedValue) and
 *    formula cells are rejected with an addressed error;
 *  - numeric/boolean coercion by Excel is undone by careful stringification.
 *
 * @package App\Services\Excel
 */

namespace App\Services\Excel;

use App\Exceptions\ConditionCellParseException;
use App\Exceptions\ExcelImportException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class ExcelTableReader
{
    private ConditionCellCodec $codec;

    /** @var array Collected error entries for ExcelImportException */
    private array $errors = [];

    public function __construct(ConditionCellCodec $codec)
    {
        $this->codec = $codec;
    }

    /**
     * Cheap v2-format detection without loading the whole workbook.
     */
    public function isRoundTripFile(string $path): bool
    {
        try {
            $reader = IOFactory::createReaderForFile($path);
            if (!method_exists($reader, 'listWorksheetNames')) {
                return false;
            }
            return in_array(ExcelLayout::SHEET_META, $reader->listWorksheetNames($path), true);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Parse the workbook into an ExcelImportResult.
     *
     * @throws ExcelImportException  With the full list of addressed errors.
     */
    public function read(string $path): ExcelImportResult
    {
        $this->errors = [];
        $result = new ExcelImportResult();

        $spreadsheet = IOFactory::load($path);

        $metaSheet = $spreadsheet->getSheetByName(ExcelLayout::SHEET_META);
        if ($metaSheet === null) {
            throw new ExcelImportException([[
                'cell' => null, 'row' => null, 'column' => null, 'field' => null,
                'message' => 'Format non reconnu : feuille _meta absente. Ce fichier n\'est pas un export Gandalf.',
            ]]);
        }
        $this->readMeta($metaSheet, $result);

        $rulesSheet = $spreadsheet->getSheetByName(ExcelLayout::SHEET_RULES);
        if ($rulesSheet === null) {
            throw new ExcelImportException([[
                'cell' => null, 'row' => null, 'column' => null, 'field' => null,
                'message' => 'Format non reconnu : feuille "' . ExcelLayout::SHEET_RULES . '" absente.',
            ]]);
        }

        $columns = $this->readHeader($rulesSheet, $result);
        if ($columns !== null) {
            $this->readRules($rulesSheet, $columns, $result);
        }

        if (!empty($this->errors)) {
            throw new ExcelImportException($this->errors);
        }

        return $result;
    }

    // -------------------------------------------------------------------------
    // _meta sheet
    // -------------------------------------------------------------------------

    private function readMeta(Worksheet $sheet, ExcelImportResult $result): void
    {
        // Read by label (column A) rather than by fixed row, tolerating reordering
        $values = [];
        $highestRow = min($sheet->getHighestDataRow(), 50);
        for ($row = 1; $row <= $highestRow; $row++) {
            $label = trim($this->cellToString($sheet, 'A' . $row));
            if ($label !== '') {
                $values[$label] = trim($this->cellToString($sheet, 'B' . $row));
            }
        }

        $result->formatVersion = $values['format_version'] ?? '';
        $result->tableId = $this->normalizeId($values['table_id'] ?? '');
        $result->variantId = $this->normalizeId($values['variant_id'] ?? '');
        $result->exportedAt = $this->normalizeExportedAt($sheet, $values['exported_at'] ?? '');
        $result->matchingType = $values['matching_type'] ?? 'first';
        $result->decisionType = $values['decision_type'] ?? 'string';
        $result->variantTitle = $values['variant_title'] ?? '';
        $result->variantDescription = $values['variant_description'] ?? '';
        $result->defaultDecision = $values['default_decision'] ?? '';
        $result->tableTitle = $values['table_title'] ?? '';
        $result->tableDescription = $values['table_description'] ?? '';
    }

    /**
     * Validate a 24-hex Mongo id; blank returns null (create flow), anything
     * else malformed is reported as an error.
     */
    private function normalizeId(string $value): ?string
    {
        if ($value === '') {
            return null;
        }
        if (!preg_match('/^[0-9a-f]{24}$/i', $value)) {
            $this->errors[] = [
                'cell' => null, 'row' => null, 'column' => null, 'field' => null,
                'message' => "Identifiant invalide dans la feuille _meta : \"$value\".",
            ];
            return null;
        }
        return strtolower($value);
    }

    /**
     * The exported_at token is written as an explicit string, but a user who
     * re-saved the file may have let Excel coerce it into a date serial.
     * Recover the ISO string in that case.
     */
    private function normalizeExportedAt(Worksheet $sheet, string $value): string
    {
        $row = ExcelLayout::META_ROWS['exported_at'];
        $cell = $sheet->getCell('B' . $row);
        $raw = $cell->getValue();
        if (is_float($raw) || is_int($raw)) {
            try {
                return ExcelDate::excelToDateTimeObject((float) $raw)->format(DATE_ATOM);
            } catch (\Throwable $e) {
                return $value;
            }
        }
        return $value;
    }

    // -------------------------------------------------------------------------
    // Rules sheet: header rows
    // -------------------------------------------------------------------------

    /**
     * Parse rows 2-4 into the column layout.
     *
     * @return array|null  ['fields' => [colIndex => fieldDef], 'decision' => colIndex,
     *                      'ruleTitle' => colIndex|null, 'ruleDesc' => colIndex|null,
     *                      'lastCol' => int] or null on fatal structure error.
     */
    private function readHeader(Worksheet $sheet, ExcelImportResult $result): ?array
    {
        $lastCol = Coordinate::columnIndexFromString($sheet->getHighestDataColumn());

        $fieldCols = [];       // colIndex => field def
        $decisionCol = null;
        $ruleTitleCol = null;
        $ruleDescCol = null;
        $seenKeys = [];

        for ($col = 2; $col <= $lastCol; $col++) {
            $letter = Coordinate::stringFromColumnIndex($col);
            $key = trim($this->cellToString($sheet, $letter . ExcelLayout::ROW_KEYS));

            if ($key === '') {
                continue; // empty header column: ignored entirely
            }

            $upper = strtoupper($key);
            if ($upper === ExcelLayout::SENTINEL_DECISION) {
                if ($decisionCol !== null) {
                    $this->errors[] = $this->makeError($letter, ExcelLayout::ROW_KEYS, null, 'La colonne DECISION apparaît plusieurs fois.');
                }
                $decisionCol = $col;
                continue;
            }
            if ($upper === ExcelLayout::SENTINEL_RULE_TITLE) {
                $ruleTitleCol = $col;
                continue;
            }
            if ($upper === ExcelLayout::SENTINEL_RULE_DESC) {
                $ruleDescCol = $col;
                continue;
            }

            if ($decisionCol !== null) {
                $this->errors[] = $this->makeError(
                    $letter,
                    ExcelLayout::ROW_KEYS,
                    null,
                    "Colonne de champ \"$key\" placée après la colonne DECISION — déplacez-la avant."
                );
                continue;
            }

            // Normalize the key exactly like Field::setKeyAttribute does
            $normalizedKey = strtolower(str_replace(' ', '_', $key));
            if (isset($seenKeys[$normalizedKey])) {
                $this->errors[] = $this->makeError($letter, ExcelLayout::ROW_KEYS, $normalizedKey, "Clé de champ dupliquée : \"$normalizedKey\".");
                continue;
            }
            $seenKeys[$normalizedKey] = true;

            $type = trim($this->cellToString($sheet, $letter . ExcelLayout::ROW_TYPES)) ?: 'string';
            $title = trim($this->cellToString($sheet, $letter . ExcelLayout::ROW_TITLES)) ?: $normalizedKey;

            $fieldCols[$col] = [
                'key' => $normalizedKey,
                'title' => $title,
                'type' => strtolower($type),
            ];
            $result->columnMap[$normalizedKey] = ['column' => $letter, 'title' => $title];
        }

        if ($decisionCol === null) {
            $this->errors[] = [
                'cell' => null, 'row' => ExcelLayout::ROW_KEYS, 'column' => null, 'field' => null,
                'message' => 'Format non reconnu : colonne DECISION absente de la ligne ' . ExcelLayout::ROW_KEYS . '.',
            ];
            return null;
        }
        if (empty($fieldCols)) {
            $this->errors[] = [
                'cell' => null, 'row' => ExcelLayout::ROW_KEYS, 'column' => null, 'field' => null,
                'message' => 'Aucune colonne de champ trouvée (ligne ' . ExcelLayout::ROW_KEYS . ').',
            ];
            return null;
        }

        // Field definitions in column order + cellMap entries for field header cells
        $fieldIndex = 0;
        foreach ($fieldCols as $col => $def) {
            $letter = Coordinate::stringFromColumnIndex($col);
            $result->fields[] = $def;
            $result->cellMap["fields.$fieldIndex.key"] = $letter . ExcelLayout::ROW_KEYS;
            $result->cellMap["fields.$fieldIndex.type"] = $letter . ExcelLayout::ROW_TYPES;
            $result->cellMap["fields.$fieldIndex.title"] = $letter . ExcelLayout::ROW_TITLES;
            $fieldIndex++;
        }

        return [
            'fields' => $fieldCols,
            'decision' => $decisionCol,
            'ruleTitle' => $ruleTitleCol,
            'ruleDesc' => $ruleDescCol,
            'lastCol' => $lastCol,
        ];
    }

    // -------------------------------------------------------------------------
    // Rules sheet: data rows
    // -------------------------------------------------------------------------

    private function readRules(Worksheet $sheet, array $columns, ExcelImportResult $result): void
    {
        $highestRow = $sheet->getHighestDataRow();
        $parsedRows = 0;
        $ruleIndex = 0;

        for ($row = ExcelLayout::ROW_FIRST_RULE; $row <= $highestRow; $row++) {
            if ($this->isRowBlank($sheet, $row, $columns)) {
                continue; // skip-blank policy: gaps are tolerated
            }

            if (++$parsedRows > ExcelLayout::MAX_RULE_ROWS) {
                $this->errors[] = [
                    'cell' => null, 'row' => $row, 'column' => null, 'field' => null,
                    'message' => 'Trop de règles : maximum ' . ExcelLayout::MAX_RULE_ROWS . ' lignes.',
                ];
                break;
            }

            $rule = $this->readRuleRow($sheet, $row, $columns, $ruleIndex, $result);
            $result->rules[] = $rule;
            $ruleIndex++;
        }
    }

    private function readRuleRow(Worksheet $sheet, int $row, array $columns, int $ruleIndex, ExcelImportResult $result): array
    {
        // Hidden _rule_id column: blank = new rule; malformed id = addressed error
        $ruleIdRaw = trim($this->cellToString($sheet, 'A' . $row));
        $ruleId = null;
        if ($ruleIdRaw !== '') {
            if (preg_match('/^[0-9a-f]{24}$/i', $ruleIdRaw)) {
                $ruleId = strtolower($ruleIdRaw);
            } else {
                $this->errors[] = $this->makeError('A', $row, null, "Identifiant de règle invalide : \"$ruleIdRaw\". Laissez la cellule vide pour une nouvelle règle.");
            }
        }

        $conditions = [];
        $condIndex = 0;
        foreach ($columns['fields'] as $col => $fieldDef) {
            $letter = Coordinate::stringFromColumnIndex($col);
            $address = $letter . $row;
            $cellText = $this->cellToString($sheet, $address, $fieldDef['key'], $row);

            try {
                $parsed = $this->codec->decode($cellText);
            } catch (ConditionCellParseException $e) {
                $this->errors[] = $this->makeError($letter, $row, $fieldDef['key'], $e->getMessage());
                // Keep a placeholder so downstream indexes stay aligned
                $parsed = ['condition' => '$any', 'value' => ConditionCellCodec::VALUELESS_VALUE];
            }

            $conditions[] = [
                'field_key' => $fieldDef['key'],
                'condition' => $parsed['condition'],
                'value' => $parsed['value'],
            ];

            // Dot-paths are relative to a payload where the target variant is at index 0
            $result->cellMap["variants.0.rules.$ruleIndex.conditions.$condIndex.value"] = $address;
            $result->cellMap["variants.0.rules.$ruleIndex.conditions.$condIndex.condition"] = $address;
            $result->cellMap["variants.0.rules.$ruleIndex.conditions.$condIndex.field_key"] = $address;
            $condIndex++;
        }

        $decisionLetter = Coordinate::stringFromColumnIndex($columns['decision']);
        $than = $this->cellToString($sheet, $decisionLetter . $row, 'decision', $row);
        $result->cellMap["variants.0.rules.$ruleIndex.than"] = $decisionLetter . $row;

        $title = '';
        if ($columns['ruleTitle'] !== null) {
            $title = trim($this->cellToString($sheet, Coordinate::stringFromColumnIndex($columns['ruleTitle']) . $row));
        }
        $description = '';
        if ($columns['ruleDesc'] !== null) {
            $descLetter = Coordinate::stringFromColumnIndex($columns['ruleDesc']);
            $description = trim($this->cellToString($sheet, $descLetter . $row));
            $result->cellMap["variants.0.rules.$ruleIndex.description"] = $descLetter . $row;
        }

        $rule = [
            'title' => $title,
            'than' => trim($than),
            'conditions' => $conditions,
        ];
        if ($description !== '') {
            $rule['description'] = $description;
        }
        if ($ruleId !== null) {
            $rule['_id'] = $ruleId;
        }

        return $rule;
    }

    /**
     * A row is blank when the id, every field cell, the decision, and the
     * title/description cells are all empty after trim.
     */
    private function isRowBlank(Worksheet $sheet, int $row, array $columns): bool
    {
        $cols = array_keys($columns['fields']);
        $cols[] = ExcelLayout::COL_RULE_ID;
        $cols[] = $columns['decision'];
        if ($columns['ruleTitle'] !== null) {
            $cols[] = $columns['ruleTitle'];
        }
        if ($columns['ruleDesc'] !== null) {
            $cols[] = $columns['ruleDesc'];
        }

        foreach ($cols as $col) {
            $letter = Coordinate::stringFromColumnIndex($col);
            $value = $sheet->getCell($letter . $row)->getValue();
            if ($value !== null && trim((string) $this->rawToString($value)) !== '') {
                return false;
            }
        }
        return true;
    }

    // -------------------------------------------------------------------------
    // Cell value handling
    // -------------------------------------------------------------------------

    /**
     * Read a cell as text, undoing Excel-side type coercion:
     *  - booleans → "true"/"false",
     *  - integral floats → integer string ("21.0" → "21"),
     *  - formulas → addressed error (the raw text is unusable as a value).
     *
     * @param string|null $fieldKey  When given, formula errors carry this context.
     * @param int|null    $row       Row for the formula error address.
     */
    private function cellToString(Worksheet $sheet, string $address, ?string $fieldKey = null, ?int $row = null): string
    {
        $cell = $sheet->getCell($address);

        if ($cell->getDataType() === DataType::TYPE_FORMULA) {
            preg_match('/^([A-Z]+)(\d+)$/', $address, $m);
            $this->errors[] = $this->makeError($m[1] ?? null, $row ?? (isset($m[2]) ? (int) $m[2] : null), $fieldKey, 'Formule Excel détectée — préfixez la valeur d\'une apostrophe pour la saisir en texte.');
            return '';
        }

        return $this->rawToString($cell->getValue());
    }

    private function rawToString($value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_float($value) && $value == (int) $value) {
            return (string) (int) $value;
        }
        return (string) $value;
    }

    private function makeError(?string $column, ?int $row, ?string $fieldKey, string $message): array
    {
        $cell = ($column !== null && $row !== null) ? $column . $row : null;
        $prefix = '';
        if ($row !== null && $column !== null) {
            $prefix = "Ligne $row, colonne $column" . ($fieldKey ? " ($fieldKey)" : '') . ' : ';
        }
        return [
            'cell' => $cell,
            'row' => $row,
            'column' => $column,
            'field' => $fieldKey,
            'message' => $prefix . $message,
        ];
    }
}
