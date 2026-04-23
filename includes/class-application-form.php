<?php
/**
 * Wholesale Application Form + Admin Approval
 *
 * Public shortcode [sego_wholesale_application] renders the application form.
 * Submissions land in a custom DB table and show up in WP Admin under
 * "Wholesale Applications." Admin can approve or decline from there.
 *
 * Approval creates a WooCommerce user account with the wholesale_customer role
 * and fires a webhook to AIOS so Mautic can start the onboarding sequence.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class SLW_Application_Form {

    public static function init() {
        add_shortcode( 'sego_wholesale_application', array( __CLASS__, 'render_form' ) );
        add_action( 'wp_ajax_nopriv_slw_submit_application', array( __CLASS__, 'handle_submission' ) );
        add_action( 'wp_ajax_slw_submit_application', array( __CLASS__, 'handle_submission' ) );

        // Admin menu is registered centrally by SLW_Admin_Menu

        // Handle approve/decline actions
        add_action( 'admin_init', array( __CLASS__, 'handle_admin_action' ) );
    }

    /**
     * Render the public-facing application form. If the visitor is already
     * a wholesale customer, show a message instead of the form.
     *
     * The form renders without any built-in header banner — embedding pages
     * are expected to provide their own intro / heading above the shortcode.
     */
    public static function render_form( $atts = array() ) {
        if ( slw_is_wholesale_user() ) {
            return '<div class="slw-notice slw-notice-info">You already have a wholesale account. <a href="/wholesale-order">Go to the order form</a>.</div>';
        }

        ob_start();
        include SLW_PLUGIN_DIR . 'templates/application-form.php';
        return ob_get_clean();
    }

    /**
     * Process the application form submission. Validates required fields,
     * checks the honeypot, enforces rate limiting, and saves to the DB.
     */
    public static function handle_submission() {
        // Verify nonce
        if ( ! wp_verify_nonce( $_POST['slw_nonce'] ?? '', 'slw_application_submit' ) ) {
            wp_send_json_error( array( 'message' => 'Security check failed. Please refresh the page and try again.' ) );
        }

        // Honeypot: this field should be empty (bots fill it in)
        if ( ! empty( $_POST['slw_hp_confirm'] ?? '' ) ) {
            // Pretend success so the bot doesn't know it was caught
            $owner = SLW_Email_Settings::get( 'owner_name' );
            $review_msg = $owner
                ? sprintf( 'Thanks for applying! %s reviews applications personally and you\'ll hear back within 2-3 business days.', $owner )
                : 'Thanks for applying! We review applications personally and you\'ll hear back within 2-3 business days.';
            wp_send_json_success( array( 'message' => $review_msg ) );
        }

        // Rate limiting: max 10 submissions per IP per hour. The cap exists
        // for bot protection, not legitimate testing. 10/hour is enough
        // headroom for a real prospect who fat-fingered the form a few times
        // while still catching bot floods.
        $ip = self::get_client_ip();
        $rate_key = 'slw_rate_' . md5( $ip );
        $attempts = (int) get_transient( $rate_key );
        if ( $attempts >= 10 ) {
            wp_send_json_error( array( 'message' => 'Too many submissions. Please try again in an hour.' ) );
        }
        set_transient( $rate_key, $attempts + 1, HOUR_IN_SECONDS );

        // Validate required fields
        $required = array( 'business_name', 'contact_name', 'email', 'phone', 'address', 'ein', 'business_type' );
        foreach ( $required as $field ) {
            if ( empty( trim( $_POST[ $field ] ?? '' ) ) ) {
                wp_send_json_error( array( 'message' => 'Please fill in all required fields.' ) );
            }
        }

        if ( ! is_email( $_POST['email'] ) ) {
            wp_send_json_error( array( 'message' => 'Please enter a valid email address.' ) );
        }

        if ( empty( $_POST['agree_minimum'] ?? '' ) ) {
            wp_send_json_error( array( 'message' => 'Please acknowledge the minimum order requirement.' ) );
        }

        // Sanitize and save
        global $wpdb;
        $table = $wpdb->prefix . 'slw_applications';

        $data = array(
            'business_name' => sanitize_text_field( $_POST['business_name'] ),
            'contact_name'  => sanitize_text_field( $_POST['contact_name'] ),
            'email'         => sanitize_email( $_POST['email'] ),
            'phone'         => sanitize_text_field( $_POST['phone'] ),
            'address'       => sanitize_textarea_field( $_POST['address'] ),
            // Accept website URL OR social handle (Instagram, Facebook, etc.)
            // esc_url_raw() strips anything that does not parse as a URL
            // which rejected handles like @yourstore. sanitize_text_field
            // accepts any text; display logic handles link formatting.
            'website'       => sanitize_text_field( $_POST['website'] ?? '' ),
            'ein'           => SLW_Encryption::encrypt( sanitize_text_field( $_POST['ein'] ) ),
            'business_type' => sanitize_text_field( $_POST['business_type'] ),
            'how_heard'     => sanitize_textarea_field( $_POST['how_heard'] ?? '' ),
            'why_carry'     => sanitize_textarea_field( $_POST['why_carry'] ?? '' ),
            'status'        => 'pending',
            'ip_address'    => $ip,
            'submitted_at'  => current_time( 'mysql' ),
        );

        $inserted = $wpdb->insert( $table, $data );
        if ( $inserted === false ) {
            // Insert failed. Log the error and tell the user something is wrong
            // instead of pretending success. This prevents the silent-fail
            // where submissions vanish and the prospect thinks they applied.
            error_log( 'SLW: Application insert failed. DB error: ' . $wpdb->last_error );
            $fallback_email = SLW_Email_Settings::get( 'from_address' );
            wp_send_json_error( array(
                'message' => sprintf(
                    'Sorry, we could not save your application right now. Please try again or email %s directly.',
                    $fallback_email
                ),
            ));
        }

        // Notify admin via email. Uses configured email settings for the
        // From header so deliverability is good (proper SPF/DKIM). The admin
        // recipient can be overridden via the slw_admin_notification_email
        // option; otherwise falls back to the Settings > General admin_email.
        // Send to the wholesale email address first, fall back to WP admin email
        $admin_email = get_option( 'slw_admin_notification_email' );
        if ( ! $admin_email && class_exists( 'SLW_Email_Settings' ) ) {
            $admin_email = SLW_Email_Settings::get( 'from_address' );
        }
        if ( ! $admin_email ) {
            $admin_email = get_option( 'admin_email' );
        }

        $subject = 'New Wholesale Application: ' . $data['business_name'];
        $body = "A new wholesale application has been submitted.\n\n";
        $body .= "Business: {$data['business_name']}\n";
        $body .= "Contact: {$data['contact_name']}\n";
        $body .= "Email: {$data['email']}\n";
        $body .= "Phone: {$data['phone']}\n\n";
        $body .= "Review it in your WordPress admin under Wholesale Applications:\n";
        $body .= admin_url( 'admin.php?page=slw-applications' ) . "\n";

        $email_headers = SLW_Email_Settings::get_headers();
        $email_headers[] = 'Reply-To: ' . $data['email']; // Override reply-to with applicant's email

        $sent = wp_mail( $admin_email, $subject, $body, $email_headers );
        if ( ! $sent ) {
            error_log( 'SLW: Failed to send admin notification to ' . $admin_email );
        }

        $owner = SLW_Email_Settings::get( 'owner_name' );
        $success_msg = $owner
            ? sprintf( 'Thanks for applying! %s reviews applications personally and you\'ll hear back within 2-3 business days.', $owner )
            : 'Thanks for applying! We review applications personally and you\'ll hear back within 2-3 business days.';

        wp_send_json_success( array(
            'message' => $success_msg,
        ) );
    }

    /**
     * Add the "Wholesale Applications" admin menu item.
     */
    public static function add_admin_menu() {
        // SVG icon — storefront with price tag (B2B wholesale).
        // WordPress tints it to match the current admin color scheme.
        $icon_svg = 'data:image/svg+xml;base64,' . base64_encode(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">'
            . '<path d="M2 7L10 2L18 7Z" fill="currentColor" opacity="0.8"/>'
            . '<rect x="3" y="7" width="14" height="11" rx="1" fill="currentColor"/>'
            . '<rect x="7.5" y="11" width="5" height="7" rx="0.5" fill="white" opacity="0.9"/>'
            . '<rect x="4.5" y="8.5" width="3" height="2.5" rx="0.5" fill="white" opacity="0.7"/>'
            . '<rect x="12.5" y="8.5" width="3" height="2.5" rx="0.5" fill="white" opacity="0.7"/>'
            . '<circle cx="11.5" cy="14.5" r="0.5" fill="currentColor"/>'
            . '</svg>'
        );

        add_menu_page(
            'Wholesale',
            'Wholesale',
            'manage_woocommerce',
            'slw-applications',
            array( __CLASS__, 'render_admin_page' ),
            $icon_svg,
            56
        );

        // Rename the auto-generated first sub-menu item from "Sego Lily" to "Applications"
        add_submenu_page(
            'slw-applications',
            'Wholesale Applications',
            'Applications',
            'manage_woocommerce',
            'slw-applications',
            array( __CLASS__, 'render_admin_page' )
        );
    }

    /**
     * Render the admin applications list or single application detail view.
     */
    public static function render_admin_page() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'slw_applications';

        // Single application view
        if ( isset( $_GET['app_id'] ) ) {
            $app = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM {$table} WHERE id = %d", absint( $_GET['app_id'] )
            ) );

            if ( ! $app ) {
                echo '<div class="wrap"><p>Application not found.</p></div>';
                return;
            }

            $nonce = wp_create_nonce( 'slw_admin_action' );
            ?>
            <div class="wrap">
                <h1>Wholesale Application #<?php echo esc_html( $app->id ); ?></h1>
                <table class="form-table">
                    <tr><th>Status</th><td><strong><?php echo esc_html( ucfirst( $app->status ) ); ?></strong></td></tr>
                    <tr><th>Business Name</th><td><?php echo esc_html( $app->business_name ); ?></td></tr>
                    <tr><th>Contact Name</th><td><?php echo esc_html( $app->contact_name ); ?></td></tr>
                    <tr><th>Email</th><td><a href="mailto:<?php echo esc_attr( $app->email ); ?>"><?php echo esc_html( $app->email ); ?></a></td></tr>
                    <tr><th>Phone</th><td><?php echo esc_html( $app->phone ); ?></td></tr>
                    <tr><th>Address</th><td><?php echo nl2br( esc_html( $app->address ) ); ?></td></tr>
                    <tr><th>Website / Social</th><td><?php
                        // Handle both URL and social handle formats. Build a
                        // clickable link if the value looks like a URL or domain,
                        // otherwise show it as plain text.
                        if ( ! $app->website ) {
                            echo 'N/A';
                        } else {
                            $val = trim( $app->website );
                            $lower = strtolower( $val );
                            if ( strpos( $lower, 'http://' ) === 0 || strpos( $lower, 'https://' ) === 0 ) {
                                echo '<a href="' . esc_url( $val ) . '" target="_blank">' . esc_html( $val ) . '</a>';
                            } elseif ( strpos( $lower, 'www.' ) === 0 || ( strpos( $val, '.' ) !== false && strpos( $val, '@' ) !== 0 && strpos( $val, ' ' ) === false ) ) {
                                // Looks like a bare domain, prepend https://
                                echo '<a href="' . esc_url( 'https://' . $val ) . '" target="_blank">' . esc_html( $val ) . '</a>';
                            } elseif ( strpos( $val, '@' ) === 0 ) {
                                // Instagram-style handle, link to Instagram
                                $handle = ltrim( $val, '@' );
                                echo '<a href="' . esc_url( 'https://instagram.com/' . $handle ) . '" target="_blank">' . esc_html( $val ) . '</a>';
                            } else {
                                // Plain text fallback
                                echo esc_html( $val );
                            }
                        }
                    ?></td></tr>
                    <tr><th>EIN / Resale Certificate</th><td><?php echo esc_html( SLW_Encryption::decrypt( $app->ein ) ); ?></td></tr>
                    <tr><th>Business Type</th><td><?php echo esc_html( $app->business_type ); ?></td></tr>
                    <tr><th>How They Heard About Us</th><td><?php echo esc_html( $app->how_heard ); ?></td></tr>
                    <tr><th>Why They Want to Carry Our Products</th><td><?php echo nl2br( esc_html( $app->why_carry ) ); ?></td></tr>
                    <tr><th>Submitted</th><td><?php echo esc_html( $app->submitted_at ); ?></td></tr>
                    <tr><th>IP Address</th><td><?php echo esc_html( $app->ip_address ); ?></td></tr>
                </table>

                <?php if ( $app->status === 'pending' ) : ?>
                <p>
                    <a href="<?php echo wp_nonce_url( admin_url( 'admin.php?page=slw-applications&action=approve&app_id=' . $app->id ), 'slw_admin_action' ); ?>"
                       class="button button-primary" onclick="return confirm('Approve this application? This will create a wholesale account and send a welcome email.');">
                        Approve
                    </a>
                    <a href="<?php echo wp_nonce_url( admin_url( 'admin.php?page=slw-applications&action=decline&app_id=' . $app->id ), 'slw_admin_action' ); ?>"
                       class="button" onclick="return confirm('Decline this application?');">
                        Decline
                    </a>
                </p>
                <?php endif; ?>

                <p><a href="<?php echo admin_url( 'admin.php?page=slw-applications' ); ?>">&larr; Back to all applications</a></p>
            </div>
            <?php
            return;
        }

        // List view
        $status_filter = sanitize_text_field( $_GET['status'] ?? 'all' );
        $where = '';
        if ( in_array( $status_filter, array( 'pending', 'approved', 'declined' ), true ) ) {
            $where = $wpdb->prepare( "WHERE status = %s", $status_filter );
        }

        $applications = $wpdb->get_results( "SELECT * FROM {$table} {$where} ORDER BY submitted_at DESC" );

        // Count by status for filter tabs
        $counts = $wpdb->get_results( "SELECT status, COUNT(*) as count FROM {$table} GROUP BY status", OBJECT_K );
        $total = array_sum( wp_list_pluck( $counts, 'count' ) );
        ?>
        <div class="wrap">
            <h1>Wholesale Applications
                <a href="<?php echo wp_nonce_url( admin_url( 'admin.php?page=slw-applications&action=export_csv' ), 'slw_admin_action' ); ?>" class="page-title-action">Export CSV</a>
            </h1>

            <!-- Summary Stats + Quick Info -->
            <?php
            $pending_count  = $counts['pending']->count ?? 0;
            $approved_count = $counts['approved']->count ?? 0;
            $declined_count = $counts['declined']->count ?? 0;
            $approval_rate  = $total > 0 ? round( ( $approved_count / $total ) * 100 ) : 0;
            // Average time to review (approved applications only)
            $avg_review = '';
            if ( $approved_count > 0 ) {
                $avg_days = $wpdb->get_var( "SELECT AVG(TIMESTAMPDIFF(HOUR, submitted_at, reviewed_at)) FROM {$table} WHERE status = 'approved' AND reviewed_at IS NOT NULL" );
                if ( $avg_days !== null ) {
                    $hrs = round( (float) $avg_days );
                    $avg_review = $hrs < 24 ? $hrs . 'hr' : round( $hrs / 24, 1 ) . ' days';
                }
            }
            ?>
            <div class="slw-page-summary">
                <div class="slw-page-summary__stats">
                    <div class="slw-page-summary__stat">
                        <span class="slw-page-summary__number" style="color:#D4AF37;"><?php echo esc_html( $pending_count ); ?></span>
                        <span class="slw-page-summary__label">Awaiting Review</span>
                    </div>
                    <div class="slw-page-summary__stat">
                        <span class="slw-page-summary__number" style="color:#2e7d32;"><?php echo esc_html( $approved_count ); ?></span>
                        <span class="slw-page-summary__label">Approved</span>
                    </div>
                    <div class="slw-page-summary__stat">
                        <span class="slw-page-summary__number" style="color:#c62828;"><?php echo esc_html( $declined_count ); ?></span>
                        <span class="slw-page-summary__label">Declined</span>
                    </div>
                    <div class="slw-page-summary__stat">
                        <span class="slw-page-summary__number" style="color:#386174;"><?php echo esc_html( $approval_rate ); ?>%</span>
                        <span class="slw-page-summary__label">Approval Rate</span>
                    </div>
                    <?php if ( $avg_review ) : ?>
                    <div class="slw-page-summary__stat">
                        <span class="slw-page-summary__number" style="color:#628393;"><?php echo esc_html( $avg_review ); ?></span>
                        <span class="slw-page-summary__label">Avg Response Time</span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <ul class="subsubsub">
                <li><a href="?page=slw-applications&status=all" <?php echo $status_filter === 'all' ? 'class="current"' : ''; ?>>All (<?php echo $total; ?>)</a> |</li>
                <li><a href="?page=slw-applications&status=pending" <?php echo $status_filter === 'pending' ? 'class="current"' : ''; ?>>Pending (<?php echo $counts['pending']->count ?? 0; ?>)</a> |</li>
                <li><a href="?page=slw-applications&status=approved" <?php echo $status_filter === 'approved' ? 'class="current"' : ''; ?>>Approved (<?php echo $counts['approved']->count ?? 0; ?>)</a> |</li>
                <li><a href="?page=slw-applications&status=declined" <?php echo $status_filter === 'declined' ? 'class="current"' : ''; ?>>Declined (<?php echo $counts['declined']->count ?? 0; ?>)</a></li>
            </ul>
            <table class="wp-list-table widefat fixed striped" style="margin-top: 20px;">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Business Name</th>
                        <th>Contact</th>
                        <th>Email</th>
                        <th>Business Type</th>
                        <th>Status</th>
                        <th>Submitted</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( empty( $applications ) ) : ?>
                        <tr><td colspan="8" style="text-align:center;padding:40px 20px;color:#628393;font-style:italic;">No applications yet. Share your wholesale application page (<a href="<?php echo esc_url( home_url( '/wholesale-partners' ) ); ?>" target="_blank">/wholesale-partners</a>) to start receiving applications.</td></tr>
                    <?php else : ?>
                        <?php foreach ( $applications as $app ) : ?>
                        <tr>
                            <td><?php echo esc_html( $app->id ); ?></td>
                            <td><a href="?page=slw-applications&app_id=<?php echo $app->id; ?>"><?php echo esc_html( $app->business_name ); ?></a></td>
                            <td><?php echo esc_html( $app->contact_name ); ?></td>
                            <td><?php echo esc_html( $app->email ); ?></td>
                            <td><?php echo esc_html( $app->business_type ); ?></td>
                            <td><span class="slw-status-<?php echo esc_attr( $app->status ); ?>"><?php echo esc_html( ucfirst( $app->status ) ); ?></span></td>
                            <td><?php echo esc_html( date( 'M j, Y', strtotime( $app->submitted_at ) ) ); ?></td>
                            <td>
                                <a href="?page=slw-applications&app_id=<?php echo $app->id; ?>">View</a>
                                <?php if ( $app->status === 'pending' ) : ?>
                                | <a href="<?php echo wp_nonce_url( admin_url( 'admin.php?page=slw-applications&action=approve&app_id=' . $app->id ), 'slw_admin_action' ); ?>">Approve</a>
                                | <a href="<?php echo wp_nonce_url( admin_url( 'admin.php?page=slw-applications&action=decline&app_id=' . $app->id ), 'slw_admin_action' ); ?>">Decline</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <style>
            .slw-status-pending { color: #996800; font-weight: bold; }
            .slw-status-approved { color: #007017; font-weight: bold; }
            .slw-status-declined { color: #8b0000; font-weight: bold; }
        </style>
        <?php
    }

    /**
     * Handle approve/decline admin actions. Runs on admin_init so it fires
     * before headers are sent (allows redirect after processing).
     */
    public static function handle_admin_action() {
        if ( ! isset( $_GET['page'] ) || $_GET['page'] !== 'slw-applications' ) {
            return;
        }
        if ( ! isset( $_GET['action'] ) ) {
            return;
        }
        if ( ! wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'slw_admin_action' ) ) {
            return;
        }
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }

        $action = sanitize_text_field( $_GET['action'] );

        // CSV export does not require app_id
        if ( $action === 'export_csv' ) {
            self::export_csv();
            exit;
        }

        if ( ! isset( $_GET['app_id'] ) ) {
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'slw_applications';
        $app_id = absint( $_GET['app_id'] );
        $app = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $app_id ) );

        if ( ! $app || $app->status !== 'pending' ) {
            return;
        }

        if ( $action === 'approve' ) {
            self::approve_application( $app );
        } elseif ( $action === 'decline' ) {
            self::decline_application( $app );
        }

        wp_redirect( admin_url( 'admin.php?page=slw-applications&app_id=' . $app_id . '&updated=1' ) );
        exit;
    }

    /**
     * Stream all applications as a CSV download. Useful for bulk review,
     * for sharing with Camila, or for migrating to another system later.
     */
    private static function export_csv() {
        global $wpdb;
        $table = $wpdb->prefix . 'slw_applications';
        $apps  = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY submitted_at DESC", ARRAY_A );

        $filename = 'sego-lily-wholesale-applications-' . date( 'Y-m-d' ) . '.csv';
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( "Content-Disposition: attachment; filename={$filename}" );

        $out = fopen( 'php://output', 'w' );
        if ( ! empty( $apps ) ) {
            fputcsv( $out, array_keys( $apps[0] ) );
            foreach ( $apps as $row ) {
                fputcsv( $out, $row );
            }
        }
        fclose( $out );
    }

    /**
     * Approve: create WC account with wholesale role, update status, send
     * welcome email, and fire AIOS webhook.
     */
    private static function approve_application( $app ) {
        global $wpdb;
        $table = $wpdb->prefix . 'slw_applications';

        // Parse first/last name from contact_name
        $name_parts = explode( ' ', $app->contact_name, 2 );
        $first_name = $name_parts[0];
        $last_name  = $name_parts[1] ?? '';

        // Create or update the WP user
        $existing_user = get_user_by( 'email', $app->email );
        if ( $existing_user ) {
            // User already exists (retail customer). ADD wholesale role (keep existing roles
            // so the context switcher works — they can shop as retail or wholesale).
            $existing_user->add_role( 'wholesale_customer' );
            $user_id = $existing_user->ID;
        } else {
            // Generate a password and create the account
            $password = wp_generate_password( 12, true );
            $username = sanitize_user( strtolower( $first_name . '.' . $last_name ), true );
            if ( username_exists( $username ) ) {
                $username = $username . '_' . wp_rand( 100, 999 );
            }
            $user_id = wp_insert_user( array(
                'user_login' => $username,
                'user_email' => $app->email,
                'user_pass'  => $password,
                'first_name' => $first_name,
                'last_name'  => $last_name,
                'role'       => 'wholesale_customer',
            ));

            if ( is_wp_error( $user_id ) ) {
                return;
            }
        }

        // Store business info as user meta (EIN stays encrypted)
        update_user_meta( $user_id, 'slw_business_name', $app->business_name );
        update_user_meta( $user_id, 'slw_ein', $app->ein );

        // Audit log
        SLW_Audit_Log::log( 'application_approved', sprintf( 'Application approved for %s', $app->business_name ) );
        update_user_meta( $user_id, 'slw_business_type', $app->business_type );
        update_user_meta( $user_id, 'slw_application_id', $app->id );

        // Mark application as approved
        $wpdb->update( $table, array(
            'status'      => 'approved',
            'reviewed_at' => current_time( 'mysql' ),
            'reviewed_by' => get_current_user_id(),
        ), array( 'id' => $app->id ) );

        // Send welcome email with login details
        $site_url      = home_url();
        $login_url     = wp_login_url( home_url( '/wholesale-dashboard' ) );
        $business_name = SLW_Email_Settings::get_business_name();
        $reply_email   = SLW_Email_Settings::get( 'reply_to' );
        $subject       = 'Welcome to ' . $business_name . ' Wholesale!';

        $body  = "Hi {$first_name},\n\n";
        $body .= "Great news! Your wholesale application for {$app->business_name} has been approved.\n\n";
        $discount = get_option( 'slw_discount_percent', 50 );
        $body .= "You now have access to wholesale pricing on all " . $business_name . " products at " . $discount . "% off retail.\n\n";
        if ( ! $existing_user ) {
            $body .= "Your login details:\n";
            $body .= "Username: {$username}\n";
            $body .= "Password: {$password}\n";
            $body .= "Login: {$login_url}\n\n";
            $body .= "Please change your password after your first login.\n\n";
        } else {
            $body .= "Log in with your existing account: {$login_url}\n\n";
        }
        $minimum = number_format( (float) get_option( 'slw_first_order_minimum', 300 ), 0 );
        $body .= "Your first order has a \${$minimum} minimum. After that, you can reorder any amount.\n\n";
        $body .= "Once you're logged in, head to {$site_url}/wholesale-order to browse products and place your order.\n\n";
        $body .= "You'll receive a follow-up email shortly with more details about our wholesale program, product highlights, and tips for getting started.\n\n";
        $body .= "Questions? Reply to this email or reach out at {$reply_email}.\n\n";
        $body .= "Welcome to the family,\n" . SLW_Email_Settings::get_signature();

        wp_mail( $app->email, $subject, $body, SLW_Email_Settings::get_headers() );

        // Fire AIOS webhook for Mautic onboarding sequence
        SLW_Webhooks::fire( 'wholesale-approved', array(
            'email'         => $app->email,
            'first_name'    => $first_name,
            'business_name' => $app->business_name,
        ));
    }

    /**
     * Decline: update status and send a polite decline email.
     */
    private static function decline_application( $app ) {
        global $wpdb;
        $table = $wpdb->prefix . 'slw_applications';

        $wpdb->update( $table, array(
            'status'      => 'declined',
            'reviewed_at' => current_time( 'mysql' ),
            'reviewed_by' => get_current_user_id(),
        ), array( 'id' => $app->id ) );

        // Audit log
        SLW_Audit_Log::log( 'application_declined', sprintf( 'Application declined for %s', $app->business_name ) );

        $name_parts = explode( ' ', $app->contact_name, 2 );
        $first_name = $name_parts[0];

        $business_name = SLW_Email_Settings::get_business_name();
        $site_domain   = wp_parse_url( home_url(), PHP_URL_HOST );

        $subject = 'Regarding Your ' . $business_name . ' Wholesale Application';
        $body  = "Hi {$first_name},\n\n";
        $body .= "Thank you for your interest in carrying " . $business_name . ".\n\n";
        $body .= "After reviewing your application, we're not able to move forward with a wholesale partnership at this time. ";
        $body .= "This could be due to territory overlap, business fit, or other factors.\n\n";
        $body .= "You're always welcome to shop our retail products at " . $site_domain . ", ";
        $body .= "and we'd be happy to reconsider if your situation changes.\n\n";
        $body .= "Wishing you the best,\n" . SLW_Email_Settings::get_signature();

        wp_mail( $app->email, $subject, $body, SLW_Email_Settings::get_headers() );
    }

    /**
     * Get the client IP, accounting for proxies and Cloudflare.
     */
    private static function get_client_ip() {
        $headers = array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' );
        foreach ( $headers as $header ) {
            if ( ! empty( $_SERVER[ $header ] ) ) {
                $ip = explode( ',', $_SERVER[ $header ] );
                return trim( $ip[0] );
            }
        }
        return '0.0.0.0';
    }
}
