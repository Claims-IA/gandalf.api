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
 * @package App\Http\Controllers
 */

namespace App\Http\Controllers;

use App\Exceptions\AdminIsNotActivatedException;
use App\Models\User;
use App\Services\Scoring;
use App\Services\FlowEngine;
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
    public function tableCheck(Request $request, Scoring $scoring, Application $application, $id)
    {
        $this->assertActiveAdmin($application);

        // The show_meta application setting controls whether rule details are included in the response
        return $this->response->json(
            $scoring->check($id, $request->all(), $application->_id, $application->getSettingsElem('show_meta', false))
        );
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
