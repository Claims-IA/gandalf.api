<?php
/**
 * TableVersionsController
 *
 * Admin routes of a table's versions (VERSIONS.md, batch 2). Under
 * /api/v1/admin/tables/{id}:
 *   GET    /versions                         list (without content)
 *   POST   /versions                         create a draft
 *   GET    /versions/{version_id}            read a version
 *   PUT    /versions/{version_id}            edit a draft
 *   DELETE /versions/{version_id}            delete a version
 *   POST   /versions/{version_id}/publish    publish a draft
 *   PUT    /versions/{version_id}/split      change a version's variant split
 *   POST   /activate                         activate a published version
 *
 * The ACL (config/applicationable.php) requires the base scope of each route.
 * Two depend on the version and are checked here: deleting a published
 * version needs tables_publish, and changing the split of the active version
 * (a production change) needs tables_activate.
 *
 * @package App\Http\Controllers
 */

namespace App\Http\Controllers;

use App\Models\Table;
use App\Models\TableVersion;
use Nebo15\REST\Response;
use Illuminate\Http\Request;
use App\Services\ConditionsTypes;
use App\Repositories\TablesRepository;
use App\Validators\TableRulesProvider;
use App\Repositories\TableVersionsRepository;
use Illuminate\Contracts\Validation\ValidationException;
use Nebo15\LumenApplicationable\Exceptions\AccessDeniedException;

class TableVersionsController extends Controller
{
    private $request;

    private $response;

    private $tables;

    private $versions;

    private $conditionsTypes;

    public function __construct(
        Request $request,
        Response $response,
        TablesRepository $tables,
        TableVersionsRepository $versions,
        ConditionsTypes $conditionsTypes
    ) {
        $this->request = $request;
        $this->response = $response;
        $this->tables = $tables;
        $this->versions = $versions;
        $this->conditionsTypes = $conditionsTypes;
    }

    public function index($id)
    {
        $table = $this->tables->read($id);
        $versions = array_map(function (TableVersion $version) use ($table) {
            return $version->toSummaryArray() + ['is_active' => $this->versions->isActive($table, $version)];
        }, $this->versions->listFor($table));

        return $this->response->json($versions);
    }

    public function create($id)
    {
        $this->validateInput($this->request->all(), [
            'from_version_id' => 'sometimes|mongoId',
            'name' => 'sometimes|string|max:128',
        ]);
        $table = $this->tables->read($id);
        $draft = $this->versions->createDraft(
            $table,
            $this->request->input('from_version_id'),
            $this->request->input('name', '')
        );

        return $this->response->json($this->present($table, $draft), Response::HTTP_CREATED);
    }

    public function read($id, $version_id)
    {
        $table = $this->tables->read($id);

        return $this->response->json($this->present($table, $this->versions->find($table, $version_id)));
    }

    /**
     * Replace a draft's content. The payload is a table's content (fields,
     * variants, variants_probability) plus an optional name, validated like a
     * table update, against the table's matching_type and decision_type.
     */
    public function update($id, $version_id)
    {
        $table = $this->tables->read($id);
        $draft = $this->versions->find($table, $version_id);
        if ($draft->isPublished()) {
            abort(409, 'version_published');
        }
        // Renamed fields' conditions follow before validation, as for a table.
        $values = $this->tables->withRenamedConditionsOn($draft, $this->request->all());
        $this->validateInput(
            array_merge($values, [
                'matching_type' => $table->matching_type,
                'decision_type' => $table->decision_type,
            ]),
            TableRulesProvider::rules($this->conditionsTypes) + ['name' => 'sometimes|string|max:128']
        );

        return $this->response->json($this->present($table, $this->versions->updateDraft($draft, $values)));
    }

    public function delete($id, $version_id)
    {
        $table = $this->tables->read($id);
        $version = $this->versions->find($table, $version_id);
        if ($version->isPublished()) {
            $this->requireScope('tables_publish');
        }
        $this->versions->delete($table, $version);

        return $this->response->json();
    }

    public function publish($id, $version_id)
    {
        $this->validateInput($this->request->all(), ['comment' => 'sometimes|string|max:512']);
        $table = $this->tables->read($id);
        $version = $this->versions->publish(
            $this->versions->find($table, $version_id),
            $this->request->input('comment', '')
        );

        return $this->response->json(
            $this->present($table, $version),
            Response::HTTP_OK,
            $this->versions->divergence($table, $version) ?: []
        );
    }

    public function split($id, $version_id)
    {
        $this->validateInput($this->request->all(), [
            'variants_probability' => 'required|in:first,random,percent',
            'variants' => 'required|array',
            'variants.*._id' => 'required|mongoId',
            'variants.*.probability' => 'required|integer|between:0,100',
        ]);
        $table = $this->tables->read($id);
        $version = $this->versions->find($table, $version_id);
        if ($this->versions->isActive($table, $version)) {
            $this->requireScope('tables_activate');
        }
        $version = $this->versions->updateSplit(
            $table,
            $version,
            $this->request->input('variants_probability'),
            $this->request->input('variants')
        );

        return $this->response->json($this->present($table, $version));
    }

    public function activate($id)
    {
        $this->validateInput($this->request->all(), ['version_id' => 'required|mongoId']);
        $table = $this->tables->read($id);
        $version = $this->versions->find($table, $this->request->input('version_id'));
        // Measured before the activation: afterwards the version is the active one.
        $divergence = $this->versions->divergence($table, $version);
        $meta = $this->versions->activate($table, $version);

        return $this->response->json($table->toArray(), Response::HTTP_OK, ($divergence ?: []) + $meta);
    }

    private function present(Table $table, TableVersion $version)
    {
        return $version->toArray() + ['is_active' => $this->versions->isActive($table, $version)];
    }

    private function validateInput(array $data, array $rules)
    {
        $validator = \Validator::make($data, $rules);
        if ($validator->fails()) {
            throw new ValidationException($validator);
        }
    }

    private function requireScope($scope)
    {
        $member = $this->request->user()->getApplicationUser();
        if (!$member->{'can' . ucfirst($scope)}()) {
            throw new AccessDeniedException('', 0, null, [$scope]);
        }
    }
}
