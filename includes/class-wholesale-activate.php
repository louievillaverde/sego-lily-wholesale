<?php
/**
 * Wholesale Activation Form — short form for booth leads after Holly approves.
 *
 * Shortcode: [slw_wholesale_activate]
 * Auto-creates a /wholesale-activate page on admin_init.
 *
 * This is the "Complete your setup" step for trade show leads.
 * Holly already approved them via email button. Now they just need to
 * provide EIN + shipping address to activate wholesale pricing.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class SLW_Wholesale_Activate {

    public static function init() {
        add_shortcode( 'slw_wholesale_activate', array( __CLASS__, 'render' ) );
        add_action( 'admin_init', array( __CLASS__, 'ensure_page' ) );
        add_action( 'wp_ajax_slw_activate_wholesale', array( __CLASS__, 'handle_submission' ) );
        add_action( 'wp_ajax_nopriv_slw_activate_wholesale', array( __CLASS__, 'handle_submission' ) );
    }

    public static function ensure_page() {
        if ( ! get_page_by_path( 'wholesale-activate' ) ) {
            wp_insert_post( array(
                'post_title'   => 'Activate Your Wholesale Account',
                'post_content' => '[slw_wholesale_activate]',
                'post_status'  => 'publish',
                'post_type'    => 'page',
                'post_name'    => 'wholesale-activate',
            ) );
        }
    }

    public static function render( $atts = array() ) {
        ob_start();
        ?>
        <style>
            #slw-activate { max-width: 520px; margin: 0 auto; padding: 40px 24px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; }
            #slw-activate h2 { font-family: Georgia, serif; font-size: 24px; color: #1E2A30; margin: 0 0 8px; text-align: center; }
            #slw-activate .slw-activate-sub { color: #628393; font-size: 15px; text-align: center; margin: 0 0 32px; }
            #slw-activate label { display: block; color: #1E2A30; font-size: 14px; font-weight: 600; margin: 0 0 6px; }
            #slw-activate .slw-activate-hint { color: #628393; font-size: 12px; margin: 0 0 6px; }
            #slw-activate input[type="text"],
            #slw-activate input[type="email"] {
                width: 100%; padding: 14px 16px; font-size: 16px; border: 2px solid #e0ddd8;
                border-radius: 8px; margin-bottom: 20px; box-sizing: border-box;
                font-family: inherit; transition: border-color 0.2s;
            }
            #slw-activate input:focus { border-color: #386174; outline: none; }
            #slw-activate .slw-activate-row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
            @media (max-width: 500px) { #slw-activate .slw-activate-row { grid-template-columns: 1fr; } }
            #slw-activate .slw-activate-btn {
                display: block; width: 100%; padding: 16px; font-size: 16px; font-weight: 600;
                background: #C8A951; color: #1E2A30; border: none; border-radius: 8px;
                cursor: pointer; transition: background 0.2s, transform 0.1s; margin-top: 8px;
                letter-spacing: 0.01em;
            }
            #slw-activate .slw-activate-btn:hover { background: #B89238; }
            #slw-activate .slw-activate-btn:active { transform: translateY(1px); }
            #slw-activate .slw-activate-msg { text-align: center; padding: 16px; border-radius: 8px; margin-top: 16px; }
            #slw-activate .slw-activate-msg--success { background: #E8F5E9; color: #2e7d32; }
            #slw-activate .slw-activate-msg--error { background: #FFEBEE; color: #c62828; }
        </style>

        <div id="slw-activate">
            <h2>Activate Your Wholesale Account</h2>
            <p class="slw-activate-sub">You're approved! Just a few details to get your wholesale pricing set up.</p>

            <form id="slw-activate-form">
                <?php wp_nonce_field( 'slw_activate_wholesale', 'slw_activate_nonce' ); ?>

                <label for="slw-act-email">Email (same one you used at the booth)</label>
                <input type="email" id="slw-act-email" name="email" required />

                <label for="slw-act-business">Business Name</label>
                <input type="text" id="slw-act-business" name="business_name" required />

                <label for="slw-act-ein">EIN / Resale Certificate Number</label>
                <p class="slw-activate-hint">Required for tax-exempt wholesale pricing</p>
                <input type="text" id="slw-act-ein" name="ein" required />

                <label>Shipping Address</label>
                <input type="text" id="slw-act-addr1" name="address1" placeholder="Street address" required />
                <div class="slw-activate-row">
                    <input type="text" id="slw-act-city" name="city" placeholder="City" required />
                    <input type="text" id="slw-act-state" name="state" placeholder="State" required />
                </div>
                <input type="text" id="slw-act-zip" name="zip" placeholder="ZIP code" required />

                <button type="submit" class="slw-activate-btn">Get My Wholesale Pricing</button>
                <div id="slw-activate-msg" class="slw-activate-msg" style="display:none;"></div>
            </form>
        </div>

        <script>
        (function(){
            var form = document.getElementById('slw-activate-form');
            if (!form) return;
            form.addEventListener('submit', function(e){
                e.preventDefault();
                var btn = form.querySelector('.slw-activate-btn');
                var msg = document.getElementById('slw-activate-msg');
                btn.disabled = true;
                btn.textContent = 'Activating...';
                msg.style.display = 'none';

                var fd = new FormData(form);
                fd.append('action', 'slw_activate_wholesale');

                fetch('<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>', {
                    method: 'POST', body: fd, credentials: 'same-origin'
                })
                .then(function(r){ return r.json(); })
                .then(function(res){
                    btn.disabled = false;
                    btn.textContent = 'Get My Wholesale Pricing';
                    msg.style.display = 'block';
                    if (res.success) {
                        msg.className = 'slw-activate-msg slw-activate-msg--success';
                        msg.textContent = res.data.message || 'Your wholesale account is active! Check your email for login details.';
                        form.reset();
                    } else {
                        msg.className = 'slw-activate-msg slw-activate-msg--error';
                        msg.textContent = res.data || 'Something went wrong. Please try again.';
                    }
                })
                .catch(function(){
                    btn.disabled = false;
                    btn.textContent = 'Get My Wholesale Pricing';
                    msg.style.display = 'block';
                    msg.className = 'slw-activate-msg slw-activate-msg--error';
                    msg.textContent = 'Network error. Please try again.';
                });
            });
        })();
        </script>
        <?php
        return ob_get_clean();
    }

    public static function handle_submission() {
        check_ajax_referer( 'slw_activate_wholesale', 'slw_activate_nonce' );

        $email         = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
        $business_name = sanitize_text_field( wp_unslash( $_POST['business_name'] ?? '' ) );
        $ein           = sanitize_text_field( wp_unslash( $_POST['ein'] ?? '' ) );
        $address1      = sanitize_text_field( wp_unslash( $_POST['address1'] ?? '' ) );
        $city          = sanitize_text_field( wp_unslash( $_POST['city'] ?? '' ) );
        $state         = sanitize_text_field( wp_unslash( $_POST['state'] ?? '' ) );
        $zip           = sanitize_text_field( wp_unslash( $_POST['zip'] ?? '' ) );

        if ( ! $email || ! $business_name || ! $ein || ! $address1 || ! $city || ! $state || ! $zip ) {
            wp_send_json_error( 'Please fill in all fields.' );
        }

        if ( ! is_email( $email ) ) {
            wp_send_json_error( 'Please enter a valid email address.' );
        }

        // Find the approved application for this email
        global $wpdb;
        $app_table = $wpdb->prefix . 'slw_applications';
        $app = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$app_table} WHERE email = %s AND status = 'approved' ORDER BY id DESC LIMIT 1",
            $email
        ) );

        if ( ! $app ) {
            wp_send_json_error( 'We couldn\'t find an approved application for this email. Please make sure you\'re using the same email from the trade show.' );
        }

        $full_address = $address1 . ', ' . $city . ', ' . $state . ' ' . $zip;

        // Update the application with EIN and address
        $wpdb->update( $app_table, array(
            'ein'           => $ein,
            'business_name' => $business_name,
        ), array( 'id' => $app->id ) );

        // Update user meta if the user exists
        $user = get_user_by( 'email', $email );
        if ( $user ) {
            // Encrypt EIN if encryption is available
            if ( class_exists( 'SLW_Encryption' ) ) {
                update_user_meta( $user->ID, 'slw_ein', SLW_Encryption::encrypt( $ein ) );
            } else {
                update_user_meta( $user->ID, 'slw_ein', $ein );
            }
            update_user_meta( $user->ID, 'slw_business_name', $business_name );
            update_user_meta( $user->ID, 'billing_address_1', $address1 );
            update_user_meta( $user->ID, 'billing_city', $city );
            update_user_meta( $user->ID, 'billing_state', $state );
            update_user_meta( $user->ID, 'billing_postcode', $zip );
            update_user_meta( $user->ID, 'shipping_address_1', $address1 );
            update_user_meta( $user->ID, 'shipping_city', $city );
            update_user_meta( $user->ID, 'shipping_state', $state );
            update_user_meta( $user->ID, 'shipping_postcode', $zip );
        }

        if ( class_exists( 'SLW_Audit_Log' ) ) {
            SLW_Audit_Log::log( 'wholesale_activated', sprintf( 'Wholesale account activated for %s (%s)', $business_name, $email ) );
        }

        wp_send_json_success( array(
            'message' => 'Your wholesale account is active! Login details are in your email — please check your spam or promotions folder if you don\'t see them in your inbox within a few minutes.',
        ) );
    }
}
