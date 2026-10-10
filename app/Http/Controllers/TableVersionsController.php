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
 *   POST   /versions/{version_id}/decisions  test run of a draft or a version
 *   POST   /activate                         activate a published version
 *   GET    /calls                            recent calls of the project's API keys
 *   GET    /flows                            flows using the table, version per node
 *   POST   /flows/update                     bring flows to the active version
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
use App\Services\Scoring;
use App\Services\VersionCalls;
use App\Services\ConditionsTypes;
use App\Repositories\TablesRepository;
use App\Validators\TableRulesProvider;
use App\Repositories\TableVersionsRepository;
use App\Repositories\FlowVersionsRepository;
use Illuminate\Contracts\Validation\ValidationException;
use Nebo15\LumenApplicationable\Models\Application;
use Nebo15\LumenApplicationable\Exceptions\AccessDeniedException;

class TableVersionsController extends Controller
{
    private $request;

    private $response;

    private $tables;

    private $versions;

    private $conditionsTypes;

    private $calls;

    private $flowVersions;

    public function __construct(
        Request $request,
        Response $response,
        TablesRepository $tables,
        TableVersionsRepository $versions,
        ConditionsTypes $conditionsTypes,
        VersionCalls $calls,
        FlowVersionsRepository $flowVersions
    ) {
        $this->flowVersions = $flowVersions;
        $this->request = $request;
        $this->response = $response;
        $this->tables = $tables;
        $this->versions = $versions;
        $this->conditionsTypes = $conditionsTypes;
        $this->calls = $calls;
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

    public function delete(Application $application, $id, $version_id)
    {
        $table = $this->tables->read($id);
        $version = $this->versions->find($table, $version_id);
        if ($version->isPublished()) {
            $this->requireScope('tables_publish');
        }
        $this->versions->delete($table, $version, $application);

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

    /**
     * Activate a published version. The response meta lists the API keys that
     * recently called the table without a version (`callers_without_version`):
     * the activation changes what they run; and the flows still pinned to
     * another version (`flows_outdated`): it changes nothing for them until
     * they are brought to it (POST /flows/update). In compatibility mode flows
     * follow, so none is left behind.
     */
    public function activate(Application $application, $id)
    {
        $this->validateInput($this->request->all(), ['version_id' => 'required|mongoId']);
        $table = $this->tables->read($id);
        $version = $this->versions->find($table, $this->request->input('version_id'));
        // Measured before the activation: afterwards the version is the active one.
        $divergence = $this->versions->divergence($table, $version);
        $meta = $this->versions->activate($table, $version);
        $meta['callers_without_version'] = $this->calls->withoutVersion($table, $application);
        $meta['flows_outdated'] = array_values(array_filter($this->flowVersions->flowsUsing($table), function ($flow) {
            return $flow['outdated'];
        }));

        return $this->response->json($table->toArray(), Response::HTTP_OK, ($divergence ?: []) + $meta);
    }

    /**
     * Test run of a draft or a version from gandalf.web, with the body of the
     * route v2 (`{ variant_id, data }`). The decision is flagged `test`, left
     * out of analytics and of the default decision history, and always carries
     * the rule details.
     */
    public function run(Scoring $scoring, Application $application, $id, $version_id)
    {
        $table = $this->tables->read($id);
        $version = $this->versions->find($table, $version_id);

        return $this->response->json(
            $scoring->evaluateRequest($table, $version, $this->request->all(), $application->_id, true, true)
        );
    }

    /**
     * Recent calls of the project's API keys to the table, per requested
     * version (`requested_version` null: the active version, whichever it is).
     */
    public function calls(Application $application, $id)
    {
        $table = $this->tables->read($id);

        return $this->response->json(
            $this->calls->recentFor($table, $application),
            Response::HTTP_OK,
            ['window_days' => VersionCalls::windowDays()]
        );
    }

    /**
     * The project's flows using the table, with the table version each of
     * their nodes runs and whether that is the active one.
     */
    public function flows($id)
    {
        return $this->response->json($this->flowVersions->flowsUsing($this->tables->read($id)));
    }

    /**
     * Bring flows to the table's active version (all, or `flow_ids`): a draft
     * of each, pinned to it, published with `publish` (tables_publish) and
     * activated with `activate` (tables_activate, implies publish).
     */
    public function updateFlows($id)
    {
        $this->validateInput($this->request->all(), [
            'flow_ids' => 'sometimes|array',
            'flow_ids.*' => 'mongoId',
            'publish' => 'sometimes|boolean',
            'activate' => 'sometimes|boolean',
        ]);
        $activate = (bool) $this->request->input('activate', false);
        $publish = $activate || (bool) $this->request->input('publish', false);
        if ($publish) {
            $this->requireScope('tables_publish');
        }
        if ($activate) {
            $this->requireScope('tables_activate');
        }
        $flowIds = $this->request->input('flow_ids');

        return $this->response->json($this->flowVersions->updateToActive(
            $this->tables->read($id),
            $flowIds === null ? null : array_map('strval', (array) $flowIds),
            $publish,
            $activate
        ));
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
