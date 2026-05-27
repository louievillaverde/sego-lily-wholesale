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

        // Snapshot any pre-existing notices so we can read out the new
        // ones emitted by add_to_cart() failures.
        if ( function_exists( 'wc_clear_notices' ) ) {
            wc_clear_notices();
        }

        $added = 0;
        $failures = array();

        foreach ( $items as $item ) {
            $product_id   = absint( $item['product_id'] ?? 0 );
            $quantity     = absint( $item['quantity'] ?? 0 );
            $variation_id = absint( $item['variation_id'] ?? 0 );
            $variation    = isset( $item['variation'] ) && is_array( $item['variation'] ) ? array_map( 'sanitize_text_field', $item['variation'] ) : array();

            if ( $product_id <= 0 || $quantity <= 0 ) {
                continue;
            }

            $product = wc_get_product( $product_id );
            if ( ! $product ) {
                $failures[] = sprintf( '#%d (product not found)', $product_id );
                continue;
            }

            $type = $product->get_type();
            $result = false;

            if ( $type === 'variable' ) {
                // Variable products: the order form renders one row per
                // variation, so $variation_id MUST be set for the add to
                // succeed. If it's missing, fall back to the variation
                // whose attributes match $variation, or fail with a clear
                // message instead of silently dropping the click.
                if ( $variation_id <= 0 ) {
                    if ( ! empty( $variation ) ) {
                        $data_store = WC_Data_Store::load( 'product' );
                        $variation_id = (int) $data_store->find_matching_product_variation( $product, $variation );
                    }
                    if ( $variation_id <= 0 ) {
                        $failures[] = sprintf(
                            '%s (no variation selected, pick a scent/size first)',
                            $product->get_name()
                        );
                        error_log( sprintf(
                            '[SLW order-form] variable product %d (%s) sent without variation_id and no matching variation found',
                            $product_id,
                            $product->get_name()
                        ) );
                        continue;
                    }
                }
                $result = WC()->cart->add_to_cart( $product_id, $quantity, $variation_id, $variation );
            } elseif ( $type === 'grouped' ) {
                // Grouped products must be added child-by-child. Apply
                // quantity to each child so the gift-set/bundle adds
                // every component.
                $children = $product->get_children();
                $any_child_added = false;
                foreach ( $children as $child_id ) {
                    $child = wc_get_product( $child_id );
                    if ( ! $child ) continue;
                    $child_result = WC()->cart->add_to_cart( $child_id, $quantity );
                    if ( $child_result ) {
                        $any_child_added = true;
                    }
                }
                $result = $any_child_added;
            } elseif ( $type === 'bundle' && class_exists( 'WC_Product_Bundle' ) ) {
                // WC Product Bundles plugin requires bundle config to add.
                // Without bundle config we can't add cleanly from the
                // simplified order form, so surface a meaningful error.
                $failures[] = sprintf( '%s (bundles must be added from their product page)', $product->get_name() );
                continue;
            } else {
                $result = WC()->cart->add_to_cart( $product_id, $quantity, $variation_id, $variation );
            }

            if ( $result ) {
                $added++;
            } else {
                // Capture WC's actual error notice if present.
                $err = '';
                if ( function_exists( 'wc_get_notices' ) ) {
                    $notices = wc_get_notices( 'error' );
                    if ( ! empty( $notices ) && isset( $notices[0]['notice'] ) ) {
                        $err = wp_strip_all_tags( $notices[0]['notice'] );
                    }
                    wc_clear_notices();
                }
                $failures[] = $product->get_name() . ( $err ? ': ' . $err : '' );
                // Always log failures (not only WP_DEBUG) so admins can
                // diagnose without flipping debug mode on the live site.
                error_log( sprintf(
                    '[SLW order-form] add_to_cart failed for product %d (type=%s, var=%d, qty=%d): %s',
                    $product_id, $type, $variation_id, $quantity, $err ?: 'no WC notice captured'
                ) );
            }
        }

        if ( $added > 0 && empty( $failures ) ) {
            wp_send_json_success( array(
                'message'  => $added . ' product(s) added to your cart.',
                'cart_url' => wc_get_cart_url(),
            ));
        } elseif ( $added > 0 ) {
            wp_send_json_success( array(
                'message'  => sprintf(
                    '%d added, %d skipped: %s',
                    $added,
                    count( $failures ),
                    implode( '; ', $failures )
                ),
                'cart_url' => wc_get_cart_url(),
            ));
        } else {
            $msg = empty( $failures )
                ? 'Could not add items to cart. Please check quantities and try again.'
                : 'Could not add: ' . implode( '; ', $failures );
            wp_send_json_error( array( 'message' => $msg ) );
        }
    }
}
