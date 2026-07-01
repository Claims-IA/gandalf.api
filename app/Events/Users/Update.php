<?php
/**
 * Users\Update Event
 *
 * Fired by UsersRepository::createOrUpdate() when an existing user's profile is
 * updated. Carries the updated User model to the EventListener which syncs the
 * changes to Intercom and fires a user-update track event in Mixpanel.
 *
 * @package App\Events\Users
 */

namespace App\Events\Users;

use App\Models\User;

class Update
{
    public $user;

    public function __construct(User $user)
    {
        $this->user = $user;
    }
}
