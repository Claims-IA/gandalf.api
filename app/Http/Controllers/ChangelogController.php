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
use App\Models\Table;
use App\Repositories\FlowRepository;
use App\Repositories\TablesRepository;
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
     * The document attributes are replaced with those stored in the snapshot and
     * saved; the model observer records a new changelog entry for the rollback.
     *
     * Changelog::rollback() is not used: it finds the snapshot by application
     * but reloads the document without that filter, so a document moved to
     * another application since the snapshot was pulled back here and
     * overwritten. The document is loaded within the current application
     * (404 when it is no longer there) and the snapshot applied to it.
     *
     * @param  string $table        The MongoDB collection name.
     * @param  string $model_id     The MongoDB ObjectID of the document.
     * @param  string $changelog_id The changelog entry ID to restore.
     * @return \Illuminate\Http\JsonResponse
     */
    public function rollback($table, $model_id, $changelog_id)
    {
        $applicationId = ApplicationableHelper::getApplicationId();
        $changelog = $this->changelogModel->findById(
            $changelog_id,
            $table,
            $model_id,
            ['model.attributes.applications' => $applicationId]
        );
        $model = $changelog->getModelClass();
        $document = $model->newQuery()
            ->where($model->getKeyName(), $model_id)
            ->where('applications', (string) $applicationId)
            ->firstOrFail();

        // A table rollback can rename fields back (same field _id, other key):
        // the flows that followed the rename must follow the rollback too.
        // (The save result only says whether it succeeded, so the fields after
        // it are read back.)
        $fieldsBefore = $table === 'tables' ? $this->tableFields($model_id) : [];

        $result = ['reverted' => $document->setRawAttributes($changelog->model['attributes'])->save()];

        if ($table === 'tables') {
            $renames = TablesRepository::fieldRenamesBetween($fieldsBefore, $this->tableFields($model_id));
            if ($renames) {
                $result += (new FlowRepository())->followFieldRenames($model_id, $renames);
            }
        }

        return $this->response->json($result);
    }

    /**
     * A table's fields, as arrays (empty when the table is not found in the
     * current application). Only the fields are read.
     *
     * @param  string $id
     * @return array
     */
    private function tableFields($id)
    {
        $table = Table::where('_id', $id)
            ->where('applications', (string) ApplicationableHelper::getApplicationId())
            ->first(['fields']);
        $fields = [];
        foreach ((($table ? $table->fields : null) ?: []) as $field) {
            $fields[] = ['_id' => (string) $field->_id, 'key' => $field->key];
        }

        return $fields;
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
