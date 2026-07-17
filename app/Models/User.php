<?php
/**
 * User Model
 *
 * Represents a Gandalf platform user stored in the MongoDB 'users' collection.
 * Implements multiple contracts required by the framework: Authenticatable for
 * session/token auth, Authorizable for gate checks, OauthableContract so
 * LumenOauth2 can issue tokens, and ApplicationableUserContract so the user
 * can be associated with multiple applications. Provides built-in token management
 * for email verification and password reset flows, each with a 1-hour TTL.
 *
 * @package App\Models
 */
namespace App\Models;

use App\Exceptions\TokenExpiredException;
use App\Exceptions\TokenNotFoundException;
use App\Services\Hasher;
use Illuminate\Auth\Authenticatable;
use Laravel\Lumen\Auth\Authorizable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Contracts\Auth\Access\Authorizable as AuthorizableContract;
use Nebo15\LumenApplicationable\Contracts\ApplicationableUser as ApplicationableUserContract;
use Nebo15\LumenApplicationable\Traits\ApplicationableUserTrait;
use Nebo15\LumenOauth2\Interfaces\Oauthable as OauthableContract;
use Nebo15\LumenOauth2\Traits\Oauthable;
use Nebo15\REST\Traits\ListableTrait;
use Nebo15\REST\Interfaces\ListableInterface;

/**
 * Class User
 * @package App\Models
 * @property string $username
 * @property string $first_name
 * @property string $last_name
 * @property string $email
 * @property string $password
 * @property string $temporary_email
 * @property bool $active
 * @property array $tokens
 * @method static \Illuminate\Database\Eloquent\Builder where($column, $operator = null, $value = null, $boolean = 'and')
 */
class User extends Base implements
    ListableInterface,
    AuthenticatableContract,
    AuthorizableContract,
    OauthableContract,
    ApplicationableUserContract
{
    use ListableTrait, Authenticatable, Authorizable, Oauthable, ApplicationableUserTrait;

    protected $listable = [
        '_id',
        'username',
        'first_name',
        'last_name',
    ];

    protected $visible = ['_id', 'username', 'temporary_email', 'email', 'first_name', 'last_name', 'active', 'settings'];

    protected $fillable = ['username', 'temporary_email', 'email', 'password', 'first_name', 'last_name', 'settings'];

    protected $attributes = [
        'active' => false,
        'email' => '',
        'temporary_email' => '',
        'tokens' => [],
        // Free-form per-user UI preferences (e.g. flow editor input mode).
        // Stored verbatim; the API never interprets it.
        'settings' => [],
    ];

    /**
     * The attributes excluded from the model's JSON form.
     *
     * @var array
     */
    protected $hidden = [
        'password',
    ];

    // -------------------------------------------------------------------------
    // Password reset token methods
    // -------------------------------------------------------------------------

    /**
     * Generate and store a new password-reset token with a 1-hour TTL.
     *
     * The token is stored inside the embedded 'tokens.reset_password' map on the
     * user document. Callers must still call save() after this method.
     *
     * @return $this
     */
    public function createResetPasswordToken()
    {
        $this->attributes['tokens']['reset_password'] = [
            'token' => Hasher::getToken(),
            'expired' => time() + 3600, // 1 hour from now
        ];

        return $this;
    }

    /**
     * Return the stored reset_password token array, or false if none exists.
     *
     * @return array|false
     */
    public function getResetPasswordToken()
    {
        return $this->getInternalToken('reset_password');
    }

    /**
     * Find the user whose reset_password token matches the given value.
     *
     * @param  string $token  The raw token string from the reset email.
     * @return User
     * @throws \App\Exceptions\TokenNotFoundException
     * @throws \App\Exceptions\TokenExpiredException
     */
    public function findByResetPasswordToken($token)
    {
        return $this->findByToken($token, 'reset_password');
    }

    /**
     * Remove the reset_password token from the user's tokens map.
     *
     * Should be called after a successful password change to invalidate the token.
     *
     * @return $this
     */
    public function removeResetPasswordToken()
    {
        unset($this->attributes['tokens']['reset_password']);

        return $this;
    }

    /**
     * Set a new plain-text password on the model.
     *
     * The actual bcrypt hashing is performed by UserObserver::saving() so the raw
     * password is never persisted. Returns $this for chaining.
     *
     * @param  string $new_password
     * @return $this
     */
    public function changePassword($new_password)
    {
        $this->setAttribute('password', $new_password);

        return $this;
    }

    // -------------------------------------------------------------------------
    // Email verification token methods
    // -------------------------------------------------------------------------

    /**
     * Generate and store a new email-verification token with a 1-hour TTL.
     *
     * The email is first stored in 'temporary_email'; only after verifyEmail() is
     * called does it move to the 'email' field and 'active' becomes true.
     *
     * @return $this
     */
    public function createVerifyEmailToken()
    {
        $this->attributes['tokens']['verify_email'] = [
            'token' => Hasher::getToken(),
            'expired' => time() + 3600, // 1 hour from now
        ];

        return $this;
    }

    /**
     * Return the stored verify_email token array, or false if none exists.
     *
     * @return array|false
     */
    public function getVerifyEmailToken()
    {
        return $this->getInternalToken('verify_email');
    }

    /**
     * Confirm the user's email and activate the account.
     *
     * Promotes temporary_email to the canonical email field, sets active to true,
     * and removes the now-used verification token. Callers must still call save().
     *
     * @return $this
     */
    public function verifyEmail()
    {
        $this->email = $this->temporary_email;
        $this->temporary_email = null;
        $this->active = true;
        $this->removeVerifyEmailToken();

        return $this;
    }

    /**
     * Find the user whose verify_email token matches the given value.
     *
     * @param  string $token  The raw token string from the verification email.
     * @return User
     * @throws \App\Exceptions\TokenNotFoundException
     * @throws \App\Exceptions\TokenExpiredException
     */
    public function findByVerifyEmailToken($token)
    {
        return $this->findByToken($token, 'verify_email');
    }

    /**
     * Remove the verify_email token from the user's tokens map.
     *
     * @return $this
     */
    public function removeVerifyEmailToken()
    {
        unset($this->attributes['tokens']['verify_email']);

        return $this;
    }

    /**
     * Find a user by an arbitrary token type and validate its expiry.
     *
     * Queries the users collection for a matching token value nested under
     * tokens.{type}.token, then checks the expiry timestamp. Optional $field/$value
     * parameters can be used to add extra query constraints.
     *
     * @param  string      $token  Raw token string to look up.
     * @param  string      $type   Token type ('reset_password' or 'verify_email').
     * @param  string|null $field  Optional additional filter field name.
     * @param  mixed|null  $value  Optional additional filter field value.
     * @return User
     * @throws \App\Exceptions\TokenNotFoundException  If no user has this token.
     * @throws \App\Exceptions\TokenExpiredException   If the token has passed its TTL.
     */
    public function findByToken($token, $type, $field = null, $value = null)
    {
        $query = $this->where("tokens.$type.token", '=', $token);
        if ($field and $value) {
            $query->where($field, '=', $value);
        }
        if (!$user = $query->first()) {
            throw new TokenNotFoundException;
        }
        // Reject tokens whose Unix timestamp expiry has passed
        if ($user->tokens[$type]['expired'] <= time()) {
            throw new TokenExpiredException;
        }

        return $user;
    }

    /**
     * Check whether the user's account is active (email has been verified).
     *
     * @return bool
     */
    public function isActive()
    {
        return $this->active;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Safely retrieve a typed token array from the attributes.
     *
     * Returns false rather than null to allow simple boolean checks by callers.
     *
     * @param  string $type  Token type key within the 'tokens' map.
     * @return array|false
     */
    private function getInternalToken($type)
    {
        return (array_key_exists('tokens', $this->attributes) && array_key_exists($type, $this->attributes['tokens'])) ?
            $this->attributes['tokens'][$type] :
            false;
    }
}
