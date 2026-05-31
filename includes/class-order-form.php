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
        add_action( 'wp_ajax_slw_add_to_cart',     array( __CLASS__, 'ajax_add_to_cart' ) );
        add_action( 'wp_ajax_slw_reorder_last',    array( __CLASS__, 'ajax_reorder_last' ) );
        add_action( 'wp_ajax_slw_remove_cart_line', array( __CLASS__, 'ajax_remove_cart_line' ) );
        add_action( 'wp_ajax_slw_clear_cart',       array( __CLASS__, 'ajax_clear_cart' ) );
        add_action( 'wp_ajax_slw_set_cart_qty',     array( __CLASS__, 'ajax_set_cart_qty' ) );
    }

    /**
     * Update the quantity of a single cart line. Called from the order
     * form Cart Preview +/- buttons and direct input. Returns updated
     * cart state for re-render.
     */
    public static function ajax_set_cart_qty() {
        check_ajax_referer( 'slw_order_form', 'nonce' );
        if ( ! is_user_logged_in() || ! slw_is_wholesale_user() ) {
            wp_send_json_error( array( 'message' => 'Wholesale customers only.' ), 403 );
        }
        if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
            wp_send_json_error( array( 'message' => 'Cart unavailable.' ), 500 );
        }
        // Same context flag the page-render path sets so admins
        // testing in preview mode get the same wholesale violation
        // computation through the AJAX path that they get on initial
        // load. Real wholesale customers pass slw_is_wholesale_context
        // via role check; this only matters for admin testing.
        $GLOBALS['slw_in_portal_render'] = true;
        // Hydrate cart from session in case lazy-load hasn't fired yet.
        if ( WC()->session && method_exists( WC()->cart, 'get_cart_from_session' ) ) {
            WC()->cart->get_cart_from_session();
        }
        $cart_key = isset( $_POST['cart_key'] ) ? sanitize_text_field( wp_unslash( $_POST['cart_key'] ) ) : '';
        $qty      = max( 0, (int) ( $_POST['qty'] ?? 0 ) );
        $pid      = absint( $_POST['product_id']   ?? 0 );
        $vid      = absint( $_POST['variation_id'] ?? 0 );
        $cart_items   = WC()->cart->get_cart();
        $resolved_key = '';
        if ( $cart_key && isset( $cart_items[ $cart_key ] ) ) {
            $resolved_key = $cart_key;
        } else {
            foreach ( $cart_items as $key => $ci ) {
                $ci_pid = (int) ( $ci['product_id']   ?? 0 );
                $ci_vid = (int) ( $ci['variation_id'] ?? 0 );
                if ( $pid > 0 && $vid > 0 && $ci_pid === $pid && $ci_vid === $vid ) { $resolved_key = $key; break; }
                if ( $pid > 0 && $vid === 0 && $ci_pid === $pid ) { $resolved_key = $key; break; }
                if ( $vid > 0 && $ci_vid === $vid ) { $resolved_key = $key; break; }
            }
        }
        if ( $resolved_key ) {
            if ( $qty <= 0 ) {
                WC()->cart->remove_cart_item( $resolved_key );
            } else {
                WC()->cart->set_quantity( $resolved_key, $qty, true );
            }
            WC()->cart->calculate_totals();
            if ( method_exists( WC()->cart, 'set_session' ) ) {
                WC()->cart->set_session();
            }
        } else {
            error_log( sprintf(
                '[SLW set-cart-qty] no match. posted cart_key="%s" pid=%d vid=%d qty=%d -- cart has %d items: keys=%s',
                $cart_key, $pid, $vid, $qty,
                count( $cart_items ),
                implode( ',', array_keys( $cart_items ) )
            ) );
        }
        wp_send_json_success( self::cart_state_payload() );
    }

    /**
     * Remove a single line from the cart (called from the order form
     * Cart Preview row "x" buttons). Returns the updated cart payload
     * so the JS can re-render without a page reload.
     */
    public static function ajax_remove_cart_line() {
        check_ajax_referer( 'slw_order_form', 'nonce' );
        if ( ! is_user_logged_in() || ! slw_is_wholesale_user() ) {
            wp_send_json_error( array( 'message' => 'Wholesale customers only.' ), 403 );
        }
        if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
            wp_send_json_error( array( 'message' => 'Cart unavailable.' ), 500 );
        }
        $GLOBALS['slw_in_portal_render'] = true;
        // Make sure the WC cart is hydrated from session (AJAX context
        // sometimes lazy-loads later, leaving an empty cart_contents at
        // the top of the handler).
        if ( WC()->session && method_exists( WC()->cart, 'get_cart_from_session' ) ) {
            WC()->cart->get_cart_from_session();
        }

        $cart_key = isset( $_POST['cart_key'] ) ? sanitize_text_field( wp_unslash( $_POST['cart_key'] ) ) : '';
        $pid      = absint( $_POST['product_id']   ?? 0 );
        $vid      = absint( $_POST['variation_id'] ?? 0 );

        $cart_items = WC()->cart->get_cart();
        $removed_key = '';

        // Path A: exact cart_key match (fastest, most common)
        if ( $cart_key && isset( $cart_items[ $cart_key ] ) ) {
            $removed_key = $cart_key;
        }

        // Path B: match by product_id + variation_id pair (when the JS
        // cart_key drifted from the session's key)
        if ( ! $removed_key && ( $pid > 0 || $vid > 0 ) ) {
            foreach ( $cart_items as $key => $ci ) {
                $ci_pid = (int) ( $ci['product_id']   ?? 0 );
                $ci_vid = (int) ( $ci['variation_id'] ?? 0 );
                if ( $pid > 0 && $vid > 0 && $ci_pid === $pid && $ci_vid === $vid ) {
                    $removed_key = $key; break;
                }
                if ( $pid > 0 && $vid === 0 && $ci_pid === $pid ) {
                    $removed_key = $key; break;
                }
                if ( $vid > 0 && $ci_vid === $vid ) {
                    $removed_key = $key; break;
                }
            }
        }

        if ( $removed_key ) {
            WC()->cart->remove_cart_item( $removed_key );
            // Belt-and-suspenders: explicit session save in case any
            // filter on woocommerce_cart_item_removed prevents WC's
            // own end-of-request persist.
            WC()->cart->calculate_totals();
            if ( method_exists( WC()->cart, 'set_session' ) ) {
                WC()->cart->set_session();
            }
        } else {
            error_log( sprintf(
                '[SLW remove-cart-line] no match. posted cart_key="%s" pid=%d vid=%d -- cart has %d items: keys=%s',
                $cart_key, $pid, $vid,
                count( $cart_items ),
                implode( ',', array_keys( $cart_items ) )
            ) );
        }

        wp_send_json_success( self::cart_state_payload() );
    }

    /**
     * Empty the entire cart. Triggered by the Cart Preview "Clear cart"
     * button on the order form.
     */
    public static function ajax_clear_cart() {
        check_ajax_referer( 'slw_order_form', 'nonce' );
        if ( ! is_user_logged_in() || ! slw_is_wholesale_user() ) {
            wp_send_json_error( array( 'message' => 'Wholesale customers only.' ), 403 );
        }
        $GLOBALS['slw_in_portal_render'] = true;
        if ( function_exists( 'WC' ) && WC()->cart ) {
            WC()->cart->empty_cart();
        }
        wp_send_json_success( self::cart_state_payload() );
    }

    /**
     * Snapshot of the current cart suitable for the JS to re-render the
     * Cart Preview without a page reload. Mirrors the boot payload that
     * order-form.php renders inline on initial load.
     */
    public static function cart_state_payload() {
        $items = array();
        if ( function_exists( 'WC' ) && WC()->cart && ! WC()->cart->is_empty() ) {
            foreach ( WC()->cart->get_cart() as $key => $ci ) {
                $prod = $ci['data'] ?? null;
                if ( ! $prod ) continue;
                $items[] = array(
                    'cart_key'     => $key,
                    'product_id'   => (int) ( $ci['product_id'] ?? 0 ),
                    'variation_id' => (int) ( $ci['variation_id'] ?? 0 ),
                    'qty'          => (int) ( $ci['quantity'] ?? 0 ),
                    'label'        => self::clean_cart_label( $prod ),
                    'lineTotal'    => (float) $prod->get_price() * (int) ( $ci['quantity'] ?? 0 ),
                );
            }
        }
        // Compute violations server-side so the JS doesn't have to do
        // its own client-side category matching (which depends on the
        // productCategories map being complete + consistent). Sourcing
        // from the same code path that fires at /checkout guarantees
        // the bottom-bar status, violations panel, and the checkout
        // notice all say the same thing.
        $violations = array();
        if ( class_exists( 'SLW_Category_Minimums' ) ) {
            $violations = (array) SLW_Category_Minimums::get_violations( true );
        }
        return array(
            'items'      => $items,
            'violations' => $violations,
        );
    }

    /**
     * Build a clean cart-row label: parent product name + non-subscription
     * variation attributes joined with " — ". No SKU prefix, no "One-Time
     * Purchase" (every wholesale order IS one-time, so the label is
     * redundant). Each piece is normalized so size + scent always read
     * with proper spacing.
     */
    private static function clean_cart_label( $prod ) {
        if ( ! $prod ) return '';
        // Base name: parent product if variation, otherwise just product
        $parent_id = 0;
        $base = '';
        if ( method_exists( $prod, 'get_parent_id' ) && $prod->get_parent_id() ) {
            $parent_id = (int) $prod->get_parent_id();
            $parent = wc_get_product( $parent_id );
            $base = $parent ? $parent->get_name() : $prod->get_name();
        } else {
            $base = $prod->get_name();
        }
        // Variation attributes -- skip:
        //   1. anything resembling a billing cycle / subscription period
        //   2. attributes where every sibling shares the same value
        //      (no distinguishing info -- e.g. lip balm only in 0.5oz,
        //      deodorant only in 2.5oz, listing it adds noise).
        $attrs = array();
        if ( method_exists( $prod, 'get_attributes' ) ) {
            foreach ( (array) $prod->get_attributes() as $taxonomy => $value ) {
                if ( ! $value ) continue;
                $lower = strtolower( (string) $value );
                if ( preg_match( '/month|year|week|every|one.?time|subscribe|subscription|recurring/i', $lower ) ) {
                    continue;
                }
                if ( $parent_id > 0 && function_exists( 'slw_attr_differs_among_siblings' )
                    && ! slw_attr_differs_among_siblings( $parent_id, $taxonomy ) ) {
                    continue;
                }
                $term = get_term_by( 'slug', $value, $taxonomy );
                $attrs[] = $term ? $term->name : ucfirst( str_replace( '-', ' ', $value ) );
            }
        }
        $label = $base;
        if ( ! empty( $attrs ) ) {
            $label .= ', ' . implode( ', ', $attrs );
        }
        return wp_strip_all_tags( $label );
    }

    /**
     * Render the order form shortcode. Gate access to wholesale users only.
     */
    public static function render( $atts = array() ) {
        // Admin preview mode: let admins see what wholesale customers see
        $is_admin_preview = isset( $_GET['slw_preview'] ) && current_user_can( 'manage_woocommerce' );

        // Force wholesale shopping context for wholesale users on this
        // page. Only write if the session isn't already wholesale so
        // we don't trigger a session save on every request.
        if ( ! $is_admin_preview && is_user_logged_in() && slw_is_wholesale_user()
            && function_exists( 'WC' ) && WC()->session
            && WC()->session->get( 'slw_shopping_context' ) !== 'wholesale' ) {
            WC()->session->set( 'slw_shopping_context', 'wholesale' );
        }

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

        $GLOBALS['slw_in_portal_render'] = true;

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

            if ( $type === 'variable' || $type === 'variable-subscription' ) {
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
                    // Last-resort fallback: pick the first in-stock visible
                    // variation. Prevents the 'Gift Box Varieties is a
                    // required field' error Holly hit when adding the
                    // Variety Gift Set without explicitly selecting a scent.
                    // For products where every variation is roughly
                    // equivalent (gift sets with different scents at the
                    // same price), this gets the order added cleanly
                    // instead of failing.
                    if ( $variation_id <= 0 ) {
                        $available = $product->get_available_variations();
                        foreach ( $available as $var_data ) {
                            if ( empty( $var_data['variation_id'] ) ) continue;
                            $candidate = wc_get_product( (int) $var_data['variation_id'] );
                            if ( $candidate && $candidate->is_purchasable() && $candidate->is_in_stock() ) {
                                $variation_id = (int) $var_data['variation_id'];
                                if ( empty( $variation ) ) {
                                    $variation = (array) ( $var_data['attributes'] ?? array() );
                                }
                                break;
                            }
                        }
                    }
                    if ( $variation_id <= 0 ) {
                        $failures[] = sprintf(
                            '%s (no variation selected, pick a scent/size first)',
                            $product->get_name()
                        );
                        error_log( sprintf(
                            '[SLW order-form] variable product %d (%s) sent without variation_id and no fallback variation found',
                            $product_id,
                            $product->get_name()
                        ) );
                        continue;
                    }
                }

                // Some products require variation attribute values that
                // weren't passed (e.g. "Gift Box Varieties" on a Variety
                // Set product). Fill missing required attributes with sane
                // defaults from the parent's attribute config so the WC
                // 'X is a required field' rejection doesn't fire. Pre-call
                // priming, both for slugs that map to terms and for free-
                // text attribute options.
                $variation = self::prime_variation_attributes( $product, (array) $variation, $variation_id );

                // Variety Gift Sets keep hitting WC's required-field
                // validation no matter what we prime, because the
                // "Gift Box Varieties" attribute has no real terms to
                // assign and the parent has no default for it. Bypass
                // WC's validation entirely by inserting into the cart
                // directly. Manual cart_contents insertion runs the same
                // pipeline WC uses internally minus the variation-attr
                // gate.
                $is_variety = (bool) preg_match( '/variety|gift\s*set/i', $product->get_name() );
                if ( $is_variety ) {
                    $result = self::add_variety_to_cart_directly(
                        $product_id, $quantity, $variation_id, $variation
                    );
                } else {
                    $result = WC()->cart->add_to_cart( $product_id, $quantity, $variation_id, $variation );
                }
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
                'message'    => $added . ' product(s) added to your cart.',
                'cart_url'   => wc_get_cart_url(),
                'cart_state' => self::cart_state_payload(),
            ));
        } elseif ( $added > 0 ) {
            wp_send_json_success( array(
                'message'    => sprintf(
                    '%d added, %d skipped: %s',
                    $added,
                    count( $failures ),
                    implode( '; ', $failures )
                ),
                'cart_url'   => wc_get_cart_url(),
                'cart_state' => self::cart_state_payload(),
            ));
        } else {
            $msg = empty( $failures )
                ? 'Could not add items to cart. Please check quantities and try again.'
                : 'Could not add: ' . implode( '; ', $failures );
            wp_send_json_error( array( 'message' => $msg ) );
        }
    }

    /**
     * Fill missing required variation attributes with sane defaults.
     *
     * Looks at the parent product's variation-enabled attributes. For any
     * attribute the customer didn't supply a value for, sets one from:
     *   1. The variation row's own meta (if a specific variation_id was
     *      picked, its attribute values are authoritative).
     *   2. A hardcoded preference list for known Sego Lily attributes --
     *      includes 'variety' / 'varieties' for 'gift-box-varieties' /
     *      'variety-gift-sets' so the May 29 Camila-call ask works even
     *      before she's finished creating the new Ageless/Renewal/Moxie/
     *      Variety variations.
     *   3. The first taxonomy term (for global attributes) or the first
     *      pipe-delimited option (for product-level attributes).
     *
     * @param WC_Product $product
     * @param array      $variation Attribute name => value supplied by client.
     * @param int        $variation_id The matched variation, if any.
     * @return array Completed attribute map.
     */
    /**
     * AJAX: reorder the customer's most recent completed/processing order.
     * Pushes every line item back into the cart at the same qty + variation,
     * then returns the checkout URL so the dashboard JS can redirect.
     */
    public static function ajax_reorder_last() {
        check_ajax_referer( 'slw_reorder_last', 'nonce' );
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( array( 'message' => 'Sign in first.' ) );
        }
        $user_id = get_current_user_id();
        $orders = wc_get_orders( array(
            'customer_id' => $user_id,
            'status'      => array( 'wc-processing', 'wc-completed', 'wc-on-hold' ),
            'limit'       => 1,
            'orderby'     => 'date',
            'order'       => 'DESC',
        ) );
        if ( empty( $orders ) ) {
            wp_send_json_error( array( 'message' => 'No previous order found to reorder.' ) );
        }
        $order = $orders[0];

        if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
            wp_send_json_error( array( 'message' => 'Cart is not available right now.' ) );
        }

        $added = 0;
        $failed = array();
        foreach ( $order->get_items() as $item ) {
            $product_id   = (int) $item->get_product_id();
            $variation_id = (int) $item->get_variation_id();
            $qty          = (int) $item->get_quantity();
            $variation    = $variation_id ? $item->get_variation() : array();

            if ( $qty < 1 || $product_id < 1 ) continue;
            $product = wc_get_product( $variation_id ?: $product_id );
            if ( ! $product || ! $product->is_in_stock() ) {
                $failed[] = $item->get_name();
                continue;
            }
            $ok = WC()->cart->add_to_cart( $product_id, $qty, $variation_id, (array) $variation );
            if ( $ok ) {
                $added++;
            } else {
                $failed[] = $item->get_name();
            }
        }

        if ( $added < 1 ) {
            wp_send_json_error( array(
                'message' => empty( $failed )
                    ? 'No items from your last order are still available.'
                    : 'Could not re-add: ' . implode( ', ', $failed ),
            ) );
        }

        $wholesale_checkout = get_page_by_path( 'wholesale-checkout' );
        $checkout_url       = $wholesale_checkout ? get_permalink( $wholesale_checkout->ID ) : wc_get_checkout_url();

        wp_send_json_success( array(
            'message'      => $added . ' item' . ( $added === 1 ? '' : 's' ) . ' added to cart from your last order'
                              . ( ! empty( $failed ) ? '. Skipped: ' . implode( ', ', $failed ) : '' ),
            'checkout_url' => $checkout_url,
        ) );
    }

    /**
     * Insert a variety-gift-set line directly into WC()->cart->cart_contents,
     * skipping WC_Cart::add_to_cart()'s variation-attribute validation.
     * The validation requires a non-empty value for every "used for
     * variation" attribute and rejects with "X is a required field" --
     * Variety Gift Sets are configured with "Any" for one of those
     * attributes and have no parent default to fall back on, so the
     * validation can never pass via the normal path. We bypass it.
     *
     * Same pipeline WC uses internally otherwise: generate_cart_id,
     * apply woocommerce_add_cart_item filter, fire the woocommerce_
     * add_to_cart action, recalculate totals.
     */
    private static function add_variety_to_cart_directly( $product_id, $quantity, $variation_id, $variation ) {
        if ( ! function_exists( 'WC' ) || ! WC()->cart ) return false;

        $product_data = $variation_id > 0
            ? wc_get_product( $variation_id )
            : wc_get_product( $product_id );
        if ( ! $product_data || ! $product_data->is_purchasable() ) {
            error_log( sprintf(
                '[SLW variety-direct-add] product %d not purchasable',
                $variation_id ?: $product_id
            ) );
            return false;
        }
        if ( ! $product_data->is_in_stock() ) {
            error_log( sprintf(
                '[SLW variety-direct-add] product %d out of stock',
                $variation_id ?: $product_id
            ) );
            return false;
        }

        $cart_item_data = (array) apply_filters(
            'woocommerce_add_cart_item_data', array(), $product_id, $variation_id, $quantity
        );

        $cart_id = WC()->cart->generate_cart_id( $product_id, $variation_id, $variation, $cart_item_data );

        // If the same item is already in the cart, increment its qty
        // instead of inserting a duplicate.
        $existing_key = WC()->cart->find_product_in_cart( $cart_id );
        if ( $existing_key ) {
            $new_qty = (int) WC()->cart->cart_contents[ $existing_key ]['quantity'] + (int) $quantity;
            WC()->cart->set_quantity( $existing_key, $new_qty, true );
            return $existing_key;
        }

        WC()->cart->cart_contents[ $cart_id ] = apply_filters(
            'woocommerce_add_cart_item',
            array_merge(
                $cart_item_data,
                array(
                    'key'          => $cart_id,
                    'product_id'   => (int) $product_id,
                    'variation_id' => (int) $variation_id,
                    'variation'    => $variation,
                    'quantity'     => (int) $quantity,
                    'data'         => $product_data,
                    'data_hash'    => function_exists( 'wc_get_cart_item_data_hash' ) ? wc_get_cart_item_data_hash( $product_data ) : '',
                )
            ),
            $cart_id
        );

        WC()->cart->cart_contents = apply_filters(
            'woocommerce_cart_contents_changed', WC()->cart->cart_contents
        );
        do_action( 'woocommerce_add_to_cart', $cart_id, $product_id, $quantity, $variation_id, $variation, $cart_item_data );
        WC()->cart->calculate_totals();
        // Persist to the WC session so subsequent requests (the JS-side
        // Remove / Set Qty handlers) see the same cart. Without this,
        // the in-memory cart_contents change disappears the moment the
        // request ends and the X button has nothing to remove on its
        // next AJAX call.
        WC()->cart->set_session();

        return $cart_id;
    }

    private static function prime_variation_attributes( $product, $variation, $variation_id ) {
        // Aliases that should satisfy specific attribute names even when
        // the canonical term slug doesn't include them. Holly sometimes
        // drops 'Gift' from product names; cover both forms.
        $preferred_terms = array(
            'gift-box-varieties' => array( 'variety', 'varieties', 'variety-gift-set', 'variety-gift-sets', 'variety-set', 'variety-sets' ),
            'variety-gift-sets'  => array( 'variety', 'varieties', 'variety-gift-set', 'variety-gift-sets', 'variety-set', 'variety-sets' ),
            'variety-sets'       => array( 'variety', 'varieties', 'variety-set', 'variety-sets' ),
            'gift-set-type'      => array( 'variety', 'varieties', 'variety-gift-set' ),
        );

        // 1. Pull the specific variation's own attribute values first
        //    (most authoritative).
        if ( $variation_id > 0 ) {
            $matched = wc_get_product( $variation_id );
            if ( $matched instanceof WC_Product_Variation ) {
                foreach ( $matched->get_attributes() as $tax => $value ) {
                    $key = 'attribute_' . sanitize_title( $tax );
                    if ( empty( $variation[ $key ] ) && $value !== '' ) {
                        $variation[ $key ] = $value;
                    }
                }
            }
        }

        // 2. For each required-for-variation attribute that's STILL
        //    missing, try a cascade of fallbacks:
        //      a. preferred-term aliases (handles bespoke slugs)
        //      b. terms assigned to the parent product
        //      c. ANY value used by ANY sibling variation
        //      d. first option configured on the attribute itself
        $attrs = $product->get_attributes();
        if ( ! is_array( $attrs ) ) return $variation;

        // Pre-build a map of "values seen on any variation per attr key"
        // so we can fall back to a sibling's value when the parent has
        // none defined. Cheap one-pass scan of children.
        $sibling_values = array();
        if ( method_exists( $product, 'get_children' ) ) {
            foreach ( (array) $product->get_children() as $child_id ) {
                $child = wc_get_product( (int) $child_id );
                if ( ! $child instanceof WC_Product_Variation ) continue;
                foreach ( $child->get_attributes() as $tax => $value ) {
                    if ( $value === '' || $value === null ) continue;
                    $key = sanitize_title( $tax );
                    if ( ! isset( $sibling_values[ $key ] ) ) {
                        $sibling_values[ $key ] = $value;
                    }
                }
            }
        }

        foreach ( $attrs as $attr_key => $attr_obj ) {
            if ( ! is_object( $attr_obj ) || ! method_exists( $attr_obj, 'get_variation' ) ) continue;
            if ( ! $attr_obj->get_variation() ) continue;

            $field_key = 'attribute_' . sanitize_title( $attr_key );
            if ( ! empty( $variation[ $field_key ] ) ) continue;

            $slug = sanitize_title( $attr_key );
            $assigned = '';

            // a. Preferred-term alias on the parent's term list
            $parent_terms = array();
            if ( $attr_obj->is_taxonomy() ) {
                $parent_terms = (array) wc_get_product_terms( $product->get_id(), $attr_obj->get_name(), array( 'fields' => 'slugs' ) );
            } else {
                foreach ( (array) $attr_obj->get_options() as $opt ) {
                    $parent_terms[] = sanitize_title( (string) $opt );
                }
            }

            if ( isset( $preferred_terms[ $slug ] ) ) {
                foreach ( $preferred_terms[ $slug ] as $alias ) {
                    if ( in_array( $alias, $parent_terms, true ) ) {
                        $assigned = $alias;
                        break;
                    }
                }
            }

            // b. First term assigned to the parent
            if ( $assigned === '' && ! empty( $parent_terms ) ) {
                $assigned = $parent_terms[0];
            }

            // c. ANY value seen on a sibling variation (catches "Any"
            //    attribute setups where the parent has no terms but at
            //    least one variation does)
            if ( $assigned === '' && isset( $sibling_values[ $slug ] ) ) {
                $assigned = $sibling_values[ $slug ];
            }

            // d. Final fallback: first configured option on the attribute
            //    itself. wc_attribute_orderby for taxonomies, options for
            //    product-level.
            if ( $assigned === '' ) {
                if ( $attr_obj->is_taxonomy() ) {
                    $taxonomy = $attr_obj->get_name();
                    $all_terms = get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false, 'fields' => 'slugs', 'number' => 1 ) );
                    if ( ! is_wp_error( $all_terms ) && ! empty( $all_terms ) ) {
                        $assigned = $all_terms[0];
                    }
                } else {
                    $opts = (array) $attr_obj->get_options();
                    if ( ! empty( $opts ) ) {
                        $assigned = sanitize_title( (string) $opts[0] );
                    }
                }
            }

            if ( $assigned !== '' ) {
                $variation[ $field_key ] = $assigned;
            } else {
                // Surface the failure so we can debug what's actually
                // configured on Holly's side. Without this, the only
                // signal is the customer-facing "X is a required field"
                // error.
                error_log( sprintf(
                    '[SLW prime_variation_attributes] product %d (%s) attr "%s" (slug %s) -- no candidate value found (parent_terms=%d, sibling_values=%s)',
                    $product->get_id(),
                    $product->get_name(),
                    $attr_key,
                    $slug,
                    count( $parent_terms ),
                    isset( $sibling_values[ $slug ] ) ? 'yes' : 'no'
                ) );
            }
        }

        return $variation;
    }
}
