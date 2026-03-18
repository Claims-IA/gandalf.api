<?php
/**
 * Field Model
 *
 * Represents a single input field definition embedded within a decision Table or
 * a Decision snapshot. Fields describe the data schema that consumers must supply
 * when requesting a decision: the key (machine name), display title, source
 * (always 'request' for now), data type (numeric/boolean/string), and an optional
 * Preset that applies a condition transform to the raw value before rules evaluate it.
 *
 * @package App\Models
 */

namespace App\Models;

/**
 * Class Field
 * @package App\Models
 * @property string $key
 * @property string $title
 * @property string $source
 * @property string $type
 * @property integer $index - technical property
 * @property Preset $preset
 */
class Field extends Base
{
    protected $fillable = ['_id', 'key', 'title', 'source', 'type'];

    protected $visible = ['_id', 'key', 'title', 'source', 'type', 'preset'];

    protected $attributes = [
        'preset' => null,
    ];

    protected $casts = [
        '_id' => 'string',
    ];

    /**
     * Expose the preset as a serialisable relation for toArray().
     *
     * Returns null when no preset is set so the JSON output always contains
     * the 'preset' key (with a null value) rather than omitting it.
     *
     * @return array
     */
    protected function getArrayableRelations()
    {
        return ['preset' => $this->preset ?: null];
    }

    /**
     * Define the embedded-one relationship for the optional field preset.
     *
     * A preset applies a pre-condition transform to the raw request value. For
     * example, a boolean preset "$is_set" converts the raw value to true/false
     * before the rule conditions evaluate it.
     *
     * @return \Jenssegers\Mongodb\Relations\EmbedsOne
     */
    public function preset()
    {
        return $this->embedsOne('App\Models\Preset');
    }

    /**
     * Normalise the key attribute to lowercase with spaces replaced by underscores.
     *
     * This ensures consistent key storage regardless of how an API consumer
     * formats the field name (e.g. "Card BIN", "card_bin", and "CARD_BIN" all
     * resolve to "card_bin").
     *
     * @param  string $value  Raw field key as submitted by the API caller.
     * @return void
     */
    public function setKeyAttribute($value)
    {
        $this->attributes['key'] = strtolower(str_replace(' ', '_', trim($value)));
    }
}
