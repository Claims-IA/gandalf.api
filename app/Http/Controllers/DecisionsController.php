<?php
/**
 * DecisionsController
 *
 * Exposes read-only admin endpoints for Decision records stored in MongoDB. An
 * admin user can list all decisions for their application (filtered by table or
 * variant), fetch a single decision, or attach arbitrary key-value metadata to
 * a decision for later reference (e.g. linking a payment ID to a fraud-check
 * decision). Write operations (creating decisions) are handled by ConsumerController
 * via the Scoring service.
 *
 * @package App\Http\Controllers
 */

namespace App\Http\Controllers;

use Nebo15\REST\AbstractController;

/**
 * Class DecisionsController
 * @package App\Http\Controllers
 * @method \App\Repositories\DecisionsRepository getRepository()
 */
class DecisionsController extends AbstractController
{
    protected $repositoryClassName = 'App\Repositories\DecisionsRepository';

    protected $validationRules = [
        'create' => [],
        'update' => [],
        'updateMeta' => [
            'meta' => 'required|array',
        ],
    ];

    /**
     * Return a paginated list of decisions for the current application.
     *
     * Accepts optional query parameters: size (page size), table_id (filter by
     * table), and variant_id (filter by variant). Delegates ordering and pagination
     * to the DecisionsRepository which always scopes results to the current application.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function readList()
    {
        return $this->response->jsonPaginator(
            $this->getRepository()->getDecisions(
                $this->request->input('size'),
                $this->request->input('table_id'),
                $this->request->input('variant_id')
            )
        );
    }

    /**
     * Attach or replace the metadata bag on a decision.
     *
     * Validates each key (alpha_dash, max 100 chars) and value (max 500 chars)
     * and limits total keys to 24. Useful for correlating a decision with an
     * external record (order ID, payment reference, etc.) after the fact.
     *
     * @param  string $id  MongoDB ObjectID of the decision to update.
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateMeta($id)
    {
        $this->validateRoute();

        return $this->response->json($this->getRepository()->updateMeta($id, $this->request->input('meta')));
    }
}
