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
    <div class="slw-order-form-header">
        <h2>Wholesale Order Form</h2>
        <p>Hey <?php echo esc_html( $first_name ); ?>! Set your quantities and add items to your cart. All prices shown are your wholesale rate.</p>
        <?php if ( ! $has_ordered ) : ?>
            <p class="slw-minimum-note">Your first order has a $<?php echo number_format( $minimum, 0 ); ?> minimum.</p>
        <?php endif; ?>
    </div>

    <div id="slw-order-message" class="slw-notice" style="display:none;"></div>

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
            ?>
            <tr data-product-id="<?php echo esc_attr( $product->get_id() ); ?>">
                <td class="slw-col-image">
                    <img src="<?php echo esc_url( $image ); ?>" alt="<?php echo esc_attr( $product->get_name() ); ?>" width="60" height="60" />
                </td>
                <td class="slw-col-product">
                    <strong><?php echo esc_html( $product->get_name() ); ?></strong>
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
                        <input type="number" class="slw-qty-input" min="0" max="999" value="0" data-product-id="<?php echo esc_attr( $product->get_id() ); ?>" />
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
            <?php endforeach; ?>
        </tbody>
    </table>

    <div class="slw-order-form-footer">
        <button type="button" class="slw-btn slw-btn-primary" id="slw-add-all-btn">Add All to Cart</button>
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
            if (qty < 1) {
                input.value = 1;
                qty = 1;
            }
            addToCart([{ product_id: productId, quantity: qty }], this);
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
})();
</script>
