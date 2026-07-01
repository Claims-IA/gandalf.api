<?php
/**
 * EventListener
 *
 * Central event subscriber that handles all domain events requiring third-party
 * analytics integrations. Subscribes to the three application events (Decisions\Make,
 * Users\Create, Users\Update) and routes them to the Intercom and Mixpanel services.
 * By using a subscriber instead of individual listeners, all event registrations are
 * co-located in the subscribe() method and the class is resolved once by the IoC
 * container rather than instantiated separately for each event type.
 *
 * @package App\Listeners
 */

namespace App\Listeners;

use App\Events\Users;
use App\Events\Decisions;

use App\Services\Intercom;
use App\Services\Mixpanel;
use Nebo15\LumenApplicationable\Models\Application;

class EventListener
{
    private $intercom;
    private $mixpanel;

    public function __construct(Intercom $intercom, Mixpanel $mixpanel)
    {
        $this->intercom = $intercom;
        $this->mixpanel = $mixpanel;
    }

    /**
     * Handle a Decisions\Make event.
     *
     * Looks up the admin users of the application that produced the decision
     * and notifies both Intercom (via event) and Mixpanel (via track + increment)
     * attributing the event to those users.
     *
     * @param  Decisions\Make $event
     * @return void
     */
    public function decisionMake(Decisions\Make $event)
    {
        // Fetch only admin users of the application to which this decision belongs
        $apps = Application::where('_id', $event->decision->application)
            ->where('users.role', 'admin')
            ->get(['users.user_id', 'users.role']);
        $userIds = [];
        foreach ($apps as $app) {
            foreach ($app->users as $user) {
                // Double-check the role filter since MongoDB may return all users
                if ($user->role == 'admin') {
                    $userIds[] = strval($user->user_id);
                }
            }
        }
        $this->intercom->decisionMake($event->decision, $userIds);
        $this->mixpanel->decisionMake($event->decision, $userIds);
    }

    /**
     * Handle a Users\Create event.
     *
     * Syncs the new user to both Mixpanel (track event) and Intercom (profile upsert).
     *
     * @param  Users\Create $event
     * @return void
     */
    public function userCreate(Users\Create $event)
    {
        $this->mixpanel->userCreate($event->user);
        $this->intercom->userCreateOrUpdate($event->user);
    }

    /**
     * Handle a Users\Update event.
     *
     * Syncs the updated user profile to Mixpanel (track event) and Intercom (profile upsert).
     *
     * @param  Users\Update $event
     * @return void
     */
    public function userUpdate(Users\Update $event)
    {
        $this->mixpanel->userUpdate($event->user);
        $this->intercom->userCreateOrUpdate($event->user);
    }

    /**
     * Register this class as an event subscriber.
     *
     * Maps each event class to the corresponding handler method on this listener.
     * Called automatically by the Lumen event dispatcher when it processes the
     * $subscribe array in EventServiceProvider.
     *
     * @param  \Illuminate\Events\Dispatcher $events
     * @return void
     */
    public function subscribe($events)
    {
        $events->listen('App\Events\Decisions\Make', 'App\Listeners\EventListener@decisionMake');
        $events->listen('App\Events\Users\Create', 'App\Listeners\EventListener@userCreate');
        $events->listen('App\Events\Users\Update', 'App\Listeners\EventListener@userUpdate');
    }
}
