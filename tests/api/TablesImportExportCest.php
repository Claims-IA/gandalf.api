<?php
/**
 * TablesImportExportCest
 *
 * End-to-end coverage of the round-trip Excel export/import feature:
 * export a variant as a workbook, edit it with PhpSpreadsheet the way a user
 * would in Excel (add/remove rows and columns), re-import, and assert the
 * table was updated in place (or a new one created), with optimistic-lock
 * conflicts, cell-addressed validation errors, and legacy-format regressions.
 */

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;

class TablesImportExportCest
{
    const EXPORT_URL = 'api/v1/admin/tables/%s/export?format=excel';
    const IMPORT_URL = 'api/v1/admin/tables/import';

    public function _before(ApiTester $I)
    {
        $I->createAndLoginUser();
        $I->createProjectAndSetHeader();
    }

    // -------------------------------------------------------------------------
    // Fixtures
    // -------------------------------------------------------------------------

    /**
     * A clean two-fields table payload (the shared getTableShortData fixture
     * carries a legacy matching_type and is not reused here).
     */
    private function tablePayload()
    {
        return [
            'title' => 'Export Test',
            'description' => 'Round-trip test table',
            'matching_type' => 'first',
            'decision_type' => 'string',
            'fields' => [
                ['key' => 'age', 'title' => 'Age', 'type' => 'numeric', 'source' => 'request',
                 'preset' => ['condition' => '$gte', 'value' => 0]],
                ['key' => 'country', 'title' => 'Country', 'type' => 'string', 'source' => 'request',
                 'preset' => null],
            ],
            'variants' => [
                [
                    'title' => 'Main variant',
                    'default_decision' => 'decline',
                    'rules' => [
                        [
                            'title' => 'Adults FR',
                            'than' => 'approve',
                            'conditions' => [
                                ['field_key' => 'age', 'condition' => '$gte', 'value' => '21'],
                                ['field_key' => 'country', 'condition' => '$eq', 'value' => 'FR'],
                            ],
                        ],
                        [
                            'title' => 'Everyone else',
                            'than' => 'review',
                            'conditions' => [
                                ['field_key' => 'age', 'condition' => '$any', 'value' => true],
                                ['field_key' => 'country', 'condition' => '$any', 'value' => true],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    private function createTable(ApiTester $I, ?array $payload = null)
    {
        $I->sendPOST('api/v1/admin/tables', $payload ?: $this->tablePayload());
        $I->seeResponseCodeIs(201);
        return $I->getResponseFields()->data;
    }

    private function exportToFile(ApiTester $I, $tableId, $variantId = null)
    {
        $url = sprintf(self::EXPORT_URL, $tableId) . ($variantId ? '&variant_id=' . $variantId : '');
        $I->sendGET($url);
        $I->seeResponseCodeIs(200);
        $path = codecept_output_dir() . uniqid('export_') . '.xlsx';
        $I->grabResponseToFile($path);
        return $path;
    }

    private function importFile(ApiTester $I, $path, array $params = [])
    {
        $I->sendFileMultipart(self::IMPORT_URL, $path, $params);
    }

    /**
     * Find the 1-based row of the DECISION sentinel column in row 2.
     */
    private function findColumn($sheet, $headerValue)
    {
        $highestCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($sheet->getHighestDataColumn());
        for ($col = 1; $col <= $highestCol; $col++) {
            $letter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col);
            if (strtoupper(trim((string) $sheet->getCell($letter . '2')->getValue())) === strtoupper($headerValue)) {
                return $col;
            }
        }
        return null;
    }

    // -------------------------------------------------------------------------
    // Tests
    // -------------------------------------------------------------------------

    public function roundTripIdentity(ApiTester $I)
    {
        $table = $this->createTable($I);
        $originalRuleIds = array_map(function ($rule) {
            return $rule->_id;
        }, $table->variants[0]->rules);

        $path = $this->exportToFile($I, $table->_id);

        $this->importFile($I, $path);
        $I->seeResponseCodeIs(200); // update, not create

        $I->sendGET('api/v1/admin/tables/' . $table->_id);
        $I->seeResponseCodeIs(200);
        $updated = $I->getResponseFields()->data;

        // Same table, same rules, same rule ids (analytics continuity)
        $I->assertEquals($table->_id, $updated->_id);
        $I->assertEquals(2, count($updated->variants[0]->rules));
        $updatedRuleIds = array_map(function ($rule) {
            return $rule->_id;
        }, $updated->variants[0]->rules);
        $I->assertEquals($originalRuleIds, $updatedRuleIds, 'rule ids must survive a no-op round trip');

        // Conditions intact
        $rule = $updated->variants[0]->rules[0];
        $I->assertEquals('$gte', $rule->conditions[0]->condition);
        $I->assertEquals('21', $rule->conditions[0]->value);
        $I->assertEquals('approve', $rule->than);

        // Preset survived (never travels through Excel)
        $I->assertEquals('$gte', $updated->fields[0]->preset->condition);
        unlink($path);
    }

    public function addRuleRow(ApiTester $I)
    {
        $table = $this->createTable($I);
        $path = $this->exportToFile($I, $table->_id);

        // Append a row the way a user would: blank _rule_id, condition cells, decision
        $spreadsheet = IOFactory::load($path);
        $sheet = $spreadsheet->getSheetByName('Rules');
        $row = $sheet->getHighestDataRow() + 1;
        $sheet->setCellValueExplicit('B' . $row, '< 18', DataType::TYPE_STRING);
        $sheet->setCellValueExplicit('C' . $row, 'in: FR, BE', DataType::TYPE_STRING);
        $decisionCol = $this->findColumn($sheet, 'DECISION');
        $letter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($decisionCol);
        $sheet->setCellValueExplicit($letter . $row, 'minor', DataType::TYPE_STRING);
        (new XlsxWriter($spreadsheet))->save($path);

        $this->importFile($I, $path);
        $I->seeResponseCodeIs(200);
        $updated = $I->getResponseFields()->data;

        $I->assertEquals(3, count($updated->variants[0]->rules));
        $newRule = $updated->variants[0]->rules[2];
        $I->assertEquals('minor', $newRule->than);
        $I->assertEquals('$lt', $newRule->conditions[0]->condition);
        $I->assertEquals('18', $newRule->conditions[0]->value);
        $I->assertEquals('$in', $newRule->conditions[1]->condition);
        $I->assertNotEmpty($newRule->_id, 'new rule gets a fresh id');
        unlink($path);
    }

    public function deleteRuleRow(ApiTester $I)
    {
        $table = $this->createTable($I);
        $keptRuleId = $table->variants[0]->rules[0]->_id;
        $path = $this->exportToFile($I, $table->_id);

        $spreadsheet = IOFactory::load($path);
        $sheet = $spreadsheet->getSheetByName('Rules');
        $sheet->removeRow(6); // second rule row (first is row 5)
        (new XlsxWriter($spreadsheet))->save($path);

        $this->importFile($I, $path);
        $I->seeResponseCodeIs(200);
        $updated = $I->getResponseFields()->data;

        $I->assertEquals(1, count($updated->variants[0]->rules));
        $I->assertEquals($keptRuleId, $updated->variants[0]->rules[0]->_id, 'surviving rule keeps its id');
        unlink($path);
    }

    public function addFieldColumn(ApiTester $I)
    {
        $table = $this->createTable($I);
        $path = $this->exportToFile($I, $table->_id);

        // Insert a new field column before DECISION and fill header + cells
        $spreadsheet = IOFactory::load($path);
        $sheet = $spreadsheet->getSheetByName('Rules');
        $decisionCol = $this->findColumn($sheet, 'DECISION');
        $sheet->insertNewColumnBefore(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($decisionCol));
        $letter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($decisionCol);
        $sheet->setCellValueExplicit($letter . '2', 'score', DataType::TYPE_STRING);
        $sheet->setCellValueExplicit($letter . '3', 'numeric', DataType::TYPE_STRING);
        $sheet->setCellValueExplicit($letter . '4', 'Score', DataType::TYPE_STRING);
        // Existing rule rows: "don't care" for the new field
        $sheet->setCellValueExplicit($letter . '5', '*', DataType::TYPE_STRING);
        $sheet->setCellValueExplicit($letter . '6', '> 50', DataType::TYPE_STRING);
        (new XlsxWriter($spreadsheet))->save($path);

        $this->importFile($I, $path);
        $I->seeResponseCodeIs(200);
        $updated = $I->getResponseFields()->data;

        $I->assertEquals(3, count($updated->fields));
        $I->assertEquals('score', $updated->fields[2]->key);
        $I->assertEquals('numeric', $updated->fields[2]->type);
        $I->assertEquals(null, $updated->fields[2]->preset);
        // Pre-existing preset untouched
        $I->assertEquals('$gte', $updated->fields[0]->preset->condition);
        // Second rule got the > 50 condition on the new field
        $conditions = $updated->variants[0]->rules[1]->conditions;
        $I->assertEquals(3, count($conditions));
        $I->assertEquals('$gt', $conditions[2]->condition);
        unlink($path);
    }

    public function removeFieldColumnSingleVariant(ApiTester $I)
    {
        $table = $this->createTable($I);
        $path = $this->exportToFile($I, $table->_id);

        // Remove the "country" column (C) entirely
        $spreadsheet = IOFactory::load($path);
        $sheet = $spreadsheet->getSheetByName('Rules');
        $sheet->removeColumn('C');
        (new XlsxWriter($spreadsheet))->save($path);

        $this->importFile($I, $path);
        $I->seeResponseCodeIs(200);
        $updated = $I->getResponseFields()->data;

        $I->assertEquals(1, count($updated->fields));
        $I->assertEquals('age', $updated->fields[0]->key);
        foreach ($updated->variants[0]->rules as $rule) {
            $I->assertEquals(1, count($rule->conditions));
        }
        unlink($path);
    }

    public function removeFieldColumnBlockedByOtherVariant(ApiTester $I)
    {
        // Two variants, both using the "country" field
        $payload = $this->tablePayload();
        $payload['variants'][] = [
            'title' => 'Challenger B',
            'default_decision' => 'decline',
            'rules' => [[
                'than' => 'approve',
                'conditions' => [
                    ['field_key' => 'age', 'condition' => '$any', 'value' => true],
                    ['field_key' => 'country', 'condition' => '$eq', 'value' => 'BE'],
                ],
            ]],
        ];
        $table = $this->createTable($I, $payload);
        $defaultVariantId = $table->variants[0]->_id;

        $path = $this->exportToFile($I, $table->_id, $defaultVariantId);

        $spreadsheet = IOFactory::load($path);
        $sheet = $spreadsheet->getSheetByName('Rules');
        $sheet->removeColumn('C'); // country
        (new XlsxWriter($spreadsheet))->save($path);

        $this->importFile($I, $path);
        $I->seeResponseCodeIs(422);
        $I->seeResponseContains('Challenger B');
        $I->seeResponseContains('country');

        // Table untouched
        $I->sendGET('api/v1/admin/tables/' . $table->_id);
        $unchanged = $I->getResponseFields()->data;
        $I->assertEquals(2, count($unchanged->fields));
        unlink($path);
    }

    public function conflictThenForce(ApiTester $I)
    {
        $table = $this->createTable($I);
        $path = $this->exportToFile($I, $table->_id);

        // Concurrent edit: touch the table through the API after the export.
        // updated_at has 1-second resolution — step past it so the lock token differs.
        sleep(1);
        $update = $this->tablePayload();
        $update['title'] = 'Edited elsewhere';
        $I->sendPUT('api/v1/admin/tables/' . $table->_id, $update);
        $I->seeResponseCodeIs(200);

        // Stale file → 409
        $this->importFile($I, $path);
        $I->seeResponseCodeIs(409);
        $I->seeResponseContains('table_conflict');

        // force=1 → overwrite accepted
        $this->importFile($I, $path, ['force' => 1]);
        $I->seeResponseCodeIs(200);
        unlink($path);
    }

    public function grammarErrorIsCellAddressed(ApiTester $I)
    {
        $table = $this->createTable($I);
        $path = $this->exportToFile($I, $table->_id);

        $spreadsheet = IOFactory::load($path);
        $sheet = $spreadsheet->getSheetByName('Rules');
        $sheet->setCellValueExplicit('B5', '=>21', DataType::TYPE_STRING); // typo for >=
        (new XlsxWriter($spreadsheet))->save($path);

        $this->importFile($I, $path);
        $I->seeResponseCodeIs(422);
        $I->seeResponseContains('B5');
        $I->seeResponseContains('>=');
        unlink($path);
    }

    public function quotedLiteralSurvivesRoundTrip(ApiTester $I)
    {
        $payload = $this->tablePayload();
        // A stored $eq value that looks like an operator
        $payload['variants'][0]['rules'][0]['conditions'][1]['value'] = '>= 3';
        $table = $this->createTable($I, $payload);

        $path = $this->exportToFile($I, $table->_id);
        $this->importFile($I, $path);
        $I->seeResponseCodeIs(200);
        $updated = $I->getResponseFields()->data;

        $condition = $updated->variants[0]->rules[0]->conditions[1];
        $I->assertEquals('$eq', $condition->condition);
        $I->assertEquals('>= 3', $condition->value);
        unlink($path);
    }

    public function createModeMakesNewTable(ApiTester $I)
    {
        $table = $this->createTable($I);
        $path = $this->exportToFile($I, $table->_id);

        $this->importFile($I, $path, ['mode' => 'create']);
        $I->seeResponseCodeIs(201);
        $created = $I->getResponseFields()->data;
        $I->assertNotEquals($table->_id, $created->_id, 'mode=create must mint a new table');
        unlink($path);
    }

    public function legacyCsvStillCreates(ApiTester $I)
    {
        // The pre-v2 3-section CSV keeps its create-only behavior
        $csv = "## METADATA\ntitle,Legacy CSV\nmatching_type,first\ndecision_type,string\ndefault_decision,no\n\n"
            . "## FIELDS\nkey,title,type\nage,Age,numeric\n\n"
            . "## RULES\nage,decision\n\$gte:18,yes\n";
        $path = codecept_output_dir() . uniqid('legacy_') . '.csv';
        file_put_contents($path, $csv);

        $this->importFile($I, $path);
        $I->seeResponseCodeIs(201);
        unlink($path);
    }

    public function exportUnknownVariantIs404(ApiTester $I)
    {
        $table = $this->createTable($I);
        $I->sendGET(sprintf(self::EXPORT_URL, $table->_id) . '&variant_id=' . str_repeat('0', 24));
        $I->seeResponseCodeIs(404);
    }

    public function importIntoWrongProjectIs404(ApiTester $I)
    {
        $table = $this->createTable($I);
        $path = $this->exportToFile($I, $table->_id);

        // Switch to another project: the embedded table id is now foreign
        $I->createProjectAndSetHeader(['title' => 'Other project']);
        $this->importFile($I, $path);
        $I->seeResponseCodeIs(404);
        unlink($path);
    }
}
