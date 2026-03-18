<?php
/**
 * Rule Model
 *
 * Represents a single rule embedded within a Variant (Table) or captured in a
 * Decision snapshot. A rule is composed of one or more Conditions that are all
 * AND-evaluated; if all conditions match, the rule's 'than' value becomes the
 * decision outcome (for decision-type tables) or contributes a numeric score
 * (for scoring-type tables). The 'probability' and 'requests' fields are populated
 * by the analytics system and reflect historical hit rates.
 *
 * @package App\Models
 */

namespace App\Models;

/**
 * Class Rule
 * @package App\Models
 * @property string $title
 * @property string $description
 * @property string $decision
 * @property string $than
 * @property float $probability
 * @property integer $requests
 * @property Condition[] $conditions
 *
 */
class Rule extends Base
{
    protected $visible = ['_id', 'title', 'decision', 'description', 'than', 'probability', 'requests', 'conditions'];

    protected $fillable = ['_id', 'title', 'description', 'than'];

    protected $casts = [
        '_id' => 'string',
        'title' => 'string',
        'description' => 'string',
    ];

    /**
     * Expose conditions as a serialisable relation for toArray().
     *
     * @return array
     */
    protected function getArrayableRelations()
    {
        return ['conditions' => $this->conditions];
    }

    /**
     * Define the embedded-many relationship for conditions.
     *
     * @return \Jenssegers\Mongodb\Relations\EmbedsMany
     */
    public function conditions()
    {
        return $this->embedsMany('App\Models\Condition');
    }

    /**
     * Replace all conditions on this rule with a new set.
     *
     * Clears existing conditions first, then associates each new Condition model.
     * Returns $this for method chaining.
     *
     * @param  array $conditions  Array of condition definition arrays.
     * @return $this
     */
    public function setConditions($conditions)
    {
        $this->conditions()->delete();
        foreach ($conditions as $condition) {
            $this->conditions()->associate(new Condition($condition));
        }

        return $this;
    }

    /**
     * Store the 'than' value rounded to 5 decimal places when it is a float.
     *
     * Floating-point precision differences in scoring sums can lead to unexpected
     * results; rounding keeps values predictable and avoids storing extremely
     * long decimal representations in MongoDB.
     *
     * @param  mixed $value  The decision outcome value (string, int, or float).
     * @return void
     */
    public function setThanAttribute($value)
    {
        $this->attributes['than'] = is_float($value) ? round($value, 5) : $value;
    }
}
