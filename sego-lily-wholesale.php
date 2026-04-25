<?php
/**
 * Plugin Name:       Wholesale Portal
 * Plugin URI:        https://github.com/louievillaverde/sego-lily-wholesale
 * Description:       All-in-one B2B wholesale portal for WooCommerce. Customer portal, tiered pricing, application workflow, PDF invoices, email sequences with multi-provider support, NET payment terms, lead capture, trade show tools, and automated order reminders. Built by Lead Piranha.
 * Version:           3.9.2
 * Author:            Lead Piranha
 * Author URI:        https://leadpiranha.com
 * Requires at least: 6.0
 * Tested up to:      6.9.4
 * Requires PHP:      7.4
 * WooCommerce requires at least: 8.0
 * License:           Proprietary
 * Text Domain:       sego-lily-wholesale
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'SLW_VERSION', '3.9.2' );
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

    // Warn if WooCommerce Wholesale Suite is also active — both plugins
    // register similar page slugs and hooks which causes admin page mix-ups.
    if ( class_exists( 'WooCommerceWholeSalePrices' ) || defined( 'WWPP_PLUGIN_DIR' ) || defined( 'WWP_PLUGIN_DIR' ) ) {
        add_action( 'admin_notices', function() {
            echo '<div class="notice notice-warning is-dismissible"><p>'
                . '<strong>Wholesale Portal:</strong> WooCommerce Wholesale Suite is also active. '
                . 'Both plugins manage wholesale pricing and may conflict. Please deactivate Wholesale Suite '
                . 'to avoid duplicate pricing rules and admin page conflicts.'
                . '</p></div>';
        });
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
    require_once SLW_PLUGIN_DIR . 'includes/class-docs.php';
    require_once SLW_PLUGIN_DIR . 'includes/class-wholesale-orders.php';
    require_once SLW_PLUGIN_DIR . 'includes/class-email-settings.php';
    require_once SLW_PLUGIN_DIR . 'includes/class-email-sequences.php';
    require_once SLW_PLUGIN_DIR . 'includes/class-encryption.php';
    require_once SLW_PLUGIN_DIR . 'includes/class-audit-log.php';
    require_once SLW_PLUGIN_DIR . 'includes/class-shipping-calculator.php';
    require_once SLW_PLUGIN_DIR . 'includes/class-product-recommendations.php';
    require_once SLW_PLUGIN_DIR . 'includes/class-nav-menu.php';
    require_once SLW_PLUGIN_DIR . 'includes/class-context-switcher.php';

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
    require_once SLW_PLUGIN_DIR . 'includes/class-customer-portal.php';
    require_once SLW_PLUGIN_DIR . 'includes/class-quiz-results.php';
    require_once SLW_PLUGIN_DIR . 'includes/class-email-approve.php';
    require_once SLW_PLUGIN_DIR . 'includes/class-wholesale-activate.php';
    require_once SLW_PLUGIN_DIR . 'includes/class-analytics.php';
    require_once SLW_PLUGIN_DIR . 'includes/class-referral-coupons.php';
    require_once SLW_PLUGIN_DIR . 'includes/class-referral-dashboard.php';
    require_once SLW_PLUGIN_DIR . 'includes/class-xero-compat.php';

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
    SLW_Docs::init();
    SLW_Wholesale_Orders::init();
    SLW_Email_Settings::init();
    SLW_Email_Sequences::init();
    SLW_Encryption::init();
    SLW_Audit_Log::init();
    SLW_Shipping_Calculator::init();
    SLW_Nav_Menu::init();
    SLW_Context_Switcher::init();
    SLW_Product_Recommendations::init();

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
    SLW_Customer_Portal::init();
    SLW_Quiz_Results::init();
    SLW_Email_Approve::init();
    SLW_Wholesale_Activate::init();
    SLW_Analytics::init();
    SLW_Referral_Coupons::init();
    SLW_Referral_Dashboard::init();
    SLW_Xero_Compat::init();

    // Enqueue frontend styles on pages that use our shortcodes
    add_action( 'wp_enqueue_scripts', function() {
        if ( is_page() || has_shortcode( get_post()->post_content ?? '', 'sego_wholesale_application' )
            || has_shortcode( get_post()->post_content ?? '', 'sego_wholesale_order_form' )
            || has_shortcode( get_post()->post_content ?? '', 'sego_wholesale_dashboard' )
            || has_shortcode( get_post()->post_content ?? '', 'sego_wholesale_rfq' )
            || has_shortcode( get_post()->post_content ?? '', 'wholesale_lead_capture' )
            || has_shortcode( get_post()->post_content ?? '', 'wholesale_lead_capture_quick' )
            || has_shortcode( get_post()->post_content ?? '', 'wholesale_portal' ) ) {
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
        'wholesale-booth' => array(
            'title'   => 'Wholesale Booth',
            'content' => '[wholesale_lead_capture_quick]',
        ),
        'wholesale-portal' => array(
            'title'   => 'Wholesale Portal',
            'content' => '[wholesale_portal]',
        ),
        'quiz-results' => array(
            'title'   => 'Your Skincare Results',
            'content' => '[slw_quiz_results]',
        ),
        'wholesale-activate' => array(
            'title'   => 'Activate Your Wholesale Account',
            'content' => '[slw_wholesale_activate]',
        ),
        'my-referrals' => array(
            'title'   => 'My Referral Codes',
            'content' => '[slw_my_referrals]',
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

    // Schedule the 2-hour cart abandon cron event
    if ( ! wp_next_scheduled( 'slw_cart_abandon_check' ) ) {
        wp_schedule_event( time(), 'every_two_hours', 'slw_cart_abandon_check' );
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

    // Unschedule the 2-hour cart abandon check
    $cart_timestamp = wp_next_scheduled( 'slw_cart_abandon_check' );
    if ( $cart_timestamp ) {
        wp_unschedule_event( $cart_timestamp, 'slw_cart_abandon_check' );
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
    global $wpdb;

    // ── 1. Tables: create if missing (checked independently) ──
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $charset_collate = $wpdb->get_charset_collate();

    $app_table = $wpdb->prefix . 'slw_applications';
    if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $app_table ) ) !== $app_table ) {
        dbDelta( "CREATE TABLE IF NOT EXISTS {$app_table} (
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
        ) {$charset_collate};" );
    }

    $leads_table = $wpdb->prefix . 'slw_leads';
    if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $leads_table ) ) !== $leads_table ) {
        dbDelta( "CREATE TABLE {$leads_table} (
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
        ) {$charset_collate};" );
    }

    // ── 2. Role: create if missing ──
    if ( ! get_role( 'wholesale_customer' ) ) {
        $customer_role = get_role( 'customer' );
        $caps = $customer_role ? $customer_role->capabilities : array( 'read' => true );
        add_role( 'wholesale_customer', 'Wholesale Customer', $caps );
    }

    // ── 3. Pages: create each individually if missing (no version flag needed) ──
    $required_pages = array(
        'wholesale-partners'  => array( 'Wholesale Partners',              '[sego_wholesale_application]' ),
        'wholesale-order'     => array( 'Wholesale Order Form',            '[sego_wholesale_order_form]' ),
        'wholesale-dashboard' => array( 'My Wholesale Account',            '[sego_wholesale_dashboard]' ),
        'wholesale-rfq'       => array( 'Request a Quote',                 '[sego_wholesale_rfq]' ),
        'wholesale-leads'     => array( 'Become a Wholesale Partner',      '[wholesale_lead_capture]' ),
        'wholesale-booth'     => array( 'Wholesale Booth',                 '[wholesale_lead_capture_quick]' ),
        'wholesale-portal'    => array( 'Wholesale Portal',                '[wholesale_portal]' ),
    );
    foreach ( $required_pages as $slug => $data ) {
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
    // No db_version flag needed — each check is independent.
    // Tables check via SHOW TABLES, pages check via get_page_by_path.
    // Runs on every admin_init but each check is a single cheap query
    // that short-circuits when the resource already exists.
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
    if ( ! $user ) {
        return false;
    }
    // Wholesale role OR administrator (admins can access all wholesale features)
    return in_array( 'wholesale_customer', (array) $user->roles, true )
        || in_array( 'administrator', (array) $user->roles, true );
}

/**
 * Helper: check if the current session is in wholesale shopping context.
 *
 * Returns true ONLY when the user has the wholesale_customer role AND the
 * WooCommerce session context is set to 'wholesale' (the default). When a
 * wholesale user switches to "For Myself" mode via the context switcher,
 * this returns false so they see retail pricing and no wholesale minimums.
 *
 * Use this instead of slw_is_wholesale_user() in pricing hooks, order
 * rules, and anywhere the shopping context matters. Keep using
 * slw_is_wholesale_user() for role-level checks (portal access, nav menu,
 * admin columns, etc.).
 */
function slw_is_wholesale_context( $user_id = null ) {
    if ( ! $user_id ) {
        $user_id = get_current_user_id();
    }
    if ( ! $user_id ) {
        return false;
    }
    $user = get_userdata( $user_id );
    if ( ! $user ) {
        return false;
    }
    // Only actual wholesale role holders get wholesale pricing — NOT admins
    // (admins can access wholesale pages but shop at retail pricing unless
    // they also have the wholesale_customer role)
    if ( ! in_array( 'wholesale_customer', (array) $user->roles, true ) ) {
        return false;
    }
    // Check WC session context
    if ( function_exists( 'WC' ) && WC()->session ) {
        $context = WC()->session->get( 'slw_shopping_context', 'wholesale' );
        return $context === 'wholesale';
    }
    return true; // Default to wholesale if no session available
}

/**
 * Helper: get a plugin setting with a fallback default.
 */
function slw_get_option( $key, $default = '' ) {
    $value = get_option( 'slw_' . $key );
    return ( $value !== false && $value !== '' ) ? $value : $default;
}
