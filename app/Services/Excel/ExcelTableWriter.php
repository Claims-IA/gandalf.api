<?php
/**
 * ExcelTableWriter
 *
 * Builds the round-trip Excel workbook for one variant of a decision table.
 * See ExcelLayout for the physical layout contract shared with the reader.
 *
 * Guardrails baked into the workbook:
 *  - every value cell is written as an explicit string (no formula/date coercion),
 *  - the rule-data range is formatted as Text so Excel does not mangle values
 *    the user types (dates, leading zeros),
 *  - sheet protection (no password) locks the title row, the hidden _rule_id
 *    header, and the sentinel header cells, while leaving data cells and field
 *    header cells (rows 2-4) editable so users can add/rename columns,
 *  - row/column insertion & deletion stay allowed under protection,
 *  - dropdowns: boolean field columns get a non-strict value list, the type
 *    row gets a strict numeric/boolean/string list.
 *
 * @package App\Services\Excel
 */

namespace App\Services\Excel;

use App\Models\Table;
use App\Models\Variant;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Style\Protection as StyleProtection;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class ExcelTableWriter
{
    private ConditionCellCodec $codec;

    public function __construct(ConditionCellCodec $codec)
    {
        $this->codec = $codec;
    }

    /**
     * Build the workbook for the given table variant and return the path of
     * the generated temporary .xlsx file. The caller is responsible for
     * streaming and deleting the file.
     *
     * @param  Table   $table
     * @param  Variant $variant
     * @return string  Absolute path to the .xlsx temp file
     */
    public function write(Table $table, Variant $variant): string
    {
        $spreadsheet = new Spreadsheet();

        $rulesSheet = $spreadsheet->getActiveSheet();
        $rulesSheet->setTitle(ExcelLayout::SHEET_RULES);

        $fields = $table->fields()->get();
        $this->writeRulesSheet($rulesSheet, $table, $variant, $fields);

        $metaSheet = $spreadsheet->createSheet();
        $metaSheet->setTitle(ExcelLayout::SHEET_META);
        $this->writeMetaSheet($metaSheet, $table, $variant);
        $metaSheet->setSheetState(Worksheet::SHEETSTATE_VERYHIDDEN);

        $helpSheet = $spreadsheet->createSheet();
        $helpSheet->setTitle(ExcelLayout::SHEET_HELP);
        $this->writeHelpSheet($helpSheet);

        $spreadsheet->setActiveSheetIndex(0);

        $tmpPath = sys_get_temp_dir() . '/' . uniqid('table_export_', true) . '.xlsx';
        (new Xlsx($spreadsheet))->save($tmpPath);

        return $tmpPath;
    }

    // -------------------------------------------------------------------------
    // Rules sheet
    // -------------------------------------------------------------------------

    private function writeRulesSheet(Worksheet $sheet, Table $table, Variant $variant, $fields): void
    {
        $fieldCount = count($fields);
        // Columns: A=_rule_id, B..=fields, then DECISION, RULE_TITLE, RULE_DESC
        $decisionCol = 2 + $fieldCount;
        $ruleTitleCol = $decisionCol + 1;
        $ruleDescCol = $decisionCol + 2;
        $lastCol = $ruleDescCol;
        $lastColLetter = Coordinate::stringFromColumnIndex($lastCol);

        // --- Row 1: workbook title (merged, informational only) ---
        $title = trim(($table->title ?: 'Table') . ' — ' . ($variant->title ?: 'Variante par défaut'));
        $this->setString($sheet, 1, ExcelLayout::ROW_TITLE, $title);
        $sheet->mergeCells('A1:' . $lastColLetter . '1');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->getRowDimension(ExcelLayout::ROW_TITLE)->setRowHeight(24);

        // --- Rows 2-4: headers ---
        $this->setString($sheet, ExcelLayout::COL_RULE_ID, ExcelLayout::ROW_KEYS, '_rule_id');

        $col = 2;
        foreach ($fields as $field) {
            $this->setString($sheet, $col, ExcelLayout::ROW_KEYS, $field->key);
            $this->setString($sheet, $col, ExcelLayout::ROW_TYPES, $field->type);
            $this->setString($sheet, $col, ExcelLayout::ROW_TITLES, $field->title ?: $field->key);
            $col++;
        }

        $this->setString($sheet, $decisionCol, ExcelLayout::ROW_KEYS, ExcelLayout::SENTINEL_DECISION);
        $this->setString($sheet, $decisionCol, ExcelLayout::ROW_TITLES, 'Décision');
        $this->setString($sheet, $ruleTitleCol, ExcelLayout::ROW_KEYS, ExcelLayout::SENTINEL_RULE_TITLE);
        $this->setString($sheet, $ruleTitleCol, ExcelLayout::ROW_TITLES, 'Titre');
        $this->setString($sheet, $ruleDescCol, ExcelLayout::ROW_KEYS, ExcelLayout::SENTINEL_RULE_DESC);
        $this->setString($sheet, $ruleDescCol, ExcelLayout::ROW_TITLES, 'Description');

        // --- Rule rows ---
        $row = ExcelLayout::ROW_FIRST_RULE;
        foreach ($variant->rules()->get() as $rule) {
            // Index conditions by field_key for O(1) lookup
            $conditionMap = [];
            foreach ($rule->conditions()->get() as $condition) {
                $conditionMap[$condition->field_key] = $condition;
            }

            $this->setString($sheet, ExcelLayout::COL_RULE_ID, $row, (string) $rule->_id);

            $col = 2;
            foreach ($fields as $field) {
                if (isset($conditionMap[$field->key])) {
                    $c = $conditionMap[$field->key];
                    $cell = $this->codec->encode($c->condition, $c->value);
                } else {
                    // No condition stored for this field — "don't care"
                    $cell = '*';
                }
                $this->setString($sheet, $col, $row, $cell);
                $col++;
            }

            $this->setString($sheet, $decisionCol, $row, $this->codec->stringify($rule->than));
            $this->setString($sheet, $ruleTitleCol, $row, (string) ($rule->title ?? ''));
            $this->setString($sheet, $ruleDescCol, $row, (string) ($rule->description ?? ''));
            $row++;
        }
        $lastRuleRow = $row - 1;

        $this->styleRulesSheet($sheet, $fields, $decisionCol, $lastCol, $lastRuleRow);
    }

    /**
     * Apply styling, protection, number formats, and data validations.
     */
    private function styleRulesSheet(Worksheet $sheet, $fields, int $decisionCol, int $lastCol, int $lastRuleRow): void
    {
        $lastColLetter = Coordinate::stringFromColumnIndex($lastCol);
        // Prepare styles/dropdowns beyond existing rules so appended rows behave
        $preparedLastRow = max($lastRuleRow, ExcelLayout::ROW_FIRST_RULE - 1) + ExcelLayout::PREPARED_DATA_ROWS;

        // Machine rows (keys/types): small gray italic
        $sheet->getStyle('A2:' . $lastColLetter . '3')->getFont()
            ->setItalic(true)->setSize(9)->getColor()->setARGB('FF808080');

        // Human header row: bold white on blue, bottom border
        $titlesRange = 'A' . ExcelLayout::ROW_TITLES . ':' . $lastColLetter . ExcelLayout::ROW_TITLES;
        $titlesStyle = $sheet->getStyle($titlesRange);
        $titlesStyle->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
        $titlesStyle->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF2F5B96');
        $titlesStyle->getBorders()->getBottom()->setBorderStyle(Border::BORDER_MEDIUM);
        $titlesStyle->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        // Decision column visually distinct
        $decisionLetter = Coordinate::stringFromColumnIndex($decisionCol);
        $sheet->getStyle($decisionLetter . ExcelLayout::ROW_FIRST_RULE . ':' . $decisionLetter . $preparedLastRow)
            ->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFF2F7E8');

        // Text format on the whole editable area: stops Excel auto-converting
        // what the user types (dates, leading zeros, fractions)
        $dataRange = 'A' . ExcelLayout::ROW_FIRST_RULE . ':' . $lastColLetter . $preparedLastRow;
        $sheet->getStyle($dataRange)->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);

        // Hidden _rule_id column
        $sheet->getColumnDimension('A')->setVisible(false);

        // Freeze header rows + hidden id column
        $sheet->freezePane('B' . ExcelLayout::ROW_FIRST_RULE);

        // Column widths
        foreach (range(2, $lastCol) as $colIndex) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($colIndex))->setAutoSize(true);
        }
        $sheet->getColumnDimension('A')->setWidth(4);

        // --- Protection: guardrail only (no password) ---
        // PhpSpreadsheet semantics: true = operation LOCKED under protection,
        // so allow structural edits with explicit false.
        $protection = $sheet->getProtection();
        $protection->setSheet(true);
        $protection->setInsertRows(false);
        $protection->setDeleteRows(false);
        $protection->setInsertColumns(false);
        $protection->setDeleteColumns(false);
        $protection->setFormatColumns(false);
        $protection->setFormatCells(false);
        $protection->setSort(false);

        // Cells are locked by default; unlock everything users may edit:
        // field header cells (rows 2-4, needed to add/rename columns) and data rows.
        $sheet->getStyle('B2:' . $lastColLetter . $preparedLastRow)
            ->getProtection()->setLocked(StyleProtection::PROTECTION_UNPROTECTED);
        // Re-lock the sentinel header cells (rows 2-4 of the trailing columns)
        $sheet->getStyle($decisionLetter . '2:' . $lastColLetter . ExcelLayout::ROW_TITLES)
            ->getProtection()->setLocked(StyleProtection::PROTECTION_PROTECTED);
        // _rule_id data cells stay unlocked (row deletion needs it) but are hidden anyway
        $sheet->getStyle('A' . ExcelLayout::ROW_FIRST_RULE . ':A' . $preparedLastRow)
            ->getProtection()->setLocked(StyleProtection::PROTECTION_UNPROTECTED);

        // --- Data validations ---
        // Strict type dropdown on the type row for field columns
        $typeValidation = $this->makeListValidation('"numeric,boolean,string"', true);
        for ($colIndex = 2; $colIndex < $decisionCol; $colIndex++) {
            $letter = Coordinate::stringFromColumnIndex($colIndex);
            $sheet->getCell($letter . ExcelLayout::ROW_TYPES)->setDataValidation(clone $typeValidation);
        }

        // Non-strict value dropdown on boolean field columns (help, not a straitjacket)
        $boolValidation = $this->makeListValidation('"true,false,*,+,null"', false);
        $colIndex = 2;
        foreach ($fields as $field) {
            if ($field->type === 'boolean') {
                $letter = Coordinate::stringFromColumnIndex($colIndex);
                $range = $letter . ExcelLayout::ROW_FIRST_RULE . ':' . $letter . $preparedLastRow;
                $sheet->setDataValidation($range, clone $boolValidation);
            }
            $colIndex++;
        }

        // Grammar hint as a comment on each field title cell
        for ($ci = 2; $ci < $decisionCol; $ci++) {
            $letter = Coordinate::stringFromColumnIndex($ci);
            $sheet->getComment($letter . ExcelLayout::ROW_TITLES)->getText()->createText(
                "Exemples de conditions :\n>= 21   [18..25]   not [1..2]\nin: FR, BE   contains: x\n* = peu importe   + = renseigné   null = absent\n'>= 3' (quotes) = valeur littérale"
            );
        }
    }

    // -------------------------------------------------------------------------
    // _meta sheet
    // -------------------------------------------------------------------------

    private function writeMetaSheet(Worksheet $sheet, Table $table, Variant $variant): void
    {
        $updatedAt = $table->updated_at;
        $exportedAt = $updatedAt ? $updatedAt->toIso8601String() : '';

        $values = [
            'format_version' => ExcelLayout::FORMAT_VERSION,
            'table_id' => (string) $table->_id,
            'variant_id' => (string) $variant->_id,
            'exported_at' => $exportedAt,
            'matching_type' => (string) ($table->matching_type ?? 'first'),
            'decision_type' => (string) ($table->decision_type ?? 'string'),
            'variant_title' => (string) ($variant->title ?? ''),
            'variant_description' => (string) ($variant->description ?? ''),
            'default_decision' => $this->codec->stringify($variant->default_decision ?? ''),
            'table_title' => (string) ($table->title ?? ''),
            'table_description' => (string) ($table->description ?? ''),
        ];

        foreach (ExcelLayout::META_ROWS as $label => $row) {
            $this->setString($sheet, 1, $row, $label);
            $this->setString($sheet, 2, $row, $values[$label] ?? '');
        }
    }

    // -------------------------------------------------------------------------
    // _help sheet
    // -------------------------------------------------------------------------

    private function writeHelpSheet(Worksheet $sheet): void
    {
        $lines = [
            ['Syntaxe des cellules de condition', ''],
            ['', ''],
            ['Cellule', 'Signification'],
            ['* ou vide (aussi: ---, any)', 'Peu importe (toujours vrai)'],
            ['+ (aussi: set, is set)', 'Le champ est renseigné'],
            ['null (aussi: is null)', 'Le champ est absent/null'],
            ['Lyon  ou  = Lyon', 'Égal à "Lyon"'],
            ['!= FR', 'Différent de "FR"'],
            ['> 21   >= 21   < 21   <= 21', 'Comparaisons numériques'],
            ['[18..25]', 'Entre 18 et 25 (bornes incluses)'],
            [']18..25[', 'Entre 18 et 25 (bornes excluses)'],
            [']18..25]', '18 exclu, 25 inclus'],
            ['[18..25[', '18 inclus, 25 exclu'],
            ['not [18..25]', 'Hors de l\'intervalle 18-25'],
            ['in: FR, BE, LU', 'Dans la liste'],
            ['not in: visa, amex', 'Hors de la liste'],
            ['contains: bmw', 'Contient la sous-chaîne (insensible à la casse)'],
            ['not contains: bmw', 'Ne contient pas la sous-chaîne'],
            ['starts: FR', 'Commence par'],
            ['ends: 75', 'Se termine par'],
            ["'>= 3'", 'Valeur littérale ">= 3" (les quotes protègent les valeurs qui ressemblent à un opérateur)'],
            ['', ''],
            ['Lignes', 'Ajoutez des lignes pour créer des règles (laissez la colonne cachée _rule_id vide).'],
            ['Colonnes', 'Ajoutez une colonne de champ : remplissez la clé (ligne 2), le type (ligne 3) et le titre (ligne 4).'],
            ['Suppression', 'Supprimer une ligne supprime la règle ; supprimer une colonne supprime le champ (si aucune autre variante ne l\'utilise).'],
        ];

        $row = 1;
        foreach ($lines as [$a, $b]) {
            $this->setString($sheet, 1, $row, $a);
            $this->setString($sheet, 2, $row, $b);
            $row++;
        }

        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(13);
        $sheet->getStyle('A3:B3')->getFont()->setBold(true);
        $sheet->getColumnDimension('A')->setWidth(34);
        $sheet->getColumnDimension('B')->setWidth(80);
        $sheet->getProtection()->setSheet(true);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Write a cell as an explicit string: immune to formula ("=x"), date, and
     * numeric coercion on the PhpSpreadsheet side.
     */
    private function setString(Worksheet $sheet, int $col, int $row, string $value): void
    {
        $sheet->setCellValueExplicit(
            Coordinate::stringFromColumnIndex($col) . $row,
            $value,
            DataType::TYPE_STRING
        );
    }

    private function makeListValidation(string $formula, bool $strict): DataValidation
    {
        $validation = new DataValidation();
        // Counter-intuitive PhpSpreadsheet/OOXML quirk: the XLSX writer inverts
        // this flag (OOXML showDropDown="1" HIDES the arrow), so false here is
        // what actually displays the in-cell dropdown in Excel.
        $validation->setType(DataValidation::TYPE_LIST)
            ->setAllowBlank(true)
            ->setShowDropDown(false)
            ->setFormula1($formula);
        if ($strict) {
            $validation->setErrorStyle(DataValidation::STYLE_STOP)->setShowErrorMessage(true);
        } else {
            $validation->setErrorStyle(DataValidation::STYLE_INFORMATION)->setShowErrorMessage(false);
        }
        return $validation;
    }
}
