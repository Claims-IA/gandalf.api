<?php
/**
 * DateValueTest
 *
 * Unit tests for the date field grammar (ISO dates, relative "today±N"
 * expressions) and for the evaluation of date conditions by ConditionsTypes.
 * "today" is frozen so relative expressions are deterministic.
 */

use App\Services\ConditionsTypes;
use App\Services\DateValue;

class DateValueTest extends \Codeception\TestCase\Test
{
    protected function _before()
    {
        DateValue::freezeToday('2026-03-31');
    }

    protected function _after()
    {
        DateValue::freezeToday(null);
    }

    private function day(string $iso): int
    {
        return intdiv((new \DateTimeImmutable($iso . 'T00:00:00Z'))->getTimestamp(), 86400);
    }

    // -------------------------------------------------------------------------
    // ISO values
    // -------------------------------------------------------------------------

    public function isoProvider(): array
    {
        return [
            'date'                  => ['2026-03-15', true],
            'datetime Z'            => ['2026-03-15T10:30:00Z', true],
            'datetime offset'       => ['2026-03-15T23:30:00+02:00', true],
            'datetime no seconds'   => ['2026-03-15T23:30', true],
            'datetime fraction'     => ['2026-03-15T23:30:00.123Z', true],
            'space separator'       => ['2026-03-15 08:00:00', true],
            'leap day'              => ['2024-02-29', true],
            'not a leap day'        => ['2026-02-29', false],
            'month 13'              => ['2026-13-01', false],
            'hour 24'               => ['2026-03-15T24:00', false],
            'french format'         => ['15/03/2026', false],
            'no padding'            => ['2026-3-5', false],
            'relative is not ISO'   => ['today', false],
            'empty'                 => ['', false],
            'number'                => [20260315, false],
            'null'                  => [null, false],
        ];
    }

    /**
     * @dataProvider isoProvider
     */
    public function testIsIsoDate($value, bool $expected)
    {
        $this->assertSame($expected, DateValue::isIsoDate($value));
    }

    public function testTimePartIsIgnored()
    {
        // The day is the date as written, without timezone conversion.
        $this->assertSame($this->day('2026-03-15'), DateValue::toDayNumber('2026-03-15T23:30:00+02:00'));
        $this->assertSame($this->day('2026-03-15'), DateValue::toDayNumber('2026-03-15T00:30:00-05:00'));
    }

    public function testDaysBeforeEpoch()
    {
        $this->assertSame(-1, DateValue::toDayNumber('1969-12-31'));
        $this->assertSame(0, DateValue::toDayNumber('1970-01-01'));
    }

    // -------------------------------------------------------------------------
    // Relative expressions (today frozen to 2026-03-31)
    // -------------------------------------------------------------------------

    public function relativeProvider(): array
    {
        return [
            'today'              => ['today', '2026-03-31'],
            'minus days'         => ['today-30d', '2026-03-01'],
            'plus days'          => ['today+1d', '2026-04-01'],
            'weeks'              => ['today-2w', '2026-03-17'],
            'month end clamped'  => ['today-1m', '2026-02-28'],
            'months across year' => ['today-15m', '2024-12-31'],
            'years'              => ['today-18y', '2008-03-31'],
            'spaces and case'    => [' Today - 30D ', '2026-03-01'],
        ];
    }

    /**
     * @dataProvider relativeProvider
     */
    public function testRelativeExpressions(string $expression, string $expected)
    {
        $this->assertSame($this->day($expected), DateValue::toDayNumber($expression));
    }

    public function testLeapDayYearArithmeticIsClamped()
    {
        DateValue::freezeToday('2024-02-29');
        $this->assertSame($this->day('2025-02-28'), DateValue::toDayNumber('today+1y'));
    }

    public function invalidExpressionProvider(): array
    {
        return [
            'unknown unit'    => ['today-3h'],
            'two offsets'     => ['today-1y+2d'],
            'missing amount'  => ['today-d'],
            'yesterday'       => ['yesterday'],
            'now'             => ['now'],
        ];
    }

    /**
     * @dataProvider invalidExpressionProvider
     */
    public function testInvalidExpressions(string $expression)
    {
        $this->assertNull(DateValue::toDayNumber($expression));
    }

    public function testCanonical()
    {
        $this->assertSame('today-30d', DateValue::canonical(' Today - 30D '));
        $this->assertSame('2026-03-15', DateValue::canonical(' 2026-03-15 '));
        $this->assertNull(DateValue::canonical('15/03/2026'));
    }

    // -------------------------------------------------------------------------
    // Condition values (validation)
    // -------------------------------------------------------------------------

    public function conditionValueProvider(): array
    {
        return [
            'eq iso'                 => ['$eq', '2026-03-15', true],
            'gte relative'           => ['$gte', 'today-30d', true],
            'lt datetime'            => ['$lt', '2026-03-15T10:00:00Z', true],
            'gt not a date'          => ['$gt', '42', false],
            'range iso'              => ['$between', '2026-01-01;2026-12-31', true],
            'range relative'         => ['$between_excl', 'today-1y;today', true],
            'range reversed'         => ['$between', '2026-12-31;2026-01-01', false],
            'range single day'       => ['$between', '2026-01-01;2026-01-01', false],
            'range one bound'        => ['$not_between', '2026-01-01', false],
            'range bad bound'        => ['$between_rexcl', '2026-01-01;soon', false],
            'valueless any'          => ['$any', true, true],
            'valueless is_null'      => ['$is_null', true, true],
            'string op rejected'     => ['$contains', '2026', false],
            'list op rejected'       => ['$in', '2026-01-01, 2026-01-02', false],
        ];
    }

    /**
     * @dataProvider conditionValueProvider
     */
    public function testIsValidConditionValue(string $operator, $value, bool $expected)
    {
        $this->assertSame($expected, DateValue::isValidConditionValue($operator, $value));
    }

    // -------------------------------------------------------------------------
    // Evaluation through ConditionsTypes
    // -------------------------------------------------------------------------

    public function evaluationProvider(): array
    {
        return [
            // operator, condition value, request value, expected
            'eq same day'                => ['$eq', '2026-03-15', '2026-03-15', true],
            'eq same day other format'   => ['$eq', '2026-03-15', '2026-03-15T23:30:00+02:00', true],
            'ne'                         => ['$ne', '2026-03-15', '2026-03-16', true],
            'gt'                         => ['$gt', '2026-03-15', '2026-03-16', true],
            'gt equal'                   => ['$gt', '2026-03-15', '2026-03-15', false],
            'gte equal'                  => ['$gte', '2026-03-15', '2026-03-15', true],
            'lt across years'            => ['$lt', '2026-01-01', '2025-12-31', true],
            'within last 30 days'        => ['$gte', 'today-30d', '2026-03-10', true],
            'older than 30 days'         => ['$gte', 'today-30d', '2026-02-27', false],
            'in the past'                => ['$lt', 'today', '2026-03-30', true],
            'between inclusive bound'    => ['$between', '2026-01-01;2026-03-31', '2026-03-31', true],
            'between_excl bound'         => ['$between_excl', '2026-01-01;2026-03-31', '2026-03-31', false],
            'between_lexcl lower bound'  => ['$between_lexcl', '2026-01-01;2026-03-31', '2026-01-01', false],
            'between_rexcl lower bound'  => ['$between_rexcl', '2026-01-01;2026-03-31', '2026-01-01', true],
            'between relative'           => ['$between', 'today-1y;today', '2025-06-01', true],
            'not_between outside'        => ['$not_between', '2026-01-01;2026-03-31', '2025-12-31', true],
            'not_between inside'         => ['$not_between', '2026-01-01;2026-03-31', '2026-02-01', false],
            'invalid request never matches' => ['$ne', '2026-03-15', 'not a date', false],
            'invalid condition never matches' => ['$lt', 'soon', '2026-03-15', false],
            'null request'               => ['$lt', 'today', null, false],
        ];
    }

    /**
     * @dataProvider evaluationProvider
     */
    public function testEvaluation(string $operator, $conditionValue, $requestValue, bool $expected)
    {
        $engine = new ConditionsTypes();
        $this->assertSame($expected, $engine->checkConditionValue($operator, $conditionValue, $requestValue, 'date'));
    }

    public function testValuelessOperatorsSeeTheRawValue()
    {
        $engine = new ConditionsTypes();
        $this->assertTrue($engine->checkConditionValue('$is_null', true, null, 'date'));
        $this->assertFalse($engine->checkConditionValue('$is_null', true, '2026-03-15', 'date'));
        $this->assertTrue($engine->checkConditionValue('$any', true, null, 'date'));
    }

    public function testOtherTypesAreUnchanged()
    {
        // Without the date type, the range operator still parses numbers.
        $engine = new ConditionsTypes();
        $this->assertTrue($engine->checkConditionValue('$between', '1;5', 3, 'numeric'));
        $this->assertTrue($engine->checkConditionValue('$between', '1;5', 3));
    }
}
