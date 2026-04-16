<?php
/**
 * Wholesale Customer Dashboard
 *
 * Renders a branded "My Wholesale Account" page at /wholesale-dashboard.
 * Shows recent orders, quick reorder links, account details, brand assets
 * download, and a direct contact link to Holly.
 *
 * Gated to wholesale_customer role only.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class SLW_Dashboard {

    public static function init() {
        add_shortcode( 'sego_wholesale_dashboard', array( __CLASS__, 'render' ) );
    }

    /**
     * Render the dashboard. Non-wholesale users get redirected.
     */
    public static function render( $atts = array() ) {
        if ( ! is_user_logged_in() || ! slw_is_wholesale_user() ) {
            if ( ! is_admin() ) {
                wp_redirect( home_url( '/wholesale-partners' ) );
                exit;
            }
            return '<div class="slw-notice slw-notice-warning">Please <a href="' . wp_login_url( home_url( '/wholesale-dashboard' ) ) . '">log in</a> with your wholesale account.</div>';
        }

        ob_start();
        include SLW_PLUGIN_DIR . 'templates/dashboard.php';
        return ob_get_clean();
    }
}
