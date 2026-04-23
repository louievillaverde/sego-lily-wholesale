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
            array( 'title' => 'Partner Login',       'url' => wp_login_url( home_url( '/wholesale-portal' ) ) ),
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
