<?php
/**
 * Superadmin Command
 *
 * Artisan command (gandalf:superadmin) that grants or revokes the superadmin
 * role (ADMIN.md), the only way to do so: no API route sets it. Each grant or
 * revocation is recorded in the admin log.
 *
 *   php artisan gandalf:superadmin someone@claims-ia.fr            grant
 *   php artisan gandalf:superadmin someone@claims-ia.fr --remove   revoke
 *   php artisan gandalf:superadmin --list                          list
 *
 * @package App\Console\Commands
 */

namespace App\Console\Commands;

use App\Models\User;
use App\Services\AdminLog;
use Illuminate\Console\Command;

class Superadmin extends Command
{
    protected $signature = 'gandalf:superadmin {email? : Email of the account} {--remove : Revoke the role} {--list : List the superadmins}';

    protected $description = 'Grant, revoke or list the superadmin role (reserved to Claims IA)';

    /**
     * @param  AdminLog $log
     * @return int  0 on success, 1 when the account is unknown or no email is given.
     */
    public function handle(AdminLog $log)
    {
        if ($this->option('list')) {
            foreach (User::where('superadmin', true)->orderBy('email')->get() as $user) {
                $this->line($user->email . ' (' . $user->username . ')');
            }

            return 0;
        }

        $email = (string) $this->argument('email');
        if ($email === '') {
            $this->error('Give the email of the account, or --list.');

            return 1;
        }
        $user = User::where('email', mb_strtolower($email))->first() ?: User::where('email', $email)->first();
        if (!$user) {
            $this->error("No account with the email $email.");

            return 1;
        }

        $grant = !$this->option('remove');
        if ($user->isSuperadmin() === $grant) {
            $this->info($email . ($grant ? ' is already a superadmin.' : ' is not a superadmin.'));

            return 0;
        }
        if ($grant) {
            $user->setAttribute('superadmin', true);
            $user->save();
        } else {
            $user->drop('superadmin');
        }
        $log->recordCommand($grant ? 'superadmin.grant' : 'superadmin.revoke', $user);
        $this->info($email . ($grant ? ' is now a superadmin.' : ' is no longer a superadmin.'));

        return 0;
    }
}
