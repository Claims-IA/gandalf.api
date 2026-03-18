<?php
/**
 * Intercom Service
 *
 * Integrates the Gandalf API with Intercom for user analytics and CRM. Implements
 * the BaseEvents contract and provides three capabilities: syncing user profile data
 * to Intercom on create/update, recording a 'decision-made' event each time a
 * decision is evaluated (attributed to the application's admin users), and generating
 * the HMAC-based secure code that the frontend Intercom widget uses to verify user
 * identity. All methods are no-ops when INTERCOM_ENABLED is false.
 *
 * @package App\Services
 */

namespace App\Services;

use App\Models\User;
use App\Models\Decision;

class Intercom extends BaseEvents
{
    private $intercom;

    public function __construct(\Nebo15\LumenIntercom\Intercom $intercom)
    {
        $this->intercom = $intercom;
    }

    /**
     * Create or update the user's profile in Intercom.
     *
     * Sends user_id, email, last request timestamp, and key custom attributes
     * to Intercom so the CRM profile stays current. The third argument 'true'
     * tells the Intercom client to queue the request rather than sending inline.
     *
     * @param  User $user
     * @return void|false  Returns false when integration is disabled.
     */
    public function userCreateOrUpdate(User $user)
    {
        if (false == env('INTERCOM_ENABLED')) {
            return false;
        }
        $user_data = [
            'user_id' => $user->getId(),
            'email' => $user->email,
            'last_request_at' => time(),
            'custom_attributes' => [
                'username' => $user->username,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
            ],
        ];
        $this->intercom->updateUser($user_data, true);
    }

    /**
     * Record a 'decision-made' event in Intercom for each admin user.
     *
     * Associates the event with the application's admin user IDs so each admin
     * can see their application's decision activity in their Intercom timeline.
     * Includes a deep-link URL to the variant in the Gandalf admin panel.
     *
     * @param  Decision $decision   The persisted decision record.
     * @param  array    $user_ids   Admin user IDs to attribute the event to.
     * @return void|false
     */
    public function decisionMake(Decision $decision, array $user_ids)
    {
        if (false == env('INTERCOM_ENABLED')) {
            return false;
        }
        $table_id = strval($decision->table['_id']);
        $variant_id = strval($decision->table['variant']['_id']);
        // Build the metadata payload including a clickable deep-link to the admin variant view
        $meta = [
            'decision_id' => strval($decision->_id),
            'table_id' => $table_id,
            'table_title' => $decision->table['title'],
            'matching_type' => $decision->table['matching_type'],
            'variant_id' => [
                'value' => $variant_id,
                'url' => str_replace(
                    ['{table_id}', '{variant_id}'],
                    [$table_id, $variant_id],
                    config('services.link.admin_variant')
                )
            ],
            'variant_title' => $decision->table['variant']['title'],
        ];

        // Create one event per admin user so each has it in their own Intercom feed
        foreach ($user_ids as $user_id) {
            $this->intercom->createEvent([
                'event_name' => 'decision-made',
                'created_at' => time(),
                'user_id' => $user_id,
                'metadata' => $meta,
            ], true);
        }
    }

    /**
     * Generate the HMAC-SHA256 secure code for Intercom identity verification.
     *
     * The Intercom JavaScript widget sends this code along with the user ID so
     * Intercom's servers can verify the user is authenticated on the Gandalf platform.
     *
     * @param  string $user_id  The user's MongoDB ObjectID string.
     * @return string           Hex-encoded HMAC-SHA256 digest.
     */
    public function generateSecureCode($user_id)
    {
        return hash_hmac('sha256', $user_id, env('INTERCOM_APP_SECRET'));
    }
}
