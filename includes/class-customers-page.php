<?php
/**
 * Customers Page
 *
 * Consolidated admin page with tabbed navigation for
 * Customers, Leads, and Import functionality.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class SLW_Customers_Page {

    public static function init() {
        add_action( 'admin_init', array( __CLASS__, 'maybe_export_csv' ) );
        add_action( 'admin_init', array( __CLASS__, 'maybe_backfill_mautic_sync_flag' ) );
        add_action( 'wp_ajax_slw_deactivate_wholesale', array( __CLASS__, 'ajax_deactivate_wholesale' ) );
        add_action( 'wp_ajax_slw_resend_welcome', array( __CLASS__, 'ajax_resend_welcome' ) );
        add_action( 'admin_post_slw_sync_mautic_bulk', array( __CLASS__, 'handle_sync_mautic_bulk' ) );
    }

    /**
     * AJAX: resend the wholesale welcome email (login credentials) to an
     * existing wholesale customer. Generates a fresh password, applies it,
     * and re-sends the same email Quick Add would send on first creation
     * via wp_mail (Holly's transactional pipe).
     *
     * Added v4.6.11 to give Holly a one-click recovery when a welcome email
     * lands in a customer's spam folder or gets missed.
     */
    public static function ajax_resend_welcome() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }
        check_ajax_referer( 'slw_resend_welcome', 'nonce' );

        $user_id = isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0;
        if ( ! $user_id ) {
            wp_send_json_error( 'Missing user_id' );
        }

        if ( ! class_exists( 'SLW_Premium_Features' ) ) {
            wp_send_json_error( 'Premium features not loaded' );
        }

        $result = SLW_Premium_Features::resend_welcome_email( $user_id );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }

        $user = get_userdata( $user_id );
        wp_send_json_success( array(
            'message' => 'Welcome email re-sent to ' . $user->user_email . ' with a fresh password.',
        ) );
    }

    /**
     * One-time backfill of the slw_synced_to_mautic user_meta flag.
     *
     * v4.6.8 introduced the flag and the bulk-sync banner, but the flag was
     * only set going forward. Any wholesale customer already in Mautic
     * (application-path approvals, customers tagged via direct API, anyone
     * tagged before this flag existed) showed up as "unsynced" on the
     * Customers page even though they were correctly tagged. This handler
     * runs once: queries Mautic for the set of emails carrying the
     * wholesale-approved tag and sets the flag for matching WP customers.
     * Gated by slw_mautic_backfill_done so it never repeats. Skipped
     * silently if Mautic is unconfigured/unreachable (retries next load).
     */
    public static function maybe_backfill_mautic_sync_flag() {
        if ( get_option( 'slw_mautic_backfill_done' ) ) {
            return;
        }
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }
        if ( ! class_exists( 'SLW_Webhooks' ) ) {
            return;
        }

        $tagged_emails = SLW_Webhooks::get_mautic_emails_with_tag( 'wholesale-approved' );
        if ( $tagged_emails === false ) {
            return; // Retry on next admin load.
        }

        $users = get_users( array(
            'role'   => 'wholesale_customer',
            'fields' => array( 'ID', 'user_email' ),
        ) );

        foreach ( $users as $user ) {
            if ( get_user_meta( $user->ID, 'slw_synced_to_mautic', true ) ) {
                continue;
            }
            if ( ! empty( $tagged_emails[ strtolower( $user->user_email ) ] ) ) {
                update_user_meta( $user->ID, 'slw_synced_to_mautic', current_time( 'mysql' ) );
            }
        }

        update_option( 'slw_mautic_backfill_done', current_time( 'mysql' ) );
    }

    /**
     * Bulk-sync wholesale customers to Mautic by firing the
     * wholesale-approved webhook for each one without slw_synced_to_mautic
     * user_meta. Idempotent: customers with the flag are skipped.
     *
     * Backfill mechanism for the manual-add Mautic sync gap fixed in v4.6.8.
     * Pre-v4.6.8 the Quick Add and CSV Import paths skipped the webhook
     * entirely, leaving manually-added customers stranded outside Mautic.
     * This processes all of them in one click.
     */
    public static function handle_sync_mautic_bulk() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( 'Unauthorized', 403 );
        }
        check_admin_referer( 'slw_sync_mautic_bulk' );

        if ( ! class_exists( 'SLW_Webhooks' ) ) {
            wp_safe_redirect( add_query_arg( 'slw_sync_result', 'no_webhooks', admin_url( 'admin.php?page=slw-customers&tab=customers' ) ) );
            exit;
        }

        $users = get_users( array(
            'role'   => 'wholesale_customer',
            'fields' => array( 'ID', 'user_email' ),
        ) );

        // Pre-fetch the set of emails already tagged in Mautic so we don't
        // re-fire the webhook for contacts that are genuinely synced (just
        // missing the WP-side flag). Re-firing wouldn't double-tag (Mautic
        // dedups) but WOULD re-enroll them in the onboarding campaign,
        // duplicating emails. So if Mautic already has them, just set the
        // flag and skip the webhook.
        $tagged_emails = SLW_Webhooks::get_mautic_emails_with_tag( 'wholesale-approved' );
        if ( ! is_array( $tagged_emails ) ) {
            $tagged_emails = array();
        }

        $synced = 0;
        $skipped = 0;
        foreach ( $users as $user ) {
            if ( get_user_meta( $user->ID, 'slw_synced_to_mautic', true ) ) {
                $skipped++;
                continue;
            }
            if ( ! empty( $tagged_emails[ strtolower( $user->user_email ) ] ) ) {
                update_user_meta( $user->ID, 'slw_synced_to_mautic', current_time( 'mysql' ) );
                $skipped++;
                continue;
            }

            $first_name    = get_user_meta( $user->ID, 'first_name', true );
            $last_name     = get_user_meta( $user->ID, 'last_name', true );
            $business_name = get_user_meta( $user->ID, 'slw_business_name', true );

            SLW_Webhooks::fire( 'wholesale-approved', array(
                'email'         => $user->user_email,
                'first_name'    => $first_name,
                'last_name'     => $last_name,
                'business_name' => $business_name,
                'source'        => 'mautic_backfill_sync',
            ) );
            update_user_meta( $user->ID, 'slw_synced_to_mautic', current_time( 'mysql' ) );
            $synced++;
        }

        $result = $synced . '_' . $skipped;
        wp_safe_redirect( add_query_arg( array(
            'slw_sync_result' => $result,
        ), admin_url( 'admin.php?page=slw-customers&tab=customers' ) ) );
        exit;
    }

    /**
     * AJAX: deactivate a wholesale customer (remove role + Mautic tag).
     */
    public static function ajax_deactivate_wholesale() {
        check_ajax_referer( 'slw_customers_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( 'Permission denied.' );
        }

        $user_id = absint( $_POST['user_id'] ?? 0 );
        if ( ! $user_id ) {
            wp_send_json_error( 'Invalid user.' );
        }

        $user = get_userdata( $user_id );
        if ( ! $user || ! slw_is_wholesale_user( $user_id ) ) {
            wp_send_json_error( 'User is not a wholesale customer.' );
        }

        $user->remove_role( 'wholesale_customer' );

        // Remove Mautic tag so they can re-enter if reactivated
        if ( class_exists( 'SLW_Webhooks' ) ) {
            SLW_Webhooks::remove_mautic_tag( $user->user_email, 'wholesale-approved' );
        }

        // Audit log
        if ( class_exists( 'SLW_Audit_Log' ) ) {
            SLW_Audit_Log::log( 'wholesale_deactivated', sprintf( 'Wholesale access revoked for %s (%s)', $user->display_name, $user->user_email ) );
        }

        wp_send_json_success( 'Wholesale access removed.' );
    }

    /**
     * Handle CSV export of wholesale customers.
     */
    public static function maybe_export_csv() {
        if ( ! isset( $_GET['page'] ) || $_GET['page'] !== 'slw-customers' ) return;
        if ( ! isset( $_GET['action'] ) || $_GET['action'] !== 'export_csv' ) return;
        if ( ! current_user_can( 'manage_woocommerce' ) ) return;
        if ( ! wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'slw_export_customers' ) ) wp_die( 'Invalid nonce.' );

        $users = get_users( array( 'role' => 'wholesale_customer' ) );

        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename=wholesale-customers-' . date( 'Y-m-d' ) . '.csv' );

        $out = fopen( 'php://output', 'w' );
        fputcsv( $out, array( 'Name', 'Email', 'Business Name', 'Parent Organization', 'Tier', 'NET Terms', 'Phone', 'Address', 'EIN' ) );

        foreach ( $users as $user ) {
            $tier = class_exists( 'SLW_Tiers' ) ? SLW_Tiers::get_user_tier( $user->ID ) : 'standard';
            $net = class_exists( 'SLW_Gateway_Net30' ) ? SLW_Gateway_Net30::get_user_net_terms( $user->ID ) : 0;
            $ein = class_exists( 'SLW_Customer_Portal' ) ? SLW_Customer_Portal::get_user_ein( $user->ID ) : '';
            fputcsv( $out, array(
                $user->first_name . ' ' . $user->last_name,
                $user->user_email,
                get_user_meta( $user->ID, 'slw_business_name', true ),
                get_user_meta( $user->ID, 'slw_parent_organization', true ),
                ucfirst( $tier ),
                $net > 0 ? 'NET ' . $net : 'None',
                get_user_meta( $user->ID, 'billing_phone', true ),
                self::format_user_address( $user->ID ),
                $ein,
            ) );
        }
        fclose( $out );
        exit;
    }

    /**
     * Build a single-line address summary from a user's billing fields.
     * Falls back to slw_business_address (legacy) when WC fields are empty.
     */
    public static function format_user_address( $user_id ) {
        $line1 = trim( (string) get_user_meta( $user_id, 'billing_address_1', true ) );
        $line2 = trim( (string) get_user_meta( $user_id, 'billing_address_2', true ) );
        $city  = trim( (string) get_user_meta( $user_id, 'billing_city', true ) );
        $state = trim( (string) get_user_meta( $user_id, 'billing_state', true ) );
        $zip   = trim( (string) get_user_meta( $user_id, 'billing_postcode', true ) );

        $parts = array_filter( array( $line1, $line2 ) );
        $locality_parts = array_filter( array( $city, trim( $state . ' ' . $zip ) ) );
        if ( $locality_parts ) {
            $parts[] = implode( ', ', $locality_parts );
        }

        $address = implode( ', ', $parts );
        if ( $address ) {
            return $address;
        }

        // Legacy fallback for customers added before WC billing fields were captured
        return trim( (string) get_user_meta( $user_id, 'slw_business_address', true ) );
    }

    /**
     * Render the tabbed customers page.
     */
    public static function render_page() {
        $current_tab = sanitize_text_field( $_GET['tab'] ?? 'customers' );
        $tabs = array(
            'customers' => 'Customers',
            'leads'     => 'Leads',
            'import'    => 'Import',
            'referrals' => 'Referrals',
        );
        ?>
        <div class="wrap slw-admin-dashboard">
            <h1 class="slw-admin-dashboard__title">Customers</h1>
            <p class="slw-admin-dashboard__subtitle">Manage wholesale customers, leads, and imports</p>

            <nav class="nav-tab-wrapper" style="margin-bottom: 20px;">
                <?php foreach ( $tabs as $slug => $label ) : ?>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=slw-customers&tab=' . $slug ) ); ?>"
                       class="nav-tab <?php echo $current_tab === $slug ? 'nav-tab-active' : ''; ?>">
                        <?php echo esc_html( $label ); ?>
                    </a>
                <?php endforeach; ?>
            </nav>

            <?php
            switch ( $current_tab ) {
                case 'leads':
                    if ( class_exists( 'SLW_Lead_Capture' ) ) {
                        SLW_Lead_Capture::render_admin_page();
                    }
                    break;

                case 'referrals':
                    if ( class_exists( 'SLW_Referral_Dashboard' ) ) {
                        SLW_Referral_Dashboard::render_admin_summary();
                    }
                    break;

                case 'import':
                    if ( class_exists( 'SLW_Premium_Features' ) ) {
                        SLW_Premium_Features::render_import_page();
                    }
                    break;

                default:
                    self::render_customers_tab();
                    break;
            }
            ?>
        </div>
        <?php
    }

    /**
     * Render the Customers tab - list of wholesale_customer users.
     */
    private static function render_customers_tab() {
        $per_page    = 20;
        $paged       = max( 1, absint( $_GET['paged'] ?? 1 ) );
        $search      = sanitize_text_field( $_GET['s'] ?? '' );
        $org_filter  = sanitize_text_field( wp_unslash( $_GET['org'] ?? '' ) );
        $offset      = ( $paged - 1 ) * $per_page;

        // Build user query args.
        // Sort by parent organization first, then by business name when an org
        // filter is active, so locations group together. Otherwise newest first.
        $args = array(
            'role'    => 'wholesale_customer',
            'number'  => $per_page,
            'offset'  => $offset,
            'orderby' => $org_filter ? 'meta_value' : 'registered',
            'meta_key' => $org_filter ? 'slw_business_name' : '',
            'order'   => $org_filter ? 'ASC' : 'DESC',
        );

        // Apply parent organization filter when set.
        if ( $org_filter ) {
            $args['meta_query'] = array(
                array(
                    'key'   => 'slw_parent_organization',
                    'value' => $org_filter,
                ),
            );
        }

        // Search by business name, first name, last name, email, or parent organization.
        if ( $search ) {
            // Get IDs that match email/display_name separately.
            $email_match_args = array(
                'role'           => 'wholesale_customer',
                'search'         => '*' . $search . '*',
                'search_columns' => array( 'user_email', 'display_name' ),
                'fields'         => 'ID',
            );
            $email_matched_ids = get_users( $email_match_args );

            $meta_match_args = array(
                'role'       => 'wholesale_customer',
                'fields'     => 'ID',
                'meta_query' => array(
                    'relation' => 'OR',
                    array(
                        'key'     => 'slw_business_name',
                        'value'   => $search,
                        'compare' => 'LIKE',
                    ),
                    array(
                        'key'     => 'first_name',
                        'value'   => $search,
                        'compare' => 'LIKE',
                    ),
                    array(
                        'key'     => 'last_name',
                        'value'   => $search,
                        'compare' => 'LIKE',
                    ),
                    array(
                        'key'     => 'slw_parent_organization',
                        'value'   => $search,
                        'compare' => 'LIKE',
                    ),
                ),
            );
            $meta_matched_ids = get_users( $meta_match_args );

            $matched_ids = array_unique( array_merge( $email_matched_ids, $meta_matched_ids ) );

            if ( empty( $matched_ids ) ) {
                $matched_ids = array( 0 ); // Force empty result set.
            }

            $args['include'] = $matched_ids;
        }

        $user_query  = new WP_User_Query( $args );
        $customers   = $user_query->get_results();
        $total_users = $user_query->get_total();
        $total_pages = ceil( $total_users / $per_page );
        ?>

        <?php
        $export_url = wp_nonce_url( admin_url( 'admin.php?page=slw-customers&action=export_csv' ), 'slw_export_customers' );
        $sync_nonce = wp_create_nonce( 'slw_sync_mautic_bulk' );
        $sync_action_url = admin_url( 'admin-post.php' );

        // Pull all distinct parent organizations for the filter dropdown.
        global $wpdb;
        $all_organizations = $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT meta_value FROM {$wpdb->usermeta}
             WHERE meta_key = %s AND meta_value != ''
             ORDER BY meta_value ASC",
            'slw_parent_organization'
        ) );

        // Count unsynced wholesale customers for the Sync to Mautic button.
        $unsynced_count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->users} u
             INNER JOIN {$wpdb->usermeta} cap ON cap.user_id = u.ID AND cap.meta_key = %s AND cap.meta_value LIKE %s
             LEFT JOIN {$wpdb->usermeta} synced ON synced.user_id = u.ID AND synced.meta_key = %s
             WHERE synced.meta_value IS NULL OR synced.meta_value = ''",
            $wpdb->prefix . 'capabilities',
            '%wholesale_customer%',
            'slw_synced_to_mautic'
        ) );

        // Show the post-sync result notice.
        $sync_result = sanitize_text_field( $_GET['slw_sync_result'] ?? '' );
        if ( $sync_result === 'no_webhooks' ) : ?>
            <div class="notice notice-error is-dismissible"><p>Sync skipped: webhook system not loaded.</p></div>
        <?php elseif ( $sync_result && strpos( $sync_result, '_' ) !== false ) :
            list( $synced_n, $skipped_n ) = array_map( 'absint', explode( '_', $sync_result ) ); ?>
            <div class="notice notice-success is-dismissible"><p>Mautic sync complete. Synced <?php echo $synced_n; ?>, skipped <?php echo $skipped_n; ?> already-synced.</p></div>
        <?php endif; ?>

        <?php if ( $unsynced_count > 0 ) : ?>
            <div style="background:#FFF8E1;border:1px solid #ffe082;border-radius:6px;padding:12px 16px;margin-bottom:12px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;">
                <div style="color:#5d4037;">
                    <strong><?php echo $unsynced_count; ?> wholesale <?php echo $unsynced_count === 1 ? 'customer' : 'customers'; ?> not yet synced to Mautic.</strong>
                    <span style="font-size:13px;color:#996800;">Manually-added customers (Quick Add or CSV import) before v4.6.8 weren't auto-tagged in Mautic. Click below to backfill.</span>
                </div>
                <form method="post" action="<?php echo esc_url( $sync_action_url ); ?>" style="margin:0;">
                    <input type="hidden" name="action" value="slw_sync_mautic_bulk" />
                    <input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $sync_nonce ); ?>" />
                    <button type="submit" class="button button-primary" onclick="return confirm('Sync <?php echo $unsynced_count; ?> wholesale customer(s) to Mautic? This fires the wholesale-approved webhook for each, which tags them in Mautic and starts the onboarding sequence.');">Sync to Mautic</button>
                </form>
            </div>
        <?php endif; ?>

        <?php if ( $org_filter ) : ?>
            <!-- Active organization filter banner. Makes it visually obvious which subset is showing. -->
            <div style="background:#FFF8E1;border:1px solid #ffe082;border-radius:6px;padding:10px 14px;margin-bottom:12px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;">
                <div style="color:#5d4037;">
                    <strong>Showing locations in:</strong>
                    <span style="background:#fff;padding:2px 8px;border-radius:10px;font-weight:600;margin-left:6px;"><?php echo esc_html( $org_filter ); ?></span>
                    <span style="color:#996800;font-size:13px;margin-left:8px;"><?php echo esc_html( $total_users ); ?> location<?php echo $total_users !== 1 ? 's' : ''; ?></span>
                </div>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=slw-customers&tab=customers' ) ); ?>" class="button button-small">Clear filter</a>
            </div>
        <?php endif; ?>

        <!-- Search + Org Filter Box -->
        <div class="slw-admin-card" style="padding: 12px 16px; margin-bottom: 16px; display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap;">
            <form method="get" style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                <input type="hidden" name="page" value="slw-customers" />
                <input type="hidden" name="tab" value="customers" />

                <label for="slw-customer-search" class="screen-reader-text">Search</label>
                <input type="search" id="slw-customer-search" name="s"
                       value="<?php echo esc_attr( $search ); ?>"
                       placeholder="Search name, business, or organization..."
                       style="min-width: 280px;" />

                <?php if ( ! empty( $all_organizations ) ) : ?>
                    <label for="slw-org-filter" class="screen-reader-text">Filter by organization</label>
                    <select id="slw-org-filter" name="org" onchange="this.form.submit();" style="min-width:200px;">
                        <option value="">All organizations</option>
                        <?php foreach ( $all_organizations as $org ) : ?>
                            <option value="<?php echo esc_attr( $org ); ?>" <?php selected( $org_filter, $org ); ?>><?php echo esc_html( $org ); ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>

                <button type="submit" class="button">Search</button>
                <?php if ( $search || $org_filter ) : ?>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=slw-customers&tab=customers' ) ); ?>" class="button">Clear</a>
                <?php endif; ?>
            </form>
            <div style="display: flex; align-items: center; gap: 12px;">
                <a href="<?php echo esc_url( $export_url ); ?>" class="page-title-action">Export CSV</a>
                <span style="color: #628393; font-size: 13px;">
                    <?php echo esc_html( $total_users ); ?> customer<?php echo $total_users !== 1 ? 's' : ''; ?>
                </span>
            </div>
        </div>

        <!-- Customers Table -->
        <div class="slw-admin-card" style="padding: 0;">
            <table class="wp-list-table widefat striped">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Business Name</th>
                        <th>Organization</th>
                        <th>Email</th>
                        <th>Tier</th>
                        <th>NET Terms</th>
                        <th>Orders</th>
                        <th>Last Order</th>
                        <th>Address</th>
                        <th>EIN</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( empty( $customers ) ) : ?>
                        <tr>
                            <td colspan="11" style="text-align: center; padding: 24px; color: #628393;">
                                <?php echo $search ? 'No customers match your search.' : 'No wholesale customers found.'; ?>
                            </td>
                        </tr>
                    <?php else : ?>
                        <?php
                        // Batch query order counts and last order dates for all customers on this page.
                        $user_ids = wp_list_pluck( $customers, 'ID' );
                        $order_counts = array();
                        $last_orders  = array();
                        foreach ( $user_ids as $uid ) {
                            $order_counts[ $uid ] = 0;
                            $last_orders[ $uid ]  = null;
                        }
                        $all_order_ids = wc_get_orders( array(
                            'customer_id' => $user_ids,
                            'limit'       => -1,
                            'return'      => 'ids',
                            'status'      => array( 'wc-processing', 'wc-completed', 'wc-on-hold', 'wc-pending', 'wc-cancelled', 'wc-refunded', 'wc-failed' ),
                        ) );
                        foreach ( $all_order_ids as $oid ) {
                            $order = wc_get_order( $oid );
                            if ( ! $order ) continue;
                            $uid = $order->get_user_id();
                            if ( ! isset( $order_counts[ $uid ] ) ) continue;
                            $order_counts[ $uid ] = ( $order_counts[ $uid ] ?? 0 ) + 1;
                            $date = $order->get_date_created();
                            if ( $date && ( ! isset( $last_orders[ $uid ] ) || $date > $last_orders[ $uid ] ) ) {
                                $last_orders[ $uid ] = $date;
                            }
                        }
                        ?>
                        <?php foreach ( $customers as $customer ) : ?>
                            <?php
                            $user_id       = $customer->ID;
                            $first         = get_user_meta( $user_id, 'first_name', true );
                            $last          = get_user_meta( $user_id, 'last_name', true );
                            $full_name     = trim( $first . ' ' . $last ) ?: $customer->display_name;
                            $business_name = get_user_meta( $user_id, 'slw_business_name', true );
                            $tier          = class_exists( 'SLW_Tiers' ) ? SLW_Tiers::get_user_tier( $user_id ) : 'standard';
                            $net_terms     = class_exists( 'SLW_Gateway_Net30' ) ? SLW_Gateway_Net30::get_user_net_terms( $user_id ) : 0;

                            $order_count     = $order_counts[ $user_id ] ?? 0;
                            $last_order_date = isset( $last_orders[ $user_id ] ) && $last_orders[ $user_id ]
                                ? $last_orders[ $user_id ]->date_i18n( get_option( 'date_format' ) )
                                : 'None';
                            ?>
                            <?php
                            $address_str = self::format_user_address( $user_id );
                            $customer_ein = class_exists( 'SLW_Customer_Portal' ) ? SLW_Customer_Portal::get_user_ein( $user_id ) : '';
                            $parent_org   = get_user_meta( $user_id, 'slw_parent_organization', true );
                            // Build the org-filter URL so the pill filters to that organization on click.
                            $org_pill_url = $parent_org
                                ? add_query_arg( array(
                                    'page' => 'slw-customers',
                                    'tab'  => 'customers',
                                    'org'  => $parent_org,
                                  ), admin_url( 'admin.php' ) )
                                : '';
                            ?>
                            <tr>
                                <td><strong><?php echo esc_html( $full_name ); ?></strong></td>
                                <td><?php echo esc_html( $business_name ?: 'None' ); ?></td>
                                <td><?php if ( $parent_org ) : ?>
                                    <a href="<?php echo esc_url( $org_pill_url ); ?>"
                                       title="View all locations in <?php echo esc_attr( $parent_org ); ?>"
                                       style="display:inline-block;padding:2px 10px;background:#FFF8E1;color:#5d4037;border:1px solid #ffe082;border-radius:10px;font-size:12px;font-weight:600;text-decoration:none;line-height:1.5;">
                                        <?php echo esc_html( $parent_org ); ?>
                                    </a>
                                <?php else : ?>
                                    <span style="color:#999;font-size:12px;">None</span>
                                <?php endif; ?></td>
                                <td><a href="mailto:<?php echo esc_attr( $customer->user_email ); ?>"><?php echo esc_html( $customer->user_email ); ?></a></td>
                                <td><span class="slw-lead-status slw-lead-status--<?php echo esc_attr( $tier ); ?>"><?php echo esc_html( ucfirst( $tier ) ); ?></span></td>
                                <td><?php echo $net_terms ? 'NET ' . esc_html( $net_terms ) : 'None'; ?></td>
                                <td><?php echo esc_html( $order_count ); ?></td>
                                <td><?php echo $last_order_date; ?></td>
                                <td style="font-size:12px;color:#628393;max-width:200px;"><?php echo $address_str ? esc_html( $address_str ) : 'None'; ?></td>
                                <td style="font-size:12px;"><?php
                                    if ( $customer_ein ) {
                                        echo esc_html( $customer_ein );
                                    } else {
                                        echo '<span style="color:#c62828;font-weight:600;" title="No EIN on file">missing</span>';
                                    }
                                ?></td>
                                <td style="white-space:nowrap;">
                                    <a href="<?php echo esc_url( get_edit_user_link( $user_id ) ); ?>" class="button button-small">Edit</a>
                                    <button type="button" class="button button-small slw-resend-welcome-btn" data-user-id="<?php echo esc_attr( $user_id ); ?>" data-email="<?php echo esc_attr( $customer->user_email ); ?>">Resend Welcome</button>
                                    <button type="button" class="button button-small slw-deactivate-btn" data-user-id="<?php echo esc_attr( $user_id ); ?>" data-name="<?php echo esc_attr( $customer->display_name ); ?>" style="color:#c62828;">Deactivate</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php
        // Pagination.
        if ( $total_pages > 1 ) :
            $base_url = admin_url( 'admin.php?page=slw-customers&tab=customers' );
            if ( $search ) {
                $base_url = add_query_arg( 's', $search, $base_url );
            }
            ?>
            <div class="tablenav" style="margin-top: 12px;">
                <div class="tablenav-pages">
                    <span class="displaying-num"><?php echo esc_html( $total_users ); ?> items</span>
                    <span class="pagination-links">
                        <?php if ( $paged > 1 ) : ?>
                            <a class="first-page button" href="<?php echo esc_url( add_query_arg( 'paged', 1, $base_url ) ); ?>">&laquo;</a>
                            <a class="prev-page button" href="<?php echo esc_url( add_query_arg( 'paged', $paged - 1, $base_url ) ); ?>">&lsaquo;</a>
                        <?php else : ?>
                            <span class="tablenav-pages-navspan button disabled">&laquo;</span>
                            <span class="tablenav-pages-navspan button disabled">&lsaquo;</span>
                        <?php endif; ?>

                        <span class="paging-input">
                            <span class="tablenav-paging-text">
                                <?php echo esc_html( $paged ); ?> of <?php echo esc_html( $total_pages ); ?>
                            </span>
                        </span>

                        <?php if ( $paged < $total_pages ) : ?>
                            <a class="next-page button" href="<?php echo esc_url( add_query_arg( 'paged', $paged + 1, $base_url ) ); ?>">&rsaquo;</a>
                            <a class="last-page button" href="<?php echo esc_url( add_query_arg( 'paged', $total_pages, $base_url ) ); ?>">&raquo;</a>
                        <?php else : ?>
                            <span class="tablenav-pages-navspan button disabled">&rsaquo;</span>
                            <span class="tablenav-pages-navspan button disabled">&raquo;</span>
                        <?php endif; ?>
                    </span>
                </div>
            </div>
        <?php endif; ?>

        <script>
        (function() {
            var nonce = '<?php echo esc_js( wp_create_nonce( 'slw_customers_nonce' ) ); ?>';
            var resendNonce = '<?php echo esc_js( wp_create_nonce( 'slw_resend_welcome' ) ); ?>';

            document.querySelectorAll('.slw-resend-welcome-btn').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    var userId = this.getAttribute('data-user-id');
                    var email = this.getAttribute('data-email');
                    if (!confirm('Resend the welcome email (with a fresh login password) to ' + email + '?\n\nThis invalidates their current password.')) return;
                    btn.disabled = true;
                    var originalLabel = btn.textContent;
                    btn.textContent = 'Sending...';
                    var formData = new FormData();
                    formData.append('action', 'slw_resend_welcome');
                    formData.append('nonce', resendNonce);
                    formData.append('user_id', userId);
                    var xhr = new XMLHttpRequest();
                    xhr.open('POST', ajaxurl);
                    xhr.onload = function() {
                        var resp;
                        try { resp = JSON.parse(xhr.responseText); } catch(e) { resp = null; }
                        if (resp && resp.success) {
                            btn.textContent = 'Sent';
                            btn.style.color = '#2e7d32';
                            setTimeout(function() {
                                btn.textContent = originalLabel;
                                btn.style.color = '';
                                btn.disabled = false;
                            }, 4000);
                        } else {
                            btn.disabled = false;
                            btn.textContent = originalLabel;
                            alert('Could not resend: ' + (resp && resp.data ? resp.data : 'Unknown error'));
                        }
                    };
                    xhr.send(formData);
                });
            });

            document.querySelectorAll('.slw-deactivate-btn').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    var userId = this.getAttribute('data-user-id');
                    var name = this.getAttribute('data-name');
                    if (!confirm('Deactivate wholesale access for ' + name + '? They will lose wholesale pricing and portal access.')) return;
                    var row = this.closest('tr');
                    btn.disabled = true;
                    btn.textContent = 'Removing...';
                    var formData = new FormData();
                    formData.append('action', 'slw_deactivate_wholesale');
                    formData.append('nonce', nonce);
                    formData.append('user_id', userId);
                    var xhr = new XMLHttpRequest();
                    xhr.open('POST', ajaxurl);
                    xhr.onload = function() {
                        var resp;
                        try { resp = JSON.parse(xhr.responseText); } catch(e) { resp = null; }
                        if (resp && resp.success) {
                            row.style.opacity = '0.4';
                            btn.textContent = 'Deactivated';
                            btn.style.color = '#999';
                        } else {
                            btn.disabled = false;
                            btn.textContent = 'Deactivate';
                            alert('Could not deactivate: ' + (resp && resp.data ? resp.data : 'Unknown error'));
                        }
                    };
                    xhr.send(formData);
                });
            });
        })();
        </script>
        <?php
    }
}
