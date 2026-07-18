<?php
/**
 * FlowObserver
 *
 * Eloquent model observer for the Flow model, mirroring TableObserver. The
 * 'saved' hook writes a changelog snapshot via Nebo15/Changelog after every
 * successful save (create or update), recording the full flow state and the
 * authenticated user's username. This powers the audit trail and rollback
 * exposed through ChangelogController under changelog/flows/{id}.
 *
 * @package App\Observers
 */

namespace App\Observers;

use Auth;
use App\Models\Flow;
use Nebo15\Changelog\Changelog;

class FlowObserver
{
    public function saved(Flow $flow)
    {
        /** @var \App\Models\User|null $user */
        $user = Auth::guard()->user();
        // Skip the snapshot when there is no authenticated user (e.g. console
        // or system context): the changelog author is required, and a flow
        // saved outside a user request has no meaningful author to record.
        if ($user === null) {
            return;
        }
        Changelog::createFromModel($flow, $user->username);
    }
}
