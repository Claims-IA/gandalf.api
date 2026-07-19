<?php
/**
 * CopyMoveGuard
 *
 * Shared authorisation + target-application validation for the cross-project
 * copy/move actions on tables and flows. Included by TablesController and
 * FlowsController, which both extend Nebo15\REST\AbstractController and therefore
 * expose $this->request and $this->response.
 *
 * The single entry point guardCopyMove() returns a ready-to-send JsonResponse
 * describing the first failed check, or null when the operation is allowed:
 *   - 403 when the caller is not an admin of the SOURCE application;
 *   - 422 when the target application is the source application (no-op move);
 *   - 404 when the target application does not exist;
 *   - 403 when the caller is not a member of the TARGET application.
 *
 * The source table/flow itself is already scoped to the current application by
 * AbstractRepository::read (via the Applicationable contract), so ownership of
 * the source needs no extra check here.
 *
 * @package App\Http\Controllers\Concerns
 */

namespace App\Http\Controllers\Concerns;

use Nebo15\LumenApplicationable\ApplicationableHelper;
use Nebo15\LumenApplicationable\Models\Application;

trait CopyMoveGuard
{
    /**
     * Validate a copy/move request to $targetProjectId.
     *
     * @param  string $targetProjectId  The destination application id.
     * @return \Symfony\Component\HttpFoundation\Response|null  Error response, or null when allowed.
     */
    protected function guardCopyMove($targetProjectId)
    {
        // Only a project admin of the source application may copy/move.
        $currentUser = $this->request->user()->getApplicationUser();
        if (!$currentUser || !$currentUser->isAdmin()) {
            return $this->response->json(
                ['message' => 'Only a project admin can copy or move tables and flows.'],
                403
            );
        }

        // Copying/moving to the same application is a no-op and almost certainly a mistake.
        if ((string) $targetProjectId === (string) ApplicationableHelper::getApplicationId()) {
            return $this->response->json(
                ['message' => 'Source and target applications are the same.'],
                422
            );
        }

        // The target application must exist.
        $targetApp = Application::find($targetProjectId);
        if (!$targetApp) {
            return $this->response->json(
                ['message' => 'Target application not found.'],
                404
            );
        }

        // The caller must also be a member of the target application to write into it.
        $callerId = $this->request->user()->getId();
        if (!$targetApp->getUser($callerId)) {
            return $this->response->json(
                ['message' => 'You are not a member of the target application.'],
                403
            );
        }

        return null;
    }
}
