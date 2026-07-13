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
use App\Services\GraphSort;
use App\Exceptions\FlowValidationException;
use Nebo15\REST\AbstractRepository;
use Nebo15\LumenApplicationable\ApplicationableHelper;
use Nebo15\LumenApplicationable\Contracts\Applicationable;

class FlowRepository extends AbstractRepository
{
    protected $modelClassName = 'App\Models\Flow';

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
        // the FlowEngine via GraphSort so the invariant lives in one place.
        if (empty($errors) && \App\Services\GraphSort::order($nodeIds, $adjacency) === null) {
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
     * Permissive by design: json is rejected upstream; numeric feeds numeric;
     * everything textual feeds a string field; a numeric can be read as a string.
     * Boolean fields accept only boolean-ish sources.
     *
     * @param  string $sourceType  numeric | string | alpha_num | boolean
     * @param  string $targetType  numeric | boolean | string
     * @return bool
     */
    private function typesCompatible($sourceType, $targetType)
    {
        if ($sourceType === 'json') {
            return false;
        }
        if ($targetType === 'string') {
            return true; // any scalar can be read as a string
        }
        if ($targetType === 'numeric') {
            return $sourceType === 'numeric';
        }
        if ($targetType === 'boolean') {
            return $sourceType === 'boolean';
        }

        return true;
    }
}
