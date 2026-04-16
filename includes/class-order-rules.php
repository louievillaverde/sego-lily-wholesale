<?php
/**
 * Order Rules: Minimum Enforcement + NET 30 Payment
 *
 * Enforces the $300 first-order minimum at checkout. Tracks whether a wholesale
 * customer has placed their first order via user meta. Also provides NET 30
 * payment terms as a WooCommerce payment gateway (admin-toggled per user).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class SLW_Order_Rules {

    public static function init() {
        // Enforce minimum order amount at checkout
        add_action( 'woocommerce_check_cart_items', array( __CLASS__, 'enforce_minimum' ) );

        // After a wholesale order completes, mark the user as having placed their first order
        add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'mark_first_order' ) );
        add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'mark_first_order' ) );

        // Register the NET 30 payment gateway
        add_filter( 'woocommerce_payment_gateways', array( __CLASS__, 'register_net30_gateway' ) );

        // NOTE: the NET 30 toggle on user profiles is rendered by
        // SLW_Wholesale_Role::render_user_profile_section (consolidated
        // with the other wholesale fields). We intentionally do NOT hook
        // our own render_net30_field / save_net30_field here to avoid
        // duplicate form fields on the user profile page.

        // Show a notice on cart page about the minimum
        add_action( 'woocommerce_before_cart', array( __CLASS__, 'cart_minimum_notice' ) );
    }

    /**
     * Check the cart total against the required minimum. First-time wholesale
     * customers must meet the first_order_minimum ($300 default). Returning
     * customers must meet the reorder_minimum ($0 default).
     */
    public static function enforce_minimum() {
        if ( ! slw_is_wholesale_user() ) {
            return;
        }

        $user_id = get_current_user_id();
        $has_ordered = get_user_meta( $user_id, 'slw_first_order_placed', true );

        if ( $has_ordered ) {
            $minimum = (float) slw_get_option( 'reorder_minimum', 0 );
            $label = 'reorder';
        } else {
            $minimum = (float) slw_get_option( 'first_order_minimum', 300 );
            $label = 'first wholesale order';
        }

        if ( $minimum <= 0 ) {
            return;
        }

        $cart_total = (float) WC()->cart->get_subtotal();

        if ( $cart_total < $minimum ) {
            wc_add_notice(
                sprintf(
                    'Your %s requires a $%s minimum. Your current cart total is $%s. Add a few more products to meet the minimum.',
                    $label,
                    number_format( $minimum, 0 ),
                    number_format( $cart_total, 2 )
                ),
                'error'
            );
        }
    }

    /**
     * Show a friendly notice on the cart page so wholesale customers know
     * about the minimum before they try to check out.
     */
    public static function cart_minimum_notice() {
        if ( ! slw_is_wholesale_user() ) {
            return;
        }

        $user_id = get_current_user_id();
        $has_ordered = get_user_meta( $user_id, 'slw_first_order_placed', true );

        if ( ! $has_ordered ) {
            $minimum = (float) slw_get_option( 'first_order_minimum', 300 );
            $cart_total = (float) WC()->cart->get_subtotal();
            if ( $cart_total < $minimum ) {
                echo '<div class="slw-notice slw-notice-info">Your first wholesale order has a $' . number_format( $minimum, 0 ) . ' minimum. You\'re at $' . number_format( $cart_total, 2 ) . ' so far.</div>';
            }
        }
    }

    /**
     * When a wholesale order moves to processing or completed, flag the user
     * so they're no longer subject to the first-order minimum. Also fires the
     * first-order-placed webhook to AIOS for Mautic tagging.
     */
    public static function mark_first_order( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        $user_id = $order->get_user_id();
        if ( ! $user_id || ! slw_is_wholesale_user( $user_id ) ) {
            return;
        }

        // Only fire once (check if flag already set)
        if ( get_user_meta( $user_id, 'slw_first_order_placed', true ) ) {
            return;
        }

        update_user_meta( $user_id, 'slw_first_order_placed', current_time( 'mysql' ) );

        // Fire webhook to AIOS so Mautic can apply the first-order-placed tag
        $user = get_userdata( $user_id );
        SLW_Webhooks::fire( 'first-order-placed', array(
            'email'         => $user->user_email,
            'first_name'    => $user->first_name,
            'business_name' => get_user_meta( $user_id, 'slw_business_name', true ),
            'order_id'      => $order_id,
            'order_total'   => $order->get_total(),
        ));
    }

    /**
     * Register the NET 30 payment gateway with WooCommerce.
     */
    public static function register_net30_gateway( $gateways ) {
        if ( get_option( 'slw_net30_enabled', false ) ) {
            require_once SLW_PLUGIN_DIR . 'includes/class-gateway-net30.php';
            $gateways[] = 'SLW_Gateway_Net30';
        }
        return $gateways;
    }

    /**
     * Show the NET 30 toggle on wholesale user profiles in WP Admin.
     */
    public static function render_net30_field( $user ) {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }
        if ( ! slw_is_wholesale_user( $user->ID ) ) {
            return;
        }
        if ( ! get_option( 'slw_net30_enabled', false ) ) {
            return;
        }

        $net30 = get_user_meta( $user->ID, 'slw_net30_approved', true );
        ?>
        <h3>Sego Lily Wholesale</h3>
        <table class="form-table">
            <tr>
                <th><label for="slw_net30_approved">NET 30 Payment Terms</label></th>
                <td>
                    <label>
                        <input type="checkbox" name="slw_net30_approved" id="slw_net30_approved" value="1" <?php checked( $net30, '1' ); ?> />
                        Allow this customer to use NET 30 payment terms at checkout
                    </label>
                </td>
            </tr>
        </table>
        <?php
    }

    /**
     * Save the NET 30 toggle when an admin updates a user profile.
     */
    public static function save_net30_field( $user_id ) {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }
        $value = isset( $_POST['slw_net30_approved'] ) ? '1' : '0';
        update_user_meta( $user_id, 'slw_net30_approved', $value );
    }
}
