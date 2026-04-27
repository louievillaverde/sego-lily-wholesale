<?php
/**
 * Wholesale Order Form
 *
 * Renders a table-style product catalog at /wholesale-order. Every published
 * WooCommerce product appears in a row with thumbnail, name, wholesale price,
 * and a quantity input. Wholesale customers can add items individually or use
 * the "Add All to Cart" button at the bottom.
 *
 * Non-wholesale visitors are redirected to the application form.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class SLW_Order_Form {

    public static function init() {
        add_shortcode( 'sego_wholesale_order_form', array( __CLASS__, 'render' ) );

        // AJAX handler for adding items to cart from the order form
        add_action( 'wp_ajax_slw_add_to_cart', array( __CLASS__, 'ajax_add_to_cart' ) );
    }

    /**
     * Render the order form shortcode. Gate access to wholesale users only.
     */
    public static function render( $atts = array() ) {
        // Admin preview mode: let admins see what wholesale customers see
        $is_admin_preview = isset( $_GET['slw_preview'] ) && current_user_can( 'manage_woocommerce' );

        // Redirect non-wholesale visitors to the application form
        if ( ! $is_admin_preview && ( ! is_user_logged_in() || ! slw_is_wholesale_user() ) ) {
            if ( ! is_admin() ) {
                wp_redirect( home_url( '/wholesale-partners' ) );
                exit;
            }
            return '<div class="slw-notice slw-notice-warning">Please <a href="' . wp_login_url( home_url( '/wholesale-order' ) ) . '">log in</a> with your wholesale account to access the order form.</div>';
        }

        ob_start();

        // Show admin preview banner
        if ( $is_admin_preview && class_exists( 'SLW_Dashboard' ) ) {
            SLW_Dashboard::render_preview_banner( 'Order Form' );
        }

        include SLW_PLUGIN_DIR . 'templates/order-form.php';
        return ob_get_clean();
    }

    /**
     * AJAX handler: add one or more products to the cart. Accepts an array
     * of {product_id, quantity} pairs from the order form.
     */
    public static function ajax_add_to_cart() {
        check_ajax_referer( 'slw_order_form', 'nonce' );

        if ( ! slw_is_wholesale_user() ) {
            wp_send_json_error( array( 'message' => 'Wholesale access required.' ) );
        }

        $items = json_decode( stripslashes( $_POST['items'] ?? '[]' ), true );
        if ( empty( $items ) || ! is_array( $items ) ) {
            wp_send_json_error( array( 'message' => 'No items selected.' ) );
        }

        $added = 0;
        foreach ( $items as $item ) {
            $product_id   = absint( $item['product_id'] ?? 0 );
            $quantity     = absint( $item['quantity'] ?? 0 );
            $variation_id = absint( $item['variation_id'] ?? 0 );
            $variation    = isset( $item['variation'] ) && is_array( $item['variation'] ) ? array_map( 'sanitize_text_field', $item['variation'] ) : array();

            if ( $product_id > 0 && $quantity > 0 ) {
                $result = WC()->cart->add_to_cart( $product_id, $quantity, $variation_id, $variation );
                if ( $result ) {
                    $added++;
                }
            }
        }

        if ( $added > 0 ) {
            wp_send_json_success( array(
                'message'  => $added . ' product(s) added to your cart.',
                'cart_url' => wc_get_cart_url(),
            ));
        } else {
            wp_send_json_error( array( 'message' => 'Could not add items to cart. Please check quantities and try again.' ) );
        }
    }
}
