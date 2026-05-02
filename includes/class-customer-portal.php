<?php
/**
 * Wholesale Customer Portal
 *
 * Unified tabbed portal page that serves as the home base for wholesale
 * customers. Combines dashboard, order form, invoices, account settings,
 * RFQ, price list, and help into a single page with tab navigation.
 *
 * Shortcode: [wholesale_portal]
 * URL: /wholesale-portal
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class SLW_Customer_Portal {

    /** @var array Tab definitions: slug => label */
    private static $tabs = array(
        'dashboard'  => 'Dashboard',
        'orders'     => 'Order Form',
        'invoices'   => 'Invoices',
        'account'    => 'Account',
        'assets'     => 'Assets',
        'rfq'        => 'Request a Quote',
        'price-list' => 'Price List',
        'help'       => 'Help / Contact',
    );

    public static function init() {
        add_shortcode( 'wholesale_portal', array( __CLASS__, 'render' ) );
        add_action( 'admin_post_slw_save_ein', array( __CLASS__, 'handle_save_ein' ) );
    }

    /**
     * Handle the customer-side EIN update form submission.
     * Encrypts before storing so the same field is portable between
     * application records and user_meta.
     */
    public static function handle_save_ein() {
        if ( ! is_user_logged_in() ) {
            wp_die( 'You must be logged in.', 'Unauthorized', array( 'response' => 401 ) );
        }
        check_admin_referer( 'slw_save_ein' );

        $user_id = get_current_user_id();
        if ( ! slw_is_wholesale_user( $user_id ) ) {
            wp_die( 'Wholesale customers only.', 'Unauthorized', array( 'response' => 403 ) );
        }

        $ein = sanitize_text_field( wp_unslash( $_POST['slw_ein'] ?? '' ) );
        if ( $ein === '' ) {
            // Empty submit clears the value.
            update_user_meta( $user_id, 'slw_ein', '' );
        } else {
            update_user_meta( $user_id, 'slw_ein', SLW_Encryption::encrypt( $ein ) );
        }

        $redirect = wp_get_referer() ?: home_url( '/wholesale-portal/?tab=account' );
        $redirect = add_query_arg( 'slw_ein_saved', '1', $redirect );
        wp_safe_redirect( $redirect );
        exit;
    }

    /**
     * Decrypt and return the current user's EIN. Empty string if unset.
     */
    public static function get_user_ein( $user_id ) {
        $stored = get_user_meta( $user_id, 'slw_ein', true );
        if ( ! $stored ) {
            return '';
        }
        return class_exists( 'SLW_Encryption' ) ? SLW_Encryption::decrypt( $stored ) : $stored;
    }

    /**
     * Render the portal. Non-wholesale users get redirected.
     */
    public static function render( $atts = array() ) {
        $is_admin_preview = isset( $_GET['slw_preview'] ) && current_user_can( 'manage_woocommerce' );

        if ( ! $is_admin_preview && ( ! is_user_logged_in() || ! slw_is_wholesale_user() ) ) {
            if ( ! is_admin() ) {
                wp_redirect( home_url( '/wholesale-partners' ) );
                exit;
            }
            return '<div class="slw-notice slw-notice-warning">Please <a href="' . esc_url( wp_login_url( home_url( '/wholesale-portal' ) ) ) . '">log in</a> with your wholesale account.</div>';
        }

        // Determine active tab (check both 'tab' for frontend and 'portal_tab' for admin)
        $active_tab = 'dashboard';
        if ( isset( $_GET['portal_tab'] ) ) {
            $active_tab = sanitize_key( $_GET['portal_tab'] );
        } elseif ( isset( $_GET['tab'] ) ) {
            $active_tab = sanitize_key( $_GET['tab'] );
        }
        if ( ! array_key_exists( $active_tab, self::$tabs ) ) {
            $active_tab = 'dashboard';
        }

        ob_start();

        // Admin preview banner
        if ( $is_admin_preview && class_exists( 'SLW_Dashboard' ) ) {
            SLW_Dashboard::render_preview_banner( 'Customer Portal — ' . esc_html( self::$tabs[ $active_tab ] ) );
        }

        // When rendered inside the admin Preview page, tab URLs must stay in admin
        $is_admin_context = is_admin();
        if ( $is_admin_context ) {
            $portal_url = admin_url( 'admin.php?page=slw-preview' );
        } else {
            $portal_url = home_url( '/wholesale-portal' );
        }
        // Preserve preview params when building tab URLs
        $extra_params = array();
        if ( $is_admin_preview ) {
            $extra_params['slw_preview'] = '1';
            if ( isset( $_GET['slw_view_as'] ) ) {
                $extra_params['slw_view_as'] = absint( $_GET['slw_view_as'] );
            }
        }
        ?>
        <div class="slw-portal-wrap">

            <!-- Tab Navigation -->
            <nav class="slw-portal-tabs" role="tablist">
                <div class="slw-portal-tabs-inner">
                    <?php foreach ( self::$tabs as $slug => $label ) :
                        $tab_key = $is_admin_context ? 'portal_tab' : 'tab';
                        $params = array_merge( $extra_params, array( $tab_key => $slug ) );
                        // Dashboard is default — no tab param needed
                        if ( $slug === 'dashboard' ) {
                            unset( $params[ $tab_key ] );
                        }
                        $url = add_query_arg( $params, $portal_url );
                        $is_active = ( $slug === $active_tab );
                    ?>
                        <a href="<?php echo esc_url( $url ); ?>"
                           class="slw-portal-tab<?php echo $is_active ? ' slw-portal-tab-active' : ''; ?>"
                           role="tab"
                           aria-selected="<?php echo $is_active ? 'true' : 'false'; ?>"
                           data-tab="<?php echo esc_attr( $slug ); ?>">
                            <?php echo esc_html( $label ); ?>
                        </a>
                    <?php endforeach; ?>
                </div>

                <!-- Mobile dropdown -->
                <div class="slw-portal-tabs-mobile">
                    <select class="slw-portal-tab-select" onchange="if(this.value) window.location.href=this.value;">
                        <?php foreach ( self::$tabs as $slug => $label ) :
                            $mobile_tab_key = $is_admin_context ? 'portal_tab' : 'tab';
                            $params = array_merge( $extra_params, array( $mobile_tab_key => $slug ) );
                            if ( $slug === 'dashboard' ) {
                                unset( $params['tab'] );
                            }
                            $url = add_query_arg( $params, $portal_url );
                        ?>
                            <option value="<?php echo esc_url( $url ); ?>" <?php selected( $slug, $active_tab ); ?>>
                                <?php echo esc_html( $label ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </nav>

            <!-- Tab Content -->
            <div class="slw-portal-content">
                <?php
                switch ( $active_tab ) {
                    case 'dashboard':
                        self::render_dashboard_tab();
                        break;
                    case 'orders':
                        self::render_orders_tab();
                        break;
                    case 'invoices':
                        self::render_invoices_tab();
                        break;
                    case 'account':
                        self::render_account_tab();
                        break;
                    case 'assets':
                        self::render_assets_tab();
                        break;
                    case 'rfq':
                        self::render_rfq_tab();
                        break;
                    case 'price-list':
                        self::render_price_list_tab();
                        break;
                    case 'help':
                        self::render_help_tab();
                        break;
                }
                ?>
            </div>
        </div>
        <?php

        return ob_get_clean();
    }

    // ── Tab Renderers ─────────────────────────────────────────────────────

    /**
     * Dashboard tab — reuses existing dashboard template.
     */
    private static function render_dashboard_tab() {
        include SLW_PLUGIN_DIR . 'templates/dashboard.php';
    }

    /**
     * Order Form tab — reuses existing order form template.
     */
    private static function render_orders_tab() {
        include SLW_PLUGIN_DIR . 'templates/order-form.php';
    }

    /**
     * Invoices tab — lists all orders with invoice download links.
     */
    private static function render_invoices_tab() {
        $user = wp_get_current_user();
        $current_page = isset( $_GET['slw_page'] ) ? absint( $_GET['slw_page'] ) : 1;

        $orders = wc_get_orders( array(
            'customer' => $user->ID,
            'limit'    => 20,
            'offset'   => ( max( 1, $current_page ) - 1 ) * 20,
            'orderby'  => 'date',
            'order'    => 'DESC',
            'paginate' => true,
        ) );

        $has_invoice_class = class_exists( 'SLW_PDF_Invoices' );
        ?>
        <div class="slw-invoices-tab">
            <h3>Invoices</h3>
            <p>Download invoices for your past orders. Invoices are generated as printable PDFs.</p>

            <?php if ( empty( $orders->orders ) ) : ?>
                <p class="slw-empty-orders">No orders yet. Place your first order to see invoices here.</p>
            <?php else : ?>
            <div class="slw-order-history-table-wrap">
                <table class="slw-order-history-table">
                    <thead>
                        <tr>
                            <th>Invoice / Order</th>
                            <th>Date</th>
                            <th>Status</th>
                            <th>Total</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $orders->orders as $order ) :
                            $status = $order->get_status();
                            $invoice_url = '';
                            if ( $has_invoice_class ) {
                                $invoice_url = add_query_arg( array(
                                    'slw_invoice' => $order->get_id(),
                                    'key'         => $order->get_order_key(),
                                ), home_url( '/' ) );
                            }
                        ?>
                        <tr>
                            <td>
                                <a href="<?php echo esc_url( $order->get_view_order_url() ); ?>">
                                    #<?php echo esc_html( $order->get_order_number() ); ?>
                                </a>
                            </td>
                            <td><?php echo esc_html( $order->get_date_created()->date( 'M j, Y' ) ); ?></td>
                            <td>
                                <span class="slw-status-badge slw-status-<?php echo esc_attr( $status ); ?>">
                                    <?php echo esc_html( wc_get_order_status_name( $status ) ); ?>
                                </span>
                            </td>
                            <td><?php echo wp_kses_post( $order->get_formatted_order_total() ); ?></td>
                            <td class="slw-order-actions">
                                <a href="<?php echo esc_url( $order->get_view_order_url() ); ?>" class="slw-btn slw-btn-small slw-btn-ghost">View</a>
                                <?php if ( $invoice_url ) : ?>
                                    <a href="<?php echo esc_url( $invoice_url ); ?>" class="slw-btn slw-btn-small slw-btn-primary" target="_blank">Download Invoice</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php
            $total_pages = $orders->max_num_pages;
            if ( $total_pages > 1 ) :
                $base_url = add_query_arg( 'tab', 'invoices', home_url( '/wholesale-portal' ) );
            ?>
            <div class="slw-pagination">
                <?php if ( $current_page > 1 ) : ?>
                    <a href="<?php echo esc_url( add_query_arg( 'slw_page', $current_page - 1, $base_url ) ); ?>" class="slw-btn slw-btn-small slw-btn-ghost">&larr; Previous</a>
                <?php else : ?>
                    <span class="slw-btn slw-btn-small slw-btn-ghost slw-pagination-disabled">&larr; Previous</span>
                <?php endif; ?>

                <span class="slw-pagination-info">
                    Page <?php echo esc_html( $current_page ); ?> of <?php echo esc_html( $total_pages ); ?>
                </span>

                <?php if ( $current_page < $total_pages ) : ?>
                    <a href="<?php echo esc_url( add_query_arg( 'slw_page', $current_page + 1, $base_url ) ); ?>" class="slw-btn slw-btn-small slw-btn-ghost">Next &rarr;</a>
                <?php else : ?>
                    <span class="slw-btn slw-btn-small slw-btn-ghost slw-pagination-disabled">Next &rarr;</span>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Account tab — edit profile, change password, shipping address.
     */
    private static function render_account_tab() {
        $user = wp_get_current_user();
        $edit_account_url = wc_get_account_endpoint_url( 'edit-account' );
        $edit_address_url = wc_get_account_endpoint_url( 'edit-address' );
        $business_name    = get_user_meta( $user->ID, 'slw_business_name', true );
        $net30_approved   = get_user_meta( $user->ID, 'slw_net30_approved', true ) === '1';
        $ein              = self::get_user_ein( $user->ID );
        $ein_just_saved   = ! empty( $_GET['slw_ein_saved'] );
        ?>
        <div class="slw-account-tab">
            <h3>Account Settings</h3>

            <?php if ( $ein_just_saved ) : ?>
                <div class="slw-notice slw-notice-success" style="margin-bottom:16px;">EIN saved.</div>
            <?php elseif ( empty( $ein ) ) : ?>
                <div class="slw-notice slw-notice-warning" style="margin-bottom:16px;">
                    <strong>Action needed:</strong> please add your EIN / Resale Certificate number below. We need it on file before your first order.
                </div>
            <?php endif; ?>

            <div class="slw-dashboard-grid">
                <div class="slw-dashboard-card">
                    <h4>Profile Information</h4>
                    <dl class="slw-account-summary">
                        <div class="slw-account-summary-row">
                            <dt>Name</dt>
                            <dd><?php echo esc_html( $user->first_name . ' ' . $user->last_name ); ?></dd>
                        </div>
                        <div class="slw-account-summary-row">
                            <dt>Email</dt>
                            <dd><?php echo esc_html( $user->user_email ); ?></dd>
                        </div>
                        <?php if ( $business_name ) : ?>
                        <div class="slw-account-summary-row">
                            <dt>Business</dt>
                            <dd><?php echo esc_html( $business_name ); ?></dd>
                        </div>
                        <?php endif; ?>
                        <?php if ( $net30_approved ) : ?>
                        <div class="slw-account-summary-row">
                            <dt>Payment Terms</dt>
                            <dd><strong>NET 30 Approved</strong></dd>
                        </div>
                        <?php endif; ?>
                    </dl>
                    <div style="margin-top:16px;">
                        <a href="<?php echo esc_url( $edit_account_url ); ?>" class="slw-btn slw-btn-primary">Edit Account Details</a>
                    </div>
                </div>

                <div class="slw-dashboard-card">
                    <h4>Shipping Address</h4>
                    <?php
                    $address_parts = array_filter( array(
                        get_user_meta( $user->ID, 'shipping_address_1', true ),
                        get_user_meta( $user->ID, 'shipping_address_2', true ),
                        get_user_meta( $user->ID, 'shipping_city', true ),
                        get_user_meta( $user->ID, 'shipping_state', true ) . ' ' . get_user_meta( $user->ID, 'shipping_postcode', true ),
                    ) );
                    if ( ! empty( $address_parts ) ) : ?>
                        <p><?php echo esc_html( implode( ', ', $address_parts ) ); ?></p>
                    <?php else : ?>
                        <p class="slw-empty-orders">No shipping address on file.</p>
                    <?php endif; ?>
                    <div style="margin-top:16px;">
                        <a href="<?php echo esc_url( $edit_address_url ); ?>" class="slw-btn slw-btn-secondary">Update Shipping Address</a>
                    </div>
                </div>

                <div class="slw-dashboard-card">
                    <h4>Password</h4>
                    <p>Change your account password from the WooCommerce account page.</p>
                    <div style="margin-top:16px;">
                        <a href="<?php echo esc_url( $edit_account_url ); ?>" class="slw-btn slw-btn-secondary">Change Password</a>
                    </div>
                </div>

                <div class="slw-dashboard-card">
                    <h4>EIN / Resale Certificate</h4>
                    <p>Required for tax-exempt wholesale ordering. Stored encrypted at rest.</p>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                        <?php wp_nonce_field( 'slw_save_ein' ); ?>
                        <input type="hidden" name="action" value="slw_save_ein" />
                        <label for="slw-ein-input" style="display:block;margin-bottom:6px;font-weight:600;">EIN Number</label>
                        <input type="text" id="slw-ein-input" name="slw_ein"
                               value="<?php echo esc_attr( $ein ); ?>"
                               placeholder="XX-XXXXXXX"
                               style="width:100%;padding:8px;margin-bottom:12px;" />
                        <button type="submit" class="slw-btn slw-btn-primary">Save EIN</button>
                    </form>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Assets tab — onboarding materials (images, PDFs, video links, etc.).
     */
    private static function render_assets_tab() {
        $user = wp_get_current_user();
        $assets = class_exists( 'SLW_Customer_Assets' )
            ? SLW_Customer_Assets::get_assets_for_user( $user->ID )
            : array();

        $type_icons = array(
            'image' => '&#128247;', // camera
            'pdf'   => '&#128196;', // doc
            'video' => '&#127909;', // film
            'link'  => '&#128279;', // link
        );
        $type_labels = array(
            'image' => 'Image',
            'pdf'   => 'PDF',
            'video' => 'Video',
            'link'  => 'Link',
        );
        ?>
        <div class="slw-assets-tab">
            <h3>Brand Assets &amp; Resources</h3>
            <p>Logos, product photos, shelf talkers, and marketing materials for your retail displays. Need something specific that's not here? <a href="?tab=help">Just ask</a>.</p>

            <?php if ( empty( $assets ) ) : ?>
                <div class="slw-notice slw-notice-info" style="margin-top:16px;">
                    No assets available yet — we'll add them shortly. In the meantime, reach out via the Help tab and we'll send what you need by email.
                </div>
            <?php else : ?>
                <div class="slw-dashboard-grid" style="grid-template-columns:repeat(auto-fill,minmax(240px,1fr));">
                    <?php foreach ( $assets as $asset ) :
                        $type  = $asset['type'] ?? 'link';
                        $icon  = $type_icons[ $type ] ?? $type_icons['link'];
                        $label = $type_labels[ $type ] ?? $type_labels['link'];
                        $thumb = $asset['thumbnail'] ?? '';
                    ?>
                        <div class="slw-dashboard-card slw-asset-card" style="display:flex;flex-direction:column;gap:8px;">
                            <?php if ( $thumb ) : ?>
                                <div class="slw-asset-card__thumb" style="width:100%;aspect-ratio:16/10;background:#f0eee9 url('<?php echo esc_url( $thumb ); ?>') center/cover no-repeat;border-radius:6px;"></div>
                            <?php else : ?>
                                <div class="slw-asset-card__thumb" style="width:100%;aspect-ratio:16/10;background:#F7F6F3;border-radius:6px;display:flex;align-items:center;justify-content:center;font-size:42px;color:#386174;">
                                    <?php echo $icon; ?>
                                </div>
                            <?php endif; ?>
                            <div style="font-size:11px;text-transform:uppercase;letter-spacing:0.05em;color:#628393;font-weight:600;"><?php echo esc_html( $label ); ?></div>
                            <h4 style="margin:0;font-size:16px;"><?php echo esc_html( $asset['title'] ?? '' ); ?></h4>
                            <?php if ( ! empty( $asset['description'] ) ) : ?>
                                <p style="margin:0;font-size:13px;color:#628393;line-height:1.45;"><?php echo esc_html( $asset['description'] ); ?></p>
                            <?php endif; ?>
                            <div style="margin-top:auto;padding-top:8px;">
                                <a href="<?php echo esc_url( $asset['url'] ?? '#' ); ?>" target="_blank" rel="noopener" class="slw-btn slw-btn-small slw-btn-primary">
                                    <?php echo $type === 'video' || $type === 'link' ? 'Open' : 'Download'; ?>
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * RFQ tab — renders the existing RFQ shortcode.
     */
    private static function render_rfq_tab() {
        if ( class_exists( 'SLW_RFQ' ) ) {
            // Use the existing shortcode render method
            echo SLW_RFQ::render_form();
        } else {
            echo '<div class="slw-notice slw-notice-info">The Request a Quote feature is not currently available.</div>';
        }
    }

    /**
     * Price List tab — download link for the line sheet.
     */
    private static function render_price_list_tab() {
        $linesheet_url = '';
        if ( class_exists( 'SLW_PDF_Linesheet' ) ) {
            $linesheet_url = SLW_PDF_Linesheet::get_linesheet_url();
        }
        ?>
        <div class="slw-price-list-tab">
            <h3>Price List / Line Sheet</h3>
            <p>Download our current wholesale price list as a printable PDF. This includes all products, wholesale pricing, minimum quantities, and case pack sizes.</p>

            <?php if ( $linesheet_url ) : ?>
                <div style="margin-top:20px;">
                    <a href="<?php echo esc_url( $linesheet_url ); ?>" class="slw-btn slw-btn-primary" target="_blank">Download Price List (PDF)</a>
                </div>
                <p class="slw-help-text" style="margin-top:12px;">This document is confidential and intended for approved wholesale partners only.</p>
            <?php else : ?>
                <div class="slw-notice slw-notice-info">The price list is not currently available. Please contact us for pricing information.</div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Help / Contact tab.
     */
    private static function render_help_tab() {
        $contact_email = class_exists( 'SLW_Email_Settings' ) ? SLW_Email_Settings::get( 'from_address' ) : get_option( 'admin_email' );
        $contact_name  = class_exists( 'SLW_Email_Settings' ) ? SLW_Email_Settings::get( 'owner_name' ) : '';
        $contact_label = $contact_name ? esc_html( $contact_name ) : 'our wholesale team';
        ?>
        <div class="slw-help-tab">
            <h3>Help &amp; Contact</h3>

            <div class="slw-dashboard-grid">
                <div class="slw-dashboard-card">
                    <h4>Contact Us</h4>
                    <p>Have a question about your order, need to make changes, or want to discuss pricing?</p>
                    <p><strong>Email:</strong> <a href="mailto:<?php echo esc_attr( $contact_email ); ?>"><?php echo esc_html( $contact_email ); ?></a></p>
                    <?php if ( $contact_name ) : ?>
                        <p>Your wholesale account manager is <strong><?php echo esc_html( $contact_name ); ?></strong>.</p>
                    <?php endif; ?>
                </div>

                <div class="slw-dashboard-card">
                    <h4>Frequently Asked Questions</h4>
                    <dl class="slw-faq-list">
                        <div class="slw-faq-item">
                            <dt>What is the minimum order?</dt>
                            <dd>First orders have a $<?php echo esc_html( number_format( (float) slw_get_option( 'first_order_minimum', 300 ), 0 ) ); ?> minimum. Reorders may have a lower or no minimum.</dd>
                        </div>
                        <div class="slw-faq-item">
                            <dt>How do I apply for NET 30 terms?</dt>
                            <dd>After your first order, email <?php echo esc_html( $contact_label ); ?> to request NET 30 payment terms for your account.</dd>
                        </div>
                        <div class="slw-faq-item">
                            <dt>Can I get marketing materials or brand assets?</dt>
                            <dd>Yes! Email <?php echo esc_html( $contact_label ); ?> and we will send over shelf talkers, marketing materials, and digital brand assets.</dd>
                        </div>
                        <div class="slw-faq-item">
                            <dt>How do case packs work?</dt>
                            <dd>Some products must be ordered in case pack multiples (e.g., cases of 6). The order form shows the case pack size for each product.</dd>
                        </div>
                    </dl>
                </div>

                <div class="slw-dashboard-card slw-dashboard-card-wide">
                    <h4>Brand Assets</h4>
                    <p>High-resolution logos, product photos, shelf talkers, and marketing materials live on the <a href="?tab=assets">Assets tab</a>.</p>
                    <p>Need something that isn't there? Email <a href="mailto:<?php echo esc_attr( $contact_email ); ?>"><?php echo esc_html( $contact_label ); ?></a> and we'll add it.</p>
                </div>
            </div>
        </div>
        <?php
    }
}
