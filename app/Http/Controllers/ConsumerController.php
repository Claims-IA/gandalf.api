<?php
/**
 * ConsumerController
 *
 * Provides the consumer-facing decision API endpoints. This controller is used
 * by external applications (via API consumer credentials) and by end users. It
 * enforces that the owning application has at least one active admin before
 * allowing decisions to be evaluated, preventing orphaned applications from
 * consuming quota. Decision creation is delegated entirely to the Scoring service.
 *
 * Table versions (VERSIONS.md, batch 3): the route v2 takes
 * `{ version, variant_id, data }` and runs the requested published version, the
 * route v1 keeps its flat body, runs the active version and is deprecated. Both
 * count the calls of API keys (VersionCalls).
 *
 * @package App\Http\Controllers
 */

namespace App\Http\Controllers;

use App\Exceptions\AdminIsNotActivatedException;
use App\Models\Table;
use App\Models\TableVersion;
use App\Models\User;
use App\Services\Scoring;
use App\Services\FlowEngine;
use App\Services\VersionCalls;
use App\Services\TableVersioning;
use App\Repositories\TablesRepository;
use Illuminate\Contracts\Validation\ValidationException;
use Nebo15\LumenApplicationable\ApplicationableHelper;
use Nebo15\LumenApplicationable\Models\Application;
use Nebo15\REST\Response;
use Illuminate\Http\Request;
use App\Repositories\DecisionsRepository;
use Nebo15\REST\Traits\ValidatesRequestsTrait;

class ConsumerController extends Controller
{
    use ValidatesRequestsTrait;

    private $response;
    private $decisionsRepository;

    /**
     * Inject dependencies.
     *
     * @param Response             $response
     * @param DecisionsRepository  $decisionsRepository
     */
    public function __construct(Response $response, DecisionsRepository $decisionsRepository)
    {
        $this->response = $response;
        $this->decisionsRepository = $decisionsRepository;
    }

    /**
     * Evaluate a decision table against the provided field values.
     *
     * Guards against inactive projects by requiring at least one 'admin'-role user
     * of the application to have an active (email-verified) account. This prevents
     * orphaned or unverified applications from consuming the decision engine.
     * Delegates the actual evaluation logic to the Scoring service.
     *
     * @param  Request     $request
     * @param  Scoring     $scoring
     * @param  Application $application  Resolved from the X-Application header.
     * @param  string      $id           MongoDB ObjectID of the table to evaluate.
     * @return \Illuminate\Http\JsonResponse
     * @throws AdminIsNotActivatedException if the application has no active admin users.
     */
    public function tableCheck(
        Request $request,
        Scoring $scoring,
        VersionCalls $calls,
        Application $application,
        $id
    ) {
        $this->assertActiveAdmin($application);

        // The show_meta application setting controls whether rule details are included in the response
        $decision = $scoring->check($id, $request->all(), $application->_id, $application->getSettingsElem('show_meta', false));
        $calls->record($id, null, $application->_id);
        $response = $this->response->json($decision);
        $response->headers->add(self::v1DeprecationHeaders(
            $id,
            env('DECISIONS_V1_DEPRECATED_AT'),
            env('DECISIONS_V1_SUNSET')
        ));

        return $response;
    }

    /**
     * Route v2: evaluate a published version of a table.
     *
     * Body `{ "version": 4, "variant_id": "…", "data": { … } }`, `version` and
     * `variant_id` optional. Without `version`, the active version runs. A
     * published version other than the active one needs the
     * decisions_make_inactive scope (403 version_not_allowed); a draft has no
     * number and is never reachable here (404 version_not_found).
     *
     * @param  Request          $request
     * @param  Scoring          $scoring
     * @param  TablesRepository $tables
     * @param  TableVersioning  $versioning
     * @param  VersionCalls     $calls
     * @param  Application      $application
     * @param  string           $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function tableCheckV2(
        Request $request,
        Scoring $scoring,
        TablesRepository $tables,
        TableVersioning $versioning,
        VersionCalls $calls,
        Application $application,
        $id
    ) {
        $this->assertActiveAdmin($application);
        $validator = \Validator::make($request->all(), ['version' => 'integer|min:1']);
        if ($validator->fails()) {
            throw new ValidationException($validator);
        }
        $table = $tables->read($id);
        $number = $request->input('version');
        $version = $number === null
            ? $versioning->activeVersion($table)
            : $this->requestedVersion($table, (int) $number);

        $decision = $scoring->evaluateRequest(
            $table,
            $version,
            $request->except('version'),
            $application->_id,
            $application->getSettingsElem('show_meta', false)
        );
        $calls->record($id, $number === null ? null : (int) $number, $application->_id);

        return $this->response->json($decision);
    }

    /**
     * Headers announcing the end of the route v1 (RFC 9745 Deprecation,
     * RFC 8594 Sunset), from two dates set in the environment. No date, no header.
     *
     * @param  string      $tableId
     * @param  string|null $deprecatedAt  e.g. "2026-11-01" (DECISIONS_V1_DEPRECATED_AT)
     * @param  string|null $sunset        e.g. "2027-06-30" (DECISIONS_V1_SUNSET)
     * @return array
     */
    public static function v1DeprecationHeaders($tableId, $deprecatedAt, $sunset)
    {
        $headers = [];
        if ($deprecatedAt && ($time = strtotime($deprecatedAt . ' UTC')) !== false) {
            $headers['Deprecation'] = '@' . $time;
            $headers['Link'] = '</api/v2/tables/' . $tableId . '/decisions>; rel="successor-version"';
        }
        if ($sunset && ($time = strtotime($sunset . ' UTC')) !== false) {
            $headers['Sunset'] = gmdate('D, d M Y H:i:s', $time) . ' GMT';
        }

        return $headers;
    }

    /**
     * The published version of a table numbered $number, if the caller may run it.
     *
     * @param  Table $table
     * @param  int   $number
     * @return TableVersion
     */
    private function requestedVersion(Table $table, $number)
    {
        $version = TableVersion::where('table_id', (string) $table->getId())
            ->where('status', TableVersion::STATUS_PUBLISHED)
            ->where('number', $number)
            ->first();
        if (!$version) {
            abort(404, 'version_not_found');
        }
        $active = (string) $table->active_version_id === (string) $version->getId();
        if (!$active && !$this->callerCan('decisions_make_inactive')) {
            abort(403, 'version_not_allowed');
        }

        return $version;
    }

    /**
     * Whether the caller, a member user or an API key of the application,
     * holds a scope.
     *
     * @param  string $scope
     * @return bool
     */
    private function callerCan($scope)
    {
        $method = 'can' . ucfirst($scope);
        $guard = \Auth::guard();
        if (!$guard->guest()) {
            $member = $guard->user()->getApplicationUser();

            return $member && $member->$method();
        }

        return app()->bound('applicationable.consumer') && app('applicationable.consumer')->$method();
    }

    /**
     * Execute a Decision Requirement Graph (Flow) against the provided inputs.
     *
     * Same activation guard as tableCheck — applied once for the whole flow, not
     * per node — then delegates orchestration to the FlowEngine, which runs the
     * graph's tables in topological order and assembles the declared outputs.
     *
     * @param  Request     $request
     * @param  FlowEngine  $flowEngine
     * @param  Application $application  Resolved from the X-Application header.
     * @param  string      $id           MongoDB ObjectID of the flow to run.
     * @return \Illuminate\Http\JsonResponse
     * @throws AdminIsNotActivatedException if the application has no active admin users.
     */
    public function flowCheck(Request $request, FlowEngine $flowEngine, Application $application, $id)
    {
        $this->assertActiveAdmin($application);

        return $this->response->json(
            $flowEngine->run($id, $request->all(), $application->_id, $application->getSettingsElem('show_meta', false))
        );
    }

    /**
     * Ensure the application has at least one active (email-verified) admin.
     *
     * Shared by tableCheck and flowCheck to block orphaned/unverified projects
     * from consuming the decision engine.
     *
     * @param  Application $application
     * @return void
     * @throws AdminIsNotActivatedException
     */
    private function assertActiveAdmin(Application $application)
    {
        $users = $application->users()->where('role', 'admin')->all();
        if (!$users) {
            throw new AdminIsNotActivatedException;
        }
        $userIds = [];
        foreach ($users as $user) {
            $userIds[] = strval($user['user_id']);
        }
        if (User::whereIn('_id', $userIds)->where('active', true)->count() == 0) {
            throw new AdminIsNotActivatedException;
        }
    }

    /**
     * Retrieve a single decision by ID using the consumer-safe representation.
     *
     * The per-rule breakdown is gated by the application's `show_meta` setting,
     * matching the live decision endpoint (tableCheck).
     *
     * @param  Application $application  Resolved from the X-Application header.
     * @param  string      $id           MongoDB ObjectID of the decision.
     * @return \Illuminate\Http\JsonResponse
     */
    public function decision(Application $application, $id)
    {
        return $this->response->json(
            $this->decisionsRepository->getConsumerDecision(
                $id,
                $application->getSettingsElem('show_meta', false)
            )
        );
    }
}
