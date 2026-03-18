<?php
/**
 * TableObserver
 *
 * Eloquent model observer for the Table model. The primary active hook is 'saved',
 * which creates a changelog snapshot via the Nebo15/Changelog library after every
 * successful save (create or update). The changelog records the full table state and
 * the username of the authenticated user who made the change, enabling audit trails
 * and rollback functionality exposed through the ChangelogController.
 *
 * @package App\Observers
 */

namespace App\Observers;

use Auth;
use \App\Models\Table;
use Nebo15\Changelog\Changelog;

class TableObserver
{
    public function creating(Table $table)
    {
    }

    public function created(Table $table)
    {
    }

    public function updating(Table $table)
    {
    }

    public function updated(Table $table)
    {
    }

    public function saving(Table $table)
    {
    }

    public function saved(Table $table)
    {
        /** @var \App\Models\User $user */
        $user = Auth::guard()->user();
        Changelog::createFromModel($table, $user->username);
    }

    public function deleting(Table $table)
    {
    }

    public function deleted(Table $table)
    {
    }

    public function restoring(Table $table)
    {
    }

    public function restored(Table $table)
    {
    }
}
