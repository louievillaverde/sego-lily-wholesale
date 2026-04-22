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
     * Render the admin preview page with an iframe pointing to the customer portal.
     */
    public static function render_preview_page() {
        $portal_url = home_url( '/wholesale-portal?slw_preview=1' );
        $tab = isset( $_GET['portal_tab'] ) ? sanitize_key( $_GET['portal_tab'] ) : '';
        if ( $tab ) {
            $portal_url = add_query_arg( 'tab', $tab, $portal_url );
        }
        ?>
        <div class="wrap">
            <h1>Customer Portal Preview</h1>
            <p class="description">This shows the wholesale customer portal exactly as your wholesale partners see it. Use the tabs inside the portal to navigate between sections.</p>
            <div style="margin-top:16px;">
                <iframe
                    src="<?php echo esc_url( $portal_url ); ?>"
                    style="width:100%;min-height:800px;border:1px solid #ddd;border-radius:6px;background:#fff;"
                    id="slw-portal-preview-iframe"
                ></iframe>
            </div>
        </div>
        <script>
        (function() {
            var iframe = document.getElementById('slw-portal-preview-iframe');
            if (!iframe) return;
            // Auto-resize iframe to content height
            function resizeIframe() {
                try {
                    var body = iframe.contentWindow.document.body;
                    var html = iframe.contentWindow.document.documentElement;
                    var height = Math.max(
                        body.scrollHeight, body.offsetHeight,
                        html.clientHeight, html.scrollHeight, html.offsetHeight
                    );
                    iframe.style.height = Math.max(height + 40, 800) + 'px';
                } catch(e) {}
            }
            iframe.addEventListener('load', function() {
                resizeIframe();
                // Re-check after images load
                setTimeout(resizeIframe, 1000);
                setTimeout(resizeIframe, 3000);
            });
        })();
        </script>
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
        // Sub-pages — ORDER MATTERS (this is the sidebar order)
        // -----------------------------------------------------------

        // 1. Dashboard (replaces auto-generated first item)
        add_submenu_page(
            'slw-dashboard',
            'Wholesale Dashboard',
            'Dashboard',
            'manage_woocommerce',
            'slw-dashboard',
            array( 'SLW_Admin_Dashboard', 'render_page' )
        );

        // 2. Applications
        add_submenu_page(
            'slw-dashboard',
            'Wholesale Applications',
            'Applications',
            'manage_woocommerce',
            'slw-applications',
            array( 'SLW_Application_Form', 'render_admin_page' )
        );

        // 2b. Email Sequences
        if ( class_exists( 'SLW_Email_Sequences' ) ) {
            add_submenu_page(
                'slw-dashboard',
                'Email Sequences',
                'Sequences',
                'manage_woocommerce',
                'slw-sequences',
                array( 'SLW_Email_Sequences', 'render_page' )
            );
        }

        // 3. Orders
        if ( class_exists( 'SLW_Wholesale_Orders' ) ) {
            add_submenu_page(
                'slw-dashboard',
                'Wholesale Orders',
                'Orders',
                'manage_woocommerce',
                'slw-orders',
                array( 'SLW_Wholesale_Orders', 'render_page' )
            );
        }

        // 3b. Preview (customer portal iframe)
        add_submenu_page(
            'slw-dashboard',
            'Portal Preview',
            'Preview',
            'manage_woocommerce',
            'slw-preview',
            array( __CLASS__, 'render_preview_page' )
        );

        // 4. Quote Requests
        if ( class_exists( 'SLW_RFQ' ) ) {
            add_submenu_page(
                'slw-dashboard',
                'Quote Requests',
                'Quotes',
                'manage_woocommerce',
                'slw-rfq',
                array( 'SLW_RFQ', 'render_admin_page' )
            );
        }

        // 4. Tiers
        if ( class_exists( 'SLW_Tier_Settings' ) ) {
            add_submenu_page(
                'slw-dashboard',
                'Wholesale Tiers',
                'Tiers',
                'manage_woocommerce',
                'slw-tiers',
                array( 'SLW_Tier_Settings', 'render_page' )
            );
        }

        // 5. Lead Capture
        if ( class_exists( 'SLW_Lead_Capture' ) ) {
            add_submenu_page(
                'slw-dashboard',
                'Lead Capture',
                'Leads',
                'manage_woocommerce',
                'slw-leads',
                array( 'SLW_Lead_Capture', 'render_admin_page' )
            );
        }

        // 6. Import Users
        if ( class_exists( 'SLW_Premium_Features' ) ) {
            add_submenu_page(
                'slw-dashboard',
                'Import Wholesale Users',
                'Import',
                'manage_woocommerce',
                'slw-import',
                array( 'SLW_Premium_Features', 'render_import_page' )
            );
        }

        // 7. Settings
        add_submenu_page(
            'slw-dashboard',
            'Wholesale Settings',
            'Settings',
            'manage_woocommerce',
            'slw-settings',
            array( 'SLW_Settings', 'render_page' )
        );

        // 8. Invoices
        if ( class_exists( 'SLW_Invoice_Settings' ) ) {
            add_submenu_page(
                'slw-dashboard',
                'Invoice Settings',
                'Invoices',
                'manage_woocommerce',
                'slw-invoice-settings',
                array( 'SLW_Invoice_Settings', 'render_page' )
            );
        }

        // 9. Help & Resources (always last)
        add_submenu_page(
            'slw-dashboard',
            'Help & Resources',
            'Help',
            'manage_woocommerce',
            'slw-docs',
            array( 'SLW_Docs', 'render_page' )
        );
    }
}
