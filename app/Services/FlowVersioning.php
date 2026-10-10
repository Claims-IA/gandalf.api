<?php
/**
 * FlowVersioning
 *
 * Flow versions (VERSIONS.md, batch 4), as TableVersioning is for tables:
 * - resolves a flow's active version;
 * - pins nodes to table versions: a node without `table_version_id` keeps the
 *   pin of the same node in the flow's current version, or takes its table's
 *   active version;
 * - after each flow save (FlowObserver), in compatibility mode, a graph change
 *   publishes and activates a version, as a table save does;
 * - in compatibility mode, flows follow each activation of a table version
 *   (followTable), as they ran the live table before versions. Without it, a
 *   flow stays on its pinned versions until a new flow version pins others
 *   (FlowVersionsRepository::updateToActive).
 *
 * The flow document holds a copy of its active version's graph, nodes pinned:
 * activating a version copies its graph onto the flow (copyIntoFlow).
 *
 * @package App\Services
 */

namespace App\Services;

use App\Models\Flow;
use App\Models\Table;
use App\Models\FlowVersion;
use MongoDB\Driver\Exception\BulkWriteException;

class FlowVersioning
{
    // Two saves of the same flow may race for the next version number; the
    // unique (flow_id, number) index rejects the loser, which retries.
    const PUBLISH_ATTEMPTS = 3;

    /**
     * The active version of a flow, or null while it has none (a flow saved
     * before the migration ran and since, or copied without the compatibility
     * mode).
     *
     * @param  Flow $flow
     * @return FlowVersion|null
     */
    public function activeVersion(Flow $flow)
    {
        $id = $flow->active_version_id;
        if (!$id) {
            return null;
        }

        return FlowVersion::where('_id', $id)
            ->where('flow_id', (string) $flow->getId())
            ->first();
    }

    /**
     * The graph a flow or a version holds: inputs, outputs, nodes, edges.
     *
     * @param  Flow|FlowVersion $model
     * @return array
     */
    public static function graphOf($model)
    {
        $attributes = $model->getAttributes();
        $graph = [];
        foreach (FlowVersion::GRAPH_KEYS as $key) {
            $graph[$key] = array_values((array) ($attributes[$key] ?? []));
        }

        return $graph;
    }

    /**
     * Fingerprint of a graph, pins included: two saves of the same graph match.
     *
     * @param  array $graph
     * @return string
     */
    public static function contentHash(array $graph)
    {
        $content = [];
        foreach (FlowVersion::GRAPH_KEYS as $key) {
            $content[$key] = $graph[$key] ?? [];
        }

        return sha1(json_encode(TableVersioning::normalize($content)));
    }

    /**
     * Nodes with their pins. A node sent without `table_version_id` keeps the
     * pin of the same node (same table) in $current, else takes its table's
     * active version: null for a table without versions, whose node then runs
     * the table as stored.
     *
     * @param  array                 $nodes
     * @param  FlowVersion|Flow|null $current  Where kept pins come from.
     * @return array
     */
    public function pinNodes(array $nodes, $current = null)
    {
        $kept = [];
        foreach ($current ? (array) $current->nodes : [] as $node) {
            $node = (array) $node;
            if (!empty($node['table_version_id']) && isset($node['node_id'], $node['table_id'])) {
                $kept[$node['node_id'] . '@' . $node['table_id']] = (string) $node['table_version_id'];
            }
        }

        $actives = [];
        foreach ($nodes as $i => $node) {
            $node = (array) $node;
            if (!empty($node['table_version_id'])) {
                $node['table_version_id'] = (string) $node['table_version_id'];
            } else {
                $key = ($node['node_id'] ?? '') . '@' . ($node['table_id'] ?? '');
                $node['table_version_id'] = $kept[$key] ?? self::activeTableVersionId($node['table_id'] ?? null, $actives);
            }
            $nodes[$i] = $node;
        }

        return array_values($nodes);
    }

    /**
     * Keep a flow's versions in step with it after a save (FlowObserver):
     * - the versions follow the flow when it moves to another application;
     * - in compatibility mode, a graph change publishes and activates a
     *   version holding the saved graph, nodes pinned.
     *
     * @param  Flow $flow
     * @return FlowVersion|null  The version published, if any.
     */
    public function syncFromFlow(Flow $flow)
    {
        $active = $this->activeVersion($flow);
        $applications = array_values((array) $flow->getApplications());
        if ($active && array_values((array) $active->getApplications()) !== $applications) {
            FlowVersion::where('flow_id', (string) $flow->getId())->update(['applications' => $applications]);
        }

        if (!TableVersioning::legacyAutoPublish()) {
            return null;
        }
        $graph = self::graphOf($flow);
        $graph['nodes'] = $this->pinNodes($graph['nodes'], $active ?: $flow);
        if ($active && self::contentHash(self::graphOf($active)) === self::contentHash($graph)) {
            return null;
        }

        $version = $this->newVersion($flow, $graph, $active);
        $this->publish($version, '', true);
        // Query updates fire no model event: the flow is not saved again.
        Flow::where('_id', $flow->getId())->update([
            'active_version_id' => (string) $version->getId(),
            'nodes' => $graph['nodes'],
        ]);
        foreach (['active_version_id' => (string) $version->getId(), 'nodes' => $graph['nodes']] as $key => $value) {
            $flow->setAttribute($key, $value);
            $flow->syncOriginalAttribute($key);
        }

        return $version;
    }

    /**
     * Compatibility mode: the flows using a table follow the activation of one
     * of its versions, as they ran the live table before versions. Each flow
     * whose active version pins the table elsewhere gets a version pinning it
     * to the active one, published and activated at once. Field renames follow
     * through FlowRepository::followFieldRenames, as for a table update.
     *
     * @param  Table $table
     * @return int  Number of flows that followed.
     */
    public function followTable(Table $table)
    {
        $target = $table->active_version_id ? (string) $table->active_version_id : null;
        if (!TableVersioning::legacyAutoPublish() || $target === null) {
            return 0;
        }
        $tableId = (string) $table->getId();
        $followed = 0;
        foreach (Flow::where('nodes.table_id', $tableId)->get() as $flow) {
            $active = $this->activeVersion($flow);
            $graph = self::graphOf($active ?: $flow);
            $graph['nodes'] = $this->pinNodes($graph['nodes'], $active ?: $flow);
            $changed = false;
            foreach ($graph['nodes'] as $i => $node) {
                if (($node['table_id'] ?? null) === $tableId && ($node['table_version_id'] ?? null) !== $target) {
                    $graph['nodes'][$i]['table_version_id'] = $target;
                    $changed = true;
                }
            }
            if (!$changed) {
                continue;
            }
            $version = $this->newVersion($flow, $graph, $active);
            $this->publish($version, '', true);
            $this->copyIntoFlow($flow, $version);
            $followed++;
        }

        return $followed;
    }

    /**
     * Publish a version: freeze it and number it after the flow's last
     * published version. The active version does not change.
     *
     * @param  FlowVersion $version
     * @param  string      $comment
     * @param  bool        $autopublished  Published by the compatibility mode.
     * @return FlowVersion
     * @throws BulkWriteException  When the number is still taken after retries.
     */
    public function publish(FlowVersion $version, $comment = '', $autopublished = false)
    {
        $version->setAttribute('status', FlowVersion::STATUS_PUBLISHED);
        $version->setAttribute('comment', (string) $comment);
        $version->setAttribute('autopublished', (bool) $autopublished);
        $version->setAttribute('published_by', TableVersioning::currentUserId());
        $version->setAttribute('published_at', \Carbon\Carbon::now());

        for ($attempt = 1;; $attempt++) {
            $version->setAttribute('number', $this->nextNumber($version->flow_id));
            try {
                $version->save();

                return $version;
            } catch (BulkWriteException $e) {
                if ($attempt >= self::PUBLISH_ATTEMPTS) {
                    throw $e;
                }
            }
        }
    }

    /**
     * Make a published version the one its flow runs, and copy its graph onto
     * the flow. The flow save (FlowObserver) then finds both graphs equal and
     * publishes nothing.
     *
     * @param  Flow        $flow
     * @param  FlowVersion $version
     * @return Flow
     */
    public function copyIntoFlow(Flow $flow, FlowVersion $version)
    {
        foreach (self::graphOf($version) as $key => $value) {
            $flow->setAttribute($key, $value);
        }
        $flow->setAttribute('active_version_id', (string) $version->getId());
        $flow->save();

        return $flow;
    }

    /**
     * Delete all versions of a deleted flow, and the calls counted on it.
     *
     * @param  Flow $flow
     */
    public function deleteForFlow(Flow $flow)
    {
        FlowVersion::where('flow_id', (string) $flow->getId())->delete();
        (new VersionCalls)->deleteForFlow($flow->getId());
    }

    /**
     * Whether a node of the flow's active version runs a table version other
     * than its table's active one: the flow does not use the active version.
     *
     * @param  Flow $flow
     * @return bool
     */
    public function isOutdated(Flow $flow)
    {
        $active = $this->activeVersion($flow);
        $actives = [];
        foreach ((array) ($active ?: $flow)->nodes as $node) {
            $node = (array) $node;
            $pin = $node['table_version_id'] ?? null;
            if ($pin && $pin !== self::activeTableVersionId($node['table_id'] ?? null, $actives)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A new version of a flow holding $graph.
     *
     * @param  Flow             $flow
     * @param  array            $graph
     * @param  FlowVersion|null $origin
     * @return FlowVersion
     */
    public function newVersion(Flow $flow, array $graph, $origin = null)
    {
        $version = new FlowVersion;
        $version->setAttribute('flow_id', (string) $flow->getId());
        $version->setAttribute('applications', array_values((array) $flow->getApplications()));
        $version->setAttribute('origin_version_id', $origin ? (string) $origin->getId() : null);
        $version->setAttribute('created_by', TableVersioning::currentUserId());
        foreach (FlowVersion::GRAPH_KEYS as $key) {
            $version->setAttribute($key, $graph[$key] ?? []);
        }

        return $version;
    }

    /**
     * A table's active version id, cached per call.
     *
     * @param  string|null $tableId
     * @param  array       $cache
     * @return string|null
     */
    private static function activeTableVersionId($tableId, array &$cache)
    {
        if (!$tableId) {
            return null;
        }
        if (!array_key_exists($tableId, $cache)) {
            $table = Table::where('_id', (string) $tableId)->first(['active_version_id']);
            $cache[$tableId] = $table && $table->active_version_id ? (string) $table->active_version_id : null;
        }

        return $cache[$tableId];
    }

    private function nextNumber($flowId)
    {
        $last = FlowVersion::where('flow_id', (string) $flowId)
            ->where('status', FlowVersion::STATUS_PUBLISHED)
            ->orderBy('number', 'desc')
            ->first(['number']);

        return $last ? ((int) $last->number) + 1 : 1;
    }
}
