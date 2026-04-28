<?php
/**
 * Pricing Page
 *
 * Consolidated admin page for all pricing-related configuration:
 * global pricing settings, wholesale tiers, and per-product overrides.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class SLW_Pricing_Page {

    public static function init() {
        add_action( 'admin_init', array( __CLASS__, 'handle_save' ) );
    }

    /**
     * Render the consolidated pricing page.
     */
    public static function render_page() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }

        $saved = isset( $_GET['slw_pricing_saved'] ) && $_GET['slw_pricing_saved'] === '1';
        $tiers = SLW_Tiers::get_tiers();
        ?>
        <div class="wrap slw-admin-dashboard">
            <h1>Pricing Configuration</h1>
            <p style="color:#628393;margin-bottom:24px;">Manage global pricing, wholesale tiers, and view per-product overrides in one place.</p>

            <?php if ( $saved ) : ?>
                <div class="notice notice-success is-dismissible">
                    <p>Pricing settings saved successfully.</p>
                </div>
            <?php endif; ?>

            <form method="post">
                <?php wp_nonce_field( 'slw_pricing_save' ); ?>
                <input type="hidden" name="slw_pricing_save" value="1" />

                <!-- Section 1: Global Pricing -->
                <div class="slw-admin-card" style="padding:20px 24px;margin-bottom:24px;">
                    <h2 class="slw-admin-card__heading" style="margin-bottom:16px;">Global Pricing</h2>
                    <p style="color:#628393;margin-bottom:16px;">These defaults apply to all wholesale customers unless overridden by a tier or per-product setting.</p>

                    <table class="form-table">
                        <tr>
                            <th scope="row"><label for="slw_discount_percent">Wholesale Discount (%)</label></th>
                            <td>
                                <input type="number" id="slw_discount_percent" name="slw_discount_percent"
                                       value="<?php echo esc_attr( get_option( 'slw_discount_percent', 50 ) ); ?>"
                                       min="1" max="99" step="1" class="small-text" />
                                <p class="description">Percentage off retail price for wholesale customers. Default: 50%.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="slw_first_order_minimum">First Order Minimum ($)</label></th>
                            <td>
                                <input type="number" id="slw_first_order_minimum" name="slw_first_order_minimum"
                                       value="<?php echo esc_attr( get_option( 'slw_first_order_minimum', 300 ) ); ?>"
                                       min="0" step="1" class="small-text" />
                                <p class="description">Minimum cart total required for a wholesale customer's first order.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="slw_reorder_minimum">Reorder Minimum ($)</label></th>
                            <td>
                                <input type="number" id="slw_reorder_minimum" name="slw_reorder_minimum"
                                       value="<?php echo esc_attr( get_option( 'slw_reorder_minimum', 0 ) ); ?>"
                                       min="0" step="1" class="small-text" />
                                <p class="description">Minimum cart total for subsequent orders. Set to 0 for no minimum on reorders.</p>
                            </td>
                        </tr>
                    </table>
                </div>

                <!-- Section 2: Wholesale Tiers -->
                <div class="slw-admin-card" style="padding:20px 24px;margin-bottom:24px;">
                    <h2 class="slw-admin-card__heading" style="margin-bottom:16px;">Wholesale Tiers</h2>
                    <p style="color:#628393;margin-bottom:16px;">Define your wholesale tiers below. Each tier has a discount percentage and upgrade thresholds. Customers are auto-upgraded when they meet <strong>either</strong> the order count or lifetime spend threshold.</p>

                    <table class="widefat fixed striped" style="max-width:900px;">
                        <thead>
                            <tr>
                                <th style="width:120px;">Tier Slug</th>
                                <th style="width:160px;">Display Name</th>
                                <th style="width:100px;">Discount %</th>
                                <th style="width:130px;">Orders Threshold</th>
                                <th style="width:130px;">Spend Threshold ($)</th>
                                <th style="width:60px;"></th>
                            </tr>
                        </thead>
                        <tbody id="slw-tier-rows">
                            <?php
                            $index = 0;
                            foreach ( $tiers as $slug => $tier ) :
                            ?>
                            <tr class="slw-tier-row">
                                <td>
                                    <input type="text" name="tiers[<?php echo $index; ?>][slug]" value="<?php echo esc_attr( $slug ); ?>" class="regular-text" style="width:100%;" pattern="[a-z0-9_]+" title="Lowercase letters, numbers, underscores only" required />
                                </td>
                                <td>
                                    <input type="text" name="tiers[<?php echo $index; ?>][name]" value="<?php echo esc_attr( $tier['name'] ); ?>" class="regular-text" style="width:100%;" required />
                                </td>
                                <td>
                                    <input type="number" name="tiers[<?php echo $index; ?>][discount]" value="<?php echo esc_attr( $tier['discount'] ); ?>" min="0" max="99" step="0.5" style="width:100%;" required />
                                </td>
                                <td>
                                    <input type="number" name="tiers[<?php echo $index; ?>][order_threshold]" value="<?php echo esc_attr( $tier['order_threshold'] ?? 0 ); ?>" min="0" step="1" style="width:100%;" />
                                </td>
                                <td>
                                    <input type="number" name="tiers[<?php echo $index; ?>][spend_threshold]" value="<?php echo esc_attr( $tier['spend_threshold'] ?? 0 ); ?>" min="0" step="1" style="width:100%;" />
                                </td>
                                <td>
                                    <?php if ( $slug !== 'standard' ) : ?>
                                        <button type="button" class="button slw-remove-tier" title="Remove tier">&times;</button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php
                            $index++;
                            endforeach;
                            ?>
                        </tbody>
                    </table>

                    <p style="margin-top:12px;">
                        <button type="button" class="button" id="slw-add-tier">+ Add Tier</button>
                    </p>

                    <h3 style="margin-top:20px;">How Tiers Work</h3>
                    <ul style="list-style:disc;margin-left:20px;color:#628393;">
                        <li>The <strong>Standard</strong> tier is the default for all new wholesale partners. Its discount should match your global wholesale discount.</li>
                        <li>Higher tiers unlock automatically when a customer reaches <strong>either</strong> the order count or the lifetime spend threshold.</li>
                        <li>Tiers are evaluated from top to bottom. The highest tier the customer qualifies for is assigned.</li>
                        <li>Auto-upgrade happens when an order status changes to "Completed". You can also set tiers manually on user profiles.</li>
                        <li>Tiers never auto-downgrade. Use the user profile to manually adjust if needed.</li>
                    </ul>
                </div>

                <!-- Section 3: Per-Product Overrides (editable) -->
                <div class="slw-admin-card" style="padding:20px 24px;margin-bottom:24px;">
                    <h2 class="slw-admin-card__heading" style="margin-bottom:16px;">Per-Product Overrides</h2>
                    <p style="color:#628393;margin-bottom:16px;">Set custom wholesale pricing, minimum quantities, and case pack sizes per product. Changes here are saved along with the rest of the pricing settings.</p>

                    <?php self::render_product_overrides_table(); ?>
                </div>

                <?php submit_button( 'Save Pricing Settings' ); ?>
            </form>
        </div>

        <script>
        (function() {
            var tbody = document.getElementById('slw-tier-rows');
            var addBtn = document.getElementById('slw-add-tier');

            addBtn.addEventListener('click', function() {
                var rows = tbody.querySelectorAll('.slw-tier-row');
                var idx = rows.length;
                var tr = document.createElement('tr');
                tr.className = 'slw-tier-row';
                tr.innerHTML = '<td><input type="text" name="tiers[' + idx + '][slug]" value="" class="regular-text" style="width:100%;" pattern="[a-z0-9_]+" title="Lowercase letters, numbers, underscores only" required /></td>'
                    + '<td><input type="text" name="tiers[' + idx + '][name]" value="" class="regular-text" style="width:100%;" required /></td>'
                    + '<td><input type="number" name="tiers[' + idx + '][discount]" value="50" min="0" max="99" step="0.5" style="width:100%;" required /></td>'
                    + '<td><input type="number" name="tiers[' + idx + '][order_threshold]" value="0" min="0" step="1" style="width:100%;" /></td>'
                    + '<td><input type="number" name="tiers[' + idx + '][spend_threshold]" value="0" min="0" step="1" style="width:100%;" /></td>'
                    + '<td><button type="button" class="button slw-remove-tier" title="Remove tier">&times;</button></td>';
                tbody.appendChild(tr);
            });

            tbody.addEventListener('click', function(e) {
                if (e.target.classList.contains('slw-remove-tier')) {
                    e.target.closest('tr').remove();
                }
            });
        })();
        </script>
        <?php
    }

    /**
     * Handle form save on admin_init.
     */
    public static function handle_save() {
        if ( ! isset( $_POST['slw_pricing_save'] ) ) {
            return;
        }

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( 'Unauthorized', 403 );
        }

        check_admin_referer( 'slw_pricing_save' );

        // Save global pricing fields
        $discount = absint( $_POST['slw_discount_percent'] ?? 50 );
        $discount = max( 1, min( 99, $discount ) );
        update_option( 'slw_discount_percent', $discount );

        $first_min = floatval( $_POST['slw_first_order_minimum'] ?? 300 );
        update_option( 'slw_first_order_minimum', max( 0, $first_min ) );

        $reorder_min = floatval( $_POST['slw_reorder_minimum'] ?? 0 );
        update_option( 'slw_reorder_minimum', max( 0, $reorder_min ) );

        // Save tiers
        $raw_tiers = isset( $_POST['tiers'] ) ? $_POST['tiers'] : array();
        $tiers = array();

        foreach ( $raw_tiers as $raw ) {
            $slug = sanitize_key( $raw['slug'] ?? '' );
            if ( ! $slug ) {
                continue;
            }

            $tiers[ $slug ] = array(
                'name'            => sanitize_text_field( $raw['name'] ?? $slug ),
                'discount'        => max( 0, min( 99, (float) ( $raw['discount'] ?? 50 ) ) ),
                'order_threshold' => max( 0, absint( $raw['order_threshold'] ?? 0 ) ),
                'spend_threshold' => max( 0, (float) ( $raw['spend_threshold'] ?? 0 ) ),
            );
        }

        // Ensure standard tier always exists
        if ( ! isset( $tiers['standard'] ) ) {
            $tiers = array_merge( array(
                'standard' => array(
                    'name'            => 'Standard',
                    'discount'        => 50,
                    'order_threshold' => 0,
                    'spend_threshold' => 0,
                ),
            ), $tiers );
        }

        update_option( 'slw_wholesale_tiers', $tiers );

        // Save per-product overrides
        if ( isset( $_POST['slw_product_price'] ) && is_array( $_POST['slw_product_price'] ) ) {
            foreach ( $_POST['slw_product_price'] as $pid => $price ) {
                $pid = absint( $pid );
                if ( $pid <= 0 ) continue;
                update_post_meta( $pid, '_slw_wholesale_price', wc_clean( $price ) );
            }
        }
        if ( isset( $_POST['slw_product_min'] ) && is_array( $_POST['slw_product_min'] ) ) {
            foreach ( $_POST['slw_product_min'] as $pid => $min ) {
                $pid = absint( $pid );
                if ( $pid <= 0 ) continue;
                update_post_meta( $pid, '_slw_minimum_qty', wc_clean( $min ) );
            }
        }
        if ( isset( $_POST['slw_product_case'] ) && is_array( $_POST['slw_product_case'] ) ) {
            foreach ( $_POST['slw_product_case'] as $pid => $case ) {
                $pid = absint( $pid );
                if ( $pid <= 0 ) continue;
                update_post_meta( $pid, '_slw_case_pack_size', wc_clean( $case ) );
            }
        }

        // Save new product override (from "Add Product" row)
        if ( ! empty( $_POST['slw_new_product_id'] ) ) {
            $new_pid = absint( $_POST['slw_new_product_id'] );
            if ( $new_pid > 0 && get_post_type( $new_pid ) === 'product' ) {
                if ( ! empty( $_POST['slw_new_product_price'] ) ) {
                    update_post_meta( $new_pid, '_slw_wholesale_price', wc_clean( $_POST['slw_new_product_price'] ) );
                }
                if ( ! empty( $_POST['slw_new_product_min'] ) ) {
                    update_post_meta( $new_pid, '_slw_minimum_qty', wc_clean( $_POST['slw_new_product_min'] ) );
                }
                if ( ! empty( $_POST['slw_new_product_case'] ) ) {
                    update_post_meta( $new_pid, '_slw_case_pack_size', wc_clean( $_POST['slw_new_product_case'] ) );
                }
            }
        }

        // Redirect back with success flag
        wp_redirect( add_query_arg( 'slw_pricing_saved', '1', wp_get_referer() ? wp_get_referer() : admin_url( 'admin.php?page=slw-pricing' ) ) );
        exit;
    }

    /**
     * Render the editable per-product overrides table with pagination.
     */
    private static function render_product_overrides_table() {
        $paged = isset( $_GET['slw_prod_page'] ) ? max( 1, absint( $_GET['slw_prod_page'] ) ) : 1;
        $per_page = 20;

        $all = wc_get_products( array(
            'status'  => 'publish',
            'limit'   => -1,
            'orderby' => 'title',
            'order'   => 'ASC',
        ) );

        // Filter to orderable product types (includes subscription types)
        $all = array_filter( $all, function( $p ) {
            return $p->is_type( 'simple' ) || $p->is_type( 'variable' )
                || $p->is_type( 'subscription' ) || $p->is_type( 'variable-subscription' );
        } );
        $all = array_values( $all );

        $total_products = count( $all );
        $total_pages = max( 1, ceil( $total_products / $per_page ) );
        $products = array_slice( $all, ( $paged - 1 ) * $per_page, $per_page );

        if ( empty( $products ) ) {
            echo '<p style="color:#888;font-style:italic;">No published products found.</p>';
            return;
        }

        $base_url = admin_url( 'admin.php?page=slw-pricing' );
        ?>
        <p style="color:#888;margin-bottom:8px;">Showing page <?php echo esc_html( $paged ); ?> of <?php echo esc_html( $total_pages ); ?> (<?php echo esc_html( $total_products ); ?> products)</p>
        <table class="widefat striped" style="border-collapse:collapse;">
            <thead>
                <tr>
                    <th style="text-align:left;padding:10px 12px;">Product</th>
                    <th style="text-align:left;padding:10px 12px;width:100px;">SKU</th>
                    <th style="text-align:left;padding:10px 12px;width:140px;">Wholesale Price</th>
                    <th style="text-align:left;padding:10px 12px;width:100px;">Min Qty</th>
                    <th style="text-align:left;padding:10px 12px;width:120px;">Case Pack</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $products as $product ) :
                    if ( ! $product ) continue;

                    $pid             = $product->get_id();
                    $title           = $product->get_name();
                    $wholesale_price = get_post_meta( $pid, '_slw_wholesale_price', true );
                    $min_qty         = get_post_meta( $pid, '_slw_minimum_qty', true );
                    $case_pack       = get_post_meta( $pid, '_slw_case_pack_size', true );
                ?>
                <tr>
                    <td style="padding:8px 12px;">
                        <a href="<?php echo esc_url( admin_url( 'post.php?post=' . $pid . '&action=edit' ) ); ?>" style="color:#386174;text-decoration:none;font-weight:500;"><?php echo esc_html( $title ); ?></a>
                    </td>
                    <td style="padding:8px 12px;color:#628393;font-size:13px;"><?php echo esc_html( $product->get_sku() ?: '' ); ?></td>
                    <td style="padding:8px 12px;">
                        <div style="display:flex;align-items:center;gap:4px;">
                            <span style="color:#628393;">$</span>
                            <input type="number" name="slw_product_price[<?php echo $pid; ?>]" value="<?php echo esc_attr( $wholesale_price ); ?>" step="0.01" min="0" style="width:90px;padding:4px 8px;" placeholder="Global %" />
                        </div>
                    </td>
                    <td style="padding:8px 12px;">
                        <input type="number" name="slw_product_min[<?php echo $pid; ?>]" value="<?php echo esc_attr( $min_qty ); ?>" step="1" min="0" style="width:70px;padding:4px 8px;" placeholder="None" />
                    </td>
                    <td style="padding:8px 12px;">
                        <input type="number" name="slw_product_case[<?php echo $pid; ?>]" value="<?php echo esc_attr( $case_pack ); ?>" step="1" min="0" style="width:70px;padding:4px 8px;" placeholder="None" />
                    </td>
                </tr>
                <?php endforeach; ?>

                <!-- Add Product row -->
                <tr style="background:#f6faf6;border-top:2px solid #e0ddd8;">
                    <td style="padding:10px 12px;" colspan="2">
                        <div style="display:flex;align-items:center;gap:8px;">
                            <span style="font-weight:600;color:#386174;font-size:13px;white-space:nowrap;">Add product:</span>
                            <input type="number" name="slw_new_product_id" value="" min="1" step="1" style="width:100px;padding:6px 10px;" placeholder="Product ID" />
                        </div>
                    </td>
                    <td style="padding:10px 12px;">
                        <div style="display:flex;align-items:center;gap:4px;">
                            <span style="color:#628393;">$</span>
                            <input type="number" name="slw_new_product_price" value="" step="0.01" min="0" style="width:100px;padding:6px 10px;" placeholder="Price" />
                        </div>
                    </td>
                    <td style="padding:10px 12px;">
                        <input type="number" name="slw_new_product_min" value="" step="1" min="0" style="width:80px;padding:6px 10px;" placeholder="Min" />
                    </td>
                    <td style="padding:10px 12px;">
                        <input type="number" name="slw_new_product_case" value="" step="1" min="0" style="width:80px;padding:6px 10px;" placeholder="Case" />
                    </td>
                </tr>
            </tbody>
        </table>

        <?php if ( $total_pages > 1 ) : ?>
        <div style="margin-top:12px;">
            <?php
            for ( $i = 1; $i <= $total_pages; $i++ ) {
                $page_url = add_query_arg( 'slw_prod_page', $i, $base_url );
                if ( $i === $paged ) {
                    echo '<strong style="margin-right:8px;padding:4px 10px;background:#386174;color:#fff;border-radius:3px;">' . $i . '</strong>';
                } else {
                    echo '<a href="' . esc_url( $page_url ) . '" style="margin-right:8px;padding:4px 10px;border:1px solid #ccc;border-radius:3px;text-decoration:none;color:#386174;">' . $i . '</a>';
                }
            }
            ?>
        </div>
        <?php endif; ?>
        <?php
    }
}
