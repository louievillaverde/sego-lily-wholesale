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
	 * Scan variable products and build per-scent status.
	 */
	private static function scan() {
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
					);
				}
				if ( 'publish' === $v->get_status() ) {
					$scents[ $name ]['live']++;
				} else {
					$scents[ $name ]['hidden']++;
				}
			}
			foreach ( $options as $o ) {
				$o = (string) $o;
				if ( ! isset( $scents[ $o ] ) ) {
					$scents[ $o ] = array( 'in_options' => true, 'live' => 0, 'hidden' => 0 );
				}
			}
			ksort( $scents );
			$out[] = array( 'product' => $product, 'scents' => $scents );
		}
		return $out;
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
		if ( ! isset( $_GET['page'] ) || 'slw-scents' !== $_GET['page'] ) {
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
		wp_safe_redirect( admin_url( 'admin.php?page=slw-scents&updated=1' ) );
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

	public static function render_page() {
		$data = self::scan();
		?>
		<div class="wrap">
			<h1>Scent Availability</h1>
			<p class="description" style="max-width:760px;">Every scent across your products in one place. Discontinuing a scent here removes it from <strong>both</strong> retail and wholesale in one click, so a scent you stop making can't stay orderable anywhere. Anything flagged <span style="color:#c0392b;font-weight:600;">Live on wholesale, off retail</span> is the exact gap that lets a customer order a discontinued scent.</p>
			<?php if ( isset( $_GET['updated'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p>Scent updated across retail and wholesale.</p></div>
			<?php endif; ?>
			<?php if ( empty( $data ) ) : ?>
				<p>No products with scents found.</p>
			<?php endif; ?>
			<?php foreach ( $data as $row ) :
				$product = $row['product'];
				?>
				<h2 style="margin-top:26px;"><?php echo esc_html( $product->get_name() ); ?></h2>
				<table class="wp-list-table widefat fixed striped" style="max-width:760px;">
					<thead><tr><th style="width:42%;">Scent</th><th style="width:33%;">Status</th><th style="width:25%;">Action</th></tr></thead>
					<tbody>
					<?php foreach ( $row['scents'] as $name => $s ) :
						list( $code, $label ) = self::status_of( $s );
						$color = 'live' === $code ? '#1a764d' : ( 'orphan' === $code ? '#c0392b' : '#8A9499' );
						$act   = ( 'off' === $code ) ? 'restore' : 'discontinue';
						$url   = wp_nonce_url(
							admin_url( 'admin.php?page=slw-scents&slw_scent_action=' . $act . '&product=' . $product->get_id() . '&scent=' . rawurlencode( $name ) ),
							'slw_scent_action'
						);
						?>
						<tr>
							<td><strong><?php echo esc_html( $name ); ?></strong></td>
							<td><span style="color:<?php echo esc_attr( $color ); ?>;font-weight:600;"><?php echo esc_html( $label ); ?></span></td>
							<td>
								<?php if ( 'off' === $code ) : ?>
									<a href="<?php echo esc_url( $url ); ?>" class="button button-small">Restore</a>
								<?php else : ?>
									<a href="<?php echo esc_url( $url ); ?>" class="button button-small"
									   onclick="return confirm('Discontinue this scent everywhere? It will be removed from both retail and wholesale.');">Discontinue everywhere</a>
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
