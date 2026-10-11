<?php
/**
 * ProjectsController
 *
 * Handles project (application) level operations that go beyond user management.
 * Provides two endpoints: one to permanently delete an application and all of its
 * associated decision tables, and one to export the application's data as a
 * compressed archive (tables, decisions, changelogs) using mongoexport.
 *
 * @package App\Http\Controllers
 */

namespace App\Http\Controllers;

use App\Models\Table;
use App\Models\TableVersion;
use App\Models\FlowVersion;
use App\Models\User;
use App\Models\Invitation;
use App\Services\Accounts;
use App\Services\AdminLog;
use App\Services\DbTransfer;
use App\Services\VersionCalls;
use App\Services\Mail;
use App\Exceptions\IdNotFoundException;
use MongoDB\BSON\ObjectID;
use Nebo15\REST\AbstractController;
use Nebo15\LumenApplicationable\Models\Application;

class ProjectsController extends AbstractController
{
    protected $repositoryClassName = '';

    protected $validationRules = [
        'confirmCollaborator' => [
            'user_id' => 'required_without:email|string',
            'email'   => 'required_without:user_id|email',
        ],
        'cancelInvitation' => [
            'email' => 'required|email',
        ],
        'resendInvitation' => [
            'email' => 'required|email',
        ],
        'deleteAccount' => [
            'user_id' => 'required|string',
        ],
        // scope: completed in createAccount() with the caller's own scopes
        'createAccount' => [
            'email' => 'required|email|unique:users,email|unique:users,temporary_email',
            'username' => 'sometimes|required|between:2,32|username|unique:users,username',
            'role' => 'required|string',
        ],
    ];

    /**
     * Permanently delete the application and all of its decision tables.
     * Reserved to superadmins (route middleware 'superadmin', ADMIN.md).
     *
     * Uses a MongoDB $in query to delete every Table document associated with
     * the application before removing the application document itself. This is
     * a hard delete with no recovery path.
     *
     * @param  Application $application  The current application resolved from the X-Application header.
     * @return \Illuminate\Http\JsonResponse
     */
    public function deleteProject(Application $application)
    {
        app(AdminLog::class)->note(['title' => $application->title]);
        // Remove all decision tables that belong to this application first, with
        // their versions and the calls of its API keys (a query delete fires no
        // TableObserver::deleted)
        Table::where(['applications' => ['$in' => [$application->_id]]])->delete();
        TableVersion::where(['applications' => ['$in' => [$application->_id]]])->delete();
        FlowVersion::where(['applications' => ['$in' => [$application->_id]]])->delete();
        (new VersionCalls)->deleteForApplication($application->_id);
        // Then delete the application document itself
        $application->delete();

        return $this->response->json();
    }

    /**
     * Export all application data to a downloadable archive and return its URL.
     *
     * The DbTransfer service runs mongoexport for the tables, decisions, and
     * changelogs collections filtered by application ID, then packages the JSON
     * files into a .tar.gz stored in public/dump/. Returns the public download URL.
     *
     * @param  DbTransfer  $dbTransfer
     * @param  Application $application  The current application resolved from the X-Application header.
     * @return \Illuminate\Http\JsonResponse
     */
    public function export(DbTransfer $dbTransfer, Application $application)
    {
        return $this->response->json(['url' => $dbTransfer->export($application->_id)]);
    }

    /**
     * List the project's collaborators enriched with a confirmation status,
     * merged with any pending invitations that have not yet been accepted.
     *
     * Each returned entry carries a `status` field:
     *   - `active`  — a registered user whose account is confirmed (User.active = true)
     *   - `pending` — a registered user who has not confirmed their email (User.active = false)
     *   - `invited` — an invitation was sent but the invitee has not registered yet
     *                 (no User document exists; identified by email only)
     *
     * The embedded application-user records only store user_id/role/scope, so the
     * `active` flag is joined in from the main users collection here rather than in
     * the Applicationable package's toArray().
     *
     * @param  Application $application  The current application (X-Application header).
     * @return \Illuminate\Http\JsonResponse
     */
    public function collaborators(Application $application)
    {
        $collaborators = [];
        $memberEmails = [];

        // Registered collaborators embedded in the application document.
        foreach ($application->users as $member) {
            $user = User::find($member->user_id);
            $email = $user ? $user->email : null;
            // A pending user keeps its address in temporary_email until confirmed.
            if ($user && !$email) {
                $email = $user->temporary_email;
            }
            if ($email) {
                $memberEmails[] = strtolower($email);
            }

            $collaborators[] = [
                'user_id'  => (string) $member->user_id,
                'email'    => $email,
                'username' => $user ? $user->username : null,
                'role'     => $member->role,
                'scope'    => $member->scope,
                'status'   => ($user && $user->isActive()) ? 'active' : 'pending',
            ];
        }

        // Pending invitations for this project whose invitee never registered.
        // Filter on the embedded project._id in PHP: the MongoDB driver does not
        // reliably match nested keys via where('project._id', ...).
        $appId = (string) $application->_id;
        $invitations = Invitation::all()->filter(function ($invitation) use ($appId) {
            return isset($invitation->project['_id'])
                && (string) $invitation->project['_id'] === $appId;
        });
        foreach ($invitations as $invitation) {
            // Skip invitations already reflected as a member (accepted/registered).
            if (in_array(strtolower($invitation->email), $memberEmails, true)) {
                continue;
            }
            $collaborators[] = [
                'user_id'  => null,
                'email'    => $invitation->email,
                'username' => null,
                'role'     => $invitation->role,
                'scope'    => $invitation->scope,
                'status'   => 'invited',
            ];
        }

        return $this->response->json($collaborators);
    }

    /**
     * Confirm a collaborator's account on behalf of the invitee (admin action).
     *
     * This is an admin-only shortcut that bypasses the email-verification click.
     * Only a collaborator with role `admin` on the current project may call it.
     * Two cases are handled, mirroring the natural verification/registration flow:
     *
     *   1. Pending user (`user_id` or `email` of an existing User with active=false):
     *      replicate User::verifyEmail() — promote temporary_email -> email,
     *      set active=true, clear the verify_email token.
     *
     *   2. Pending invitation (`email` with no User yet): create the account with a
     *      randomly generated temporary password, activate it, add the user to the
     *      project with the invitation's role/scope, and remove the invitation.
     *      The temporary password is returned to the admin and, when email delivery
     *      is enabled, also emailed to the invitee, who must replace it at their
     *      first sign-in. `email_sent` says whether the email went out.
     *
     * @param  Application $application  The current application (X-Application header).
     * @param  Accounts    $accounts
     * @return \Illuminate\Http\JsonResponse
     * @throws IdNotFoundException  When no matching user or invitation is found.
     */
    public function confirmCollaborator(Application $application, Accounts $accounts)
    {
        // Authorisation: only a project admin may confirm another collaborator.
        if (!$this->requireAdmin()) {
            return $this->response->json(
                ['message' => 'Only a project admin can confirm collaborators.'],
                403
            );
        }

        $this->validateRoute();

        $userId = $this->request->input('user_id');
        $email = $this->request->input('email');

        // Resolve an existing user, either by id or email. A confirmed email
        // (email column) takes precedence; only fall back to temporary_email
        // (an unconfirmed, pending address) when no confirmed account matches.
        $user = null;
        if ($userId) {
            $user = User::find($userId);
        } elseif ($email) {
            $user = User::where('email', $email)->first()
                ?: User::where('temporary_email', $email)->first();
        }

        // Case 1 — existing user: activate it the same way verifyEmail() does.
        if ($user) {
            if (!$user->isActive()) {
                $user->confirmAccount()->save();
            }

            // The email may also have a pending invitation for THIS project (the
            // account was created elsewhere and never joined here). Consume it:
            // add the user to the project with the invited role/scope and delete
            // the invitation, mirroring how registration honours invitations.
            $resolvedEmail = $user->email ?: $user->temporary_email ?: $email;
            if ($resolvedEmail) {
                $invitation = $this->findInvitation($application, $resolvedEmail);
                if ($invitation) {
                    if (!$application->getUser((string) $user->_id)) {
                        $application->setUser([
                            'user_id' => (string) $user->_id,
                            'role'    => $invitation->role,
                            'scope'   => $invitation->scope,
                        ])->save();
                    }
                    $invitation->delete();
                }
            }

            return $this->response->json([
                'user_id' => (string) $user->_id,
                'email'   => $user->email,
                'status'  => 'active',
            ]);
        }

        // Case 2 — pending invitation: create + activate the account.
        if (!$email) {
            throw new IdNotFoundException('No user or invitation found to confirm.');
        }

        $invitation = $this->findInvitation($application, $email);
        if (!$invitation) {
            throw new IdNotFoundException('No user or invitation found to confirm.');
        }

        // Reject if an account already exists for this email — mirror the
        // uniqueness UsersController@create enforces, which this direct-create
        // path would otherwise bypass.
        if (User::where('email', $email)->first()
            || User::where('temporary_email', $email)->first()) {
            return $this->response->json(
                ['message' => 'An account already exists for this email.'],
                422
            );
        }

        // A temporary password the invitee must replace at their first sign-in
        $temporaryPassword = $accounts->temporaryPassword();

        $newUser = new User();
        $newUser->fill([
            'username' => $accounts->usernameFromEmail($email),
            'email'    => $email,
        ]);
        $newUser->setTemporaryPassword($temporaryPassword);
        // Created already-confirmed: this is an explicit admin confirmation.
        $newUser->active = true;
        $newUser->save();

        // Add the freshly created user to the project with the invited role/scope.
        if (!$application->getUser((string) $newUser->_id)) {
            $application->setUser([
                'user_id' => (string) $newUser->_id,
                'role'    => $invitation->role,
                'scope'   => $invitation->scope,
            ])->save();
        }

        // The invitation has been fulfilled.
        $invitation->delete();

        // Also emailed when sending works; always returned so the admin can
        // pass it on.
        $emailSent = $accounts->mailTemporaryPassword($newUser, $temporaryPassword);

        return $this->response->json([
            'user_id'            => (string) $newUser->_id,
            'email'              => $email,
            'status'             => 'active',
            'temporary_password' => $temporaryPassword,
            'email_sent'         => $emailSent,
        ]);
    }

    /**
     * Create an account directly in the current project (admin only).
     *
     * The account is created active, with a temporary password, and joins the
     * project with the given role and scopes, at once: no invitation, no email
     * to click. The temporary password is returned once, emailed too when
     * sending works (`email_sent`), and must be replaced at the first sign-in.
     *
     * Same rules as an invitation: the scopes are among the caller's and
     * include the required ones. Only an admin creates an account, as only an
     * admin confirms one. An email already used by an account is refused (422):
     * that account is added with POST /projects/users instead. A pending
     * invitation of this email to this project is consumed.
     *
     * @param  Application $application
     * @param  Accounts    $accounts
     * @param  AdminLog    $log  Names the account when a superadmin creates it.
     * @return \Illuminate\Http\JsonResponse
     */
    public function createAccount(Application $application, Accounts $accounts, AdminLog $log)
    {
        if (!$this->requireAdmin()) {
            return $this->response->json(
                ['message' => 'Only a project admin can create accounts.'],
                403
            );
        }
        $currentUser = $this->request->user()->getApplicationUser();
        $this->validationRules['createAccount']['scope'] =
            'required|array|required_scopes|in:' . join(',', $currentUser->scope);
        $this->validateRoute();

        $email = $this->request->input('email');
        $role = strtolower((string) $this->request->input('role')) === 'admin'
            ? 'admin'
            : $this->request->input('role');
        $temporaryPassword = $accounts->temporaryPassword();

        $user = new User();
        $user->fill([
            'username' => $this->request->input('username') ?: $accounts->usernameFromEmail($email),
            'email'    => $email,
        ]);
        $user->setTemporaryPassword($temporaryPassword);
        $user->active = true;
        $user->save();

        $application->setUser([
            'user_id' => (string) $user->_id,
            'role'    => $role,
            'scope'   => $this->request->input('scope'),
        ])->save();

        $invitation = $this->findInvitation($application, $email);
        if ($invitation) {
            $invitation->delete();
        }

        $log->note(['user_id' => (string) $user->_id, 'email' => $email, 'username' => $user->username]);
        $emailSent = $accounts->mailTemporaryPassword($user, $temporaryPassword);

        return $this->response->json([
            'user_id'            => (string) $user->_id,
            'username'           => $user->username,
            'email'              => $email,
            'status'             => 'active',
            'temporary_password' => $temporaryPassword,
            'email_sent'         => $emailSent,
        ], 201);
    }

    /**
     * Cancel a pending invitation (admin only).
     *
     * Removes the invitation identified by `email` on the current project. Only a
     * project admin may do this. Returns 404 when no such invitation exists.
     *
     * @param  Application $application
     * @return \Illuminate\Http\JsonResponse
     * @throws IdNotFoundException
     */
    public function cancelInvitation(Application $application)
    {
        if (!$this->requireAdmin()) {
            return $this->response->json(
                ['message' => 'Only a project admin can cancel invitations.'],
                403
            );
        }
        $this->validateRoute();

        $invitation = $this->findInvitation($application, $this->request->input('email'));
        if (!$invitation) {
            throw new IdNotFoundException('No pending invitation found for this email.');
        }
        $invitation->delete();

        return $this->response->json();
    }

    /**
     * Resend the invitation email for a pending invitation (admin only).
     *
     * Re-sends the invitation email for the invitation identified by `email` on
     * the current project. Only a project admin may do this. Returns 404 when no
     * such invitation exists.
     *
     * @param  Application $application
     * @param  Mail        $mail
     * @return \Illuminate\Http\JsonResponse
     * @throws IdNotFoundException
     */
    public function resendInvitation(Application $application, Mail $mail)
    {
        if (!$this->requireAdmin()) {
            return $this->response->json(
                ['message' => 'Only a project admin can resend invitations.'],
                403
            );
        }
        $this->validateRoute();

        $invitation = $this->findInvitation($application, $this->request->input('email'));
        if (!$invitation) {
            throw new IdNotFoundException('No pending invitation found for this email.');
        }
        $mail->sendEmailInvitation($invitation);

        return $this->response->json(['email' => $invitation->email]);
    }

    /**
     * Permanently delete a user account (admin only).
     *
     * Destructive and irreversible. Guarded on several conditions:
     *   - the caller must be a project admin;
     *   - the caller cannot delete their own account;
     *   - the target must exist, and must not be a superadmin;
     *   - the target must NOT be a member of any application other than the
     *     current one (409). This prevents orphaning the account elsewhere.
     *
     * When allowed, the user is removed from the current project and the account
     * document is deleted.
     *
     * @param  Application $application
     * @return \Illuminate\Http\JsonResponse
     * @throws IdNotFoundException
     */
    public function deleteAccount(Application $application)
    {
        if (!$this->requireAdmin()) {
            return $this->response->json(
                ['message' => 'Only a project admin can delete accounts.'],
                403
            );
        }
        $this->validateRoute();

        $userId = $this->request->input('user_id');

        // A user may not delete their own account through this endpoint.
        if ($userId === $this->request->user()->getId()) {
            return $this->response->json(
                ['message' => 'You cannot delete your own account.'],
                403
            );
        }

        $user = User::find($userId);
        if (!$user) {
            throw new IdNotFoundException('No account found for this id.');
        }

        // An application admin may be a client's user: a superadmin's account
        // is never theirs to delete (ADMIN.md).
        if ($user->isSuperadmin()) {
            return $this->response->json(
                ['message' => 'A superadmin account cannot be deleted here.'],
                403
            );
        }

        // Refuse if the account belongs to any OTHER application than this one.
        $currentAppId = (string) $application->_id;
        $otherApps = Application::where('users.user_id', new ObjectID($userId))
            ->get()
            ->filter(function ($app) use ($currentAppId) {
                return (string) $app->_id !== $currentAppId;
            });
        if ($otherApps->count() > 0) {
            return $this->response->json(
                [
                    'message' => 'This account is a member of other projects. Remove it from those first.',
                    'projects' => $otherApps->map(function ($app) {
                        return ['_id' => (string) $app->_id, 'title' => $app->title];
                    })->values(),
                ],
                409
            );
        }

        // Detach from the current project (if still a member) then delete.
        if ($application->getUser($userId)) {
            $application->deleteUser($userId)->save();
        }
        $user->delete();

        return $this->response->json();
    }

    /**
     * Whether the current user is an admin of the current project.
     *
     * @return bool
     */
    private function requireAdmin()
    {
        $currentUser = $this->request->user()->getApplicationUser();

        return $currentUser && $currentUser->isAdmin();
    }

    /**
     * Find the pending invitation for an email on the given project.
     *
     * Matches the embedded project._id in PHP, as the MongoDB driver does not
     * reliably match nested keys via where('project._id', ...).
     *
     * @param  Application $application
     * @param  string      $email
     * @return \App\Models\Invitation|null
     */
    private function findInvitation(Application $application, $email)
    {
        $appId = (string) $application->_id;

        return Invitation::where('email', $email)->get()->filter(function ($inv) use ($appId) {
            return isset($inv->project['_id']) && (string) $inv->project['_id'] === $appId;
        })->first();
    }
}
