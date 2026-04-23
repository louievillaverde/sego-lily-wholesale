<?php
/**
 * Webhook + Mautic Tag Integration
 *
 * When key events happen (approval, cart abandon, etc.), this class:
 *   1. Fires an HTTP POST to the configured webhook URL (for AIOS or any external system)
 *   2. Directly adds the corresponding tag to the contact in Mautic via API
 *
 * Step 2 is what makes the Mautic segments → campaigns flow work. Without it,
 * contacts never get tagged and never enter the campaign segments.
 *
 * Mautic credentials come from the Sequences settings (slw_mautic_url,
 * slw_mautic_client_id, slw_mautic_client_secret). If Mautic is not
 * configured, only the webhook fires (step 1).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class SLW_Webhooks {

    const USER_AGENT = 'Mozilla/5.0 (compatible; AIOS-Mautic/1.0; +https://leadpiranha.com)';

    public static function init() {
        // Nothing to hook. fire() is called directly by other modules.
    }

    /**
     * Fire a webhook AND tag the contact in Mautic.
     *
     * @param string $event  Event name — also used as the Mautic tag name.
     * @param array  $data   Payload. Must include 'email' for Mautic tagging.
     */
    public static function fire( $event, $data = array() ) {
        // Step 1: Fire webhook to external URL (AIOS or any configured endpoint)
        self::fire_webhook( $event, $data );

        // Step 2: Tag the contact in Mautic directly
        if ( ! empty( $data['email'] ) ) {
            self::tag_mautic_contact( $data['email'], $event, $data );
        }
    }

    /**
     * Step 1: Send HTTP POST to the configured webhook URL.
     */
    private static function fire_webhook( $event, $data ) {
        $base_url = get_option( 'slw_webhook_url', '' );
        if ( empty( $base_url ) ) {
            return;
        }

        $url = trailingslashit( $base_url ) . $event;

        $data['event']     = $event;
        $data['timestamp'] = current_time( 'c' );
        $data['site']      = home_url();

        $response = wp_remote_post( $url, array(
            'timeout'  => 5,
            'blocking' => true,
            'headers'  => array( 'Content-Type' => 'application/json' ),
            'body'     => wp_json_encode( $data ),
        ));

        $success       = ! is_wp_error( $response );
        $response_code = $success ? wp_remote_retrieve_response_code( $response ) : 0;
        if ( $success && ( $response_code < 200 || $response_code >= 300 ) ) {
            $success = false;
        }

        if ( is_wp_error( $response ) && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( 'SLW Webhook Error [' . $event . ']: ' . $response->get_error_message() );
        }

        self::log_webhook( $event, $data, $success, $response_code );
    }

    /**
     * Step 2: Find or create the contact in Mautic and add the event tag.
     *
     * This is what makes segments → campaigns work. The tag name matches
     * the event name (e.g., 'wholesale-cart-abandoned' event adds the
     * 'wholesale-cart-abandoned' tag).
     *
     * @param string $email Contact email.
     * @param string $tag   Tag to add (same as event name).
     * @param array  $data  Additional contact data (first_name, business_name, etc.).
     */
    private static function tag_mautic_contact( $email, $tag, $data = array() ) {
        $base_url      = rtrim( get_option( 'slw_mautic_url', '' ), '/' );
        $client_id     = get_option( 'slw_mautic_client_id', '' );
        $client_secret = get_option( 'slw_mautic_client_secret', '' );

        if ( empty( $base_url ) || empty( $client_id ) || empty( $client_secret ) ) {
            return; // Mautic not configured — skip silently
        }

        // Get OAuth2 token (use cached if available)
        $token = get_transient( 'slw_mautic_access_token' );
        if ( ! $token ) {
            $token_response = wp_remote_post( $base_url . '/oauth/v2/token', array(
                'timeout' => 10,
                'headers' => array(
                    'Content-Type' => 'application/x-www-form-urlencoded',
                    'User-Agent'   => self::USER_AGENT,
                ),
                'body' => array(
                    'grant_type'    => 'client_credentials',
                    'client_id'     => $client_id,
                    'client_secret' => $client_secret,
                ),
            ));

            if ( is_wp_error( $token_response ) ) {
                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                    error_log( 'SLW Mautic Token Error: ' . $token_response->get_error_message() );
                }
                return;
            }

            $token_body = json_decode( wp_remote_retrieve_body( $token_response ), true );
            if ( empty( $token_body['access_token'] ) ) {
                return;
            }

            $token = $token_body['access_token'];
            set_transient( 'slw_mautic_access_token', $token, 50 * MINUTE_IN_SECONDS );
        }

        $headers = array(
            'Authorization' => 'Bearer ' . $token,
            'Content-Type'  => 'application/json',
            'Accept'        => 'application/json',
            'User-Agent'    => self::USER_AGENT,
        );

        // Find existing contact by email
        $search = wp_remote_get( $base_url . '/api/contacts?search=' . rawurlencode( $email ) . '&limit=1', array(
            'timeout' => 10,
            'headers' => $headers,
        ));

        $contact_id = null;
        if ( ! is_wp_error( $search ) ) {
            $search_body = json_decode( wp_remote_retrieve_body( $search ), true );
            $contacts    = $search_body['contacts'] ?? array();
            if ( ! empty( $contacts ) ) {
                // Get the first contact
                $contact_id = array_key_first( $contacts );
            }
        }

        // Build contact data for create/update
        $tags = array( $tag );

        // Retail quiz leads also get quiz-completed + routing tags
        if ( ! empty( $data['skin_concern'] ) ) {
            $tags[] = 'quiz-completed';
            $tags[] = 'retail-quiz-lead';
            $skin_tag_map = array(
                'Dryness & tightness'  => 'skin-dryness',
                'Breakouts'            => 'skin-breakouts',
                'Redness & sensitivity' => 'skin-sensitivity',
                'Wrinkles & dark spots' => 'skin-aging',
            );
            if ( isset( $skin_tag_map[ $data['skin_concern'] ] ) ) {
                $tags[] = $skin_tag_map[ $data['skin_concern'] ];
            }
        }
        if ( ! empty( $data['frustration'] ) ) {
            $frustration_tag_map = array(
                'Nothing works long enough'  => 'frustration-durability',
                'Too many products'          => 'frustration-simplify',
                'Don\'t trust ingredients'   => 'frustration-ingredients',
                'Just want something simple' => 'frustration-simple',
            );
            if ( isset( $frustration_tag_map[ $data['frustration'] ] ) ) {
                $tags[] = $frustration_tag_map[ $data['frustration'] ];
            }
        }

        $contact_data = array(
            'email' => $email,
            'tags'  => $tags,
        );
        if ( ! empty( $data['first_name'] ) ) {
            $contact_data['firstname'] = $data['first_name'];
        }
        if ( ! empty( $data['business_name'] ) ) {
            $contact_data['company'] = $data['business_name'];
        }
        if ( ! empty( $data['skin_concern'] ) ) {
            $contact_data['skin_concern'] = $data['skin_concern'];
        }
        if ( ! empty( $data['product_count'] ) ) {
            $contact_data['product_count'] = $data['product_count'];
        }
        if ( ! empty( $data['frustration'] ) ) {
            $contact_data['frustration'] = $data['frustration'];
        }
        if ( ! empty( $data['skincare_experience'] ) ) {
            $contact_data['skincare_experience'] = $data['skincare_experience'];
        }
        if ( ! empty( $data['tallow_interest'] ) ) {
            $contact_data['tallow_interest'] = $data['tallow_interest'];
        }
        if ( ! empty( $data['business_type'] ) ) {
            $contact_data['company_industry'] = $data['business_type'];
        }

        if ( $contact_id ) {
            // Update existing contact — add the tag
            wp_remote_request( $base_url . '/api/contacts/' . $contact_id . '/edit', array(
                'method'  => 'PATCH',
                'timeout' => 10,
                'headers' => $headers,
                'body'    => wp_json_encode( $contact_data ),
            ));
        } else {
            // Create new contact with the tag
            wp_remote_post( $base_url . '/api/contacts/new', array(
                'timeout' => 10,
                'headers' => $headers,
                'body'    => wp_json_encode( $contact_data ),
            ));
        }
    }

    /**
     * Remove a tag from a Mautic contact. Used when an event resolves
     * (e.g., cart-abandoned tag removed when order completes).
     *
     * @param string $email Contact email.
     * @param string $tag   Tag to remove.
     */
    public static function remove_mautic_tag( $email, $tag ) {
        $base_url      = rtrim( get_option( 'slw_mautic_url', '' ), '/' );
        $client_id     = get_option( 'slw_mautic_client_id', '' );
        $client_secret = get_option( 'slw_mautic_client_secret', '' );

        if ( empty( $base_url ) || empty( $client_id ) || empty( $client_secret ) ) {
            return;
        }

        $token = get_transient( 'slw_mautic_access_token' );
        if ( ! $token ) {
            return; // No cached token — skip to avoid slowing down the request
        }

        $headers = array(
            'Authorization' => 'Bearer ' . $token,
            'Content-Type'  => 'application/json',
            'Accept'        => 'application/json',
            'User-Agent'    => self::USER_AGENT,
        );

        // Find contact
        $search = wp_remote_get( $base_url . '/api/contacts?search=' . rawurlencode( $email ) . '&limit=1', array(
            'timeout' => 10,
            'headers' => $headers,
        ));

        if ( is_wp_error( $search ) ) {
            return;
        }

        $search_body = json_decode( wp_remote_retrieve_body( $search ), true );
        $contacts    = $search_body['contacts'] ?? array();
        if ( empty( $contacts ) ) {
            return;
        }

        $contact_id = array_key_first( $contacts );

        // Remove the tag (prefix with - to remove)
        wp_remote_request( $base_url . '/api/contacts/' . $contact_id . '/edit', array(
            'method'  => 'PATCH',
            'timeout' => 10,
            'headers' => $headers,
            'body'    => wp_json_encode( array(
                'tags' => array( '-' . $tag ),
            )),
        ));
    }

    /**
     * Log a webhook fire to the circular buffer.
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

        $log = array_slice( $log, 0, 50 );
        update_option( 'slw_webhook_log', $log, false );
    }
}
