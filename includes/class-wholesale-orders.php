<?php
/**
 * Wholesale Orders Admin Page
 *
 * A filtered view of WooCommerce orders placed by wholesale_customer users.
 * Includes summary stats, filter bar, sortable table, pagination, and CSV export.
 * Uses HPOS-compatible wc_get_orders() exclusively.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class SLW_Wholesale_Orders {

    /** @var int Orders per page */
    const PER_PAGE = 20;

    public static function init() {
        // Handle CSV export early (before headers are sent)
        add_action( 'admin_init', array( __CLASS__, 'maybe_export_csv' ) );
    }

    /**
     * Render the Wholesale Orders admin page.
     */
    public static function render_page() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }

        // Gather wholesale user IDs
        $wholesale_users = get_users( array(
            'role'   => 'wholesale_customer',
            'fields' => 'ID',
        ) );

        if ( empty( $wholesale_users ) ) {
            self::render_empty_state();
            return;
        }

        // Parse filters
        $current_page   = max( 1, absint( $_GET['paged'] ?? 1 ) );
        $status_filter  = sanitize_text_field( $_GET['order_status'] ?? '' );
        $tier_filter    = sanitize_text_field( $_GET['tier'] ?? '' );
        $search         = sanitize_text_field( $_GET['s'] ?? '' );
        $date_from      = sanitize_text_field( $_GET['date_from'] ?? '' );
        $date_to        = sanitize_text_field( $_GET['date_to'] ?? '' );

        // If tier filter is set, narrow user IDs to that tier
        $filtered_user_ids = $wholesale_users;
        if ( $tier_filter ) {
            $filtered_user_ids = array();
            foreach ( $wholesale_users as $uid ) {
                $user_tier = class_exists( 'SLW_Tiers' ) ? SLW_Tiers::get_user_tier( $uid ) : 'standard';
                if ( $user_tier === $tier_filter ) {
                    $filtered_user_ids[] = $uid;
                }
            }
            if ( empty( $filtered_user_ids ) ) {
                self::render_empty_state( 'No orders found for the selected tier.' );
                return;
            }
        }

        // If searching by business name, narrow user IDs
        if ( $search ) {
            $search_ids = array();
            foreach ( $filtered_user_ids as $uid ) {
                $biz = get_user_meta( $uid, 'slw_business_name', true );
                if ( stripos( $biz, $search ) !== false ) {
                    $search_ids[] = $uid;
                }
            }
            $filtered_user_ids = $search_ids;
            if ( empty( $filtered_user_ids ) ) {
                self::render_empty_state( 'No orders found matching "' . esc_html( $search ) . '".' );
                return;
            }
        }

        // Build wc_get_orders args
        $args = array(
            'customer_id' => $filtered_user_ids,
            'limit'       => self::PER_PAGE,
            'paged'       => $current_page,
            'orderby'     => 'date',
            'order'       => 'DESC',
            'paginate'    => true,
        );

        if ( $status_filter && $status_filter !== 'all' ) {
            $args['status'] = 'wc-' . $status_filter;
        } else {
            $args['status'] = array( 'wc-processing', 'wc-completed', 'wc-on-hold', 'wc-pending', 'wc-cancelled', 'wc-refunded', 'wc-failed' );
        }

        if ( $date_from ) {
            $args['date_created'] = '>=' . $date_from;
        }
        if ( $date_to ) {
            // If we already set date_created for from, use a range
            if ( $date_from ) {
                $args['date_created'] = $date_from . '...' . $date_to . ' 23:59:59';
            } else {
                $args['date_created'] = '<=' . $date_to . ' 23:59:59';
            }
        }

        $results     = wc_get_orders( $args );
        $orders      = $results->orders;
        $total_orders = $results->total;
        $total_pages = $results->max_num_pages;

        // Summary stats: query ALL matching orders (no pagination) for totals
        $stats_args = $args;
        $stats_args['limit']    = -1;
        $stats_args['paginate'] = false;
        $stats_args['return']   = 'ids';
        $all_order_ids = wc_get_orders( $stats_args );

        $total_revenue = 0;
        foreach ( $all_order_ids as $oid ) {
            $o = wc_get_order( $oid );
            if ( $o ) {
                $total_revenue += (float) $o->get_total();
            }
        }
        $avg_order = count( $all_order_ids ) > 0 ? $total_revenue / count( $all_order_ids ) : 0;

        // Get available tiers for filter dropdown
        $tiers = array( 'standard' => 'Standard' );
        if ( class_exists( 'SLW_Tiers' ) && method_exists( 'SLW_Tiers', 'get_tiers' ) ) {
            $all_tiers = SLW_Tiers::get_tiers();
            if ( ! empty( $all_tiers ) ) {
                $tiers = array();
                foreach ( $all_tiers as $slug => $config ) {
                    $tiers[ $slug ] = $config['label'] ?? ucfirst( $slug );
                }
            }
        }

        // CSV export URL
        $export_url = wp_nonce_url(
            admin_url( 'admin.php?page=slw-orders&action=export_csv'
                . ( $status_filter ? '&order_status=' . $status_filter : '' )
                . ( $tier_filter   ? '&tier=' . $tier_filter : '' )
                . ( $search        ? '&s=' . urlencode( $search ) : '' )
                . ( $date_from     ? '&date_from=' . $date_from : '' )
                . ( $date_to       ? '&date_to=' . $date_to : '' )
            ),
            'slw_export_orders'
        );

        ?>
        <div class="wrap slw-wholesale-orders">
            <h1 class="wp-heading-inline">Wholesale Orders</h1>
            <a href="<?php echo esc_url( $export_url ); ?>" class="page-title-action">Export CSV</a>
            <hr class="wp-header-end">

            <!-- Summary Stats -->
            <div class="slw-orders-summary">
                <div class="slw-orders-stat-card">
                    <span class="slw-orders-stat-label">Total Orders</span>
                    <span class="slw-orders-stat-value"><?php echo esc_html( number_format( count( $all_order_ids ) ) ); ?></span>
                </div>
                <div class="slw-orders-stat-card">
                    <span class="slw-orders-stat-label">Total Revenue</span>
                    <span class="slw-orders-stat-value"><?php echo wp_kses_post( wc_price( $total_revenue ) ); ?></span>
                </div>
                <div class="slw-orders-stat-card">
                    <span class="slw-orders-stat-label">Avg Order Value</span>
                    <span class="slw-orders-stat-value"><?php echo wp_kses_post( wc_price( $avg_order ) ); ?></span>
                </div>
            </div>

            <!-- Filter Bar -->
            <div class="slw-orders-filters">
                <form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
                    <input type="hidden" name="page" value="slw-orders" />

                    <label for="slw-filter-date-from">From</label>
                    <input type="date" id="slw-filter-date-from" name="date_from"
                           value="<?php echo esc_attr( $date_from ); ?>" />

                    <label for="slw-filter-date-to">To</label>
                    <input type="date" id="slw-filter-date-to" name="date_to"
                           value="<?php echo esc_attr( $date_to ); ?>" />

                    <select name="order_status" id="slw-filter-status">
                        <option value="">All Statuses</option>
                        <?php
                        $statuses = array(
                            'processing' => 'Processing',
                            'completed'  => 'Completed',
                            'on-hold'    => 'On Hold',
                            'pending'    => 'Pending',
                            'cancelled'  => 'Cancelled',
                            'refunded'   => 'Refunded',
                        );
                        foreach ( $statuses as $val => $label ) :
                        ?>
                            <option value="<?php echo esc_attr( $val ); ?>" <?php selected( $status_filter, $val ); ?>>
                                <?php echo esc_html( $label ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <select name="tier" id="slw-filter-tier">
                        <option value="">All Tiers</option>
                        <?php foreach ( $tiers as $slug => $label ) : ?>
                            <option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $tier_filter, $slug ); ?>>
                                <?php echo esc_html( $label ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <input type="search" name="s" placeholder="Search business name..."
                           value="<?php echo esc_attr( $search ); ?>" class="slw-orders-search" />

                    <?php submit_button( 'Filter', 'secondary', 'filter_action', false ); ?>

                    <?php if ( $status_filter || $tier_filter || $search || $date_from || $date_to ) : ?>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=slw-orders' ) ); ?>" class="button">Clear</a>
                    <?php endif; ?>
                </form>
            </div>

            <!-- Orders Table -->
            <?php if ( empty( $orders ) ) : ?>
                <div class="slw-orders-empty">
                    <p>No wholesale orders match your filters.</p>
                </div>
            <?php else : ?>
                <table class="wp-list-table widefat fixed striped slw-orders-table">
                    <thead>
                        <tr>
                            <th class="column-order" style="width:8%;">Order</th>
                            <th class="column-date" style="width:11%;">Date</th>
                            <th class="column-customer" style="width:13%;">Customer</th>
                            <th class="column-business" style="width:15%;">Business Name</th>
                            <th class="column-items" style="width:8%;">Items</th>
                            <th class="column-total" style="width:10%;">Total</th>
                            <th class="column-status" style="width:10%;">Status</th>
                            <th class="column-payment" style="width:13%;">Payment</th>
                            <th class="column-tier" style="width:9%;">Tier</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $orders as $order ) :
                            $user_id       = $order->get_user_id();
                            $customer_name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
                            $business_name = $user_id ? get_user_meta( $user_id, 'slw_business_name', true ) : '';
                            $item_count    = $order->get_item_count();
                            $status        = $order->get_status();
                            $payment       = $order->get_payment_method_title();
                            $tier          = $user_id && class_exists( 'SLW_Tiers' ) ? SLW_Tiers::get_user_tier( $user_id ) : 'standard';
                            $order_date    = $order->get_date_created();
                            $edit_url      = $order->get_edit_order_url();
                        ?>
                        <tr>
                            <td>
                                <a href="<?php echo esc_url( $edit_url ); ?>" class="slw-orders-order-link">
                                    <strong>#<?php echo esc_html( $order->get_order_number() ); ?></strong>
                                </a>
                            </td>
                            <td><?php echo $order_date ? esc_html( $order_date->date_i18n( 'M j, Y' ) ) : '&mdash;'; ?></td>
                            <td><?php echo esc_html( $customer_name ?: '(Guest)' ); ?></td>
                            <td><?php echo esc_html( $business_name ?: '&mdash;' ); ?></td>
                            <td><?php echo esc_html( $item_count ); ?></td>
                            <td><?php echo wp_kses_post( wc_price( $order->get_total() ) ); ?></td>
                            <td>
                                <span class="slw-status-badge slw-status-<?php echo esc_attr( $status ); ?>">
                                    <?php echo esc_html( wc_get_order_status_name( $status ) ); ?>
                                </span>
                            </td>
                            <td><?php echo esc_html( $payment ?: '&mdash;' ); ?></td>
                            <td>
                                <span class="slw-tier-badge slw-tier-badge--<?php echo esc_attr( $tier ); ?>">
                                    <?php echo esc_html( ucfirst( $tier ) ); ?>
                                </span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <!-- Pagination -->
                <?php if ( $total_pages > 1 ) : ?>
                    <div class="slw-orders-pagination">
                        <?php
                        $base_url = admin_url( 'admin.php?page=slw-orders' );
                        if ( $status_filter ) $base_url = add_query_arg( 'order_status', $status_filter, $base_url );
                        if ( $tier_filter )   $base_url = add_query_arg( 'tier', $tier_filter, $base_url );
                        if ( $search )        $base_url = add_query_arg( 's', $search, $base_url );
                        if ( $date_from )     $base_url = add_query_arg( 'date_from', $date_from, $base_url );
                        if ( $date_to )       $base_url = add_query_arg( 'date_to', $date_to, $base_url );

                        echo paginate_links( array(
                            'base'      => add_query_arg( 'paged', '%#%', $base_url ),
                            'format'    => '',
                            'current'   => $current_page,
                            'total'     => $total_pages,
                            'prev_text' => '&laquo; Previous',
                            'next_text' => 'Next &raquo;',
                        ) );
                        ?>
                        <span class="slw-orders-pagination-info">
                            Page <?php echo esc_html( $current_page ); ?> of <?php echo esc_html( $total_pages ); ?>
                            (<?php echo esc_html( number_format( $total_orders ) ); ?> orders)
                        </span>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Render empty state.
     */
    private static function render_empty_state( $message = '' ) {
        ?>
        <div class="wrap slw-wholesale-orders">
            <h1 class="wp-heading-inline">Wholesale Orders</h1>
            <hr class="wp-header-end">
            <div class="slw-orders-empty">
                <p><?php echo esc_html( $message ?: 'No wholesale orders found.' ); ?></p>
            </div>
        </div>
        <?php
    }

    /**
     * Handle CSV export request (fires on admin_init, before headers).
     */
    public static function maybe_export_csv() {
        if ( ! isset( $_GET['page'] ) || $_GET['page'] !== 'slw-orders' ) {
            return;
        }
        if ( ! isset( $_GET['action'] ) || $_GET['action'] !== 'export_csv' ) {
            return;
        }
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }
        if ( ! wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'slw_export_orders' ) ) {
            wp_die( 'Invalid nonce.' );
        }

        self::export_csv();
        exit;
    }

    /**
     * Stream wholesale orders as CSV.
     */
    private static function export_csv() {
        $wholesale_users = get_users( array(
            'role'   => 'wholesale_customer',
            'fields' => 'ID',
        ) );

        if ( empty( $wholesale_users ) ) {
            wp_die( 'No wholesale customers found.' );
        }

        // Apply same filters as the page
        $status_filter = sanitize_text_field( $_GET['order_status'] ?? '' );
        $tier_filter   = sanitize_text_field( $_GET['tier'] ?? '' );
        $search        = sanitize_text_field( $_GET['s'] ?? '' );
        $date_from     = sanitize_text_field( $_GET['date_from'] ?? '' );
        $date_to       = sanitize_text_field( $_GET['date_to'] ?? '' );

        $filtered_user_ids = $wholesale_users;

        if ( $tier_filter ) {
            $filtered_user_ids = array();
            foreach ( $wholesale_users as $uid ) {
                $user_tier = class_exists( 'SLW_Tiers' ) ? SLW_Tiers::get_user_tier( $uid ) : 'standard';
                if ( $user_tier === $tier_filter ) {
                    $filtered_user_ids[] = $uid;
                }
            }
        }

        if ( $search ) {
            $search_ids = array();
            foreach ( $filtered_user_ids as $uid ) {
                $biz = get_user_meta( $uid, 'slw_business_name', true );
                if ( stripos( $biz, $search ) !== false ) {
                    $search_ids[] = $uid;
                }
            }
            $filtered_user_ids = $search_ids;
        }

        if ( empty( $filtered_user_ids ) ) {
            wp_die( 'No orders match the current filters.' );
        }

        $args = array(
            'customer_id' => $filtered_user_ids,
            'limit'       => -1,
            'orderby'     => 'date',
            'order'       => 'DESC',
            'status'      => array( 'wc-processing', 'wc-completed', 'wc-on-hold', 'wc-pending', 'wc-cancelled', 'wc-refunded', 'wc-failed' ),
        );

        if ( $status_filter && $status_filter !== 'all' ) {
            $args['status'] = 'wc-' . $status_filter;
        }

        if ( $date_from && $date_to ) {
            $args['date_created'] = $date_from . '...' . $date_to . ' 23:59:59';
        } elseif ( $date_from ) {
            $args['date_created'] = '>=' . $date_from;
        } elseif ( $date_to ) {
            $args['date_created'] = '<=' . $date_to . ' 23:59:59';
        }

        $orders = wc_get_orders( $args );

        $filename = 'wholesale-orders-' . date( 'Y-m-d' ) . '.csv';
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename=' . $filename );

        $out = fopen( 'php://output', 'w' );
        fputcsv( $out, array(
            'Order #', 'Date', 'Customer', 'Email', 'Business Name', 'Items',
            'Subtotal', 'Tax', 'Shipping', 'Total', 'Status', 'Payment Method', 'Tier',
        ) );

        foreach ( $orders as $order ) {
            $user_id       = $order->get_user_id();
            $customer_name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
            $business_name = $user_id ? get_user_meta( $user_id, 'slw_business_name', true ) : '';
            $tier          = $user_id && class_exists( 'SLW_Tiers' ) ? SLW_Tiers::get_user_tier( $user_id ) : 'standard';
            $order_date    = $order->get_date_created();

            fputcsv( $out, array(
                $order->get_order_number(),
                $order_date ? $order_date->date_i18n( 'Y-m-d' ) : '',
                $customer_name,
                $order->get_billing_email(),
                $business_name,
                $order->get_item_count(),
                $order->get_subtotal(),
                $order->get_total_tax(),
                $order->get_shipping_total(),
                $order->get_total(),
                wc_get_order_status_name( $order->get_status() ),
                $order->get_payment_method_title(),
                ucfirst( $tier ),
            ) );
        }

        fclose( $out );
    }
}
