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
        'slw_discount_percent'              => array( 'sanitize' => 'absint',              'default' => 50 ),
        'slw_first_order_minimum'           => array( 'sanitize' => 'floatval',            'default' => 300 ),
        'slw_reorder_minimum'               => array( 'sanitize' => 'floatval',            'default' => 0 ),
        'slw_webhook_url'                   => array( 'sanitize' => 'esc_url_raw',         'default' => '' ),
        'slw_net30_enabled'                 => array( 'sanitize' => 'rest_sanitize_boolean','default' => false ),
        'slw_wholesale_tax_exempt_default'  => array( 'sanitize' => 'rest_sanitize_boolean','default' => false ),
        'slw_wholesale_shipping_methods'    => array( 'sanitize' => 'array',               'default' => array() ),
        'slw_retail_shipping_methods'       => array( 'sanitize' => 'array',               'default' => array() ),
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
                if ( $config['sanitize'] === 'array' ) {
                    $value = isset( $_POST[ $key ] ) ? self::sanitize_method_array( $_POST[ $key ] ) : array();
                } elseif ( $config['sanitize'] === 'rest_sanitize_boolean' ) {
                    $value = ! empty( $_POST[ $key ] );
                } elseif ( $config['sanitize'] === 'absint' ) {
                    $value = absint( $_POST[ $key ] ?? $config['default'] );
                } elseif ( $config['sanitize'] === 'floatval' ) {
                    $value = floatval( $_POST[ $key ] ?? $config['default'] );
                } elseif ( $config['sanitize'] === 'esc_url_raw' ) {
                    $value = esc_url_raw( $_POST[ $key ] ?? '' );
                } else {
                    $value = sanitize_text_field( $_POST[ $key ] ?? '' );
                }
                update_option( $key, $value );
            }
            $saved = true;
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

                <h2 class="title">Shipping Method Restrictions</h2>
                <p>Select which shipping methods each customer type can see at checkout. Leaving either section empty means that role sees ALL shipping methods (no restrictions).</p>
                <?php self::render_shipping_restrictions(); ?>

                <?php submit_button( 'Save Settings' ); ?>
            </form>
        </div>
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
}
