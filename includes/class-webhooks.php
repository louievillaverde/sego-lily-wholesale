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
            'blocking' => true, // Must be true to capture response for logging
            'headers'  => array(
                'Content-Type' => 'application/json',
            ),
            'body'     => wp_json_encode( $data ),
        ));

        // Determine success/failure
        $success       = ! is_wp_error( $response );
        $response_code = $success ? wp_remote_retrieve_response_code( $response ) : 0;
        if ( $success && ( $response_code < 200 || $response_code >= 300 ) ) {
            $success = false;
        }

        // Log failures for debugging (visible in WP debug.log)
        if ( is_wp_error( $response ) && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( 'SLW Webhook Error [' . $event . ']: ' . $response->get_error_message() );
        }

        // Store in webhook log (circular buffer, last 50 entries)
        self::log_webhook( $event, $data, $success, $response_code );
    }

    /**
     * Log a webhook fire to the circular buffer stored in wp_options.
     *
     * @param string $event         Event name.
     * @param array  $data          Payload that was sent.
     * @param bool   $success       Whether the request succeeded.
     * @param int    $response_code HTTP response code (0 if WP_Error).
     */
    private static function log_webhook( $event, $data, $success, $response_code ) {
        $log = get_option( 'slw_webhook_log', array() );
        if ( ! is_array( $log ) ) {
            $log = array();
        }

        array_unshift( $log, array(
            'event'  => $event,
            'email'  => isset( $data['email'] ) ? $data['email'] : '',
            'status' => $success ? 'success' : 'failed',
            'code'   => $response_code,
            'time'   => current_time( 'mysql' ),
        ));

        // Keep last 50 entries
        $log = array_slice( $log, 0, 50 );
        update_option( 'slw_webhook_log', $log, false );
    }
}
