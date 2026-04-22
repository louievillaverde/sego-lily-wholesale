<?php
/**
 * PDF Invoice System
 *
 * Serves clean, print-optimized HTML invoices at a custom URL endpoint.
 * No external PHP libraries — the page is a standalone HTML document with
 * inline CSS. Users click "Print / Save as PDF" to export via the browser's
 * native print dialog (same approach as Stripe and FreshBooks).
 *
 * Endpoint: ?slw_invoice=ORDER_ID&key=ORDER_KEY
 * Security: order key must match, and user must be the order owner or an admin.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class SLW_PDF_Invoices {

	public static function init() {
		// Register the query var and intercept requests
		add_filter( 'query_vars', array( __CLASS__, 'register_query_vars' ) );
		add_action( 'template_redirect', array( __CLASS__, 'handle_invoice_request' ) );
		add_action( 'template_redirect', array( __CLASS__, 'handle_invoice_preview' ) );

		// Add "Download Invoice" to WooCommerce My Account > Orders
		add_filter( 'woocommerce_my_account_my_orders_actions', array( __CLASS__, 'add_my_orders_action' ), 10, 2 );

		// Admin metabox on order edit screen (HPOS-compatible)
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_order_metabox' ) );
		add_action( 'wp_ajax_slw_send_invoice', array( __CLASS__, 'ajax_send_invoice' ) );
	}

	/**
	 * Register our custom query variable.
	 */
	public static function register_query_vars( $vars ) {
		$vars[] = 'slw_invoice';
		$vars[] = 'slw_invoice_preview';
		return $vars;
	}

	/**
	 * Get the invoice URL for a given order.
	 *
	 * @param WC_Order|int $order Order object or ID.
	 * @return string
	 */
	public static function get_invoice_url( $order ) {
		if ( is_numeric( $order ) ) {
			$order = wc_get_order( $order );
		}
		if ( ! $order ) {
			return '';
		}
		return add_query_arg( array(
			'slw_invoice' => $order->get_id(),
			'key'         => $order->get_order_key(),
		), home_url( '/' ) );
	}

	/**
	 * Intercept invoice requests and serve the standalone HTML page.
	 */
	public static function handle_invoice_request() {
		$order_id = absint( get_query_var( 'slw_invoice' ) );
		if ( ! $order_id ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wp_die( esc_html__( 'Invoice not found.', 'sego-lily-wholesale' ), 404 );
		}

		// Verify security: order key must match
		$provided_key = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
		if ( $provided_key !== $order->get_order_key() ) {
			wp_die( esc_html__( 'Invalid invoice link.', 'sego-lily-wholesale' ), 403 );
		}

		// User must be the order owner or an admin
		$current_user_id = get_current_user_id();
		if ( $current_user_id !== $order->get_customer_id() && ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this invoice.', 'sego-lily-wholesale' ), 403 );
		}

		// Serve the invoice
		self::render_invoice( $order );
		exit;
	}

	/**
	 * Add "Download Invoice" link to My Account > Orders table.
	 */
	public static function add_my_orders_action( $actions, $order ) {
		if ( $order->get_status() !== 'cancelled' ) {
			$actions['slw_invoice'] = array(
				'url'  => self::get_invoice_url( $order ),
				'name' => __( 'Invoice', 'sego-lily-wholesale' ),
			);
		}
		return $actions;
	}

	/**
	 * Add a metabox to the WooCommerce order edit screen.
	 * HPOS-compatible: registers for both post type and woocommerce_page_wc-orders.
	 */
	public static function add_order_metabox() {
		$screen = class_exists( '\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController' )
			&& wc_get_container()->get( \Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class )->custom_orders_table_usage_is_enabled()
			? wc_get_page_screen_id( 'shop-order' )
			: 'shop_order';

		add_meta_box(
			'slw-invoice-metabox',
			__( 'Wholesale Invoice', 'sego-lily-wholesale' ),
			array( __CLASS__, 'render_order_metabox' ),
			$screen,
			'side',
			'default'
		);
	}

	/**
	 * Render the admin metabox content.
	 */
	public static function render_order_metabox( $post_or_order ) {
		$order = ( $post_or_order instanceof WC_Order ) ? $post_or_order : wc_get_order( $post_or_order->ID );
		if ( ! $order ) {
			echo '<p>Order not found.</p>';
			return;
		}

		$invoice_url = self::get_invoice_url( $order );
		$nonce       = wp_create_nonce( 'slw_send_invoice_' . $order->get_id() );
		?>
		<div style="text-align:center;padding:8px 0;">
			<p style="margin:0 0 12px;">
				<strong><?php echo esc_html( self::get_invoice_number( $order ) ); ?></strong>
			</p>
			<a href="<?php echo esc_url( $invoice_url ); ?>" target="_blank"
			   class="button" style="width:100%;text-align:center;margin-bottom:8px;display:block;">
				View Invoice
			</a>
			<button type="button" class="button button-primary" style="width:100%;"
					id="slw-send-invoice-btn"
					data-order-id="<?php echo esc_attr( $order->get_id() ); ?>"
					data-nonce="<?php echo esc_attr( $nonce ); ?>">
				Send Invoice to Customer
			</button>
			<p id="slw-send-invoice-status" style="margin:8px 0 0;font-size:12px;"></p>
		</div>
		<script>
		jQuery(document).ready(function($) {
			$('#slw-send-invoice-btn').on('click', function() {
				var btn = $(this);
				var status = $('#slw-send-invoice-status');
				btn.prop('disabled', true).text('Sending...');
				status.text('');
				$.post(ajaxurl, {
					action: 'slw_send_invoice',
					order_id: btn.data('order-id'),
					nonce: btn.data('nonce')
				}, function(res) {
					btn.prop('disabled', false).text('Send Invoice to Customer');
					if (res.success) {
						status.css('color', '#2e7d32').text('Invoice sent!');
					} else {
						status.css('color', '#c62828').text(res.data || 'Failed to send.');
					}
				}).fail(function() {
					btn.prop('disabled', false).text('Send Invoice to Customer');
					status.css('color', '#c62828').text('Request failed.');
				});
			});
		});
		</script>
		<?php
	}

	/**
	 * AJAX handler: email the invoice link to the customer.
	 */
	public static function ajax_send_invoice() {
		$order_id = absint( $_POST['order_id'] ?? 0 );
		$nonce    = sanitize_text_field( $_POST['nonce'] ?? '' );

		if ( ! current_user_can( 'manage_woocommerce' ) || ! wp_verify_nonce( $nonce, 'slw_send_invoice_' . $order_id ) ) {
			wp_send_json_error( 'Permission denied.' );
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wp_send_json_error( 'Order not found.' );
		}

		$to      = $order->get_billing_email();
		$subject = sprintf(
			'Invoice %s from %s',
			self::get_invoice_number( $order ),
			SLW_Invoice_Settings::get( 'business_name' )
		);

		$invoice_url   = self::get_invoice_url( $order );
		$business_name = SLW_Invoice_Settings::get( 'business_name' );
		$accent        = SLW_Invoice_Settings::get( 'accent_color' );

		$body = sprintf(
			'<div style="font-family:Georgia,serif;max-width:600px;margin:0 auto;padding:30px;">' .
			'<h2 style="color:%s;margin:0 0 16px;">%s</h2>' .
			'<p>Hello %s,</p>' .
			'<p>Your invoice <strong>%s</strong> for <strong>%s</strong> is ready.</p>' .
			'<p style="margin:24px 0;"><a href="%s" style="display:inline-block;padding:14px 28px;background:%s;color:#fff;text-decoration:none;border-radius:4px;font-weight:bold;">View Invoice</a></p>' .
			'<p style="color:#628393;font-size:14px;">You can also copy and paste this link into your browser:<br>%s</p>' .
			'<p style="margin-top:24px;">Thank you,<br>%s</p>' .
			'</div>',
			esc_attr( $accent ),
			esc_html( $business_name ),
			esc_html( $order->get_billing_first_name() ),
			esc_html( self::get_invoice_number( $order ) ),
			wp_kses_post( $order->get_formatted_order_total() ),
			esc_url( $invoice_url ),
			esc_attr( $accent ),
			esc_url( $invoice_url ),
			esc_html( $business_name )
		);

		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		$sent    = wp_mail( $to, $subject, $body, $headers );

		if ( $sent ) {
			$order->add_order_note(
				sprintf( 'Invoice emailed to %s by %s.', $to, wp_get_current_user()->display_name )
			);
			wp_send_json_success();
		} else {
			wp_send_json_error( 'Email failed to send.' );
		}
	}

	/**
	 * Get formatted invoice number for an order.
	 *
	 * @param WC_Order $order
	 * @return string
	 */
	public static function get_invoice_number( $order ) {
		$prefix = SLW_Invoice_Settings::get( 'number_prefix' );
		return $prefix . $order->get_id();
	}

	/**
	 * Determine if the order used NET payment terms.
	 *
	 * @param WC_Order $order
	 * @return bool
	 */
	private static function is_net30_order( $order ) {
		$payment_method = $order->get_payment_method();
		if ( $payment_method === 'slw_net30' ) {
			return true;
		}
		// Also check meta for older orders
		$net30_meta = $order->get_meta( '_slw_net30_order' );
		return $net30_meta === '1' || $net30_meta === 'yes';
	}

	/**
	 * Get the NET term days for an order. Checks order meta first, falls
	 * back to the user's current term, then defaults to 30.
	 *
	 * @param WC_Order $order
	 * @return int
	 */
	private static function get_order_net_days( $order ) {
		// 1. Check order-level meta (set at checkout time)
		$days = absint( $order->get_meta( '_slw_net_terms_days' ) );
		if ( $days > 0 ) {
			return $days;
		}

		// 2. Fall back to user's current setting
		$user_id = $order->get_user_id();
		if ( $user_id && class_exists( 'SLW_Gateway_Net30' ) ) {
			$user_days = SLW_Gateway_Net30::get_user_net_terms( $user_id );
			if ( $user_days > 0 ) {
				return $user_days;
			}
		}

		// 3. Default to 30 for legacy orders
		return 30;
	}

	/**
	 * Get payment status label and badge color.
	 *
	 * @param WC_Order $order
	 * @return array { label: string, color: string, bg: string }
	 */
	private static function get_payment_status( $order ) {
		$status   = $order->get_status();
		$is_net   = self::is_net30_order( $order );
		$net_days = $is_net ? self::get_order_net_days( $order ) : 0;
		$net_label = $net_days > 0 ? 'NET ' . $net_days : 'NET 30';

		if ( in_array( $status, array( 'completed', 'processing' ), true ) && ! $is_net ) {
			return array(
				'label' => 'Paid',
				'color' => '#2e7d32',
				'bg'    => '#e8f5e9',
			);
		}

		if ( $status === 'refunded' ) {
			return array(
				'label' => 'Refunded',
				'color' => '#c62828',
				'bg'    => '#fbe9e7',
			);
		}

		if ( $is_net && in_array( $status, array( 'processing', 'completed' ), true ) ) {
			return array(
				'label' => $net_label . ' - Paid',
				'color' => '#2e7d32',
				'bg'    => '#e8f5e9',
			);
		}

		if ( $is_net ) {
			return array(
				'label' => $net_label . ' - Unpaid',
				'color' => '#e65100',
				'bg'    => '#fff8e1',
			);
		}

		if ( in_array( $status, array( 'on-hold', 'pending' ), true ) ) {
			return array(
				'label' => 'Unpaid',
				'color' => '#c62828',
				'bg'    => '#fbe9e7',
			);
		}

		return array(
			'label' => ucfirst( $status ),
			'color' => '#628393',
			'bg'    => '#e3f2fd',
		);
	}

	/**
	 * Handle invoice preview requests from the settings page.
	 * Renders a sample invoice using dummy data. Admin only.
	 */
	public static function handle_invoice_preview() {
		if ( ! get_query_var( 'slw_invoice_preview' ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to preview invoices.', 'sego-lily-wholesale' ), 403 );
		}

		self::render_preview_invoice();
		exit;
	}

	/**
	 * Render a sample invoice with dummy data for the settings preview.
	 * Uses the exact same HTML template as real invoices.
	 */
	private static function render_preview_invoice() {
		// Gather settings (same as real invoice)
		$logo_id        = absint( SLW_Invoice_Settings::get( 'logo_id' ) );
		$logo_url       = $logo_id ? wp_get_attachment_image_url( $logo_id, 'medium' ) : '';
		$business_name  = SLW_Invoice_Settings::get( 'business_name' );
		$business_addr  = SLW_Invoice_Settings::get( 'business_address' );
		$business_phone = SLW_Invoice_Settings::get( 'business_phone' );
		$business_email = SLW_Invoice_Settings::get( 'business_email' );
		$accent         = SLW_Invoice_Settings::get( 'accent_color' );
		$footer_text    = SLW_Invoice_Settings::get( 'footer_text' );
		$terms_note     = SLW_Invoice_Settings::get( 'payment_terms' );
		$prefix         = SLW_Invoice_Settings::get( 'number_prefix' );
		$invoice_number = $prefix . '1234';

		// Dummy data
		$invoice_date   = date_i18n( 'F j, Y' );
		$due_date       = date_i18n( 'F j, Y', time() + ( 30 * DAY_IN_SECONDS ) );
		$billing_name   = 'Jane Doe';
		$billing_company = 'Acme Beauty Boutique';
		$billing_address = '123 Main Street<br>Suite 200<br>Salt Lake City, UT 84101';
		$billing_email  = 'jane@acmebeauty.com';
		$is_net30       = true;

		$payment_status = array(
			'label' => 'NET 30 - Unpaid',
			'color' => '#e65100',
			'bg'    => '#fff8e1',
		);

		// Use real products from the store if available
		$dummy_items = array();
		if ( function_exists( 'wc_get_products' ) ) {
			$real_products = wc_get_products( array( 'limit' => 3, 'status' => 'publish', 'orderby' => 'date', 'order' => 'DESC' ) );
			$qtys = array( 24, 12, 36 );
			$i = 0;
			foreach ( $real_products as $prod ) {
				$discount = absint( get_option( 'slw_discount_percent', 50 ) );
				$wholesale_price = (float) $prod->get_regular_price() * ( 1 - $discount / 100 );
				$dummy_items[] = array(
					'name'  => $prod->get_name(),
					'sku'   => $prod->get_sku() ?: 'SKU-' . $prod->get_id(),
					'qty'   => $qtys[ $i ] ?? 12,
					'price' => round( $wholesale_price, 2 ),
				);
				$i++;
			}
		}
		// Fallback if no products exist
		if ( empty( $dummy_items ) ) {
			$dummy_items = array(
				array( 'name' => 'Sample Product A', 'sku' => 'SP-001', 'qty' => 24, 'price' => 18.00 ),
				array( 'name' => 'Sample Product B', 'sku' => 'SP-002', 'qty' => 12, 'price' => 14.50 ),
				array( 'name' => 'Sample Product C', 'sku' => 'SP-003', 'qty' => 36, 'price' => 22.00 ),
			);
		}

		$subtotal = 0;
		foreach ( $dummy_items as $item ) {
			$subtotal += $item['qty'] * $item['price'];
		}
		$shipping = 12.50;
		$total    = $subtotal + $shipping;

		// Compute accent-light (10% opacity)
		$accent_light = $accent . '1a';

		header( 'Content-Type: text/html; charset=utf-8' );
		?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Preview - <?php echo esc_html( $invoice_number ); ?></title>
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body {
	font-family: Inter, system-ui, -apple-system, sans-serif;
	font-size: 13px;
	line-height: 1.5;
	color: #1E2A30;
	background: #f9f9f9;
	-webkit-print-color-adjust: exact;
	print-color-adjust: exact;
}
.invoice-container {
	max-width: 760px;
	margin: 12px auto;
	background: #fff;
	border-radius: 6px;
	box-shadow: 0 1px 3px rgba(0,0,0,0.1);
	overflow: hidden;
}
.invoice-header, .invoice-title-bar { padding-left: 32px; padding-right: 32px; }
.invoice-body { padding: 24px 32px; }
.invoice-footer { padding: 16px 32px; }
.invoice-preview-badge {
	display: block;
	background: #D4AF37;
	color: #fff;
	text-align: center;
	padding: 6px;
	font-size: 11px;
	font-weight: 700;
	text-transform: uppercase;
	letter-spacing: 1px;
}
.invoice-header {
	display: flex;
	justify-content: space-between;
	align-items: flex-start;
	padding: 40px 48px 32px;
	border-bottom: 3px solid <?php echo esc_attr( $accent ); ?>;
}
.invoice-logo img { max-width: 180px; max-height: 64px; display: block; }
.invoice-logo-text {
	font-family: Georgia, 'Times New Roman', serif;
	font-size: 24px;
	font-weight: 700;
	color: <?php echo esc_attr( $accent ); ?>;
}
.invoice-business-info {
	text-align: right;
	font-size: 13px;
	color: #628393;
	line-height: 1.7;
}
.invoice-business-info strong {
	color: #1E2A30;
	font-size: 15px;
	display: block;
	margin-bottom: 4px;
}
.invoice-title-bar {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 24px 48px;
	background: <?php echo esc_attr( $accent_light ); ?>;
}
.invoice-title {
	font-family: Georgia, 'Times New Roman', serif;
	font-size: 28px;
	font-weight: 700;
	color: <?php echo esc_attr( $accent ); ?>;
	letter-spacing: 2px;
	text-transform: uppercase;
}
.invoice-meta { text-align: right; font-size: 13px; line-height: 1.8; }
.invoice-meta strong { color: #1E2A30; }
.invoice-meta span { color: #628393; }
.invoice-body { padding: 32px 48px; }
.invoice-parties {
	display: flex;
	justify-content: space-between;
	align-items: flex-start;
	margin-bottom: 32px;
}
.invoice-bill-to h3 {
	font-family: Georgia, 'Times New Roman', serif;
	font-size: 11px;
	text-transform: uppercase;
	letter-spacing: 1.5px;
	color: #628393;
	margin-bottom: 8px;
	font-weight: 600;
}
.invoice-bill-to .bill-to-name { font-size: 16px; font-weight: 700; color: #1E2A30; margin-bottom: 2px; }
.invoice-bill-to .bill-to-company { font-size: 14px; color: #386174; margin-bottom: 4px; }
.invoice-bill-to .bill-to-detail { font-size: 13px; color: #628393; line-height: 1.6; }
.invoice-status-badge {
	display: inline-block;
	padding: 8px 20px;
	border-radius: 20px;
	font-size: 13px;
	font-weight: 700;
	letter-spacing: 0.5px;
	text-transform: uppercase;
}
.invoice-table { width: 100%; border-collapse: collapse; margin-bottom: 24px; }
.invoice-table thead th {
	font-size: 11px;
	text-transform: uppercase;
	letter-spacing: 1px;
	color: #628393;
	font-weight: 600;
	padding: 12px 0;
	border-bottom: 2px solid #e0ddd8;
	text-align: left;
}
.invoice-table thead th.text-right { text-align: right; }
.invoice-table tbody td {
	padding: 14px 0;
	border-bottom: 1px solid #f0eeea;
	font-size: 14px;
	vertical-align: top;
}
.invoice-table tbody td.text-right { text-align: right; }
.invoice-table .item-name { font-weight: 600; color: #1E2A30; }
.invoice-table .item-sku { font-size: 12px; color: #8A9499; margin-top: 2px; }
.invoice-totals { display: flex; justify-content: flex-end; margin-bottom: 32px; }
.invoice-totals-table { width: 280px; }
.invoice-totals-table .total-row {
	display: flex;
	justify-content: space-between;
	padding: 8px 0;
	font-size: 14px;
	color: #628393;
}
.invoice-totals-table .total-row.grand-total {
	border-top: 2px solid <?php echo esc_attr( $accent ); ?>;
	margin-top: 8px;
	padding-top: 12px;
	font-size: 18px;
	font-weight: 700;
	color: #1E2A30;
}
.invoice-totals-table .total-row.grand-total .total-amount {
	color: <?php echo esc_attr( $accent ); ?>;
}
.invoice-terms {
	background: #fffdf5;
	border: 1px solid #f0e6c0;
	border-radius: 6px;
	padding: 16px 20px;
	margin-bottom: 24px;
	font-size: 13px;
	color: #8B6914;
}
.invoice-terms strong { display: block; margin-bottom: 4px; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px; }
.invoice-footer {
	border-top: 1px solid #e0ddd8;
	padding: 24px 48px;
	text-align: center;
	font-size: 13px;
	color: #628393;
	line-height: 1.8;
}
</style>
</head>
<body>

<div class="invoice-container">
	<div class="invoice-preview-badge">Sample Preview &mdash; Not a Real Invoice</div>

	<div class="invoice-header">
		<div class="invoice-logo">
			<?php if ( $logo_url ) : ?>
				<img src="<?php echo esc_url( $logo_url ); ?>" alt="<?php echo esc_attr( $business_name ); ?>" />
			<?php else : ?>
				<div class="invoice-logo-text"><?php echo esc_html( $business_name ); ?></div>
			<?php endif; ?>
		</div>
		<div class="invoice-business-info">
			<strong><?php echo esc_html( $business_name ); ?></strong>
			<?php if ( $business_addr ) : ?>
				<?php echo nl2br( esc_html( $business_addr ) ); ?><br>
			<?php endif; ?>
			<?php if ( $business_phone ) : ?>
				<?php echo esc_html( $business_phone ); ?><br>
			<?php endif; ?>
			<?php if ( $business_email ) : ?>
				<?php echo esc_html( $business_email ); ?>
			<?php endif; ?>
		</div>
	</div>

	<div class="invoice-title-bar">
		<div class="invoice-title">Invoice</div>
		<div class="invoice-meta">
			<span>Invoice #:</span> <strong><?php echo esc_html( $invoice_number ); ?></strong><br>
			<span>Date:</span> <strong><?php echo esc_html( $invoice_date ); ?></strong><br>
			<span>Due:</span> <strong><?php echo esc_html( $due_date ); ?></strong>
		</div>
	</div>

	<div class="invoice-body">
		<div class="invoice-parties">
			<div class="invoice-bill-to">
				<h3>Bill To</h3>
				<div class="bill-to-name"><?php echo esc_html( $billing_name ); ?></div>
				<div class="bill-to-company"><?php echo esc_html( $billing_company ); ?></div>
				<div class="bill-to-detail"><?php echo wp_kses_post( $billing_address ); ?></div>
				<div class="bill-to-detail"><?php echo esc_html( $billing_email ); ?></div>
			</div>
			<div>
				<span class="invoice-status-badge"
					  style="color:<?php echo esc_attr( $payment_status['color'] ); ?>;background:<?php echo esc_attr( $payment_status['bg'] ); ?>;">
					<?php echo esc_html( $payment_status['label'] ); ?>
				</span>
			</div>
		</div>

		<table class="invoice-table">
			<thead>
				<tr>
					<th style="width:45%;">Product</th>
					<th style="width:15%;">SKU</th>
					<th class="text-right" style="width:10%;">Qty</th>
					<th class="text-right" style="width:15%;">Unit Price</th>
					<th class="text-right" style="width:15%;">Total</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $dummy_items as $item ) : ?>
				<tr>
					<td>
						<div class="item-name"><?php echo esc_html( $item['name'] ); ?></div>
						<div class="item-sku"><?php echo esc_html( $item['sku'] ); ?></div>
					</td>
					<td><?php echo esc_html( $item['sku'] ); ?></td>
					<td class="text-right"><?php echo esc_html( $item['qty'] ); ?></td>
					<td class="text-right">$<?php echo esc_html( number_format( $item['price'], 2 ) ); ?></td>
					<td class="text-right">$<?php echo esc_html( number_format( $item['qty'] * $item['price'], 2 ) ); ?></td>
				</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<div class="invoice-totals">
			<div class="invoice-totals-table">
				<div class="total-row">
					<span>Subtotal</span>
					<span>$<?php echo esc_html( number_format( $subtotal, 2 ) ); ?></span>
				</div>
				<div class="total-row">
					<span>Shipping</span>
					<span>$<?php echo esc_html( number_format( $shipping, 2 ) ); ?></span>
				</div>
				<div class="total-row grand-total">
					<span>Total</span>
					<span class="total-amount">$<?php echo esc_html( number_format( $total, 2 ) ); ?></span>
				</div>
			</div>
		</div>

		<?php if ( $terms_note ) : ?>
		<div class="invoice-terms">
			<strong>Payment Terms</strong>
			<?php echo esc_html( $terms_note ); ?>
		</div>
		<?php endif; ?>
	</div>

	<div class="invoice-footer">
		<?php echo nl2br( esc_html( $footer_text ) ); ?>
	</div>
</div>

</body>
</html>
		<?php
	}

	/**
	 * Render the full standalone HTML invoice page and exit.
	 *
	 * @param WC_Order $order
	 */
	private static function render_invoice( $order ) {
		// Gather settings
		$logo_id        = absint( SLW_Invoice_Settings::get( 'logo_id' ) );
		$logo_url       = $logo_id ? wp_get_attachment_image_url( $logo_id, 'medium' ) : '';
		$business_name  = SLW_Invoice_Settings::get( 'business_name' );
		$business_addr  = SLW_Invoice_Settings::get( 'business_address' );
		$business_phone = SLW_Invoice_Settings::get( 'business_phone' );
		$business_email = SLW_Invoice_Settings::get( 'business_email' );
		$accent         = SLW_Invoice_Settings::get( 'accent_color' );
		$footer_text    = SLW_Invoice_Settings::get( 'footer_text' );
		$terms_note     = SLW_Invoice_Settings::get( 'payment_terms' );
		$invoice_number = self::get_invoice_number( $order );
		$is_net30       = self::is_net30_order( $order );
		$net_days       = $is_net30 ? self::get_order_net_days( $order ) : 0;
		$payment_status = self::get_payment_status( $order );

		// Dates
		$order_date = $order->get_date_created();
		$invoice_date = $order_date ? $order_date->date_i18n( 'F j, Y' ) : date_i18n( 'F j, Y' );
		$due_date = $is_net30 && $order_date
			? date_i18n( 'F j, Y', $order_date->getTimestamp() + ( $net_days * DAY_IN_SECONDS ) )
			: 'Due on receipt';

		// Billing
		$billing_name     = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
		$billing_company  = $order->get_billing_company();
		$billing_address  = $order->get_formatted_billing_address();
		$billing_email    = $order->get_billing_email();

		// Line items
		$items = $order->get_items();

		// Compute accent-light (10% opacity)
		$accent_light = $accent . '1a';

		header( 'Content-Type: text/html; charset=utf-8' );
		?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo esc_html( $invoice_number ); ?> - <?php echo esc_html( $business_name ); ?></title>
<style>
/* Reset */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

body {
	font-family: Inter, system-ui, -apple-system, sans-serif;
	font-size: 14px;
	line-height: 1.6;
	color: #1E2A30;
	background: #f5f5f5;
	-webkit-print-color-adjust: exact;
	print-color-adjust: exact;
}

.invoice-container {
	max-width: 800px;
	margin: 24px auto;
	background: #fff;
	border-radius: 8px;
	box-shadow: 0 1px 3px rgba(0,0,0,0.1);
	overflow: hidden;
}

/* Header */
.invoice-header {
	display: flex;
	justify-content: space-between;
	align-items: flex-start;
	padding: 40px 48px 32px;
	border-bottom: 3px solid <?php echo esc_attr( $accent ); ?>;
}

.invoice-logo img {
	max-width: 180px;
	max-height: 64px;
	display: block;
}

.invoice-logo-text {
	font-family: Georgia, 'Times New Roman', serif;
	font-size: 24px;
	font-weight: 700;
	color: <?php echo esc_attr( $accent ); ?>;
}

.invoice-business-info {
	text-align: right;
	font-size: 13px;
	color: #628393;
	line-height: 1.7;
}

.invoice-business-info strong {
	color: #1E2A30;
	font-size: 15px;
	display: block;
	margin-bottom: 4px;
}

/* Title bar */
.invoice-title-bar {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 24px 48px;
	background: <?php echo esc_attr( $accent_light ); ?>;
}

.invoice-title {
	font-family: Georgia, 'Times New Roman', serif;
	font-size: 28px;
	font-weight: 700;
	color: <?php echo esc_attr( $accent ); ?>;
	letter-spacing: 2px;
	text-transform: uppercase;
}

.invoice-meta {
	text-align: right;
	font-size: 13px;
	line-height: 1.8;
}

.invoice-meta strong {
	color: #1E2A30;
}

.invoice-meta span {
	color: #628393;
}

/* Body */
.invoice-body {
	padding: 32px 48px;
}

/* Bill To + Payment Status */
.invoice-parties {
	display: flex;
	justify-content: space-between;
	align-items: flex-start;
	margin-bottom: 32px;
}

.invoice-bill-to h3 {
	font-family: Georgia, 'Times New Roman', serif;
	font-size: 11px;
	text-transform: uppercase;
	letter-spacing: 1.5px;
	color: #628393;
	margin-bottom: 8px;
	font-weight: 600;
}

.invoice-bill-to .bill-to-name {
	font-size: 16px;
	font-weight: 700;
	color: #1E2A30;
	margin-bottom: 2px;
}

.invoice-bill-to .bill-to-company {
	font-size: 14px;
	color: #386174;
	margin-bottom: 4px;
}

.invoice-bill-to .bill-to-detail {
	font-size: 13px;
	color: #628393;
	line-height: 1.6;
}

.invoice-status-badge {
	display: inline-block;
	padding: 8px 20px;
	border-radius: 20px;
	font-size: 13px;
	font-weight: 700;
	letter-spacing: 0.5px;
	text-transform: uppercase;
}

/* Line items table */
.invoice-table {
	width: 100%;
	border-collapse: collapse;
	margin-bottom: 24px;
}

.invoice-table thead th {
	font-size: 11px;
	text-transform: uppercase;
	letter-spacing: 1px;
	color: #628393;
	font-weight: 600;
	padding: 12px 0;
	border-bottom: 2px solid #e0ddd8;
	text-align: left;
}

.invoice-table thead th.text-right {
	text-align: right;
}

.invoice-table tbody td {
	padding: 14px 0;
	border-bottom: 1px solid #f0eeea;
	font-size: 14px;
	vertical-align: top;
}

.invoice-table tbody td.text-right {
	text-align: right;
}

.invoice-table .item-name {
	font-weight: 600;
	color: #1E2A30;
}

.invoice-table .item-sku {
	font-size: 12px;
	color: #8A9499;
	margin-top: 2px;
}

/* Totals */
.invoice-totals {
	display: flex;
	justify-content: flex-end;
	margin-bottom: 32px;
}

.invoice-totals-table {
	width: 280px;
}

.invoice-totals-table .total-row {
	display: flex;
	justify-content: space-between;
	padding: 8px 0;
	font-size: 14px;
	color: #628393;
}

.invoice-totals-table .total-row.grand-total {
	border-top: 2px solid <?php echo esc_attr( $accent ); ?>;
	margin-top: 8px;
	padding-top: 12px;
	font-size: 18px;
	font-weight: 700;
	color: #1E2A30;
}

.invoice-totals-table .total-row.grand-total .total-amount {
	color: <?php echo esc_attr( $accent ); ?>;
}

/* Payment terms */
.invoice-terms {
	background: #fffdf5;
	border: 1px solid #f0e6c0;
	border-radius: 6px;
	padding: 16px 20px;
	margin-bottom: 24px;
	font-size: 13px;
	color: #8B6914;
}

.invoice-terms strong {
	display: block;
	margin-bottom: 4px;
	font-size: 12px;
	text-transform: uppercase;
	letter-spacing: 0.5px;
}

/* Footer */
.invoice-footer {
	border-top: 1px solid #e0ddd8;
	padding: 24px 48px;
	text-align: center;
	font-size: 13px;
	color: #628393;
	line-height: 1.8;
}

/* Print/Download buttons */
.invoice-actions {
	position: fixed;
	bottom: 24px;
	right: 24px;
	display: flex;
	gap: 8px;
	z-index: 100;
}

.invoice-actions button {
	display: inline-flex;
	align-items: center;
	gap: 8px;
	padding: 12px 24px;
	font-family: Inter, system-ui, sans-serif;
	font-size: 14px;
	font-weight: 600;
	border: none;
	border-radius: 6px;
	cursor: pointer;
	box-shadow: 0 2px 8px rgba(0,0,0,0.15);
	transition: transform 0.1s, box-shadow 0.1s;
}

.invoice-actions button:hover {
	transform: translateY(-1px);
	box-shadow: 0 4px 12px rgba(0,0,0,0.2);
}

.invoice-actions .btn-print {
	background: <?php echo esc_attr( $accent ); ?>;
	color: #fff;
}

.invoice-actions .btn-download {
	background: #1E2A30;
	color: #fff;
}

/* Print styles */
@media print {
	body {
		background: #fff;
	}

	.invoice-container {
		max-width: none;
		margin: 0;
		box-shadow: none;
		border-radius: 0;
	}

	.invoice-header {
		padding: 24px 32px 20px;
	}

	.invoice-title-bar {
		padding: 16px 32px;
	}

	.invoice-body {
		padding: 24px 32px;
	}

	.invoice-footer {
		padding: 16px 32px;
	}

	.invoice-actions {
		display: none !important;
	}

	.invoice-table tbody tr {
		page-break-inside: avoid;
	}

	@page {
		margin: 0.5in;
	}
}
</style>
</head>
<body>

<div class="invoice-container">
	<!-- Header: Logo + Business Info -->
	<div class="invoice-header">
		<div class="invoice-logo">
			<?php if ( $logo_url ) : ?>
				<img src="<?php echo esc_url( $logo_url ); ?>" alt="<?php echo esc_attr( $business_name ); ?>" />
			<?php else : ?>
				<div class="invoice-logo-text"><?php echo esc_html( $business_name ); ?></div>
			<?php endif; ?>
		</div>
		<div class="invoice-business-info">
			<strong><?php echo esc_html( $business_name ); ?></strong>
			<?php if ( $business_addr ) : ?>
				<?php echo nl2br( esc_html( $business_addr ) ); ?><br>
			<?php endif; ?>
			<?php if ( $business_phone ) : ?>
				<?php echo esc_html( $business_phone ); ?><br>
			<?php endif; ?>
			<?php if ( $business_email ) : ?>
				<?php echo esc_html( $business_email ); ?>
			<?php endif; ?>
		</div>
	</div>

	<!-- Title bar: INVOICE + meta -->
	<div class="invoice-title-bar">
		<div class="invoice-title">Invoice</div>
		<div class="invoice-meta">
			<span>Invoice #:</span> <strong><?php echo esc_html( $invoice_number ); ?></strong><br>
			<span>Date:</span> <strong><?php echo esc_html( $invoice_date ); ?></strong><br>
			<span>Due:</span> <strong><?php echo esc_html( $due_date ); ?></strong>
		</div>
	</div>

	<div class="invoice-body">
		<!-- Bill To + Payment Status -->
		<div class="invoice-parties">
			<div class="invoice-bill-to">
				<h3>Bill To</h3>
				<div class="bill-to-name"><?php echo esc_html( $billing_name ); ?></div>
				<?php if ( $billing_company ) : ?>
					<div class="bill-to-company"><?php echo esc_html( $billing_company ); ?></div>
				<?php endif; ?>
				<div class="bill-to-detail">
					<?php echo wp_kses_post( $billing_address ); ?>
				</div>
				<?php if ( $billing_email ) : ?>
					<div class="bill-to-detail"><?php echo esc_html( $billing_email ); ?></div>
				<?php endif; ?>
			</div>
			<div>
				<span class="invoice-status-badge"
					  style="color:<?php echo esc_attr( $payment_status['color'] ); ?>;background:<?php echo esc_attr( $payment_status['bg'] ); ?>;">
					<?php echo esc_html( $payment_status['label'] ); ?>
				</span>
			</div>
		</div>

		<!-- Line Items -->
		<table class="invoice-table">
			<thead>
				<tr>
					<th style="width:45%;">Product</th>
					<th style="width:15%;">SKU</th>
					<th class="text-right" style="width:10%;">Qty</th>
					<th class="text-right" style="width:15%;">Unit Price</th>
					<th class="text-right" style="width:15%;">Total</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $items as $item ) :
					$product   = $item->get_product();
					$sku       = $product ? $product->get_sku() : '';
					$qty       = $item->get_quantity();
					$line_total = $item->get_total();
					$unit_price = $qty > 0 ? $line_total / $qty : 0;
				?>
				<tr>
					<td>
						<div class="item-name"><?php echo esc_html( $item->get_name() ); ?></div>
						<?php if ( $sku ) : ?>
							<div class="item-sku"><?php echo esc_html( $sku ); ?></div>
						<?php endif; ?>
					</td>
					<td><?php echo esc_html( $sku ?: '-' ); ?></td>
					<td class="text-right"><?php echo esc_html( $qty ); ?></td>
					<td class="text-right"><?php echo wp_kses_post( wc_price( $unit_price ) ); ?></td>
					<td class="text-right"><?php echo wp_kses_post( wc_price( $line_total ) ); ?></td>
				</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<!-- Totals -->
		<div class="invoice-totals">
			<div class="invoice-totals-table">
				<div class="total-row">
					<span>Subtotal</span>
					<span><?php echo wp_kses_post( wc_price( $order->get_subtotal() ) ); ?></span>
				</div>
				<?php if ( (float) $order->get_total_tax() > 0 ) : ?>
				<div class="total-row">
					<span>Tax</span>
					<span><?php echo wp_kses_post( wc_price( $order->get_total_tax() ) ); ?></span>
				</div>
				<?php endif; ?>
				<?php if ( (float) $order->get_shipping_total() > 0 ) : ?>
				<div class="total-row">
					<span>Shipping</span>
					<span><?php echo wp_kses_post( wc_price( $order->get_shipping_total() ) ); ?></span>
				</div>
				<?php endif; ?>
				<?php if ( (float) $order->get_total_discount() > 0 ) : ?>
				<div class="total-row">
					<span>Discount</span>
					<span>-<?php echo wp_kses_post( wc_price( $order->get_total_discount() ) ); ?></span>
				</div>
				<?php endif; ?>
				<div class="total-row grand-total">
					<span>Total</span>
					<span class="total-amount"><?php echo wp_kses_post( wc_price( $order->get_total() ) ); ?></span>
				</div>
			</div>
		</div>

		<?php if ( $is_net30 && $terms_note ) : ?>
		<!-- Payment Terms -->
		<div class="invoice-terms">
			<strong>Payment Terms</strong>
			<?php echo esc_html( $terms_note ); ?>
		</div>
		<?php endif; ?>
	</div>

	<!-- Footer -->
	<div class="invoice-footer">
		<?php echo nl2br( esc_html( $footer_text ) ); ?>
	</div>
</div>

<!-- Floating action buttons -->
<div class="invoice-actions">
	<button type="button" class="btn-print" onclick="window.print();">
		<svg width="16" height="16" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg">
			<path d="M4 1h8v3H4V1zm-2 3h12a2 2 0 012 2v5h-3v4H3v-4H0V6a2 2 0 012-2zm3 7h6v3H5v-3zm7-4a1 1 0 100 2 1 1 0 000-2z" fill="currentColor"/>
		</svg>
		Print / Save as PDF
	</button>
</div>

</body>
</html>
		<?php
	}
}
