<?php
/**
 * Plugin Name:       Wholesale Portal
 * Plugin URI:        https://github.com/louievillaverde/sego-lily-wholesale
 * Description:       Turn any WooCommerce store into a full B2B wholesale operation. Tiered wholesale pricing, application-based onboarding, order minimums, NET payment terms, tax exemption, customizable PDF invoices, downloadable line sheets, request-for-quote, automated reorder reminders, lead capture, bulk user import, and CRM webhook integration. Built by Lead Piranha.
 * Version:           2.3.0
 * Author:            Lead Piranha
 * Author URI:        https://leadpiranha.com
 * Requires at least: 6.0
 * Tested up to:      6.9.4
 * Requires PHP:      7.4
 * WooCommerce requires at least: 8.0
 * License:           Proprietary
 * Text Domain:       sego-lily-wholesale
 *
 * Git Updater compatibility headers. When Git Updater is installed, it
 * watches the GitHub repo below and surfaces new releases in the native
 * WordPress "Plugins > Updates" screen. Holly clicks "Update Now" like
 * any other plugin and the new version installs automatically. Her data
 * (users, applications, orders, settings) is preserved across updates.
 *
 * GitHub Plugin URI: louievillaverde/sego-lily-wholesale
 * Primary Branch:    main
 * Release Asset:     true
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'SLW_VERSION', '2.3.0' );
define( 'SLW_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SLW_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Declare HPOS (High-Performance Order Storage) compatibility so WooCommerce
 * doesn't show a warning in the plugins list.
 */
add_action( 'before_woocommerce_init', function() {
    if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
    }
});

/**
 * Only boot the plugin when WooCommerce is active. Without WooCommerce,
 * wholesale pricing and cart rules have nothing to hook into.
 */
add_action( 'plugins_loaded', function() {
    if ( ! class_exists( 'WooCommerce' ) ) {
        add_action( 'admin_notices', function() {
            echo '<div class="notice notice-error"><p><strong>Wholesale Portal</strong> requires WooCommerce to be installed and active.</p></div>';
        });
        return;
    }

    // Load all modules — core
    require_once SLW_PLUGIN_DIR . 'includes/class-wholesale-role.php';
    require_once SLW_PLUGIN_DIR . 'includes/class-settings.php';
    require_once SLW_PLUGIN_DIR . 'includes/class-application-form.php';
    require_once SLW_PLUGIN_DIR . 'includes/class-order-rules.php';
    require_once SLW_PLUGIN_DIR . 'includes/class-order-form.php';
    require_once SLW_PLUGIN_DIR . 'includes/class-dashboard.php';
    require_once SLW_PLUGIN_DIR . 'includes/class-webhooks.php';
    require_once SLW_PLUGIN_DIR . 'includes/class-premium-features.php';
    require_once SLW_PLUGIN_DIR . 'includes/class-updater.php';
    require_once SLW_PLUGIN_DIR . 'includes/class-admin-menu.php';
    require_once SLW_PLUGIN_DIR . 'includes/class-admin-dashboard.php';
    require_once SLW_PLUGIN_DIR . 'includes/class-lead-capture.php';
    require_once SLW_PLUGIN_DIR . 'includes/class-help.php';
    require_once SLW_PLUGIN_DIR . 'includes/class-wholesale-orders.php';
    require_once SLW_PLUGIN_DIR . 'includes/class-email-settings.php';

    // Load v2.0 modules — tiers, invoices, reminders, RFQ
    require_once SLW_PLUGIN_DIR . 'includes/class-tiers.php';
    require_once SLW_PLUGIN_DIR . 'includes/class-tier-settings.php';
    require_once SLW_PLUGIN_DIR . 'includes/class-product-minimums.php';
    require_once SLW_PLUGIN_DIR . 'includes/class-customer-groups.php';
    require_once SLW_PLUGIN_DIR . 'includes/class-invoice-settings.php';
    require_once SLW_PLUGIN_DIR . 'includes/class-pdf-invoices.php';
    require_once SLW_PLUGIN_DIR . 'includes/class-pdf-linesheet.php';
    require_once SLW_PLUGIN_DIR . 'includes/class-new-arrivals.php';
    require_once SLW_PLUGIN_DIR . 'includes/class-reminders.php';
    require_once SLW_PLUGIN_DIR . 'includes/class-rfq.php';

    // Initialize — core
    SLW_Wholesale_Role::init();
    SLW_Settings::init();
    SLW_Application_Form::init();
    SLW_Order_Rules::init();
    SLW_Order_Form::init();
    SLW_Dashboard::init();
    SLW_Webhooks::init();
    SLW_Premium_Features::init();
    SLW_Updater::init();
    SLW_Admin_Menu::init();
    SLW_Admin_Dashboard::init();
    SLW_Lead_Capture::init();
    SLW_Help::init();
    SLW_Wholesale_Orders::init();
    SLW_Email_Settings::init();

    // Initialize — v2.0 modules (order matters: tiers before groups)
    SLW_Tiers::init();
    SLW_Tier_Settings::init();
    SLW_Product_Minimums::init();
    SLW_Customer_Groups::init();
    SLW_Invoice_Settings::init();
    SLW_PDF_Invoices::init();
    SLW_PDF_Linesheet::init();
    SLW_New_Arrivals::init();
    SLW_Reminders::init();
    SLW_RFQ::init();

    // Enqueue frontend styles on pages that use our shortcodes
    add_action( 'wp_enqueue_scripts', function() {
        if ( is_page() || has_shortcode( get_post()->post_content ?? '', 'sego_wholesale_application' )
            || has_shortcode( get_post()->post_content ?? '', 'sego_wholesale_order_form' )
            || has_shortcode( get_post()->post_content ?? '', 'sego_wholesale_dashboard' )
            || has_shortcode( get_post()->post_content ?? '', 'sego_wholesale_rfq' )
            || has_shortcode( get_post()->post_content ?? '', 'wholesale_lead_capture' ) ) {
            wp_enqueue_style(
                'sego-lily-wholesale',
                SLW_PLUGIN_URL . 'assets/sego-lily-wholesale.css',
                array(),
                SLW_VERSION
            );
        }
    });
});

/**
 * Activation: create the wholesale role and auto-generate pages with shortcodes
 * so Holly doesn't have to create them manually.
 */
register_activation_hook( __FILE__, function() {
    // Create wholesale customer role with the same caps as WooCommerce "customer"
    if ( ! get_role( 'wholesale_customer' ) ) {
        $customer_role = get_role( 'customer' );
        $caps = $customer_role ? $customer_role->capabilities : array( 'read' => true );
        add_role( 'wholesale_customer', 'Wholesale Customer', $caps );
    }

    // Create required pages if they don't exist yet
    $pages = array(
        'wholesale-partners' => array(
            'title'   => 'Wholesale Partners',
            'content' => '[sego_wholesale_application]',
        ),
        'wholesale-order' => array(
            'title'   => 'Wholesale Order Form',
            'content' => '[sego_wholesale_order_form]',
        ),
        'wholesale-dashboard' => array(
            'title'   => 'My Wholesale Account',
            'content' => '[sego_wholesale_dashboard]',
        ),
        'wholesale-rfq' => array(
            'title'   => 'Request a Quote',
            'content' => '[sego_wholesale_rfq]',
        ),
        'wholesale-leads' => array(
            'title'   => 'Become a Wholesale Partner',
            'content' => '[wholesale_lead_capture]',
        ),
    );

    foreach ( $pages as $slug => $page_data ) {
        $existing = get_page_by_path( $slug );
        if ( ! $existing ) {
            wp_insert_post( array(
                'post_title'   => $page_data['title'],
                'post_content' => $page_data['content'],
                'post_status'  => 'publish',
                'post_type'    => 'page',
                'post_name'    => $slug,
            ));
        }
    }

    // Create the applications database table
    global $wpdb;
    $table = $wpdb->prefix . 'slw_applications';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS {$table} (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        business_name VARCHAR(255) NOT NULL,
        contact_name VARCHAR(255) NOT NULL,
        email VARCHAR(255) NOT NULL,
        phone VARCHAR(50) NOT NULL,
        address TEXT NOT NULL,
        website VARCHAR(255) DEFAULT '',
        ein VARCHAR(100) NOT NULL,
        business_type VARCHAR(100) NOT NULL,
        how_heard TEXT DEFAULT '',
        why_carry TEXT DEFAULT '',
        status VARCHAR(20) DEFAULT 'pending',
        ip_address VARCHAR(45) DEFAULT '',
        submitted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        reviewed_at DATETIME DEFAULT NULL,
        reviewed_by BIGINT(20) UNSIGNED DEFAULT NULL,
        PRIMARY KEY (id),
        KEY status (status),
        KEY email (email)
    ) {$charset_collate};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );

    // Create the leads table
    $leads_table = $wpdb->prefix . 'slw_leads';
    $leads_sql = "CREATE TABLE {$leads_table} (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        name VARCHAR(255) NOT NULL,
        email VARCHAR(255) NOT NULL,
        business_name VARCHAR(255) DEFAULT '',
        phone VARCHAR(50) DEFAULT '',
        how_heard TEXT DEFAULT '',
        source VARCHAR(50) DEFAULT 'shortcode',
        status VARCHAR(20) DEFAULT 'new',
        captured_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        notes TEXT DEFAULT '',
        PRIMARY KEY (id),
        KEY status (status),
        KEY email (email)
    ) {$charset_collate};";
    dbDelta( $leads_sql );

    // Set default options
    if ( get_option( 'slw_discount_percent' ) === false ) {
        update_option( 'slw_discount_percent', 50 );
    }
    if ( get_option( 'slw_first_order_minimum' ) === false ) {
        update_option( 'slw_first_order_minimum', 300 );
    }
    if ( get_option( 'slw_reorder_minimum' ) === false ) {
        update_option( 'slw_reorder_minimum', 0 );
    }
    if ( get_option( 'slw_webhook_url' ) === false ) {
        update_option( 'slw_webhook_url', '' );
    }

    flush_rewrite_rules();
});

/**
 * Deactivation: flush rewrite rules and unschedule cron, but do NOT delete data.
 * Roles, users, orders, and applications all survive deactivation.
 */
register_deactivation_hook( __FILE__, function() {
    // Unschedule the daily reorder reminder check
    $timestamp = wp_next_scheduled( 'slw_daily_reorder_check' );
    if ( $timestamp ) {
        wp_unschedule_event( $timestamp, 'slw_daily_reorder_check' );
    }
    flush_rewrite_rules();
});

/**
 * Self-healing table check. Runs on every admin page load. If the
 * applications table does not exist (because the activation hook failed,
 * or the plugin was overwritten via FTP while active), creates it now.
 *
 * This means the plugin works even when activation hooks do not fire.
 * Cheap: one SHOW TABLES query per admin page load, skipped entirely
 * once the table exists (option cached in memory).
 */
add_action( 'admin_init', function() {
    if ( get_option( 'slw_db_version' ) === '1.1' ) {
        return;  // already verified
    }
    global $wpdb;
    $table = $wpdb->prefix . 'slw_applications';
    $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
    if ( $exists !== $table ) {
        // Table missing. Create it now.
        $charset_collate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE IF NOT EXISTS {$table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            business_name VARCHAR(255) NOT NULL,
            contact_name VARCHAR(255) NOT NULL,
            email VARCHAR(255) NOT NULL,
            phone VARCHAR(50) NOT NULL,
            address TEXT NOT NULL,
            website VARCHAR(255) DEFAULT '',
            ein VARCHAR(100) NOT NULL,
            business_type VARCHAR(100) NOT NULL,
            how_heard TEXT DEFAULT '',
            why_carry TEXT DEFAULT '',
            status VARCHAR(20) DEFAULT 'pending',
            ip_address VARCHAR(45) DEFAULT '',
            submitted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            reviewed_at DATETIME DEFAULT NULL,
            reviewed_by BIGINT(20) UNSIGNED DEFAULT NULL,
            PRIMARY KEY (id),
            KEY status (status),
            KEY email (email)
        ) {$charset_collate};";
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );

        // Leads table
        $leads_table = $wpdb->prefix . 'slw_leads';
        $leads_sql = "CREATE TABLE {$leads_table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(255) NOT NULL,
            email VARCHAR(255) NOT NULL,
            business_name VARCHAR(255) DEFAULT '',
            phone VARCHAR(50) DEFAULT '',
            how_heard TEXT DEFAULT '',
            source VARCHAR(50) DEFAULT 'shortcode',
            status VARCHAR(20) DEFAULT 'new',
            captured_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            notes TEXT DEFAULT '',
            PRIMARY KEY (id),
            KEY status (status),
            KEY email (email)
        ) {$charset_collate};";
        dbDelta( $leads_sql );

        // Also ensure role + pages exist in case activation never ran
        if ( ! get_role( 'wholesale_customer' ) ) {
            $customer_role = get_role( 'customer' );
            $caps = $customer_role ? $customer_role->capabilities : array( 'read' => true );
            add_role( 'wholesale_customer', 'Wholesale Customer', $caps );
        }
        foreach ( array(
            'wholesale-partners'  => array( 'Wholesale Partners',    '[sego_wholesale_application]' ),
            'wholesale-order'     => array( 'Wholesale Order Form',  '[sego_wholesale_order_form]' ),
            'wholesale-dashboard' => array( 'My Wholesale Account',  '[sego_wholesale_dashboard]' ),
            'wholesale-rfq'       => array( 'Request a Quote',       '[sego_wholesale_rfq]' ),
            'wholesale-leads'     => array( 'Become a Wholesale Partner', '[wholesale_lead_capture]' ),
        ) as $slug => $data ) {
            if ( ! get_page_by_path( $slug ) ) {
                wp_insert_post( array(
                    'post_title'   => $data[0],
                    'post_content' => $data[1],
                    'post_status'  => 'publish',
                    'post_type'    => 'page',
                    'post_name'    => $slug,
                ));
            }
        }
    }
    update_option( 'slw_db_version', '1.1' );
});

/**
 * Clean up the dismissed-notice user meta from pre-1.4.0 versions that
 * showed a "please install Git Updater" nag. The plugin now has a built-in
 * updater (SLW_Updater) that hooks into the native WP update system, so
 * no third-party git plugin is required. This cleanup runs once per user
 * and is harmless if the meta key doesn't exist.
 */
add_action( 'admin_init', function() {
    if ( get_user_meta( get_current_user_id(), 'slw_dismissed_git_updater_notice', true ) ) {
        delete_user_meta( get_current_user_id(), 'slw_dismissed_git_updater_notice' );
    }
}, 99 );

/**
 * Helper: check if the current user (or a given user) has the wholesale role.
 */
function slw_is_wholesale_user( $user_id = null ) {
    if ( ! $user_id ) {
        $user_id = get_current_user_id();
    }
    if ( ! $user_id ) {
        return false;
    }
    $user = get_userdata( $user_id );
    return $user && in_array( 'wholesale_customer', (array) $user->roles, true );
}

/**
 * Helper: get a plugin setting with a fallback default.
 */
function slw_get_option( $key, $default = '' ) {
    $value = get_option( 'slw_' . $key );
    return ( $value !== false && $value !== '' ) ? $value : $default;
}
