<?php
/**
 * Wholesale Checkout
 *
 * The [sego_wholesale_checkout] shortcode is now a thin wrapper around
 * WC's native [woocommerce_checkout]. We do four things here:
 *   1. Gate access (logged-in, wholesale role, cart not empty)
 *   2. Force the wholesale shopping context so prices stay wholesale
 *   3. Render the wholesale portal nav at the top so customers can
 *      navigate back to other portal sections
 *   4. Add a small "Back to the order form" link
 *
 * The actual form, summary, payment gateways, and shipping methods are
 * fully handled by WC core. That keeps payment + shipping working end-
 * to-end. We don't inject any custom CSS into the WC markup -- the
 * theme's stylesheet handles it.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class SLW_Wholesale_Checkout {

    public static function init() {
        add_shortcode( 'sego_wholesale_checkout', array( __CLASS__, 'render' ) );
    }

    public static function render() {
        // Force wholesale shopping context for the checkout. Only write
        // when not already set so we don't trigger a session save on
        // every request.
        if ( is_user_logged_in() && function_exists( 'slw_is_wholesale_user' )
            && slw_is_wholesale_user() && function_exists( 'WC' ) && WC()->session
            && WC()->session->get( 'slw_shopping_context' ) !== 'wholesale' ) {
            WC()->session->set( 'slw_shopping_context', 'wholesale' );
        }

        if ( ! is_user_logged_in() ) {
            return '<div class="slw-checkout-gate"><h2>Sign in to your wholesale account</h2><p><a href="' . esc_url( wp_login_url( get_permalink() ) ) . '">Log in</a> to access wholesale checkout.</p></div>';
        }
        if ( ! function_exists( 'slw_is_wholesale_user' ) || ! slw_is_wholesale_user() ) {
            return '<div class="slw-checkout-gate"><h2>Wholesale customers only</h2><p>This checkout is for approved wholesale partners. <a href="' . esc_url( home_url( '/wholesale-partners' ) ) . '">Apply for an account here</a>.</p></div>';
        }
        if ( ! function_exists( 'WC' ) || ! WC()->cart || WC()->cart->is_empty() ) {
            return '<div class="slw-checkout-gate slw-checkout-empty"><h2>Your cart is empty</h2><p>Pick up where you left off on the order form.</p><a class="slw-btn slw-btn-primary" href="' . esc_url( home_url( '/wholesale-order' ) ) . '">Back to the order form</a></div>';
        }

        ob_start();
        if ( class_exists( 'SLW_Customer_Portal' ) ) {
            SLW_Customer_Portal::render_nav( 'orders' );
        }
        ?>
        <div class="slw-checkout-page">
            <p class="slw-checkout-back-link">
                <a href="<?php echo esc_url( home_url( '/wholesale-order' ) ); ?>">&larr; Back to the order form</a>
            </p>
            <?php echo do_shortcode( '[woocommerce_checkout]' ); ?>
        </div>
        <?php
        return ob_get_clean();
    }
}
