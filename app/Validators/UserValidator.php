<?php
/**
 * UserValidator
 *
 * Custom validation rules for User model fields. Registered globally by
 * ValidationServiceProvider. Validates username format (alphanumeric, dash, dot,
 * underscore), password strength (must contain upper, lower, and digit), last name
 * format (letters and apostrophe only), the current_password confirmation required
 * for password changes, and a uniqueness check that allows a user to keep their
 * existing value without triggering a "taken" error.
 *
 * @package App\Validators
 */
namespace App\Validators;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

class UserValidator
{
    /**
     * Validate that a username contains only allowed characters.
     *
     * Permits letters (upper and lower), digits, hyphens, underscores, and dots.
     * Spaces and special characters are not allowed.
     *
     * @param  string $attribute
     * @param  mixed  $value
     * @return bool
     */
    public function username($attribute, $value)
    {
        return preg_match('/^[a-zA-Z0-9-_\.]+$/', $value);
    }

    /**
     * Validate password strength: must contain upper, lower, and digit characters.
     *
     * Uses three look-ahead assertions to ensure the password has at least one
     * uppercase letter, one lowercase letter, and one digit. Does not allow spaces.
     *
     * @param  string $attribute
     * @param  mixed  $value
     * @return bool
     */
    public function password($attribute, $value)
    {
        return preg_match('/^\S*(?=\S*[A-Z])(?=\S*[a-z])(?=\S*[\d])\S*$/', $value);
    }

    /**
     * Validate that a last name contains only letters and apostrophes.
     *
     * Allows names like "O'Brien" but not names with digits or most special characters.
     *
     * @param  string $attribute
     * @param  mixed  $value
     * @return bool
     */
    public function lastName($attribute, $value)
    {
        return preg_match('/^[a-zA-Z\']+$/', $value);
    }

    /**
     * Validate uniqueness while allowing the authenticated user to keep their current value.
     *
     * The standard Lumen 'unique' rule would reject a user's own existing email or username
     * during a profile update. This rule passes when the submitted value matches what the
     * authenticated user already has stored, and fails if another user has the same value.
     *
     * @param  string $attribute    The attribute name.
     * @param  mixed  $value        The submitted value.
     * @param  array  $parameters   Must contain the field name to check (e.g. 'email', 'username').
     * @return bool
     * @throws \InvalidArgumentException  If no field parameter is provided.
     * @throws AuthorizationException     If no authenticated user is found.
     */
    public function unique($attribute, $value, $parameters)
    {
        if (!$parameters) {
            throw new \InvalidArgumentException("Validation rule uniqueExceptUser requires 1 parameter");
        }
        $field = $parameters[0];
        /** @var User $user */
        if (!$user = \Auth::user()) {
            throw new AuthorizationException;
        }
        // Allow the user to submit their existing value without triggering a uniqueness failure
        if ($user->$field == $value) {
            return true;
        }

        // Check that no other user already owns this value
        return 0 == User::where($field, $value)->get(['_id'])->count();
    }

    /**
     * Validate that the submitted current_password matches the authenticated user's stored hash.
     *
     * Required when a user requests a password change to prevent CSRF/session-hijacking
     * attacks from silently changing passwords. Uses the model's password hasher so the
     * algorithm stays consistent with UserObserver::saving().
     *
     * @param  string $attribute
     * @param  mixed  $value        The plain-text current password submitted by the user.
     * @return bool
     * @throws AuthorizationException  If no authenticated user is found.
     */
    public function currentPassword($attribute, $value)
    {
        /** @var User $user */
        if (!$user = \Auth::user()) {
            throw new AuthorizationException;
        }

        return $user->getPasswordHasher()->check($value, $user->password);
    }
}
