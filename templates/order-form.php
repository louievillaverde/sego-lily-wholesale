<?php
/**
 * Template: Wholesale Order Form
 *
 * Rendered by the [sego_wholesale_order_form] shortcode.
 * Products grouped by category with inline variation rows.
 * Each category is collapsible with its own "Add to Cart" button.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

$user = wp_get_current_user();
$first_name = $user->first_name ?: $user->display_name;
$has_ordered = get_user_meta( $user->ID, 'slw_first_order_placed', true );
$minimum = (float) slw_get_option( 'first_order_minimum', 300 );
$nonce = wp_create_nonce( 'slw_order_form' );
$ajax_url = admin_url( 'admin-ajax.php' );

// Get all published products
$all_products = wc_get_products( array(
    'status'  => 'publish',
    'limit'   => -1,
    'orderby' => 'title',
    'order'   => 'ASC',
));

// Filter to orderable product types (includes subscription types since
// Holly's products are set up as subscriptions on the retail side but
// should be treated as one-time purchases for wholesale)
$all_products = array_filter( $all_products, function( $p ) {
    return $p->is_type( 'simple' ) || $p->is_type( 'variable' )
        || $p->is_type( 'subscription' ) || $p->is_type( 'variable-subscription' );
} );
$all_products = array_values( $all_products );

// Filter by order form categories if configured
$allowed_cats = get_option( 'slw_order_form_categories', array() );
$allowed_cats = is_array( $allowed_cats ) ? array_filter( array_map( 'absint', $allowed_cats ) ) : array();

// Group products by category
$grouped = array();
foreach ( $all_products as $product ) {
    $terms = get_the_terms( $product->get_id(), 'product_cat' );
    $category_name = ( $terms && ! is_wp_error( $terms ) ) ? $terms[0]->name : 'Uncategorized';
    $category_id   = ( $terms && ! is_wp_error( $terms ) ) ? $terms[0]->term_id : 0;

    // Skip if category filtering is active and this category isn't allowed
    if ( ! empty( $allowed_cats ) && $category_id && ! in_array( $category_id, $allowed_cats, true ) ) {
        continue;
    }

    $grouped[ $category_name ][] = $product;
}
ksort( $grouped );
$products = $all_products; // keep for empty check
?>

<div class="slw-order-form-wrap">

    <?php if ( get_option( 'slw_store_notice_enabled' ) && get_option( 'slw_store_notice_text' ) ) : ?>
        <?php
        $notice_type = get_option( 'slw_store_notice_type', 'info' );
        $notice_dismissible = get_option( 'slw_store_notice_dismissible', false );
        $notice_dismissed = get_user_meta( get_current_user_id(), 'slw_notice_dismissed', true );
        ?>
        <?php if ( ! $notice_dismissible || $notice_dismissed !== md5( get_option( 'slw_store_notice_text' ) ) ) : ?>
        <div class="slw-notice slw-notice-<?php echo esc_attr( $notice_type ); ?> slw-store-notice<?php echo $notice_dismissible ? ' slw-notice-dismissible' : ''; ?>">
            <?php echo wp_kses_post( get_option( 'slw_store_notice_text' ) ); ?>
            <?php if ( $notice_dismissible ) : ?>
                <button type="button" class="slw-notice-dismiss" data-nonce="<?php echo esc_attr( wp_create_nonce( 'slw_dismiss_notice' ) ); ?>">&times;</button>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    <?php endif; ?>

    <div class="slw-order-form-header">
        <h2>Wholesale Order Form</h2>
        <p>Hey <?php echo esc_html( $first_name ); ?>! Set your quantities and add items to your cart. All prices shown are your wholesale rate.</p>
        <?php if ( ! $has_ordered && $minimum > 0 ) : ?>
            <p class="slw-minimum-note">Your first order has a $<?php echo number_format( $minimum, 0 ); ?> minimum.</p>
        <?php elseif ( $has_ordered ) :
            $reorder_min = (float) slw_get_option( 'reorder_minimum', 0 );
            if ( $reorder_min > 0 ) : ?>
            <p class="slw-minimum-note">Reorder minimum: $<?php echo number_format( $reorder_min, 0 ); ?></p>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <div id="slw-order-message" class="slw-notice" style="display:none;" tabindex="-1"></div>

    <?php
    // ── Most Ordered: customer's top reorder candidates ──
    // Pulls completed/processing orders for this customer, tallies quantity
    // by product (or variation), and renders a quick-reorder grid above the
    // category sections. Reduces "I meant honey, not rosewood" mistakes.
    $most_ordered_ids = array();
    $past_orders = wc_get_orders( array(
        'customer_id' => $user->ID,
        'limit'       => 30,
        'status'      => array( 'wc-processing', 'wc-completed', 'wc-on-hold' ),
        'return'      => 'objects',
    ) );
    if ( ! empty( $past_orders ) ) {
        $tally = array();
        foreach ( $past_orders as $past_order ) {
            foreach ( $past_order->get_items() as $line ) {
                $pid = $line->get_variation_id() ?: $line->get_product_id();
                if ( ! $pid ) continue;
                $tally[ $pid ] = ( $tally[ $pid ] ?? 0 ) + (int) $line->get_quantity();
            }
        }
        arsort( $tally, SORT_NUMERIC );
        $most_ordered_ids = array_slice( array_keys( $tally ), 0, 6, true );
    }
    if ( ! empty( $most_ordered_ids ) ) :
    ?>
    <div class="slw-most-ordered">
        <div class="slw-section-header slw-most-ordered-header">
            <h3 class="slw-section-header__title">Your Most Ordered</h3>
            <span class="slw-section-header__hint">Quick reorder</span>
        </div>
        <div class="slw-new-arrivals-grid">
            <?php foreach ( $most_ordered_ids as $mo_id ) :
                $mo_product = wc_get_product( $mo_id );
                if ( ! $mo_product || ! $mo_product->is_in_stock() ) continue;

                // For variations, surface the variation directly so the right
                // scent gets reordered, not the parent.
                $is_var = $mo_product->is_type( 'variation' );
                $mo_parent = $is_var ? wc_get_product( $mo_product->get_parent_id() ) : $mo_product;

                $mo_image = wp_get_attachment_image_url( $mo_product->get_image_id(), 'thumbnail' );
                if ( ! $mo_image && $mo_parent ) {
                    $mo_image = wp_get_attachment_image_url( $mo_parent->get_image_id(), 'thumbnail' );
                }
                if ( ! $mo_image ) {
                    $mo_image = wc_placeholder_img_src( 'thumbnail' );
                }

                $mo_label = $mo_product->get_name();
                $mo_price = wc_price( $mo_product->get_price() );

                // Same case-pack/min logic the regular grid uses
                $mo_lookup_id  = $is_var ? $mo_product->get_parent_id() : $mo_product->get_id();
                $mo_case_pack  = class_exists( 'SLW_Product_Minimums' ) ? SLW_Product_Minimums::get_case_pack_size( $mo_lookup_id ) : 0;
                $mo_default    = $mo_case_pack > 0 ? $mo_case_pack : 1;
            ?>
            <div class="slw-new-arrival-card">
                <div class="slw-new-arrival-image">
                    <img src="<?php echo esc_url( $mo_image ); ?>" alt="<?php echo esc_attr( $mo_label ); ?>" />
                </div>
                <div class="slw-new-arrival-info">
                    <h4><?php echo esc_html( $mo_label ); ?></h4>
                    <div class="slw-new-arrival-price"><?php echo $mo_price; ?></div>
                    <div class="slw-new-arrival-actions">
                        <input type="number" class="slw-mo-qty-input" min="1" max="999" value="<?php echo esc_attr( $mo_default ); ?>"
                               data-product-id="<?php echo esc_attr( $is_var ? $mo_product->get_parent_id() : $mo_product->get_id() ); ?>"
                               data-variation-id="<?php echo esc_attr( $is_var ? $mo_product->get_id() : '' ); ?>" />
                        <button type="button" class="slw-btn slw-btn-small slw-btn-primary slw-mo-add-btn"
                                data-product-id="<?php echo esc_attr( $is_var ? $mo_product->get_parent_id() : $mo_product->get_id() ); ?>"
                                data-variation-id="<?php echo esc_attr( $is_var ? $mo_product->get_id() : '' ); ?>">Reorder</button>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php
    // New Arrivals section
    $new_arrivals = class_exists( 'SLW_New_Arrivals' ) ? SLW_New_Arrivals::get_products() : array();
    if ( ! empty( $new_arrivals ) ) :
    ?>
    <div class="slw-new-arrivals">
        <div class="slw-new-arrivals-header">
            <h3>New Arrivals</h3>
        </div>
        <div class="slw-new-arrivals-grid">
            <?php foreach ( $new_arrivals as $na_product ) :
                if ( ! $na_product->is_type( 'simple' ) && ! $na_product->is_type( 'variable' ) ) continue;

                $na_image = wp_get_attachment_image_url( $na_product->get_image_id(), 'medium' );
                if ( ! $na_image ) {
                    $na_image = wc_placeholder_img_src( 'medium' );
                }

                if ( $na_product->is_type( 'variable' ) ) {
                    $na_price_html = $na_product->get_price_html();
                } else {
                    $na_price_html = wc_price( $na_product->get_price() );
                }
            ?>
            <div class="slw-new-arrival-card">
                <span class="slw-new-badge">NEW</span>
                <div class="slw-new-arrival-image">
                    <img src="<?php echo esc_url( $na_image ); ?>" alt="<?php echo esc_attr( $na_product->get_name() ); ?>" />
                </div>
                <div class="slw-new-arrival-info">
                    <h4><?php echo esc_html( $na_product->get_name() ); ?></h4>
                    <div class="slw-new-arrival-price"><?php echo $na_price_html; ?></div>
                    <?php if ( $na_product->is_type( 'simple' ) && $na_product->is_in_stock() ) : ?>
                        <div class="slw-new-arrival-actions">
                            <input type="number" class="slw-na-qty-input" min="1" max="999" value="1" data-product-id="<?php echo esc_attr( $na_product->get_id() ); ?>" />
                            <button type="button" class="slw-btn slw-btn-small slw-btn-primary slw-na-add-btn" data-product-id="<?php echo esc_attr( $na_product->get_id() ); ?>">Add to Cart</button>
                        </div>
                    <?php elseif ( ! $na_product->is_in_stock() ) : ?>
                        <span class="slw-out-of-stock">Out of stock</span>
                    <?php else : ?>
                        <a href="<?php echo esc_url( $na_product->get_permalink() ); ?>" class="slw-btn slw-btn-small slw-btn-primary">Select Options</a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if ( empty( $grouped ) ) : ?>
        <p>No products available right now. Check back soon!</p>
    <?php else : ?>

    <?php foreach ( $grouped as $category_name => $cat_products ) :
        $cat_slug = sanitize_title( $category_name );

        // Look up the category's term_id by walking the first product's terms.
        $cat_term_id = 0;
        foreach ( $cat_products as $cp ) {
            $cp_terms = get_the_terms( $cp->get_id(), 'product_cat' );
            if ( $cp_terms && ! is_wp_error( $cp_terms ) ) {
                foreach ( $cp_terms as $cp_term ) {
                    if ( $cp_term->name === $category_name ) {
                        $cat_term_id = $cp_term->term_id;
                        break 2;
                    }
                }
            }
        }
        $category_min_qty = ( $cat_term_id && class_exists( 'SLW_Category_Minimums' ) )
            ? SLW_Category_Minimums::get_category_minimum( $cat_term_id )
            : 0;
    ?>
    <div class="slw-category-section" data-category="<?php echo esc_attr( $cat_slug ); ?>" data-category-min="<?php echo esc_attr( $category_min_qty ); ?>" data-category-term="<?php echo esc_attr( $cat_term_id ); ?>">
        <div class="slw-category-header" style="display:flex;justify-content:space-between;align-items:center;background:#386174;color:#F7F6F3;padding:14px 20px;border-radius:8px 8px 0 0;margin-top:20px;cursor:pointer;" data-category="<?php echo esc_attr( $cat_slug ); ?>">
            <div>
                <?php
                $cat_display = $category_name;
                if ( strcasecmp( $cat_display, 'Bundles' ) === 0 || strcasecmp( $cat_display, 'Bundle' ) === 0 ) {
                    $cat_display = 'Variety Sets';
                }
                ?>
                <h3 style="margin:0;font-size:18px;color:#F7F6F3;font-family:Georgia,'Times New Roman',serif;"><?php echo esc_html( $cat_display ); ?></h3>
                <span style="font-size:13px;opacity:0.8;">
                    <?php echo esc_html( count( $cat_products ) ); ?> product<?php echo count( $cat_products ) !== 1 ? 's' : ''; ?>
                    <?php if ( $category_min_qty > 0 ) : ?>
                        &middot; Minimum <?php echo esc_html( $category_min_qty ); ?> units (mix &amp; match)
                        <span class="slw-cat-progress" data-category="<?php echo esc_attr( $cat_slug ); ?>" style="margin-left:8px;font-weight:600;">0 / <?php echo esc_html( $category_min_qty ); ?></span>
                    <?php endif; ?>
                </span>
            </div>
            <div style="display:flex;align-items:center;gap:12px;">
                <?php
                // Pluralize singular category names ("Deodorant" -> "Deodorants").
                // Display rename: 'Bundles' surfaces as 'Variety Sets' on the
                // wholesale order form (Holly call ask) without touching the
                // actual WP category slug.
                $cat_label_for_button = $category_name;
                if ( strcasecmp( $cat_label_for_button, 'Bundles' ) === 0 || strcasecmp( $cat_label_for_button, 'Bundle' ) === 0 ) {
                    $cat_label_for_button = 'Variety Sets';
                }
                $cat_label_plural = preg_match( '/(s|x|z|ch|sh)$/i', $cat_label_for_button )
                    ? $cat_label_for_button
                    : $cat_label_for_button . 's';
                ?>
                <button type="button" class="slw-btn slw-btn-small slw-add-category" data-category="<?php echo esc_attr( $cat_slug ); ?>" style="background:#D4AF37;color:#1E2A30;border:none;font-weight:700;" onclick="event.stopPropagation();">One-Click Add <?php echo esc_html( $cat_label_plural ); ?></button>
                <span class="slw-category-toggle" style="font-size:18px;">&#9660;</span>
            </div>
        </div>

        <div class="slw-category-body" id="slw-cat-<?php echo esc_attr( $cat_slug ); ?>">
        <table class="slw-product-table" style="border-radius:0 0 8px 8px;margin-top:0;">
            <thead>
                <tr>
                    <th class="slw-col-image"></th>
                    <th class="slw-col-product">Product</th>
                    <th class="slw-col-price">Wholesale Price</th>
                    <th class="slw-col-qty">Qty</th>
                    <th class="slw-col-action"></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $cat_products as $product ) :

                    // Variable + variable-subscription: render each variation as its own row
                    if ( $product->is_type( 'variable' ) || $product->is_type( 'variable-subscription' ) ) :
                        $variations = $product->get_available_variations();
                        $parent_image = wp_get_attachment_image_url( $product->get_image_id(), 'thumbnail' );
                        if ( ! $parent_image ) {
                            $parent_image = wc_placeholder_img_src( 'thumbnail' );
                        }

                        // Parent product minimums apply to each variation
                        $parent_case_pack = class_exists( 'SLW_Product_Minimums' ) ? SLW_Product_Minimums::get_case_pack_size( $product->get_id() ) : 0;
                        $parent_min_qty   = class_exists( 'SLW_Product_Minimums' ) ? SLW_Product_Minimums::get_product_minimum( $product->get_id() ) : 0;

                        $seen_labels = array();

                        foreach ( $variations as $var_data ) :
                            $variation = wc_get_product( $var_data['variation_id'] );
                            if ( ! $variation || ! $variation->is_in_stock() ) continue;

                            // WCS stores subscription meta on variations. If this variation
                            // has a billing period set, it's a recurring subscription option.
                            // We only want the non-subscription (one-time) variation, OR if
                            // ALL variations are subscriptions, deduplicate by product attributes.
                            $sub_period = get_post_meta( $var_data['variation_id'], '_subscription_period', true );
                            $sub_interval = get_post_meta( $var_data['variation_id'], '_subscription_period_interval', true );
                            $is_recurring = ( ! empty( $sub_period ) && ! empty( $sub_interval ) );

                            // Build label from real product attributes only
                            $var_attrs = $var_data['attributes'] ?? array();
                            $var_label_parts = array();
                            foreach ( $var_attrs as $attr_key => $attr_val ) {
                                if ( ! $attr_val ) continue;
                                $taxonomy = str_replace( 'attribute_', '', $attr_key );
                                $term = get_term_by( 'slug', $attr_val, $taxonomy );
                                $term_name = $term ? $term->name : ucfirst( $attr_val );

                                // Skip any value that looks like a billing interval
                                $name_lower = strtolower( $term_name );
                                if ( preg_match( '/\d+\s*\/\s*mo|\d+\s*month|monthly|yearly|weekly|every\s*\d|one.?time|subscribe|subscription/i', $name_lower ) ) {
                                    continue;
                                }

                                $var_label_parts[] = $term_name;
                            }
                            $var_label = ! empty( $var_label_parts ) ? implode( ' / ', $var_label_parts ) : $variation->get_name();
                            $label_key = strtolower( trim( $var_label ) );

                            // Skip recurring variations when we already have this product option
                            if ( $is_recurring && isset( $seen_labels[ $label_key ] ) ) continue;

                            // If this is recurring but first time seeing this label, only allow
                            // it if there's no non-recurring version (otherwise prefer non-recurring)
                            if ( $is_recurring && ! isset( $seen_labels[ $label_key ] ) ) {
                                // Check if a non-recurring variation with same label exists
                                $has_nonrecurring = false;
                                foreach ( $variations as $check ) {
                                    $check_period = get_post_meta( $check['variation_id'], '_subscription_period', true );
                                    $check_interval = get_post_meta( $check['variation_id'], '_subscription_period_interval', true );
                                    if ( ! empty( $check_period ) && ! empty( $check_interval ) ) continue;
                                    // This is a non-recurring variation, check if same label
                                    $check_parts = array();
                                    foreach ( ($check['attributes'] ?? array()) as $ck => $cv ) {
                                        if ( ! $cv ) continue;
                                        $ct = str_replace( 'attribute_', '', $ck );
                                        $cterm = get_term_by( 'slug', $cv, $ct );
                                        $cn = $cterm ? $cterm->name : ucfirst( $cv );
                                        if ( preg_match( '/\d+\s*\/\s*mo|\d+\s*month|monthly|yearly|weekly|every\s*\d|one.?time|subscribe|subscription/i', strtolower( $cn ) ) ) continue;
                                        $check_parts[] = $cn;
                                    }
                                    $check_label = strtolower( trim( implode( ' / ', $check_parts ) ) );
                                    if ( $check_label === $label_key ) {
                                        $has_nonrecurring = true;
                                        break;
                                    }
                                }
                                if ( $has_nonrecurring ) continue; // skip this recurring one, non-recurring will render
                            }

                            if ( isset( $seen_labels[ $label_key ] ) ) continue;
                            $seen_labels[ $label_key ] = true;

                            $var_image = $var_data['image']['thumb_src'] ?? '';
                            if ( ! $var_image ) {
                                $var_image = $parent_image;
                            }

                            // Variation-level or parent-level minimums
                            $v_case_pack = class_exists( 'SLW_Product_Minimums' ) ? SLW_Product_Minimums::get_case_pack_size( $variation->get_id() ) : 0;
                            $v_min_qty   = class_exists( 'SLW_Product_Minimums' ) ? SLW_Product_Minimums::get_product_minimum( $variation->get_id() ) : 0;
                            if ( $v_case_pack <= 0 ) $v_case_pack = $parent_case_pack;
                            if ( $v_min_qty <= 0 ) $v_min_qty = $parent_min_qty;

                            $v_step = $v_case_pack > 0 ? $v_case_pack : 1;
                            $v_default = $v_case_pack > 0 ? $v_case_pack : 0;
                            $v_min_input = $v_case_pack > 0 ? $v_case_pack : 0;
                            if ( $v_min_qty > $v_min_input ) {
                                $v_min_input = $v_min_qty;
                            }

                            // When a category-wide min is set, the customer can mix scents
                            // freely, so relax the per-row min so they aren't forced to enter
                            // the full minimum on a single SKU.
                            if ( $category_min_qty > 0 ) {
                                $v_min_input = 0;
                                $v_default   = 0;
                                if ( $v_case_pack <= 0 ) {
                                    $v_step = 1;
                                }
                            }

                            $v_price_html = wc_price( $variation->get_price() );
                ?>
                <tr data-product-id="<?php echo esc_attr( $product->get_id() ); ?>" data-variation-id="<?php echo esc_attr( $variation->get_id() ); ?>" data-category="<?php echo esc_attr( $cat_slug ); ?>" id="slw-product-<?php echo esc_attr( $variation->get_id() ); ?>">
                    <?php
                    // Larger lightbox image (full-size if available, else thumbnail).
                    $var_image_full = $variation->get_image_id() ? wp_get_attachment_image_url( $variation->get_image_id(), 'large' ) : '';
                    if ( ! $var_image_full ) {
                        $var_image_full = wp_get_attachment_image_url( $product->get_image_id(), 'large' );
                    }
                    if ( ! $var_image_full ) {
                        $var_image_full = $var_image;
                    }
                    ?>
                    <td class="slw-col-image">
                        <button type="button"
                                class="slw-row-image-btn"
                                aria-label="Enlarge product image"
                                data-image-full="<?php echo esc_url( $var_image_full ); ?>"
                                data-image-alt="<?php echo esc_attr( $product->get_name() . ' ' . $var_label ); ?>">
                            <img src="<?php echo esc_url( $var_image ); ?>" alt="<?php echo esc_attr( $var_label ); ?>" width="60" height="60" />
                        </button>
                    </td>
                    <?php
                    // Product hover: short description always (full description
                    // is too long for the tooltip). Falls back to long description
                    // only if no short is set.
                    $product_desc = trim( wp_strip_all_tags( $product->get_short_description() ) );
                    if ( ! $product_desc ) {
                        $product_desc = trim( wp_strip_all_tags( $product->get_description() ) );
                    }
                    if ( strlen( $product_desc ) > 320 ) {
                        $product_desc = substr( $product_desc, 0, 317 ) . '...';
                    }

                    // Scent hover: prefer the variation's own description,
                    // then any attribute-term description from the variation
                    // attributes (Sego Lily's scent attribute is a taxonomy
                    // and the term description is what the Shop All page
                    // surfaces). Skip terms that look like billing intervals.
                    $scent_desc = trim( wp_strip_all_tags( $variation->get_description() ) );
                    if ( ! $scent_desc ) {
                        foreach ( (array) $variation->get_attributes() as $attr_tax => $attr_val ) {
                            if ( ! $attr_val ) continue;
                            $lower_val = strtolower( (string) $attr_val );
                            if ( preg_match( '/month|year|week|every|one.?time|subscribe|subscription/i', $lower_val ) ) {
                                continue;
                            }
                            $term = get_term_by( 'slug', $attr_val, $attr_tax );
                            if ( $term && ! is_wp_error( $term ) && trim( wp_strip_all_tags( $term->description ) ) ) {
                                $scent_desc = trim( wp_strip_all_tags( $term->description ) );
                                break;
                            }
                        }
                    }
                    if ( strlen( $scent_desc ) > 320 ) {
                        $scent_desc = substr( $scent_desc, 0, 317 ) . '...';
                    }
                    ?>
                    <td class="slw-col-product">
                        <strong class="slw-product-name<?php echo $product_desc ? ' slw-has-hover' : ''; ?>"
                                <?php if ( $product_desc ) : ?>
                                data-hover-title="<?php echo esc_attr( $product->get_name() ); ?>"
                                data-hover-desc="<?php echo esc_attr( $product_desc ); ?>"
                                <?php endif; ?>><?php echo esc_html( $product->get_name() ); ?></strong>
                        <br><span class="slw-product-meta<?php echo $scent_desc ? ' slw-has-hover' : ''; ?>"
                                  <?php if ( $scent_desc ) : ?>
                                  data-hover-title="<?php echo esc_attr( $var_label ); ?>"
                                  data-hover-desc="<?php echo esc_attr( $scent_desc ); ?>"
                                  <?php endif; ?>><?php echo esc_html( $var_label ); ?></span>
                        <?php if ( $v_case_pack > 0 && class_exists( 'SLW_Product_Minimums' ) && SLW_Product_Minimums::case_packs_enabled() ) : ?>
                            <br><span class="slw-case-pack-label">Case of <?php echo esc_html( $v_case_pack ); ?></span>
                        <?php endif; ?>
                        <?php if ( $variation->get_sku() ) : ?>
                            <br><span class="slw-product-sku">SKU: <?php echo esc_html( $variation->get_sku() ); ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="slw-col-price"><?php echo $v_price_html; ?></td>
                    <td class="slw-col-qty">
                        <input type="number" class="slw-qty-input" min="<?php echo esc_attr( $v_min_input ); ?>" max="999" step="<?php echo esc_attr( $v_step ); ?>" value="<?php echo esc_attr( $v_default ); ?>"
                               data-product-id="<?php echo esc_attr( $product->get_id() ); ?>"
                               data-variation-id="<?php echo esc_attr( $variation->get_id() ); ?>"
                               data-variation="<?php echo esc_attr( wp_json_encode( $var_attrs ) ); ?>"
                               data-price="<?php echo esc_attr( $variation->get_price() ); ?>" />
                        <?php if ( $v_min_input > 1 ) : ?>
                            <span class="slw-min-badge" aria-label="Minimum quantity">Min <?php echo esc_html( $v_min_input ); ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="slw-col-action">
                        <button type="button" class="slw-btn slw-btn-small slw-btn-primary slw-add-single"
                                data-product-id="<?php echo esc_attr( $product->get_id() ); ?>"
                                data-variation-id="<?php echo esc_attr( $variation->get_id() ); ?>">Add</button>
                    </td>
                </tr>
                <?php
                        endforeach;

                    // Simple products: same as before
                    else :
                        $image = wp_get_attachment_image_url( $product->get_image_id(), 'thumbnail' );
                        if ( ! $image ) {
                            $image = wc_placeholder_img_src( 'thumbnail' );
                        }
                        $price_html = wc_price( $product->get_price() );

                        $case_pack = class_exists( 'SLW_Product_Minimums' ) ? SLW_Product_Minimums::get_case_pack_size( $product->get_id() ) : 0;
                        $min_qty   = class_exists( 'SLW_Product_Minimums' ) ? SLW_Product_Minimums::get_product_minimum( $product->get_id() ) : 0;
                        $step      = $case_pack > 0 ? $case_pack : 1;
                        $default_qty = $case_pack > 0 ? $case_pack : 0;
                        $min_input = $case_pack > 0 ? $case_pack : 0;
                        if ( $min_qty > $min_input ) {
                            $min_input = $min_qty;
                        }

                        // Category min set: let customer split quantities across products.
                        if ( $category_min_qty > 0 ) {
                            $min_input   = 0;
                            $default_qty = 0;
                            if ( $case_pack <= 0 ) {
                                $step = 1;
                            }
                        }
                ?>
                <tr data-product-id="<?php echo esc_attr( $product->get_id() ); ?>" data-category="<?php echo esc_attr( $cat_slug ); ?>" id="slw-product-<?php echo esc_attr( $product->get_id() ); ?>">
                    <?php $image_full = wp_get_attachment_image_url( $product->get_image_id(), 'large' ); if ( ! $image_full ) $image_full = $image; ?>
                    <td class="slw-col-image">
                        <button type="button"
                                class="slw-row-image-btn"
                                aria-label="Enlarge product image"
                                data-image-full="<?php echo esc_url( $image_full ); ?>"
                                data-image-alt="<?php echo esc_attr( $product->get_name() ); ?>">
                            <img src="<?php echo esc_url( $image ); ?>" alt="<?php echo esc_attr( $product->get_name() ); ?>" width="60" height="60" />
                        </button>
                    </td>
                    <?php
                    $p_desc = trim( wp_strip_all_tags( $product->get_short_description() ) );
                    if ( ! $p_desc ) {
                        $p_desc = trim( wp_strip_all_tags( $product->get_description() ) );
                    }
                    if ( strlen( $p_desc ) > 320 ) {
                        $p_desc = substr( $p_desc, 0, 317 ) . '...';
                    }
                    ?>
                    <td class="slw-col-product">
                        <strong class="slw-product-name<?php echo $p_desc ? ' slw-has-hover' : ''; ?>"
                                <?php if ( $p_desc ) : ?>
                                data-hover-title="<?php echo esc_attr( $product->get_name() ); ?>"
                                data-hover-desc="<?php echo esc_attr( $p_desc ); ?>"
                                <?php endif; ?>><?php echo esc_html( $product->get_name() ); ?></strong>
                        <?php if ( $case_pack > 0 && class_exists( 'SLW_Product_Minimums' ) && SLW_Product_Minimums::case_packs_enabled() ) : ?>
                            <br><span class="slw-case-pack-label">Case of <?php echo esc_html( $case_pack ); ?></span>
                        <?php endif; ?>
                        <?php if ( $product->get_sku() ) : ?>
                            <br><span class="slw-product-sku">SKU: <?php echo esc_html( $product->get_sku() ); ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="slw-col-price"><?php echo $price_html; ?></td>
                    <td class="slw-col-qty">
                        <?php if ( $product->is_in_stock() ) : ?>
                            <input type="number" class="slw-qty-input" min="<?php echo esc_attr( $min_input ); ?>" max="999" step="<?php echo esc_attr( $step ); ?>" value="<?php echo esc_attr( $default_qty ); ?>" data-product-id="<?php echo esc_attr( $product->get_id() ); ?>" data-price="<?php echo esc_attr( $product->get_price() ); ?>" />
                            <?php if ( $min_input > 1 ) : ?>
                                <span class="slw-min-badge" aria-label="Minimum quantity">Min <?php echo esc_html( $min_input ); ?></span>
                            <?php endif; ?>
                        <?php else : ?>
                            <span class="slw-out-of-stock">Out of stock</span>
                        <?php endif; ?>
                    </td>
                    <td class="slw-col-action">
                        <?php if ( $product->is_in_stock() ) : ?>
                            <button type="button" class="slw-btn slw-btn-small slw-btn-primary slw-add-single" data-product-id="<?php echo esc_attr( $product->get_id() ); ?>">Add</button>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php
                    endif;

                    // Product Recommendations row
                    if ( class_exists( 'SLW_Product_Recommendations' ) ) :
                        $rec_ids = SLW_Product_Recommendations::get_recommendations( $product->get_id() );
                        if ( ! empty( $rec_ids ) ) :
                            $rec_names = array();
                            foreach ( $rec_ids as $rec_id ) {
                                $rec_product = wc_get_product( $rec_id );
                                if ( $rec_product ) {
                                    $rec_names[] = '<a href="#slw-product-' . esc_attr( $rec_id ) . '" class="slw-rec-link" data-target="slw-product-' . esc_attr( $rec_id ) . '">' . esc_html( $rec_product->get_name() ) . '</a>';
                                }
                            }
                            if ( ! empty( $rec_names ) ) :
                ?>
                <tr class="slw-recommendation-row">
                    <td colspan="5">
                        <span class="slw-rec-dot"></span>
                        <span class="slw-rec-label">Pairs well with:</span>
                        <?php echo implode( ', ', $rec_names ); ?>
                    </td>
                </tr>
                <?php
                            endif;
                        endif;
                    endif;
                ?>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
    <?php endforeach; ?>

    <!-- Shipping Estimate -->
    <div class="slw-shipping-calculator slw-of-card" id="slw-shipping-calculator">
        <div class="slw-of-card__header">
            <h3 class="slw-of-card__title">Shipping Estimate</h3>
            <p class="slw-of-card__subtitle">Get an estimated shipping cost before checkout.</p>
        </div>
        <div class="slw-shipping-calculator-form">
            <div class="slw-shipping-calculator-inputs">
                <input type="text" id="slw-ship-zip" class="slw-ship-input" placeholder="Zip code" maxlength="10"
                       value="<?php echo esc_attr( get_user_meta( $user->ID, 'shipping_postcode', true ) ); ?>" />
                <input type="hidden" id="slw-ship-country" value="<?php echo esc_attr( get_user_meta( $user->ID, 'shipping_country', true ) ?: 'US' ); ?>" />
                <input type="hidden" id="slw-ship-state" value="<?php echo esc_attr( get_user_meta( $user->ID, 'shipping_state', true ) ); ?>" />
                <button type="button" class="slw-btn slw-btn-small slw-btn-primary" id="slw-calc-shipping-btn">Calculate Shipping</button>
            </div>
            <div id="slw-shipping-results" class="slw-shipping-results" style="display:none;"></div>
        </div>
    </div>

    <!-- Cart Preview. Itemized list of everything currently staged on the
         page (qty > 0) so the customer can see exactly what's about to
         hit checkout without scrolling back through the product tables. -->
    <div class="slw-of-card slw-cart-preview" aria-live="polite">
        <div class="slw-of-card__header">
            <h3 class="slw-of-card__title">Cart Preview</h3>
            <p class="slw-of-card__subtitle" id="slw-cart-preview-meta">Set quantities above to populate.</p>
        </div>
        <ul class="slw-cart-preview__list" id="slw-cart-preview-list"></ul>
    </div>

    <!-- Order Subtotal -->
    <div class="slw-of-card slw-order-summary-card" aria-live="polite">
        <div class="slw-of-card__header">
            <h3 class="slw-of-card__title">Order Subtotal</h3>
            <p class="slw-of-card__subtitle" id="slw-of-subtotal-meta">0 items</p>
        </div>
        <div class="slw-os-line slw-os-line--main">
            <span class="slw-os-label">Subtotal</span>
            <span class="slw-os-value" id="slw-of-subtotal"><?php echo wp_kses_post( wc_price( 0 ) ); ?></span>
        </div>
        <div class="slw-os-line slw-os-line--meta">
            <span class="slw-os-shipping" id="slw-of-shipping-line"></span>
        </div>
    </div>

    <div class="slw-order-form-footer">
        <div class="slw-of-actions">
            <button type="button" class="slw-btn slw-btn-primary slw-of-action-btn" id="slw-save-template-btn">Save Order Preset Preset</button>
            <button type="button" class="slw-btn slw-btn-cta slw-of-action-btn slw-of-action-btn--cta" id="slw-checkout-btn">Proceed to Checkout</button>
        </div>
    </div>

    <?php endif; ?>
</div>

<style>
/* Section header used above "Your Most Ordered" / "Quick reorder" so the
   title + hint render as a single tidy pair. */
.slw-section-header { display: flex; align-items: baseline; justify-content: space-between; gap: 12px; margin: 16px 0 10px; padding-bottom: 8px; border-bottom: 1px solid #e0ddd8; }
.slw-section-header__title { margin: 0; font-family: Georgia, 'Times New Roman', serif; font-size: 18px; font-weight: 700; color: #386174; line-height: 1.2; }
.slw-section-header__hint { font-size: 12px; color: #628393; font-style: italic; letter-spacing: 0.2px; }

/* Uniform card pattern used for Shipping Estimate, Cart Preview, and Order Subtotal. */
.slw-of-card {
    margin-top: 18px;
    padding: 18px 22px;
    background: linear-gradient(180deg, #FAF8F2 0%, #F7F6F3 100%);
    border: 1px solid #E0DBD0;
    border-radius: 10px;
    box-shadow: 0 1px 3px rgba(56, 97, 116, 0.05);
}
.slw-of-card__header { display: flex; flex-direction: column; gap: 4px; margin-bottom: 12px; padding-bottom: 10px; border-bottom: 1px solid #E0DBD0; }
.slw-of-card__title { margin: 0; font-family: Georgia, 'Times New Roman', serif; font-size: 16px; font-weight: 700; color: #386174; letter-spacing: 0.2px; }
.slw-of-card__subtitle { margin: 0; font-size: 12px; color: #628393; font-style: italic; }

/* Cart Preview list */
.slw-cart-preview__list { list-style: none; margin: 0; padding: 0; }
.slw-cart-preview__item { display: grid; grid-template-columns: 36px 1fr auto; gap: 10px; align-items: center; padding: 8px 0; border-bottom: 1px dashed rgba(224, 219, 208, 0.65); font-size: 13px; }
.slw-cart-preview__item:last-child { border-bottom: none; }
.slw-cart-preview__qty { font-weight: 700; color: #386174; font-family: Georgia, 'Times New Roman', serif; }
.slw-cart-preview__name { color: #2C2C2C; }
.slw-cart-preview__total { font-weight: 600; color: #2C2C2C; }

/* Order Subtotal lines */
.slw-os-line { display: flex; justify-content: space-between; align-items: baseline; gap: 16px; }
.slw-os-line--main { padding-bottom: 8px; border-bottom: 1px dashed #E0DBD0; margin-bottom: 8px; }
.slw-os-label { font-family: Georgia, 'Times New Roman', serif; font-size: 14px; color: #386174; font-weight: 600; letter-spacing: 0.2px; }
.slw-os-value { font-family: Georgia, 'Times New Roman', serif; font-size: 26px; font-weight: 700; color: #2C2C2C; line-height: 1; }
.slw-os-shipping { font-size: 12px; color: #628393; text-align: right; }
.slw-os-shipping strong { color: #2C2C2C; font-style: normal; font-weight: 700; }

/* Action row: Save Order Preset + Proceed to Checkout
   sized as siblings (same padding, same font size). */
.slw-order-form-footer { display: flex; justify-content: flex-end; align-items: center; gap: 12px; flex-wrap: wrap; margin-top: 16px; padding-bottom: 8px; }
.slw-of-actions { display: flex; gap: 12px; align-items: stretch; flex-wrap: wrap; }
.slw-of-action-btn { padding: 13px 24px !important; font-size: 14px !important; min-height: 46px; }
.slw-of-action-btn--cta { font-weight: 700 !important; padding-left: 28px !important; padding-right: 28px !important; }

@media (max-width: 600px) {
    .slw-order-form-footer { justify-content: stretch; }
    .slw-of-actions { width: 100%; }
    .slw-of-action-btn { flex: 1; }
    .slw-os-line { flex-wrap: wrap; }
    .slw-os-shipping { text-align: left; flex-basis: 100%; }
    .slw-cart-preview__item { grid-template-columns: 32px 1fr; }
    .slw-cart-preview__total { grid-column: 2; text-align: right; }
}
.slw-of-toast {
    position: fixed;
    bottom: 80px;
    right: 18px;
    z-index: 99998;
    display: inline-flex;
    align-items: center;
    gap: 14px;
    padding: 12px 18px;
    border-radius: 10px;
    background: #1E2A30;
    color: #F7F6F3;
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Helvetica, Arial, sans-serif;
    font-size: 14px;
    line-height: 1.4;
    box-shadow: 0 6px 24px rgba(0,0,0,0.22);
    max-width: 360px;
    opacity: 0;
    transform: translateY(8px);
    transition: opacity 0.22s ease, transform 0.22s ease;
}
.slw-of-toast--in { opacity: 1; transform: translateY(0); }
.slw-of-toast--success { background: #1F5D3A; }
.slw-of-toast--info { background: #386174; }
.slw-of-toast__msg { flex: 1 1 auto; }
.slw-of-toast__cta {
    flex: 0 0 auto;
    color: #F7F6F3 !important;
    background: rgba(247,246,243,0.16);
    padding: 6px 12px;
    border-radius: 6px;
    text-decoration: none !important;
    font-weight: 600;
    font-size: 13px;
    white-space: nowrap;
    transition: background 0.15s;
}
.slw-of-toast__cta:hover { background: rgba(247,246,243,0.28); color: #F7F6F3 !important; }
@media (max-width: 480px) {
    .slw-of-toast {
        left: 12px;
        right: 12px;
        bottom: 72px;
        max-width: none;
        font-size: 13px;
        padding: 10px 14px;
    }
}
@media (prefers-reduced-motion: reduce) {
    .slw-of-toast { transition: none; transform: none; }
}
</style>

<script>
(function() {
    var ajaxUrl = '<?php echo esc_js( $ajax_url ); ?>';
    var nonce = '<?php echo esc_js( $nonce ); ?>';
    var msgEl = document.getElementById('slw-order-message');

    var cartUrl     = <?php echo wp_json_encode( wc_get_cart_url() ); ?>;
    var checkoutUrl = <?php echo wp_json_encode( wc_get_checkout_url() ); ?>;

    // Live order subtotal in the footer. Reads data-price + quantity off
    // every .slw-qty-input row and renders running totals so the wholesale
    // customer doesn't have to bounce to the cart page to know what they
    // are about to spend.
    var subtotalEl = document.getElementById('slw-of-subtotal');
    var subtotalMetaEl = document.getElementById('slw-of-subtotal-meta');
    var currencySymbol = <?php echo wp_json_encode( html_entity_decode( get_woocommerce_currency_symbol() ) ); ?>;
    var decimalSep = <?php echo wp_json_encode( wc_get_price_decimal_separator() ); ?>;
    var thousandsSep = <?php echo wp_json_encode( wc_get_price_thousand_separator() ); ?>;
    var decimals = <?php echo (int) wc_get_price_decimals(); ?>;

    function formatPrice(amount) {
        var sign = amount < 0 ? '-' : '';
        var abs = Math.abs(amount).toFixed(decimals);
        var parts = abs.split('.');
        parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, thousandsSep);
        var joined = parts.join(decimalSep);
        return sign + currencySymbol + joined;
    }

    var previewListEl = document.getElementById('slw-cart-preview-list');
    var previewMetaEl = document.getElementById('slw-cart-preview-meta');

    function rowLabelFor(input) {
        var row = input.closest('tr');
        if (!row) return '';
        var prodCell = row.querySelector('.slw-col-product');
        if (!prodCell) return '';
        var name = prodCell.querySelector('strong');
        var meta = prodCell.querySelector('.slw-product-meta');
        var nameTxt = name ? name.textContent.trim() : '';
        var metaTxt = meta ? meta.textContent.trim() : '';
        return metaTxt ? nameTxt + ', ' + metaTxt : nameTxt;
    }

    function updateSubtotal() {
        if (!subtotalEl) return;
        var total = 0;
        var lineCount = 0;
        var itemCount = 0;
        var previewItems = [];
        document.querySelectorAll('.slw-qty-input').forEach(function(input) {
            var qty = parseInt(input.value, 10) || 0;
            if (qty <= 0) return;
            var price = parseFloat(input.getAttribute('data-price')) || 0;
            total += qty * price;
            lineCount++;
            itemCount += qty;
            previewItems.push({ label: rowLabelFor(input), qty: qty, lineTotal: qty * price });
        });
        subtotalEl.textContent = formatPrice(total);

        // Cart preview: itemized list of everything staged with qty > 0.
        if (previewListEl) {
            previewListEl.innerHTML = '';
            if (previewItems.length === 0) {
                if (previewMetaEl) previewMetaEl.textContent = 'Set quantities above to populate.';
            } else {
                if (previewMetaEl) {
                    previewMetaEl.textContent = itemCount + ' item' + (itemCount === 1 ? '' : 's') +
                        ' across ' + lineCount + ' product' + (lineCount === 1 ? '' : 's');
                }
                previewItems.forEach(function(item) {
                    var li = document.createElement('li');
                    li.className = 'slw-cart-preview__item';
                    li.innerHTML =
                        '<span class="slw-cart-preview__qty">' + item.qty + '×</span>' +
                        '<span class="slw-cart-preview__name">' + item.label + '</span>' +
                        '<span class="slw-cart-preview__total">' + formatPrice(item.lineTotal) + '</span>';
                    previewListEl.appendChild(li);
                });
            }
        }

        if (subtotalMetaEl) {
            if (itemCount === 0) {
                subtotalMetaEl.textContent = '0 items';
            } else if (lineCount === 1) {
                subtotalMetaEl.textContent = itemCount + ' item' + (itemCount === 1 ? '' : 's');
            } else {
                subtotalMetaEl.textContent = itemCount + ' items across ' + lineCount + ' products';
            }
        }
    }

    document.querySelectorAll('.slw-qty-input').forEach(function(input) {
        input.addEventListener('input', updateSubtotal);
        input.addEventListener('change', updateSubtotal);
    });
    updateSubtotal();

    // Errors get the persistent inline notice + scroll (user must see).
    // Success/info show as a corner toast with a View Cart link, so the
    // user isn't yanked away from where they were scrolling.
    function showMessage(text, type) {
        if (type === 'error') {
            msgEl.textContent = text;
            msgEl.className = 'slw-notice slw-notice-' + type;
            msgEl.style.display = 'block';
            msgEl.scrollIntoView({ behavior: 'smooth' });
            setTimeout(function() { msgEl.focus(); }, 300);
            return;
        }
        showToast(text, type);
    }

    function showToast(text, type) {
        var prior = document.getElementById('slw-of-toast');
        if (prior) prior.remove();
        var toast = document.createElement('div');
        toast.id = 'slw-of-toast';
        toast.className = 'slw-of-toast slw-of-toast--' + (type || 'info');
        toast.setAttribute('role', 'status');
        toast.setAttribute('aria-live', 'polite');
        var msg = document.createElement('span');
        msg.className = 'slw-of-toast__msg';
        msg.textContent = text;
        toast.appendChild(msg);
        if (type === 'success' && checkoutUrl) {
            var link = document.createElement('a');
            link.href = checkoutUrl;
            link.className = 'slw-of-toast__cta';
            link.textContent = 'Checkout →';
            toast.appendChild(link);
        }
        document.body.appendChild(toast);
        requestAnimationFrame(function() { toast.classList.add('slw-of-toast--in'); });
        setTimeout(function() {
            toast.classList.remove('slw-of-toast--in');
            setTimeout(function() { if (toast.parentNode) toast.remove(); }, 250);
        }, 6000);
    }

    function collectItems(scope) {
        var items = [];
        var inputs = scope
            ? scope.querySelectorAll('.slw-qty-input')
            : document.querySelectorAll('.slw-qty-input');
        inputs.forEach(function(input) {
            var qty = parseInt(input.value) || 0;
            if (qty > 0) {
                var item = {
                    product_id: input.getAttribute('data-product-id'),
                    quantity: qty
                };
                var varId = input.getAttribute('data-variation-id');
                if (varId) {
                    item.variation_id = varId;
                    try {
                        item.variation = JSON.parse(input.getAttribute('data-variation') || '{}');
                    } catch(e) {
                        item.variation = {};
                    }
                }
                items.push(item);
            }
        });
        return items;
    }

    function addToCart(items, btn, origText) {
        if (btn) {
            btn.disabled = true;
            btn.textContent = 'Adding...';
        }

        var formData = new FormData();
        formData.append('action', 'slw_add_to_cart');
        formData.append('nonce', nonce);
        formData.append('items', JSON.stringify(items));

        var xhr = new XMLHttpRequest();
        xhr.open('POST', ajaxUrl);
        xhr.onload = function() {
            var resp;
            try { resp = JSON.parse(xhr.responseText); } catch(e) { resp = null; }

            if (resp && resp.success) {
                showMessage(resp.data.message, 'success');
                // Only zero the inputs that were just added to cart, leave
                // every other staged row alone. Previously this would wipe
                // EVERY qty input after any per-row Add, which destroyed
                // partially-built orders.
                items.forEach(function(it) {
                    var selector = it.variation_id
                        ? '.slw-qty-input[data-variation-id="' + it.variation_id + '"]'
                        : '.slw-qty-input[data-product-id="' + it.product_id + '"]:not([data-variation-id])';
                    var input = document.querySelector(selector);
                    if (input) input.value = '0';
                });
                updateSubtotal();
            } else {
                var msg = (resp && resp.data && resp.data.message) ? resp.data.message : 'Could not add items to cart.';
                showMessage(msg, 'error');
            }
            if (btn) {
                btn.disabled = false;
                btn.textContent = origText || 'Add';
            }
        };
        xhr.onerror = function() {
            showMessage('Network error. Please try again.', 'error');
            if (btn) {
                btn.disabled = false;
                btn.textContent = origText || 'Add';
            }
        };
        xhr.send(formData);
    }

    // Single-row "Add" button
    document.querySelectorAll('.slw-add-single').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var productId = this.getAttribute('data-product-id');
            var varId = this.getAttribute('data-variation-id');
            var selector = varId
                ? '.slw-qty-input[data-variation-id="' + varId + '"]'
                : '.slw-qty-input[data-product-id="' + productId + '"]:not([data-variation-id])';
            var input = document.querySelector(selector);
            if (!input) return;
            var qty = parseInt(input.value) || 0;
            var minVal = parseInt(input.getAttribute('min')) || 1;
            if (qty < minVal) {
                input.value = minVal;
                qty = minVal;
            }
            var item = { product_id: productId, quantity: qty };
            if (varId) {
                item.variation_id = varId;
                try {
                    item.variation = JSON.parse(input.getAttribute('data-variation') || '{}');
                } catch(e) {
                    item.variation = {};
                }
            }
            addToCart([item], this, 'Add');
        });
    });

    // Category "Add to Cart" buttons
    document.querySelectorAll('.slw-add-category').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var cat = this.getAttribute('data-category');
            var section = document.querySelector('.slw-category-section[data-category="' + cat + '"]');
            if (!section) return;
            var items = collectItems(section);
            if (items.length === 0) {
                showMessage('Set quantities for the products you want in this category, then click the button.', 'info');
                return;
            }
            addToCart(items, this, this.textContent);
        });
    });

    // Category header collapse/expand
    document.querySelectorAll('.slw-category-header').forEach(function(header) {
        header.addEventListener('click', function() {
            var cat = this.getAttribute('data-category');
            var body = document.getElementById('slw-cat-' + cat);
            var arrow = this.querySelector('.slw-category-toggle');
            if (body) {
                if (body.style.display === 'none') {
                    body.style.display = '';
                    if (arrow) arrow.innerHTML = '&#9660;';
                } else {
                    body.style.display = 'none';
                    if (arrow) arrow.innerHTML = '&#9654;';
                }
            }
        });
    });

    // Most Ordered: quick reorder buttons (variations supported)
    document.querySelectorAll('.slw-mo-add-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var productId = this.getAttribute('data-product-id');
            var varId = this.getAttribute('data-variation-id');
            var selector = varId
                ? '.slw-mo-qty-input[data-variation-id="' + varId + '"]'
                : '.slw-mo-qty-input[data-product-id="' + productId + '"]:not([data-variation-id])';
            var input = document.querySelector(selector);
            if (!input) return;
            var qty = parseInt(input.value) || 1;
            if (qty < 1) { qty = 1; input.value = 1; }
            var item = { product_id: productId, quantity: qty };
            if (varId) {
                item.variation_id = varId;
            }
            addToCart([item], this, 'Reorder');
        });
    });

    // New Arrivals: individual "Add to Cart" buttons
    document.querySelectorAll('.slw-na-add-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var productId = this.getAttribute('data-product-id');
            var input = document.querySelector('.slw-na-qty-input[data-product-id="' + productId + '"]');
            var qty = parseInt(input.value) || 1;
            if (qty < 1) { qty = 1; input.value = 1; }

            var origText = btn.textContent;
            btn.disabled = true;
            btn.textContent = 'Adding...';

            var formData = new FormData();
            formData.append('action', 'slw_new_arrival_add_to_cart');
            formData.append('nonce', nonce);
            formData.append('product_id', productId);
            formData.append('quantity', qty);

            var xhr = new XMLHttpRequest();
            xhr.open('POST', ajaxUrl);
            xhr.onload = function() {
                var resp;
                try { resp = JSON.parse(xhr.responseText); } catch(e) { resp = null; }
                if (resp && resp.success) {
                    showMessage(resp.data.message, 'success');
                } else {
                    var msg = (resp && resp.data && resp.data.message) ? resp.data.message : 'Could not add to cart.';
                    showMessage(msg, 'error');
                }
                btn.disabled = false;
                btn.textContent = origText;
            };
            xhr.onerror = function() {
                showMessage('Network error. Please try again.', 'error');
                btn.disabled = false;
                btn.textContent = origText;
            };
            xhr.send(formData);
        });
    });

    // "Proceed to Checkout" button. Collects every row with qty > 0,
    // bulk-adds to the WC cart, then redirects straight to /checkout --
    // skips /cart entirely per the May 29 2026 call with Camila + LV.
    // Items already added via per-row buttons live in the WC cart; this
    // path picks up anything still in the on-page quantity inputs.
    var checkoutBtn = document.getElementById('slw-checkout-btn');
    if (checkoutBtn) {
        checkoutBtn.addEventListener('click', function() {
            var btn = this;
            var items = collectItems(null);

            // Nothing to add but cart has items already -> go straight to checkout.
            if (items.length === 0) {
                window.location.href = checkoutUrl;
                return;
            }

            btn.disabled = true;
            btn.textContent = 'Preparing checkout...';

            var formData = new FormData();
            formData.append('action', 'slw_add_to_cart');
            formData.append('nonce', nonce);
            formData.append('items', JSON.stringify(items));

            var xhr = new XMLHttpRequest();
            xhr.open('POST', ajaxUrl);
            xhr.onload = function() {
                var resp;
                try { resp = JSON.parse(xhr.responseText); } catch(e) { resp = null; }
                if (resp && resp.success) {
                    window.location.href = checkoutUrl;
                } else {
                    var msg = (resp && resp.data && resp.data.message) ? resp.data.message : 'Could not prepare checkout.';
                    showMessage(msg, 'error');
                    btn.disabled = false;
                    btn.textContent = 'Proceed to Checkout';
                }
            };
            xhr.onerror = function() {
                showMessage('Network error. Please try again.', 'error');
                btn.disabled = false;
                btn.textContent = 'Proceed to Checkout';
            };
            xhr.send(formData);
        });
    }

    // "Save Order Preset" button
    var saveTemplateBtn = document.getElementById('slw-save-template-btn');
    if (saveTemplateBtn) {
        saveTemplateBtn.addEventListener('click', function() {
            var templateName = prompt('Name this saved order (e.g. "Q3 reorder"):');
            if (!templateName || !templateName.trim()) return;

            saveTemplateBtn.disabled = true;
            saveTemplateBtn.textContent = 'Saving...';

            var formData = new FormData();
            formData.append('action', 'slw_save_cart');
            formData.append('nonce', nonce);
            formData.append('template_name', templateName.trim());

            var xhr = new XMLHttpRequest();
            xhr.open('POST', ajaxUrl);
            xhr.onload = function() {
                var resp;
                try { resp = JSON.parse(xhr.responseText); } catch(e) { resp = null; }
                if (resp && resp.success) {
                    showMessage(resp.data.message, 'success');
                } else {
                    var msg = (resp && resp.data && resp.data.message) ? resp.data.message : 'Could not save template.';
                    showMessage(msg, 'error');
                }
                saveTemplateBtn.disabled = false;
                saveTemplateBtn.textContent = 'Save Order Preset';
            };
            xhr.onerror = function() {
                showMessage('Network error. Please try again.', 'error');
                saveTemplateBtn.disabled = false;
                saveTemplateBtn.textContent = 'Save Order Preset';
            };
            xhr.send(formData);
        });
    }

    // Hover popups on product names + scent labels. Surfaces the short
    // description / variation description in a small card without an
    // explicit click. Replaces the (i) icon from v4.6.69. Click outside
    // the trigger dismisses.
    var hoverPopup = null;
    function ensureHoverPopup() {
        if (hoverPopup) return hoverPopup;
        hoverPopup = document.createElement('div');
        hoverPopup.className = 'slw-hover-popup';
        hoverPopup.setAttribute('role', 'dialog');
        hoverPopup.style.display = 'none';
        hoverPopup.innerHTML =
            '<div class="slw-hover-popup__title"></div>' +
            '<div class="slw-hover-popup__body"></div>';
        document.body.appendChild(hoverPopup);
        return hoverPopup;
    }
    function positionHoverPopup(popup, anchor) {
        var rect = anchor.getBoundingClientRect();
        var top  = rect.bottom + window.scrollY + 6;
        var left = rect.left + window.scrollX;
        var maxLeft = window.scrollX + window.innerWidth - 340;
        if (left > maxLeft) left = maxLeft;
        if (left < window.scrollX + 12) left = window.scrollX + 12;
        popup.style.top  = top + 'px';
        popup.style.left = left + 'px';
    }
    function showHoverPopup(anchor) {
        var title = anchor.getAttribute('data-hover-title') || '';
        var body  = anchor.getAttribute('data-hover-desc')  || '';
        if (!body) return;
        var popup = ensureHoverPopup();
        popup.querySelector('.slw-hover-popup__title').textContent = title;
        popup.querySelector('.slw-hover-popup__body').textContent  = body;
        popup.style.display = 'block';
        requestAnimationFrame(function() { positionHoverPopup(popup, anchor); });
    }
    function hideHoverPopup() {
        if (hoverPopup) hoverPopup.style.display = 'none';
    }
    document.querySelectorAll('.slw-has-hover').forEach(function(el) {
        el.addEventListener('mouseenter', function() { showHoverPopup(this); });
        el.addEventListener('mouseleave', hideHoverPopup);
        el.addEventListener('focus',      function() { showHoverPopup(this); });
        el.addEventListener('blur',       hideHoverPopup);
    });

    // Image lightbox. Click a product row's thumbnail to blow it up.
    var lightbox = null;
    function ensureLightbox() {
        if (lightbox) return lightbox;
        lightbox = document.createElement('div');
        lightbox.className = 'slw-lightbox';
        lightbox.style.display = 'none';
        lightbox.innerHTML =
            '<button type="button" class="slw-lightbox__close" aria-label="Close">' +
                '<svg viewBox="0 0 16 16" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M4 4l8 8M12 4l-8 8"/></svg>' +
            '</button>' +
            '<img class="slw-lightbox__img" alt="" />';
        lightbox.addEventListener('click', function(e) {
            if (e.target === lightbox || e.target.closest('.slw-lightbox__close')) {
                hideLightbox();
            }
        });
        document.body.appendChild(lightbox);
        return lightbox;
    }
    function showLightbox(src, alt) {
        var box = ensureLightbox();
        var img = box.querySelector('.slw-lightbox__img');
        img.src = src;
        img.alt = alt || '';
        box.style.display = 'flex';
        document.body.style.overflow = 'hidden';
    }
    function hideLightbox() {
        if (!lightbox) return;
        lightbox.style.display = 'none';
        document.body.style.overflow = '';
    }
    document.querySelectorAll('.slw-row-image-btn').forEach(function(btn) {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            showLightbox(this.getAttribute('data-image-full'), this.getAttribute('data-image-alt'));
        });
    });
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            hideHoverPopup();
            hideLightbox();
        }
    });

    // Recommendation links: smooth scroll to the target product row
    document.querySelectorAll('.slw-rec-link').forEach(function(link) {
        link.addEventListener('click', function(e) {
            e.preventDefault();
            var targetId = this.getAttribute('data-target');
            var targetRow = document.getElementById(targetId);
            if (targetRow) {
                targetRow.scrollIntoView({ behavior: 'smooth', block: 'center' });
                targetRow.classList.add('slw-highlight-row');
                setTimeout(function() {
                    targetRow.classList.remove('slw-highlight-row');
                }, 1500);
            }
        });
    });

    // Shipping calculator
    var calcShipBtn = document.getElementById('slw-calc-shipping-btn');
    var shipDebounceTimer = null;

    function getCartItems() {
        return collectItems(null);
    }

    function calculateShipping() {
        var items = getCartItems();
        var zip = document.getElementById('slw-ship-zip').value.trim();
        var resultsEl = document.getElementById('slw-shipping-results');

        if (items.length === 0) {
            resultsEl.style.display = 'block';
            resultsEl.innerHTML = '<div class="slw-shipping-notice">Set product quantities above first.</div>';
            return;
        }
        if (!zip) {
            resultsEl.style.display = 'block';
            resultsEl.innerHTML = '<div class="slw-shipping-notice">Please enter a zip code.</div>';
            return;
        }

        calcShipBtn.disabled = true;
        calcShipBtn.textContent = 'Calculating...';
        resultsEl.style.display = 'block';
        resultsEl.innerHTML = '<div class="slw-shipping-notice">Calculating...</div>';

        var formData = new FormData();
        formData.append('action', 'slw_estimate_shipping');
        formData.append('nonce', nonce);
        formData.append('items', JSON.stringify(items));
        formData.append('zip_code', zip);
        formData.append('country', document.getElementById('slw-ship-country').value);
        formData.append('state', document.getElementById('slw-ship-state').value);

        var xhr = new XMLHttpRequest();
        xhr.open('POST', ajaxUrl);
        xhr.onload = function() {
            var resp;
            try { resp = JSON.parse(xhr.responseText); } catch(e) { resp = null; }

            if (resp && resp.success && resp.data.rates) {
                var html = '<div class="slw-shipping-rates">';
                resp.data.rates.forEach(function(rate) {
                    html += '<div class="slw-shipping-rate">';
                    html += '<span class="slw-shipping-rate-label">' + rate.label + '</span>';
                    html += '<span class="slw-shipping-rate-cost">' + rate.cost + '</span>';
                    html += '</div>';
                });
                html += '</div>';
                resultsEl.innerHTML = html;
                // Mirror the cheapest rate up to the summary card so the
                // customer sees an estimated grand total without scrolling.
                if (resp.data.rates.length > 0) {
                    var shipLine = document.getElementById('slw-of-shipping-line');
                    if (shipLine) {
                        var firstRate = resp.data.rates[0];
                        shipLine.innerHTML = '+ shipping est. <strong>' + firstRate.cost + '</strong>';
                    }
                }
            } else {
                var msg = (resp && resp.data && resp.data.message) ? resp.data.message : 'Could not calculate shipping.';
                resultsEl.innerHTML = '<div class="slw-shipping-notice slw-shipping-notice-error">' + msg + '</div>';
            }
            calcShipBtn.disabled = false;
            calcShipBtn.textContent = 'Calculate Shipping';
        };
        xhr.onerror = function() {
            resultsEl.innerHTML = '<div class="slw-shipping-notice slw-shipping-notice-error">Network error. Please try again.</div>';
            calcShipBtn.disabled = false;
            calcShipBtn.textContent = 'Calculate Shipping';
        };
        xhr.send(formData);
    }

    if (calcShipBtn) {
        calcShipBtn.addEventListener('click', calculateShipping);
    }

    // Debounced auto-recalculate when quantities change (if zip is filled)
    document.querySelectorAll('.slw-qty-input').forEach(function(input) {
        input.addEventListener('change', function() {
            var zip = document.getElementById('slw-ship-zip');
            if (zip && zip.value.trim()) {
                clearTimeout(shipDebounceTimer);
                shipDebounceTimer = setTimeout(calculateShipping, 800);
            }
        });
    });

    // Live category-minimum progress: sum quantities inside each category section
    // and update the "X / Y" badge in the category header.
    function updateCategoryProgress() {
        document.querySelectorAll('.slw-category-section').forEach(function(section) {
            var min = parseInt(section.getAttribute('data-category-min')) || 0;
            if (min <= 0) return;
            var slug = section.getAttribute('data-category');
            var total = 0;
            section.querySelectorAll('.slw-qty-input').forEach(function(input) {
                total += parseInt(input.value) || 0;
            });
            var badge = document.querySelector('.slw-cat-progress[data-category="' + slug + '"]');
            if (badge) {
                badge.textContent = total + ' / ' + min;
                badge.style.color = (total >= min) ? '#a3d977' : '#FFD27F';
            }
        });
    }
    document.querySelectorAll('.slw-qty-input').forEach(function(input) {
        input.addEventListener('input', updateCategoryProgress);
        input.addEventListener('change', updateCategoryProgress);
    });
    updateCategoryProgress();

    // Dismiss store notice
    var dismissBtn = document.querySelector('.slw-notice-dismiss');
    if (dismissBtn) {
        dismissBtn.addEventListener('click', function() {
            var notice = this.closest('.slw-store-notice');
            var formData = new FormData();
            formData.append('action', 'slw_dismiss_notice');
            formData.append('nonce', this.getAttribute('data-nonce'));

            var xhr = new XMLHttpRequest();
            xhr.open('POST', ajaxUrl);
            xhr.send(formData);
            notice.style.display = 'none';
        });
    }
})();
</script>
