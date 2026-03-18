<?php
/**
 * DecisionsRepository
 *
 * Provides data access methods for Decision documents in MongoDB. Extends the
 * Nebo15/REST AbstractRepository to inherit standard CRUD operations, and adds
 * application-scoped list/query methods for admin dashboards. The updateMeta
 * method validates each key-value pair before persisting, keeping the metadata bag
 * well-formed and within size limits.
 *
 * @package App\Repositories
 */

namespace App\Repositories;

use App\Models\Decision;
use Nebo15\LumenApplicationable\ApplicationableHelper;
use Nebo15\REST\AbstractRepository;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Contracts\Validation\ValidationException;

/**
 * Class DecisionsRepository
 * @package App\Repositories
 * @method Decision read($id)
 */
class DecisionsRepository extends AbstractRepository
{
    protected $modelClassName = 'App\Models\Decision';

    /**
     * Return a paginated, application-scoped list of decisions.
     *
     * When $table_id is provided the query also checks that at least one decision
     * exists for that table (throws ModelNotFoundException if none found) and orders
     * results newest-first. When $variant_id is provided it filters by variant
     * instead. Without either filter all decisions for the application are returned.
     *
     * @param  int|null    $size        Page size (defaults to model's $perPage).
     * @param  string|null $table_id    Optional MongoDB ObjectID string for a specific table.
     * @param  string|null $variant_id  Optional MongoDB ObjectID string for a specific variant.
     * @return \Illuminate\Pagination\LengthAwarePaginator
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function getDecisions($size = null, $table_id = null, $variant_id = null)
    {
        /** @var \Jenssegers\Mongodb\Eloquent\Builder $query */
        if ($table_id) {
            // Filter by the embedded table._id field
            $query = $this->getModel()->query()->where('table._id', $table_id);
            // Return 404 if the table has never produced any decisions
            if ($query->count() <= 0) {
                $e = new ModelNotFoundException;
                $e->setModel(Decision::class);
                throw $e;
            }
            // Show most recent decisions first when browsing a specific table
            $query = $query->orderBy(Decision::CREATED_AT, 'DESC');
        } elseif ($variant_id) {
            // Filter by the embedded table.variant._id field for variant-level analytics
            $query = $this->getModel()->query()->where('table.variant._id', $variant_id);
        } else {
            // Default: all decisions for the application, newest first
            $query = Decision::orderBy(Decision::CREATED_AT, 'DESC');
        }
        // Always scope to the current application for multi-tenant isolation
        $query = $query->where('applications', ApplicationableHelper::getApplicationId());

        return $this->paginateQuery($query, $size);
    }

    /**
     * Retrieve a single decision using the consumer-safe field subset.
     *
     * @param  string $id  MongoDB ObjectID of the decision.
     * @return array
     */
    public function getConsumerDecision($id)
    {
        return $this->read($id)->toConsumerArray();
    }

    /**
     * Validate and persist a metadata bag onto an existing decision.
     *
     * Builds dynamic validation rules to ensure: each key is alphanumeric/dash
     * (max 100 chars), each value is non-empty (max 500 chars), and the total
     * number of keys does not exceed 24. Throws a ValidationException if any
     * constraint is violated.
     *
     * @param  string $id    MongoDB ObjectID of the decision.
     * @param  array  $meta  Key-value metadata array to attach.
     * @return Decision
     * @throws \Illuminate\Contracts\Validation\ValidationException
     */
    public function updateMeta($id, $meta)
    {
        $decision = $this->read($id);

        // Build a flat key/value structure from the meta array so the standard
        // Validator can apply individual rules to each key and value separately
        $i = 0;
        $rules = [];
        $values = [];
        foreach ($meta as $key => $value) {
            $values["key_$i"] = $key;
            $values["key_{$i}_value"] = $value;
            $rules["key_$i"] = 'required|max:100|alpha_dash';
            $rules["key_{$i}_value"] = 'required|max:500|regex:/.+/';
            $i++;
        }
        // Also validate the total key count (max 24 metadata keys per decision)
        $values['meta_keys_amount'] = count($rules) / 2;
        $rules['meta_keys_amount'] = 'numeric|max:24';

        $validator = \Validator::make($values, $rules);
        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $decision->meta = $meta;

        return $decision->save();
    }
}
