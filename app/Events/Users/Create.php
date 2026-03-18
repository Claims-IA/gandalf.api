<?php
/**
 * Users\Create Event
 *
 * Fired by UsersRepository::createOrUpdate() when a new user is created. Carries
 * the newly persisted User model to the EventListener which forwards the data to
 * Intercom (user profile sync) and Mixpanel (user-create track event).
 *
 * @package App\Events\Users
 */

namespace App\Events\Users;

use App\Models\User;

class Create
{
    public $user;

    public function __construct(User $user)
    {
        $this->user = $user;
    }
}
