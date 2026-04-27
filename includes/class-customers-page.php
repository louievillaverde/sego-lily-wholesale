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
        // Nothing needed - render is called by menu.
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

        // Search by business name via meta query.
        if ( $search ) {
            $args['meta_query'] = array(
                array(
                    'key'     => 'slw_business_name',
                    'value'   => $search,
                    'compare' => 'LIKE',
                ),
            );
        }

        $user_query  = new WP_User_Query( $args );
        $customers   = $user_query->get_results();
        $total_users = $user_query->get_total();
        $total_pages = ceil( $total_users / $per_page );
        ?>

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
            <span style="color: #628393; font-size: 13px;">
                <?php echo esc_html( $total_users ); ?> customer<?php echo $total_users !== 1 ? 's' : ''; ?>
            </span>
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
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( empty( $customers ) ) : ?>
                        <tr>
                            <td colspan="8" style="text-align: center; padding: 24px; color: #628393;">
                                <?php echo $search ? 'No customers match your search.' : 'No wholesale customers found.'; ?>
                            </td>
                        </tr>
                    <?php else : ?>
                        <?php foreach ( $customers as $customer ) : ?>
                            <?php
                            $user_id       = $customer->ID;
                            $first         = get_user_meta( $user_id, 'first_name', true );
                            $last          = get_user_meta( $user_id, 'last_name', true );
                            $full_name     = trim( $first . ' ' . $last ) ?: $customer->display_name;
                            $business_name = get_user_meta( $user_id, 'slw_business_name', true );
                            $tier          = class_exists( 'SLW_Tiers' ) ? SLW_Tiers::get_user_tier( $user_id ) : 'standard';
                            $net_terms     = class_exists( 'SLW_Gateway_Net30' ) ? SLW_Gateway_Net30::get_user_net_terms( $user_id ) : 0;

                            // Order count and last order date.
                            $order_ids  = wc_get_orders( array(
                                'customer' => $user_id,
                                'return'   => 'ids',
                                'limit'    => -1,
                            ) );
                            $order_count = count( $order_ids );

                            $last_order_date = '&mdash;';
                            if ( $order_count > 0 ) {
                                $latest_orders = wc_get_orders( array(
                                    'customer' => $user_id,
                                    'limit'    => 1,
                                    'orderby'  => 'date',
                                    'order'    => 'DESC',
                                ) );
                                if ( ! empty( $latest_orders ) ) {
                                    $last_order_date = $latest_orders[0]->get_date_created()->date_i18n( get_option( 'date_format' ) );
                                }
                            }
                            ?>
                            <tr>
                                <td><strong><?php echo esc_html( $full_name ); ?></strong></td>
                                <td><?php echo esc_html( $business_name ?: '&mdash;' ); ?></td>
                                <td><a href="mailto:<?php echo esc_attr( $customer->user_email ); ?>"><?php echo esc_html( $customer->user_email ); ?></a></td>
                                <td><span class="slw-lead-status slw-lead-status--<?php echo esc_attr( $tier ); ?>"><?php echo esc_html( ucfirst( $tier ) ); ?></span></td>
                                <td><?php echo $net_terms ? 'NET ' . esc_html( $net_terms ) : '&mdash;'; ?></td>
                                <td><?php echo esc_html( $order_count ); ?></td>
                                <td><?php echo $last_order_date; ?></td>
                                <td>
                                    <a href="<?php echo esc_url( get_edit_user_link( $user_id ) ); ?>" class="button button-small">Edit</a>
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
        <?php endif;
    }
}
