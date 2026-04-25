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
		add_action( 'woocommerce_product_options_general_product_data', array( __CLASS__, 'add_case_pack_field' ), 21 );
		add_action( 'woocommerce_admin_process_product_object', array( __CLASS__, 'save_minimum_qty_field' ) );
		add_action( 'woocommerce_admin_process_product_object', array( __CLASS__, 'save_case_pack_field' ) );

		// Cart enforcement
		add_action( 'woocommerce_check_cart_items', array( __CLASS__, 'enforce_minimums' ) );
		add_action( 'woocommerce_check_cart_items', array( __CLASS__, 'enforce_case_packs' ) );

		// Quantity input min attribute on product pages
		add_filter( 'woocommerce_quantity_input_args', array( __CLASS__, 'quantity_input_args' ), 999, 2 );

		// Order form integration: expose minimum data via a filter other
		// modules can read, and hook into the product query args
		add_filter( 'slw_order_form_product_data', array( __CLASS__, 'add_minimum_to_order_form' ), 10, 2 );

		// Frontend product page: show case pack + minimum info for wholesale users
		add_action( 'woocommerce_single_product_summary', array( __CLASS__, 'display_case_pack_on_product_page' ), 25 );
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
		if ( ! slw_is_wholesale_context() ) {
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
		$data['case_pack_size'] = self::get_case_pack_size( $product->get_id() );
		return $data;
	}

	// ── Case Pack Size Field ──────────────────────────────────────────────

	/**
	 * Add the case pack size field on the product General tab, after Min Wholesale Qty.
	 */
	public static function add_case_pack_field() {
		woocommerce_wp_text_input( array(
			'id'                => '_slw_case_pack_size',
			'label'             => 'Wholesale Case Pack Size',
			'desc_tip'          => true,
			'description'       => 'Wholesale customers must order in multiples of this number (e.g. 6 = case of 6). Leave blank for no restriction.',
			'type'              => 'number',
			'custom_attributes' => array( 'step' => '1', 'min' => '0' ),
		) );
	}

	/**
	 * Save the case pack size field.
	 *
	 * @param WC_Product $product
	 */
	public static function save_case_pack_field( $product ) {
		$value = isset( $_POST['_slw_case_pack_size'] ) ? wc_clean( $_POST['_slw_case_pack_size'] ) : '';
		$product->update_meta_data( '_slw_case_pack_size', $value );
	}

	// ── Case Pack Cart Enforcement ────────────────────────────────────────

	/**
	 * Validate that cart quantities are multiples of the case pack size.
	 */
	public static function enforce_case_packs() {
		if ( ! slw_is_wholesale_context() ) {
			return;
		}

		foreach ( WC()->cart->get_cart() as $cart_item ) {
			$product = $cart_item['data'];
			if ( ! $product ) {
				continue;
			}

			$product_id = $product->get_id();
			$parent_id  = $product->get_parent_id();
			$case_size  = self::get_case_pack_size( $parent_id ? $parent_id : $product_id );

			if ( $case_size <= 0 ) {
				continue;
			}

			$qty = (int) $cart_item['quantity'];
			if ( $qty % $case_size !== 0 ) {
				wc_add_notice(
					sprintf(
						'%s must be ordered in multiples of %d (case pack). You have %d in your cart.',
						esc_html( $product->get_name() ),
						$case_size,
						$qty
					),
					'error'
				);
			}
		}
	}

	// ── Quantity Input Args (updated for case pack step) ──────────────────

	/**
	 * Override quantity_input_args to also set the step attribute for case packs.
	 */
	public static function quantity_input_args( $args, $product ) {
		if ( ! slw_is_wholesale_context() ) {
			return $args;
		}

		$product_id = $product->get_id();
		$parent_id  = $product->get_parent_id();
		$lookup_id  = $parent_id ? $parent_id : $product_id;

		// Minimum qty
		$min = self::get_product_minimum( $lookup_id );
		if ( $min > 0 ) {
			$args['min_value'] = $min;
			if ( isset( $args['input_value'] ) && (int) $args['input_value'] < $min ) {
				$args['input_value'] = $min;
			}
		}

		// Case pack step
		$case_size = self::get_case_pack_size( $lookup_id );
		if ( $case_size > 0 ) {
			$args['step'] = $case_size;
			// Default value should be the case pack size (not 1)
			if ( ! isset( $args['input_value'] ) || (int) $args['input_value'] < $case_size ) {
				$args['input_value'] = $case_size;
			}
			// Ensure min_value is at least case_size
			if ( ! isset( $args['min_value'] ) || (int) $args['min_value'] < $case_size ) {
				$args['min_value'] = $case_size;
			}
		}

		return $args;
	}

	// ── Frontend Product Page Display ─────────────────────────────────────

	/**
	 * Display case pack size and minimum order info on single product pages
	 * for logged-in wholesale customers.
	 */
	public static function display_case_pack_on_product_page() {
		if ( ! slw_is_wholesale_context() ) {
			return;
		}

		global $product;
		if ( ! $product || ! is_a( $product, 'WC_Product' ) ) {
			return;
		}

		$product_id = $product->get_id();
		$case_pack  = self::get_case_pack_size( $product_id );
		$min_qty    = self::get_product_minimum( $product_id );

		if ( $case_pack <= 0 && $min_qty <= 0 ) {
			return;
		}

		$parts = array();
		if ( $case_pack > 0 ) {
			$parts[] = 'Case of ' . esc_html( $case_pack );
		}
		if ( $min_qty > 0 ) {
			$parts[] = 'Min. order: ' . esc_html( $min_qty ) . ' units';
		}

		echo '<p class="slw-wholesale-label">Wholesale: ' . implode( ' | ', $parts ) . '</p>';
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

	/**
	 * Get the case pack size for a product.
	 *
	 * @param int $product_id
	 * @return int 0 if no case pack set.
	 */
	public static function get_case_pack_size( $product_id ) {
		$size = get_post_meta( $product_id, '_slw_case_pack_size', true );
		return ( $size !== '' && is_numeric( $size ) && (int) $size > 0 ) ? (int) $size : 0;
	}
}
