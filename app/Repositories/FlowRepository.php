<?php
/**
 * FlowRepository
 *
 * Persistence and graph validation for Decision Requirement Graphs (Flows).
 * Beyond the standard CRUD it enforces that a saved flow is a well-formed,
 * acyclic graph whose wiring is type-compatible with the referenced tables.
 *
 * @package App\Repositories
 */

namespace App\Repositories;

use App\Models\Flow;
use App\Models\Table;
use App\Models\FlowRun;
use App\Services\GraphSort;
use App\Exceptions\FlowValidationException;
use App\Repositories\TablesRepository;
use MongoDB\BSON\Regex;
use Nebo15\REST\AbstractRepository;
use Nebo15\LumenApplicationable\ApplicationableHelper;
use Nebo15\LumenApplicationable\Contracts\Applicationable;

class FlowRepository extends AbstractRepository
{
    protected $modelClassName = 'App\Models\Flow';

    protected $observerClassName = 'App\Observers\FlowObserver';

    /**
     * Return a paginated, application-scoped list of flows with optional filters.
     *
     * Mirrors TablesRepository::readListWithFilters: case-insensitive regex
     * search on 'title' and 'description', always scoped to the current
     * application. Unknown filter keys are ignored to prevent arbitrary queries.
     *
     * @param  array $filters  Associative array of filter keys and values.
     * @return \Illuminate\Pagination\LengthAwarePaginator
     */
    public function readListWithFilters(array $filters = [])
    {
        // Query-string values arrive as strings; MongoDB's $limit needs an int.
        $size = isset($filters['size']) ? (int) $filters['size'] : null;
        if (!$filters) {
            return $this->readList($size);
        }

        // Whitelist filterable fields to prevent arbitrary MongoDB queries.
        $available = ['title', 'description'];

        $where = [];
        foreach ($filters as $field => $filter) {
            if (in_array($field, $available) && $filter !== '' && $filter !== null) {
                $where[$field] = new Regex($filter, 'i');
            }
        }
        // Exact match on category_id — mirrors TablesRepository: a substring
        // regex would let 'cat_a' match 'cat_abc', so filtering is an equality
        // check, not a search.
        if (!empty($filters['category_id'])) {
            $where['category_id'] = $filters['category_id'];
        }
        // Always scope to the authenticated application for tenant isolation.
        $where['applications'] = ApplicationableHelper::getApplicationId();

        return $this->getModel()->query()->where($where)->paginate($size);
    }

    /**
     * Create or update a flow after validating its graph.
     *
     * On update, the submitted values are merged over the existing graph so a
     * partial PUT (e.g. title only) never silently wipes nodes/edges/outputs.
     * The merged graph is what gets validated and persisted.
     *
     * @param  array       $values
     * @param  string|null $id
     * @return Flow
     * @throws FlowValidationException  When the graph is malformed.
     */
    public function createOrUpdate($values, $id = null)
    {
        /** @var Flow $model */
        $model = $id ? $this->read($id) : $this->getModel()->newInstance();

        if ($id) {
            // Only the graph-shaping keys present in the request override the
            // existing document; missing keys keep their stored value.
            foreach (['title', 'description', 'inputs', 'outputs', 'nodes', 'edges'] as $key) {
                if (!array_key_exists($key, $values)) {
                    $values[$key] = $model->{$key};
                }
            }
        }

        $this->validateGraph($values);

        if ($model instanceof Applicationable) {
            ApplicationableHelper::addApplication($model);
        }
        $model->fill($values);
        $model->save();

        return $model;
    }

    /**
     * Copy a flow into a target application, bringing its referenced tables.
     *
     * A flow is only executable when every node's table lives in the same
     * application (validateGraph / FlowEngine resolve tables per-application via
     * findProjectTable). So we first duplicate each referenced table into the
     * target, remap the nodes' table_id to the new copies, then create the flow
     * copy owned by the target.
     *
     * The graph is NOT re-validated through createOrUpdate here: validateGraph
     * runs against the CURRENT (source) application, whereas the remapped ids
     * point at the target's tables. The source graph was already valid and we
     * only swap table ids (for identical copies) and the owning application, so
     * writing directly is safe.
     *
     * @param  string $id         Source flow id (scoped to the current application).
     * @param  string $project_id Target application id.
     * @return Flow  The newly created flow copy.
     */
    public function copyTo($id, $project_id)
    {
        $source = $this->read($id);

        $values = $source->getAttributes();
        unset($values[$source->getKeyName()]);
        unset($values['applications']);
        unset($values['category_id']);

        $values['nodes'] = $this->copyReferencedTables($source->nodes ?: [], $project_id);

        /** @var Flow $model */
        $model = $this->getModel()->newInstance();
        // String id, consistent with how `applications` is stored across the codebase.
        $model->applications = [(string) $project_id];
        $model->category_id = null;
        $model->fill($values);
        $model->save();

        return $model;
    }

    /**
     * Move a flow to another application, bringing copies of its referenced tables.
     *
     * The flow document changes ownership (disappears from the source), but its
     * referenced tables are COPIED into the target (never moved) so that other
     * flows in the source application keep working. Node table_ids are remapped
     * to those copies. As in copyTo, the graph is not re-validated against the
     * source application; applications is set directly (not via the buggy
     * ApplicationableTrait::removeApplication).
     *
     * @param  string $id         Source flow id (scoped to the current application).
     * @param  string $project_id Target application id.
     * @return Flow  The moved flow (same document, new owner).
     */
    public function moveTo($id, $project_id)
    {
        /** @var Flow $flow */
        $flow = $this->read($id);

        $flow->nodes = $this->copyReferencedTables($flow->nodes ?: [], $project_id);
        // String id, consistent with how `applications` is stored across the codebase.
        $flow->applications = [(string) $project_id];
        $flow->category_id = null;
        $flow->save();

        return $flow;
    }

    /**
     * Duplicate every table referenced by a flow's nodes into the target
     * application and return the nodes with their table_id remapped to the copies.
     *
     * Distinct table ids are copied once each (a table used by several nodes maps
     * to a single copy). Tables are read from the current (source) application via
     * findProjectTable; a node whose table cannot be resolved keeps its original
     * id (validateGraph would already have rejected such a flow on save, so this
     * is defensive only).
     *
     * @param  array  $nodes       The source flow's nodes ([{ node_id, table_id }]).
     * @param  string $project_id  Target application id.
     * @return array  Nodes with remapped table_id.
     */
    private function copyReferencedTables($nodes, $project_id)
    {
        $tablesRepo = new TablesRepository();
        $idMap = [];   // oldTableId => newTableId

        foreach ($nodes as $node) {
            $oldId = isset($node['table_id']) ? (string) $node['table_id'] : null;
            if (!$oldId || isset($idMap[$oldId])) {
                continue;
            }
            $table = $this->findProjectTable($oldId);
            if ($table) {
                $copy = $tablesRepo->duplicateInto($table, $project_id);
                $idMap[$oldId] = (string) $copy->_id;
            }
        }

        return array_map(function ($node) use ($idMap) {
            $node = (array) $node;
            $oldId = isset($node['table_id']) ? (string) $node['table_id'] : null;
            if ($oldId && isset($idMap[$oldId])) {
                $node['table_id'] = $idMap[$oldId];
            }
            return $node;
        }, $nodes);
    }

    /**
     * Paginated run history for a flow, most recent first.
     *
     * The flow is resolved first through read() (application-scoped by the CRUD),
     * so its runs inherit that scope — runs are always requested per flow, never
     * across the whole application.
     *
     * @param  string   $flowId
     * @param  int|null $size
     * @return \Illuminate\Pagination\LengthAwarePaginator
     */
    public function getRuns($flowId, $size = null)
    {
        // Ensures the flow exists in the current project (throws 404 otherwise).
        $this->read($flowId);

        // Scope to the current application in its own right (defence in depth),
        // rather than relying solely on the read() gate above and on flow ids
        // being globally unique. whereRaw with the dotted key is how Jenssegers
        // matches an embedded field (a plain where('flow._id', ...) does not
        // traverse it).
        $query = FlowRun::where('applications', ApplicationableHelper::getApplicationId())
            ->whereRaw(['flow._id' => (string) $flowId])
            ->orderBy('created_at', 'desc');

        return $this->paginateQuery($query, $size);
    }

    /**
     * Validate the flow graph.
     *
     * Checks, in order: referenced tables exist in the project; node ids are
     * unique; every edge target field is a real field key on its node's table;
     * every edge source resolves (a declared input, or an upstream node's
     * output); type compatibility of each wire (a `json` output cannot feed a
     * typed input); the graph is acyclic (a DAG); and at least one output is
     * declared and resolves.
     *
     * @param  array $values
     * @return void
     * @throws FlowValidationException
     */
    public function validateGraph($values)
    {
        $errors = [];

        $inputs = isset($values['inputs']) ? $values['inputs'] : [];
        $outputs = isset($values['outputs']) ? $values['outputs'] : [];
        $nodes = isset($values['nodes']) ? $values['nodes'] : [];
        $edges = isset($values['edges']) ? $values['edges'] : [];

        // Index inputs by key.
        $inputKeys = [];
        foreach ($inputs as $input) {
            if (isset($input['key'])) {
                $inputKeys[$input['key']] = isset($input['type']) ? $input['type'] : 'string';
            }
        }

        // Resolve nodes → their tables, checking uniqueness and project scope.
        $nodeTable = [];       // node_id => Table
        $nodeIds = [];
        foreach ($nodes as $node) {
            $nodeId = isset($node['node_id']) ? $node['node_id'] : null;
            $tableId = isset($node['table_id']) ? $node['table_id'] : null;
            if (!$nodeId) {
                $errors[] = 'A node is missing node_id.';
                continue;
            }
            if (in_array($nodeId, $nodeIds, true)) {
                $errors[] = "Duplicate node_id '$nodeId'.";
                continue;
            }
            $nodeIds[] = $nodeId;

            $table = $tableId ? $this->findProjectTable($tableId) : null;
            if (!$table) {
                $errors[] = "Node '$nodeId' references a table that does not exist in this project.";
                continue;
            }
            $nodeTable[$nodeId] = $table;
        }

        // Validate each edge and collect the dependency graph (target → sources).
        $adjacency = [];           // node_id => [upstream node_ids] (for cycle check)
        $wiredFields = [];         // "node_id:field" => true (coverage / no-double)
        foreach ($nodeIds as $nid) {
            $adjacency[$nid] = [];
        }

        foreach ($edges as $i => $edge) {
            $from = isset($edge['from']) ? $edge['from'] : [];
            $into = isset($edge['into']) ? $edge['into'] : [];

            $intoNode = isset($into['node']) ? $into['node'] : null;
            $intoField = isset($into['field']) ? $into['field'] : null;

            // Target must be a known node.
            if (!$intoNode || !isset($nodeTable[$intoNode])) {
                $errors[] = "Edge #$i targets an unknown node.";
                continue;
            }
            // Target field must be a real field key on the node's table.
            if (!$intoField || !$this->tableHasField($nodeTable[$intoNode], $intoField)) {
                $errors[] = "Edge #$i targets field '$intoField', which is not a field of node '$intoNode'.";
                continue;
            }
            // No field wired twice.
            $fieldKey = $intoNode . ':' . $intoField;
            if (isset($wiredFields[$fieldKey])) {
                $errors[] = "Field '$intoField' of node '$intoNode' is wired by more than one edge.";
                continue;
            }
            $wiredFields[$fieldKey] = true;

            $targetType = $this->fieldType($nodeTable[$intoNode], $intoField);

            // Source: a declared input, or an upstream node's output.
            if (isset($from['input'])) {
                $inputKey = $from['input'];
                if (!isset($inputKeys[$inputKey])) {
                    $errors[] = "Edge #$i sources input '$inputKey', which is not a declared flow input.";
                    continue;
                }
                if (!$this->typesCompatible($inputKeys[$inputKey], $targetType)) {
                    $errors[] = "Edge #$i: input '$inputKey' ({$inputKeys[$inputKey]}) is not compatible with field '$intoField' ($targetType).";
                }
            } elseif (isset($from['node'])) {
                $srcNode = $from['node'];
                $srcOutput = isset($from['output']) ? $from['output'] : 'final_decision';
                if (!isset($nodeTable[$srcNode])) {
                    $errors[] = "Edge #$i sources an unknown node.";
                    continue;
                }
                if ($srcOutput !== 'final_decision') {
                    $errors[] = "Edge #$i sources output '$srcOutput'; only 'final_decision' is available today.";
                    continue;
                }
                $srcType = $this->tableOutputType($nodeTable[$srcNode]);
                if ($srcType === 'json') {
                    $errors[] = "Edge #$i: node '$srcNode' outputs json, which cannot feed a table input.";
                } elseif (!$this->typesCompatible($srcType, $targetType)) {
                    $errors[] = "Edge #$i: output of '$srcNode' ($srcType) is not compatible with field '$intoField' ($targetType).";
                }
                // Dependency: intoNode depends on srcNode.
                $adjacency[$intoNode][] = $srcNode;
            } else {
                $errors[] = "Edge #$i has no valid source (expected 'input' or 'node').";
            }
        }

        // Field coverage: every field of every node's table must be fed at run
        // time — by a wired edge, or by a flow input of the same key. A field
        // that is neither would fail Scoring's `present` check at execution, so
        // reject it here instead of surfacing the error only on a run.
        foreach ($nodeIds as $nid) {
            if (!isset($nodeTable[$nid])) {
                continue;
            }
            foreach (($nodeTable[$nid]->fields ?: []) as $field) {
                $key = isset($field['key']) ? $field['key'] : null;
                if ($key === null) {
                    continue;
                }
                $fedByEdge = isset($wiredFields[$nid . ':' . $key]);
                $fedByInput = isset($inputKeys[$key]);
                if (!$fedByEdge && !$fedByInput) {
                    $errors[] = "Field '$key' of node '$nid' is not fed by any edge or flow input.";
                }
            }
        }

        // Acyclicity: a null topological order means a cycle remains. Shared with
        // the FlowEngine via GraphSort so the invariant lives in one place. Run it
        // regardless of other errors: $adjacency only ever links known nodes (a
        // dependency is recorded only after both endpoints are resolved), so it is
        // a valid graph even when unrelated edge/output errors were collected. This
        // reports a cycle alongside those errors instead of hiding it until they
        // are fixed and the flow is re-submitted.
        if (GraphSort::order($nodeIds, $adjacency) === null) {
            $errors[] = 'The graph contains a cycle; a decision graph must be acyclic.';
        }

        // Outputs: at least one, each named uniquely and resolving to a known node/output.
        if (empty($outputs)) {
            $errors[] = 'The flow must declare at least one output.';
        } else {
            $outputNames = [];
            foreach ($outputs as $j => $output) {
                $name = isset($output['name']) ? $output['name'] : null;
                if (!$name) {
                    $errors[] = "Output #$j is missing a name.";
                } elseif (in_array($name, $outputNames, true)) {
                    $errors[] = "Duplicate output name '$name'.";
                } else {
                    $outputNames[] = $name;
                }

                $fromNode = isset($output['from_node']) ? $output['from_node'] : null;
                $fromOutput = isset($output['from_output']) ? $output['from_output'] : 'final_decision';
                if (!$fromNode || !isset($nodeTable[$fromNode])) {
                    $errors[] = "Output #$j references an unknown node.";
                    continue;
                }
                if ($fromOutput !== 'final_decision') {
                    $errors[] = "Output #$j uses '$fromOutput'; only 'final_decision' is available today.";
                }
            }
        }

        if (!empty($errors)) {
            throw new FlowValidationException($errors);
        }
    }

    /**
     * Find a table by id, strictly scoped to the current application.
     *
     * Returns null when no application context is resolvable rather than falling
     * back to an unscoped lookup, so a flow can never reference a table from
     * another project. Public so the FlowEngine can re-check a table still exists
     * at run time.
     *
     * @param  string $tableId
     * @return Table|null
     */
    public function findProjectTable($tableId)
    {
        try {
            $appId = ApplicationableHelper::getApplicationId();
        } catch (\Exception $e) {
            return null;
        }
        if (!$appId) {
            return null;
        }

        return Table::where('_id', $tableId)
            ->where('applications', $appId)
            ->first();
    }

    /**
     * Whether a table has a field with the given key.
     *
     * @param  Table  $table
     * @param  string $key
     * @return bool
     */
    private function tableHasField(Table $table, $key)
    {
        foreach (($table->fields ?: []) as $field) {
            if (isset($field['key']) && $field['key'] === $key) {
                return true;
            }
        }

        return false;
    }

    /**
     * The declared type of a table field.
     *
     * @param  Table  $table
     * @param  string $key
     * @return string  numeric | boolean | string
     */
    private function fieldType(Table $table, $key)
    {
        foreach (($table->fields ?: []) as $field) {
            if (isset($field['key']) && $field['key'] === $key) {
                return isset($field['type']) ? $field['type'] : 'string';
            }
        }

        return 'string';
    }

    /**
     * The output type of a table's final_decision, mirroring Decision::getOutputType.
     *
     * @param  Table $table
     * @return string  numeric | string | alpha_num | json
     */
    private function tableOutputType(Table $table)
    {
        if ($table->matching_type !== 'first') {
            return 'numeric';
        }

        return $table->decision_type ?: 'string';
    }

    /**
     * Whether a source value type can feed a target field type.
     *
     * Strict by design: a DRG wire is only valid when the two ends belong to the
     * same type family. Connecting variables of different families (e.g. a
     * numeric score into a string field, or an integer into a boolean) is
     * rejected — if a node needs a boolean input, the upstream output must itself
     * be boolean-typed. This makes a flow self-documenting and catches wiring
     * mistakes at save time rather than on a later run.
     *
     * Families:
     *   - text    : string, alpha_num  (equivalent — input fields only know 'string')
     *   - numeric : numeric  (with the synonyms number/integer)
     *   - boolean : boolean  (with the synonym bool)
     *   - json    : never wireable, in or out.
     *
     * @param  string $sourceType  numeric | string | alpha_num | boolean | json
     * @param  string $targetType  numeric | boolean | string
     * @return bool
     */
    private function typesCompatible($sourceType, $targetType)
    {
        $sourceFamily = $this->typeFamily($sourceType);
        $targetFamily = $this->typeFamily($targetType);

        // json (or any unknown type) is never a valid wire endpoint.
        if ($sourceFamily === null || $targetFamily === null) {
            return false;
        }

        return $sourceFamily === $targetFamily;
    }

    /**
     * Normalise a declared type to its family, or null when it cannot be wired.
     *
     * Collapses synonyms (number/integer → numeric, bool → boolean) and treats
     * alpha_num as text, so 'string' and 'alpha_num' are the same family. Returns
     * null for 'json' and any unrecognised type, which makes them un-wireable.
     *
     * @param  string $type
     * @return string|null  'text' | 'numeric' | 'boolean' | null
     */
    private function typeFamily($type)
    {
        switch (strtolower((string) $type)) {
            case 'string':
            case 'alpha_num':
                return 'text';
            case 'numeric':
            case 'number':
            case 'integer':
                return 'numeric';
            case 'boolean':
            case 'bool':
                return 'boolean';
            default:
                // json and anything unrecognised: not a valid wire endpoint.
                return null;
        }
    }
}
