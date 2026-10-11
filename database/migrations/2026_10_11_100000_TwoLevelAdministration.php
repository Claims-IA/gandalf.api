<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * Two-level administration (ADMIN.md):
 * - deleting an application is reserved to superadmins: the project_delete user
 *   scope no longer grants anything and leaves the members and the pending
 *   invitations (down gives it back to the application admins);
 * - the admin log is read most recent first, by application or by superadmin.
 *
 * Nobody becomes superadmin here: `php artisan gandalf:superadmin <email>`.
 *
 * Idempotent. Raw collections, as in 2016_07_04_101145_NewScopes.
 */
class TwoLevelAdministration extends Migration
{
    const SCOPE = 'project_delete';

    public function up()
    {
        $this->updateMembers(function (array $user) {
            $user['scope'] = array_values(array_diff($user['scope'] ?? [], [self::SCOPE]));

            return $user;
        });
        \DB::collection('invitations')->where('scope', self::SCOPE)->pull('scope', self::SCOPE);

        Schema::table('admin_log', function (Blueprint $collection) {
            $collection->index(['created_at']);
            $collection->index(['application_id', 'created_at']);
            $collection->index(['user_id', 'created_at']);
        });
    }

    public function down()
    {
        $this->updateMembers(function (array $user) {
            if (($user['role'] ?? null) === 'admin') {
                $user['scope'] = array_values(array_unique(array_merge($user['scope'] ?? [], [self::SCOPE])));
            }

            return $user;
        });

        // By name, in an associative array: a list of columns only names
        // single-column indexes (each column gets "_1").
        Schema::table('admin_log', function (Blueprint $collection) {
            $collection->dropIndex([
                'created_at' => 'created_at_1',
                'application_id' => 'application_id_1_created_at_1',
                'user_id' => 'user_id_1_created_at_1',
            ]);
        });
    }

    private function updateMembers(callable $update)
    {
        foreach (\DB::collection('applications')->get() as $application) {
            if (empty($application['users'])) {
                continue;
            }
            $users = array_map($update, (array) $application['users']);
            \DB::collection('applications')->where('_id', (string) $application['_id'])->update([
                '$set' => ['users' => $users],
            ]);
        }
    }
}
