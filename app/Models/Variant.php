<?php
/**
 * Variant Model
 *
 * Represents one variant embedded within a decision Table. A table must have at
 * least one variant; when multiple variants exist, the Table::getVariantForCheck()
 * method selects which one to use based on the table's variants_probability setting
 * (first, random, or weighted percent). Each variant carries its own ordered list
 * of Rules and a default_decision that is returned when no rule matches. Variants
 * enable A/B testing of different rule sets on the same table schema.
 *
 * @package App\Models
 */

namespace App\Models;

/**
 * Class Variant
 * @package App\Models
 * @property string $title
 * @property string $description
 * @property string $default_title
 * @property string $default_description
 * @property string $default_decision
 * @property Rule[] $rules
 */
class Variant extends Base
{
    // Timestamps are managed manually in Table::setVariants() because embedded
    // documents are not subject to Eloquent's automatic timestamp handling.
    public $timestamps = false;

    protected $attributes = [
        'title'               => '',
        'description'         => '',
        'default_title'       => '',
        'default_description' => '',
        'probability'         => 0,
        'is_default'          => false,
    ];

    protected $visible = [
        '_id',
        'title',
        'description',
        'default_decision',
        'default_title',
        'default_description',
        'probability',
        'is_default',
        'created_at',
        'created_by',
        'updated_at',
        'updated_by',
        'rules',
        'fields',
    ];

    protected $fillable = [
        '_id',
        'title',
        'description',
        'default_title',
        'default_description',
        'default_decision',
        'matching_type',
        'probability',
        'is_default',
        'created_at',
        'created_by',
        'updated_at',
        'updated_by',
    ];

    protected $casts = [
        '_id'          => 'string',
        'title'        => 'string',
        'description'  => 'string',
        'default_title'       => 'string',
        'default_description' => 'string',
        'is_default'   => 'boolean',
    ];

    /**
     * Expose rules as a serialisable relation for toArray().
     *
     * @return array
     */
    protected function getArrayableRelations()
    {
        return [
            'rules' => $this->rules,
        ];
    }

    /**
     * Define the embedded-many relationship for rules.
     *
     * Rules are evaluated in order during scoring; for decision-type tables the
     * first fully matching rule wins.
     *
     * @return \Jenssegers\Mongodb\Relations\EmbedsMany
     */
    public function rules()
    {
        return $this->embedsMany('App\Models\Rule');
    }

    /**
     * Replace all rules on this variant with a new set including their conditions.
     *
     * Clears existing rules first, then creates Rule models and delegates condition
     * creation to Rule::setConditions(). Returns $this for method chaining.
     *
     * @param  array $rules  Array of rule definition arrays (each optionally containing 'conditions').
     * @return $this
     */
    public function setRules($rules)
    {
        // Clear existing rules before writing the new set to prevent accumulation
        $this->rules()->delete();
        foreach ($rules as $rule) {
            $ruleModel = new Rule($rule);
            // Only set conditions when explicitly provided (some rules may have none)
            if (isset($rule['conditions'])) {
                $ruleModel->setConditions($rule['conditions']);
            }
            $this->rules()->associate($ruleModel);
        }

        return $this;
    }
}
