<?php
/**
 * TablesRepository
 *
 * Provides data access for Table documents in MongoDB. Extends AbstractRepository
 * for standard CRUD operations, adds a filtered list method supporting title,
 * description, and matching_type queries, and an analytics method that aggregates
 * historical Decision data to calculate hit rates for every rule and condition in a
 * specific variant. The analytics query uses the low-level MongoDB driver directly
 * for performance, bypassing Eloquent's overhead for the large-batch aggregation.
 *
 * @package App\Repositories
 */

namespace App\Repositories;

use App\Models\Decision;
use MongoDB\BSON\Regex;
use MongoDB\Driver\Query;
use MongoDB\BSON\ObjectID;
use MongoDB\Driver\Manager;
use MongoDB\BSON\UTCDatetime;
use Nebo15\REST\AbstractRepository;
use Nebo15\LumenApplicationable\ApplicationableHelper;
use Nebo15\LumenApplicationable\Contracts\Applicationable;

/**
 * Class TablesRepository
 * @package App\Repositories
 * @method \App\Models\Table read($id)
 * @method \App\Models\Table getModel()
 * @method \App\Models\Table[] findByIds(array $ids)
 */
class TablesRepository extends AbstractRepository
{
    protected $modelClassName = 'App\Models\Table';

    protected $observerClassName = 'App\Observers\TableObserver';

    /**
     * Return a paginated, application-scoped list of tables with optional filters.
     *
     * Supports case-insensitive regex search on 'title' and 'description', and
     * exact match on 'matching_type'. Always scopes the query to the current
     * application ID.
     *
     * @param  array $filters  Associative array of filter keys and values.
     * @return \Illuminate\Pagination\LengthAwarePaginator
     */
    public function readListWithFilters(array $filters = [])
    {
        // Cast to int: query-string values arrive as strings, but MongoDB's
        // $limit stage requires a numeric argument (a string "20" throws a
        // CommandException). Keep null when absent so the model's default
        // page size applies.
        $size = isset($filters['size']) ? (int) $filters['size'] : null;
        if (!$filters) {
            return $this->readList($size);
        }
        // Only allow filtering on these fields to prevent arbitrary MongoDB queries
        $available = ['title', 'description'];

        $where = [];
        foreach ($filters as $field => $filter) {
            if (in_array($field, $available)) {
                // Case-insensitive prefix/substring regex match
                $where[$field] = new Regex($filter, 'i');
            }
        }
        // Exact match on matching_type (decision or scoring)
        if (!empty($filters['matching_type'])) {
            $where['matching_type'] = $filters['matching_type'];
        }
        // Exact match on category_id — a substring regex would let 'cat_a' match
        // 'cat_abc', so category filtering is an equality check, not a search.
        if (!empty($filters['category_id'])) {
            $where['category_id'] = $filters['category_id'];
        }
        // Always scope to the authenticated application for tenant isolation
        $where['applications'] = ApplicationableHelper::getApplicationId();

        return $this->getModel()->query()->where($where)->paginate($size);
    }

    /**
     * Create a new table or update an existing one with fields and variants.
     *
     * When $id is null a new Table instance is created; otherwise the existing
     * document is fetched first. The Applicationable helper ensures the current
     * application ID is added to the model's applications array. Fields and
     * variants are replaced atomically using their respective set methods.
     *
     * @param  array       $values  Validated request data.
     * @param  string|null $id      MongoDB ObjectID of the table to update, or null to create.
     * @return \App\Models\Table
     */
    public function createOrUpdate($values, $id = null)
    {
        /** @var \App\Models\Table $model */
        $model = $id ? $this->read($id) : $this->getModel()->newInstance();
        // Add the current application to the model's applications array if not already present
        if ($model instanceof Applicationable) {
            ApplicationableHelper::addApplication($model);
        }
        $model->fill($values);
        // Replace the full embedded fields set if provided
        if (isset($values['fields'])) {
            $model->setFields($values['fields']);
        }
        // Replace the full embedded variants set (including rules and conditions) if provided
        if (isset($values['variants'])) {
            $model->setVariants($values['variants']);
        }
        $model->save();

        return $model;
    }

    /**
     * Calculate per-rule and per-condition hit rates for a specific variant.
     *
     * Uses the low-level MongoDB driver (bypassing Eloquent) to efficiently query
     * all decisions for the given table/variant combination that were created after
     * the table's last modification. Builds a hit-rate map indexed by rule ID and
     * by "ruleId@conditionId", then annotates each rule and condition on the variant
     * with probability (matched/requests) and request count.
     *
     * Returns a cloned table with only the analysed variant, ready for serialisation.
     *
     * @param  string $table_id   MongoDB ObjectID of the table to analyse.
     * @param  string $variant_id MongoDB ObjectID of the variant to analyse.
     * @return \App\Models\Table  Cloned table containing only the annotated variant.
     */
    /**
     * Copy a decision table into a different project.
     *
     * Reads the source table, strips its identity and application association, then
     * saves a new copy owned exclusively by the target project. Fields, variants,
     * rules, and conditions are duplicated atomically via their respective set methods.
     *
     * @param  string $id         MongoDB ObjectID of the source table.
     * @param  string $project_id MongoDB ObjectID of the target project/application.
     * @return \App\Models\Table  The newly created copy.
     */
    public function copyTo($id, $project_id)
    {
        $source = $this->read($id);
        $values = $source->getAttributes();
        unset($values[$source->getKeyName()]);
        unset($values['applications']);

        /** @var \App\Models\Table $model */
        $model = $this->getModel()->newInstance();
        $model->applications = [new ObjectID($project_id)];
        $model->fill($values);
        if (isset($values['fields'])) {
            $model->setFields($values['fields']);
        }
        if (isset($values['variants'])) {
            $model->setVariants($values['variants']);
        }
        $model->save();

        return $model;
    }

    public function analyzeTableDecisions($table_id, $variant_id)
    {
        $table = $this->read($table_id);
        // Use the raw MongoDB driver for this heavy read to avoid Eloquent's per-row hydration overhead
        $mongo = new Manager(sprintf("mongodb://%s:%d", env('DB_HOST'), env('DB_PORT')));
        $query = new Query(
            [
            'table._id' => new ObjectID($table_id),
            'table.variant._id' => new ObjectId($variant_id),
            'applications' => ApplicationableHelper::getApplicationId(),
            // Only include decisions made after the table was last updated (older decisions
            // may reference rules that no longer exist in the current table definition)
            'created_at' => ['$gte' => new UTCDatetime($table->updated_at->timestamp * 1000)]
            ],
            // Project only the 'rules' field to minimise data transfer from MongoDB
            ['projection' => ['rules' => 1]]
        );
        $decisions = $mongo->executeQuery(env('DB_DATABASE') . '.' . (new Decision)->getTable(), $query)->toArray();
        // $map[ruleId] = ['matched' => N, 'requests' => N]
        // $map["ruleId@conditionId"] = ['matched' => N, 'requests' => N]
        $map = [];

        if (($decisionsAmount = count($decisions)) > 0) {
            foreach ($decisions as $decision) {
                $rules = $decision->rules;

                foreach ($rules as $rule) {
                    if (!isset($rule->_id)) {
                        // Skip legacy decisions created before rule IDs were stored
                        continue;
                    }
                    $ruleIndex = strval($rule->_id);
                    // Track each condition's individual hit rate
                    foreach ($rule->conditions as $condition) {
                        $index = "$ruleIndex@" . strval($condition->_id);
                        if (!isset($map[$index])) {
                            $map[$index] = ['matched' => 0, 'requests' => 0];
                        }

                        if ($condition->matched === true) {
                            $map[$index]['matched']++;
                        }
                        $map[$index]['requests']++;
                    }
                    // Track the overall rule hit rate (than === decision means the rule fired)
                    if (!isset($map[$ruleIndex])) {
                        $map[$ruleIndex] = ['matched' => 0, 'requests' => 0];
                    }
                    $map[$ruleIndex]['requests']++;
                    if ($rule->than === $rule->decision) {
                        $map[$ruleIndex]['matched']++;
                    }
                }
            }
        }

        // Annotate the live variant's rules and conditions with the calculated statistics
        $variant = $table->getVariantForCheck($variant_id);
        foreach ($variant->rules as $rule) {
            $ruleIndex = $rule->_id;
            foreach ($rule->conditions as $condition) {
                $index = "$ruleIndex@" . strval($condition->_id);
                if (array_key_exists($index, $map)) {
                    // probability = fraction of times this condition was satisfied
                    $condition->probability = round($map[$index]['matched'] / $map[$index]['requests'], 5);
                } else {
                    $condition->probability = null;
                }
                $condition->requests = array_key_exists($index, $map) ? $map[$index]['requests'] : 0;
                $rule->conditions()->associate($condition);
            }
            $ruleHasRequests = array_key_exists($ruleIndex, $map);
            $rule->probability = $ruleHasRequests ?
                round($map[$ruleIndex]['matched'] / $map[$ruleIndex]['requests'], 5) :
                0;
            $rule->requests = $ruleHasRequests ? $map[$ruleIndex]['requests'] : 0;
            $variant->rules()->associate($rule);
        }
        // Return a clone with only the analysed variant to avoid serialising other variants
        $clonedTable = clone $table;
        $clonedTable->variants = [];
        $clonedTable->variants()->associate($variant);

        return $clonedTable;
    }
}
