<?php
/**
 * New Arrivals
 *
 * Provides a helper to query WooCommerce products published within a
 * configurable number of days. Used by the order form template to display
 * a "New Arrivals" card section above the main product table.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class SLW_New_Arrivals {

	public static function init() {
		// AJAX handler for adding a single new-arrival item to cart
		add_action( 'wp_ajax_slw_new_arrival_add_to_cart', array( __CLASS__, 'ajax_add_to_cart' ) );
	}

	/**
	 * Get products published within the last N days.
	 *
	 * @param int $days Number of days to look back. Default uses the
	 *                  slw_new_arrivals_days option (fallback 30).
	 * @return WC_Product[] Array of WC_Product objects.
	 */
	public static function get_products( $days = null ) {
		if ( $days === null ) {
			$days = absint( get_option( 'slw_new_arrivals_days', 30 ) );
		}

		$date_after = date( 'Y-m-d', strtotime( '-' . $days . ' days' ) );

		$products = wc_get_products( array(
			'status'     => 'publish',
			'limit'      => -1,
			'orderby'    => 'date',
			'order'      => 'DESC',
			'date_query' => array(
				array(
					'after'     => $date_after,
					'inclusive' => true,
				),
			),
		) );

		// wc_get_products date_query support can be inconsistent across
		// WC versions. Fall back to filtering by post_date if needed.
		if ( empty( $products ) ) {
			$products = wc_get_products( array(
				'status'  => 'publish',
				'limit'   => -1,
				'orderby' => 'date',
				'order'   => 'DESC',
			) );

			$cutoff = strtotime( '-' . $days . ' days' );
			$products = array_filter( $products, function( $product ) use ( $cutoff ) {
				$created = $product->get_date_created();
				return $created && $created->getTimestamp() >= $cutoff;
			} );
		}

		return array_values( $products );
	}

	/**
	 * AJAX handler: add a single product to the cart from the new arrivals
	 * section. Uses the same pattern as the main order form.
	 */
	public static function ajax_add_to_cart() {
		check_ajax_referer( 'slw_order_form', 'nonce' );

		if ( ! slw_is_wholesale_user() ) {
			wp_send_json_error( array( 'message' => 'Wholesale access required.' ) );
		}

		$product_id = absint( $_POST['product_id'] ?? 0 );
		$quantity   = absint( $_POST['quantity'] ?? 1 );

		if ( $product_id < 1 || $quantity < 1 ) {
			wp_send_json_error( array( 'message' => 'Invalid product or quantity.' ) );
		}

		$result = WC()->cart->add_to_cart( $product_id, $quantity );

		if ( $result ) {
			wp_send_json_success( array(
				'message'  => 'Added to cart!',
				'cart_url' => wc_get_cart_url(),
			) );
		} else {
			wp_send_json_error( array( 'message' => 'Could not add to cart. Please try again.' ) );
		}
	}
}
