<?php
/**
 * Email-based approve/deny for wholesale applications and booth leads.
 *
 * Generates signed URLs that Holly can tap in her email (phone or desktop)
 * to approve or deny a wholesale application without logging into WP Admin.
 * Uses HMAC-SHA256 with AUTH_KEY as the secret so tokens can't be forged.
 *
 * Also updates the admin notification emails for both website applications
 * and booth leads to include approve/deny buttons.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class SLW_Email_Approve {

    /**
     * Token validity period in seconds (7 days).
     */
    const TOKEN_EXPIRY = 604800;

    public static function init() {
        // Public endpoint for approve/deny clicks from email
        add_action( 'init', array( __CLASS__, 'handle_email_action' ) );
    }

    /**
     * Generate a signed approve or deny URL for a given application.
     *
     * @param int    $app_id Application ID from wp_slw_applications.
     * @param string $action 'approve' or 'deny'.
     * @return string Full URL Holly can click.
     */
    public static function get_action_url( $app_id, $action ) {
        $expires = time() + self::TOKEN_EXPIRY;
        $token   = self::sign( $app_id, $action, $expires );

        return add_query_arg( array(
            'slw_action' => $action,
            'app_id'     => $app_id,
            'expires'    => $expires,
            'token'      => $token,
        ), home_url( '/' ) );
    }

    /**
     * Generate HMAC signature.
     */
    private static function sign( $app_id, $action, $expires ) {
        $payload = $app_id . ':' . $action . ':' . $expires;
        return hash_hmac( 'sha256', $payload, AUTH_KEY );
    }

    /**
     * Verify a signed token.
     */
    private static function verify( $app_id, $action, $expires, $token ) {
        if ( time() > (int) $expires ) {
            return false;
        }
        $expected = self::sign( $app_id, $action, $expires );
        return hash_equals( $expected, $token );
    }

    /**
     * Handle incoming approve/deny clicks from email.
     */
    public static function handle_email_action() {
        if ( empty( $_GET['slw_action'] ) || empty( $_GET['app_id'] ) || empty( $_GET['token'] ) || empty( $_GET['expires'] ) ) {
            return;
        }

        $action  = sanitize_text_field( $_GET['slw_action'] );
        $app_id  = absint( $_GET['app_id'] );
        $expires = absint( $_GET['expires'] );
        $token   = sanitize_text_field( $_GET['token'] );

        if ( ! in_array( $action, array( 'approve', 'deny' ), true ) ) {
            return;
        }

        if ( ! self::verify( $app_id, $action, $expires, $token ) ) {
            wp_die( 'This link has expired or is invalid. Please review the application in your WordPress admin.', 'Link Expired', array( 'response' => 403 ) );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'slw_applications';
        $app   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $app_id ) );

        if ( ! $app ) {
            wp_die( 'Application not found.', 'Not Found', array( 'response' => 404 ) );
        }

        if ( $app->status !== 'pending' ) {
            self::render_already_handled( $app );
            exit;
        }

        if ( $action === 'approve' ) {
            // Use the existing approve logic from SLW_Application_Form
            if ( class_exists( 'SLW_Application_Form' ) && method_exists( 'SLW_Application_Form', 'approve_application' ) ) {
                // The approve_application method is private, so we replicate the key steps
                self::do_approve( $app );
            }
            self::render_result( $app, 'approved' );
        } else {
            self::do_deny( $app );
            self::render_result( $app, 'denied' );
        }

        exit;
    }

    /**
     * Approve a wholesale application (mirrors SLW_Application_Form::approve_application).
     */
    private static function do_approve( $app ) {
        global $wpdb;
        $table = $wpdb->prefix . 'slw_applications';

        // Create or find user
        $existing_user = get_user_by( 'email', $app->email );
        if ( $existing_user ) {
            $user_id = $existing_user->ID;
            $existing_user->set_role( 'wholesale_customer' );
        } else {
            $password = wp_generate_password( 12 );
            $name_parts = explode( ' ', $app->contact_name, 2 );
            $user_id = wp_insert_user( array(
                'user_login' => $app->email,
                'user_email' => $app->email,
                'user_pass'  => $password,
                'first_name' => $name_parts[0] ?? '',
                'last_name'  => $name_parts[1] ?? '',
                'role'       => 'wholesale_customer',
            ) );
            if ( is_wp_error( $user_id ) ) {
                return;
            }
        }

        // Store application data on the user
        update_user_meta( $user_id, 'slw_business_name', $app->business_name );
        update_user_meta( $user_id, 'slw_application_id', $app->id );
        if ( ! empty( $app->ein ) ) {
            update_user_meta( $user_id, 'slw_ein', $app->ein );
        }
        if ( ! empty( $app->business_type ) ) {
            update_user_meta( $user_id, 'slw_business_type', $app->business_type );
        }

        // Mark approved
        $wpdb->update( $table, array(
            'status'      => 'approved',
            'reviewed_at' => current_time( 'mysql' ),
            'reviewed_by' => 0, // Email-based approval, no WP user context
        ), array( 'id' => $app->id ) );

        // Audit log
        if ( class_exists( 'SLW_Audit_Log' ) ) {
            SLW_Audit_Log::log( 'application_approved', sprintf( 'Application approved via email for %s', $app->business_name ) );
        }

        // Send welcome email to the applicant
        $business_name = get_bloginfo( 'name' );
        $first_name    = explode( ' ', $app->contact_name, 2 )[0];
        $discount      = get_option( 'slw_discount_percent', 50 );
        $subject       = 'Welcome to ' . $business_name . ' Wholesale!';

        $body  = "Hi {$first_name},\n\n";
        $body .= "Great news! Your wholesale application for {$app->business_name} has been approved.\n\n";
        $body .= "You now have access to wholesale pricing on all {$business_name} products at {$discount}% off retail.\n\n";
        $body .= "Your first order will need to be paid upfront. After that, you'll be eligible for NET 30 payment terms. We prefer payment via ACH/bank transfer to keep costs down for everyone.\n\n";
        if ( ! $existing_user ) {
            $body .= "Here are your login details:\n";
            $body .= "Email: {$app->email}\n";
            $body .= "Password: {$password}\n\n";
        }
        $body .= "Log in and start ordering:\n";
        $body .= site_url( '/wholesale-dashboard/' ) . "\n\n";
        $body .= "Questions? Just reply to this email.\n\n";
        $owner = class_exists( 'SLW_Email_Settings' ) ? SLW_Email_Settings::get( 'owner_name' ) : '';
        $body .= $owner ? "— {$owner}" : "— The {$business_name} Team";

        $headers = class_exists( 'SLW_Email_Settings' ) ? SLW_Email_Settings::get_headers() : array();
        wp_mail( $app->email, $subject, $body, $headers );

        // Fire webhook for Mautic
        if ( class_exists( 'SLW_Webhooks' ) ) {
            SLW_Webhooks::fire( 'wholesale-approved', array(
                'email'         => $app->email,
                'first_name'    => $first_name,
                'business_name' => $app->business_name,
                'source'        => 'email_approve',
            ) );
        }
    }

    /**
     * Deny a wholesale application.
     */
    private static function do_deny( $app ) {
        global $wpdb;
        $table = $wpdb->prefix . 'slw_applications';

        $wpdb->update( $table, array(
            'status'      => 'declined',
            'reviewed_at' => current_time( 'mysql' ),
            'reviewed_by' => 0,
        ), array( 'id' => $app->id ) );

        if ( class_exists( 'SLW_Audit_Log' ) ) {
            SLW_Audit_Log::log( 'application_declined', sprintf( 'Application declined via email for %s', $app->business_name ) );
        }

        // Send polite decline email
        $business_name = get_bloginfo( 'name' );
        $first_name    = explode( ' ', $app->contact_name, 2 )[0];
        $subject       = 'Your ' . $business_name . ' wholesale application';

        $body  = "Hi {$first_name},\n\n";
        $body .= "Thanks for your interest in carrying {$business_name} products.\n\n";
        $body .= "After reviewing your application, we're not able to set up a wholesale account at this time. ";
        $body .= "This could be for a number of reasons, and it doesn't mean the door is closed.\n\n";
        $body .= "You're always welcome to shop our retail products at " . site_url() . "\n\n";
        $body .= "If you'd like to chat about it, just reply to this email.\n\n";
        $owner = class_exists( 'SLW_Email_Settings' ) ? SLW_Email_Settings::get( 'owner_name' ) : '';
        $body .= $owner ? "— {$owner}" : "— The {$business_name} Team";

        $headers = class_exists( 'SLW_Email_Settings' ) ? SLW_Email_Settings::get_headers() : array();
        wp_mail( $app->email, $subject, $body, $headers );
    }

    /**
     * Build the HTML email body for Holly's notification with approve/deny buttons.
     *
     * @param object $app        Application row from the DB.
     * @param string $source     'website' or 'booth' — changes the copy.
     * @param array  $extra_data Additional quiz data for booth leads.
     * @return string HTML email body.
     */
    public static function build_notification_html( $app, $source = 'website', $extra_data = array() ) {
        $approve_url = self::get_action_url( $app->id, 'approve' );
        $deny_url    = self::get_action_url( $app->id, 'deny' );
        $admin_url   = admin_url( 'admin.php?page=slw-applications&app_id=' . $app->id );

        $source_label = $source === 'booth' ? 'Trade Show Booth' : 'Website Application';

        $html = '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>';
        $html .= '<body style="margin:0;padding:0;background:#F7F6F3;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,sans-serif;">';
        $html .= '<div style="max-width:600px;margin:0 auto;padding:32px 24px;background:#ffffff;">';

        $html .= '<h2 style="color:#1E2A30;font-size:20px;margin:0 0 8px;">New Wholesale Inquiry</h2>';
        $html .= '<p style="color:#628393;font-size:14px;margin:0 0 24px;">Source: ' . esc_html( $source_label ) . '</p>';

        // Contact details table
        $html .= '<table style="width:100%;border-collapse:collapse;margin-bottom:24px;">';
        $fields = array(
            'Business'  => $app->business_name,
            'Contact'   => $app->contact_name,
            'Email'     => $app->email,
            'Phone'     => $app->phone ?: '—',
        );
        if ( ! empty( $app->website ) ) {
            $fields['Business Website'] = $app->website;
        }
        if ( ! empty( $app->business_type ) ) {
            $fields['Business Type'] = $app->business_type;
        }
        if ( ! empty( $app->ein ) ) {
            // EIN is stored encrypted; decrypt for display
            $fields['EIN'] = class_exists( 'SLW_Encryption' ) ? SLW_Encryption::decrypt( $app->ein ) : $app->ein;
        }
        // Add booth quiz data if present
        if ( ! empty( $extra_data['skincare_experience'] ) ) {
            $fields['Carries Skincare?'] = $extra_data['skincare_experience'];
        }
        if ( ! empty( $extra_data['tallow_interest'] ) ) {
            $fields['Why Tallow?'] = $extra_data['tallow_interest'];
        }
        if ( ! empty( $extra_data['event'] ) ) {
            $fields['Event'] = $extra_data['event'];
        }

        foreach ( $fields as $label => $value ) {
            $html .= '<tr>';
            $html .= '<td style="padding:8px 12px;border-bottom:1px solid #e0ddd8;color:#628393;font-size:14px;width:130px;">' . esc_html( $label ) . '</td>';
            $html .= '<td style="padding:8px 12px;border-bottom:1px solid #e0ddd8;color:#1E2A30;font-size:14px;">' . esc_html( $value ) . '</td>';
            $html .= '</tr>';
        }
        $html .= '</table>';

        // Booth leads note — they haven't submitted EIN yet
        if ( $source === 'booth' && empty( $app->ein ) ) {
            $html .= '<p style="color:#996800;font-size:13px;background:#FFF8E1;padding:12px;border-radius:6px;margin-bottom:24px;">';
            $html .= 'This lead came from the trade show booth. EIN and shipping address will be collected after approval via the activation form.';
            $html .= '</p>';
        }

        // Approve / Deny buttons
        $html .= '<div style="text-align:center;margin:24px 0;">';
        $html .= '<a href="' . esc_url( $approve_url ) . '" style="display:inline-block;background:#2e7d32;color:#ffffff;padding:14px 32px;text-decoration:none;font-size:16px;font-weight:600;border-radius:6px;margin-right:12px;">Approve</a>';
        $html .= '<a href="' . esc_url( $deny_url ) . '" style="display:inline-block;background:#c62828;color:#ffffff;padding:14px 32px;text-decoration:none;font-size:16px;font-weight:600;border-radius:6px;">Deny</a>';
        $html .= '</div>';

        // Link to admin for full review
        $html .= '<p style="text-align:center;color:#628393;font-size:13px;">';
        $html .= '<a href="' . esc_url( $admin_url ) . '" style="color:#386174;">View full details in WordPress</a>';
        $html .= '</p>';

        $html .= '</div></body></html>';
        return $html;
    }

    /**
     * Render confirmation page after approve/deny.
     */
    private static function render_result( $app, $result ) {
        $color = $result === 'approved' ? '#2e7d32' : '#c62828';
        $title = $result === 'approved' ? 'Application Approved' : 'Application Declined';
        $msg   = $result === 'approved'
            ? 'A welcome email has been sent to ' . esc_html( $app->email ) . ' with their login details.'
            : 'A polite decline email has been sent to ' . esc_html( $app->email ) . '.';

        echo '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . esc_html( $title ) . '</title></head>';
        echo '<body style="margin:0;padding:60px 24px;background:#F7F6F3;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,sans-serif;text-align:center;">';
        echo '<div style="max-width:480px;margin:0 auto;background:#ffffff;padding:40px 32px;border-radius:12px;box-shadow:0 2px 12px rgba(0,0,0,0.08);">';
        echo '<div style="width:48px;height:48px;border-radius:50%;background:' . $color . ';margin:0 auto 16px;line-height:48px;color:#fff;font-size:24px;">' . ( $result === 'approved' ? '&#10003;' : '&#10005;' ) . '</div>';
        echo '<h1 style="color:#1E2A30;font-size:22px;margin:0 0 12px;">' . esc_html( $title ) . '</h1>';
        echo '<p style="color:#1E2A30;font-size:16px;margin:0 0 8px;"><strong>' . esc_html( $app->business_name ) . '</strong></p>';
        echo '<p style="color:#628393;font-size:14px;margin:0 0 24px;">' . esc_html( $app->contact_name ) . ' &middot; ' . esc_html( $app->email ) . '</p>';
        echo '<p style="color:#628393;font-size:14px;">' . $msg . '</p>';
        echo '</div></body></html>';
    }

    /**
     * Render "already handled" page.
     */
    private static function render_already_handled( $app ) {
        echo '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Already Reviewed</title></head>';
        echo '<body style="margin:0;padding:60px 24px;background:#F7F6F3;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,sans-serif;text-align:center;">';
        echo '<div style="max-width:480px;margin:0 auto;background:#ffffff;padding:40px 32px;border-radius:12px;">';
        echo '<h1 style="color:#1E2A30;font-size:22px;margin:0 0 12px;">Already Reviewed</h1>';
        echo '<p style="color:#628393;font-size:14px;">This application for <strong>' . esc_html( $app->business_name ) . '</strong> has already been ' . esc_html( $app->status ) . '.</p>';
        echo '</div></body></html>';
    }
}
