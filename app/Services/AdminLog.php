<?php
/**
 * AdminLog
 *
 * Journal of the superadmins' actions (ADMIN.md), in the 'admin_log' collection.
 * SuperadminAuditMiddleware records every API request a superadmin makes once
 * its response is known; a controller adds what the request did with note()
 * (the id of the application it created, for instance), merged into that entry.
 * The `gandalf:superadmin` command records grants and revocations.
 *
 * Bound as a singleton: the notes belong to the current request.
 *
 * @package App\Services
 */

namespace App\Services;

use App\Models\AdminLogEntry;
use App\Models\User;

class AdminLog
{
    /**
     * Details noted by the controllers for the current request.
     *
     * @var array
     */
    private $details = [];

    /**
     * Add details to the entry of the current request.
     *
     * @param  array $details
     * @return void
     */
    public function note(array $details)
    {
        $this->details = array_merge($this->details, $details);
    }

    /**
     * Record a superadmin's request, with the details noted for it.
     *
     * @param  User        $user
     * @param  string      $method          As routed.
     * @param  string      $path            As routed.
     * @param  string|null $applicationId   The application of X-Application, if any.
     * @param  int         $status          HTTP status of the response.
     * @param  string|null $ip
     * @return AdminLogEntry
     */
    public function recordRequest(User $user, $method, $path, $applicationId, $status, $ip)
    {
        $details = $this->details;
        $this->details = [];

        return $this->write([
            'user_id' => (string) $user->getId(),
            'email' => $user->email,
            'action' => 'request',
            'method' => $method,
            'path' => $path,
            'application_id' => $applicationId,
            'status' => (int) $status,
            'ip' => $ip,
            'details' => $details,
        ]);
    }

    /**
     * Record a grant or a revocation made with the `gandalf:superadmin` command.
     *
     * @param  string $action  'superadmin.grant' or 'superadmin.revoke'.
     * @param  User   $target
     * @return AdminLogEntry
     */
    public function recordCommand($action, User $target)
    {
        return $this->write([
            'user_id' => null,
            'email' => null,
            'action' => $action,
            'details' => [
                'user_id' => (string) $target->getId(),
                'email' => $target->email,
            ],
        ]);
    }

    /**
     * @param  array $attributes
     * @return AdminLogEntry
     */
    protected function write(array $attributes)
    {
        $entry = new AdminLogEntry;
        $entry->fill($attributes)->save();

        return $entry;
    }
}
