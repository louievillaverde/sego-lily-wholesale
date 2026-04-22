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

        $campaigns = isset( $result['campaigns'] ) ? $result['campaigns'] : array();
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

        // Webhook log
        $webhook_log = get_option( 'slw_webhook_log', array() );

        // Last sync time (when transients were set)
        $last_sync = get_transient( 'slw_mautic_campaigns' ) !== false ? 'Cached (within 15 min)' : 'Not cached';

        // Nonce for AJAX and refresh
        $nonce = wp_create_nonce( 'slw_sequences_nonce' );
        $refresh_url = wp_nonce_url( admin_url( 'admin.php?page=slw-sequences' ), 'slw_refresh_sequences', 'slw_refresh' );

        ?>
        <div class="wrap slw-sequences-wrap">
            <h1>Email Sequences</h1>
            <p>Monitor your Mautic email campaigns and webhook activity. All editing happens in Mautic.</p>

            <?php if ( $saved ) : ?>
                <div class="notice notice-success is-dismissible"><p>Settings saved.</p></div>
            <?php endif; ?>

            <!-- ─── Connection Status Bar ─── -->
            <div class="slw-connection-bar <?php echo $connected ? 'slw-connected' : 'slw-disconnected'; ?>">
                <div class="slw-connection-info">
                    <span class="slw-connection-dot"></span>
                    <?php if ( $connected ) : ?>
                        <span>Connected to Mautic at <strong><?php echo esc_html( parse_url( $mautic_url, PHP_URL_HOST ) ); ?></strong></span>
                        <span class="slw-connection-sync"><?php echo esc_html( $last_sync ); ?></span>
                    <?php elseif ( $has_config && $api_error ) : ?>
                        <span>Connection failed: <?php echo esc_html( $api_error ); ?></span>
                    <?php elseif ( $provider === 'none' ) : ?>
                        <span>No email provider configured (webhooks only mode)</span>
                    <?php else : ?>
                        <span>Not connected &mdash; configure your Mautic credentials below</span>
                    <?php endif; ?>
                </div>
                <?php if ( $connected ) : ?>
                    <a href="<?php echo esc_url( $refresh_url ); ?>" class="button slw-refresh-btn">
                        &#8635; Refresh
                    </a>
                <?php endif; ?>
            </div>

            <?php if ( $connected ) : ?>

            <!-- ─── Quick Stats ─── -->
            <div class="slw-stats-grid">
                <div class="slw-stat-card accent-teal">
                    <span class="stat-number"><?php echo esc_html( number_format( $total_sent ) ); ?></span>
                    <span class="stat-label">Emails Sent</span>
                </div>
                <div class="slw-stat-card accent-green">
                    <span class="stat-number"><?php echo esc_html( $avg_open_rate ); ?>%</span>
                    <span class="stat-label">Avg Open Rate</span>
                </div>
                <div class="slw-stat-card accent-gold">
                    <span class="stat-number"><?php echo esc_html( $active_count ); ?></span>
                    <span class="stat-label">Active Sequences</span>
                </div>
                <div class="slw-stat-card">
                    <span class="stat-number"><?php echo esc_html( number_format( $total_contacts ) ); ?></span>
                    <span class="stat-label">Contacts in Sequences</span>
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
                <div class="slw-admin-card slw-campaign-card">
                    <div class="slw-campaign-header" data-campaign="<?php echo esc_attr( $c_id ); ?>">
                        <div class="slw-campaign-info">
                            <h3>
                                <?php echo esc_html( $c_name ); ?>
                                <span class="slw-status-badge <?php echo $c_published ? 'slw-badge-published' : 'slw-badge-unpublished'; ?>">
                                    <?php echo $c_published ? 'Published' : 'Unpublished'; ?>
                                </span>
                            </h3>
                            <div class="slw-campaign-meta">
                                <span><?php echo esc_html( $c_contacts ); ?> contact<?php echo $c_contacts !== 1 ? 's' : ''; ?></span>
                                <span class="slw-meta-sep">&middot;</span>
                                <span><?php echo esc_html( $email_count ); ?> email<?php echo $email_count !== 1 ? 's' : ''; ?></span>
                            </div>
                        </div>
                        <button type="button" class="button slw-toggle-emails" aria-expanded="false">
                            &#9660; Show Emails
                        </button>
                    </div>

                    <?php if ( ! empty( $c_emails ) ) : ?>
                    <div class="slw-campaign-emails" style="display:none;">
                        <table class="wp-list-table widefat striped">
                            <thead>
                                <tr>
                                    <th>Subject</th>
                                    <th>Timing</th>
                                    <th>Sent</th>
                                    <th>Open Rate</th>
                                    <th>Click Rate</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ( $c_emails as $ce ) :
                                    $open_rate  = $ce['sent'] > 0 ? round( ( $ce['opens'] / $ce['sent'] ) * 100, 1 ) : 0;
                                    $click_rate = $ce['sent'] > 0 ? round( ( $ce['clicks'] / $ce['sent'] ) * 100, 1 ) : 0;
                                ?>
                                <tr>
                                    <td><strong><?php echo esc_html( $ce['subject'] ); ?></strong></td>
                                    <td><span class="slw-timing-pill"><?php echo esc_html( $ce['timing'] ); ?></span></td>
                                    <td><?php echo esc_html( number_format( $ce['sent'] ) ); ?></td>
                                    <td>
                                        <span class="slw-rate <?php echo $open_rate >= 30 ? 'slw-rate-good' : ( $open_rate >= 15 ? 'slw-rate-ok' : 'slw-rate-low' ); ?>">
                                            <?php echo esc_html( $open_rate ); ?>%
                                        </span>
                                    </td>
                                    <td>
                                        <span class="slw-rate <?php echo $click_rate >= 5 ? 'slw-rate-good' : ( $click_rate >= 2 ? 'slw-rate-ok' : 'slw-rate-low' ); ?>">
                                            <?php echo esc_html( $click_rate ); ?>%
                                        </span>
                                    </td>
                                    <td class="slw-email-actions">
                                        <?php if ( $ce['email_id'] > 0 ) : ?>
                                            <a href="<?php echo esc_url( $mautic_url . '/s/emails/edit/' . $ce['email_id'] ); ?>"
                                               target="_blank" rel="noopener" class="button button-small">Edit in Mautic</a>
                                            <a href="<?php echo esc_url( $mautic_url . '/s/emails/view/' . $ce['email_id'] ); ?>"
                                               target="_blank" rel="noopener" class="button button-small">View</a>
                                        <?php else : ?>
                                            <span class="description">ID unavailable</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>

            <?php endif; /* end if $connected */ ?>

            <!-- ─── Webhook Health ─── -->
            <h2 class="title">Webhook Activity</h2>

            <?php if ( empty( $webhook_log ) ) : ?>
                <div class="slw-admin-card">
                    <p>No webhook activity recorded yet. Webhooks fire when applications are approved, first orders are placed, or reorder reminders trigger.</p>
                </div>
            <?php else : ?>
                <div class="slw-admin-card" style="padding: 0; overflow: hidden;">
                    <table class="wp-list-table widefat striped">
                        <thead>
                            <tr>
                                <th>Event</th>
                                <th>Email</th>
                                <th>Status</th>
                                <th>HTTP Code</th>
                                <th>Time</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( array_slice( $webhook_log, 0, 25 ) as $entry ) : ?>
                            <tr>
                                <td><code><?php echo esc_html( $entry['event'] ?? '' ); ?></code></td>
                                <td><?php echo esc_html( $entry['email'] ?? '-' ); ?></td>
                                <td>
                                    <?php
                                    $status = $entry['status'] ?? 'unknown';
                                    $badge_class = $status === 'success' ? 'slw-status-approved' : 'slw-status-declined';
                                    ?>
                                    <span class="<?php echo esc_attr( $badge_class ); ?>"><?php echo esc_html( ucfirst( $status ) ); ?></span>
                                </td>
                                <td><?php echo esc_html( $entry['code'] ?? '-' ); ?></td>
                                <td><?php echo esc_html( $entry['time'] ?? '' ); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <!-- ─── Provider Settings ─── -->
            <h2 class="title">Email Provider Settings</h2>

            <div class="slw-admin-card">
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

                    <!-- Mailchimp fields -->
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

                    <!-- ActiveCampaign fields -->
                    <table class="form-table slw-provider-fields slw-activecampaign-fields" style="display:none;">
                        <tr>
                            <th scope="row"><label for="slw_activecampaign_url">Account URL</label></th>
                            <td>
                                <input type="url" id="slw_activecampaign_url" name="slw_activecampaign_url"
                                       value="<?php echo esc_attr( get_option( 'slw_activecampaign_url', '' ) ); ?>"
                                       class="regular-text" placeholder="https://yourname.api-us1.com" />
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="slw_activecampaign_api_key">API Key</label></th>
                            <td>
                                <input type="password" id="slw_activecampaign_api_key" name="slw_activecampaign_api_key"
                                       value="<?php echo esc_attr( get_option( 'slw_activecampaign_api_key', '' ) ); ?>"
                                       class="regular-text" autocomplete="new-password" />
                                <p class="description">Settings > Developer > API Access.</p>
                            </td>
                        </tr>
                    </table>

                    <!-- Klaviyo fields -->
                    <table class="form-table slw-provider-fields slw-klaviyo-fields" style="display:none;">
                        <tr>
                            <th scope="row"><label for="slw_klaviyo_api_key">Private API Key</label></th>
                            <td>
                                <input type="password" id="slw_klaviyo_api_key" name="slw_klaviyo_api_key"
                                       value="<?php echo esc_attr( get_option( 'slw_klaviyo_api_key', '' ) ); ?>"
                                       class="regular-text" autocomplete="new-password" />
                                <p class="description">Account > Settings > API Keys > Private Key.</p>
                            </td>
                        </tr>
                    </table>

                    <!-- ConvertKit fields -->
                    <table class="form-table slw-provider-fields slw-convertkit-fields" style="display:none;">
                        <tr>
                            <th scope="row"><label for="slw_convertkit_api_key">API Key</label></th>
                            <td>
                                <input type="password" id="slw_convertkit_api_key" name="slw_convertkit_api_key"
                                       value="<?php echo esc_attr( get_option( 'slw_convertkit_api_key', '' ) ); ?>"
                                       class="regular-text" autocomplete="new-password" />
                                <p class="description">Settings > Advanced > API Key.</p>
                            </td>
                        </tr>
                    </table>

                    <!-- Coming soon notice for non-Mautic providers -->
                    <div class="slw-provider-coming-soon" style="display:none;padding:16px 20px;background:#fff8e1;border:1px solid #ffe082;border-radius:6px;margin:12px 0;">
                        <strong style="color:#e65100;">Coming Soon:</strong>
                        <span style="color:#2C2C2C;">Full campaign stats and deep links for this provider are in development. Save your credentials now — webhook integration works immediately. Campaign stats will be available in a future update.</span>
                    </div>

                    <div class="slw-mautic-fields" style="margin-bottom: 16px;">
                        <button type="button" id="slw-test-connection" class="button" data-nonce="<?php echo esc_attr( $nonce ); ?>">
                            Test Connection
                        </button>
                        <span id="slw-test-result" style="margin-left: 12px;"></span>
                    </div>

                    <?php submit_button( 'Save Provider Settings' ); ?>
                </form>
            </div>

        </div>

        <script>
        (function($) {
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
}
