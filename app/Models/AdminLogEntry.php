<?php
/**
 * AdminLogEntry Model
 *
 * One action of a superadmin (ADMIN.md), stored in the MongoDB 'admin_log'
 * collection and never edited:
 *   - an API request: method, routed path, application (X-Application), HTTP
 *     status, IP, plus the details the controller noted (the application created
 *     or deleted, for instance);
 *   - a `gandalf:superadmin` command: no method nor path, `action` names it.
 *
 * Request bodies are not kept: they hold the applications' data.
 *
 * @package App\Models
 * @property string|null $user_id         The superadmin; null for a command.
 * @property string|null $email           Their email when the action was made.
 * @property string      $action          'request', 'superadmin.grant' or 'superadmin.revoke'.
 * @property string|null $method
 * @property string|null $path
 * @property string|null $application_id
 * @property int|null    $status
 * @property string|null $ip
 * @property array       $details
 */

namespace App\Models;

class AdminLogEntry extends Base
{
    protected $collection = 'admin_log';

    protected $perPage = 50;

    protected $attributes = [
        'details' => [],
    ];

    protected $fillable = [
        'user_id',
        'email',
        'action',
        'method',
        'path',
        'application_id',
        'status',
        'ip',
        'details',
    ];

    protected $visible = [
        '_id',
        'user_id',
        'email',
        'action',
        'method',
        'path',
        'application_id',
        'status',
        'ip',
        'details',
        self::CREATED_AT,
    ];
}
