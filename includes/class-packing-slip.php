<?php
/**
 * Packing slips.
 *
 * A print-optimized packing slip served at
 * ?slw_packing_slip=<order_id>&key=<order_key>, so Holly can print one straight
 * from the order and drop it in the box. Same standalone-HTML + browser-print
 * approach as the invoices, but with NO pricing, just ship-to and the items +
 * quantities a picker/packer needs. Removes her reliance on Xero for slips.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SLW_Packing_Slip {

	public static function init() {
		add_filter( 'query_vars', array( __CLASS__, 'register_query_vars' ) );
		add_action( 'template_redirect', array( __CLASS__, 'handle_request' ) );
	}

	public static function register_query_vars( $vars ) {
		$vars[] = 'slw_packing_slip';
		return $vars;
	}

	/** Shareable URL for an order's packing slip (order key is the bearer token). */
	public static function get_url( $order ) {
		if ( is_numeric( $order ) ) {
			$order = wc_get_order( $order );
		}
		if ( ! $order ) {
			return '';
		}
		return add_query_arg( array(
			'slw_packing_slip' => $order->get_id(),
			'key'              => $order->get_order_key(),
		), home_url( '/' ) );
	}

	public static function handle_request() {
		$order_id = absint( get_query_var( 'slw_packing_slip' ) );
		if ( ! $order_id ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wp_die( esc_html__( 'Order not found.', 'sego-lily-wholesale' ), 404 );
		}

		// Order key is the bearer token (same model as invoices); admins pass too.
		$provided_key = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
		if ( $provided_key !== $order->get_order_key() && ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Invalid packing slip link.', 'sego-lily-wholesale' ), 403 );
		}

		self::render( $order );
		exit;
	}

	private static function render( $order ) {
		$logo_id       = absint( SLW_Invoice_Settings::get( 'logo_id' ) );
		$logo_url      = $logo_id ? wp_get_attachment_image_url( $logo_id, 'medium' ) : '';
		$business_name = SLW_Invoice_Settings::get( 'business_name' ) ?: get_bloginfo( 'name' );
		$accent        = SLW_Invoice_Settings::get( 'accent_color' ) ?: '#386174';

		$number   = class_exists( 'SLW_PDF_Invoices' ) ? SLW_PDF_Invoices::get_invoice_number( $order ) : '#' . $order->get_order_number();
		$date     = $order->get_date_created() ? $order->get_date_created()->date_i18n( 'F j, Y' ) : '';
		$business = $order->get_user_id() ? get_user_meta( $order->get_user_id(), 'slw_business_name', true ) : '';
		$ship     = $order->get_formatted_shipping_address();
		if ( ! $ship ) {
			$ship = $order->get_formatted_billing_address();
		}
		$customer_note = $order->get_customer_note();

		// Build rows: name (with variation), SKU, quantity. No prices.
		$rows = '';
		foreach ( $order->get_items() as $item ) {
			$product = $item->get_product();
			$sku     = $product ? $product->get_sku() : '';
			$rows   .= '<tr><td>' . esc_html( $item->get_name() ) . '</td><td>' . esc_html( $sku ) . '</td><td class="qty">' . esc_html( $item->get_quantity() ) . '</td></tr>';
		}

		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Packing Slip <?php echo esc_html( $number ); ?></title>
<style>
	* { box-sizing: border-box; }
	body { font-family: -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; color: #1f2d33; margin: 0; background: #f4f7f8; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
	.sheet { max-width: 760px; margin: 24px auto; background: #fff; padding: 40px 44px; border-radius: 8px; box-shadow: 0 1px 4px rgba(0,0,0,0.08); }
	.top { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 3px solid <?php echo esc_attr( $accent ); ?>; padding-bottom: 18px; margin-bottom: 24px; }
	.top img { max-height: 64px; max-width: 220px; }
	.brand { font-size: 20px; font-weight: 700; color: <?php echo esc_attr( $accent ); ?>; }
	.doc-title { text-align: right; }
	.doc-title h1 { margin: 0; font-size: 22px; letter-spacing: 1px; text-transform: uppercase; color: <?php echo esc_attr( $accent ); ?>; }
	.doc-title .meta { font-size: 13px; color: #5b6b72; margin-top: 4px; }
	.shipto { margin-bottom: 26px; }
	.shipto .label { font-size: 11px; text-transform: uppercase; letter-spacing: 1px; color: #628393; margin-bottom: 6px; }
	.shipto .addr { font-size: 15px; line-height: 1.5; }
	.shipto .biz { font-weight: 700; }
	table { width: 100%; border-collapse: collapse; }
	thead th { text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: 1px; color: #628393; border-bottom: 2px solid #e2e9ec; padding: 8px 6px; }
	thead th.qty, tbody td.qty { text-align: right; }
	tbody td { padding: 11px 6px; border-bottom: 1px solid #eef2f4; font-size: 15px; }
	td.qty { text-align: right; font-weight: 700; }
	.note { margin-top: 22px; padding: 14px 16px; background: #f4f7f8; border-radius: 6px; font-size: 14px; }
	.note .label { font-size: 11px; text-transform: uppercase; letter-spacing: 1px; color: #628393; margin-bottom: 4px; }
	.foot { margin-top: 34px; text-align: center; color: #8aa0a8; font-size: 13px; }
	.btn-print { display: inline-block; margin: 20px auto 0; padding: 10px 22px; background: <?php echo esc_attr( $accent ); ?>; color: #fff; border: 0; border-radius: 6px; font-size: 14px; cursor: pointer; }
	.actions { text-align: center; }
	@media print {
		body { background: #fff; }
		.sheet { box-shadow: none; margin: 0; max-width: none; padding: 0; }
		.actions { display: none !important; }
	}
</style>
</head>
<body>
	<div class="sheet">
		<div class="top">
			<div>
				<?php if ( $logo_url ) : ?>
					<img src="<?php echo esc_url( $logo_url ); ?>" alt="<?php echo esc_attr( $business_name ); ?>">
				<?php else : ?>
					<div class="brand"><?php echo esc_html( $business_name ); ?></div>
				<?php endif; ?>
			</div>
			<div class="doc-title">
				<h1>Packing Slip</h1>
				<div class="meta">Order <?php echo esc_html( $number ); ?><?php echo $date ? ' &middot; ' . esc_html( $date ) : ''; ?></div>
			</div>
		</div>

		<div class="shipto">
			<div class="label">Ship to</div>
			<div class="addr">
				<?php if ( $business ) : ?><span class="biz"><?php echo esc_html( $business ); ?></span><br><?php endif; ?>
				<?php echo wp_kses_post( $ship ?: '&mdash;' ); ?>
			</div>
		</div>

		<table>
			<thead>
				<tr><th>Item</th><th>SKU</th><th class="qty">Qty</th></tr>
			</thead>
			<tbody>
				<?php echo $rows; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built with esc_html above ?>
			</tbody>
		</table>

		<?php if ( $customer_note ) : ?>
			<div class="note"><div class="label">Note</div><?php echo esc_html( $customer_note ); ?></div>
		<?php endif; ?>

		<div class="foot">Thank you for your order.</div>
		<div class="actions">
			<button type="button" class="btn-print" onclick="window.print();">Print packing slip</button>
		</div>
	</div>
</body>
</html>
		<?php
	}
}
