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

        // One-shot migration: flip the global slw_net30_enabled toggle ON.
        // This setting was the silent gate that kept the gateway from
        // registering with WC even when individual users had NET terms
        // granted (confirmed root cause for BBar 2026-05-26). Per-user
        // NET term grants are the real approval, so the global flag is
        // safe to default ON. Marked done with a flag so it only runs once.
        add_action( 'admin_init', array( __CLASS__, 'maybe_enable_net_globally' ) );

        // One-shot Sego Lily price seed: stamps per-product wholesale +
        // retail overrides for every known SKU using Holly's confirmed
        // prices. Runs once on admin_init then sets a flag.
        add_action( 'admin_init', array( __CLASS__, 'maybe_seed_sego_lily_prices' ) );

        // Surface the seed result as an admin notice.
        add_action( 'admin_notices', array( __CLASS__, 'sego_seed_notice' ) );

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
        if ( ! slw_is_wholesale_context() ) {
            return;
        }

        $user_id = get_current_user_id();
        $has_ordered = get_user_meta( $user_id, 'slw_first_order_placed', true );

        // Retroactive backfill: customers who placed orders before the plugin
        // tracked this flag would otherwise always hit the first-order minimum.
        // Check WC order history once and cache the result permanently.
        if ( ! $has_ordered && $user_id ) {
            $prior_orders = wc_get_orders( array(
                'customer_id' => $user_id,
                'limit'       => 1,
                'status'      => array( 'wc-processing', 'wc-completed' ),
                'return'      => 'ids',
            ) );
            if ( ! empty( $prior_orders ) ) {
                $has_ordered = current_time( 'mysql' );
                update_user_meta( $user_id, 'slw_first_order_placed', $has_ordered );
            }
        }

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
        if ( ! slw_is_wholesale_context() ) {
            return;
        }

        $user_id = get_current_user_id();
        $has_ordered = get_user_meta( $user_id, 'slw_first_order_placed', true );
        if ( ! $has_ordered && $user_id ) {
            $prior = wc_get_orders( array( 'customer_id' => $user_id, 'limit' => 1, 'status' => array( 'wc-processing', 'wc-completed' ), 'return' => 'ids' ) );
            if ( ! empty( $prior ) ) {
                $has_ordered = current_time( 'mysql' );
                update_user_meta( $user_id, 'slw_first_order_placed', $has_ordered );
            }
        }

        if ( ! $has_ordered ) {
            $minimum    = (float) slw_get_option( 'first_order_minimum', 300 );
            $cart_total = (float) WC()->cart->get_subtotal();
            if ( $minimum > 0 ) {
                $pct   = min( 100, round( ( $cart_total / $minimum ) * 100 ) );
                $met   = $cart_total >= $minimum;
                $color = $met ? '#2e7d32' : '#386174';
                $msg   = $met
                    ? 'First order minimum met!'
                    : 'First order minimum: $' . number_format( $cart_total, 2 ) . ' / $' . number_format( $minimum, 0 );
                echo '<div class="slw-cart-minimum-bar" style="margin:12px 0 4px;padding:14px 16px;background:#f7f6f3;border:1px solid #e0dbd0;border-radius:8px;">'
                   . '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">'
                   . '<span style="font-size:13px;font-weight:600;color:' . $color . ';">' . esc_html( $msg ) . '</span>'
                   . '<span style="font-size:12px;color:#628393;">' . $pct . '%</span>'
                   . '</div>'
                   . '<div style="background:#e0dbd0;border-radius:999px;height:6px;overflow:hidden;">'
                   . '<div style="height:100%;width:' . $pct . '%;background:' . $color . ';border-radius:999px;transition:width 0.3s;"></div>'
                   . '</div>'
                   . '</div>';
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
     * Flip the global slw_net30_enabled option ON the first time this
     * runs after the v4.6.45 update. Idempotent via the autoenabled flag.
     */
    public static function maybe_enable_net_globally() {
        if ( get_option( 'slw_net30_autoenabled' ) ) {
            return;
        }
        update_option( 'slw_net30_enabled', true );
        update_option( 'slw_net30_autoenabled', '1', true );
    }

    /**
     * One-shot Sego Lily price seed. Sets per-product wholesale + retail
     * overrides for every product whose name matches a known SKU pattern,
     * using the prices Holly confirmed in her 2026-05-26 email. After this
     * runs, slw_get_true_regular_price returns the correct one-time retail
     * and apply_wholesale_price short-circuits to the wholesale value at
     * step 1, regardless of how the product's _regular_price meta was
     * stored or filtered by subscription plugins. Idempotent via flag.
     */
    public static function maybe_seed_sego_lily_prices() {
        if ( get_option( 'slw_sego_prices_seeded' ) ) {
            return;
        }
        if ( ! function_exists( 'wc_get_products' ) ) {
            return;
        }

        // Each entry: [match_callback, wholesale, retail]
        $catalog = array(
            array( function( $n ) { return ( strpos( $n, 'gift' ) !== false ) || ( strpos( $n, 'variety' ) !== false ); }, 27, 54 ),
            array( function( $n ) { return ( strpos( $n, 'lip balm' ) !== false ); }, 7, 14 ),
            array( function( $n ) { return ( strpos( $n, 'deodorant' ) !== false ); }, 8, 16 ),
            array( function( $n ) { return ( strpos( $n, 'butter' ) !== false ) && ( strpos( $n, '4 oz' ) !== false || strpos( $n, '4oz' ) !== false || strpos( $n, '4-oz' ) !== false ); }, 27, 54 ),
            array( function( $n ) { return ( strpos( $n, 'butter' ) !== false ) && ( strpos( $n, '2 oz' ) !== false || strpos( $n, '2oz' ) !== false || strpos( $n, '2-oz' ) !== false ); }, 18, 36 ),
        );

        $products = wc_get_products( array( 'limit' => -1, 'status' => 'publish', 'return' => 'objects' ) );
        $updated = array();
        foreach ( $products as $product ) {
            $name = strtolower( $product->get_name() );
            foreach ( $catalog as $rule ) {
                list( $cb, $wholesale, $retail ) = $rule;
                if ( $cb( $name ) ) {
                    $existing_w = $product->get_meta( '_slw_wholesale_price' );
                    if ( $existing_w === '' || ! is_numeric( $existing_w ) ) {
                        $product->update_meta_data( '_slw_wholesale_price', (string) $wholesale );
                    }
                    $existing_r = $product->get_meta( '_slw_retail_price' );
                    if ( $existing_r === '' || ! is_numeric( $existing_r ) ) {
                        $product->update_meta_data( '_slw_retail_price', (string) $retail );
                    }
                    $product->save();
                    $updated[] = sprintf( '%s ($%d / $%d)', $product->get_name(), $retail, $wholesale );
                    break;
                }
            }
        }

        update_option( 'slw_sego_prices_seeded', '1', true );
        if ( ! empty( $updated ) ) {
            update_option( 'slw_sego_prices_seeded_summary', $updated, true );
        }
    }

    /**
     * Show a one-time admin notice listing the products the seed touched.
     * Dismissable; cleared after first view.
     */
    public static function sego_seed_notice() {
        $summary = get_option( 'slw_sego_prices_seeded_summary' );
        if ( empty( $summary ) || ! is_array( $summary ) ) {
            return;
        }
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }
        echo '<div class="notice notice-success is-dismissible"><p><strong>Sego Lily wholesale prices seeded</strong> on ' . count( $summary ) . ' product(s):</p><ul style="margin:6px 0 0 20px;">';
        foreach ( $summary as $line ) {
            echo '<li>' . esc_html( $line ) . '</li>';
        }
        echo '</ul><p style="margin-top:8px;color:#628393;">Edit any product to adjust its Wholesale + True Retail fields.</p></div>';
        delete_option( 'slw_sego_prices_seeded_summary' );
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
        <h3>Wholesale Portal</h3>
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
