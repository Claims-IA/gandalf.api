<?php
/**
 * FlowRun Model
 *
 * Records one execution of a Flow: the inputs it was run with, the assembled
 * outputs, and a trace of each node (which table, which decision it produced,
 * its answer). Stored in the MongoDB 'flow_runs' collection. This is what links
 * the several Decision documents a single graph run produces — filling the role
 * the unused `group` field on Decision hinted at.
 *
 * @package App\Models
 *
 * @property array  $flow         { _id, title }
 * @property mixed  $application
 * @property array  $inputs
 * @property array  $answer
 * @property array  $nodes        Per-node trace: { node_id, table_id, decision_id, answer }
 */

namespace App\Models;

use Nebo15\LumenApplicationable\Contracts\Applicationable;
use Nebo15\LumenApplicationable\Traits\ApplicationableTrait;

class FlowRun extends Base implements Applicationable
{
    use ApplicationableTrait;

    protected $collection = 'flow_runs';

    protected $perPage = 20;

    protected $attributes = [
        'flow' => [],
        'inputs' => [],
        'answer' => [],
        'nodes' => [],
    ];

    protected $visible = [
        '_id',
        'flow',
        'application',
        'inputs',
        'answer',
        'nodes',
        self::CREATED_AT,
        self::UPDATED_AT,
    ];

    protected $fillable = [
        'flow',
        'application',
        'inputs',
        'answer',
        'nodes',
    ];
}
