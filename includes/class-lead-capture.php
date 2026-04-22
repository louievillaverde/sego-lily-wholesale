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

                    <button type="submit" class="slw-btn slw-btn-primary slw-lead-capture__submit">
                        Get Wholesale Info
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

        $url_source = sanitize_text_field( $_GET['source'] ?? 'trade_show' );
        $url_event  = sanitize_text_field( $_GET['event'] ?? '' );

        ob_start();
        ?>
        <div class="slw-quick-capture">
            <form id="slw-quick-form" class="slw-quick-capture__form">
                <?php wp_nonce_field( 'slw_capture_lead', 'slw_lead_nonce' ); ?>
                <input type="hidden" name="source" value="<?php echo esc_attr( $url_source ); ?>" />
                <input type="hidden" name="event" value="<?php echo esc_attr( $url_event ); ?>" />

                <h2 class="slw-quick-capture__heading">Interested in wholesale?</h2>

                <div class="slw-quick-capture__field">
                    <input type="text" name="name" placeholder="Your Name" required class="slw-quick-capture__input" />
                </div>

                <div class="slw-quick-capture__field">
                    <input type="email" name="email" placeholder="Email Address" required class="slw-quick-capture__input" />
                </div>

                <div class="slw-quick-capture__field">
                    <input type="tel" name="phone" placeholder="Phone Number" class="slw-quick-capture__input" />
                </div>

                <button type="submit" class="slw-quick-capture__submit">Get Started</button>

                <div class="slw-quick-capture__message" style="display:none;"></div>
            </form>
        </div>

        <script>
        (function(){
            var form = document.getElementById('slw-quick-form');
            if (!form) return;
            form.addEventListener('submit', function(e) {
                e.preventDefault();
                var btn = form.querySelector('.slw-quick-capture__submit');
                var msg = form.querySelector('.slw-quick-capture__message');
                btn.disabled = true;
                btn.textContent = 'Saving...';

                var data = new FormData(form);
                data.append('action', 'slw_capture_lead');
                data.append('quick_mode', '1');

                fetch('<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>', {
                    method: 'POST',
                    body: data,
                    credentials: 'same-origin'
                })
                .then(function(r){ return r.json(); })
                .then(function(res){
                    if (res.success) {
                        msg.style.display = 'block';
                        msg.className = 'slw-quick-capture__message slw-quick-capture__message--success';
                        msg.textContent = 'Thanks! We\'ll be in touch soon.';
                        form.reset();
                        // Re-set hidden fields after reset
                        form.querySelector('[name="source"]').value = '<?php echo esc_js( $url_source ); ?>';
                        form.querySelector('[name="event"]').value = '<?php echo esc_js( $url_event ); ?>';
                        setTimeout(function(){
                            msg.style.display = 'none';
                            btn.disabled = false;
                            btn.textContent = 'Get Started';
                        }, 3000);
                    } else {
                        msg.style.display = 'block';
                        msg.className = 'slw-quick-capture__message slw-quick-capture__message--error';
                        msg.textContent = res.data || 'Something went wrong.';
                        btn.disabled = false;
                        btn.textContent = 'Add Lead';
                    }
                })
                .catch(function(){
                    msg.style.display = 'block';
                    msg.className = 'slw-quick-capture__message slw-quick-capture__message--error';
                    msg.textContent = 'Network error.';
                    btn.disabled = false;
                    btn.textContent = 'Add Lead';
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
        $name          = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
        $email         = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
        $business_name = sanitize_text_field( wp_unslash( $_POST['business_name'] ?? '' ) );
        $phone         = sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) );
        $how_heard     = sanitize_text_field( wp_unslash( $_POST['how_heard'] ?? '' ) );
        $source        = sanitize_text_field( wp_unslash( $_POST['source'] ?? 'website' ) );
        $event         = sanitize_text_field( wp_unslash( $_POST['event'] ?? '' ) );

        // Validate allowed sources
        $allowed_sources = array( 'website', 'trade_show', 'referral', 'social_media', 'phone_call', 'other', 'shortcode', 'manual' );
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

        $wpdb->insert( $table, array(
            'name'          => $name,
            'email'         => $email,
            'business_name' => $business_name,
            'phone'         => $phone,
            'how_heard'     => $how_heard,
            'source'        => $source,
            'status'        => 'new',
            'captured_at'   => current_time( 'mysql' ),
        ), array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ) );

        // Fire webhook
        if ( class_exists( 'SLW_Webhooks' ) ) {
            SLW_Webhooks::fire( 'lead-captured', array(
                'email'         => $email,
                'name'          => $name,
                'business_name' => $business_name,
                'source'        => $source,
                'event'         => $event,
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
        fputcsv( $output, array( 'ID', 'Name', 'Email', 'Business', 'Phone', 'How Heard', 'Source', 'Status', 'Captured', 'Notes' ) );

        foreach ( $leads as $lead ) {
            fputcsv( $output, array(
                $lead->id,
                $lead->name,
                $lead->email,
                $lead->business_name,
                $lead->phone,
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

        $wpdb->insert( $table, array(
            'name'          => $name,
            'email'         => $email,
            'business_name' => $business_name,
            'phone'         => $phone,
            'how_heard'     => $how_heard,
            'source'        => $source,
            'status'        => 'new',
            'captured_at'   => current_time( 'mysql' ),
            'notes'         => $notes,
        ), array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ) );

        // Fire webhook
        if ( class_exists( 'SLW_Webhooks' ) ) {
            SLW_Webhooks::fire( 'lead-captured', array(
                'email'         => $email,
                'name'          => $name,
                'business_name' => $business_name,
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
            'manual'       => array( 'label' => 'Manual',       'class' => 'slw-source-badge--gray' ),
            'other'        => array( 'label' => 'Other',        'class' => 'slw-source-badge--gray' ),
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
                            <th>Source</th>
                            <th>Status</th>
                            <th>Captured</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ( empty( $leads ) ) : ?>
                            <tr><td colspan="9" class="slw-lead-table__empty">No leads found.</td></tr>
                        <?php else : ?>
                            <?php foreach ( $leads as $lead ) : ?>
                                <tr>
                                    <td class="slw-lead-table__check"><input type="checkbox" class="slw-lead-check" value="<?php echo esc_attr( $lead->id ); ?>" /></td>
                                    <td><strong><?php echo esc_html( $lead->name ); ?></strong></td>
                                    <td><a href="mailto:<?php echo esc_attr( $lead->email ); ?>"><?php echo esc_html( $lead->email ); ?></a></td>
                                    <td><?php echo esc_html( $lead->business_name ); ?></td>
                                    <td><?php echo esc_html( $lead->phone ); ?></td>
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

        // QR code tools: update QR on event name change
        (function(){
            var eventInput = document.getElementById('slw-qr-event-name');
            if (!eventInput) return;
            var qrImg = document.getElementById('slw-qr-image');
            var copyBtn = document.getElementById('slw-copy-link-btn');
            var downloadLink = document.getElementById('slw-qr-download');
            var linkDisplay = document.getElementById('slw-qr-link-display');
            var baseUrl = '<?php echo esc_url( home_url( '/wholesale-leads' ) ); ?>';

            function updateQR() {
                var event = eventInput.value.trim();
                var url = baseUrl + '?source=trade_show';
                if (event) url += '&event=' + encodeURIComponent(event);
                var qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=' + encodeURIComponent(url);
                qrImg.src = qrUrl;
                downloadLink.href = qrUrl;
                linkDisplay.textContent = url;
                copyBtn.setAttribute('data-url', url);
            }

            eventInput.addEventListener('input', updateQR);

            copyBtn.addEventListener('click', function() {
                var url = this.getAttribute('data-url');
                navigator.clipboard.writeText(url).then(function(){
                    copyBtn.textContent = 'Copied!';
                    setTimeout(function(){ copyBtn.textContent = 'Copy Link'; }, 2000);
                });
            });
        })();
        </script>
        <?php
    }

    // ------------------------------------------------------------------
    // Trade Show Tools Section
    // ------------------------------------------------------------------

    private static function render_trade_show_tools() {
        $lead_page_url = home_url( '/wholesale-leads' );
        $default_url   = $lead_page_url . '?source=trade_show';
        $qr_api_url    = 'https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=' . rawurlencode( $default_url );
        ?>
        <div class="slw-admin-card slw-trade-show-tools">
            <h2 class="slw-admin-card__heading">
                <span class="dashicons dashicons-megaphone" style="margin-right:6px;color:#D4AF37;"></span>
                Trade Show Tools
            </h2>
            <p class="slw-trade-show-tools__desc">Print this QR code and place it at your trade show booth. Visitors scan it on their phone, fill in their info, and become a lead automatically.</p>

            <div class="slw-trade-show-tools__grid">
                <div class="slw-trade-show-tools__qr">
                    <img id="slw-qr-image" src="<?php echo esc_url( $qr_api_url ); ?>" alt="Lead Capture QR Code" width="200" height="200" />
                </div>
                <div class="slw-trade-show-tools__controls">
                    <div class="slw-quick-add-form__field">
                        <label for="slw-qr-event-name"><strong>Event Name</strong> <span class="slw-optional">(optional)</span></label>
                        <input type="text" id="slw-qr-event-name" placeholder="e.g. Montana Craft Fair" />
                    </div>

                    <div class="slw-trade-show-tools__link-box">
                        <label><strong>Lead Capture URL</strong></label>
                        <code id="slw-qr-link-display"><?php echo esc_html( $default_url ); ?></code>
                    </div>

                    <div class="slw-trade-show-tools__buttons">
                        <a id="slw-qr-download" href="<?php echo esc_url( $qr_api_url ); ?>" class="button" download="wholesale-qr-code.png" target="_blank">
                            <span class="dashicons dashicons-download" style="margin-top:4px;"></span> Download QR Code
                        </a>
                        <button type="button" id="slw-copy-link-btn" class="button" data-url="<?php echo esc_attr( $default_url ); ?>">
                            <span class="dashicons dashicons-admin-page" style="margin-top:4px;"></span> Copy Link
                        </button>
                    </div>

                    <p class="slw-trade-show-tools__booth-link">
                        <strong>Booth Tablet URL:</strong>
                        <a href="<?php echo esc_url( home_url( '/wholesale-booth?source=trade_show' ) ); ?>" target="_blank">
                            <?php echo esc_html( home_url( '/wholesale-booth?source=trade_show' ) ); ?>
                        </a>
                    </p>
                </div>
            </div>
        </div>
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
                            <tr><th>Phone</th><td><?php echo esc_html( $lead->phone ?: '—' ); ?></td></tr>
                            <tr><th>How Heard</th><td><?php echo esc_html( $lead->how_heard ?: '—' ); ?></td></tr>
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
