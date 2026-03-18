<?php
/**
 * Hasher
 *
 * Utility class for generating cryptographically strong random tokens. Used by
 * the User model to create email-verification and password-reset tokens, and by
 * DbTransfer to create unpredictable archive filenames. Token generation combines
 * a random ASCII string with bcrypt (using a random salt from random_bytes) and
 * strips forward-slash characters to produce a URL-safe string.
 *
 * @package App\Services
 */

namespace App\Services;

use Illuminate\Hashing\BcryptHasher;

class Hasher
{
    /**
     * Generate a cryptographically strong URL-safe random token string.
     *
     * Produces the token by bcrypt-hashing a random ASCII string with a random
     * 22-byte salt, then removing any '/' characters so the result is safe to use
     * in URLs and filenames without encoding.
     *
     * @param  int $length  Length of the intermediate random string (default 32).
     * @return string       The generated token (bcrypt output minus slashes).
     */
    public static function getToken($length = 32)
    {
        return str_replace(
            '/',
            '',
            (new BcryptHasher())->make(
                self::generateRandomString($length),
                // random_bytes provides a CSPRNG salt, making the output unpredictable
                ['salt' => random_bytes(22)]
            )
        );
    }

    /**
     * Generate a random ASCII string of printable characters.
     *
     * Builds a string of $length characters from the printable ASCII range 33–126
     * (exclamation mark through tilde) using mt_rand. This string is used only as
     * the bcrypt input, not directly as a token, so mt_rand's non-CSPRNG nature
     * is acceptable — the security comes from the bcrypt + random_bytes salt.
     *
     * @param  int    $length  Number of characters to generate.
     * @return string
     */
    private static function generateRandomString($length)
    {
        $string = '';
        for ($i = 0; $i < $length; $i++) {
            $string .= chr(mt_rand(33, 126));
        }

        return $string;
    }
}
