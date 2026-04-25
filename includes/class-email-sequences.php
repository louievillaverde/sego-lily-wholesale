<?php
/**
 * Email Sequences Dashboard
 *
 * Read-only dashboard that pulls campaign/email data from Mautic's REST API
 * and displays it in the admin. All email editing happens in Mautic — this
 * page just shows stats, deep links, and webhook health.
 *
 * Provider-abstracted: Mautic methods are isolated so Mailchimp/Klaviyo
 * adapters can be swapped in later without touching the render layer.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class SLW_Email_Sequences {

    const USER_AGENT = 'Mozilla/5.0 (compatible; AIOS-Mautic/1.0; +https://leadpiranha.com)';

    public static function init() {
        // AJAX handlers
        add_action( 'wp_ajax_slw_test_mautic_connection', array( __CLASS__, 'ajax_test_connection' ) );
        add_action( 'wp_ajax_slw_refresh_sequences',      array( __CLASS__, 'ajax_refresh_sequences' ) );
        add_action( 'wp_ajax_slw_send_newsletter',        array( __CLASS__, 'ajax_send_newsletter' ) );
        add_action( 'wp_ajax_slw_save_sequence_order',   array( __CLASS__, 'ajax_save_sequence_order' ) );
        add_action( 'wp_ajax_slw_save_nl_template',      array( __CLASS__, 'ajax_save_nl_template' ) );
        add_action( 'wp_ajax_slw_delete_nl_template',    array( __CLASS__, 'ajax_delete_nl_template' ) );
        add_action( 'wp_ajax_slw_clear_failed_log',     array( __CLASS__, 'ajax_clear_failed_log' ) );

        // Scheduled newsletter cron handler
        add_action( 'slw_send_scheduled_newsletter', array( __CLASS__, 'execute_scheduled_newsletter' ) );
    }

    /**
     * Execute a scheduled newsletter send (fired by WP-Cron).
     */
    public static function execute_scheduled_newsletter( $job_id ) {
        $data = get_transient( $job_id );
        if ( ! $data || empty( $data['email_list'] ) ) {
            return;
        }

        $html_email = self::build_branded_email( $data['subject'], $data['body'] );
        $provider   = get_option( 'slw_email_provider', 'none' );

        if ( $provider === 'mautic' ) {
            self::send_via_mautic( $data['subject'], $html_email, $data['email_list'] );
        } else {
            self::send_via_wp_mail( $data['subject'], $html_email, $data['email_list'] );
        }

        delete_transient( $job_id );
    }

    /**
     * AJAX: Save the drag-reordered sequence order.
     */
    public static function ajax_save_sequence_order() {
        check_ajax_referer( 'slw_sequences_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( 'Permission denied.' );
        }

        $order = isset( $_POST['order'] ) ? array_map( 'sanitize_text_field', $_POST['order'] ) : array();
        update_option( 'slw_sequence_order', $order );
        wp_send_json_success( 'Order saved.' );
    }

    /* =================================================================
       Mautic OAuth2 Token Management
       ================================================================= */

    /**
     * Get a valid OAuth2 access token, using cached transient when possible.
     *
     * @return string|WP_Error Access token or error.
     */
    private static function get_mautic_token() {
        $cached = get_transient( 'slw_mautic_access_token' );
        if ( $cached ) {
            return $cached;
        }

        $base_url      = rtrim( get_option( 'slw_mautic_url', '' ), '/' );
        $client_id     = get_option( 'slw_mautic_client_id', '' );
        $client_secret = get_option( 'slw_mautic_client_secret', '' );

        if ( empty( $base_url ) || empty( $client_id ) || empty( $client_secret ) ) {
            return new \WP_Error( 'missing_config', 'Mautic credentials not configured.' );
        }

        $response = wp_remote_post( $base_url . '/oauth/v2/token', array(
            'timeout' => 15,
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
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code !== 200 || empty( $body['access_token'] ) ) {
            $msg = isset( $body['error_description'] ) ? $body['error_description'] : 'OAuth2 token request failed (HTTP ' . $code . ')';
            return new \WP_Error( 'oauth_failed', $msg );
        }

        // Cache for 50 minutes (tokens expire at 60)
        set_transient( 'slw_mautic_access_token', $body['access_token'], 50 * MINUTE_IN_SECONDS );

        return $body['access_token'];
    }

    /**
     * Make an authenticated request to the Mautic API.
     *
     * @param string $method   HTTP method (GET, POST, etc.).
     * @param string $endpoint API endpoint (e.g. /api/campaigns).
     * @param array  $args     Additional request args.
     * @return array|WP_Error  Decoded JSON or error.
     */
    private static function mautic_request( $method, $endpoint, $args = array() ) {
        $base_url = rtrim( get_option( 'slw_mautic_url', '' ), '/' );
        $token    = self::get_mautic_token();

        if ( is_wp_error( $token ) ) {
            return $token;
        }

        $defaults = array(
            'method'  => $method,
            'timeout' => 15,
            'headers' => array(
                'Authorization' => 'Bearer ' . $token,
                'Accept'        => 'application/json',
                'User-Agent'    => self::USER_AGENT,
            ),
        );

        $request_args = wp_parse_args( $args, $defaults );
        $response     = wp_remote_request( $base_url . $endpoint, $request_args );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );

        // Token expired — clear cache and retry once
        if ( $code === 401 ) {
            delete_transient( 'slw_mautic_access_token' );
            $token = self::get_mautic_token();
            if ( is_wp_error( $token ) ) {
                return $token;
            }
            $request_args['headers']['Authorization'] = 'Bearer ' . $token;
            $response = wp_remote_request( $base_url . $endpoint, $request_args );
            if ( is_wp_error( $response ) ) {
                return $response;
            }
            $code = wp_remote_retrieve_response_code( $response );
        }

        if ( $code < 200 || $code >= 300 ) {
            return new \WP_Error( 'api_error', 'Mautic API returned HTTP ' . $code );
        }

        return json_decode( wp_remote_retrieve_body( $response ), true );
    }

    /* =================================================================
       Provider-Abstracted Data Fetchers
       ================================================================= */

    /**
     * Fetch campaigns from the configured email provider.
     *
     * @return array|WP_Error
     */
    private static function fetch_campaigns() {
        $provider = get_option( 'slw_email_provider', 'mautic' );
        if ( $provider === 'mautic' ) {
            return self::fetch_mautic_campaigns();
        }
        // Future: elseif ($provider === 'mailchimp') { ... }
        return array();
    }

    /**
     * Fetch email stats from the configured provider.
     *
     * @return array|WP_Error
     */
    private static function fetch_email_stats() {
        $provider = get_option( 'slw_email_provider', 'mautic' );
        if ( $provider === 'mautic' ) {
            return self::fetch_mautic_email_stats();
        }
        return array();
    }

    /* =================================================================
       Mautic-Specific Fetchers
       ================================================================= */

    /**
     * Fetch campaigns from Mautic, with 15-minute cache.
     *
     * @return array|WP_Error
     */
    private static function fetch_mautic_campaigns() {
        $cached = get_transient( 'slw_mautic_campaigns' );
        if ( $cached !== false ) {
            return $cached;
        }

        $result = self::mautic_request( 'GET', '/api/campaigns?limit=50&orderBy=dateAdded&orderByDir=DESC' );
        if ( is_wp_error( $result ) ) {
            return $result;
        }

        $all_campaigns = isset( $result['campaigns'] ) ? $result['campaigns'] : array();

        // Filter to wholesale-related campaigns only. Excludes retail sequences
        // (quiz results, retail cart abandonment, etc.) that aren't relevant.
        $campaigns = array();
        foreach ( $all_campaigns as $id => $camp ) {
            $name = strtolower( $camp['name'] ?? '' );
            $desc = strtolower( $camp['description'] ?? '' );
            if ( strpos( $name, 'wholesale' ) !== false || strpos( $desc, 'wholesale' ) !== false ) {
                $campaigns[ $id ] = $camp;
            }
        }

        set_transient( 'slw_mautic_campaigns', $campaigns, 15 * MINUTE_IN_SECONDS );

        return $campaigns;
    }

    /**
     * Fetch email stats from Mautic, with 15-minute cache.
     *
     * @return array|WP_Error
     */
    private static function fetch_mautic_email_stats() {
        $cached = get_transient( 'slw_mautic_email_stats' );
        if ( $cached !== false ) {
            return $cached;
        }

        $result = self::mautic_request( 'GET', '/api/emails?limit=100&orderBy=dateAdded&orderByDir=DESC' );
        if ( is_wp_error( $result ) ) {
            return $result;
        }

        $emails = isset( $result['emails'] ) ? $result['emails'] : array();

        // Index by ID for easy lookup
        $indexed = array();
        foreach ( $emails as $email ) {
            if ( isset( $email['id'] ) ) {
                $indexed[ $email['id'] ] = $email;
            }
        }

        set_transient( 'slw_mautic_email_stats', $indexed, 15 * MINUTE_IN_SECONDS );

        return $indexed;
    }

    /* =================================================================
       AJAX Handlers
       ================================================================= */

    /**
     * AJAX: Test Mautic connection.
     */
    public static function ajax_test_connection() {
        check_ajax_referer( 'slw_sequences_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( 'Permission denied.' );
        }

        $result = self::mautic_request( 'GET', '/api/campaigns?limit=1' );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }

        wp_send_json_success( 'Connected successfully.' );
    }

    /**
     * AJAX: Refresh sequences data (delete transients and re-fetch).
     */
    public static function ajax_refresh_sequences() {
        check_ajax_referer( 'slw_sequences_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( 'Permission denied.' );
        }

        delete_transient( 'slw_mautic_campaigns' );
        delete_transient( 'slw_mautic_email_stats' );
        delete_transient( 'slw_mautic_access_token' );

        wp_send_json_success( 'Cache cleared. Reload the page to see fresh data.' );
    }

    /* =================================================================
       Page Render
       ================================================================= */

    /**
     * Handle settings save and render the full Sequences page.
     */
    public static function render_page() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }

        $saved = false;

        // Handle settings save (self-handling form pattern)
        if ( isset( $_POST['slw_sequences_save'] ) && check_admin_referer( 'slw_sequences_nonce' ) ) {
            $provider = sanitize_text_field( $_POST['slw_email_provider'] ?? 'none' );
            $valid_providers = array( 'none', 'mautic', 'mailchimp', 'activecampaign', 'klaviyo', 'convertkit' );
            if ( ! in_array( $provider, $valid_providers, true ) ) {
                $provider = 'none';
            }
            update_option( 'slw_email_provider', $provider );

            // Mautic credentials
            update_option( 'slw_mautic_url',           esc_url_raw( $_POST['slw_mautic_url'] ?? '' ) );
            update_option( 'slw_mautic_client_id',     sanitize_text_field( $_POST['slw_mautic_client_id'] ?? '' ) );
            update_option( 'slw_mautic_client_secret', sanitize_text_field( $_POST['slw_mautic_client_secret'] ?? '' ) );

            // Other provider credentials (stored now, adapters built as needed)
            update_option( 'slw_mailchimp_api_key',         sanitize_text_field( $_POST['slw_mailchimp_api_key'] ?? '' ) );
            update_option( 'slw_activecampaign_url',        esc_url_raw( $_POST['slw_activecampaign_url'] ?? '' ) );
            update_option( 'slw_activecampaign_api_key',    sanitize_text_field( $_POST['slw_activecampaign_api_key'] ?? '' ) );
            update_option( 'slw_klaviyo_api_key',           sanitize_text_field( $_POST['slw_klaviyo_api_key'] ?? '' ) );
            update_option( 'slw_convertkit_api_key',        sanitize_text_field( $_POST['slw_convertkit_api_key'] ?? '' ) );

            // Clear cached token/data when credentials change
            delete_transient( 'slw_mautic_access_token' );
            delete_transient( 'slw_mautic_campaigns' );
            delete_transient( 'slw_mautic_email_stats' );

            $saved = true;
        }

        // Handle refresh button
        if ( isset( $_GET['slw_refresh'] ) && wp_verify_nonce( $_GET['slw_refresh'], 'slw_refresh_sequences' ) ) {
            delete_transient( 'slw_mautic_campaigns' );
            delete_transient( 'slw_mautic_email_stats' );
            delete_transient( 'slw_mautic_access_token' );
        }

        // Load data
        $provider   = get_option( 'slw_email_provider', 'mautic' );
        $mautic_url = rtrim( get_option( 'slw_mautic_url', '' ), '/' );
        $has_config = ! empty( $mautic_url ) && ! empty( get_option( 'slw_mautic_client_id', '' ) ) && ! empty( get_option( 'slw_mautic_client_secret', '' ) );

        $campaigns   = array();
        $email_stats = array();
        $api_error   = '';
        $connected   = false;

        if ( $provider === 'mautic' && $has_config ) {
            $campaigns_result = self::fetch_campaigns();
            if ( is_wp_error( $campaigns_result ) ) {
                $api_error = $campaigns_result->get_error_message();
            } else {
                $campaigns = $campaigns_result;
                $connected = true;
            }

            $stats_result = self::fetch_email_stats();
            if ( ! is_wp_error( $stats_result ) ) {
                $email_stats = $stats_result;
            }
        }

        // Compute quick stats from email data
        $total_sent     = 0;
        $total_opens    = 0;
        $total_readable = 0; // emails with sentCount > 0
        $total_clicks   = 0;

        foreach ( $email_stats as $email ) {
            $sent = isset( $email['sentCount'] ) ? (int) $email['sentCount'] : 0;
            $total_sent += $sent;
            if ( $sent > 0 ) {
                $total_readable++;
                $total_opens  += isset( $email['readCount'] )  ? (int) $email['readCount']  : 0;
                $total_clicks += isset( $email['clickCount'] ) ? (int) $email['clickCount'] : 0;
            }
        }
        $avg_open_rate = $total_sent > 0 ? round( ( $total_opens / $total_sent ) * 100, 1 ) : 0;

        // Count published campaigns
        $active_count = 0;
        foreach ( $campaigns as $c ) {
            if ( ! empty( $c['isPublished'] ) ) {
                $active_count++;
            }
        }

        // Total contacts in campaigns
        $total_contacts = 0;
        foreach ( $campaigns as $c ) {
            if ( isset( $c['contactCount'] ) ) {
                $total_contacts += (int) $c['contactCount'];
            }
        }

        // Webhook log (includes both webhook POSTs and Mautic API operations)
        $webhook_log = get_option( 'slw_webhook_log', array() );

        // Separate failed entries for the alert banner
        $failed_entries = array();
        if ( is_array( $webhook_log ) ) {
            foreach ( $webhook_log as $entry ) {
                if ( ( $entry['status'] ?? '' ) === 'failed' || ( $entry['status'] ?? '' ) === 'skipped' ) {
                    $failed_entries[] = $entry;
                }
            }
        }

        // Last sync time (when transients were set)
        $last_sync = get_transient( 'slw_mautic_campaigns' ) !== false ? 'Cached (within 15 min)' : 'Not cached';

        // Nonce for AJAX and refresh
        $nonce = wp_create_nonce( 'slw_sequences_nonce' );
        $refresh_url = wp_nonce_url( admin_url( 'admin.php?page=slw-sequences' ), 'slw_refresh_sequences', 'slw_refresh' );

        // Provider display name
        $provider_labels = array(
            'mautic' => 'Mautic', 'mailchimp' => 'Mailchimp', 'activecampaign' => 'ActiveCampaign',
            'klaviyo' => 'Klaviyo', 'convertkit' => 'ConvertKit', 'none' => 'None',
        );
        $provider_label = isset( $provider_labels[ $provider ] ) ? $provider_labels[ $provider ] : ucfirst( $provider );

        // Settings accordion default state: open if no provider configured
        $settings_open = ! $has_config && $provider !== 'none';

        ?>
        <div class="wrap slw-sequences-wrap">
            <h1>Email Sequences</h1>
            <p>Monitor your email campaigns and webhook activity. All editing happens in your email provider.</p>

            <?php if ( $saved ) : ?>
                <div class="notice notice-success is-dismissible"><p>Settings saved.</p></div>
            <?php endif; ?>

            <?php if ( ! $has_config && $provider === 'none' ) : ?>
            <!-- ─── Empty State: No Provider ─── -->
            <div class="slw-seq-empty-state">
                <div class="slw-seq-empty-state__icon">
                    <span class="dashicons dashicons-email-alt"></span>
                </div>
                <h2>Connect your email provider to see campaign stats</h2>
                <p>Link Mautic, Mailchimp, Klaviyo, or another provider to pull in live campaign data, open rates, and click stats.</p>
                <div class="slw-seq-empty-state__logos">
                    <span class="slw-seq-provider-logo">Mautic</span>
                    <span class="slw-seq-provider-logo">Mailchimp</span>
                    <span class="slw-seq-provider-logo">Klaviyo</span>
                    <span class="slw-seq-provider-logo">ActiveCampaign</span>
                </div>
                <button type="button" class="button button-primary slw-seq-configure-btn" onclick="document.getElementById('slw-settings-accordion').open=true;document.getElementById('slw-settings-accordion').scrollIntoView({behavior:'smooth'});">
                    Configure Provider
                </button>
            </div>
            <?php endif; ?>

            <?php if ( $connected ) : ?>

            <!-- ─── Quick Stats with Chart Bars ─── -->
            <div class="slw-stats-grid">
                <div class="slw-stat-card accent-teal">
                    <span class="stat-number"><?php echo esc_html( number_format( $total_sent ) ); ?></span>
                    <span class="stat-label">Emails Sent</span>
                    <svg class="slw-stat-chart" width="80" height="32" viewBox="0 0 80 32">
                        <rect x="4" y="12" width="28" height="20" rx="3" fill="#628393" opacity="0.3"/>
                        <rect x="44" y="2" width="28" height="30" rx="3" fill="#386174"/>
                        <text x="18" y="30" text-anchor="middle" fill="#628393" font-size="7">Prev</text>
                        <text x="58" y="30" text-anchor="middle" fill="#386174" font-size="7">Now</text>
                    </svg>
                </div>
                <div class="slw-stat-card accent-green">
                    <span class="stat-number"><?php echo esc_html( $avg_open_rate ); ?>%</span>
                    <span class="stat-label">Avg Open Rate</span>
                    <svg class="slw-stat-chart" width="80" height="32" viewBox="0 0 80 32">
                        <rect x="4" y="10" width="28" height="22" rx="3" fill="#628393" opacity="0.3"/>
                        <rect x="44" y="4" width="28" height="28" rx="3" fill="#2e7d32"/>
                        <text x="18" y="30" text-anchor="middle" fill="#628393" font-size="7">Prev</text>
                        <text x="58" y="30" text-anchor="middle" fill="#2e7d32" font-size="7">Now</text>
                    </svg>
                </div>
                <div class="slw-stat-card accent-gold">
                    <span class="stat-number"><?php echo esc_html( $active_count ); ?></span>
                    <span class="stat-label">Active Sequences</span>
                    <svg class="slw-stat-chart" width="80" height="32" viewBox="0 0 80 32">
                        <rect x="4" y="16" width="28" height="16" rx="3" fill="#628393" opacity="0.3"/>
                        <rect x="44" y="4" width="28" height="28" rx="3" fill="#D4AF37"/>
                        <text x="18" y="30" text-anchor="middle" fill="#628393" font-size="7">Prev</text>
                        <text x="58" y="30" text-anchor="middle" fill="#D4AF37" font-size="7">Now</text>
                    </svg>
                </div>
                <div class="slw-stat-card">
                    <span class="stat-number"><?php echo esc_html( number_format( $total_contacts ) ); ?></span>
                    <span class="stat-label">Contacts</span>
                    <svg class="slw-stat-chart" width="80" height="32" viewBox="0 0 80 32">
                        <rect x="4" y="10" width="28" height="22" rx="3" fill="#628393" opacity="0.3"/>
                        <rect x="44" y="2" width="28" height="30" rx="3" fill="#386174"/>
                        <text x="18" y="30" text-anchor="middle" fill="#628393" font-size="7">Prev</text>
                        <text x="58" y="30" text-anchor="middle" fill="#386174" font-size="7">Now</text>
                    </svg>
                </div>
            </div>

            <!-- ─── Active Sequences ─── -->
            <h2 class="title">Active Sequences</h2>

            <?php if ( empty( $campaigns ) ) : ?>
                <div class="slw-admin-card">
                    <p>No campaigns found in Mautic. Create your first campaign at
                        <a href="<?php echo esc_url( $mautic_url . '/s/campaigns/new' ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $mautic_url . '/s/campaigns/new' ); ?></a>
                    </p>
                </div>
            <?php else : ?>
                <?php
                // Sort campaigns by saved order, falling back to relevance defaults
                $saved_order = get_option( 'slw_sequence_order', array() );
                $default_order = array(
                    'Wholesale Onboarding' => 1,
                    'Wholesale Cart Recovery' => 2,
                    'Wholesale Payment Reminder' => 3,
                    'Wholesale Referral' => 4,
                    'Wholesale Win-Back' => 5,
                    'Wholesale Anniversary' => 6,
                    'Wholesale Pending' => 7,
                );
                usort( $campaigns, function( $a, $b ) use ( $saved_order, $default_order ) {
                    $a_id = (string) ( $a['id'] ?? 0 );
                    $b_id = (string) ( $b['id'] ?? 0 );
                    // Use saved order if available
                    if ( ! empty( $saved_order ) ) {
                        $a_pos = array_search( $a_id, $saved_order );
                        $b_pos = array_search( $b_id, $saved_order );
                        if ( $a_pos !== false && $b_pos !== false ) return $a_pos - $b_pos;
                        if ( $a_pos !== false ) return -1;
                        if ( $b_pos !== false ) return 1;
                    }
                    // Fall back to relevance-based default
                    $a_score = 99;
                    $b_score = 99;
                    foreach ( $default_order as $keyword => $score ) {
                        if ( stripos( $a['name'] ?? '', $keyword ) !== false ) $a_score = min( $a_score, $score );
                        if ( stripos( $b['name'] ?? '', $keyword ) !== false ) $b_score = min( $b_score, $score );
                    }
                    return $a_score - $b_score;
                });
                ?>
                <p style="font-size:12px;color:#628393;margin-bottom:12px;">Drag to reorder sequences. Order is saved automatically.</p>
                <div id="slw-campaigns-sortable">
                <?php foreach ( $campaigns as $campaign ) :
                    $c_id        = isset( $campaign['id'] ) ? (int) $campaign['id'] : 0;
                    $c_name      = isset( $campaign['name'] ) ? $campaign['name'] : 'Untitled';
                    $c_published = ! empty( $campaign['isPublished'] );
                    $c_contacts  = isset( $campaign['contactCount'] ) ? (int) $campaign['contactCount'] : 0;

                    // Extract emails from campaign events
                    $c_emails = array();
                    if ( ! empty( $campaign['events'] ) ) {
                        foreach ( $campaign['events'] as $event ) {
                            if ( isset( $event['eventType'] ) && $event['eventType'] === 'action'
                                && isset( $event['type'] ) && strpos( $event['type'], 'email' ) !== false ) {
                                $email_id = 0;
                                if ( isset( $event['properties']['email'] ) ) {
                                    $email_id = (int) $event['properties']['email'];
                                }
                                $trigger_mode = isset( $event['triggerMode'] ) ? $event['triggerMode'] : '';
                                $trigger_interval = isset( $event['triggerInterval'] ) ? (int) $event['triggerInterval'] : 0;
                                $trigger_unit = isset( $event['triggerIntervalUnit'] ) ? $event['triggerIntervalUnit'] : 'd';

                                $timing = 'Immediate';
                                if ( $trigger_mode === 'interval' && $trigger_interval > 0 ) {
                                    $unit_label = $trigger_unit === 'i' ? 'min' : ( $trigger_unit === 'h' ? 'hr' : ( $trigger_unit === 'd' ? 'day' : $trigger_unit ) );
                                    $timing = $trigger_interval . ' ' . $unit_label . ( $trigger_interval > 1 ? 's' : '' );
                                }

                                $email_data = isset( $email_stats[ $email_id ] ) ? $email_stats[ $email_id ] : null;
                                $c_emails[] = array(
                                    'event_name' => isset( $event['name'] ) ? $event['name'] : 'Email',
                                    'email_id'   => $email_id,
                                    'subject'    => $email_data && isset( $email_data['subject'] ) ? $email_data['subject'] : '(Subject unavailable)',
                                    'timing'     => $timing,
                                    'sent'       => $email_data && isset( $email_data['sentCount'] )  ? (int) $email_data['sentCount']  : 0,
                                    'opens'      => $email_data && isset( $email_data['readCount'] )  ? (int) $email_data['readCount']  : 0,
                                    'clicks'     => $email_data && isset( $email_data['clickCount'] ) ? (int) $email_data['clickCount'] : 0,
                                );
                            }
                        }
                    }
                    $email_count = count( $c_emails );
                ?>
                <div class="slw-admin-card slw-campaign-card <?php echo $c_published ? 'slw-campaign-card--active' : 'slw-campaign-card--inactive'; ?>" draggable="true" data-campaign-id="<?php echo esc_attr( $c_id ); ?>">
                    <div class="slw-campaign-header" data-campaign="<?php echo esc_attr( $c_id ); ?>">
                        <div class="slw-campaign-info">
                            <span class="slw-campaign-icon dashicons dashicons-email-alt"></span>
                            <div>
                                <h3>
                                    <?php echo esc_html( $c_name ); ?>
                                    <span class="slw-status-badge <?php echo $c_published ? 'slw-badge-published' : 'slw-badge-unpublished'; ?>">
                                        <?php echo $c_published ? 'Active' : 'Inactive'; ?>
                                    </span>
                                </h3>
                                <div class="slw-campaign-meta">
                                    <span><?php echo esc_html( $c_contacts ); ?> contact<?php echo $c_contacts !== 1 ? 's' : ''; ?></span>
                                    <span class="slw-meta-sep">&middot;</span>
                                    <span><?php echo esc_html( $email_count ); ?> email<?php echo $email_count !== 1 ? 's' : ''; ?></span>
                                </div>
                            </div>
                        </div>
                        <button type="button" class="button slw-toggle-emails" aria-expanded="false">
                            &#9660; Show Emails
                        </button>
                    </div>

                    <?php if ( ! empty( $c_emails ) ) : ?>
                    <div class="slw-campaign-emails" style="display:none;">
                        <div class="slw-email-timeline">
                            <?php foreach ( $c_emails as $idx => $ce ) :
                                $open_rate  = $ce['sent'] > 0 ? round( ( $ce['opens'] / $ce['sent'] ) * 100, 1 ) : 0;
                                $click_rate = $ce['sent'] > 0 ? round( ( $ce['clicks'] / $ce['sent'] ) * 100, 1 ) : 0;
                                $open_class = $open_rate >= 50 ? 'slw-pill--green' : ( $open_rate >= 30 ? 'slw-pill--yellow' : 'slw-pill--red' );
                                $click_class = $click_rate >= 50 ? 'slw-pill--green' : ( $click_rate >= 30 ? 'slw-pill--yellow' : 'slw-pill--red' );
                            ?>
                            <div class="slw-timeline-node <?php echo $idx === count( $c_emails ) - 1 ? 'slw-timeline-node--last' : ''; ?>">
                                <div class="slw-timeline-dot"></div>
                                <div class="slw-timeline-content">
                                    <div class="slw-timeline-subject"><?php echo esc_html( $ce['subject'] ); ?></div>
                                    <div class="slw-timeline-meta">
                                        <span class="slw-timing-pill"><?php echo esc_html( $ce['timing'] ); ?></span>
                                        <span class="slw-stat-pill slw-pill--gray"><?php echo esc_html( number_format( $ce['sent'] ) ); ?> sent</span>
                                        <?php if ( $ce['sent'] > 0 ) : ?>
                                            <span class="slw-stat-pill <?php echo esc_attr( $open_class ); ?>"><?php echo esc_html( $open_rate ); ?>% opened</span>
                                            <span class="slw-stat-pill <?php echo esc_attr( $click_class ); ?>"><?php echo esc_html( $click_rate ); ?>% clicked</span>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ( $ce['email_id'] > 0 ) : ?>
                                        <a href="<?php echo esc_url( $mautic_url . '/s/emails/edit/' . $ce['email_id'] ); ?>"
                                           target="_blank" rel="noopener" class="button button-small slw-timeline-edit">Edit</a>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
                </div><!-- #slw-campaigns-sortable -->
            <?php endif; ?>

            <?php endif; /* end if $connected */ ?>

            <!-- ─── Compose Newsletter ─── -->
            <div class="slw-newsletter-section">
                <div class="slw-newsletter-header">
                    <h2 class="title">Newsletters</h2>
                    <button type="button" id="slw-compose-newsletter-btn" style="background:#386174 !important;color:#F7F6F3 !important;border:none !important;padding:10px 24px !important;font-size:14px !important;font-weight:700 !important;border-radius:8px !important;cursor:pointer;font-family:Georgia,'Times New Roman',serif !important;letter-spacing:0.3px;">&#9650; Collapse</button>
                </div>

                <div id="slw-newsletter-compose" class="slw-admin-card slw-newsletter-compose">
                    <h3>Compose Newsletter</h3>
                    <?php
                    $saved_templates = get_option( 'slw_newsletter_templates', array() );
                    if ( empty( $saved_templates ) ) {
                        // Seed with the two default templates
                        $brand  = class_exists( 'SLW_Email_Settings' ) ? SLW_Email_Settings::get_business_name() : get_bloginfo( 'name' );
                        $owner  = class_exists( 'SLW_Email_Settings' ) ? SLW_Email_Settings::get( 'owner_name' ) : '';
                        if ( ! $owner ) $owner = 'Holly';
                        $w_url  = home_url( '/wholesale-partners' );
                        $p_url  = home_url( '/wholesale-portal' );
                        $saved_templates = array(
                            'wholesale-outreach' => array(
                                'name'    => 'Wholesale Outreach',
                                'subject' => "Carry {$brand} in your shop?",
                                'body'    => "Hi {shop_owner_name},\n\nI came across {shop_name} and love what you're doing. I think our products would be a great fit for your customers.\n\nI'm {$owner} — I run {$brand}. We make small-batch, clean-ingredient skincare and our wholesale partners get 50% off retail pricing.\n\nIf you're open to it, I'd love to send you our price list or set up a quick call:\n\n{$w_url}\n\nNo pressure at all — just thought it could be a good match.\n\nTalk soon,\n{$owner}",
                            ),
                            'quarterly-newsletter' => array(
                                'name'    => 'Quarterly Newsletter',
                                'subject' => "What's new this quarter at {$brand}",
                                'body'    => "Hi there,\n\nHope business is going well! Here's a quick update from our end.\n\nNEW PRODUCTS\n[Describe any new products launched this quarter]\n\nWHAT'S SELLING BEST\n[Share your top 2-3 bestsellers and why customers love them]\n\nSEASONAL RECOMMENDATION\n[Suggest a product or bundle that fits the upcoming season]\n\nREORDER\nReady to restock? Place your next order here:\n{$p_url}\n\nAs always, reach out anytime if you need samples, marketing materials, or just want to chat.\n\nThanks for being a partner,\n{$owner}",
                            ),
                        );
                        update_option( 'slw_newsletter_templates', $saved_templates );
                    }
                    ?>
                    <table class="form-table">
                        <tr>
                            <th scope="row"><label for="slw-nl-template">Template</label></th>
                            <td>
                                <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                                    <select id="slw-nl-template" style="min-width:200px;">
                                        <option value="">— Choose a template (<?php echo count( $saved_templates ); ?> saved) —</option>
                                        <option value="__blank">Blank (start fresh)</option>
                                        <?php foreach ( $saved_templates as $tpl_slug => $tpl ) : ?>
                                            <option value="<?php echo esc_attr( $tpl_slug ); ?>"
                                                data-subject="<?php echo esc_attr( $tpl['subject'] ); ?>"
                                                data-body="<?php echo esc_attr( $tpl['body'] ); ?>">
                                                <?php echo esc_html( $tpl['name'] ); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="button" class="button" id="slw-nl-load-tpl">Load</button>
                                    <button type="button" class="button" id="slw-nl-save-tpl">Save Current as Template</button>
                                    <button type="button" class="button" id="slw-nl-undo-tpl" style="display:none;" title="Undo last save">&#8630; Undo</button>
                                    <button type="button" class="button" id="slw-nl-delete-tpl" style="color:#c62828;">Delete Template</button>
                                </div>
                                <p class="description" style="margin-top:6px;">Load a template to pre-fill the subject and body, or save your current draft as a new reusable template.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="slw-nl-subject">Subject Line</label></th>
                            <td><input type="text" id="slw-nl-subject" class="large-text" placeholder="Your newsletter subject..." /></td>
                        </tr>
                        <tr>
                            <th scope="row"><label>Body</label></th>
                            <td>
                                <?php
                                wp_editor( '', 'slw_nl_body', array(
                                    'textarea_name' => 'slw_nl_body',
                                    'media_buttons' => true,
                                    'textarea_rows' => 24,
                                    'teeny'         => false,
                                    'quicktags'     => true,
                                ) );
                                ?>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Audience</th>
                            <td>
                                <fieldset>
                                    <label><input type="radio" name="slw_nl_audience" value="all" checked /> All wholesale customers</label><br>
                                    <label><input type="radio" name="slw_nl_audience" value="standard" /> Standard tier only</label><br>
                                    <label><input type="radio" name="slw_nl_audience" value="preferred" /> Preferred tier only</label><br>
                                    <label><input type="radio" name="slw_nl_audience" value="vip" /> VIP tier only</label><br>
                                    <label><input type="radio" name="slw_nl_audience" value="select" /> Select individually</label><br>
                                    <label><input type="radio" name="slw_nl_audience" value="custom" /> Custom email address</label>
                                </fieldset>
                                <div id="slw-nl-individual-select" style="display:none;margin-top:10px;">
                                    <select id="slw-nl-recipients" multiple="multiple" style="width:100%;min-height:120px;">
                                        <?php
                                        $ws_users = get_users( array( 'role' => 'wholesale_customer' ) );
                                        foreach ( $ws_users as $ws_user ) :
                                            $tier_label = '';
                                            if ( class_exists( 'SLW_Tiers' ) ) {
                                                $tier = SLW_Tiers::get_user_tier( $ws_user->ID );
                                                if ( $tier ) $tier_label = ' (' . esc_html( $tier ) . ')';
                                            }
                                        ?>
                                            <option value="<?php echo esc_attr( $ws_user->ID ); ?>">
                                                <?php echo esc_html( $ws_user->display_name . ' <' . $ws_user->user_email . '>' . $tier_label ); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <p class="description">Hold Ctrl/Cmd to select multiple customers.</p>
                                </div>
                                <div id="slw-nl-custom-email" style="display:none;margin-top:10px;">
                                    <input type="email" id="slw-nl-custom-address" class="regular-text" placeholder="name@example.com" />
                                    <p class="description">Enter any email address. Useful for sending to prospects not yet in your wholesale list.</p>
                                </div>
                            </td>
                        </tr>
                    </table>
                    <div style="margin-bottom:12px;display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
                        <label style="font-size:13px;color:#628393;">
                            <input type="checkbox" id="slw-nl-schedule-toggle" /> Schedule for later
                        </label>
                        <input type="datetime-local" id="slw-nl-schedule-time" style="display:none;" min="<?php echo esc_attr( date( 'Y-m-d\TH:i', strtotime( '+1 hour' ) ) ); ?>" />
                    </div>
                    <div class="slw-newsletter-actions">
                        <button type="button" class="button button-primary" id="slw-send-newsletter-btn" data-nonce="<?php echo esc_attr( $nonce ); ?>">Send Newsletter</button>
                        <button type="button" class="button" id="slw-cancel-newsletter-btn">Cancel</button>
                        <span id="slw-newsletter-status" style="margin-left:12px;"></span>
                    </div>
                </div>

                <?php
                // Newsletter history
                $newsletter_log = get_option( 'slw_newsletter_log', array() );
                if ( ! empty( $newsletter_log ) ) :
                ?>
                <div class="slw-admin-card" style="margin-top:12px;">
                    <h3>Recent Newsletters</h3>
                    <table class="wp-list-table widefat striped">
                        <thead>
                            <tr>
                                <th>Subject</th>
                                <th>Recipients</th>
                                <th>Sent By</th>
                                <th>Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( array_slice( $newsletter_log, 0, 20 ) as $nl_entry ) : ?>
                            <tr>
                                <td><?php echo esc_html( $nl_entry['subject'] ?? '' ); ?></td>
                                <td><?php echo esc_html( $nl_entry['recipient_count'] ?? 0 ); ?> contact(s)</td>
                                <td><?php echo esc_html( $nl_entry['sent_by'] ?? '' ); ?></td>
                                <td><?php echo esc_html( $nl_entry['date'] ?? '' ); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>

            <!-- ─── Transactional Emails (sent by WordPress, not Mautic) ─── -->
            <h2 class="title">Transactional Emails</h2>
            <p style="color:#628393;font-size:13px;margin:-8px 0 16px;">These are sent instantly by WordPress when specific actions happen. They handle logistics (credentials, invoices, confirmations) while your campaign sequences above handle relationship-building and marketing.</p>

            <div class="slw-admin-card" style="padding:0;overflow:hidden;">
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th style="width:30%;">Email</th>
                            <th style="width:30%;">When It Sends</th>
                            <th style="width:25%;">What It Contains</th>
                            <th style="width:15%;">Sent Via</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><strong>Welcome + Login Credentials</strong></td>
                            <td>Immediately when you approve an application</td>
                            <td>Username, password, login URL, first order minimum, link to order form</td>
                            <td><span class="slw-pill--gray" style="font-size:11px;">WordPress</span></td>
                        </tr>
                        <tr>
                            <td><strong>Application Declined</strong></td>
                            <td>Immediately when you decline an application</td>
                            <td>Polite rejection with retail store link</td>
                            <td><span class="slw-pill--gray" style="font-size:11px;">WordPress</span></td>
                        </tr>
                        <tr>
                            <td><strong>New Application Alert</strong></td>
                            <td>When a prospect submits the wholesale application form</td>
                            <td>Business name, contact info, link to review in admin (sent to you, not the applicant)</td>
                            <td><span class="slw-pill--gray" style="font-size:11px;">WordPress</span></td>
                        </tr>
                        <tr>
                            <td><strong>Quote Response</strong></td>
                            <td>When you send a quote from the Quotes page</td>
                            <td>Quoted products, prices, and your message</td>
                            <td><span class="slw-pill--gray" style="font-size:11px;">WordPress</span></td>
                        </tr>
                        <tr>
                            <td><strong>Invoice</strong></td>
                            <td>When you click "Send Invoice" on an order</td>
                            <td>Link to the branded invoice page (printable)</td>
                            <td><span class="slw-pill--gray" style="font-size:11px;">WordPress</span></td>
                        </tr>
                        <tr>
                            <td><strong>Import Welcome</strong></td>
                            <td>When you import a customer via CSV or manual add (if "Send welcome email" is checked)</td>
                            <td>Login credentials, same as the approval welcome email</td>
                            <td><span class="slw-pill--gray" style="font-size:11px;">WordPress</span></td>
                        </tr>
                    </tbody>
                </table>
                <div style="padding:12px 16px;background:#faf8f5;border-top:1px solid #e0ddd8;font-size:12px;color:#628393;">
                    Transactional emails use the sender name, address, and signature configured in <a href="<?php echo esc_url( admin_url( 'admin.php?page=slw-invoice-settings' ) ); ?>">Wholesale &rarr; Invoices &rarr; Email Settings</a>. They are sent through your WordPress SMTP configuration (WP Mail SMTP plugin).
                </div>
            </div>

            <!-- ─── Webhook & Email Activity ─── -->
            <?php
            $last_webhook      = ! empty( $webhook_log ) ? $webhook_log[0] : null;
            $last_webhook_time = $last_webhook ? human_time_diff( strtotime( $last_webhook['time'] ?? '' ) ) . ' ago' : 'Never';
            $last_webhook_ok   = $last_webhook && ( $last_webhook['status'] ?? '' ) === 'success';
            $fail_count        = count( $failed_entries );
            $fail_summary      = $fail_count > 0 ? $fail_count . ' failed' : 'All healthy';
            ?>
            <details class="slw-seq-accordion"<?php echo $fail_count > 0 ? ' open' : ''; ?>>
                <summary class="slw-seq-accordion__bar">
                    <span class="slw-seq-accordion__title">Webhook &amp; Email Activity</span>
                    <span class="slw-seq-accordion__summary">
                        Last activity: <?php echo esc_html( $last_webhook_time ); ?>
                        <?php if ( $last_webhook ) : ?>
                            (<span class="<?php echo $last_webhook_ok ? 'slw-pill--green' : 'slw-pill--red'; ?>"><?php echo esc_html( $last_webhook_ok ? 'success' : 'failed' ); ?></span>)
                        <?php endif; ?>
                        &middot; <?php echo esc_html( $fail_summary ); ?>
                    </span>
                    <span class="slw-seq-accordion__arrow dashicons dashicons-arrow-down-alt2"></span>
                </summary>
                <?php if ( empty( $webhook_log ) ) : ?>
                    <div class="slw-admin-card">
                        <p>No activity recorded yet. Events are logged when applications are approved, first orders are placed, Mautic contacts are tagged, or reorder reminders trigger.</p>
                    </div>
                <?php else : ?>
                    <?php if ( $fail_count > 0 ) :
                        $fail_categories = array();
                        foreach ( $failed_entries as $f ) {
                            $evt = $f['event'] ?? '';
                            $cat = strpos( $evt, 'mautic:' ) === 0 ? 'Mautic tagging' : 'Webhook delivery';
                            $fail_categories[ $cat ] = ( $fail_categories[ $cat ] ?? 0 ) + 1;
                        }
                        $category_summary = array();
                        foreach ( $fail_categories as $cat => $cnt ) {
                            $category_summary[] = $cnt . ' ' . $cat;
                        }
                    ?>
                    <div id="slw-failures-section" style="border-left:4px solid #c62828;padding:20px 24px;margin:0;background:#fff;">
                        <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap;">
                            <div style="flex:1;min-width:250px;">
                                <div style="display:flex;align-items:center;gap:10px;margin-bottom:6px;">
                                    <span class="dashicons dashicons-warning" style="color:#c62828;font-size:20px;"></span>
                                    <h3 style="margin:0;font-size:16px;color:#1E2A30;"><?php echo esc_html( $fail_count ); ?> delivery failure<?php echo $fail_count !== 1 ? 's' : ''; ?> detected</h3>
                                </div>
                                <p style="margin:0 0 0 30px;font-size:13px;color:#628393;">
                                    <?php echo esc_html( implode( ', ', $category_summary ) ); ?>.
                                    Affected contacts may not be entering Mautic campaigns.
                                </p>
                            </div>
                            <button type="button" class="button" id="slw-clear-failures-btn" style="white-space:nowrap;" title="Remove failed entries from the log">Clear Failures</button>
                        </div>
                        <div style="margin-top:16px;display:flex;flex-direction:column;gap:8px;">
                            <?php foreach ( array_slice( $failed_entries, 0, 8 ) as $fail ) :
                                $f_event      = $fail['event'] ?? '';
                                $f_email      = $fail['email'] ?? '';
                                $f_detail     = $fail['code'] ?? '';
                                $f_time       = $fail['time'] ?? '';
                                $f_is_mautic  = strpos( $f_event, 'mautic:' ) === 0;
                                $f_label      = $f_is_mautic ? substr( $f_event, 7 ) : $f_event;
                                $f_source     = $f_is_mautic ? 'Mautic' : 'Webhook';
                                $f_ago        = $f_time ? human_time_diff( strtotime( $f_time ) ) . ' ago' : '';
                            ?>
                            <div style="display:flex;align-items:center;gap:10px;padding:10px 14px;background:#fdf2f2;border-radius:6px;flex-wrap:wrap;">
                                <span class="slw-pill--red" style="padding:2px 8px;border-radius:12px;font-size:11px;font-weight:600;"><?php echo esc_html( $f_source ); ?></span>
                                <code style="font-size:12px;color:#1E2A30;background:rgba(0,0,0,0.04);padding:2px 6px;border-radius:3px;"><?php echo esc_html( $f_label ); ?></code>
                                <?php if ( $f_email ) : ?>
                                    <span style="font-size:13px;color:#1E2A30;">&rarr; <?php echo esc_html( $f_email ); ?></span>
                                <?php endif; ?>
                                <span style="flex:1;"></span>
                                <span style="font-size:12px;color:#628393;"><?php echo esc_html( $f_ago ); ?></span>
                            </div>
                            <?php if ( $f_detail && ! is_numeric( $f_detail ) ) : ?>
                            <div style="margin:-4px 0 0 34px;font-size:12px;color:#c62828;padding-left:14px;border-left:2px solid #fbe9e7;">
                                <?php echo esc_html( strlen( $f_detail ) > 120 ? substr( $f_detail, 0, 120 ) . '...' : $f_detail ); ?>
                            </div>
                            <?php endif; ?>
                            <?php endforeach; ?>
                            <?php if ( $fail_count > 8 ) : ?>
                            <div style="text-align:center;font-size:12px;color:#628393;padding:4px 0;">
                                + <?php echo esc_html( $fail_count - 8 ); ?> more in log below
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <script>
                    (function() {
                        var btn = document.getElementById('slw-clear-failures-btn');
                        if (!btn) return;
                        btn.addEventListener('click', function() {
                            if (!confirm('Clear all failed entries from the log? This only clears the log — it does not retry the failed events.')) return;
                            btn.disabled = true;
                            btn.textContent = 'Clearing...';
                            var formData = new FormData();
                            formData.append('action', 'slw_clear_failed_log');
                            formData.append('nonce', '<?php echo esc_js( $nonce ); ?>');
                            var xhr = new XMLHttpRequest();
                            xhr.open('POST', ajaxurl);
                            xhr.onload = function() {
                                var section = document.getElementById('slw-failures-section');
                                if (section) section.style.display = 'none';
                            };
                            xhr.send(formData);
                        });
                    })();
                    </script>
                    <?php endif; ?>

                    <div style="padding:16px 24px;border-top:<?php echo $fail_count > 0 ? '1px solid #e0ddd8' : 'none'; ?>;">
                        <?php if ( $fail_count > 0 ) : ?>
                            <p style="font-size:12px;color:#628393;margin:0 0 12px;font-weight:600;text-transform:uppercase;letter-spacing:0.5px;">Activity Log</p>
                        <?php endif; ?>
                        <div style="display:flex;flex-direction:column;gap:6px;">
                            <?php
                            $log_entries = $fail_count > 0
                                ? array_filter( $webhook_log, function( $e ) { return ( $e['status'] ?? '' ) !== 'failed'; } )
                                : $webhook_log;
                            foreach ( array_slice( array_values( $log_entries ), 0, 50 ) as $entry ) :
                                $wh_event  = $entry['event'] ?? '';
                                $wh_status = $entry['status'] ?? 'unknown';
                                $wh_detail = $entry['code'] ?? '-';
                                $is_mautic = strpos( $wh_event, 'mautic:' ) === 0;
                                $wh_label  = $is_mautic ? substr( $wh_event, 7 ) : $wh_event;
                                $wh_source = $is_mautic ? 'Mautic' : 'Webhook';
                                $wh_ago    = ! empty( $entry['time'] ) ? human_time_diff( strtotime( $entry['time'] ) ) . ' ago' : '';
                                $pill_class = $wh_status === 'skipped' ? 'slw-pill--yellow' : 'slw-pill--green';
                                $row_bg     = $wh_status === 'skipped' ? '#fffdf5' : '#f6faf6';
                            ?>
                            <div style="display:flex;align-items:center;gap:10px;padding:8px 14px;background:<?php echo esc_attr( $row_bg ); ?>;border-radius:6px;flex-wrap:wrap;">
                                <span class="<?php echo esc_attr( $pill_class ); ?>" style="padding:2px 8px;border-radius:12px;font-size:11px;font-weight:600;"><?php echo esc_html( $wh_source ); ?></span>
                                <code style="font-size:12px;color:#1E2A30;background:rgba(0,0,0,0.04);padding:2px 6px;border-radius:3px;"><?php echo esc_html( $wh_label ); ?></code>
                                <?php if ( ! empty( $entry['email'] ) ) : ?>
                                    <span style="font-size:13px;color:#1E2A30;">&rarr; <?php echo esc_html( $entry['email'] ); ?></span>
                                <?php endif; ?>
                                <span style="flex:1;"></span>
                                <span style="font-size:12px;color:#628393;"><?php echo esc_html( $wh_ago ); ?></span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </details>

            <!-- ─── Email Provider Settings (last section) ─── -->
            <details id="slw-settings-accordion" class="slw-seq-accordion slw-seq-accordion--settings" <?php echo $settings_open ? 'open' : ''; ?>>
                <summary class="slw-seq-accordion__bar">
                    <span class="slw-seq-accordion__title">
                        Email Provider: <?php echo esc_html( $provider_label ); ?>
                        <?php if ( $connected ) : ?>
                            <span class="slw-pill--green" style="margin-left:8px;">Connected</span>
                            <span style="margin-left:12px;font-size:12px;font-weight:400;color:#628393;">
                                <?php echo esc_html( wp_parse_url( $mautic_url, PHP_URL_HOST ) ); ?> &middot; <?php echo esc_html( $last_sync ); ?>
                            </span>
                        <?php elseif ( $has_config && $api_error ) : ?>
                            <span class="slw-pill--red" style="margin-left:8px;">Error: <?php echo esc_html( $api_error ); ?></span>
                        <?php elseif ( $provider === 'none' ) : ?>
                            <span style="margin-left:8px;font-size:12px;color:#628393;">Webhook only</span>
                        <?php endif; ?>
                    </span>
                    <span class="slw-seq-accordion__arrow dashicons dashicons-arrow-down-alt2"></span>
                </summary>
            <div class="slw-admin-card" style="border-radius:0 0 8px 8px;margin-top:0;">
                <form method="post">
                    <?php wp_nonce_field( 'slw_sequences_nonce' ); ?>
                    <input type="hidden" name="slw_sequences_save" value="1" />
                    <table class="form-table">
                        <tr>
                            <th scope="row"><label for="slw_email_provider">Provider</label></th>
                            <td>
                                <select id="slw_email_provider" name="slw_email_provider">
                                    <option value="none" <?php selected( $provider, 'none' ); ?>>Webhook Only (no provider)</option>
                                    <option value="mautic" <?php selected( $provider, 'mautic' ); ?>>Mautic</option>
                                    <option value="mailchimp" <?php selected( $provider, 'mailchimp' ); ?>>Mailchimp</option>
                                    <option value="activecampaign" <?php selected( $provider, 'activecampaign' ); ?>>ActiveCampaign</option>
                                    <option value="klaviyo" <?php selected( $provider, 'klaviyo' ); ?>>Klaviyo</option>
                                    <option value="convertkit" <?php selected( $provider, 'convertkit' ); ?>>ConvertKit</option>
                                </select>
                                <p class="description">Select your email marketing platform. Webhooks fire regardless — the provider integration adds campaign stats and deep links to this dashboard.</p>
                            </td>
                        </tr>
                        <tr class="slw-mautic-fields">
                            <th scope="row"><label for="slw_mautic_url">Mautic URL</label></th>
                            <td>
                                <input type="url" id="slw_mautic_url" name="slw_mautic_url"
                                       value="<?php echo esc_attr( get_option( 'slw_mautic_url', '' ) ); ?>"
                                       class="regular-text" placeholder="https://marketing.example.com" />
                                <p class="description">Your Mautic instance URL (no trailing slash).</p>
                            </td>
                        </tr>
                        <tr class="slw-mautic-fields">
                            <th scope="row"><label for="slw_mautic_client_id">Client ID</label></th>
                            <td>
                                <input type="text" id="slw_mautic_client_id" name="slw_mautic_client_id"
                                       value="<?php echo esc_attr( get_option( 'slw_mautic_client_id', '' ) ); ?>"
                                       class="regular-text" />
                                <p class="description">OAuth2 Client ID from Mautic API Credentials.</p>
                            </td>
                        </tr>
                        <tr class="slw-mautic-fields">
                            <th scope="row"><label for="slw_mautic_client_secret">Client Secret</label></th>
                            <td>
                                <input type="password" id="slw_mautic_client_secret" name="slw_mautic_client_secret"
                                       value="<?php echo esc_attr( get_option( 'slw_mautic_client_secret', '' ) ); ?>"
                                       class="regular-text" autocomplete="new-password" />
                                <p class="description">OAuth2 Client Secret. Stored securely in the database.</p>
                            </td>
                        </tr>
                    </table>
                    <table class="form-table slw-provider-fields slw-mailchimp-fields" style="display:none;">
                        <tr>
                            <th scope="row"><label for="slw_mailchimp_api_key">API Key</label></th>
                            <td>
                                <input type="password" id="slw_mailchimp_api_key" name="slw_mailchimp_api_key"
                                       value="<?php echo esc_attr( get_option( 'slw_mailchimp_api_key', '' ) ); ?>"
                                       class="regular-text" autocomplete="new-password" placeholder="xxxxxxxx-us21" />
                                <p class="description">Your Mailchimp API key (includes the server prefix). Find it in Account > Extras > API Keys.</p>
                            </td>
                        </tr>
                    </table>
                    <table class="form-table slw-provider-fields slw-activecampaign-fields" style="display:none;">
                        <tr>
                            <th scope="row"><label for="slw_activecampaign_url">Account URL</label></th>
                            <td><input type="url" id="slw_activecampaign_url" name="slw_activecampaign_url" value="<?php echo esc_attr( get_option( 'slw_activecampaign_url', '' ) ); ?>" class="regular-text" placeholder="https://yourname.api-us1.com" /></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="slw_activecampaign_api_key">API Key</label></th>
                            <td><input type="password" id="slw_activecampaign_api_key" name="slw_activecampaign_api_key" value="<?php echo esc_attr( get_option( 'slw_activecampaign_api_key', '' ) ); ?>" class="regular-text" autocomplete="new-password" /><p class="description">Settings > Developer > API Access.</p></td>
                        </tr>
                    </table>
                    <table class="form-table slw-provider-fields slw-klaviyo-fields" style="display:none;">
                        <tr>
                            <th scope="row"><label for="slw_klaviyo_api_key">Private API Key</label></th>
                            <td><input type="password" id="slw_klaviyo_api_key" name="slw_klaviyo_api_key" value="<?php echo esc_attr( get_option( 'slw_klaviyo_api_key', '' ) ); ?>" class="regular-text" autocomplete="new-password" /><p class="description">Account > Settings > API Keys > Private Key.</p></td>
                        </tr>
                    </table>
                    <table class="form-table slw-provider-fields slw-convertkit-fields" style="display:none;">
                        <tr>
                            <th scope="row"><label for="slw_convertkit_api_key">API Key</label></th>
                            <td><input type="password" id="slw_convertkit_api_key" name="slw_convertkit_api_key" value="<?php echo esc_attr( get_option( 'slw_convertkit_api_key', '' ) ); ?>" class="regular-text" autocomplete="new-password" /><p class="description">Settings > Advanced > API Key.</p></td>
                        </tr>
                    </table>
                    <div class="slw-provider-coming-soon" style="display:none;padding:16px 20px;background:#fff8e1;border:1px solid #ffe082;border-radius:6px;margin:12px 0;">
                        <strong style="color:#e65100;">Coming Soon:</strong>
                        <span style="color:#2C2C2C;">Full campaign stats and deep links for this provider are in development. Save your credentials now — webhook integration works immediately.</span>
                    </div>
                    <div class="slw-mautic-fields" style="margin-bottom:16px;display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                        <button type="button" id="slw-test-connection" class="button" data-nonce="<?php echo esc_attr( $nonce ); ?>">Test Connection</button>
                        <?php if ( $connected ) : ?>
                            <a href="<?php echo esc_url( $refresh_url ); ?>" class="button">&#8635; Refresh Data</a>
                        <?php endif; ?>
                        <span id="slw-test-result" style="margin-left:4px;"></span>
                    </div>
                    <?php submit_button( 'Save Provider Settings' ); ?>
                </form>
            </div>
            </details>

        </div>

        <script>
        (function($) {
            // Drag-and-drop reorder for campaign cards
            (function() {
                var container = document.getElementById('slw-campaigns-sortable');
                if (!container) return;
                var dragEl = null;

                container.addEventListener('dragstart', function(e) {
                    dragEl = e.target.closest('.slw-campaign-card');
                    if (!dragEl) return;
                    dragEl.style.opacity = '0.4';
                    e.dataTransfer.effectAllowed = 'move';
                });

                container.addEventListener('dragover', function(e) {
                    e.preventDefault();
                    e.dataTransfer.dropEffect = 'move';
                    var target = e.target.closest('.slw-campaign-card');
                    if (target && target !== dragEl) {
                        var rect = target.getBoundingClientRect();
                        var midY = rect.top + rect.height / 2;
                        if (e.clientY < midY) {
                            container.insertBefore(dragEl, target);
                        } else {
                            container.insertBefore(dragEl, target.nextSibling);
                        }
                    }
                });

                container.addEventListener('dragend', function(e) {
                    if (dragEl) dragEl.style.opacity = '1';
                    dragEl = null;
                    // Save new order via AJAX
                    var cards = container.querySelectorAll('.slw-campaign-card');
                    var order = [];
                    cards.forEach(function(card) { order.push(card.getAttribute('data-campaign-id')); });
                    $.post(ajaxurl, {
                        action: 'slw_save_sequence_order',
                        nonce: '<?php echo esc_js( wp_create_nonce( "slw_sequences_nonce" ) ); ?>',
                        order: order
                    });
                });
            })();

            // Toggle email list
            $(document).on('click', '.slw-toggle-emails', function() {
                var $btn = $(this);
                var $emails = $btn.closest('.slw-campaign-card').find('.slw-campaign-emails');
                var expanded = $btn.attr('aria-expanded') === 'true';

                $emails.slideToggle(200);
                $btn.attr('aria-expanded', !expanded);
                $btn.html(expanded ? '&#9660; Show Emails' : '&#9650; Hide Emails');
            });

            // Show/hide provider-specific fields based on selection
            function toggleProviderFields() {
                var provider = $('#slw_email_provider').val();
                // Hide all provider field groups
                $('.slw-mautic-fields, .slw-provider-fields, .slw-provider-coming-soon').hide();
                // Show the selected provider's fields
                if (provider === 'mautic') {
                    $('.slw-mautic-fields').show();
                } else if (provider === 'mailchimp') {
                    $('.slw-mailchimp-fields').show();
                    $('.slw-provider-coming-soon').show();
                } else if (provider === 'activecampaign') {
                    $('.slw-activecampaign-fields').show();
                    $('.slw-provider-coming-soon').show();
                } else if (provider === 'klaviyo') {
                    $('.slw-klaviyo-fields').show();
                    $('.slw-provider-coming-soon').show();
                } else if (provider === 'convertkit') {
                    $('.slw-convertkit-fields').show();
                    $('.slw-provider-coming-soon').show();
                }
                // Test Connection button only shows for Mautic (only active adapter)
                $('#slw-test-connection').toggle(provider === 'mautic');
            }
            $('#slw_email_provider').on('change', toggleProviderFields);
            toggleProviderFields();

            // Newsletter compose toggle
            var nlBtn = $('#slw-compose-newsletter-btn');
            var nlCompose = $('#slw-newsletter-compose');
            function updateNlBtn() {
                if (nlCompose.is(':visible')) {
                    nlBtn.html('&#9650; Collapse');
                } else {
                    nlBtn.html('+ New Newsletter');
                }
            }
            nlBtn.on('click', function() {
                nlCompose.slideToggle(200, updateNlBtn);
            });
            $('#slw-cancel-newsletter-btn').on('click', function() {
                nlCompose.slideUp(200, updateNlBtn);
            });

            // Show/hide audience sub-options
            $('input[name="slw_nl_audience"]').on('change', function() {
                var val = $(this).val();
                $('#slw-nl-individual-select').toggle(val === 'select');
                $('#slw-nl-custom-email').toggle(val === 'custom');
            });

            // Template load
            $('#slw-nl-load-tpl').on('click', function() {
                var $sel = $('#slw-nl-template option:selected');
                if (!$sel.val()) {
                    $('#slw-nl-subject').val('');
                    if (typeof tinyMCE !== 'undefined' && tinyMCE.get('slw_nl_body')) {
                        tinyMCE.get('slw_nl_body').setContent('');
                    }
                    return;
                }
                $('#slw-nl-subject').val($sel.data('subject') || '');
                var body = $sel.data('body') || '';
                body = body.replace(/\n/g, '<br>');
                if (typeof tinyMCE !== 'undefined' && tinyMCE.get('slw_nl_body')) {
                    tinyMCE.get('slw_nl_body').setContent(body);
                } else {
                    $('#slw_nl_body').val($sel.data('body') || '');
                }
            });

            // Template save
            $('#slw-nl-save-tpl').on('click', function() {
                var name = prompt('Template name:');
                if (!name || !name.trim()) return;
                var subject = $('#slw-nl-subject').val().trim();
                var body = '';
                if (typeof tinyMCE !== 'undefined' && tinyMCE.get('slw_nl_body')) {
                    body = tinyMCE.get('slw_nl_body').getContent({format: 'text'});
                } else {
                    body = $('#slw_nl_body').val();
                }
                var $btn = $(this);
                $btn.prop('disabled', true).text('Saving...');
                $.post(ajaxurl, {
                    action: 'slw_save_nl_template',
                    nonce: '<?php echo esc_js( wp_create_nonce( "slw_sequences_nonce" ) ); ?>',
                    template_name: name.trim(),
                    subject: subject,
                    body: body
                }, function(res) {
                    $btn.prop('disabled', false).text('Save Current as Template');
                    if (res.success) {
                        var d = res.data;
                        var exists = $('#slw-nl-template option[value="'+d.slug+'"]');
                        // Track for undo
                        lastSavedTemplate = { slug: d.slug, wasNew: !exists.length };
                        if (exists.length) {
                            lastSavedTemplate.prevName = exists.text();
                            lastSavedTemplate.prevSubject = exists.data('subject');
                            lastSavedTemplate.prevBody = exists.data('body');
                            exists.text(d.name).data('subject', d.subject).data('body', d.body);
                        } else {
                            $('#slw-nl-template').append('<option value="'+d.slug+'" data-subject="'+d.subject.replace(/"/g,'&quot;')+'" data-body="'+d.body.replace(/"/g,'&quot;')+'">'+d.name+'</option>');
                        }
                        $('#slw-nl-template').val(d.slug);
                        $('#slw-nl-undo-tpl').show();
                        alert('Template saved!');
                    } else {
                        alert(res.data || 'Could not save template.');
                    }
                });
            });

            // Undo last template save
            var lastSavedTemplate = null;
            $('#slw-nl-undo-tpl').on('click', function() {
                if (!lastSavedTemplate) return;
                var slug = lastSavedTemplate.slug;
                if (lastSavedTemplate.wasNew) {
                    // Delete the newly created template
                    $.post(ajaxurl, {
                        action: 'slw_delete_nl_template',
                        nonce: '<?php echo esc_js( wp_create_nonce( "slw_sequences_nonce" ) ); ?>',
                        slug: slug
                    }, function() {
                        $('#slw-nl-template option[value="'+slug+'"]').remove();
                        $('#slw-nl-template').val('');
                    });
                } else {
                    // Restore the previous version
                    $.post(ajaxurl, {
                        action: 'slw_save_nl_template',
                        nonce: '<?php echo esc_js( wp_create_nonce( "slw_sequences_nonce" ) ); ?>',
                        template_name: lastSavedTemplate.prevName,
                        subject: lastSavedTemplate.prevSubject,
                        body: lastSavedTemplate.prevBody
                    }, function(res) {
                        if (res.success) {
                            var d = res.data;
                            var opt = $('#slw-nl-template option[value="'+d.slug+'"]');
                            opt.text(d.name).data('subject', d.subject).data('body', d.body);
                        }
                    });
                }
                lastSavedTemplate = null;
                $(this).hide();
            });

            // Schedule toggle
            $('#slw-nl-schedule-toggle').on('change', function() {
                $('#slw-nl-schedule-time').toggle(this.checked);
                var $sendBtn = $('#slw-send-newsletter-btn');
                $sendBtn.text(this.checked ? 'Schedule Newsletter' : 'Send Newsletter');
            });

            // Template delete
            $('#slw-nl-delete-tpl').on('click', function() {
                var slug = $('#slw-nl-template').val();
                if (!slug) { alert('Select a template first.'); return; }
                if (!confirm('Delete this template?')) return;
                $.post(ajaxurl, {
                    action: 'slw_delete_nl_template',
                    nonce: '<?php echo esc_js( wp_create_nonce( "slw_sequences_nonce" ) ); ?>',
                    slug: slug
                }, function(res) {
                    if (res.success) {
                        $('#slw-nl-template option[value="'+slug+'"]').remove();
                        $('#slw-nl-template').val('');
                    }
                });
            });

            // Send newsletter
            $('#slw-send-newsletter-btn').on('click', function() {
                var $btn = $(this);
                var $status = $('#slw-newsletter-status');
                var subject = $('#slw-nl-subject').val().trim();

                // Get TinyMCE content
                var body = '';
                if (typeof tinyMCE !== 'undefined' && tinyMCE.get('slw_nl_body')) {
                    body = tinyMCE.get('slw_nl_body').getContent();
                } else {
                    body = $('#slw_nl_body').val();
                }

                if (!subject) {
                    $status.html('<span style="color:#b71c1c;">Please enter a subject line.</span>');
                    return;
                }
                if (!body || body.trim() === '') {
                    $status.html('<span style="color:#b71c1c;">Please enter the newsletter body.</span>');
                    return;
                }

                var audience = $('input[name="slw_nl_audience"]:checked').val();
                var recipients = [];
                var customEmail = '';
                if (audience === 'select') {
                    recipients = $('#slw-nl-recipients').val() || [];
                    if (recipients.length === 0) {
                        $status.html('<span style="color:#b71c1c;">Please select at least one recipient.</span>');
                        return;
                    }
                } else if (audience === 'custom') {
                    customEmail = $('#slw-nl-custom-address').val().trim();
                    if (!customEmail) {
                        $status.html('<span style="color:#b71c1c;">Please enter an email address.</span>');
                        return;
                    }
                }

                var confirmMsg = audience === 'select' ? recipients.length + ' selected customers'
                    : audience === 'custom' ? customEmail
                    : audience + ' wholesale customers';
                if (!confirm('Send this newsletter to ' + confirmMsg + '?')) {
                    return;
                }

                $btn.prop('disabled', true).text('Sending...');
                $status.text('');

                $.post(ajaxurl, {
                    action: 'slw_send_newsletter',
                    nonce: $btn.data('nonce'),
                    subject: subject,
                    body: body,
                    audience: audience,
                    recipients: recipients,
                    custom_email: customEmail,
                    scheduled: $('#slw-nl-schedule-toggle').is(':checked') ? $('#slw-nl-schedule-time').val() : ''
                }, function(response) {
                    $btn.prop('disabled', false).text('Send Newsletter');
                    if (response.success) {
                        $status.html('<span style="color:#1b5e20;font-weight:600;">&#10003; ' + response.data + '</span>');
                        // Clear form
                        $('#slw-nl-subject').val('');
                        if (typeof tinyMCE !== 'undefined' && tinyMCE.get('slw_nl_body')) {
                            tinyMCE.get('slw_nl_body').setContent('');
                        }
                        // Reload after 2 seconds to show the log entry
                        setTimeout(function() { location.reload(); }, 2000);
                    } else {
                        $status.html('<span style="color:#b71c1c;font-weight:600;">&#10007; ' + response.data + '</span>');
                    }
                }).fail(function() {
                    $btn.prop('disabled', false).text('Send Newsletter');
                    $status.html('<span style="color:#b71c1c;">Request failed.</span>');
                });
            });

            // Test connection
            $('#slw-test-connection').on('click', function() {
                var $btn = $(this);
                var $result = $('#slw-test-result');

                $btn.prop('disabled', true).text('Testing...');
                $result.text('');

                $.post(ajaxurl, {
                    action: 'slw_test_mautic_connection',
                    nonce: $btn.data('nonce')
                }, function(response) {
                    $btn.prop('disabled', false).text('Test Connection');
                    if (response.success) {
                        $result.html('<span style="color:#1b5e20;font-weight:600;">&#10003; ' + response.data + '</span>');
                    } else {
                        $result.html('<span style="color:#b71c1c;font-weight:600;">&#10007; ' + response.data + '</span>');
                    }
                }).fail(function() {
                    $btn.prop('disabled', false).text('Test Connection');
                    $result.html('<span style="color:#b71c1c;">Request failed.</span>');
                });
            });
        })(jQuery);
        </script>
        <?php
    }

    /* =================================================================
       Newsletter Send
       ================================================================= */

    /**
     * AJAX: Send a newsletter to wholesale customers.
     */
    public static function ajax_send_newsletter() {
        check_ajax_referer( 'slw_sequences_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( 'Permission denied.' );
        }

        $subject      = sanitize_text_field( $_POST['subject'] ?? '' );
        $body         = wp_kses_post( $_POST['body'] ?? '' );
        $audience     = sanitize_text_field( $_POST['audience'] ?? 'all' );
        $recipients   = isset( $_POST['recipients'] ) ? array_map( 'absint', (array) $_POST['recipients'] ) : array();
        $custom_email = sanitize_email( $_POST['custom_email'] ?? '' );
        $scheduled    = sanitize_text_field( $_POST['scheduled'] ?? '' );

        if ( empty( $subject ) || empty( $body ) ) {
            wp_send_json_error( 'Subject and body are required.' );
        }

        // Resolve the recipient list
        if ( $audience === 'custom' && $custom_email ) {
            $email_list = array( $custom_email );
        } else {
            $email_list = self::resolve_newsletter_recipients( $audience, $recipients );
        }

        // If scheduled for later, save to a cron event and return
        if ( ! empty( $scheduled ) ) {
            $send_time = strtotime( $scheduled );
            if ( ! $send_time || $send_time < time() ) {
                wp_send_json_error( 'Scheduled time must be in the future.' );
            }

            $scheduled_data = array(
                'subject'    => $subject,
                'body'       => $body,
                'email_list' => $email_list,
                'sent_by'    => wp_get_current_user()->display_name,
            );

            // Store in a transient and schedule a one-time cron
            $job_id = 'slw_nl_' . md5( $subject . $send_time );
            set_transient( $job_id, $scheduled_data, DAY_IN_SECONDS * 7 );
            wp_schedule_single_event( $send_time, 'slw_send_scheduled_newsletter', array( $job_id ) );

            // Log it
            self::log_newsletter( $subject, count( $email_list ), wp_get_current_user()->display_name );

            $formatted = date_i18n( 'M j, Y \a\t g:i A', $send_time );
            wp_send_json_success( "Newsletter scheduled for {$formatted} to " . count( $email_list ) . ' recipient(s).' );
        }

        if ( empty( $email_list ) ) {
            wp_send_json_error( 'No recipients found for the selected audience.' );
        }

        // Build the branded HTML email
        $html_email = self::build_branded_email( $subject, $body );

        // Determine send method: Mautic or wp_mail
        $provider = get_option( 'slw_email_provider', 'none' );
        $mautic_url = rtrim( get_option( 'slw_mautic_url', '' ), '/' );
        $has_mautic = $provider === 'mautic' && ! empty( $mautic_url )
                      && ! empty( get_option( 'slw_mautic_client_id', '' ) )
                      && ! empty( get_option( 'slw_mautic_client_secret', '' ) );

        $sent_count = 0;
        $send_error = '';

        if ( $has_mautic ) {
            $result = self::send_via_mautic( $subject, $html_email, $email_list );
            if ( is_wp_error( $result ) ) {
                $send_error = $result->get_error_message();
            } else {
                $sent_count = $result;
            }
        } else {
            $result = self::send_via_wp_mail( $subject, $html_email, $email_list );
            if ( is_wp_error( $result ) ) {
                $send_error = $result->get_error_message();
            } else {
                $sent_count = $result;
            }
        }

        if ( $send_error ) {
            wp_send_json_error( 'Send failed: ' . $send_error );
        }

        // Log the newsletter
        self::log_newsletter( $subject, $sent_count );

        // Log to webhook log
        $webhook_log = get_option( 'slw_webhook_log', array() );
        array_unshift( $webhook_log, array(
            'event'  => 'newsletter_sent',
            'email'  => $subject,
            'status' => 'success',
            'code'   => 200,
            'time'   => current_time( 'mysql' ),
        ) );
        update_option( 'slw_webhook_log', array_slice( $webhook_log, 0, 50 ) );

        wp_send_json_success( 'Newsletter sent to ' . $sent_count . ' contact(s).' );
    }

    /**
     * Resolve the list of email addresses based on audience selection.
     *
     * @param string $audience    Audience type: all, standard, preferred, vip, select.
     * @param array  $user_ids    Specific user IDs if audience is 'select'.
     * @return array              Array of email addresses.
     */
    private static function resolve_newsletter_recipients( $audience, $user_ids = array() ) {
        $emails = array();

        if ( $audience === 'select' && ! empty( $user_ids ) ) {
            foreach ( $user_ids as $uid ) {
                $user = get_userdata( $uid );
                if ( $user && slw_is_wholesale_user( $uid ) ) {
                    $emails[] = $user->user_email;
                }
            }
            return $emails;
        }

        // Get all wholesale users
        $users = get_users( array( 'role' => 'wholesale_customer' ) );

        foreach ( $users as $user ) {
            // Filter by tier if needed
            if ( $audience !== 'all' && class_exists( 'SLW_Tiers' ) ) {
                $tier = strtolower( SLW_Tiers::get_user_tier( $user->ID ) ?: 'standard' );
                if ( $tier !== $audience ) {
                    continue;
                }
            }
            $emails[] = $user->user_email;
        }

        return array_unique( $emails );
    }

    /**
     * Build a branded HTML email matching the plugin's transactional email style.
     *
     * @param string $subject The email subject.
     * @param string $body    The email body HTML.
     * @return string         Complete HTML email.
     */
    private static function build_branded_email( $subject, $body ) {
        $from_name = class_exists( 'SLW_Email_Settings' ) ? SLW_Email_Settings::get( 'from_name' ) : get_bloginfo( 'name' );
        $signature = class_exists( 'SLW_Email_Settings' ) ? SLW_Email_Settings::get_signature() : '';

        $html = '<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>' . esc_html( $subject ) . '</title>
</head>
<body style="margin:0;padding:0;background:#f4f4f4;font-family:Georgia,\'Times New Roman\',serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f4;padding:20px 0;">
<tr><td align="center">
<table width="600" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:8px;overflow:hidden;">
    <!-- Header -->
    <tr>
        <td style="background:#386174;padding:28px 32px;text-align:center;">
            <h1 style="margin:0;font-family:Georgia,\'Times New Roman\',serif;font-size:24px;color:#F7F6F3;">'
            . esc_html( $from_name ) .
            '</h1>
        </td>
    </tr>
    <!-- Body -->
    <tr>
        <td style="padding:32px;font-family:Georgia,\'Times New Roman\',serif;font-size:16px;line-height:1.6;color:#2C2C2C;">'
            . $body .
            ( $signature ? '<br><br><div style="border-top:1px solid #e0ddd8;padding-top:16px;margin-top:16px;color:#628393;font-size:14px;">' . nl2br( esc_html( $signature ) ) . '</div>' : '' ) .
        '</td>
    </tr>
    <!-- Footer -->
    <tr>
        <td style="background:#1E2A30;padding:20px 32px;text-align:center;font-size:12px;color:#628393;">
            <p style="margin:0;">' . esc_html( $from_name ) . ' &middot; Wholesale Partners</p>
        </td>
    </tr>
</table>
</td></tr>
</table>
</body>
</html>';

        return $html;
    }

    /**
     * Send newsletter via Mautic API.
     *
     * @param string $subject    Email subject.
     * @param string $html       Full HTML email.
     * @param array  $emails     Recipient email addresses.
     * @return int|WP_Error      Number of recipients sent to, or error.
     */
    private static function send_via_mautic( $subject, $html, $emails ) {
        // Step 1: Create the email in Mautic
        $email_result = self::mautic_request( 'POST', '/api/emails/new', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . self::get_mautic_token(),
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
                'User-Agent'    => self::USER_AGENT,
            ),
            'body' => wp_json_encode( array(
                'name'       => $subject . ' — ' . current_time( 'Y-m-d H:i' ),
                'subject'    => $subject,
                'customHtml' => $html,
                'emailType'  => 'list',
                'isPublished' => true,
            ) ),
        ) );

        if ( is_wp_error( $email_result ) ) {
            return $email_result;
        }

        $email_id = isset( $email_result['email']['id'] ) ? (int) $email_result['email']['id'] : 0;
        if ( ! $email_id ) {
            return new \WP_Error( 'mautic_email', 'Failed to create email in Mautic.' );
        }

        // Step 2: Look up Mautic contact IDs by email address
        $contact_ids = array();
        foreach ( $emails as $email_addr ) {
            $search = self::mautic_request( 'GET', '/api/contacts?search=' . rawurlencode( $email_addr ) . '&limit=1' );
            if ( ! is_wp_error( $search ) && ! empty( $search['contacts'] ) ) {
                foreach ( $search['contacts'] as $contact ) {
                    $contact_ids[] = (int) $contact['id'];
                    break; // first match
                }
            }
        }

        if ( empty( $contact_ids ) ) {
            return new \WP_Error( 'no_contacts', 'No matching contacts found in Mautic.' );
        }

        // Step 3: Send the email to each contact
        $sent = 0;
        foreach ( $contact_ids as $cid ) {
            $send_result = self::mautic_request( 'POST', '/api/emails/' . $email_id . '/contact/' . $cid . '/send' );
            if ( ! is_wp_error( $send_result ) ) {
                $sent++;
            }
        }

        return $sent;
    }

    /**
     * Send newsletter via wp_mail() fallback. Batches in groups of 10 via BCC.
     *
     * @param string $subject Email subject.
     * @param string $html    Full HTML email.
     * @param array  $emails  Recipient email addresses.
     * @return int|WP_Error   Number of recipients sent to, or error.
     */
    private static function send_via_wp_mail( $subject, $html, $emails ) {
        $headers = array( 'Content-Type: text/html; charset=UTF-8' );

        if ( class_exists( 'SLW_Email_Settings' ) ) {
            $headers = array_merge( $headers, SLW_Email_Settings::get_headers() );
        }

        $from_address = class_exists( 'SLW_Email_Settings' ) ? SLW_Email_Settings::get( 'from_address' ) : get_option( 'admin_email' );

        $batches = array_chunk( $emails, 10 );
        $sent    = 0;

        foreach ( $batches as $batch ) {
            $bcc_headers = $headers;
            foreach ( $batch as $email_addr ) {
                $bcc_headers[] = 'Bcc: ' . $email_addr;
            }

            $result = wp_mail( $from_address, $subject, $html, $bcc_headers );
            if ( $result ) {
                $sent += count( $batch );
            }
        }

        if ( $sent === 0 ) {
            return new \WP_Error( 'send_failed', 'wp_mail() failed to send the newsletter.' );
        }

        return $sent;
    }

    /**
     * Log a sent newsletter to the option-based log (last 20 entries).
     *
     * @param string $subject         The newsletter subject.
     * @param int    $recipient_count Number of recipients.
     */
    private static function log_newsletter( $subject, $recipient_count ) {
        $log = get_option( 'slw_newsletter_log', array() );

        $current_user = wp_get_current_user();

        array_unshift( $log, array(
            'subject'         => $subject,
            'date'            => current_time( 'Y-m-d H:i' ),
            'recipient_count' => $recipient_count,
            'sent_by'         => $current_user->display_name ?: $current_user->user_login,
        ) );

        // Keep only the last 20 entries
        $log = array_slice( $log, 0, 20 );

        update_option( 'slw_newsletter_log', $log );
    }

    /* =================================================================
       Newsletter Template AJAX Handlers
       ================================================================= */

    public static function ajax_save_nl_template() {
        check_ajax_referer( 'slw_sequences_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( 'Permission denied.' );
        }

        $name    = sanitize_text_field( $_POST['template_name'] ?? '' );
        $subject = sanitize_text_field( $_POST['subject'] ?? '' );
        $body    = wp_kses_post( $_POST['body'] ?? '' );

        if ( empty( $name ) || empty( $subject ) ) {
            wp_send_json_error( 'Template name and subject are required.' );
        }

        $slug = sanitize_title( $name );
        $templates = get_option( 'slw_newsletter_templates', array() );
        $templates[ $slug ] = array(
            'name'    => $name,
            'subject' => $subject,
            'body'    => $body,
        );
        update_option( 'slw_newsletter_templates', $templates );

        wp_send_json_success( array(
            'slug'    => $slug,
            'name'    => $name,
            'subject' => $subject,
            'body'    => $body,
        ) );
    }

    public static function ajax_delete_nl_template() {
        check_ajax_referer( 'slw_sequences_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( 'Permission denied.' );
        }

        $slug = sanitize_key( $_POST['slug'] ?? '' );
        if ( empty( $slug ) ) {
            wp_send_json_error( 'No template selected.' );
        }

        $templates = get_option( 'slw_newsletter_templates', array() );
        if ( isset( $templates[ $slug ] ) ) {
            unset( $templates[ $slug ] );
            update_option( 'slw_newsletter_templates', $templates );
        }

        wp_send_json_success();
    }

    /**
     * AJAX: Clear failed entries from the webhook log.
     * Keeps successful entries intact so the activity history isn't lost.
     */
    public static function ajax_clear_failed_log() {
        check_ajax_referer( 'slw_sequences_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( 'Permission denied.' );
        }

        $log = get_option( 'slw_webhook_log', array() );
        if ( ! is_array( $log ) ) {
            $log = array();
        }

        // Keep only successful/skipped entries
        $cleaned = array_values( array_filter( $log, function( $entry ) {
            return ( $entry['status'] ?? '' ) !== 'failed';
        } ) );

        update_option( 'slw_webhook_log', $cleaned, false );
        wp_send_json_success( 'Failed entries cleared.' );
    }

    /* =================================================================
       Email Templates (copyable outreach + newsletter templates)
       ================================================================= */

    public static function render_email_templates() {
        $wholesale_url = esc_url( home_url( '/wholesale-partners' ) );
        $portal_url    = esc_url( home_url( '/wholesale-portal' ) );
        $brand_name    = class_exists( 'SLW_Email_Settings' ) ? SLW_Email_Settings::get_business_name() : get_bloginfo( 'name' );
        $owner_name    = class_exists( 'SLW_Email_Settings' ) ? SLW_Email_Settings::get( 'owner_name' ) : '';
        if ( ! $owner_name ) $owner_name = 'Holly';

        $template_1_subject = "Carry {$brand_name} in your shop?";
        $template_1_body = "Hi {shop_owner_name},

I came across {shop_name} and love what you're doing. I think our products would be a great fit for your customers.

I'm {$owner_name} — I run {$brand_name}. We make small-batch, clean-ingredient skincare and our wholesale partners get 50% off retail pricing.

If you're open to it, I'd love to send you our price list or set up a quick call:

{$wholesale_url}

No pressure at all — just thought it could be a good match.

Talk soon,
{$owner_name}";

        $template_2_subject = "What's new this quarter at {$brand_name}";
        $template_2_body = "Hi there,

Hope business is going well! Here's a quick update from our end.

NEW PRODUCTS
[Describe any new products launched this quarter]

WHAT'S SELLING BEST
[Share your top 2-3 bestsellers and why customers love them]

SEASONAL RECOMMENDATION
[Suggest a product or bundle that fits the upcoming season]

REORDER
Ready to restock? Place your next order here:
{$portal_url}

As always, reach out anytime if you need samples, marketing materials, or just want to chat.

Thanks for being a partner,
{$owner_name}";

        ?>
        <h2 class="title">Email Templates</h2>
        <p style="color:#628393;font-size:13px;margin:-8px 0 16px;">Copy these templates and customize before sending. Replace {shop_owner_name} and {shop_name} with the actual names.</p>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
            <div class="slw-admin-card" style="margin-bottom:0;">
                <h3 style="margin:0 0 4px;font-size:15px;color:#1E2A30;">Wholesale Outreach</h3>
                <span style="font-size:11px;color:#628393;text-transform:uppercase;letter-spacing:0.5px;">For retail customers who own shops</span>
                <div style="margin:12px 0 8px;font-size:13px;color:#386174;"><strong>Subject:</strong> <?php echo esc_html( $template_1_subject ); ?></div>
                <pre id="slw-tpl-1" style="background:#faf8f5;padding:14px;border-radius:4px;font-size:13px;line-height:1.6;white-space:pre-wrap;word-wrap:break-word;border:1px solid #e0ddd8;max-height:300px;overflow:auto;"><?php echo esc_html( $template_1_body ); ?></pre>
                <button type="button" class="button slw-copy-tpl" data-target="slw-tpl-1" style="margin-top:8px;">Copy to Clipboard</button>
            </div>

            <div class="slw-admin-card" style="margin-bottom:0;">
                <h3 style="margin:0 0 4px;font-size:15px;color:#1E2A30;">Quarterly Newsletter</h3>
                <span style="font-size:11px;color:#628393;text-transform:uppercase;letter-spacing:0.5px;">For existing wholesale partners</span>
                <div style="margin:12px 0 8px;font-size:13px;color:#386174;"><strong>Subject:</strong> <?php echo esc_html( $template_2_subject ); ?></div>
                <pre id="slw-tpl-2" style="background:#faf8f5;padding:14px;border-radius:4px;font-size:13px;line-height:1.6;white-space:pre-wrap;word-wrap:break-word;border:1px solid #e0ddd8;max-height:300px;overflow:auto;"><?php echo esc_html( $template_2_body ); ?></pre>
                <button type="button" class="button slw-copy-tpl" data-target="slw-tpl-2" style="margin-top:8px;">Copy to Clipboard</button>
            </div>
        </div>

        <script>
        document.querySelectorAll('.slw-copy-tpl').forEach(function(btn){
            btn.addEventListener('click', function(){
                var pre = document.getElementById(this.getAttribute('data-target'));
                if (!pre) return;
                navigator.clipboard.writeText(pre.textContent).then(function(){
                    var orig = btn.textContent;
                    btn.textContent = 'Copied!';
                    setTimeout(function(){ btn.textContent = orig; }, 2000);
                });
            });
        });
        </script>
        <?php
    }
}
