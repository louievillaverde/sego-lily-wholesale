<?php
/**
 * Customer Group Product Visibility
 *
 * Controls which wholesale tiers can see specific products. Adds a
 * "Visible to Tiers" multi-select on the product edit page. Products
 * restricted to certain tiers are hidden from wholesale users who
 * aren't in those tiers. Retail customers always see all products
 * (this filter only applies within the wholesale context).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class SLW_Customer_Groups {

	public static function init() {
		// Product edit field
		add_action( 'woocommerce_product_options_general_product_data', array( __CLASS__, 'add_visibility_field' ), 25 );
		add_action( 'woocommerce_admin_process_product_object', array( __CLASS__, 'save_visibility_field' ) );

		// Frontend visibility filtering
		add_filter( 'woocommerce_product_is_visible', array( __CLASS__, 'filter_product_visibility' ), 10, 2 );

		// Filter main product queries (shop page, search, archives)
		add_action( 'pre_get_posts', array( __CLASS__, 'filter_product_query' ), 20 );
		add_filter( 'woocommerce_product_query_meta_query', array( __CLASS__, 'filter_wc_product_meta_query' ), 20, 2 );

		// Order form: filter individual product visibility
		add_filter( 'slw_order_form_show_product', array( __CLASS__, 'order_form_product_visible' ), 10, 2 );
	}

	// ── Product Edit Field ────────────────────────────────────────────────

	/**
	 * Add a multi-select for tier visibility on the product General tab.
	 */
	public static function add_visibility_field() {
		global $post;

		$saved = get_post_meta( $post->ID, '_slw_visible_tiers', true );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}

		$tiers = class_exists( 'SLW_Tiers' ) ? SLW_Tiers::get_tiers() : array();
		?>
		<p class="form-field _slw_visible_tiers_field">
			<label for="_slw_visible_tiers">Visible to Wholesale Tiers</label>
			<select name="_slw_visible_tiers[]" id="_slw_visible_tiers" class="wc-enhanced-select" multiple="multiple" style="width:50%;">
				<?php foreach ( $tiers as $slug => $tier ) : ?>
					<option value="<?php echo esc_attr( $slug ); ?>" <?php echo in_array( $slug, $saved, true ) ? 'selected="selected"' : ''; ?>>
						<?php echo esc_html( $tier['name'] ); ?>
					</option>
				<?php endforeach; ?>
			</select>
			<span class="description" style="display:block;margin-top:4px;">
				Leave empty for all wholesale tiers. Select specific tiers to restrict visibility. Retail customers always see this product.
			</span>
		</p>
		<?php
	}

	/**
	 * Save the visible tiers selection.
	 *
	 * @param WC_Product $product
	 */
	public static function save_visibility_field( $product ) {
		$tiers = isset( $_POST['_slw_visible_tiers'] ) ? array_map( 'sanitize_key', (array) $_POST['_slw_visible_tiers'] ) : array();
		$product->update_meta_data( '_slw_visible_tiers', $tiers );
	}

	// ── Visibility Enforcement ────────────────────────────────────────────

	/**
	 * Filter whether a product is visible. Only restricts for wholesale
	 * users whose tier isn't in the product's allowed list.
	 *
	 * @param bool $visible    Current visibility.
	 * @param int  $product_id Product ID.
	 * @return bool
	 */
	public static function filter_product_visibility( $visible, $product_id ) {
		if ( ! $visible ) {
			return $visible;
		}

		// Only apply to wholesale users on the frontend
		if ( is_admin() ) {
			return $visible;
		}

		$user_id = get_current_user_id();
		if ( ! $user_id || ! slw_is_wholesale_user( $user_id ) ) {
			return $visible; // retail users see everything
		}

		return self::user_can_see_product( $user_id, $product_id );
	}

	/**
	 * Filter the main WP_Query for products to exclude tier-restricted items.
	 *
	 * @param WP_Query $query
	 */
	public static function filter_product_query( $query ) {
		if ( is_admin() || ! $query->is_main_query() ) {
			return;
		}

		$user_id = get_current_user_id();
		if ( ! $user_id || ! slw_is_wholesale_user( $user_id ) ) {
			return; // retail users: no filtering
		}

		if ( ! class_exists( 'SLW_Tiers' ) ) {
			return;
		}

		$user_tier = SLW_Tiers::get_user_tier( $user_id );

		$meta_query = $query->get( 'meta_query' ) ?: array();
		$meta_query[] = array(
			'relation' => 'OR',
			// Product has no tier restriction
			array(
				'key'     => '_slw_visible_tiers',
				'compare' => 'NOT EXISTS',
			),
			// Product tier list is empty (serialized empty array)
			array(
				'key'     => '_slw_visible_tiers',
				'value'   => serialize( array() ),
				'compare' => '=',
			),
			// Product tier list includes the user's tier
			array(
				'key'     => '_slw_visible_tiers',
				'value'   => '"' . $user_tier . '"',
				'compare' => 'LIKE',
			),
		);
		$query->set( 'meta_query', $meta_query );
	}

	/**
	 * Filter the WooCommerce product query meta query.
	 *
	 * @param array    $meta_query Existing meta query.
	 * @param WP_Query $query      The WC product query.
	 * @return array
	 */
	public static function filter_wc_product_meta_query( $meta_query, $query ) {
		if ( is_admin() ) {
			return $meta_query;
		}

		$user_id = get_current_user_id();
		if ( ! $user_id || ! slw_is_wholesale_user( $user_id ) ) {
			return $meta_query; // retail: no filtering
		}

		if ( ! class_exists( 'SLW_Tiers' ) ) {
			return $meta_query;
		}

		$user_tier = SLW_Tiers::get_user_tier( $user_id );

		$meta_query[] = array(
			'relation' => 'OR',
			array(
				'key'     => '_slw_visible_tiers',
				'compare' => 'NOT EXISTS',
			),
			array(
				'key'     => '_slw_visible_tiers',
				'value'   => serialize( array() ),
				'compare' => '=',
			),
			array(
				'key'     => '_slw_visible_tiers',
				'value'   => '"' . $user_tier . '"',
				'compare' => 'LIKE',
			),
		);

		return $meta_query;
	}

	/**
	 * Filter for the order form template: should this product be shown?
	 *
	 * @param bool       $show    Whether to show.
	 * @param WC_Product $product Product object.
	 * @return bool
	 */
	public static function order_form_product_visible( $show, $product ) {
		if ( ! $show ) {
			return $show;
		}

		$user_id = get_current_user_id();
		if ( ! $user_id || ! slw_is_wholesale_user( $user_id ) ) {
			return $show;
		}

		return self::user_can_see_product( $user_id, $product->get_id() );
	}

	// ── Helper ────────────────────────────────────────────────────────────

	/**
	 * Check if a wholesale user can see a specific product based on tier
	 * visibility restrictions.
	 *
	 * @param int $user_id    User ID.
	 * @param int $product_id Product ID.
	 * @return bool
	 */
	public static function user_can_see_product( $user_id, $product_id ) {
		$visible_tiers = get_post_meta( $product_id, '_slw_visible_tiers', true );

		// No restriction: visible to all
		if ( empty( $visible_tiers ) || ! is_array( $visible_tiers ) ) {
			return true;
		}

		if ( ! class_exists( 'SLW_Tiers' ) ) {
			return true;
		}

		$user_tier = SLW_Tiers::get_user_tier( $user_id );
		return in_array( $user_tier, $visible_tiers, true );
	}

	/**
	 * Get the visible tiers for a product.
	 *
	 * @param int $product_id
	 * @return array Empty array means visible to all.
	 */
	public static function get_product_visible_tiers( $product_id ) {
		$tiers = get_post_meta( $product_id, '_slw_visible_tiers', true );
		return is_array( $tiers ) ? $tiers : array();
	}
}
