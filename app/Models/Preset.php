<?php
/**
 * Preset Model
 *
 * Represents an optional pre-processing transform embedded within a Field. When a
 * field has a preset, the Scoring service applies the preset's condition operator
 * (e.g. '$is_set') against the raw request value before evaluating rule conditions.
 * The result of the preset check (true/false or the transformed value) replaces the
 * raw value for all conditions referencing that field. Presets are cached per-field
 * within a single scoring run to avoid redundant computation.
 *
 * @package App\Models
 */

namespace App\Models;

/**
 * Class Preset
 *
 * @package App\Models
 * @property string $condition
 * @property string $value
 */
class Preset extends Base
{
    protected $visible = ['_id', 'condition', 'value'];

    protected $fillable = ['_id', 'condition', 'value'];

    protected $casts = [
        '_id' => 'string',
    ];
}
