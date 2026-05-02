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
        add_action( 'wp_ajax_slw_deactivate_wholesale', array( __CLASS__, 'ajax_deactivate_wholesale' ) );
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
        fputcsv( $out, array( 'Name', 'Email', 'Business Name', 'Tier', 'NET Terms', 'Phone', 'Address', 'EIN' ) );

        foreach ( $users as $user ) {
            $tier = class_exists( 'SLW_Tiers' ) ? SLW_Tiers::get_user_tier( $user->ID ) : 'standard';
            $net = class_exists( 'SLW_Gateway_Net30' ) ? SLW_Gateway_Net30::get_user_net_terms( $user->ID ) : 0;
            $ein = class_exists( 'SLW_Customer_Portal' ) ? SLW_Customer_Portal::get_user_ein( $user->ID ) : '';
            fputcsv( $out, array(
                $user->first_name . ' ' . $user->last_name,
                $user->user_email,
                get_user_meta( $user->ID, 'slw_business_name', true ),
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
        $offset      = ( $paged - 1 ) * $per_page;

        // Build user query args.
        $args = array(
            'role'    => 'wholesale_customer',
            'number'  => $per_page,
            'offset'  => $offset,
            'orderby' => 'registered',
            'order'   => 'DESC',
        );

        // Search by business name, first name, last name, or email.
        if ( $search ) {
            $args['search']         = '*' . $search . '*';
            $args['search_columns'] = array( 'user_email', 'display_name' );
            $args['meta_query'] = array(
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
            );
            // WP_User_Query combines search + meta_query with AND by default.
            // We need OR across all of them, so use a custom approach:
            // Remove the built-in search and rely on meta_query + a filter.
            unset( $args['search'], $args['search_columns'] );

            // Instead, get IDs that match email/display_name separately.
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

        <?php $export_url = wp_nonce_url( admin_url( 'admin.php?page=slw-customers&action=export_csv' ), 'slw_export_customers' ); ?>

        <!-- Search Box -->
        <div class="slw-admin-card" style="padding: 12px 16px; margin-bottom: 16px; display: flex; align-items: center; justify-content: space-between;">
            <form method="get" style="display: flex; align-items: center; gap: 8px;">
                <input type="hidden" name="page" value="slw-customers" />
                <input type="hidden" name="tab" value="customers" />
                <label for="slw-customer-search" class="screen-reader-text">Search by business name</label>
                <input type="search" id="slw-customer-search" name="s"
                       value="<?php echo esc_attr( $search ); ?>"
                       placeholder="Search by business name..."
                       style="min-width: 280px;" />
                <button type="submit" class="button">Search</button>
                <?php if ( $search ) : ?>
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
                            <td colspan="10" style="text-align: center; padding: 24px; color: #628393;">
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
                                : '&mdash;';
                            ?>
                            <?php
                            $address_str = self::format_user_address( $user_id );
                            $customer_ein = class_exists( 'SLW_Customer_Portal' ) ? SLW_Customer_Portal::get_user_ein( $user_id ) : '';
                            ?>
                            <tr>
                                <td><strong><?php echo esc_html( $full_name ); ?></strong></td>
                                <td><?php echo esc_html( $business_name ?: '&mdash;' ); ?></td>
                                <td><a href="mailto:<?php echo esc_attr( $customer->user_email ); ?>"><?php echo esc_html( $customer->user_email ); ?></a></td>
                                <td><span class="slw-lead-status slw-lead-status--<?php echo esc_attr( $tier ); ?>"><?php echo esc_html( ucfirst( $tier ) ); ?></span></td>
                                <td><?php echo $net_terms ? 'NET ' . esc_html( $net_terms ) : '&mdash;'; ?></td>
                                <td><?php echo esc_html( $order_count ); ?></td>
                                <td><?php echo $last_order_date; ?></td>
                                <td style="font-size:12px;color:#628393;max-width:200px;"><?php echo $address_str ? esc_html( $address_str ) : '&mdash;'; ?></td>
                                <td style="font-size:12px;"><?php
                                    if ( $customer_ein ) {
                                        echo esc_html( $customer_ein );
                                    } else {
                                        echo '<span style="color:#c62828;font-weight:600;" title="No EIN on file">&mdash; missing</span>';
                                    }
                                ?></td>
                                <td style="white-space:nowrap;">
                                    <a href="<?php echo esc_url( get_edit_user_link( $user_id ) ); ?>" class="button button-small">Edit</a>
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
