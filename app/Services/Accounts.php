<?php
/**
 * Accounts
 *
 * What the routes that give an account a temporary password share (ADMIN.md):
 * an admin confirming an invitation or creating an account in their
 * application, a superadmin resetting a password.
 *
 * The temporary password is returned once to whoever asked for it, and sent
 * to the account's address when email sending is enabled. The person must
 * replace it before doing anything else (User::mustChangePassword()).
 *
 * @package App\Services
 */

namespace App\Services;

use App\Models\User;
use Illuminate\Contracts\Debug\ExceptionHandler;

class Accounts
{
    /**
     * A readable temporary password from a CSPRNG.
     *
     * The alphabet has no look-alike characters (0/O, 1/l/I), so the password
     * can be dictated or copied without ambiguity.
     *
     * @param  int $length
     * @return string
     */
    public function temporaryPassword($length = 12)
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
        $max = strlen($alphabet) - 1;
        $password = '';
        for ($i = 0; $i < $length; $i++) {
            $password .= $alphabet[random_int(0, $max)];
        }

        return $password;
    }

    /**
     * A unique, length-valid username derived from an email address.
     *
     * Usernames are 2 to 32 characters long and unique: the local part alone
     * guarantees neither, so it is padded, truncated, and given a numeric
     * suffix on collision.
     *
     * @param  string $email
     * @return string
     */
    public function usernameFromEmail($email)
    {
        $base = strstr($email, '@', true) ?: 'user';
        // Only the characters the username validator accepts
        $base = preg_replace('/[^A-Za-z0-9._-]/', '', $base);
        if (strlen($base) < 2) {
            $base = str_pad($base, 2, 'x');
        }
        $base = substr($base, 0, 32);

        $candidate = $base;
        $suffix = 1;
        while (User::where('username', $candidate)->first()) {
            $suffix++;
            $candidate = substr($base, 0, 32 - strlen((string) $suffix)) . $suffix;
        }

        return $candidate;
    }

    /**
     * Send the temporary password to the account's address.
     *
     * The account is already saved: a failure (the provider refusing the
     * recipient, for instance) is reported, not thrown, so that the caller
     * still shows the password.
     *
     * @param  User   $user
     * @param  string $password
     * @return bool  Whether the email was sent.
     */
    public function mailTemporaryPassword(User $user, $password)
    {
        if (!config('services.email.enabled')) {
            return false;
        }
        try {
            app(Mail::class)->sendTemporaryPassword($user->email, $password, $user->username);

            return true;
        } catch (\Exception $e) {
            app(ExceptionHandler::class)->report($e);

            return false;
        }
    }
}
