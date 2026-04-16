<?php
/**
 * NET 30 Payment Gateway
 *
 * A custom WooCommerce payment gateway that lets admin-approved wholesale
 * customers skip payment at checkout. The order is created as "Processing"
 * with a "NET 30" label. Payment is collected offline within 30 days.
 *
 * This gateway is only visible to wholesale users who have been individually
 * approved for NET 30 terms (via user meta slw_net30_approved).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class SLW_Gateway_Net30 extends WC_Payment_Gateway {

    public function __construct() {
        $this->id                 = 'slw_net30';
        $this->method_title       = 'NET 30 Terms';
        $this->method_description = 'Allows approved wholesale customers to place orders on NET 30 payment terms. Payment is collected offline.';
        $this->has_fields         = false;
        $this->title              = 'NET 30 Payment Terms';
        $this->description        = 'Your order will be invoiced with payment due within 30 days.';
        $this->enabled            = 'yes';

        $this->init_settings();
    }

    /**
     * Only show this gateway to wholesale users who have NET 30 approval.
     */
    public function is_available() {
        if ( ! slw_is_wholesale_user() ) {
            return false;
        }

        $user_id = get_current_user_id();
        $approved = get_user_meta( $user_id, 'slw_net30_approved', true );

        return $approved === '1';
    }

    /**
     * Process the payment: mark order as processing (not completed, since
     * payment hasn't been received yet) and add a note about NET 30 terms.
     */
    public function process_payment( $order_id ) {
        $order = wc_get_order( $order_id );

        // Set the order status to "on-hold" so it's clearly awaiting payment
        $order->update_status( 'processing', 'Order placed on NET 30 terms. Payment due within 30 days.' );

        // Add a customer note visible on the order confirmation
        $order->add_order_note( 'Payment due within 30 days of order date. Please remit payment to wholesale@segolilyskincare.com.', true );

        // Store the due date as order meta for reference
        $due_date = date( 'Y-m-d', strtotime( '+30 days' ) );
        $order->update_meta_data( '_slw_net30_due_date', $due_date );
        $order->save();

        // Schedule a reminder for the admin when payment is due
        wp_schedule_single_event(
            strtotime( '+30 days' ),
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
 * Send admin a reminder when NET 30 payment is due. Fires 30 days after
 * the order was placed via wp_schedule_single_event.
 */
add_action( 'slw_net30_payment_reminder', function( $order_id ) {
    $order = wc_get_order( $order_id );
    if ( ! $order || $order->is_paid() ) {
        return;
    }

    $admin_email = get_option( 'admin_email' );
    $subject = 'NET 30 Payment Due: Order #' . $order_id;
    $body  = "The NET 30 payment period has ended for Order #{$order_id}.\n\n";
    $body .= "Customer: " . $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() . "\n";
    $body .= "Email: " . $order->get_billing_email() . "\n";
    $body .= "Total: $" . $order->get_total() . "\n\n";
    $body .= "Please follow up to collect payment if it hasn't been received.";

    wp_mail( $admin_email, $subject, $body );
});
