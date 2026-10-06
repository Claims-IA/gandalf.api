<?php
/**
 * DateValue
 *
 * Parsing and resolution of the values handled by `date` fields. A date field
 * works at calendar-day granularity:
 *
 *  - Request values (and absolute condition values) are ISO 8601 dates,
 *    "YYYY-MM-DD", optionally followed by a time part
 *    ("2026-03-15T10:30:00+02:00"). The time part is accepted for convenience
 *    and ignored: the day is the date as written, without timezone conversion.
 *  - Condition values may also be relative to the current day: "today",
 *    "today-30d", "today+1y" (units: d = days, w = weeks, m = months,
 *    y = years). "today" is the current date in DECISION_TIMEZONE, falling
 *    back to the application timezone (APP_TIMEZONE, itself UTC by default).
 *
 * Every value resolves to a day number (days since 1970-01-01), so the
 * numeric comparison operators of ConditionsTypes work unchanged on dates.
 *
 * @package App\Services
 */

namespace App\Services;

final class DateValue
{
    /** Operators a condition on a date field may use. */
    public const OPERATORS = [
        '$any', '$is_set', '$is_null',
        '$eq', '$ne', '$gt', '$gte', '$lt', '$lte',
        '$between', '$between_excl', '$between_lexcl', '$between_rexcl', '$not_between',
    ];

    /** Operators whose value is a "min;max" pair. */
    public const RANGE_OPERATORS = ['$between', '$between_excl', '$between_lexcl', '$between_rexcl', '$not_between'];

    /** Operators that ignore their value. */
    public const VALUELESS_OPERATORS = ['$any', '$is_set', '$is_null'];

    // YYYY-MM-DD, optionally followed by a time ("T" or space) and a UTC offset.
    private const ISO_PATTERN = '/^(\d{4})-(\d{2})-(\d{2})(?:[T ](?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d(?:\.\d+)?)?(?:Z|[+-](?:[01]\d|2[0-3]):?[0-5]\d)?)?$/i';

    // today, today-30d, Today + 1Y (case-insensitive, spaces tolerated).
    private const RELATIVE_PATTERN = '/^today(?:\s*([+-])\s*(\d{1,4})\s*([dwmy]))?$/i';

    /** @var \DateTimeImmutable|null "today" frozen by tests, see freezeToday(). */
    private static $frozenToday = null;

    /**
     * Whether the value is an ISO 8601 date (or date-time) — the only form
     * accepted for date fields in decision requests.
     *
     * @param  mixed $value
     * @return bool
     */
    public static function isIsoDate($value): bool
    {
        return self::parseIso($value) !== null;
    }

    /**
     * Whether the value is a relative expression ("today", "today-30d", …).
     *
     * @param  mixed $value
     * @return bool
     */
    public static function isRelative($value): bool
    {
        return is_string($value) && preg_match(self::RELATIVE_PATTERN, trim($value)) === 1;
    }

    /**
     * Resolve an ISO date or a relative expression to its day number (days
     * since 1970-01-01), or null when the value is neither.
     *
     * @param  mixed $value
     * @return int|null
     */
    public static function toDayNumber($value): ?int
    {
        $date = self::parseIso($value) ?? self::parseRelative($value);

        return $date === null ? null : intdiv($date->getTimestamp(), 86400);
    }

    /**
     * Canonical text of a date expression: relative expressions are lowercased
     * and stripped of spaces ("Today - 30D" → "today-30d"), ISO values are
     * trimmed. Null when the value is not a date expression.
     *
     * @param  mixed $value
     * @return string|null
     */
    public static function canonical($value): ?string
    {
        if (self::isRelative($value)) {
            return strtolower(preg_replace('/\s+/', '', $value));
        }

        return self::isIsoDate($value) ? trim($value) : null;
    }

    /**
     * Whether a condition value is valid for a date field under the operator.
     *
     * Range bounds must be in increasing order, like numeric ranges (see
     * GeneralValidator::betweenString), but only when that order cannot change
     * over time: two absolute dates, or two offsets from today in comparable
     * units. A mixed range such as "today;2026-12-31" is valid when saved and
     * becomes empty later on; rejecting it then would block every later save of
     * the table (the whole table is validated on each update).
     *
     * @param  string $operator
     * @param  mixed  $value
     * @return bool
     */
    public static function isValidConditionValue($operator, $value): bool
    {
        if (!in_array($operator, self::OPERATORS, true)) {
            return false;
        }
        if (in_array($operator, self::VALUELESS_OPERATORS, true)) {
            return true;
        }
        if (in_array($operator, self::RANGE_OPERATORS, true)) {
            $range = self::toEngineConditionValue($operator, $value);
            if ($range === null) {
                return false;
            }
            [$minExpr, $maxExpr] = explode(';', $value);
            if (!self::hasFixedOrder($minExpr, $maxExpr)) {
                return true;
            }
            [$min, $max] = explode(';', $range);

            return (int) $min < (int) $max;
        }

        return self::toDayNumber($value) !== null;
    }

    /**
     * Whether two date expressions keep the same order whatever the current day:
     * both absolute, or both relative with offsets in the same unit family (days
     * and weeks, or months and years; a bare "today" fits either).
     *
     * @param  string $a
     * @param  string $b
     * @return bool
     */
    private static function hasFixedOrder($a, $b): bool
    {
        if (self::isIsoDate($a) && self::isIsoDate($b)) {
            return true;
        }
        $familyA = self::relativeFamily($a);
        $familyB = self::relativeFamily($b);
        if ($familyA === null || $familyB === null) {
            return false;
        }

        return $familyA === 'any' || $familyB === 'any' || $familyA === $familyB;
    }

    /**
     * Unit family of a relative expression: 'days' (d, w), 'months' (m, y),
     * 'any' for a bare "today", null when the value is not relative.
     *
     * @param  mixed $value
     * @return string|null
     */
    private static function relativeFamily($value): ?string
    {
        if (!is_string($value) || !preg_match(self::RELATIVE_PATTERN, trim($value), $m)) {
            return null;
        }
        if (!isset($m[1])) {
            return 'any';
        }

        return in_array(strtolower($m[3]), ['d', 'w'], true) ? 'days' : 'months';
    }

    /**
     * Rewrite a date condition value into the numeric form the engine
     * compares: a day number, or "min;max" day numbers for range operators.
     * Null when the value is not a valid date expression.
     *
     * @param  string $operator
     * @param  mixed  $value
     * @return int|string|null
     */
    public static function toEngineConditionValue($operator, $value)
    {
        if (in_array($operator, self::RANGE_OPERATORS, true)) {
            $bounds = is_string($value) ? explode(';', $value) : [];
            if (count($bounds) !== 2) {
                return null;
            }
            $min = self::toDayNumber($bounds[0]);
            $max = self::toDayNumber($bounds[1]);

            return ($min === null || $max === null) ? null : $min . ';' . $max;
        }

        return self::toDayNumber($value);
    }

    /**
     * The current day, as a UTC midnight date carrying the calendar date of
     * DECISION_TIMEZONE (or the application timezone).
     *
     * @return \DateTimeImmutable
     */
    public static function today(): \DateTimeImmutable
    {
        if (self::$frozenToday !== null) {
            return self::$frozenToday;
        }

        $now = new \DateTimeImmutable('now', self::timezone());

        return self::utcDay((int) $now->format('Y'), (int) $now->format('n'), (int) $now->format('j'));
    }

    /**
     * Freeze "today" to a fixed ISO date so relative expressions are
     * deterministic. Tests only; pass null to unfreeze.
     *
     * @param  string|null $isoDate
     * @return void
     */
    public static function freezeToday($isoDate): void
    {
        self::$frozenToday = $isoDate === null ? null : self::parseIso($isoDate);
    }

    /**
     * @param  mixed $value
     * @return \DateTimeImmutable|null
     */
    private static function parseIso($value): ?\DateTimeImmutable
    {
        if (!is_string($value) || !preg_match(self::ISO_PATTERN, trim($value), $m)) {
            return null;
        }
        [, $year, $month, $day] = $m;
        if (!checkdate((int) $month, (int) $day, (int) $year)) {
            return null;
        }

        return self::utcDay((int) $year, (int) $month, (int) $day);
    }

    /**
     * @param  mixed $value
     * @return \DateTimeImmutable|null
     */
    private static function parseRelative($value): ?\DateTimeImmutable
    {
        if (!is_string($value) || !preg_match(self::RELATIVE_PATTERN, trim($value), $m)) {
            return null;
        }
        $today = self::today();
        if (!isset($m[1])) {
            return $today;
        }

        $amount = (int) $m[2] * ($m[1] === '-' ? -1 : 1);
        switch (strtolower($m[3])) {
            case 'd':
                return $today->modify("$amount days");
            case 'w':
                return $today->modify(($amount * 7) . ' days');
            case 'm':
                return self::addMonths($today, $amount);
            default:
                return self::addMonths($today, 12 * $amount);
        }
    }

    /**
     * Calendar month arithmetic, clamped to the end of the target month:
     * 2026-03-31 minus one month is 2026-02-28 (DateTime::modify() would
     * overflow to 2026-03-03).
     *
     * @param  \DateTimeImmutable $date
     * @param  int                $months
     * @return \DateTimeImmutable
     */
    private static function addMonths(\DateTimeImmutable $date, int $months): \DateTimeImmutable
    {
        $index = (int) $date->format('Y') * 12 + (int) $date->format('n') - 1 + $months;
        $year = intdiv($index, 12);
        $month = $index % 12 + 1;
        $lastDay = (int) self::utcDay($year, $month, 1)->format('t');

        return self::utcDay($year, $month, min((int) $date->format('j'), $lastDay));
    }

    private static function utcDay(int $year, int $month, int $day): \DateTimeImmutable
    {
        return (new \DateTimeImmutable('@0'))->setDate($year, $month, $day);
    }

    private static function timezone(): \DateTimeZone
    {
        $name = env('DECISION_TIMEZONE') ?: date_default_timezone_get();
        try {
            return new \DateTimeZone($name);
        } catch (\Exception $e) {
            // Misconfigured DECISION_TIMEZONE: fall back rather than fail every
            // decision, but leave a trace (php-fpm forwards error_log to stderr).
            error_log("DECISION_TIMEZONE '$name' is not a valid timezone; using " . date_default_timezone_get() . '.');
            return new \DateTimeZone(date_default_timezone_get());
        }
    }
}
