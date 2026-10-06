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
use App\Models\Field;
use App\Services\Excel\ConditionCellCodec;
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
    /** @var array What the last createOrUpdate did to field keys and flows. */
    private $lastFieldRenames = [];

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
        // A field sent with its stored _id but another key is a rename. Orphan
        // conditions still using the old key follow it (the editor renames them
        // itself; this covers API clients), and so do the application's flows
        // using this table, once the table is saved.
        $this->lastFieldRenames = [];
        list($values, $renames) = $id ? $this->applyFieldRenames($model, $values) : [$values, []];
        // Enforce the table invariant: every variant shares the SAME set of
        // fields (columns), so each rule must carry exactly one condition per
        // field, in field order. Realign before persisting so a client that
        // sends drifted conditions (extra/missing/reordered) can never store an
        // inconsistent table. Skip only when we cannot determine the field set
        // (fields neither provided nor already stored) — normalising against an
        // empty field set would wrongly wipe every condition. In practice the
        // API always sends fields ('required'); this guards programmatic calls.
        if (isset($values['variants'])) {
            $fields = isset($values['fields']) ? $values['fields'] : $this->existingFields($model);
            if (!empty($fields)) {
                $values['variants'] = $this->normalizeVariantConditions($fields, $values['variants']);
            }
        }
        // Replace the full embedded fields set if provided
        if (isset($values['fields'])) {
            $model->setFields($values['fields']);
        }
        // Replace the full embedded variants set (including rules and conditions) if provided
        if (isset($values['variants'])) {
            $model->setVariants($values['variants']);
        }
        $model->save();

        if ($renames) {
            $this->lastFieldRenames = ['field_renames' => (object) $renames]
                + (new FlowRepository())->followFieldRenames((string) $model->_id, $renames);
        }

        return $model;
    }

    /**
     * Detect the field renames of an update payload and point its conditions at
     * the new keys. Runs on the raw request body too (see withRenamedConditions):
     * malformed entries are skipped, and left to the validation.
     *
     * @param  \App\Models\Table $model   The stored table (before its fields are replaced).
     * @param  array             $values  Update payload.
     * @return array  [payload, renames (old key => new key)]
     */
    private function applyFieldRenames($model, array $values)
    {
        if (!isset($values['fields']) || !is_array($values['fields'])) {
            return [$values, []];
        }
        $renames = self::fieldRenamesBetween($this->existingFields($model), $values['fields']);
        if ($renames && isset($values['variants']) && is_array($values['variants'])) {
            $values['variants'] = $this->renameConditions($model, $values['variants'], $values['fields'], $renames);
        }

        return [$values, $renames];
    }

    /**
     * Fields renamed between two field lists: present in both with the same _id
     * and another key. Two fields may swap keys. A field _id listed twice counts
     * once, its last occurrence, as Table::setFields stores it (the validation
     * rejects duplicated _ids; this keeps programmatic callers consistent).
     * Shared by table updates and changelog rollbacks.
     *
     * @param  array $before  Stored fields.
     * @param  array $after   New fields.
     * @return array  old key => new key (normalized keys)
     */
    public static function fieldRenamesBetween(array $before, array $after)
    {
        $keys = function (array $fields) {
            $byId = [];
            foreach ($fields as $field) {
                $id = is_array($field) && isset($field['_id']) ? self::idString($field['_id']) : null;
                if ($id !== null && isset($field['key']) && is_scalar($field['key'])) {
                    $byId[$id] = Field::normalizeKey($field['key']);
                }
            }
            return $byId;
        };

        $renames = [];
        $stored = $keys($before);
        foreach ($keys($after) as $id => $key) {
            if (isset($stored[$id]) && $stored[$id] !== $key) {
                $renames[$stored[$id]] = $key;
            }
        }

        return $renames;
    }

    /**
     * An id as a string (scalar or ObjectID), null for anything else.
     *
     * @param  mixed $value
     * @return string|null
     */
    private static function idString($value)
    {
        return (is_scalar($value) || $value instanceof ObjectID) ? (string) $value : null;
    }

    /**
     * Point the submitted conditions of renamed fields at their new key, rule by
     * rule.
     *
     * A condition already stored (known _id) follows the rename only if the
     * client left its key as stored, so conditions the client renamed itself,
     * including a swap or a chain of renames, are left alone and every rename
     * applies once. A new condition (no stored _id) still using an old key that
     * is no longer a field follows it too. A stored condition left on a key that
     * a renamed field now takes belonged to a field removed by the update, and is
     * dropped. A condition the client wrote on the new key wins: the rule's
     * condition on the old key then does not move.
     *
     * Idempotent (createOrUpdate re-applies what withRenamedConditions did): a
     * moved condition no longer carries its stored key, and a dropped one is gone.
     *
     * @param  \App\Models\Table $model     The stored table.
     * @param  array             $variants  Submitted variants.
     * @param  array             $fields    Submitted fields.
     * @param  array             $renames   old key => new key
     * @return array
     */
    private function renameConditions($model, array $variants, array $fields, array $renames)
    {
        $stored = $this->storedConditionKeys($model);
        $fieldKeys = [];   // normalized key => number of submitted fields using it
        foreach ($fields as $field) {
            if (is_array($field) && isset($field['key']) && is_scalar($field['key'])) {
                $key = Field::normalizeKey($field['key']);
                $fieldKeys[$key] = (isset($fieldKeys[$key]) ? $fieldKeys[$key] : 0) + 1;
            }
        }
        // New keys held by the renamed field alone (a duplicated key is left to the validation)
        $targets = [];
        foreach ($renames as $new) {
            if (!isset($renames[$new]) && isset($fieldKeys[$new]) && $fieldKeys[$new] === 1) {
                $targets[(string) $new] = true;
            }
        }

        foreach ($variants as $v => $variant) {
            if (!is_array($variant) || !isset($variant['rules']) || !is_array($variant['rules'])) {
                continue;
            }
            foreach ($variant['rules'] as $r => $rule) {
                if (!is_array($rule) || !isset($rule['conditions']) || !is_array($rule['conditions'])) {
                    continue;
                }
                $conditions = $rule['conditions'];
                $moves = [];      // condition index => new key
                $drops = [];      // condition index => true
                $written = [];    // new keys the client already wrote a condition on
                foreach ($conditions as $c => $condition) {
                    if (!is_array($condition) || !isset($condition['field_key']) || !is_scalar($condition['field_key'])) {
                        continue;
                    }
                    $key = Field::normalizeKey($condition['field_key']);
                    $id = isset($condition['_id']) ? self::idString($condition['_id']) : null;
                    $isStored = $id !== null && isset($stored[$id]);
                    if ($isStored && $key === $stored[$id] && isset($renames[$key])) {
                        $moves[$c] = (string) $renames[$key];
                    } elseif (!$isStored && !isset($fieldKeys[$key]) && isset($renames[$key])) {
                        $moves[$c] = (string) $renames[$key];
                    } elseif ($isStored && $key === $stored[$id] && isset($targets[$key])) {
                        $drops[$c] = true;
                    } elseif (isset($targets[$key])) {
                        $written[$key] = true;
                    }
                }
                foreach ($moves as $c => $new) {
                    if (!isset($written[$new])) {
                        $conditions[$c]['field_key'] = $new;
                    }
                }
                if ($moves || $drops) {
                    $variants[$v]['rules'][$r]['conditions'] = array_values(array_diff_key($conditions, $drops));
                }
            }
        }

        return $variants;
    }

    /**
     * Stored conditions' field keys, by condition _id.
     *
     * @param  \App\Models\Table $model
     * @return array  condition _id => field key
     */
    private function storedConditionKeys($model)
    {
        $keys = [];
        foreach (($model->variants ?: []) as $variant) {
            foreach (($variant->rules ?: []) as $rule) {
                foreach (($rule->conditions ?: []) as $condition) {
                    if ($condition->_id !== null && $condition->field_key !== null) {
                        $keys[(string) $condition->_id] = $condition->field_key;
                    }
                }
            }
        }

        return $keys;
    }

    /**
     * Apply the field renames of an update payload to its conditions before it
     * is validated, so each condition is validated against its field's type
     * (see TablesController::update). createOrUpdate applies the same rewrite,
     * which is idempotent, for programmatic callers. The payload is not validated
     * yet: malformed entries are left as they are, for the validation to reject.
     *
     * @param  string $id      Table id.
     * @param  array  $values  Update payload.
     * @return array
     */
    public function withRenamedConditions($id, array $values)
    {
        if (!isset($values['fields'], $values['variants']) || !is_array($values['fields']) || !is_array($values['variants'])) {
            return $values;
        }

        return $this->applyFieldRenames($this->read($id), $values)[0];
    }

    /**
     * What the last createOrUpdate did to field keys and flows (empty when no
     * field was renamed): field_renames, flows_updated, flows_failed.
     *
     * @return array
     */
    public function lastFieldRenames()
    {
        return $this->lastFieldRenames;
    }

    /**
     * Fields already stored on a model, as plain arrays (fallback when a
     * variants-only update omits the fields key).
     *
     * @param  \App\Models\Table $model
     * @return array
     */
    private function existingFields($model)
    {
        $fields = [];
        foreach (($model->fields ?: []) as $field) {
            $fields[] = is_array($field) ? $field : $field->toArray();
        }
        return $fields;
    }

    /**
     * Realign every rule's conditions to the table's field set.
     *
     * The columns of a decision table are shared by all its variants, so each
     * rule must hold exactly one condition per field, in field order. For each
     * rule we keep the existing condition matching a field (by field_key) and
     * synthesise a neutral '$any' condition (always true) for any field the rule
     * was missing — so adding a column never changes an existing rule's outcome.
     * Conditions referencing an unknown field_key (orphans) are dropped.
     *
     * @param  array $fields    Field definitions (each with a 'key').
     * @param  array $variants  Variant definitions (each with 'rules').
     * @return array            The variants with realigned rule conditions.
     */
    private function normalizeVariantConditions($fields, $variants)
    {
        // Keys compared in their stored form (Field::normalizeKey), so a submitted
        // "Cause Sinistre" matches a condition on "cause_sinistre".
        $fieldKeys = [];
        foreach ($fields as $field) {
            if (isset($field['key']) && $field['key'] !== '') {
                $fieldKeys[] = Field::normalizeKey($field['key']);
            }
        }

        foreach ($variants as &$variant) {
            if (!isset($variant['rules']) || !is_array($variant['rules'])) {
                continue;
            }
            foreach ($variant['rules'] as &$rule) {
                $existing = [];
                foreach ((isset($rule['conditions']) && is_array($rule['conditions']) ? $rule['conditions'] : []) as $condition) {
                    if (isset($condition['field_key'])) {
                        $conditionKey = Field::normalizeKey($condition['field_key']);
                        // First condition wins if a rule somehow has duplicates.
                        if (!array_key_exists($conditionKey, $existing)) {
                            $existing[$conditionKey] = $condition;
                        }
                    }
                }

                $aligned = [];
                foreach ($fieldKeys as $key) {
                    if (array_key_exists($key, $existing)) {
                        $aligned[] = $existing[$key];
                    } else {
                        // Neutral condition; a value is required by the validation
                        // (required|conditionType): the one the editor and the Excel
                        // codec store for valueless operators.
                        $aligned[] = ['field_key' => $key, 'condition' => '$any', 'value' => ConditionCellCodec::VALUELESS_VALUE];
                    }
                }
                $rule['conditions'] = $aligned;
            }
            unset($rule);
        }
        unset($variant);

        return $variants;
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

        return $this->duplicateInto($source, $project_id);
    }

    /**
     * Duplicate a table document into a target application.
     *
     * Strips identity and the source application association, resets category_id
     * (categories are per-application, so a source category would be an orphan in
     * the target), records the origin (Table::originId) and re-applies the embedded
     * fields and variants. Shared by the
     * table copyTo endpoint and the flow copy/move path (which copies a flow's
     * referenced tables into the target). Public so FlowRepository can reuse it.
     *
     * @param  \App\Models\Table $source
     * @param  string            $project_id  Target application id.
     * @return \App\Models\Table  The newly created copy.
     */
    public function duplicateInto(\App\Models\Table $source, $project_id)
    {
        $values = $source->getAttributes();
        unset($values[$source->getKeyName()]);
        unset($values['applications']);
        // A category belongs to the source application's settings.categories list;
        // it would be an orphan in the target, so the copy starts uncategorised.
        unset($values['category_id']);

        /** @var \App\Models\Table $model */
        $model = $this->getModel()->newInstance();
        // Store the application id as a string, consistent with how the rest of
        // the codebase stores `applications` (ApplicationableHelper::addApplication
        // pushes Application->_id, which is cast to string) and with how
        // findProjectTable / AbstractRepository::read query it.
        $model->applications = [(string) $project_id];
        $model->category_id = null;
        // Remember where the copy comes from (not fillable, so set directly).
        $model->origin_table_id = $source->originId();
        $model->fill($values);
        // Realign conditions on the copy too, so a duplicate can never inherit
        // (or introduce) a drifted field/condition set.
        if (isset($values['variants'])) {
            $fields = isset($values['fields']) ? $values['fields'] : [];
            $values['variants'] = $this->normalizeVariantConditions($fields, $values['variants']);
        }
        if (isset($values['fields'])) {
            $model->setFields($values['fields']);
        }
        if (isset($values['variants'])) {
            $model->setVariants($values['variants']);
        }
        $model->save();

        return $model;
    }

    /**
     * Move a table to another application (change ownership, no duplication).
     *
     * Reads the source (scoped to the current application), reassigns the sole
     * owning application to the target, and resets category_id (per-application).
     * The applications array is set directly rather than via the Applicationable
     * trait's removeApplication(), which has a known bug (assignment instead of
     * comparison) that would drop every association.
     *
     * CAVEAT: if the moved table is referenced by a flow that stays in the source
     * application, that flow becomes non-executable there (FlowRepository::
     * findProjectTable resolves a node's table within the flow's own application,
     * and the table is no longer in it). This is not detected/blocked here — moving
     * a shared table is the caller's decision. Copying a flow, by contrast, brings
     * its tables into the target (duplicated, or reused when a compatible table of
     * the same origin is already there), so it never leaves dangling references.
     *
     * @param  string $id
     * @param  string $project_id  Target application id.
     * @return \App\Models\Table  The moved table (same document, new owner).
     */
    public function moveTo($id, $project_id)
    {
        /** @var \App\Models\Table $table */
        $table = $this->read($id);
        // String id, consistent with how `applications` is stored elsewhere.
        $table->applications = [(string) $project_id];
        $table->category_id = null;
        $table->save();

        return $table;
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
