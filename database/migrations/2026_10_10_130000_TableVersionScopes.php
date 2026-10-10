<?php

use Illuminate\Database\Migrations\Migration;

/**
 * Table versions, batches 2 and 3 (VERSIONS.md): publishing and activating a
 * version are two new user scopes, tables_publish and tables_activate, and
 * running an inactive version (route v2) is decisions_make_inactive. A new
 * application's owner gets every scope of config/applicationable.php; existing
 * application admins get these three here, or nobody could publish nor
 * activate, nor give an API key decisions_make_inactive (a user only gives a
 * key the scopes they hold). Other users get them from an admin: those who
 * publish are not necessarily those who go to production.
 *
 * Idempotent. Raw collections, as in 2016_07_04_101145_NewScopes.
 */
class TableVersionScopes extends Migration
{
    const SCOPES = ['tables_publish', 'tables_activate', 'decisions_make_inactive'];

    public function up()
    {
        $this->updateUserScopes(function (array $user) {
            if (($user['role'] ?? null) !== 'admin') {
                return $user;
            }
            $user['scope'] = array_values(array_unique(array_merge($user['scope'] ?? [], self::SCOPES)));

            return $user;
        });
    }

    public function down()
    {
        $this->updateUserScopes(function (array $user) {
            $user['scope'] = array_values(array_diff($user['scope'] ?? [], self::SCOPES));

            return $user;
        });
    }

    private function updateUserScopes(callable $update)
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
