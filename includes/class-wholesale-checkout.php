<?php
/**
 * Wholesale Checkout
 *
 * The [sego_wholesale_checkout] shortcode now delegates the actual
 * checkout form to WC's native [woocommerce_checkout] shortcode. We
 * just gate access, wrap with the wholesale portal nav + brand
 * container, and let WC handle everything: payment gateways, shipping
 * zones, order creation, gateway tokenization. Cart prices flow through
 * apply_wholesale_price so the order summary matches the order form.
 *
 * Earlier iterations tried a fully-custom 2-column checkout to fix
 * Elementor-induced price drift, but the cost was a broken native
 * payment + shipping pipeline. Per LV directive 2026-05-29: lean on
 * WC core.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class SLW_Wholesale_Checkout {

    public static function init() {
        add_shortcode( 'sego_wholesale_checkout', array( __CLASS__, 'render' ) );
    }

    public static function render() {
        // Force wholesale shopping context for the checkout. Same
        // reasoning as the order form -- if a wholesale customer has
        // a stale "retail" preference in their session, force-set to
        // wholesale so apply_wholesale_price runs and the cart line
        // totals match what the order form showed.
        if ( is_user_logged_in() && function_exists( 'slw_is_wholesale_user' )
            && slw_is_wholesale_user() && function_exists( 'WC' ) && WC()->session ) {
            WC()->session->set( 'slw_shopping_context', 'wholesale' );
        }
        if ( ! is_user_logged_in() ) {
            return '<div class="slw-checkout-gate"><h2 class="slw-balance">Sign in to your wholesale account</h2><p class="slw-pretty"><a href="' . esc_url( wp_login_url( get_permalink() ) ) . '">Log in</a> to access wholesale checkout.</p></div>';
        }
        if ( ! function_exists( 'slw_is_wholesale_user' ) || ! slw_is_wholesale_user() ) {
            return '<div class="slw-checkout-gate"><h2 class="slw-balance">Wholesale customers only</h2><p class="slw-pretty">This checkout is for approved wholesale partners. <a href="' . esc_url( home_url( '/wholesale-partners' ) ) . '">Apply for an account here</a>.</p></div>';
        }
        if ( ! function_exists( 'WC' ) || ! WC()->cart || WC()->cart->is_empty() ) {
            return '<div class="slw-checkout-gate slw-checkout-empty"><h2 class="slw-balance">Your cart is empty</h2><p class="slw-pretty">Nothing to check out yet. Pick up where you left off on the order form.</p><a class="slw-btn slw-btn-primary" href="' . esc_url( home_url( '/wholesale-order' ) ) . '">Back to the order form</a></div>';
        }

        // Re-prime cart prices in case anything in the cart bypassed our
        // wholesale filter on add-to-cart. Recalculate ensures the
        // summary shown on this page matches what the order form
        // shows (apply_wholesale_price runs as the cart recalculates).
        WC()->cart->calculate_totals();

        ob_start();
        ?>
        <style>
            /* Suppress the WP theme's page title; we render our own
               in-shortcode header below. */
            body.page .entry-title:not(.slw-balance),
            body.page .wp-block-post-title:not(.slw-balance),
            body.page .elementor-page-title__title,
            body.page header.entry-header > h1 { display: none !important; }
        </style>
        <div class="slw-wholesale-checkout slw-wholesale-checkout--native">
            <?php
            if ( class_exists( 'SLW_Customer_Portal' ) ) {
                SLW_Customer_Portal::render_nav( 'orders' );
            }
            ?>
            <div class="slw-wc-header">
                <h1 class="slw-balance">Wholesale Checkout</h1>
                <p class="slw-pretty">One last step to ship your order. Prices below reflect your wholesale rate.</p>
                <a class="slw-wc-back" href="<?php echo esc_url( home_url( '/wholesale-order' ) ); ?>">&larr; Back to the order form</a>
            </div>

            <div class="slw-wc-native-wrap">
                <?php
                // Delegate the actual checkout form to WC core. Every
                // gateway (Stripe, NET 30, Square, etc.) attaches to
                // the standard #payment / .woocommerce-checkout DOM.
                // Shipping zones evaluate via the customer's address.
                // The order summary reads cart line prices, which our
                // apply_wholesale_price filter has already set to the
                // wholesale rate.
                echo do_shortcode( '[woocommerce_checkout]' );
                ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
}
