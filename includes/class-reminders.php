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

	const CRON_HOOK = 'slw_daily_reorder_check';

	public static function init() {
		// Schedule daily cron if not already scheduled
		add_action( 'init', array( __CLASS__, 'schedule_cron' ) );

		// Cron callback
		add_action( self::CRON_HOOK, array( __CLASS__, 'run_reorder_check' ) );

		// Reset reminder tracking when a new order is completed
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'reset_reminder_on_order' ) );

		// Settings fields (registered via admin_init for integration into settings page)
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
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
	 * Unschedule the cron event. Called on plugin deactivation.
	 */
	public static function unschedule_cron() {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}
	}

	/**
	 * Daily cron callback: check all wholesale users and fire reminders.
	 */
	public static function run_reorder_check() {
		// Bail if reminders are disabled
		if ( ! get_option( 'slw_reminders_enabled', '1' ) ) {
			return;
		}

		$thresholds = self::get_thresholds();

		// Get all users with the wholesale_customer role
		$users = get_users( array(
			'role'   => 'wholesale_customer',
			'fields' => array( 'ID', 'user_email' ),
		) );

		if ( empty( $users ) ) {
			return;
		}

		foreach ( $users as $user ) {
			self::check_user( $user->ID, $user->user_email, $thresholds );
		}
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

		// Fire the webhook
		$user_data  = get_userdata( $user_id );
		$first_name = $user_data->first_name ?: $user_data->display_name;
		$business   = get_user_meta( $user_id, 'slw_business_name', true );

		SLW_Webhooks::fire( 'reorder-reminder', array(
			'email'            => $user_email,
			'first_name'       => $first_name,
			'business_name'    => $business,
			'days_since_order' => $days_since,
			'reminder_level'   => $applicable_level,
		) );

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
	}

	/**
	 * Get the configured thresholds, sorted descending by days.
	 *
	 * @return array {3 => 120, 2 => 75, 1 => 45}
	 */
	private static function get_thresholds() {
		$days_1 = absint( get_option( 'slw_reminder_days_1', 45 ) );
		$days_2 = absint( get_option( 'slw_reminder_days_2', 75 ) );
		$days_3 = absint( get_option( 'slw_reminder_days_3', 120 ) );

		// Return sorted descending so the highest level is checked first
		return array(
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
}
