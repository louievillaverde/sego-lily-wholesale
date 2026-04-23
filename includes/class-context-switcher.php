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
     * Get the current shopping context from WC session.
     * Returns 'wholesale' or 'retail'. Default: 'wholesale'.
     */
    public static function get_context() {
        if ( function_exists( 'WC' ) && WC()->session ) {
            return WC()->session->get( 'slw_shopping_context', 'wholesale' );
        }
        return 'wholesale';
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
            bottom: 20px;
            left: 50%;
            transform: translateX(-50%);
            z-index: 99999;
            background: rgba(30, 42, 48, 0.85);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            border-radius: 28px;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 4px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.2);
            font-family: Georgia, 'Times New Roman', serif;
            opacity: 0.55;
            transition: opacity 0.3s;
        }
        #slw-context-bar:hover { opacity: 1; }
        #slw-context-bar .slw-ctx-btn {
            border: none !important;
            cursor: pointer;
            padding: 10px 20px !important;
            border-radius: 24px !important;
            font-size: 13px !important;
            font-weight: 600 !important;
            font-family: Georgia, 'Times New Roman', serif !important;
            display: inline-flex !important;
            align-items: center !important;
            gap: 6px;
            transition: all 0.2s;
            white-space: nowrap;
            appearance: none;
            -webkit-appearance: none;
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
            color: rgba(247,246,243,0.5) !important;
        }
        #slw-context-bar .slw-ctx-btn--inactive:hover {
            color: rgba(247,246,243,0.85) !important;
        }
        @media (max-width: 480px) {
            #slw-context-bar .slw-ctx-btn { padding: 8px 14px !important; font-size: 12px !important; }
        }
        </style>
        <div id="slw-context-bar">
            <button type="button"
                    id="slw-ctx-wholesale"
                    data-context="wholesale"
                    class="slw-ctx-btn <?php echo $current === 'wholesale' ? 'slw-ctx-btn--active-wholesale' : 'slw-ctx-btn--inactive'; ?>">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                For My Store
            </button>
            <button type="button"
                    id="slw-ctx-retail"
                    data-context="retail"
                    class="slw-ctx-btn <?php echo $current === 'retail' ? 'slw-ctx-btn--active-retail' : 'slw-ctx-btn--inactive'; ?>">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 0 1-8 0"/></svg>
                For Myself
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

            // Add bottom padding to body so the toggle bar doesn't overlap content
            document.body.style.paddingBottom = (parseInt(getComputedStyle(document.body).paddingBottom) || 0) + 56 + 'px';
        })();
        </script>
        <?php
    }
}
