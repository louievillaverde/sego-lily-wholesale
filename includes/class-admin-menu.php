<?php
/**
 * Centralized Admin Menu
 *
 * Registers the top-level "Wholesale" menu and ALL sub-pages in a single
 * place so ordering is explicit and predictable. Individual classes still
 * own their render callbacks — this class just controls menu structure.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class SLW_Admin_Menu {

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'register_menus' ) );
    }

    /**
     * Get the count of pending wholesale applications for the menu badge.
     */
    private static function get_pending_application_count() {
        global $wpdb;
        $table = $wpdb->prefix . 'slw_applications';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return 0;
        }
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'pending'" );
    }

    /**
     * Render the admin preview page. Renders the portal directly (no iframe)
     * to avoid dependency on the /wholesale-portal page existing.
     */
    public static function render_preview_page() {
        // Simulate preview mode
        $_GET['slw_preview'] = '1';

        // Load frontend CSS
        wp_enqueue_style( 'slw-frontend-preview', SLW_PLUGIN_URL . 'assets/sego-lily-wholesale.css', array(), SLW_VERSION );

        ?>
        <div class="wrap">
            <h1>Customer Portal Preview</h1>
            <p class="description" style="margin-bottom:20px;">This is exactly what your wholesale partners see. Use the tabs to navigate between sections. Select a customer from the "View as" dropdown to see their specific pricing and order history.</p>
            <div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:24px;margin-top:8px;">
                <?php
                if ( class_exists( 'SLW_Customer_Portal' ) ) {
                    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                    echo SLW_Customer_Portal::render( array() );
                } else {
                    echo '<p>Customer Portal class not loaded. Please ensure the plugin is fully activated.</p>';
                }
                ?>
            </div>
        </div>
        <?php
    }

    public static function register_menus() {
        // SVG icon — storefront with price tag (B2B wholesale).
        $icon_svg = 'data:image/svg+xml;base64,' . base64_encode(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">'
            . '<path d="M2 7L10 2L18 7Z" fill="currentColor" opacity="0.8"/>'
            . '<rect x="3" y="7" width="14" height="11" rx="1" fill="currentColor"/>'
            . '<rect x="7.5" y="11" width="5" height="7" rx="0.5" fill="white" opacity="0.9"/>'
            . '<rect x="4.5" y="8.5" width="3" height="2.5" rx="0.5" fill="white" opacity="0.7"/>'
            . '<rect x="12.5" y="8.5" width="3" height="2.5" rx="0.5" fill="white" opacity="0.7"/>'
            . '<circle cx="11.5" cy="14.5" r="0.5" fill="currentColor"/>'
            . '</svg>'
        );

        // -----------------------------------------------------------
        // Top-level menu: "Wholesale"
        // Landing page = Dashboard
        // -----------------------------------------------------------
        add_menu_page(
            'Wholesale',
            'Wholesale',
            'manage_woocommerce',
            'slw-dashboard',
            array( 'SLW_Admin_Dashboard', 'render_page' ),
            $icon_svg,
            56
        );

        // -----------------------------------------------------------
        // Sub-pages — ordered by relevance for daily workflow
        // -----------------------------------------------------------

        // 1. Dashboard (landing page, replaces auto-generated first item)
        add_submenu_page( 'slw-dashboard', 'Wholesale Dashboard', 'Dashboard', 'manage_woocommerce', 'slw-dashboard', array( 'SLW_Admin_Dashboard', 'render_page' ) );

        // 2. Applications (most frequent daily action) — with pending count badge
        $pending_count = self::get_pending_application_count();
        $app_label = 'Applications';
        if ( $pending_count > 0 ) {
            $app_label .= ' <span class="awaiting-mod update-plugins count-' . $pending_count . '"><span class="pending-count">' . $pending_count . '</span></span>';
        }
        add_submenu_page( 'slw-dashboard', 'Wholesale Applications', $app_label, 'manage_woocommerce', 'slw-applications', array( 'SLW_Application_Form', 'render_admin_page' ) );

        // 3. Orders (reviewing wholesale orders)
        if ( class_exists( 'SLW_Wholesale_Orders' ) ) {
            add_submenu_page( 'slw-dashboard', 'Wholesale Orders', 'Orders', 'manage_woocommerce', 'slw-orders', array( 'SLW_Wholesale_Orders', 'render_page' ) );
        }

        // 4. Sequences (email campaigns + newsletters)
        if ( class_exists( 'SLW_Email_Sequences' ) ) {
            add_submenu_page( 'slw-dashboard', 'Email Sequences', 'Sequences', 'manage_woocommerce', 'slw-sequences', array( 'SLW_Email_Sequences', 'render_page' ) );
        }

        // 5. Quotes (RFQ management)
        if ( class_exists( 'SLW_RFQ' ) ) {
            add_submenu_page( 'slw-dashboard', 'Quote Requests', 'Quotes', 'manage_woocommerce', 'slw-rfq', array( 'SLW_RFQ', 'render_admin_page' ) );
        }

        // 6. Leads (prospect pipeline)
        if ( class_exists( 'SLW_Lead_Capture' ) ) {
            add_submenu_page( 'slw-dashboard', 'Lead Capture', 'Leads', 'manage_woocommerce', 'slw-leads', array( 'SLW_Lead_Capture', 'render_admin_page' ) );
        }

        // 7. Tiers (pricing tier configuration)
        if ( class_exists( 'SLW_Tier_Settings' ) ) {
            add_submenu_page( 'slw-dashboard', 'Wholesale Tiers', 'Tiers', 'manage_woocommerce', 'slw-tiers', array( 'SLW_Tier_Settings', 'render_page' ) );
        }

        // 8. Import (add customers)
        if ( class_exists( 'SLW_Premium_Features' ) ) {
            add_submenu_page( 'slw-dashboard', 'Import Wholesale Users', 'Import', 'manage_woocommerce', 'slw-import', array( 'SLW_Premium_Features', 'render_import_page' ) );
        }

        // 9. Preview (customer portal iframe)
        add_submenu_page( 'slw-dashboard', 'Portal Preview', 'Preview', 'manage_woocommerce', 'slw-preview', array( __CLASS__, 'render_preview_page' ) );

        // 10. Invoices (branding + email config — setup task, not daily)
        if ( class_exists( 'SLW_Invoice_Settings' ) ) {
            add_submenu_page( 'slw-dashboard', 'Invoice Settings', 'Invoices', 'manage_woocommerce', 'slw-invoice-settings', array( 'SLW_Invoice_Settings', 'render_page' ) );
        }

        // 11. Analytics (funnels + page intelligence)
        if ( class_exists( 'SLW_Analytics' ) ) {
            add_submenu_page( 'slw-dashboard', 'Analytics', 'Analytics', 'manage_woocommerce', 'slw-analytics', array( 'SLW_Analytics', 'render_page' ) );
        }

        // 12. Settings (discounts, minimums, NET terms — setup task)
        add_submenu_page( 'slw-dashboard', 'Wholesale Settings', 'Settings', 'manage_woocommerce', 'slw-settings', array( 'SLW_Settings', 'render_page' ) );

        // 13. Help (always last)
        add_submenu_page( 'slw-dashboard', 'Help & Resources', 'Help', 'manage_woocommerce', 'slw-docs', array( 'SLW_Docs', 'render_page' ) );
    }
}
