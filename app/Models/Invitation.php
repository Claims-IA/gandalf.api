<?php
/**
 * Invitation Model
 *
 * Represents a pending invitation to join an application. When an admin invites
 * another user by email, an Invitation document is created in MongoDB with the
 * target email, the project reference (id + title), the role to assign, and the
 * permission scope to grant. If the invitee already has an account they are added
 * to the application immediately; if not, the invitation is processed during their
 * registration in UsersController::create().
 *
 * @package App\Models
 */
namespace App\Models;

class Invitation extends Base
{

    protected $attributes = [
        'email' => '',
        'project' => [],
        'role' => '',
        'scope' => [],
    ];

    protected $fillable = ['email', 'project', 'role', 'scope'];
}
