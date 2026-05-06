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

        // Step 3: For wholesale-approved, send the first onboarding email
        // immediately via Mautic API (don't wait for cron to process the campaign)
        if ( $event === 'wholesale-approved' && ! empty( $data['email'] ) ) {
            self::send_mautic_onboarding_email( $data['email'] );
        }
    }

    /**
     * Send the first onboarding email immediately via Mautic API.
     * This bypasses the campaign cron queue so the new wholesale partner
     * gets the Welcome Kit email right alongside the WP approval email.
     *
     * Email ID 15 = "Wholesale 01 - Welcome Kit"
     */
    private static function send_mautic_onboarding_email( $email ) {
        $base_url      = rtrim( get_option( 'slw_mautic_url', '' ), '/' );
        $client_id     = get_option( 'slw_mautic_client_id', '' );
        $client_secret = get_option( 'slw_mautic_client_secret', '' );

        if ( empty( $base_url ) || empty( $client_id ) || empty( $client_secret ) ) {
            return;
        }

        $token = self::get_mautic_token( $base_url, $client_id, $client_secret );
        if ( ! $token ) {
            return;
        }

        $headers = array(
            'Authorization' => 'Bearer ' . $token,
            'Accept'        => 'application/json',
            'User-Agent'    => self::USER_AGENT,
        );

        // Find the contact by email
        $search = wp_remote_get( $base_url . '/api/contacts?search=' . rawurlencode( $email ) . '&limit=1', array(
            'timeout' => 10,
            'headers' => $headers,
        ) );

        if ( is_wp_error( $search ) ) return;

        $body = json_decode( wp_remote_retrieve_body( $search ), true );
        $contacts = $body['contacts'] ?? array();
        if ( empty( $contacts ) ) return;

        $contact_id = array_key_first( $contacts );

        // Send email 15 (Welcome Kit) to this contact
        $onboarding_email_id = absint( get_option( 'slw_onboarding_email_id', 15 ) );
        $result = wp_remote_post( $base_url . '/api/emails/' . $onboarding_email_id . '/contact/' . $contact_id . '/send', array(
            'timeout' => 10,
            'headers' => $headers,
        ) );

        if ( ! is_wp_error( $result ) ) {
            $code = wp_remote_retrieve_response_code( $result );
            self::log_mautic( 'onboarding-email-sent', $email, $code >= 200 && $code < 300 ? 'success' : 'failed', 'Email ' . $onboarding_email_id . ' HTTP ' . $code );
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
    /**
     * Fetch the set of email addresses in Mautic that carry a given tag.
     * Used by the v4.6.9 sync-flag backfill so already-tagged customers
     * (those who came through the application path, or were synced via
     * direct API, or were tagged before slw_synced_to_mautic existed)
     * get their flag set correctly without re-firing the webhook.
     *
     * @param string $tag    Tag name without leading +/-.
     * @param int    $limit  Max contacts to scan (Mautic default is 30; we
     *                       page through up to this many).
     * @return array<string,bool>|false  Map of lowercased email => true,
     *                       or false if Mautic is unconfigured / auth failed.
     */
    public static function get_mautic_emails_with_tag( $tag, $limit = 500 ) {
        $base_url      = rtrim( get_option( 'slw_mautic_url', '' ), '/' );
        $client_id     = get_option( 'slw_mautic_client_id', '' );
        $client_secret = get_option( 'slw_mautic_client_secret', '' );

        if ( empty( $base_url ) || empty( $client_id ) || empty( $client_secret ) ) {
            return false;
        }

        $token = self::get_mautic_token( $base_url, $client_id, $client_secret );
        if ( ! $token ) {
            return false;
        }

        $headers = array(
            'Authorization' => 'Bearer ' . $token,
            'Accept'        => 'application/json',
            'User-Agent'    => self::USER_AGENT,
        );

        $emails    = array();
        $page_size = 100;
        $start     = 0;

        while ( $start < $limit ) {
            $url = $base_url . '/api/contacts?' . http_build_query( array(
                'search' => 'tag:' . $tag,
                'limit'  => $page_size,
                'start'  => $start,
            ) );
            $resp = wp_remote_get( $url, array(
                'timeout' => 15,
                'headers' => $headers,
            ) );

            if ( is_wp_error( $resp ) ) {
                return false;
            }

            $code = wp_remote_retrieve_response_code( $resp );
            if ( $code === 401 ) {
                delete_transient( 'slw_mautic_access_token' );
                $token = self::get_mautic_token( $base_url, $client_id, $client_secret );
                if ( ! $token ) return false;
                $headers['Authorization'] = 'Bearer ' . $token;
                continue;
            }
            if ( $code < 200 || $code >= 300 ) {
                return false;
            }

            $body     = json_decode( wp_remote_retrieve_body( $resp ), true );
            $contacts = $body['contacts'] ?? array();
            if ( empty( $contacts ) ) break;

            foreach ( $contacts as $contact ) {
                $email = $contact['fields']['core']['email']['value'] ?? ( $contact['fields']['all']['email'] ?? '' );
                if ( $email ) {
                    $emails[ strtolower( $email ) ] = true;
                }
            }

            if ( count( $contacts ) < $page_size ) break;
            $start += $page_size;
        }

        return $emails;
    }

    /**
     * Look up the most recent email-open event for a given contact email
     * in Mautic. Used by the v4.6.15 customer engagement column so Holly
     * can spot which customers have read their welcome email vs which
     * haven't (likely landed in spam, or just unread).
     *
     * Strategy:
     *   1. Find the contact by email.
     *   2. GET /api/contacts/{id}/events with type=email.read filter.
     *   3. Return the timestamp of the most recent event, or false.
     *
     * @param string $email Contact email.
     * @return string|false ISO 8601 timestamp of last open, or false.
     */
    public static function get_last_email_open( $email ) {
        $base_url      = rtrim( get_option( 'slw_mautic_url', '' ), '/' );
        $client_id     = get_option( 'slw_mautic_client_id', '' );
        $client_secret = get_option( 'slw_mautic_client_secret', '' );

        if ( empty( $base_url ) || empty( $client_id ) || empty( $client_secret ) ) {
            return false;
        }

        $token = self::get_mautic_token( $base_url, $client_id, $client_secret );
        if ( ! $token ) {
            return false;
        }

        $headers = array(
            'Authorization' => 'Bearer ' . $token,
            'Accept'        => 'application/json',
            'User-Agent'    => self::USER_AGENT,
        );

        // Look up contact id by email.
        $search_resp = wp_remote_get( $base_url . '/api/contacts?search=' . rawurlencode( $email ) . '&limit=1', array(
            'timeout' => 10,
            'headers' => $headers,
        ) );
        if ( is_wp_error( $search_resp ) ) {
            return false;
        }
        if ( wp_remote_retrieve_response_code( $search_resp ) === 401 ) {
            delete_transient( 'slw_mautic_access_token' );
            $token = self::get_mautic_token( $base_url, $client_id, $client_secret );
            if ( ! $token ) return false;
            $headers['Authorization'] = 'Bearer ' . $token;
            $search_resp = wp_remote_get( $base_url . '/api/contacts?search=' . rawurlencode( $email ) . '&limit=1', array(
                'timeout' => 10,
                'headers' => $headers,
            ) );
        }
        if ( is_wp_error( $search_resp ) ) {
            return false;
        }
        if ( wp_remote_retrieve_response_code( $search_resp ) !== 200 ) {
            return false;
        }

        $search_body = json_decode( wp_remote_retrieve_body( $search_resp ), true );
        $contacts    = $search_body['contacts'] ?? array();
        if ( empty( $contacts ) ) {
            return false;
        }
        $contact_id = (int) array_key_first( $contacts );
        if ( ! $contact_id ) {
            return false;
        }

        // Fetch the contact's events filtered to email.read events. Mautic's
        // events endpoint paginates; we only need the most recent.
        $events_url = $base_url . '/api/contacts/' . $contact_id . '/events?' . http_build_query( array(
            'filters' => array( 'type' => 'email.read' ),
            'limit'   => 1,
            'order'   => array( 'col' => 'timestamp', 'dir' => 'DESC' ),
        ) );
        $events_resp = wp_remote_get( $events_url, array(
            'timeout' => 10,
            'headers' => $headers,
        ) );
        if ( is_wp_error( $events_resp ) || wp_remote_retrieve_response_code( $events_resp ) !== 200 ) {
            return false;
        }
        $events_body = json_decode( wp_remote_retrieve_body( $events_resp ), true );
        $events      = $events_body['events'] ?? array();
        if ( empty( $events ) ) {
            return false;
        }

        // Mautic returns events under different shapes by version. Find the
        // first event with a usable timestamp.
        foreach ( $events as $event ) {
            $ts = $event['timestamp'] ?? ( $event['eventTimestamp'] ?? null );
            if ( $ts ) {
                return $ts;
            }
        }
        return false;
    }

    private static function tag_mautic_contact( $email, $tag, $data = array() ) {
        $base_url      = rtrim( get_option( 'slw_mautic_url', '' ), '/' );
        $client_id     = get_option( 'slw_mautic_client_id', '' );
        $client_secret = get_option( 'slw_mautic_client_secret', '' );

        if ( empty( $base_url ) || empty( $client_id ) || empty( $client_secret ) ) {
            self::log_mautic( $tag, $email, 'skipped', 'Mautic not configured' );
            return;
        }

        // Get OAuth2 token (use cached if available, retry on failure)
        $token = self::get_mautic_token( $base_url, $client_id, $client_secret );
        if ( ! $token ) {
            self::log_mautic( $tag, $email, 'failed', 'Could not obtain OAuth token' );
            return;
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

        // Handle 401 on search — token may have expired between cache and use
        if ( ! is_wp_error( $search ) && wp_remote_retrieve_response_code( $search ) === 401 ) {
            delete_transient( 'slw_mautic_access_token' );
            $token = self::get_mautic_token( $base_url, $client_id, $client_secret );
            if ( ! $token ) {
                self::log_mautic( $tag, $email, 'failed', 'OAuth token expired and refresh failed' );
                return;
            }
            $headers['Authorization'] = 'Bearer ' . $token;
            $search = wp_remote_get( $base_url . '/api/contacts?search=' . rawurlencode( $email ) . '&limit=1', array(
                'timeout' => 10,
                'headers' => $headers,
            ));
        }

        if ( is_wp_error( $search ) ) {
            self::log_mautic( $tag, $email, 'failed', 'Contact search error: ' . $search->get_error_message() );
            return;
        }

        $search_code = wp_remote_retrieve_response_code( $search );
        if ( $search_code < 200 || $search_code >= 300 ) {
            self::log_mautic( $tag, $email, 'failed', 'Contact search HTTP ' . $search_code );
            return;
        }

        $contact_id = null;
        $search_body = json_decode( wp_remote_retrieve_body( $search ), true );
        $contacts    = $search_body['contacts'] ?? array();
        if ( ! empty( $contacts ) ) {
            $contact_id = array_key_first( $contacts );
        }

        // Build contact data for create/update.
        // Send tags as an ARRAY of bare names. Mautic 4+ adds these to the
        // contact's existing tags (does not replace) when sent via PATCH /edit
        // or POST /new. The "+tagname" string-prefix syntax is only correct
        // when tags is sent as a comma-separated STRING; sending "+tagname"
        // inside an array creates a literal tag named "+tagname" in Mautic.
        // That's exactly the bug v4.6.5 fixes.
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

        // Referral code fields — sync to Mautic for email merge tags
        if ( ! empty( $data['code_1'] ) ) {
            $contact_data['referral_code_1'] = $data['code_1'];
        }
        if ( ! empty( $data['code_2'] ) ) {
            $contact_data['referral_code_2'] = $data['code_2'];
        }
        if ( ! empty( $data['code_3'] ) ) {
            $contact_data['referral_code_3'] = $data['code_3'];
        }
        if ( ! empty( $data['reward_code'] ) ) {
            $contact_data['latest_reward_code'] = $data['reward_code'];
        }
        if ( isset( $data['conversions'] ) ) {
            $contact_data['referral_conversions'] = $data['conversions'];
        }

        if ( $contact_id ) {
            // Update existing contact — add the tag
            $response = wp_remote_request( $base_url . '/api/contacts/' . $contact_id . '/edit', array(
                'method'  => 'PATCH',
                'timeout' => 10,
                'headers' => $headers,
                'body'    => wp_json_encode( $contact_data ),
            ));
            $action = 'update';
        } else {
            // Create new contact with the tag
            $response = wp_remote_post( $base_url . '/api/contacts/new', array(
                'timeout' => 10,
                'headers' => $headers,
                'body'    => wp_json_encode( $contact_data ),
            ));
            $action = 'create';
        }

        // Log the result
        if ( is_wp_error( $response ) ) {
            self::log_mautic( $tag, $email, 'failed', 'Contact ' . $action . ' error: ' . $response->get_error_message() );
        } else {
            $code = wp_remote_retrieve_response_code( $response );
            if ( $code >= 200 && $code < 300 ) {
                self::log_mautic( $tag, $email, 'success', 'Contact ' . $action . ' OK (HTTP ' . $code . ')' );
            } else {
                $body = wp_remote_retrieve_body( $response );
                self::log_mautic( $tag, $email, 'failed', 'Contact ' . $action . ' HTTP ' . $code . ': ' . substr( $body, 0, 200 ) );
            }
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

        // Get token — obtain fresh one if needed (don't silently skip)
        $token = self::get_mautic_token( $base_url, $client_id, $client_secret );
        if ( ! $token ) {
            return;
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

        // Remove the tag. The "-tagname" remove syntax only works when tags
        // is sent as a comma-separated STRING (not an array). In array form,
        // Mautic creates a literal tag named "-tagname" instead of removing.
        wp_remote_request( $base_url . '/api/contacts/' . $contact_id . '/edit', array(
            'method'  => 'PATCH',
            'timeout' => 10,
            'headers' => $headers,
            'body'    => wp_json_encode( array(
                'tags' => '-' . $tag,
            )),
        ));
    }

    // ── Shared Helpers ───────────────────────────────────────────────────

    /**
     * Get a valid Mautic OAuth2 token. Uses cached transient when available,
     * fetches a new one otherwise.
     *
     * @param string $base_url      Mautic base URL.
     * @param string $client_id     OAuth2 client ID.
     * @param string $client_secret OAuth2 client secret.
     * @return string|false Access token or false on failure.
     */
    private static function get_mautic_token( $base_url, $client_id, $client_secret ) {
        $cached = get_transient( 'slw_mautic_access_token' );
        if ( $cached ) {
            return $cached;
        }

        $response = wp_remote_post( $base_url . '/oauth/v2/token', array(
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

        if ( is_wp_error( $response ) ) {
            error_log( 'SLW Mautic Token Error: ' . $response->get_error_message() );
            return false;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code !== 200 || empty( $body['access_token'] ) ) {
            $msg = $body['error_description'] ?? ( 'HTTP ' . $code );
            error_log( 'SLW Mautic Token Failed: ' . $msg );
            return false;
        }

        set_transient( 'slw_mautic_access_token', $body['access_token'], 50 * MINUTE_IN_SECONDS );
        return $body['access_token'];
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

    /**
     * Log a Mautic API operation to the circular buffer.
     * Shares the same log as webhooks so everything is visible in one place.
     */
    private static function log_mautic( $tag, $email, $status, $detail = '' ) {
        $log = get_option( 'slw_webhook_log', array() );
        if ( ! is_array( $log ) ) {
            $log = array();
        }

        array_unshift( $log, array(
            'event'  => 'mautic:' . $tag,
            'email'  => $email,
            'status' => $status,
            'code'   => $detail,
            'time'   => current_time( 'mysql' ),
        ));

        $log = array_slice( $log, 0, 50 );
        update_option( 'slw_webhook_log', $log, false );
    }
}
