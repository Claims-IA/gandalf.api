<?php
/**
 * FlowEngine
 *
 * Executes a Decision Requirement Graph (Flow). Each node is a decision table;
 * the engine runs them in topological order, feeding each node only the field
 * values it declares (from the flow's inputs and from upstream nodes' answers),
 * then assembles the flow's declared outputs from the per-node results.
 *
 * The per-node evaluation reuses the existing Scoring engine unchanged — the
 * engine only orchestrates and wires values via the unified `answer` contract.
 *
 * @package App\Services
 */

namespace App\Services;

use App\Models\Flow;
use App\Models\Table;
use App\Models\FlowRun;
use App\Repositories\FlowRepository;
use App\Exceptions\FlowValidationException;

class FlowEngine
{
    /**
     * @var Scoring
     */
    private $scoring;

    /**
     * @var FlowRepository
     */
    private $flowRepository;

    public function __construct(Scoring $scoring, FlowRepository $flowRepository)
    {
        $this->scoring = $scoring;
        $this->flowRepository = $flowRepository;
    }

    /**
     * Run a flow against the supplied inputs.
     *
     * @param  string $flowId    ObjectID of the flow.
     * @param  array  $inputs    Flow input values, keyed by input key.
     * @param  mixed  $appId     Application id, for scoping the persisted decisions.
     * @param  bool   $showMeta  Passed through to per-node scoring.
     * @return array             { answer, answer_types, decision_kind: 'drg', flow_run_id, nodes[] }
     * @throws FlowValidationException  On an unrunnable graph or a wiring error at run time.
     */
    public function run($flowId, array $inputs, $appId, $showMeta = false)
    {
        /** @var Flow $flow */
        $flow = $this->flowRepository->read($flowId);

        $nodes = $flow->nodes ?: [];
        $edges = $flow->edges ?: [];
        $outputs = $flow->outputs ?: [];

        $order = $this->orderNodes($nodes, $edges);
        $edgesByTarget = $this->groupEdgesByTarget($edges);

        $nodeResults = [];   // node_id => Scoring result array (carries `answer`)
        $nodeTrace = [];     // node_id => { node_id, table_id, input, decision_id, answer }

        try {
            foreach ($order as $nodeId) {
                $node = $this->findNode($nodes, $nodeId);
                $table = $this->requireTable($node, $nodeId);

                $values = $this->buildNodeInput(
                    $table,
                    $inputs,
                    isset($edgesByTarget[$nodeId]) ? $edgesByTarget[$nodeId] : [],
                    $nodeResults
                );

                $result = $this->scoring->check((string) $table->_id, $values, $appId, $showMeta);
                $nodeResults[$nodeId] = $result;
                $nodeTrace[$nodeId] = [
                    'node_id' => $nodeId,
                    'table_id' => (string) $table->_id,
                    'input' => $values,
                    'decision_id' => isset($result['_id']) ? $result['_id'] : null,
                    'answer' => isset($result['answer']) ? $result['answer'] : null,
                ];
            }
        } catch (\Exception $e) {
            // Record the partial run so the decisions already produced are linked
            // and traceable, then rethrow for the caller's error response.
            $this->persistRun($flow, $appId, $inputs, [], $nodeTrace, ['error' => $e->getMessage()]);
            throw $e;
        }

        list($answer, $answerTypes) = $this->assembleOutputs($outputs, $nodeResults);

        $flowRun = $this->persistRun($flow, $appId, $inputs, $answer, $nodeTrace, null);

        return [
            'flow_run_id' => $flowRun->getId(),
            'answer' => $answer,
            'answer_types' => $answerTypes,
            'decision_kind' => 'drg',
            'nodes' => array_values($nodeTrace),
        ];
    }

    /**
     * Build a single node's input map: only the fields the node's table declares,
     * each fed from a wired edge when present, otherwise from a same-named flow
     * input. Unrelated flow inputs (including `variant_id`, which is table-local
     * and must not leak across nodes) are deliberately not forwarded.
     *
     * @param  Table $table
     * @param  array $inputs        Flow inputs.
     * @param  array $incomingEdges Edges targeting this node.
     * @param  array $nodeResults   Results of already-evaluated nodes.
     * @return array
     * @throws FlowValidationException
     */
    private function buildNodeInput(Table $table, array $inputs, array $incomingEdges, array $nodeResults)
    {
        // Index this node's incoming edges by target field.
        $edgeByField = [];
        foreach ($incomingEdges as $edge) {
            if (isset($edge['into']['field'])) {
                $edgeByField[$edge['into']['field']] = $edge;
            }
        }

        $values = [];
        foreach (($table->fields ?: []) as $field) {
            $key = isset($field['key']) ? $field['key'] : null;
            if ($key === null) {
                continue;
            }
            if (isset($edgeByField[$key])) {
                $values[$key] = $this->resolveEdgeValue($edgeByField[$key], $inputs, $nodeResults);
            } elseif (array_key_exists($key, $inputs)) {
                $values[$key] = $inputs[$key];
            }
            // A field with neither a wire nor a matching input is left absent;
            // Scoring's `present` validation surfaces it as a clear error. Graph
            // validation already flags this at write time (field coverage).
        }

        return $values;
    }

    /**
     * Resolve the value an edge carries into its target field.
     *
     * @param  array $edge
     * @param  array $inputs        Flow inputs.
     * @param  array $nodeResults   Results of already-evaluated nodes.
     * @return mixed
     * @throws FlowValidationException  When a wired value is missing.
     */
    private function resolveEdgeValue(array $edge, array $inputs, array $nodeResults)
    {
        $from = isset($edge['from']) ? $edge['from'] : [];

        if (isset($from['input'])) {
            $key = $from['input'];
            if (!array_key_exists($key, $inputs)) {
                throw new FlowValidationException(["Missing flow input '$key' required by the graph."]);
            }
            return $inputs[$key];
        }

        if (isset($from['node'])) {
            $srcNode = $from['node'];
            $srcOutput = isset($from['output']) ? $from['output'] : 'final_decision';
            // Distinguish "node not evaluated / output absent" (an error) from a
            // legitimately null answer value (allowed to flow downstream).
            if (!isset($nodeResults[$srcNode]['answer'])
                || !array_key_exists($srcOutput, $nodeResults[$srcNode]['answer'])) {
                throw new FlowValidationException(["Upstream output '$srcOutput' of node '$srcNode' is not available."]);
            }
            return $nodeResults[$srcNode]['answer'][$srcOutput];
        }

        throw new FlowValidationException(['An edge has no valid source.']);
    }

    /**
     * Assemble the flow's declared outputs from the per-node results.
     *
     * @param  array $outputs
     * @param  array $nodeResults
     * @return array  [ answer, answerTypes ]
     */
    private function assembleOutputs(array $outputs, array $nodeResults)
    {
        $answer = [];
        $answerTypes = [];
        foreach ($outputs as $output) {
            $name = isset($output['name']) ? $output['name'] : null;
            if ($name === null) {
                continue;
            }
            $fromNode = isset($output['from_node']) ? $output['from_node'] : null;
            $fromOutput = isset($output['from_output']) ? $output['from_output'] : 'final_decision';
            $srcResult = isset($nodeResults[$fromNode]) ? $nodeResults[$fromNode] : [];
            $answer[$name] = isset($srcResult['answer'][$fromOutput]) ? $srcResult['answer'][$fromOutput] : null;
            $answerTypes[$name] = isset($srcResult['answer_types'][$fromOutput])
                ? $srcResult['answer_types'][$fromOutput]
                : null;
        }

        return [$answer, $answerTypes];
    }

    /**
     * Persist a FlowRun linking the decisions produced by the nodes.
     *
     * @param  Flow       $flow
     * @param  mixed      $appId
     * @param  array      $inputs
     * @param  array      $answer
     * @param  array      $nodeTrace
     * @param  array|null $error  Non-null for a failed/partial run.
     * @return FlowRun
     */
    private function persistRun(Flow $flow, $appId, array $inputs, array $answer, array $nodeTrace, $error)
    {
        $flowRun = new FlowRun();
        $flowRun->fill([
            'flow' => ['_id' => (string) $flow->_id, 'title' => $flow->title],
            'application' => $appId,
            'inputs' => $inputs,
            'answer' => $answer,
            'nodes' => array_values($nodeTrace),
            'error' => $error,
        ]);
        $flowRun->save();

        return $flowRun;
    }

    /**
     * Resolve and load a node's table, re-checking it still exists in the project
     * (a table may have been deleted after the flow was created).
     *
     * @param  array  $node
     * @param  string $nodeId
     * @return Table
     * @throws FlowValidationException
     */
    private function requireTable(array $node, $nodeId)
    {
        $tableId = isset($node['table_id']) ? $node['table_id'] : null;
        $table = $tableId ? $this->flowRepository->findProjectTable($tableId) : null;
        if (!$table) {
            throw new FlowValidationException(["Node '$nodeId' references a table that no longer exists in this project."]);
        }

        return $table;
    }

    /**
     * Order nodes by dependency (upstream first) via the shared GraphSort.
     *
     * @param  array $nodes
     * @param  array $edges
     * @return string[]
     * @throws FlowValidationException
     */
    private function orderNodes(array $nodes, array $edges)
    {
        $nodeIds = [];
        foreach ($nodes as $node) {
            if (isset($node['node_id'])) {
                $nodeIds[] = $node['node_id'];
            }
        }

        $dependencies = [];
        foreach ($nodeIds as $n) {
            $dependencies[$n] = [];
        }
        foreach ($edges as $edge) {
            $from = isset($edge['from']) ? $edge['from'] : [];
            $into = isset($edge['into']) ? $edge['into'] : [];
            if (isset($from['node']) && isset($into['node']) && isset($dependencies[$into['node']])) {
                $dependencies[$into['node']][] = $from['node'];
            }
        }

        $order = GraphSort::order($nodeIds, $dependencies);
        if ($order === null) {
            throw new FlowValidationException(['The graph contains a cycle and cannot be executed.']);
        }

        return $order;
    }

    /**
     * Group edges by their target node id.
     *
     * @param  array $edges
     * @return array  target node_id => edges[]
     */
    private function groupEdgesByTarget(array $edges)
    {
        $byTarget = [];
        foreach ($edges as $edge) {
            $targetNode = isset($edge['into']['node']) ? $edge['into']['node'] : null;
            if ($targetNode) {
                $byTarget[$targetNode][] = $edge;
            }
        }

        return $byTarget;
    }

    /**
     * @param  array  $nodes
     * @param  string $nodeId
     * @return array
     */
    private function findNode(array $nodes, $nodeId)
    {
        foreach ($nodes as $node) {
            if (isset($node['node_id']) && $node['node_id'] === $nodeId) {
                return $node;
            }
        }

        return [];
    }
}
