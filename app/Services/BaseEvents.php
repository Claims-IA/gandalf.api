<?php
/**
 * BaseEvents
 *
 * Abstract base class for analytics/CRM event services (Intercom, Mixpanel). Defines
 * the decisionMake() contract that all concrete services must implement, and provides
 * the shared getIp() helper that inspects common HTTP headers to determine the real
 * client IP address behind proxies and load balancers. Concrete subclasses handle
 * user and decision events for their respective third-party integrations.
 *
 * @package App\Services
 */

namespace App\Services;

use App\Models\User;
use App\Models\Decision;

abstract class BaseEvents
{
    /**
     * Record a decision-made event for the given user IDs in the analytics service.
     *
     * @param  Decision $decision  The persisted decision record.
     * @param  array    $user_ids  List of admin user IDs associated with the application.
     * @return void
     */
    abstract public function decisionMake(Decision $decision, array $user_ids);

    /**
     * Determine the client's real IP address from HTTP request headers.
     *
     * Checks headers in priority order to support common proxy setups:
     * HTTP_CLIENT_IP (explicit client header) > X-Forwarded-For > X-Forwarded >
     * Forwarded-For > Forwarded > REMOTE_ADDR (direct connection).
     *
     * @return string|null  The detected IP address, or null if none could be determined.
     */
    protected function getIp()
    {
        $ip = null;
        // Each header is checked in decreasing trust order; the first non-empty value wins
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED'])) {
            $ip = $_SERVER['HTTP_X_FORWARDED'];
        } elseif (!empty($_SERVER['HTTP_FORWARDED_FOR'])) {
            $ip = $_SERVER['HTTP_FORWARDED_FOR'];
        } elseif (!empty($_SERVER['HTTP_FORWARDED'])) {
            $ip = $_SERVER['HTTP_FORWARDED'];
        } elseif (!empty($_SERVER['REMOTE_ADDR'])) {
            // Fallback to the direct TCP connection IP
            $ip = $_SERVER['REMOTE_ADDR'];
        }

        return $ip;
    }
}
