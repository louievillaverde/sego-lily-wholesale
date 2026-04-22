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

		// Saved carts (order templates)
		add_action( 'wp_ajax_slw_save_cart', array( __CLASS__, 'ajax_save_cart' ) );
		add_action( 'wp_ajax_slw_load_cart', array( __CLASS__, 'ajax_load_cart' ) );
		add_action( 'wp_ajax_slw_delete_cart', array( __CLASS__, 'ajax_delete_cart' ) );

		// Dismiss store notice
		add_action( 'wp_ajax_slw_dismiss_notice', array( __CLASS__, 'ajax_dismiss_notice' ) );
	}

	/**
	 * Render the dashboard. Non-wholesale users get redirected.
	 */
	public static function render( $atts = array() ) {
		// Admin preview mode: let admins see what wholesale customers see
		$is_admin_preview = isset( $_GET['slw_preview'] ) && current_user_can( 'manage_woocommerce' );

		if ( ! $is_admin_preview && ( ! is_user_logged_in() || ! slw_is_wholesale_user() ) ) {
			if ( ! is_admin() ) {
				wp_redirect( home_url( '/wholesale-partners' ) );
				exit;
			}
			return '<div class="slw-notice slw-notice-warning">Please <a href="' . wp_login_url( home_url( '/wholesale-dashboard' ) ) . '">log in</a> with your wholesale account.</div>';
		}

		ob_start();

		// Show admin preview banner with "View as" customer selector
		if ( $is_admin_preview ) {
			self::render_preview_banner( 'Customer Dashboard' );
		}

		include SLW_PLUGIN_DIR . 'templates/dashboard.php';
		return ob_get_clean();
	}

	/**
	 * Render the admin preview banner with "View as Customer" dropdown.
	 */
	public static function render_preview_banner( $page_name = 'Page' ) {
		$wholesale_users = get_users( array(
			'role'    => 'wholesale_customer',
			'number'  => 50,
			'orderby' => 'display_name',
			'order'   => 'ASC',
			'fields'  => array( 'ID', 'display_name', 'user_email' ),
		) );
		$viewing_as = isset( $_GET['slw_view_as'] ) ? absint( $_GET['slw_view_as'] ) : 0;
		$current_url = remove_query_arg( 'slw_view_as' );
		?>
		<div class="slw-preview-banner" style="background:#386174;color:#F7F6F3;padding:12px 20px;border-radius:6px;margin-bottom:20px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
			<div style="display:flex;align-items:center;gap:8px;">
				<span style="background:#D4AF37;color:#1E2A30;font-size:11px;font-weight:700;padding:3px 10px;border-radius:3px;text-transform:uppercase;letter-spacing:0.5px;">Admin Preview</span>
				<span>You're previewing the <strong><?php echo esc_html( $page_name ); ?></strong> as a wholesale customer sees it.</span>
			</div>
			<?php if ( ! empty( $wholesale_users ) ) : ?>
			<form method="get" style="display:flex;align-items:center;gap:8px;margin:0;">
				<input type="hidden" name="slw_preview" value="1" />
				<label style="font-size:13px;color:#F7F6F3;opacity:0.9;">View as:</label>
				<select name="slw_view_as" style="padding:4px 8px;border-radius:4px;border:1px solid rgba(255,255,255,0.3);background:rgba(255,255,255,0.15);color:#F7F6F3;font-size:13px;" onchange="this.form.submit()">
					<option value="0">Default (admin)</option>
					<?php foreach ( $wholesale_users as $wu ) : ?>
						<option value="<?php echo esc_attr( $wu->ID ); ?>" <?php selected( $viewing_as, $wu->ID ); ?>>
							<?php echo esc_html( $wu->display_name ); ?> (<?php echo esc_html( $wu->user_email ); ?>)
						</option>
					<?php endforeach; ?>
				</select>
			</form>
			<?php endif; ?>
		</div>
		<?php
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

	// ── Saved Carts (Order Templates) ─────────────────────────────────────

	/**
	 * AJAX: Save the current WooCommerce cart as a named template.
	 */
	public static function ajax_save_cart() {
		// Accept nonce from either the order form nonce or the saved carts nonce
		$valid = false;
		if ( isset( $_POST['nonce'] ) ) {
			if ( wp_verify_nonce( $_POST['nonce'], 'slw_saved_carts' ) ) {
				$valid = true;
			} elseif ( wp_verify_nonce( $_POST['nonce'], 'slw_order_form' ) ) {
				$valid = true;
			}
		}
		if ( ! $valid ) {
			wp_send_json_error( array( 'message' => 'Security check failed.' ) );
		}

		if ( ! is_user_logged_in() || ! slw_is_wholesale_user() ) {
			wp_send_json_error( array( 'message' => 'Wholesale access required.' ) );
		}

		$name = isset( $_POST['template_name'] ) ? sanitize_text_field( $_POST['template_name'] ) : '';
		if ( empty( $name ) ) {
			wp_send_json_error( array( 'message' => 'Please enter a name for the template.' ) );
		}

		$cart = WC()->cart;
		if ( ! $cart || $cart->is_empty() ) {
			wp_send_json_error( array( 'message' => 'Your cart is empty. Add items before saving a template.' ) );
		}

		$user_id = get_current_user_id();
		$saved   = get_user_meta( $user_id, 'slw_saved_carts', true );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}

		// Enforce 10-template limit
		if ( count( $saved ) >= 10 ) {
			wp_send_json_error( array( 'message' => 'You can save up to 10 order templates. Please delete one before saving a new one.' ) );
		}

		// Build item list from current cart
		$items = array();
		foreach ( $cart->get_cart() as $cart_item ) {
			$items[] = array(
				'product_id' => absint( $cart_item['product_id'] ),
				'quantity'   => absint( $cart_item['quantity'] ),
			);
		}

		$slug = sanitize_title( $name ) . '-' . time();
		$saved[ $slug ] = array(
			'name'    => $name,
			'items'   => $items,
			'created' => current_time( 'Y-m-d' ),
		);

		update_user_meta( $user_id, 'slw_saved_carts', $saved );

		wp_send_json_success( array(
			'message' => sprintf( 'Order template "%s" saved with %d item(s).', esc_html( $name ), count( $items ) ),
		) );
	}

	/**
	 * AJAX: Load a saved cart template — clears current cart and adds all template items.
	 */
	public static function ajax_load_cart() {
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'slw_saved_carts' ) ) {
			wp_send_json_error( array( 'message' => 'Security check failed.' ) );
		}

		if ( ! is_user_logged_in() || ! slw_is_wholesale_user() ) {
			wp_send_json_error( array( 'message' => 'Wholesale access required.' ) );
		}

		$slug    = isset( $_POST['slug'] ) ? sanitize_text_field( $_POST['slug'] ) : '';
		$user_id = get_current_user_id();
		$saved   = get_user_meta( $user_id, 'slw_saved_carts', true );

		if ( ! is_array( $saved ) || ! isset( $saved[ $slug ] ) ) {
			wp_send_json_error( array( 'message' => 'Saved template not found.' ) );
		}

		$template = $saved[ $slug ];

		// Clear the current cart
		WC()->cart->empty_cart();

		$added   = 0;
		$skipped = array();

		foreach ( $template['items'] as $item ) {
			$product_id = absint( $item['product_id'] );
			$quantity   = absint( $item['quantity'] );

			$product = wc_get_product( $product_id );
			if ( ! $product || ! $product->exists() || ! $product->is_in_stock() || ! $product->is_purchasable() ) {
				$name = $product ? $product->get_name() : 'Product #' . $product_id;
				$skipped[] = $name;
				continue;
			}

			$result = WC()->cart->add_to_cart( $product_id, $quantity );
			if ( $result ) {
				$added++;
			}
		}

		$response = array(
			'message'  => sprintf( '%d item(s) added to your cart.', $added ),
			'redirect' => wc_get_cart_url(),
		);

		if ( ! empty( $skipped ) ) {
			$response['message'] .= ' Some items were skipped: ' . implode( ', ', $skipped );
		}

		wp_send_json_success( $response );
	}

	/**
	 * AJAX: Delete a saved cart template.
	 */
	public static function ajax_delete_cart() {
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'slw_saved_carts' ) ) {
			wp_send_json_error( array( 'message' => 'Security check failed.' ) );
		}

		if ( ! is_user_logged_in() || ! slw_is_wholesale_user() ) {
			wp_send_json_error( array( 'message' => 'Wholesale access required.' ) );
		}

		$slug    = isset( $_POST['slug'] ) ? sanitize_text_field( $_POST['slug'] ) : '';
		$user_id = get_current_user_id();
		$saved   = get_user_meta( $user_id, 'slw_saved_carts', true );

		if ( ! is_array( $saved ) || ! isset( $saved[ $slug ] ) ) {
			wp_send_json_error( array( 'message' => 'Saved template not found.' ) );
		}

		unset( $saved[ $slug ] );
		update_user_meta( $user_id, 'slw_saved_carts', $saved );

		wp_send_json_success( array( 'message' => 'Template deleted.' ) );
	}

	// ── Store Notice Dismiss ──────────────────────────────────────────────

	/**
	 * AJAX: Dismiss the store notice. Stores a hash of the notice text so
	 * if the admin updates the notice, it reappears.
	 */
	public static function ajax_dismiss_notice() {
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'slw_dismiss_notice' ) ) {
			wp_send_json_error( array( 'message' => 'Security check failed.' ) );
		}

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => 'Not logged in.' ) );
		}

		$notice_text = get_option( 'slw_store_notice_text', '' );
		update_user_meta( get_current_user_id(), 'slw_notice_dismissed', md5( $notice_text ) );

		wp_send_json_success();
	}
}
