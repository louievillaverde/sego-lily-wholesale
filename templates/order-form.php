<?php
/**
 * Template: Wholesale Order Form
 *
 * Rendered by the [sego_wholesale_order_form] shortcode.
 * Shows all products in a table with quantity inputs. Wholesale customers
 * can add individual items or bulk-add everything at once.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

$user = wp_get_current_user();
$first_name = $user->first_name ?: $user->display_name;
$has_ordered = get_user_meta( $user->ID, 'slw_first_order_placed', true );
$minimum = (float) slw_get_option( 'first_order_minimum', 300 );
$nonce = wp_create_nonce( 'slw_order_form' );
$ajax_url = admin_url( 'admin-ajax.php' );

// Get all published products
$products = wc_get_products( array(
    'status' => 'publish',
    'limit'  => -1,
    'orderby' => 'title',
    'order'   => 'ASC',
));
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
    // New Arrivals section — show recently published products in a card layout
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

    <?php if ( empty( $products ) ) : ?>
        <p>No products available right now. Check back soon!</p>
    <?php else : ?>

    <table class="slw-product-table">
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
            <?php foreach ( $products as $product ) :
                // Skip grouped or external products
                if ( ! $product->is_type( 'simple' ) && ! $product->is_type( 'variable' ) ) continue;

                $image = wp_get_attachment_image_url( $product->get_image_id(), 'thumbnail' );
                if ( ! $image ) {
                    $image = wc_placeholder_img_src( 'thumbnail' );
                }

                // For variable products, show the price range
                if ( $product->is_type( 'variable' ) ) {
                    $price_html = $product->get_price_html();
                } else {
                    $price_html = wc_price( $product->get_price() );
                }

                // Build a subtitle from product attributes (scent, size, etc.)
                $attributes = $product->get_attributes();
                $subtitle_parts = array();
                foreach ( $attributes as $attr ) {
                    if ( is_object( $attr ) && method_exists( $attr, 'get_options' ) ) {
                        $terms = $attr->get_options();
                        if ( $attr->is_taxonomy() ) {
                            $term_names = array();
                            foreach ( $terms as $term_id ) {
                                $term = get_term( $term_id );
                                if ( $term && ! is_wp_error( $term ) ) {
                                    $term_names[] = $term->name;
                                }
                            }
                            $subtitle_parts[] = implode( ', ', $term_names );
                        } else {
                            $subtitle_parts[] = implode( ', ', $terms );
                        }
                    }
                }
                $subtitle = implode( ' | ', $subtitle_parts );

                // Case pack size
                $case_pack = class_exists( 'SLW_Product_Minimums' ) ? SLW_Product_Minimums::get_case_pack_size( $product->get_id() ) : 0;
                $min_qty   = class_exists( 'SLW_Product_Minimums' ) ? SLW_Product_Minimums::get_product_minimum( $product->get_id() ) : 0;
                $step      = $case_pack > 0 ? $case_pack : 1;
                $default_qty = $case_pack > 0 ? $case_pack : 0;
                $min_input = $case_pack > 0 ? $case_pack : 0;
                if ( $min_qty > $min_input ) {
                    $min_input = $min_qty;
                }
            ?>
            <tr data-product-id="<?php echo esc_attr( $product->get_id() ); ?>" id="slw-product-<?php echo esc_attr( $product->get_id() ); ?>">
                <td class="slw-col-image">
                    <img src="<?php echo esc_url( $image ); ?>" alt="<?php echo esc_attr( $product->get_name() ); ?>" width="60" height="60" />
                </td>
                <td class="slw-col-product">
                    <strong><?php echo esc_html( $product->get_name() ); ?></strong>
                    <?php if ( $case_pack > 0 ) : ?>
                        <br><span class="slw-case-pack-label">Case of <?php echo esc_html( $case_pack ); ?></span>
                    <?php endif; ?>
                    <?php if ( $subtitle ) : ?>
                        <br><span class="slw-product-meta"><?php echo esc_html( $subtitle ); ?></span>
                    <?php endif; ?>
                    <?php if ( $product->get_sku() ) : ?>
                        <br><span class="slw-product-sku">SKU: <?php echo esc_html( $product->get_sku() ); ?></span>
                    <?php endif; ?>
                </td>
                <td class="slw-col-price"><?php echo $price_html; ?></td>
                <td class="slw-col-qty">
                    <?php if ( $product->is_type( 'simple' ) && $product->is_in_stock() ) : ?>
                        <input type="number" class="slw-qty-input" min="<?php echo esc_attr( $min_input ); ?>" max="999" step="<?php echo esc_attr( $step ); ?>" value="<?php echo esc_attr( $default_qty ); ?>" data-product-id="<?php echo esc_attr( $product->get_id() ); ?>" />
                    <?php elseif ( ! $product->is_in_stock() ) : ?>
                        <span class="slw-out-of-stock">Out of stock</span>
                    <?php else : ?>
                        <a href="<?php echo esc_url( $product->get_permalink() ); ?>" class="slw-btn slw-btn-small">Select options</a>
                    <?php endif; ?>
                </td>
                <td class="slw-col-action">
                    <?php if ( $product->is_type( 'simple' ) && $product->is_in_stock() ) : ?>
                        <button type="button" class="slw-btn slw-btn-small slw-add-single" data-product-id="<?php echo esc_attr( $product->get_id() ); ?>">Add</button>
                    <?php endif; ?>
                </td>
            </tr>
            <?php
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

    function addToCart(items, btn) {
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
                btn.textContent = btn.id === 'slw-add-all-btn' ? 'Add All to Cart' : 'Add';
            }
        };
        xhr.onerror = function() {
            showMessage('Network error. Please try again.', 'error');
            if (btn) {
                btn.disabled = false;
                btn.textContent = btn.id === 'slw-add-all-btn' ? 'Add All to Cart' : 'Add';
            }
        };
        xhr.send(formData);
    }

    // Single-row "Add" button
    document.querySelectorAll('.slw-add-single').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var productId = this.getAttribute('data-product-id');
            var input = document.querySelector('.slw-qty-input[data-product-id="' + productId + '"]');
            var qty = parseInt(input.value) || 0;
            var minVal = parseInt(input.getAttribute('min')) || 1;
            if (qty < minVal) {
                input.value = minVal;
                qty = minVal;
            }
            addToCart([{ product_id: productId, quantity: qty }], this);
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
            var items = [];
            document.querySelectorAll('.slw-qty-input').forEach(function(input) {
                var qty = parseInt(input.value) || 0;
                if (qty > 0) {
                    items.push({
                        product_id: input.getAttribute('data-product-id'),
                        quantity: qty
                    });
                }
            });
            if (items.length === 0) {
                showMessage('Set quantities for the products you want, then click Add All to Cart.', 'info');
                return;
            }
            addToCart(items, this);
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
        var items = [];
        document.querySelectorAll('.slw-qty-input').forEach(function(input) {
            var qty = parseInt(input.value) || 0;
            if (qty > 0) {
                items.push({
                    product_id: input.getAttribute('data-product-id'),
                    quantity: qty
                });
            }
        });
        return items;
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
