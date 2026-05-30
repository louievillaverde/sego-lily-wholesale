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
$reorder_minimum = (float) slw_get_option( 'reorder_minimum', 0 );

// Recommended target amount based on the customer's order history.
// Averages the last 5 completed orders (any type -- retail or
// wholesale). NO floor. Some of Holly's small wholesale suppliers
// have legitimately low typical orders; we don't push a $150 target
// onto them. If a customer only has one $300+ first order on record,
// that order IS their average and becomes the recommendation.
$recommended_amount = 0;
$past_order_count = 0;
if ( $user->ID ) {
    $past_orders = wc_get_orders( array(
        'customer_id' => $user->ID,
        'limit'       => 5,
        'status'      => array( 'wc-processing', 'wc-completed' ),
        'orderby'     => 'date',
        'order'       => 'DESC',
        'return'      => 'objects',
    ) );
    if ( ! empty( $past_orders ) && ! is_wp_error( $past_orders ) ) {
        $sum = 0;
        $cnt = 0;
        foreach ( $past_orders as $past ) {
            $t = (float) $past->get_total();
            if ( $t > 0 ) {
                $sum += $t;
                $cnt++;
            }
        }
        if ( $cnt > 0 ) {
            $recommended_amount = round( $sum / $cnt, 0 );
            $past_order_count = $cnt;
        }
    }
}

// Two distinct concepts: HARD floor (blocks checkout) and SUGGESTED
// target (visual reference only).
//   - First-time wholesale customers: hard floor = first_order_min
//     (matches the validation at woocommerce_check_cart_items).
//   - Returning customers: hard floor = reorder_min (0 unless Holly
//     explicitly set one). They are NOT blocked from checking out at
//     a small amount. Bar shows their typical order as a suggestion.
$is_suggestion_only = false;
if ( $has_ordered ) {
    if ( $reorder_minimum > 0 ) {
        $active_minimum  = $reorder_minimum;
        $active_min_label = 'reorder minimum';
    } elseif ( $recommended_amount > 0 ) {
        $active_minimum  = $recommended_amount;
        $active_min_label = $past_order_count === 1 ? 'your last order' : 'your typical order';
        $is_suggestion_only = true;
    } else {
        $active_minimum  = 0;
        $active_min_label = '';
        $is_suggestion_only = true;
    }
} else {
    $active_minimum  = $minimum;
    $active_min_label = 'first-order minimum';
}
$nonce = wp_create_nonce( 'slw_order_form' );
$ajax_url = admin_url( 'admin-ajax.php' );

// Build a JS-side map of category + product minimums so we can surface
// violations in the Cart Preview without waiting for the customer to
// reach checkout. Both maps are keyed by ID, values include the name
// and the minimum so the warning can read naturally.
$js_category_mins = array();
if ( class_exists( 'SLW_Category_Minimums' ) ) {
    $cat_mins = SLW_Category_Minimums::get_minimums();
    foreach ( (array) $cat_mins as $term_id => $min_qty ) {
        $term = get_term( $term_id, 'product_cat' );
        $js_category_mins[ (int) $term_id ] = array(
            'name' => $term && ! is_wp_error( $term ) ? $term->name : ( 'Category #' . $term_id ),
            'min'  => (int) $min_qty,
        );
    }
}
$js_product_mins = array();
$js_product_categories = array();
if ( class_exists( 'SLW_Product_Minimums' ) ) {
    foreach ( $all_products as $p ) {
        $pid = $p->get_id();
        $min = SLW_Product_Minimums::get_product_minimum( $pid );
        if ( $min > 0 ) {
            $js_product_mins[ $pid ] = array(
                'name' => $p->get_name(),
                'min'  => (int) $min,
            );
        }
        // Map for category-min lookups: which categories does this
        // product belong to? Pre-built once per page so the JS doesn't
        // have to fetch per product.
        $term_ids = wc_get_product_term_ids( $pid, 'product_cat' );
        if ( ! empty( $term_ids ) ) {
            $js_product_categories[ $pid ] = array_map( 'intval', $term_ids );
        }
    }
}

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

    <?php
    // Wholesale top nav (tabs + Sign out). Order form is a standalone
    // page outside the portal shortcode, so render the nav inline here
    // -- otherwise customers lose access to Invoices/Account/etc while
    // they shop.
    if ( class_exists( 'SLW_Customer_Portal' ) ) {
        SLW_Customer_Portal::render_nav( 'orders' );
    }
    ?>

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

        <div class="slw-order-header-tools">
            <div class="slw-order-search">
                <svg class="slw-order-search__icon" viewBox="0 0 16 16" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <circle cx="7" cy="7" r="5"/><path d="M11 11l3.5 3.5"/>
                </svg>
                <input type="search"
                       id="slw-order-search-input"
                       class="slw-order-search__input"
                       placeholder="Search by product name or SKU..."
                       aria-label="Search products"
                       autocomplete="off" />
                <button type="button" class="slw-order-search__clear" id="slw-order-search-clear" aria-label="Clear search" hidden>×</button>
            </div>
            <button type="button" class="slw-btn slw-btn-secondary slw-bulk-import-btn" id="slw-bulk-import-btn">
                <svg viewBox="0 0 16 16" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="margin-right:6px;vertical-align:middle;">
                    <path d="M2 10v3h12v-3M8 2v9M4 6l4-4 4 4"/>
                </svg>Bulk Import skus
            </button>

            <?php
            // Saved-order picker. Pulled inline from user_meta so we
            // don't need an extra AJAX round-trip on page load. Always
            // visible so the UI element is discoverable even before the
            // customer has saved their first template.
            $saved_carts        = get_user_meta( $user->ID, 'slw_saved_carts', true );
            $saved_carts        = is_array( $saved_carts ) ? $saved_carts : array();
            $saved_carts_nonce  = wp_create_nonce( 'slw_saved_carts' );
            $has_saved          = ! empty( $saved_carts );
            ?>
            <div class="slw-saved-orders" id="slw-saved-orders">
                <label class="slw-saved-orders__label" for="slw-saved-orders-select">Saved orders</label>
                <select class="slw-saved-orders__select" id="slw-saved-orders-select" data-nonce="<?php echo esc_attr( $saved_carts_nonce ); ?>" <?php echo $has_saved ? '' : 'disabled'; ?>>
                    <?php if ( $has_saved ) : ?>
                        <option value="">Load a saved order…</option>
                        <?php foreach ( $saved_carts as $slug => $tpl ) :
                            $item_count = isset( $tpl['items'] ) ? count( $tpl['items'] ) : 0;
                            $label = sprintf(
                                '%s (%d item%s, saved %s)',
                                $tpl['name'] ?? 'Untitled',
                                $item_count,
                                $item_count === 1 ? '' : 's',
                                $tpl['created'] ?? ''
                            );
                            ?>
                            <option value="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $label ); ?></option>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <option value="">No saved orders yet — save current cart below</option>
                    <?php endif; ?>
                </select>
            </div>
        </div>

        <div class="slw-bulk-import-modal" id="slw-bulk-import-modal" hidden>
            <div class="slw-bulk-import-modal__inner">
                <button type="button" class="slw-bulk-import-modal__close" id="slw-bulk-import-close" aria-label="Close">×</button>
                <h3>Bulk Import skus</h3>
                <p>Paste SKUs and quantities, one per line. Example: <code>TLB-COCO 12</code></p>
                <textarea id="slw-bulk-import-input" rows="8" placeholder="SKU-A 12&#10;SKU-B 24&#10;SKU-C 6"></textarea>
                <div class="slw-bulk-import-modal__actions">
                    <button type="button" class="slw-btn slw-btn-secondary" id="slw-bulk-import-cancel">Cancel</button>
                    <button type="button" class="slw-btn slw-btn-primary" id="slw-bulk-import-apply">Apply to Form</button>
                </div>
                <div class="slw-bulk-import-modal__result" id="slw-bulk-import-result" aria-live="polite"></div>
            </div>
        </div>
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

                // 'medium' (~300x300) instead of 'thumbnail' (150x150) so
                // the image doesn't blow up blurry inside the card.
                $mo_image = wp_get_attachment_image_url( $mo_product->get_image_id(), 'medium' );
                if ( ! $mo_image && $mo_parent ) {
                    $mo_image = wp_get_attachment_image_url( $mo_parent->get_image_id(), 'medium' );
                }
                if ( ! $mo_image ) {
                    $mo_image = wc_placeholder_img_src( 'medium' );
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
                                data-variation-id="<?php echo esc_attr( $is_var ? $mo_product->get_id() : '' ); ?>">Quick Reorder</button>
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
                <tr data-product-id="<?php echo esc_attr( $product->get_id() ); ?>" data-variation-id="<?php echo esc_attr( $variation->get_id() ); ?>" data-category="<?php echo esc_attr( $cat_slug ); ?>" data-search="<?php echo esc_attr( strtolower( $product->get_name() . ' ' . $var_label . ' ' . $variation->get_sku() . ' ' . $product->get_sku() ) ); ?>" id="slw-product-<?php echo esc_attr( $variation->get_id() ); ?>">
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

                    // Scent hover fallback chain (Shop All uses taxonomy
                    // term descriptions; we try several places to catch
                    // however Holly + Camila have set up the scent meta):
                    //   0. SLW_Scent_Library hardcoded lookup by variation label
                    //   1. variation's own description
                    //   2. attribute-term description (skipping billing intervals)
                    //   3. attribute-term meta '_scent_description' or '_description'
                    //   4. variation meta '_slw_scent_description'
                    //   5. parent product short description (last-resort fallback)
                    $scent_desc = '';
                    // Variety / gift set products have no single scent --
                    // skip the hover entirely so we don't surface a generic
                    // fallback description that doesn't apply.
                    $is_variety = (bool) preg_match( '/variety|gift\s*set/i', $product->get_name() );
                    if ( ! $is_variety && class_exists( 'SLW_Scent_Library' ) ) {
                        $lib_hit = SLW_Scent_Library::get_description( $var_label );
                        if ( $lib_hit ) {
                            $scent_desc = $lib_hit;
                        } else {
                            // Try each attribute value individually so a
                            // composite label like "Renewal - Mango" still
                            // hits "mango" in the library.
                            foreach ( (array) $variation->get_attributes() as $attr_val ) {
                                if ( ! $attr_val ) continue;
                                $attr_hit = SLW_Scent_Library::get_description( $attr_val );
                                if ( $attr_hit ) { $scent_desc = $attr_hit; break; }
                            }
                        }
                    }
                    if ( ! $is_variety && ! $scent_desc ) {
                        $scent_desc = trim( wp_strip_all_tags( $variation->get_description() ) );
                    }
                    if ( ! $is_variety && ! $scent_desc ) {
                        foreach ( (array) $variation->get_attributes() as $attr_tax => $attr_val ) {
                            if ( ! $attr_val ) continue;
                            $lower_val = strtolower( (string) $attr_val );
                            if ( preg_match( '/month|year|week|every|one.?time|subscribe|subscription/i', $lower_val ) ) {
                                continue;
                            }
                            $term = get_term_by( 'slug', $attr_val, $attr_tax );
                            if ( $term && ! is_wp_error( $term ) ) {
                                $tdesc = trim( wp_strip_all_tags( $term->description ?? '' ) );
                                if ( $tdesc ) { $scent_desc = $tdesc; break; }
                                foreach ( array( '_scent_description', '_description', 'scent_description' ) as $mk ) {
                                    $tmeta = trim( wp_strip_all_tags( (string) get_term_meta( $term->term_id, $mk, true ) ) );
                                    if ( $tmeta ) { $scent_desc = $tmeta; break 2; }
                                }
                            }
                        }
                    }
                    if ( ! $is_variety && ! $scent_desc ) {
                        $scent_desc = trim( wp_strip_all_tags( (string) get_post_meta( $variation->get_id(), '_slw_scent_description', true ) ) );
                    }
                    if ( ! $is_variety && ! $scent_desc && $product_desc ) {
                        $scent_desc = $product_desc;
                    }
                    if ( strlen( $scent_desc ) > 320 ) {
                        $scent_desc = substr( $scent_desc, 0, 317 ) . '...';
                    }
                    ?>
                    <?php
                    // Stock badge data. WC's get_stock_quantity returns null
                    // for unmanaged stock; treat null as 'in stock' without a
                    // number. Low-stock threshold = WC setting or 20 default.
                    $v_stock_qty    = $variation->get_stock_quantity();
                    $v_stock_status = $variation->get_stock_status();
                    $low_threshold  = (int) get_option( 'woocommerce_notify_low_stock_amount', 20 );
                    $v_stock_class  = '';
                    $v_stock_text   = '';
                    if ( $v_stock_status === 'outofstock' ) {
                        if ( $variation->backorders_allowed() ) {
                            $v_stock_class = 'slw-stock-badge--backorder';
                            $v_stock_text  = 'Backorder';
                        } else {
                            $v_stock_class = 'slw-stock-badge--out';
                            $v_stock_text  = 'Out of stock';
                        }
                    } elseif ( $v_stock_qty !== null && $v_stock_qty > 0 ) {
                        if ( $v_stock_qty <= $low_threshold ) {
                            $v_stock_class = 'slw-stock-badge--low';
                            $v_stock_text  = 'Only ' . (int) $v_stock_qty . ' left';
                        } else {
                            $v_stock_class = 'slw-stock-badge--in';
                            $v_stock_text  = (int) $v_stock_qty . ' in stock';
                        }
                    } elseif ( $v_stock_status === 'instock' ) {
                        $v_stock_class = 'slw-stock-badge--in';
                        $v_stock_text  = 'In stock';
                    }
                    ?>
                    <td class="slw-col-product">
                        <strong class="slw-product-name<?php echo $product_desc ? ' slw-has-hover' : ''; ?>"
                                <?php if ( $product_desc ) : ?>
                                data-hover-title="<?php echo esc_attr( $product->get_name() ); ?>"
                                data-hover-desc="<?php echo esc_attr( $product_desc ); ?>"
                                <?php endif; ?>><?php echo esc_html( $product->get_name() ); ?></strong>
                        <?php if ( $v_stock_text ) : ?>
                            <span class="slw-stock-badge <?php echo esc_attr( $v_stock_class ); ?>"><?php echo esc_html( $v_stock_text ); ?></span>
                        <?php endif; ?>
                        <br><span class="slw-product-meta<?php echo $scent_desc ? ' slw-has-hover' : ''; ?>"
                                  <?php if ( $scent_desc ) : ?>
                                  data-hover-title="<?php echo esc_attr( $var_label ); ?>"
                                  data-hover-desc="<?php echo esc_attr( $scent_desc ); ?>"
                                  <?php endif; ?>><?php echo esc_html( $var_label ); ?></span>
                        <?php if ( $v_case_pack > 0 && class_exists( 'SLW_Product_Minimums' ) && SLW_Product_Minimums::case_packs_enabled() ) : ?>
                            <br><span class="slw-case-pack-label" data-case-pack="<?php echo esc_attr( $v_case_pack ); ?>">
                                Case of <?php echo esc_html( $v_case_pack ); ?>
                                <span class="slw-case-math" data-row-case-math="<?php echo esc_attr( $variation->get_id() ); ?>"></span>
                            </span>
                        <?php endif; ?>
                        <?php
                        $v_tiers_raw = $variation->get_meta( '_slw_tiered_pricing' );
                        if ( ! $v_tiers_raw ) $v_tiers_raw = get_post_meta( $product->get_id(), '_slw_tiered_pricing', true );
                        if ( $v_tiers_raw && class_exists( 'SLW_Wholesale_Role' ) ) :
                            $v_tiers = SLW_Wholesale_Role::parse_tiers( $v_tiers_raw );
                            if ( ! empty( $v_tiers ) ) :
                                ksort( $v_tiers );
                                $tier_parts = array();
                                foreach ( $v_tiers as $qty_key => $unit_price ) {
                                    $tier_parts[] = $qty_key . '+ for ' . wc_price( $unit_price ) . ' each';
                                }
                        ?>
                            <br><span class="slw-tier-pricing"><?php echo wp_kses_post( implode( ' · ', $tier_parts ) ); ?></span>
                        <?php endif; endif; ?>
                        <?php
                        $v_lead = $variation->get_meta( '_slw_lead_time' );
                        if ( ! $v_lead ) $v_lead = get_post_meta( $product->get_id(), '_slw_lead_time', true );
                        if ( $v_lead ) : ?>
                            <br><span class="slw-lead-time">Ships in <?php echo esc_html( $v_lead ); ?></span>
                        <?php endif; ?>
                        <?php if ( $variation->get_sku() ) : ?>
                            <br><span class="slw-product-sku">SKU: <?php echo esc_html( $variation->get_sku() ); ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="slw-col-price"><?php echo $v_price_html; ?></td>
                    <?php
                    // True retail for the savings calc. The helper walks
                    // sibling variations via get_price() which is filtered
                    // through apply_wholesale_price -- meaning it'd return
                    // wholesale prices, not retail. Temporarily detach the
                    // filter so the read reflects MSRP.
                    $_slw_simple_off = remove_filter( 'woocommerce_product_get_price', array( 'SLW_Wholesale_Role', 'apply_wholesale_price' ), 99 );
                    $_slw_var_off    = remove_filter( 'woocommerce_product_variation_get_price', array( 'SLW_Wholesale_Role', 'apply_wholesale_price' ), 99 );
                    $v_retail = function_exists( 'slw_get_true_regular_price' )
                        ? slw_get_true_regular_price( $variation )
                        : (float) $variation->get_regular_price();
                    if ( $_slw_simple_off ) add_filter( 'woocommerce_product_get_price', array( 'SLW_Wholesale_Role', 'apply_wholesale_price' ), 99, 2 );
                    if ( $_slw_var_off )    add_filter( 'woocommerce_product_variation_get_price', array( 'SLW_Wholesale_Role', 'apply_wholesale_price' ), 99, 2 );
                    ?>
                    <td class="slw-col-qty">
                        <input type="number" class="slw-qty-input" min="<?php echo esc_attr( $v_min_input ); ?>" max="999" step="<?php echo esc_attr( $v_step ); ?>" value="<?php echo esc_attr( $v_default ); ?>"
                               data-product-id="<?php echo esc_attr( $product->get_id() ); ?>"
                               data-variation-id="<?php echo esc_attr( $variation->get_id() ); ?>"
                               data-variation="<?php echo esc_attr( wp_json_encode( $var_attrs ) ); ?>"
                               data-price="<?php echo esc_attr( $variation->get_price() ); ?>"
                               data-retail-price="<?php echo esc_attr( $v_retail ); ?>" />
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
                <tr data-product-id="<?php echo esc_attr( $product->get_id() ); ?>" data-category="<?php echo esc_attr( $cat_slug ); ?>" data-search="<?php echo esc_attr( strtolower( $product->get_name() . ' ' . $product->get_sku() ) ); ?>" id="slw-product-<?php echo esc_attr( $product->get_id() ); ?>">
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
                    <?php
                    $p_stock_qty    = $product->get_stock_quantity();
                    $p_stock_status = $product->get_stock_status();
                    $low_threshold  = (int) get_option( 'woocommerce_notify_low_stock_amount', 20 );
                    $p_stock_class  = ''; $p_stock_text = '';
                    if ( $p_stock_status === 'outofstock' ) {
                        if ( $product->backorders_allowed() ) {
                            $p_stock_class = 'slw-stock-badge--backorder'; $p_stock_text = 'Backorder';
                        } else {
                            $p_stock_class = 'slw-stock-badge--out'; $p_stock_text = 'Out of stock';
                        }
                    } elseif ( $p_stock_qty !== null && $p_stock_qty > 0 ) {
                        if ( $p_stock_qty <= $low_threshold ) {
                            $p_stock_class = 'slw-stock-badge--low';
                            $p_stock_text  = 'Only ' . (int) $p_stock_qty . ' left';
                        } else {
                            $p_stock_class = 'slw-stock-badge--in';
                            $p_stock_text  = (int) $p_stock_qty . ' in stock';
                        }
                    } elseif ( $p_stock_status === 'instock' ) {
                        $p_stock_class = 'slw-stock-badge--in'; $p_stock_text = 'In stock';
                    }
                    ?>
                    <td class="slw-col-product">
                        <strong class="slw-product-name<?php echo $p_desc ? ' slw-has-hover' : ''; ?>"
                                <?php if ( $p_desc ) : ?>
                                data-hover-title="<?php echo esc_attr( $product->get_name() ); ?>"
                                data-hover-desc="<?php echo esc_attr( $p_desc ); ?>"
                                <?php endif; ?>><?php echo esc_html( $product->get_name() ); ?></strong>
                        <?php if ( $p_stock_text ) : ?>
                            <span class="slw-stock-badge <?php echo esc_attr( $p_stock_class ); ?>"><?php echo esc_html( $p_stock_text ); ?></span>
                        <?php endif; ?>
                        <?php if ( $case_pack > 0 && class_exists( 'SLW_Product_Minimums' ) && SLW_Product_Minimums::case_packs_enabled() ) : ?>
                            <br><span class="slw-case-pack-label" data-case-pack="<?php echo esc_attr( $case_pack ); ?>">
                                Case of <?php echo esc_html( $case_pack ); ?>
                                <span class="slw-case-math" data-row-case-math="<?php echo esc_attr( $product->get_id() ); ?>"></span>
                            </span>
                        <?php endif; ?>
                        <?php
                        $p_tiers_raw = get_post_meta( $product->get_id(), '_slw_tiered_pricing', true );
                        if ( $p_tiers_raw && class_exists( 'SLW_Wholesale_Role' ) ) :
                            $p_tiers = SLW_Wholesale_Role::parse_tiers( $p_tiers_raw );
                            if ( ! empty( $p_tiers ) ) :
                                ksort( $p_tiers );
                                $tier_parts = array();
                                foreach ( $p_tiers as $qty_key => $unit_price ) {
                                    $tier_parts[] = $qty_key . '+ for ' . wc_price( $unit_price ) . ' each';
                                }
                        ?>
                            <br><span class="slw-tier-pricing"><?php echo wp_kses_post( implode( ' · ', $tier_parts ) ); ?></span>
                        <?php endif; endif; ?>
                        <?php
                        $p_lead = get_post_meta( $product->get_id(), '_slw_lead_time', true );
                        if ( $p_lead ) : ?>
                            <br><span class="slw-lead-time">Ships in <?php echo esc_html( $p_lead ); ?></span>
                        <?php endif; ?>
                        <?php if ( $product->get_sku() ) : ?>
                            <br><span class="slw-product-sku">SKU: <?php echo esc_html( $product->get_sku() ); ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="slw-col-price"><?php echo $price_html; ?></td>
                    <?php
                    $_slw_simple_off = remove_filter( 'woocommerce_product_get_price', array( 'SLW_Wholesale_Role', 'apply_wholesale_price' ), 99 );
                    $_slw_var_off    = remove_filter( 'woocommerce_product_variation_get_price', array( 'SLW_Wholesale_Role', 'apply_wholesale_price' ), 99 );
                    $p_retail = function_exists( 'slw_get_true_regular_price' )
                        ? slw_get_true_regular_price( $product )
                        : (float) $product->get_regular_price();
                    if ( $_slw_simple_off ) add_filter( 'woocommerce_product_get_price', array( 'SLW_Wholesale_Role', 'apply_wholesale_price' ), 99, 2 );
                    if ( $_slw_var_off )    add_filter( 'woocommerce_product_variation_get_price', array( 'SLW_Wholesale_Role', 'apply_wholesale_price' ), 99, 2 );
                    ?>
                    <td class="slw-col-qty">
                        <?php if ( $product->is_in_stock() ) : ?>
                            <input type="number" class="slw-qty-input" min="<?php echo esc_attr( $min_input ); ?>" max="999" step="<?php echo esc_attr( $step ); ?>" value="<?php echo esc_attr( $default_qty ); ?>" data-product-id="<?php echo esc_attr( $product->get_id() ); ?>" data-price="<?php echo esc_attr( $product->get_price() ); ?>" data-retail-price="<?php echo esc_attr( $p_retail ); ?>" />
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
        <div class="slw-of-card__header slw-cart-preview__header">
            <div class="slw-of-card__header-text">
                <h3 class="slw-of-card__title">Cart Preview</h3>
                <p class="slw-of-card__subtitle" id="slw-cart-preview-meta">Set quantities above to populate.</p>
            </div>
            <button type="button" class="slw-cart-preview__clear" id="slw-cart-preview-clear" hidden>Clear cart</button>
        </div>
        <ul class="slw-cart-preview__list" id="slw-cart-preview-list"></ul>
        <?php
        // Server-side initial render of the violations panel so the
        // customer sees minimum issues immediately on page load -- no
        // dependency on JS having to recompute after first paint. JS
        // still overwrites this on cart changes via renderViolations().
        $boot_violations = array();
        if ( class_exists( 'SLW_Product_Minimums' ) ) {
            $boot_violations = array_merge( $boot_violations, (array) SLW_Product_Minimums::get_violations() );
        }
        if ( class_exists( 'SLW_Category_Minimums' ) ) {
            $boot_violations = array_merge( $boot_violations, (array) SLW_Category_Minimums::get_violations() );
        }
        ?>
        <div class="slw-cart-violations" id="slw-cart-violations" <?php echo empty( $boot_violations ) ? 'hidden' : ''; ?>>
            <div class="slw-cart-violations__title">Cart needs attention before checkout</div>
            <ul class="slw-cart-violations__list" id="slw-cart-violations-list">
                <?php foreach ( $boot_violations as $v ) : ?>
                    <li><?php echo esc_html( $v ); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
    <script>
        window.SLW_DATA = window.SLW_DATA || {};
        window.SLW_DATA.categoryMins      = <?php echo wp_json_encode( (object) $js_category_mins ); ?>;
        window.SLW_DATA.productMins       = <?php echo wp_json_encode( (object) $js_product_mins ); ?>;
        window.SLW_DATA.productCategories = <?php echo wp_json_encode( (object) $js_product_categories ); ?>;
    </script>

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
        <div class="slw-os-savings" id="slw-of-savings" hidden>
            <span class="slw-os-savings__label">You're saving</span>
            <span class="slw-os-savings__value" id="slw-of-savings-value"></span>
            <span class="slw-os-savings__pct" id="slw-of-savings-pct"></span>
            <span class="slw-os-savings__hint">vs retail</span>
        </div>
    </div>

    <div class="slw-order-form-footer">
        <div class="slw-of-actions">
            <button type="button" class="slw-btn slw-btn-primary slw-of-action-btn" id="slw-save-template-btn">Save Order Preset</button>
            <button type="button" class="slw-btn slw-btn-cta slw-of-action-btn slw-of-action-btn--cta" id="slw-checkout-btn">Proceed to Checkout</button>
        </div>
    </div>

    <?php endif; ?>
</div>

<!-- Sticky progress + checkout bar. Pinned to the bottom of the
     viewport so the customer always sees how close they are to the
     minimum and can hit checkout from anywhere on the page. -->
<div class="slw-sticky-bar" id="slw-sticky-bar" data-min="<?php echo esc_attr( (float) $active_minimum ); ?>" data-min-label="<?php echo esc_attr( $active_min_label ); ?>" data-suggestion="<?php echo $is_suggestion_only ? '1' : '0'; ?>">
    <div class="slw-sticky-bar__inner">
        <div class="slw-sticky-bar__status">
            <div class="slw-sticky-bar__line">
                <span class="slw-sticky-bar__total" id="slw-sticky-total">$0.00</span>
                <span class="slw-sticky-bar__cart-tag">in cart</span>
                <span class="slw-sticky-bar__staged" id="slw-sticky-staged" hidden></span>
                <?php if ( $active_minimum > 0 ) : ?>
                    <span class="slw-sticky-bar__sep">of</span>
                    <span class="slw-sticky-bar__min">$<?php echo number_format( $active_minimum, 0 ); ?> <?php echo esc_html( $active_min_label ); ?></span>
                <?php endif; ?>
                <span class="slw-sticky-bar__pct" id="slw-sticky-pct"></span>
            </div>
            <?php if ( $active_minimum > 0 ) : ?>
                <div class="slw-sticky-bar__bar">
                    <div class="slw-sticky-bar__fill" id="slw-sticky-fill"></div>
                    <div class="slw-sticky-bar__staged-fill" id="slw-sticky-staged-fill"></div>
                </div>
            <?php endif; ?>
            <div class="slw-sticky-bar__status-msg" id="slw-sticky-msg"></div>
        </div>
        <button type="button" class="slw-btn slw-btn-cta slw-sticky-bar__cta" id="slw-sticky-checkout">Proceed to Checkout</button>
    </div>
</div>

<style>
/* Header tools row: search + bulk import button side by side.
   Generous bottom margin so the minimum-note line below has air. */
.slw-order-header-tools {
    display: flex;
    gap: 12px;
    align-items: center;
    margin-top: 12px;
    margin-bottom: 10px;
    flex-wrap: wrap;
}
.slw-order-header-tools .slw-order-search { margin-top: 0; flex: 1; min-width: 240px; }
.slw-bulk-import-btn { flex-shrink: 0; }

.slw-saved-orders {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    flex-shrink: 0;
}
.slw-saved-orders__label {
    font-size: 12px;
    font-weight: 700;
    color: #386174;
    text-transform: uppercase;
    letter-spacing: 0.4px;
    white-space: nowrap;
}
.slw-saved-orders__select {
    padding: 8px 10px;
    border: 1px solid #d4cebc;
    border-radius: 6px;
    background: #ffffff;
    color: #2C2C2C;
    font-size: 13px;
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Helvetica, Arial, sans-serif;
    cursor: pointer;
    min-width: 200px;
    max-width: 280px;
    transition: border-color 0.15s;
}
.slw-saved-orders__select:hover { border-color: #386174; }
.slw-saved-orders__select:focus {
    outline: none;
    border-color: #386174;
    box-shadow: 0 0 0 3px rgba(56, 97, 116, 0.12);
}
.slw-minimum-note {
    margin-top: 0 !important;
    margin-bottom: 18px;
    padding: 8px 14px;
    background: #fff8e3;
    border: 1px solid #f0d780;
    border-radius: 8px;
    color: #6b4f0a;
    font-size: 13px;
    font-weight: 600;
    display: inline-block;
}

/* Bulk import modal */
.slw-bulk-import-modal {
    position: fixed;
    inset: 0;
    background: rgba(20, 28, 32, 0.72);
    z-index: 99997;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 24px;
    backdrop-filter: blur(4px);
    -webkit-backdrop-filter: blur(4px);
}
.slw-bulk-import-modal[hidden] { display: none; }
.slw-bulk-import-modal__inner {
    position: relative;
    background: #ffffff;
    border-radius: 12px;
    max-width: 520px;
    width: 100%;
    padding: 24px 26px 22px;
    box-shadow: 0 20px 60px rgba(0,0,0,0.30);
}
.slw-bulk-import-modal__inner h3 { margin: 0 0 8px; font-family: Georgia, 'Times New Roman', serif; color: #386174; font-size: 20px; }
.slw-bulk-import-modal__inner p { margin: 0 0 14px; color: #628393; font-size: 13px; }
.slw-bulk-import-modal__inner code { background: #faf6e8; padding: 2px 6px; border-radius: 4px; font-size: 12px; }
.slw-bulk-import-modal__inner textarea {
    width: 100%;
    padding: 12px 14px;
    border: 1px solid #d4cebc;
    border-radius: 8px;
    font-family: ui-monospace, 'SF Mono', Menlo, monospace;
    font-size: 13px;
    line-height: 1.5;
    resize: vertical;
    min-height: 140px;
    box-sizing: border-box;
}
.slw-bulk-import-modal__inner textarea:focus { outline: none; border-color: #386174; box-shadow: 0 0 0 3px rgba(56, 97, 116, 0.12); }
.slw-bulk-import-modal__close {
    position: absolute;
    top: 12px;
    right: 14px;
    background: transparent;
    border: none;
    font-size: 22px;
    color: #628393;
    cursor: pointer;
    width: 32px;
    height: 32px;
    border-radius: 999px;
}
.slw-bulk-import-modal__close:hover { background: #f0eee9; color: #386174; }
.slw-bulk-import-modal__actions {
    margin-top: 16px;
    display: flex;
    justify-content: flex-end;
    gap: 10px;
}
.slw-bulk-import-modal__result {
    margin-top: 12px;
    font-size: 12.5px;
    color: #2C2C2C;
    font-weight: 500;
    min-height: 18px;
}

/* Search bar */
.slw-order-search {
    position: relative;
    margin-top: 12px;
    max-width: 460px;
}
.slw-order-search__icon {
    position: absolute;
    left: 12px;
    top: 50%;
    transform: translateY(-50%);
    color: #628393;
    pointer-events: none;
}
.slw-order-search__input {
    width: 100%;
    padding: 10px 36px 10px 38px !important;
    background: #ffffff !important;
    border: 1px solid #d4cebc !important;
    border-radius: 999px !important;
    font-size: 14px !important;
    color: #2C2C2C !important;
    transition: border-color 0.15s, box-shadow 0.15s;
}
.slw-order-search__input:focus {
    outline: none;
    border-color: #386174 !important;
    box-shadow: 0 0 0 3px rgba(56, 97, 116, 0.12);
}
.slw-order-search__clear {
    position: absolute;
    right: 8px;
    top: 50%;
    transform: translateY(-50%);
    background: rgba(98, 131, 147, 0.12);
    color: #628393;
    border: none;
    width: 26px;
    height: 26px;
    border-radius: 999px;
    cursor: pointer;
    font-size: 18px;
    line-height: 1;
    display: inline-flex;
    align-items: center;
    justify-content: center;
}
.slw-order-search__clear:hover { background: rgba(98, 131, 147, 0.25); color: #386174; }

/* Tier pricing inline display */
.slw-tier-pricing {
    display: inline-block;
    font-size: 12px;
    color: #1d6b2c;
    font-weight: 500;
    margin-top: 2px;
}
.slw-tier-pricing .woocommerce-Price-amount,
.slw-tier-pricing bdi { color: inherit; }

/* Lead time chip */
.slw-lead-time {
    display: inline-block;
    font-size: 11px;
    color: #2c4f5e;
    background: #e6eef3;
    border: 1px solid #b9cdd8;
    padding: 1px 8px;
    border-radius: 999px;
    margin-top: 4px;
    font-weight: 600;
    letter-spacing: 0.2px;
}

/* Case-pack math inline */
.slw-case-math { color: #628393; font-style: italic; font-weight: 500; }

/* Stock badge next to product name */
.slw-stock-badge {
    display: inline-block;
    margin-left: 8px;
    padding: 1px 8px;
    font-size: 10px;
    font-weight: 700;
    letter-spacing: 0.3px;
    text-transform: uppercase;
    border-radius: 999px;
    line-height: 1.6;
    vertical-align: middle;
    white-space: nowrap;
}
.slw-stock-badge--in { background: #e3f1e6; color: #1d6b2c; border: 1px solid #b8dbc0; }
.slw-stock-badge--low { background: #fff3d6; color: #8a6406; border: 1px solid #f0d56a; }
.slw-stock-badge--out { background: #fbe4e4; color: #963131; border: 1px solid #f2c1c1; }
.slw-stock-badge--backorder { background: #e6eef3; color: #2c4f5e; border: 1px solid #b9cdd8; }

/* Savings vs retail row on the Order Subtotal card */
.slw-os-savings {
    margin-top: 10px;
    padding-top: 10px;
    border-top: 1px dashed #e0dbd0;
    display: flex;
    align-items: baseline;
    gap: 8px;
    flex-wrap: wrap;
    font-size: 13px;
}
/* Force-hide when JS sets the HTML hidden attr -- the display: flex
   rule above would otherwise win and the savings row would stay
   visible after Clear Cart. */
.slw-os-savings[hidden] { display: none !important; }
.slw-os-savings__label { color: #386174; font-weight: 600; font-family: Georgia, 'Times New Roman', serif; }
.slw-os-savings__value { color: #1d6b2c; font-weight: 700; font-size: 18px; font-family: Georgia, 'Times New Roman', serif; }
.slw-os-savings__pct { color: #1d6b2c; font-weight: 600; font-size: 13px; }
.slw-os-savings__hint { color: #628393; font-size: 12px; font-style: italic; }

/* Sticky progress + checkout bar pinned to viewport bottom */
.slw-sticky-bar {
    position: fixed;
    left: 0;
    right: 0;
    bottom: 0;
    z-index: 99996;
    background: linear-gradient(180deg, rgba(247, 246, 243, 0.97) 0%, rgba(247, 246, 243, 1) 100%);
    border-top: 1px solid #d4cebc;
    box-shadow: 0 -4px 16px rgba(56, 97, 116, 0.10);
    backdrop-filter: blur(8px) saturate(140%);
    -webkit-backdrop-filter: blur(8px) saturate(140%);
    padding: 12px 24px;
    transform: translateY(110%);
    transition: transform 0.25s ease;
    pointer-events: none;
}
.slw-sticky-bar--visible {
    transform: translateY(0);
    pointer-events: auto;
}
.slw-sticky-bar__inner {
    max-width: 1100px;
    margin: 0 auto;
    display: flex;
    align-items: center;
    gap: 24px;
}
.slw-sticky-bar__status {
    flex: 1;
    min-width: 0;
}
.slw-sticky-bar__line {
    display: flex;
    align-items: baseline;
    gap: 8px;
    flex-wrap: wrap;
    font-size: 14px;
}
.slw-sticky-bar__total {
    font-family: Georgia, 'Times New Roman', serif;
    font-size: 22px;
    font-weight: 700;
    color: #386174;
    line-height: 1;
}
.slw-sticky-bar__sep { color: #628393; }
.slw-sticky-bar__min { color: #628393; font-weight: 500; }
.slw-sticky-bar__pct { color: #386174; font-weight: 600; }
.slw-sticky-bar__cart-tag {
    background: #386174;
    color: #F7F6F3;
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.4px;
    padding: 3px 7px;
    border-radius: 4px;
    line-height: 1;
    vertical-align: middle;
}
.slw-sticky-bar__staged {
    color: #826a3e;
    background: rgba(212, 175, 55, 0.12);
    border: 1px dashed #d4af37;
    font-size: 11px;
    font-weight: 600;
    padding: 3px 9px;
    border-radius: 999px;
    line-height: 1;
}
.slw-sticky-bar__bar {
    position: relative;
    margin-top: 8px;
    height: 7px;
    background: #e8e2d4;
    border-radius: 999px;
    overflow: hidden;
}
.slw-sticky-bar__fill {
    position: absolute;
    top: 0;
    left: 0;
    height: 100%;
    width: 0%;
    background: linear-gradient(90deg, #D4AF37 0%, #386174 100%);
    border-radius: 999px;
    transition: width 0.3s ease;
}
.slw-sticky-bar__staged-fill {
    position: absolute;
    top: 0;
    left: 0;
    height: 100%;
    width: 0%;
    background: repeating-linear-gradient(
        45deg,
        rgba(212, 175, 55, 0.5),
        rgba(212, 175, 55, 0.5) 5px,
        rgba(212, 175, 55, 0.2) 5px,
        rgba(212, 175, 55, 0.2) 10px
    );
    transition: width 0.3s ease, left 0.3s ease;
}
.slw-sticky-bar--met .slw-sticky-bar__fill {
    background: linear-gradient(90deg, #1d6b2c 0%, #2e9a3d 100%);
}
/* Suggestion mode (returning customers): soften the bar so it doesn't
   read as a hard gate. Cream-to-teal gradient, lighter message text. */
.slw-sticky-bar--suggest .slw-sticky-bar__fill {
    background: linear-gradient(90deg, #d4cebc 0%, #386174 100%);
    opacity: 0.7;
}
.slw-sticky-bar--suggest .slw-sticky-bar__status-msg {
    color: #628393;
    font-style: normal;
}
.slw-sticky-bar--suggest .slw-sticky-bar__min {
    color: #826a3e;
}
.slw-sticky-bar__status-msg {
    margin-top: 6px;
    font-size: 12px;
    color: #628393;
    font-style: italic;
}
.slw-sticky-bar--met .slw-sticky-bar__status-msg {
    color: #1d6b2c;
    font-style: normal;
    font-weight: 600;
}
.slw-sticky-bar__cta {
    flex-shrink: 0;
    padding: 12px 26px !important;
    font-size: 14px !important;
    font-weight: 700 !important;
    min-height: 46px;
}
.slw-sticky-bar__cta:disabled {
    opacity: 0.55;
    cursor: not-allowed;
}
/* Clear space at page bottom so the sticky bar doesn't cover content */
.slw-order-form-wrap { padding-bottom: 120px; }
@media (max-width: 640px) {
    .slw-sticky-bar { padding: 10px 14px; }
    .slw-sticky-bar__inner { gap: 12px; }
    .slw-sticky-bar__total { font-size: 18px; }
    .slw-sticky-bar__cta { padding: 10px 18px !important; font-size: 13px !important; min-height: 42px; }
    .slw-order-form-wrap { padding-bottom: 140px; }
}

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
.slw-cart-preview { position: relative; }
.slw-cart-preview__header {
    display: block;
    padding-right: 110px; /* reserve room for the absolute-positioned button */
}
.slw-cart-preview__clear {
    position: absolute;
    top: 18px;
    right: 18px;
    background: #386174;
    border: 1px solid #386174;
    color: #F7F6F3;
    font-size: 12px;
    font-weight: 700;
    padding: 8px 16px;
    border-radius: 6px;
    cursor: pointer;
    transition: background 0.15s, transform 0.1s, box-shadow 0.15s;
    letter-spacing: 0.3px;
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Helvetica, Arial, sans-serif;
    white-space: nowrap;
    text-transform: uppercase;
    box-shadow: 0 2px 6px rgba(56, 97, 116, 0.22);
    z-index: 2;
}
.slw-cart-preview__clear:hover {
    background: #2C4F5E;
    box-shadow: 0 4px 12px rgba(56, 97, 116, 0.32);
    transform: translateY(-1px);
}
.slw-cart-preview__clear:active { transform: translateY(0); }
.slw-cart-preview__clear[hidden] { display: none; }
.slw-cart-preview__list { list-style: none; margin: 0; padding: 0; }
.slw-cart-preview__item { display: grid; grid-template-columns: 80px 1fr auto 28px; gap: 14px; align-items: center; padding: 12px 0; border-bottom: 1px dashed rgba(224, 219, 208, 0.65); font-size: 13.5px; }
.slw-cart-preview__item:last-child { border-bottom: none; }
/* Mirror the look + native spinner arrows of .slw-qty-input on the
   product rows so the Cart Preview qty field reads as part of the
   same system. Browser default spinner arrows are intentionally
   kept (LV directive 2026-05-29). */
.slw-cart-preview__qty-edit {
    width: 70px;
    padding: 6px 8px;
    text-align: center;
    font-size: 14px;
    border: 1px solid #d4cebc;
    border-radius: 6px;
    background: #ffffff;
    color: #386174;
    font-weight: 600;
    transition: border-color 0.15s, box-shadow 0.15s;
}
.slw-cart-preview__qty-edit:focus {
    outline: none;
    border-color: #386174;
    box-shadow: 0 0 0 3px rgba(56, 97, 116, 0.12);
}
.slw-cart-preview__item:last-child { border-bottom: none; }
.slw-cart-preview__qty { font-weight: 700; color: #386174; font-family: Georgia, 'Times New Roman', serif; }
.slw-cart-preview__name { color: #2C2C2C; text-wrap: pretty; }
.slw-cart-preview__total { font-weight: 600; color: #2C2C2C; white-space: nowrap; }
.slw-cart-preview__remove {
    width: 26px;
    height: 26px;
    border-radius: 50%;
    border: 1px solid #d4cebc;
    background: #ffffff;
    color: #628393;
    font-size: 20px;
    line-height: 1;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    transition: background 0.15s, border-color 0.15s, color 0.15s, transform 0.1s;
    padding: 0;
    font-weight: 600;
    box-shadow: 0 1px 2px rgba(56, 97, 116, 0.06);
}
.slw-cart-preview__remove:hover {
    background: #fff0f0;
    border-color: #b27474;
    color: #6e3a3a;
    transform: scale(1.05);
}
.slw-cart-preview__remove:disabled { opacity: 0.4; cursor: not-allowed; }

/* Cart minimum violations -- surfaces per-product + per-category
   minimums before checkout so customers can fix in-page. */
.slw-cart-violations {
    margin-top: 14px;
    padding: 14px 16px;
    background: #fff5f0;
    border: 1px solid #f0c8a8;
    border-left: 4px solid #c0703a;
    border-radius: 8px;
    color: #6b3b1a;
}
.slw-cart-violations[hidden] { display: none !important; }
.slw-cart-violations__title {
    font-family: Georgia, 'Times New Roman', serif;
    font-weight: 700;
    font-size: 14px;
    color: #6b3b1a;
    margin-bottom: 8px;
    text-wrap: balance;
}
.slw-cart-violations__list {
    list-style: disc;
    margin: 0;
    padding-left: 20px;
    font-size: 13px;
    line-height: 1.5;
    color: #6b3b1a;
}
.slw-cart-violations__list li { margin-bottom: 4px; text-wrap: pretty; }
.slw-cart-violations__list li:last-child { margin-bottom: 0; }
/* Reposition + restyle the WooCommerce "added to cart" notice on the
   wholesale order form. Default behavior dumps a green block right above
   the Proceed to Checkout button -- looks sloppy and covers the CTA.
   We brand-style it (cream + teal) and float it as a top-right toast
   instead, so the customer gets feedback without losing the CTA.
   Auto-dismisses after 4s via the small script appended below. */
body.page-wholesale-order .woocommerce-notices-wrapper,
.slw-order-form-container ~ .woocommerce-notices-wrapper,
.slw-order-form-container .woocommerce-notices-wrapper {
    position: fixed;
    top: 90px;
    right: 20px;
    z-index: 99980;
    max-width: 360px;
    width: calc(100vw - 40px);
    pointer-events: none;
}
body.page-wholesale-order .woocommerce-message,
body.page-wholesale-order .wc-block-components-notice-banner,
body.page-wholesale-order .wc-block-components-notice-snackbar,
.slw-order-form-container ~ .woocommerce-notices-wrapper .woocommerce-message,
.slw-order-form-container .woocommerce-notices-wrapper .woocommerce-message {
    background: #386174 !important;
    color: #F7F6F3 !important;
    border: none !important;
    border-left: 4px solid #D4AF37 !important;
    border-radius: 10px !important;
    padding: 14px 18px !important;
    margin: 0 0 10px !important;
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Helvetica, Arial, sans-serif !important;
    font-size: 14px !important;
    line-height: 1.45 !important;
    box-shadow: 0 8px 24px rgba(56, 97, 116, 0.28) !important;
    pointer-events: auto;
    text-wrap: pretty;
    animation: slwToastIn 0.28s ease both;
}
body.page-wholesale-order .woocommerce-message::before,
.slw-order-form-container .woocommerce-message::before {
    color: #D4AF37 !important;
}
/* Hide the inline "View cart" + "Checkout" buttons WC stuffs into the
   notice. Wholesale doesn't use the standard cart page and the Cart
   Preview + sticky bar already give the customer the right path. */
body.page-wholesale-order .woocommerce-message .button,
body.page-wholesale-order .woocommerce-message .restore-item,
.slw-order-form-container .woocommerce-message .button {
    display: none !important;
}
@keyframes slwToastIn {
    from { opacity: 0; transform: translateX(20px); }
    to   { opacity: 1; transform: translateX(0); }
}
@keyframes slwToastOut {
    from { opacity: 1; transform: translateX(0); }
    to   { opacity: 0; transform: translateX(20px); }
}
.slw-toast-leaving { animation: slwToastOut 0.25s ease both !important; }
@media (max-width: 600px) {
    body.page-wholesale-order .woocommerce-notices-wrapper {
        top: 70px;
        right: 12px;
        left: 12px;
        max-width: none;
        width: auto;
    }
}
.slw-cart-preview__item--in-cart { background: rgba(56, 97, 116, 0.04); margin: 0 -6px; padding-left: 6px; padding-right: 6px; border-radius: 4px; }
.slw-cart-preview__item--staged .slw-cart-preview__qty { color: #8a6d1a; }
.slw-cart-preview__pending { font-size: 11px; color: #8a6d1a; font-style: italic; margin-left: 6px; font-weight: 500; }

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
    .slw-cart-preview__item { grid-template-columns: 32px 1fr 28px; gap: 8px; }
    .slw-cart-preview__total { grid-column: 2; text-align: right; }
    .slw-cart-preview__remove { grid-row: 1 / span 2; align-self: start; }
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
.slw-of-toast--error {
    background: #6b3b1a;
    border-left: 4px solid #D4AF37;
}
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
    <?php
    // Prefer the dedicated wholesale checkout (native WC, not Elementor)
    // since the theme's /checkout was silently dropping wholesale discounts.
    // Send wholesale customers to the standard WC /checkout page. WC
    // core handles payment, shipping, gateways natively. Wholesale
    // prices flow through apply_wholesale_price on cart line items.
    ?>
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
    function escapeHtml(str) {
        return String(str || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }
    function refreshCartFromServer(payload) {
        if (!payload || !Array.isArray(payload.items)) return;
        inCartItems = payload.items;
        updateSubtotal();
    }
    function postCartAction(action, extra) {
        var fd = new FormData();
        fd.append('action', action);
        fd.append('nonce', nonce);
        if (extra) {
            Object.keys(extra).forEach(function(k) { fd.append(k, extra[k]); });
        }
        return fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd })
            .then(function(r) { return r.json(); })
            .then(function(json) {
                if (json && json.success) {
                    refreshCartFromServer(json.data);
                } else {
                    console.warn('[SLW] cart action failed', action, json);
                }
                return json;
            })
            .catch(function(err) { console.warn('[SLW] cart action error', action, err); });
    }

    // Auto-dismiss the toast-styled WooCommerce notice after 4s. Adds a
    // leaving class for the slide-out animation, then removes from DOM.
    // Listen to WC's own events instead of MutationObserver -- a body-
    // subtree observer fires on every DOM mutation and was killing
    // perf on Elementor pages.
    function dismissWcToasts() {
        var notices = document.querySelectorAll('.woocommerce-message, .wc-block-components-notice-banner, .wc-block-components-notice-snackbar');
        notices.forEach(function(n) {
            if (n.dataset.slwDismissArmed) return;
            n.dataset.slwDismissArmed = '1';
            setTimeout(function() {
                n.classList.add('slw-toast-leaving');
                setTimeout(function() { n.parentNode && n.parentNode.removeChild(n); }, 280);
            }, 4000);
        });
    }
    dismissWcToasts();
    if (typeof window.jQuery !== 'undefined') {
        jQuery(document.body).on('added_to_cart wc_fragments_refreshed wc_fragments_loaded', dismissWcToasts);
    }

    // Cart minimum violations: scan inCartItems against the server-built
    // category + product minimums maps and surface any violations under
    // the Cart Preview so the customer can fix BEFORE hitting checkout.
    var violationsEl     = document.getElementById('slw-cart-violations');
    var violationsListEl = document.getElementById('slw-cart-violations-list');
    function computeViolations() {
        var data = window.SLW_DATA || {};

        // Per-product minimum check, aggregated across siblings.
        // Holly's intent: a product min of 6 on Natural Tallow Deodorant
        // means "6 total deodorants in the cart, customer can mix &
        // match scents" -- NOT 6 of each scent. The server-side check
        // in class-product-minimums.php was changed to match in this
        // release.
        var prodTotals       = {};
        (inCartItems || []).forEach(function(ci) {
            var pid = parseInt(ci.product_id, 10) || 0;
            var qty = parseInt(ci.qty, 10) || 0;
            if (pid <= 0 || qty <= 0) return;
            prodTotals[pid] = (prodTotals[pid] || 0) + qty;
        });
        var productMessages  = [];
        var productViolators = {};
        Object.keys(data.productMins || {}).forEach(function(pid) {
            var rule = data.productMins[pid];
            var have = prodTotals[pid] || 0;
            if (have > 0 && have < rule.min) {
                productMessages.push(rule.name + ' minimum: ' + rule.min + '. You have ' + have + ' (mix & match across scents). Add ' + (rule.min - have) + ' more.');
                productViolators[pid] = true;
            }
        });

        // Per-category total check: aggregate qty across ALL cart items
        // belonging to a category, since the category "mix & match
        // across scents" allowance is exactly the cross-line sum.
        var catTotals       = {};
        var catContributors = {};
        (inCartItems || []).forEach(function(ci) {
            var pid = parseInt(ci.product_id, 10) || 0;
            var qty = parseInt(ci.qty, 10) || 0;
            if (pid <= 0 || qty <= 0) return;
            var cats = (data.productCategories || {})[pid] || [];
            cats.forEach(function(tid) {
                catTotals[tid] = (catTotals[tid] || 0) + qty;
                if (!catContributors[tid]) catContributors[tid] = {};
                catContributors[tid][pid] = true;
            });
        });
        var categoryMessages = [];
        Object.keys(data.categoryMins || {}).forEach(function(tid) {
            var rule = data.categoryMins[tid];
            var have = catTotals[tid] || 0;
            if (have > 0 && have < rule.min) {
                // Dedupe: if exactly one product in the cart is in this
                // category AND that product is independently violating
                // its own min, skip -- fixing the product fixes both.
                var contributors = Object.keys(catContributors[tid] || {});
                if (contributors.length === 1 && productViolators[contributors[0]]) {
                    return;
                }
                categoryMessages.push(rule.name + ' minimum: ' + rule.min + ' units. You have ' + have + '. Add ' + (rule.min - have) + ' more (mix & match across scents).');
            }
        });

        // Cart-total minimum (first-order or explicit reorder). Read
        // from the sticky bar's data attrs so we don't duplicate state.
        var bar = document.getElementById('slw-sticky-bar');
        if (bar) {
            var minVal      = parseFloat(bar.getAttribute('data-min')) || 0;
            var minLabel    = bar.getAttribute('data-min-label') || 'order minimum';
            var isHardFloor = bar.getAttribute('data-suggestion') !== '1';
            // Compute cart total + item count from inCartItems since
            // updateSubtotal's locals aren't in scope here.
            var cartTotal = 0;
            var cartItemCount = 0;
            (inCartItems || []).forEach(function(ci) {
                cartTotal     += (ci.lineTotal || 0);
                cartItemCount += parseInt(ci.qty, 10) || 0;
            });
            // Only surface as a violation once the customer has SOMETHING
            // in cart -- otherwise the empty-cart state is just empty.
            if (isHardFloor && minVal > 0 && cartItemCount > 0 && cartTotal < minVal) {
                var diff = minVal - cartTotal;
                productMessages.unshift(
                    'Order ' + minLabel + ': ' + formatPrice(minVal) +
                    '. Cart total is ' + formatPrice(cartTotal) +
                    '. Add ' + formatPrice(diff) + ' more.'
                );
            }
        }

        return productMessages.concat(categoryMessages);
    }
    function renderViolations() {
        if (!violationsEl || !violationsListEl) return [];
        var v = computeViolations();
        violationsListEl.innerHTML = '';
        if (v.length === 0) {
            violationsEl.hidden = true;
            return v;
        }
        violationsEl.hidden = false;
        v.forEach(function(line) {
            var li = document.createElement('li');
            li.textContent = line;
            violationsListEl.appendChild(li);
        });
        return v;
    }

    // Cart Preview: per-line remove + qty +/- + direct input + clear-all
    //
    // NOTE on event binding: we delegate from `document` instead of the
    // <ul id="slw-cart-preview-list"> so the click handler still fires
    // even if the previewListEl reference goes stale (the inner HTML is
    // rewritten by updateSubtotal every render -- the parent ul is
    // stable, but on some themes the entire .slw-cart-preview card gets
    // moved by a child theme / Elementor template, and any delegation
    // bound to the ul reference would silently stop firing). Document-
    // level is the safest scope.
    document.addEventListener('click', function(e) {
        var removeBtn = e.target.closest('.slw-cart-preview__remove');
        if (!removeBtn) return;
        // Existence diagnostic: brief teal toast confirms the click reached
        // the handler. If you don't see this when X is clicked, the
        // handler isn't binding (CSS overlay, theme injection, etc).
        showMessage('Removing from cart…', 'info');
        e.preventDefault();
        removeBtn.disabled = true;
        var ckey = removeBtn.getAttribute('data-cart-key')     || '';
        var pid  = removeBtn.getAttribute('data-product-id')   || '0';
        var vid  = removeBtn.getAttribute('data-variation-id') || '0';
        // Optimistic local removal: drop the row from inCartItems and
        // re-render immediately so the X feels instant. If the server
        // reconciles with a different cart state, the next server
        // response overwrites our local view.
        var snapshot = inCartItems.slice();
        inCartItems = inCartItems.filter(function(ci) {
            if (ckey && ci.cart_key === ckey) return false;
            if (pid !== '0' && vid !== '0'
                && String(ci.product_id) === pid
                && String(ci.variation_id) === vid) return false;
            if (pid !== '0' && vid === '0'
                && String(ci.product_id) === pid
                && (!ci.variation_id || ci.variation_id === 0)) return false;
            return true;
        });
        updateSubtotal();
        postCartAction('slw_remove_cart_line', {
            cart_key:     ckey,
            product_id:   pid,
            variation_id: vid
        }).then(function(json) {
            if (json && json.success && json.data && Array.isArray(json.data.items)) {
                var stillThere = json.data.items.some(function(ci) {
                    if (ckey) return ci.cart_key === ckey;
                    if (pid !== '0' && vid !== '0') return String(ci.product_id) === pid && String(ci.variation_id) === vid;
                    return String(ci.product_id) === pid;
                });
                if (stillThere) {
                    inCartItems = snapshot;
                    updateSubtotal();
                    showMessage('Could not remove the item. Try Clear Cart and re-adding what you want.', 'error');
                }
            } else if (!json || !json.success) {
                inCartItems = snapshot;
                updateSubtotal();
                showMessage('Could not remove the item (AJAX error). Check console.', 'error');
            }
        });
    });

    // Direct edit: debounce on input, immediate on blur / Enter
    if (previewListEl) {
        var qtyInputDebounce = null;
        function commitQty(input) {
            var next = Math.max(0, parseInt(input.value, 10) || 0);
            input.value = next;
            postCartAction('slw_set_cart_qty', {
                cart_key:     input.getAttribute('data-cart-key')     || '',
                product_id:   input.getAttribute('data-product-id')   || '0',
                variation_id: input.getAttribute('data-variation-id') || '0',
                qty:          String(next)
            });
        }
        previewListEl.addEventListener('input', function(e) {
            var input = e.target.closest('.slw-cart-preview__qty-edit');
            if (!input) return;
            clearTimeout(qtyInputDebounce);
            qtyInputDebounce = setTimeout(function() { commitQty(input); }, 600);
        });
        previewListEl.addEventListener('blur', function(e) {
            var input = e.target.closest('.slw-cart-preview__qty-edit');
            if (!input) return;
            clearTimeout(qtyInputDebounce);
            commitQty(input);
        }, true);
        previewListEl.addEventListener('keydown', function(e) {
            if (e.key !== 'Enter') return;
            var input = e.target.closest('.slw-cart-preview__qty-edit');
            if (!input) return;
            e.preventDefault();
            clearTimeout(qtyInputDebounce);
            commitQty(input);
            input.blur();
        });
    }
    var clearCartBtn = document.getElementById('slw-cart-preview-clear');
    if (clearCartBtn) {
        clearCartBtn.addEventListener('click', function(e) {
            e.preventDefault();
            if (!window.confirm('Remove every item from your cart?')) return;
            clearCartBtn.disabled = true;
            // Reset every qty input on the product rows so staged
            // values stop driving the savings / subtotal display.
            document.querySelectorAll('.slw-qty-input').forEach(function(input) {
                input.value = 0;
            });
            // Wipe stale summary numbers immediately so the customer
            // doesn't see a flash of stale data between the AJAX call
            // and updateSubtotal running.
            var savingsEl = document.getElementById('slw-of-savings');
            if (savingsEl) savingsEl.hidden = true;
            var shipLine = document.getElementById('slw-of-shipping-line');
            if (shipLine) shipLine.innerHTML = '';
            var shipResults = document.getElementById('slw-shipping-results');
            if (shipResults) {
                shipResults.innerHTML = '';
                shipResults.style.display = 'none';
            }
            inCartItems = [];
            updateSubtotal();
            postCartAction('slw_clear_cart').finally(function() {
                clearCartBtn.disabled = false;
                // Re-hide just in case the server response triggered a
                // re-render with stale staged inputs.
                if (savingsEl) savingsEl.hidden = true;
                if (shipLine) shipLine.innerHTML = '';
                updateSubtotal();
            });
        });
    }

    var previewListEl = document.getElementById('slw-cart-preview-list');
    var previewMetaEl = document.getElementById('slw-cart-preview-meta');

    // Items actually in the WC cart (server-rendered on initial load,
    // refreshed via AJAX after each per-row Add success). Separate from
    // staged items (qty > 0 in inputs but not yet added).
    var inCartItems = <?php
        $boot = array();
        if ( function_exists( 'WC' ) && WC()->cart && ! WC()->cart->is_empty() ) {
            // Mirror SLW_Order_Form::clean_cart_label so the inline boot
            // uses the same clean label format as the AJAX refresh path:
            // parent product name + non-billing variation attributes,
            // comma-separated. No SKU prefix, no "One-Time Purchase".
            foreach ( WC()->cart->get_cart() as $key => $ci ) {
                $prod = $ci['data'] ?? null;
                if ( ! $prod ) continue;
                $parent_id = (int) $prod->get_parent_id();
                $base = $prod->get_name();
                if ( $parent_id ) {
                    $parent = wc_get_product( $parent_id );
                    if ( $parent ) $base = $parent->get_name();
                }
                $attrs = array();
                foreach ( (array) $prod->get_attributes() as $taxonomy => $value ) {
                    if ( ! $value ) continue;
                    $lower = strtolower( (string) $value );
                    if ( preg_match( '/month|year|week|every|one.?time|subscribe|subscription|recurring/i', $lower ) ) continue;
                    if ( $parent_id > 0 && function_exists( 'slw_attr_differs_among_siblings' )
                        && ! slw_attr_differs_among_siblings( $parent_id, $taxonomy ) ) continue;
                    $term = get_term_by( 'slug', $value, $taxonomy );
                    $attrs[] = $term ? $term->name : ucfirst( str_replace( '-', ' ', $value ) );
                }
                $label = $base . ( ! empty( $attrs ) ? ', ' . implode( ', ', $attrs ) : '' );
                $boot[] = array(
                    'cart_key'     => $key,
                    'product_id'   => (int) ( $ci['product_id'] ?? 0 ),
                    'variation_id' => (int) ( $ci['variation_id'] ?? 0 ),
                    'qty'          => (int) ( $ci['quantity'] ?? 0 ),
                    'label'        => wp_strip_all_tags( $label ),
                    'lineTotal'    => (float) $prod->get_price() * (int) ( $ci['quantity'] ?? 0 ),
                );
            }
        }
        echo wp_json_encode( $boot );
    ?>;

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

    function priceForItem(productId, variationId) {
        // Look up a matching input on the page (variation first, then parent).
        var selector = variationId
            ? '.slw-qty-input[data-variation-id="' + variationId + '"]'
            : '.slw-qty-input[data-product-id="' + productId + '"]:not([data-variation-id])';
        var input = document.querySelector(selector);
        if (input) {
            var p = parseFloat(input.getAttribute('data-price'));
            if (p && !isNaN(p)) return p;
        }
        return 0;
    }

    function mergeIntoCart(it) {
        var price = priceForItem(it.product_id, it.variation_id);
        var existing = inCartItems.find(function(a) {
            return a.product_id === it.product_id && a.variation_id === (it.variation_id || 0);
        });
        if (existing) {
            existing.qty += it.qty;
            existing.lineTotal = existing.qty * price;
        } else {
            inCartItems.push({
                product_id:   it.product_id,
                variation_id: it.variation_id || 0,
                qty:          it.qty,
                label:        it.label || rowLabelForByIds(it.product_id, it.variation_id),
                lineTotal:    it.qty * price
            });
        }
    }

    function rowLabelForByIds(productId, variationId) {
        var selector = variationId
            ? '.slw-qty-input[data-variation-id="' + variationId + '"]'
            : '.slw-qty-input[data-product-id="' + productId + '"]:not([data-variation-id])';
        var input = document.querySelector(selector);
        return input ? rowLabelFor(input) : 'Product #' + productId;
    }

    function updateSubtotal() {
        if (!subtotalEl) return;
        var stagedTotal = 0;
        var stagedLineCount = 0;
        var stagedItemCount = 0;
        var stagedItems = [];
        document.querySelectorAll('.slw-qty-input').forEach(function(input) {
            var qty = parseInt(input.value, 10) || 0;
            if (qty <= 0) return;
            var price = parseFloat(input.getAttribute('data-price')) || 0;
            stagedTotal += qty * price;
            stagedLineCount++;
            stagedItemCount += qty;
            stagedItems.push({ label: rowLabelFor(input), qty: qty, lineTotal: qty * price });
        });

        // Order subtotal = staged + cart items (so the customer sees the
        // full grand total without scrolling).
        var cartTotal = inCartItems.reduce(function(sum, ci) { return sum + (ci.lineTotal || 0); }, 0);
        var cartItemCount = inCartItems.reduce(function(sum, ci) { return sum + (ci.qty || 0); }, 0);
        subtotalEl.textContent = formatPrice(stagedTotal + cartTotal);

        // Cart preview: WC cart items first (already in cart), then any
        // staged-but-not-yet-added rows below.
        if (previewListEl) {
            previewListEl.innerHTML = '';
            inCartItems.forEach(function(item) {
                var li = document.createElement('li');
                li.className = 'slw-cart-preview__item slw-cart-preview__item--in-cart';
                li.innerHTML =
                    '<input type="number" class="slw-cart-preview__qty-edit" min="0" step="1" value="' + item.qty + '"' +
                        ' data-cart-key="' + (item.cart_key || '') + '"' +
                        ' data-product-id="' + (item.product_id || 0) + '"' +
                        ' data-variation-id="' + (item.variation_id || 0) + '"' +
                        ' aria-label="Quantity" />' +
                    '<span class="slw-cart-preview__name">' + escapeHtml(item.label) + '</span>' +
                    '<span class="slw-cart-preview__total">' + formatPrice(item.lineTotal) + '</span>' +
                    '<button type="button" class="slw-cart-preview__remove" aria-label="Remove from cart"' +
                        ' data-cart-key="' + (item.cart_key || '') + '"' +
                        ' data-product-id="' + (item.product_id || 0) + '"' +
                        ' data-variation-id="' + (item.variation_id || 0) + '">&times;</button>';
                previewListEl.appendChild(li);
            });
            // Staged items (qty changed but Add not clicked) are NOT
            // rendered here -- they pollute the Cart Preview with
            // "(staged)" rows that confuse customers. Cart Preview only
            // shows what's actually in the cart. Use the Add buttons or
            // category Add to Cart to commit a row.
            if (previewMetaEl) {
                if (cartItemCount === 0) {
                    previewMetaEl.textContent = 'Add items above to populate.';
                } else {
                    previewMetaEl.textContent = cartItemCount + ' item' + (cartItemCount === 1 ? '' : 's') + ' in cart';
                }
            }
            var clearBtn = document.getElementById('slw-cart-preview-clear');
            if (clearBtn) clearBtn.hidden = cartItemCount === 0;
        }

        // Savings vs retail (Tier 1 ROI element). Looks up each staged input's
        // data-retail-price; for in-cart items uses the same lookup. Skip if
        // retail isn't priced higher than wholesale (subscription products
        // sometimes have weird retail values).
        var retailTotal = 0;
        var wholesaleTotal = stagedTotal + cartTotal;
        document.querySelectorAll('.slw-qty-input').forEach(function(input) {
            var qty = parseInt(input.value, 10) || 0;
            if (qty <= 0) return;
            var r = parseFloat(input.getAttribute('data-retail-price')) || 0;
            retailTotal += qty * r;
        });
        inCartItems.forEach(function(ci) {
            var selector = ci.variation_id
                ? '.slw-qty-input[data-variation-id="' + ci.variation_id + '"]'
                : '.slw-qty-input[data-product-id="' + ci.product_id + '"]:not([data-variation-id])';
            var input = document.querySelector(selector);
            var r = input ? (parseFloat(input.getAttribute('data-retail-price')) || 0) : 0;
            retailTotal += ci.qty * r;
        });
        var savingsEl = document.getElementById('slw-of-savings');
        var savings = retailTotal - wholesaleTotal;
        if (savingsEl) {
            // Only surface savings when items are actually IN CART --
            // staged-but-not-added quantities shouldn't drive the savings
            // display, otherwise an empty cart can show "$54 off" off
            // numbers the customer never committed to.
            if (savings > 0 && retailTotal > 0 && cartItemCount > 0) {
                savingsEl.hidden = false;
                document.getElementById('slw-of-savings-value').textContent = formatPrice(savings);
                var pct = Math.round((savings / retailTotal) * 100);
                document.getElementById('slw-of-savings-pct').textContent = '(' + pct + '% off)';
            } else {
                savingsEl.hidden = true;
            }
        }

        // Recompute violations (per-product + per-category minimums) so
        // they surface in the Cart Preview before checkout, not after.
        var currentViolations = renderViolations();

        // Sticky bar update.
        // Bar now reports CART total (committed via Add buttons) and
        // STAGED total (quantities typed but not yet added) separately
        // so customers don't mistake a rising "$X / $300" number for
        // "I'm already in cart". Two-segment fill: solid teal for the
        // cart portion, gold-tinted hatching for the staged extension.
        var stickyBar        = document.getElementById('slw-sticky-bar');
        var stickyTotal      = document.getElementById('slw-sticky-total');
        var stickyStaged     = document.getElementById('slw-sticky-staged');
        var stickyPct        = document.getElementById('slw-sticky-pct');
        var stickyFill       = document.getElementById('slw-sticky-fill');
        var stickyStagedFill = document.getElementById('slw-sticky-staged-fill');
        var stickyMsg        = document.getElementById('slw-sticky-msg');
        var stickyCta        = document.getElementById('slw-sticky-checkout');
        if (stickyBar) {
            var grandTotal = stagedTotal + cartTotal;
            var totalItems = stagedItemCount + cartItemCount;
            if (stickyTotal) stickyTotal.textContent = formatPrice(cartTotal);
            if (stickyStaged) {
                if (stagedTotal > 0) {
                    stickyStaged.hidden = false;
                    stickyStaged.textContent = '+ ' + formatPrice(stagedTotal) + ' staged';
                } else {
                    stickyStaged.hidden = true;
                    stickyStaged.textContent = '';
                }
            }
            var minimum         = parseFloat(stickyBar.getAttribute('data-min')) || 0;
            var minLabel        = stickyBar.getAttribute('data-min-label') || '';
            var isSuggestion    = stickyBar.getAttribute('data-suggestion') === '1';
            stickyBar.classList.toggle('slw-sticky-bar--visible',  totalItems > 0);
            stickyBar.classList.toggle('slw-sticky-bar--suggest', isSuggestion);
            if (minimum > 0) {
                var cartPct   = Math.min(100, (cartTotal   / minimum) * 100);
                var stagedPct = Math.max(0, Math.min(100 - cartPct, (stagedTotal / minimum) * 100));
                var combined  = Math.min(100, cartPct + stagedPct);
                if (stickyFill) stickyFill.style.width = cartPct + '%';
                if (stickyStagedFill) {
                    stickyStagedFill.style.left  = cartPct + '%';
                    stickyStagedFill.style.width = stagedPct + '%';
                }
                if (stickyPct) stickyPct.textContent = '· ' + Math.round(combined) + '%';

                if (isSuggestion) {
                    // Returning customer mode: never block, never nag.
                    // The bar is a heads-up about their typical order
                    // size, not a checkout gate.
                    stickyBar.classList.remove('slw-sticky-bar--met');
                    if (stickyMsg) {
                        if (currentViolations.length > 0) {
                            stickyMsg.textContent = 'Cart needs attention: ' + currentViolations.length + ' minimum not met. See below.';
                        } else if (cartTotal >= minimum) {
                            stickyMsg.textContent = 'Above ' + minLabel + '. Ready to check out.';
                        } else if (cartTotal > 0) {
                            stickyMsg.textContent = 'You can check out at any amount. ' + minLabel.charAt(0).toUpperCase() + minLabel.slice(1) + ' for reference: ' + formatPrice(minimum) + '.';
                        } else if (stagedTotal > 0) {
                            stickyMsg.textContent = 'Click Add on any product row to add it to your cart.';
                        } else {
                            stickyMsg.textContent = '';
                        }
                    }
                    if (stickyCta) stickyCta.disabled = cartItemCount === 0 || currentViolations.length > 0;
                } else {
                    // First-order mode: hard floor, checkout disabled
                    // until the cart (not staged) meets the minimum.
                    if (cartTotal >= minimum && currentViolations.length === 0) {
                        stickyBar.classList.add('slw-sticky-bar--met');
                        if (stickyMsg) stickyMsg.textContent = 'Minimum met. Ready to check out.';
                        if (stickyCta) stickyCta.disabled = false;
                    } else if (currentViolations.length > 0) {
                        stickyBar.classList.remove('slw-sticky-bar--met');
                        if (stickyMsg) stickyMsg.textContent = 'Cart needs attention: ' + currentViolations.length + ' minimum not met. See below.';
                        if (stickyCta) stickyCta.disabled = true;
                    } else {
                        stickyBar.classList.remove('slw-sticky-bar--met');
                        var diff = minimum - cartTotal;
                        var msg = 'Add ' + formatPrice(diff) + ' more (in cart) to reach the ' + minLabel + '.';
                        if (stagedTotal > 0) {
                            msg += ' Staged items count once you hit Add.';
                        }
                        if (stickyMsg) stickyMsg.textContent = msg;
                        if (stickyCta) stickyCta.disabled = cartItemCount === 0;
                    }
                }
            } else {
                if (stickyMsg) stickyMsg.textContent = '';
                if (stickyCta) stickyCta.disabled = cartItemCount === 0;
            }
        }

        // Legacy variable consumption below
        var total     = stagedTotal + cartTotal;
        var lineCount = stagedLineCount + inCartItems.length;
        var itemCount = stagedItemCount + cartItemCount;

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
        // Live case-pack math: "Case of 6 (24 = 4 cases)"
        input.addEventListener('input', updateCaseMath);
    });
    function updateCaseMath() {
        var row = this.closest ? this.closest('tr') : null;
        if (!row) return;
        var cell = row.querySelector('.slw-case-pack-label');
        if (!cell) return;
        var pack = parseInt(cell.getAttribute('data-case-pack'), 10) || 0;
        if (pack < 1) return;
        var qty = parseInt(this.value, 10) || 0;
        var math = row.querySelector('.slw-case-math');
        if (!math) return;
        if (qty < 1) { math.textContent = ''; return; }
        var cases = qty / pack;
        if (Math.abs(cases - Math.round(cases)) < 0.001) {
            math.textContent = ' · ' + qty + ' = ' + Math.round(cases) + ' case' + (Math.round(cases) === 1 ? '' : 's');
        } else {
            var full = Math.floor(cases);
            var leftover = qty - (full * pack);
            math.textContent = ' · ' + qty + ' = ' + full + ' case' + (full === 1 ? '' : 's') + ' + ' + leftover + ' loose';
        }
    }
    updateSubtotal();

    // Live search filter. Hides rows that don't match the typed query
    // against product name + variation label + SKU. Also collapses any
    // category section that has zero visible rows.
    var searchInput = document.getElementById('slw-order-search-input');
    var searchClear = document.getElementById('slw-order-search-clear');
    function runSearchFilter() {
        var q = (searchInput.value || '').toLowerCase().trim();
        searchClear.hidden = q === '';
        var visibleSections = 0;
        document.querySelectorAll('.slw-category-section').forEach(function(section) {
            var matched = 0;
            section.querySelectorAll('tr[data-search]').forEach(function(row) {
                var hay = row.getAttribute('data-search') || '';
                if (!q || hay.indexOf(q) !== -1) {
                    row.style.display = '';
                    matched++;
                } else {
                    row.style.display = 'none';
                }
            });
            section.style.display = (q && matched === 0) ? 'none' : '';
            if (section.style.display !== 'none') visibleSections++;
        });
    }
    if (searchInput) {
        searchInput.addEventListener('input', runSearchFilter);
        searchClear.addEventListener('click', function() {
            searchInput.value = '';
            runSearchFilter();
            searchInput.focus();
        });
    }

    // Bulk SKU paste. Reads each line, parses SKU + qty, finds the matching
    // row (variation SKU or simple product SKU on data-search), populates
    // the qty input. Reports back which lines matched + which were unknown.
    var bulkBtn    = document.getElementById('slw-bulk-import-btn');
    var bulkModal  = document.getElementById('slw-bulk-import-modal');
    var bulkClose  = document.getElementById('slw-bulk-import-close');
    var bulkCancel = document.getElementById('slw-bulk-import-cancel');
    var bulkApply  = document.getElementById('slw-bulk-import-apply');
    var bulkInput  = document.getElementById('slw-bulk-import-input');
    var bulkResult = document.getElementById('slw-bulk-import-result');
    function openBulk()  { if (bulkModal) bulkModal.hidden = false; if (bulkInput) bulkInput.focus(); }
    function closeBulk() { if (bulkModal) bulkModal.hidden = true; if (bulkResult) bulkResult.textContent = ''; }
    if (bulkBtn)    bulkBtn.addEventListener('click', openBulk);
    if (bulkClose)  bulkClose.addEventListener('click', closeBulk);
    if (bulkCancel) bulkCancel.addEventListener('click', closeBulk);
    if (bulkApply) {
        bulkApply.addEventListener('click', function() {
            var lines = (bulkInput.value || '').split(/\r?\n/);
            var matched = 0;
            var unknown = [];
            lines.forEach(function(raw) {
                var trimmed = raw.trim();
                if (!trimmed) return;
                // Allow "SKU 12", "SKU,12", "SKU\t12", "SKU = 12"
                var m = trimmed.match(/^([^\s,=\t]+)[\s,=\t]+(\d+)$/);
                if (!m) { unknown.push(trimmed); return; }
                var sku = m[1].toLowerCase();
                var qty = parseInt(m[2], 10) || 0;
                if (qty < 1) return;
                // Find an input whose row's data-search contains the SKU.
                var hit = null;
                document.querySelectorAll('tr[data-search]').forEach(function(row) {
                    if (hit) return;
                    var hay = row.getAttribute('data-search') || '';
                    if (hay.indexOf(sku) !== -1) {
                        var input = row.querySelector('.slw-qty-input');
                        if (input) hit = input;
                    }
                });
                if (hit) {
                    hit.value = qty;
                    matched++;
                } else {
                    unknown.push(sku);
                }
            });
            updateSubtotal();
            var msg = matched + ' SKU' + (matched === 1 ? '' : 's') + ' applied.';
            if (unknown.length) msg += ' Not found: ' + unknown.join(', ');
            bulkResult.textContent = msg;
            if (matched > 0 && unknown.length === 0) {
                setTimeout(closeBulk, 1200);
            }
        });
    }

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
        // No inline Checkout CTA -- the sticky bottom bar already has
        // Proceed to Checkout, so the toast is just confirmation.
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
                // Prefer the server's canonical cart state so the Cart
                // Preview reflects EVERYTHING in the cart (catches dedup,
                // qty merges, anything WC did differently than what we
                // expected locally). Fall back to local merge if the
                // server didn't return state (older endpoints).
                if (resp.data.cart_state && Array.isArray(resp.data.cart_state.items)) {
                    inCartItems = resp.data.cart_state.items;
                } else {
                    items.forEach(function(it) {
                        var prodId = parseInt(it.product_id, 10) || 0;
                        var varId  = parseInt(it.variation_id, 10) || 0;
                        mergeIntoCart({ product_id: prodId, variation_id: varId, qty: parseInt(it.quantity, 10) || 0 });
                    });
                }
                // Zero matching row qty inputs whether we merged locally
                // or refreshed from server.
                items.forEach(function(it) {
                    var prodId = parseInt(it.product_id, 10) || 0;
                    var varId  = parseInt(it.variation_id, 10) || 0;
                    var selector = varId
                        ? '.slw-qty-input[data-variation-id="' + varId + '"]'
                        : '.slw-qty-input[data-product-id="' + prodId + '"]:not([data-variation-id])';
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
            addToCart([item], this, 'Quick Reorder');
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
    var stickyCheckoutBtn = document.getElementById('slw-sticky-checkout');
    function bindCheckout(btn) {
        if (!btn) return;
        btn.addEventListener('click', function() {
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
    bindCheckout(checkoutBtn);
    bindCheckout(stickyCheckoutBtn);

    // Saved-order picker: applies a previously-saved template to the cart
    // and reloads so the order form reflects the loaded items.
    var savedOrdersSelect = document.getElementById('slw-saved-orders-select');
    if (savedOrdersSelect) {
        savedOrdersSelect.addEventListener('change', function() {
            var slug = this.value;
            if (!slug) return;
            if (!window.confirm('Load this saved order? Your current cart will be replaced.')) {
                this.value = '';
                return;
            }
            var savedNonce = this.getAttribute('data-nonce') || '';
            this.disabled = true;
            var fd = new FormData();
            fd.append('action', 'slw_load_cart');
            fd.append('nonce', savedNonce);
            fd.append('slug', slug);
            fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd })
                .then(function(r) { return r.json(); })
                .then(function(json) {
                    if (json && json.success) {
                        showMessage(json.data.message || 'Saved order loaded.', 'success');
                        setTimeout(function() { window.location.reload(); }, 600);
                    } else {
                        var msg = (json && json.data && json.data.message) ? json.data.message : 'Could not load the saved order.';
                        showMessage(msg, 'error');
                        savedOrdersSelect.disabled = false;
                        savedOrdersSelect.value = '';
                    }
                })
                .catch(function() {
                    showMessage('Network error loading the saved order.', 'error');
                    savedOrdersSelect.disabled = false;
                    savedOrdersSelect.value = '';
                });
        });
    }

    // Lightweight diagnostic surface so we can verify what data the
    // client side actually has when something doesn't show up. Type
    // `SLW_DEBUG.dump()` in the browser console to log SLW_DATA +
    // inCartItems + computed violations.
    window.SLW_DEBUG = {
        dump: function() {
            console.log('[SLW DEBUG] SLW_DATA:', window.SLW_DATA);
            console.log('[SLW DEBUG] inCartItems:', inCartItems);
            try {
                console.log('[SLW DEBUG] violations:', computeViolations());
            } catch (e) {
                console.log('[SLW DEBUG] computeViolations threw:', e);
            }
        }
    };

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
        // Combine staged items (qty inputs above) with what's already in
        // the cart so the customer can calculate shipping after they've
        // added everything, not only while inputs are still populated.
        var items = getCartItems().concat(inCartItems.map(function(ci) {
            return {
                product_id:   ci.product_id,
                variation_id: ci.variation_id,
                quantity:     ci.qty
            };
        }));
        var zip = document.getElementById('slw-ship-zip').value.trim();
        var resultsEl = document.getElementById('slw-shipping-results');

        if (items.length === 0) {
            resultsEl.style.display = 'block';
            resultsEl.innerHTML = '<div class="slw-shipping-notice">Add items to your cart first.</div>';
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

            if (resp && resp.success && resp.data) {
                // Fallback: WC couldn't find a usable rate -> wholesale
                // shop standard is to weigh + invoice after pack-out.
                if (resp.data.fallback) {
                    resultsEl.innerHTML =
                        '<div class="slw-shipping-fallback">' +
                            '<strong>' + (resp.data.fallback_label || 'Shipping invoiced separately') + '</strong>' +
                            '<span>' + (resp.data.fallback_detail || '') + '</span>' +
                        '</div>';
                    var shipLine = document.getElementById('slw-of-shipping-line');
                    if (shipLine) shipLine.innerHTML = '+ shipping invoiced separately';
                } else if (Array.isArray(resp.data.rates) && resp.data.rates.length > 0) {
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
                    var shipLine = document.getElementById('slw-of-shipping-line');
                    if (shipLine) {
                        var firstRate = resp.data.rates[0];
                        shipLine.innerHTML = '+ shipping est. <strong>' + firstRate.cost + '</strong>';
                    }
                } else {
                    resultsEl.innerHTML = '<div class="slw-shipping-notice slw-shipping-notice-error">Could not calculate shipping.</div>';
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
