<?php
/**
 * Template: Wholesale Customer Dashboard
 *
 * Rendered by the [sego_wholesale_dashboard] shortcode.
 * Shows a branded hub for wholesale customers with orders, quick links,
 * and account info.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

$user = wp_get_current_user();
$first_name = $user->first_name ?: $user->display_name;
$business_name = get_user_meta( $user->ID, 'slw_business_name', true );
$has_ordered = get_user_meta( $user->ID, 'slw_first_order_placed', true );
$net30_approved = get_user_meta( $user->ID, 'slw_net30_approved', true ) === '1';

// Get recent orders for this user
$orders = wc_get_orders( array(
    'customer' => $user->ID,
    'limit'    => 10,
    'orderby'  => 'date',
    'order'    => 'DESC',
));
?>

<div class="slw-dashboard-wrap">
    <div class="slw-dashboard-header">
        <h2>Welcome back, <?php echo esc_html( $first_name ); ?></h2>
        <?php if ( $business_name ) : ?>
            <p class="slw-business-name"><?php echo esc_html( $business_name ); ?></p>
        <?php endif; ?>
    </div>

    <div class="slw-dashboard-grid">
        <!-- Quick Actions -->
        <div class="slw-dashboard-card">
            <h3>Quick Actions</h3>
            <ul class="slw-quick-links">
                <li><a href="<?php echo home_url( '/wholesale-order' ); ?>" class="slw-btn slw-btn-primary">Place a New Order</a></li>
                <li><a href="<?php echo wc_get_cart_url(); ?>" class="slw-btn slw-btn-secondary">View Cart</a></li>
                <li><a href="<?php echo wc_get_account_endpoint_url( 'edit-account' ); ?>">Edit Account Details</a></li>
                <li><a href="<?php echo wc_get_account_endpoint_url( 'edit-address' ); ?>">Update Shipping Address</a></li>
            </ul>
        </div>

        <!-- Account Status -->
        <div class="slw-dashboard-card">
            <h3>Account Status</h3>
            <table class="slw-status-table">
                <tr>
                    <td>Account Type</td>
                    <td><strong>Wholesale Partner</strong></td>
                </tr>
                <tr>
                    <td>Discount</td>
                    <td><strong><?php echo esc_html( slw_get_option( 'discount_percent', 50 ) ); ?>% off retail</strong></td>
                </tr>
                <tr>
                    <td>First Order</td>
                    <td>
                        <?php if ( $has_ordered ) : ?>
                            <span style="color: #007017;">Completed</span>
                        <?php else : ?>
                            <span style="color: #996800;">$<?php echo number_format( (float) slw_get_option( 'first_order_minimum', 300 ), 0 ); ?> minimum required</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php if ( $net30_approved ) : ?>
                <tr>
                    <td>Payment Terms</td>
                    <td><strong>NET 30 Approved</strong></td>
                </tr>
                <?php endif; ?>
            </table>
        </div>

        <!-- Recent Orders -->
        <div class="slw-dashboard-card slw-dashboard-card-wide">
            <h3>Recent Orders</h3>
            <?php if ( empty( $orders ) ) : ?>
                <p>No orders yet. <a href="<?php echo home_url( '/wholesale-order' ); ?>">Place your first order</a> to get started.</p>
            <?php else : ?>
            <table class="slw-orders-table">
                <thead>
                    <tr>
                        <th>Order</th>
                        <th>Date</th>
                        <th>Status</th>
                        <th>Total</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $orders as $order ) : ?>
                    <tr>
                        <td>#<?php echo esc_html( $order->get_order_number() ); ?></td>
                        <td><?php echo esc_html( $order->get_date_created()->date( 'M j, Y' ) ); ?></td>
                        <td><?php echo esc_html( wc_get_order_status_name( $order->get_status() ) ); ?></td>
                        <td><?php echo $order->get_formatted_order_total(); ?></td>
                        <td><a href="<?php echo esc_url( $order->get_view_order_url() ); ?>">View</a></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>

        <!-- Resources -->
        <div class="slw-dashboard-card">
            <h3>Wholesale Resources</h3>
            <ul class="slw-resource-links">
                <li><a href="mailto:wholesale@segolilyskincare.com">Contact Holly (wholesale@segolilyskincare.com)</a></li>
                <li><a href="<?php echo home_url( '/wholesale-order' ); ?>">Product Catalog / Order Form</a></li>
            </ul>
            <p class="slw-help-text">Need brand assets, shelf talkers, or marketing materials? Email Holly and she'll send them over.</p>
        </div>
    </div>
</div>
