<?php
/**
 * Shopping Context Switcher
 *
 * Adds a floating toggle bar for wholesale users to switch between
 * "For My Store" (wholesale pricing, minimums, B2B experience) and
 * "For Myself" (retail pricing, no minimums, personal shopping).
 *
 * Context is stored in WC()->session so it stays in sync with the cart.
 * Switching contexts clears the cart to prevent mixed-pricing line items.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class SLW_Context_Switcher {

    public static function init() {
        // Render the toggle bar in the footer (frontend only)
        add_action( 'wp_footer', array( __CLASS__, 'render_toggle_bar' ) );

        // AJAX handler for context switch
        add_action( 'wp_ajax_slw_switch_context', array( __CLASS__, 'ajax_switch_context' ) );
    }

    /**
     * Get the current shopping context.
     *
     * Priority order:
     *   1. WC session value if explicitly set this session
     *   2. Persistent user preference (last context the user chose, stored
     *      in user_meta) -- this keeps wholesale customers in wholesale
     *      mode across sessions instead of forcing them to re-toggle every
     *      visit. Important for NET 30 gateway availability + tier pricing.
     *   3. Default 'retail' for new users so casual browsing doesn't
     *      trigger wholesale minimums.
     */
    public static function get_context() {
        if ( function_exists( 'WC' ) && WC()->session ) {
            $session_value = WC()->session->get( 'slw_shopping_context', null );
            if ( $session_value === 'wholesale' || $session_value === 'retail' ) {
                return $session_value;
            }
        }
        if ( is_user_logged_in() ) {
            $pref = get_user_meta( get_current_user_id(), 'slw_preferred_context', true );
            if ( $pref === 'wholesale' || $pref === 'retail' ) {
                if ( function_exists( 'WC' ) && WC()->session ) {
                    WC()->session->set( 'slw_shopping_context', $pref );
                }
                return $pref;
            }
        }
        return 'retail';
    }

    /**
     * AJAX handler: switch between wholesale and retail context.
     * Clears the cart and updates the WC session.
     */
    public static function ajax_switch_context() {
        check_ajax_referer( 'slw_context_switch', 'nonce' );

        if ( ! is_user_logged_in() || ! slw_is_wholesale_user() ) {
            wp_send_json_error( array( 'message' => 'Not authorized.' ) );
        }

        $context = sanitize_key( $_POST['context'] ?? '' );
        if ( ! in_array( $context, array( 'wholesale', 'retail' ), true ) ) {
            wp_send_json_error( array( 'message' => 'Invalid context.' ) );
        }

        // Set session context
        if ( function_exists( 'WC' ) && WC()->session ) {
            WC()->session->set( 'slw_shopping_context', $context );
        }

        // Persist the choice on the user so it survives logout/login.
        update_user_meta( get_current_user_id(), 'slw_preferred_context', $context );

        // Clear the cart to prevent mixed pricing
        if ( function_exists( 'WC' ) && WC()->cart ) {
            WC()->cart->empty_cart();
        }

        wp_send_json_success( array(
            'context' => $context,
            'message' => $context === 'wholesale'
                ? 'Switched to wholesale pricing. Cart cleared.'
                : 'Switched to retail pricing. Cart cleared.',
        ) );
    }

    /**
     * Render the floating toggle bar in the site footer.
     * Only visible to logged-in wholesale users, hidden in admin.
     */
    public static function render_toggle_bar() {
        // Only show for wholesale users on the frontend
        if ( is_admin() ) return;
        if ( ! is_user_logged_in() || ! slw_is_wholesale_user() ) return;

        $current = self::get_context();
        $nonce   = wp_create_nonce( 'slw_context_switch' );
        $ajax_url = admin_url( 'admin-ajax.php' );
        ?>
        <style>
        #slw-context-bar {
            position: fixed;
            bottom: 18px;
            right: 18px;
            z-index: 99999;
            background: rgba(30, 42, 48, 0.78);
            backdrop-filter: blur(14px) saturate(140%);
            -webkit-backdrop-filter: blur(14px) saturate(140%);
            border: 1px solid rgba(247,246,243,0.08);
            border-radius: 999px;
            display: inline-flex;
            align-items: center;
            gap: 2px;
            padding: 3px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.18);
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Helvetica, Arial, sans-serif;
            opacity: 0.45;
            transition: opacity 0.25s, transform 0.25s;
            transform: scale(0.96);
        }
        #slw-context-bar:hover { opacity: 1; transform: scale(1); }
        #slw-context-bar .slw-ctx-btn {
            border: none !important;
            cursor: pointer;
            padding: 6px 12px !important;
            border-radius: 999px !important;
            font-size: 11.5px !important;
            font-weight: 600 !important;
            letter-spacing: 0.2px !important;
            font-family: inherit !important;
            display: inline-flex !important;
            align-items: center !important;
            gap: 5px;
            transition: background 0.18s, color 0.18s;
            white-space: nowrap;
            appearance: none;
            -webkit-appearance: none;
            line-height: 1.2 !important;
            height: auto !important;
            min-height: 0 !important;
            text-transform: none !important;
        }
        #slw-context-bar .slw-ctx-btn svg {
            width: 11px;
            height: 11px;
            flex: 0 0 auto;
        }
        #slw-context-bar .slw-ctx-btn--active-wholesale {
            background: #386174 !important;
            color: #F7F6F3 !important;
        }
        #slw-context-bar .slw-ctx-btn--active-retail {
            background: #D4AF37 !important;
            color: #1E2A30 !important;
        }
        #slw-context-bar .slw-ctx-btn--inactive {
            background: transparent !important;
            color: rgba(247,246,243,0.45) !important;
        }
        #slw-context-bar .slw-ctx-btn--inactive:hover {
            color: rgba(247,246,243,0.85) !important;
        }
        @media (max-width: 480px) {
            #slw-context-bar { bottom: 12px; right: 12px; opacity: 0.55; }
            #slw-context-bar .slw-ctx-btn { padding: 5px 10px !important; font-size: 11px !important; }
            #slw-context-bar .slw-ctx-btn svg { width: 10px; height: 10px; }
        }
        @media (prefers-reduced-motion: reduce) {
            #slw-context-bar { transition: none; transform: none; }
        }
        </style>
        <div id="slw-context-bar" role="group" aria-label="Shopping mode">
            <button type="button"
                    id="slw-ctx-retail"
                    data-context="retail"
                    aria-pressed="<?php echo $current === 'retail' ? 'true' : 'false'; ?>"
                    class="slw-ctx-btn <?php echo $current === 'retail' ? 'slw-ctx-btn--active-retail' : 'slw-ctx-btn--inactive'; ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 0 1-8 0"/></svg>
                Myself
            </button>
            <button type="button"
                    id="slw-ctx-wholesale"
                    data-context="wholesale"
                    aria-pressed="<?php echo $current === 'wholesale' ? 'true' : 'false'; ?>"
                    class="slw-ctx-btn <?php echo $current === 'wholesale' ? 'slw-ctx-btn--active-wholesale' : 'slw-ctx-btn--inactive'; ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                My Store
            </button>
        </div>

        <script>
        (function() {
            var ajaxUrl  = <?php echo wp_json_encode( $ajax_url ); ?>;
            var nonce    = <?php echo wp_json_encode( $nonce ); ?>;
            var current  = <?php echo wp_json_encode( $current ); ?>;

            var btns = document.querySelectorAll('#slw-context-bar button');
            btns.forEach(function(btn) {
                btn.addEventListener('click', function() {
                    var target = this.getAttribute('data-context');
                    if (target === current) return;

                    if (!confirm('Switching will clear your current cart. Continue?')) return;

                    // Disable buttons during request
                    btns.forEach(function(b) { b.disabled = true; b.style.opacity = '0.6'; });

                    var formData = new FormData();
                    formData.append('action', 'slw_switch_context');
                    formData.append('nonce', nonce);
                    formData.append('context', target);

                    var xhr = new XMLHttpRequest();
                    xhr.open('POST', ajaxUrl);
                    xhr.onload = function() {
                        // Reload regardless of response to refresh pricing
                        window.location.reload();
                    };
                    xhr.onerror = function() {
                        alert('Network error. Please try again.');
                        btns.forEach(function(b) { b.disabled = false; b.style.opacity = '1'; });
                    };
                    xhr.send(formData);
                });
            });

            // Corner-anchored toggle doesn't need body padding adjustment.
        })();
        </script>
        <?php
    }
}
