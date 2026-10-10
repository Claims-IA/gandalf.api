<?php
/**
 * FlowVersionsController
 *
 * Admin routes of a flow's versions (VERSIONS.md, batch 4), as
 * TableVersionsController for tables. Under /api/v1/admin/flows/{id}:
 *   GET    /versions                         list (without graph)
 *   POST   /versions                         create a draft
 *   GET    /versions/{version_id}            read a version
 *   PUT    /versions/{version_id}            edit a draft
 *   DELETE /versions/{version_id}            delete a version
 *   POST   /versions/{version_id}/publish    publish a draft
 *   POST   /versions/{version_id}/decisions  test run of a draft or a version
 *   POST   /activate                         activate a published version
 *   GET    /calls                            recent calls of the project's API keys
 *
 * The ACL (config/applicationable.php) requires the base scope of each route;
 * deleting a published version also needs tables_publish, checked here.
 *
 * @package App\Http\Controllers
 */

namespace App\Http\Controllers;

use App\Models\Flow;
use App\Models\FlowVersion;
use Nebo15\REST\Response;
use Illuminate\Http\Request;
use App\Services\FlowEngine;
use App\Services\VersionCalls;
use App\Repositories\FlowRepository;
use App\Repositories\FlowVersionsRepository;
use Illuminate\Contracts\Validation\ValidationException;
use Nebo15\LumenApplicationable\Models\Application;
use Nebo15\LumenApplicationable\Exceptions\AccessDeniedException;

class FlowVersionsController extends Controller
{
    private $request;

    private $response;

    private $flows;

    private $versions;

    private $calls;

    public function __construct(
        Request $request,
        Response $response,
        FlowRepository $flows,
        FlowVersionsRepository $versions,
        VersionCalls $calls
    ) {
        $this->request = $request;
        $this->response = $response;
        $this->flows = $flows;
        $this->versions = $versions;
        $this->calls = $calls;
    }

    public function index($id)
    {
        $flow = $this->flows->read($id);
        $versions = array_map(function (FlowVersion $version) use ($flow) {
            return $version->toSummaryArray() + ['is_active' => $this->versions->isActive($flow, $version)];
        }, $this->versions->listFor($flow));

        return $this->response->json($versions);
    }

    public function create($id)
    {
        $this->validateInput($this->request->all(), [
            'from_version_id' => 'sometimes|mongoId',
            'name' => 'sometimes|string|max:128',
        ]);
        $flow = $this->flows->read($id);
        $draft = $this->versions->createDraft(
            $flow,
            $this->request->input('from_version_id'),
            $this->request->input('name', '')
        );

        return $this->response->json($this->present($flow, $draft), Response::HTTP_CREATED);
    }

    public function read($id, $version_id)
    {
        $flow = $this->flows->read($id);

        return $this->response->json($this->present($flow, $this->versions->find($flow, $version_id)));
    }

    /**
     * Replace a draft's graph: the keys sent (inputs, outputs, nodes, edges)
     * replace the draft's, plus an optional name. A node may name the table
     * version it is pinned to (`table_version_id`, a published version).
     */
    public function update($id, $version_id)
    {
        $this->validateInput($this->request->all(), [
            'name' => 'sometimes|string|max:128',
            'nodes' => 'sometimes|required|array',
            'nodes.*.table_version_id' => 'sometimes|mongoId',
            'edges' => 'sometimes|array',
            'inputs' => 'sometimes|array',
            'outputs' => 'sometimes|required|array',
        ]);
        $flow = $this->flows->read($id);
        $draft = $this->versions->find($flow, $version_id);

        return $this->response->json($this->present($flow, $this->versions->updateDraft($draft, $this->request->all())));
    }

    public function delete(Application $application, $id, $version_id)
    {
        $flow = $this->flows->read($id);
        $version = $this->versions->find($flow, $version_id);
        if ($version->isPublished()) {
            $this->requireScope('tables_publish');
        }
        $this->versions->delete($flow, $version, $application);

        return $this->response->json();
    }

    public function publish($id, $version_id)
    {
        $this->validateInput($this->request->all(), ['comment' => 'sometimes|string|max:512']);
        $flow = $this->flows->read($id);
        $version = $this->versions->publish(
            $this->versions->find($flow, $version_id),
            $this->request->input('comment', '')
        );

        return $this->response->json(
            $this->present($flow, $version),
            Response::HTTP_OK,
            $this->versions->divergence($flow, $version) ?: []
        );
    }

    /**
     * Activate a published version. The response meta lists the API keys that
     * recently called the flow without a version (`callers_without_version`).
     */
    public function activate(Application $application, $id)
    {
        $this->validateInput($this->request->all(), ['version_id' => 'required|mongoId']);
        $flow = $this->flows->read($id);
        $version = $this->versions->find($flow, $this->request->input('version_id'));
        // Measured before the activation: afterwards the version is the active one.
        $divergence = $this->versions->divergence($flow, $version);
        $this->versions->activate($flow, $version);

        return $this->response->json(
            $flow->toArray(),
            Response::HTTP_OK,
            ($divergence ?: []) + ['callers_without_version' => $this->calls->withoutVersion($flow, $application)]
        );
    }

    /**
     * Test run of a draft or a version from gandalf.web, body `{ data }`. The
     * run and its decisions are flagged `test`; the rules are always returned.
     */
    public function run(FlowEngine $engine, Application $application, $id, $version_id)
    {
        $this->validateInput($this->request->all(), ['data' => 'present|array']);
        $flow = $this->flows->read($id);
        $version = $this->versions->find($flow, $version_id);

        return $this->response->json(
            $engine->run($flow, (array) $this->request->input('data'), $application->_id, true, $version, true)
        );
    }

    public function calls(Application $application, $id)
    {
        $flow = $this->flows->read($id);

        return $this->response->json(
            $this->calls->recentFor($flow, $application),
            Response::HTTP_OK,
            ['window_days' => VersionCalls::windowDays()]
        );
    }

    private function present(Flow $flow, FlowVersion $version)
    {
        return $version->toArray() + ['is_active' => $this->versions->isActive($flow, $version)];
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
