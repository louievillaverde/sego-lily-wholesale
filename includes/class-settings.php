<?php
/**
 * Plugin Settings Page
 *
 * Self-handling form (no options.php dependency) so it works correctly
 * as a sub-page under the custom Wholesale top-level menu. The old
 * Settings API approach broke when moved from add_options_page to
 * add_submenu_page because options.php's whitelist check is tied to
 * the page registration context.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class SLW_Settings {

    /** @var array All settings with their sanitize callbacks and defaults */
    private static $fields = array(
        // Note: slw_discount_percent, slw_first_order_minimum, slw_reorder_minimum
        // are managed by the Pricing page (class-pricing-page.php). Do NOT add them
        // here or they'll be overwritten with empty values on every Settings save.
        'slw_webhook_url'                   => array( 'sanitize' => 'esc_url_raw',         'default' => '' ),
        'slw_net30_enabled'                 => array( 'sanitize' => 'rest_sanitize_boolean','default' => false ),
        'slw_wholesale_tax_exempt_default'  => array( 'sanitize' => 'rest_sanitize_boolean','default' => false ),
        'slw_wholesale_shipping_methods'    => array( 'sanitize' => 'array',               'default' => array() ),
        'slw_retail_shipping_methods'       => array( 'sanitize' => 'array',               'default' => array() ),
        // Booth & Lead Capture
        'slw_booth_retail_code'             => array( 'sanitize' => 'sanitize_text_field',  'default' => 'SEGO15' ),
        'slw_booth_retail_offer'            => array( 'sanitize' => 'sanitize_text_field',  'default' => '15% off your first order' ),
        'slw_booth_retail_url'              => array( 'sanitize' => 'esc_url_raw',          'default' => '' ),
        'slw_booth_wholesale_heading'       => array( 'sanitize' => 'sanitize_text_field',  'default' => 'Welcome! Here is our wholesale price list' ),
        'slw_booth_wholesale_offer'         => array( 'sanitize' => 'sanitize_text_field',  'default' => 'Free shipping if you order at the show' ),
        'slw_booth_wholesale_code'          => array( 'sanitize' => 'sanitize_text_field',  'default' => '' ),
        // Product Visibility
        'slw_wholesale_only_categories'     => array( 'sanitize' => 'array',               'default' => array() ),
        'slw_order_form_categories'         => array( 'sanitize' => 'array',               'default' => array() ),
        // Analytics
        'slw_clarity_project_id'            => array( 'sanitize' => 'sanitize_text_field', 'default' => '' ),
        // Order Form
        'slw_new_arrivals_days'             => array( 'sanitize' => 'absint',             'default' => 30 ),
        'slw_case_packs_enabled'            => array( 'sanitize' => 'rest_sanitize_boolean', 'default' => false ),
        // Store Notice
        'slw_store_notice_enabled'          => array( 'sanitize' => 'rest_sanitize_boolean','default' => false ),
        'slw_store_notice_text'             => array( 'sanitize' => 'wp_kses_post',        'default' => '' ),
        'slw_store_notice_type'             => array( 'sanitize' => 'sanitize_text_field', 'default' => 'info' ),
        'slw_store_notice_dismissible'      => array( 'sanitize' => 'rest_sanitize_boolean','default' => false ),
    );

    public static function init() {
        // Admin menu is registered centrally by SLW_Admin_Menu
    }

    /**
     * Sanitize an array of shipping method IDs.
     */
    private static function sanitize_method_array( $value ) {
        if ( ! is_array( $value ) ) return array();
        return array_values( array_filter( array_map( 'sanitize_text_field', $value ) ) );
    }

    /**
     * Handle form save + render the settings page.
     */
    public static function render_page() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }

        $saved = false;

        // Handle form submission
        if ( isset( $_POST['slw_settings_save'] ) && check_admin_referer( 'slw_settings_nonce' ) ) {
            foreach ( self::$fields as $key => $config ) {
                // wp_unslash ALL string-y inputs before sanitizing. WordPress auto-escapes
                // $_POST so apostrophes arrive as \'. Without unslash, the backslash gets
                // persisted and shows up in the rendered field as literal text
                // (e.g. "Welcome! Here\'s our wholesale price list").
                $raw = $_POST[ $key ] ?? '';
                if ( $config['sanitize'] === 'array' ) {
                    $value = isset( $_POST[ $key ] ) ? self::sanitize_method_array( wp_unslash( (array) $_POST[ $key ] ) ) : array();
                } elseif ( $config['sanitize'] === 'rest_sanitize_boolean' ) {
                    $value = ! empty( $_POST[ $key ] );
                } elseif ( $config['sanitize'] === 'absint' ) {
                    $value = absint( $raw ?: $config['default'] );
                } elseif ( $config['sanitize'] === 'floatval' ) {
                    $value = floatval( $raw ?: $config['default'] );
                } elseif ( $config['sanitize'] === 'esc_url_raw' ) {
                    $value = esc_url_raw( wp_unslash( $raw ) );
                } elseif ( $config['sanitize'] === 'wp_kses_post' ) {
                    $value = wp_kses_post( wp_unslash( $raw ) );
                } else {
                    $value = sanitize_text_field( wp_unslash( $raw ) );
                }
                update_option( $key, $value );
            }
            $saved = true;
        }

        // One-time cleanup: strip stale backslashes from text options saved before
        // the wp_unslash fix above. Targets the known-affected text + html fields.
        if ( ! get_option( 'slw_settings_unslash_cleanup_done' ) ) {
            $text_keys = array(
                'slw_booth_retail_code',
                'slw_booth_retail_offer',
                'slw_booth_wholesale_heading',
                'slw_store_notice_text',
            );
            foreach ( $text_keys as $opt_key ) {
                $current = get_option( $opt_key );
                if ( is_string( $current ) && strpos( $current, "\\" ) !== false ) {
                    update_option( $opt_key, stripslashes( $current ) );
                }
            }
            update_option( 'slw_settings_unslash_cleanup_done', 1 );
        }

        ?>
        <div class="wrap">
            <h1>Wholesale Settings</h1>

            <?php if ( $saved ) : ?>
                <div class="notice notice-success is-dismissible"><p>Settings saved.</p></div>
            <?php endif; ?>

            <form method="post">
                <?php wp_nonce_field( 'slw_settings_nonce' ); ?>
                <input type="hidden" name="slw_settings_save" value="1" />

                <div class="slw-admin-card" style="padding:16px 20px;background:#fff8e1;border:1px solid #ffe082;border-radius:6px;margin-bottom:20px;">
                    <strong>Pricing settings have moved.</strong> Discount percentage, order minimums, and tiers are now managed on the <a href="<?php echo esc_url( admin_url( 'admin.php?page=slw-pricing' ) ); ?>">Pricing page</a>.
                </div>

                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="slw_webhook_url">Automation Webhook URL</label></th>
                        <td>
                            <input type="url" id="slw_webhook_url" name="slw_webhook_url"
                                   value="<?php echo esc_attr( get_option( 'slw_webhook_url', '' ) ); ?>"
                                   class="regular-text" placeholder="https://your-webhook-url.com/wholesale" />
                            <p class="description">Webhook endpoint for CRM/email automation. Fires on application approval and first order placed.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="slw_net30_enabled">Enable NET Payment Terms</label></th>
                        <td>
                            <label>
                                <input type="checkbox" id="slw_net30_enabled" name="slw_net30_enabled"
                                       value="1" <?php checked( get_option( 'slw_net30_enabled', false ) ); ?> />
                                Allow admins to grant NET 30, 60, or 90 day payment terms to individual wholesale customers
                            </label>
                            <p class="description">When enabled, a NET terms dropdown appears on wholesale user profiles where you can assign NET 30, 60, or 90 day terms per customer.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="slw_wholesale_tax_exempt_default">Default wholesale tax exemption</label></th>
                        <td>
                            <label>
                                <input type="checkbox" id="slw_wholesale_tax_exempt_default" name="slw_wholesale_tax_exempt_default"
                                       value="1" <?php checked( get_option( 'slw_wholesale_tax_exempt_default', false ) ); ?> />
                                Make every wholesale user tax exempt by default
                            </label>
                            <p class="description">When enabled, all wholesale customers are exempt from sales tax at checkout regardless of the per-user "Resale Cert Verified" toggle. Leave unchecked to require per-user verification.</p>
                        </td>
                    </tr>
                </table>

                <h2 class="title">Booth &amp; Lead Capture</h2>
                <p>Settings for the smart booth form used at trade shows. These control what visitors see after submitting their info.</p>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="slw_booth_retail_code">Retail Discount Code</label></th>
                        <td>
                            <input type="text" id="slw_booth_retail_code" name="slw_booth_retail_code"
                                   value="<?php echo esc_attr( get_option( 'slw_booth_retail_code', 'SEGO15' ) ); ?>"
                                   class="regular-text" />
                            <p class="description">Discount code shown to retail booth visitors. Default: SEGO15</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="slw_booth_retail_offer">Retail Discount Text</label></th>
                        <td>
                            <input type="text" id="slw_booth_retail_offer" name="slw_booth_retail_offer"
                                   value="<?php echo esc_attr( get_option( 'slw_booth_retail_offer', '15% off your first order' ) ); ?>"
                                   class="regular-text" />
                            <p class="description">Shown as the incentive heading for retail visitors. E.g. "15% off your first order"</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="slw_booth_retail_url">Retail Shop Link</label></th>
                        <td>
                            <input type="url" id="slw_booth_retail_url" name="slw_booth_retail_url"
                                   value="<?php echo esc_attr( get_option( 'slw_booth_retail_url', home_url( '/shop-all' ) ) ); ?>"
                                   class="regular-text" placeholder="<?php echo esc_attr( home_url( '/shop-all' ) ); ?>" />
                            <p class="description">URL the "Shop Now" and "Browse Products" buttons link to.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="slw_booth_wholesale_heading">Wholesale Incentive Heading</label></th>
                        <td>
                            <input type="text" id="slw_booth_wholesale_heading" name="slw_booth_wholesale_heading"
                                   value="<?php echo esc_attr( get_option( 'slw_booth_wholesale_heading', 'Welcome! Here is our wholesale price list' ) ); ?>"
                                   class="regular-text" />
                            <p class="description">Heading shown to wholesale booth visitors after they submit.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="slw_booth_wholesale_offer">Wholesale Bonus Offer</label></th>
                        <td>
                            <input type="text" id="slw_booth_wholesale_offer" name="slw_booth_wholesale_offer"
                                   value="<?php echo esc_attr( get_option( 'slw_booth_wholesale_offer', 'Free shipping if you order at the show' ) ); ?>"
                                   class="regular-text" />
                            <p class="description">Incentive headline shown to wholesale booth visitors. Default: "Free shipping if you order at the show"</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="slw_booth_wholesale_code">Wholesale Bonus Code</label></th>
                        <td>
                            <input type="text" id="slw_booth_wholesale_code" name="slw_booth_wholesale_code"
                                   value="<?php echo esc_attr( get_option( 'slw_booth_wholesale_code', '' ) ); ?>"
                                   class="regular-text" />
                            <p class="description">Optional WooCommerce coupon code that unlocks the bonus (e.g. a 100% shipping discount you create in WC). Shown to wholesale booth visitors so they can apply it at checkout. Leave blank to show only the offer headline.</p>
                        </td>
                    </tr>
                </table>

                <h2 class="title">Product Visibility</h2>
                <p>Control which product categories are visible to retail vs. wholesale customers, and which appear on the wholesale order form.</p>
                <?php self::render_category_checkboxes(); ?>

                <h2 class="title">Analytics</h2>
                <p>Configuration for the Page Intelligence tab on the Analytics page.</p>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="slw_clarity_project_id">Clarity Project ID</label></th>
                        <td>
                            <input type="text" id="slw_clarity_project_id" name="slw_clarity_project_id"
                                   value="<?php echo esc_attr( get_option( 'slw_clarity_project_id', '' ) ); ?>"
                                   class="regular-text" placeholder="e.g. wggeipzv3y" />
                            <p class="description">Your Microsoft Clarity project ID. Find it at <a href="https://clarity.microsoft.com" target="_blank">clarity.microsoft.com</a> under your project settings. Used on the Analytics &rarr; Page Intelligence tab.</p>
                        </td>
                    </tr>
                </table>

                <h2 class="title">Order Form</h2>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="slw_new_arrivals_days">New Arrivals (days)</label></th>
                        <td>
                            <input type="number" id="slw_new_arrivals_days" name="slw_new_arrivals_days"
                                   value="<?php echo esc_attr( get_option( 'slw_new_arrivals_days', 30 ) ); ?>"
                                   min="0" max="365" step="1" style="width:80px;" />
                            <p class="description">Products published within this many days appear in the "New Arrivals" section on the order form. Set to 0 to hide the section.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="slw_case_packs_enabled">Case Packs</label></th>
                        <td>
                            <label>
                                <input type="checkbox" id="slw_case_packs_enabled" name="slw_case_packs_enabled"
                                       value="1" <?php checked( get_option( 'slw_case_packs_enabled', false ) ); ?> />
                                Enable per-product case pack sizes
                            </label>
                            <p class="description">Off (default): case pack inputs are hidden in admin and "Case of N" labels are hidden on the order form. Turn on if you have products that must be ordered in fixed multiples (e.g., a case of 6 lip balms that can't be broken).</p>
                        </td>
                    </tr>
                </table>

                <h2 class="title">Store Notice</h2>
                <p>Display a banner at the top of the wholesale order form and dashboard.</p>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="slw_store_notice_enabled">Enable Store Notice</label></th>
                        <td>
                            <label>
                                <input type="checkbox" id="slw_store_notice_enabled" name="slw_store_notice_enabled"
                                       value="1" <?php checked( get_option( 'slw_store_notice_enabled', false ) ); ?> />
                                Show a notice banner to wholesale customers
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="slw_store_notice_text">Notice Text</label></th>
                        <td>
                            <textarea id="slw_store_notice_text" name="slw_store_notice_text"
                                      rows="3" class="large-text"><?php echo esc_textarea( get_option( 'slw_store_notice_text', '' ) ); ?></textarea>
                            <p class="description">HTML allowed (links, bold, etc.). This text appears at the top of the order form and dashboard.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="slw_store_notice_type">Notice Type</label></th>
                        <td>
                            <select id="slw_store_notice_type" name="slw_store_notice_type">
                                <?php $current_type = get_option( 'slw_store_notice_type', 'info' ); ?>
                                <option value="info" <?php selected( $current_type, 'info' ); ?>>Info (blue)</option>
                                <option value="success" <?php selected( $current_type, 'success' ); ?>>Success (green)</option>
                                <option value="warning" <?php selected( $current_type, 'warning' ); ?>>Warning (yellow)</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="slw_store_notice_dismissible">Dismissible</label></th>
                        <td>
                            <label>
                                <input type="checkbox" id="slw_store_notice_dismissible" name="slw_store_notice_dismissible"
                                       value="1" <?php checked( get_option( 'slw_store_notice_dismissible', false ) ); ?> />
                                Allow customers to dismiss the notice (reappears if you change the text)
                            </label>
                        </td>
                    </tr>
                </table>

                <h2 class="title">Shipping Method Restrictions</h2>
                <p>Select which shipping methods each customer type can see at checkout. Leaving either section empty means that role sees ALL shipping methods (no restrictions).</p>
                <?php self::render_shipping_restrictions(); ?>

                <?php submit_button( 'Save Settings' ); ?>
            </form>
        </div>

        <div id="slw-unsaved-bar" style="display:none;position:fixed;bottom:0;left:0;right:0;background:#386174;color:#F7F6F3;padding:12px 24px;text-align:center;font-family:Georgia,'Times New Roman',serif;font-size:14px;z-index:99999;box-shadow:0 -2px 8px rgba(0,0,0,0.15);">
            You have unsaved changes.
            <button type="button" onclick="document.querySelector('form [name=slw_settings_save]').closest('form').submit();" style="margin-left:12px;background:#D4AF37;color:#1E2A30;border:none;padding:6px 18px;border-radius:4px;font-weight:600;cursor:pointer;font-family:inherit;">Save Settings</button>
        </div>
        <script>
        (function() {
            var form = document.querySelector('form');
            if (!form) return;
            var bar = document.getElementById('slw-unsaved-bar');
            var initial = new FormData(form);
            function check() {
                var current = new FormData(form);
                var changed = false;
                for (var [key, val] of current.entries()) {
                    if (initial.get(key) !== val) { changed = true; break; }
                }
                bar.style.display = changed ? '' : 'none';
            }
            form.addEventListener('input', check);
            form.addEventListener('change', check);
        })();
        </script>
        <?php
    }

    /**
     * Render checkbox lists for shipping method restrictions.
     */
    private static function render_shipping_restrictions() {
        if ( ! class_exists( 'WC_Shipping_Zones' ) ) {
            echo '<p><em>WooCommerce Shipping Zones not available yet.</em></p>';
            return;
        }

        $all_methods = array();
        $zones = WC_Shipping_Zones::get_zones();
        $zones[] = array(
            'id'               => 0,
            'zone_name'        => 'Locations not covered by your other zones',
            'shipping_methods' => WC_Shipping_Zones::get_zone( 0 )->get_shipping_methods(),
        );

        foreach ( $zones as $zone ) {
            $methods = $zone['shipping_methods'] ?? array();
            foreach ( $methods as $method ) {
                $key = $method->id . ':' . $method->instance_id;
                $all_methods[ $key ] = array(
                    'zone'  => $zone['zone_name'],
                    'title' => $method->get_title(),
                    'id'    => $method->id,
                );
            }
        }

        if ( empty( $all_methods ) ) {
            echo '<p><em>No shipping methods configured yet. Add them in WooCommerce &gt; Settings &gt; Shipping.</em></p>';
            return;
        }

        $wholesale_allowed = (array) get_option( 'slw_wholesale_shipping_methods', array() );
        $retail_allowed    = (array) get_option( 'slw_retail_shipping_methods', array() );
        ?>
        <table class="form-table">
            <tr>
                <th scope="row">Wholesale customers can use</th>
                <td>
                    <?php foreach ( $all_methods as $key => $m ) : ?>
                        <label style="display:block;margin-bottom:6px;">
                            <input type="checkbox" name="slw_wholesale_shipping_methods[]" value="<?php echo esc_attr( $key ); ?>"
                                <?php checked( in_array( $key, $wholesale_allowed, true ) ); ?> />
                            <?php echo esc_html( $m['title'] ); ?> <span style="color:#888;">(<?php echo esc_html( $m['zone'] ); ?>)</span>
                        </label>
                    <?php endforeach; ?>
                    <p class="description">Leave all unchecked to allow every method.</p>
                </td>
            </tr>
            <tr>
                <th scope="row">Retail customers can use</th>
                <td>
                    <?php foreach ( $all_methods as $key => $m ) : ?>
                        <label style="display:block;margin-bottom:6px;">
                            <input type="checkbox" name="slw_retail_shipping_methods[]" value="<?php echo esc_attr( $key ); ?>"
                                <?php checked( in_array( $key, $retail_allowed, true ) ); ?> />
                            <?php echo esc_html( $m['title'] ); ?> <span style="color:#888;">(<?php echo esc_html( $m['zone'] ); ?>)</span>
                        </label>
                    <?php endforeach; ?>
                    <p class="description">Leave all unchecked to allow every method.</p>
                </td>
            </tr>
        </table>
        <?php
    }

    /**
     * Render checkbox lists for Wholesale-Only Categories and Order Form Categories.
     */
    private static function render_category_checkboxes() {
        $terms = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false ) );

        if ( is_wp_error( $terms ) || empty( $terms ) ) {
            echo '<p><em>No product categories found. Create categories in Products &gt; Categories first.</em></p>';
            return;
        }

        $wholesale_only_cats  = (array) get_option( 'slw_wholesale_only_categories', array() );
        $order_form_cats      = (array) get_option( 'slw_order_form_categories', array() );
        ?>
        <table class="form-table">
            <tr>
                <th scope="row">Wholesale Exclusive Categories</th>
                <td>
                    <?php foreach ( $terms as $term ) : ?>
                        <label style="display:block;margin-bottom:6px;">
                            <input type="checkbox" name="slw_wholesale_only_categories[]" value="<?php echo esc_attr( $term->term_id ); ?>"
                                <?php checked( in_array( (string) $term->term_id, $wholesale_only_cats, false ) ); ?> />
                            <?php echo esc_html( $term->name ); ?>
                        </label>
                    <?php endforeach; ?>
                    <p class="description">Products in these categories are hidden from your retail shop. Only wholesale customers can see and purchase them.</p>
                </td>
            </tr>
            <tr>
                <th scope="row">Order Form Filter</th>
                <td>
                    <?php foreach ( $terms as $term ) : ?>
                        <label style="display:block;margin-bottom:6px;">
                            <input type="checkbox" name="slw_order_form_categories[]" value="<?php echo esc_attr( $term->term_id ); ?>"
                                <?php checked( in_array( (string) $term->term_id, $order_form_cats, false ) ); ?> />
                            <?php echo esc_html( $term->name ); ?>
                        </label>
                    <?php endforeach; ?>
                    <p class="description">Limit the wholesale order form to only show these categories. Leave all unchecked to show everything.</p>
                </td>
            </tr>
        </table>
        <?php
    }
}
