<?php
/**
 * FlowVersion Model
 *
 * One version of a flow's graph, stored in the MongoDB 'flow_versions'
 * collection: its inputs, outputs, nodes and edges. Each node is pinned to a
 * published version of its table (`table_version_id`): activating a new table
 * version changes nothing for a flow until a new flow version pins it. A
 * version is a draft until it is published; a published version is numbered
 * and frozen. The flow points to the version the API runs
 * (Flow::active_version_id).
 *
 * See VERSIONS.md (batch 4) and DRG.md for the lifecycle.
 *
 * @package App\Models
 */

namespace App\Models;

use Nebo15\LumenApplicationable\Contracts\Applicationable;
use Nebo15\LumenApplicationable\Traits\ApplicationableTrait;

/**
 * Class FlowVersion
 * @package App\Models
 * @property string $flow_id
 * @property string $status                draft | published
 * @property int|null $number              Assigned at publication, unique per flow
 * @property string $name
 * @property string $comment
 * @property string|null $origin_version_id Version or draft this one comes from
 * @property bool $autopublished           Published by the compatibility mode or the
 *                                         migration, not by a person
 * @property array $inputs
 * @property array $outputs
 * @property array $nodes                  { node_id, table_id, table_version_id }
 * @property array $edges
 */
class FlowVersion extends Base implements Applicationable
{
    use ApplicationableTrait;

    const STATUS_DRAFT = 'draft';
    const STATUS_PUBLISHED = 'published';

    // The graph: what a version holds, and what a flow holds a copy of.
    const GRAPH_KEYS = ['inputs', 'outputs', 'nodes', 'edges'];

    protected $collection = 'flow_versions';

    protected $attributes = [
        'flow_id' => null,
        'status' => self::STATUS_DRAFT,
        'name' => '',
        'comment' => '',
        'origin_version_id' => null,
        'autopublished' => false,
        'inputs' => [],
        'outputs' => [],
        'nodes' => [],
        'edges' => [],
        'created_by' => null,
        'published_by' => null,
        'published_at' => null,
    ];

    protected $visible = [
        '_id',
        'flow_id',
        'status',
        'number',
        'name',
        'comment',
        'origin_version_id',
        'autopublished',
        'inputs',
        'outputs',
        'nodes',
        'edges',
        'created_by',
        'published_by',
        'published_at',
        'created_at',
        'updated_at',
    ];

    protected $fillable = [
        'name',
        'comment',
        'inputs',
        'outputs',
        'nodes',
        'edges',
    ];

    protected $dates = ['published_at'];

    protected $casts = [
        '_id' => 'string',
        'number' => 'integer',
        'autopublished' => 'boolean',
    ];

    public function isPublished()
    {
        return $this->status === self::STATUS_PUBLISHED;
    }

    /**
     * The version without its graph, for version lists.
     * @return array
     */
    public function toSummaryArray()
    {
        return array_diff_key($this->toArray(), array_flip(self::GRAPH_KEYS));
    }

    /**
     * Dates as ISO-8601, like table versions.
     * @param  \DateTime $date
     * @return string
     */
    protected function serializeDate(\DateTime $date)
    {
        return $date->format(\DateTime::ATOM);
    }
}
