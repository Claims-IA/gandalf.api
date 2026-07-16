<?php
/**
 * ExcelImportResult
 *
 * DTO produced by ExcelTableReader: the parsed content of a round-trip Excel
 * workbook plus the bookkeeping needed to (a) merge it back into an existing
 * table and (b) translate validation errors into cell addresses.
 *
 * @package App\Services\Excel
 */

namespace App\Services\Excel;

class ExcelImportResult
{
    /** @var string|null 24-hex table id from _meta, null when absent/blank */
    public ?string $tableId = null;

    /** @var string|null 24-hex variant id from _meta */
    public ?string $variantId = null;

    /** @var string ISO-8601 Table.updated_at at export time (optimistic-lock token) */
    public string $exportedAt = '';

    /** @var string Format identifier from _meta (e.g. 'gandalf-xlsx-v2') */
    public string $formatVersion = '';

    public string $tableTitle = '';
    public string $tableDescription = '';
    public string $matchingType = 'first';
    public string $decisionType = 'string';

    public string $variantTitle = '';
    public string $variantDescription = '';
    public string $defaultDecision = '';

    /**
     * Field definitions parsed from the header rows, in sheet column order:
     * [['key' => ..., 'title' => ..., 'type' => ...], ...]
     *
     * @var array
     */
    public array $fields = [];

    /**
     * Rules parsed from the data rows, in sheet row order:
     * [['_id' => string|null, 'title' => ..., 'description' => ...,
     *   'than' => ..., 'conditions' => [['field_key','condition','value'], ...]], ...]
     *
     * @var array
     */
    public array $rules = [];

    /**
     * Validation dot-path (relative to a single-variant payload, e.g.
     * "variants.0.rules.3.conditions.2.value") → cell address (e.g. "C8").
     * The merge service rebases the variant index when the target variant is
     * not at position 0 in the assembled payload.
     *
     * @var array<string, string>
     */
    public array $cellMap = [];

    /**
     * field_key → ['column' => 'C', 'title' => 'Âge'] for error decoration.
     *
     * @var array<string, array{column: string, title: string}>
     */
    public array $columnMap = [];

    /**
     * True when the _meta sheet carries both a table id and a variant id,
     * i.e. the file is a round-trip export that can update its origin.
     */
    public function hasOrigin(): bool
    {
        return $this->tableId !== null && $this->variantId !== null;
    }
}
