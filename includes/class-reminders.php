<?php
/**
 * Automated Reorder Reminders
 *
 * Registers a daily WP-Cron event that checks every wholesale customer's last
 * completed order date. When a customer hasn't ordered in X days, fires a
 * webhook to AIOS so Mautic (or another automation) can send a reminder email.
 *
 * Three reminder levels with configurable day thresholds:
 * - Level 1: 45 days (gentle nudge)
 * - Level 2: 75 days (second reminder)
 * - Level 3: 120 days (final reminder / win-back)
 *
 * Reminder tracking is stored in user meta to prevent duplicate sends.
 * Tracking resets when the customer places a new completed order.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class SLW_Reminders {

	const CRON_HOOK       = 'slw_daily_reorder_check';
	const CART_CRON_HOOK  = 'slw_cart_abandon_check';

	public static function init() {
		// Schedule daily cron if not already scheduled
		add_action( 'init', array( __CLASS__, 'schedule_cron' ) );

		// Schedule 2-hour cart abandon cron
		add_action( 'init', array( __CLASS__, 'schedule_cart_cron' ) );

		// Register custom 2-hour interval
		add_filter( 'cron_schedules', array( __CLASS__, 'add_two_hour_schedule' ) );

		// Cron callbacks
		add_action( self::CRON_HOOK, array( __CLASS__, 'run_reorder_check' ) );
		add_action( self::CART_CRON_HOOK, array( __CLASS__, 'run_cart_abandon_check' ) );

		// Reset reminder tracking when a new order is completed
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'reset_reminder_on_order' ) );

		// Settings fields (registered via admin_init for integration into settings page)
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
	}

	/**
	 * Register a custom 2-hour cron interval for cart abandon checks.
	 *
	 * @param array $schedules Existing cron schedules.
	 * @return array
	 */
	public static function add_two_hour_schedule( $schedules ) {
		$schedules['every_two_hours'] = array(
			'interval' => 2 * HOUR_IN_SECONDS,
			'display'  => 'Every 2 Hours',
		);
		return $schedules;
	}

	/**
	 * Schedule the daily cron event if it isn't already scheduled.
	 */
	public static function schedule_cron() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Schedule the 2-hour cart abandon cron event if not already scheduled.
	 */
	public static function schedule_cart_cron() {
		if ( ! wp_next_scheduled( self::CART_CRON_HOOK ) ) {
			wp_schedule_event( time(), 'every_two_hours', self::CART_CRON_HOOK );
		}
	}

	/**
	 * Unschedule all cron events. Called on plugin deactivation.
	 */
	public static function unschedule_cron() {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}

		$cart_timestamp = wp_next_scheduled( self::CART_CRON_HOOK );
		if ( $cart_timestamp ) {
			wp_unschedule_event( $cart_timestamp, self::CART_CRON_HOOK );
		}
	}

	/**
	 * Daily cron callback: check all wholesale users and fire reminders.
	 * Also checks for payment-due-soon orders and stale applications.
	 */
	public static function run_reorder_check() {
		// Reorder reminders (levels 1-3 + lapsed)
		if ( get_option( 'slw_reminders_enabled', '1' ) ) {
			$thresholds = self::get_thresholds();

			$users = get_users( array(
				'role'   => 'wholesale_customer',
				'fields' => array( 'ID', 'user_email' ),
			) );

			if ( ! empty( $users ) ) {
				foreach ( $users as $user ) {
					self::check_user( $user->ID, $user->user_email, $thresholds );
				}
			}
		}

		// Payment-due-soon reminders (NET terms orders due in ~5 days)
		self::check_payment_due_soon();

		// Stale application alerts (pending > 72 hours)
		self::check_stale_applications();
	}

	/**
	 * Check a single wholesale user and fire a reminder webhook if needed.
	 *
	 * @param int    $user_id    User ID.
	 * @param string $user_email User email.
	 * @param array  $thresholds Array of {level => days} sorted descending.
	 */
	private static function check_user( $user_id, $user_email, $thresholds ) {
		$last_order_date = self::get_last_completed_order_date( $user_id );

		// If the user has never ordered, skip (they're brand new)
		if ( ! $last_order_date ) {
			return;
		}

		$days_since = (int) ( ( current_time( 'timestamp' ) - strtotime( $last_order_date ) ) / DAY_IN_SECONDS );

		// Determine which reminder level applies (highest threshold first)
		$applicable_level = 0;
		foreach ( $thresholds as $level => $days ) {
			if ( $days_since >= $days ) {
				$applicable_level = $level;
				break; // thresholds are sorted descending, first match is the highest
			}
		}

		if ( $applicable_level === 0 ) {
			return; // Not due for any reminder yet
		}

		// Check if we already sent this reminder level
		$last_sent_level = (int) get_user_meta( $user_id, 'slw_last_reminder_level', true );
		if ( $last_sent_level >= $applicable_level ) {
			return; // Already sent this level (or a higher one)
		}

		// Fire the webhook — level 4 (lapsed) uses a distinct event
		$user_data  = get_userdata( $user_id );
		$first_name = $user_data->first_name ?: $user_data->display_name;
		$business   = get_user_meta( $user_id, 'slw_business_name', true );

		if ( $applicable_level === 4 ) {
			SLW_Webhooks::fire( 'wholesale-lapsed', array(
				'email'            => $user_email,
				'first_name'       => $first_name,
				'business_name'    => $business,
				'days_since_order' => $days_since,
			) );
		} else {
			SLW_Webhooks::fire( 'reorder-reminder', array(
				'email'            => $user_email,
				'first_name'       => $first_name,
				'business_name'    => $business,
				'days_since_order' => $days_since,
				'reminder_level'   => $applicable_level,
			) );
		}

		// Record that we sent this level
		update_user_meta( $user_id, 'slw_last_reminder_level', $applicable_level );
		update_user_meta( $user_id, 'slw_last_reminder_date', current_time( 'mysql' ) );
	}

	/**
	 * Get the last completed order date for a user (HPOS-compatible).
	 *
	 * @param int $user_id User ID.
	 * @return string|false MySQL datetime string, or false if no orders.
	 */
	private static function get_last_completed_order_date( $user_id ) {
		$orders = wc_get_orders( array(
			'customer_id' => $user_id,
			'status'      => 'completed',
			'limit'       => 1,
			'orderby'     => 'date',
			'order'       => 'DESC',
			'return'      => 'objects',
		) );

		if ( empty( $orders ) ) {
			return false;
		}

		$order = $orders[0];
		$date  = $order->get_date_completed();
		return $date ? $date->date( 'Y-m-d H:i:s' ) : false;
	}

	/**
	 * Reset reminder tracking when a customer completes a new order.
	 *
	 * @param int $order_id WC Order ID.
	 */
	public static function reset_reminder_on_order( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$user_id = $order->get_customer_id();
		if ( ! $user_id ) {
			return;
		}

		// Only reset for wholesale customers
		$user = get_userdata( $user_id );
		if ( ! $user || ! in_array( 'wholesale_customer', (array) $user->roles, true ) ) {
			return;
		}

		delete_user_meta( $user_id, 'slw_last_reminder_level' );
		delete_user_meta( $user_id, 'slw_last_reminder_date' );

		// Reset cart abandon tracking (customer placed an order, cart is no longer abandoned)
		delete_user_meta( $user_id, 'slw_cart_abandon_notified' );

		// Remove Mautic tags so they exit the campaign segments
		$email = $user->user_email;
		SLW_Webhooks::remove_mautic_tag( $email, 'wholesale-cart-abandoned' );
		SLW_Webhooks::remove_mautic_tag( $email, 'wholesale-lapsed' );
	}

	/**
	 * Get the configured thresholds, sorted descending by days.
	 *
	 * @return array {4 => 180, 3 => 120, 2 => 75, 1 => 45}
	 */
	private static function get_thresholds() {
		$days_1 = absint( get_option( 'slw_reminder_days_1', 45 ) );
		$days_2 = absint( get_option( 'slw_reminder_days_2', 75 ) );
		$days_3 = absint( get_option( 'slw_reminder_days_3', 120 ) );
		$days_4 = absint( get_option( 'slw_reminder_days_4', 180 ) );

		// Return sorted descending so the highest level is checked first
		return array(
			4 => $days_4,
			3 => $days_3,
			2 => $days_2,
			1 => $days_1,
		);
	}

	/* ---------------------------------------------------------------
	   Settings Fields (Reorder Reminders section)
	   --------------------------------------------------------------- */

	/**
	 * Register settings fields for the Reorder Reminders section.
	 * These fields will appear when integrated into the settings page.
	 */
	public static function register_settings() {
		// Register the settings
		register_setting( 'slw_settings', 'slw_reminders_enabled', array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'default'           => '1',
		) );
		register_setting( 'slw_settings', 'slw_reminder_days_1', array(
			'type'              => 'integer',
			'sanitize_callback' => 'absint',
			'default'           => 45,
		) );
		register_setting( 'slw_settings', 'slw_reminder_days_2', array(
			'type'              => 'integer',
			'sanitize_callback' => 'absint',
			'default'           => 75,
		) );
		register_setting( 'slw_settings', 'slw_reminder_days_3', array(
			'type'              => 'integer',
			'sanitize_callback' => 'absint',
			'default'           => 120,
		) );
		register_setting( 'slw_settings', 'slw_reminder_days_4', array(
			'type'              => 'integer',
			'sanitize_callback' => 'absint',
			'default'           => 180,
		) );

		// Add settings section
		add_settings_section(
			'slw_reminders_section',
			'Reorder Reminders',
			array( __CLASS__, 'render_section_description' ),
			'slw-settings'
		);

		// Enable/disable toggle
		add_settings_field(
			'slw_reminders_enabled',
			'Enable Reminders',
			array( __CLASS__, 'render_enabled_field' ),
			'slw-settings',
			'slw_reminders_section'
		);

		// Reminder day thresholds
		add_settings_field(
			'slw_reminder_days_1',
			'First Reminder (days)',
			array( __CLASS__, 'render_days_1_field' ),
			'slw-settings',
			'slw_reminders_section'
		);

		add_settings_field(
			'slw_reminder_days_2',
			'Second Reminder (days)',
			array( __CLASS__, 'render_days_2_field' ),
			'slw-settings',
			'slw_reminders_section'
		);

		add_settings_field(
			'slw_reminder_days_3',
			'Final Reminder (days)',
			array( __CLASS__, 'render_days_3_field' ),
			'slw-settings',
			'slw_reminders_section'
		);

		add_settings_field(
			'slw_reminder_days_4',
			'Lapsed Customer (days)',
			array( __CLASS__, 'render_days_4_field' ),
			'slw-settings',
			'slw_reminders_section'
		);
	}

	public static function render_section_description() {
		echo '<p>Automatically fire AIOS webhooks when wholesale customers haven\'t ordered in a while. Each customer receives at most one reminder per level.</p>';
	}

	public static function render_enabled_field() {
		$value = get_option( 'slw_reminders_enabled', '1' );
		?>
		<label>
			<input type="checkbox" name="slw_reminders_enabled" value="1" <?php checked( $value, '1' ); ?> />
			Send reorder reminders via AIOS webhook
		</label>
		<?php
	}

	public static function render_days_1_field() {
		$value = get_option( 'slw_reminder_days_1', 45 );
		?>
		<input type="number" name="slw_reminder_days_1" value="<?php echo esc_attr( $value ); ?>" min="1" max="365" class="small-text" /> days since last order
		<p class="description">Gentle nudge. Default: 45 days.</p>
		<?php
	}

	public static function render_days_2_field() {
		$value = get_option( 'slw_reminder_days_2', 75 );
		?>
		<input type="number" name="slw_reminder_days_2" value="<?php echo esc_attr( $value ); ?>" min="1" max="365" class="small-text" /> days since last order
		<p class="description">Second reminder. Default: 75 days.</p>
		<?php
	}

	public static function render_days_3_field() {
		$value = get_option( 'slw_reminder_days_3', 120 );
		?>
		<input type="number" name="slw_reminder_days_3" value="<?php echo esc_attr( $value ); ?>" min="1" max="365" class="small-text" /> days since last order
		<p class="description">Final reminder / win-back. Default: 120 days.</p>
		<?php
	}

	public static function render_days_4_field() {
		$value = get_option( 'slw_reminder_days_4', 180 );
		?>
		<input type="number" name="slw_reminder_days_4" value="<?php echo esc_attr( $value ); ?>" min="1" max="730" class="small-text" /> days since last order
		<p class="description">Lapsed customer (fires <code>wholesale-lapsed</code> webhook instead of reorder-reminder). Default: 180 days.</p>
		<?php
	}

	/* ---------------------------------------------------------------
	   Cart Abandon Check (2-hour cron)
	   --------------------------------------------------------------- */

	/**
	 * Every 2 hours, scan wholesale customers for abandoned carts.
	 * A cart is "abandoned" when:
	 * - The user has a persistent WooCommerce cart with items
	 * - They haven't completed an order in the last 4 hours
	 * - We haven't already notified them for this cart session
	 */
	public static function run_cart_abandon_check() {
		$users = get_users( array(
			'role'   => 'wholesale_customer',
			'fields' => array( 'ID', 'user_email' ),
		) );

		if ( empty( $users ) ) {
			return;
		}

		foreach ( $users as $user ) {
			self::check_cart_abandon( $user->ID, $user->user_email );
		}
	}

	/**
	 * Check a single user for an abandoned cart and fire the webhook if needed.
	 *
	 * @param int    $user_id    User ID.
	 * @param string $user_email User email.
	 */
	private static function check_cart_abandon( $user_id, $user_email ) {
		// Check for a persistent cart
		$cart = get_user_meta( $user_id, '_woocommerce_persistent_cart_1', true );
		if ( empty( $cart ) || empty( $cart['cart'] ) ) {
			// No cart — clear any previous notification flag
			delete_user_meta( $user_id, 'slw_cart_abandon_notified' );
			return;
		}

		$cart_items = $cart['cart'];
		$item_count = 0;
		$cart_total = 0.0;

		foreach ( $cart_items as $item ) {
			$qty        = isset( $item['quantity'] ) ? (int) $item['quantity'] : 0;
			$item_count += $qty;

			// Calculate cart total from product price x quantity
			$product_id = ! empty( $item['variation_id'] ) ? $item['variation_id'] : $item['product_id'];
			$product    = wc_get_product( $product_id );
			if ( $product ) {
				$cart_total += (float) $product->get_price() * $qty;
			}
		}

		if ( $item_count < 1 ) {
			delete_user_meta( $user_id, 'slw_cart_abandon_notified' );
			return;
		}

		// Check if the user completed an order in the last 4 hours
		$recent_orders = wc_get_orders( array(
			'customer_id' => $user_id,
			'status'      => array( 'completed', 'processing' ),
			'limit'       => 1,
			'orderby'     => 'date',
			'order'       => 'DESC',
			'date_after'  => gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - ( 4 * HOUR_IN_SECONDS ) ),
			'return'      => 'ids',
		) );

		if ( ! empty( $recent_orders ) ) {
			return; // They ordered recently, not abandoned
		}

		// Check if we already notified for this cart session
		$notified_at = get_user_meta( $user_id, 'slw_cart_abandon_notified', true );
		if ( ! empty( $notified_at ) ) {
			return; // Already sent for this cart session
		}

		// Fire the webhook
		$user_data  = get_userdata( $user_id );
		$first_name = $user_data->first_name ?: $user_data->display_name;
		$business   = get_user_meta( $user_id, 'slw_business_name', true );

		SLW_Webhooks::fire( 'wholesale-cart-abandoned', array(
			'email'         => $user_email,
			'first_name'    => $first_name,
			'business_name' => $business,
			'cart_total'    => round( $cart_total, 2 ),
			'item_count'    => $item_count,
		) );

		// Mark as notified so we don't fire again until the cart resets
		update_user_meta( $user_id, 'slw_cart_abandon_notified', current_time( 'mysql' ) );
	}

	/* ---------------------------------------------------------------
	   Payment Due Soon Check (daily cron)
	   --------------------------------------------------------------- */

	/**
	 * Check NET-terms orders for upcoming payment due dates (5 days out, +/- 1 day).
	 * Fires wholesale-payment-due-soon webhook to remind the customer.
	 */
	private static function check_payment_due_soon() {
		// Query orders on NET payment terms that are not yet completed/paid
		$orders = wc_get_orders( array(
			'status'     => array( 'on-hold', 'processing' ),
			'limit'      => -1,
			'meta_key'   => '_slw_net_terms_days',
			'meta_compare' => 'EXISTS',
			'return'     => 'objects',
		) );

		if ( empty( $orders ) ) {
			return;
		}

		$now = current_time( 'timestamp' );

		foreach ( $orders as $order ) {
			$order_id = $order->get_id();

			// Skip if already sent
			if ( $order->get_meta( '_slw_payment_reminder_sent' ) ) {
				continue;
			}

			$net_days = absint( $order->get_meta( '_slw_net_terms_days' ) );
			if ( $net_days < 1 ) {
				continue;
			}

			$order_date = $order->get_date_created();
			if ( ! $order_date ) {
				continue;
			}

			$due_timestamp = strtotime( '+' . $net_days . ' days', $order_date->getTimestamp() );
			$days_until_due = (int) round( ( $due_timestamp - $now ) / DAY_IN_SECONDS );

			// Fire if due date is 4-6 days away (5 days +/- 1 day window)
			if ( $days_until_due < 4 || $days_until_due > 6 ) {
				continue;
			}

			$user_id = $order->get_customer_id();
			$email   = $order->get_billing_email();
			$first   = $order->get_billing_first_name();

			$business = '';
			if ( $user_id ) {
				$business = get_user_meta( $user_id, 'slw_business_name', true );
			}

			$due_date = date( 'Y-m-d', $due_timestamp );

			SLW_Webhooks::fire( 'wholesale-payment-due-soon', array(
				'email'         => $email,
				'first_name'    => $first,
				'business_name' => $business,
				'order_id'      => $order_id,
				'due_date'      => $due_date,
				'net_terms'     => $net_days,
			) );

			// Mark as sent to prevent duplicates
			$order->update_meta_data( '_slw_payment_reminder_sent', current_time( 'mysql' ) );
			$order->save();
		}
	}

	/* ---------------------------------------------------------------
	   Stale Application Check (daily cron)
	   --------------------------------------------------------------- */

	/**
	 * Check for wholesale applications that have been pending > 72 hours.
	 * Fires wholesale-application-stale webhook to the admin email.
	 */
	private static function check_stale_applications() {
		global $wpdb;
		$table = $wpdb->prefix . 'slw_applications';

		// Get applications pending for more than 72 hours
		$cutoff = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - ( 72 * HOUR_IN_SECONDS ) );
		$stale_apps = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, contact_name, business_name, submitted_at FROM {$table} WHERE status = 'pending' AND submitted_at < %s",
			$cutoff
		) );

		if ( empty( $stale_apps ) ) {
			return;
		}

		// Track which applications we've already flagged (option-based to prevent daily spam)
		$flagged = get_option( 'slw_stale_apps_flagged', array() );
		if ( ! is_array( $flagged ) ) {
			$flagged = array();
		}

		$admin_email = get_option( 'admin_email' );

		foreach ( $stale_apps as $app ) {
			$app_id = (int) $app->id;

			if ( in_array( $app_id, $flagged, true ) ) {
				continue; // Already flagged
			}

			$days_pending = (int) round( ( current_time( 'timestamp' ) - strtotime( $app->submitted_at ) ) / DAY_IN_SECONDS );

			SLW_Webhooks::fire( 'wholesale-application-stale', array(
				'email'          => $admin_email,
				'applicant_name' => $app->contact_name,
				'business_name'  => $app->business_name,
				'days_pending'   => $days_pending,
				'application_id' => $app_id,
			) );

			$flagged[] = $app_id;
		}

		update_option( 'slw_stale_apps_flagged', $flagged, false );
	}
}
