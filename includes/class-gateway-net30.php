<?php
/**
 * NET Payment Terms Gateway (30 / 60 / 90 days)
 *
 * A custom WooCommerce payment gateway that lets admin-approved wholesale
 * customers skip payment at checkout. The order is created as "Processing"
 * with a NET label. Payment is collected offline within the granted term.
 *
 * This gateway is only visible to wholesale users who have been individually
 * approved for NET terms (via user meta slw_net_terms = 30, 60, or 90).
 *
 * Backward compatible: if the legacy slw_net30_approved meta is '1' and
 * slw_net_terms is not yet set, the user is treated as NET 30.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class SLW_Gateway_Net30 extends WC_Payment_Gateway {

    public function __construct() {
        $this->id                 = 'slw_net30';
        $this->method_title       = 'NET Payment Terms';
        $this->method_description = 'Allows approved wholesale customers to place orders on NET 30/60/90 payment terms. Payment is collected offline.';
        $this->has_fields         = false;
        $this->enabled            = 'yes';

        // Dynamic title/description set in is_available() or at display time
        $this->title              = 'NET Payment Terms';
        $this->description        = 'Your order will be invoiced with payment due per your approved terms.';

        $this->init_settings();
    }

    /**
     * Get the NET term days for a given user. Returns 0 if no terms approved.
     * Backward compatible with legacy slw_net30_approved checkbox.
     *
     * @param int $user_id
     * @return int 0, 30, 60, or 90
     */
    public static function get_user_net_terms( $user_id ) {
        $terms = get_user_meta( $user_id, 'slw_net_terms', true );

        if ( $terms !== '' && $terms !== false ) {
            return absint( $terms );
        }

        // Backward compat: legacy checkbox
        $legacy = get_user_meta( $user_id, 'slw_net30_approved', true );
        if ( $legacy === '1' ) {
            return 30;
        }

        return 0;
    }

    /**
     * Only show this gateway to wholesale users who have NET terms approval.
     * Also dynamically update the title/description based on the user's term.
     */
    public function is_available() {
        if ( ! slw_is_wholesale_context() ) {
            return false;
        }

        $user_id = get_current_user_id();
        $days    = self::get_user_net_terms( $user_id );

        if ( $days < 1 ) {
            return false;
        }

        // Update the gateway title/description dynamically
        $this->title       = sprintf( 'NET %d Payment Terms', $days );
        $this->description = sprintf( 'Your order will be invoiced with payment due within %d days.', $days );

        return true;
    }

    /**
     * Process the payment: mark order as processing and record the NET term.
     */
    public function process_payment( $order_id ) {
        $order   = wc_get_order( $order_id );
        $user_id = $order->get_user_id();
        $days    = self::get_user_net_terms( $user_id );

        if ( $days < 1 ) {
            $days = 30; // Fallback safety
        }

        // Set the order status with a note about the specific term
        $order->update_status(
            'processing',
            sprintf( 'Order placed on NET %d terms. Payment due within %d days.', $days, $days )
        );

        // Customer-visible note
        $payment_email = class_exists( 'SLW_Email_Settings' ) ? SLW_Email_Settings::get( 'from_address' ) : get_option( 'admin_email' );
        $order->add_order_note(
            sprintf(
                'Payment due within %d days of order date. Please remit payment to %s.',
                $days,
                $payment_email
            ),
            true
        );

        // Store the term days and due date as order meta
        $due_date = date( 'Y-m-d', strtotime( '+' . $days . ' days' ) );
        $order->update_meta_data( '_slw_net_terms_days', $days );
        $order->update_meta_data( '_slw_net30_due_date', $due_date );

        // Xero-compatible standard meta keys
        $order->update_meta_data( '_payment_terms', sprintf( 'NET %d', $days ) );
        $order->update_meta_data( '_due_date', $due_date );
        $order->update_meta_data( '_xero_payment_term', $days );

        // Keep legacy meta for backward compat with reports/queries
        if ( $days === 30 ) {
            $order->update_meta_data( '_slw_net30_order', '1' );
        }

        $order->save();

        // Schedule a reminder for the admin when payment is due
        wp_schedule_single_event(
            strtotime( '+' . $days . ' days' ),
            'slw_net30_payment_reminder',
            array( $order_id )
        );

        // Empty the cart
        WC()->cart->empty_cart();

        return array(
            'result'   => 'success',
            'redirect' => $this->get_return_url( $order ),
        );
    }
}

/**
 * Send admin a reminder when NET payment is due. Fires N days after
 * the order was placed via wp_schedule_single_event.
 */
add_action( 'slw_net30_payment_reminder', function( $order_id ) {
    $order = wc_get_order( $order_id );
    if ( ! $order || $order->is_paid() ) {
        return;
    }

    $days        = absint( $order->get_meta( '_slw_net_terms_days' ) ) ?: 30;
    $admin_email = get_option( 'admin_email' );
    $subject     = sprintf( 'NET %d Payment Due: Order #%s', $days, $order_id );
    $body  = sprintf( "The NET %d payment period has ended for Order #%s.\n\n", $days, $order_id );
    $body .= "Customer: " . $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() . "\n";
    $body .= "Email: " . $order->get_billing_email() . "\n";
    $body .= "Total: $" . $order->get_total() . "\n\n";
    $body .= "Please follow up to collect payment if it hasn't been received.";

    wp_mail( $admin_email, $subject, $body );
});
