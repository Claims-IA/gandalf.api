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
use App\Models\User;
use App\Models\Invitation;
use App\Services\DbTransfer;
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
    ];

    /**
     * Permanently delete the application and all of its decision tables.
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
        // Remove all decision tables that belong to this application first
        Table::where(['applications' => ['$in' => [$application->_id]]])->delete();
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
     *      is enabled, also emailed to the invitee.
     *
     * @param  Application $application  The current application (X-Application header).
     * @param  Mail        $mail
     * @return \Illuminate\Http\JsonResponse
     * @throws IdNotFoundException  When no matching user or invitation is found.
     */
    public function confirmCollaborator(Application $application, Mail $mail)
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

        // Resolve an existing user, either by id or (temporary_)email.
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
                if ($user->temporary_email) {
                    $user->email = $user->temporary_email;
                    $user->temporary_email = null;
                }
                $user->active = true;
                $user->removeVerifyEmailToken();
                $user->save();
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

        // Generate a readable, unambiguous temporary password (CSPRNG) that the
        // invitee must change after first login. Avoids look-alike characters.
        $temporaryPassword = $this->generateTemporaryPassword();

        $newUser = new User();
        $newUser->fill([
            'username' => strstr($email, '@', true),
            'email'    => $email,
            'password' => $temporaryPassword,
        ]);
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

        // Deliver the temporary password by email when email sending is enabled;
        // it is always returned so the admin can pass it on manually.
        $mail->sendTemporaryPassword($email, $temporaryPassword, $newUser->username);

        return $this->response->json([
            'user_id'            => (string) $newUser->_id,
            'email'              => $email,
            'status'             => 'active',
            'temporary_password' => $temporaryPassword,
        ]);
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
     *   - the target must exist;
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

    /**
     * Generate a readable temporary password using a CSPRNG.
     *
     * Uses an alphabet stripped of look-alike characters (0/O, 1/l/I) so the
     * password can be dictated or copied without ambiguity, while random_int
     * keeps the selection cryptographically secure.
     *
     * @param  int $length
     * @return string
     */
    private function generateTemporaryPassword($length = 12)
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
        $max = strlen($alphabet) - 1;
        $password = '';
        for ($i = 0; $i < $length; $i++) {
            $password .= $alphabet[random_int(0, $max)];
        }

        return $password;
    }
}
