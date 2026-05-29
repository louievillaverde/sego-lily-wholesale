<?php
/**
 * Downloadable Line Sheet / Price List
 *
 * Serves a clean, print-optimized HTML catalog of all wholesale products
 * grouped by category. Accessible only to logged-in wholesale users.
 *
 * Endpoint: ?slw_linesheet=1&key=NONCE
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class SLW_PDF_Linesheet {

	public static function init() {
		add_filter( 'query_vars', array( __CLASS__, 'register_query_vars' ) );
		add_action( 'template_redirect', array( __CLASS__, 'handle_linesheet_request' ) );
	}

	/**
	 * Register the custom query variable.
	 */
	public static function register_query_vars( $vars ) {
		$vars[] = 'slw_linesheet';
		return $vars;
	}

	/**
	 * Get the line sheet URL for the current user.
	 *
	 * @return string
	 */
	public static function get_linesheet_url() {
		return add_query_arg( array(
			'slw_linesheet' => '1',
			'key'           => wp_create_nonce( 'slw_linesheet' ),
		), home_url( '/' ) );
	}

	/**
	 * Intercept line sheet requests.
	 */
	public static function handle_linesheet_request() {
		if ( ! get_query_var( 'slw_linesheet' ) ) {
			return;
		}

		// Security: must be logged in as a wholesale user
		if ( ! is_user_logged_in() || ! slw_is_wholesale_user() ) {
			wp_die(
				esc_html__( 'You must be logged in as a wholesale customer to view the price list.', 'sego-lily-wholesale' ),
				esc_html__( 'Access Denied', 'sego-lily-wholesale' ),
				403
			);
		}

		// Verify nonce
		$nonce = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'slw_linesheet' ) ) {
			wp_die(
				esc_html__( 'This link has expired. Please return to your dashboard and click the button again.', 'sego-lily-wholesale' ),
				esc_html__( 'Link Expired', 'sego-lily-wholesale' ),
				403
			);
		}

		self::render_linesheet();
		exit;
	}

	/**
	 * Query all published products and group them by category.
	 *
	 * @return array [ 'Category Name' => [ product_data, ... ], ... ]
	 */
	public static function get_products_by_category() {
		$args = array(
			'status'  => 'publish',
			'limit'   => -1,
			'orderby' => 'title',
			'order'   => 'ASC',
		);

		$products = wc_get_products( $args );

		$products = array_filter( $products, function( $p ) {
			return $p->is_type( 'simple' ) || $p->is_type( 'variable' )
				|| $p->is_type( 'subscription' ) || $p->is_type( 'variable-subscription' );
		} );

		$grouped  = array();
		$discount_pct = (float) slw_get_option( 'discount_percent', 50 );

		remove_filter( 'woocommerce_product_get_price', array( 'SLW_Wholesale_Role', 'apply_wholesale_price' ), 99 );
		remove_filter( 'woocommerce_product_get_sale_price', array( 'SLW_Wholesale_Role', 'apply_wholesale_price' ), 99 );

		foreach ( $products as $product ) {
			$terms = get_the_terms( $product->get_id(), 'product_cat' );
			$category_name = ( $terms && ! is_wp_error( $terms ) )
				? $terms[0]->name
				: 'Uncategorized';

			$tiers_string = $product->get_meta( '_slw_tiered_pricing' );
			$min_qty = '';
			if ( $tiers_string ) {
				$tiers = SLW_Wholesale_Role::parse_tiers( $tiers_string );
				if ( ! empty( $tiers ) ) {
					$min_qty = min( array_keys( $tiers ) );
				}
			}

			$image_id  = $product->get_image_id();
			$image_url = $image_id ? wp_get_attachment_image_url( $image_id, 'thumbnail' ) : '';

			$rows = self::build_rows_for_product( $product, $discount_pct );
			foreach ( $rows as $row ) {
				$row['min_qty']   = $row['min_qty']   ?? $min_qty;
				$row['image_url'] = $row['image_url'] ?? $image_url;
				$grouped[ $category_name ][] = $row;
			}
		}

		// Restore the wholesale price filter
		add_filter( 'woocommerce_product_get_price', array( 'SLW_Wholesale_Role', 'apply_wholesale_price' ), 99, 2 );
		add_filter( 'woocommerce_product_get_sale_price', array( 'SLW_Wholesale_Role', 'apply_wholesale_price' ), 99, 2 );

		ksort( $grouped );
		return $grouped;
	}

	/**
	 * Build one or more rows for a product. Simple products yield a single
	 * row. Variable / variable-subscription products are expanded into one
	 * row per unique size/scent variation, mirroring the order form: the
	 * payment-frequency attribute is stripped from the label, variations
	 * are deduplicated by the remaining attribute label, and the MAX-priced
	 * sibling per label wins (= the one-time charge).
	 *
	 * @param WC_Product $product
	 * @param float      $discount_pct Global discount percentage.
	 * @return array<int,array> Each row has name, sku, retail_price, wholesale_price, min_qty, image_url.
	 */
	private static function build_rows_for_product( $product, $discount_pct ) {
		$is_variable = $product->is_type( 'variable' ) || $product->is_type( 'variable-subscription' );

		if ( ! $is_variable ) {
			$retail    = (float) $product->get_price();
			if ( $retail <= 0 ) {
				$retail = slw_get_true_regular_price( $product );
			}
			$wholesale = self::calculate_wholesale_price( $product, $retail );
			if ( $discount_pct > 0 && $discount_pct < 100 && $wholesale > 0 ) {
				$retail = round( $wholesale / ( 1 - $discount_pct / 100 ), 2 );
			}
			return array( array(
				'name'            => $product->get_name(),
				'sku'             => $product->get_sku(),
				'retail_price'    => $retail,
				'wholesale_price' => $wholesale,
			) );
		}

		// Variable / variable-subscription: walk variations and group by
		// non-payment attribute label, picking max-priced per group.
		$variations = $product->get_available_variations();
		$groups = array(); // label_key => array of variation rows
		foreach ( $variations as $var_data ) {
			$variation = wc_get_product( $var_data['variation_id'] ?? 0 );
			if ( ! $variation || ! $variation->is_in_stock() ) {
				continue;
			}
			$attrs = $var_data['attributes'] ?? array();
			$label_parts = array();
			foreach ( $attrs as $attr_key => $attr_val ) {
				if ( ! $attr_val ) continue;
				$taxonomy  = str_replace( 'attribute_', '', $attr_key );
				$term      = get_term_by( 'slug', $attr_val, $taxonomy );
				$term_name = $term ? $term->name : ucfirst( str_replace( array( '-', '_' ), ' ', $attr_val ) );
				$lower = strtolower( $term_name );
				if ( preg_match( '/\d+\s*\/\s*mo|\d+\s*month|monthly|yearly|weekly|every\s*\d|one.?time|subscribe|subscription/i', $lower ) ) {
					continue;
				}
				$label_parts[] = $term_name;
			}
			$label = ! empty( $label_parts ) ? implode( ' / ', $label_parts ) : '';
			$label_key = strtolower( trim( $label ) );

			$var_price = (float) $variation->get_price();
			if ( $var_price <= 0 ) continue;

			if ( ! isset( $groups[ $label_key ] ) ) {
				$groups[ $label_key ] = array(
					'label'       => $label,
					'best_price'  => 0.0,
					'best_var'    => null,
				);
			}
			if ( $var_price > $groups[ $label_key ]['best_price'] ) {
				$groups[ $label_key ]['best_price'] = $var_price;
				$groups[ $label_key ]['best_var']   = $variation;
			}
		}

		if ( empty( $groups ) ) {
			// Fall back: treat as a single row using the parent's helper price.
			$retail    = slw_get_true_regular_price( $product );
			$wholesale = self::calculate_wholesale_price( $product, $retail );
			if ( $discount_pct > 0 && $discount_pct < 100 && $wholesale > 0 ) {
				$retail = round( $wholesale / ( 1 - $discount_pct / 100 ), 2 );
			}
			return array( array(
				'name'            => $product->get_name(),
				'sku'             => $product->get_sku(),
				'retail_price'    => $retail,
				'wholesale_price' => $wholesale,
			) );
		}

		$rows = array();
		foreach ( $groups as $g ) {
			/** @var WC_Product_Variation $variation */
			$variation = $g['best_var'];
			$retail    = (float) $g['best_price'];
			$wholesale = self::calculate_wholesale_price( $variation, $retail );
			if ( $discount_pct > 0 && $discount_pct < 100 && $wholesale > 0 ) {
				$retail = round( $wholesale / ( 1 - $discount_pct / 100 ), 2 );
			}
			$var_image_id = $variation->get_image_id();
			$rows[] = array(
				'name'            => $product->get_name() . ( $g['label'] !== '' ? ', ' . $g['label'] : '' ),
				'sku'             => $variation->get_sku() ?: $product->get_sku(),
				'retail_price'    => $retail,
				'wholesale_price' => $wholesale,
				'image_url'       => $var_image_id ? wp_get_attachment_image_url( $var_image_id, 'thumbnail' ) : null,
			);
		}
		return $rows;
	}

	/**
	 * Calculate wholesale price for a product (mirrors the pricing engine logic).
	 *
	 * @param WC_Product $product
	 * @param float      $retail_price
	 * @return float
	 */
	private static function calculate_wholesale_price( $product, $retail_price ) {
		// 1. Per-product override
		$override = $product->get_meta( '_slw_wholesale_price' );
		if ( $override !== '' && is_numeric( $override ) && (float) $override >= 0 ) {
			return round( (float) $override, 2 );
		}

		// 2. Category-level discount
		$terms = get_the_terms( $product->get_id(), 'product_cat' );
		if ( $terms && ! is_wp_error( $terms ) ) {
			foreach ( $terms as $term ) {
				$cat_discount = get_term_meta( $term->term_id, 'slw_category_discount', true );
				if ( $cat_discount !== '' && is_numeric( $cat_discount ) ) {
					return round( $retail_price * ( 1 - (float) $cat_discount / 100 ), 2 );
				}
			}
		}

		// 3. Global discount
		$discount = (float) slw_get_option( 'discount_percent', 50 );
		return round( $retail_price * ( 1 - $discount / 100 ), 2 );
	}

	/**
	 * Render the standalone HTML line sheet page and exit.
	 */
	private static function render_linesheet() {
		$logo_id        = absint( SLW_Invoice_Settings::get( 'logo_id' ) );
		$logo_url       = $logo_id ? wp_get_attachment_image_url( $logo_id, 'medium' ) : '';
		$business_name  = SLW_Invoice_Settings::get( 'business_name' );
		$business_phone = SLW_Invoice_Settings::get( 'business_phone' );
		$business_email = SLW_Invoice_Settings::get( 'business_email' );
		$accent         = SLW_Invoice_Settings::get( 'accent_color' );
		$accent_light   = $accent . '1a';

		$products_by_cat = self::get_products_by_category();
		$today           = date_i18n( 'F j, Y' );

		// Personalize for the logged-in wholesale customer by default. Allow
		// override via ?prepared_for=Name in the URL (e.g. when Holly sends
		// the sheet to a prospect she's quoting) and via on-page click-to-edit
		// for ad-hoc tweaks before printing.
		$current_user      = wp_get_current_user();
		$customer_business = $current_user->ID ? get_user_meta( $current_user->ID, 'slw_business_name', true ) : '';
		$customer_default  = $customer_business ?: trim( $current_user->first_name . ' ' . $current_user->last_name );
		if ( ! $customer_default ) {
			$customer_default = $current_user->display_name ?: '';
		}
		$customer_label = isset( $_GET['prepared_for'] )
			? sanitize_text_field( wp_unslash( $_GET['prepared_for'] ) )
			: $customer_default;

		header( 'Content-Type: text/html; charset=utf-8' );
		?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Wholesale Price List - <?php echo esc_html( $business_name ); ?></title>
<style>
/* Reset */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

body {
	font-family: Inter, system-ui, -apple-system, sans-serif;
	font-size: 13px;
	line-height: 1.5;
	color: #1E2A30;
	background: #f5f5f5;
	-webkit-print-color-adjust: exact;
	print-color-adjust: exact;
}

.linesheet-container {
	max-width: 900px;
	margin: 24px auto;
	background: #fff;
	border-radius: 8px;
	box-shadow: 0 1px 3px rgba(0,0,0,0.1);
	overflow: hidden;
}

/* Header */
.linesheet-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 32px 40px;
	border-bottom: 3px solid <?php echo esc_attr( $accent ); ?>;
}

.linesheet-logo img {
	max-width: 160px;
	max-height: 56px;
	display: block;
}

.linesheet-logo-text {
	font-family: Georgia, 'Times New Roman', serif;
	font-size: 22px;
	font-weight: 700;
	color: <?php echo esc_attr( $accent ); ?>;
}

.linesheet-header-right {
	text-align: right;
}

.linesheet-header-right .title {
	font-family: Georgia, 'Times New Roman', serif;
	font-size: 22px;
	font-weight: 700;
	color: <?php echo esc_attr( $accent ); ?>;
	margin-bottom: 4px;
}

.linesheet-header-right .customer-row {
	margin-top: 6px;
	font-size: 13px;
	color: #1E2A30;
	display: inline-flex;
	gap: 6px;
	align-items: baseline;
}

.linesheet-header-right .customer-prefix {
	color: #628393;
	font-weight: 400;
}

.linesheet-header-right .customer {
	font-weight: 600;
	letter-spacing: 0.2px;
	min-width: 80px;
	display: inline-block;
	padding: 1px 6px;
	border-radius: 4px;
	border: 1px dashed transparent;
	outline: none;
	cursor: text;
	transition: border-color 0.15s, background 0.15s;
}

.linesheet-header-right .customer:hover {
	border-color: #d0c8b8;
	background: #faf7f0;
}

.linesheet-header-right .customer:focus {
	border-color: <?php echo esc_attr( $accent ); ?>;
	background: #fff;
}

.linesheet-header-right .customer:empty::before {
	content: attr(data-placeholder);
	color: #b5b0a3;
	font-weight: 400;
	font-style: italic;
}

.linesheet-header-right .date {
	font-size: 12px;
	color: #628393;
	margin-top: 4px;
	text-transform: uppercase;
	letter-spacing: 0.5px;
}

/* Body */
.linesheet-body {
	padding: 24px 40px 32px;
}

/* Category sections */
.linesheet-category {
	margin-bottom: 28px;
	page-break-inside: avoid;
}

.linesheet-category-header {
	font-family: Georgia, 'Times New Roman', serif;
	font-size: 18px;
	font-weight: 700;
	color: <?php echo esc_attr( $accent ); ?>;
	padding: 10px 0;
	border-bottom: 2px solid <?php echo esc_attr( $accent ); ?>;
	margin-bottom: 0;
}

/* Product table */
.linesheet-table {
	width: 100%;
	border-collapse: collapse;
}

.linesheet-table thead th {
	font-size: 10px;
	text-transform: uppercase;
	letter-spacing: 1px;
	color: #628393;
	font-weight: 600;
	padding: 10px 8px;
	border-bottom: 1px solid #e0ddd8;
	text-align: left;
}

.linesheet-table thead th.text-right {
	text-align: right;
}

.linesheet-table thead th.text-center {
	text-align: center;
}

.linesheet-table tbody td {
	padding: 10px 8px;
	border-bottom: 1px solid #f0eeea;
	font-size: 13px;
	vertical-align: middle;
}

.linesheet-table tbody td.text-right {
	text-align: right;
}

.linesheet-table tbody td.text-center {
	text-align: center;
}

.linesheet-table tbody tr:hover {
	background: #fafaf8;
}

.linesheet-product-img {
	width: 48px;
	height: 48px;
	object-fit: cover;
	border-radius: 4px;
	background: #f0eeea;
}

.linesheet-product-img-placeholder {
	width: 48px;
	height: 48px;
	border-radius: 4px;
	background: #f0eeea;
	display: flex;
	align-items: center;
	justify-content: center;
	color: #ccc;
	font-size: 18px;
}

.linesheet-product-name {
	font-weight: 600;
	color: #1E2A30;
}

.linesheet-sku {
	color: #8A9499;
	font-size: 12px;
}

.linesheet-retail-price {
	text-decoration: line-through;
	color: #8A9499;
	font-size: 12px;
}

.linesheet-wholesale-price {
	font-weight: 700;
	color: <?php echo esc_attr( $accent ); ?>;
	font-size: 14px;
}

.linesheet-min-qty {
	font-size: 12px;
	color: #628393;
}

/* Footer */
.linesheet-footer {
	border-top: 1px solid #e0ddd8;
	padding: 20px 40px;
	text-align: center;
	font-size: 12px;
	color: #628393;
	line-height: 1.8;
}

/* Confidential notice */
.linesheet-confidential {
	background: <?php echo esc_attr( $accent_light ); ?>;
	padding: 10px 40px;
	text-align: center;
	font-size: 11px;
	color: #628393;
	text-transform: uppercase;
	letter-spacing: 1px;
	font-weight: 600;
}

/* Action buttons */
.linesheet-actions {
	position: fixed;
	bottom: 24px;
	right: 24px;
	display: flex;
	gap: 8px;
	z-index: 100;
}

.linesheet-actions button {
	display: inline-flex;
	align-items: center;
	gap: 8px;
	padding: 12px 24px;
	font-family: Inter, system-ui, sans-serif;
	font-size: 14px;
	font-weight: 600;
	border: none;
	border-radius: 6px;
	cursor: pointer;
	box-shadow: 0 2px 8px rgba(0,0,0,0.15);
	transition: transform 0.1s, box-shadow 0.1s;
}

.linesheet-actions button:hover {
	transform: translateY(-1px);
	box-shadow: 0 4px 12px rgba(0,0,0,0.2);
}

.linesheet-actions .btn-print {
	background: <?php echo esc_attr( $accent ); ?>;
	color: #fff;
}

/* Print styles */
@media print {
	body {
		background: #fff;
		font-size: 11px;
	}

	.linesheet-container {
		max-width: none;
		margin: 0;
		box-shadow: none;
		border-radius: 0;
	}

	.linesheet-header {
		padding: 12px 20px 14px;
	}

	.linesheet-body {
		padding: 14px 20px 18px;
	}

	.linesheet-footer {
		padding: 10px 20px;
	}

	.linesheet-confidential {
		padding: 6px 20px;
	}

	.linesheet-actions {
		display: none !important;
	}

	.linesheet-header-right .customer {
		border-color: transparent !important;
		background: transparent !important;
		padding: 0 !important;
	}

	.linesheet-header-right .customer:empty::before {
		content: "" !important;
	}

	.linesheet-category {
		page-break-inside: avoid;
		margin-bottom: 18px;
	}

	.linesheet-category-header {
		font-size: 15px;
		padding: 6px 0;
	}

	.linesheet-table tbody tr {
		page-break-inside: avoid;
	}

	.linesheet-table tbody tr:hover {
		background: transparent;
	}

	.linesheet-table tbody td {
		padding: 6px 6px;
		font-size: 11px;
	}

	.linesheet-product-img,
	.linesheet-product-img-placeholder {
		width: 32px;
		height: 32px;
	}

	.linesheet-wholesale-price {
		font-size: 12px;
	}

	@page {
		margin: 0.4in 0.4in 0.55in;
		size: letter portrait;
		@bottom-center {
			content: "Page " counter(page) " of " counter(pages);
			font-family: Inter, system-ui, sans-serif;
			font-size: 9px;
			color: #8A9499;
		}
	}
}
</style>
</head>
<body>

<div class="linesheet-container">
	<!-- Confidential banner -->
	<div class="linesheet-confidential">
		Confidential Wholesale Pricing - For Authorized Partners Only
	</div>

	<!-- Header -->
	<div class="linesheet-header">
		<div class="linesheet-logo">
			<?php if ( $logo_url ) : ?>
				<img src="<?php echo esc_url( $logo_url ); ?>" alt="<?php echo esc_attr( $business_name ); ?>" />
			<?php else : ?>
				<div class="linesheet-logo-text"><?php echo esc_html( $business_name ); ?></div>
			<?php endif; ?>
		</div>
		<div class="linesheet-header-right">
			<div class="title">Wholesale Price List</div>
			<div class="customer-row">
				<span class="customer-prefix">Prepared for</span>
				<span class="customer"
				      contenteditable="true"
				      spellcheck="false"
				      role="textbox"
				      aria-label="Prepared for (click to edit)"
				      data-placeholder="(click to add a name)"><?php echo esc_html( $customer_label ); ?></span>
			</div>
			<div class="date"><?php echo esc_html( $today ); ?></div>
		</div>
	</div>

	<!-- Products by category -->
	<div class="linesheet-body">
		<?php if ( empty( $products_by_cat ) ) : ?>
			<p style="text-align:center;color:#628393;padding:40px 0;">No products found.</p>
		<?php else : ?>
			<?php foreach ( $products_by_cat as $category => $products ) : ?>
				<div class="linesheet-category">
					<h2 class="linesheet-category-header"><?php echo esc_html( $category ); ?></h2>
					<table class="linesheet-table">
						<thead>
							<tr>
								<th style="width:52px;"></th>
								<th style="width:35%;">Product</th>
								<th style="width:12%;">SKU</th>
								<th class="text-right" style="width:13%;">Retail</th>
								<th class="text-right" style="width:15%;">Wholesale</th>
								<th class="text-center" style="width:10%;">Min Qty</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $products as $product ) : ?>
							<tr>
								<td>
									<?php if ( $product['image_url'] ) : ?>
										<img class="linesheet-product-img" src="<?php echo esc_url( $product['image_url'] ); ?>" alt="" />
									<?php else : ?>
										<div class="linesheet-product-img-placeholder">-</div>
									<?php endif; ?>
								</td>
								<td>
									<span class="linesheet-product-name"><?php echo esc_html( $product['name'] ); ?></span>
								</td>
								<td>
									<span class="linesheet-sku"><?php echo esc_html( $product['sku'] ?: '-' ); ?></span>
								</td>
								<td class="text-right">
									<span class="linesheet-retail-price"><?php echo wp_kses_post( wc_price( $product['retail_price'] ) ); ?></span>
								</td>
								<td class="text-right">
									<span class="linesheet-wholesale-price"><?php echo wp_kses_post( wc_price( $product['wholesale_price'] ) ); ?></span>
								</td>
								<td class="text-center">
									<?php if ( $product['min_qty'] ) : ?>
										<span class="linesheet-min-qty"><?php echo esc_html( $product['min_qty'] ); ?>+</span>
									<?php else : ?>
										<span class="linesheet-min-qty">-</span>
									<?php endif; ?>
								</td>
							</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endforeach; ?>
		<?php endif; ?>
	</div>

	<!-- Footer -->
	<div class="linesheet-footer">
		<?php echo esc_html( $business_name ); ?>
		<?php if ( $business_phone ) : ?>
			&nbsp;&middot;&nbsp; <?php echo esc_html( $business_phone ); ?>
		<?php endif; ?>
		<?php if ( $business_email ) : ?>
			&nbsp;&middot;&nbsp; <?php echo esc_html( $business_email ); ?>
		<?php endif; ?>
		<br>
		Prices are subject to change without notice. All prices in <?php echo esc_html( get_woocommerce_currency() ); ?>.
	</div>
</div>

<!-- Floating action button -->
<div class="linesheet-actions">
	<button type="button" class="btn-print" onclick="window.print();">
		<svg width="16" height="16" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg">
			<path d="M4 1h8v3H4V1zm-2 3h12a2 2 0 012 2v5h-3v4H3v-4H0V6a2 2 0 012-2zm3 7h6v3H5v-3zm7-4a1 1 0 100 2 1 1 0 000-2z" fill="currentColor"/>
		</svg>
		Print / Save as PDF
	</button>
</div>

</body>
</html>
		<?php
	}
}
