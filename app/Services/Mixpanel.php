<?php
/**
 * Mixpanel Service
 *
 * Integrates the Gandalf API with Mixpanel for product analytics. Extends BaseEvents
 * and implements the decisionMake() contract to increment per-user decision counters.
 * Also provides userCreate() and userUpdate() methods that fire track events and sync
 * user profile properties. All methods are no-ops when MIXPANEL_ENABLED is false.
 * The client IP is forwarded to Mixpanel so geographic analytics are accurate.
 *
 * @package App\Services
 */

namespace App\Services;

use App\Models\User;
use App\Models\Decision;

/**
 * Class Mixpanel
 * @package MBank\Service
 * @property \Nebo15\LumenMixpanel\Mixpanel $mixpanel
 *
 * $this->mixpanel->addTrackEvent('event_name', ["landing page" => "/specials"]);
 * $this->mixpanel->addUserEvent('set', '', ['$email'=>'email@example.com', '$first_name'=>'test user']);
 * $this->mixpanel->addUserEvent('increment', 'login_count', 1);
 * $this->mixpanel->addUserEvent('append', 'custom_property', ['custom_value1', 'custom_value2']);
 * $this->mixpanel->addUserEvent('trackCharge', '', 9.99);
 * $this->mixpanel->addUserEvent('trackCharge', '', [9.99, strtotime('NOW')]);
 */
class Mixpanel extends BaseEvents
{
    private $mixpanel;

    public function __construct(\Nebo15\LumenMixpanel\Mixpanel $mixpanel)
    {
        $this->mixpanel = $mixpanel;
    }

    /**
     * Record a user-create event in Mixpanel.
     *
     * @param  User $user
     * @return void
     */
    public function userCreate(User $user)
    {
        $this->userCreateOrUpdate($user, 'user-create');
    }

    /**
     * Record a user-update event in Mixpanel.
     *
     * @param  User $user
     * @return void
     */
    public function userUpdate(User $user)
    {
        $this->userCreateOrUpdate($user, 'user-update');
    }

    /**
     * Increment the Decisions count and update last-decision timestamp for each admin user.
     *
     * Called whenever a decision is evaluated. The client IP is forwarded to Mixpanel
     * so geographic distribution of decisions can be analysed.
     *
     * @param  Decision $decision  The persisted decision record.
     * @param  array    $user_ids  Admin user IDs to attribute the event to.
     * @return void|false
     */
    public function decisionMake(Decision $decision, array $user_ids)
    {
        if (false == env('MIXPANEL_ENABLED')) {
            return false;
        }
        foreach ($user_ids as $id) {
            $this->mixpanel->setIdentity($id);
            $this->mixpanel->setIp($this->getIp());
            // Update the last-decision timestamp on the user profile
            $this->mixpanel->addUserEvent('set', '', ['Last Decision created_at' => time()]);
            // Increment the lifetime decisions counter on the user profile
            $this->mixpanel->addUserEvent('increment', 'Decisions count', 1);
        }
    }

    /**
     * Send a track event and sync the user profile to Mixpanel.
     *
     * Shared implementation for both userCreate and userUpdate so both flows
     * emit the same set of profile properties ($email, $username, etc.).
     *
     * @param  User   $user  The user model to sync.
     * @param  string $type  Event name ('user-create' or 'user-update').
     * @return void|false
     */
    protected function userCreateOrUpdate(User $user, $type)
    {
        if (false == env('MIXPANEL_ENABLED')) {
            return false;
        }
        $this->mixpanel->setIdentity($user->getId());
        $this->mixpanel->setIp($this->getIp());
        // Fire a named track event for the user lifecycle action
        $this->mixpanel->addTrackEvent(
            $type,
            [
                'created_at' => time(),
                'username' => $user->username,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'email' => $user->email,
            ]
        );
        // Also update the Mixpanel user profile properties to keep them current
        $this->mixpanel->addUserEvent('set', $type, [
            '$email' => $user->email,
            '$username' => $user->username,
            '$first_name' => $user->first_name,
            '$last_name' => $user->last_name,
        ]);
    }
}
