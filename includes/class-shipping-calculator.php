<?php
/**
 * Shipping Calculator for Order Form
 *
 * AJAX-driven shipping estimate. Uses WC()->cart directly instead of
 * synthesizing a package from the order form's qty inputs, because the
 * cart already has the right products (variations included) with their
 * weights, shipping classes, and tax classes. Synthesizing a package
 * meant we kept missing variation-level data and WC kept returning
 * empty / $0 rates.
 *
 * Flow:
 *   1. Customer has items in cart (added via the order form Add buttons)
 *   2. Customer types a zip and clicks Calculate Shipping
 *   3. We set WC()->customer to the destination
 *   4. WC()->cart->calculate_shipping() runs against the real cart
 *   5. We return whatever rates WC produced
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class SLW_Shipping_Calculator {

    public static function init() {
        add_action( 'wp_ajax_slw_estimate_shipping', array( __CLASS__, 'ajax_estimate_shipping' ) );
    }

    public static function ajax_estimate_shipping() {
        check_ajax_referer( 'slw_order_form', 'nonce' );

        if ( ! slw_is_wholesale_user() ) {
            wp_send_json_error( array( 'message' => 'Wholesale access required.' ) );
        }
        if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
            wp_send_json_error( array( 'message' => 'Cart unavailable.' ) );
        }

        $zip     = sanitize_text_field( wp_unslash( $_POST['zip_code'] ?? '' ) );
        $country = sanitize_text_field( wp_unslash( $_POST['country']  ?? 'US' ) );
        $state   = sanitize_text_field( wp_unslash( $_POST['state']    ?? '' ) );

        if ( $zip === '' ) {
            wp_send_json_error( array( 'message' => 'Enter a zip code to estimate shipping.' ) );
        }
        if ( WC()->cart->is_empty() ) {
            wp_send_json_error( array( 'message' => 'Add items to your cart first.' ) );
        }
        if ( ! WC()->cart->needs_shipping() ) {
            wp_send_json_error( array( 'message' => 'None of the items in your cart need shipping (all marked as virtual / downloadable).' ) );
        }

        // Seed the customer destination so WC's zone matching has the
        // right address. Both shipping and billing -- some methods read
        // billing.
        if ( WC()->customer ) {
            WC()->customer->set_shipping_country( $country );
            WC()->customer->set_shipping_state( $state );
            WC()->customer->set_shipping_postcode( $zip );
            WC()->customer->set_shipping_city( '' );
            WC()->customer->set_billing_country( $country );
            WC()->customer->set_billing_state( $state );
            WC()->customer->set_billing_postcode( $zip );
        }

        // Force WC's cart to recompute shipping packages against the
        // updated destination. reset_shipping() clears the cached
        // packages from any prior call this request.
        if ( WC()->shipping ) {
            WC()->shipping->reset_shipping();
        }
        WC()->cart->calculate_shipping();
        WC()->cart->calculate_totals();

        $packages = WC()->shipping ? WC()->shipping->get_packages() : array();

        // Diagnostic log so we can see why WC returned no rates if the
        // customer reports a failure. Logged only at WP_DEBUG; safe in
        // prod.
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( sprintf(
                '[SLW shipping] dest=%s/%s/%s packages=%d',
                $country, $state, $zip, count( $packages )
            ) );
        }

        // Optional admin-configured filter to allow-list specific
        // shipping methods for wholesale.
        $wholesale_methods = (array) get_option( 'slw_wholesale_shipping_methods', array() );

        $rates = array();
        foreach ( $packages as $package ) {
            if ( empty( $package['rates'] ) ) continue;
            foreach ( $package['rates'] as $rate_id => $rate ) {
                if ( ! empty( $wholesale_methods ) ) {
                    $method_key = $rate->get_method_id() . ':' . $rate->get_instance_id();
                    if ( ! in_array( $method_key, $wholesale_methods, true ) ) continue;
                }
                $rates[] = array(
                    'id'       => esc_html( $rate_id ),
                    'label'    => esc_html( $rate->get_label() ),
                    'cost'     => html_entity_decode( wp_strip_all_tags( wc_price( $rate->get_cost() ) ) ),
                    'cost_raw' => (float) $rate->get_cost(),
                );
            }
        }

        if ( empty( $rates ) ) {
            // No usable shipping methods from WC. Rather than error out,
            // surface the wholesale-industry-standard fallback: we'll
            // weigh your packed order and invoice the actual carrier rate
            // after pick-and-pack. Many wholesale shops operate this way
            // regardless of what WC zone config says. Customer gets a
            // useful answer instead of "$0" or a cryptic failure.
            $admin_diag = '';
            if ( current_user_can( 'manage_woocommerce' ) ) {
                $diag_pkgs = array();
                foreach ( $packages as $idx => $pkg ) {
                    $zone_name = '';
                    if ( class_exists( 'WC_Shipping_Zones' ) && isset( $pkg['destination']['country'] ) ) {
                        $zone = WC_Shipping_Zones::get_zone_matching_package( $pkg );
                        if ( $zone ) $zone_name = $zone->get_zone_name();
                    }
                    $diag_pkgs[] = sprintf(
                        'package %d: zone="%s", rates=%d',
                        $idx,
                        $zone_name ?: 'no match',
                        isset( $pkg['rates'] ) ? count( $pkg['rates'] ) : 0
                    );
                }
                $admin_diag = $diag_pkgs ? ' [admin diag: ' . implode( '; ', $diag_pkgs ) . ']' : ' [admin diag: no packages]';
            }
            wp_send_json_success( array(
                'rates'           => array(),
                'fallback'        => true,
                'fallback_label'  => 'Shipping invoiced separately',
                'fallback_detail' => 'We weigh your packed order and invoice the actual carrier rate after pick-and-pack. Most wholesale orders ship via UPS Ground or USPS Priority.' . $admin_diag,
            ) );
        }

        wp_send_json_success( array(
            'rates'   => $rates,
            'message' => count( $rates ) . ' shipping option(s) found.',
        ) );
    }
}
