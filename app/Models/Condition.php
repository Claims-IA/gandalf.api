<?php
/**
 * Condition Model
 *
 * Represents a single condition embedded within a Rule. A condition specifies
 * which field to test (field_key), the comparison operator (condition, one of the
 * keys defined in ConditionsTypes), and the threshold value to compare against.
 * During scoring, the 'matched' boolean is set transiently and the condition
 * array is written into the Decision snapshot; 'probability' and 'requests' are
 * populated by the analytics system from historical decisions.
 *
 * @package App\Models
 */

namespace App\Models;

/**
 * Class Condition
 * @package App\Models
 * @property string $field_key
 * @property string $condition
 * @property string $value
 * @property bool $matched
 * @property integer $probability
 * @property integer $requests
 */
class Condition extends Base
{
    protected $visible = ['_id', 'field_key', 'condition', 'value', 'probability', 'requests'];

    protected $fillable = ['_id', 'field_key', 'condition', 'value'];

    /**
     * Normalise the field_key attribute to lowercase with spaces replaced by underscores.
     *
     * Mirrors the normalisation applied to Field::key so that condition lookups
     * during scoring always find the matching field regardless of how the key
     * was entered in the table editor.
     *
     * @param  string $value  Raw field_key value as submitted.
     * @return void
     */
    public function setFieldKeyAttribute($value)
    {
        $this->attributes['field_key'] = strtolower(str_replace(' ', '_', trim($value)));
    }
}
