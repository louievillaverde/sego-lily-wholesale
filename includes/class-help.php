<?php
/**
 * Help / Getting Started Page
 *
 * Step-by-step setup guide, quick links, system info, and support section
 * for the Wholesale Portal admin area.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class SLW_Help {

    public static function init() {
        // No hooks needed — page renders on demand via admin menu.
    }

    public static function render_page() {
        $steps = self::get_steps();
        $quick_links = self::get_quick_links();
        $system_info = self::get_system_info();
        ?>
        <div class="wrap slw-admin-dashboard slw-help">
            <h1 class="slw-admin-dashboard__title">Help & Getting Started</h1>
            <p class="slw-admin-dashboard__subtitle">Everything you need to set up and run your wholesale portal</p>

            <!-- Getting Started Guide -->
            <div class="slw-admin-card slw-help__guide">
                <h2 class="slw-admin-card__heading">Setup Guide</h2>
                <p style="color:#628393;margin-bottom:20px;">Follow these steps to get your wholesale portal up and running.</p>

                <div class="slw-help__accordion">
                    <?php foreach ( $steps as $i => $step ) : ?>
                        <div class="slw-help__step">
                            <button type="button" class="slw-help__step-toggle" onclick="this.parentElement.classList.toggle('slw-help__step--open')">
                                <span class="slw-help__step-num"><?php echo esc_html( $i + 1 ); ?></span>
                                <span class="slw-help__step-title"><?php echo esc_html( $step['title'] ); ?></span>
                                <span class="slw-help__step-arrow dashicons dashicons-arrow-down-alt2"></span>
                            </button>
                            <div class="slw-help__step-body">
                                <p><?php echo esc_html( $step['description'] ); ?></p>
                                <?php if ( ! empty( $step['url'] ) ) : ?>
                                    <a href="<?php echo esc_url( $step['url'] ); ?>" class="button button-primary slw-help__step-btn">
                                        <?php echo esc_html( $step['button'] ); ?> &rarr;
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Quick Links Grid -->
            <div class="slw-admin-card">
                <h2 class="slw-admin-card__heading">Quick Links</h2>
                <div class="slw-help__links-grid">
                    <?php foreach ( $quick_links as $link ) : ?>
                        <a href="<?php echo esc_url( $link['url'] ); ?>" class="slw-help__link-card" <?php echo ! empty( $link['external'] ) ? 'target="_blank"' : ''; ?>>
                            <span class="dashicons <?php echo esc_attr( $link['icon'] ); ?> slw-help__link-icon"></span>
                            <span class="slw-help__link-label"><?php echo esc_html( $link['label'] ); ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- System Info -->
            <div class="slw-admin-card slw-help__sysinfo">
                <button type="button" class="slw-help__sysinfo-toggle" onclick="this.nextElementSibling.classList.toggle('slw-help__sysinfo-body--open');this.querySelector('.dashicons').classList.toggle('dashicons-arrow-down-alt2');this.querySelector('.dashicons').classList.toggle('dashicons-arrow-up-alt2');">
                    <h2 class="slw-admin-card__heading" style="margin:0;">System Information</h2>
                    <span class="dashicons dashicons-arrow-down-alt2"></span>
                </button>
                <div class="slw-help__sysinfo-body">
                    <table class="slw-help__sysinfo-table">
                        <?php foreach ( $system_info as $label => $value ) : ?>
                            <tr>
                                <th><?php echo esc_html( $label ); ?></th>
                                <td><?php echo esc_html( $value ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </table>
                </div>
            </div>

            <!-- Support -->
            <div class="slw-admin-card">
                <h2 class="slw-admin-card__heading">Support</h2>
                <p style="color:#628393;">Need help? Contact your administrator or the plugin developer.</p>
                <p>
                    <a href="mailto:support@leadpiranha.com" class="button button-primary">Contact Support</a>
                    <a href="https://github.com/louievillaverde/sego-lily-wholesale#readme" class="button" target="_blank">View Documentation</a>
                </p>
                <p class="slw-admin-card__version" style="margin-top:16px;">Wholesale Portal v<?php echo esc_html( SLW_VERSION ); ?></p>
            </div>
        </div>
        <?php
    }

    private static function get_steps() {
        return array(
            array(
                'title'       => 'Set up your wholesale discount and order minimums',
                'description' => 'Configure the default wholesale discount percentage, first order minimum, and reorder minimum. These are the core settings that determine your wholesale pricing and order requirements.',
                'url'         => admin_url( 'admin.php?page=slw-settings' ),
                'button'      => 'Go to Settings',
            ),
            array(
                'title'       => 'Customize your application form page',
                'description' => 'Your wholesale application page was auto-created at /wholesale-partners. You can edit the page content, add branding, or customize the fields your applicants see.',
                'url'         => admin_url( 'edit.php?post_type=page&s=wholesale+partners' ),
                'button'      => 'Edit Application Page',
            ),
            array(
                'title'       => 'Configure invoice branding',
                'description' => 'Upload your company logo and customize the header/footer text that appears on PDF invoices sent to your wholesale customers.',
                'url'         => class_exists( 'SLW_Invoice_Settings' ) ? admin_url( 'admin.php?page=slw-invoice-settings' ) : '',
                'button'      => 'Go to Invoice Settings',
            ),
            array(
                'title'       => 'Set up wholesale tiers (optional)',
                'description' => 'Create multiple pricing tiers based on order volume or customer level. Tiers allow you to offer better discounts to your highest-volume buyers.',
                'url'         => class_exists( 'SLW_Tier_Settings' ) ? admin_url( 'admin.php?page=slw-tiers' ) : '',
                'button'      => 'Go to Tiers',
            ),
            array(
                'title'       => 'Import existing wholesale customers (optional)',
                'description' => 'If you have existing wholesale customers, you can import them via CSV. Each imported user will receive the wholesale_customer role and get access to wholesale pricing.',
                'url'         => class_exists( 'SLW_Premium_Features' ) ? admin_url( 'admin.php?page=slw-import' ) : '',
                'button'      => 'Go to Import',
            ),
            array(
                'title'       => 'Test the full wholesale flow',
                'description' => 'Visit your application page as a logged-out user, submit a test application, approve it from the admin, then log in as the test user to verify wholesale pricing and the order form.',
                'url'         => home_url( '/wholesale-partners' ),
                'button'      => 'View Application Form',
            ),
        );
    }

    private static function get_quick_links() {
        $links = array(
            array(
                'label'    => 'Documentation',
                'icon'     => 'dashicons-book',
                'url'      => 'https://github.com/louievillaverde/sego-lily-wholesale#readme',
                'external' => true,
            ),
            array(
                'label' => 'Settings',
                'icon'  => 'dashicons-admin-settings',
                'url'   => admin_url( 'admin.php?page=slw-settings' ),
            ),
        );

        if ( class_exists( 'SLW_Invoice_Settings' ) ) {
            $links[] = array(
                'label' => 'Invoice Settings',
                'icon'  => 'dashicons-media-document',
                'url'   => admin_url( 'admin.php?page=slw-invoice-settings' ),
            );
        }

        $links[] = array(
            'label' => 'Application Form',
            'icon'  => 'dashicons-clipboard',
            'url'   => home_url( '/wholesale-partners' ),
            'external' => true,
        );
        $links[] = array(
            'label' => 'Order Form',
            'icon'  => 'dashicons-store',
            'url'   => home_url( '/wholesale-order' ),
            'external' => true,
        );
        $links[] = array(
            'label' => 'Customer Dashboard',
            'icon'  => 'dashicons-dashboard',
            'url'   => home_url( '/wholesale-dashboard' ),
            'external' => true,
        );
        $links[] = array(
            'label'    => 'Changelog',
            'icon'     => 'dashicons-update',
            'url'      => 'https://github.com/louievillaverde/sego-lily-wholesale/releases',
            'external' => true,
        );

        return $links;
    }

    private static function get_system_info() {
        global $wpdb;

        $customer_count = 0;
        $user_count = count_users();
        if ( isset( $user_count['avail_roles']['wholesale_customer'] ) ) {
            $customer_count = $user_count['avail_roles']['wholesale_customer'];
        }

        $app_count = 0;
        $app_table = $wpdb->prefix . 'slw_applications';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $app_table ) ) === $app_table ) {
            $app_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$app_table}" );
        }

        $wc_version = defined( 'WC_VERSION' ) ? WC_VERSION : 'N/A';

        return array(
            'Plugin Version'      => SLW_VERSION,
            'WordPress Version'   => get_bloginfo( 'version' ),
            'WooCommerce Version' => $wc_version,
            'PHP Version'         => phpversion(),
            'Active Customers'    => $customer_count,
            'Total Applications'  => $app_count,
        );
    }
}
