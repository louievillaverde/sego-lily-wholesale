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
        // Site-wide filter -- applies to the order-form AJAX estimate,
        // the cart page, and the standard /checkout. Strips bogus
        // free-shipping methods (e.g. Flexible Shipping (Free)) so they
        // never appear to wholesale customers; Local Pickup is the
        // only legitimately-free option. Also injects an "Invoiced
        // separately" pseudo-rate when Local Pickup would otherwise be
        // the only choice (most wholesale buyers ship, not pick up).
        add_filter( 'woocommerce_package_rates', array( __CLASS__, 'filter_bogus_free_rates' ), 100, 2 );
        // Prevent WC from auto-selecting Local Pickup just because it's
        // the cheapest. Wholesale customers should make an explicit
        // pickup choice; default to the "Invoiced separately" pseudo-
        // rate or any real shipping method instead.
        add_filter( 'woocommerce_shipping_chosen_method', array( __CLASS__, 'avoid_auto_local_pickup' ), 10, 2 );
    }

    /**
     * Filter shipping rates for wholesale users:
     *   1. Strip bogus free rates (anything cost <= 0 that isn't Local
     *      Pickup), e.g. an inadvertent "Flexible Shipping (Free)".
     *   2. Restrict Local Pickup to Montana destinations (Holly's HQ).
     *      Out-of-state customers can't realistically pick up.
     *   3. If no real paid shipping method survived (typical when Holly
     *      hasn't fully configured carrier zones), inject estimated
     *      USPS Priority + UPS Ground rates calculated from cart weight
     *      so the customer sees REAL approximate numbers instead of
     *      $0/pickup-only. Estimates noted as "approx." and Holly can
     *      reconcile actual cost when packing.
     */
    public static function filter_bogus_free_rates( $rates, $package ) {
        if ( ! function_exists( 'slw_is_wholesale_context' ) || ! slw_is_wholesale_context() ) {
            return $rates;
        }
        // Step 1: strip bogus free rates
        foreach ( $rates as $rate_id => $rate ) {
            $cost      = (float) $rate->get_cost();
            $method_id = $rate->get_method_id();
            if ( $cost <= 0 && $method_id !== 'local_pickup' ) {
                unset( $rates[ $rate_id ] );
            }
        }
        // Step 2: restrict Local Pickup to Montana
        $dest_state    = isset( $package['destination']['state'] ) ? strtoupper( $package['destination']['state'] ) : '';
        $dest_postcode = isset( $package['destination']['postcode'] ) ? $package['destination']['postcode'] : '';
        $is_montana    = ( $dest_state === 'MT' ) || ( strlen( $dest_postcode ) >= 2 && substr( $dest_postcode, 0, 2 ) === '59' );
        if ( ! $is_montana ) {
            foreach ( $rates as $rate_id => $rate ) {
                if ( $rate->get_method_id() === 'local_pickup' ) {
                    unset( $rates[ $rate_id ] );
                }
            }
        }
        // Step 3: inject weight-based estimates if no real paid method
        // is available. Skipped when Holly's zones already returned
        // real carrier rates.
        $has_real_rate = false;
        foreach ( $rates as $rate ) {
            $mid  = $rate->get_method_id();
            $cost = (float) $rate->get_cost();
            if ( $mid === 'local_pickup' ) continue;
            if ( $mid === 'slw_invoice_shipping' ) continue;
            if ( $mid === 'slw_estimate' ) continue;
            if ( $cost > 0 ) { $has_real_rate = true; break; }
        }
        if ( ! $has_real_rate ) {
            foreach ( self::weight_based_estimates( $package ) as $rate_id => $rate ) {
                $rates[ $rate_id ] = $rate;
            }
        }
        return $rates;
    }

    /**
     * Compute approximate shipping rates from cart weight. Returns an
     * array of WC_Shipping_Rate objects: USPS Priority Mail estimate
     * and UPS Ground estimate. Used as a fallback when Holly's WC
     * carrier zones don't return real rates so the customer still sees
     * useful approximate numbers.
     *
     * Heuristic (zone 4-5, typical US wholesale):
     *   USPS Priority: $9 base + $2.25/lb
     *   UPS Ground:    $12 base + $1.75/lb
     * These are rough; Holly reconciles the actual carrier rate when
     * she prints labels.
     */
    private static function weight_based_estimates( $package ) {
        $weight = 0;
        if ( ! empty( $package['contents'] ) ) {
            foreach ( $package['contents'] as $item ) {
                if ( empty( $item['data'] ) || ! is_object( $item['data'] ) ) continue;
                $unit_weight = (float) $item['data']->get_weight();
                if ( $unit_weight <= 0 ) {
                    // Default 0.75 lb / unit assumption for tallow jars
                    // when product weight isn't set in WC. Better than
                    // ignoring the item entirely.
                    $unit_weight = 0.75;
                }
                $weight += $unit_weight * (int) $item['quantity'];
            }
        }
        // Convert from store weight unit to lb if needed
        $weight_unit = get_option( 'woocommerce_weight_unit', 'lbs' );
        if ( $weight_unit === 'kg' )  $weight = $weight * 2.20462;
        if ( $weight_unit === 'g' )   $weight = $weight * 0.00220462;
        if ( $weight_unit === 'oz' )  $weight = $weight * 0.0625;

        if ( $weight <= 0 ) $weight = 1; // safety: at least 1 lb

        $usps_cost = round( 9  + $weight * 2.25, 2 );
        $ups_cost  = round( 12 + $weight * 1.75, 2 );

        $out = array();
        $out['slw_estimate:usps'] = new WC_Shipping_Rate(
            'slw_estimate:usps',
            sprintf( 'USPS Priority Mail (approx. %0.1f lb)', $weight ),
            $usps_cost,
            array(),
            'slw_estimate'
        );
        $out['slw_estimate:ups'] = new WC_Shipping_Rate(
            'slw_estimate:ups',
            sprintf( 'UPS Ground (approx. %0.1f lb)', $weight ),
            $ups_cost,
            array(),
            'slw_estimate'
        );
        return $out;
    }

    /**
     * Don't let WC auto-select Local Pickup as the default shipping
     * method. WC picks whatever is first / cheapest; for wholesale
     * customers we want the customer to make an explicit choice, so
     * prefer (in order): any real paid shipping method, our
     * "Invoiced separately" pseudo-rate, then finally Local Pickup
     * only if nothing else is available.
     */
    public static function avoid_auto_local_pickup( $chosen_method, $available_methods ) {
        if ( ! function_exists( 'slw_is_wholesale_context' ) || ! slw_is_wholesale_context() ) {
            return $chosen_method;
        }
        if ( empty( $available_methods ) ) return $chosen_method;
        // If WC's current choice isn't Local Pickup, leave it alone.
        if ( isset( $available_methods[ $chosen_method ] ) ) {
            $current = $available_methods[ $chosen_method ];
            if ( $current && method_exists( $current, 'get_method_id' )
                && $current->get_method_id() !== 'local_pickup' ) {
                return $chosen_method;
            }
        }
        // Prefer any non-pickup, non-invoice rate first
        foreach ( $available_methods as $key => $rate ) {
            $mid = $rate->get_method_id();
            if ( $mid !== 'local_pickup' && $mid !== 'slw_invoice_shipping' ) {
                return $key;
            }
        }
        // Then our invoice-separately pseudo-rate
        foreach ( $available_methods as $key => $rate ) {
            if ( $rate->get_method_id() === 'slw_invoice_shipping' ) {
                return $key;
            }
        }
        return $chosen_method;
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
        // Force-hydrate the cart from session before checking is_empty.
        // In admin-ajax.php WC's cart sometimes lazy-loads later in the
        // request -- without this, is_empty() returns true for a cart
        // that actually has items, and the calc returns the misleading
        // "add items first" error.
        if ( WC()->session && method_exists( WC()->cart, 'get_cart_from_session' ) ) {
            WC()->cart->get_cart_from_session();
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
                $method_id   = $rate->get_method_id();
                $cost        = (float) $rate->get_cost();

                if ( ! empty( $wholesale_methods ) ) {
                    $method_key = $method_id . ':' . $rate->get_instance_id();
                    if ( ! in_array( $method_key, $wholesale_methods, true ) ) continue;
                }

                // Filter out free rates that aren't Local Pickup. Holly's
                // setup has a "Flexible Shipping (Free)" rate showing up
                // that shouldn't apply to wholesale orders. The only
                // legitimately free option for wholesale is Local Pickup.
                if ( $cost <= 0 && $method_id !== 'local_pickup' ) {
                    continue;
                }

                $rates[] = array(
                    'id'       => esc_html( $rate_id ),
                    'label'    => esc_html( $rate->get_label() ),
                    'cost'     => html_entity_decode( wp_strip_all_tags( wc_price( $cost ) ) ),
                    'cost_raw' => $cost,
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
