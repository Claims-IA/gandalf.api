<?php
/**
 * ObserverServiceProvider
 *
 * Attaches Eloquent model observers to the Table, Flow, User, and Invitation
 * models during application boot. Observers are used in preference to inline events
 * to keep side-effect logic (sending emails, writing changelog entries, hashing
 * passwords) in dedicated classes rather than in the models or repositories
 * themselves.
 *
 * @package App\Providers
 */

namespace App\Providers;

use App\Models\Flow;
use App\Models\Invitation;
use App\Models\Table;
use App\Models\User;
use App\Observers\FlowObserver;
use App\Observers\InvitationsObserver;
use App\Observers\TableObserver;
use App\Observers\UserObserver;
use Illuminate\Support\ServiceProvider;

class ObserverServiceProvider extends ServiceProvider
{

    /**
     * Bootstrap any necessary services.
     *
     * @return void
     */
    public function boot()
    {
        Table::observe(new TableObserver);
        Flow::observe(new FlowObserver);
        User::observe(new UserObserver);
        Invitation::observe(new InvitationsObserver);
    }

    public function register()
    {
    }
}
