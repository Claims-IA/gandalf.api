<?php
/**
 * FlowsController
 *
 * REST CRUD for Decision Requirement Graphs (Flows). Create/read/update/delete
 * and list are provided by the Nebo15 AbstractController, which delegates writes
 * to FlowRepository::createOrUpdate — where the graph is validated. Basic request
 * validation (title/structure) happens here; graph-level validation (references,
 * acyclicity, type compatibility) happens in the repository.
 *
 * @package App\Http\Controllers
 */

namespace App\Http\Controllers;

use Nebo15\REST\AbstractController;

class FlowsController extends AbstractController
{
    protected $repositoryClassName = 'App\Repositories\FlowRepository';

    protected $validationRules = [
        'create' => [
            'title'   => 'required|string|min:1',
            'nodes'   => 'required|array',
            'edges'   => 'sometimes|array',
            'inputs'  => 'sometimes|array',
            'outputs' => 'required|array',
        ],
        'update' => [
            'title'   => 'sometimes|required|string|min:1',
            'nodes'   => 'sometimes|required|array',
            'edges'   => 'sometimes|array',
            'inputs'  => 'sometimes|array',
            'outputs' => 'sometimes|required|array',
        ],
        'readList' => [
            'title' => 'sometimes|min:1',
        ],
    ];
}
