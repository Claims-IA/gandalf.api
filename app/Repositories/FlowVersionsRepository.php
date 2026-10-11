<?php
/**
 * FlowVersionsRepository
 *
 * Lifecycle of a flow's versions (VERSIONS.md, batch 4), as
 * TableVersionsRepository for tables:
 * - drafts, created from a version or another draft and edited like a flow,
 *   each node pinned to a published version of its table;
 * - publication: a draft is frozen and numbered;
 * - activation: the published version the API runs;
 * - deletion, refused for the active version and for a version an API key
 *   still calls.
 *
 * It also brings flows to the active version of one of their tables
 * (updateToActive): a new flow version pins it, the edges following the field
 * renames between the two table versions.
 *
 * @package App\Repositories
 */

namespace App\Repositories;

use App\Models\Flow;
use App\Models\Table;
use App\Models\FlowVersion;
use App\Models\TableVersion;
use App\Services\VersionCalls;
use App\Services\FlowVersioning;
use App\Services\TableVersioning;
use App\Exceptions\FlowValidationException;
use Nebo15\LumenApplicationable\Models\Application;
use Nebo15\LumenApplicationable\ApplicationableHelper;

class FlowVersionsRepository
{
    private $flows;

    private $versioning;

    private $calls;

    public function __construct(FlowRepository $flows, FlowVersioning $versioning, VersionCalls $calls)
    {
        $this->flows = $flows;
        $this->versioning = $versioning;
        $this->calls = $calls;
    }

    /**
     * A flow's versions without their graph: drafts first (last edited first),
     * then published versions (newest first).
     *
     * @param  Flow $flow
     * @return FlowVersion[]
     */
    public function listFor(Flow $flow)
    {
        $versions = FlowVersion::where('flow_id', (string) $flow->getId())
            ->get(['_id', 'flow_id', 'applications', 'status', 'number', 'name', 'comment', 'origin_version_id',
                'autopublished', 'created_by', 'published_by', 'published_at', 'created_at', 'updated_at'])
            ->all();
        usort($versions, function (FlowVersion $a, FlowVersion $b) {
            if ($a->isPublished() !== $b->isPublished()) {
                return $a->isPublished() ? 1 : -1;
            }
            if ($a->isPublished()) {
                return $b->number <=> $a->number;
            }

            return strcmp((string) $b->updated_at, (string) $a->updated_at);
        });

        return $versions;
    }

    /**
     * @param  Flow   $flow
     * @param  string $versionId
     * @return FlowVersion
     */
    public function find(Flow $flow, $versionId)
    {
        $version = FlowVersion::where('_id', $versionId)->where('flow_id', (string) $flow->getId())->first();
        if (!$version) {
            abort(404, 'version_not_found');
        }

        return $version;
    }

    /**
     * The published version of a flow numbered $number.
     *
     * @param  Flow $flow
     * @param  int  $number
     * @return FlowVersion
     */
    public function findPublished(Flow $flow, $number)
    {
        $version = FlowVersion::where('flow_id', (string) $flow->getId())
            ->where('status', FlowVersion::STATUS_PUBLISHED)
            ->where('number', (int) $number)
            ->first();
        if (!$version) {
            abort(404, 'version_not_found');
        }

        return $version;
    }

    public function isActive(Flow $flow, FlowVersion $version)
    {
        return (string) $flow->active_version_id === (string) $version->getId();
    }

    /**
     * Create a draft holding the graph of a version or a draft of the flow, by
     * default its active version (or the flow itself before it has one).
     *
     * @param  Flow        $flow
     * @param  string|null $fromVersionId
     * @param  string      $name
     * @return FlowVersion
     */
    public function createDraft(Flow $flow, $fromVersionId = null, $name = '')
    {
        $source = $fromVersionId ? $this->find($flow, $fromVersionId) : $this->versioning->activeVersion($flow);
        $graph = FlowVersioning::graphOf($source ?: $flow);
        $graph['nodes'] = $this->versioning->pinNodes($graph['nodes'], $source ?: $flow);

        $draft = $this->versioning->newVersion($flow, $graph, $source);
        $draft->setAttribute('name', (string) $name);
        $draft->save();

        return $draft;
    }

    /**
     * Replace a draft's graph: the keys sent replace the draft's, nodes are
     * pinned (a node without a pin keeps the draft's, or takes its table's
     * active version) and the graph is validated as a flow's.
     *
     * @param  FlowVersion $draft
     * @param  array       $values  inputs, outputs, nodes, edges, name.
     * @return FlowVersion
     * @throws FlowValidationException
     */
    public function updateDraft(FlowVersion $draft, array $values)
    {
        if ($draft->isPublished()) {
            abort(409, 'version_published');
        }
        $graph = FlowVersioning::graphOf($draft);
        foreach (FlowVersion::GRAPH_KEYS as $key) {
            if (array_key_exists($key, $values)) {
                $graph[$key] = array_values((array) $values[$key]);
            }
        }
        $graph['nodes'] = $this->versioning->pinNodes($graph['nodes'], $draft);
        $this->flows->validateGraph($graph);

        foreach ($graph as $key => $value) {
            $draft->setAttribute($key, $value);
        }
        if (array_key_exists('name', $values)) {
            $draft->setAttribute('name', (string) $values['name']);
        }
        $draft->save();

        return $draft;
    }

    /**
     * Publish a draft. Its graph is validated again: a pinned table version
     * deleted since would make it unrunnable.
     *
     * @param  FlowVersion $draft
     * @param  string      $comment
     * @return FlowVersion
     * @throws FlowValidationException
     */
    public function publish(FlowVersion $draft, $comment = '')
    {
        if ($draft->isPublished()) {
            abort(409, 'version_published');
        }
        $this->flows->validateGraph(FlowVersioning::graphOf($draft));

        return $this->versioning->publish($draft, $comment);
    }

    /**
     * Make a published version the one the API runs; its graph is copied onto
     * the flow.
     *
     * @param  Flow        $flow
     * @param  FlowVersion $version
     * @return Flow
     */
    public function activate(Flow $flow, FlowVersion $version)
    {
        if (!$version->isPublished()) {
            abort(409, 'version_not_published');
        }

        return $this->versioning->copyIntoFlow($flow, $version);
    }

    /**
     * Delete a version. The active version stays, and so does a published
     * version an API key of the application called by its number within the
     * window (VersionCalls).
     *
     * @param  Flow        $flow
     * @param  FlowVersion $version
     * @param  Application $application
     */
    public function delete(Flow $flow, FlowVersion $version, Application $application)
    {
        if ($this->isActive($flow, $version)) {
            abort(409, 'version_in_use');
        }
        if ($version->isPublished() && $this->calls->isCalled($flow, $version->number, $application)) {
            abort(409, 'version_in_use');
        }
        $version->delete();
    }

    /**
     * Warning for a version that does not descend from the flow's active
     * version, as for tables (TableVersionsRepository::divergence).
     *
     * @param  Flow        $flow
     * @param  FlowVersion $version
     * @return array|null
     */
    public function divergence(Flow $flow, FlowVersion $version)
    {
        $active = $this->versioning->activeVersion($flow);
        if (!$active) {
            return null;
        }
        $origins = function ($id) {
            $origin = FlowVersion::where('_id', $id)->first(['origin_version_id']);

            return $origin ? $origin->origin_version_id : null;
        };
        if (TableVersionsRepository::descendsFrom((string) $version->getId(), (string) $active->getId(), $origins)) {
            return null;
        }

        return [
            'warning' => 'version_diverges',
            'active_version' => ['_id' => (string) $active->getId(), 'number' => $active->number],
        ];
    }

    /**
     * The current application's flows whose active version (or graph, without
     * versions) has a node on the table, with the version each node runs.
     *
     * @param  Table $table
     * @return array  [['_id', 'title', 'active_version' => {_id, number}|null, 'nodes' => [{node_id, table_version}], 'outdated' => bool]]
     */
    public function flowsUsing(Table $table)
    {
        $tableId = (string) $table->getId();
        $active = $table->active_version_id ? (string) $table->active_version_id : null;
        $numbers = [];
        foreach (TableVersion::where('table_id', $tableId)->get(['_id', 'number']) as $version) {
            $numbers[(string) $version->getId()] = $version->number;
        }

        $rows = [];
        foreach ($this->flowsOn($tableId) as $flow) {
            $flowVersion = $this->versioning->activeVersion($flow);
            $nodes = [];
            $outdated = false;
            foreach ((array) ($flowVersion ?: $flow)->nodes as $node) {
                $node = (array) $node;
                if (($node['table_id'] ?? null) !== $tableId) {
                    continue;
                }
                $pin = isset($node['table_version_id']) ? (string) $node['table_version_id'] : null;
                $outdated = $outdated || ($pin !== null && $pin !== $active);
                $nodes[] = [
                    'node_id' => $node['node_id'] ?? null,
                    'table_version' => $pin ? ['_id' => $pin, 'number' => $numbers[$pin] ?? null] : null,
                ];
            }
            $rows[] = [
                '_id' => (string) $flow->getId(),
                'title' => $flow->title,
                'active_version' => $flowVersion
                    ? ['_id' => (string) $flowVersion->getId(), 'number' => $flowVersion->number]
                    : null,
                'nodes' => $nodes,
                'outdated' => $outdated,
            ];
        }

        return $rows;
    }

    /**
     * Bring flows to the active version of one of their tables: for each flow
     * whose active version pins the table elsewhere, a draft from that version
     * pins it to the active one, its edges following the field renames between
     * the two table versions (as a table update made flows follow them). A flow
     * whose graph no longer validates is reported as incompatible and left as
     * is. The drafts can be published, then activated, in the same call.
     *
     * @param  Table      $table
     * @param  array|null $flowIds  Flows to update; null: every flow using the table.
     * @param  bool       $publish
     * @param  bool       $activate  Needs $publish.
     * @return array  [['_id', 'title', 'status' => updated|up_to_date|incompatible,
     *                  'version' => {_id, number, status}|null, 'errors' => []]]
     */
    public function updateToActive(Table $table, $flowIds = null, $publish = false, $activate = false)
    {
        $tableId = (string) $table->getId();
        $target = $table->active_version_id
            ? TableVersion::where('_id', (string) $table->active_version_id)->first()
            : null;

        $results = [];
        foreach ($this->flowsOn($tableId) as $flow) {
            if ($flowIds !== null && !in_array((string) $flow->getId(), $flowIds, true)) {
                continue;
            }
            $row = ['_id' => (string) $flow->getId(), 'title' => $flow->title, 'status' => 'up_to_date', 'version' => null, 'errors' => []];
            $active = $this->versioning->activeVersion($flow);
            $graph = FlowVersioning::graphOf($active ?: $flow);
            $graph['nodes'] = $this->versioning->pinNodes($graph['nodes'], $active ?: $flow);
            $repinned = $target ? $this->repin($graph, $tableId, $target) : null;
            if ($repinned === null) {
                $results[] = $row;
                continue;
            }
            try {
                $this->flows->validateGraph($repinned);
            } catch (FlowValidationException $e) {
                $results[] = ['status' => 'incompatible', 'errors' => $e->getErrors()] + $row;
                continue;
            }

            $draft = $this->versioning->newVersion($flow, $repinned, $active);
            $draft->setAttribute('name', trim($table->title . ' v' . $target->number));
            $draft->save();
            if ($publish) {
                $this->versioning->publish($draft);
                if ($activate) {
                    $this->versioning->copyIntoFlow($flow, $draft);
                }
            }
            $results[] = [
                'status' => 'updated',
                'version' => ['_id' => (string) $draft->getId(), 'number' => $draft->number, 'status' => $draft->status],
            ] + $row;
        }

        return $results;
    }

    /**
     * The graph with the nodes on a table pinned to $target and their edges
     * following the field renames between each node's previous version and
     * $target, or null when every node on the table already runs $target.
     *
     * @param  array        $graph
     * @param  string       $tableId
     * @param  TableVersion $target
     * @return array|null
     */
    private function repin(array $graph, $tableId, TableVersion $target)
    {
        $targetId = (string) $target->getId();
        $byPin = [];   // previous pin => node ids
        foreach ($graph['nodes'] as $i => $node) {
            if (($node['table_id'] ?? null) !== $tableId || ($node['table_version_id'] ?? null) === $targetId) {
                continue;
            }
            $byPin[(string) ($node['table_version_id'] ?? '')][] = (string) $node['node_id'];
            $graph['nodes'][$i]['table_version_id'] = $targetId;
        }
        if (!$byPin) {
            return null;
        }

        $inputKeys = [];
        foreach ($graph['inputs'] as $input) {
            $input = (array) $input;
            if (isset($input['key'])) {
                $inputKeys[$input['key']] = true;
            }
        }
        $targetFields = $target->getAttributes()['fields'] ?? [];
        foreach ($byPin as $pin => $nodeIds) {
            $previous = $pin !== '' ? TableVersion::where('_id', $pin)->first() : null;
            $previousFields = $previous ? ($previous->getAttributes()['fields'] ?? []) : [];
            $renames = TablesRepository::fieldRenamesBetween($previousFields, $targetFields);
            if ($renames) {
                $graph['edges'] = FlowRepository::renameEdges($graph['edges'], $nodeIds, $inputKeys, $renames) ?: $graph['edges'];
            }
        }

        return $graph;
    }

    /**
     * The current application's flows with a node on the table.
     *
     * @param  string $tableId
     * @return Flow[]
     */
    private function flowsOn($tableId)
    {
        return Flow::where('applications', (string) ApplicationableHelper::getApplicationId())
            ->where('nodes.table_id', (string) $tableId)
            ->orderBy('title')
            ->get()
            ->all();
    }
}
