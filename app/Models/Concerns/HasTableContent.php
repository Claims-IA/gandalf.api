<?php
/**
 * HasTableContent
 *
 * The content of a decision table: its fields, its variants (with their rules
 * and conditions) and how a variant is picked for a decision. Shared by Table,
 * which still holds a copy of its active version's content during release A,
 * and TableVersion, which holds the content of one version (see VERSIONS.md).
 *
 * @package App\Models\Concerns
 */

namespace App\Models\Concerns;

use App\Models\Field;
use App\Models\Preset;
use App\Models\Variant;
use App\Models\Condition;
use App\Exceptions\VariantNotFound;

trait HasTableContent
{
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
     * (created_at, created_by) across updates. Sets updated_at and updated_by on
     * every variant using the currently authenticated user.
     *
     * There is no default variant (VERSIONS.md, batch 2b): the split picks the
     * variant that runs, and an export names the variant it exports. An
     * `is_default` key sent by a client, or stored by an older version, is
     * dropped here.
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

        foreach ($variants as $variantData) {
            $existingId = isset($variantData['_id']) ? strval($variantData['_id']) : null;
            $prev       = ($existingId && isset($existing[$existingId])) ? $existing[$existingId] : null;

            // Preserve created_at / created_by for existing variants
            unset($variantData['is_default']);
            $variantData['created_at']  = $prev ? $prev->created_at : $now;
            $variantData['created_by']  = $prev ? $prev->created_by : $uid;
            $variantData['updated_at']  = $now;
            $variantData['updated_by']  = $uid;

            $model = (new Variant($variantData))->setRules($variantData['rules'] ?? []);
            $this->variants()->associate($model);
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
