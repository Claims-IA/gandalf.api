<?php
/**
 * DeleteExpiredTokens Command
 *
 * Artisan command (tokens:delete) that removes expired tokens from user documents
 * in MongoDB. Scans users who have at least one expired OAuth access token, refresh
 * token, password-reset token, or email-verification token, then removes only the
 * expired entries from each embedded array. Scheduled to run hourly to keep user
 * documents lean and prevent stale tokens from being inadvertently accepted.
 *
 * @package App\Console\Commands
 */

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class DeleteExpiredTokens extends Command
{
    protected $signature = 'tokens:delete';

    protected $description = 'Delete expired User tokens';

    /**
     * Execute the token cleanup command.
     *
     * Uses a single MongoDB query with OR conditions to efficiently find all users
     * who have at least one expired token of any type. For each such user, expired
     * OAuth tokens are removed from their embedded arrays while valid ones are kept.
     * The inline $filter closure keeps tokens whose expiry is >= now (i.e. still valid),
     * so reject($filter) returns the ones to remove.
     *
     * @return void
     */
    public function handle()
    {
        /** @var User[] $users */
        $time = time();
        // Single query to find all users with at least one expired token of any type
        $users = User::where('accessTokens.expires', '<=', $time)
            ->orWhere('refreshTokens.expires', '<=', $time)
            ->orWhere('tokens.reset_password.expired', '<=', $time)
            ->orWhere('tokens.verify_email.expired', '<=', $time)
            ->get();

        // This filter KEEPS tokens that are still valid (expires >= now)
        $filter = function ($item) use ($time) {
            return $item->expires >= $time;
        };

        foreach ($users as $user) {
            // reject($filter) returns tokens where the filter returned false (i.e. expired ones)
            $filteredAccessTokens = $user->accessTokens()->reject($filter);
            if ($filteredAccessTokens->count() > 0) {
                $user->accessTokens()->dissociate($filteredAccessTokens);
            }

            $filteredRefreshTokens = $user->refreshTokens()->reject($filter);
            if ($filteredRefreshTokens->count() > 0) {
                $user->refreshTokens()->dissociate($filteredRefreshTokens);
            }

            // Internal tokens use 'expired' (singular) rather than 'expires'
            if ($user->getResetPasswordToken()['expired'] < $time) {
                $user->removeResetPasswordToken();
            }
            if ($user->getVerifyEmailToken()['expired'] < $time) {
                $user->removeVerifyEmailToken();
            }
            $user->save();
        }
    }
}
