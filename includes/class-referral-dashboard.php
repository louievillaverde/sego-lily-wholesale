<?php
/**
 * Referral Dashboard (Customer-Facing)
 *
 * Shortcode: [slw_my_referrals]
 * Page: /my-referrals (auto-created on plugin activation)
 *
 * Shows logged-in retail customers:
 *   - Their 3 referral codes (with copy buttons)
 *   - Which codes have been used and by whom (first name only)
 *   - Progress bar: 0/3, 1/3, 2/3, 3/3
 *   - Reward coupons they've earned
 *   - Expiry date for remaining codes
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class SLW_Referral_Dashboard {

    public static function init() {
        add_shortcode( 'slw_my_referrals', array( __CLASS__, 'render' ) );
    }

    public static function render( $atts = array() ) {
        if ( ! is_user_logged_in() ) {
            return '<div style="text-align:center;padding:40px 20px;">'
                . '<p style="font-size:17px;color:#1E2A30;">Please <a href="' . esc_url( wp_login_url( get_permalink() ) ) . '">log in</a> to view your referral codes.</p>'
                . '</div>';
        }

        $user_id     = get_current_user_id();
        $codes_json  = get_user_meta( $user_id, 'slw_referral_codes', true );
        $conversions = absint( get_user_meta( $user_id, 'slw_referral_conversions', true ) );
        $created     = get_user_meta( $user_id, 'slw_referral_codes_created', true );
        $rewards     = json_decode( get_user_meta( $user_id, 'slw_referral_rewards', true ) ?: '[]', true );

        if ( empty( $codes_json ) ) {
            return '<div style="text-align:center;padding:40px 20px;">'
                . '<h2 style="font-family:\'Libre Baskerville\',Georgia,serif;color:#1E2A30;">Your Referral Codes</h2>'
                . '<p style="font-size:17px;color:#628393;">Your referral codes will appear here after your first order. '
                . 'Each code gives a friend 15% off, and you earn a reward every time one is used.</p>'
                . '</div>';
        }

        $codes = json_decode( $codes_json, true );
        if ( ! is_array( $codes ) ) return '';

        // Calculate expiry
        $expiry_date = '';
        $days_left   = 0;
        if ( $created ) {
            $expiry_ts   = strtotime( $created ) + ( 90 * DAY_IN_SECONDS );
            $expiry_date = date( 'F j, Y', $expiry_ts );
            $days_left   = max( 0, ceil( ( $expiry_ts - time() ) / DAY_IN_SECONDS ) );
        }

        $codes_used  = 0;
        $code_items  = array();
        foreach ( $codes as $code ) {
            $coupon_id = wc_get_coupon_id_by_code( $code );
            $redeemed_name = '';
            if ( $coupon_id ) {
                $redeemed_name = get_post_meta( $coupon_id, 'slw_redeemed_by_name', true );
                if ( $redeemed_name ) {
                    // Only show first name for privacy
                    $redeemed_name = explode( ' ', trim( $redeemed_name ) )[0];
                    $codes_used++;
                }
            }
            $code_items[] = array(
                'code'     => $code,
                'used'     => ! empty( $redeemed_name ),
                'used_by'  => $redeemed_name,
            );
        }

        $progress_pct = ( $codes_used / 3 ) * 100;

        ob_start();
        ?>
        <style>
            .slw-ref { max-width:600px; margin:0 auto; padding:24px; font-family:'Merriweather Sans','Helvetica Neue',sans-serif; color:#1E2A30; }
            .slw-ref h2 { font-family:'Libre Baskerville',Georgia,serif; font-size:28px; text-align:center; margin:0 0 8px; }
            .slw-ref-sub { text-align:center; color:#628393; font-size:15px; margin:0 0 32px; }
            .slw-ref-progress { background:#e8e8e8; border-radius:20px; height:12px; margin:0 0 8px; overflow:hidden; }
            .slw-ref-progress-bar { height:100%; border-radius:20px; transition:width 0.5s ease; }
            .slw-ref-progress-label { text-align:center; font-size:13px; color:#628393; margin:0 0 28px; }
            .slw-ref-code { display:flex; align-items:center; justify-content:space-between; padding:16px 20px; margin:0 0 12px; border-radius:8px; border:2px solid #e0e0e0; }
            .slw-ref-code.used { border-color:#28a745; background:#f6fff6; }
            .slw-ref-code-text { font-family:monospace; font-size:18px; font-weight:bold; letter-spacing:1.5px; }
            .slw-ref-code.used .slw-ref-code-text { text-decoration:line-through; color:#999; }
            .slw-ref-copy { background:#386174; color:#fff; border:none; padding:8px 16px; border-radius:6px; cursor:pointer; font-size:13px; font-weight:600; }
            .slw-ref-copy:hover { background:#2C4F5E; }
            .slw-ref-used-badge { color:#28a745; font-size:13px; font-weight:600; }
            .slw-ref-reward-section { margin-top:32px; padding-top:24px; border-top:1px solid #e0e0e0; }
            .slw-ref-reward { display:flex; align-items:center; justify-content:space-between; padding:12px 20px; margin:0 0 8px; background:#FEF8EC; border-radius:8px; border:1px solid #E8D8A0; }
            .slw-ref-reward-code { font-family:monospace; font-size:16px; font-weight:bold; color:#B8892E; }
            .slw-ref-reward-desc { font-size:13px; color:#628393; }
            .slw-ref-tier { margin-top:32px; padding:20px; background:#F7F6F3; border-radius:8px; }
            .slw-ref-tier h3 { font-family:'Libre Baskerville',Georgia,serif; font-size:18px; margin:0 0 12px; }
            .slw-ref-tier-row { display:flex; align-items:center; gap:12px; padding:6px 0; font-size:14px; }
            .slw-ref-tier-dot { width:24px; height:24px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:11px; font-weight:bold; color:#fff; flex-shrink:0; }
            .slw-ref-tier-dot.done { background:#28a745; }
            .slw-ref-tier-dot.pending { background:#ccc; }
            .slw-ref-expiry { text-align:center; margin-top:24px; font-size:13px; color:#999; }
        </style>

        <div class="slw-ref">
            <h2>Your Referral Codes</h2>
            <p class="slw-ref-sub">Share a code with a friend &mdash; they get 15% off, you earn a reward.</p>

            <!-- Progress -->
            <div class="slw-ref-progress">
                <div class="slw-ref-progress-bar" style="width:<?php echo $progress_pct; ?>%;background:<?php echo $codes_used >= 3 ? '#D4AF37' : '#386174'; ?>;"></div>
            </div>
            <p class="slw-ref-progress-label">
                <?php echo $codes_used; ?> of 3 codes used
                <?php if ( $codes_used >= 3 ) : ?>
                    &mdash; <strong style="color:#D4AF37;">All codes redeemed!</strong>
                <?php endif; ?>
            </p>

            <!-- Codes -->
            <?php foreach ( $code_items as $item ) : ?>
                <div class="slw-ref-code <?php echo $item['used'] ? 'used' : ''; ?>">
                    <span class="slw-ref-code-text"><?php echo esc_html( $item['code'] ); ?></span>
                    <?php if ( $item['used'] ) : ?>
                        <span class="slw-ref-used-badge">&#10004; Used by <?php echo esc_html( $item['used_by'] ); ?></span>
                    <?php else : ?>
                        <button class="slw-ref-copy" onclick="navigator.clipboard.writeText('<?php echo esc_js( $item['code'] ); ?>');this.textContent='Copied!';setTimeout(()=>this.textContent='Copy',2000);">Copy</button>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>

            <!-- Reward tiers -->
            <div class="slw-ref-tier">
                <h3>Your Rewards</h3>
                <div class="slw-ref-tier-row">
                    <span class="slw-ref-tier-dot <?php echo $conversions >= 1 ? 'done' : 'pending'; ?>"><?php echo $conversions >= 1 ? '&#10004;' : '1'; ?></span>
                    <span>1st friend orders &rarr; <strong>You get 10% off</strong></span>
                </div>
                <div class="slw-ref-tier-row">
                    <span class="slw-ref-tier-dot <?php echo $conversions >= 2 ? 'done' : 'pending'; ?>"><?php echo $conversions >= 2 ? '&#10004;' : '2'; ?></span>
                    <span>2nd friend orders &rarr; <strong>You get 10% off</strong></span>
                </div>
                <div class="slw-ref-tier-row">
                    <span class="slw-ref-tier-dot <?php echo $conversions >= 3 ? 'done' : 'pending'; ?>"><?php echo $conversions >= 3 ? '&#10004;' : '3'; ?></span>
                    <span>3rd friend orders &rarr; <strong>You get 15% off</strong></span>
                </div>
            </div>

            <!-- Earned rewards -->
            <?php if ( ! empty( $rewards ) ) : ?>
                <div class="slw-ref-reward-section">
                    <h3 style="font-family:'Libre Baskerville',Georgia,serif;font-size:18px;margin:0 0 12px;">Reward Coupons Earned</h3>
                    <?php foreach ( $rewards as $r ) : ?>
                        <div class="slw-ref-reward">
                            <div>
                                <span class="slw-ref-reward-code"><?php echo esc_html( $r['code'] ); ?></span>
                                <br><span class="slw-ref-reward-desc"><?php echo esc_html( $r['discount'] ); ?>% off your next order</span>
                            </div>
                            <button class="slw-ref-copy" onclick="navigator.clipboard.writeText('<?php echo esc_js( $r['code'] ); ?>');this.textContent='Copied!';setTimeout(()=>this.textContent='Copy',2000);">Copy</button>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <!-- Expiry -->
            <?php if ( $days_left > 0 && $codes_used < 3 ) : ?>
                <p class="slw-ref-expiry">
                    Your codes expire <?php echo esc_html( $expiry_date ); ?> (<?php echo $days_left; ?> days left)
                </p>
            <?php endif; ?>
        </div>
        <?php

        return ob_get_clean();
    }
}
