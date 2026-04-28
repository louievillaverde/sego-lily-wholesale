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
        <?php if ( ! $has_ordered ) : ?>
            <p class="slw-minimum-note">Your first order has a $<?php echo number_format( $minimum, 0 ); ?> minimum.</p>
        <?php endif; ?>
    </div>

    <div id="slw-order-message" class="slw-notice" style="display:none;" tabindex="-1"></div>

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
    ?>
    <div class="slw-category-section" data-category="<?php echo esc_attr( $cat_slug ); ?>">
        <div class="slw-category-header" style="display:flex;justify-content:space-between;align-items:center;background:#386174;color:#F7F6F3;padding:14px 20px;border-radius:8px 8px 0 0;margin-top:20px;cursor:pointer;" data-category="<?php echo esc_attr( $cat_slug ); ?>">
            <div>
                <h3 style="margin:0;font-size:18px;color:#F7F6F3;font-family:Georgia,'Times New Roman',serif;"><?php echo esc_html( $category_name ); ?></h3>
                <span style="font-size:13px;opacity:0.8;"><?php echo esc_html( count( $cat_products ) ); ?> product<?php echo count( $cat_products ) !== 1 ? 's' : ''; ?></span>
            </div>
            <div style="display:flex;align-items:center;gap:12px;">
                <button type="button" class="slw-btn slw-btn-small slw-add-category" data-category="<?php echo esc_attr( $cat_slug ); ?>" style="background:#D4AF37;color:#1E2A30;border:none;font-weight:700;" onclick="event.stopPropagation();">Add <?php echo esc_html( $category_name ); ?> to Cart</button>
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

                        foreach ( $variations as $var_data ) :
                            $variation = wc_get_product( $var_data['variation_id'] );
                            if ( ! $variation || ! $variation->is_in_stock() ) continue;

                            // Skip variations that are purely subscription billing options.
                            // Only skip if the variation's attributes are ALL subscription-related
                            // (billing period, signup fee, etc.) with no real product attributes
                            // like scent or size. If it has real attrs, keep it.
                            $var_attrs_raw = $var_data['attributes'] ?? array();
                            $real_attr_count = 0;
                            $sub_attr_count = 0;
                            foreach ( $var_attrs_raw as $attr_key => $attr_val ) {
                                if ( ! $attr_val ) continue;
                                $key_lower = strtolower( $attr_key );
                                if ( strpos( $key_lower, 'subscription' ) !== false
                                    || strpos( $key_lower, 'billing' ) !== false
                                    || strpos( $key_lower, 'sign-up' ) !== false
                                    || strpos( $key_lower, 'signup' ) !== false
                                    || strpos( $key_lower, 'purchase' ) !== false ) {
                                    $sub_attr_count++;
                                } else {
                                    $real_attr_count++;
                                }
                            }
                            // Skip only if ALL attributes are subscription-related (no real product attrs)
                            if ( $sub_attr_count > 0 && $real_attr_count === 0 ) continue;

                            // Variation image or fall back to parent
                            $var_image = $var_data['image']['thumb_src'] ?? '';
                            if ( ! $var_image ) {
                                $var_image = $parent_image;
                            }

                            // Build variation name from attributes (skip subscription-related attrs)
                            $var_attrs = $var_data['attributes'] ?? array();
                            $var_label_parts = array();
                            foreach ( $var_attrs as $attr_key => $attr_val ) {
                                if ( ! $attr_val ) continue;
                                $key_lower = strtolower( $attr_key );
                                // Skip subscription/purchase-type attributes from the label
                                if ( strpos( $key_lower, 'subscription' ) !== false
                                    || strpos( $key_lower, 'billing' ) !== false
                                    || strpos( $key_lower, 'purchase' ) !== false
                                    || strpos( $key_lower, 'sign-up' ) !== false
                                    || strpos( $key_lower, 'signup' ) !== false ) {
                                    continue;
                                }
                                $taxonomy = str_replace( 'attribute_', '', $attr_key );
                                $term = get_term_by( 'slug', $attr_val, $taxonomy );
                                $var_label_parts[] = $term ? $term->name : ucfirst( $attr_val );
                            }
                            $var_label = ! empty( $var_label_parts ) ? implode( ' / ', $var_label_parts ) : $variation->get_name();

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

                            $v_price_html = wc_price( $variation->get_price() );
                ?>
                <tr data-product-id="<?php echo esc_attr( $product->get_id() ); ?>" data-variation-id="<?php echo esc_attr( $variation->get_id() ); ?>" data-category="<?php echo esc_attr( $cat_slug ); ?>" id="slw-product-<?php echo esc_attr( $variation->get_id() ); ?>">
                    <td class="slw-col-image">
                        <img src="<?php echo esc_url( $var_image ); ?>" alt="<?php echo esc_attr( $var_label ); ?>" width="60" height="60" />
                    </td>
                    <td class="slw-col-product">
                        <strong><?php echo esc_html( $product->get_name() ); ?></strong>
                        <br><span class="slw-product-meta"><?php echo esc_html( $var_label ); ?></span>
                        <?php if ( $v_case_pack > 0 ) : ?>
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
                               data-variation="<?php echo esc_attr( wp_json_encode( $var_attrs ) ); ?>" />
                    </td>
                    <td class="slw-col-action">
                        <button type="button" class="slw-btn slw-btn-small slw-add-single"
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
                ?>
                <tr data-product-id="<?php echo esc_attr( $product->get_id() ); ?>" data-category="<?php echo esc_attr( $cat_slug ); ?>" id="slw-product-<?php echo esc_attr( $product->get_id() ); ?>">
                    <td class="slw-col-image">
                        <img src="<?php echo esc_url( $image ); ?>" alt="<?php echo esc_attr( $product->get_name() ); ?>" width="60" height="60" />
                    </td>
                    <td class="slw-col-product">
                        <strong><?php echo esc_html( $product->get_name() ); ?></strong>
                        <?php if ( $case_pack > 0 ) : ?>
                            <br><span class="slw-case-pack-label">Case of <?php echo esc_html( $case_pack ); ?></span>
                        <?php endif; ?>
                        <?php if ( $product->get_sku() ) : ?>
                            <br><span class="slw-product-sku">SKU: <?php echo esc_html( $product->get_sku() ); ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="slw-col-price"><?php echo $price_html; ?></td>
                    <td class="slw-col-qty">
                        <?php if ( $product->is_in_stock() ) : ?>
                            <input type="number" class="slw-qty-input" min="<?php echo esc_attr( $min_input ); ?>" max="999" step="<?php echo esc_attr( $step ); ?>" value="<?php echo esc_attr( $default_qty ); ?>" data-product-id="<?php echo esc_attr( $product->get_id() ); ?>" />
                        <?php else : ?>
                            <span class="slw-out-of-stock">Out of stock</span>
                        <?php endif; ?>
                    </td>
                    <td class="slw-col-action">
                        <?php if ( $product->is_in_stock() ) : ?>
                            <button type="button" class="slw-btn slw-btn-small slw-add-single" data-product-id="<?php echo esc_attr( $product->get_id() ); ?>">Add</button>
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
    <div class="slw-shipping-calculator" id="slw-shipping-calculator">
        <div class="slw-shipping-calculator-header">
            <h3>Shipping Estimate</h3>
            <p>Get an estimated shipping cost for your order before checkout.</p>
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

    <div class="slw-order-form-footer">
        <button type="button" class="slw-btn slw-btn-primary" id="slw-add-all-btn">Add All to Cart</button>
        <button type="button" class="slw-btn slw-btn-secondary" id="slw-save-template-btn">Save as Template</button>
        <a href="<?php echo esc_url( wc_get_cart_url() ); ?>" class="slw-btn slw-btn-secondary">View Cart</a>
    </div>

    <?php endif; ?>
</div>

<script>
(function() {
    var ajaxUrl = '<?php echo esc_js( $ajax_url ); ?>';
    var nonce = '<?php echo esc_js( $nonce ); ?>';
    var msgEl = document.getElementById('slw-order-message');

    function showMessage(text, type) {
        msgEl.textContent = text;
        msgEl.className = 'slw-notice slw-notice-' + type;
        msgEl.style.display = 'block';
        msgEl.scrollIntoView({ behavior: 'smooth' });
        setTimeout(function() { msgEl.focus(); }, 300);
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
                // Reset quantities after successful add
                document.querySelectorAll('.slw-qty-input').forEach(function(input) {
                    input.value = '0';
                });
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

    // "Add All to Cart" button
    var addAllBtn = document.getElementById('slw-add-all-btn');
    if (addAllBtn) {
        addAllBtn.addEventListener('click', function() {
            var items = collectItems(null);
            if (items.length === 0) {
                showMessage('Set quantities for the products you want, then click Add All to Cart.', 'info');
                return;
            }
            addToCart(items, this, 'Add All to Cart');
        });
    }

    // "Save as Template" button
    var saveTemplateBtn = document.getElementById('slw-save-template-btn');
    if (saveTemplateBtn) {
        saveTemplateBtn.addEventListener('click', function() {
            var templateName = prompt('Enter a name for this order template:');
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
                saveTemplateBtn.textContent = 'Save as Template';
            };
            xhr.onerror = function() {
                showMessage('Network error. Please try again.', 'error');
                saveTemplateBtn.disabled = false;
                saveTemplateBtn.textContent = 'Save as Template';
            };
            xhr.send(formData);
        });
    }

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
