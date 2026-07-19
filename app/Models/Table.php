<?php
/**
 * Table Model
 *
 * Represents a decision table stored in the MongoDB 'tables' collection. A table
 * defines the schema (fields) and evaluation logic (one or more variants, each
 * containing ordered rules with conditions) used by the Scoring service. Tables
 * support two matching types: 'decision' (first matching rule wins) and 'scoring'
 * (all matching rules contribute a numeric value). The Applicationable trait scopes
 * queries to the currently authenticated application for multi-tenant isolation.
 *
 * @package App\Models
 */

namespace App\Models;

use App\Exceptions\VariantNotFound;
use Nebo15\REST\Traits\ListableTrait;
use Nebo15\REST\Interfaces\ListableInterface;
use Nebo15\LumenApplicationable\Contracts\Applicationable;
use Nebo15\LumenApplicationable\Traits\ApplicationableTrait;

/**
 * Class Table
 * @package App\Models
 * @property string $title
 * @property string $description
 * @property string $matching_type
 * @property string $variants_probability
 * @property Variant[] $variants
 * @property Field[] $fields
 * @method static Decision findById($id)
 * @method static Decision create(array $attributes = [])
 * @method Decision save(array $options = [])
 * @method static \Illuminate\Pagination\LengthAwarePaginator paginate($perPage = 15, $columns = ['*'], $pageName = 'page', $page = null)
 */
class Table extends Base implements ListableInterface, Applicationable
{
    use ApplicationableTrait;

    protected $perPage = 20;

    protected $listable = ['_id', 'title', 'description', 'matching_type', 'category_id'];

    protected $attributes = [
        'title' => '',
        'description' => '',
        'matching_type' => 'first',
        'variants_probability' => '',
        // Optional reference to a category defined in the owning application's
        // settings.categories list. Null means "uncategorised".
        'category_id' => null,
    ];

    protected $visible = [
        '_id',
        'title',
        'description',
        'matching_type',
        'decision_type',
        'variants_probability',
        'category_id',
        'fields',
        'variants',
    ];

    protected $fillable = [
        'title',
        'description',
        'matching_type',
        'decision_type',
        'variants_probability',
        'category_id',
    ];

    protected $casts = [
        '_id' => 'string',
        'title' => 'string',
        'description' => 'string',
        'default_title' => 'string',
        'default_description' => 'string',
    ];

    /**
     * Expose fields and variants as serialisable relations for toArray().
     *
     * @return array
     */
    protected function getArrayableRelations()
    {
        return [
            'fields' => $this->fields,
            'variants' => $this->variants,
        ];
    }

    /**
     * Define the embedded-many relationship for field definitions.
     *
     * Fields are stored as a sub-array within the table document.
     *
     * @return \Jenssegers\Mongodb\Relations\EmbedsMany
     */
    public function fields()
    {
        return $this->embedsMany('App\Models\Field');
    }

    /**
     * Define the embedded-many relationship for table variants.
     *
     * Each variant contains its own set of rules used for A/B testing or
     * alternative scoring models on the same table schema.
     *
     * @return \Jenssegers\Mongodb\Relations\EmbedsMany
     */
    public function variants()
    {
        return $this->embedsMany('App\Models\Variant');
    }

    /**
     * Return the minimal summary representation used in list endpoints.
     *
     * Includes the listable scalar fields plus a reduced variant list
     * (id, title, description only) to avoid returning the full rule set.
     *
     * @return array
     */
    public function toListArray()
    {
        $array = [];
        foreach ($this->listable as $field) {
            $array[$field] = $this->$field;
        }
        // Include variant summaries so the frontend can show variant picker without fetching full table
        $array['variants'] = $this->variants()->get()->map(function (Variant $variant) {
            return [
                '_id'        => $variant->_id,
                'title'      => $variant->title,
                'description'=> $variant->description,
                'is_default' => (bool)$variant->is_default,
            ];
        });

        return $array;
    }

    /**
     * Replace all embedded fields with a new set.
     *
     * Deletes existing fields first, then creates new Field (and optional Preset)
     * models for each entry in $fields. Returns $this for chaining.
     *
     * @param  array $fields  Array of field definition arrays.
     * @return $this
     */
    public function setFields($fields)
    {
        // Clear existing fields before writing the new set to avoid duplicates
        $this->fields()->delete();
        foreach ($fields as $field) {
            $fieldModel = new Field($field);
            // Attach a Preset sub-document if a preset was supplied for this field
            if (isset($field['preset'])) {
                $fieldModel->preset()->associate(new Preset($field['preset']));
            }
            $this->fields()->associate($fieldModel);
        }

        return $this;
    }

    /**
     * Replace all embedded variants with a new set (including their rules).
     *
     * Snapshots existing variants before deletion to preserve immutable fields
     * (created_at, created_by, is_default) across updates. Sets updated_at and
     * updated_by on every variant using the currently authenticated user.
     *
     * is_default rules:
     *   - Preserved from the existing variant when its _id matches.
     *   - New variants (no matching _id) receive is_default = false.
     *   - If no variant ends up with is_default = true, the first one is promoted.
     *
     * @param  array $variants  Array of variant definition arrays (each containing 'rules').
     * @return $this
     */
    public function setVariants($variants)
    {
        // Snapshot existing variants indexed by string _id before they are deleted
        $existing = [];
        foreach ($this->variants()->get() as $v) {
            $existing[strval($v->_id)] = $v;
        }

        $now  = \Carbon\Carbon::now();
        $user = \Auth::guard()->user();
        $uid  = $user ? strval($user->getId()) : null;

        $this->variants()->delete();

        $hasDefault  = false;
        $builtModels = [];

        foreach ($variants as $variantData) {
            $existingId = isset($variantData['_id']) ? strval($variantData['_id']) : null;
            $prev       = ($existingId && isset($existing[$existingId])) ? $existing[$existingId] : null;

            // Preserve is_default from the database; new variants default to false
            $isDefault = $prev ? (bool)$prev->is_default : false;

            // Preserve created_at / created_by for existing variants
            $variantData['is_default']  = $isDefault;
            $variantData['created_at']  = $prev ? $prev->created_at : $now;
            $variantData['created_by']  = $prev ? $prev->created_by : $uid;
            $variantData['updated_at']  = $now;
            $variantData['updated_by']  = $uid;

            if ($isDefault) {
                $hasDefault = true;
            }

            $model = (new Variant($variantData))->setRules($variantData['rules'] ?? []);
            $this->variants()->associate($model);
            $builtModels[] = $model;
        }

        // Ensure exactly one variant carries is_default = true (promote the first if none do)
        if (!$hasDefault && !empty($builtModels)) {
            $builtModels[0]->is_default = true;
            $this->variants()->associate($builtModels[0]);
        }

        return $this;
    }

    /**
     * Select the variant to use for a scoring check.
     *
     * If $variantId is provided, look it up directly. Otherwise apply the table's
     * variants_probability strategy:
     *   - 'first':   always use the first variant (deterministic, useful for testing).
     *   - 'random':  pick uniformly at random (equal A/B split).
     *   - 'percent': weighted random selection using each variant's probability field,
     *                implementing a cumulative distribution traversal.
     *
     * @param  string|null $variantId  Specific variant to use, or null for auto-selection.
     * @return Variant
     * @throws VariantNotFound  If no matching variant can be found.
     */
    public function getVariantForCheck($variantId = null)
    {
        $variant = null;
        $collection = $this->variants()->get();
        if ($variantId) {
            // Caller explicitly requested a particular variant (e.g. for testing)
            $variant = $collection->find($variantId);
        } else {
            switch ($this->variants_probability) {
                case 'first':
                    // Deterministic: always return the first defined variant
                    $variant = $collection->first();
                    break;
                case 'random':
                    // Uniform random pick; falls back to first() if only one variant exists
                    $variant = $collection->count() > 1 ? $collection->random() : $collection->first();
                    break;
                case 'percent':
                    if ($collection->count() == 1) {
                        $variant = $collection->first();
                    } else {
                        // Cumulative distribution: walk variants summing probability until we
                        // exceed the random number. E.g. [70%, 30%]: rand=65 hits first variant.
                        $i = 0;
                        $percent = rand(1, 100);
                        /** @var Condition $item */
                        foreach ($collection->all() as $item) {
                            $i = $i + $item->probability;
                            if ($i >= $percent) {
                                $variant = $item;
                                break;
                            }
                        }
                    }
                    break;
                default:
                    // Unknown strategy — fall back to first variant as a safe default
                    $variant = $collection->first();
            }
        }
        if (!$variant) {
            throw new VariantNotFound;
        }

        return $variant;
    }

    /**
     * Return a collection of field key strings for this table.
     *
     * Used by the Scoring service to build the dynamic validation ruleset for
     * incoming decision requests (each field key becomes a required input).
     *
     * @return \Illuminate\Support\Collection
     */
    public function getFieldsKeys()
    {
        return $this->fields()->get()->map(function (Field $field) {
            return $field->key;
        });
    }
}
