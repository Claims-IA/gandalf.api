<?php
/**
 * ExcelLayout
 *
 * Single source of truth for the Excel round-trip workbook layout, shared by
 * ExcelTableWriter (export) and ExcelTableReader (import). Any change to the
 * physical layout must happen here so both sides stay in sync.
 *
 * Workbook structure:
 *   "Rules"  — visible grid: 1 title row, 3 header rows, then one rule per row.
 *   "_meta"  — veryHidden sheet holding table/variant identity and the
 *              optimistic-lock token (exported_at = Table.updated_at).
 *   "_help"  — visible cheat-sheet of the condition-cell grammar (ignored on read).
 *
 * "Rules" sheet map:
 *   Col A (hidden)   : _rule_id — blank = new rule, missing id = deleted rule.
 *   Col B..N         : one column per field. Row 2 = key, row 3 = type, row 4 = title.
 *   After last field : DECISION (rule 'than'), RULE_TITLE, RULE_DESC columns,
 *                      identified by sentinel literals in row 2.
 *   Row 5+           : rule rows (fully blank rows are skipped, not terminators).
 *
 * @package App\Services\Excel
 */

namespace App\Services\Excel;

class ExcelLayout
{
    /** Format identifier written to _meta; bump on breaking layout changes. */
    public const FORMAT_VERSION = 'gandalf-xlsx-v2';

    // Sheet names (fixed — avoids the 31-char/invalid-char sheet title problem)
    public const SHEET_RULES = 'Rules';
    public const SHEET_META = '_meta';
    public const SHEET_HELP = '_help';

    // Rules sheet rows
    public const ROW_TITLE = 1;   // merged workbook title (informational)
    public const ROW_KEYS = 2;    // machine layer: field keys + sentinels
    public const ROW_TYPES = 3;   // machine layer: field types
    public const ROW_TITLES = 4;  // human layer: field titles
    public const ROW_FIRST_RULE = 5;

    // Rules sheet fixed columns
    public const COL_RULE_ID = 1; // column A, hidden

    // Sentinel header literals (row 2) marking the trailing non-field columns
    public const SENTINEL_DECISION = 'DECISION';
    public const SENTINEL_RULE_TITLE = 'RULE_TITLE';
    public const SENTINEL_RULE_DESC = 'RULE_DESC';

    /** Hard cap on parsed rule rows — guards against pathological sheets. */
    public const MAX_RULE_ROWS = 5000;

    /** Number of data rows that get validation dropdowns / unlocked styling. */
    public const PREPARED_DATA_ROWS = 500;

    /**
     * _meta sheet: label (col A) → row number. Values live in column B,
     * written as explicit strings so Excel never coerces ids or ISO dates.
     */
    public const META_ROWS = [
        'format_version' => 1,
        'table_id' => 2,
        'variant_id' => 3,
        'exported_at' => 4,
        'matching_type' => 5,
        'decision_type' => 6,
        'variant_title' => 7,
        'variant_description' => 8,
        'default_decision' => 9,
        'table_title' => 10,
        'table_description' => 11,
    ];
}
