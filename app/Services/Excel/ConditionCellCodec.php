<?php
/**
 * ConditionCellCodec
 *
 * Bidirectional translation between the engine's condition operators and the
 * human-readable cell syntax used in the Excel export/import format.
 *
 * Cell grammar (case-insensitive keywords/prefixes, whitespace-tolerant):
 *
 *   *  / --- / any            → $any            (empty cell also means $any)
 *   +  / set / is set         → $is_set
 *   null / is null / is_null  → $is_null
 *   = x / == x / bare value   → $eq
 *   != x / <> x               → $ne
 *   > x  >= x  < x  <= x      → $gt $gte $lt $lte
 *   [a..b]                    → $between        (a <= x <= b)
 *   ]a..b[                    → $between_excl   (a <  x <  b)
 *   ]a..b]                    → $between_lexcl  (a <  x <= b)
 *   [a..b[                    → $between_rexcl  (a <= x <  b)
 *   not [a..b] / ![a..b]      → $not_between    (x < a or x > b)
 *   in: a, b, c               → $in             (value list passed verbatim)
 *   not in: a, b / nin: /!in: → $nin
 *   contains: x / ~ x         → $contains
 *   not contains: / !contains: / !~ x → $not_contains
 *   starts: x / starts with: x → $starts_with
 *   ends: x / ends with: x     → $ends_with
 *   'literal'                 → $eq with the quoted text taken verbatim
 *                                ('' inside quotes escapes a single quote)
 *
 * The quoting rule is the escape hatch that makes round-trips lossless: an
 * $eq value that would otherwise be re-parsed as an operator (e.g. ">= 3",
 * "null", "+", "in: x") is emitted single-quoted, and decode() strips exactly
 * one layer of quotes back to the literal value.
 *
 * Round-trip invariant: decode(encode($op, $value)) === [$op, $value]
 * (with $value normalized to string; see stringify()).
 *
 * @package App\Services\Excel
 */

namespace App\Services\Excel;

use App\Exceptions\ConditionCellParseException;

class ConditionCellCodec
{
    /**
     * Keyword cells (full-cell match, case-insensitive) → operator.
     * Order does not matter here: keys are exact matches after lowercasing.
     */
    private const KEYWORDS = [
        '*' => '$any',
        '---' => '$any',
        'any' => '$any',
        '+' => '$is_set',
        'set' => '$is_set',
        'is_set' => '$is_set',
        'is set' => '$is_set',
        'null' => '$is_null',
        'is null' => '$is_null',
        'is_null' => '$is_null',
    ];

    /**
     * Prefix operators → engine operator, ordered longest/most-specific first
     * so that "not contains:" is not swallowed by "contains:" etc.
     */
    private const PREFIXES = [
        'not contains:' => '$not_contains',
        '!contains:' => '$not_contains',
        'not in:' => '$nin',
        'nin:' => '$nin',
        '!in:' => '$nin',
        'starts with:' => '$starts_with',
        'starts:' => '$starts_with',
        'ends with:' => '$ends_with',
        'ends:' => '$ends_with',
        'contains:' => '$contains',
        'in:' => '$in',
        '!~' => '$not_contains',
        '>=' => '$gte',
        '<=' => '$lte',
        '!=' => '$ne',
        '<>' => '$ne',
        '==' => '$eq',
        '=' => '$eq',
        '>' => '$gt',
        '<' => '$lt',
        '~' => '$contains',
    ];

    /**
     * Interval operator → [opening bracket, closing bracket] of its canonical
     * serialization. "[" = inclusive bound, "]" (opening) / "[" (closing) = exclusive.
     */
    private const INTERVAL_BRACKETS = [
        '$between' => ['[', ']'],
        '$between_excl' => [']', '['],
        '$between_lexcl' => [']', ']'],
        '$between_rexcl' => ['[', '['],
    ];

    /**
     * Value placeholder stored for valueless operators ($any, $is_set, $is_null).
     * `true` passes the "required" Lumen rule and matches what the web UI stores.
     */
    public const VALUELESS_VALUE = true;

    // -------------------------------------------------------------------------
    // Decode: cell text → [operator, value]
    // -------------------------------------------------------------------------

    /**
     * Parse a condition cell into ['condition' => operator, 'value' => value].
     *
     * An empty cell is treated as "don't care" and returns a $any condition
     * (the validation layer requires every rule to cover every field, and the
     * engine's $any operator is a purpose-built always-true).
     *
     * @param  string $cell  Raw cell text.
     * @return array{condition: string, value: mixed}
     * @throws ConditionCellParseException  On unparseable syntax.
     */
    public function decode(string $cell): array
    {
        $cell = trim($cell);

        // Empty cell = "don't care" = $any
        if ($cell === '') {
            return ['condition' => '$any', 'value' => self::VALUELESS_VALUE];
        }

        // Fully quoted cell → $eq with the literal value ('' escapes ')
        if (strlen($cell) >= 2 && $cell[0] === "'" && substr($cell, -1) === "'") {
            $inner = substr($cell, 1, -1);
            // A lone escaped quote boundary like 'a'b' is malformed: after
            // unescaping, no unpaired quote may remain.
            $unescaped = str_replace("''", "\x00", $inner);
            if (strpos($unescaped, "'") !== false) {
                throw new ConditionCellParseException(
                    "Valeur quotée mal formée : \"$cell\". Doublez les apostrophes internes ('')."
                );
            }
            return ['condition' => '$eq', 'value' => str_replace("\x00", "'", $unescaped)];
        }

        // Keyword cells ($any / $is_set / $is_null shortcuts)
        $lower = mb_strtolower($cell);
        if (isset(self::KEYWORDS[$lower])) {
            return ['condition' => self::KEYWORDS[$lower], 'value' => self::VALUELESS_VALUE];
        }

        // Common typos rejected with a hint rather than silently mis-parsed
        if (str_starts_with($cell, '=>')) {
            throw new ConditionCellParseException("Opérateur inconnu '=>' — vouliez-vous dire '>=' ?");
        }
        if (str_starts_with($cell, '=<')) {
            throw new ConditionCellParseException("Opérateur inconnu '=<' — vouliez-vous dire '<=' ?");
        }

        // Negated interval: "not [a..b]" or "![a..b]"
        if (preg_match('/^(not\s+|!)(?=[\[\]])/i', $cell, $m)) {
            $interval = $this->decodeInterval(substr($cell, strlen($m[1])), $cell);
            if ($interval !== null) {
                if ($interval['condition'] !== '$between') {
                    throw new ConditionCellParseException(
                        "\"$cell\" : la négation d'intervalle ne supporte que les bornes incluses [a..b]."
                    );
                }
                return ['condition' => '$not_between', 'value' => $interval['value']];
            }
            // "not …" that is not an interval falls through to prefixes/bare $eq
        }

        // Interval: [a..b] and exclusive variants
        if ($cell[0] === '[' || $cell[0] === ']') {
            $interval = $this->decodeInterval($cell, $cell);
            if ($interval !== null) {
                return $interval;
            }
            throw new ConditionCellParseException(
                "Intervalle mal formé : \"$cell\". Format attendu : [min..max] (variantes ]..[, ]..], [..[)."
            );
        }

        // Prefix operators, longest first
        foreach (self::PREFIXES as $prefix => $operator) {
            if ($this->matchPrefix($lower, $prefix)) {
                $value = trim(substr($cell, strlen($prefix)));
                if ($value === '') {
                    throw new ConditionCellParseException(
                        "\"$cell\" : l'opérateur \"$prefix\" attend une valeur."
                    );
                }
                if (in_array($operator, ['$gt', '$gte', '$lt', '$lte'], true)) {
                    $value = $this->normalizeNumeric($value, $cell);
                }
                return ['condition' => $operator, 'value' => $value];
            }
        }

        // Friendly default: bare value = equality
        return ['condition' => '$eq', 'value' => $cell];
    }

    // -------------------------------------------------------------------------
    // Encode: [operator, value] → cell text
    // -------------------------------------------------------------------------

    /**
     * Serialize an engine condition into its canonical cell syntax.
     *
     * @param  string $operator  Engine operator key (e.g. '$gte').
     * @param  mixed  $value     Stored condition value.
     * @return string  Cell text whose decode() yields the same pair back.
     * @throws \InvalidArgumentException  On unknown operator.
     */
    public function encode(string $operator, $value): string
    {
        switch ($operator) {
            case '$any':
                return '*';
            case '$is_set':
                return '+';
            case '$is_null':
                return 'null';
            case '$eq':
                return $this->encodeEq($this->stringify($value));
            case '$ne':
                return '!= ' . $this->stringify($value);
            case '$gt':
                return '> ' . $this->stringify($value);
            case '$gte':
                return '>= ' . $this->stringify($value);
            case '$lt':
                return '< ' . $this->stringify($value);
            case '$lte':
                return '<= ' . $this->stringify($value);
            case '$in':
                return 'in: ' . $this->stringify($value);
            case '$nin':
                return 'not in: ' . $this->stringify($value);
            case '$contains':
                return 'contains: ' . $this->stringify($value);
            case '$not_contains':
                return 'not contains: ' . $this->stringify($value);
            case '$starts_with':
                return 'starts: ' . $this->stringify($value);
            case '$ends_with':
                return 'ends: ' . $this->stringify($value);
            case '$between':
            case '$between_excl':
            case '$between_lexcl':
            case '$between_rexcl':
                [$open, $close] = self::INTERVAL_BRACKETS[$operator];
                return $open . $this->encodeIntervalBounds($value) . $close;
            case '$not_between':
                return 'not [' . $this->encodeIntervalBounds($value) . ']';
            default:
                throw new \InvalidArgumentException("Opérateur inconnu : '$operator'.");
        }
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Try to parse an interval cell "[a..b]" (any bracket variant).
     * Returns null when the text does not match the interval shape at all.
     *
     * @param  string $text      The candidate interval (negation prefix already stripped).
     * @param  string $original  Original cell text, for error messages.
     * @return array{condition: string, value: string}|null
     * @throws ConditionCellParseException  On interval shape with non-numeric bounds.
     */
    private function decodeInterval(string $text, string $original): ?array
    {
        if (!preg_match('/^([\[\]])\s*(.+?)\s*\.\.\s*(.+?)\s*([\[\]])$/', $text, $m)) {
            return null;
        }

        [, $open, $min, $max, $close] = $m;

        $min = $this->normalizeNumeric($min, $original, 'borne');
        $max = $this->normalizeNumeric($max, $original, 'borne');

        // Map bracket pair to operator: "[" opening / "]" closing = inclusive
        $operator = match ($open . $close) {
            '[]' => '$between',
            '][' => '$between_excl',
            ']]' => '$between_lexcl',
            '[[' => '$between_rexcl',
            default => null,
        };

        if ($operator === null) {
            return null;
        }

        // Engine format for betweenString values: "min;max"
        return ['condition' => $operator, 'value' => $min . ';' . $max];
    }

    /**
     * Check whether the lowercased cell starts with the given operator prefix.
     * Word-like prefixes (ending in ':') match as-is; symbol prefixes must not
     * be immediately followed by another operator symbol (already handled by
     * longest-first ordering).
     */
    private function matchPrefix(string $lowerCell, string $prefix): bool
    {
        return str_starts_with($lowerCell, $prefix);
    }

    /**
     * Validate that a value is numeric (accepting the French decimal comma)
     * and normalize the comma to a dot so the Lumen 'numeric' rule passes.
     *
     * @throws ConditionCellParseException
     */
    private function normalizeNumeric(string $value, string $cell, string $what = 'valeur'): string
    {
        $normalized = str_replace(',', '.', $value);
        if (!is_numeric($normalized)) {
            throw new ConditionCellParseException(
                "\"$cell\" : la $what \"$value\" n'est pas numérique."
            );
        }
        return $normalized;
    }

    /**
     * Emit an $eq value, quoting it when the bare form would be re-parsed as
     * something else (operator prefix, keyword, interval, quote, or a value
     * whose leading/trailing whitespace a bare decode would trim away).
     */
    private function encodeEq(string $value): string
    {
        if ($this->needsQuoting($value)) {
            return "'" . str_replace("'", "''", $value) . "'";
        }
        return $value;
    }

    /**
     * A bare $eq value needs quoting when decode(value) would not return
     * [$eq, value] identically. Rather than duplicating the grammar rules,
     * simply re-decode the candidate and compare.
     */
    private function needsQuoting(string $value): bool
    {
        if ($value === '' || trim($value) !== $value) {
            return true;
        }
        try {
            $decoded = $this->decode($value);
        } catch (ConditionCellParseException $e) {
            return true;
        }
        return $decoded['condition'] !== '$eq' || $decoded['value'] !== $value;
    }

    /**
     * Serialize the engine's "min;max" interval value into "min..max".
     */
    private function encodeIntervalBounds($value): string
    {
        $parts = explode(';', $this->stringify($value), 2);
        $min = trim($parts[0]);
        $max = trim($parts[1] ?? '');
        return $min . '..' . $max;
    }

    /**
     * Normalize a stored condition value into its canonical string form.
     * Handles booleans/numbers coming from JSON-created conditions.
     */
    public function stringify($value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_float($value) && $value == (int) $value) {
            return (string) (int) $value;
        }
        return (string) $value;
    }
}
