<?php
/**
 * Admin Dashboard
 *
 * The landing page for the Wholesale admin area. Shows quick stats,
 * recent activity, quick actions, and a getting-started checklist.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class SLW_Admin_Dashboard {

    public static function init() {
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_assets' ) );
    }

    /**
     * Enqueue admin CSS only on our plugin pages.
     */
    public static function enqueue_admin_assets( $hook ) {
        $screen = get_current_screen();
        if ( $screen && strpos( $screen->id, 'slw-' ) !== false ) {
            wp_enqueue_style(
                'slw-admin',
                SLW_PLUGIN_URL . 'assets/sego-lily-wholesale.css',
                array(),
                SLW_VERSION
            );
        }
    }

    /**
     * Render the admin dashboard page.
     */
    public static function render_page() {
        $stats = self::get_stats();
        $checklist = self::get_checklist();
        $activity = self::get_recent_activity();
        $completed = count( array_filter( $checklist, function( $item ) { return $item['done']; } ) );
        $total = count( $checklist );
        ?>
        <div class="wrap slw-admin-dashboard">
            <h1 class="slw-admin-dashboard__title">Wholesale Portal</h1>
            <p class="slw-admin-dashboard__subtitle">Overview of your wholesale operation</p>

            <div class="slw-admin-dashboard__grid">
                <!-- Main Column -->
                <div class="slw-admin-dashboard__main">

                    <!-- Quick Stats -->
                    <div class="slw-admin-stats">
                        <div class="slw-admin-stats__card slw-admin-stats__card--teal">
                            <a href="<?php echo esc_url( admin_url( 'admin.php?page=slw-applications' ) ); ?>" class="slw-admin-stats__link">
                                <span class="slw-admin-stats__icon dashicons dashicons-clipboard"></span>
                                <span class="slw-admin-stats__number"><?php echo esc_html( $stats['pending_applications'] ); ?></span>
                                <span class="slw-admin-stats__label">Pending Applications</span>
                            </a>
                        </div>
                        <div class="slw-admin-stats__card slw-admin-stats__card--green">
                            <a href="<?php echo esc_url( admin_url( 'admin.php?page=slw-orders' ) ); ?>" class="slw-admin-stats__link">
                                <span class="slw-admin-stats__icon dashicons dashicons-groups"></span>
                                <span class="slw-admin-stats__number"><?php echo esc_html( $stats['active_customers'] ); ?></span>
                                <span class="slw-admin-stats__label">Active Customers</span>
                            </a>
                        </div>
                        <div class="slw-admin-stats__card slw-admin-stats__card--gold">
                            <a href="<?php echo esc_url( admin_url( 'edit.php?post_type=shop_order' ) ); ?>" class="slw-admin-stats__link">
                                <span class="slw-admin-stats__icon dashicons dashicons-cart"></span>
                                <span class="slw-admin-stats__number"><?php echo esc_html( $stats['orders_this_month'] ); ?></span>
                                <span class="slw-admin-stats__label">Orders This Month (<?php echo wp_kses_post( wc_price( $stats['revenue_this_month'] ) ); ?>)</span>
                            </a>
                        </div>
                        <div class="slw-admin-stats__card slw-admin-stats__card--slate">
                            <?php $rfq_url = class_exists( 'SLW_RFQ' ) ? admin_url( 'admin.php?page=slw-rfq' ) : '#'; ?>
                            <a href="<?php echo esc_url( $rfq_url ); ?>" class="slw-admin-stats__link">
                                <span class="slw-admin-stats__icon dashicons dashicons-format-chat"></span>
                                <span class="slw-admin-stats__number"><?php echo esc_html( $stats['open_quotes'] ); ?></span>
                                <span class="slw-admin-stats__label">Open Quote Requests</span>
                            </a>
                        </div>
                    </div>

                    <!-- Recent Activity -->
                    <div class="slw-admin-card">
                        <h2 class="slw-admin-card__heading">Recent Activity</h2>
                        <?php if ( ! empty( $activity ) ) : ?>
                            <ul class="slw-admin-activity">
                                <?php foreach ( $activity as $item ) : ?>
                                    <li class="slw-admin-activity__item">
                                        <span class="dashicons <?php echo esc_attr( $item['icon'] ); ?> slw-admin-activity__icon"></span>
                                        <span class="slw-admin-activity__text"><?php echo esc_html( $item['text'] ); ?></span>
                                        <span class="slw-admin-activity__time"><?php echo esc_html( $item['time'] ); ?></span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php else : ?>
                            <p class="slw-admin-card__empty">No recent activity yet.</p>
                        <?php endif; ?>
                    </div>

                    <!-- Quick Actions -->
                    <div class="slw-admin-card">
                        <h2 class="slw-admin-card__heading">Quick Actions</h2>
                        <div class="slw-admin-actions">
                            <a href="<?php echo esc_url( admin_url( 'user-new.php' ) ); ?>" class="button slw-admin-actions__btn">
                                <span class="dashicons dashicons-plus-alt2"></span> New Wholesale User
                            </a>
                            <?php if ( class_exists( 'SLW_Premium_Features' ) ) : ?>
                                <a href="<?php echo esc_url( admin_url( 'admin.php?page=slw-import' ) ); ?>" class="button slw-admin-actions__btn">
                                    <span class="dashicons dashicons-upload"></span> Import Users
                                </a>
                            <?php endif; ?>
                            <a href="<?php echo esc_url( home_url( '/wholesale-order?slw_preview=1' ) ); ?>" class="button slw-admin-actions__btn" target="_blank">
                                <span class="dashicons dashicons-store"></span> View Order Form
                            </a>
                            <?php
                            $linesheet_url = '';
                            if ( class_exists( 'SLW_PDF_Linesheet' ) ) {
                                $linesheet_url = home_url( '/?slw_linesheet=1' );
                            }
                            if ( $linesheet_url ) : ?>
                                <a href="<?php echo esc_url( $linesheet_url ); ?>" class="button slw-admin-actions__btn" target="_blank">
                                    <span class="dashicons dashicons-media-document"></span> Download Line Sheet
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>

                </div>

                <!-- Sidebar -->
                <div class="slw-admin-dashboard__sidebar">

                    <!-- Getting Started Checklist / Celebration / Notifications -->
                    <div class="slw-admin-card">
                        <?php if ( $completed >= $total ) :
                            // Record when setup was first completed
                            $completed_at = get_option( 'slw_setup_completed_at' );
                            if ( ! $completed_at ) {
                                update_option( 'slw_setup_completed_at', current_time( 'mysql' ), false );
                                $completed_at = current_time( 'mysql' );
                            }
                            $days_since = ( time() - strtotime( $completed_at ) ) / DAY_IN_SECONDS;

                            if ( $days_since < 7 ) :
                                // Celebration card (first 7 days)
                        ?>
                            <div class="slw-celebration">
                                <div class="slw-celebration__confetti" aria-hidden="true">
                                    <span class="slw-confetti-dot slw-confetti-dot--1"></span>
                                    <span class="slw-confetti-dot slw-confetti-dot--2"></span>
                                    <span class="slw-confetti-dot slw-confetti-dot--3"></span>
                                    <span class="slw-confetti-dot slw-confetti-dot--4"></span>
                                    <span class="slw-confetti-dot slw-confetti-dot--5"></span>
                                    <span class="slw-confetti-dot slw-confetti-dot--6"></span>
                                    <span class="slw-confetti-dot slw-confetti-dot--7"></span>
                                    <span class="slw-confetti-dot slw-confetti-dot--8"></span>
                                </div>
                                <div class="slw-celebration__icon">&#127881;</div>
                                <h3 class="slw-celebration__title">Your wholesale portal is live!</h3>
                                <div class="slw-celebration__stats">
                                    <span class="slw-celebration__stat">
                                        <strong><?php echo esc_html( $stats['active_customers'] ); ?></strong> wholesale customers
                                    </span>
                                    <span class="slw-celebration__stat">
                                        <strong><?php echo esc_html( $stats['orders_this_month'] ); ?></strong> orders this month
                                    </span>
                                </div>
                            </div>
                            <?php else :
                                // Notifications card (after 7 days)
                                $pending_apps   = $stats['pending_applications'];
                                $pending_quotes = $stats['open_quotes'];
                                $webhook_log    = get_option( 'slw_webhook_log', array() );
                                $wh_failures_24h = 0;
                                $cutoff = strtotime( '-24 hours' );
                                foreach ( $webhook_log as $wh_entry ) {
                                    if ( isset( $wh_entry['time'] ) && strtotime( $wh_entry['time'] ) >= $cutoff && ( $wh_entry['status'] ?? '' ) !== 'success' ) {
                                        $wh_failures_24h++;
                                    }
                                }
                            ?>
                            <h2 class="slw-admin-card__heading">Notifications</h2>
                            <ul class="slw-notifications-list">
                                <li class="slw-notification-item <?php echo $pending_apps > 0 ? 'slw-notification-item--alert' : ''; ?>">
                                    <span class="dashicons dashicons-clipboard"></span>
                                    <span class="slw-notification-text">
                                        <strong><?php echo esc_html( $pending_apps ); ?></strong> pending application<?php echo $pending_apps !== 1 ? 's' : ''; ?>
                                    </span>
                                    <?php if ( $pending_apps > 0 ) : ?>
                                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=slw-applications&status=pending' ) ); ?>" class="slw-notification-link">Review</a>
                                    <?php endif; ?>
                                </li>
                                <li class="slw-notification-item <?php echo $pending_quotes > 0 ? 'slw-notification-item--alert' : ''; ?>">
                                    <span class="dashicons dashicons-format-chat"></span>
                                    <span class="slw-notification-text">
                                        <strong><?php echo esc_html( $pending_quotes ); ?></strong> pending quote<?php echo $pending_quotes !== 1 ? 's' : ''; ?>
                                    </span>
                                    <?php if ( $pending_quotes > 0 && class_exists( 'SLW_RFQ' ) ) : ?>
                                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=slw-rfq' ) ); ?>" class="slw-notification-link">Review</a>
                                    <?php endif; ?>
                                </li>
                                <li class="slw-notification-item <?php echo $wh_failures_24h > 0 ? 'slw-notification-item--alert' : ''; ?>">
                                    <span class="dashicons dashicons-admin-links"></span>
                                    <span class="slw-notification-text">
                                        <?php if ( $wh_failures_24h > 0 ) : ?>
                                            <strong><?php echo esc_html( $wh_failures_24h ); ?></strong> webhook failure<?php echo $wh_failures_24h !== 1 ? 's' : ''; ?> in last 24h
                                        <?php else : ?>
                                            Webhooks healthy
                                        <?php endif; ?>
                                    </span>
                                </li>
                            </ul>
                            <?php endif; ?>
                        <?php else : ?>
                            <h2 class="slw-admin-card__heading">Getting Started</h2>
                            <p class="slw-admin-card__progress"><?php echo esc_html( $completed ); ?> of <?php echo esc_html( $total ); ?> complete</p>
                            <div class="slw-admin-checklist-bar">
                                <div class="slw-admin-checklist-bar__fill" style="width: <?php echo esc_attr( $total > 0 ? round( ( $completed / $total ) * 100 ) : 0 ); ?>%"></div>
                            </div>
                            <ul class="slw-admin-checklist">
                                <?php foreach ( $checklist as $item ) : ?>
                                    <li class="slw-admin-checklist__item <?php echo $item['done'] ? 'slw-admin-checklist__item--done' : ''; ?>">
                                        <span class="slw-admin-checklist__check"><?php echo $item['done'] ? '&#10003;' : '&#9675;'; ?></span>
                                        <span class="slw-admin-checklist__text"><?php echo esc_html( $item['label'] ); ?></span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>

                    <!-- Helpful Resources -->
                    <div class="slw-admin-card">
                        <h2 class="slw-admin-card__heading">Resources</h2>
                        <ul class="slw-admin-resources">
                            <li><a href="<?php echo esc_url( admin_url( 'admin.php?page=slw-docs' ) ); ?>"><span class="dashicons dashicons-book"></span> Help &amp; Docs</a></li>
                            <li><a href="mailto:support@leadpiranha.com"><span class="dashicons dashicons-sos"></span> Contact Support</a></li>
                            <li><a href="<?php echo esc_url( home_url( '/wholesale-dashboard?slw_preview=1' ) ); ?>" target="_blank"><span class="dashicons dashicons-dashboard"></span> Customer Dashboard</a></li>
                            <li><a href="<?php echo esc_url( home_url( '/wholesale-order?slw_preview=1' ) ); ?>" target="_blank"><span class="dashicons dashicons-store"></span> Order Form</a></li>
                        </ul>
                        <div class="slw-whats-new">
                            <h4 class="slw-whats-new__title">What's New in v<?php echo esc_html( SLW_VERSION ); ?></h4>
                            <ul class="slw-whats-new__list">
                                <li>Redesigned admin dashboard with improved stats cards</li>
                                <li>Single-customer import form for quick account creation</li>
                                <li>Improved settings page section navigation</li>
                            </ul>
                        </div>
                        <p class="slw-admin-card__version">Wholesale Portal v<?php echo esc_html( SLW_VERSION ); ?></p>
                    </div>

                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Gather lightweight stats for the dashboard.
     */
    private static function get_stats() {
        global $wpdb;

        // Pending applications
        $app_table = $wpdb->prefix . 'slw_applications';
        $pending = 0;
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $app_table ) ) === $app_table ) {
            $pending = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$app_table} WHERE status = 'pending'" );
        }

        // Active wholesale customers
        $user_count = count_users();
        $active_customers = isset( $user_count['avail_roles']['wholesale_customer'] ) ? $user_count['avail_roles']['wholesale_customer'] : 0;

        // Wholesale orders this month (HPOS-compatible)
        $orders_this_month = 0;
        $revenue_this_month = 0;
        if ( function_exists( 'wc_get_orders' ) ) {
            $first_of_month = gmdate( 'Y-m-01' );
            $orders = wc_get_orders( array(
                'limit'        => -1,
                'status'       => array( 'wc-processing', 'wc-completed', 'wc-on-hold' ),
                'date_created' => '>=' . $first_of_month,
                'meta_key'     => '_slw_wholesale_order',
                'meta_value'   => '1',
                'return'       => 'ids',
            ) );
            $orders_this_month = count( $orders );

            // Get revenue total
            foreach ( $orders as $order_id ) {
                $order = wc_get_order( $order_id );
                if ( $order ) {
                    $revenue_this_month += (float) $order->get_total();
                }
            }
        }

        // Open quote requests
        $open_quotes = 0;
        if ( class_exists( 'SLW_RFQ' ) && function_exists( 'wc_get_orders' ) ) {
            $rfq_table = $wpdb->prefix . 'slw_rfq';
            if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $rfq_table ) ) === $rfq_table ) {
                $open_quotes = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$rfq_table} WHERE status = 'pending'" );
            }
        }

        return array(
            'pending_applications' => $pending,
            'active_customers'     => $active_customers,
            'orders_this_month'    => $orders_this_month,
            'revenue_this_month'   => $revenue_this_month,
            'open_quotes'          => $open_quotes,
        );
    }

    /**
     * Build the getting-started checklist with completion state.
     */
    private static function get_checklist() {
        $product_count = (int) wp_count_posts( 'product' )->publish;
        return array(
            array( 'label' => 'Configure wholesale discount',      'done' => get_option( 'slw_discount_percent' ) !== false ),
            array( 'label' => 'Set first order minimum',           'done' => get_option( 'slw_first_order_minimum' ) !== false ),
            array( 'label' => 'Add products to your store',        'done' => $product_count > 0 ),
            array( 'label' => 'Customize your application form',   'done' => (bool) get_page_by_path( 'wholesale-partners' ) ),
            array( 'label' => 'Configure invoice branding',        'done' => (bool) get_option( 'slw_invoice_logo_id' ) ),
            array( 'label' => 'Test the wholesale flow',           'done' => false ), // manual step
        );
    }

    /**
     * Get the last 5 activity items from applications and orders.
     */
    private static function get_recent_activity() {
        global $wpdb;
        $items = array();

        // Recent applications
        $app_table = $wpdb->prefix . 'slw_applications';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $app_table ) ) === $app_table ) {
            $apps = $wpdb->get_results(
                "SELECT business_name, status, submitted_at, reviewed_at FROM {$app_table} ORDER BY submitted_at DESC LIMIT 5"
            );
            foreach ( $apps as $app ) {
                if ( $app->status === 'pending' ) {
                    $items[] = array(
                        'icon' => 'dashicons-clipboard',
                        'text' => sprintf( 'New application from %s', $app->business_name ),
                        'time' => human_time_diff( strtotime( $app->submitted_at ) ) . ' ago',
                        'date' => $app->submitted_at,
                    );
                } elseif ( $app->status === 'approved' && $app->reviewed_at ) {
                    $items[] = array(
                        'icon' => 'dashicons-yes-alt',
                        'text' => sprintf( '%s application approved', $app->business_name ),
                        'time' => human_time_diff( strtotime( $app->reviewed_at ) ) . ' ago',
                        'date' => $app->reviewed_at,
                    );
                }
            }
        }

        // Recent wholesale orders (HPOS-compatible)
        if ( function_exists( 'wc_get_orders' ) ) {
            $recent_orders = wc_get_orders( array(
                'limit'    => 5,
                'orderby'  => 'date',
                'order'    => 'DESC',
                'meta_key' => '_slw_wholesale_order',
                'meta_value' => '1',
            ) );
            foreach ( $recent_orders as $order ) {
                $items[] = array(
                    'icon' => 'dashicons-cart',
                    'text' => sprintf( 'Wholesale order #%s (%s)', $order->get_id(), wc_price( $order->get_total() ) ),
                    'time' => human_time_diff( $order->get_date_created()->getTimestamp() ) . ' ago',
                    'date' => $order->get_date_created()->format( 'Y-m-d H:i:s' ),
                );
            }
        }

        // Sort by date descending and take 5
        usort( $items, function( $a, $b ) {
            return strtotime( $b['date'] ) - strtotime( $a['date'] );
        } );

        return array_slice( $items, 0, 5 );
    }
}
