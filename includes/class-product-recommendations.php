<?php
/**
 * Product Recommendations (Wholesale Pairings)
 *
 * Allows admins to set up to 3 "recommended with" products per product.
 * Shown on the wholesale order form as "Pairs well with" links that
 * scroll to the paired product in the table.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class SLW_Product_Recommendations {

    public static function init() {
        // Add the multi-select field to the product edit General tab
        add_action( 'woocommerce_product_options_general_product_data', array( __CLASS__, 'render_product_field' ) );
        add_action( 'woocommerce_admin_process_product_object', array( __CLASS__, 'save_product_field' ) );

        // Enqueue admin scripts for the product edit page
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_scripts' ) );
    }

    /**
     * Enqueue WC's enhanced select on the product edit screen.
     */
    public static function enqueue_admin_scripts( $hook ) {
        global $post_type;
        if ( $hook === 'post.php' && $post_type === 'product' ) {
            wp_enqueue_script( 'wc-enhanced-select' );
        }
    }

    /**
     * Render the "Recommended Wholesale Pairings" field on the General tab.
     * Uses WooCommerce's built-in wc-product-search select2.
     */
    public static function render_product_field() {
        global $post;

        $product_ids = get_post_meta( $post->ID, '_slw_recommended_products', true );
        if ( ! is_array( $product_ids ) ) {
            $product_ids = array();
        }

        // Build the options for already-selected products
        $selected_options = '';
        foreach ( $product_ids as $pid ) {
            $product = wc_get_product( $pid );
            if ( $product ) {
                $selected_options .= '<option value="' . esc_attr( $pid ) . '" selected="selected">'
                    . esc_html( $product->get_formatted_name() )
                    . '</option>';
            }
        }
        ?>
        <div class="options_group">
            <p class="form-field">
                <label for="_slw_recommended_products"><?php esc_html_e( 'Recommended Wholesale Pairings', 'sego-lily-wholesale' ); ?></label>
                <select class="wc-product-search" multiple="multiple" style="width: 50%;"
                        id="_slw_recommended_products" name="_slw_recommended_products[]"
                        data-placeholder="<?php esc_attr_e( 'Search for products...', 'sego-lily-wholesale' ); ?>"
                        data-action="woocommerce_json_search_products"
                        data-maximum-selection-length="3">
                    <?php echo $selected_options; ?>
                </select>
                <?php echo wc_help_tip( 'Select up to 3 products to recommend alongside this product on the wholesale order form. These appear as "Pairs well with" links.' ); ?>
            </p>
        </div>
        <?php
    }

    /**
     * Save the recommended products meta. Max 3 product IDs.
     *
     * @param WC_Product $product The product being saved.
     */
    public static function save_product_field( $product ) {
        $ids = isset( $_POST['_slw_recommended_products'] ) ? array_map( 'absint', (array) $_POST['_slw_recommended_products'] ) : array();

        // Enforce max 3
        $ids = array_slice( array_filter( $ids ), 0, 3 );

        $product->update_meta_data( '_slw_recommended_products', $ids );
    }

    /**
     * Get recommended product IDs for a given product.
     *
     * @param int $product_id The product ID.
     * @return array Array of recommended product IDs.
     */
    public static function get_recommendations( $product_id ) {
        $ids = get_post_meta( $product_id, '_slw_recommended_products', true );
        if ( ! is_array( $ids ) ) {
            return array();
        }
        return array_filter( array_map( 'absint', $ids ) );
    }
}
