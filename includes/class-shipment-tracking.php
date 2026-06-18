<?php
/**
 * Shipment Tracking.
 *
 * Makes sure the customer gets their tracking number in their order email.
 *
 * Tracking is read AUTOMATICALLY from WooCommerce Shipping (the label you buy
 * on the order already carries the tracking number), so there is normally
 * nothing to type. When the order is marked Completed, the tracking number is
 * included in the customer's completed-order email. A manual field is offered
 * only as a fallback for orders shipped outside WooCommerce Shipping.
 *
 * One email: the tracking is injected into WooCommerce's existing completed
 * email rather than sending a second one.
 *
 * HPOS-compatible.
 *
 * @package Sego_Lily_Wholesale
 * @since 4.6.136
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SLW_Shipment_Tracking {

	const META_NUMBER  = '_slw_tracking_number';   // manual fallback only
	const META_CARRIER = '_slw_tracking_carrier';  // manual fallback only
	const META_SENT    = '_slw_tracking_email_sent';

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
		// Inject tracking into the customer's completed-order email (one email).
		add_action( 'woocommerce_email_after_order_table', array( __CLASS__, 'inject_into_email' ), 10, 4 );
		// On completion, stamp + fire the Mautic webhook.
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'on_completed' ), 15, 1 );
		// Manual "resend tracking" button.
		add_action( 'wp_ajax_slw_send_tracking', array( __CLASS__, 'ajax_send' ) );
	}

	/**
	 * Resolve the best tracking for an order:
	 *   1. WooCommerce Shipping / shipment-tracking standard meta (automatic)
	 *   2. WooCommerce Shipping label meta (automatic)
	 *   3. Manual fallback field
	 *
	 * @return array|null ['number','carrier_label','carrier_slug','source']
	 */
	public static function resolve_tracking( $order ) {
		// 1. Standard shipment-tracking items (this is what WooCommerce Shipping populates).
		$items = $order->get_meta( '_wc_shipment_tracking_items' );
		if ( is_string( $items ) ) {
			$items = maybe_unserialize( $items );
		}
		if ( is_array( $items ) && ! empty( $items ) ) {
			$last = end( $items );
			$num  = isset( $last['tracking_number'] ) ? (string) $last['tracking_number'] : '';
			$prov = '';
			if ( ! empty( $last['custom_tracking_provider'] ) ) {
				$prov = (string) $last['custom_tracking_provider'];
			} elseif ( ! empty( $last['tracking_provider'] ) ) {
				$prov = (string) $last['tracking_provider'];
			}
			if ( $num ) {
				return array(
					'number'        => $num,
					'carrier_label' => $prov,
					'carrier_slug'  => self::detect_carrier( $prov ),
					'source'        => 'wcshipping',
				);
			}
		}

		// 2. WooCommerce Shipping label meta.
		$labels = $order->get_meta( 'wcshipping_labels' );
		if ( is_string( $labels ) ) {
			$labels = maybe_unserialize( $labels );
		}
		if ( is_array( $labels ) && ! empty( $labels ) ) {
			$last = end( $labels );
			$num  = isset( $last['tracking'] ) ? (string) $last['tracking'] : '';
			$prov = isset( $last['carrier_id'] ) ? (string) $last['carrier_id'] : '';
			if ( $num ) {
				return array(
					'number'        => $num,
					'carrier_label' => strtoupper( $prov ),
					'carrier_slug'  => self::detect_carrier( $prov ),
					'source'        => 'wcshipping',
				);
			}
		}

		// 3. Manual fallback.
		$num = (string) $order->get_meta( self::META_NUMBER );
		if ( $num ) {
			$slug     = (string) $order->get_meta( self::META_CARRIER );
			$carriers = self::carriers();
			return array(
				'number'        => $num,
				'carrier_label' => isset( $carriers[ $slug ] ) ? $carriers[ $slug ][0] : '',
				'carrier_slug'  => $slug,
				'source'        => 'manual',
			);
		}

		return null;
	}

	private static function detect_carrier( $label ) {
		$l = strtolower( (string) $label );
		if ( strpos( $l, 'usps' ) !== false ) { return 'usps'; }
		if ( strpos( $l, 'fedex' ) !== false ) { return 'fedex'; }
		if ( strpos( $l, 'dhl' ) !== false ) { return 'dhl'; }
		if ( strpos( $l, 'ups' ) !== false ) { return 'ups'; }
		return '';
	}

	private static function carrier_url( $slug, $number ) {
		$carriers = self::carriers();
		if ( empty( $slug ) || empty( $carriers[ $slug ] ) || empty( $carriers[ $slug ][1] ) ) {
			return '';
		}
		return sprintf( $carriers[ $slug ][1], rawurlencode( $number ) );
	}

	/**
	 * Append the tracking block to the customer's completed-order email.
	 */
	public static function inject_into_email( $order, $sent_to_admin, $plain_text, $email ) {
		if ( $sent_to_admin ) {
			return;
		}
		if ( ! isset( $email->id ) || $email->id !== 'customer_completed_order' ) {
			return;
		}
		$t = self::resolve_tracking( $order );
		if ( ! $t ) {
			return;
		}
		$carrier = $t['carrier_label'];
		$url     = self::carrier_url( $t['carrier_slug'], $t['number'] );

		if ( $plain_text ) {
			echo "\n----------\nShipment tracking\n";
			if ( $carrier ) { echo 'Carrier: ' . esc_html( $carrier ) . "\n"; }
			echo 'Tracking number: ' . esc_html( $t['number'] ) . "\n";
			if ( $url ) { echo 'Track it: ' . esc_url_raw( $url ) . "\n"; }
			echo "----------\n";
			return;
		}
		?>
		<div style="margin:24px 0;padding:18px 20px;border:1px solid #d8e3e3;border-left:4px solid #2c7a7b;border-radius:6px;background:#f6fafa;">
			<p style="margin:0 0 8px;font-weight:700;color:#2c7a7b;font-size:15px;">Your shipment is on its way</p>
			<?php if ( $carrier ) : ?><p style="margin:0 0 4px;color:#555;">Carrier: <strong><?php echo esc_html( $carrier ); ?></strong></p><?php endif; ?>
			<p style="margin:0 0 4px;color:#555;">Tracking number: <strong><?php echo esc_html( $t['number'] ); ?></strong></p>
			<?php if ( $url ) : ?>
				<p style="margin:12px 0 0;"><a href="<?php echo esc_url( $url ); ?>" style="background:#2c7a7b;color:#ffffff;text-decoration:none;padding:10px 22px;border-radius:6px;font-weight:600;display:inline-block;">Track your package</a></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * On completion, stamp + fire the Mautic webhook. The completed email
	 * (firing alongside this) carries the tracking via inject_into_email().
	 */
	public static function on_completed( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		$t = self::resolve_tracking( $order );
		if ( ! $t ) {
			return;
		}
		$order->update_meta_data( self::META_SENT, current_time( 'mysql' ) );
		$order->add_order_note( sprintf( 'Tracking (%s) included in the completed-order email to the customer.', $t['number'] ) );
		$order->save();
		self::fire_webhook( $order, $t );
	}

	private static function fire_webhook( $order, $t ) {
		if ( ! class_exists( 'SLW_Webhooks' ) ) {
			return;
		}
		SLW_Webhooks::fire( 'wholesale-order-shipped', array(
			'email'           => $order->get_billing_email(),
			'first_name'      => $order->get_billing_first_name(),
			'last_name'       => $order->get_billing_last_name(),
			'order_id'        => $order->get_id(),
			'order_number'    => $order->get_order_number(),
			'tracking_number' => $t['number'],
			'carrier'         => $t['carrier_label'],
		) );
	}

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
		$t     = self::resolve_tracking( $order );
		$auto  = $t && 'wcshipping' === $t['source'];
		$sent  = (string) $order->get_meta( self::META_SENT );
		$nonce = wp_create_nonce( 'slw_tracking_' . $order->get_id() );
		?>
		<div class="slw-tracking-box" style="font-size:12px;">
			<div style="background:#eef6f6;border:1px solid #cfe3e3;border-radius:6px;padding:10px 12px;margin-bottom:10px;">
				<strong style="color:#2c7a7b;">This is automatic.</strong> When you buy a shipping label here and mark the order <strong>Completed</strong>, your customer is automatically emailed their tracking number. You normally do not need to touch anything in this box.
			</div>

			<?php if ( $t ) : ?>
				<p style="margin:0 0 8px;">
					Tracking on this order: <strong><?php echo esc_html( $t['number'] ); ?></strong><?php echo $t['carrier_label'] ? ' (' . esc_html( $t['carrier_label'] ) . ')' : ''; ?>
					<br><span style="color:<?php echo $auto ? '#2e7d32' : '#777'; ?>;"><?php echo $auto ? 'From your shipping label.' : 'Entered manually.'; ?></span>
					<?php if ( $sent ) : ?><br><span style="color:#2e7d32;">Sent to customer <?php echo esc_html( $sent ); ?>.</span><?php endif; ?>
				</p>
				<button type="button" class="button" id="slw-send-tracking" data-order="<?php echo esc_attr( $order->get_id() ); ?>" data-nonce="<?php echo esc_attr( $nonce ); ?>">Resend tracking to customer</button>
				<span style="display:block;margin:4px 0 0;color:#999;font-size:11px;">Use this only if you completed the order before buying the label.</span>
				<span id="slw-tracking-status" style="display:block;margin-top:6px;"></span>
			<?php else : ?>
				<p style="margin:0 0 8px;color:#777;">No tracking on this order yet. It will appear here once you buy the shipping label.</p>
			<?php endif; ?>

			<details style="margin-top:12px;border-top:1px solid #eee;padding-top:8px;">
				<summary style="cursor:pointer;color:#777;">Shipped this order outside WooCommerce Shipping?</summary>
				<div style="margin-top:8px;">
					<p style="margin:0 0 6px;color:#777;font-size:11px;">Only if you did NOT buy the label here. Otherwise leave this blank, your label tracking above is already handled.</p>
					<?php wp_nonce_field( 'slw_save_tracking_' . $order->get_id(), 'slw_tracking_save_nonce' ); ?>
					<input type="text" name="slw_tracking_number" placeholder="Tracking number" value="<?php echo esc_attr( (string) $order->get_meta( self::META_NUMBER ) ); ?>" style="width:100%;margin-bottom:6px;" />
					<select name="slw_tracking_carrier" style="width:100%;">
						<?php $mc = (string) $order->get_meta( self::META_CARRIER ); foreach ( self::carriers() as $slug => $c ) : ?>
							<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $mc, $slug ); ?>><?php echo esc_html( $c[0] ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
			</details>
		</div>
		<script>
		(function($){
			$('#slw-send-tracking').on('click', function(){
				var b=$(this), s=$('#slw-tracking-status');
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
	 * Save the manual fallback fields.
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
		$order->update_meta_data( self::META_NUMBER, isset( $_POST['slw_tracking_number'] ) ? sanitize_text_field( wp_unslash( $_POST['slw_tracking_number'] ) ) : '' );
		$order->update_meta_data( self::META_CARRIER, isset( $_POST['slw_tracking_carrier'] ) ? sanitize_key( wp_unslash( $_POST['slw_tracking_carrier'] ) ) : '' );
		$order->save();
	}

	/**
	 * Resend the completed-order email (which now carries the tracking).
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
		if ( ! self::resolve_tracking( $order ) ) {
			wp_send_json_error( array( 'message' => 'No tracking on this order yet. Buy the shipping label first.' ) );
		}
		$mailer = WC()->mailer();
		$emails = $mailer->get_emails();
		if ( isset( $emails['WC_Email_Customer_Completed_Order'] ) ) {
			$emails['WC_Email_Customer_Completed_Order']->trigger( $order_id );
			$order->update_meta_data( self::META_SENT, current_time( 'mysql' ) );
			$order->save();
			wp_send_json_success( array( 'message' => 'Tracking email sent to ' . $order->get_billing_email() . '.' ) );
		}
		wp_send_json_error( array( 'message' => 'Could not trigger the email.' ) );
	}
}
