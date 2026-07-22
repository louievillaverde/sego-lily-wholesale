<?php
/**
 * Admin: New Wholesale Order + order tools.
 *
 * Two admin conveniences the plugin was missing, both requested by Holly:
 *
 *  1. "New Order" screen (Wholesale > New Order). Place an order on behalf of
 *     an existing wholesale customer, at that customer's wholesale pricing,
 *     without routing it through the retail flow. The order is attributed to
 *     the customer's account (counts toward their history, sets them up for
 *     reorders), NET terms are applied if the customer is on terms, shipping
 *     can be added inline, and the branded invoice is emailed on save. Keeps
 *     wholesale orders out of the retail books and off the manual Xero re-key.
 *
 *  2. A "Shipping + invoice" box on the WooCommerce order edit screen: add a
 *     shipping charge and re-send the invoice in one click, even on a Completed
 *     order (no flipping the status to edit line items first). Solves the
 *     "customer chose local pickup then decided to ship" case.
 *
 * Pure repo feature, no store-admin access needed to build; ships with the
 * plugin update. Wholesale pricing comes from the same engine the storefront
 * uses via SLW_Wholesale_Role::price_for_product().
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SLW_Admin_New_Order {

	public static function init() {
		add_action( 'admin_post_slw_create_manual_order', array( __CLASS__, 'handle_create_order' ) );
		add_action( 'admin_post_slw_add_shipping_invoice', array( __CLASS__, 'handle_add_shipping_invoice' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'register_shipping_metabox' ) );
	}

	/* =====================================================================
	   1. New Wholesale Order screen
	   ===================================================================== */

	/**
	 * Render callback for the Wholesale > New Order submenu.
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'You do not have permission to access this page.' );
		}

		self::maybe_render_notices();

		$customers = self::get_wholesale_customers();
		$catalog   = self::get_orderable_products();
		$action    = esc_url( admin_url( 'admin-post.php' ) );
		?>
		<div class="wrap">
			<h1>New Wholesale Order</h1>
			<p style="max-width:760px;color:#3a4a52;">
				Place an order for an existing wholesale customer. It's attributed to
				their account at their wholesale pricing, so it counts toward their
				history and stays out of your retail books. Add shipping here or later,
				and we'll email the invoice on save if you tick the box.
			</p>

			<?php if ( empty( $customers ) ) : ?>
				<div class="notice notice-warning"><p>No wholesale customers yet. Add one under <a href="<?php echo esc_url( admin_url( 'admin.php?page=slw-customers' ) ); ?>">Wholesale &rsaquo; Customers</a> first.</p></div>
				<?php return; ?>
			<?php endif; ?>

			<form method="post" action="<?php echo $action; ?>" id="slw-new-order-form">
				<?php wp_nonce_field( 'slw_create_manual_order' ); ?>
				<input type="hidden" name="action" value="slw_create_manual_order">

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="slw-customer">Customer</label></th>
						<td>
							<select name="customer_id" id="slw-customer" required style="min-width:360px;">
								<option value="">Select a wholesale customer&hellip;</option>
								<?php foreach ( $customers as $c ) : ?>
									<option value="<?php echo esc_attr( $c['id'] ); ?>"><?php echo esc_html( $c['label'] ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description">Their billing details and NET terms are pulled from their account automatically.</p>
						</td>
					</tr>
				</table>

				<h2 style="margin-top:8px;">Products</h2>
				<p class="description" style="margin-bottom:10px;">Prices shown are this customer tier's wholesale prices. Enter quantities for what they're ordering; leave the rest blank.</p>

				<?php if ( empty( $catalog ) ) : ?>
					<div class="notice notice-warning inline"><p>No published products found.</p></div>
				<?php else : ?>
					<?php foreach ( $catalog as $category => $rows ) : ?>
						<h3 style="margin:18px 0 6px;color:#386174;"><?php echo esc_html( $category ); ?></h3>
						<table class="widefat striped" style="max-width:760px;">
							<thead>
								<tr>
									<th style="width:52%;">Product</th>
									<th>SKU</th>
									<th style="text-align:right;">Wholesale</th>
									<th style="width:90px;text-align:right;">Qty</th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $rows as $row ) : ?>
									<tr>
										<td><?php echo esc_html( $row['label'] ); ?></td>
										<td><?php echo esc_html( $row['sku'] ); ?></td>
										<td style="text-align:right;"><?php echo wp_kses_post( wc_price( $row['wholesale'] ) ); ?></td>
										<td style="text-align:right;">
											<input type="number" min="0" step="1" name="qty[<?php echo esc_attr( $row['id'] ); ?>]" value="" style="width:72px;text-align:right;">
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php endforeach; ?>
				<?php endif; ?>

				<h2 style="margin-top:22px;">Shipping &amp; invoice</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="slw-ship-amt">Shipping charge</label></th>
						<td>
							<input type="number" min="0" step="0.01" name="shipping_amount" id="slw-ship-amt" value="" style="width:120px;" placeholder="0.00">
							<input type="text" name="shipping_label" value="Shipping" style="width:160px;margin-left:8px;" placeholder="Label (e.g. UPS)">
							<p class="description">Leave blank to add shipping later.</p>
						</td>
					</tr>
					<tr>
						<th scope="row">Invoice</th>
						<td>
							<label><input type="checkbox" name="send_invoice" value="1" checked> Email the invoice to the customer when I create this order</label>
						</td>
					</tr>
				</table>

				<?php submit_button( 'Create wholesale order' ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Handle the New Order form submission.
	 */
	public static function handle_create_order() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Permission denied.' );
		}
		check_admin_referer( 'slw_create_manual_order' );

		$customer_id = absint( $_POST['customer_id'] ?? 0 );
		$user        = $customer_id ? get_userdata( $customer_id ) : null;
		if ( ! $user ) {
			self::redirect_back_error( 'Pick a customer for the order.' );
		}

		$qty_raw = isset( $_POST['qty'] ) && is_array( $_POST['qty'] ) ? wp_unslash( $_POST['qty'] ) : array();
		$lines   = array();
		foreach ( $qty_raw as $pid => $q ) {
			$q = absint( $q );
			if ( $q > 0 ) {
				$lines[ absint( $pid ) ] = $q;
			}
		}
		if ( empty( $lines ) ) {
			self::redirect_back_error( 'Add a quantity to at least one product.' );
		}

		$order = wc_create_order( array(
			'customer_id' => $customer_id,
			'status'      => 'on-hold',
		) );
		if ( is_wp_error( $order ) ) {
			self::redirect_back_error( 'Could not create the order: ' . $order->get_error_message() );
		}

		foreach ( $lines as $pid => $q ) {
			$product = wc_get_product( $pid );
			if ( ! $product ) {
				continue;
			}
			$item_id = $order->add_product( $product, $q );
			if ( ! $item_id ) {
				continue;
			}
			// Force the customer's wholesale unit price (admin context does not
			// run the storefront price filter, so set it explicitly).
			$unit = SLW_Wholesale_Role::price_for_product( $product );
			$line = $order->get_item( $item_id );
			if ( $line ) {
				$line->set_subtotal( $unit * $q );
				$line->set_total( $unit * $q );
				$line->save();
			}
		}

		// Optional shipping line.
		$ship_amt = isset( $_POST['shipping_amount'] ) ? floatval( $_POST['shipping_amount'] ) : 0.0;
		if ( $ship_amt > 0 ) {
			$label = sanitize_text_field( wp_unslash( $_POST['shipping_label'] ?? 'Shipping' ) );
			self::add_shipping_line( $order, $ship_amt, $label ?: 'Shipping' );
		}

		self::apply_customer_details( $order, $user, $customer_id );
		self::apply_net_terms( $order, $customer_id );

		$order->add_order_note( sprintf( 'Manual wholesale order created by %s.', wp_get_current_user()->display_name ) );
		$order->calculate_totals();
		$order->save();

		// Make the order Xero-ready (NET terms + discount meta) so a connector
		// like MyWorks Sync maps it without manual re-entry.
		if ( class_exists( 'SLW_Xero_Compat' ) ) {
			SLW_Xero_Compat::sync_order_meta( $order->get_id() );
		}

		$emailed = 0;
		if ( ! empty( $_POST['send_invoice'] ) && class_exists( 'SLW_PDF_Invoices' ) ) {
			$emailed = SLW_PDF_Invoices::email_invoice( $order ) ? 1 : 0;
		}

		wp_safe_redirect( add_query_arg(
			array( 'page' => 'slw-new-order', 'created' => $order->get_id(), 'emailed' => $emailed ),
			admin_url( 'admin.php' )
		) );
		exit;
	}

	/* =====================================================================
	   2. Shipping + invoice box on the order edit screen
	   ===================================================================== */

	/**
	 * Register the metabox on both legacy (shop_order) and HPOS order screens.
	 */
	public static function register_shipping_metabox() {
		$screens = array( 'shop_order' );
		if ( function_exists( 'wc_get_page_screen_id' ) ) {
			$screens[] = wc_get_page_screen_id( 'shop-order' );
		}
		add_meta_box(
			'slw_ship_invoice',
			'Shipping + invoice',
			array( __CLASS__, 'render_shipping_metabox' ),
			array_filter( $screens ),
			'side',
			'default'
		);
	}

	/**
	 * Metabox body: add a shipping charge and resend the invoice in one click,
	 * works even on Completed orders (no need to flip status to edit items).
	 *
	 * @param WP_Post|WC_Order $post_or_order Legacy passes a WP_Post, HPOS the order.
	 */
	public static function render_shipping_metabox( $post_or_order ) {
		$order = ( $post_or_order instanceof WC_Order )
			? $post_or_order
			: wc_get_order( is_object( $post_or_order ) ? $post_or_order->ID : $post_or_order );
		if ( ! $order ) {
			return;
		}

		$oid          = $order->get_id();
		$current_ship = (float) $order->get_shipping_total();
		$nonce        = wp_create_nonce( 'slw_add_shipping_invoice_' . $oid );
		$action_url   = admin_url( 'admin-post.php' );
		// The order edit screen is itself a <form>, so a nested <form> here
		// would submit the wrong thing. Build and submit a detached form on
		// click instead.
		?>
		<?php if ( $current_ship > 0 ) : ?>
			<p style="margin:0 0 8px;color:#3a4a52;">Current shipping: <strong><?php echo wp_kses_post( wc_price( $current_ship ) ); ?></strong>. Adding here replaces it.</p>
		<?php endif; ?>
		<p style="margin:0 0 6px;">
			<label for="slw-mb-ship">Shipping charge</label><br>
			<input type="number" min="0" step="0.01" id="slw-mb-ship" style="width:100%;" placeholder="0.00">
		</p>
		<p style="margin:0 0 6px;">
			<input type="text" id="slw-mb-ship-label" value="Shipping" style="width:100%;" placeholder="Label (e.g. UPS)">
		</p>
		<p style="margin:0 0 10px;">
			<label><input type="checkbox" id="slw-mb-ship-invoice" value="1" checked> Email the updated invoice</label>
		</p>
		<button type="button" class="button button-primary" style="width:100%;" onclick="slwSubmitShipping()">Add shipping &amp; save</button>
		<script>
		function slwSubmitShipping() {
			var amt = document.getElementById('slw-mb-ship').value;
			if ( ! amt || parseFloat( amt ) <= 0 ) { alert( 'Enter a shipping amount.' ); return; }
			var f = document.createElement('form');
			f.method = 'post';
			f.action = <?php echo wp_json_encode( $action_url ); ?>;
			var fields = {
				action: 'slw_add_shipping_invoice',
				order_id: <?php echo wp_json_encode( (string) $oid ); ?>,
				_wpnonce: <?php echo wp_json_encode( $nonce ); ?>,
				shipping_amount: amt,
				shipping_label: document.getElementById('slw-mb-ship-label').value,
				send_invoice: document.getElementById('slw-mb-ship-invoice').checked ? '1' : ''
			};
			for ( var k in fields ) {
				var i = document.createElement('input');
				i.type = 'hidden'; i.name = k; i.value = fields[k];
				f.appendChild( i );
			}
			document.body.appendChild( f );
			f.submit();
		}
		</script>
		<?php
	}

	/**
	 * Handle the metabox submission.
	 */
	public static function handle_add_shipping_invoice() {
		$order_id = absint( $_POST['order_id'] ?? 0 );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Permission denied.' );
		}
		check_admin_referer( 'slw_add_shipping_invoice_' . $order_id );

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wp_die( 'Order not found.' );
		}

		$amt = isset( $_POST['shipping_amount'] ) ? floatval( $_POST['shipping_amount'] ) : 0.0;
		if ( $amt > 0 ) {
			$label = sanitize_text_field( wp_unslash( $_POST['shipping_label'] ?? 'Shipping' ) );
			self::add_shipping_line( $order, $amt, $label ?: 'Shipping' );
			$order->calculate_totals();
			$order->add_order_note( sprintf( 'Shipping (%s) added by %s.', wc_price( $amt ), wp_get_current_user()->display_name ) );
			$order->save();
		}

		$emailed = 0;
		if ( ! empty( $_POST['send_invoice'] ) && class_exists( 'SLW_PDF_Invoices' ) ) {
			$emailed = SLW_PDF_Invoices::email_invoice( $order ) ? 1 : 0;
		}

		$redirect = $order->get_edit_order_url();
		$redirect = add_query_arg( array( 'slw_shipped' => 1, 'slw_emailed' => $emailed ), $redirect );
		wp_safe_redirect( $redirect );
		exit;
	}

	/* =====================================================================
	   Helpers
	   ===================================================================== */

	/**
	 * Replace any existing shipping lines with a single flat shipping charge.
	 * Uses the order-item API so it works on any order status.
	 */
	private static function add_shipping_line( $order, $amount, $label ) {
		foreach ( $order->get_items( 'shipping' ) as $item_id => $item ) {
			$order->remove_item( $item_id );
		}
		$shipping = new WC_Order_Item_Shipping();
		$shipping->set_method_title( $label );
		$shipping->set_total( wc_format_decimal( $amount ) );
		$order->add_item( $shipping );
	}

	/**
	 * Copy billing (and shipping) details from the customer's account.
	 */
	private static function apply_customer_details( $order, $user, $customer_id ) {
		$company = get_user_meta( $customer_id, 'slw_business_name', true );

		$order->set_billing_first_name( $user->first_name );
		$order->set_billing_last_name( $user->last_name );
		$order->set_billing_email( $user->user_email );
		if ( $company ) {
			$order->set_billing_company( $company );
		}

		// Pull standard WooCommerce billing/shipping address meta if present.
		$fields = array( 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'phone' );
		foreach ( $fields as $f ) {
			$bill = get_user_meta( $customer_id, 'billing_' . $f, true );
			if ( $bill ) {
				$setter = 'set_billing_' . $f;
				if ( method_exists( $order, $setter ) ) {
					$order->{$setter}( $bill );
				}
			}
			$ship = get_user_meta( $customer_id, 'shipping_' . $f, true );
			$ship = $ship ?: $bill; // fall back to billing when no separate shipping address
			if ( $ship && 'phone' !== $f ) {
				$setter = 'set_shipping_' . $f;
				if ( method_exists( $order, $setter ) ) {
					$order->{$setter}( $ship );
				}
			}
		}
		$order->set_shipping_first_name( $user->first_name );
		$order->set_shipping_last_name( $user->last_name );
		if ( $company ) {
			$order->set_shipping_company( $company );
		}
	}

	/**
	 * Apply NET terms + due date if the customer is on terms.
	 */
	private static function apply_net_terms( $order, $customer_id ) {
		$days = class_exists( 'SLW_Gateway_Net30' ) ? (int) SLW_Gateway_Net30::get_user_net_terms( $customer_id ) : 0;
		if ( $days > 0 ) {
			$due = gmdate( 'Y-m-d', strtotime( '+' . $days . ' days', current_time( 'timestamp', true ) ) );
			$order->update_meta_data( '_slw_net_terms_days', $days );
			$order->update_meta_data( '_slw_net30_due_date', $due );
			$order->set_payment_method_title( 'NET ' . $days );
		}
	}

	/**
	 * Wholesale customers for the picker, labelled with business name + NET tag.
	 *
	 * @return array<int,array{id:int,label:string}>
	 */
	private static function get_wholesale_customers() {
		$users = get_users( array(
			'role'    => 'wholesale_customer',
			'orderby' => 'display_name',
			'order'   => 'ASC',
			'number'  => -1,
		) );

		$out = array();
		foreach ( $users as $u ) {
			$business = get_user_meta( $u->ID, 'slw_business_name', true );
			$name     = trim( $u->first_name . ' ' . $u->last_name ) ?: $u->display_name;
			$label    = $business ? sprintf( '%s (%s)', $business, $name ) : $name;
			$label   .= ' - ' . $u->user_email;
			$days     = class_exists( 'SLW_Gateway_Net30' ) ? (int) SLW_Gateway_Net30::get_user_net_terms( $u->ID ) : 0;
			if ( $days > 0 ) {
				$label .= ' [NET ' . $days . ']';
			}
			$out[] = array( 'id' => $u->ID, 'label' => $label );
		}
		return $out;
	}

	/**
	 * Orderable products grouped by category, each row carrying the real
	 * product/variation ID plus the customer-tier wholesale price. Unlike the
	 * line-sheet grouping (display only), these rows can build order line items.
	 *
	 * @return array<string,array<int,array>>
	 */
	private static function get_orderable_products() {
		$products = wc_get_products( array(
			'status'  => 'publish',
			'limit'   => -1,
			'orderby' => 'title',
			'order'   => 'ASC',
		) );

		$grouped = array();
		foreach ( $products as $product ) {
			$terms = get_the_terms( $product->get_id(), 'product_cat' );
			$cat   = ( $terms && ! is_wp_error( $terms ) ) ? $terms[0]->name : 'Uncategorized';

			if ( $product->is_type( 'variable' ) || $product->is_type( 'variable-subscription' ) ) {
				foreach ( $product->get_children() as $vid ) {
					$variation = wc_get_product( $vid );
					if ( ! $variation || ! $variation->is_purchasable() ) {
						continue;
					}
					$grouped[ $cat ][] = array(
						'id'        => $vid,
						'label'     => $product->get_name() . ' - ' . wc_get_formatted_variation( $variation, true, false ),
						'sku'       => $variation->get_sku(),
						'wholesale' => SLW_Wholesale_Role::price_for_product( $variation ),
					);
				}
			} elseif ( $product->is_type( 'simple' ) || $product->is_type( 'subscription' ) ) {
				$grouped[ $cat ][] = array(
					'id'        => $product->get_id(),
					'label'     => $product->get_name(),
					'sku'       => $product->get_sku(),
					'wholesale' => SLW_Wholesale_Role::price_for_product( $product ),
				);
			}
		}

		ksort( $grouped );
		return $grouped;
	}

	private static function maybe_render_notices() {
		if ( isset( $_GET['created'] ) ) {
			$oid  = absint( $_GET['created'] );
			$edit = admin_url( 'post.php?post=' . $oid . '&action=edit' );
			$msg  = 'Wholesale order #' . $oid . ' created';
			if ( ! empty( $_GET['emailed'] ) ) {
				$msg .= ' and the invoice was emailed';
			}
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $msg ) . '. <a href="' . esc_url( $edit ) . '">Open the order</a>.</p></div>';
		}
		if ( isset( $_GET['slw_error'] ) ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( sanitize_text_field( wp_unslash( $_GET['slw_error'] ) ) ) . '</p></div>';
		}
	}

	private static function redirect_back_error( $message ) {
		wp_safe_redirect( add_query_arg(
			array( 'page' => 'slw-new-order', 'slw_error' => rawurlencode( $message ) ),
			admin_url( 'admin.php' )
		) );
		exit;
	}
}
