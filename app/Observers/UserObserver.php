<?php
/**
 * UserObserver
 *
 * Eloquent model observer for the User model. Active hooks:
 * - 'creating': auto-generates a username from the email prefix when none is provided,
 *   and activates the user immediately when ACTIVATE_ALL_USERS=true (useful for dev).
 * - 'created': sends the welcome/email-verification email via the Mail service.
 * - 'saving': hashes the password with bcrypt whenever it has been changed, ensuring
 *   the raw password is never written to MongoDB.
 *
 * @package App\Observers
 */

namespace App\Observers;

use App\Models\User;
use App\Services\Mail;

class UserObserver
{
    /**
     * Before a new user is inserted into MongoDB.
     *
     * Auto-generates a username from the email prefix when the caller did not
     * supply one. Also activates the user immediately when ACTIVATE_ALL_USERS is
     * true, bypassing the email verification flow (for development/testing).
     *
     * @param  User $user
     * @return void
     */
    public function creating(User $user)
    {
        // Auto-derive username from email prefix when not explicitly provided
        if (!$user->username) {
            if ($user->temporary_email) {
                list($user->username) = explode('@', $user->temporary_email);
            }
        }
        // In environments where all users should be pre-activated, skip email verification
        if (true === env('ACTIVATE_ALL_USERS')) {
            $user->active = true;
        }
    }

    /**
     * After a new user has been saved to MongoDB.
     *
     * Dispatches the welcome/email-verification email using the Mail service.
     * Called only on first creation, not on updates.
     *
     * @param  User $user
     * @return void
     */
    public function created(User $user)
    {
        // Only send the verification email when a verify_email token was actually
        // issued. Users created already-active (e.g. the ACTIVATE_ALL_USERS bypass
        // or an admin-confirmed invitation) have no token, and calling
        // getVerifyEmailToken()['token'] on the resulting false would fatal.
        $token = $user->getVerifyEmailToken();
        if (!$token || empty($user->temporary_email)) {
            return;
        }

        /**
         * @var Mail $mail
         */
        $mail = app('\App\Services\Mail');
        $mail->sendEmailConfirmation($user->temporary_email, $token['token'], $user->username);
    }

    public function updating(User $user)
    {
    }

    public function updated(User $user)
    {
    }

    /**
     * Before any save (create or update).
     *
     * Hashes the password with bcrypt whenever the password attribute has changed.
     * The isDirty() check prevents re-hashing an already-hashed value on saves that
     * only modify other fields.
     *
     * @param  User $user
     * @return void
     */
    public function saving(User $user)
    {
        if ($user->isDirty('password')) {
            $user->password = $user->getPasswordHasher()->make($user->password);
        }
    }

    public function saved(User $user)
    {
    }

    public function deleting(User $user)
    {
    }

    public function deleted(User $user)
    {
    }

    public function restoring(User $user)
    {
    }

    public function restored(User $user)
    {
    }
}
