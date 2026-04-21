<?php
/**
 * Plugin Settings Page
 *
 * Provides a simple admin settings page under Settings > Sego Lily Wholesale.
 * Holly or her admin can adjust discount percentage, order minimums, the AIOS
 * webhook URL, and toggle NET 30 availability from here.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class SLW_Settings {

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'add_settings_page' ) );
        add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
    }

    public static function add_settings_page() {
        add_submenu_page(
            'slw-applications',
            'Wholesale Settings',
            'Settings',
            'manage_woocommerce',
            'slw-settings',
            array( __CLASS__, 'render_page' )
        );
    }

    public static function register_settings() {
        register_setting( 'slw_settings_group', 'slw_discount_percent', array(
            'type'              => 'integer',
            'sanitize_callback' => 'absint',
            'default'           => 50,
        ));
        register_setting( 'slw_settings_group', 'slw_first_order_minimum', array(
            'type'              => 'number',
            'sanitize_callback' => array( __CLASS__, 'sanitize_float' ),
            'default'           => 300,
        ));
        register_setting( 'slw_settings_group', 'slw_reorder_minimum', array(
            'type'              => 'number',
            'sanitize_callback' => array( __CLASS__, 'sanitize_float' ),
            'default'           => 0,
        ));
        register_setting( 'slw_settings_group', 'slw_webhook_url', array(
            'type'              => 'string',
            'sanitize_callback' => 'esc_url_raw',
            'default'           => '',
        ));
        register_setting( 'slw_settings_group', 'slw_net30_enabled', array(
            'type'              => 'boolean',
            'sanitize_callback' => 'rest_sanitize_boolean',
            'default'           => false,
        ));
        register_setting( 'slw_settings_group', 'slw_wholesale_tax_exempt_default', array(
            'type'              => 'boolean',
            'sanitize_callback' => 'rest_sanitize_boolean',
            'default'           => false,
        ));
        register_setting( 'slw_settings_group', 'slw_wholesale_shipping_methods', array(
            'type'              => 'array',
            'sanitize_callback' => array( __CLASS__, 'sanitize_method_array' ),
            'default'           => array(),
        ));
        register_setting( 'slw_settings_group', 'slw_retail_shipping_methods', array(
            'type'              => 'array',
            'sanitize_callback' => array( __CLASS__, 'sanitize_method_array' ),
            'default'           => array(),
        ));
    }

    public static function sanitize_method_array( $value ) {
        if ( ! is_array( $value ) ) return array();
        return array_values( array_filter( array_map( 'sanitize_text_field', $value ) ) );
    }

    public static function sanitize_float( $value ) {
        return floatval( $value );
    }

    public static function render_page() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }
        ?>
        <div class="wrap">
            <h1>Sego Lily Wholesale Settings</h1>
            <form method="post" action="options.php">
                <?php settings_fields( 'slw_settings_group' ); ?>
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
                        <th scope="row"><label for="slw_webhook_url">AIOS Webhook URL</label></th>
                        <td>
                            <input type="url" id="slw_webhook_url" name="slw_webhook_url"
                                   value="<?php echo esc_attr( get_option( 'slw_webhook_url', '' ) ); ?>"
                                   class="regular-text" placeholder="https://your-aios-url.com/webhooks/wholesale-approved" />
                            <p class="description">Webhook endpoint for Lead Piranha AIOS. Fires on application approval and first order placed.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="slw_net30_enabled">Enable NET 30 Option</label></th>
                        <td>
                            <label>
                                <input type="checkbox" id="slw_net30_enabled" name="slw_net30_enabled"
                                       value="1" <?php checked( get_option( 'slw_net30_enabled', false ) ); ?> />
                                Allow admins to grant NET 30 payment terms to individual wholesale customers
                            </label>
                            <p class="description">When enabled, a "NET 30 Terms" checkbox appears on wholesale user profiles.</p>
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

                <h2>Shipping Method Restrictions</h2>
                <p>Select which shipping methods each customer type can see at checkout. Leaving either section empty means that role sees ALL shipping methods (no restrictions).</p>
                <?php self::render_shipping_restrictions(); ?>

                <?php submit_button( 'Save Settings' ); ?>
            </form>
        </div>
        <?php
    }

    /**
     * Render checkbox lists for the two shipping method restriction settings.
     * Enumerates every shipping method across every shipping zone so Holly
     * can pick which apply to wholesale vs retail.
     */
    private static function render_shipping_restrictions() {
        if ( ! class_exists( 'WC_Shipping_Zones' ) ) {
            echo '<p><em>WooCommerce Shipping Zones not available yet.</em></p>';
            return;
        }

        // Collect all configured shipping methods across all zones
        $all_methods = array();
        $zones = WC_Shipping_Zones::get_zones();
        // Include the "Locations not covered" zone (id 0)
        $zones[] = array(
            'id'             => 0,
            'zone_name'      => 'Locations not covered by your other zones',
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
