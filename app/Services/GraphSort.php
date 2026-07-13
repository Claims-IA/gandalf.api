<?php
/**
 * GraphSort
 *
 * Shared topological ordering / cycle detection for flow graphs (Kahn's
 * algorithm). Used both at write time (FlowRepository, to reject cyclic graphs)
 * and at run time (FlowEngine, to evaluate nodes in dependency order), so the
 * acyclicity invariant is defined in exactly one place.
 *
 * @package App\Services
 */

namespace App\Services;

class GraphSort
{
    /**
     * Return the node ids in dependency order (every node after all the nodes it
     * depends on), or null if the graph contains a cycle.
     *
     * @param  string[] $nodeIds
     * @param  array    $dependencies  node_id => list of node_ids it depends on
     * @return string[]|null  Ordered ids, or null when a cycle remains.
     */
    public static function order(array $nodeIds, array $dependencies)
    {
        // Reverse index: for each node, who depends on it (its dependents).
        $dependents = [];
        foreach ($nodeIds as $n) {
            $dependents[$n] = [];
        }
        $inDegree = [];
        foreach ($nodeIds as $n) {
            $deps = isset($dependencies[$n]) ? $dependencies[$n] : [];
            $inDegree[$n] = count($deps);
            foreach ($deps as $dep) {
                if (isset($dependents[$dep])) {
                    $dependents[$dep][] = $n;
                }
            }
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
            foreach ($dependents[$current] as $dependent) {
                $inDegree[$dependent]--;
                if ($inDegree[$dependent] === 0) {
                    $queue[] = $dependent;
                }
            }
        }

        return count($order) === count($nodeIds) ? $order : null;
    }
}
