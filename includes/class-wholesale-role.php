<?php
/**
 * Wholesale Role and Pricing
 *
 * Creates the wholesale_customer role and applies automatic pricing discounts.
 * Retail visitors see normal prices. Wholesale users see the discounted price
 * everywhere: catalog, product pages, cart, checkout, and order emails.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class SLW_Wholesale_Role {

    public static function init() {
        // Filter product prices for wholesale users
        add_filter( 'woocommerce_product_get_price', array( __CLASS__, 'apply_wholesale_price' ), 99, 2 );
        add_filter( 'woocommerce_product_get_regular_price', array( __CLASS__, 'keep_regular_price' ), 99, 2 );
        add_filter( 'woocommerce_product_get_sale_price', array( __CLASS__, 'apply_wholesale_price' ), 99, 2 );

        // Variable product price ranges
        add_filter( 'woocommerce_product_variation_get_price', array( __CLASS__, 'apply_wholesale_price' ), 99, 2 );
        add_filter( 'woocommerce_product_variation_get_regular_price', array( __CLASS__, 'keep_regular_price' ), 99, 2 );
        add_filter( 'woocommerce_product_variation_get_sale_price', array( __CLASS__, 'apply_wholesale_price' ), 99, 2 );

        // Variable product price hash (forces WC to recalculate cached price ranges)
        add_filter( 'woocommerce_get_variation_prices_hash', array( __CLASS__, 'variation_price_hash' ), 99, 1 );

        // Show "Wholesale Price" label on product pages and in cart
        add_filter( 'woocommerce_get_price_html', array( __CLASS__, 'price_html' ), 99, 2 );
        add_filter( 'woocommerce_cart_item_price', array( __CLASS__, 'cart_item_price_label' ), 99, 3 );

        // Tiered pricing in the cart: when quantity crosses a threshold, apply
        // the better per-unit price to the line item.
        add_action( 'woocommerce_before_calculate_totals', array( __CLASS__, 'apply_tiered_pricing' ), 99 );

        // Tax exemption: wholesale users with valid resale cert pay no sales tax
        add_filter( 'woocommerce_customer_get_is_vat_exempt', array( __CLASS__, 'tax_exempt' ), 99, 2 );
        add_action( 'woocommerce_calculated_total', array( __CLASS__, 'maybe_zero_tax' ), 99 );

        // Hide wholesale-only products from retail customers
        add_action( 'pre_get_posts', array( __CLASS__, 'filter_wholesale_only_products' ) );
        add_filter( 'woocommerce_product_query_meta_query', array( __CLASS__, 'filter_wholesale_only_meta' ), 10, 2 );

        // Per-product price override (admin can set a custom wholesale price per product).
        // Hooked to woocommerce_product_options_general_product_data so the fields render
        // for ALL product types (simple, variable, variable-subscription, etc.) instead
        // of being trapped inside the pricing options_group which is hidden for variable
        // products. That was why Gift Set could never have its wholesale override set --
        // the field was simply not visible on variable-subscription products.
        add_action( 'woocommerce_product_options_general_product_data', array( __CLASS__, 'add_product_pricing_field' ) );
        add_action( 'woocommerce_admin_process_product_object', array( __CLASS__, 'save_product_pricing_field' ) );

        // Admin user list column showing wholesale status
        add_filter( 'manage_users_columns', array( __CLASS__, 'add_user_column' ) );
        add_filter( 'manage_users_custom_column', array( __CLASS__, 'render_user_column' ), 10, 3 );

        // Manual promote/demote on user profile
        add_action( 'show_user_profile', array( __CLASS__, 'render_user_profile_section' ) );
        add_action( 'edit_user_profile', array( __CLASS__, 'render_user_profile_section' ) );
        add_action( 'personal_options_update', array( __CLASS__, 'save_user_profile_section' ) );
        add_action( 'edit_user_profile_update', array( __CLASS__, 'save_user_profile_section' ) );

        // Hide WooCommerce Subscriptions elements for wholesale users
        add_filter( 'wcsatt_product_subscription_schemes', array( __CLASS__, 'hide_subscription_schemes' ), 999, 2 );
        add_filter( 'woocommerce_subscriptions_product_price_string', array( __CLASS__, 'hide_subscription_price_string' ), 999, 2 );
        add_filter( 'woocommerce_subscription_price_string', array( __CLASS__, 'hide_subscription_price_string' ), 999, 2 );
        add_filter( 'woocommerce_is_subscription', array( __CLASS__, 'disable_subscription_behavior' ), 999, 3 );
        add_filter( 'wcs_is_subscription', array( __CLASS__, 'disable_subscription_behavior' ), 999, 3 );
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'hide_subscription_css' ) );

        // Cart-specific subscription suppression for wholesale users.
        // The wholesale flow is one-time only; recurring totals + scheme
        // meta on cart line items shouldn't be visible (Holly call w/ Camila
        // 2026-05-29: "wholesale shouldn't see subscription pricing at all").
        add_filter( 'woocommerce_get_item_data',                     array( __CLASS__, 'strip_subscription_item_data' ), 999, 2 );
        add_filter( 'wcs_cart_totals_order_total_html',              array( __CLASS__, 'wholesale_cart_total_html' ), 999, 1 );
        add_filter( 'wcs_cart_recurring_total_html',                 array( __CLASS__, 'strip_recurring_for_wholesale' ), 999, 1 );
        add_filter( 'woocommerce_subscriptions_cart_totals_recurring_total_html', array( __CLASS__, 'strip_recurring_for_wholesale' ), 999, 1 );

        // Suppress retail mini-cart / side-cart popup for wholesale users.
        // The order-form page has its own Cart Preview; the theme's auto-
        // opening side cart competes with it and confused Holly's customers.
        add_filter( 'woocommerce_widget_cart_is_hidden',  array( __CLASS__, 'hide_widget_cart_for_wholesale' ) );
        add_filter( 'woocommerce_add_to_cart_fragments',  array( __CLASS__, 'maybe_empty_cart_fragments' ), 999 );
        add_action( 'wp_footer',                          array( __CLASS__, 'suppress_side_cart_js' ) );

        // Redirect "Return to shop" to wholesale order form for wholesale users
        add_filter( 'woocommerce_return_to_shop_redirect', array( __CLASS__, 'wholesale_return_to_shop' ) );

        // Add "Continue Shopping" link after add-to-cart on single product pages
        add_action( 'woocommerce_after_add_to_cart_button', array( __CLASS__, 'continue_shopping_link' ) );

        // Coupon gating: block retail coupons for wholesale customers,
        // allow only codes that signal wholesale-intent via a prefix
        // (WHOLESALE-, WS-, WHOLE-) OR have the _slw_wholesale_allowed
        // post meta set to '1' on the coupon. Default retail coupons
        // never apply at wholesale prices.
        add_filter( 'woocommerce_coupon_is_valid', array( __CLASS__, 'gate_coupon_for_wholesale' ), 10, 3 );
        add_filter( 'woocommerce_coupon_error',    array( __CLASS__, 'coupon_error_for_wholesale' ), 10, 3 );

        // Bulk action: grant NET 30 to existing wholesale accounts
        add_filter( 'bulk_actions-users', array( __CLASS__, 'add_bulk_net30_action' ) );
        add_filter( 'handle_bulk_actions-users', array( __CLASS__, 'handle_bulk_net30_action' ), 10, 3 );
        add_action( 'admin_notices', array( __CLASS__, 'bulk_net30_notice' ) );

        // Product page redirect banner + account icon JS fix for wholesale users
        // Both injected via wp_footer JS to avoid Elementor bypassing WC hooks.
        add_action( 'wp_footer', array( __CLASS__, 'product_page_and_account_js' ) );
    }

    /**
     * Single wp_footer script for wholesale users that:
     * 1. On product pages: injects a banner before the add-to-cart form
     *    directing them to the wholesale order form. Done in JS so it works
     *    with Elementor, which bypasses woocommerce_single_product_summary.
     * 2. Rewrites any My Account link to /wholesale-portal (catches Elementor
     *    account icon hrefs that bypass the PHP page_link filter).
     */
    public static function product_page_and_account_js() {
        if ( is_admin() ) return;
        if ( ! is_user_logged_in() || ! slw_is_wholesale_user() ) return;

        $is_product     = is_product() ? 'true' : 'false';
        $order_form_url = esc_js( home_url( '/wholesale-order' ) );
        $acct_url       = get_permalink( get_option( 'woocommerce_myaccount_page_id' ) );
        $portal_url     = home_url( '/wholesale-portal' );
        ?>
        <script>
        (function(){
            var isProduct  = <?php echo $is_product; ?>;
            var orderForm  = <?php echo wp_json_encode( home_url( '/wholesale-order' ) ); ?>;
            var acct       = <?php echo wp_json_encode( $acct_url ); ?>;
            var portal     = <?php echo wp_json_encode( $portal_url ); ?>;

            // 1. Product page banner
            if (isProduct) {
                // Scoped keyframe animations -- class-based so they cannot bleed onto other elements
                var style = document.createElement('style');
                style.textContent =
                    '@keyframes slwBannerIn{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}'
                    + '@keyframes slwBtnBreath{0%,100%{box-shadow:0 2px 6px rgba(56,97,116,0.18),0 0 0 0 rgba(212,175,55,0)}50%{box-shadow:0 0 0 2px rgba(212,175,55,0.55),0 0 28px 8px rgba(212,175,55,0.65),0 4px 14px rgba(212,175,55,0.35)}}'
                    + '@keyframes slwArrowNudge{0%,70%,100%{transform:translateX(0)}35%{transform:translateX(5px)}}'
                    + '.slw-wholesale-banner{animation:slwBannerIn 0.35s ease both}'
                    + '.slw-order-btn{'
                    +   'background:#F7F6F3!important;'
                    +   'animation:slwBtnBreath 3s ease-in-out 0.6s infinite!important'
                    + '}'
                    + '.slw-order-btn .slw-arrow{display:inline-block;animation:slwArrowNudge 2.4s ease-in-out 0.6s infinite}';
                document.head.appendChild(style);

                var banner = document.createElement('div');
                banner.innerHTML =
                    '<div class="slw-wholesale-banner" style="background:#386174;border-radius:8px;padding:18px 22px;margin:18px 0;'
                    + 'box-shadow:0 4px 16px rgba(56,97,116,0.3);'
                    + 'font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Helvetica,Arial,sans-serif;">'
                    + '<p style="margin:0 0 4px;font-size:12px;color:rgba(247,246,243,0.65);font-weight:500;letter-spacing:0.5px;text-transform:uppercase;text-wrap:balance;">'
                    + 'Wholesale account'
                    + '</p>'
                    + '<p style="margin:0 0 14px;font-size:15px;color:#F7F6F3;font-weight:600;line-height:1.4;text-wrap:pretty;">'
                    + 'Psst! You\'re on the retail page. Your order form is over here.'
                    + '</p>'
                    + '<a href="' + orderForm + '" class="slw-order-btn" style="display:inline-block;'
                    + 'color:#386174;padding:12px 22px;border-radius:6px;font-size:14px;font-weight:700;'
                    + 'text-decoration:none;letter-spacing:0.2px;line-height:1.3;">'
                    + 'Go to my order form <span class="slw-arrow">&rarr;</span></a>'
                    + '</div>';
                // Insert after the add-to-cart form
                var target = document.querySelector('.elementor-add-to-cart, form.cart');
                if (target) {
                    target.parentNode.insertBefore(banner.firstElementChild, target.nextSibling);
                }
            }

            // 2. Account icon rewrite
            if (acct && portal) {
                var acctNorm = acct.replace(/\/$/, '');
                document.querySelectorAll('a[href]').forEach(function(a) {
                    if (a.href.replace(/\/$/, '') === acctNorm) {
                        a.href = portal;
                    }
                });
            }
        })();
        </script>
        <?php
    }

    /**
     * Tax exempt status for wholesale users. If the user has the wholesale role
     * AND is marked as resale-cert-verified (admin-toggled in user profile),
     * WooCommerce skips sales tax at checkout. This is the standard B2B pattern
     * for businesses with a valid resale certificate.
     */
    public static function tax_exempt( $is_exempt, $customer ) {
        if ( ! $customer ) return $is_exempt;
        $user_id = $customer->get_id();
        if ( ! $user_id ) return $is_exempt;
        if ( ! slw_is_wholesale_context( $user_id ) ) return $is_exempt;
        // Tax exempt if admin has verified the resale cert OR if global default is on
        $verified = get_user_meta( $user_id, 'slw_resale_cert_verified', true );
        $global_default = get_option( 'slw_wholesale_tax_exempt_default', '0' );
        return ( $verified === '1' || $global_default === '1' ) ? true : $is_exempt;
    }

    /**
     * Catch-all for tax calculation if the standard exempt filter does not fire
     * (some WC themes recalculate after the customer object is built).
     */
    public static function maybe_zero_tax( $total ) {
        if ( ! is_admin() && slw_is_wholesale_context() ) {
            $verified = get_user_meta( get_current_user_id(), 'slw_resale_cert_verified', true );
            $global = get_option( 'slw_wholesale_tax_exempt_default', '0' );
            if ( $verified === '1' || $global === '1' ) {
                WC()->cart->set_total_tax( 0 );
            }
        }
        return $total;
    }

    /**
     * Hide products marked "wholesale-only" from retail customers.
     * Admin sets a checkbox on the product edit page; that product only
     * appears in the catalog for wholesale users.
     */
    public static function filter_wholesale_only_products( $query ) {
        if ( is_admin() || ! $query->is_main_query() ) return;
        if ( slw_is_wholesale_context() ) return;  // wholesale context sees everything

        // Hide individually-flagged wholesale-only products
        $meta_query = $query->get( 'meta_query' ) ?: array();
        $meta_query[] = array(
            'relation' => 'OR',
            array(
                'key'     => '_slw_wholesale_only',
                'value'   => '1',
                'compare' => '!=',
            ),
            array(
                'key'     => '_slw_wholesale_only',
                'compare' => 'NOT EXISTS',
            ),
        );
        $query->set( 'meta_query', $meta_query );

        // Hide products in wholesale-only categories
        $wholesale_cats = get_option( 'slw_wholesale_only_categories', array() );
        if ( ! empty( $wholesale_cats ) ) {
            $tax_query = $query->get( 'tax_query' ) ?: array();
            $tax_query[] = array(
                'taxonomy' => 'product_cat',
                'field'    => 'term_id',
                'terms'    => array_map( 'absint', $wholesale_cats ),
                'operator' => 'NOT IN',
            );
            $query->set( 'tax_query', $tax_query );
        }
    }

    /**
     * Same wholesale-only filter applied at the WooCommerce query level
     * (catches the shop page, search results, related products).
     */
    public static function filter_wholesale_only_meta( $meta_query, $query ) {
        if ( is_admin() || slw_is_wholesale_context() ) return $meta_query;
        $meta_query[] = array(
            'relation' => 'OR',
            array( 'key' => '_slw_wholesale_only', 'value' => '1', 'compare' => '!=' ),
            array( 'key' => '_slw_wholesale_only', 'compare' => 'NOT EXISTS' ),
        );
        return $meta_query;
    }

    /**
     * Per-product wholesale price override + wholesale-only checkbox.
     * Renders on the WooCommerce product edit page under "General" pricing.
     * If set, this overrides the global discount percentage for this product.
     */
    public static function add_product_pricing_field() {
        global $post;
        $show_for_all = 'show_if_simple show_if_external show_if_variable show_if_variable-subscription show_if_subscription';
        $sym = get_woocommerce_currency_symbol();

        echo '<div class="options_group slw-wholesale-fields">';
        woocommerce_wp_text_input( array(
            'id'                => '_slw_retail_price',
            'wrapper_class'     => $show_for_all,
            'label'             => 'WS: True Retail (' . $sym . ')',
            'desc_tip'          => true,
            'description'       => 'One-time retail price used by the Price List.',
            'type'              => 'number',
            'custom_attributes' => array( 'step' => '0.01', 'min' => '0' ),
        ));
        woocommerce_wp_text_input( array(
            'id'                => '_slw_wholesale_price',
            'wrapper_class'     => $show_for_all,
            'label'             => 'WS: Price (' . $sym . ')',
            'desc_tip'          => true,
            'description'       => 'Per-product wholesale price.',
            'type'              => 'number',
            'custom_attributes' => array( 'step' => '0.01', 'min' => '0' ),
        ));
        woocommerce_wp_checkbox( array(
            'id'            => '_slw_wholesale_only',
            'wrapper_class' => $show_for_all,
            'label'         => 'WS: Wholesale-only',
        ));
        woocommerce_wp_text_input( array(
            'id'            => '_slw_tiered_pricing',
            'wrapper_class' => $show_for_all,
            'label'         => 'WS: Tiered',
            'desc_tip'      => true,
            'description'   => 'qty:price pairs.',
            'placeholder'   => '12:15.00,24:12.00',
        ));
        woocommerce_wp_text_input( array(
            'id'            => '_slw_lead_time',
            'wrapper_class' => $show_for_all,
            'label'         => 'WS: Lead Time',
            'desc_tip'      => true,
            'description'   => 'Free text dispatch estimate. Surfaces as "Ships in ___" on the order form.',
            'placeholder'   => '2-3 business days',
        ));
        echo '</div>';
    }

    public static function save_product_pricing_field( $product ) {
        $retail = isset( $_POST['_slw_retail_price'] ) ? wc_clean( $_POST['_slw_retail_price'] ) : '';
        $price  = isset( $_POST['_slw_wholesale_price'] ) ? wc_clean( $_POST['_slw_wholesale_price'] ) : '';
        $only   = isset( $_POST['_slw_wholesale_only'] ) ? '1' : '0';
        $product->update_meta_data( '_slw_retail_price', $retail );
        $product->update_meta_data( '_slw_wholesale_price', $price );
        $product->update_meta_data( '_slw_wholesale_only', $only );

        // Tiered pricing: "qty1:price1,qty2:price2" format
        // Example: "12:15.00,24:12.00,48:10.00" means 12+ = $15 each, 24+ = $12, 48+ = $10
        $tiers = isset( $_POST['_slw_tiered_pricing'] ) ? wc_clean( $_POST['_slw_tiered_pricing'] ) : '';
        $product->update_meta_data( '_slw_tiered_pricing', $tiers );

        // Lead time: free-text estimate shown as "Ships in ___" on the
        // wholesale order form. Helps wholesale buyers plan inventory.
        $lead = isset( $_POST['_slw_lead_time'] ) ? wc_clean( $_POST['_slw_lead_time'] ) : '';
        $product->update_meta_data( '_slw_lead_time', $lead );
    }

    /**
     * Apply tiered pricing to cart line items. Runs before total calc.
     * For each line item where the product has tiered pricing configured AND
     * the cart quantity meets a tier threshold, set the line price to the
     * tiered rate. Works alongside the standard wholesale discount: the
     * tier always produces a LOWER price than the regular wholesale rate.
     */
    public static function apply_tiered_pricing( $cart ) {
        if ( is_admin() && ! defined( 'DOING_AJAX' ) ) return;
        if ( ! slw_is_wholesale_context() ) return;

        foreach ( $cart->get_cart() as $cart_item ) {
            if ( empty( $cart_item['data'] ) ) continue;
            $product = $cart_item['data'];
            $qty = (int) $cart_item['quantity'];
            $tiers_string = $product->get_meta( '_slw_tiered_pricing' );
            $tiers = self::parse_tiers( $tiers_string );
            if ( empty( $tiers ) ) continue;

            // Find the best tier for this quantity (highest threshold met)
            $best_price = null;
            foreach ( $tiers as $threshold => $tier_price ) {
                if ( $qty >= $threshold ) {
                    $best_price = $tier_price;
                }
            }

            if ( $best_price !== null ) {
                $product->set_price( $best_price );
            }
        }
    }

    /**
     * Parse tiered pricing string into an array of [qty => price] brackets.
     * Returns empty array if format invalid.
     */
    public static function parse_tiers( $tiers_string ) {
        if ( empty( $tiers_string ) ) return array();
        $tiers = array();
        foreach ( explode( ',', $tiers_string ) as $pair ) {
            $parts = explode( ':', trim( $pair ) );
            if ( count( $parts ) === 2 && is_numeric( $parts[0] ) && is_numeric( $parts[1] ) ) {
                $tiers[ (int) $parts[0] ] = (float) $parts[1];
            }
        }
        ksort( $tiers );
        return $tiers;
    }

    /**
     * Decide whether a coupon is valid for the current request. Wholesale
     * customers get only coupons whose code starts with WHOLESALE-, WS-,
     * or WHOLE- (case-insensitive) OR coupons explicitly flagged via
     * _slw_wholesale_allowed meta. Retail coupons are blocked so they
     * can't double-discount the already-wholesale prices.
     */
    public static function gate_coupon_for_wholesale( $is_valid, $coupon, $discounts = null ) {
        if ( ! slw_is_wholesale_context() ) return $is_valid;
        if ( ! $is_valid ) return $is_valid;
        if ( ! is_object( $coupon ) || ! method_exists( $coupon, 'get_code' ) ) return $is_valid;

        // Admin override
        $allowed = get_post_meta( $coupon->get_id(), '_slw_wholesale_allowed', true );
        if ( $allowed === '1' ) return $is_valid;

        $code = strtoupper( (string) $coupon->get_code() );
        $wholesale_prefixes = array( 'WHOLESALE-', 'WS-', 'WHOLE-', 'B2B-' );
        foreach ( $wholesale_prefixes as $prefix ) {
            if ( strpos( $code, $prefix ) === 0 ) return $is_valid;
        }
        return false;
    }

    /**
     * Replace WC's generic "Coupon not valid" message with a wholesale-
     * specific explanation when our filter rejected.
     */
    public static function coupon_error_for_wholesale( $err, $err_code, $coupon ) {
        if ( ! slw_is_wholesale_context() ) return $err;
        if ( (int) $err_code !== WC_Coupon::E_WC_COUPON_INVALID_FILTERED ) return $err;
        return 'This coupon is for retail customers. Wholesale customers can use coupons with codes starting with WHOLESALE-, WS-, WHOLE-, or B2B-. Ask Holly if you need a wholesale-specific code.';
    }

    /**
     * Add a "Wholesale" column to the Users admin list so Holly can see who
     * is a wholesale customer at a glance.
     */
    public static function add_user_column( $columns ) {
        $columns['slw_wholesale'] = 'Wholesale';
        return $columns;
    }

    public static function render_user_column( $value, $column_name, $user_id ) {
        if ( $column_name !== 'slw_wholesale' ) return $value;
        if ( slw_is_wholesale_user( $user_id ) ) {
            $verified  = get_user_meta( $user_id, 'slw_resale_cert_verified', true ) === '1';
            $net_terms = class_exists( 'SLW_Gateway_Net30' ) ? SLW_Gateway_Net30::get_user_net_terms( $user_id ) : 0;
            $badges = array( '<span style="color:#386174;font-weight:600;">Wholesale</span>' );
            if ( $verified )    $badges[] = '<span style="color:#628393;">Tax Exempt</span>';
            if ( $net_terms > 0 ) $badges[] = '<span style="color:#D4AF37;">NET ' . esc_html( $net_terms ) . '</span>';
            return implode( '<br>', $badges );
        }
        return '<span style="color:#999;">Retail</span>';
    }

    /**
     * Show a "Sego Lily Wholesale" section on every user's profile page.
     * Lets Holly toggle: wholesale role on/off, resale cert verified, NET 30.
     * No need to wait for an application form submission to grant wholesale
     * access to a known customer.
     */
    public static function render_user_profile_section( $user ) {
        if ( ! current_user_can( 'edit_users' ) ) return;
        $is_wholesale = slw_is_wholesale_user( $user->ID );
        $resale_verified = get_user_meta( $user->ID, 'slw_resale_cert_verified', true ) === '1';
        // NET terms: read new meta first, fall back to legacy checkbox
        $net_terms = get_user_meta( $user->ID, 'slw_net_terms', true );
        if ( $net_terms === '' || $net_terms === false ) {
            $legacy_net30 = get_user_meta( $user->ID, 'slw_net30_approved', true );
            $net_terms = ( $legacy_net30 === '1' ) ? 30 : 0;
        }
        $net_terms = absint( $net_terms );
        $resale_number = SLW_Encryption::decrypt( get_user_meta( $user->ID, 'slw_resale_certificate_number', true ) );
        $ein_number    = SLW_Encryption::decrypt( get_user_meta( $user->ID, 'slw_ein', true ) );
        $parent_org    = get_user_meta( $user->ID, 'slw_parent_organization', true );
        ?>
        <h2>Wholesale Portal</h2>
        <table class="form-table">
            <tr>
                <th><label>Wholesale Status</label></th>
                <td>
                    <label><input type="checkbox" name="slw_is_wholesale" value="1" <?php checked( $is_wholesale ); ?> /> Wholesale Customer (50% off retail)</label>
                    <p class="description">Toggle this to manually promote a retail customer to wholesale, or demote back to retail.</p>
                </td>
            </tr>
            <tr>
                <th><label>Resale Certificate Verified</label></th>
                <td>
                    <label><input type="checkbox" name="slw_resale_cert_verified" value="1" <?php checked( $resale_verified ); ?> /> Tax Exempt (verified resale cert on file)</label>
                    <p class="description">Tick this once you have verified their resale certificate or EIN. WooCommerce will skip sales tax at checkout.</p>
                </td>
            </tr>
            <tr>
                <th><label>Order Minimums</label></th>
                <td>
                    <label><input type="checkbox" name="slw_min_exempt" value="1" <?php checked( get_user_meta( $user->ID, 'slw_min_exempt', true ) === '1' ); ?> /> Exempt from order minimums</label>
                    <p class="description">Skip per-product minimum quantities and case-pack requirements for this customer, so their order quantities are honored as-is. Use to grandfather a returning partner onto their original terms (e.g. ordering in 2s) instead of the new-customer minimum. Order-value minimums are not affected.</p>
                </td>
            </tr>
            <tr>
                <th><label for="slw_resale_certificate_number">Resale Certificate Number</label></th>
                <td>
                    <input type="text" id="slw_resale_certificate_number" name="slw_resale_certificate_number" value="<?php echo esc_attr( $resale_number ); ?>" class="regular-text" />
                </td>
            </tr>
            <tr>
                <th><label for="slw_ein">EIN Number</label></th>
                <td>
                    <input type="text" id="slw_ein" name="slw_ein" value="<?php echo esc_attr( $ein_number ); ?>" class="regular-text" placeholder="XX-XXXXXXX" />
                    <p class="description">Federal EIN. Stored encrypted at rest. Customer can also edit this from their wholesale portal account tab.</p>
                </td>
            </tr>
            <tr>
                <th><label for="slw_parent_organization">Parent Organization</label></th>
                <td>
                    <input type="text" id="slw_parent_organization" name="slw_parent_organization" value="<?php echo esc_attr( $parent_org ); ?>" class="regular-text" placeholder="e.g. Boutique X" list="slw_parent_org_suggestions" />
                    <?php
                    global $wpdb;
                    $existing_orgs = $wpdb->get_col( $wpdb->prepare(
                        "SELECT DISTINCT meta_value FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value != '' ORDER BY meta_value ASC LIMIT 100",
                        'slw_parent_organization'
                    ) );
                    if ( ! empty( $existing_orgs ) ) : ?>
                        <datalist id="slw_parent_org_suggestions">
                            <?php foreach ( $existing_orgs as $org ) : ?>
                                <option value="<?php echo esc_attr( $org ); ?>"></option>
                            <?php endforeach; ?>
                        </datalist>
                    <?php endif; ?>
                    <p class="description">Optional. Use when this account is one location of a multi-location customer. All accounts sharing the same parent organization will group together in the Customers admin and the new-organization filter dropdown. Free text. Type a new name or pick from existing organizations.</p>
                </td>
            </tr>
            <tr>
                <th><label for="slw_net_terms">NET Payment Terms</label></th>
                <td>
                    <select name="slw_net_terms" id="slw_net_terms">
                        <option value="0" <?php selected( $net_terms, 0 ); ?>>No NET terms</option>
                        <option value="30" <?php selected( $net_terms, 30 ); ?>>NET 30</option>
                        <option value="60" <?php selected( $net_terms, 60 ); ?>>NET 60</option>
                        <option value="90" <?php selected( $net_terms, 90 ); ?>>NET 90</option>
                    </select>
                    <p class="description">Grant this customer NET payment terms at checkout. They can place orders and pay within the selected number of days.</p>

                    <?php
                    // Live diagnostic: show whether this customer will actually
                    // see the NET gateway at checkout right now. Catches the
                    // common case where per-user terms are set but the global
                    // plugin toggle is off (gateway never registered with WC).
                    if ( $net_terms > 0 && $is_wholesale ) :
                        $global_enabled = (bool) get_option( 'slw_net30_enabled', false );
                        $ctx_pref       = get_user_meta( $user->ID, 'slw_preferred_context', true );
                        $ctx_blocked    = ( $ctx_pref === 'retail' );

                        if ( $global_enabled && ! $ctx_blocked ) {
                            $badge_bg    = '#e7f5ec'; $badge_fg = '#2e7d32'; $badge_border = '#b6dec1';
                            $badge_text  = '&#10004; NET ' . $net_terms . ' will appear at checkout for this customer.';
                        } else {
                            $badge_bg    = '#fff4e0'; $badge_fg = '#996800'; $badge_border = '#f0d9a8';
                            $reasons = array();
                            if ( ! $global_enabled ) {
                                $reasons[] = '<strong>The global "Enable NET Payment Terms" setting is OFF</strong> (Wholesale &rarr; Settings). Turn it on or the gateway will not be registered with WooCommerce.';
                            }
                            if ( $ctx_blocked ) {
                                $reasons[] = 'This user has manually chosen retail shopping mode (<code>slw_preferred_context = retail</code>). They will not see wholesale gateways until they switch back.';
                            }
                            $badge_text = '&#9888; NET ' . $net_terms . ' is granted but <strong>will not appear at checkout</strong>. Reason: ' . implode( ' Also: ', $reasons );
                        }
                        ?>
                        <p style="margin-top:10px;padding:10px 12px;background:<?php echo esc_attr( $badge_bg ); ?>;color:<?php echo esc_attr( $badge_fg ); ?>;border:1px solid <?php echo esc_attr( $badge_border ); ?>;border-radius:6px;font-size:13px;line-height:1.5;">
                            <?php echo wp_kses_post( $badge_text ); ?>
                        </p>
                    <?php endif; ?>
                </td>
            </tr>
        </table>
        <?php
    }

    public static function save_user_profile_section( $user_id ) {
        if ( ! current_user_can( 'edit_user', $user_id ) ) return;

        // Toggle the wholesale role
        $should_be_wholesale = ! empty( $_POST['slw_is_wholesale'] );
        $user = get_userdata( $user_id );
        $is_currently = slw_is_wholesale_user( $user_id );

        if ( $should_be_wholesale && ! $is_currently ) {
            $user->add_role( 'wholesale_customer' );
        } elseif ( ! $should_be_wholesale && $is_currently ) {
            $user->remove_role( 'wholesale_customer' );

            // Remove the wholesale-approved tag in Mautic so they can
            // re-enter the segment/campaign if reactivated later
            if ( class_exists( 'SLW_Webhooks' ) ) {
                SLW_Webhooks::remove_mautic_tag( $user->user_email, 'wholesale-approved' );
            }
        }

        update_user_meta( $user_id, 'slw_resale_cert_verified', ! empty( $_POST['slw_resale_cert_verified'] ) ? '1' : '0' );

        // Per-customer exemption from quantity minimums / case packs (grandfathering).
        update_user_meta( $user_id, 'slw_min_exempt', ! empty( $_POST['slw_min_exempt'] ) ? '1' : '0' );

        // Save NET terms (new dropdown) and keep legacy meta in sync
        $net_terms = absint( $_POST['slw_net_terms'] ?? 0 );
        if ( ! in_array( $net_terms, array( 0, 30, 60, 90 ), true ) ) {
            $net_terms = 0;
        }
        update_user_meta( $user_id, 'slw_net_terms', $net_terms );
        // Keep legacy slw_net30_approved in sync for backward compat
        update_user_meta( $user_id, 'slw_net30_approved', $net_terms > 0 ? '1' : '0' );

        update_user_meta( $user_id, 'slw_resale_certificate_number', SLW_Encryption::encrypt( sanitize_text_field( $_POST['slw_resale_certificate_number'] ?? '' ) ) );

        // EIN: keep encrypted to match the application/portal flow
        $ein_input = sanitize_text_field( wp_unslash( $_POST['slw_ein'] ?? '' ) );
        update_user_meta( $user_id, 'slw_ein', $ein_input === '' ? '' : SLW_Encryption::encrypt( $ein_input ) );

        // Parent Organization: free text, plain (not encrypted, used for grouping in admin)
        $parent_org_input = sanitize_text_field( wp_unslash( $_POST['slw_parent_organization'] ?? '' ) );
        update_user_meta( $user_id, 'slw_parent_organization', $parent_org_input );

        // Audit log: wholesale status change
        if ( $should_be_wholesale && ! $is_currently ) {
            $user_display = get_userdata( $user_id )->display_name ?? 'User #' . $user_id;
            SLW_Audit_Log::log( 'wholesale_status_changed', sprintf( 'Wholesale status granted for user %s', $user_display ) );
        } elseif ( ! $should_be_wholesale && $is_currently ) {
            $user_display = get_userdata( $user_id )->display_name ?? 'User #' . $user_id;
            SLW_Audit_Log::log( 'wholesale_status_changed', sprintf( 'Wholesale status revoked for user %s', $user_display ) );
        }
    }

    /**
     * Apply the wholesale price. Four-tier resolution priority:
     *   1. Per-product override (_slw_wholesale_price meta) -- admin-set per product (wins over everything)
     *   2. Category-level discount override (via slw_resolve_wholesale_price filter)
     *   3. Global discount percentage (default 50%)
     *   4. Original price unchanged if user is not wholesale
     *
     * The filter slw_resolve_wholesale_price is used so other modules
     * (like SLW_Premium_Features for category overrides) can plug in
     * without us modifying this function every time.
     */
    public static function apply_wholesale_price( $price, $product ) {
        if ( ! slw_is_wholesale_context() ) {
            return $price;
        }

        if ( $price === '' || $price === null ) {
            return $price;
        }

        $debug = ! empty( $_GET['slw_price_debug'] ) && current_user_can( 'manage_woocommerce' );

        // 1. Per-product override (highest priority)
        // Check the product itself, then fall back to parent for variations
        $override = $product->get_meta( '_slw_wholesale_price' );
        $override_source = 'product';
        if ( $override === '' || ! is_numeric( $override ) ) {
            $parent_id = $product->get_parent_id();
            if ( $parent_id ) {
                $override = get_post_meta( $parent_id, '_slw_wholesale_price', true );
                $override_source = 'parent';
            }
        }
        if ( $override !== '' && is_numeric( $override ) && (float) $override >= 0 ) {
            if ( $debug ) {
                error_log( sprintf(
                    '[SLW price] product %d (%s): override from %s = %s (input price: %s)',
                    $product->get_id(), $product->get_name(), $override_source, $override, $price
                ) );
            }
            return round( (float) $override, 2 );
        }

        // 2. Give other modules (category override, future tier rules) a
        // chance to resolve the price. Returning non-null short-circuits.
        $resolved = apply_filters( 'slw_resolve_wholesale_price', null, (float) $price, $product );
        if ( $resolved !== null && is_numeric( $resolved ) ) {
            if ( $debug ) {
                error_log( sprintf(
                    '[SLW price] product %d (%s): slw_resolve_wholesale_price filter returned %s',
                    $product->get_id(), $product->get_name(), $resolved
                ) );
            }
            return round( (float) $resolved, 2 );
        }

        // 3. Fall back to global percentage discount.
        // Resolve the TRUE retail base via slw_get_true_regular_price,
        // which now walks variations via raw _regular_price post meta
        // (direct SQL MAX). No filter recursion, no WC Subscriptions
        // pollution -- always returns MSRP / one-time price, never the
        // recurring rate.
        $base_price = function_exists( 'slw_get_true_regular_price' )
            ? (float) slw_get_true_regular_price( $product )
            : 0.0;
        if ( $base_price <= 0 ) {
            $regular = $product->get_regular_price();
            $base_price = ( $regular !== '' && is_numeric( $regular ) && (float) $regular > 0 )
                ? (float) $regular
                : (float) $price;
        }

        $discount = (float) slw_get_option( 'discount_percent', 50 );
        $multiplier = 1 - ( $discount / 100 );
        $final = round( $base_price * $multiplier, 2 );

        if ( $debug ) {
            error_log( sprintf(
                '[SLW price] product %d (%s): regular=%s, input_price=%s, base=%s, discount=%s%%, final=%s',
                $product->get_id(), $product->get_name(), $regular, $price, $base_price, $discount, $final
            ) );
        }

        return $final;
    }

    /**
     * Resolve a product's wholesale unit price WITHOUT requiring the current
     * request to be in wholesale context. Mirrors apply_wholesale_price's
     * resolution (per-product override -> slw_resolve_wholesale_price filter ->
     * global % discount off the true MSRP) so admin-created orders (the New
     * Wholesale Order screen) price lines exactly like the storefront does.
     *
     * @param WC_Product $product
     * @return float Wholesale unit price.
     */
    public static function price_for_product( $product ) {
        if ( ! $product instanceof WC_Product ) {
            return 0.0;
        }

        // 1. Per-product override (product, then parent for variations).
        $override = $product->get_meta( '_slw_wholesale_price' );
        if ( $override === '' || ! is_numeric( $override ) ) {
            $parent_id = $product->get_parent_id();
            if ( $parent_id ) {
                $override = get_post_meta( $parent_id, '_slw_wholesale_price', true );
            }
        }
        if ( $override !== '' && is_numeric( $override ) && (float) $override >= 0 ) {
            return round( (float) $override, 2 );
        }

        // True retail base (subscription-safe MSRP / one-time price).
        $base_price = function_exists( 'slw_get_true_regular_price' )
            ? (float) slw_get_true_regular_price( $product )
            : 0.0;
        if ( $base_price <= 0 ) {
            $regular = $product->get_regular_price();
            $base_price = ( $regular !== '' && is_numeric( $regular ) && (float) $regular > 0 )
                ? (float) $regular
                : (float) $product->get_price();
        }

        // 2. Category / tier override modules (same filter the engine uses).
        $resolved = apply_filters( 'slw_resolve_wholesale_price', null, $base_price, $product );
        if ( $resolved !== null && is_numeric( $resolved ) ) {
            return round( (float) $resolved, 2 );
        }

        // 3. Global percentage discount.
        $discount = (float) slw_get_option( 'discount_percent', 50 );
        return round( $base_price * ( 1 - ( $discount / 100 ) ), 2 );
    }

    /**
     * Keep the regular price intact so WooCommerce can show the strikethrough
     * comparison (retail price crossed out, wholesale price shown).
     */
    public static function keep_regular_price( $price, $product ) {
        return $price;
    }

    /**
     * Add the wholesale role to the variation price hash so WooCommerce
     * doesn't serve cached retail prices to wholesale users.
     */
    public static function variation_price_hash( $hash ) {
        $hash[] = slw_is_wholesale_context() ? 'wholesale' : 'retail';
        return $hash;
    }

    /**
     * Modify the displayed price HTML on product pages and catalog.
     * Wholesale users see: "<del>$40.00</del> Wholesale: $20.00"
     */
    public static function price_html( $price_html, $product ) {
        if ( ! slw_is_wholesale_context() ) {
            return $price_html;
        }

        // Strikethrough retail above the wholesale price. Use the helper so
        // variable-subscription variations report the one-time price (the
        // MAX-priced sibling), not the recurring rate stored in
        // _regular_price.
        if ( $product->is_type( 'simple' ) || $product->is_type( 'variation' ) ) {
            $regular = slw_get_true_regular_price( $product );
            $discount = (float) slw_get_option( 'discount_percent', 50 );
            $wholesale = ( $discount > 0 && $discount < 100 )
                ? round( $regular * ( 1 - $discount / 100 ), 2 )
                : $regular;

            if ( $regular > 0 ) {
                return '<del>' . wc_price( $regular ) . '</del> <span class="slw-wholesale-label">Wholesale: ' . wc_price( $wholesale ) . '</span>';
            }
        }

        return $price_html;
    }

    /**
     * Add "Wholesale Price" label in the cart line items.
     */
    public static function cart_item_price_label( $price_html, $cart_item, $cart_item_key ) {
        if ( slw_is_wholesale_context() ) {
            return '<span class="slw-wholesale-label">Wholesale: </span>' . $price_html;
        }
        return $price_html;
    }

    /**
     * Tell WooCommerce Subscriptions that a product is NOT a subscription
     * for wholesale users. This prevents the subscription add-to-cart form,
     * recurring billing, and "sign up" buttons from rendering. Products
     * are treated as one-time purchases.
     */
    public static function disable_subscription_behavior( $is_subscription, $product_id = 0, $product = null ) {
        if ( slw_is_wholesale_context() ) {
            return false;
        }
        return $is_subscription;
    }

    /**
     * Hide SATT/WCS subscription schemes on product pages for wholesale users.
     */
    public static function hide_subscription_schemes( $schemes, $product ) {
        if ( slw_is_wholesale_context() ) {
            return array();
        }
        return $schemes;
    }

    /**
     * Strip "from $X / month" subscription price strings for wholesale users.
     */
    public static function hide_subscription_price_string( $price_string, $product ) {
        if ( slw_is_wholesale_context() ) {
            return '';
        }
        return $price_string;
    }

    /**
     * Inject CSS to hide any remaining subscription UI elements for wholesale users.
     * Includes cart-page selectors so recurring totals + scheme prompts
     * don't bleed through on the wholesale cart/checkout.
     */
    public static function hide_subscription_css() {
        if ( ! slw_is_wholesale_context() || is_admin() ) {
            return;
        }
        // Output directly via wp_head so it works on ALL pages (product pages,
        // cart, checkout), not just pages where our plugin stylesheet is enqueued.
        add_action( 'wp_head', function() {
            echo '<style id="slw-hide-subscriptions">'
                . '.wcsatt-options-wrapper,'
                . '.wcsatt-options-prompt,'
                . '.subscription-details,'
                . '.woocommerce-subscription-price,'
                . '.subscription-price,'
                . '[data-wcsatt],'
                // Cart / checkout recurring totals
                . '.cart-subscription-details,'
                . '.recurring-total,'
                . '.recurring-totals,'
                . '.wc-subscriptions-recurring-totals,'
                . '.cart-recurring-totals,'
                . '.recurring-total-section,'
                . 'tr.recurring-total,'
                . 'tr.wcs-recurring-total,'
                . 'tr.cart-subscription-details,'
                // Subscription scheme line meta on cart line items
                . '.wc_payment_method_paypal_express_subscription_details,'
                . '.subscription-sign-up-fee,'
                . '.product-subscription-price,'
                // Hide retail side-cart / mini-cart popups...
                . '.elementor-menu-cart__main,'
                . '.elementor-menu-cart__container,'
                . '.elementor-menu-cart--shown .elementor-menu-cart__main,'
                . '.elementor-menu-cart--opened .elementor-menu-cart__main,'
                . '.widget_shopping_cart,'
                . '.woocommerce-mini-cart,'
                . '.mini-cart,'
                . '.cart-popup,'
                . '.cart-drawer,'
                . '.side-cart,'
                . '.wc-block-mini-cart__drawer,'
                // ...AND the cart icon itself. Wholesale customers have
                // the in-page Cart Preview + the wholesale checkout link
                // -- the retail header cart icon is dead UI for them and
                // its click-to-open behavior keeps competing with our
                // sticky bar. Just hide it. (LV directive 2026-05-29.)
                . '.elementor-menu-cart,'
                . '.elementor-menu-cart__wrapper,'
                . '.elementor-menu-cart__toggle,'
                . '.elementor-widget-woocommerce-menu-cart,'
                . '.wc-block-mini-cart,'
                . '.wc-block-mini-cart__button,'
                . '.wp-block-woocommerce-mini-cart,'
                . '.header-cart,'
                . '.header-cart-icon,'
                . '.header-cart-link,'
                . '.site-header .cart-icon,'
                . '.cart-toggle,'
                . '.shopping-cart-icon,'
                . 'a.cart-contents,'
                . '.menu-item-cart,'
                . 'a[href$="/cart/"],'
                . 'a[href$="/cart"]'
                . '{display:none!important;visibility:hidden!important;pointer-events:none!important}'
                // Without the cart icon next to it the member / account
                // icon collapses into the adjacent Shop CTA. Restore the
                // breathing room that the cart icon used to occupy by
                // adding margin to common account-icon widget selectors.
                . '.elementor-widget-woocommerce-my-account-page-title,'
                . '.elementor-icon-list-item:has(a[href*="account"]),'
                . '.elementor-icon-list-item:has(a[href*="portal"]),'
                . '.elementor-widget-icon-list a[href*="account"],'
                . '.elementor-widget-icon-list a[href*="portal"],'
                . '.header-account-icon,'
                . '.my-account-link,'
                . '.user-account-icon,'
                . '.account-icon,'
                . 'a[href$="/wholesale-portal/"],'
                . 'a[href$="/wholesale-portal"],'
                . 'a[href$="/my-account/"],'
                . 'a[href$="/my-account"]'
                . '{margin-left:18px!important}'
                . '</style>';
        } );
    }

    /**
     * Strip subscription scheme meta + recurring price hints from cart line
     * items for wholesale users. These come through as $cart_item_data
     * entries like "Subscription: Every Month" added by WCS/WCSATT.
     */
    public static function strip_subscription_item_data( $item_data, $cart_item ) {
        if ( ! slw_is_wholesale_context() ) {
            return $item_data;
        }
        return array_filter( (array) $item_data, function( $entry ) {
            $key   = strtolower( (string) ( $entry['key']   ?? '' ) );
            $value = strtolower( (string) ( $entry['value'] ?? '' ) );
            // Drop any entry whose label or value looks like subscription meta.
            $needles = array( 'subscription', 'recurring', 'every month', 'every year', 'every week', 'sign-up fee', 'sign up fee', 'billing' );
            foreach ( $needles as $needle ) {
                if ( strpos( $key, $needle ) !== false || strpos( $value, $needle ) !== false ) {
                    return false;
                }
            }
            return true;
        } );
    }

    /**
     * For wholesale users, drop the "(includes recurring)" annotation from
     * the cart order total HTML and show just the one-time charge.
     */
    public static function wholesale_cart_total_html( $html ) {
        if ( ! slw_is_wholesale_context() ) {
            return $html;
        }
        // Strip any text in parens that mentions "recurring" or "subscription".
        $html = preg_replace( '/\s*\([^)]*(recurring|subscription)[^)]*\)/i', '', $html );
        return $html;
    }

    /**
     * Wipe the recurring totals section entirely for wholesale users.
     */
    public static function strip_recurring_for_wholesale( $html ) {
        if ( ! slw_is_wholesale_context() ) {
            return $html;
        }
        return '';
    }

    /**
     * Tell WC the widget cart is hidden for wholesale users so the theme's
     * mini-cart / side-cart stays collapsed.
     */
    public static function hide_widget_cart_for_wholesale( $is_hidden ) {
        if ( slw_is_wholesale_context() ) {
            return true;
        }
        return $is_hidden;
    }

    /**
     * Empty the cart fragments AJAX response for wholesale users so themes
     * stop pushing live mini-cart HTML updates after each add-to-cart.
     */
    public static function maybe_empty_cart_fragments( $fragments ) {
        if ( slw_is_wholesale_context() ) {
            return array();
        }
        return $fragments;
    }

    /**
     * Intercept the jQuery 'added_to_cart' event for wholesale users so
     * themes that auto-open a side cart on that event stay closed.
     */
    public static function suppress_side_cart_js() {
        // The cart icon is hidden entirely via CSS for wholesale users
        // (see hide_subscription_css). There's nothing left to coordinate
        // with -- skip all JS. The previous MutationObserver on
        // documentElement subtree was a perf killer on Elementor pages
        // (fires for every class change in any descendant). Gone.
        return;
    }

    /**
     * Redirect "Return to shop" button to the wholesale portal order form.
     */
    public static function wholesale_return_to_shop( $url ) {
        if ( slw_is_wholesale_context() ) {
            return home_url( '/wholesale-portal/?tab=orders' );
        }
        return $url;
    }

    /**
     * Add a "Continue Shopping" link after the add-to-cart button for wholesale users.
     */
    public static function continue_shopping_link() {
        if ( ! slw_is_wholesale_context() ) {
            return;
        }
        // Rendered as a centered block under the Add to Cart button. Points
        // to the wholesale order form (browse more products) not the orders
        // history tab, which is what "Continue Shopping" actually implies.
        echo '<div class="slw-continue-shopping-wrap" style="display:block;width:100%;text-align:center;margin:14px 0 4px;">';
        echo '<a href="' . esc_url( home_url( '/wholesale-order' ) ) . '" class="slw-continue-shopping" style="display:inline-block;color:#386174;text-decoration:none;font-size:14px;font-weight:600;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Helvetica,Arial,sans-serif;border-bottom:1px solid transparent;transition:border-color 0.15s;">&larr; Continue shopping</a>';
        echo '</div>';
    }

    /**
     * Add "Grant NET 30" to the Users list bulk-actions dropdown.
     */
    public static function add_bulk_net30_action( $actions ) {
        $actions['slw_grant_net30'] = 'Grant NET 30 (skip first-order requirement)';
        return $actions;
    }

    /**
     * Handle the bulk NET 30 grant action. Sets the three user-meta keys
     * needed so the selected wholesale users qualify for NET 30 immediately
     * without placing a qualifying first order.
     */
    public static function handle_bulk_net30_action( $redirect_to, $action, $user_ids ) {
        if ( $action !== 'slw_grant_net30' ) {
            return $redirect_to;
        }
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return $redirect_to;
        }
        $count = 0;
        foreach ( $user_ids as $user_id ) {
            if ( ! slw_is_wholesale_user( $user_id ) ) {
                continue;
            }
            update_user_meta( $user_id, 'slw_first_order_placed', current_time( 'mysql' ) );
            update_user_meta( $user_id, 'slw_net_terms', 30 );
            update_user_meta( $user_id, 'slw_net30_approved', '1' );
            $count++;
        }
        return add_query_arg( 'slw_net30_granted', $count, $redirect_to );
    }

    /**
     * Show a success notice after the bulk NET 30 action completes.
     */
    public static function bulk_net30_notice() {
        if ( empty( $_GET['slw_net30_granted'] ) ) {
            return;
        }
        $count = absint( $_GET['slw_net30_granted'] );
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $count ) . ' wholesale account(s) granted NET 30 terms (first-order requirement skipped).</p></div>';
    }
}
