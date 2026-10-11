<?php
/**
 * Flow Model — Decision Requirement Graph
 *
 * A Flow composes several decision Tables into a graph (a DRG, in DMN terms):
 * the output of one table feeds an input field of another. Stored in the
 * MongoDB 'flows' collection. Unlike DMN, a node does not embed its decision
 * logic — it references an existing Table by id, so a table can be a node of
 * several flows and still be called directly.
 *
 * Structure:
 *   - inputs[]  — the flow's public input contract: { key, type }.
 *   - outputs[] — the flow's public output contract: { name, from_node, from_output }.
 *   - nodes[]   — { node_id, table_id, table_version_id }, one per referenced
 *                 table, pinned to a version of it (VERSIONS.md, batch 4).
 *   - edges[]   — { from, into } wiring, where `from` is either an input
 *                 ({ input }) or an upstream node output ({ node, output }),
 *                 and `into` is a downstream field ({ node, field }).
 *
 * The graph lives in the flow's versions (FlowVersion); the flow document holds
 * its identity, its active version (`active_version_id`) and, until release B
 * ends it, a copy of the active version's graph, like a table.
 *
 * Graph validation (acyclicity, references, type compatibility) lives in the
 * FlowRepository / FlowsController, not here. The Applicationable trait scopes
 * queries to the current application for multi-tenant isolation.
 *
 * @package App\Models
 *
 * @property string $title
 * @property string $description
 * @property array  $inputs
 * @property array  $outputs
 * @property array  $nodes
 * @property array  $edges
 * @method static \Illuminate\Pagination\LengthAwarePaginator paginate($perPage = 15, $columns = ['*'], $pageName = 'page', $page = null)
 */

namespace App\Models;

use Nebo15\REST\Traits\ListableTrait;
use Nebo15\REST\Interfaces\ListableInterface;
use Nebo15\LumenApplicationable\Contracts\Applicationable;
use Nebo15\LumenApplicationable\Traits\ApplicationableTrait;

class Flow extends Base implements ListableInterface, Applicationable
{
    use ApplicationableTrait, ListableTrait;

    protected $collection = 'flows';

    protected $perPage = 20;

    protected $listable = ['_id', 'title', 'description', 'category_id'];

    protected $attributes = [
        'title' => '',
        'description' => '',
        'inputs' => [],
        'outputs' => [],
        'nodes' => [],
        'edges' => [],
        // Optional reference to a category defined in the owning application's
        // settings.categories list. Null means "uncategorised".
        'category_id' => null,
    ];

    protected $visible = [
        '_id',
        'title',
        'description',
        'category_id',
        'active_version_id',
        'inputs',
        'outputs',
        'nodes',
        'edges',
    ];

    protected $fillable = [
        'title',
        'description',
        'category_id',
        'inputs',
        'outputs',
        'nodes',
        'edges',
    ];

    protected $casts = [
        '_id' => 'string',
        'title' => 'string',
        'description' => 'string',
    ];
}
