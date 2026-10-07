<?php
/**
 * ExcelDateColumnTest
 *
 * Reading a workbook with a date column: condition cells are decoded with
 * date bounds, and a date that Excel itself converted to a serial number
 * (typed without prefix) is read back as its ISO date.
 */

use App\Exceptions\ExcelImportException;
use App\Services\Excel\ConditionCellCodec;
use App\Services\Excel\ExcelLayout;
use App\Services\Excel\ExcelTableReader;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class ExcelDateColumnTest extends \Codeception\TestCase\Test
{
    private $path;

    protected function _after()
    {
        if ($this->path && file_exists($this->path)) {
            unlink($this->path);
        }
    }

    /**
     * Minimal round-trip workbook: one date field, one rule row per entry of
     * $cells (a string, or a callable that fills the cell itself).
     */
    private function workbook(array $cells): string
    {
        $spreadsheet = new Spreadsheet();
        $rules = $spreadsheet->getActiveSheet()->setTitle(ExcelLayout::SHEET_RULES);
        $rules->setCellValue('B' . ExcelLayout::ROW_KEYS, 'claim_date');
        $rules->setCellValue('C' . ExcelLayout::ROW_KEYS, ExcelLayout::SENTINEL_DECISION);
        $rules->setCellValue('B' . ExcelLayout::ROW_TYPES, 'date');
        $rules->setCellValue('B' . ExcelLayout::ROW_TITLES, 'Claim date');

        $row = ExcelLayout::ROW_FIRST_RULE;
        foreach ($cells as $cell) {
            if (is_callable($cell)) {
                $cell($rules, 'B' . $row);
            } else {
                $rules->setCellValueExplicit('B' . $row, $cell, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            }
            $rules->setCellValue('C' . $row, 'accept');
            $row++;
        }

        $meta = $spreadsheet->createSheet()->setTitle(ExcelLayout::SHEET_META);
        $meta->setCellValue('A1', 'format_version');
        $meta->setCellValue('B1', ExcelLayout::FORMAT_VERSION);

        $this->path = tempnam(sys_get_temp_dir(), 'gandalf') . '.xlsx';
        (new Xlsx($spreadsheet))->save($this->path);

        return $this->path;
    }

    private function conditions(array $cells): array
    {
        $result = (new ExcelTableReader(new ConditionCellCodec()))->read($this->workbook($cells));

        return array_map(function ($rule) {
            $condition = $rule['conditions'][0];
            return [$condition['condition'], $condition['value']];
        }, $result->rules);
    }

    public function testDateConditionCells()
    {
        $this->assertSame([
            ['$gte', 'today-30d'],
            ['$between', '2026-01-01;2026-12-31'],
            ['$eq', '2026-03-15'],
        ], $this->conditions(['>= today-30d', '[2026-01-01..2026-12-31]', '2026-03-15']));
    }

    public function testExcelConvertedDateIsReadAsIsoDate()
    {
        $typedDate = function ($sheet, $address) {
            // What Excel stores when "2026-03-15" is typed in a General cell
            $sheet->setCellValue($address, ExcelDate::PHPToExcel(gmmktime(0, 0, 0, 3, 15, 2026)));
            $sheet->getStyle($address)->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_DATE_YYYYMMDD);
        };

        $this->assertSame([['$eq', '2026-03-15']], $this->conditions([$typedDate]));
    }

    public function testNonDateComparisonIsAnAddressedError()
    {
        try {
            $this->conditions(['>= 42']);
            $this->fail('A numeric bound in a date column must be rejected.');
        } catch (ExcelImportException $e) {
            $error = $e->getErrors()[0];
            $this->assertSame('B', $error['column']);
            $this->assertSame(ExcelLayout::ROW_FIRST_RULE, $error['row']);
            $this->assertContains("n'est pas une date", $error['message']);
        }
    }
}
