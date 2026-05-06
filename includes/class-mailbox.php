<?php
/**
 * Wholesale Inbox quick-launch page.
 *
 * v1 (4.6.8): renders an admin page with a one-click "Open Inbox in Webmail"
 * button that points at the wholesale@ mailbox in SiteGround Webmail. Stores
 * the webmail URL + email address as plugin options so Holly only configures
 * once. Full IMAP integration (read messages inline, mark seen, etc.) is
 * filed for a later build — the link approach gives 90% of the value with
 * zero credential storage and zero parsing risk.
 *
 * @since 4.6.8
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SLW_Mailbox {

    public static function init() {
        add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
        add_action( 'admin_post_slw_save_mailbox_settings', array( __CLASS__, 'handle_save_settings' ) );
    }

    public static function register_settings() {
        register_setting( 'slw_mailbox', 'slw_webmail_url' );
        register_setting( 'slw_mailbox', 'slw_wholesale_email_address' );
    }

    public static function handle_save_settings() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( 'Unauthorized', 403 );
        }
        check_admin_referer( 'slw_save_mailbox_settings' );

        $webmail_url = isset( $_POST['slw_webmail_url'] ) ? esc_url_raw( wp_unslash( $_POST['slw_webmail_url'] ) ) : '';
        $email       = isset( $_POST['slw_wholesale_email_address'] ) ? sanitize_email( wp_unslash( $_POST['slw_wholesale_email_address'] ) ) : '';

        update_option( 'slw_webmail_url', $webmail_url );
        update_option( 'slw_wholesale_email_address', $email );

        wp_safe_redirect( add_query_arg( 'slw_mailbox_saved', '1', admin_url( 'admin.php?page=slw-inbox' ) ) );
        exit;
    }

    public static function render_page() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( 'Unauthorized', 403 );
        }

        $webmail_url = (string) get_option( 'slw_webmail_url', '' );
        $email       = (string) get_option( 'slw_wholesale_email_address', 'wholesale@segolilyskincare.com' );
        $saved       = ! empty( $_GET['slw_mailbox_saved'] );
        ?>
        <div class="wrap" style="max-width:780px;">
            <h1>Wholesale Inbox</h1>
            <p style="color:#555;font-size:14px;">Quick access to the <code><?php echo esc_html( $email ); ?></code> mailbox without leaving the WordPress admin.</p>

            <?php if ( $saved ) : ?>
                <div class="notice notice-success is-dismissible"><p>Inbox settings saved.</p></div>
            <?php endif; ?>

            <?php if ( $webmail_url ) : ?>
                <div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:24px;margin-top:16px;text-align:center;">
                    <div style="font-size:48px;line-height:1;margin-bottom:8px;">&#9993;</div>
                    <h2 style="margin:0 0 6px;">Open the wholesale inbox</h2>
                    <p style="color:#666;margin:0 0 18px;">Opens <strong><?php echo esc_html( $email ); ?></strong> in SiteGround Webmail in a new tab.</p>
                    <a href="<?php echo esc_url( $webmail_url ); ?>" target="_blank" rel="noopener" class="button button-primary button-hero" style="font-size:15px;">Open Inbox in Webmail &nbsp;&rarr;</a>
                </div>

                <details style="margin-top:18px;">
                    <summary style="cursor:pointer;color:#666;font-size:13px;">Update inbox settings</summary>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:18px 22px;margin-top:12px;">
                        <input type="hidden" name="action" value="slw_save_mailbox_settings" />
                        <?php wp_nonce_field( 'slw_save_mailbox_settings' ); ?>
                        <p style="margin-top:0;">
                            <label for="slw_wholesale_email_address" style="display:block;font-weight:600;margin-bottom:4px;">Wholesale Email Address</label>
                            <input type="email" id="slw_wholesale_email_address" name="slw_wholesale_email_address" value="<?php echo esc_attr( $email ); ?>" class="regular-text" />
                        </p>
                        <p>
                            <label for="slw_webmail_url" style="display:block;font-weight:600;margin-bottom:4px;">Webmail URL</label>
                            <input type="url" id="slw_webmail_url" name="slw_webmail_url" value="<?php echo esc_attr( $webmail_url ); ?>" class="large-text" />
                        </p>
                        <?php submit_button( 'Save Settings', 'secondary', 'submit', false ); ?>
                    </form>
                </details>
            <?php else : ?>
                <!--
                    First-time setup: the steps and the input field live in
                    ONE card so the path from "read instructions" to "paste
                    the URL and save" is impossible to miss. Earlier (4.6.8 -
                    4.6.10) the steps were yellow card, settings were a
                    separate form below — Holly literally asked "what Inbox
                    tab settings am I entering it into" because she didn't
                    scroll past the yellow card.
                -->
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <input type="hidden" name="action" value="slw_save_mailbox_settings" />
                    <?php wp_nonce_field( 'slw_save_mailbox_settings' ); ?>
                    <input type="hidden" name="slw_wholesale_email_address" value="<?php echo esc_attr( $email ); ?>" />
                    <div style="background:#FFF8E1;border:1px solid #ffe082;border-radius:8px;padding:22px 26px;margin-top:16px;">
                        <h2 style="margin-top:0;color:#5d4037;">First-time setup</h2>
                        <ol style="color:#5d4037;line-height:1.7;margin-bottom:18px;">
                            <li>Log in to <a href="https://my.siteground.com" target="_blank" rel="noopener">my.siteground.com</a> and open Site Tools for <strong>segolilyskincare.com</strong>.</li>
                            <li>Go to <strong>Email &raquo; Accounts</strong>.</li>
                            <li>Find <strong><?php echo esc_html( $email ); ?></strong> in the list, click the kebab menu (&hellip;), then choose <strong>Login to Webmail</strong>.</li>
                            <li>Once Webmail opens, copy the URL from the address bar.</li>
                            <li>Paste it in the field below and click <strong>Save</strong>.</li>
                        </ol>
                        <div style="background:#fff;border:1px solid #ffe082;border-radius:6px;padding:14px 16px;">
                            <label for="slw_webmail_url" style="display:block;font-weight:600;color:#5d4037;margin-bottom:6px;">Paste the Webmail URL here</label>
                            <input type="url" id="slw_webmail_url" name="slw_webmail_url" value="" class="large-text" placeholder="https://&hellip;siteground.biz/webmail/&hellip;" required style="margin-bottom:10px;" />
                            <button type="submit" class="button button-primary">Save &amp; Activate Inbox</button>
                        </div>
                    </div>
                </form>
            <?php endif; ?>

            <p style="margin-top:24px;color:#999;font-size:12px;">Coming later: read recent messages inline without leaving WordPress (full IMAP integration).</p>
        </div>
        <?php
    }
}

SLW_Mailbox::init();
