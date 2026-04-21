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
        add_action( 'wp_ajax_slw_capture_lead', array( __CLASS__, 'ajax_capture_lead' ) );
        add_action( 'wp_ajax_nopriv_slw_capture_lead', array( __CLASS__, 'ajax_capture_lead' ) );
        add_action( 'wp_ajax_slw_update_lead', array( __CLASS__, 'ajax_update_lead' ) );
        add_action( 'wp_ajax_slw_export_leads_csv', array( __CLASS__, 'ajax_export_csv' ) );
        add_action( 'wp_ajax_slw_bulk_leads_action', array( __CLASS__, 'ajax_bulk_action' ) );
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
        // Enqueue plugin CSS
        wp_enqueue_style(
            'sego-lily-wholesale',
            SLW_PLUGIN_URL . 'assets/sego-lily-wholesale.css',
            array(),
            SLW_VERSION
        );

        ob_start();
        ?>
        <div class="slw-lead-capture">
            <div class="slw-lead-capture__form-wrap">
                <h2 class="slw-lead-capture__heading">Interested in Wholesale?</h2>
                <p class="slw-lead-capture__intro">Leave your details and we'll reach out with more information about our wholesale program.</p>

                <form id="slw-lead-form" class="slw-lead-capture__form">
                    <?php wp_nonce_field( 'slw_capture_lead', 'slw_lead_nonce' ); ?>

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
    // AJAX: Capture Lead
    // ------------------------------------------------------------------

    public static function ajax_capture_lead() {
        check_ajax_referer( 'slw_capture_lead', 'slw_lead_nonce' );

        $name          = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
        $email         = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
        $business_name = sanitize_text_field( wp_unslash( $_POST['business_name'] ?? '' ) );
        $phone         = sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) );
        $how_heard     = sanitize_text_field( wp_unslash( $_POST['how_heard'] ?? '' ) );

        if ( empty( $name ) || empty( $email ) || empty( $business_name ) ) {
            wp_send_json_error( 'Please fill in all required fields.' );
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

        $wpdb->insert( $table, array(
            'name'          => $name,
            'email'         => $email,
            'business_name' => $business_name,
            'phone'         => $phone,
            'how_heard'     => $how_heard,
            'source'        => 'shortcode',
            'status'        => 'new',
            'captured_at'   => current_time( 'mysql' ),
        ), array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ) );

        // Fire webhook
        if ( class_exists( 'SLW_Webhooks' ) ) {
            SLW_Webhooks::fire( 'lead-captured', array(
                'email'         => $email,
                'name'          => $name,
                'business_name' => $business_name,
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
                            <th>Status</th>
                            <th>Captured</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ( empty( $leads ) ) : ?>
                            <tr><td colspan="8" class="slw-lead-table__empty">No leads found.</td></tr>
                        <?php else : ?>
                            <?php foreach ( $leads as $lead ) : ?>
                                <tr>
                                    <td class="slw-lead-table__check"><input type="checkbox" class="slw-lead-check" value="<?php echo esc_attr( $lead->id ); ?>" /></td>
                                    <td><strong><?php echo esc_html( $lead->name ); ?></strong></td>
                                    <td><a href="mailto:<?php echo esc_attr( $lead->email ); ?>"><?php echo esc_html( $lead->email ); ?></a></td>
                                    <td><?php echo esc_html( $lead->business_name ); ?></td>
                                    <td><?php echo esc_html( $lead->phone ); ?></td>
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
