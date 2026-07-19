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

use App\Http\Controllers\Concerns\CopyMoveGuard;
use Nebo15\REST\AbstractController;
use Nebo15\REST\Interfaces\ListableInterface;

/**
 * @method \App\Repositories\FlowRepository getRepository()
 */
class FlowsController extends AbstractController
{
    use CopyMoveGuard;

    protected $repositoryClassName = 'App\Repositories\FlowRepository';

    protected $validationRules = [
        'create' => [
            'title'       => 'required|string|min:1',
            'category_id' => 'sometimes|string',
            'nodes'       => 'required|array',
            'edges'       => 'sometimes|array',
            'inputs'      => 'sometimes|array',
            'outputs'     => 'required|array',
        ],
        'update' => [
            'title'       => 'sometimes|required|string|min:1',
            'category_id' => 'sometimes|string',
            'nodes'       => 'sometimes|required|array',
            'edges'       => 'sometimes|array',
            'inputs'      => 'sometimes|array',
            'outputs'     => 'sometimes|required|array',
        ],
        'readList' => [
            'title'       => 'sometimes|min:1',
            'description' => 'sometimes|min:1',
            'category_id' => 'sometimes|string',
        ],
    ];

    /**
     * Return a paginated, application-scoped list of flows.
     *
     * Overrides the vendor readList (which ignores query filters) to validate
     * and apply the same title/description filters as the tables endpoint, and
     * to reduce each flow to its list projection (Flow::toListArray).
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function readList()
    {
        $this->validateRoute();

        return $this->response->jsonPaginator(
            $this->getRepository()->readListWithFilters($this->request->all()),
            [],
            function (ListableInterface $model) {
                return $model->toListArray();
            }
        );
    }

    /**
     * Paginated run history for a flow (most recent first).
     *
     * @param  string $id  Flow ObjectID.
     * @return \Illuminate\Http\JsonResponse
     */
    public function runs($id)
    {
        return $this->response->jsonPaginator(
            $this->getRepository()->getRuns($id, $this->request->input('size'))
        );
    }

    /**
     * Copy a flow into another project, bringing copies of its referenced tables.
     *
     * Admin-only; the caller must be a member of the target project (guardCopyMove).
     * The referenced tables are duplicated into the target and the node table_ids
     * remapped, so the copied flow stays executable there.
     *
     * @param  string $id         Flow ObjectID.
     * @param  string $project_id Target project/application ObjectID.
     * @return \Illuminate\Http\JsonResponse
     */
    public function copyTo($id, $project_id)
    {
        if ($error = $this->guardCopyMove($project_id)) {
            return $error;
        }

        return $this->response->json(
            $this->getRepository()->copyTo($id, $project_id)->toArray()
        );
    }

    /**
     * Move a flow to another project (change ownership), bringing copies of its
     * referenced tables.
     *
     * Admin-only; the caller must be a member of the target project. The flow
     * disappears from the source project; its referenced tables are COPIED (not
     * moved) into the target so other source flows keep working.
     *
     * @param  string $id         Flow ObjectID.
     * @param  string $project_id Target project/application ObjectID.
     * @return \Illuminate\Http\JsonResponse
     */
    public function moveTo($id, $project_id)
    {
        if ($error = $this->guardCopyMove($project_id)) {
            return $error;
        }

        return $this->response->json(
            $this->getRepository()->moveTo($id, $project_id)->toArray()
        );
    }
}
