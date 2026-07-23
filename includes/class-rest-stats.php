<?php
/**
 * Wholesale stats REST endpoint.
 *
 * Exposes a read-only summary of the wholesale operation so the Lead Piranha
 * client portal can show live numbers (orders, revenue, customers, outstanding
 * NET terms, top accounts) inside the hub instead of a link-out.
 *
 * Route:  GET /wp-json/slw/v1/stats?key=<shared-secret>
 * Auth:   a shared secret. Define SLW_STATS_KEY in wp-config.php (preferred),
 *         or the plugin auto-generates and stores one (slw_stats_api_key) that
 *         you can read via the same constant fallback. The portal sends the
 *         matching value server-side; nothing is exposed publicly.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SLW_Rest_Stats {

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register' ) );
	}

	public static function register() {
		register_rest_route( 'slw/v1', '/stats', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'stats' ),
			'permission_callback' => array( __CLASS__, 'authorize' ),
		) );
	}

	/** Return the configured shared secret (constant preferred, option fallback). */
	public static function get_key() {
		if ( defined( 'SLW_STATS_KEY' ) && SLW_STATS_KEY ) {
			return (string) SLW_STATS_KEY;
		}
		$k = get_option( 'slw_stats_api_key' );
		if ( ! $k ) {
			$k = wp_generate_password( 40, false );
			update_option( 'slw_stats_api_key', $k, false );
		}
		return (string) $k;
	}

	public static function authorize( $request ) {
		$provided = (string) $request->get_param( 'key' );
		$expected = self::get_key();
		return $provided !== '' && hash_equals( $expected, $provided );
	}

	public static function stats() {
		$wholesale_ids = get_users( array( 'role' => 'wholesale_customer', 'fields' => 'ID', 'number' => -1 ) );
		$wset          = array_flip( array_map( 'intval', $wholesale_ids ) );

		// Pull recent orders and keep the ones belonging to wholesale accounts.
		// Holly's wholesale volume is modest, so a bounded recent window is plenty.
		$orders = wc_get_orders( array(
			'limit'   => 800,
			'orderby' => 'date',
			'order'   => 'DESC',
			'type'    => 'shop_order',
			'status'  => array( 'processing', 'completed', 'on-hold', 'pending' ),
		) );

		$month_start = strtotime( gmdate( 'Y-m-01 00:00:00' ) );

		$orders_total = 0;
		$orders_month = 0;
		$rev_total    = 0.0;
		$rev_month    = 0.0;
		$net_open_cnt = 0;
		$net_open_amt = 0.0;
		$by_account   = array(); // name => total

		foreach ( $orders as $order ) {
			$uid = (int) $order->get_user_id();
			if ( $uid && ! isset( $wset[ $uid ] ) ) {
				continue; // retail / non-wholesale customer
			}
			if ( ! $uid ) {
				continue; // guest, not a wholesale account
			}

			$total   = (float) $order->get_total();
			$created = $order->get_date_created() ? $order->get_date_created()->getTimestamp() : 0;

			$orders_total++;
			$rev_total += $total;
			if ( $created >= $month_start ) {
				$orders_month++;
				$rev_month += $total;
			}

			// Outstanding NET: on terms and not yet paid.
			$net_days = (int) $order->get_meta( '_slw_net_terms_days' );
			if ( $net_days > 0 && ! $order->get_date_paid() ) {
				$net_open_cnt++;
				$net_open_amt += $total;
			}

			$name = get_user_meta( $uid, 'slw_business_name', true );
			if ( ! $name ) {
				$name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ) ?: ( 'Account #' . $uid );
			}
			$by_account[ $name ] = ( $by_account[ $name ] ?? 0 ) + $total;
		}

		arsort( $by_account );
		$top_accounts = array();
		foreach ( array_slice( $by_account, 0, 5, true ) as $name => $amt ) {
			$top_accounts[] = array( 'name' => $name, 'revenue' => round( $amt, 2 ) );
		}

		return rest_ensure_response( array(
			'currency'        => get_woocommerce_currency(),
			'customers'       => count( $wholesale_ids ),
			'orders_total'    => $orders_total,
			'orders_month'    => $orders_month,
			'revenue_total'   => round( $rev_total, 2 ),
			'revenue_month'   => round( $rev_month, 2 ),
			'net_open_count'  => $net_open_cnt,
			'net_open_amount' => round( $net_open_amt, 2 ),
			'top_accounts'    => $top_accounts,
			'generated_at'    => gmdate( 'c' ),
		) );
	}
}
