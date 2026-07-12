<?php
/**
 * FlowEngine
 *
 * Executes a Decision Requirement Graph (Flow). Each node is a decision table;
 * the engine runs them in topological order, feeding each node's inputs from the
 * flow's inputs and from the outputs of already-evaluated upstream nodes, then
 * assembles the flow's declared outputs from the per-node results.
 *
 * The per-node evaluation reuses the existing Scoring engine unchanged — the
 * engine only orchestrates and wires values via the unified `answer` contract.
 *
 * @package App\Services
 */

namespace App\Services;

use App\Models\Flow;
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

        $order = $this->topologicalOrder($nodes, $edges);

        // Group edges by their target node for quick input assembly.
        $edgesByTarget = [];
        foreach ($edges as $edge) {
            $into = isset($edge['into']) ? $edge['into'] : [];
            $targetNode = isset($into['node']) ? $into['node'] : null;
            if ($targetNode) {
                $edgesByTarget[$targetNode][] = $edge;
            }
        }

        $nodeResults = [];   // node_id => Scoring result array (carries `answer`)
        $nodeTrace = [];     // node_id => { table_id, decision_id, input, answer }

        foreach ($order as $nodeId) {
            $node = $this->findNode($nodes, $nodeId);
            $tableId = $node['table_id'];

            // Assemble this node's input: flow inputs by key, then wired edges win.
            $values = $inputs;
            foreach (isset($edgesByTarget[$nodeId]) ? $edgesByTarget[$nodeId] : [] as $edge) {
                $field = $edge['into']['field'];
                $values[$field] = $this->resolveEdgeValue($edge, $inputs, $nodeResults);
            }

            $result = $this->scoring->check($tableId, $values, $appId, $showMeta);
            $nodeResults[$nodeId] = $result;
            $nodeTrace[$nodeId] = [
                'node_id' => $nodeId,
                'table_id' => (string) $tableId,
                'decision_id' => isset($result['_id']) ? $result['_id'] : null,
                'answer' => isset($result['answer']) ? $result['answer'] : null,
            ];
        }

        // Assemble the flow's declared outputs.
        $answer = [];
        $answerTypes = [];
        foreach ($outputs as $output) {
            $name = $output['name'];
            $fromNode = $output['from_node'];
            $fromOutput = isset($output['from_output']) ? $output['from_output'] : 'final_decision';
            $srcResult = isset($nodeResults[$fromNode]) ? $nodeResults[$fromNode] : [];
            $answer[$name] = isset($srcResult['answer'][$fromOutput]) ? $srcResult['answer'][$fromOutput] : null;
            $answerTypes[$name] = isset($srcResult['answer_types'][$fromOutput])
                ? $srcResult['answer_types'][$fromOutput]
                : null;
        }

        // Persist the run, linking the decisions produced by each node.
        $flowRun = new FlowRun();
        $flowRun->fill([
            'flow' => ['_id' => (string) $flow->_id, 'title' => $flow->title],
            'application' => $appId,
            'inputs' => $inputs,
            'answer' => $answer,
            'nodes' => array_values($nodeTrace),
        ]);
        $flowRun->save();

        return [
            'flow_run_id' => $flowRun->getId(),
            'answer' => $answer,
            'answer_types' => $answerTypes,
            'decision_kind' => 'drg',
            'nodes' => array_values($nodeTrace),
        ];
    }

    /**
     * Resolve the value an edge carries into its target field.
     *
     * @param  array $edge
     * @param  array $inputs        Flow inputs.
     * @param  array $nodeResults   Results of already-evaluated nodes.
     * @return mixed
     * @throws FlowValidationException  When a wired upstream value is missing.
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
            if (!isset($nodeResults[$srcNode]['answer'][$srcOutput])) {
                throw new FlowValidationException(["Upstream output '$srcOutput' of node '$srcNode' is not available."]);
            }
            return $nodeResults[$srcNode]['answer'][$srcOutput];
        }

        throw new FlowValidationException(['An edge has no valid source.']);
    }

    /**
     * Return the node ids in an order where every node's upstream dependencies
     * come first (Kahn's algorithm). Throws if the graph is not acyclic.
     *
     * @param  array $nodes
     * @param  array $edges
     * @return array  Ordered node ids.
     * @throws FlowValidationException
     */
    private function topologicalOrder(array $nodes, array $edges)
    {
        $nodeIds = [];
        foreach ($nodes as $node) {
            if (isset($node['node_id'])) {
                $nodeIds[] = $node['node_id'];
            }
        }

        // deps[node] = set of nodes it depends on (an edge from a node → this node).
        $deps = [];
        foreach ($nodeIds as $n) {
            $deps[$n] = [];
        }
        foreach ($edges as $edge) {
            $from = isset($edge['from']) ? $edge['from'] : [];
            $into = isset($edge['into']) ? $edge['into'] : [];
            if (isset($from['node']) && isset($into['node'])) {
                $deps[$into['node']][] = $from['node'];
            }
        }

        $inDegree = [];
        foreach ($nodeIds as $n) {
            $inDegree[$n] = count($deps[$n]);
        }
        $queue = [];
        foreach ($inDegree as $n => $d) {
            if ($d === 0) {
                $queue[] = $n;
            }
        }

        $order = [];
        while (!empty($queue)) {
            $current = array_shift($queue);
            $order[] = $current;
            foreach ($nodeIds as $n) {
                if (in_array($current, $deps[$n], true)) {
                    $inDegree[$n]--;
                    if ($inDegree[$n] === 0) {
                        $queue[] = $n;
                    }
                }
            }
        }

        if (count($order) !== count($nodeIds)) {
            throw new FlowValidationException(['The graph contains a cycle and cannot be executed.']);
        }

        return $order;
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
