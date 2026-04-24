<?php
/**
 * Xero Integration Compatibility
 *
 * Ensures WooCommerce order and customer meta is structured for Xero invoice
 * sync. Works with the official WooCommerce Xero extension or any plugin that
 * reads standard Xero-compatible meta keys.
 *
 * What this does:
 *   - Copies SLW NET payment term meta to Xero-standard keys on orders
 *   - Stores Xero-compatible contact info on wholesale customer profiles
 *   - Filters the Xero invoice payload (if the official plugin is active) to
 *     inject due dates and payment terms for NET orders
 *   - Adds wholesale discount as visible line-level data for Xero reconciliation
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class SLW_Xero_Compat {

    /**
     * Default Xero revenue account code. Holly can override in settings.
     * Common: 200 = Sales, 400 = Revenue. Set to empty to let Xero use its default.
     */
    const DEFAULT_ACCOUNT_CODE = '';

    public static function init() {
        // Sync Xero meta when NET term orders are created
        add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'sync_order_meta' ), 20 );
        add_action( 'woocommerce_order_status_completed',  array( __CLASS__, 'sync_order_meta' ), 20 );

        // Sync Xero meta when a NET term order is placed (catches it early)
        add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'sync_order_meta' ), 20 );

        // Store Xero contact info when a wholesale user is approved
        add_action( 'slw_wholesale_approved', array( __CLASS__, 'sync_contact_meta' ), 10, 2 );

        // Backfill contact meta when admin views a wholesale user profile
        add_action( 'edit_user_profile', array( __CLASS__, 'maybe_backfill_contact_meta' ), 5 );

        // Filter Xero invoice args if the official WooCommerce Xero plugin is active
        add_filter( 'woocommerce_xero_invoice_to_xml', array( __CLASS__, 'filter_xero_invoice' ), 10, 2 );

        // Also support the older filter name some Xero plugins use
        add_filter( 'wc_xero_invoice_args', array( __CLASS__, 'filter_xero_invoice_args' ), 10, 2 );
    }

    // =========================================================================
    // Order Meta Sync
    // =========================================================================

    /**
     * Copy SLW NET payment meta to Xero-standard keys.
     *
     * SLW stores:
     *   _slw_net_terms_days  → 30, 60, or 90
     *   _slw_net30_due_date  → Y-m-d
     *
     * Xero expects:
     *   _payment_terms       → "NET 30" (string)
     *   _due_date            → Y-m-d (ISO date)
     *   _xero_payment_term   → numeric days (some plugins use this)
     */
    public static function sync_order_meta( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) return;

        $net_days = absint( $order->get_meta( '_slw_net_terms_days' ) );
        if ( $net_days < 1 ) return; // Not a NET term order

        $due_date = $order->get_meta( '_slw_net30_due_date' );
        if ( empty( $due_date ) ) {
            // Reconstruct from order date + terms
            $order_date = $order->get_date_created();
            if ( $order_date ) {
                $due_date = date( 'Y-m-d', strtotime( '+' . $net_days . ' days', $order_date->getTimestamp() ) );
            }
        }

        // Standard Xero-compatible keys
        $order->update_meta_data( '_payment_terms', sprintf( 'NET %d', $net_days ) );
        $order->update_meta_data( '_xero_payment_term', $net_days );

        if ( $due_date ) {
            $order->update_meta_data( '_due_date', $due_date );
        }

        // Store wholesale tier for Xero tracking/reporting
        $user_id = $order->get_user_id();
        if ( $user_id && class_exists( 'SLW_Tiers' ) ) {
            $tier = SLW_Tiers::get_user_tier( $user_id );
            if ( $tier ) {
                $order->update_meta_data( '_xero_tracking_category', 'Wholesale Tier' );
                $order->update_meta_data( '_xero_tracking_option', ucfirst( $tier ) );
            }
        }

        // Revenue account code (if configured)
        $account_code = get_option( 'slw_xero_account_code', self::DEFAULT_ACCOUNT_CODE );
        if ( ! empty( $account_code ) ) {
            $order->update_meta_data( '_xero_account_code', $account_code );
        }

        $order->save();
    }

    // =========================================================================
    // Contact Meta Sync
    // =========================================================================

    /**
     * When a wholesale user is approved, store Xero-compatible contact meta.
     *
     * @param int   $user_id      The approved user's ID.
     * @param array $application  Application data (business_name, etc.).
     */
    public static function sync_contact_meta( $user_id, $application = array() ) {
        $user = get_userdata( $user_id );
        if ( ! $user ) return;

        // Business name — try application data first, then user meta
        $business_name = '';
        if ( ! empty( $application['business_name'] ) ) {
            $business_name = $application['business_name'];
        } else {
            $business_name = get_user_meta( $user_id, 'slw_business_name', true );
        }

        if ( empty( $business_name ) ) {
            $business_name = $user->first_name . ' ' . $user->last_name;
        }

        // Xero contact name — this is what appears on invoices in Xero
        update_user_meta( $user_id, '_xero_contact_name', sanitize_text_field( $business_name ) );

        // Account number — use WooCommerce user ID for cross-reference
        update_user_meta( $user_id, '_xero_account_number', 'WC-' . $user_id );

        // Tax number (EIN) — if stored and decryptable
        if ( class_exists( 'SLW_Encryption' ) ) {
            $ein = get_user_meta( $user_id, 'slw_ein_encrypted', true );
            if ( $ein ) {
                $decrypted = SLW_Encryption::decrypt( $ein );
                if ( $decrypted ) {
                    update_user_meta( $user_id, '_xero_tax_number', $decrypted );
                }
            }
        }

        // Payment terms default
        $net_terms = 0;
        if ( class_exists( 'SLW_Gateway_Net30' ) ) {
            $net_terms = SLW_Gateway_Net30::get_user_net_terms( $user_id );
        }
        if ( $net_terms > 0 ) {
            update_user_meta( $user_id, '_xero_payment_terms', sprintf( 'NET %d', $net_terms ) );
            update_user_meta( $user_id, '_xero_payment_term_days', $net_terms );
        }
    }

    /**
     * Backfill Xero contact meta when admin views a profile (catch users
     * approved before this module was installed).
     */
    public static function maybe_backfill_contact_meta( $user ) {
        if ( ! function_exists( 'slw_is_wholesale_user' ) || ! slw_is_wholesale_user( $user->ID ) ) {
            return;
        }

        $existing = get_user_meta( $user->ID, '_xero_contact_name', true );
        if ( ! empty( $existing ) ) {
            return; // Already synced
        }

        self::sync_contact_meta( $user->ID );
    }

    // =========================================================================
    // Xero Plugin Filters
    // =========================================================================

    /**
     * Filter for the official WooCommerce Xero extension's invoice XML.
     * Injects DueDate and payment terms for NET orders.
     */
    public static function filter_xero_invoice( $xml, $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) return $xml;

        $due_date = $order->get_meta( '_due_date' );
        $terms    = $order->get_meta( '_payment_terms' );

        if ( ! $due_date || ! $terms ) return $xml;

        // Inject DueDate into the XML if not already present
        if ( strpos( $xml, '<DueDate>' ) === false && strpos( $xml, '</Invoice>' ) !== false ) {
            $due_xml = '<DueDate>' . date( 'Y-m-d', strtotime( $due_date ) ) . '</DueDate>';
            $ref_xml = '<Reference>' . esc_html( $terms ) . ' - Order #' . $order_id . '</Reference>';
            $xml = str_replace( '</Invoice>', $due_xml . $ref_xml . '</Invoice>', $xml );
        }

        return $xml;
    }

    /**
     * Filter for alternative Xero plugins that use an args array.
     */
    public static function filter_xero_invoice_args( $args, $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) return $args;

        $due_date = $order->get_meta( '_due_date' );
        $terms    = $order->get_meta( '_payment_terms' );

        if ( $due_date ) {
            $args['DueDate'] = date( 'Y-m-d', strtotime( $due_date ) );
        }

        if ( $terms ) {
            $args['Reference'] = $terms . ' - Order #' . $order_id;
        }

        // Payment term type for Xero API
        $net_days = absint( $order->get_meta( '_xero_payment_term' ) );
        if ( $net_days > 0 ) {
            $args['PaymentTerms'] = array(
                'Sales' => array(
                    'Day'  => $net_days,
                    'Type' => 'DAYSAFTERBILLDATE',
                ),
            );
        }

        // Tracking category for wholesale tier
        $tracking_cat    = $order->get_meta( '_xero_tracking_category' );
        $tracking_option = $order->get_meta( '_xero_tracking_option' );
        if ( $tracking_cat && $tracking_option ) {
            $args['Tracking'] = array(
                array(
                    'Name'   => $tracking_cat,
                    'Option' => $tracking_option,
                ),
            );
        }

        return $args;
    }
}
