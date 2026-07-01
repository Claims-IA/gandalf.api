<?php
/**
 * ChangelogController
 *
 * Exposes the Nebo15/Changelog audit trail through the API. Every time a
 * decision table is saved a changelog snapshot is created by the TableObserver.
 * This controller lets admin users browse the full history of a resource, diff
 * two versions, and roll back to any previous state. All queries are automatically
 * scoped to the current application so tenants cannot see each other's history.
 *
 * @package App\Http\Controllers
 */
namespace App\Http\Controllers;

use Laravel\Lumen\Routing\Controller;
use Nebo15\Changelog\Changelog;
use Nebo15\Changelog\ControllerInterface;
use Nebo15\LumenApplicationable\ApplicationableHelper;
use Nebo15\REST\Response;
use Illuminate\Http\Request;
use Illuminate\Contracts\Validation\ValidationException;

class ChangelogController extends Controller implements ControllerInterface
{
    protected $request;

    protected $response;

    protected $changelogModel;

    /**
     * Inject the request, response, and changelog model dependencies.
     *
     * @param Request   $request
     * @param Response  $response
     * @param Changelog $changelogModel
     */
    public function __construct(Request $request, Response $response, Changelog $changelogModel)
    {
        $this->request = $request;
        $this->response = $response;
        $this->changelogModel = $changelogModel;
    }

    /**
     * Return all changelog entries for a given collection (resource type).
     *
     * Results are scoped to the current application via the applications embedded
     * field so cross-tenant leakage is impossible.
     *
     * @param  string $table  The MongoDB collection name (e.g. "tables").
     * @return \Illuminate\Http\JsonResponse
     */
    public function all($table)
    {
        return $this->response->jsonPaginator(
            $this->changelogModel->findAll(
                $table,
                null,
                $this->request->get('size'),
                // Scope the query to the current application so tenants see only their history
                ['model.attributes.applications' => ApplicationableHelper::getApplicationId()]
            )
        );
    }

    /**
     * Return changelog entries for a specific document within a collection.
     *
     * @param  string $table     The MongoDB collection name.
     * @param  string $model_id  The MongoDB ObjectID of the specific document.
     * @return \Illuminate\Http\JsonResponse
     */
    public function allWithId($table, $model_id)
    {
        return $this->response->jsonPaginator(
            $this->changelogModel->findAll(
                $table,
                $model_id,
                $this->request->get('size'),
                ['model.attributes.applications' => ApplicationableHelper::getApplicationId()]
            )
        );
    }

    /**
     * Return a structured diff between two changelog snapshots.
     *
     * Requires compare_with (the changelog entry ID to compare against) and
     * optionally original (the baseline entry ID; defaults to the most recent).
     *
     * @param  string $table     The MongoDB collection name.
     * @param  string $model_id  The MongoDB ObjectID of the document.
     * @return \Illuminate\Http\JsonResponse
     */
    public function diff($table, $model_id)
    {
        $this->validate($this->request, [
            'compare_with' => 'required',
            'original' => 'sometimes|required',
        ]);

        return $this->response->json(
            $this->changelogModel->diff(
                $table,
                $model_id,
                $this->request->input('compare_with'),
                $this->request->input('original'),
                ['model.attributes.applications' => ApplicationableHelper::getApplicationId()]
            )
        );
    }

    /**
     * Roll back a document to the state captured in a specific changelog entry.
     *
     * The Changelog library replaces the current document attributes with those
     * stored in the snapshot, then persists the result and creates a new changelog
     * entry for the rollback action itself.
     *
     * @param  string $table        The MongoDB collection name.
     * @param  string $model_id     The MongoDB ObjectID of the document.
     * @param  string $changelog_id The changelog entry ID to restore.
     * @return \Illuminate\Http\JsonResponse
     */
    public function rollback($table, $model_id, $changelog_id)
    {
        return $this->response->json(
            $this->changelogModel->rollback(
                $table,
                $model_id,
                $changelog_id,
                ['model.attributes.applications' => ApplicationableHelper::getApplicationId()]
            )
        );
    }

    /**
     * Re-throw validation failures as the framework's ValidationException.
     *
     * Required by the ControllerInterface contract from Nebo15/Changelog so that
     * the validation helper in the base Controller class routes errors correctly.
     *
     * @param  Request $request
     * @param  mixed   $validator
     * @throws ValidationException
     */
    protected function throwValidationException(Request $request, $validator)
    {
        throw new ValidationException($validator);
    }
}
