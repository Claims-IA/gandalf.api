<?php

use Illuminate\Database\Migrations\Migration;

/**
 * Table versions, batch 2 (VERSIONS.md): publishing and activating a version are
 * two new user scopes, tables_publish and tables_activate. A new application's
 * owner gets every scope of config/applicationable.php; existing application
 * admins get these two here, or nobody could publish nor activate. Other users
 * get them from an admin: those who publish are not necessarily those who go
 * to production.
 *
 * Idempotent. Raw collections, as in 2016_07_04_101145_NewScopes.
 */
class TableVersionScopes extends Migration
{
    const SCOPES = ['tables_publish', 'tables_activate'];

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
