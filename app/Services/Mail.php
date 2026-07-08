<?php
/**
 * Mail Service
 *
 * Wraps the Postmark transactional email API to send the three email types used
 * by Gandalf: account activation (email verification), password recovery, and
 * project invitation. All methods are no-ops when the 'services.email.enabled'
 * config flag is false or when the relevant Postmark template ID is not configured,
 * making it safe to run the application locally without email credentials.
 *
 * @package App\Services
 */

namespace App\Services;

use Postmark\PostmarkClient;

class Mail
{

    private $postmark;

    public function __construct()
    {
        $this->postmark = new PostmarkClient(config('services.postmark.token'));
    }

    /**
     * Send the welcome/email-verification email to a newly registered user.
     *
     * Uses the 'welcome' Postmark template and injects the verification link with
     * the one-time token. The action_url template variable replaces {code} with the
     * actual token so the frontend confirmation URL is constructed server-side.
     *
     * @param  string $email  The unconfirmed email address to send to.
     * @param  string $code   The raw verification token.
     * @param  string $name   The user's username (used for personalisation).
     * @return void|null      Returns null when email sending is disabled.
     */
    public function sendEmailConfirmation($email, $code, $name)
    {
        if (false == config('services.email.enabled')) {
            return null;
        }
        if (config('services.postmark.templates.welcome')) {
            $this->postmark->sendEmailWithTemplate(
                config('services.postmark.sender'),
                $email,
                self::templateId(config('services.postmark.templates.welcome')),
                [
                    'product_name' => 'Gandalf',
                    'name' => $name,
                    // Replace the {code} placeholder in the configured frontend URL
                    'action_url' => str_replace('{code}', $code, config('services.link.confirmation_email')),
                    'username' => $name,
                ]
            );
        }
    }

    /**
     * Send the password-recovery email containing the reset link.
     *
     * Uses the 'reset_password' Postmark template. The reset link is constructed
     * by substituting {code} in the configured URL with the actual token.
     *
     * @param  string $email  The user's confirmed email address.
     * @param  string $code   The raw password-reset token.
     * @param  User   $user   The user model (used for the username personalisation field).
     * @return void|null
     */
    public function sendRecoveryPassword($email, $code, $user)
    {
        if (false == config('services.email.enabled')) {
            return null;
        }

        if (config('services.postmark.templates.reset_password')) {
            $this->postmark->sendEmailWithTemplate(
                config('services.postmark.sender'),
                $email,
                self::templateId(config('services.postmark.templates.reset_password')),
                [
                    'product_name' => 'Gandalf',
                    'name' => $user->username,
                    'action_url' => str_replace('{code}', $code, config('services.link.reset_password')),
                    'username' => $user->username,
                ]
            );
        }
    }

    /**
     * Send an invitation email to a prospective project member.
     *
     * Uses the 'invite' Postmark template and includes the project name and a
     * link to the registration/login page where the invitee can accept the invite.
     *
     * @param  \App\Models\Invitation $invitation  The invitation document.
     * @return void|null
     */
    public function sendEmailInvitation($invitation)
    {
        if (false == config('services.email.enabled')) {
            return null;
        }
        if (config('services.postmark.templates.invite')) {
            $this->postmark->sendEmailWithTemplate(
                config('services.postmark.sender'),
                $invitation->email,
                self::templateId(config('services.postmark.templates.invite')),
                [
                    'email' => $invitation->email,
                    'project_name' => $invitation->project['title'],
                    'action_url' => config('services.link.invite'),
                ]
            );
        }
    }

    /**
     * Normalise a configured Postmark template reference.
     *
     * Template IDs read from the environment are strings (e.g. "45631871"). The
     * Postmark SDK treats a string as a template *alias* and an integer as a
     * template *ID*, so a numeric string is sent as an alias and rejected with
     * "The Template's 'Alias' ... was not found". Cast numeric values to int so
     * they are sent as an ID, while leaving genuine (non-numeric) aliases intact.
     *
     * @param  string $template  Configured template ID or alias.
     * @return int|string
     */
    private static function templateId($template)
    {
        return is_numeric($template) ? (int) $template : $template;
    }
}
