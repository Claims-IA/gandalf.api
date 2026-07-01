<?php
/**
 * UsersRepository
 *
 * Provides data access for User documents in MongoDB. Extends AbstractRepository
 * for standard CRUD and adds a createOrUpdate method that handles the email
 * verification flow: when an email is provided and ACTIVATE_ALL_USERS is false,
 * the address is stored in temporary_email and a verify-email token is generated.
 * After saving, a Create or Update event is fired so Intercom and Mixpanel are
 * notified of the change.
 *
 * @package App\Repositories
 */

namespace App\Repositories;

use App\Models\User;
use App\Events\Users\Create;
use App\Events\Users\Update;
use Nebo15\REST\AbstractRepository;
use Nebo15\LumenApplicationable\ApplicationableHelper;
use Nebo15\LumenApplicationable\Contracts\Applicationable;

/**
 * Class UsersRepository
 * @package App\Repositories
 * @method User getModel()
 */
class UsersRepository extends AbstractRepository
{
    protected $modelClassName = 'App\Models\User';
    protected $observerClassName = 'App\Observers\UserObserver';


    /**
     * Create a new user or update an existing one.
     *
     * When creating, if ACTIVATE_ALL_USERS is false (the default) the provided
     * email is stored in temporary_email and a verification token is generated;
     * the canonical email field remains empty until verifyEmail() is called. When
     * updating with a new email, the same flow applies but the old email remains
     * in the email field until the new one is confirmed.
     *
     * Fires a Create or Update event after saving so the EventListener can sync
     * the user to Intercom and Mixpanel.
     *
     * @param  array       $values  Validated user attributes to apply.
     * @param  string|null $id      MongoDB ObjectID of user to update, or null to create.
     * @return User
     */
    public function createOrUpdate($values, $id = null)
    {
        /** @var User $user */
        $user = $id ? $this->read($id) : $this->getModel()->newInstance();

        // Add the current application context if the User model implements Applicationable
        if ($user instanceof Applicationable) {
            ApplicationableHelper::addApplication($user);
        }

        // Email verification flow: store new address as temporary until confirmed
        if (array_key_exists('email', $values) and !env('ACTIVATE_ALL_USERS')) {
            $values['temporary_email'] = $values['email'];
            if ($id) {
                // During update keep the old confirmed email in place until verification completes
                unset($values['email']);
            }
            $user->createVerifyEmailToken();
        }

        $user->fill($values)->save();

        // Notify analytics/CRM services of the user lifecycle event
        \Event::fire($id ? new Update($user) : new Create($user));

        return $user;
    }
}
