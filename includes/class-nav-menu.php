<?php
/**
 * Dynamic Wholesale Navigation Menu
 *
 * Automatically replaces the sub-items under any menu item linking to
 * /wholesale-partners (or with "wholesale" as a custom link) based on
 * the visitor's login state and wholesale role.
 *
 * Visitors / retail customers see:
 *   Apply for Wholesale → /wholesale-partners
 *   Partner Login       → /my-account
 *
 * Logged-in wholesale partners see:
 *   My Portal       → /wholesale-portal
 *   Order Form      → /wholesale-order
 *   My Dashboard    → /wholesale-dashboard
 *   Request a Quote → /wholesale-rfq
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class SLW_Nav_Menu {

    public static function init() {
        add_filter( 'wp_nav_menu_objects', array( __CLASS__, 'filter_menu_items' ), 10, 2 );
    }

    /**
     * Filter nav menu items. Find the "Wholesale" parent item and replace
     * its children based on the current user's role.
     */
    public static function filter_menu_items( $items, $args ) {
        // Find the wholesale parent menu item
        $wholesale_parent_id = null;
        foreach ( $items as $item ) {
            $title_lower = strtolower( trim( $item->title ) );
            if ( $title_lower === 'wholesale' && (int) $item->menu_item_parent === 0 ) {
                $wholesale_parent_id = $item->ID;
                break;
            }
        }

        if ( ! $wholesale_parent_id ) {
            return $items;
        }

        // Remove existing children of the wholesale parent
        $items = array_filter( $items, function( $item ) use ( $wholesale_parent_id ) {
            return (int) $item->menu_item_parent !== $wholesale_parent_id;
        });

        // Build replacement children based on user state
        $is_wholesale = is_user_logged_in() && function_exists( 'slw_is_wholesale_user' ) && slw_is_wholesale_user();

        if ( $is_wholesale ) {
            $children = array(
                array( 'title' => 'My Portal',       'url' => home_url( '/wholesale-portal' ) ),
                array( 'title' => 'Order Form',      'url' => home_url( '/wholesale-order' ) ),
                array( 'title' => 'My Dashboard',    'url' => home_url( '/wholesale-dashboard' ) ),
                array( 'title' => 'Request a Quote', 'url' => home_url( '/wholesale-rfq' ) ),
            );
        } else {
            $children = array(
                array( 'title' => 'Apply for Wholesale', 'url' => home_url( '/wholesale-partners' ) ),
                array( 'title' => 'Partner Login',       'url' => home_url( '/my-account' ) ),
            );
        }

        // Create mock menu item objects for the children
        $menu_order = 1000; // high number to appear after other items
        foreach ( $children as $child ) {
            $mock = new stdClass();
            $mock->ID               = --$menu_order + 99000; // unique fake ID
            $mock->db_id            = $mock->ID;
            $mock->title            = $child['title'];
            $mock->url              = $child['url'];
            $mock->menu_item_parent = (string) $wholesale_parent_id;
            $mock->menu_order       = $menu_order;
            $mock->type             = 'custom';
            $mock->type_label       = 'Custom Link';
            $mock->object           = 'custom';
            $mock->object_id        = $mock->ID;
            $mock->target           = '';
            $mock->attr_title       = '';
            $mock->description      = '';
            $mock->classes          = array( 'menu-item', 'menu-item-type-custom' );
            $mock->xfn              = '';
            $mock->current          = false;
            $mock->current_item_ancestor = false;
            $mock->current_item_parent   = false;

            // Mark current page
            $current_url = home_url( $_SERVER['REQUEST_URI'] ?? '' );
            if ( rtrim( $child['url'], '/' ) === rtrim( strtok( $current_url, '?' ), '/' ) ) {
                $mock->current = true;
                $mock->classes[] = 'current-menu-item';
            }

            $items[] = $mock;
            $menu_order++;
        }

        return $items;
    }
}
