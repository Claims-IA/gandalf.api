<?php
/**
 * InvitationsObserver
 *
 * Eloquent model observer for the Invitation model. The primary active hook is
 * 'created', which sends the invitation email via the Mail service when a new
 * Invitation document is persisted. Note: the UsersController::invite() method
 * also calls sendEmailInvitation() directly, so there is currently a duplicate
 * send; the observer hook is retained for cases where invitations are created
 * outside the controller context.
 *
 * @package App\Observers
 */

namespace App\Observers;

use App\Models\Invitation;

class InvitationsObserver
{
    public function creating(Invitation $invitation)
    {
    }

    public function created(Invitation $invitation)
    {
        /**
         * @var Mail $mail
         */
        $mail = app('\App\Services\Mail');
        $mail->sendEmailInvitation($invitation);
    }

    public function updating(Invitation $invitation)
    {
    }

    public function updated(Invitation $invitation)
    {
    }

    public function saving(Invitation $invitation)
    {
    }

    public function saved(Invitation $invitation)
    {
    }

    public function deleting(Invitation $invitation)
    {
    }

    public function deleted(Invitation $invitation)
    {
    }

    public function restoring(Invitation $invitation)
    {
    }

    public function restored(Invitation $invitation)
    {
    }
}
