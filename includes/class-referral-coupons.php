<?php
/**
 * Referral Coupon System (Retail)
 *
 * HelloFresh-style referral program for retail customers:
 *
 * 1. Customer takes trade show quiz and gets SEGO15 (15% off first order)
 * 2. After their first order completes, plugin generates 3 unique coupon codes
 * 3. Customer shares codes with friends — each code is single-use, 15% off, 90-day expiry
 * 4. When a friend redeems a code, the referrer earns a reward:
 *    - 1st friend: 10% off coupon
 *    - 2nd friend: 10% off coupon
 *    - 3rd friend (all used!): 20% off coupon
 * 5. Webhooks fire to Mautic on generation + each redemption
 *
 * Codes are standard WooCommerce coupons with custom meta for tracking.
 * Referrer rewards are also WooCommerce coupons, auto-generated on redemption.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class SLW_Referral_Coupons {

    /** Number of referral codes generated per customer */
    const CODES_PER_CUSTOMER = 3;

    /** Referral code discount (friends get this) */
    const FRIEND_DISCOUNT_PERCENT = 15;

    /** Days until referral codes expire */
    const CODE_EXPIRY_DAYS = 90;

    /** Reward tiers: index = number of friends who have redeemed (0-based) */
    const REWARD_TIERS = array(
        1 => 10,  // 1st friend redeems → referrer gets 10% off
        2 => 10,  // 2nd friend redeems → referrer gets 10% off
        3 => 20,  // 3rd friend redeems → referrer gets 20% off (bonus!)
    );

    /** Days until reward coupons expire */
    const REWARD_EXPIRY_DAYS = 120;

    public static function init() {
        // Generate referral codes after first retail order completes
        add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'on_order_completed' ), 25 );

        // Validate referral coupons at checkout
        add_filter( 'woocommerce_coupon_is_valid', array( __CLASS__, 'validate_referral_coupon' ), 10, 3 );

        // Track redemption when an order with a referral coupon completes
        add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'track_redemption' ), 30 );

        // Admin: show referral info on user profile
        add_action( 'show_user_profile', array( __CLASS__, 'render_admin_referral_section' ) );
        add_action( 'edit_user_profile', array( __CLASS__, 'render_admin_referral_section' ) );
    }

    // =========================================================================
    // Code Generation
    // =========================================================================

    /**
     * On order completion, generate referral codes for first-time retail customers.
     */
    public static function on_order_completed( $order_id ) {
        $order   = wc_get_order( $order_id );
        if ( ! $order ) return;

        $user_id = $order->get_user_id();
        if ( ! $user_id ) return;

        // Skip wholesale customers — this is a retail-only feature
        if ( function_exists( 'slw_is_wholesale_user' ) && slw_is_wholesale_user( $user_id ) ) {
            return;
        }

        // Skip if referral codes already generated for this user
        $existing = get_user_meta( $user_id, 'slw_referral_codes', true );
        if ( ! empty( $existing ) ) {
            return;
        }

        // Verify this is their first completed order
        $completed_orders = wc_get_orders( array(
            'customer_id' => $user_id,
            'status'      => 'completed',
            'limit'       => 2,
            'return'       => 'ids',
        ));

        // Should only have the current order (or at most 1)
        if ( count( $completed_orders ) > 1 ) {
            return;
        }

        self::generate_codes( $user_id );
    }

    /**
     * Generate 3 unique referral coupon codes for a customer.
     */
    private static function generate_codes( $user_id ) {
        $user       = get_userdata( $user_id );
        $first_name = strtoupper( substr( preg_replace( '/[^A-Za-z]/', '', $user->first_name ?: 'FRIEND' ), 0, 8 ) );
        $email      = $user->user_email;
        $codes      = array();
        $expiry     = date( 'Y-m-d', strtotime( '+' . self::CODE_EXPIRY_DAYS . ' days' ) );

        for ( $i = 0; $i < self::CODES_PER_CUSTOMER; $i++ ) {
            $suffix = strtoupper( wp_generate_password( 4, false ) );
            $code   = 'SEGO-' . $first_name . '-' . $suffix;

            // Ensure uniqueness
            while ( wc_get_coupon_id_by_code( $code ) ) {
                $suffix = strtoupper( wp_generate_password( 4, false ) );
                $code   = 'SEGO-' . $first_name . '-' . $suffix;
            }

            $coupon = new WC_Coupon();
            $coupon->set_code( $code );
            $coupon->set_discount_type( 'percent' );
            $coupon->set_amount( self::FRIEND_DISCOUNT_PERCENT );
            $coupon->set_usage_limit( 1 );
            $coupon->set_individual_use( true );
            $coupon->set_date_expires( $expiry );
            $coupon->set_description(
                sprintf( 'Referral code from %s %s (user #%d)', $user->first_name, $user->last_name, $user_id )
            );
            $coupon->save();

            // Custom meta for tracking
            $coupon_id = $coupon->get_id();
            update_post_meta( $coupon_id, 'slw_referral_coupon', 'yes' );
            update_post_meta( $coupon_id, 'slw_referrer_user_id', $user_id );
            update_post_meta( $coupon_id, 'slw_referrer_email', $email );

            $codes[] = $code;
        }

        // Store codes on the referrer
        update_user_meta( $user_id, 'slw_referral_codes', wp_json_encode( $codes ) );
        update_user_meta( $user_id, 'slw_referral_conversions', 0 );
        update_user_meta( $user_id, 'slw_referral_codes_created', current_time( 'Y-m-d H:i:s' ) );

        // Fire webhook + Mautic tag
        if ( class_exists( 'SLW_Webhooks' ) ) {
            SLW_Webhooks::fire( 'referral-codes-generated', array(
                'email'      => $email,
                'first_name' => $user->first_name,
                'last_name'  => $user->last_name,
                'codes'      => $codes,
                'code_1'     => $codes[0] ?? '',
                'code_2'     => $codes[1] ?? '',
                'code_3'     => $codes[2] ?? '',
                'expiry'     => $expiry,
                'user_id'    => $user_id,
            ));
        }

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( sprintf(
                'SLW Referral: Generated %d codes for user #%d (%s): %s',
                count( $codes ), $user_id, $email, implode( ', ', $codes )
            ));
        }
    }

    // =========================================================================
    // Validation
    // =========================================================================

    /**
     * Validate referral coupons: the redeemer must NOT be the referrer.
     */
    public static function validate_referral_coupon( $valid, $coupon, $discount ) {
        if ( ! $valid ) return $valid;

        $coupon_id = $coupon->get_id();
        $is_referral = get_post_meta( $coupon_id, 'slw_referral_coupon', true );

        if ( $is_referral !== 'yes' ) {
            return $valid;
        }

        // Block the referrer from using their own codes
        $referrer_email = get_post_meta( $coupon_id, 'slw_referrer_email', true );
        $current_email  = '';

        if ( is_user_logged_in() ) {
            $current_user  = wp_get_current_user();
            $current_email = $current_user->user_email;
        } elseif ( WC()->customer ) {
            $current_email = WC()->customer->get_billing_email();
        }

        if ( $current_email && strtolower( $current_email ) === strtolower( $referrer_email ) ) {
            throw new Exception(
                __( 'This referral code is for your friends — you can\'t use your own code!', 'sego-lily-wholesale' )
            );
        }

        return $valid;
    }

    // =========================================================================
    // Redemption Tracking + Rewards
    // =========================================================================

    /**
     * When an order completes with a referral coupon, reward the referrer.
     */
    public static function track_redemption( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) return;

        $coupons_used = $order->get_coupon_codes();
        if ( empty( $coupons_used ) ) return;

        foreach ( $coupons_used as $code ) {
            $coupon_id = wc_get_coupon_id_by_code( $code );
            if ( ! $coupon_id ) continue;

            $is_referral = get_post_meta( $coupon_id, 'slw_referral_coupon', true );
            if ( $is_referral !== 'yes' ) continue;

            $referrer_id = absint( get_post_meta( $coupon_id, 'slw_referrer_user_id', true ) );
            if ( ! $referrer_id ) continue;

            // Increment the referrer's conversion count
            $conversions = absint( get_user_meta( $referrer_id, 'slw_referral_conversions', true ) ) + 1;
            update_user_meta( $referrer_id, 'slw_referral_conversions', $conversions );

            // Record which code was redeemed and by whom
            $redeemer_email = $order->get_billing_email();
            $redeemer_name  = $order->get_billing_first_name() . ' ' . $order->get_billing_last_name();
            update_post_meta( $coupon_id, 'slw_redeemed_by_email', $redeemer_email );
            update_post_meta( $coupon_id, 'slw_redeemed_by_name', trim( $redeemer_name ) );
            update_post_meta( $coupon_id, 'slw_redeemed_order_id', $order_id );
            update_post_meta( $coupon_id, 'slw_redeemed_date', current_time( 'Y-m-d H:i:s' ) );

            // Generate reward coupon for the referrer
            $reward_code = self::generate_reward( $referrer_id, $conversions, $code );

            // Fire webhook
            $referrer = get_userdata( $referrer_id );
            if ( class_exists( 'SLW_Webhooks' ) && $referrer ) {
                $event_data = array(
                    'email'           => $referrer->user_email,
                    'first_name'      => $referrer->first_name,
                    'referral_code'   => $code,
                    'redeemer_name'   => trim( $redeemer_name ),
                    'redeemer_email'  => $redeemer_email,
                    'conversions'     => $conversions,
                    'reward_code'     => $reward_code,
                    'all_used'        => $conversions >= self::CODES_PER_CUSTOMER ? 'yes' : 'no',
                );

                SLW_Webhooks::fire( 'referral-code-redeemed', $event_data );

                // Extra tag when all 3 are used
                if ( $conversions >= self::CODES_PER_CUSTOMER ) {
                    SLW_Webhooks::fire( 'referral-all-codes-redeemed', array(
                        'email'      => $referrer->user_email,
                        'first_name' => $referrer->first_name,
                        'conversions' => $conversions,
                        'reward_code' => $reward_code,
                    ));
                }
            }
        }
    }

    /**
     * Generate a reward coupon for the referrer based on how many friends redeemed.
     *
     * @param int    $referrer_id  The referrer's user ID.
     * @param int    $conversions  How many referral codes have been redeemed (1, 2, or 3).
     * @param string $source_code  The referral code that was just redeemed.
     * @return string The reward coupon code.
     */
    private static function generate_reward( $referrer_id, $conversions, $source_code ) {
        $discount = self::REWARD_TIERS[ $conversions ] ?? 10;
        $referrer = get_userdata( $referrer_id );
        $name     = strtoupper( substr( preg_replace( '/[^A-Za-z]/', '', $referrer->first_name ?: 'REWARD' ), 0, 8 ) );
        $suffix   = strtoupper( wp_generate_password( 4, false ) );
        $expiry   = date( 'Y-m-d', strtotime( '+' . self::REWARD_EXPIRY_DAYS . ' days' ) );

        $label = $conversions >= self::CODES_PER_CUSTOMER ? 'BONUS' : 'REWARD';
        $code  = $label . '-' . $name . '-' . $suffix;

        // Ensure uniqueness
        while ( wc_get_coupon_id_by_code( $code ) ) {
            $suffix = strtoupper( wp_generate_password( 4, false ) );
            $code   = $label . '-' . $name . '-' . $suffix;
        }

        $coupon = new WC_Coupon();
        $coupon->set_code( $code );
        $coupon->set_discount_type( 'percent' );
        $coupon->set_amount( $discount );
        $coupon->set_usage_limit( 1 );
        $coupon->set_individual_use( true );
        $coupon->set_date_expires( $expiry );
        $coupon->set_email_restrictions( array( $referrer->user_email ) );
        $coupon->set_description(
            sprintf(
                'Referral reward #%d for %s (user #%d). Friend used code: %s',
                $conversions, $referrer->first_name, $referrer_id, $source_code
            )
        );
        $coupon->save();

        // Custom meta
        $coupon_id = $coupon->get_id();
        update_post_meta( $coupon_id, 'slw_referral_reward', 'yes' );
        update_post_meta( $coupon_id, 'slw_reward_tier', $conversions );
        update_post_meta( $coupon_id, 'slw_referrer_user_id', $referrer_id );
        update_post_meta( $coupon_id, 'slw_triggered_by_code', $source_code );

        // Store on user for easy lookup
        $rewards = json_decode( get_user_meta( $referrer_id, 'slw_referral_rewards', true ) ?: '[]', true );
        $rewards[] = array(
            'code'        => $code,
            'discount'    => $discount,
            'tier'        => $conversions,
            'created'     => current_time( 'Y-m-d H:i:s' ),
            'source_code' => $source_code,
        );
        update_user_meta( $referrer_id, 'slw_referral_rewards', wp_json_encode( $rewards ) );

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( sprintf(
                'SLW Referral: Reward tier %d — %d%% off code %s generated for user #%d',
                $conversions, $discount, $code, $referrer_id
            ));
        }

        return $code;
    }

    // =========================================================================
    // Admin UI
    // =========================================================================

    /**
     * Show referral codes and redemption status on user profile in admin.
     */
    public static function render_admin_referral_section( $user ) {
        // Only show for non-wholesale users who have referral codes
        if ( function_exists( 'slw_is_wholesale_user' ) && slw_is_wholesale_user( $user->ID ) ) {
            return;
        }

        $codes_json  = get_user_meta( $user->ID, 'slw_referral_codes', true );
        $conversions = absint( get_user_meta( $user->ID, 'slw_referral_conversions', true ) );
        $created     = get_user_meta( $user->ID, 'slw_referral_codes_created', true );
        $rewards     = json_decode( get_user_meta( $user->ID, 'slw_referral_rewards', true ) ?: '[]', true );

        if ( empty( $codes_json ) ) {
            return; // No referral codes generated yet
        }

        $codes = json_decode( $codes_json, true );
        if ( ! is_array( $codes ) ) return;

        ?>
        <h2>Referral Program</h2>
        <table class="form-table">
            <tr>
                <th>Referral Codes</th>
                <td>
                    <?php foreach ( $codes as $code ) :
                        $coupon_id = wc_get_coupon_id_by_code( $code );
                        $redeemed  = $coupon_id ? get_post_meta( $coupon_id, 'slw_redeemed_by_email', true ) : '';
                        $status    = $redeemed ? '&#10004; Used by ' . esc_html( $redeemed ) : 'Available';
                        $color     = $redeemed ? '#28a745' : '#6c757d';
                    ?>
                        <code style="display:inline-block;margin:2px 8px 2px 0;padding:4px 8px;background:#f0f0f0;border-radius:3px;">
                            <?php echo esc_html( $code ); ?>
                        </code>
                        <span style="color:<?php echo $color; ?>;font-size:12px;">
                            <?php echo $status; ?>
                        </span><br>
                    <?php endforeach; ?>
                </td>
            </tr>
            <tr>
                <th>Conversions</th>
                <td>
                    <strong><?php echo $conversions; ?></strong> of <?php echo self::CODES_PER_CUSTOMER; ?> codes redeemed
                    <?php if ( $conversions >= self::CODES_PER_CUSTOMER ) : ?>
                        <span style="color:#D4AF37;font-weight:bold;"> &mdash; All codes used!</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php if ( ! empty( $rewards ) ) : ?>
            <tr>
                <th>Reward Coupons Earned</th>
                <td>
                    <?php foreach ( $rewards as $r ) : ?>
                        <code style="display:inline-block;margin:2px 8px 2px 0;padding:4px 8px;background:#fff8e1;border-radius:3px;">
                            <?php echo esc_html( $r['code'] ); ?>
                        </code>
                        <span style="font-size:12px;">
                            <?php echo esc_html( $r['discount'] ); ?>% off (Tier <?php echo esc_html( $r['tier'] ); ?>)
                        </span><br>
                    <?php endforeach; ?>
                </td>
            </tr>
            <?php endif; ?>
            <tr>
                <th>Codes Generated</th>
                <td><?php echo esc_html( $created ); ?></td>
            </tr>
        </table>
        <?php
    }
}
