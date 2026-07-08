<?php
/**
 * Scent Availability
 *
 * A management view (rendered inside Wholesale > Pricing) for every scent:
 * status, which channel it sells on, sizes, stock, and recent wholesale demand,
 * with a one-click discontinue-everywhere. It also installs guardrails so a
 * scent can't be ordered where it shouldn't be:
 *   - an "orphan" (variation live but removed from the product's options) is
 *     non-purchasable on both sides, and
 *   - a scent set to wholesale-only / retail-only is non-purchasable in the
 *     other context.
 *
 * @package SegoLilyWholesale
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SLW_Scent_Availability {

	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'handle_actions' ) );
		add_filter( 'woocommerce_variation_is_purchasable', array( __CLASS__, 'block_orphaned_variation' ), 20, 2 );
	}

	/* ---------- attribute helpers ---------- */

	private static function variation_scent( $variation ) {
		foreach ( (array) $variation->get_attributes() as $ak => $av ) {
			if ( stripos( (string) $ak, 'scent' ) !== false ) {
				return (string) $av;
			}
		}
		return '';
	}

	private static function variation_size( $variation ) {
		foreach ( (array) $variation->get_attributes() as $ak => $av ) {
			if ( stripos( (string) $ak, 'size' ) !== false ) {
				return (string) $av;
			}
		}
		return '';
	}

	private static function product_scent_options( $product ) {
		foreach ( (array) $product->get_attributes() as $key => $attr ) {
			$name = is_object( $attr ) ? $attr->get_name() : (string) $key;
			if ( stripos( $name, 'scent' ) !== false ) {
				return is_object( $attr ) ? (array) $attr->get_options() : array();
			}
		}
		return null;
	}

	/**
	 * The product's Size attribute value(s), for single-size products where
	 * size isn't a per-variation attribute (lip balm, deodorant).
	 */
	private static function product_base_size( $product ) {
		foreach ( (array) $product->get_attributes() as $key => $attr ) {
			$name = is_object( $attr ) ? $attr->get_name() : (string) $key;
			if ( stripos( $name, 'size' ) !== false ) {
				$opts = is_object( $attr ) ? (array) $attr->get_options() : array();
				return $opts ? implode( ', ', $opts ) : '';
			}
		}
		return '';
	}

	/* ---------- per-scent channel (both | wholesale | retail) ---------- */

	private static function channel_of( $product, $scent ) {
		$map = get_post_meta( $product->get_id(), '_slw_scent_channel', true );
		if ( is_array( $map ) && isset( $map[ strtolower( $scent ) ] ) ) {
			return $map[ strtolower( $scent ) ];
		}
		return 'both';
	}

	private static function set_channel( $product, $scent, $channel ) {
		if ( ! in_array( $channel, array( 'both', 'wholesale', 'retail' ), true ) ) {
			return;
		}
		$map = get_post_meta( $product->get_id(), '_slw_scent_channel', true );
		if ( ! is_array( $map ) ) {
			$map = array();
		}
		if ( 'both' === $channel ) {
			unset( $map[ strtolower( $scent ) ] );
		} else {
			$map[ strtolower( $scent ) ] = $channel;
		}
		update_post_meta( $product->get_id(), '_slw_scent_channel', $map );
	}

	/* ---------- guardrail ---------- */

	public static function block_orphaned_variation( $purchasable, $variation ) {
		if ( ! $purchasable ) {
			return $purchasable;
		}
		$parent = wc_get_product( $variation->get_parent_id() );
		if ( ! $parent ) {
			return $purchasable;
		}
		$options = self::product_scent_options( $parent );
		if ( null === $options ) {
			return $purchasable;
		}
		$vscent = self::variation_scent( $variation );
		if ( '' === $vscent ) {
			return $purchasable;
		}
		$in_options = false;
		foreach ( $options as $o ) {
			if ( strcasecmp( (string) $o, $vscent ) === 0 ) {
				$in_options = true;
				break;
			}
		}
		if ( ! $in_options ) {
			return false; // orphaned / discontinued
		}
		// Channel enforcement.
		$channel = self::channel_of( $parent, $vscent );
		if ( 'both' !== $channel ) {
			$is_ws = function_exists( 'slw_is_wholesale_context' ) && slw_is_wholesale_context();
			if ( 'wholesale' === $channel && ! $is_ws ) {
				return false;
			}
			if ( 'retail' === $channel && $is_ws ) {
				return false;
			}
		}
		return $purchasable;
	}

	/* ---------- sales (wholesale only) ---------- */

	/**
	 * Wholesale units sold per scent (all time), keyed "productID|scent-lower".
	 * Wholesale-only on purpose: this is the wholesale portal, so demand should
	 * reflect wholesale accounts, not retail. Cached 6h. Refunds excluded.
	 */
	private static function units_by_scent() {
		$cached = get_transient( 'slw_scent_units_ws' );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$uids = get_users( array( 'role' => 'wholesale_customer', 'fields' => 'ID' ) );
		if ( empty( $uids ) ) {
			set_transient( 'slw_scent_units_ws', array(), 6 * HOUR_IN_SECONDS );
			return array();
		}
		$orders = wc_get_orders( array(
			'customer_id' => $uids,
			'limit'       => -1,
			'status'      => array( 'wc-processing', 'wc-completed', 'wc-on-hold' ),
		) );
		$map = array();
		foreach ( $orders as $order ) {
			foreach ( $order->get_items() as $item ) {
				$prod = $item->get_product();
				if ( ! $prod ) {
					continue;
				}
				$scent = self::variation_scent( $prod );
				if ( '' === $scent ) {
					continue;
				}
				$key         = $item->get_product_id() . '|' . strtolower( $scent );
				$map[ $key ] = ( $map[ $key ] ?? 0 ) + (int) $item->get_quantity();
			}
		}
		set_transient( 'slw_scent_units_ws', $map, 6 * HOUR_IN_SECONDS );
		return $map;
	}

	/* ---------- scan ---------- */

	private static function scan() {
		$sales    = self::units_by_scent();
		$products = wc_get_products( array(
			'type'   => array( 'variable', 'variable-subscription' ),
			'limit'  => -1,
			'status' => 'publish',
		) );
		$out = array();
		foreach ( $products as $product ) {
			$options = self::product_scent_options( $product );
			if ( null === $options ) {
				continue;
			}
			$in_options = array();
			foreach ( $options as $o ) {
				$in_options[ strtolower( (string) $o ) ] = true;
			}
			$scents = array();
			foreach ( $product->get_children() as $vid ) {
				$v = wc_get_product( $vid );
				if ( ! $v ) {
					continue;
				}
				$name = self::variation_scent( $v );
				if ( '' === $name ) {
					continue;
				}
				if ( ! isset( $scents[ $name ] ) ) {
					$scents[ $name ] = array(
						'in_options' => isset( $in_options[ strtolower( $name ) ] ),
						'live'       => 0,
						'hidden'     => 0,
						'sizes'      => array(),
						'in_stock'   => false,
						'qty'        => null,
						'sold'       => 0,
						'channel'    => self::channel_of( $product, $name ),
					);
				}
				if ( 'publish' === $v->get_status() ) {
					$scents[ $name ]['live']++;
				} else {
					$scents[ $name ]['hidden']++;
				}
				$size = self::variation_size( $v );
				if ( '' !== $size && ! in_array( $size, $scents[ $name ]['sizes'], true ) ) {
					$scents[ $name ]['sizes'][] = $size;
				}
				if ( $v->is_in_stock() ) {
					$scents[ $name ]['in_stock'] = true;
				}
				if ( $v->managing_stock() ) {
					$scents[ $name ]['qty'] = (int) ( $scents[ $name ]['qty'] ?? 0 ) + (int) $v->get_stock_quantity();
				}
			}
			foreach ( $options as $o ) {
				$o = (string) $o;
				if ( ! isset( $scents[ $o ] ) ) {
					$scents[ $o ] = array( 'in_options' => true, 'live' => 0, 'hidden' => 0, 'sizes' => array(), 'in_stock' => false, 'qty' => null, 'sold' => 0, 'channel' => self::channel_of( $product, $o ) );
				}
			}
			foreach ( $scents as $name => $s ) {
				$scents[ $name ]['sold'] = (int) ( $sales[ $product->get_id() . '|' . strtolower( $name ) ] ?? 0 );
			}
			ksort( $scents );
			$out[] = array( 'product' => $product, 'scents' => $scents, 'base_size' => self::product_base_size( $product ) );
		}
		return $out;
	}

	private static function status_of( $s ) {
		if ( $s['live'] > 0 && ! $s['in_options'] ) {
			return array( 'orphan', 'Live on wholesale, off retail' );
		}
		if ( $s['in_options'] && ( $s['live'] > 0 || 0 === $s['hidden'] ) ) {
			return array( 'live', 'Live' );
		}
		return array( 'off', 'Discontinued' );
	}

	private static function needs_attention( $s ) {
		list( $code ) = self::status_of( $s );
		return ( 'orphan' === $code ) || ( 'live' === $code && ! $s['in_stock'] );
	}

	/* ---------- actions ---------- */

	public static function handle_actions() {
		if ( ! isset( $_GET['page'] ) || 'slw-pricing' !== $_GET['page'] ) {
			return;
		}
		if ( ! isset( $_GET['slw_scent_action'] ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		if ( ! wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'slw_scent_action' ) ) {
			wp_die( 'Invalid nonce.' );
		}
		$action  = sanitize_key( $_GET['slw_scent_action'] );
		$pid     = absint( $_GET['product'] ?? 0 );
		$scent   = sanitize_text_field( wp_unslash( $_GET['scent'] ?? '' ) );
		$product = $pid ? wc_get_product( $pid ) : null;
		if ( $product && '' !== $scent ) {
			if ( in_array( $action, array( 'discontinue', 'restore' ), true ) ) {
				self::set_scent( $product, $scent, 'restore' === $action );
			} elseif ( 'channel' === $action ) {
				self::set_channel( $product, $scent, sanitize_key( $_GET['channel'] ?? 'both' ) );
			}
		}
		wp_safe_redirect( admin_url( 'admin.php?page=slw-pricing&slw_scent_updated=1#slw-scents' ) );
		exit;
	}

	private static function set_scent( $product, $scent, $live ) {
		foreach ( $product->get_children() as $vid ) {
			$v = wc_get_product( $vid );
			if ( ! $v ) {
				continue;
			}
			if ( strcasecmp( self::variation_scent( $v ), $scent ) !== 0 ) {
				continue;
			}
			$v->set_status( $live ? 'publish' : 'private' );
			$v->save();
		}
		$attributes = $product->get_attributes();
		foreach ( $attributes as $key => $attr ) {
			$name = is_object( $attr ) ? $attr->get_name() : (string) $key;
			if ( stripos( $name, 'scent' ) === false || ! is_object( $attr ) ) {
				continue;
			}
			$options = (array) $attr->get_options();
			if ( $live ) {
				if ( ! in_array( $scent, $options, true ) ) {
					$options[] = $scent;
				}
			} else {
				$options = array_values( array_filter( $options, function ( $o ) use ( $scent ) {
					return strcasecmp( (string) $o, $scent ) !== 0;
				} ) );
			}
			$attr->set_options( $options );
			$attributes[ $key ] = $attr;
		}
		$product->set_attributes( $attributes );
		$product->save();
	}

	/* ---------- render (section inside Wholesale > Pricing) ---------- */

	public static function render_section() {
		$data = self::scan();

		$t_total = 0; $t_live = 0; $t_off = 0; $t_attn = 0;
		foreach ( $data as $row ) {
			foreach ( $row['scents'] as $s ) {
				$t_total++;
				list( $code ) = self::status_of( $s );
				if ( 'off' === $code ) { $t_off++; } else { $t_live++; }
				if ( self::needs_attention( $s ) ) { $t_attn++; }
			}
		}
		$chip = function ( $label, $value, $color ) {
			return '<span style="display:inline-block;background:#F7F6F3;border:1px solid #E8E2D6;border-radius:6px;padding:6px 12px;margin:0 8px 8px 0;font-size:13px;">'
				. '<strong style="color:' . esc_attr( $color ) . ';font-size:16px;">' . esc_html( $value ) . '</strong> '
				. '<span style="color:#628393;">' . esc_html( $label ) . '</span></span>';
		};
		?>
		<div class="slw-admin-card" id="slw-scents" style="padding:20px 24px;margin-bottom:24px;">
			<h2 class="slw-admin-card__heading" style="margin-bottom:8px;">Scent Availability</h2>
			<p style="color:#628393;margin-bottom:14px;max-width:820px;">Every scent's channel, status, stock, and recent demand in one place. <strong>Channel</strong> sets where a scent sells: both, wholesale only, or retail only. <em>Wholesale sold</em> is all-time units to wholesale accounts (refunds excluded). Discontinuing removes a scent from <strong>both</strong> sides in one click. <span style="color:#c0392b;font-weight:600;">Needs attention</span> = live on wholesale but off retail, or live but out of stock.</p>
			<?php if ( isset( $_GET['slw_scent_updated'] ) ) : ?>
				<div class="notice notice-success inline" style="margin:0 0 14px;"><p>Scent updated.</p></div>
			<?php endif; ?>
			<div style="margin-bottom:6px;">
				<?php
				echo wp_kses_post( $chip( 'scents', $t_total, '#2C2C2C' ) );
				echo wp_kses_post( $chip( 'live', $t_live, '#1a764d' ) );
				echo wp_kses_post( $chip( 'discontinued', $t_off, '#8A9499' ) );
				echo wp_kses_post( $chip( 'need attention', $t_attn, $t_attn ? '#c0392b' : '#8A9499' ) );
				?>
			</div>
			<?php if ( empty( $data ) ) : ?>
				<p style="color:#628393;">No products with scents found.</p>
			<?php endif; ?>
			<?php foreach ( $data as $row ) :
				$product = $row['product'];
				?>
				<h3 style="margin:20px 0 6px;"><?php echo esc_html( $product->get_name() ); ?></h3>
				<table class="widefat fixed striped" style="max-width:1000px;">
					<thead><tr>
						<th style="width:20%;">Scent</th>
						<th style="width:20%;">Status</th>
						<th style="width:20%;">Channel</th>
						<th style="width:11%;">Sizes</th>
						<th style="width:11%;">Stock</th>
						<th style="width:8%;">WS sold</th>
						<th style="width:10%;">Action</th>
					</tr></thead>
					<tbody>
					<?php foreach ( $row['scents'] as $name => $s ) :
						list( $code, $label ) = self::status_of( $s );
						$color = 'live' === $code ? '#1a764d' : ( 'orphan' === $code ? '#c0392b' : '#8A9499' );
						$attn  = self::needs_attention( $s );
						$act   = ( 'off' === $code ) ? 'restore' : 'discontinue';
						$url   = wp_nonce_url(
							admin_url( 'admin.php?page=slw-pricing&slw_scent_action=' . $act . '&product=' . $product->get_id() . '&scent=' . rawurlencode( $name ) ),
							'slw_scent_action'
						);
						if ( 'off' === $code ) {
							$stock_html = '<span style="color:#8A9499;">&mdash;</span>';
						} elseif ( null !== $s['qty'] ) {
							$q  = (int) $s['qty'];
							$sc = $q <= 0 ? '#c0392b' : ( $q < 6 ? '#b26a00' : '#1a764d' );
							$stock_html = '<span style="color:' . $sc . ';">' . esc_html( $q ) . ' in stock</span>';
						} else {
							$stock_html = $s['in_stock']
								? '<span style="color:#1a764d;">In stock</span>'
								: '<span style="color:#c0392b;">Out of stock</span>';
						}
						$sizes_txt = $s['sizes'] ? implode( ', ', $s['sizes'] ) : $row['base_size'];
						// channel toggle
						$channel_html = '<span style="color:#8A9499;">&mdash;</span>';
						if ( 'off' !== $code ) {
							$parts = array();
							foreach ( array( 'both' => 'Both', 'wholesale' => 'WS only', 'retail' => 'Retail only' ) as $ck => $cl ) {
								$curl   = wp_nonce_url( admin_url( 'admin.php?page=slw-pricing&slw_scent_action=channel&channel=' . $ck . '&product=' . $product->get_id() . '&scent=' . rawurlencode( $name ) ), 'slw_scent_action' );
								$is_cur = ( $s['channel'] === $ck );
								$style  = $is_cur ? 'font-weight:700;color:#386174;text-decoration:none;' : 'color:#8A9499;';
								$parts[] = '<a href="' . esc_url( $curl ) . '" style="' . $style . '">' . esc_html( $cl ) . '</a>';
							}
							$channel_html = '<span style="font-size:12px;">' . implode( ' <span style="color:#ccc;">|</span> ', $parts ) . '</span>';
						}
						?>
						<tr<?php echo $attn ? ' style="background:#fdf3f2;"' : ''; ?>>
							<td><strong><?php echo esc_html( $name ); ?></strong></td>
							<td><span style="color:<?php echo esc_attr( $color ); ?>;font-weight:600;"><?php echo esc_html( $label ); ?></span></td>
							<td><?php echo wp_kses_post( $channel_html ); ?></td>
							<td style="color:#628393;"><?php echo $sizes_txt ? esc_html( $sizes_txt ) : '&mdash;'; ?></td>
							<td><?php echo wp_kses_post( $stock_html ); ?></td>
							<td style="color:#628393;"><?php echo esc_html( $s['sold'] ); ?></td>
							<td>
								<?php if ( 'off' === $code ) : ?>
									<a href="<?php echo esc_url( $url ); ?>" class="button button-small">Restore</a>
								<?php else : ?>
									<a href="<?php echo esc_url( $url ); ?>" class="button button-small"
									   onclick="return confirm('Discontinue this scent everywhere? It will be removed from both retail and wholesale.');">Discontinue</a>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endforeach; ?>
		</div>
		<?php
	}
}
