<?php
/**
 * Per-Category Minimum Quantity
 *
 * Lets the admin set a minimum total quantity per product category that
 * a wholesale cart must satisfy. The customer can mix and match across
 * scents/SKUs inside the category as long as the sum hits the minimum.
 *
 * Storage: option 'slw_category_minimums' => [ term_id => min_qty ].
 * Enforcement: woocommerce_check_cart_items, sums cart quantities by
 * each item's primary product category and the category's parents.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class SLW_Category_Minimums {

    const OPTION_KEY = 'slw_category_minimums';

    public static function init() {
        add_action( 'woocommerce_check_cart_items', array( __CLASS__, 'enforce_category_minimums' ) );
    }

    /**
     * Get all configured category minimums as [ term_id => min_qty ].
     */
    public static function get_minimums() {
        $stored = get_option( self::OPTION_KEY, array() );
        if ( ! is_array( $stored ) ) {
            return array();
        }
        $out = array();
        foreach ( $stored as $term_id => $min ) {
            $term_id = absint( $term_id );
            $min     = absint( $min );
            if ( $term_id > 0 && $min > 0 ) {
                $out[ $term_id ] = $min;
            }
        }
        return $out;
    }

    /**
     * Get the minimum for a single category term.
     */
    public static function get_category_minimum( $term_id ) {
        $all = self::get_minimums();
        return $all[ absint( $term_id ) ] ?? 0;
    }

    /**
     * Save the category minimums from a $_POST array shaped as
     * [ term_id => min_qty ]. Called from the Pricing page handler.
     */
    public static function save_from_post() {
        if ( ! isset( $_POST['slw_category_minimums'] ) || ! is_array( $_POST['slw_category_minimums'] ) ) {
            update_option( self::OPTION_KEY, array() );
            return;
        }
        $clean = array();
        foreach ( $_POST['slw_category_minimums'] as $term_id => $min ) {
            $term_id = absint( $term_id );
            $min     = absint( $min );
            if ( $term_id > 0 && $min > 0 ) {
                $clean[ $term_id ] = $min;
            }
        }
        update_option( self::OPTION_KEY, $clean );
    }

    /**
     * Sum the total cart quantity by category term_id. A product is counted
     * under every term in its category list (so a product in Ageless and a
     * subcategory both get the qty).
     *
     * @return array term_id => total_qty
     */
    public static function sum_cart_by_category() {
        $totals = array();
        if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
            return $totals;
        }
        foreach ( WC()->cart->get_cart() as $cart_item ) {
            $product   = $cart_item['data'];
            if ( ! $product ) continue;

            $qty = (int) ( $cart_item['quantity'] ?? 0 );
            if ( $qty <= 0 ) continue;

            // Probe every ID associated with this cart line, not just
            // the parent product id, because variation/subscription
            // setups sometimes assign categories at the variation level
            // while leaving the parent uncategorized, or vice versa.
            // Single-id lookups missed those lines and they vanished
            // from the category total. Union of all candidates fixes
            // it; if a real wholesale customer adds an item, at least
            // ONE of these will have the right categories.
            $candidate_ids = array();
            if ( ! empty( $cart_item['product_id'] ) ) {
                $candidate_ids[] = (int) $cart_item['product_id'];
            }
            if ( ! empty( $cart_item['variation_id'] ) ) {
                $candidate_ids[] = (int) $cart_item['variation_id'];
            }
            if ( method_exists( $product, 'get_parent_id' ) ) {
                $pp = (int) $product->get_parent_id();
                if ( $pp > 0 ) $candidate_ids[] = $pp;
            }
            $candidate_ids[] = (int) $product->get_id();
            $candidate_ids = array_values( array_unique( array_filter( $candidate_ids ) ) );

            $term_ids = array();
            foreach ( $candidate_ids as $cid ) {
                $direct = wc_get_product_term_ids( $cid, 'product_cat' );
                if ( ! empty( $direct ) ) {
                    $term_ids = array_merge( $term_ids, $direct );
                }
            }
            $term_ids = array_values( array_unique( array_map( 'intval', $term_ids ) ) );

            if ( empty( $term_ids ) ) continue;

            // Include ancestor categories so a min configured on a
            // parent category (Tallow Butter) catches products tagged
            // in a sub-category (Tallow Butter > Renewal).
            $all_terms = $term_ids;
            foreach ( $term_ids as $tid ) {
                $ancestors = get_ancestors( $tid, 'product_cat' );
                if ( ! empty( $ancestors ) ) {
                    $all_terms = array_merge( $all_terms, $ancestors );
                }
            }
            $all_terms = array_unique( $all_terms );

            foreach ( $all_terms as $tid ) {
                $totals[ $tid ] = ( $totals[ $tid ] ?? 0 ) + $qty;
            }
        }
        return $totals;
    }

    /**
     * Enforce category minimums on cart submit. Errors when any category
     * with a configured min is below it.
     */
    public static function enforce_category_minimums() {
        if ( ! slw_is_wholesale_context() ) {
            return;
        }
        // Grandfathered customers are exempt from quantity minimums.
        if ( class_exists( 'SLW_Product_Minimums' ) && SLW_Product_Minimums::user_is_exempt() ) {
            return;
        }
        foreach ( self::get_violations() as $msg ) {
            wc_add_notice( $msg, 'error' );
        }
    }

    /**
     * Compute the list of category-minimum violations for the current
     * cart. Returns an array of human-readable message strings. Shared
     * by enforce_category_minimums() (server-side notice) and the
     * order-form template (in-page violations panel) so both surfaces
     * stay in sync.
     */
    public static function get_violations( $structured = false ) {
        $out = array();
        if ( ! slw_is_wholesale_context() ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( '[SLW cat-mins] skipped: not wholesale context' );
            }
            return $out;
        }
        $mins = self::get_minimums();
        if ( empty( $mins ) ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( '[SLW cat-mins] skipped: no category minimums configured' );
            }
            return $out;
        }
        $totals = self::sum_cart_by_category();
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            // Capture the cart's actual product->category mapping too,
            // so we can see whether the configured mins term_ids line
            // up with what the products are tagged in. If mins={47:6}
            // but every product has cats=[83, 91, 104] -> mismatch,
            // products aren't in the category that has the rule.
            $cart_cats = array();
            if ( function_exists( 'WC' ) && WC()->cart ) {
                foreach ( WC()->cart->get_cart() as $ci ) {
                    $p = $ci['data'] ?? null;
                    if ( ! $p ) continue;
                    $pid = method_exists( $p, 'get_parent_id' ) && $p->get_parent_id() ? $p->get_parent_id() : $p->get_id();
                    $cart_cats[ $pid ] = wc_get_product_term_ids( $pid, 'product_cat' );
                }
            }
            error_log( sprintf(
                '[SLW cat-mins] mins=%s totals=%s cart_products_cats=%s',
                wp_json_encode( $mins ),
                wp_json_encode( $totals ),
                wp_json_encode( $cart_cats )
            ) );
        }

        // (Pre-v4.6.134 there was a "single-contributor dedupe" block
        // here that suppressed a category violation when its only
        // contributor was independently violating its per-product
        // min. That made sense when product mins were enforced. Since
        // v4.6.115 product mins are no-op, so the dedupe was silently
        // eating real category violations whenever a product still
        // had its legacy _slw_min_qty meta set + only one product in
        // the category. Removed.)

        foreach ( $mins as $term_id => $min_qty ) {
            $cart_qty = $totals[ $term_id ] ?? 0;
            if ( $cart_qty <= 0 ) continue;
            if ( $cart_qty < $min_qty ) {
                $term = get_term( $term_id, 'product_cat' );
                $cat_name = $term && ! is_wp_error( $term ) ? $term->name : 'this category';
                // Spell out that the "have X" count is category-scoped,
                // not the total cart, so a 6-item cart with only 4 in
                // this category doesn't look like a bug to the customer.
                $label = sprintf(
                    '%s minimum: %d units. You have %d %s in your cart. Add %d more (mix & match any scent/size).',
                    $cat_name,
                    (int) $min_qty,
                    (int) $cart_qty,
                    $cat_name,
                    (int) $min_qty - (int) $cart_qty
                );
                if ( $structured ) {
                    $out[] = array(
                        'type'  => 'category',
                        'name'  => $cat_name,
                        'have'  => (int) $cart_qty,
                        'min'   => (int) $min_qty,
                        'add'   => (int) $min_qty - (int) $cart_qty,
                        'label' => $label,
                    );
                } else {
                    $out[] = $label;
                }
            }
        }
        return $out;
    }

    /**
     * Render the admin UI section. Called from the Pricing page render.
     */
    public static function render_admin_section() {
        $terms = get_terms( array(
            'taxonomy'   => 'product_cat',
            'hide_empty' => false,
        ) );
        $current = self::get_minimums();
        ?>
        <div class="slw-admin-card" style="padding:20px 24px;margin-bottom:24px;">
            <h2 class="slw-admin-card__heading" style="margin-bottom:8px;">Category Minimums</h2>
            <p style="color:#628393;margin-bottom:6px;">
                Set a minimum total quantity for a whole <strong>category</strong>, met by mixing any products and scents in it. Use this when the minimum spans several products, like Gift Sets.
            </p>
            <p style="color:#628393;margin-bottom:16px;font-size:13px;">
                Example: set Gift Sets to 4. A customer can order 2 Ageless gift sets plus 2 Renewal gift sets, or 4 of one type. Anything adding up to 4 works. For a minimum on a single product line (e.g. 6 Ageless, mixing its scents), use the <strong>Product Min Qty</strong> column in the pricing table above instead. Leave a row blank for no minimum. Don't set both a category minimum and product minimums on the same items, or they double-count.
            </p>

            <?php if ( is_wp_error( $terms ) || empty( $terms ) ) : ?>
                <p><em>No product categories yet. Create them under Products &rarr; Categories first.</em></p>
            <?php else : ?>
            <table class="widefat striped" style="max-width:600px;">
                <thead>
                    <tr>
                        <th style="text-align:left;padding:10px 12px;">Category</th>
                        <th style="text-align:left;padding:10px 12px;width:140px;">Minimum Qty</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $terms as $term ) :
                        $val = $current[ $term->term_id ] ?? '';
                    ?>
                        <tr>
                            <td style="padding:8px 12px;"><?php echo esc_html( $term->name ); ?></td>
                            <td style="padding:8px 12px;">
                                <input type="number"
                                       name="slw_category_minimums[<?php echo esc_attr( $term->term_id ); ?>]"
                                       value="<?php echo esc_attr( $val ); ?>"
                                       min="0" step="1"
                                       style="width:80px;padding:4px 8px;"
                                       placeholder="None" />
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
        <?php
    }
}
