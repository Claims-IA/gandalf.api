<?php
/**
 * DateFieldValidationTest
 *
 * Validation of tables with a date field, through the same rule set as the
 * create/update endpoints (TableRulesProvider), and of decision request
 * values. Boots the Lumen application for the Validator facade and the
 * custom rules; no database access is involved.
 */

use App\Services\ConditionsTypes;
use App\Services\DateValue;
use App\Validators\TableRulesProvider;

class DateFieldValidationTest extends \Codeception\TestCase\Test
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../bootstrap/app.php';
    }

    protected function _before()
    {
        DateValue::freezeToday('2026-03-31');
    }

    protected function _after()
    {
        DateValue::freezeToday(null);
    }

    /**
     * A one-field table payload whose single rule holds the given condition.
     */
    private function payload(string $fieldType, string $operator, $value): array
    {
        return [
            'title' => 'Dates',
            'matching_type' => 'first',
            'decision_type' => 'string',
            'fields' => [
                ['key' => 'claim_date', 'title' => 'Claim date', 'type' => $fieldType, 'source' => 'request', 'preset' => []],
            ],
            'variants' => [[
                'default_decision' => 'refuse',
                'rules' => [[
                    'than' => 'accept',
                    'conditions' => [
                        ['field_key' => 'claim_date', 'condition' => $operator, 'value' => $value],
                    ],
                ]],
            ]],
        ];
    }

    private function fails(array $payload): array
    {
        $validator = \Validator::make($payload, TableRulesProvider::rules(new ConditionsTypes()));

        return $validator->fails() ? array_keys($validator->errors()->toArray()) : [];
    }

    public function testDateTypeIsAccepted()
    {
        $this->assertSame([], $this->fails($this->payload('date', '$gte', '2026-01-01')));
    }

    public function testRelativeAndRangeValuesAreAccepted()
    {
        $this->assertSame([], $this->fails($this->payload('date', '$gte', 'today-30d')));
        $this->assertSame([], $this->fails($this->payload('date', '$between', 'today-1y;today')));
        $this->assertSame([], $this->fails($this->payload('date', '$any', true)));
    }

    public function testInvalidDateConditionsAreRejected()
    {
        $path = 'variants.0.rules.0.conditions.0.value';
        $this->assertSame([$path], $this->fails($this->payload('date', '$gte', '15/03/2026')));
        $this->assertSame([$path], $this->fails($this->payload('date', '$gt', 42)));
        $this->assertSame([$path], $this->fails($this->payload('date', '$contains', '2026')));
        $this->assertSame([$path], $this->fails($this->payload('date', '$between', '2026-12-31;2026-01-01')));
    }

    public function testNumericFieldsKeepTheirRules()
    {
        // The date grammar only applies to date fields.
        $path = 'variants.0.rules.0.conditions.0.value';
        $this->assertSame([], $this->fails($this->payload('numeric', '$gt', 42)));
        $this->assertSame([$path], $this->fails($this->payload('numeric', '$gt', '2026-01-01')));
    }

    public function testFieldKeysAreMatchedAsStored()
    {
        // Field::setKeyAttribute stores 'Claim Date' as 'claim_date'
        $payload = $this->payload('date', '$gte', '15/03/2026');
        $payload['fields'][0]['key'] = 'Claim Date';
        $this->assertSame(['variants.0.rules.0.conditions.0.value'], $this->fails($payload));
    }

    public function testPresetFieldRulesCompareThePresetResult()
    {
        // With a preset, rules compare its boolean result, not a date.
        $payload = $this->payload('date', '$eq', true);
        $payload['fields'][0]['preset'] = ['condition' => '$gte', 'value' => 'today-18y'];
        $this->assertSame([], $this->fails($payload));
    }

    public function testUnknownTypeIsRejected()
    {
        $this->assertContains('fields.0.type', $this->fails($this->payload('datetime', '$eq', '2026-01-01')));
    }

    public function requestValueProvider(): array
    {
        return [
            'date'          => ['2026-03-15', true],
            'datetime'      => ['2026-03-15T10:30:00+02:00', true],
            'null'          => [null, true],
            'relative'      => ['today', false],
            'french format' => ['15/03/2026', false],
        ];
    }

    /**
     * Decision requests send concrete dates: same rule as Scoring builds.
     *
     * @dataProvider requestValueProvider
     */
    public function testRequestValues($value, bool $valid)
    {
        $validator = \Validator::make(['claim_date' => $value], ['claim_date' => 'present|isoDate']);
        $this->assertSame($valid, $validator->passes());
    }
}
