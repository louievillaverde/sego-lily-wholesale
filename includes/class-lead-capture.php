<?php
/**
 * Lead Capture
 *
 * Simple leads database for prospects who aren't ready to apply yet.
 * Provides a frontend shortcode [wholesale_lead_capture] and an admin
 * list/detail view for managing captured leads.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class SLW_Lead_Capture {

    public static function init() {
        add_shortcode( 'wholesale_lead_capture', array( __CLASS__, 'render_shortcode' ) );
        add_shortcode( 'wholesale_lead_capture_quick', array( __CLASS__, 'render_quick_shortcode' ) );
        add_action( 'wp_ajax_slw_capture_lead', array( __CLASS__, 'ajax_capture_lead' ) );
        add_action( 'wp_ajax_nopriv_slw_capture_lead', array( __CLASS__, 'ajax_capture_lead' ) );
        add_action( 'wp_ajax_slw_update_lead', array( __CLASS__, 'ajax_update_lead' ) );
        add_action( 'wp_ajax_slw_export_leads_csv', array( __CLASS__, 'ajax_export_csv' ) );
        add_action( 'wp_ajax_slw_bulk_leads_action', array( __CLASS__, 'ajax_bulk_action' ) );
        add_action( 'admin_post_slw_manual_add_lead', array( __CLASS__, 'handle_manual_add_lead' ) );
        add_action( 'admin_post_slw_set_active_event', array( __CLASS__, 'handle_set_active_event' ) );
    }

    // ------------------------------------------------------------------
    // Database
    // ------------------------------------------------------------------

    /**
     * Create the leads table. Called from activation hook.
     */
    public static function create_table() {
        global $wpdb;
        $table = $wpdb->prefix . 'slw_leads';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(255) NOT NULL,
            email VARCHAR(255) NOT NULL,
            business_name VARCHAR(255) DEFAULT '',
            phone VARCHAR(50) DEFAULT '',
            address TEXT DEFAULT '',
            ein VARCHAR(255) DEFAULT '',
            how_heard TEXT DEFAULT '',
            source VARCHAR(50) DEFAULT 'shortcode',
            status VARCHAR(20) DEFAULT 'new',
            captured_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            notes TEXT DEFAULT '',
            PRIMARY KEY (id),
            KEY status (status),
            KEY email (email)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    /**
     * Ensure the /wholesale-leads page exists.
     */
    public static function ensure_page() {
        if ( ! get_page_by_path( 'wholesale-leads' ) ) {
            wp_insert_post( array(
                'post_title'   => 'Become a Wholesale Partner',
                'post_content' => '[wholesale_lead_capture]',
                'post_status'  => 'publish',
                'post_type'    => 'page',
                'post_name'    => 'wholesale-leads',
            ) );
        }
    }

    // ------------------------------------------------------------------
    // Frontend Shortcode
    // ------------------------------------------------------------------

    public static function render_shortcode( $atts ) {
        $atts = shortcode_atts( array( 'mode' => '' ), $atts, 'wholesale_lead_capture' );

        // mode=quick delegates to the quick capture renderer
        if ( $atts['mode'] === 'quick' ) {
            return self::render_quick_shortcode( $atts );
        }

        // Enqueue plugin CSS
        wp_enqueue_style(
            'sego-lily-wholesale',
            SLW_PLUGIN_URL . 'assets/sego-lily-wholesale.css',
            array(),
            SLW_VERSION
        );

        // Read source + event from URL params (for QR code tracking)
        $url_source = sanitize_text_field( $_GET['source'] ?? '' );
        $url_event  = sanitize_text_field( $_GET['event'] ?? '' );

        // Fall back to the admin-set "active event" so iPad-direct fills (where
        // the URL has no ?event= query param) still tag the right trade show.
        if ( $url_event === '' ) {
            $active_event = trim( (string) get_option( 'slw_active_event', '' ) );
            if ( $active_event !== '' ) {
                $url_event = $active_event;
            }
        }
        $source_val = $url_source ? $url_source : 'website';

        ob_start();
        ?>
        <div class="slw-lead-capture">
            <div class="slw-lead-capture__form-wrap">
                <h2 class="slw-lead-capture__heading">Interested in Wholesale?</h2>
                <p class="slw-lead-capture__intro">Leave your details and we'll reach out with more information about our wholesale program.</p>

                <form id="slw-lead-form" class="slw-lead-capture__form">
                    <?php wp_nonce_field( 'slw_capture_lead', 'slw_lead_nonce' ); ?>
                    <input type="hidden" name="source" value="<?php echo esc_attr( $source_val ); ?>" />
                    <input type="hidden" name="event" value="<?php echo esc_attr( $url_event ); ?>" />

                    <div class="slw-lead-capture__field">
                        <label for="slw-lead-name">Name <span class="slw-required">*</span></label>
                        <input type="text" id="slw-lead-name" name="name" required />
                    </div>

                    <div class="slw-lead-capture__field">
                        <label for="slw-lead-email">Email <span class="slw-required">*</span></label>
                        <input type="email" id="slw-lead-email" name="email" required />
                    </div>

                    <div class="slw-lead-capture__field">
                        <label for="slw-lead-business">Business Name <span class="slw-required">*</span></label>
                        <input type="text" id="slw-lead-business" name="business_name" required />
                    </div>

                    <div class="slw-lead-capture__row">
                        <div class="slw-lead-capture__field">
                            <label for="slw-lead-phone">Phone <span class="slw-optional">(optional)</span></label>
                            <input type="tel" id="slw-lead-phone" name="phone" />
                        </div>
                        <div class="slw-lead-capture__field">
                            <label for="slw-lead-how-heard">How did you hear about us? <span class="slw-optional">(optional)</span></label>
                            <input type="text" id="slw-lead-how-heard" name="how_heard" />
                        </div>
                    </div>

                    <button type="submit" class="slw-btn slw-btn-cta slw-lead-capture__submit">
                        Get Started
                    </button>

                    <div class="slw-lead-capture__message" style="display:none;"></div>
                </form>
            </div>
        </div>

        <script>
        (function(){
            var form = document.getElementById('slw-lead-form');
            if (!form) return;
            form.addEventListener('submit', function(e) {
                e.preventDefault();
                var btn = form.querySelector('.slw-lead-capture__submit');
                var msg = form.querySelector('.slw-lead-capture__message');
                btn.disabled = true;
                btn.textContent = 'Submitting...';

                var data = new FormData(form);
                data.append('action', 'slw_capture_lead');

                fetch('<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>', {
                    method: 'POST',
                    body: data,
                    credentials: 'same-origin'
                })
                .then(function(r){ return r.json(); })
                .then(function(res){
                    msg.style.display = 'block';
                    if (res.success) {
                        msg.className = 'slw-lead-capture__message slw-lead-capture__message--success';
                        msg.textContent = 'Thank you! We\'ll be in touch soon.';
                        form.reset();
                    } else {
                        msg.className = 'slw-lead-capture__message slw-lead-capture__message--error';
                        msg.textContent = res.data || 'Something went wrong. Please try again.';
                    }
                    btn.disabled = false;
                    btn.textContent = 'Get Wholesale Info';
                })
                .catch(function(){
                    msg.style.display = 'block';
                    msg.className = 'slw-lead-capture__message slw-lead-capture__message--error';
                    msg.textContent = 'Network error. Please try again.';
                    btn.disabled = false;
                    btn.textContent = 'Get Wholesale Info';
                });
            });
        })();
        </script>
        <?php
        return ob_get_clean();
    }

    // ------------------------------------------------------------------
    // Quick Capture Shortcode (Trade Show Mode)
    // ------------------------------------------------------------------

    public static function render_quick_shortcode( $atts = array() ) {
        wp_enqueue_style(
            'sego-lily-wholesale',
            SLW_PLUGIN_URL . 'assets/sego-lily-wholesale.css',
            array(),
            SLW_VERSION
        );

        $url_source = sanitize_text_field( $_GET['source'] ?? '' );
        $url_event  = sanitize_text_field( $_GET['event'] ?? '' );
        $url_path   = sanitize_text_field( $_GET['path'] ?? '' );

        // Fall back to the admin-set "active event" so iPad-direct fills (where
        // the URL has no ?event= query param) still tag the right trade show.
        if ( $url_event === '' ) {
            $active_event = trim( (string) get_option( 'slw_active_event', '' ) );
            if ( $active_event !== '' ) {
                $url_event = $active_event;
            }
        }

        // Server-side initial step selection. ?path=retail or ?path=wholesale
        // skips the welcome card-picker and lands the visitor directly on the
        // matching quiz. Used by the Walk-By Retail QR so a customer scanning
        // from a poster lands straight on the retail quiz.
        $initial_step = '1';
        if ( $url_path === 'retail' ) {
            $initial_step = '2a';
        } elseif ( $url_path === 'wholesale' ) {
            $initial_step = '2b';
        }

        // Pull booth settings
        $retail_code    = esc_attr( get_option( 'slw_booth_retail_code', 'SEGO15' ) );
        $retail_offer   = esc_html( get_option( 'slw_booth_retail_offer', '15% off your first order' ) );
        $retail_url     = esc_url( get_option( 'slw_booth_retail_url', home_url( '/shop-all' ) ) );
        $wholesale_head = esc_html( get_option( 'slw_booth_wholesale_heading', "Welcome! Here's our wholesale price list" ) );
        $wholesale_offer = esc_html( get_option( 'slw_booth_wholesale_offer', 'Free shipping if you order at the show' ) );
        // Lazy-create the free-shipping coupon on first booth render so the
        // thank-you screen always has a working code to show.
        $wholesale_code  = esc_attr( self::ensure_wholesale_freeship_coupon() );
        $linesheet_url  = esc_url( home_url( '/wholesale-portal' ) );
        $apply_url      = esc_url( home_url( '/wholesale-partners' ) );

        ob_start();
        ?>
        <style>
        /* Inline critical booth CSS for full-page takeover on booth/tablet use */

        /* Full-page cream background */
        body.page-template-default:has(#slw-booth) { background: #F7F6F3 !important; }
        body { background: #F7F6F3 !important; }

        /* Main container, centered, full-height, vertically aligned */
        #slw-booth {
            max-width: 680px;
            margin: 0 auto;
            padding: 60px 24px;
            font-family: Georgia, 'Times New Roman', serif;
            text-align: center;
            min-height: 80vh;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }
        #slw-booth * { box-sizing: border-box; }

        /* Step visibility + animation */
        #slw-booth .slw-booth__step { display: none; animation: slw-booth-fade-in 0.3s ease both; }
        #slw-booth .slw-booth__step--active { display: flex; flex-direction: column; align-items: center; }
        @keyframes slw-booth-fade-in { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }

        /* Headings */
        #slw-booth .slw-booth__title {
            font-family: Georgia, 'Times New Roman', serif;
            font-size: 32px;
            font-weight: 700;
            color: #1E2A30;
            margin: 0 0 8px;
            line-height: 1.2;
        }
        #slw-booth .slw-booth__subtitle {
            font-size: 17px;
            color: #628393;
            margin: 0 0 40px;
        }

        /* Card selector, wider, overflow-safe */
        #slw-booth .slw-booth__cards {
            display: flex;
            gap: 20px;
            justify-content: center;
            flex-wrap: wrap;
            width: 100%;
        }
        #slw-booth .slw-booth__card {
            background: #fff !important;
            border: 2px solid #e0ddd8 !important;
            border-radius: 16px !important;
            padding: 44px 32px !important;
            cursor: pointer;
            text-align: center;
            width: 280px;
            max-width: calc(50% - 10px);
            display: flex !important;
            flex-direction: column !important;
            align-items: center !important;
            gap: 10px;
            transition: all 0.2s;
            appearance: none;
            -webkit-appearance: none;
            font-family: inherit;
            overflow: hidden;
            word-wrap: break-word;
        }
        #slw-booth .slw-booth__card:hover,
        #slw-booth .slw-booth__card:focus {
            background: #386174 !important;
            border-color: #386174 !important;
            box-shadow: 0 8px 32px rgba(56,97,116,0.2);
            transform: translateY(-4px);
            outline: none;
        }
        #slw-booth .slw-booth__card-icon { display: block; margin-bottom: 4px; flex-shrink: 0; }
        #slw-booth .slw-booth__card-label {
            font-family: Georgia, 'Times New Roman', serif;
            font-size: 20px;
            font-weight: 700;
            color: #1E2A30;
            line-height: 1.3;
            word-wrap: break-word;
            overflow-wrap: break-word;
        }
        #slw-booth .slw-booth__card-desc {
            font-size: 14px;
            color: #628393;
            line-height: 1.4;
        }
        #slw-booth .slw-booth__card:hover .slw-booth__card-label,
        #slw-booth .slw-booth__card:focus .slw-booth__card-label { color: #F7F6F3 !important; }
        #slw-booth .slw-booth__card:hover .slw-booth__card-desc,
        #slw-booth .slw-booth__card:focus .slw-booth__card-desc { color: rgba(247,246,243,0.8) !important; }
        #slw-booth .slw-booth__card:hover svg,
        #slw-booth .slw-booth__card:focus svg { stroke: #D4AF37 !important; }

        /* Text inputs */
        #slw-booth .slw-booth__input {
            display: block;
            width: 100%;
            max-width: 440px;
            margin: 24px auto 0;
            padding: 16px 20px;
            height: auto;
            font-size: 20px;
            font-family: Georgia, 'Times New Roman', serif;
            border: 2px solid #e0ddd8 !important;
            border-radius: 12px !important;
            background: #fff !important;
            color: #1E2A30;
            text-align: center;
            appearance: none;
            -webkit-appearance: none;
        }
        #slw-booth .slw-booth__input:focus {
            border-color: #386174 !important;
            box-shadow: 0 0 0 3px rgba(56,97,116,0.12);
            outline: none;
        }

        /* Pill buttons */
        #slw-booth .slw-booth__pills {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            justify-content: center;
            margin-top: 28px;
            width: 100%;
            max-width: 500px;
        }
        #slw-booth .slw-booth__pill {
            background: #fff !important;
            border: 2px solid #e0ddd8 !important;
            border-radius: 28px !important;
            padding: 14px 24px !important;
            font-size: 16px;
            font-family: Georgia, 'Times New Roman', serif;
            color: #1E2A30 !important;
            cursor: pointer;
            min-height: 48px;
            transition: all 0.2s;
            appearance: none;
            -webkit-appearance: none;
            flex: 0 1 auto;
        }
        #slw-booth .slw-booth__pill:hover { border-color: #386174 !important; }
        #slw-booth .slw-booth__pill--selected { background: #386174 !important; border-color: #386174 !important; color: #F7F6F3 !important; }

        /* Incentive page */
        #slw-booth .slw-booth__thanks { font-size: 18px; color: #386174; margin: 0 0 16px; font-style: italic; }
        #slw-booth .slw-booth__incentive-heading { font-family: Georgia, 'Times New Roman', serif; font-size: 28px; font-weight: 700; color: #1E2A30; margin: 0 0 24px; line-height: 1.3; }
        #slw-booth .slw-booth__incentive-sub { font-size: 17px; color: #628393; margin: 0 0 32px; }
        #slw-booth .slw-booth__code-badge {
            display: block;
            max-width: 380px;
            width: 100%;
            margin: 0 auto 24px;
            background: linear-gradient(135deg, #D4AF37, #c49b2a) !important;
            border: none !important;
            border-radius: 12px !important;
            padding: 20px 24px;
            font-size: 20px;
            font-weight: 700;
            font-family: Georgia, 'Times New Roman', serif;
            color: #1E2A30 !important;
            text-align: center;
            box-shadow: 0 4px 16px rgba(212,175,55,0.3);
        }

        /* CTA buttons */
        #slw-booth .slw-booth__cta-group { display: flex; gap: 16px; justify-content: center; flex-wrap: wrap; margin-top: 24px; width: 100%; }
        #slw-booth .slw-booth__cta {
            display: inline-flex !important;
            align-items: center;
            justify-content: center;
            padding: 16px 32px !important;
            font-size: 17px !important;
            font-family: Georgia, 'Times New Roman', serif !important;
            font-weight: 700 !important;
            border-radius: 10px !important;
            text-decoration: none !important;
            min-height: 52px;
            text-align: center;
            cursor: pointer;
            border: none !important;
            transition: all 0.2s;
            flex: 1 1 auto;
            max-width: 280px;
        }
        #slw-booth .slw-booth__cta--primary { background: #386174 !important; color: #F7F6F3 !important; box-shadow: 0 4px 16px rgba(56,97,116,0.3); }
        #slw-booth .slw-booth__cta--primary:hover { background: #2C4F5E !important; transform: translateY(-2px); }
        #slw-booth .slw-booth__cta--secondary { background: #D4AF37 !important; color: #1E2A30 !important; box-shadow: 0 4px 16px rgba(212,175,55,0.25); }
        #slw-booth .slw-booth__cta--secondary:hover { background: #BF9A2C !important; transform: translateY(-2px); }

        #slw-booth .slw-booth__question-label { font-size: 14px; color: #628393; margin: 16px 0 0; }

        /* Progress bar */
        #slw-booth .slw-booth__progress {
            width: 100%;
            max-width: 300px;
            margin: 0 auto 32px;
            display: none;
        }
        #slw-booth .slw-booth__progress--visible { display: block; }
        #slw-booth .slw-booth__progress-bar {
            height: 4px;
            background: #e0ddd8;
            border-radius: 2px;
            overflow: hidden;
        }
        #slw-booth .slw-booth__progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #386174, #D4AF37);
            border-radius: 2px;
            transition: width 0.4s ease;
        }
        #slw-booth .slw-booth__progress-label {
            font-size: 12px;
            color: #8A9499;
            margin-top: 8px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* Next button */
        #slw-booth .slw-booth__next-btn {
            display: block !important;
            margin: 24px auto 0;
            padding: 16px 48px !important;
            font-size: 18px !important;
            font-family: Georgia, 'Times New Roman', serif !important;
            font-weight: 700 !important;
            background: #386174 !important;
            color: #F7F6F3 !important;
            border: none !important;
            border-radius: 10px !important;
            cursor: pointer;
            transition: all 0.2s;
            appearance: none;
            -webkit-appearance: none;
            min-height: 52px;
            box-shadow: 0 4px 16px rgba(56,97,116,0.25);
        }
        #slw-booth .slw-booth__next-btn:hover {
            background: #2C4F5E !important;
            transform: translateY(-2px);
            box-shadow: 0 6px 24px rgba(56,97,116,0.35);
        }
        #slw-booth .slw-booth__enter-hint {
            font-size: 12px;
            color: #8A9499;
            margin-top: 10px;
        }

        /* Back button */
        #slw-booth .slw-booth__back-btn {
            display: inline-block !important;
            margin-top: 12px;
            padding: 10px 24px !important;
            font-size: 14px !important;
            font-family: Georgia, 'Times New Roman', serif !important;
            font-weight: 500 !important;
            background: transparent !important;
            color: #8A9499 !important;
            border: none !important;
            cursor: pointer;
            transition: color 0.2s;
            appearance: none;
            -webkit-appearance: none;
        }
        #slw-booth .slw-booth__back-btn:hover {
            color: #386174 !important;
        }

        /* Tablet + mobile */
        @media (max-width: 600px) {
            #slw-booth { padding: 40px 16px; }
            #slw-booth .slw-booth__title { font-size: 26px; }
            #slw-booth .slw-booth__card { width: 100%; max-width: 100%; padding: 28px 20px !important; }
            #slw-booth .slw-booth__cards { flex-direction: column; align-items: center; }
            #slw-booth .slw-booth__input { font-size: 18px; }
            #slw-booth .slw-booth__pill { font-size: 15px; padding: 12px 20px !important; }
            #slw-booth .slw-booth__incentive-heading { font-size: 24px; }
            #slw-booth .slw-booth__cta { max-width: 100%; width: 100%; }
            #slw-booth .slw-booth__cta-group { flex-direction: column; align-items: center; }
        }

        /* Loading overlay */
        #slw-booth .slw-booth__loading { position: absolute; inset: 0; background: rgba(247,246,243,0.85); display: flex; align-items: center; justify-content: center; z-index: 10; border-radius: 16px; }
        </style>
        <div class="slw-booth" id="slw-booth">
            <?php wp_nonce_field( 'slw_capture_lead', 'slw_lead_nonce' ); ?>
            <input type="hidden" id="slw-booth-source" value="<?php echo esc_attr( $url_source ); ?>" />
            <input type="hidden" id="slw-booth-event" value="<?php echo esc_attr( $url_event ); ?>" />

            <!-- Progress bar -->
            <div class="slw-booth__progress" id="slw-booth-progress">
                <div class="slw-booth__progress-bar"><div class="slw-booth__progress-fill" id="slw-booth-progress-fill" style="width:33%"></div></div>
                <div class="slw-booth__progress-label" id="slw-booth-progress-label">Step 1 of 3</div>
            </div>

            <!-- ============ STEP 1: Interest Selector ============ -->
            <div class="slw-booth__step<?php echo $initial_step === '1' ? ' slw-booth__step--active' : ''; ?>" data-step="1">
                <h2 class="slw-booth__title">Welcome to Sego Lily</h2>
                <p class="slw-booth__subtitle">How can we help you today?</p>
                <div class="slw-booth__cards">
                    <button type="button" class="slw-booth__card" data-path="retail" aria-label="I'm a Customer">
                        <span class="slw-booth__card-icon">
                            <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="#386174" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2L3 6v14a2 2 0 002 2h14a2 2 0 002-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 01-8 0"/></svg>
                        </span>
                        <span class="slw-booth__card-label">I'm a Customer</span>
                        <span class="slw-booth__card-desc">Shopping for myself</span>
                    </button>
                    <button type="button" class="slw-booth__card" data-path="wholesale" aria-label="I'm a Business">
                        <span class="slw-booth__card-icon">
                            <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="#386174" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                        </span>
                        <span class="slw-booth__card-label">I'm a Business</span>
                        <span class="slw-booth__card-desc">Interested in wholesale</span>
                    </button>
                </div>
            </div>

            <!-- ============ STEP 2a: Retail Path ============ -->
            <div class="slw-booth__step<?php echo $initial_step === '2a' ? ' slw-booth__step--active' : ''; ?>" data-step="2a">
                <div class="slw-booth__question slw-booth__question--active" data-q="r1">
                    <h2 class="slw-booth__title">What's your name?</h2>
                    <input type="text" class="slw-booth__input" id="slw-booth-r-name" placeholder="First name" autocomplete="given-name" />
                    <button type="button" class="slw-booth__next-btn" data-next="r2">Next &rarr;</button>
                    <button type="button" class="slw-booth__back-btn" data-back-step="1">&larr; Back</button>
                    <p class="slw-booth__enter-hint">or press Enter &crarr;</p>
                </div>
                <div class="slw-booth__question" data-q="r2">
                    <h2 class="slw-booth__title">What's your biggest skin concern?</h2>
                    <div class="slw-booth__pills slw-booth__pills--advance" data-next-q="r3" data-field="skin_concern">
                        <button type="button" class="slw-booth__pill" data-value="Dryness &amp; tightness">Dryness &amp; tightness</button>
                        <button type="button" class="slw-booth__pill" data-value="Breakouts">Breakouts</button>
                        <button type="button" class="slw-booth__pill" data-value="Redness &amp; sensitivity">Redness &amp; sensitivity</button>
                        <button type="button" class="slw-booth__pill" data-value="Wrinkles &amp; dark spots">Wrinkles &amp; dark spots</button>
                    </div>
                    <button type="button" class="slw-booth__back-btn" data-back-q="r1">&larr; Back</button>
                </div>
                <div class="slw-booth__question" data-q="r3">
                    <h2 class="slw-booth__title">How many skincare products do you use daily?</h2>
                    <div class="slw-booth__pills slw-booth__pills--advance" data-next-q="r4" data-field="product_count">
                        <button type="button" class="slw-booth__pill" data-value="1-3">1-3 products</button>
                        <button type="button" class="slw-booth__pill" data-value="4-6">4-6 products</button>
                        <button type="button" class="slw-booth__pill" data-value="7+">7+ products</button>
                    </div>
                    <button type="button" class="slw-booth__back-btn" data-back-q="r2">&larr; Back</button>
                </div>
                <div class="slw-booth__question" data-q="r4">
                    <h2 class="slw-booth__title">What frustrates you most about skincare?</h2>
                    <div class="slw-booth__pills slw-booth__pills--advance" data-next-q="r5" data-field="frustration">
                        <button type="button" class="slw-booth__pill" data-value="Nothing works long enough">Nothing works long enough</button>
                        <button type="button" class="slw-booth__pill" data-value="Too many products">Too many products</button>
                        <button type="button" class="slw-booth__pill" data-value="Don't trust ingredients">Don't trust ingredients</button>
                        <button type="button" class="slw-booth__pill" data-value="Just want something simple">Just want something simple</button>
                    </div>
                    <button type="button" class="slw-booth__back-btn" data-back-q="r3">&larr; Back</button>
                </div>
                <div class="slw-booth__question" data-q="r5">
                    <h2 class="slw-booth__title">Where should we send your personalized routine?</h2>
                    <p style="color:#628393;font-size:14px;margin:0 0 16px;text-align:center;">We'll match you with the right products based on your answers.</p>
                    <input type="email" class="slw-booth__input" id="slw-booth-r-email" placeholder="you@email.com" autocomplete="email" />
                    <button type="button" class="slw-booth__next-btn slw-booth__submit-btn" data-path="retail">Get My Results &rarr;</button>
                    <button type="button" class="slw-booth__back-btn" data-back-q="r4">&larr; Back</button>
                    <p class="slw-booth__enter-hint">or press Enter &crarr;</p>
                </div>
            </div>

            <!-- ============ STEP 2b: Wholesale Path ============ -->
            <div class="slw-booth__step<?php echo $initial_step === '2b' ? ' slw-booth__step--active' : ''; ?>" data-step="2b">
                <div class="slw-booth__question slw-booth__question--active" data-q="w1">
                    <h2 class="slw-booth__title">What's your name?</h2>
                    <input type="text" class="slw-booth__input" id="slw-booth-w-name" placeholder="First name" autocomplete="given-name" />
                    <button type="button" class="slw-booth__next-btn" data-next="w2">Next &rarr;</button>
                    <button type="button" class="slw-booth__back-btn" data-back-step="1">&larr; Back</button>
                    <p class="slw-booth__enter-hint">or press Enter &crarr;</p>
                </div>
                <div class="slw-booth__question" data-q="w2">
                    <h2 class="slw-booth__title">What type of business?</h2>
                    <div class="slw-booth__pills slw-booth__pills--advance" data-next-q="w3" data-field="business_type">
                        <button type="button" class="slw-booth__pill" data-value="Boutique">Boutique</button>
                        <button type="button" class="slw-booth__pill" data-value="Salon / Spa">Salon / Spa</button>
                        <button type="button" class="slw-booth__pill" data-value="Health Store">Health Store</button>
                        <button type="button" class="slw-booth__pill" data-value="Other">Other</button>
                    </div>
                    <button type="button" class="slw-booth__back-btn" data-back-q="w1">&larr; Back</button>
                </div>
                <div class="slw-booth__question" data-q="w3">
                    <h2 class="slw-booth__title">Do you currently carry skincare?</h2>
                    <div class="slw-booth__pills slw-booth__pills--advance" data-next-q="w4" data-field="skincare_experience">
                        <button type="button" class="slw-booth__pill" data-value="Yes, looking to add">Yes, looking to add</button>
                        <button type="button" class="slw-booth__pill" data-value="Yes, looking to switch">Yes, looking to switch</button>
                        <button type="button" class="slw-booth__pill" data-value="No, first time">No, first time</button>
                    </div>
                    <button type="button" class="slw-booth__back-btn" data-back-q="w2">&larr; Back</button>
                </div>
                <div class="slw-booth__question" data-q="w4">
                    <h2 class="slw-booth__title">What draws you to tallow butter?</h2>
                    <div class="slw-booth__pills slw-booth__pills--advance" data-next-q="w5" data-field="tallow_interest">
                        <button type="button" class="slw-booth__pill" data-value="Customers asking for it">Customers asking for it</button>
                        <button type="button" class="slw-booth__pill" data-value="Clean ingredient trend">Clean ingredient trend</button>
                        <button type="button" class="slw-booth__pill" data-value="Better margins">Better margins</button>
                        <button type="button" class="slw-booth__pill" data-value="Personal believer">Personal believer</button>
                    </div>
                    <button type="button" class="slw-booth__back-btn" data-back-q="w3">&larr; Back</button>
                </div>
                <div class="slw-booth__question" data-q="w5">
                    <h2 class="slw-booth__title">Where should we send your wholesale catalog + pricing?</h2>
                    <p style="color:#628393;font-size:14px;margin:0 0 16px;text-align:center;">We'll send you our full product line, pricing tiers, and how to get started.</p>
                    <input type="email" class="slw-booth__input" id="slw-booth-w-email" placeholder="you@email.com" autocomplete="email" />
                    <button type="button" class="slw-booth__next-btn slw-booth__submit-btn" data-path="wholesale">Get Wholesale Info &rarr;</button>
                    <button type="button" class="slw-booth__back-btn" data-back-q="w4">&larr; Back</button>
                    <p class="slw-booth__enter-hint">or press Enter &crarr;</p>
                </div>
            </div>

            <!-- ============ STEP 3a: Retail Incentive (in-person) ============ -->
            <div class="slw-booth__step" data-step="3a">
                <p class="slw-booth__thanks">Thanks, <span id="slw-booth-r-thanksname"></span>!</p>
                <h2 class="slw-booth__incentive-heading"><?php echo $retail_offer; ?></h2>
                <div class="slw-booth__code-badge" style="font-size:22px;padding:16px 24px;">Show this screen to Holly</div>
                <p style="text-align:center;color:#628393;font-size:14px;margin:12px 0 0;">Code <strong><?php echo $retail_code; ?></strong> also saved for online use</p>
                <div style="margin-top:24px;padding:20px;background:#F7F6F3;border-radius:8px;text-align:center;">
                    <p style="color:#386174;font-size:15px;font-weight:600;margin:0 0 6px;">Check your inbox</p>
                    <p style="color:#628393;font-size:14px;margin:0;">Your personalized skincare routine is on the way.</p>
                </div>
                <div style="margin-top:16px;padding:16px 20px;background:#FEF8EC;border:1px solid #E8D8A0;border-radius:8px;text-align:center;">
                    <p style="color:#B8892E;font-size:14px;font-weight:600;margin:0 0 4px;">After your first order, you'll get 3 personal codes to share with friends</p>
                    <p style="color:#628393;font-size:13px;margin:0;">They get 15% off. You earn a reward every time.</p>
                </div>
            </div>

            <!-- ============ STEP 3b: Wholesale Incentive ============ -->
            <div class="slw-booth__step" data-step="3b">
                <p class="slw-booth__thanks">Thanks, <span id="slw-booth-w-thanksname"></span>!</p>
                <h2 class="slw-booth__incentive-heading"><?php echo $wholesale_offer; ?></h2>
                <div class="slw-booth__code-badge" style="font-size:22px;padding:16px 24px;">Show this screen to Holly</div>
                <?php if ( $wholesale_code !== '' ) : ?>
                    <p style="text-align:center;color:#628393;font-size:14px;margin:12px 0 0;">Use code <strong><?php echo $wholesale_code; ?></strong> at checkout</p>
                <?php else : ?>
                    <p style="text-align:center;color:#628393;font-size:14px;margin:12px 0 0;">Place your order at the show to unlock the bonus.</p>
                <?php endif; ?>
                <div style="margin-top:24px;padding:20px;background:#F7F6F3;border-radius:8px;text-align:center;">
                    <p style="color:#386174;font-size:15px;font-weight:600;margin:0 0 6px;">Check your inbox</p>
                    <p style="color:#628393;font-size:14px;margin:0;">Your wholesale catalog + pricing is on the way. Holly will review your info and follow up personally.</p>
                </div>
            </div>

            <!-- Loading overlay -->
            <div class="slw-booth__loading" id="slw-booth-loading" style="display:none;">
                <div class="slw-booth__spinner"></div>
            </div>
        </div>

        <script>
        (function(){
            var booth = document.getElementById('slw-booth');
            if (!booth) return;

            var ajaxUrl  = '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>';
            var nonce    = booth.querySelector('[name="slw_lead_nonce"]').value;
            var urlSrc   = document.getElementById('slw-booth-source').value;
            var urlEvt   = document.getElementById('slw-booth-event').value;

            var currentPath = ''; // 'retail' or 'wholesale'
            var resetTimer  = null;

            // ---- Helpers ----
            function showStep(stepId) {
                booth.querySelectorAll('.slw-booth__step').forEach(function(s){
                    s.classList.remove('slw-booth__step--active');
                });
                var target = booth.querySelector('[data-step="' + stepId + '"]');
                if (target) {
                    target.classList.add('slw-booth__step--active');
                }
            }

            function showQuestion(qId) {
                var step = booth.querySelector('.slw-booth__step--active');
                if (!step) return;
                step.querySelectorAll('.slw-booth__question').forEach(function(q){
                    q.classList.remove('slw-booth__question--active');
                });
                var target = step.querySelector('[data-q="' + qId + '"]');
                if (target) {
                    target.classList.add('slw-booth__question--active');
                    var inp = target.querySelector('.slw-booth__input');
                    if (inp) setTimeout(function(){ inp.focus(); }, 350);
                }
            }

            function resetBooth() {
                clearTimeout(resetTimer);
                currentPath = '';
                showStep('1');
                // Clear inputs
                booth.querySelectorAll('.slw-booth__input').forEach(function(i){ i.value = ''; });
                booth.querySelectorAll('.slw-booth__pill').forEach(function(p){ p.classList.remove('slw-booth__pill--selected'); });
                // Reset questions to first
                booth.querySelectorAll('.slw-booth__step').forEach(function(s){
                    var first = s.querySelector('.slw-booth__question');
                    if (first) {
                        s.querySelectorAll('.slw-booth__question').forEach(function(q){ q.classList.remove('slw-booth__question--active'); });
                        first.classList.add('slw-booth__question--active');
                    }
                });
            }

            function startResetTimer() {
                clearTimeout(resetTimer);
                resetTimer = setTimeout(resetBooth, 30000);
            }

            // ---- Step 1: Card selection ----
            booth.querySelectorAll('.slw-booth__card').forEach(function(card){
                card.addEventListener('click', function(){
                    currentPath = this.getAttribute('data-path');
                    if (currentPath === 'retail') {
                        showStep('2a');
                        var inp = booth.querySelector('#slw-booth-r-name');
                        if (inp) setTimeout(function(){ inp.focus(); }, 350);
                    } else {
                        showStep('2b');
                        var inp = booth.querySelector('#slw-booth-w-name');
                        if (inp) setTimeout(function(){ inp.focus(); }, 350);
                    }
                });
            });

            // ---- Text input: advance on Enter ----
            booth.querySelectorAll('.slw-booth__input').forEach(function(inp){
                inp.addEventListener('keydown', function(e){
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        var val = this.value.trim();
                        if (!val) return;

                        var q = this.closest('.slw-booth__question');
                        var qId = q.getAttribute('data-q');

                        // Validate email
                        if (this.type === 'email' && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(val)) {
                            this.classList.add('slw-booth__input--error');
                            setTimeout(function(){ inp.classList.remove('slw-booth__input--error'); }, 1500);
                            return;
                        }

                        // If this question has a submit button, click it instead of advancing
                        var submitBtn = q.querySelector('.slw-booth__submit-btn');
                        if (submitBtn) {
                            submitBtn.click();
                            return;
                        }

                        // Advance to next question
                        var prefix = qId.charAt(0);
                        var num = parseInt(qId.charAt(1), 10);
                        showQuestion(prefix + (num + 1));
                    }
                });
            });

            // ---- Next button click (non-submit): advance to next question ----
            booth.querySelectorAll('.slw-booth__next-btn:not(.slw-booth__submit-btn)').forEach(function(btn){
                btn.addEventListener('click', function(){
                    var nextQ = this.getAttribute('data-next');
                    var q = this.closest('.slw-booth__question');
                    var inp = q.querySelector('.slw-booth__input');
                    if (inp) {
                        var val = inp.value.trim();
                        if (!val) { inp.focus(); return; }
                        if (inp.type === 'email' && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(val)) {
                            inp.classList.add('slw-booth__input--error');
                            setTimeout(function(){ inp.classList.remove('slw-booth__input--error'); }, 1500);
                            inp.focus();
                            return;
                        }
                    }
                    showQuestion(nextQ);
                });
            });

            // ---- Back button click ----
            booth.querySelectorAll('.slw-booth__back-btn').forEach(function(btn){
                btn.addEventListener('click', function(){
                    var backStep = this.getAttribute('data-back-step');
                    var backQ    = this.getAttribute('data-back-q');
                    if (backStep) {
                        showStep(backStep);
                    } else if (backQ) {
                        showQuestion(backQ);
                    }
                });
            });

            // ---- Progress bar updates ----
            var progressFill  = document.getElementById('slw-booth-progress-fill');
            var progressLabel = document.getElementById('slw-booth-progress-label');
            var progressWrap  = document.getElementById('slw-booth-progress');

            function updateProgress(step, total) {
                if (!progressFill) return;
                progressWrap.classList.add('slw-booth__progress--visible');
                var pct = Math.round((step / total) * 100);
                progressFill.style.width = pct + '%';
                progressLabel.textContent = 'Step ' + step + ' of ' + total;
            }

            // Show progress when entering step 2
            var origShowStep = showStep;
            showStep = function(stepId) {
                origShowStep(stepId);
                if (stepId === '1') { progressWrap.classList.remove('slw-booth__progress--visible'); }
                else if (stepId === '2a' || stepId === '2b') { updateProgress(2, 3); }
                else if (stepId === '3a' || stepId === '3b') { updateProgress(3, 3); }
            };

            // Update progress on question advance within step 2
            var origShowQ = showQuestion;
            showQuestion = function(qId) {
                origShowQ(qId);
                var num = parseInt(qId.charAt(1), 10);
                if (num && progressFill) {
                    // Both paths now have 5 questions
                    var subPct = 33 + (num / 5) * 33;
                    progressFill.style.width = Math.min(subPct, 66) + '%';
                }
            };

            // ---- Track quiz answers across pill steps ----
            var quizData = {};

            // ---- Pill selection: store value + advance to next question ----
            booth.querySelectorAll('.slw-booth__pill').forEach(function(pill){
                pill.addEventListener('click', function(){
                    var pillGroup = this.closest('.slw-booth__pills');
                    pillGroup.querySelectorAll('.slw-booth__pill').forEach(function(p){
                        p.classList.remove('slw-booth__pill--selected');
                    });
                    this.classList.add('slw-booth__pill--selected');

                    var fieldName = pillGroup.getAttribute('data-field');
                    if (fieldName) {
                        quizData[fieldName] = this.getAttribute('data-value');
                    }

                    var nextQ = pillGroup.getAttribute('data-next-q');
                    if (nextQ) showQuestion(nextQ);
                });
            });

            // ---- Submit button (on the email question) ----
            booth.querySelectorAll('.slw-booth__submit-btn').forEach(function(btn){
                btn.addEventListener('click', function(){
                    var isRetail = this.getAttribute('data-path') === 'retail';
                    var nameVal, emailVal;

                    if (isRetail) {
                        nameVal  = document.getElementById('slw-booth-r-name').value.trim();
                        emailVal = document.getElementById('slw-booth-r-email').value.trim();
                    } else {
                        nameVal  = document.getElementById('slw-booth-w-name').value.trim();
                        emailVal = document.getElementById('slw-booth-w-email').value.trim();
                    }

                    if (!nameVal || !emailVal) return;

                    // Show loading
                    document.getElementById('slw-booth-loading').style.display = 'flex';

                    // Build form data
                    var fd = new FormData();
                    fd.append('action', 'slw_capture_lead');
                    fd.append('slw_lead_nonce', nonce);
                    fd.append('quick_mode', '1');
                    fd.append('booth_mode', '1');
                    fd.append('name', nameVal);
                    fd.append('email', emailVal);
                    fd.append('source', urlSrc || (isRetail ? 'retail_booth' : 'wholesale_booth'));
                    fd.append('event', urlEvt);

                    if (isRetail) {
                        fd.append('how_heard', quizData.skin_concern || '');
                        fd.append('product_count', quizData.product_count || '');
                        fd.append('frustration', quizData.frustration || '');
                    } else {
                        fd.append('how_heard', quizData.business_type || '');
                        fd.append('business_type', quizData.business_type || '');
                        fd.append('skincare_experience', quizData.skincare_experience || '');
                        fd.append('tallow_interest', quizData.tallow_interest || '');
                    }

                    fetch(ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
                    .then(function(r){ return r.json(); })
                    .then(function(res){
                        document.getElementById('slw-booth-loading').style.display = 'none';
                        // Show incentive regardless of duplicate error
                        if (isRetail) {
                            document.getElementById('slw-booth-r-thanksname').textContent = nameVal.split(' ')[0];
                            showStep('3a');
                        } else {
                            document.getElementById('slw-booth-w-thanksname').textContent = nameVal.split(' ')[0];
                            showStep('3b');
                        }
                        startResetTimer();
                    })
                    .catch(function(){
                        document.getElementById('slw-booth-loading').style.display = 'none';
                        // Show incentive even on network error
                        if (isRetail) {
                            document.getElementById('slw-booth-r-thanksname').textContent = nameVal.split(' ')[0];
                            showStep('3a');
                        } else {
                            document.getElementById('slw-booth-w-thanksname').textContent = nameVal.split(' ')[0];
                            showStep('3b');
                        }
                        startResetTimer();
                    });
                });
            });
        })();
        </script>
        <?php
        return ob_get_clean();
    }

    // ------------------------------------------------------------------
    // AJAX: Capture Lead
    // ------------------------------------------------------------------

    public static function ajax_capture_lead() {
        check_ajax_referer( 'slw_capture_lead', 'slw_lead_nonce' );

        $quick_mode    = ! empty( $_POST['quick_mode'] );
        $booth_mode    = ! empty( $_POST['booth_mode'] );
        $name          = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
        $email         = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
        $business_name = sanitize_text_field( wp_unslash( $_POST['business_name'] ?? '' ) );
        $business_type = sanitize_text_field( wp_unslash( $_POST['business_type'] ?? '' ) );
        $phone         = sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) );
        $how_heard     = sanitize_text_field( wp_unslash( $_POST['how_heard'] ?? '' ) );
        $address       = sanitize_textarea_field( wp_unslash( $_POST['address'] ?? '' ) );
        $ein_plain     = sanitize_text_field( wp_unslash( $_POST['ein'] ?? '' ) );
        $product_count       = sanitize_text_field( wp_unslash( $_POST['product_count'] ?? '' ) );
        $frustration         = sanitize_text_field( wp_unslash( $_POST['frustration'] ?? '' ) );
        $skincare_experience = sanitize_text_field( wp_unslash( $_POST['skincare_experience'] ?? '' ) );
        $tallow_interest     = sanitize_text_field( wp_unslash( $_POST['tallow_interest'] ?? '' ) );
        $source              = sanitize_text_field( wp_unslash( $_POST['source'] ?? 'website' ) );
        $event         = sanitize_text_field( wp_unslash( $_POST['event'] ?? '' ) );

        // Validate allowed sources
        $allowed_sources = array( 'website', 'trade_show', 'referral', 'social_media', 'phone_call', 'other', 'shortcode', 'manual', 'retail_booth', 'wholesale_booth', 'retail_walkby' );
        if ( ! in_array( $source, $allowed_sources, true ) ) {
            $source = 'website';
        }

        // Quick mode only requires name + email
        if ( $quick_mode ) {
            if ( empty( $name ) || empty( $email ) ) {
                wp_send_json_error( 'Please fill in name and email.' );
            }
        } else {
            if ( empty( $name ) || empty( $email ) || empty( $business_name ) ) {
                wp_send_json_error( 'Please fill in all required fields.' );
            }
        }

        if ( ! is_email( $email ) ) {
            wp_send_json_error( 'Please enter a valid email address.' );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'slw_leads';

        // Check for duplicate email
        $exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE email = %s",
            $email
        ) );
        if ( $exists ) {
            wp_send_json_error( 'This email has already been submitted.' );
        }

        // If event is provided, append it to how_heard for tracking
        if ( $event && empty( $how_heard ) ) {
            $how_heard = 'Event: ' . $event;
        } elseif ( $event ) {
            $how_heard .= ' | Event: ' . $event;
        }

        // Booth mode: store quiz data in notes for wholesale leads
        $notes = '';
        if ( $booth_mode && $business_type ) {
            $note_parts = array( 'Business Type: ' . $business_type );
            if ( $skincare_experience ) $note_parts[] = 'Skincare Exp: ' . $skincare_experience;
            if ( $tallow_interest )     $note_parts[] = 'Tallow Interest: ' . $tallow_interest;
            $notes = implode( ' | ', $note_parts );
        }

        // ── Retail leads (booth quiz + walk-by retail QR) go to WooCommerce, not the wholesale leads table ──
        if ( $source === 'retail_booth' || $source === 'retail_walkby' ) {
            // Create or find WooCommerce customer
            $existing_user = get_user_by( 'email', $email );
            if ( ! $existing_user ) {
                $name_parts = explode( ' ', $name, 2 );
                $first = $name_parts[0];
                $last  = $name_parts[1] ?? '';
                $user_id = wc_create_new_customer( $email, '', wp_generate_password() );
                if ( ! is_wp_error( $user_id ) ) {
                    update_user_meta( $user_id, 'first_name', $first );
                    update_user_meta( $user_id, 'last_name', $last );
                    update_user_meta( $user_id, 'billing_phone', $phone );
                    update_user_meta( $user_id, 'slw_booth_source', $source );
                    update_user_meta( $user_id, 'slw_booth_event', $event );
                    update_user_meta( $user_id, 'slw_skin_concern', $how_heard );
                    if ( $product_count ) update_user_meta( $user_id, 'slw_product_count', $product_count );
                    if ( $frustration )   update_user_meta( $user_id, 'slw_frustration', $frustration );
                }
            }

            // Fire webhook with retail tag for Mautic segmentation
            if ( class_exists( 'SLW_Webhooks' ) ) {
                SLW_Webhooks::fire( 'retail-lead-captured', array(
                    'email'         => $email,
                    'first_name'    => $name_parts[0] ?? $name,
                    'source'        => $source,
                    'event'         => $event,
                    'skin_concern'  => $how_heard,
                    'product_count' => $product_count,
                    'frustration'   => $frustration,
                ) );
            }

            // Also store a count in options for the dashboard display
            $retail_count = (int) get_option( 'slw_retail_booth_leads', 0 );
            update_option( 'slw_retail_booth_leads', $retail_count + 1 );

            wp_send_json_success();
            return;
        }

        // ── Wholesale leads go to the leads table ──
        $ein_encrypted = $ein_plain && class_exists( 'SLW_Encryption' ) ? SLW_Encryption::encrypt( $ein_plain ) : $ein_plain;
        $wpdb->insert( $table, array(
            'name'          => $name,
            'email'         => $email,
            'business_name' => $business_name,
            'phone'         => $phone,
            'address'       => $address,
            'ein'           => $ein_encrypted,
            'how_heard'     => $how_heard,
            'source'        => $source,
            'status'        => 'new',
            'captured_at'   => current_time( 'mysql' ),
            'notes'         => $notes,
        ), array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ) );

        // Also create an application record for booth wholesale leads
        // so Holly can approve/deny them through the same system
        if ( $source === 'wholesale_booth' ) {
            $app_table = $wpdb->prefix . 'slw_applications';
            $wpdb->insert( $app_table, array(
                'business_name' => $business_type ? $business_type . ': ' . $name : $name,
                'contact_name'  => $name,
                'email'         => $email,
                'phone'         => $phone,
                'address'       => '',
                'website'       => '',
                'ein'           => '',
                'business_type' => $business_type,
                'how_heard'     => $event ? 'Trade show: ' . $event : 'Trade show booth',
                'why_carry'     => $tallow_interest,
                'status'        => 'pending',
                'ip_address'    => '',
                'submitted_at'  => current_time( 'mysql' ),
            ) );
            $new_app_id = $wpdb->insert_id;

            // Send Holly the approve/deny notification email
            if ( $new_app_id && class_exists( 'SLW_Email_Approve' ) ) {
                $app_row = $wpdb->get_row( $wpdb->prepare(
                    "SELECT * FROM {$app_table} WHERE id = %d", $new_app_id
                ) );
                if ( $app_row ) {
                    $admin_email = get_option( 'slw_admin_notification_email' );
                    if ( ! $admin_email && class_exists( 'SLW_Email_Settings' ) ) {
                        $admin_email = SLW_Email_Settings::get( 'from_address' );
                    }
                    if ( ! $admin_email ) {
                        $admin_email = get_option( 'admin_email' );
                    }

                    $subject = 'Booth Lead. Wholesale Inquiry: ' . $name;
                    $body    = SLW_Email_Approve::build_notification_html( $app_row, 'booth', array(
                        'skincare_experience' => $skincare_experience,
                        'tallow_interest'     => $tallow_interest,
                        'event'               => $event,
                    ) );

                    $headers   = class_exists( 'SLW_Email_Settings' ) ? SLW_Email_Settings::get_headers() : array();
                    $headers[] = 'Content-Type: text/html; charset=UTF-8';
                    $headers[] = 'Reply-To: ' . $email;
                    wp_mail( $admin_email, $subject, $body, $headers );
                }
            }
        }

        // Fire webhook
        if ( class_exists( 'SLW_Webhooks' ) ) {
            SLW_Webhooks::fire( 'lead-captured', array(
                'email'               => $email,
                'first_name'          => explode( ' ', $name, 2 )[0],
                'name'                => $name,
                'business_name'       => $business_name,
                'business_type'       => $business_type,
                'skincare_experience' => $skincare_experience,
                'tallow_interest'     => $tallow_interest,
                'source'              => $source,
                'event'               => $event,
            ) );
        }

        wp_send_json_success();
    }

    // ------------------------------------------------------------------
    // AJAX: Update Lead (admin)
    // ------------------------------------------------------------------

    public static function ajax_update_lead() {
        check_ajax_referer( 'slw_update_lead', 'slw_lead_nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $id     = absint( $_POST['lead_id'] ?? 0 );
        $status = sanitize_text_field( $_POST['status'] ?? '' );
        $notes  = sanitize_textarea_field( wp_unslash( $_POST['notes'] ?? '' ) );

        if ( ! $id ) {
            wp_send_json_error( 'Invalid lead.' );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'slw_leads';

        $update = array();
        $formats = array();

        if ( $status ) {
            $update['status'] = $status;
            $formats[] = '%s';
        }
        if ( isset( $_POST['notes'] ) ) {
            $update['notes'] = $notes;
            $formats[] = '%s';
        }

        if ( ! empty( $update ) ) {
            $wpdb->update( $table, $update, array( 'id' => $id ), $formats, array( '%d' ) );
        }

        wp_send_json_success();
    }

    // ------------------------------------------------------------------
    // AJAX: Bulk Action
    // ------------------------------------------------------------------

    public static function ajax_bulk_action() {
        check_ajax_referer( 'slw_bulk_leads', 'slw_lead_nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $ids    = array_map( 'absint', (array) ( $_POST['ids'] ?? array() ) );
        $action = sanitize_text_field( $_POST['bulk_action'] ?? '' );

        if ( empty( $ids ) || empty( $action ) ) {
            wp_send_json_error( 'No items selected.' );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'slw_leads';
        $allowed_statuses = array( 'new', 'contacted', 'converted', 'archived' );

        if ( in_array( $action, $allowed_statuses, true ) ) {
            $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
            $wpdb->query( $wpdb->prepare(
                "UPDATE {$table} SET status = %s WHERE id IN ({$placeholders})",
                array_merge( array( $action ), $ids )
            ) );
        }

        wp_send_json_success();
    }

    // ------------------------------------------------------------------
    // AJAX: Export CSV
    // ------------------------------------------------------------------

    public static function ajax_export_csv() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( 'Unauthorized' );
        }

        check_admin_referer( 'slw_export_leads' );

        global $wpdb;
        $table = $wpdb->prefix . 'slw_leads';
        $status_filter = sanitize_text_field( $_GET['status'] ?? '' );

        $where = '';
        if ( $status_filter && $status_filter !== 'all' ) {
            $where = $wpdb->prepare( ' WHERE status = %s', $status_filter );
        }

        $leads = $wpdb->get_results( "SELECT * FROM {$table}{$where} ORDER BY captured_at DESC" );

        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename=wholesale-leads-' . gmdate( 'Y-m-d' ) . '.csv' );

        $output = fopen( 'php://output', 'w' );
        fputcsv( $output, array( 'ID', 'Name', 'Email', 'Business', 'Phone', 'Address', 'EIN', 'How Heard', 'Source', 'Status', 'Captured', 'Notes' ) );

        foreach ( $leads as $lead ) {
            $csv_ein = ! empty( $lead->ein ) && class_exists( 'SLW_Encryption' )
                ? SLW_Encryption::decrypt( $lead->ein )
                : ( $lead->ein ?? '' );
            fputcsv( $output, array(
                $lead->id,
                $lead->name,
                $lead->email,
                $lead->business_name,
                $lead->phone,
                $lead->address ?? '',
                $csv_ein,
                $lead->how_heard,
                $lead->source,
                $lead->status,
                $lead->captured_at,
                $lead->notes,
            ) );
        }

        fclose( $output );
        exit;
    }

    // ------------------------------------------------------------------
    // Admin Post: Manual Add Lead
    // ------------------------------------------------------------------

    public static function handle_manual_add_lead() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( 'Unauthorized' );
        }

        check_admin_referer( 'slw_manual_add_lead' );

        $name          = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
        $email         = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
        $business_name = sanitize_text_field( wp_unslash( $_POST['business_name'] ?? '' ) );
        $phone         = sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) );
        $address       = sanitize_textarea_field( wp_unslash( $_POST['address'] ?? '' ) );
        $ein_plain     = sanitize_text_field( wp_unslash( $_POST['ein'] ?? '' ) );
        $source        = sanitize_text_field( wp_unslash( $_POST['source'] ?? 'manual' ) );
        $event_name    = sanitize_text_field( wp_unslash( $_POST['event_name'] ?? '' ) );
        $notes         = sanitize_textarea_field( wp_unslash( $_POST['notes'] ?? '' ) );

        if ( empty( $name ) || empty( $email ) ) {
            wp_redirect( admin_url( 'admin.php?page=slw-leads&slw_notice=error&slw_msg=name_email_required' ) );
            exit;
        }

        if ( ! is_email( $email ) ) {
            wp_redirect( admin_url( 'admin.php?page=slw-leads&slw_notice=error&slw_msg=invalid_email' ) );
            exit;
        }

        $allowed_sources = array( 'website', 'trade_show', 'referral', 'social_media', 'phone_call', 'other', 'manual' );
        if ( ! in_array( $source, $allowed_sources, true ) ) {
            $source = 'manual';
        }

        $how_heard = '';
        if ( $event_name ) {
            $how_heard = 'Event: ' . $event_name;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'slw_leads';

        // Check for duplicate email
        $exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE email = %s",
            $email
        ) );
        if ( $exists ) {
            wp_redirect( admin_url( 'admin.php?page=slw-leads&slw_notice=error&slw_msg=duplicate_email' ) );
            exit;
        }

        $ein_encrypted = $ein_plain && class_exists( 'SLW_Encryption' ) ? SLW_Encryption::encrypt( $ein_plain ) : $ein_plain;
        $wpdb->insert( $table, array(
            'name'          => $name,
            'email'         => $email,
            'business_name' => $business_name,
            'phone'         => $phone,
            'address'       => $address,
            'ein'           => $ein_encrypted,
            'how_heard'     => $how_heard,
            'source'        => $source,
            'status'        => 'new',
            'captured_at'   => current_time( 'mysql' ),
            'notes'         => $notes,
        ), array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ) );

        // Fire webhook
        if ( class_exists( 'SLW_Webhooks' ) ) {
            SLW_Webhooks::fire( 'lead-captured', array(
                'email'         => $email,
                'name'          => $name,
                'business_name' => $business_name,
                'address'       => $address,
                'source'        => $source,
            ) );
        }

        wp_redirect( admin_url( 'admin.php?page=slw-leads&slw_notice=success&slw_msg=lead_added' ) );
        exit;
    }

    // ------------------------------------------------------------------
    // Helper: Source badge HTML
    // ------------------------------------------------------------------

    private static function get_source_badge( $source ) {
        $badge_map = array(
            'trade_show'   => array( 'label' => 'Trade Show',   'class' => 'slw-source-badge--gold' ),
            'referral'     => array( 'label' => 'Referral',     'class' => 'slw-source-badge--green' ),
            'website'      => array( 'label' => 'Website',      'class' => 'slw-source-badge--teal' ),
            'social_media' => array( 'label' => 'Social Media', 'class' => 'slw-source-badge--purple' ),
            'phone_call'   => array( 'label' => 'Phone Call',   'class' => 'slw-source-badge--gray' ),
            'shortcode'    => array( 'label' => 'Website',      'class' => 'slw-source-badge--teal' ),
            'manual'          => array( 'label' => 'Manual',          'class' => 'slw-source-badge--gray' ),
            'retail_booth'    => array( 'label' => 'Retail Booth',    'class' => 'slw-source-badge--gold' ),
            'wholesale_booth' => array( 'label' => 'Wholesale Booth', 'class' => 'slw-source-badge--gold' ),
            'other'           => array( 'label' => 'Other',           'class' => 'slw-source-badge--gray' ),
        );

        $info = $badge_map[ $source ] ?? array( 'label' => ucfirst( $source ), 'class' => 'slw-source-badge--gray' );

        return sprintf(
            '<span class="slw-source-badge %s">%s</span>',
            esc_attr( $info['class'] ),
            esc_html( $info['label'] )
        );
    }

    // ------------------------------------------------------------------
    // Admin Page
    // ------------------------------------------------------------------

    public static function render_admin_page() {
        global $wpdb;
        $table = $wpdb->prefix . 'slw_leads';

        // Single lead view
        $lead_id = absint( $_GET['lead'] ?? 0 );
        if ( $lead_id ) {
            self::render_single_lead( $lead_id );
            return;
        }

        $current_status = sanitize_text_field( $_GET['status'] ?? 'all' );

        // Admin notices
        $notice_type = sanitize_text_field( $_GET['slw_notice'] ?? '' );
        $notice_msg  = sanitize_text_field( $_GET['slw_msg'] ?? '' );

        // Get counts per status
        $counts = array( 'all' => 0, 'new' => 0, 'contacted' => 0, 'converted' => 0, 'archived' => 0 );
        $raw_counts = $wpdb->get_results( "SELECT status, COUNT(*) as cnt FROM {$table} GROUP BY status" );
        foreach ( $raw_counts as $row ) {
            $counts[ $row->status ] = (int) $row->cnt;
            $counts['all'] += (int) $row->cnt;
        }

        // Get leads
        $where = '';
        if ( $current_status !== 'all' ) {
            $where = $wpdb->prepare( ' WHERE status = %s', $current_status );
        }
        $leads = $wpdb->get_results( "SELECT * FROM {$table}{$where} ORDER BY captured_at DESC LIMIT 200" );

        ?>
        <div class="wrap slw-admin-dashboard">
            <h1 class="slw-admin-dashboard__title">Lead Capture</h1>
            <p class="slw-admin-dashboard__subtitle">Manage wholesale prospect leads</p>

            <?php if ( $notice_type === 'success' && $notice_msg === 'lead_added' ) : ?>
                <div class="notice notice-success is-dismissible"><p>Lead added successfully.</p></div>
            <?php elseif ( $notice_type === 'error' ) : ?>
                <div class="notice notice-error is-dismissible"><p>
                    <?php
                    $error_messages = array(
                        'name_email_required' => 'Name and email are required.',
                        'invalid_email'       => 'Please enter a valid email address.',
                        'duplicate_email'     => 'A lead with this email already exists.',
                    );
                    echo esc_html( $error_messages[ $notice_msg ] ?? 'An error occurred.' );
                    ?>
                </p></div>
            <?php endif; ?>

            <!-- Quick Add Lead (Collapsible) -->
            <div class="slw-admin-card slw-quick-add-card">
                <div class="slw-quick-add-card__header" onclick="document.getElementById('slw-quick-add-body').classList.toggle('slw-quick-add-card__body--hidden');">
                    <h2 class="slw-admin-card__heading" style="margin:0;cursor:pointer;">
                        <span class="dashicons dashicons-plus-alt2" style="margin-right:6px;color:#386174;"></span>
                        Quick Add Lead
                        <span class="slw-quick-add-card__toggle dashicons dashicons-arrow-down-alt2"></span>
                    </h2>
                </div>
                <div id="slw-quick-add-body" class="slw-quick-add-card__body slw-quick-add-card__body--hidden">
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="slw-quick-add-form">
                        <?php wp_nonce_field( 'slw_manual_add_lead' ); ?>
                        <input type="hidden" name="action" value="slw_manual_add_lead" />

                        <div class="slw-quick-add-form__grid">
                            <div class="slw-quick-add-form__field">
                                <label>Name <span class="slw-required">*</span></label>
                                <input type="text" name="name" required />
                            </div>
                            <div class="slw-quick-add-form__field">
                                <label>Email <span class="slw-required">*</span></label>
                                <input type="email" name="email" required />
                            </div>
                            <div class="slw-quick-add-form__field">
                                <label>Business Name</label>
                                <input type="text" name="business_name" />
                            </div>
                            <div class="slw-quick-add-form__field">
                                <label>Phone</label>
                                <input type="tel" name="phone" />
                            </div>
                            <div class="slw-quick-add-form__field slw-quick-add-form__field--full">
                                <label>Address</label>
                                <input type="text" name="address" placeholder="Street, city, state, zip" />
                            </div>
                            <div class="slw-quick-add-form__field">
                                <label>EIN / Resale Cert</label>
                                <input type="text" name="ein" placeholder="XX-XXXXXXX" />
                            </div>
                            <div class="slw-quick-add-form__field">
                                <label>Source</label>
                                <select name="source" id="slw-manual-source">
                                    <option value="manual">Manual</option>
                                    <option value="website">Website</option>
                                    <option value="trade_show">Trade Show</option>
                                    <option value="referral">Referral</option>
                                    <option value="social_media">Social Media</option>
                                    <option value="phone_call">Phone Call</option>
                                    <option value="other">Other</option>
                                </select>
                            </div>
                            <div class="slw-quick-add-form__field slw-quick-add-form__event-field" id="slw-event-field" style="display:none;">
                                <label>Event Name</label>
                                <input type="text" name="event_name" placeholder="e.g. Montana Craft Fair" />
                            </div>
                            <div class="slw-quick-add-form__field slw-quick-add-form__field--full">
                                <label>Notes</label>
                                <textarea name="notes" rows="2" placeholder="Optional notes about this lead..."></textarea>
                            </div>
                        </div>

                        <button type="submit" class="button button-primary" style="margin-top:12px;">Add Lead</button>
                    </form>
                </div>
            </div>

            <!-- Status Tabs -->
            <div class="slw-lead-tabs">
                <?php
                $tabs = array( 'all' => 'All', 'new' => 'New', 'contacted' => 'Contacted', 'converted' => 'Converted', 'archived' => 'Archived' );
                foreach ( $tabs as $key => $label ) :
                    $active = $current_status === $key ? ' slw-lead-tabs__tab--active' : '';
                    $url = admin_url( 'admin.php?page=slw-leads&status=' . $key );
                ?>
                    <a href="<?php echo esc_url( $url ); ?>" class="slw-lead-tabs__tab<?php echo esc_attr( $active ); ?>">
                        <?php echo esc_html( $label ); ?>
                        <span class="slw-lead-tabs__badge"><?php echo esc_html( $counts[ $key ] ); ?></span>
                    </a>
                <?php endforeach; ?>
            </div>

            <!-- Toolbar -->
            <div class="slw-lead-toolbar">
                <form method="post" id="slw-leads-bulk-form" style="display:inline-flex;align-items:center;gap:8px;">
                    <?php wp_nonce_field( 'slw_bulk_leads', 'slw_lead_nonce' ); ?>
                    <select name="bulk_action" class="slw-lead-toolbar__select">
                        <option value="">Bulk Actions</option>
                        <option value="new">Mark as New</option>
                        <option value="contacted">Mark as Contacted</option>
                        <option value="converted">Mark as Converted</option>
                        <option value="archived">Archive</option>
                    </select>
                    <button type="button" class="button slw-lead-toolbar__apply" onclick="slwBulkAction()">Apply</button>
                </form>

                <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-ajax.php?action=slw_export_leads_csv&status=' . $current_status ), 'slw_export_leads' ) ); ?>" class="button">
                    <span class="dashicons dashicons-download" style="margin-top:4px;"></span> Export CSV
                </a>
            </div>

            <!-- Leads Table -->
            <div class="slw-admin-card" style="padding:0;">
                <table class="slw-lead-table">
                    <thead>
                        <tr>
                            <th class="slw-lead-table__check"><input type="checkbox" id="slw-check-all" /></th>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Business</th>
                            <th>Phone</th>
                            <th>Address</th>
                            <th>EIN</th>
                            <th>Source</th>
                            <th>Status</th>
                            <th>Captured</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ( empty( $leads ) ) : ?>
                            <tr><td colspan="11" class="slw-lead-table__empty">No leads found.</td></tr>
                        <?php else : ?>
                            <?php foreach ( $leads as $lead ) :
                                $lead_ein_plain = ! empty( $lead->ein ) && class_exists( 'SLW_Encryption' )
                                    ? SLW_Encryption::decrypt( $lead->ein )
                                    : ( $lead->ein ?? '' );
                            ?>
                                <tr>
                                    <td class="slw-lead-table__check"><input type="checkbox" class="slw-lead-check" value="<?php echo esc_attr( $lead->id ); ?>" /></td>
                                    <td><strong><?php echo esc_html( $lead->name ); ?></strong></td>
                                    <td><a href="mailto:<?php echo esc_attr( $lead->email ); ?>"><?php echo esc_html( $lead->email ); ?></a></td>
                                    <td><?php echo esc_html( $lead->business_name ); ?></td>
                                    <td><?php echo esc_html( $lead->phone ); ?></td>
                                    <td style="font-size:12px;color:#628393;max-width:200px;"><?php echo ! empty( $lead->address ) ? esc_html( $lead->address ) : 'None'; ?></td>
                                    <td style="font-size:12px;"><?php echo $lead_ein_plain ? esc_html( $lead_ein_plain ) : 'None'; ?></td>
                                    <td><?php echo self::get_source_badge( $lead->source ); ?></td>
                                    <td><span class="slw-lead-status slw-lead-status--<?php echo esc_attr( $lead->status ); ?>"><?php echo esc_html( ucfirst( $lead->status ) ); ?></span></td>
                                    <td><?php echo esc_html( human_time_diff( strtotime( $lead->captured_at ) ) . ' ago' ); ?></td>
                                    <td>
                                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=slw-leads&lead=' . $lead->id ) ); ?>" class="button button-small">View</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Trade Show Tools -->
            <?php self::render_trade_show_tools(); ?>

            <!-- Email Templates -->
            <?php // Email templates moved to Sequences tab ?>
        </div>

        <script>
        document.getElementById('slw-check-all').addEventListener('change', function() {
            document.querySelectorAll('.slw-lead-check').forEach(function(cb) { cb.checked = this.checked; }.bind(this));
        });

        function slwBulkAction() {
            var form = document.getElementById('slw-leads-bulk-form');
            var action = form.querySelector('[name="bulk_action"]').value;
            if (!action) { alert('Please select a bulk action.'); return; }
            var checked = document.querySelectorAll('.slw-lead-check:checked');
            if (!checked.length) { alert('Please select at least one lead.'); return; }
            var ids = Array.from(checked).map(function(cb){ return cb.value; });
            var data = new FormData(form);
            data.append('action', 'slw_bulk_leads_action');
            ids.forEach(function(id){ data.append('ids[]', id); });
            fetch('<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>', { method: 'POST', body: data, credentials: 'same-origin' })
            .then(function(r){ return r.json(); })
            .then(function(){ location.reload(); });
        }

        // Toggle event name field based on source selection
        document.getElementById('slw-manual-source').addEventListener('change', function() {
            var eventField = document.getElementById('slw-event-field');
            eventField.style.display = this.value === 'trade_show' ? '' : 'none';
        });

        // Copy-link buttons on the Trade Show Tools panel. Supports the
        // wholesale booth QR and walk-by retail QR with the same handler.
        document.querySelectorAll('.slw-copy-link-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var url = this.getAttribute('data-url');
                if (!url) return;
                var orig = btn.innerHTML;
                navigator.clipboard.writeText(url).then(function() {
                    btn.textContent = 'Copied!';
                    setTimeout(function() { btn.innerHTML = orig; }, 2000);
                });
            });
        });
        </script>
        <?php
    }

    // ------------------------------------------------------------------
    // Trade Show Tools Section
    // ------------------------------------------------------------------

    private static function render_trade_show_tools() {
        $active_event = get_option( 'slw_active_event', '' );
        $home         = home_url( '/wholesale-booth' );

        // Wholesale booth URL. Lands on the path picker so visitors choose
        // "I'm a Customer" or "I'm a Business". Holly's primary booth flow.
        $booth_url = add_query_arg( 'source', 'trade_show', $home );
        if ( $active_event !== '' ) {
            $booth_url = add_query_arg( 'event', rawurlencode( $active_event ), $booth_url );
        }
        $booth_qr = 'https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=' . rawurlencode( $booth_url );

        // Walk-by retail URL. Skips the path picker via ?path=retail and lands
        // straight on the retail quiz so a passing customer scanning from a
        // poster can claim their reward without seeing the wholesale flow.
        $retail_url = add_query_arg( array(
            'source' => 'retail_walkby',
            'path'   => 'retail',
        ), $home );
        if ( $active_event !== '' ) {
            $retail_url = add_query_arg( 'event', rawurlencode( $active_event ), $retail_url );
        }
        $retail_qr = 'https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=' . rawurlencode( $retail_url );

        $just_set = ! empty( $_GET['slw_event_set'] );
        ?>
        <div class="slw-admin-card slw-trade-show-tools">
            <h2 class="slw-admin-card__heading">
                <span class="dashicons dashicons-megaphone" style="margin-right:6px;color:#D4AF37;"></span>
                Trade Show Tools
            </h2>

            <?php if ( $just_set ) : ?>
                <div class="notice notice-success inline" style="margin:0 0 16px;"><p>Active event saved.</p></div>
            <?php endif; ?>

            <!-- Active Trade Show. Single source of truth for event tagging. -->
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="background:#FFF8E1;border:1px solid #ffe082;border-radius:6px;padding:14px 18px;margin-bottom:20px;">
                <?php wp_nonce_field( 'slw_set_active_event' ); ?>
                <input type="hidden" name="action" value="slw_set_active_event" />

                <div style="display:flex;flex-wrap:wrap;align-items:center;gap:10px;">
                    <strong style="color:#5d4037;">Active Trade Show:</strong>
                    <input type="text" name="active_event" value="<?php echo esc_attr( $active_event ); ?>" placeholder="e.g. Montana Craft Fair" style="flex:1;min-width:240px;padding:6px 10px;" />
                    <button type="submit" class="button button-primary">Save</button>
                    <?php if ( $active_event !== '' ) : ?>
                        <button type="submit" name="active_event" value="" class="button" onclick="return confirm('Clear the active event? Future booth and iPad fills will be tagged without an event name until you set a new one.');">Clear</button>
                    <?php endif; ?>
                </div>

                <p style="margin:10px 0 0;color:#5d4037;font-size:13px;line-height:1.5;">
                    Set this once at the start of each show. Every lead captured during the show (QR scan or iPad direct fill) gets tagged with this name automatically. The event name flows into:
                </p>
                <ul style="margin:6px 0 0 22px;padding:0;list-style:disc;color:#5d4037;font-size:13px;line-height:1.5;">
                    <li>The <em>How Heard</em> field on the lead row in WP admin.</li>
                    <li>The <code>event</code> property on the <code>lead-captured</code> webhook payload that fires to Mautic.</li>
                    <li>Any Mautic segment or campaign you've set up to filter on the <code>event</code> field, which then drives tags and follow-up sequences.</li>
                </ul>
                <p style="margin:6px 0 0;color:#5d4037;font-size:13px;">Clear the field when the show ends so post-show fills are tagged correctly (or empty).</p>
            </form>

            <!-- Wholesale Booth QR -->
            <h3 style="margin:0 0 6px;font-size:16px;">Wholesale Booth QR</h3>
            <p style="color:#628393;margin:0 0 14px;">Print this QR for your booth. Visitors scan, choose between the customer or wholesale path on the welcome screen, and become a lead. The same URL works on the iPad as a tablet kiosk.</p>

            <div class="slw-trade-show-tools__grid" style="margin-bottom:24px;">
                <div class="slw-trade-show-tools__qr">
                    <img src="<?php echo esc_url( $booth_qr ); ?>" alt="Wholesale Booth QR Code" width="200" height="200" />
                </div>
                <div class="slw-trade-show-tools__controls">
                    <div class="slw-trade-show-tools__link-box">
                        <label><strong>Booth URL</strong></label>
                        <code><?php echo esc_html( $booth_url ); ?></code>
                    </div>
                    <div class="slw-trade-show-tools__buttons">
                        <a href="<?php echo esc_url( $booth_qr ); ?>" class="button" download="wholesale-booth-qr.png" target="_blank">
                            <span class="dashicons dashicons-download" style="margin-top:4px;"></span> Download QR
                        </a>
                        <button type="button" class="button slw-copy-link-btn" data-url="<?php echo esc_attr( $booth_url ); ?>">
                            <span class="dashicons dashicons-admin-page" style="margin-top:4px;"></span> Copy Link
                        </button>
                    </div>
                    <p class="slw-trade-show-tools__booth-link">
                        <strong>Booth Tablet URL:</strong>
                        <a href="<?php echo esc_url( $booth_url ); ?>" target="_blank"><?php echo esc_html( $booth_url ); ?></a>
                    </p>
                </div>
            </div>

            <!-- Walk-By Retail QR -->
            <h3 style="margin:0 0 6px;font-size:16px;">Walk-By Retail QR</h3>
            <p style="color:#628393;margin:0 0 14px;">For retail customers who want to scan and go. Print and post this somewhere the public can see (table tent, booth backdrop, retail floor). Skips the wholesale path picker and lands them straight on the retail quiz so they can claim their reward later, even without your help at the booth.</p>

            <div class="slw-trade-show-tools__grid">
                <div class="slw-trade-show-tools__qr">
                    <img src="<?php echo esc_url( $retail_qr ); ?>" alt="Walk-By Retail QR Code" width="200" height="200" />
                </div>
                <div class="slw-trade-show-tools__controls">
                    <div class="slw-trade-show-tools__link-box">
                        <label><strong>Retail URL</strong></label>
                        <code><?php echo esc_html( $retail_url ); ?></code>
                    </div>
                    <div class="slw-trade-show-tools__buttons">
                        <a href="<?php echo esc_url( $retail_qr ); ?>" class="button" download="retail-walkby-qr.png" target="_blank">
                            <span class="dashicons dashicons-download" style="margin-top:4px;"></span> Download QR
                        </a>
                        <button type="button" class="button slw-copy-link-btn" data-url="<?php echo esc_attr( $retail_url ); ?>">
                            <span class="dashicons dashicons-admin-page" style="margin-top:4px;"></span> Copy Link
                        </button>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Ensure a free-shipping WooCommerce coupon exists for the wholesale booth
     * bonus. Lazy-creates on first booth wholesale submission so Holly never
     * has to touch WC → Coupons. Idempotent: returns the existing code if the
     * setting is already populated.
     *
     * @return string The coupon code (empty string if WC isn't loaded).
     */
    public static function ensure_wholesale_freeship_coupon() {
        if ( ! function_exists( 'wc_get_coupon_id_by_code' ) || ! class_exists( 'WC_Coupon' ) ) {
            return '';
        }

        $existing = trim( (string) get_option( 'slw_booth_wholesale_code', '' ) );
        if ( $existing !== '' && wc_get_coupon_id_by_code( $existing ) ) {
            return $existing;
        }

        // Use the configured code if Holly set one but the actual coupon went
        // missing; otherwise generate a sensible default.
        $code = $existing !== '' ? $existing : 'WHOLESALE-SHOWSHIP';

        // Don't double-create if a coupon with this code already exists.
        if ( ! wc_get_coupon_id_by_code( $code ) ) {
            $coupon = new WC_Coupon();
            $coupon->set_code( $code );
            $coupon->set_discount_type( 'percent' );
            $coupon->set_amount( 0 );
            $coupon->set_free_shipping( true );
            $coupon->set_individual_use( false );
            $coupon->set_description( 'Auto-created by Sego Lily Wholesale: free shipping for wholesale customers who complete the booth quiz at a trade show.' );
            $coupon->save();
        }

        update_option( 'slw_booth_wholesale_code', $code );
        return $code;
    }

    /**
     * Persist the admin-set "active trade show" name so booth/iPad fills get
     * tagged with it server-side, even when the URL has no ?event= param.
     */
    public static function handle_set_active_event() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( 'Unauthorized', 403 );
        }
        check_admin_referer( 'slw_set_active_event' );

        $value = sanitize_text_field( wp_unslash( $_POST['active_event'] ?? '' ) );
        update_option( 'slw_active_event', $value );

        wp_safe_redirect( add_query_arg(
            array( 'tab' => 'leads', 'slw_event_set' => '1' ),
            admin_url( 'admin.php?page=slw-customers' )
        ) );
        exit;
    }

    // ------------------------------------------------------------------
    // Email Templates Section
    // ------------------------------------------------------------------

    private static function render_email_templates() {
        $wholesale_url = esc_url( home_url( '/wholesale-partners' ) );
        $portal_url    = esc_url( home_url( '/wholesale-portal' ) );

        $template_1_subject = 'Carry Sego Lily in your shop?';
        $template_1_body = "Hi {owner_name},

I came across {shop_name} and love what you're doing. I think our products would be a great fit for your customers.

I'm Holly. I run Sego Lily Naturals out of Montana. We make small-batch, plant-based skincare that's been really popular with boutiques and spas. Everything is made with clean ingredients and our wholesale partners get 50% off retail pricing.

If you're open to it, I'd love to send you our price list or set up a quick call. You can learn more and apply here:

{$wholesale_url}

No pressure at all. Just thought it could be a good match.

Talk soon,
Holly";

        $template_2_subject = "What's new this quarter at Sego Lily";
        $template_2_body = "Hi there,

Hope business is going well! Here's a quick update from our end.

NEW PRODUCTS
[Describe any new products launched this quarter]

WHAT'S SELLING BEST
[Share your top 2-3 bestsellers and why customers love them]

SEASONAL RECOMMENDATION
[Suggest a product or bundle that fits the upcoming season]

REORDER
Ready to restock? You can place your next order here:
{$portal_url}

As always, reach out anytime if you need samples, marketing materials, or just want to chat.

Thanks for being a partner,
Holly";

        ?>
        <div class="slw-admin-card slw-email-templates">
            <h2 class="slw-admin-card__heading">
                <span class="dashicons dashicons-email-alt" style="margin-right:6px;color:#386174;"></span>
                Email Templates
            </h2>
            <p style="color:#628393;margin-top:0;">Copy these templates and customize them before sending. Merge fields like {owner_name} and {shop_name} need to be replaced manually.</p>

            <!-- Template 1: Wholesale Outreach -->
            <div class="slw-email-template-card">
                <div class="slw-email-template-card__header">
                    <h3 class="slw-email-template-card__title">Wholesale Outreach</h3>
                    <span class="slw-email-template-card__tag">For retail customers</span>
                </div>
                <div class="slw-email-template-card__subject">
                    <strong>Subject:</strong> <?php echo esc_html( $template_1_subject ); ?>
                </div>
                <pre class="slw-email-template-card__body" id="slw-email-tpl-1"><?php echo esc_html( $template_1_body ); ?></pre>
                <button type="button" class="button slw-email-template-card__copy" data-target="slw-email-tpl-1">
                    <span class="dashicons dashicons-admin-page" style="margin-top:4px;"></span> Copy to Clipboard
                </button>
            </div>

            <!-- Template 2: Quarterly Newsletter -->
            <div class="slw-email-template-card">
                <div class="slw-email-template-card__header">
                    <h3 class="slw-email-template-card__title">Quarterly Newsletter</h3>
                    <span class="slw-email-template-card__tag">For wholesale partners</span>
                </div>
                <div class="slw-email-template-card__subject">
                    <strong>Subject:</strong> <?php echo esc_html( $template_2_subject ); ?>
                </div>
                <pre class="slw-email-template-card__body" id="slw-email-tpl-2"><?php echo esc_html( $template_2_body ); ?></pre>
                <button type="button" class="button slw-email-template-card__copy" data-target="slw-email-tpl-2">
                    <span class="dashicons dashicons-admin-page" style="margin-top:4px;"></span> Copy to Clipboard
                </button>
            </div>
        </div>

        <script>
        document.querySelectorAll('.slw-email-template-card__copy').forEach(function(btn){
            btn.addEventListener('click', function(){
                var targetId = this.getAttribute('data-target');
                var pre = document.getElementById(targetId);
                if (!pre) return;
                navigator.clipboard.writeText(pre.textContent).then(function(){
                    var orig = btn.innerHTML;
                    btn.innerHTML = '<span class="dashicons dashicons-yes" style="margin-top:4px;"></span> Copied!';
                    setTimeout(function(){ btn.innerHTML = orig; }, 2000);
                });
            });
        });
        </script>
        <?php
    }

    /**
     * Single lead detail view.
     */
    private static function render_single_lead( $lead_id ) {
        global $wpdb;
        $table = $wpdb->prefix . 'slw_leads';
        $lead = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $lead_id ) );

        if ( ! $lead ) {
            echo '<div class="wrap"><div class="notice notice-error"><p>Lead not found.</p></div></div>';
            return;
        }

        // Build application pre-fill URL
        $app_page = get_page_by_path( 'wholesale-partners' );
        $convert_url = $app_page ? add_query_arg( array(
            'prefill_name'     => $lead->name,
            'prefill_email'    => $lead->email,
            'prefill_business' => $lead->business_name,
            'prefill_phone'    => $lead->phone,
        ), get_permalink( $app_page ) ) : '#';

        ?>
        <div class="wrap slw-admin-dashboard">
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=slw-leads' ) ); ?>" class="slw-lead-back">&larr; Back to Leads</a>
            <h1 class="slw-admin-dashboard__title"><?php echo esc_html( $lead->name ); ?></h1>

            <div class="slw-admin-dashboard__grid">
                <div class="slw-admin-dashboard__main">
                    <div class="slw-admin-card">
                        <h2 class="slw-admin-card__heading">Lead Details</h2>
                        <table class="slw-lead-detail-table">
                            <tr><th>Name</th><td><?php echo esc_html( $lead->name ); ?></td></tr>
                            <tr><th>Email</th><td><a href="mailto:<?php echo esc_attr( $lead->email ); ?>"><?php echo esc_html( $lead->email ); ?></a></td></tr>
                            <tr><th>Business</th><td><?php echo esc_html( $lead->business_name ); ?></td></tr>
                            <tr><th>Phone</th><td><?php echo esc_html( $lead->phone ?: 'None' ); ?></td></tr>
                            <tr><th>Address</th><td><?php echo ! empty( $lead->address ) ? nl2br( esc_html( $lead->address ) ) : 'None'; ?></td></tr>
                            <tr><th>EIN</th><td><?php
                                $detail_ein = ! empty( $lead->ein ) && class_exists( 'SLW_Encryption' )
                                    ? SLW_Encryption::decrypt( $lead->ein )
                                    : ( $lead->ein ?? '' );
                                echo $detail_ein ? esc_html( $detail_ein ) : 'None';
                            ?></td></tr>
                            <tr><th>How Heard</th><td><?php echo esc_html( $lead->how_heard ?: 'None' ); ?></td></tr>
                            <tr><th>Source</th><td><?php echo esc_html( ucfirst( $lead->source ) ); ?></td></tr>
                            <tr><th>Captured</th><td><?php echo esc_html( date_i18n( 'M j, Y g:i a', strtotime( $lead->captured_at ) ) ); ?></td></tr>
                        </table>
                    </div>
                </div>

                <div class="slw-admin-dashboard__sidebar">
                    <div class="slw-admin-card">
                        <h2 class="slw-admin-card__heading">Status & Notes</h2>
                        <form id="slw-lead-update-form">
                            <?php wp_nonce_field( 'slw_update_lead', 'slw_lead_nonce' ); ?>
                            <input type="hidden" name="lead_id" value="<?php echo esc_attr( $lead->id ); ?>" />

                            <div class="slw-lead-capture__field" style="margin-bottom:16px;">
                                <label for="slw-lead-status"><strong>Status</strong></label>
                                <select id="slw-lead-status" name="status" style="width:100%;">
                                    <?php foreach ( array( 'new', 'contacted', 'converted', 'archived' ) as $s ) : ?>
                                        <option value="<?php echo esc_attr( $s ); ?>" <?php selected( $lead->status, $s ); ?>><?php echo esc_html( ucfirst( $s ) ); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="slw-lead-capture__field" style="margin-bottom:16px;">
                                <label for="slw-lead-notes"><strong>Notes</strong></label>
                                <textarea id="slw-lead-notes" name="notes" rows="5" style="width:100%;"><?php echo esc_textarea( $lead->notes ); ?></textarea>
                            </div>

                            <button type="submit" class="button button-primary" style="width:100%;">Save Changes</button>
                        </form>

                        <hr style="margin:16px 0;" />

                        <a href="<?php echo esc_url( $convert_url ); ?>" class="button" style="width:100%;text-align:center;" target="_blank">
                            Convert to Application &rarr;
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <script>
        document.getElementById('slw-lead-update-form').addEventListener('submit', function(e) {
            e.preventDefault();
            var data = new FormData(this);
            data.append('action', 'slw_update_lead');
            fetch('<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>', { method: 'POST', body: data, credentials: 'same-origin' })
            .then(function(r){ return r.json(); })
            .then(function(res){
                if (res.success) { alert('Lead updated.'); } else { alert('Error: ' + (res.data || 'Unknown')); }
            });
        });
        </script>
        <?php
    }
}
