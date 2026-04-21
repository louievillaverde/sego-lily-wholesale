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

        // Per-product price override (admin can set a custom wholesale price per product)
        add_action( 'woocommerce_product_options_pricing', array( __CLASS__, 'add_product_pricing_field' ) );
        add_action( 'woocommerce_admin_process_product_object', array( __CLASS__, 'save_product_pricing_field' ) );

        // Admin user list column showing wholesale status
        add_filter( 'manage_users_columns', array( __CLASS__, 'add_user_column' ) );
        add_filter( 'manage_users_custom_column', array( __CLASS__, 'render_user_column' ), 10, 3 );

        // Manual promote/demote on user profile
        add_action( 'show_user_profile', array( __CLASS__, 'render_user_profile_section' ) );
        add_action( 'edit_user_profile', array( __CLASS__, 'render_user_profile_section' ) );
        add_action( 'personal_options_update', array( __CLASS__, 'save_user_profile_section' ) );
        add_action( 'edit_user_profile_update', array( __CLASS__, 'save_user_profile_section' ) );
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
        if ( ! slw_is_wholesale_user( $user_id ) ) return $is_exempt;
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
        if ( ! is_admin() && slw_is_wholesale_user() ) {
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
        if ( slw_is_wholesale_user() ) return;  // wholesale users see everything
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
    }

    /**
     * Same wholesale-only filter applied at the WooCommerce query level
     * (catches the shop page, search results, related products).
     */
    public static function filter_wholesale_only_meta( $meta_query, $query ) {
        if ( is_admin() || slw_is_wholesale_user() ) return $meta_query;
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
        woocommerce_wp_text_input( array(
            'id'          => '_slw_wholesale_price',
            'label'       => 'Wholesale Price (' . get_woocommerce_currency_symbol() . ')',
            'desc_tip'    => true,
            'description' => 'Override the default wholesale discount for this product. Leave blank to use the global discount.',
            'type'        => 'number',
            'custom_attributes' => array( 'step' => '0.01', 'min' => '0' ),
        ));
        woocommerce_wp_checkbox( array(
            'id'          => '_slw_wholesale_only',
            'label'       => 'Wholesale only',
            'description' => 'Hide this product from retail customers. Only wholesale users can see and buy it.',
        ));
        woocommerce_wp_text_input( array(
            'id'          => '_slw_tiered_pricing',
            'label'       => 'Tiered Pricing (wholesale)',
            'desc_tip'    => true,
            'description' => 'Quantity:price pairs, comma-separated. Example: 12:15.00,24:12.00,48:10.00 means 12+ = $15 each, 24+ = $12 each, 48+ = $10 each. Applied to wholesale users only. Leave blank to use the standard wholesale price.',
            'placeholder' => '12:15.00,24:12.00,48:10.00',
        ));
    }

    public static function save_product_pricing_field( $product ) {
        $price = isset( $_POST['_slw_wholesale_price'] ) ? wc_clean( $_POST['_slw_wholesale_price'] ) : '';
        $only  = isset( $_POST['_slw_wholesale_only'] ) ? '1' : '0';
        $product->update_meta_data( '_slw_wholesale_price', $price );
        $product->update_meta_data( '_slw_wholesale_only', $only );

        // Tiered pricing: "qty1:price1,qty2:price2" format
        // Example: "12:15.00,24:12.00,48:10.00" means 12+ = $15 each, 24+ = $12, 48+ = $10
        $tiers = isset( $_POST['_slw_tiered_pricing'] ) ? wc_clean( $_POST['_slw_tiered_pricing'] ) : '';
        $product->update_meta_data( '_slw_tiered_pricing', $tiers );
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
        if ( ! slw_is_wholesale_user() ) return;

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
        $resale_number = get_user_meta( $user->ID, 'slw_resale_certificate_number', true );
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
                <th><label for="slw_resale_certificate_number">Resale Certificate Number</label></th>
                <td>
                    <input type="text" id="slw_resale_certificate_number" name="slw_resale_certificate_number" value="<?php echo esc_attr( $resale_number ); ?>" class="regular-text" />
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
        }

        update_user_meta( $user_id, 'slw_resale_cert_verified', ! empty( $_POST['slw_resale_cert_verified'] ) ? '1' : '0' );

        // Save NET terms (new dropdown) and keep legacy meta in sync
        $net_terms = absint( $_POST['slw_net_terms'] ?? 0 );
        if ( ! in_array( $net_terms, array( 0, 30, 60, 90 ), true ) ) {
            $net_terms = 0;
        }
        update_user_meta( $user_id, 'slw_net_terms', $net_terms );
        // Keep legacy slw_net30_approved in sync for backward compat
        update_user_meta( $user_id, 'slw_net30_approved', $net_terms > 0 ? '1' : '0' );

        update_user_meta( $user_id, 'slw_resale_certificate_number', sanitize_text_field( $_POST['slw_resale_certificate_number'] ?? '' ) );
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
        if ( ! slw_is_wholesale_user() || is_admin() ) {
            return $price;
        }

        if ( $price === '' || $price === null ) {
            return $price;
        }

        // 1. Per-product override (highest priority)
        $override = $product->get_meta( '_slw_wholesale_price' );
        if ( $override !== '' && is_numeric( $override ) && (float) $override >= 0 ) {
            return round( (float) $override, 2 );
        }

        // 2. Give other modules (category override, future tier rules) a
        // chance to resolve the price. Returning non-null short-circuits.
        $resolved = apply_filters( 'slw_resolve_wholesale_price', null, (float) $price, $product );
        if ( $resolved !== null && is_numeric( $resolved ) ) {
            return round( (float) $resolved, 2 );
        }

        // 3. Fall back to global percentage discount
        $discount = (float) slw_get_option( 'discount_percent', 50 );
        $multiplier = 1 - ( $discount / 100 );

        return round( (float) $price * $multiplier, 2 );
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
        $hash[] = slw_is_wholesale_user() ? 'wholesale' : 'retail';
        return $hash;
    }

    /**
     * Modify the displayed price HTML on product pages and catalog.
     * Wholesale users see: "<del>$40.00</del> Wholesale: $20.00"
     */
    public static function price_html( $price_html, $product ) {
        if ( ! slw_is_wholesale_user() || is_admin() ) {
            return $price_html;
        }

        // For simple products, show the retail price struck through
        if ( $product->is_type( 'simple' ) || $product->is_type( 'variation' ) ) {
            $regular = (float) $product->get_regular_price();
            $discount = (float) slw_get_option( 'discount_percent', 50 );
            $wholesale = round( $regular * ( 1 - $discount / 100 ), 2 );

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
        if ( slw_is_wholesale_user() ) {
            return '<span class="slw-wholesale-label">Wholesale: </span>' . $price_html;
        }
        return $price_html;
    }
}
