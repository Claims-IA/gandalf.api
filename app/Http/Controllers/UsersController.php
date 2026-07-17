<?php
/**
 * UsersController
 *
 * Handles all user lifecycle operations for the Gandalf API: registration,
 * email verification, password reset, profile updates, user search, and
 * invitation dispatch. Routes are split between public (OAuth client-only)
 * and authenticated (full OAuth token) groups depending on whether a logged-in
 * user is required.
 *
 * @package App\Http\Controllers
 */

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Intercom;
use MongoDB\BSON\Regex;
use App\Models\Invitation;
use Nebo15\REST\Response;
use Nebo15\REST\AbstractController;
use Nebo15\REST\Interfaces\ListableInterface;
use Nebo15\LumenApplicationable\Models\Application;

/**
 * Class UsersController
 * @package App\Http\Controllers
 * @method \App\Repositories\UsersRepository getRepository()
 */
class UsersController extends AbstractController
{
    protected $repositoryClassName = 'App\Repositories\UsersRepository';

    protected $validationRules = [
        'validateUsername' => [
            'username' => 'required|unique:users,username|between:2,32|username',
        ],
        'create' => [
            'username' => 'required|unique:users,username|between:2,32|username',
            'first_name' => 'sometimes|required|string|between:2,32|alpha',
            'last_name' => 'sometimes|required|string|between:2,32|last_name',
            'email' => 'required|unique:users,email|email',
            'password' => 'required|between:6,32|password',
        ],
        'updateUser' => [
            'username' => 'sometimes|required|uniqueExceptUser:username|between:2,32|username',
            'first_name' => 'sometimes|required|string|between:2,32|alpha',
            'last_name' => 'sometimes|required|string|between:2,32|alpha',
            'email' => 'sometimes|required|uniqueExceptUser:email|email',
            'password' => 'sometimes|required|between:6,32|password',
            'current_password' => 'required_with:password|current_password',
            'settings' => 'sometimes|array',
        ],
        'createResetPasswordToken' => [
            'email' => 'required|email',
        ],
        'changePassword' => [
            'token' => 'required',
            'password' => 'required|between:6,32|password',
        ],
        'invite' => [
            'email' => 'required|email',
            'role' => 'required|string',
            'scope' => 'required|array',
        ],
    ];

    /**
     * Validate that a username is available and meets format requirements.
     *
     * Uses the 'validateUsername' ruleset (unique, length 2–32, alphanumeric/dash/dot).
     * Returns an empty 200 JSON response on success; validation errors yield 422.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function validateUsername()
    {
        $this->validateRoute();

        return $this->response->json();
    }

    /**
     * Return a paginated list of users filtered by name or email prefix.
     *
     * If the 'name' query parameter contains '@' the search is performed on the
     * email field; otherwise it searches usernames. The currently authenticated
     * user is excluded from the results so they don't appear in invitation lists.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function readListWithFilters()
    {
        $query = $this->getRepository()->getModel()->query();
        // Distinguish between email search (contains '@') and username search
        if (strpos($this->request->input('name', ''), '@') === false) {
            // Case-insensitive prefix match on username, exclude the requesting user
            $query->where(['username' => new Regex('^' . ($this->request->input('name', '.')) . '.*', 'i')]);
            $query->where(['username' => ['$ne' => $this->request->user()->username]]);
        } else {
            // Case-insensitive prefix match on email, exclude the requesting user
            $query->where(['email' => new Regex('^' . ($this->request->input('name', '.')) . '.*', 'i')]);
            $query->where(['email' => ['$ne' => $this->request->user()->email]]);
        }

        return $this->response->jsonPaginator(
            $this->getRepository()->paginateQuery($query, $this->request->input('size')),
            [],
            function (ListableInterface $model) {
                return $model->toListArray();
            }
        );
    }

    /**
     * Confirm a user's email address using a one-time token.
     *
     * Finds the user by the verify_email token, marks the account active by
     * promoting the temporary_email to the confirmed email field, then saves.
     * Returns the updated user array.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function verifyEmail()
    {
        return $this->response->json(
            $this->getRepository()->getModel()
                ->findByVerifyEmailToken($this->request->input('token'))
                ->verifyEmail()->save()
                ->toArray()
        );
    }

    /**
     * Re-send the email verification token to a user who has not yet confirmed.
     *
     * Looks up the user by their unconfirmed (temporary) email address, creates
     * a fresh token with a new 1-hour TTL, and dispatches the confirmation email.
     * In the 'local' environment the token is also returned in a sandbox field so
     * developers can complete the flow without an email server.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function resendVerifyEmailToken()
    {
        /** @var \App\Models\User $user */
        $user = User::where('temporary_email', $this->request->input('email'))->firstOrFail();
        // Generate a new token (overwrites any existing one) and persist immediately
        $user->createVerifyEmailToken()->save();
        $this->getMailService()->sendEmailConfirmation(
            $user->temporary_email,
            $user->getVerifyEmailToken()['token'],
            $user->username
        );
        $sandboxData = [];
        // Expose the token in the response body only in local dev to aid testing
        if (env('APP_ENV') == 'local') {
            $sandboxData['token_email'] = $user->getVerifyEmailToken();
        }

        return $this->response->json(
            [],
            Response::HTTP_OK,
            [],
            [],
            $sandboxData
        );
    }

    /**
     * Register a new user account.
     *
     * Validates the request, delegates creation to the repository (which fires
     * the Create event and generates a verify-email token), then processes any
     * pending invitations for the user's email so they are automatically added
     * to invited applications. Returns HTTP 201 on success.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function create()
    {
        $this->validateRoute();
        $user = $this->getRepository()->createOrUpdate($this->request->all());

        $sandboxData = [];
        // In local dev, return the email token in the response to bypass email sending
        if (env('APP_ENV') == 'local') {
            $sandboxData['token_email'] = $user->getVerifyEmailToken();
        }

        // Automatically honour any pending invitations that were sent before the user registered
        $invitation = Invitation::where('email', $user->email)->get();
        foreach ($invitation as $item) {
            if (array_key_exists('_id', $item->project)) {
                $application = Application::find($item->project['_id']);
                // Only add the user if they are not already a member to avoid duplicates
                if (!$application->getUser($user->email)) {
                    $application->setUser([
                        'user_id' => (string)$user->_id,
                        'role' => $item->role,
                        'scope' => $item->scope,
                    ])->save();
                }
            }
        }

        return $this->response->json(
            $user->toArray(),
            Response::HTTP_CREATED,
            [],
            [],
            $sandboxData
        );
    }

    /**
     * Update the authenticated user's profile.
     *
     * Validates against the 'updateUser' ruleset (all fields optional except
     * current_password when changing password). Delegates to the repository which
     * fires the Update event and triggers a verify-email flow if the email changed.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateUser()
    {
        $this->validateRoute();
        $user = $this->getRepository()->createOrUpdate(
            $this->request->request->all(),
            $this->request->user()->getId()
        );

        $sandboxData = [];
        if (env('APP_ENV') == 'local') {
            $sandboxData['token_email'] = $user->getVerifyEmailToken();
        }

        return $this->response->json(
            $user->toArray(),
            Response::HTTP_OK,
            [],
            [],
            $sandboxData
        );
    }

    /**
     * Complete a password reset using the emailed reset token.
     *
     * Finds the user by the reset_password token (throws TokenNotFoundException /
     * TokenExpiredException if invalid or expired), sets the new password, and
     * saves. The password hash is applied by UserObserver on save.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function changePassword()
    {
        $this->validateRoute();

        return $this->response->json(
            $this->getRepository()
                ->getModel()
                ->findByResetPasswordToken($this->request->input('token'))
                ->changePassword($this->request->input('password'))
                ->save()->toArray()
        );
    }

    /**
     * Initiate the "forgot password" flow by emailing a reset token.
     *
     * Looks up the account by email, generates a time-limited reset token, saves
     * it to the user document, and dispatches the recovery email via Postmark. In
     * local environments the raw token is included in the response for convenience.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function createResetPasswordToken()
    {
        $this->validateRoute();
        $email = $this->request->input('email');
        /** @var \App\Models\User $user */
        $user = $this->getRepository()->getModel()->query()->where(['email' => $email])->firstOrFail();

        $return = [];
        $sandboxData = [];
        $user->createResetPasswordToken();

        $this->getMailService()->sendRecoveryPassword($email, $user->getResetPasswordToken()['token'], $user);
        // Save after sending so a failed email delivery does not persist a stale token
        $user->save();

        if (env('APP_ENV') == 'local') {
            $sandboxData['reset_password_token'] = $user->getResetPasswordToken();
        }

        return $this->response->json(
            $return,
            Response::HTTP_OK,
            [],
            [],
            $sandboxData
        );
    }

    /**
     * Return the authenticated user's profile augmented with an Intercom secure code.
     *
     * The secure_code is an HMAC used by the Intercom JavaScript widget to verify
     * that the user identity passed from the frontend belongs to the authenticated session.
     *
     * @param  Intercom $intercom
     * @return \Illuminate\Http\JsonResponse
     */
    public function getUserInfo(Intercom $intercom)
    {
        $user = $this->request->user()->toArray();
        // Attach the HMAC-based secure code expected by Intercom's identity verification
        $user['secure_code'] = $intercom->generateSecureCode($user['_id']);

        return $this->response->json($user);
    }

    /**
     * Invite another user to join the current application.
     *
     * Validates that the requested scope is a subset of the inviting user's own scope
     * (preventing privilege escalation), creates an Invitation document, and sends the
     * invitation email. If the invitee already has an account the InvitationsObserver
     * (not this controller) handles adding them directly.
     *
     * @param  Application $application  The current application resolved from the X-Application header.
     * @return \Illuminate\Http\JsonResponse
     */
    public function invite(Application $application)
    {
        $current_user = $this->request->user()->getApplicationUser();
        // Restrict the allowed scope values to those the current user already possesses
        $this->validationRules['invite']['scope'] = 'required|array|in:' . join(',', $current_user->scope);
        $this->validateRoute();
        $project = $application->toArray();
        $fill = $this->request->all();
        // Embed the minimal project reference (id + title) into the invitation document
        $fill['project'] = [
            '_id' => $project['_id'],
            'title' => $project['title'],
        ];
        $invitation = (new Invitation())->fill($fill)->save();
        $this->getMailService()->sendEmailInvitation($invitation);

        return $this->response->json($invitation->toArray());
    }

    /**
     * Resolve the Mail service from the IoC container.
     *
     * Using app() here avoids injecting Mail into the constructor and keeps the
     * controller compatible with the AbstractController parent signature.
     *
     * @return \App\Services\Mail
     */
    private function getMailService()
    {
        return app('\App\Services\Mail');
    }
}
