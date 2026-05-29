<?php
/**
 * Shipping Calculator for Order Form
 *
 * Provides an AJAX-driven shipping estimate on the wholesale order form
 * so customers know approximate shipping costs before checkout. Uses
 * WooCommerce's native shipping calculation engine.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class SLW_Shipping_Calculator {

    public static function init() {
        add_action( 'wp_ajax_slw_estimate_shipping', array( __CLASS__, 'ajax_estimate_shipping' ) );
    }

    /**
     * AJAX handler: estimate shipping for a set of products + quantities.
     *
     * Expects POST data:
     *   - nonce: slw_order_form nonce
     *   - items: JSON array of {product_id, quantity}
     *   - zip_code: destination postal code
     *   - country: destination country (default US)
     *   - state: destination state (optional)
     */
    public static function ajax_estimate_shipping() {
        check_ajax_referer( 'slw_order_form', 'nonce' );

        if ( ! slw_is_wholesale_user() ) {
            wp_send_json_error( array( 'message' => 'Wholesale access required.' ) );
        }

        $items    = json_decode( stripslashes( $_POST['items'] ?? '[]' ), true );
        $zip_code = sanitize_text_field( $_POST['zip_code'] ?? '' );
        $country  = sanitize_text_field( $_POST['country'] ?? 'US' );
        $state    = sanitize_text_field( $_POST['state'] ?? '' );

        if ( empty( $items ) || ! is_array( $items ) ) {
            wp_send_json_error( array( 'message' => 'No items selected for shipping estimate.' ) );
        }

        if ( empty( $zip_code ) ) {
            wp_send_json_error( array( 'message' => 'Please enter a zip code.' ) );
        }

        // Build a temporary package for WooCommerce shipping calculation
        $package_contents = array();
        $package_total    = 0;

        foreach ( $items as $index => $item ) {
            $product_id   = absint( $item['product_id']   ?? 0 );
            $variation_id = absint( $item['variation_id'] ?? 0 );
            $quantity     = absint( $item['quantity']     ?? 0 );

            if ( $product_id <= 0 || $quantity <= 0 ) {
                continue;
            }

            // For variations, load the variation itself so WC reads the
            // variation-level weight/dimensions/shipping class. Falls
            // back to the parent product if no variation_id was sent.
            $product = $variation_id > 0
                ? wc_get_product( $variation_id )
                : wc_get_product( $product_id );
            if ( ! $product || ! $product->is_in_stock() ) {
                continue;
            }

            $line_total = (float) $product->get_price() * $quantity;
            $package_total += $line_total;

            $package_contents[ $index ] = array(
                'key'               => 'slw_est_' . $index,
                'product_id'        => $product_id,
                'variation_id'      => $variation_id,
                'variation'         => array(),
                'quantity'          => $quantity,
                'data'              => $product,
                'data_hash'         => method_exists( 'wc_get_cart_item_data_hash' ) ? wc_get_cart_item_data_hash( $product ) : '',
                'line_total'        => $line_total,
                'line_tax'          => 0,
                'line_subtotal'     => $line_total,
                'line_subtotal_tax' => 0,
            );
        }

        if ( empty( $package_contents ) ) {
            wp_send_json_error( array( 'message' => 'No valid items for shipping estimate.' ) );
        }

        // Build the package array WooCommerce expects
        $package = array(
            'contents'        => $package_contents,
            'contents_cost'   => $package_total,
            'applied_coupons' => array(),
            'user'            => array( 'ID' => get_current_user_id() ),
            'destination'     => array(
                'country'   => $country,
                'state'     => $state,
                'postcode'  => $zip_code,
                'city'      => '',
                'address'   => '',
                'address_2' => '',
            ),
        );

        // Seed WC's customer object with the destination so shipping
        // zone matching uses the same data the package does. Some
        // shipping methods read from WC()->customer instead of the
        // package destination -- without this they fall back to the
        // store's home zip and return wrong (or zero) rates.
        if ( WC()->customer ) {
            WC()->customer->set_shipping_country( $country );
            WC()->customer->set_shipping_state( $state );
            WC()->customer->set_shipping_postcode( $zip_code );
            WC()->customer->set_billing_country( $country );
            WC()->customer->set_billing_state( $state );
            WC()->customer->set_billing_postcode( $zip_code );
        }

        // Calculate shipping using WooCommerce's engine
        $shipping = WC()->shipping();
        $shipping->reset_shipping();
        $calculated = $shipping->calculate_shipping_for_package( $package );

        if ( empty( $calculated['rates'] ) ) {
            wp_send_json_error( array(
                'message' => 'No shipping methods available for this destination. Check your WooCommerce shipping zones cover ' . esc_html( $state ?: $country ) . ' ' . esc_html( $zip_code ) . '.',
            ) );
        }

        // Filter to only wholesale-allowed methods if configured
        $wholesale_methods = (array) get_option( 'slw_wholesale_shipping_methods', array() );

        $rates = array();
        foreach ( $calculated['rates'] as $rate_id => $rate ) {
            // If wholesale method restrictions are set, filter by them
            if ( ! empty( $wholesale_methods ) ) {
                $method_key = $rate->get_method_id() . ':' . $rate->get_instance_id();
                if ( ! in_array( $method_key, $wholesale_methods, true ) ) {
                    continue;
                }
            }

            $rates[] = array(
                'id'        => esc_html( $rate_id ),
                'label'     => esc_html( $rate->get_label() ),
                'cost'      => html_entity_decode( wp_strip_all_tags( wc_price( $rate->get_cost() ) ) ),
                'cost_raw'  => (float) $rate->get_cost(),
            );
        }

        if ( empty( $rates ) ) {
            wp_send_json_error( array( 'message' => 'No shipping methods available for wholesale orders to this destination.' ) );
        }

        wp_send_json_success( array(
            'rates'   => $rates,
            'message' => count( $rates ) . ' shipping option(s) found.',
        ) );
    }
}
