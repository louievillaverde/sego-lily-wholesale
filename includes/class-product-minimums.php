<?php
/**
 * Per-Product Minimum Quantity
 *
 * Adds a "Minimum Wholesale Quantity" field to each product. When a
 * wholesale user adds the product to their cart with fewer than the
 * minimum, a WooCommerce error notice blocks checkout. The quantity
 * stepper on product pages and the order form also respects the min.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class SLW_Product_Minimums {

	public static function init() {
		// Product edit field (after the existing wholesale price fields)
		add_action( 'woocommerce_product_options_general_product_data', array( __CLASS__, 'add_minimum_qty_field' ), 20 );
		add_action( 'woocommerce_admin_process_product_object', array( __CLASS__, 'save_minimum_qty_field' ) );

		// Cart enforcement
		add_action( 'woocommerce_check_cart_items', array( __CLASS__, 'enforce_minimums' ) );

		// Quantity input min attribute on product pages
		add_filter( 'woocommerce_quantity_input_args', array( __CLASS__, 'quantity_input_args' ), 10, 2 );

		// Order form integration: expose minimum data via a filter other
		// modules can read, and hook into the product query args
		add_filter( 'slw_order_form_product_data', array( __CLASS__, 'add_minimum_to_order_form' ), 10, 2 );
	}

	// ── Product Edit Field ────────────────────────────────────────────────

	/**
	 * Add the minimum qty field on the product General tab, after wholesale pricing.
	 */
	public static function add_minimum_qty_field() {
		woocommerce_wp_text_input( array(
			'id'                => '_slw_minimum_qty',
			'label'             => 'Min. Wholesale Qty',
			'desc_tip'          => true,
			'description'       => 'Minimum quantity a wholesale customer must order for this product. Leave blank for no minimum.',
			'type'              => 'number',
			'custom_attributes' => array( 'step' => '1', 'min' => '0' ),
		) );
	}

	/**
	 * Save the minimum qty field.
	 *
	 * @param WC_Product $product
	 */
	public static function save_minimum_qty_field( $product ) {
		$value = isset( $_POST['_slw_minimum_qty'] ) ? wc_clean( $_POST['_slw_minimum_qty'] ) : '';
		$product->update_meta_data( '_slw_minimum_qty', $value );
	}

	// ── Cart Enforcement ──────────────────────────────────────────────────

	/**
	 * Check every cart item against its per-product minimum. Fires on the
	 * same hook (woocommerce_check_cart_items) that class-order-rules.php
	 * uses for the order-total minimum, so both checks run together.
	 */
	public static function enforce_minimums() {
		if ( ! slw_is_wholesale_user() ) {
			return;
		}

		foreach ( WC()->cart->get_cart() as $cart_item ) {
			$product = $cart_item['data'];
			if ( ! $product ) {
				continue;
			}

			// For variations, check the parent product's minimum too
			$product_id = $product->get_id();
			$parent_id = $product->get_parent_id();
			$min = self::get_product_minimum( $parent_id ? $parent_id : $product_id );

			if ( $min <= 0 ) {
				continue;
			}

			$qty = (int) $cart_item['quantity'];
			if ( $qty < $min ) {
				wc_add_notice(
					sprintf(
						'%s requires a minimum wholesale quantity of %d. You have %d in your cart.',
						esc_html( $product->get_name() ),
						$min,
						$qty
					),
					'error'
				);
			}
		}
	}

	// ── Quantity Input Args ───────────────────────────────────────────────

	/**
	 * Set the min attribute on quantity inputs for wholesale users so the
	 * stepper starts at the product minimum.
	 *
	 * @param array      $args    Quantity input args.
	 * @param WC_Product $product Product object.
	 * @return array
	 */
	public static function quantity_input_args( $args, $product ) {
		if ( ! slw_is_wholesale_user() ) {
			return $args;
		}

		$product_id = $product->get_id();
		$parent_id = $product->get_parent_id();
		$min = self::get_product_minimum( $parent_id ? $parent_id : $product_id );

		if ( $min > 0 ) {
			$args['min_value'] = $min;
			// Only set input_value if it's currently below the minimum
			if ( isset( $args['input_value'] ) && (int) $args['input_value'] < $min ) {
				$args['input_value'] = $min;
			}
		}

		return $args;
	}

	// ── Order Form Integration ────────────────────────────────────────────

	/**
	 * Add minimum quantity data to order form product data array.
	 * This filter is available for the order form template to use.
	 *
	 * @param array      $data    Product data array.
	 * @param WC_Product $product Product object.
	 * @return array
	 */
	public static function add_minimum_to_order_form( $data, $product ) {
		$min = self::get_product_minimum( $product->get_id() );
		$data['minimum_qty'] = $min;
		return $data;
	}

	// ── Helper ────────────────────────────────────────────────────────────

	/**
	 * Get the minimum wholesale quantity for a product.
	 *
	 * @param int $product_id
	 * @return int 0 if no minimum set.
	 */
	public static function get_product_minimum( $product_id ) {
		$min = get_post_meta( $product_id, '_slw_minimum_qty', true );
		return ( $min !== '' && is_numeric( $min ) && (int) $min > 0 ) ? (int) $min : 0;
	}
}
