<?php
/**
 * Dynamic Wholesale Navigation Menu
 *
 * Automatically replaces the sub-items under any menu item titled
 * "Wholesale" based on the visitor's login state and wholesale role.
 *
 * Uses both wp_nav_menu_objects AND wp_get_nav_menu_items to ensure
 * compatibility with Elementor's nav widget which may bypass the
 * standard wp_nav_menu_objects filter.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class SLW_Nav_Menu {

    public static function init() {
        // Standard WordPress menu filter
        add_filter( 'wp_nav_menu_objects', array( __CLASS__, 'filter_menu_items' ), 999, 2 );

        // Earlier filter that Elementor may use
        add_filter( 'wp_get_nav_menu_items', array( __CLASS__, 'filter_nav_menu_items' ), 999, 3 );

        // Redirect wholesale users to portal after login
        add_filter( 'woocommerce_login_redirect', array( __CLASS__, 'login_redirect' ), 10, 2 );
        add_filter( 'login_redirect', array( __CLASS__, 'login_redirect' ), 10, 3 );

        // Rewrite the My Account page URL to the wholesale portal at the
        // get_permalink layer (catches the header account icon when it's
        // wired through the page_link filter).
        add_filter( 'page_link', array( __CLASS__, 'rewrite_account_link' ), 10, 2 );

        // Server-side fallback. When a wholesale user actually lands on
        // /my-account (because the icon hardcoded the URL, or they typed
        // it, or some Elementor widget bypassed page_link), redirect them
        // to /wholesale-portal before WC renders. Covers every entry point
        // regardless of how the icon was built.
        add_action( 'template_redirect', array( __CLASS__, 'redirect_account_to_portal' ) );

        // Wholesale checkout routing: wholesale customers hitting the
        // theme-built /checkout (often Elementor) get redirected to
        // /wholesale-checkout which uses the native WC checkout shortcode
        // and therefore respects apply_wholesale_price on every line item.
        add_action( 'template_redirect', array( __CLASS__, 'redirect_checkout_for_wholesale' ) );

        // Same problem for /cart: theme-built cart page often doesn't
        // apply wholesale prices, so wholesale users go to the wholesale
        // order form (which acts as their cart).
        add_action( 'template_redirect', array( __CLASS__, 'redirect_cart_for_wholesale' ) );

        // Rewrite cart-page links upfront so theme cart icons go to the
        // right URL for wholesale users at click time.
        add_filter( 'page_link', array( __CLASS__, 'rewrite_cart_link' ), 10, 2 );
        add_filter( 'woocommerce_get_cart_url', array( __CLASS__, 'rewrite_wc_cart_url' ), 999 );

        // Self-heal: make sure the wholesale-checkout page exists. Plugin
        // activation creates it, but existing installs need this fallback.
        add_action( 'admin_init', array( __CLASS__, 'maybe_create_wholesale_checkout_page' ) );
    }

    /**
     * Send wholesale customers from /cart to /wholesale-order so they
     * stay on the wholesale-aware order form / cart surface.
     */
    public static function redirect_cart_for_wholesale() {
        if ( is_admin() ) return;
        if ( ! is_user_logged_in() ) return;
        if ( ! function_exists( 'slw_is_wholesale_user' ) || ! slw_is_wholesale_user() ) return;
        if ( ! function_exists( 'is_cart' ) || ! is_cart() ) return;
        $order_form = get_page_by_path( 'wholesale-order' );
        if ( ! $order_form ) return;
        if ( is_page( $order_form->ID ) ) return;
        wp_safe_redirect( get_permalink( $order_form->ID ) );
        exit;
    }

    /**
     * page_link filter: rewrite the WC cart page URL to /wholesale-order
     * for wholesale customers so theme cart icons resolve to the right URL.
     */
    public static function rewrite_cart_link( $link, $post_id ) {
        if ( is_admin() ) return $link;
        if ( ! is_user_logged_in() ) return $link;
        if ( ! function_exists( 'slw_is_wholesale_user' ) || ! slw_is_wholesale_user() ) return $link;
        $cart_page_id = (int) get_option( 'woocommerce_cart_page_id' );
        if ( ! $cart_page_id || (int) $post_id !== $cart_page_id ) return $link;
        $order_form = get_page_by_path( 'wholesale-order' );
        return $order_form ? get_permalink( $order_form->ID ) : $link;
    }

    /**
     * wc_get_cart_url filter: same rewrite for any WC-internal cart URL
     * lookup (e.g. nav menu items configured via WC settings, mini-cart
     * widget links). Skipped on admin and during checkout/order-pay so
     * WC's internal redirect chain stays intact.
     */
    public static function rewrite_wc_cart_url( $url ) {
        if ( is_admin() ) return $url;
        if ( ! is_user_logged_in() ) return $url;
        if ( ! function_exists( 'slw_is_wholesale_user' ) || ! slw_is_wholesale_user() ) return $url;
        // Don't mess with cart URLs WC uses internally during checkout flow
        if ( defined( 'WOOCOMMERCE_CHECKOUT' ) || ( function_exists( 'is_checkout' ) && is_checkout() ) ) return $url;
        $order_form = get_page_by_path( 'wholesale-order' );
        return $order_form ? get_permalink( $order_form->ID ) : $url;
    }

    /**
     * Send wholesale customers from /checkout to /wholesale-checkout so the
     * native WC checkout renders (Elementor-built checkout was silently
     * dropping the wholesale discount).
     */
    public static function redirect_checkout_for_wholesale() {
        if ( is_admin() ) return;
        if ( ! is_user_logged_in() ) return;
        if ( ! function_exists( 'slw_is_wholesale_user' ) || ! slw_is_wholesale_user() ) return;
        if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) return;
        // Don't redirect order-received / pay endpoints
        if ( function_exists( 'is_wc_endpoint_url' ) && (
                is_wc_endpoint_url( 'order-received' ) ||
                is_wc_endpoint_url( 'order-pay' )
        ) ) return;
        $wholesale = get_page_by_path( 'wholesale-checkout' );
        if ( ! $wholesale ) return;
        if ( is_page( $wholesale->ID ) ) return; // already there
        wp_safe_redirect( get_permalink( $wholesale->ID ) );
        exit;
    }

    /**
     * Idempotent self-heal: create the /wholesale-checkout page if it
     * doesn't exist. Existing installs that updated past v4.6.72 without
     * deactivating + reactivating need this.
     */
    public static function maybe_create_wholesale_checkout_page() {
        $version_key = 'slw_wholesale_checkout_shortcode_v2';
        if ( get_option( $version_key ) ) return;
        $existing = get_page_by_path( 'wholesale-checkout' );
        if ( ! $existing ) {
            wp_insert_post( array(
                'post_title'   => 'Wholesale Checkout',
                'post_content' => '[sego_wholesale_checkout]',
                'post_status'  => 'publish',
                'post_type'    => 'page',
                'post_name'    => 'wholesale-checkout',
            ));
        } else {
            // Migrate to the custom shortcode if the page is still on the
            // bare [woocommerce_checkout]. Only rewrites pages that haven't
            // been hand-edited away from the native shortcode.
            $current = trim( $existing->post_content );
            if ( $current === '[woocommerce_checkout]' || $current === '' ) {
                wp_update_post( array(
                    'ID'           => $existing->ID,
                    'post_content' => '[sego_wholesale_checkout]',
                ));
            }
        }
        update_option( $version_key, '1', true );
    }

    /**
     * Server-side catch-all. Sends both wholesale customers AND admins
     * hitting the WooCommerce 'My Account' page over to /wholesale-portal.
     *
     * Admins are intentionally included after Holly call 2026-05-29: she
     * kept landing on the retail member-portal view and wondered why the
     * wholesale options weren't there. Routing admins to the portal too
     * keeps the surface they see consistent with what real wholesale
     * customers see. They still have wp-admin for everything else; the
     * logout endpoint stays reachable so they can sign out.
     *
     * Escape hatch: ?as_member=1 in the URL bypasses the redirect for
     * troubleshooting. Honored only when an admin has the capability,
     * so a wholesale customer can't bookmark their way out.
     */
    public static function redirect_account_to_portal() {
        if ( is_admin() ) return;
        if ( ! is_user_logged_in() ) return;
        if ( ! function_exists( 'is_account_page' ) || ! is_account_page() ) return;
        if ( is_wc_endpoint_url( 'customer-logout' ) ) return;

        $is_wholesale = function_exists( 'slw_is_wholesale_user' ) && slw_is_wholesale_user();
        $is_admin     = current_user_can( 'manage_woocommerce' );
        if ( ! $is_wholesale && ! $is_admin ) return;

        // Admin troubleshooting escape hatch
        if ( $is_admin && ! empty( $_GET['as_member'] ) ) return;

        wp_safe_redirect( home_url( '/wholesale-portal' ) );
        exit;
    }

    /**
     * Point the My Account page link at the wholesale portal for logged-in
     * wholesale users, so the header account icon goes to the right place.
     */
    public static function rewrite_account_link( $link, $post_id ) {
        if ( is_admin() ) {
            return $link;
        }
        $account_page_id = (int) get_option( 'woocommerce_myaccount_page_id' );
        if ( ! $account_page_id || (int) $post_id !== $account_page_id || ! is_user_logged_in() ) {
            return $link;
        }
        // Wholesale customer OR admin -- both routed to the wholesale portal
        // for surface consistency (Holly call 2026-05-29).
        $is_wholesale = function_exists( 'slw_is_wholesale_user' ) && slw_is_wholesale_user();
        $is_admin     = current_user_can( 'manage_woocommerce' );
        if ( $is_wholesale || $is_admin ) {
            return home_url( '/wholesale-portal' );
        }
        return $link;
    }

    /**
     * After login, send wholesale users to the portal instead of My Account.
     */
    public static function login_redirect( $redirect, $user = null ) {
        // WooCommerce passes $user as 2nd arg, WordPress login_redirect passes it as 3rd
        if ( ! $user && func_num_args() >= 3 ) {
            $user = func_get_arg( 2 );
        }
        if ( is_string( $user ) ) {
            $user = get_user_by( 'login', $user );
        }
        if ( $user && is_object( $user ) && in_array( 'wholesale_customer', (array) ( $user->roles ?? array() ), true ) ) {
            return home_url( '/wholesale-portal' );
        }
        return $redirect;
    }

    /**
     * Get the replacement children based on user state.
     */
    private static function get_children() {
        $is_wholesale = is_user_logged_in() && function_exists( 'slw_is_wholesale_user' ) && slw_is_wholesale_user();

        if ( $is_wholesale ) {
            return array(
                array( 'title' => 'My Portal',       'url' => home_url( '/wholesale-portal' ) ),
                array( 'title' => 'Order Form',      'url' => home_url( '/wholesale-order' ) ),
                array( 'title' => 'My Dashboard',    'url' => home_url( '/wholesale-dashboard' ) ),
                array( 'title' => 'Request a Quote', 'url' => home_url( '/wholesale-rfq' ) ),
            );
        }

        return array(
            array( 'title' => 'Apply for Wholesale', 'url' => home_url( '/wholesale-partners' ) ),
            array( 'title' => 'Partner Login',       'url' => home_url( '/my-account' ) ),
        );
    }

    /**
     * Build a mock menu item object.
     */
    private static function make_item( $child, $parent_id, $order ) {
        $obj = new stdClass();
        $obj->ID                      = 900000 + $order;
        $obj->db_id                   = $obj->ID;
        $obj->title                   = $child['title'];
        $obj->url                     = $child['url'];
        $obj->menu_item_parent        = (string) $parent_id;
        $obj->menu_order              = $order;
        $obj->type                    = 'custom';
        $obj->type_label              = 'Custom Link';
        $obj->object                  = 'custom';
        $obj->object_id               = (string) $obj->ID;
        $obj->target                  = '';
        $obj->attr_title              = '';
        $obj->description             = '';
        $obj->classes                 = array( 'menu-item', 'menu-item-type-custom', 'menu-item-object-custom' );
        $obj->xfn                     = '';
        $obj->current                 = false;
        $obj->current_item_ancestor   = false;
        $obj->current_item_parent     = false;
        $obj->post_type               = 'nav_menu_item';
        $obj->post_status             = 'publish';
        $obj->post_parent             = 0;
        $obj->post_title              = $child['title'];
        $obj->post_name               = sanitize_title( $child['title'] );

        // Highlight current page
        $request_uri = $_SERVER['REQUEST_URI'] ?? '';
        $current_path = rtrim( strtok( $request_uri, '?' ), '/' );
        $child_path   = rtrim( wp_parse_url( $child['url'], PHP_URL_PATH ), '/' );
        if ( $current_path === $child_path ) {
            $obj->current = true;
            $obj->classes[] = 'current-menu-item';
        }

        return $obj;
    }

    /**
     * Filter via wp_nav_menu_objects (standard WP menus).
     */
    public static function filter_menu_items( $items, $args ) {
        return self::do_filter( $items );
    }

    /**
     * Filter via wp_get_nav_menu_items (Elementor compatibility).
     */
    public static function filter_nav_menu_items( $items, $menu, $args ) {
        return self::do_filter( $items );
    }

    /**
     * Core filter logic shared by both hooks.
     */
    private static function do_filter( $items ) {
        if ( empty( $items ) || ! is_array( $items ) ) {
            return $items;
        }

        // Don't run in admin
        if ( is_admin() ) {
            return $items;
        }

        // Find the wholesale parent
        $parent_id  = null;
        $parent_key = null;
        foreach ( $items as $key => $item ) {
            $title = strtolower( trim( $item->title ?? $item->post_title ?? '' ) );
            $parent = $item->menu_item_parent ?? '0';
            if ( $title === 'wholesale' && ( $parent === '0' || $parent === 0 || $parent === '' ) ) {
                $parent_id  = $item->ID ?? $item->db_id;
                $parent_key = $key;
                break;
            }
        }

        if ( ! $parent_id ) {
            return $items;
        }

        // Remove existing children
        $filtered = array();
        foreach ( $items as $item ) {
            $item_parent = (string) ( $item->menu_item_parent ?? '0' );
            if ( $item_parent !== (string) $parent_id ) {
                $filtered[] = $item;
            }
        }

        // Add dynamic children
        $children = self::get_children();
        $order = 9000;
        foreach ( $children as $child ) {
            $filtered[] = self::make_item( $child, $parent_id, $order++ );
        }

        return $filtered;
    }
}
