<?php
/**
 * Wholesale Tiers
 *
 * Core tier logic: get/set user tier, calculate effective discount,
 * auto-upgrade on order completion, upgrade history tracking.
 *
 * Tier data is stored in user meta (slw_wholesale_tier). The tier
 * discount REPLACES the global discount for that user — Standard
 * uses the global setting, higher tiers get a bigger discount.
 *
 * Does NOT modify class-wholesale-role.php. Instead, hooks into the
 * option_slw_discount_percent filter to dynamically swap the discount
 * value when a wholesale user is browsing or when cart totals are
 * calculated.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class SLW_Tiers {

	/**
	 * Default tier definitions. Overridden by the slw_wholesale_tiers option
	 * once the admin saves tier settings.
	 */
	private static $default_tiers = array(
		'standard'  => array(
			'name'            => 'Standard',
			'discount'        => 50,
			'order_threshold' => 0,
			'spend_threshold' => 0,
		),
		'preferred' => array(
			'name'            => 'Preferred',
			'discount'        => 55,
			'order_threshold' => 3,
			'spend_threshold' => 1500,
		),
		'vip'       => array(
			'name'            => 'VIP',
			'discount'        => 60,
			'order_threshold' => 10,
			'spend_threshold' => 5000,
		),
	);

	public static function init() {
		// Dynamically override the global discount option based on user tier
		add_filter( 'option_slw_discount_percent', array( __CLASS__, 'filter_discount_option' ) );

		// Add tier to the variation price hash so cached prices reflect tier
		add_filter( 'woocommerce_get_variation_prices_hash', array( __CLASS__, 'variation_price_hash' ), 100 );

		// Auto-upgrade on order completion
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'on_order_completed' ), 20 );

		// User profile fields (admin only)
		add_action( 'show_user_profile', array( __CLASS__, 'render_user_tier_section' ), 20 );
		add_action( 'edit_user_profile', array( __CLASS__, 'render_user_tier_section' ), 20 );
		add_action( 'personal_options_update', array( __CLASS__, 'save_user_tier_section' ) );
		add_action( 'edit_user_profile_update', array( __CLASS__, 'save_user_tier_section' ) );

		// Add tier badge to admin user list column
		add_filter( 'manage_users_custom_column', array( __CLASS__, 'append_tier_badge_to_column' ), 20, 3 );
	}

	// ── Tier Data ─────────────────────────────────────────────────────────

	/**
	 * Get all tier definitions, merging admin-saved config with defaults.
	 *
	 * @return array Keyed by tier slug.
	 */
	public static function get_tiers() {
		$saved = get_option( 'slw_wholesale_tiers', array() );
		if ( ! empty( $saved ) && is_array( $saved ) ) {
			return $saved;
		}
		return self::$default_tiers;
	}

	/**
	 * Get the tier slugs in order from lowest to highest.
	 *
	 * @return array
	 */
	public static function get_tier_order() {
		return array_keys( self::get_tiers() );
	}

	/**
	 * Get a single tier definition by slug.
	 *
	 * @param string $slug Tier slug.
	 * @return array|null
	 */
	public static function get_tier_config( $slug ) {
		$tiers = self::get_tiers();
		return isset( $tiers[ $slug ] ) ? $tiers[ $slug ] : null;
	}

	// ── User Tier ─────────────────────────────────────────────────────────

	/**
	 * Get the tier slug for a user. Defaults to 'standard' if not set.
	 *
	 * @param int $user_id
	 * @return string
	 */
	public static function get_user_tier( $user_id ) {
		$tier = get_user_meta( $user_id, 'slw_wholesale_tier', true );
		if ( ! $tier || ! self::get_tier_config( $tier ) ) {
			return 'standard';
		}
		return $tier;
	}

	/**
	 * Set the tier for a user.
	 *
	 * @param int    $user_id
	 * @param string $tier_slug
	 */
	public static function set_user_tier( $user_id, $tier_slug ) {
		update_user_meta( $user_id, 'slw_wholesale_tier', $tier_slug );
	}

	/**
	 * Get the effective discount percentage for a user based on their tier.
	 *
	 * @param int $user_id
	 * @return float
	 */
	public static function get_tier_discount( $user_id ) {
		$tier_slug = self::get_user_tier( $user_id );
		$config = self::get_tier_config( $tier_slug );
		if ( $config ) {
			return (float) $config['discount'];
		}
		// Absolute fallback: the global setting
		return (float) get_option( 'slw_discount_percent', 50 );
	}

	// ── Auto-Upgrade Logic ────────────────────────────────────────────────

	/**
	 * Check if a user qualifies for a higher tier and upgrade if so.
	 *
	 * @param int $user_id
	 * @return bool True if upgraded.
	 */
	public static function check_and_upgrade( $user_id ) {
		if ( ! slw_is_wholesale_user( $user_id ) ) {
			return false;
		}

		$current_tier = self::get_user_tier( $user_id );
		$tiers = self::get_tiers();
		$tier_order = self::get_tier_order();

		// Get user stats
		$order_count = self::get_completed_order_count( $user_id );
		$lifetime_spend = self::get_lifetime_spend( $user_id );

		// Walk tiers from highest to lowest; assign the best the user qualifies for
		$best_tier = 'standard';
		foreach ( $tier_order as $slug ) {
			$config = $tiers[ $slug ];
			$order_threshold = (int) ( $config['order_threshold'] ?? 0 );
			$spend_threshold = (float) ( $config['spend_threshold'] ?? 0 );

			// Standard tier has no thresholds — everyone qualifies
			if ( $order_threshold <= 0 && $spend_threshold <= 0 ) {
				$best_tier = $slug;
				continue;
			}

			// User qualifies if they meet EITHER threshold (OR logic)
			if ( ( $order_threshold > 0 && $order_count >= $order_threshold )
				|| ( $spend_threshold > 0 && $lifetime_spend >= $spend_threshold ) ) {
				$best_tier = $slug;
			}
		}

		// Only upgrade, never downgrade automatically
		$current_index = array_search( $current_tier, $tier_order, true );
		$best_index = array_search( $best_tier, $tier_order, true );

		if ( $best_index !== false && $best_index > $current_index ) {
			$old_tier = $current_tier;
			self::set_user_tier( $user_id, $best_tier );

			// Record upgrade history
			$history = get_user_meta( $user_id, 'slw_tier_history', true );
			if ( ! is_array( $history ) ) {
				$history = array();
			}
			$history[] = array(
				'tier'   => $best_tier,
				'from'   => $old_tier,
				'date'   => current_time( 'mysql' ),
				'reason' => sprintf(
					'Auto-upgrade: %d orders, $%s lifetime spend',
					$order_count,
					number_format( $lifetime_spend, 2 )
				),
			);
			update_user_meta( $user_id, 'slw_tier_history', $history );

			/**
			 * Fires when a user is upgraded to a higher wholesale tier.
			 *
			 * @param int    $user_id  User ID.
			 * @param string $old_tier Previous tier slug.
			 * @param string $new_tier New tier slug.
			 */
			do_action( 'slw_tier_upgraded', $user_id, $old_tier, $best_tier );

			return true;
		}

		return false;
	}

	/**
	 * Hook: check for tier upgrade when an order completes.
	 *
	 * @param int $order_id
	 */
	public static function on_order_completed( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$user_id = $order->get_user_id();
		if ( ! $user_id || ! slw_is_wholesale_user( $user_id ) ) {
			return;
		}

		self::check_and_upgrade( $user_id );
	}

	// ── User Stats (HPOS-compatible) ──────────────────────────────────────

	/**
	 * Count completed orders for a user.
	 *
	 * @param int $user_id
	 * @return int
	 */
	public static function get_completed_order_count( $user_id ) {
		return (int) wc_get_customer_order_count( $user_id );
	}

	/**
	 * Get lifetime spend for a user (completed + processing orders).
	 *
	 * @param int $user_id
	 * @return float
	 */
	public static function get_lifetime_spend( $user_id ) {
		$spent = wc_get_customer_total_spent( $user_id );
		return (float) $spent;
	}

	// ── Option Filter (pricing integration) ───────────────────────────────

	/**
	 * Filter the slw_discount_percent option value to return the tier-specific
	 * discount for the current wholesale user. This is how the tier discount
	 * integrates with the existing pricing engine in class-wholesale-role.php
	 * without modifying that file.
	 *
	 * @param mixed $value The stored option value.
	 * @return mixed
	 */
	public static function filter_discount_option( $value ) {
		// Only filter on the frontend (not admin)
		if ( is_admin() && ! wp_doing_ajax() ) {
			return $value;
		}

		$user_id = get_current_user_id();
		if ( ! $user_id || ! slw_is_wholesale_user( $user_id ) ) {
			return $value;
		}

		$tier_slug = self::get_user_tier( $user_id );
		$config = self::get_tier_config( $tier_slug );
		if ( $config && isset( $config['discount'] ) ) {
			return $config['discount'];
		}

		return $value;
	}

	/**
	 * Add the user's tier to the variation price hash so WooCommerce doesn't
	 * serve cached prices from a different tier.
	 *
	 * @param array $hash
	 * @return array
	 */
	public static function variation_price_hash( $hash ) {
		$user_id = get_current_user_id();
		if ( $user_id && slw_is_wholesale_user( $user_id ) ) {
			$hash[] = 'slw_tier_' . self::get_user_tier( $user_id );
		}
		return $hash;
	}

	// ── User Profile Section ──────────────────────────────────────────────

	/**
	 * Render the wholesale tier section on user profile pages.
	 *
	 * @param WP_User $user
	 */
	public static function render_user_tier_section( $user ) {
		if ( ! current_user_can( 'edit_users' ) ) {
			return;
		}
		if ( ! slw_is_wholesale_user( $user->ID ) ) {
			return;
		}

		$current_tier = self::get_user_tier( $user->ID );
		$tiers = self::get_tiers();
		$tier_order = self::get_tier_order();
		$config = self::get_tier_config( $current_tier );
		$tier_name = $config ? $config['name'] : 'Standard';

		// Stats for upgrade progress
		$order_count = self::get_completed_order_count( $user->ID );
		$lifetime_spend = self::get_lifetime_spend( $user->ID );

		// Find next tier for progress display
		$current_index = array_search( $current_tier, $tier_order, true );
		$next_tier = null;
		$next_config = null;
		if ( $current_index !== false && isset( $tier_order[ $current_index + 1 ] ) ) {
			$next_tier = $tier_order[ $current_index + 1 ];
			$next_config = self::get_tier_config( $next_tier );
		}

		// Tier history
		$history = get_user_meta( $user->ID, 'slw_tier_history', true );
		if ( ! is_array( $history ) ) {
			$history = array();
		}

		// Badge colors
		$badge_colors = array(
			'standard'  => '#628393',
			'preferred' => '#386174',
			'vip'       => '#D4AF37',
		);
		$badge_color = isset( $badge_colors[ $current_tier ] ) ? $badge_colors[ $current_tier ] : '#628393';
		?>
		<h2>Wholesale Tier</h2>
		<table class="form-table">
			<tr>
				<th><label>Current Tier</label></th>
				<td>
					<span style="display:inline-block;padding:4px 12px;border-radius:12px;font-size:13px;font-weight:bold;color:#fff;background:<?php echo esc_attr( $badge_color ); ?>;">
						<?php echo esc_html( $tier_name ); ?>
					</span>
					<span style="margin-left:8px;color:#628393;font-size:13px;">
						(<?php echo esc_html( $config['discount'] ?? 50 ); ?>% discount)
					</span>
				</td>
			</tr>
			<tr>
				<th><label for="slw_wholesale_tier">Override Tier</label></th>
				<td>
					<select name="slw_wholesale_tier" id="slw_wholesale_tier">
						<?php foreach ( $tiers as $slug => $t ) : ?>
							<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $current_tier, $slug ); ?>>
								<?php echo esc_html( $t['name'] ); ?> (<?php echo esc_html( $t['discount'] ); ?>%)
							</option>
						<?php endforeach; ?>
					</select>
					<p class="description">Manually set this user's tier. Auto-upgrade will not downgrade below this.</p>
				</td>
			</tr>
			<tr>
				<th><label>Order Stats</label></th>
				<td>
					<strong><?php echo esc_html( $order_count ); ?></strong> completed orders
					&nbsp;|&nbsp;
					<strong>$<?php echo esc_html( number_format( $lifetime_spend, 2 ) ); ?></strong> lifetime spend
				</td>
			</tr>
			<?php if ( $next_config ) : ?>
			<tr>
				<th><label>Upgrade Progress</label></th>
				<td>
					<?php
					$next_name = $next_config['name'];
					$order_target = (int) ( $next_config['order_threshold'] ?? 0 );
					$spend_target = (float) ( $next_config['spend_threshold'] ?? 0 );

					if ( $order_target > 0 ) :
						$order_pct = min( 100, round( ( $order_count / $order_target ) * 100 ) );
					?>
					<div style="margin-bottom:8px;">
						<span style="font-size:13px;color:#628393;">
							<?php echo esc_html( $order_count ); ?>/<?php echo esc_html( $order_target ); ?> orders to <?php echo esc_html( $next_name ); ?>
						</span>
						<div style="background:#e0ddd8;border-radius:3px;height:8px;margin-top:4px;max-width:300px;">
							<div style="background:#386174;height:100%;border-radius:3px;width:<?php echo esc_attr( $order_pct ); ?>%;transition:width 0.3s;"></div>
						</div>
					</div>
					<?php endif; ?>

					<?php if ( $spend_target > 0 ) :
						$spend_pct = min( 100, round( ( $lifetime_spend / $spend_target ) * 100 ) );
					?>
					<div>
						<span style="font-size:13px;color:#628393;">
							$<?php echo esc_html( number_format( $lifetime_spend, 0 ) ); ?>/$<?php echo esc_html( number_format( $spend_target, 0 ) ); ?> spend to <?php echo esc_html( $next_name ); ?>
						</span>
						<div style="background:#e0ddd8;border-radius:3px;height:8px;margin-top:4px;max-width:300px;">
							<div style="background:#386174;height:100%;border-radius:3px;width:<?php echo esc_attr( $spend_pct ); ?>%;transition:width 0.3s;"></div>
						</div>
					</div>
					<?php endif; ?>
				</td>
			</tr>
			<?php endif; ?>
			<?php if ( ! empty( $history ) ) : ?>
			<tr>
				<th><label>Tier History</label></th>
				<td>
					<ul style="margin:0;padding:0;list-style:none;font-size:13px;color:#628393;">
						<?php foreach ( array_reverse( $history ) as $entry ) : ?>
							<li style="margin-bottom:4px;">
								<?php echo esc_html( $entry['date'] ); ?> &mdash;
								<?php echo esc_html( $entry['from'] ?? '?' ); ?> &rarr; <?php echo esc_html( $entry['tier'] ); ?>
								<?php if ( ! empty( $entry['reason'] ) ) : ?>
									<em>(<?php echo esc_html( $entry['reason'] ); ?>)</em>
								<?php endif; ?>
							</li>
						<?php endforeach; ?>
					</ul>
				</td>
			</tr>
			<?php endif; ?>
		</table>
		<?php
	}

	/**
	 * Save the tier override from the user profile page.
	 *
	 * @param int $user_id
	 */
	public static function save_user_tier_section( $user_id ) {
		if ( ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}

		if ( ! isset( $_POST['slw_wholesale_tier'] ) ) {
			return;
		}

		$new_tier = sanitize_text_field( $_POST['slw_wholesale_tier'] );
		if ( ! self::get_tier_config( $new_tier ) ) {
			return;
		}

		$old_tier = self::get_user_tier( $user_id );
		if ( $new_tier !== $old_tier ) {
			self::set_user_tier( $user_id, $new_tier );

			// Record manual change in history
			$history = get_user_meta( $user_id, 'slw_tier_history', true );
			if ( ! is_array( $history ) ) {
				$history = array();
			}
			$history[] = array(
				'tier'   => $new_tier,
				'from'   => $old_tier,
				'date'   => current_time( 'mysql' ),
				'reason' => 'Manual admin override',
			);
			update_user_meta( $user_id, 'slw_tier_history', $history );

			if ( array_search( $new_tier, self::get_tier_order(), true ) > array_search( $old_tier, self::get_tier_order(), true ) ) {
				do_action( 'slw_tier_upgraded', $user_id, $old_tier, $new_tier );
			}
		}
	}

	/**
	 * Append tier badge to the existing wholesale column in the user list.
	 *
	 * @param string $value       Current column value.
	 * @param string $column_name Column name.
	 * @param int    $user_id     User ID.
	 * @return string
	 */
	public static function append_tier_badge_to_column( $value, $column_name, $user_id ) {
		if ( $column_name !== 'slw_wholesale' ) {
			return $value;
		}
		if ( ! slw_is_wholesale_user( $user_id ) ) {
			return $value;
		}

		$tier_slug = self::get_user_tier( $user_id );
		$config = self::get_tier_config( $tier_slug );
		if ( ! $config ) {
			return $value;
		}

		$badge_colors = array(
			'standard'  => '#628393',
			'preferred' => '#386174',
			'vip'       => '#D4AF37',
		);
		$color = isset( $badge_colors[ $tier_slug ] ) ? $badge_colors[ $tier_slug ] : '#628393';
		$text_color = $tier_slug === 'vip' ? '#1E2A30' : '#fff';

		$badge = sprintf(
			'<br><span style="display:inline-block;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600;color:%s;background:%s;">%s</span>',
			esc_attr( $text_color ),
			esc_attr( $color ),
			esc_html( $config['name'] )
		);

		return $value . $badge;
	}
}
