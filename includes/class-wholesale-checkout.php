<?php
/**
 * Custom Wholesale Checkout
 *
 * Replaces the standard WC checkout for wholesale customers. We render
 * line items at OUR computed wholesale prices (lifted directly from
 * apply_wholesale_price), build a brand-styled form, and submit a WC
 * order with explicit line totals so the displayed price matches the
 * stored order amount exactly.
 *
 * Why: the native WC checkout (Elementor or shortcode) was producing
 * line totals that didn't match the order form's subtotal because the
 * subscription plugin filter chain re-injected itself during checkout
 * render. Going all-in on a custom flow eliminates the price drift.
 *
 * Shortcode: [sego_wholesale_checkout]
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class SLW_Wholesale_Checkout {

    public static function init() {
        add_shortcode( 'sego_wholesale_checkout', array( __CLASS__, 'render' ) );
        add_action( 'admin_post_slw_place_wholesale_order', array( __CLASS__, 'handle_place_order' ) );
        add_action( 'admin_post_nopriv_slw_place_wholesale_order', array( __CLASS__, 'handle_place_order' ) );
    }

    /**
     * Read the per-line wholesale price + true retail price for an
     * individual cart line. Wholesale price uses the same logic the
     * order form Cart Preview uses (filter active). Retail price is
     * read with the wholesale filter temporarily off so it reflects
     * the true MSRP, not the wholesale-discounted number.
     */
    private static function line_prices( $cart_item ) {
        $product = $cart_item['data'] ?? null;
        if ( ! $product ) {
            return array( 'wholesale' => 0.0, 'retail' => 0.0 );
        }
        $qty = (int) ( $cart_item['quantity'] ?? 0 );
        // Wholesale (filtered) price -- what the customer will be charged
        $wholesale = (float) $product->get_price() * $qty;
        // True retail with our filter explicitly OFF
        $had_simple_filter    = remove_filter( 'woocommerce_product_get_price', array( 'SLW_Wholesale_Role', 'apply_wholesale_price' ), 99 );
        $had_variation_filter = remove_filter( 'woocommerce_product_variation_get_price', array( 'SLW_Wholesale_Role', 'apply_wholesale_price' ), 99 );
        $retail_per_unit = function_exists( 'slw_get_true_regular_price' )
            ? slw_get_true_regular_price( $product )
            : (float) $product->get_regular_price();
        if ( $had_simple_filter )    add_filter( 'woocommerce_product_get_price', array( 'SLW_Wholesale_Role', 'apply_wholesale_price' ), 99, 2 );
        if ( $had_variation_filter ) add_filter( 'woocommerce_product_variation_get_price', array( 'SLW_Wholesale_Role', 'apply_wholesale_price' ), 99, 2 );
        $retail = (float) $retail_per_unit * $qty;
        return array(
            'wholesale'      => round( $wholesale, 2 ),
            'retail'         => round( $retail, 2 ),
            'wholesale_unit' => round( (float) $product->get_price(), 2 ),
            'retail_unit'    => round( (float) $retail_per_unit, 2 ),
            'qty'            => $qty,
        );
    }

    /**
     * Build the order summary (line items + totals) used both in the
     * sidebar render and the order-creation submission path.
     */
    private static function build_summary() {
        $items = array();
        $sub_wholesale = 0;
        $sub_retail    = 0;
        if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
            return array( 'items' => $items, 'subtotal' => 0, 'retail_subtotal' => 0, 'savings' => 0, 'discount_pct' => 0 );
        }
        foreach ( WC()->cart->get_cart() as $key => $cart_item ) {
            $prices  = self::line_prices( $cart_item );
            $product = $cart_item['data'];
            $items[] = array(
                'key'             => $key,
                'product_id'      => (int) ( $cart_item['product_id']   ?? 0 ),
                'variation_id'    => (int) ( $cart_item['variation_id'] ?? 0 ),
                'name'            => $product->get_name(),
                'sku'             => $product->get_sku(),
                'image_id'        => $product->get_image_id() ?: ( $product->get_parent_id() ? get_post_thumbnail_id( $product->get_parent_id() ) : 0 ),
                'qty'             => $prices['qty'],
                'wholesale_unit'  => $prices['wholesale_unit'],
                'retail_unit'     => $prices['retail_unit'],
                'wholesale_total' => $prices['wholesale'],
                'retail_total'    => $prices['retail'],
            );
            $sub_wholesale += $prices['wholesale'];
            $sub_retail    += $prices['retail'];
        }
        $savings = max( 0, $sub_retail - $sub_wholesale );
        $pct     = $sub_retail > 0 ? round( ( $savings / $sub_retail ) * 100, 1 ) : 0;
        return array(
            'items'           => $items,
            'subtotal'        => round( $sub_wholesale, 2 ),
            'retail_subtotal' => round( $sub_retail, 2 ),
            'savings'         => round( $savings, 2 ),
            'discount_pct'    => $pct,
        );
    }

    public static function render() {
        // Enqueue WC's checkout script + each available gateway's payment
        // scripts so the native payment-method UI works exactly as it does
        // on the standard /checkout (Stripe card iframes, NET 30 confirm,
        // etc. all attach to the standard #payment selector + radios).
        if ( function_exists( 'wp_enqueue_script' ) ) {
            wp_enqueue_script( 'wc-checkout' );
            if ( function_exists( 'WC' ) && WC()->payment_gateways ) {
                foreach ( WC()->payment_gateways->get_available_payment_gateways() as $gateway ) {
                    if ( method_exists( $gateway, 'payment_scripts' ) ) {
                        $gateway->payment_scripts();
                    }
                }
            }
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

        $summary = self::build_summary();
        $user    = wp_get_current_user();
        $uid     = $user->ID;

        // Make sure shipping rates are calculated against the user's saved address.
        $ship_zip     = get_user_meta( $uid, 'shipping_postcode', true );
        $ship_country = get_user_meta( $uid, 'shipping_country',  true ) ?: 'US';
        $ship_state   = get_user_meta( $uid, 'shipping_state',    true );

        if ( WC()->customer ) {
            WC()->customer->set_shipping_country( $ship_country );
            WC()->customer->set_shipping_state( $ship_state );
            WC()->customer->set_shipping_postcode( $ship_zip );
        }
        WC()->cart->calculate_shipping();
        WC()->cart->calculate_totals();
        $available_shipping = WC()->shipping ? WC()->shipping->get_packages() : array();

        $chosen_methods = WC()->session ? WC()->session->get( 'chosen_shipping_methods', array() ) : array();
        $chosen_method  = $chosen_methods[0] ?? '';

        $available_gateways = WC()->payment_gateways ? WC()->payment_gateways->get_available_payment_gateways() : array();
        $default_gateway    = WC()->session ? WC()->session->get( 'chosen_payment_method', '' ) : '';
        if ( ! $default_gateway && ! empty( $available_gateways ) ) {
            $default_gateway = current( array_keys( $available_gateways ) );
        }

        $shipping_total = (float) WC()->cart->get_shipping_total();
        $tax_total      = (float) WC()->cart->get_total_tax();
        $grand_total    = $summary['subtotal'] + $shipping_total + $tax_total;

        ob_start();
        $business_name = get_user_meta( $uid, 'slw_business_name', true );
        $owner_phone   = class_exists( 'SLW_Email_Settings' ) ? SLW_Email_Settings::get( 'from_phone' ) : '';
        $owner_email   = class_exists( 'SLW_Email_Settings' ) ? SLW_Email_Settings::get( 'from_address' ) : get_option( 'admin_email' );
        ?>
        <style>
            /* Hide the WP page title that renders above the shortcode --
               we already render our own styled "Wholesale Checkout" h1
               inside the shortcode's header. Avoids the duplicate title. */
            body.page .entry-title:not(.slw-balance),
            body.page .wp-block-post-title:not(.slw-balance),
            body.page .elementor-page-title__title,
            body.page header.entry-header > h1 { display: none !important; }
        </style>
        <div class="slw-wholesale-checkout">
            <div class="slw-wc-header">
                <h1 class="slw-balance">Wholesale Checkout</h1>
                <p class="slw-pretty">One last step to ship your order. Prices below reflect your wholesale rate.</p>
                <a class="slw-wc-back" href="<?php echo esc_url( home_url( '/wholesale-order' ) ); ?>">&larr; Back to the order form</a>
            </div>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="slw-wc-grid checkout woocommerce-checkout" id="slw-wholesale-checkout-form">
                <?php wp_nonce_field( 'slw_place_wholesale_order' ); ?>
                <input type="hidden" name="action" value="slw_place_wholesale_order" />

                <!-- LEFT: form fields -->
                <div class="slw-wc-form">
                    <section class="slw-wc-section">
                        <h2 class="slw-wc-section__title">Contact</h2>
                        <div class="slw-wc-fields">
                            <label class="slw-wc-field slw-wc-field--full">
                                <span>Business name</span>
                                <input type="text" name="business_name" value="<?php echo esc_attr( $business_name ); ?>" />
                            </label>
                            <label class="slw-wc-field">
                                <span>First name *</span>
                                <input type="text" name="first_name" value="<?php echo esc_attr( $user->first_name ); ?>" required />
                            </label>
                            <label class="slw-wc-field">
                                <span>Last name *</span>
                                <input type="text" name="last_name" value="<?php echo esc_attr( $user->last_name ); ?>" required />
                            </label>
                            <label class="slw-wc-field">
                                <span>Email *</span>
                                <input type="email" name="email" value="<?php echo esc_attr( $user->user_email ); ?>" required />
                            </label>
                            <label class="slw-wc-field">
                                <span>Phone</span>
                                <input type="text" name="phone" value="<?php echo esc_attr( get_user_meta( $uid, 'billing_phone', true ) ); ?>" />
                            </label>
                        </div>
                    </section>

                    <section class="slw-wc-section">
                        <h2 class="slw-wc-section__title">Shipping Address</h2>
                        <div class="slw-wc-fields">
                            <label class="slw-wc-field slw-wc-field--full">
                                <span>Address line 1 *</span>
                                <input type="text" name="shipping_address_1" value="<?php echo esc_attr( get_user_meta( $uid, 'shipping_address_1', true ) ); ?>" required />
                            </label>
                            <label class="slw-wc-field slw-wc-field--full">
                                <span>Address line 2</span>
                                <input type="text" name="shipping_address_2" value="<?php echo esc_attr( get_user_meta( $uid, 'shipping_address_2', true ) ); ?>" />
                            </label>
                            <label class="slw-wc-field">
                                <span>City *</span>
                                <input type="text" name="shipping_city" value="<?php echo esc_attr( get_user_meta( $uid, 'shipping_city', true ) ); ?>" required />
                            </label>
                            <label class="slw-wc-field">
                                <span>State *</span>
                                <input type="text" name="shipping_state" value="<?php echo esc_attr( $ship_state ); ?>" required />
                            </label>
                            <label class="slw-wc-field">
                                <span>Postcode *</span>
                                <input type="text" name="shipping_postcode" value="<?php echo esc_attr( $ship_zip ); ?>" required />
                            </label>
                            <label class="slw-wc-field">
                                <span>Country</span>
                                <input type="text" name="shipping_country" value="<?php echo esc_attr( $ship_country ); ?>" />
                            </label>
                        </div>
                        <label class="slw-wc-checkbox slw-wc-checkbox--section-footer">
                            <input type="checkbox" name="billing_same_as_shipping" value="1" checked />
                            <span>Billing address is the same as shipping</span>
                        </label>
                    </section>

                    <section class="slw-wc-section slw-wc-billing-section" hidden>
                        <h2 class="slw-wc-section__title">Billing Address</h2>
                        <div class="slw-wc-fields slw-wc-billing-fields">
                            <label class="slw-wc-field slw-wc-field--full">
                                <span>Address line 1</span>
                                <input type="text" name="billing_address_1" value="<?php echo esc_attr( get_user_meta( $uid, 'billing_address_1', true ) ); ?>" />
                            </label>
                            <label class="slw-wc-field slw-wc-field--full">
                                <span>Address line 2</span>
                                <input type="text" name="billing_address_2" value="<?php echo esc_attr( get_user_meta( $uid, 'billing_address_2', true ) ); ?>" />
                            </label>
                            <label class="slw-wc-field">
                                <span>City</span>
                                <input type="text" name="billing_city" value="<?php echo esc_attr( get_user_meta( $uid, 'billing_city', true ) ); ?>" />
                            </label>
                            <label class="slw-wc-field">
                                <span>State</span>
                                <input type="text" name="billing_state" value="<?php echo esc_attr( get_user_meta( $uid, 'billing_state', true ) ); ?>" />
                            </label>
                            <label class="slw-wc-field">
                                <span>Postcode</span>
                                <input type="text" name="billing_postcode" value="<?php echo esc_attr( get_user_meta( $uid, 'billing_postcode', true ) ); ?>" />
                            </label>
                            <label class="slw-wc-field">
                                <span>Country</span>
                                <input type="text" name="billing_country" value="<?php echo esc_attr( get_user_meta( $uid, 'billing_country', true ) ?: 'US' ); ?>" />
                            </label>
                        </div>
                    </section>

                    <section class="slw-wc-section">
                        <h2 class="slw-wc-section__title">Shipping</h2>
                        <div class="slw-wc-radios">
                            <?php if ( ! empty( $available_shipping ) ) :
                                $rate_count = 0;
                                foreach ( $available_shipping as $pkg ) { $rate_count += count( $pkg['rates'] ); }
                                if ( $rate_count > 0 ) :
                                    foreach ( $available_shipping as $package_id => $package ) :
                                        foreach ( $package['rates'] as $rate_id => $rate ) :
                            ?>
                                <label class="slw-wc-radio">
                                    <input type="radio" name="shipping_method" value="<?php echo esc_attr( $rate_id ); ?>" <?php checked( $rate_id, $chosen_method ); ?> />
                                    <span class="slw-wc-radio__label">
                                        <strong><?php echo esc_html( $rate->get_label() ); ?></strong>
                                        <span class="slw-wc-radio__cost"><?php echo wp_kses_post( wc_price( $rate->get_cost() ) ); ?></span>
                                    </span>
                                </label>
                            <?php
                                        endforeach;
                                    endforeach;
                                else : ?>
                                    <div class="slw-wc-note slw-wc-note--info">
                                        <strong>Shipping calculated separately.</strong>
                                        <span>We'll weigh your packed order and invoice the actual carrier rate after pick-and-pack. Most wholesale orders ship via UPS Ground or USPS Priority.</span>
                                    </div>
                                <?php endif;
                            else : ?>
                                <div class="slw-wc-note slw-wc-note--info">
                                    <strong>Shipping calculated separately.</strong>
                                    <span>We'll weigh your packed order and invoice the actual carrier rate after pick-and-pack. Most wholesale orders ship via UPS Ground or USPS Priority.</span>
                                </div>
                            <?php endif; ?>
                        </div>
                    </section>

                    <section class="slw-wc-section">
                        <h2 class="slw-wc-section__title">Payment</h2>
                        <?php
                        // Use WooCommerce's NATIVE payment method rendering --
                        // the same wc_get_template that the standard checkout
                        // uses. Wrapped in #payment + .woocommerce-checkout-
                        // payment so every gateway's JS (Stripe iframes,
                        // NET 30 confirm, etc.) attaches to the structure
                        // it expects.
                        if ( ! empty( $available_gateways ) ) :
                            WC()->payment_gateways()->set_current_gateway( $available_gateways );
                            ?>
                            <div id="payment" class="woocommerce-checkout-payment slw-wc-payment-wrap">
                                <ul class="wc_payment_methods payment_methods methods">
                                    <?php foreach ( $available_gateways as $gateway ) {
                                        wc_get_template( 'checkout/payment-method.php', array( 'gateway' => $gateway ) );
                                    } ?>
                                </ul>
                            </div>
                        <?php else : ?>
                            <p class="slw-wc-note">No payment methods available. Contact <?php echo esc_html( $owner_email ); ?>.</p>
                        <?php endif; ?>
                    </section>

                    <section class="slw-wc-section">
                        <h2 class="slw-wc-section__title">Packing &amp; Delivery Notes</h2>
                        <textarea name="order_notes" rows="3" placeholder="Special packing requests, delivery window, dock instructions, PO number, anything we should know to pack and ship correctly."></textarea>
                    </section>

                    <div class="slw-wc-place-order">
                        <button type="submit" class="slw-btn slw-wc-place-btn">
                            <span class="slw-wc-place-btn__label">Place Wholesale Order</span>
                            <span class="slw-wc-place-btn__total"><?php echo wp_kses_post( wc_price( $grand_total ) ); ?></span>
                        </button>
                        <p class="slw-wc-fine-print">By placing this order you agree to your wholesale partnership terms with <?php echo esc_html( get_bloginfo( 'name' ) ); ?>.</p>
                    </div>
                </div>

                <!-- RIGHT: order summary -->
                <aside class="slw-wc-summary">
                    <h2 class="slw-wc-summary__title">Order Summary</h2>
                    <ul class="slw-wc-line-items">
                        <?php foreach ( $summary['items'] as $item ) :
                            $img = $item['image_id'] ? wp_get_attachment_image_url( $item['image_id'], 'thumbnail' ) : wc_placeholder_img_src( 'thumbnail' );
                        ?>
                        <li class="slw-wc-line">
                            <img class="slw-wc-line__img" src="<?php echo esc_url( $img ); ?>" alt="" />
                            <div class="slw-wc-line__body">
                                <div class="slw-wc-line__name"><?php echo esc_html( $item['name'] ); ?></div>
                                <div class="slw-wc-line__meta">
                                    <span><?php echo (int) $item['qty']; ?> &times; <?php echo wp_kses_post( wc_price( $item['wholesale_unit'] ) ); ?></span>
                                    <?php if ( $item['retail_unit'] > $item['wholesale_unit'] ) : ?>
                                        <span class="slw-wc-line__retail-strike"><?php echo wp_kses_post( wc_price( $item['retail_unit'] ) ); ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="slw-wc-line__total"><?php echo wp_kses_post( wc_price( $item['wholesale_total'] ) ); ?></div>
                        </li>
                        <?php endforeach; ?>
                    </ul>

                    <dl class="slw-wc-totals">
                        <div class="slw-wc-totals__row">
                            <dt>Subtotal</dt>
                            <dd><?php echo wp_kses_post( wc_price( $summary['subtotal'] ) ); ?></dd>
                        </div>
                        <?php if ( $summary['savings'] > 0 ) : ?>
                        <div class="slw-wc-totals__row slw-wc-totals__row--savings">
                            <dt>Wholesale savings vs MSRP</dt>
                            <dd><?php echo wp_kses_post( wc_price( $summary['savings'] ) ); ?> <span class="slw-wc-totals__pct">(<?php echo esc_html( $summary['discount_pct'] ); ?>% off)</span></dd>
                        </div>
                        <?php endif; ?>
                        <div class="slw-wc-totals__row">
                            <dt>Shipping</dt>
                            <dd><?php echo $shipping_total > 0 ? wp_kses_post( wc_price( $shipping_total ) ) : '<em>Invoiced after pack-out</em>'; ?></dd>
                        </div>
                        <?php if ( $tax_total > 0 ) : ?>
                        <div class="slw-wc-totals__row">
                            <dt>Tax</dt>
                            <dd><?php echo wp_kses_post( wc_price( $tax_total ) ); ?></dd>
                        </div>
                        <?php endif; ?>
                        <div class="slw-wc-totals__row slw-wc-totals__row--grand">
                            <dt>Total</dt>
                            <dd><?php echo wp_kses_post( wc_price( $grand_total ) ); ?></dd>
                        </div>
                    </dl>

                    <p class="slw-wc-help">Need help? Contact <a href="mailto:<?php echo esc_attr( $owner_email ); ?>"><?php echo esc_html( $owner_email ); ?></a><?php if ( $owner_phone ) : ?> &middot; <?php echo esc_html( $owner_phone ); ?><?php endif; ?>.</p>
                </aside>
            </form>
        </div>

        <script>
        (function() {
            // Toggle the entire Billing Address section based on the
            // "same as shipping" checkbox so an empty card never sits in
            // the form when billing is mirrored.
            var box = document.querySelector('.slw-wholesale-checkout input[name="billing_same_as_shipping"]');
            var billingSection = document.querySelector('.slw-wc-billing-section');
            function sync() { if (billingSection) billingSection.hidden = box.checked; }
            if (box) {
                box.addEventListener('change', sync);
                sync();
            }

            // WC's native payment-method.php handles its own field
            // visibility (.payment_box auto-toggles via wc-checkout.js).
            // Recompute shipping when zip / state changes -- AJAX hook
            // into WC's update_order_review endpoint so radios refresh.
            var shippingPostcode = document.querySelector('.slw-wholesale-checkout input[name="shipping_postcode"]');
            var shippingState    = document.querySelector('.slw-wholesale-checkout input[name="shipping_state"]');
            function triggerWcUpdate() {
                if (typeof window.jQuery !== 'undefined' && jQuery.fn.trigger) {
                    jQuery(document.body).trigger('update_checkout');
                }
            }
            if (shippingPostcode) shippingPostcode.addEventListener('blur', triggerWcUpdate);
            if (shippingState)    shippingState.addEventListener('blur', triggerWcUpdate);
        })();
        </script>
        <?php
        return ob_get_clean();
    }

    /**
     * Submit handler. Builds a real WC order with EXPLICIT line totals
     * lifted from our summary build so the saved order amount matches
     * what the customer was shown on the order form. Avoids letting WC
     * recompute prices through filter chains that could drift.
     */
    public static function handle_place_order() {
        check_admin_referer( 'slw_place_wholesale_order' );
        if ( ! is_user_logged_in() ) {
            wp_die( 'Sign in to place an order.', 'Unauthorized', array( 'response' => 401 ) );
        }
        $uid = get_current_user_id();
        if ( ! slw_is_wholesale_user( $uid ) ) {
            wp_die( 'Wholesale customers only.', 'Forbidden', array( 'response' => 403 ) );
        }
        if ( ! function_exists( 'WC' ) || ! WC()->cart || WC()->cart->is_empty() ) {
            wp_safe_redirect( home_url( '/wholesale-order' ) );
            exit;
        }

        // Pull form values
        $first    = sanitize_text_field( wp_unslash( $_POST['first_name'] ?? '' ) );
        $last     = sanitize_text_field( wp_unslash( $_POST['last_name']  ?? '' ) );
        $email    = sanitize_email( wp_unslash( $_POST['email']          ?? '' ) );
        $phone    = sanitize_text_field( wp_unslash( $_POST['phone']     ?? '' ) );
        $company  = sanitize_text_field( wp_unslash( $_POST['business_name'] ?? '' ) );
        $ship     = array(
            'address_1' => sanitize_text_field( wp_unslash( $_POST['shipping_address_1'] ?? '' ) ),
            'address_2' => sanitize_text_field( wp_unslash( $_POST['shipping_address_2'] ?? '' ) ),
            'city'      => sanitize_text_field( wp_unslash( $_POST['shipping_city']      ?? '' ) ),
            'state'     => sanitize_text_field( wp_unslash( $_POST['shipping_state']     ?? '' ) ),
            'postcode'  => sanitize_text_field( wp_unslash( $_POST['shipping_postcode']  ?? '' ) ),
            'country'   => sanitize_text_field( wp_unslash( $_POST['shipping_country']   ?? 'US' ) ),
        );
        $billing_same = ! empty( $_POST['billing_same_as_shipping'] );
        $billing = $billing_same ? $ship : array(
            'address_1' => sanitize_text_field( wp_unslash( $_POST['billing_address_1'] ?? '' ) ),
            'address_2' => sanitize_text_field( wp_unslash( $_POST['billing_address_2'] ?? '' ) ),
            'city'      => sanitize_text_field( wp_unslash( $_POST['billing_city']      ?? '' ) ),
            'state'     => sanitize_text_field( wp_unslash( $_POST['billing_state']     ?? '' ) ),
            'postcode'  => sanitize_text_field( wp_unslash( $_POST['billing_postcode']  ?? '' ) ),
            'country'   => sanitize_text_field( wp_unslash( $_POST['billing_country']   ?? 'US' ) ),
        );
        $shipping_method = sanitize_text_field( wp_unslash( $_POST['shipping_method'] ?? '' ) );
        $payment_method  = sanitize_text_field( wp_unslash( $_POST['payment_method']  ?? '' ) );
        $notes = sanitize_textarea_field( wp_unslash( $_POST['order_notes'] ?? '' ) );

        // Persist the customer info we just collected so subsequent
        // orders auto-fill. Mirror to the WC_Customer record so admin
        // /wp-admin/user-edit.php stays in sync.
        $customer = new WC_Customer( $uid );
        $customer->set_first_name( $first );
        $customer->set_last_name( $last );
        $customer->set_email( $email );
        $customer->set_billing_first_name( $first );
        $customer->set_billing_last_name( $last );
        $customer->set_billing_email( $email );
        $customer->set_billing_phone( $phone );
        $customer->set_billing_company( $company );
        $customer->set_shipping_company( $company );
        foreach ( $ship as $k => $v ) {
            $customer->{ 'set_shipping_' . $k }( $v );
            update_user_meta( $uid, 'shipping_' . $k, $v );
        }
        foreach ( $billing as $k => $v ) {
            $customer->{ 'set_billing_' . $k }( $v );
            update_user_meta( $uid, 'billing_' . $k, $v );
        }
        $customer->save();

        // Update WC session shipping address so calculate_shipping uses it.
        WC()->customer->set_shipping_country( $ship['country'] );
        WC()->customer->set_shipping_state(   $ship['state'] );
        WC()->customer->set_shipping_postcode( $ship['postcode'] );
        WC()->customer->set_shipping_city(    $ship['city'] );
        WC()->customer->set_shipping_address_1( $ship['address_1'] );
        WC()->customer->set_shipping_address_2( $ship['address_2'] );

        // Persist chosen shipping + payment in the session so cart calcs use them
        if ( WC()->session ) {
            WC()->session->set( 'chosen_shipping_methods', array( $shipping_method ) );
            WC()->session->set( 'chosen_payment_method',   $payment_method );
        }
        WC()->cart->calculate_shipping();
        WC()->cart->calculate_totals();

        // Build the order with EXPLICIT line prices from our summary
        $summary = self::build_summary();
        if ( empty( $summary['items'] ) ) {
            wp_safe_redirect( home_url( '/wholesale-order' ) );
            exit;
        }

        try {
            $order = wc_create_order( array( 'customer_id' => $uid ) );
            if ( is_wp_error( $order ) ) {
                throw new Exception( $order->get_error_message() );
            }

            foreach ( $summary['items'] as $i ) {
                $pid   = $i['variation_id'] ?: $i['product_id'];
                $prod  = wc_get_product( $pid );
                if ( ! $prod ) continue;
                $item_id = $order->add_product( $prod, $i['qty'] );
                if ( ! $item_id ) continue;
                $line_item = $order->get_item( $item_id );
                if ( $line_item ) {
                    $line_item->set_subtotal( $i['wholesale_total'] );
                    $line_item->set_total(    $i['wholesale_total'] );
                    $line_item->save();
                }
            }

            // Add shipping
            $packages = WC()->shipping ? WC()->shipping->get_packages() : array();
            foreach ( $packages as $pkg ) {
                foreach ( $pkg['rates'] as $rate_id => $rate ) {
                    if ( $rate_id !== $shipping_method ) continue;
                    $shipping = new WC_Order_Item_Shipping();
                    $shipping->set_method_title( $rate->get_label() );
                    $shipping->set_method_id( $rate->get_method_id() );
                    $shipping->set_total( $rate->get_cost() );
                    $shipping->set_taxes( array( 'total' => $rate->taxes ?? array() ) );
                    $order->add_item( $shipping );
                }
            }

            // Addresses on the order itself
            $order->set_address( array(
                'first_name' => $first,
                'last_name'  => $last,
                'email'      => $email,
                'phone'      => $phone,
                'company'    => $company,
                'address_1'  => $billing['address_1'],
                'address_2'  => $billing['address_2'],
                'city'       => $billing['city'],
                'state'      => $billing['state'],
                'postcode'   => $billing['postcode'],
                'country'    => $billing['country'],
            ), 'billing' );
            $order->set_address( array(
                'first_name' => $first,
                'last_name'  => $last,
                'company'    => $company,
                'address_1'  => $ship['address_1'],
                'address_2'  => $ship['address_2'],
                'city'       => $ship['city'],
                'state'      => $ship['state'],
                'postcode'   => $ship['postcode'],
                'country'    => $ship['country'],
            ), 'shipping' );

            // Payment + notes
            $order->set_payment_method( $payment_method );
            if ( ! empty( $available_gateways = WC()->payment_gateways->get_available_payment_gateways() ) && isset( $available_gateways[ $payment_method ] ) ) {
                $order->set_payment_method_title( $available_gateways[ $payment_method ]->get_title() );
            }
            if ( $notes ) {
                $order->add_order_note( 'Customer notes: ' . $notes, true );
            }

            // Mark which path created this
            $order->update_meta_data( '_slw_wholesale_order', '1' );

            $order->calculate_totals( false ); // false = don't recalc line items, keep our explicit totals
            $order->save();

            // Hand off to the gateway for processing
            $gateway = $available_gateways[ $payment_method ] ?? null;
            if ( $gateway ) {
                $result = $gateway->process_payment( $order->get_id() );
                if ( isset( $result['result'] ) && $result['result'] === 'success' && ! empty( $result['redirect'] ) ) {
                    WC()->cart->empty_cart();
                    wp_safe_redirect( $result['redirect'] );
                    exit;
                }
            }

            // Fallback: mark on-hold and send to thank you
            $order->update_status( 'on-hold', 'Wholesale order placed via custom checkout.' );
            WC()->cart->empty_cart();
            wp_safe_redirect( $order->get_checkout_order_received_url() );
            exit;

        } catch ( Exception $e ) {
            error_log( '[SLW wholesale-checkout] order creation failed: ' . $e->getMessage() );
            wp_safe_redirect( add_query_arg( 'slw_checkout_error', '1', home_url( '/wholesale-checkout' ) ) );
            exit;
        }
    }
}
