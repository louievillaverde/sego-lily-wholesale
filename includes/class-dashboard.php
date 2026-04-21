<?php
/**
 * Wholesale Customer Dashboard
 *
 * Renders a branded "My Wholesale Account" page at /wholesale-dashboard.
 * Shows order history with reorder capability, account details, quick links.
 *
 * Gated to wholesale_customer role only.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class SLW_Dashboard {

	public static function init() {
		add_shortcode( 'sego_wholesale_dashboard', array( __CLASS__, 'render' ) );
		add_action( 'wp_ajax_slw_reorder', array( __CLASS__, 'ajax_reorder' ) );
	}

	/**
	 * Render the dashboard. Non-wholesale users get redirected.
	 */
	public static function render( $atts = array() ) {
		if ( ! is_user_logged_in() || ! slw_is_wholesale_user() ) {
			if ( ! is_admin() ) {
				wp_redirect( home_url( '/wholesale-partners' ) );
				exit;
			}
			return '<div class="slw-notice slw-notice-warning">Please <a href="' . wp_login_url( home_url( '/wholesale-dashboard' ) ) . '">log in</a> with your wholesale account.</div>';
		}

		ob_start();
		include SLW_PLUGIN_DIR . 'templates/dashboard.php';
		return ob_get_clean();
	}

	/**
	 * Get paginated orders for the current wholesale user.
	 *
	 * @param int $user_id
	 * @param int $page     Current page (1-based).
	 * @param int $per_page Orders per page.
	 * @return array { orders: WC_Order[], total: int, pages: int, page: int }
	 */
	public static function get_orders( $user_id, $page = 1, $per_page = 10 ) {
		$page = max( 1, intval( $page ) );

		$orders = wc_get_orders( array(
			'customer' => $user_id,
			'limit'    => $per_page,
			'offset'   => ( $page - 1 ) * $per_page,
			'orderby'  => 'date',
			'order'    => 'DESC',
			'paginate' => true,
		) );

		return array(
			'orders' => $orders->orders,
			'total'  => $orders->total,
			'pages'  => $orders->max_num_pages,
			'page'   => $page,
		);
	}

	/**
	 * AJAX handler: re-order — adds all line items from a previous order to the cart.
	 */
	public static function ajax_reorder() {
		// Verify nonce
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'slw_reorder_nonce' ) ) {
			wp_send_json_error( array( 'message' => 'Security check failed.' ) );
		}

		if ( ! is_user_logged_in() || ! slw_is_wholesale_user() ) {
			wp_send_json_error( array( 'message' => 'You must be logged in as a wholesale customer.' ) );
		}

		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		if ( ! $order_id ) {
			wp_send_json_error( array( 'message' => 'Invalid order.' ) );
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wp_send_json_error( array( 'message' => 'Order not found.' ) );
		}

		// Verify the current user owns this order
		$current_user_id = get_current_user_id();
		if ( $order->get_customer_id() !== $current_user_id ) {
			wp_send_json_error( array( 'message' => 'You do not have permission to reorder this order.' ) );
		}

		$skipped = array();
		$added   = 0;

		foreach ( $order->get_items() as $item ) {
			$product_id   = $item->get_product_id();
			$variation_id = $item->get_variation_id();
			$quantity     = $item->get_quantity();
			$product      = wc_get_product( $variation_id ? $variation_id : $product_id );

			// Skip if product no longer exists or is out of stock
			if ( ! $product || ! $product->exists() ) {
				$skipped[] = $item->get_name() . ' (no longer available)';
				continue;
			}

			if ( ! $product->is_in_stock() ) {
				$skipped[] = $item->get_name() . ' (out of stock)';
				continue;
			}

			if ( ! $product->is_purchasable() ) {
				$skipped[] = $item->get_name() . ' (not purchasable)';
				continue;
			}

			// Get variation attributes if applicable
			$variation_data = array();
			if ( $variation_id ) {
				$variation_data = $item->get_meta_data();
				$attrs = array();
				foreach ( $variation_data as $meta ) {
					$key = $meta->key;
					if ( strpos( $key, 'pa_' ) === 0 || strpos( $key, 'attribute_' ) === 0 ) {
						$attrs[ $key ] = $meta->value;
					}
				}
				$variation_data = $attrs;
			}

			$result = WC()->cart->add_to_cart( $product_id, $quantity, $variation_id, $variation_data );

			if ( $result ) {
				$added++;
			} else {
				$skipped[] = $item->get_name() . ' (could not add to cart)';
			}
		}

		if ( $added === 0 && ! empty( $skipped ) ) {
			wp_send_json_error( array(
				'message' => 'None of the items could be added to your cart.',
				'skipped' => $skipped,
			) );
		}

		$response = array(
			'message'     => sprintf( '%d item(s) added to your cart.', $added ),
			'redirect'    => wc_get_cart_url(),
			'added'       => $added,
		);

		if ( ! empty( $skipped ) ) {
			$response['skipped'] = $skipped;
		}

		wp_send_json_success( $response );
	}
}
