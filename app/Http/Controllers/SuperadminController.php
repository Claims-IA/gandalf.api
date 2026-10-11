<?php
/**
 * SuperadminController
 *
 * Routes reserved to superadmins (ADMIN.md), behind the 'superadmin' route
 * middleware:
 *
 *   - the admin log, most recent first, filtered by application (its creation
 *     included) or by superadmin;
 *   - the accounts: every account with its applications, accounts that belong
 *     to no application included, which no application screen reaches; an
 *     account deleted for good; a temporary password given to an account.
 *
 * Neither the request bodies nor the responses are logged: a temporary
 * password never reaches the log.
 *
 * @package App\Http\Controllers
 */

namespace App\Http\Controllers;

use App\Exceptions\IdNotFoundException;
use App\Models\AdminLogEntry;
use App\Models\Invitation;
use App\Models\User;
use App\Services\Accounts;
use App\Services\AdminLog;
use Illuminate\Http\Request;
use MongoDB\BSON\ObjectID;
use MongoDB\BSON\Regex;
use Nebo15\LumenApplicationable\Models\Application;
use Nebo15\REST\Response;

class SuperadminController extends Controller
{
    /**
     * GET /api/v1/superadmin/log
     *
     * @param  Request  $request
     * @param  Response $response
     * @return \Illuminate\Http\JsonResponse
     */
    public function log(Request $request, Response $response)
    {
        $this->validate($request, [
            'application_id' => 'sometimes|string',
            'user_id' => 'sometimes|string',
            'size' => 'sometimes|integer|min:1|max:200',
            'page' => 'sometimes|integer|min:1',
        ]);

        $query = AdminLogEntry::query()->orderBy(AdminLogEntry::CREATED_AT, 'desc');
        if ($request->has('application_id')) {
            // Its creation included: that request has no X-Application, the
            // new id is in its details.
            $applicationId = (string) $request->input('application_id');
            $query->where(function ($query) use ($applicationId) {
                $query->where('application_id', $applicationId)->orWhere('details.application_id', $applicationId);
            });
        }
        if ($request->has('user_id')) {
            $query->where('user_id', (string) $request->input('user_id'));
        }

        return $response->jsonPaginator($query->paginate((int) $request->input('size', 50)));
    }

    /**
     * GET /api/v1/superadmin/users
     *
     * Every account, by username, with the applications it belongs to and its
     * role in each. `search` matches the start of a word of the username or of
     * the email address (pending address included), ignoring case;
     * `without_application=1` keeps the accounts that belong to no
     * application.
     *
     * @param  Request  $request
     * @param  Response $response
     * @return \Illuminate\Http\JsonResponse
     */
    public function users(Request $request, Response $response)
    {
        $this->validate($request, [
            'search' => 'sometimes|string|max:100',
            'without_application' => 'sometimes|boolean',
            'size' => 'sometimes|integer|min:1|max:200',
            'page' => 'sometimes|integer|min:1',
        ]);

        $query = User::query()->orderBy('username', 'asc');
        $search = trim((string) $request->input('search', ''));
        if ($search !== '') {
            $regex = new Regex('(^|[^A-Za-z0-9])' . preg_quote($search), 'i');
            $query->where(function ($query) use ($regex) {
                $query->where('username', 'regex', $regex)
                    ->orWhere('email', 'regex', $regex)
                    ->orWhere('temporary_email', 'regex', $regex);
            });
        }
        if ($request->input('without_application')) {
            $members = array_map(function ($id) {
                return new ObjectID($id);
            }, array_keys(self::memberships()));
            $query->whereNotIn('_id', $members);
        }

        $page = $query->paginate((int) $request->input('size', 50));
        $memberships = self::memberships(array_map(function (User $user) {
            return (string) $user->getId();
        }, $page->items()));

        return $response->jsonPaginator($page, [], function (User $user) use ($memberships) {
            return self::account($user, $memberships[(string) $user->getId()] ?? []);
        });
    }

    /**
     * DELETE /api/v1/superadmin/users/{id}
     *
     * Delete an account for good: it leaves every application it belongs to,
     * its pending invitations are cancelled, its sessions end with it. Refused
     * for one's own account and for a superadmin's (the flag is removed on the
     * server first). An application left without an admin is flagged in the
     * administration screen.
     *
     * @param  Request  $request
     * @param  Response $response
     * @param  AdminLog $log
     * @param  string   $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function deleteUser(Request $request, Response $response, AdminLog $log, $id)
    {
        $user = $this->target($request, $id);
        if ($user instanceof \Illuminate\Http\JsonResponse) {
            return $user;
        }

        $applications = [];
        foreach (self::applicationsOf($id) as $application) {
            $application->deleteUser($id)->save();
            $applications[] = ['_id' => (string) $application->_id, 'title' => $application->title];
        }
        $emails = array_values(array_filter([$user->email, $user->temporary_email]));
        if ($emails) {
            Invitation::whereIn('email', $emails)->delete();
        }
        $log->note([
            'user_id' => $id,
            'email' => $user->email ?: $user->temporary_email,
            'username' => $user->username,
            'applications' => array_column($applications, '_id'),
        ]);
        $user->delete();

        return $response->json(['_id' => $id, 'applications' => $applications]);
    }

    /**
     * POST /api/v1/superadmin/users/{id}/password
     *
     * Give an account a temporary password, returned once and emailed too when
     * sending works (`email_sent`): the person must replace it at their next
     * sign-in. The account is signed out everywhere, and activated if its
     * address was not verified. Refused for one's own account (changed from
     * the profile) and for a superadmin's.
     *
     * @param  Request  $request
     * @param  Response $response
     * @param  AdminLog $log
     * @param  Accounts $accounts
     * @param  string   $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function resetPassword(Request $request, Response $response, AdminLog $log, Accounts $accounts, $id)
    {
        $user = $this->target($request, $id);
        if ($user instanceof \Illuminate\Http\JsonResponse) {
            return $user;
        }

        $temporaryPassword = $accounts->temporaryPassword();
        $user->confirmAccount()
            ->setTemporaryPassword($temporaryPassword)
            ->removeResetPasswordToken()
            ->save();
        $user->revokeSessions();
        $log->note(['user_id' => $id, 'email' => $user->email, 'username' => $user->username]);

        return $response->json([
            'user_id' => $id,
            'username' => $user->username,
            'email' => $user->email,
            'temporary_password' => $temporaryPassword,
            'email_sent' => $accounts->mailTemporaryPassword($user, $temporaryPassword),
        ]);
    }

    /**
     * The account a superadmin acts on, or the refusal to send: not found,
     * one's own, or a superadmin's.
     *
     * @param  Request $request
     * @param  string  $id
     * @return User|\Illuminate\Http\JsonResponse
     * @throws IdNotFoundException
     */
    private function target(Request $request, $id)
    {
        if ($id === (string) $request->user()->getId()) {
            return response()->json(['meta' => ['code' => 403, 'error' => 'own_account',
                'error_message' => 'Not on your own account: use your profile.']], 403);
        }
        $user = User::find($id);
        if (!$user) {
            throw new IdNotFoundException;
        }
        if ($user->isSuperadmin()) {
            return response()->json(['meta' => ['code' => 403, 'error' => 'superadmin_account',
                'error_message' => 'Not on a superadmin account: remove the flag on the server first.']], 403);
        }

        return $user;
    }

    /**
     * An account as listed: its profile fields and its applications.
     *
     * @param  User  $user
     * @param  array $applications  [_id, title, role] of each.
     * @return array
     */
    private static function account(User $user, array $applications)
    {
        $created = $user->getAttribute('created_at');

        return $user->toArray() + [
            'created_at' => $created instanceof \DateTimeInterface ? $created->format(DATE_ATOM) : null,
            'applications' => $applications,
        ];
    }

    /**
     * The applications an account belongs to.
     *
     * @param  string $id
     * @return \Illuminate\Support\Collection
     */
    private static function applicationsOf($id)
    {
        return Application::where('users.user_id', new ObjectID($id))->get();
    }

    /**
     * user id => [_id, title, role] of each application the user belongs to,
     * for the given users, or for every member of an application.
     *
     * @param  string[]|null $userIds
     * @return array
     */
    private static function memberships(array $userIds = null)
    {
        $query = Application::query();
        if ($userIds !== null) {
            $query->whereIn('users.user_id', array_map(function ($id) {
                return new ObjectID($id);
            }, $userIds));
        }
        $memberships = [];
        foreach ($query->orderBy('title', 'asc')->get(['title', 'users']) as $application) {
            foreach ($application->users as $member) {
                $userId = (string) $member->user_id;
                if ($userId === '' || ($userIds !== null && !in_array($userId, $userIds, true))) {
                    continue;
                }
                $memberships[$userId][] = [
                    '_id' => (string) $application->_id,
                    'title' => $application->title,
                    'role' => $member->role,
                ];
            }
        }

        return $memberships;
    }
}
