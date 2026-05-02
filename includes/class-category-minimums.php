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

            // For variations, categories live on the parent.
            $parent_id  = $product->get_parent_id();
            $lookup_id  = $parent_id ? $parent_id : $product->get_id();
            $term_ids   = wc_get_product_term_ids( $lookup_id, 'product_cat' );

            if ( empty( $term_ids ) ) continue;

            $qty = (int) $cart_item['quantity'];
            foreach ( $term_ids as $tid ) {
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
        $mins = self::get_minimums();
        if ( empty( $mins ) ) {
            return;
        }

        $totals = self::sum_cart_by_category();

        foreach ( $mins as $term_id => $min_qty ) {
            $cart_qty = $totals[ $term_id ] ?? 0;
            if ( $cart_qty <= 0 ) {
                continue; // Nothing in this category. Skip silently.
            }
            if ( $cart_qty < $min_qty ) {
                $term = get_term( $term_id, 'product_cat' );
                $cat_name = $term && ! is_wp_error( $term ) ? $term->name : 'this category';
                wc_add_notice(
                    sprintf(
                        '%s requires a minimum of %d units (you can mix and match across scents). You currently have %d.',
                        esc_html( $cat_name ),
                        $min_qty,
                        $cart_qty
                    ),
                    'error'
                );
            }
        }
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
                Set a minimum total quantity for a category. Customers can mix and match items within the category to hit the total, so smaller stores aren't forced to buy 6 of one scent to meet your minimum.
            </p>
            <p style="color:#628393;margin-bottom:16px;font-size:13px;">
                Example: set Ageless to 6. A customer can order 3 Honey plus 3 Lavender, or 2/2/2 across three scents, or 6 of one scent. Anything that adds up to 6 works. Leave a row blank for no minimum on that category.
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
