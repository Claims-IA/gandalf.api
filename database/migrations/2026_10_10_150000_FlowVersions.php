<?php

use Illuminate\Database\Migrations\Migration;
use MongoDB\BSON\UTCDateTime;

/**
 * Flow versions, release B (VERSIONS.md, batch 4): each flow becomes its version
 * 1, published and active, each node pinned to the version of its table that is
 * active when the migration runs. Flows ran the live table until then, which is
 * the active version: pinning to it changes nothing, whereas pinning to the
 * table's version 1 would undo the versions the compatibility mode published.
 * The flow document keeps its graph, nodes pinned, as the copy of its active
 * version (as a table does).
 *
 * Idempotent: a flow that already has an active version is left as is. The
 * rollback removes flow_versions, the pins and active_version_id.
 *
 * Raw collections, not Eloquent: saving a Flow fires FlowObserver.
 */
class FlowVersions extends Migration
{
    public function up()
    {
        // One number per published version of a flow; drafts have none.
        \DB::collection('flow_versions')->raw(function ($collection) {
            return $collection->createIndex(
                ['flow_id' => 1, 'number' => 1],
                [
                    'name' => 'flow_versions_number',
                    'unique' => true,
                    'partialFilterExpression' => ['status' => 'published'],
                ]
            );
        });

        $activeVersions = [];
        foreach (\DB::collection('flows')->get() as $flow) {
            if (!empty($flow['active_version_id'])) {
                continue;
            }
            $flowId = (string) $flow['_id'];
            $nodes = [];
            foreach ((array) ($flow['nodes'] ?? []) as $node) {
                $node = (array) $node;
                $node['table_version_id'] = self::activeVersionOf($node['table_id'] ?? null, $activeVersions);
                $nodes[] = $node;
            }
            $publishedAt = isset($flow['updated_at']) ? $flow['updated_at'] : new UTCDateTime;
            $versionId = \DB::collection('flow_versions')->insertGetId([
                'flow_id' => $flowId,
                'applications' => isset($flow['applications']) ? $flow['applications'] : [],
                'status' => 'published',
                'number' => 1,
                'name' => '',
                'comment' => '',
                'origin_version_id' => null,
                'autopublished' => true,
                'inputs' => isset($flow['inputs']) ? $flow['inputs'] : [],
                'outputs' => isset($flow['outputs']) ? $flow['outputs'] : [],
                'nodes' => $nodes,
                'edges' => isset($flow['edges']) ? $flow['edges'] : [],
                'created_by' => null,
                'published_by' => null,
                'published_at' => $publishedAt,
                'created_at' => $publishedAt,
                'updated_at' => $publishedAt,
            ]);
            \DB::collection('flows')->where('_id', $flowId)->update([
                '$set' => ['active_version_id' => (string) $versionId, 'nodes' => $nodes],
            ]);
        }
    }

    public function down()
    {
        foreach (\DB::collection('flows')->where('active_version_id', 'exists', true)->get() as $flow) {
            $nodes = [];
            foreach ((array) ($flow['nodes'] ?? []) as $node) {
                $node = (array) $node;
                unset($node['table_version_id']);
                $nodes[] = $node;
            }
            \DB::collection('flows')->where('_id', (string) $flow['_id'])->update([
                '$set' => ['nodes' => $nodes],
                '$unset' => ['active_version_id' => ''],
            ]);
        }
        \DB::collection('flow_versions')->raw(function ($collection) {
            return $collection->drop();
        });
    }

    /**
     * The active version id of a table, null for a table without versions
     * (its node then runs the table as stored).
     */
    private static function activeVersionOf($tableId, array &$cache)
    {
        if (!$tableId) {
            return null;
        }
        if (!array_key_exists($tableId, $cache)) {
            $table = \DB::collection('tables')->where('_id', (string) $tableId)->first();
            $cache[$tableId] = !empty($table['active_version_id']) ? (string) $table['active_version_id'] : null;
        }

        return $cache[$tableId];
    }
}
