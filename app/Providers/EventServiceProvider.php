<?php
/**
 * EventServiceProvider
 *
 * Registers the application's event subscribers with the Lumen event dispatcher.
 * Uses the $subscribe array (instead of the $listen array) so that EventListener
 * can declare all of its own event-to-method bindings via its subscribe() method,
 * keeping event registration co-located with the handler logic rather than split
 * across two files.
 *
 * @package App\Providers
 */

namespace App\Providers;

use Laravel\Lumen\Providers\EventServiceProvider as ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event listener mappings for the application.
     *
     * @var array
     */
    protected $subscribe = [
        'App\Listeners\EventListener',
    ];
}
