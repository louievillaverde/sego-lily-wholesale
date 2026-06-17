<?php
/**
 * Shipment Tracking.
 *
 * Lets an admin add a tracking number + carrier to an order, and automatically
 * emails the customer their tracking the moment the order is marked Completed.
 * Also fires a `wholesale-order-shipped` webhook to Mautic (so the tracking
 * number lands on the contact and a Mautic flow can take over later if desired).
 *
 * Self-contained: the customer email is sent directly via wp_mail using the
 * store's configured sender, so it works with whatever mailer the site uses
 * (Brevo SMTP, etc.) without needing any campaign set up first.
 *
 * HPOS-compatible throughout (wc_get_order + order CRUD, no post meta).
 *
 * @package Sego_Lily_Wholesale
 * @since 4.6.136
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SLW_Shipment_Tracking {

	const META_NUMBER  = '_slw_tracking_number';
	const META_CARRIER = '_slw_tracking_carrier';
	const META_EMAILED = '_slw_tracking_emailed';

	/**
	 * Carrier slug => [label, tracking URL template (%s = number)].
	 */
	private static function carriers() {
		return array(
			''      => array( 'Select carrier', '' ),
			'usps'  => array( 'USPS', 'https://tools.usps.com/go/TrackConfirmAction?tLabels=%s' ),
			'ups'   => array( 'UPS', 'https://www.ups.com/track?tracknum=%s' ),
			'fedex' => array( 'FedEx', 'https://www.fedex.com/fedextrack/?trknbr=%s' ),
			'dhl'   => array( 'DHL', 'https://www.dhl.com/us-en/home/tracking.html?tracking-id=%s' ),
			'other' => array( 'Other', '' ),
		);
	}

	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_metabox' ) );
		add_action( 'woocommerce_process_shop_order_meta', array( __CLASS__, 'save_metabox' ), 30, 1 );
		// When the order is marked Completed, send tracking if we have it.
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'on_completed' ), 15, 1 );
		// Manual "send/resend" button.
		add_action( 'wp_ajax_slw_send_tracking', array( __CLASS__, 'ajax_send' ) );
	}

	/**
	 * Register the metabox on the order edit screen (HPOS-compatible).
	 */
	public static function add_metabox() {
		$screen = class_exists( '\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController' )
			&& wc_get_container()->get( \Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class )->custom_orders_table_usage_is_enabled()
			? wc_get_page_screen_id( 'shop-order' )
			: 'shop_order';

		add_meta_box(
			'slw-tracking-metabox',
			__( 'Shipment Tracking', 'sego-lily-wholesale' ),
			array( __CLASS__, 'render_metabox' ),
			$screen,
			'side',
			'default'
		);
	}

	public static function render_metabox( $post_or_order ) {
		$order = ( $post_or_order instanceof WC_Order ) ? $post_or_order : wc_get_order( $post_or_order->ID );
		if ( ! $order ) {
			echo '<p>Order not found.</p>';
			return;
		}

		$number   = (string) $order->get_meta( self::META_NUMBER );
		$carrier  = (string) $order->get_meta( self::META_CARRIER );
		$emailed  = (string) $order->get_meta( self::META_EMAILED );
		$nonce    = wp_create_nonce( 'slw_tracking_' . $order->get_id() );
		$send_lbl = $emailed ? __( 'Resend tracking email', 'sego-lily-wholesale' ) : __( 'Send tracking email now', 'sego-lily-wholesale' );
		?>
		<div class="slw-tracking-box">
			<?php wp_nonce_field( 'slw_save_tracking_' . $order->get_id(), 'slw_tracking_save_nonce' ); ?>
			<p style="margin:0 0 6px;">
				<label for="slw_tracking_number" style="display:block;font-weight:600;margin-bottom:3px;">Tracking number</label>
				<input type="text" id="slw_tracking_number" name="slw_tracking_number" value="<?php echo esc_attr( $number ); ?>" style="width:100%;" />
			</p>
			<p style="margin:0 0 8px;">
				<label for="slw_tracking_carrier" style="display:block;font-weight:600;margin-bottom:3px;">Carrier</label>
				<select id="slw_tracking_carrier" name="slw_tracking_carrier" style="width:100%;">
					<?php foreach ( self::carriers() as $slug => $c ) : ?>
						<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $carrier, $slug ); ?>><?php echo esc_html( $c[0] ); ?></option>
					<?php endforeach; ?>
				</select>
			</p>
			<p style="margin:0 0 8px;font-size:11px;color:#666;">
				Saved when you update the order. The customer is emailed their tracking automatically when the order is marked Completed.
				<?php if ( $emailed ) : ?><br><span style="color:#2e7d32;">Tracking emailed <?php echo esc_html( $emailed ); ?>.</span><?php endif; ?>
			</p>
			<button type="button" class="button" id="slw-send-tracking" data-order="<?php echo esc_attr( $order->get_id() ); ?>" data-nonce="<?php echo esc_attr( $nonce ); ?>"><?php echo esc_html( $send_lbl ); ?></button>
			<span id="slw-tracking-status" style="display:block;margin-top:6px;font-size:12px;"></span>
		</div>
		<script>
		(function($){
			$('#slw-send-tracking').on('click', function(){
				var b=$(this), s=$('#slw-tracking-status');
				var num=$('#slw_tracking_number').val();
				if(!num){ s.css('color','#b00').text('Enter a tracking number first (and save the order).'); return; }
				b.prop('disabled',true); s.css('color','#666').text('Sending...');
				$.post(ajaxurl,{action:'slw_send_tracking',order_id:b.data('order'),_nonce:b.data('nonce')},function(r){
					b.prop('disabled',false);
					if(r&&r.success){ s.css('color','#2e7d32').text(r.data&&r.data.message?r.data.message:'Sent.'); }
					else { s.css('color','#b00').text(r&&r.data&&r.data.message?r.data.message:'Could not send.'); }
				}).fail(function(){ b.prop('disabled',false); s.css('color','#b00').text('Request failed.'); });
			});
		})(jQuery);
		</script>
		<?php
	}

	/**
	 * Save the tracking fields when the order is saved. If the order is already
	 * Completed and we now have tracking that has not been emailed, send it.
	 */
	public static function save_metabox( $order_id ) {
		if ( ! isset( $_POST['slw_tracking_save_nonce'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['slw_tracking_save_nonce'] ) ), 'slw_save_tracking_' . $order_id ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		$number  = isset( $_POST['slw_tracking_number'] ) ? sanitize_text_field( wp_unslash( $_POST['slw_tracking_number'] ) ) : '';
		$carrier = isset( $_POST['slw_tracking_carrier'] ) ? sanitize_key( wp_unslash( $_POST['slw_tracking_carrier'] ) ) : '';
		$order->update_meta_data( self::META_NUMBER, $number );
		$order->update_meta_data( self::META_CARRIER, $carrier );
		$order->save();

		if ( $number && $order->has_status( 'completed' ) && ! $order->get_meta( self::META_EMAILED ) ) {
			self::maybe_send( $order );
		}
	}

	/**
	 * Fired when an order moves to Completed.
	 */
	public static function on_completed( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		if ( $order->get_meta( self::META_NUMBER ) && ! $order->get_meta( self::META_EMAILED ) ) {
			self::maybe_send( $order );
		}
	}

	/**
	 * Send the tracking email + fire the Mautic webhook, then mark as emailed.
	 *
	 * @param WC_Order $order
	 * @param bool     $force Send even if already emailed (manual resend).
	 * @return bool
	 */
	public static function maybe_send( $order, $force = false ) {
		$number = (string) $order->get_meta( self::META_NUMBER );
		if ( ! $number ) {
			return false;
		}
		if ( ! $force && $order->get_meta( self::META_EMAILED ) ) {
			return false;
		}
		$sent = self::send_email( $order );
		if ( $sent ) {
			$order->update_meta_data( self::META_EMAILED, current_time( 'mysql' ) );
			$order->add_order_note( __( 'Tracking email sent to customer.', 'sego-lily-wholesale' ) );
			$order->save();
			self::fire_webhook( $order );
		}
		return $sent;
	}

	private static function send_email( $order ) {
		$to = $order->get_billing_email();
		if ( ! $to ) {
			return false;
		}
		$number   = (string) $order->get_meta( self::META_NUMBER );
		$carrier  = (string) $order->get_meta( self::META_CARRIER );
		$carriers = self::carriers();
		$cname    = isset( $carriers[ $carrier ] ) ? $carriers[ $carrier ][0] : '';
		$url      = self::carrier_url( $carrier, $number );
		$first    = $order->get_billing_first_name();
		$store    = get_option( 'woocommerce_email_from_name', get_bloginfo( 'name' ) );
		$order_no = $order->get_order_number();

		$subject = sprintf( 'Your %s order #%s is on its way', $store, $order_no );

		$btn = $url
			? '<p style="margin:24px 0;"><a href="' . esc_url( $url ) . '" style="background:#2c7a7b;color:#ffffff;text-decoration:none;padding:12px 26px;border-radius:6px;font-weight:600;display:inline-block;">Track your package</a></p>'
			: '';

		$body  = '<div style="font-family:Georgia,serif;max-width:520px;margin:0 auto;color:#2d2d2d;line-height:1.6;">';
		$body .= '<div style="border-left:4px solid #2c7a7b;padding:4px 0 4px 16px;margin-bottom:24px;"><span style="font-size:20px;color:#2c7a7b;">' . esc_html( $store ) . '</span></div>';
		$body .= '<p>Hi ' . esc_html( $first ? $first : 'there' ) . ',</p>';
		$body .= '<p>Good news, your order is on its way. Here are your tracking details:</p>';
		$body .= '<table style="margin:16px 0;border-collapse:collapse;">';
		$body .= '<tr><td style="padding:4px 16px 4px 0;color:#777;">Order</td><td style="padding:4px 0;font-weight:600;">#' . esc_html( $order_no ) . '</td></tr>';
		if ( $cname ) {
			$body .= '<tr><td style="padding:4px 16px 4px 0;color:#777;">Carrier</td><td style="padding:4px 0;font-weight:600;">' . esc_html( $cname ) . '</td></tr>';
		}
		$body .= '<tr><td style="padding:4px 16px 4px 0;color:#777;">Tracking</td><td style="padding:4px 0;font-weight:600;">' . esc_html( $number ) . '</td></tr>';
		$body .= '</table>';
		$body .= $btn;
		$body .= '<p>Thank you for supporting Sego Lily.</p>';
		$body .= '<p style="margin-top:24px;color:#555;">Sego Lily Skincare</p>';
		$body .= '</div>';

		$from_name  = $store;
		$from_email = get_option( 'woocommerce_email_from_address', get_option( 'admin_email' ) );
		$headers    = array(
			'Content-Type: text/html; charset=UTF-8',
			sprintf( 'From: %s <%s>', $from_name, $from_email ),
		);

		return (bool) wp_mail( $to, $subject, $body, $headers );
	}

	private static function carrier_url( $carrier, $number ) {
		$carriers = self::carriers();
		if ( empty( $carrier ) || empty( $carriers[ $carrier ] ) || empty( $carriers[ $carrier ][1] ) ) {
			return '';
		}
		return sprintf( $carriers[ $carrier ][1], rawurlencode( $number ) );
	}

	private static function fire_webhook( $order ) {
		if ( ! class_exists( 'SLW_Webhooks' ) ) {
			return;
		}
		SLW_Webhooks::fire( 'wholesale-order-shipped', array(
			'email'           => $order->get_billing_email(),
			'first_name'      => $order->get_billing_first_name(),
			'last_name'       => $order->get_billing_last_name(),
			'order_id'        => $order->get_id(),
			'order_number'    => $order->get_order_number(),
			'tracking_number' => (string) $order->get_meta( self::META_NUMBER ),
			'carrier'         => (string) $order->get_meta( self::META_CARRIER ),
		) );
	}

	/**
	 * Manual send/resend from the metabox button.
	 */
	public static function ajax_send() {
		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		$nonce    = isset( $_POST['_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_nonce'] ) ) : '';
		if ( ! $order_id || ! wp_verify_nonce( $nonce, 'slw_tracking_' . $order_id ) || ! current_user_can( 'edit_shop_orders' ) ) {
			wp_send_json_error( array( 'message' => 'Permission denied.' ) );
		}
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wp_send_json_error( array( 'message' => 'Order not found.' ) );
		}
		if ( ! $order->get_meta( self::META_NUMBER ) ) {
			wp_send_json_error( array( 'message' => 'Save a tracking number on the order first.' ) );
		}
		$sent = self::maybe_send( $order, true );
		if ( $sent ) {
			wp_send_json_success( array( 'message' => 'Tracking email sent to ' . $order->get_billing_email() . '.' ) );
		}
		wp_send_json_error( array( 'message' => 'The mailer did not accept the message.' ) );
	}
}
