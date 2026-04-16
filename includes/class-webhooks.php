<?php
/**
 * AIOS Webhook Integration
 *
 * Fires HTTP POST requests to the Lead Piranha AIOS when key events happen:
 * - wholesale-approved: Application approved, triggers Mautic onboarding sequence
 * - first-order-placed: First wholesale order, triggers Mautic Email 5 tag
 *
 * The webhook URL is configured in plugin settings. If no URL is set, webhooks
 * are silently skipped (the plugin works fine standalone without AIOS).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class SLW_Webhooks {

    public static function init() {
        // Nothing to hook here. The fire() method is called directly by other modules.
    }

    /**
     * Fire a webhook to the AIOS endpoint. Non-blocking (uses wp_remote_post
     * with a short timeout so it doesn't slow down the admin action).
     *
     * @param string $event  Event name (appended to the webhook URL path).
     * @param array  $data   Payload to send as JSON.
     */
    public static function fire( $event, $data = array() ) {
        $base_url = get_option( 'slw_webhook_url', '' );
        if ( empty( $base_url ) ) {
            return;
        }

        // Build the full URL: base + event slug
        // If the base URL already ends with a path segment, append the event
        $url = trailingslashit( $base_url ) . $event;

        $data['event']     = $event;
        $data['timestamp'] = current_time( 'c' );
        $data['site']      = home_url();

        $response = wp_remote_post( $url, array(
            'timeout'  => 5,
            'blocking' => false,
            'headers'  => array(
                'Content-Type' => 'application/json',
            ),
            'body'     => wp_json_encode( $data ),
        ));

        // Log failures for debugging (visible in WP debug.log)
        if ( is_wp_error( $response ) && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( 'SLW Webhook Error [' . $event . ']: ' . $response->get_error_message() );
        }
    }
}
