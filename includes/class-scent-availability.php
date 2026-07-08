<?php
/**
 * Scent Availability
 *
 * One place for the shop owner to see every scent's status across products and
 * discontinue a scent EVERYWHERE in a single click, so a scent taken off retail
 * can't quietly stay orderable on wholesale. It also installs a guardrail: a
 * scent whose variation is still live but is no longer one of the product's
 * selectable options (an "orphan", exactly what let a customer order a
 * discontinued Bourbon Coffee) is made non-purchasable on both sides.
 *
 * @package SegoLilyWholesale
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SLW_Scent_Availability {

	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'handle_actions' ) );
		// Guardrail: never let an orphaned scent (variation live but removed from
		// the product's options) be purchased, retail or wholesale.
		add_filter( 'woocommerce_variation_is_purchasable', array( __CLASS__, 'block_orphaned_variation' ), 20, 2 );
	}

	/**
	 * Read the scent value stored on a variation (custom "Scent" attribute).
	 */
	private static function variation_scent( $variation ) {
		foreach ( (array) $variation->get_attributes() as $ak => $av ) {
			if ( stripos( (string) $ak, 'scent' ) !== false ) {
				return (string) $av;
			}
		}
		return '';
	}

	/**
	 * The product's current selectable scent options (strings).
	 */
	private static function product_scent_options( $product ) {
		foreach ( (array) $product->get_attributes() as $key => $attr ) {
			$name = is_object( $attr ) ? $attr->get_name() : (string) $key;
			if ( stripos( $name, 'scent' ) !== false ) {
				return is_object( $attr ) ? (array) $attr->get_options() : array();
			}
		}
		return null; // no scent attribute on this product
	}

	/**
	 * Guardrail. Block purchase of a variation whose scent is no longer one of
	 * the parent's options (orphaned / discontinued but still live).
	 */
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
			return $purchasable; // product has no scent attribute
		}
		$vscent = self::variation_scent( $variation );
		if ( '' === $vscent ) {
			return $purchasable;
		}
		foreach ( $options as $o ) {
			if ( strcasecmp( (string) $o, $vscent ) === 0 ) {
				return $purchasable; // still a current option
			}
		}
		return false; // orphaned scent -> not purchasable
	}

	/**
	 * The size value stored on a variation (custom "Size" attribute).
	 */
	private static function variation_size( $variation ) {
		foreach ( (array) $variation->get_attributes() as $ak => $av ) {
			if ( stripos( (string) $ak, 'size' ) !== false ) {
				return (string) $av;
			}
		}
		return '';
	}

	/**
	 * Total units sold per scent over the last 90 days across ALL channels
	 * (retail + wholesale), keyed "productID|lowercased-scent". This is real
	 * demand, so discontinue decisions aren't skewed by the (usually small)
	 * wholesale volume alone. Cached for 6h since it scans orders. Refunded
	 * orders are excluded by status.
	 */
	private static function units_by_scent() {
		$cached = get_transient( 'slw_scent_units_90d' );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$orders = wc_get_orders( array(
			'limit'        => -1,
			'status'       => array( 'wc-processing', 'wc-completed', 'wc-on-hold' ),
			'date_created' => '>=' . gmdate( 'Y-m-d', time() - 90 * DAY_IN_SECONDS ),
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
		set_transient( 'slw_scent_units_90d', $map, 6 * HOUR_IN_SECONDS );
		return $map;
	}

	/**
	 * Scan variable products and build per-scent status, stock, sizes + sales.
	 */
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
					$scents[ $o ] = array( 'in_options' => true, 'live' => 0, 'hidden' => 0, 'sizes' => array(), 'in_stock' => false, 'qty' => null, 'sold' => 0 );
				}
			}
			foreach ( $scents as $name => $s ) {
				$scents[ $name ]['sold'] = (int) ( $sales[ $product->get_id() . '|' . strtolower( $name ) ] ?? 0 );
			}
			ksort( $scents );
			$out[] = array( 'product' => $product, 'scents' => $scents );
		}
		return $out;
	}

	/**
	 * Does a scent need attention? (orphaned, or live but out of stock)
	 */
	private static function needs_attention( $s ) {
		list( $code ) = self::status_of( $s );
		return ( 'orphan' === $code ) || ( 'live' === $code && ! $s['in_stock'] );
	}

	/**
	 * live | orphan | off, plus a human label.
	 */
	private static function status_of( $s ) {
		if ( $s['live'] > 0 && ! $s['in_options'] ) {
			return array( 'orphan', 'Live on wholesale, off retail' );
		}
		if ( $s['in_options'] && ( $s['live'] > 0 || 0 === $s['hidden'] ) ) {
			return array( 'live', 'Live' );
		}
		return array( 'off', 'Discontinued' );
	}

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
		if ( $product && '' !== $scent && in_array( $action, array( 'discontinue', 'restore' ), true ) ) {
			self::set_scent( $product, $scent, 'restore' === $action );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=slw-pricing&slw_scent_updated=1#slw-scents' ) );
		exit;
	}

	/**
	 * Discontinue ($live=false) or restore ($live=true) a scent everywhere:
	 * flip its variations' status and add/remove it from the product options.
	 */
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

	/**
	 * Renders as a section inside the Wholesale > Pricing page (not its own tab).
	 */
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
			<p style="color:#628393;margin-bottom:14px;max-width:800px;">Every scent's status, stock, and recent demand in one place. <em>Sold 90d</em> is total units sold across retail and wholesale in the last 90 days (refunds excluded), so keep/discontinue calls reflect real demand. Discontinuing a scent removes it from <strong>both</strong> retail and wholesale in one click. <span style="color:#c0392b;font-weight:600;">Needs attention</span> = live on wholesale but off retail, or live but out of stock.</p>
			<?php if ( isset( $_GET['slw_scent_updated'] ) ) : ?>
				<div class="notice notice-success inline" style="margin:0 0 14px;"><p>Scent updated across retail and wholesale.</p></div>
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
				<table class="widefat fixed striped" style="max-width:880px;">
					<thead><tr>
						<th style="width:26%;">Scent</th>
						<th style="width:22%;">Status</th>
						<th style="width:15%;">Sizes</th>
						<th style="width:14%;">Stock</th>
						<th style="width:10%;">Sold 90d</th>
						<th style="width:13%;">Action</th>
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
						?>
						<tr<?php echo $attn ? ' style="background:#fdf3f2;"' : ''; ?>>
							<td><strong><?php echo esc_html( $name ); ?></strong></td>
							<td><span style="color:<?php echo esc_attr( $color ); ?>;font-weight:600;"><?php echo esc_html( $label ); ?></span></td>
							<td style="color:#628393;"><?php echo $s['sizes'] ? esc_html( implode( ', ', $s['sizes'] ) ) : '&mdash;'; ?></td>
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
