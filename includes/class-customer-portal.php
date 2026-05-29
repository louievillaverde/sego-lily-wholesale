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

    /** @var array Tab definitions: slug => label. 'help' is intentionally
     * absent from the visible tab bar -- the page still renders if accessed
     * via ?tab=help, surfaced from the dashboard Quick Links as
     * 'Help / Contact [Owner]'. Keeps the upper bar cleaner per Holly call. */
    private static $tabs = array(
        'dashboard'  => 'Dashboard',
        'orders'     => 'Order Form',
        'invoices'   => 'Invoices',
        'account'    => 'Account',
        'assets'     => 'Assets',
        'rfq'        => 'Request a Quote',
        'price-list' => 'Price List',
    );

    /** @var array Hidden tabs that still render if accessed by URL/slug. */
    private static $hidden_tabs = array(
        'help' => 'Help / Contact',
    );

    public static function init() {
        add_shortcode( 'wholesale_portal', array( __CLASS__, 'render' ) );
        add_action( 'admin_post_slw_save_ein',     array( __CLASS__, 'handle_save_ein' ) );
        add_action( 'admin_post_slw_save_account', array( __CLASS__, 'handle_save_account' ) );
    }

    /**
     * Render the wholesale top nav (tabs + Sign out). Used by the portal
     * itself and by the standalone Order Form page so wholesale customers
     * never lose access to their other portal sections while shopping.
     *
     * @param string $active_tab Slug of the currently-active tab.
     */
    public static function render_nav( $active_tab = 'orders' ) {
        $portal_url = home_url( '/wholesale-portal' );
        ?>
        <nav class="slw-portal-tabs slw-portal-tabs--standalone" role="tablist">
            <div class="slw-portal-tabs-inner">
                <?php foreach ( self::$tabs as $slug => $label ) :
                    $url = ( $slug === 'dashboard' )
                        ? $portal_url
                        : add_query_arg( array( 'tab' => $slug ), $portal_url );
                    $is_active = ( $slug === $active_tab );
                    // Order Form tab points directly at /wholesale-order
                    // since that's the canonical page now.
                    if ( $slug === 'orders' ) {
                        $url = home_url( '/wholesale-order' );
                    }
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
            <?php if ( is_user_logged_in() ) : ?>
                <a href="<?php echo esc_url( wp_logout_url( home_url( '/' ) ) ); ?>"
                   class="slw-portal-signout"
                   aria-label="Sign out">
                    <svg viewBox="0 0 16 16" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M6 14H3.5A1.5 1.5 0 0 1 2 12.5v-9A1.5 1.5 0 0 1 3.5 2H6"/>
                        <path d="M10 11l3-3-3-3M13 8H6"/>
                    </svg>
                    <span>Sign out</span>
                </a>
            <?php endif; ?>
            <div class="slw-portal-tabs-mobile">
                <select class="slw-portal-tab-select" onchange="if(this.value) window.location.href=this.value;">
                    <?php foreach ( self::$tabs as $slug => $label ) :
                        $url = ( $slug === 'dashboard' )
                            ? $portal_url
                            : add_query_arg( array( 'tab' => $slug ), $portal_url );
                        if ( $slug === 'orders' ) {
                            $url = home_url( '/wholesale-order' );
                        }
                    ?>
                        <option value="<?php echo esc_url( $url ); ?>" <?php selected( $slug, $active_tab ); ?>>
                            <?php echo esc_html( $label ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </nav>
        <?php
    }

    /**
     * Save handler for inline Account tab forms: profile, shipping,
     * billing, password. Routed through admin-post.php so the user
     * comes back to /wholesale-portal?tab=account&slw_account_saved=...
     * after a successful update. Updates flow into both WP user fields
     * AND the WC customer record so admins see the same data in
     * /wp-admin/user-edit.php.
     */
    public static function handle_save_account() {
        if ( ! is_user_logged_in() ) {
            wp_die( 'You must be logged in.', 'Unauthorized', array( 'response' => 401 ) );
        }
        check_admin_referer( 'slw_save_account' );

        $user_id = get_current_user_id();
        if ( ! slw_is_wholesale_user( $user_id ) ) {
            wp_die( 'Wholesale customers only.', 'Unauthorized', array( 'response' => 403 ) );
        }

        $section = sanitize_key( $_POST['section'] ?? '' );
        $section_keys = array( 'profile', 'shipping', 'billing', 'password' );
        if ( ! in_array( $section, $section_keys, true ) ) {
            wp_safe_redirect( home_url( '/wholesale-portal?tab=account' ) );
            exit;
        }

        $customer = new WC_Customer( $user_id );

        if ( $section === 'profile' ) {
            $first = sanitize_text_field( wp_unslash( $_POST['first_name'] ?? '' ) );
            $last  = sanitize_text_field( wp_unslash( $_POST['last_name']  ?? '' ) );
            $email = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
            if ( $first ) update_user_meta( $user_id, 'first_name', $first );
            if ( $last  ) update_user_meta( $user_id, 'last_name',  $last );
            if ( $email && is_email( $email ) ) {
                wp_update_user( array( 'ID' => $user_id, 'user_email' => $email ) );
                $customer->set_email( $email );
            }
            if ( $first ) $customer->set_first_name( $first );
            if ( $last  ) $customer->set_last_name( $last );
        } elseif ( $section === 'shipping' ) {
            $fields = array( 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'company' );
            foreach ( $fields as $f ) {
                $v = sanitize_text_field( wp_unslash( $_POST[ 'shipping_' . $f ] ?? '' ) );
                $customer->{ 'set_shipping_' . $f }( $v );
                update_user_meta( $user_id, 'shipping_' . $f, $v );
            }
        } elseif ( $section === 'billing' ) {
            $fields = array( 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'company', 'phone' );
            foreach ( $fields as $f ) {
                $v = sanitize_text_field( wp_unslash( $_POST[ 'billing_' . $f ] ?? '' ) );
                $customer->{ 'set_billing_' . $f }( $v );
                update_user_meta( $user_id, 'billing_' . $f, $v );
            }
            $bemail = sanitize_email( wp_unslash( $_POST['billing_email'] ?? '' ) );
            if ( $bemail && is_email( $bemail ) ) {
                $customer->set_billing_email( $bemail );
                update_user_meta( $user_id, 'billing_email', $bemail );
            }
        } elseif ( $section === 'password' ) {
            $current = (string) ( $_POST['current_password'] ?? '' );
            $new     = (string) ( $_POST['new_password'] ?? '' );
            $confirm = (string) ( $_POST['confirm_password'] ?? '' );
            $user_obj = wp_get_current_user();
            if ( ! $current || ! wp_check_password( $current, $user_obj->user_pass, $user_id ) ) {
                $redirect = add_query_arg( 'slw_account_error', 'bad_current', home_url( '/wholesale-portal?tab=account' ) );
                wp_safe_redirect( $redirect ); exit;
            }
            if ( strlen( $new ) < 8 || $new !== $confirm ) {
                $redirect = add_query_arg( 'slw_account_error', 'bad_new', home_url( '/wholesale-portal?tab=account' ) );
                wp_safe_redirect( $redirect ); exit;
            }
            wp_set_password( $new, $user_id );
            // wp_set_password logs the user out; re-authenticate so they stay in the portal.
            wp_set_current_user( $user_id );
            wp_set_auth_cookie( $user_id, true );
        }

        $customer->save();

        $redirect = add_query_arg( 'slw_account_saved', $section, home_url( '/wholesale-portal?tab=account' ) );
        wp_safe_redirect( $redirect );
        exit;
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
        // Admins viewing the portal auto-enter preview context: no ?slw_preview
        // required. The portal-render global also flips slw_is_wholesale_context
        // to true so order form prices reflect wholesale rates while admin browses.
        $GLOBALS['slw_in_portal_render'] = true;
        $is_admin_preview = current_user_can( 'manage_woocommerce' ) && (
            isset( $_GET['slw_preview'] ) || ! slw_is_wholesale_user()
        );

        if ( ! $is_admin_preview && ( ! is_user_logged_in() || ! slw_is_wholesale_user() ) ) {
            $GLOBALS['slw_in_portal_render'] = false;
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
        $known = array_merge( self::$tabs, self::$hidden_tabs );
        if ( ! array_key_exists( $active_tab, $known ) ) {
            $active_tab = 'dashboard';
        }

        ob_start();

        // Admin preview banner
        if ( $is_admin_preview && class_exists( 'SLW_Dashboard' ) ) {
            SLW_Dashboard::render_preview_banner( 'Customer Portal: ' . esc_html( $known[ $active_tab ] ?? ucfirst( $active_tab ) ) );
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
                        // Dashboard is default. No tab param needed.
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

                <?php if ( ! $is_admin_context && is_user_logged_in() ) : ?>
                    <a href="<?php echo esc_url( wp_logout_url( home_url( '/' ) ) ); ?>"
                       class="slw-portal-signout"
                       aria-label="Sign out">
                        <svg viewBox="0 0 16 16" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M6 14H3.5A1.5 1.5 0 0 1 2 12.5v-9A1.5 1.5 0 0 1 3.5 2H6"/>
                            <path d="M10 11l3-3-3-3M13 8H6"/>
                        </svg>
                        <span>Sign out</span>
                    </a>
                <?php endif; ?>

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

        $GLOBALS['slw_in_portal_render'] = false;
        return ob_get_clean();
    }

    // ── Tab Renderers ─────────────────────────────────────────────────────

    /**
     * Dashboard tab. Reuses existing dashboard template.
     */
    private static function render_dashboard_tab() {
        include SLW_PLUGIN_DIR . 'templates/dashboard.php';
    }

    /**
     * Order Form tab. Reuses existing order form template.
     */
    private static function render_orders_tab() {
        include SLW_PLUGIN_DIR . 'templates/order-form.php';
    }

    /**
     * Invoices tab. Lists all orders with invoice download links.
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
     * Account tab. Inline forms for profile, shipping, billing, password,
     * + EIN. All saves route through admin-post.php?action=slw_save_account
     * (and slw_save_ein for the legacy field). No redirects out to
     * /my-account anymore -- everything happens inside the portal.
     */
    private static function render_account_tab() {
        $user           = wp_get_current_user();
        $uid            = $user->ID;
        $business_name  = get_user_meta( $uid, 'slw_business_name', true );
        $net30_approved = get_user_meta( $uid, 'slw_net30_approved', true ) === '1';
        $ein            = self::get_user_ein( $uid );
        $ein_just_saved = ! empty( $_GET['slw_ein_saved'] );
        $saved_section  = sanitize_key( $_GET['slw_account_saved'] ?? '' );
        $error_code     = sanitize_key( $_GET['slw_account_error'] ?? '' );
        $post_url       = admin_url( 'admin-post.php' );

        $get_meta = function( $key ) use ( $uid ) {
            return (string) get_user_meta( $uid, $key, true );
        };

        $section_labels = array(
            'profile'  => 'Profile updated.',
            'shipping' => 'Shipping address saved.',
            'billing'  => 'Billing details saved.',
            'password' => 'Password updated.',
        );
        ?>
        <div class="slw-account-tab">
            <h3>Account Settings</h3>

            <?php if ( $saved_section && isset( $section_labels[ $saved_section ] ) ) : ?>
                <div class="slw-notice slw-notice-success">&#10003; <?php echo esc_html( $section_labels[ $saved_section ] ); ?></div>
            <?php endif; ?>
            <?php if ( $error_code === 'bad_current' ) : ?>
                <div class="slw-notice slw-notice-error">Current password is incorrect.</div>
            <?php elseif ( $error_code === 'bad_new' ) : ?>
                <div class="slw-notice slw-notice-error">New password must be at least 8 characters and match the confirmation.</div>
            <?php endif; ?>
            <?php if ( $ein_just_saved ) : ?>
                <div class="slw-notice slw-notice-success">EIN saved.</div>
            <?php elseif ( empty( $ein ) ) : ?>
                <div class="slw-notice slw-notice-warning">
                    <strong>Action needed:</strong> add your EIN / Resale Certificate below. We need it on file before your first order.
                </div>
            <?php endif; ?>

            <div class="slw-account-grid">

                <!-- Profile (first) -->
                <form method="post" action="<?php echo esc_url( $post_url ); ?>" class="slw-account-card" id="profile">
                    <?php wp_nonce_field( 'slw_save_account' ); ?>
                    <input type="hidden" name="action"  value="slw_save_account" />
                    <input type="hidden" name="section" value="profile" />
                    <h4 class="slw-account-card__title">Profile</h4>
                    <div class="slw-account-card__grid">
                        <label class="slw-account-field">
                            <span>First name</span>
                            <input type="text" name="first_name" value="<?php echo esc_attr( $user->first_name ); ?>" />
                        </label>
                        <label class="slw-account-field">
                            <span>Last name</span>
                            <input type="text" name="last_name" value="<?php echo esc_attr( $user->last_name ); ?>" />
                        </label>
                        <label class="slw-account-field slw-account-field--wide">
                            <span>Email</span>
                            <input type="email" name="email" value="<?php echo esc_attr( $user->user_email ); ?>" />
                        </label>
                        <?php if ( $business_name ) : ?>
                            <div class="slw-account-readout">
                                <span class="slw-account-readout__label">Business</span>
                                <span class="slw-account-readout__value"><?php echo esc_html( $business_name ); ?></span>
                            </div>
                        <?php endif; ?>
                        <?php if ( $net30_approved ) : ?>
                            <div class="slw-account-readout">
                                <span class="slw-account-readout__label">Payment Terms</span>
                                <span class="slw-account-readout__value"><strong>NET 30 Approved</strong></span>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="slw-account-card__actions">
                        <button type="submit" class="slw-btn slw-btn-primary">Save Profile</button>
                    </div>
                </form>

                <!-- Password (second, next to Profile) -->
                <form method="post" action="<?php echo esc_url( $post_url ); ?>" class="slw-account-card" id="password">
                    <?php wp_nonce_field( 'slw_save_account' ); ?>
                    <input type="hidden" name="action"  value="slw_save_account" />
                    <input type="hidden" name="section" value="password" />
                    <h4 class="slw-account-card__title">Password</h4>
                    <div class="slw-account-card__grid">
                        <label class="slw-account-field slw-account-field--wide">
                            <span>Current password</span>
                            <input type="password" name="current_password" autocomplete="current-password" />
                        </label>
                        <label class="slw-account-field slw-account-field--wide">
                            <span>New password (min 8 chars)</span>
                            <input type="password" name="new_password" autocomplete="new-password" />
                        </label>
                        <label class="slw-account-field slw-account-field--wide">
                            <span>Confirm new password</span>
                            <input type="password" name="confirm_password" autocomplete="new-password" />
                        </label>
                    </div>
                    <div class="slw-account-card__actions">
                        <button type="submit" class="slw-btn slw-btn-primary">Update Password</button>
                    </div>
                </form>

                <!-- Billing -->
                <form method="post" action="<?php echo esc_url( $post_url ); ?>" class="slw-account-card" id="billing">
                    <?php wp_nonce_field( 'slw_save_account' ); ?>
                    <input type="hidden" name="action"  value="slw_save_account" />
                    <input type="hidden" name="section" value="billing" />
                    <h4 class="slw-account-card__title">Billing Details</h4>
                    <div class="slw-account-card__grid">
                        <label class="slw-account-field">
                            <span>Billing email</span>
                            <input type="email" name="billing_email" value="<?php echo esc_attr( $get_meta( 'billing_email' ) ?: $user->user_email ); ?>" />
                        </label>
                        <label class="slw-account-field">
                            <span>Phone</span>
                            <input type="text" name="billing_phone" value="<?php echo esc_attr( $get_meta( 'billing_phone' ) ); ?>" />
                        </label>
                        <label class="slw-account-field slw-account-field--wide">
                            <span>Company (optional)</span>
                            <input type="text" name="billing_company" value="<?php echo esc_attr( $get_meta( 'billing_company' ) ); ?>" />
                        </label>
                        <label class="slw-account-field slw-account-field--wide">
                            <span>Address line 1</span>
                            <input type="text" name="billing_address_1" value="<?php echo esc_attr( $get_meta( 'billing_address_1' ) ); ?>" />
                        </label>
                        <label class="slw-account-field slw-account-field--wide">
                            <span>Address line 2</span>
                            <input type="text" name="billing_address_2" value="<?php echo esc_attr( $get_meta( 'billing_address_2' ) ); ?>" />
                        </label>
                        <label class="slw-account-field">
                            <span>City</span>
                            <input type="text" name="billing_city" value="<?php echo esc_attr( $get_meta( 'billing_city' ) ); ?>" />
                        </label>
                        <label class="slw-account-field">
                            <span>State / Region</span>
                            <input type="text" name="billing_state" value="<?php echo esc_attr( $get_meta( 'billing_state' ) ); ?>" />
                        </label>
                        <label class="slw-account-field">
                            <span>Postcode</span>
                            <input type="text" name="billing_postcode" value="<?php echo esc_attr( $get_meta( 'billing_postcode' ) ); ?>" />
                        </label>
                        <label class="slw-account-field">
                            <span>Country</span>
                            <input type="text" name="billing_country" value="<?php echo esc_attr( $get_meta( 'billing_country' ) ?: 'US' ); ?>" />
                        </label>
                    </div>
                    <div class="slw-account-card__actions">
                        <button type="submit" class="slw-btn slw-btn-primary">Save Billing</button>
                    </div>
                </form>

                <!-- Shipping -->
                <form method="post" action="<?php echo esc_url( $post_url ); ?>" class="slw-account-card" id="shipping">
                    <?php wp_nonce_field( 'slw_save_account' ); ?>
                    <input type="hidden" name="action"  value="slw_save_account" />
                    <input type="hidden" name="section" value="shipping" />
                    <h4 class="slw-account-card__title">Shipping Address</h4>
                    <div class="slw-account-card__grid">
                        <label class="slw-account-field slw-account-field--wide">
                            <span>Company (optional)</span>
                            <input type="text" name="shipping_company" value="<?php echo esc_attr( $get_meta( 'shipping_company' ) ); ?>" />
                        </label>
                        <label class="slw-account-field slw-account-field--wide">
                            <span>Address line 1</span>
                            <input type="text" name="shipping_address_1" value="<?php echo esc_attr( $get_meta( 'shipping_address_1' ) ); ?>" />
                        </label>
                        <label class="slw-account-field slw-account-field--wide">
                            <span>Address line 2</span>
                            <input type="text" name="shipping_address_2" value="<?php echo esc_attr( $get_meta( 'shipping_address_2' ) ); ?>" />
                        </label>
                        <label class="slw-account-field">
                            <span>City</span>
                            <input type="text" name="shipping_city" value="<?php echo esc_attr( $get_meta( 'shipping_city' ) ); ?>" />
                        </label>
                        <label class="slw-account-field">
                            <span>State / Region</span>
                            <input type="text" name="shipping_state" value="<?php echo esc_attr( $get_meta( 'shipping_state' ) ); ?>" />
                        </label>
                        <label class="slw-account-field">
                            <span>Postcode</span>
                            <input type="text" name="shipping_postcode" value="<?php echo esc_attr( $get_meta( 'shipping_postcode' ) ); ?>" />
                        </label>
                        <label class="slw-account-field">
                            <span>Country</span>
                            <input type="text" name="shipping_country" value="<?php echo esc_attr( $get_meta( 'shipping_country' ) ?: 'US' ); ?>" />
                        </label>
                    </div>
                    <div class="slw-account-card__actions">
                        <button type="submit" class="slw-btn slw-btn-primary">Save Shipping</button>
                    </div>
                </form>

                <!-- EIN / Resale Certificate (last, requires confirm) -->
                <form method="post" action="<?php echo esc_url( $post_url ); ?>" class="slw-account-card slw-account-card--sensitive" id="ein" data-confirm="Are you sure you want to update your EIN / Resale Certificate? This is a tax document on file with your business.">
                    <?php wp_nonce_field( 'slw_save_ein' ); ?>
                    <input type="hidden" name="action" value="slw_save_ein" />
                    <h4 class="slw-account-card__title">EIN / Resale Certificate</h4>
                    <p class="slw-account-card__hint">Required for tax-exempt wholesale ordering. Stored encrypted at rest. Saved changes update your customer record on the admin side immediately.</p>
                    <div class="slw-account-card__grid">
                        <label class="slw-account-field slw-account-field--wide">
                            <span>EIN Number</span>
                            <input type="text" name="slw_ein" value="<?php echo esc_attr( $ein ); ?>" placeholder="XX-XXXXXXX" />
                        </label>
                    </div>
                    <div class="slw-account-card__actions">
                        <button type="submit" class="slw-btn slw-btn-primary">Save EIN</button>
                    </div>
                </form>

            </div>
        </div>
        <script>
        (function() {
            document.querySelectorAll('.slw-account-card--sensitive').forEach(function(form) {
                var msg = form.getAttribute('data-confirm') || 'Save these changes?';
                form.addEventListener('submit', function(e) {
                    if (!window.confirm(msg)) {
                        e.preventDefault();
                    }
                });
            });
        })();
        </script>
        <?php
    }

    /**
     * Assets tab. Onboarding materials (images, PDFs, video links, etc.).
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
            <p>Logos, product photos, shelf talkers, and marketing materials for your retail displays. Need something specific that's not here? <a href="?tab=help" class="slw-text-link">Just ask</a>.</p>

            <?php if ( empty( $assets ) ) : ?>
                <div class="slw-assets-empty">
                    <div class="slw-assets-empty__icon" aria-hidden="true">&#128230;</div>
                    <h4>Your asset library is being prepared</h4>
                    <p>Logos, product photos, shelf talkers, and marketing materials will show up here as we curate them for your store. In the meantime, head to the <a href="?tab=help">Help tab</a> and we'll send what you need by email.</p>
                </div>
            <?php else : ?>
                <div class="slw-asset-grid">
                    <?php foreach ( $assets as $asset ) :
                        $type  = $asset['type'] ?? 'link';
                        $icon  = $type_icons[ $type ] ?? $type_icons['link'];
                        $label = $type_labels[ $type ] ?? $type_labels['link'];
                        $thumb = $asset['thumbnail'] ?? '';
                    ?>
                        <div class="slw-asset-card">
                            <?php if ( $thumb ) : ?>
                                <div class="slw-asset-card__thumb slw-asset-card__thumb--photo" style="background-image:url('<?php echo esc_url( $thumb ); ?>');">
                                    <span class="slw-asset-card__badge"><?php echo esc_html( $label ); ?></span>
                                </div>
                            <?php else : ?>
                                <div class="slw-asset-card__thumb slw-asset-card__thumb--placeholder slw-asset-card__thumb--<?php echo esc_attr( $type ); ?>">
                                    <span class="slw-asset-card__badge"><?php echo esc_html( $label ); ?></span>
                                    <span class="slw-asset-card__icon" aria-hidden="true"><?php echo $icon; ?></span>
                                </div>
                            <?php endif; ?>
                            <div class="slw-asset-card__body">
                                <h4 class="slw-asset-card__title"><?php echo esc_html( $asset['title'] ?? '' ); ?></h4>
                                <?php if ( ! empty( $asset['description'] ) ) : ?>
                                    <p class="slw-asset-card__description"><?php echo esc_html( $asset['description'] ); ?></p>
                                <?php endif; ?>
                                <div class="slw-asset-card__actions">
                                    <a href="<?php echo esc_url( $asset['url'] ?? '#' ); ?>" target="_blank" rel="noopener" class="slw-btn slw-btn-small slw-btn-primary">
                                        <?php echo $type === 'video' || $type === 'link' ? 'Open' : 'Download'; ?>
                                    </a>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * RFQ tab. Renders the existing RFQ shortcode.
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
     * Price List tab. Shows live wholesale pricing grouped by category, plus
     * a PDF download for the printable version. Pricing renders live from
     * WooCommerce so this view stays in sync with whatever is set on each
     * product (no stale catalog files).
     */
    private static function render_price_list_tab() {
        $linesheet_url   = '';
        $products_by_cat = array();
        if ( class_exists( 'SLW_PDF_Linesheet' ) ) {
            $linesheet_url = SLW_PDF_Linesheet::get_linesheet_url();
            try {
                $products_by_cat = SLW_PDF_Linesheet::get_products_by_category();
            } catch ( \Throwable $e ) {
                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                    error_log( 'SLW Price List: get_products_by_category failed: ' . $e->getMessage() );
                }
                $products_by_cat = array();
            }
        }

        $total_products   = 0;
        $total_categories = count( $products_by_cat );
        foreach ( $products_by_cat as $items ) {
            $total_products += count( $items );
        }

        $discount_pct = (float) slw_get_option( 'discount_percent', 50 );
        ?>
        <div class="slw-price-list-tab">
            <div class="slw-price-list-header">
                <div class="slw-price-list-header__intro">
                    <h3>Price List</h3>
                    <p>Your live wholesale pricing across all products. Updated in real time as products and pricing change.</p>
                </div>
                <?php if ( $linesheet_url ) :
                    // Auto-trigger the print dialog for regular wholesale customers
                    // so 'Download PDF' is a true one-click action. Admins (who get
                    // the editable quote-mode fields) ignore this flag server-side.
                    $download_url = add_query_arg( 'autoprint', '1', $linesheet_url );
                    ?>
                    <div class="slw-price-list-header__actions">
                        <a href="<?php echo esc_url( $download_url ); ?>" class="slw-btn slw-btn-primary" target="_blank">Download PDF</a>
                    </div>
                <?php endif; ?>
            </div>

            <?php if ( ! empty( $products_by_cat ) ) : ?>
                <div class="slw-price-list-stats">
                    <div class="slw-price-list-stat">
                        <span class="slw-price-list-stat__number"><?php echo esc_html( $total_products ); ?></span>
                        <span class="slw-price-list-stat__label">Products</span>
                    </div>
                    <div class="slw-price-list-stat">
                        <span class="slw-price-list-stat__number"><?php echo esc_html( $total_categories ); ?></span>
                        <span class="slw-price-list-stat__label">Categories</span>
                    </div>
                    <div class="slw-price-list-stat">
                        <span class="slw-price-list-stat__number"><?php echo esc_html( (int) $discount_pct ); ?>%</span>
                        <span class="slw-price-list-stat__label">Off Retail</span>
                    </div>
                    <div class="slw-price-list-stat">
                        <span class="slw-price-list-stat__number" style="font-size:22px;">Live</span>
                        <span class="slw-price-list-stat__label">Always Current</span>
                    </div>
                </div>

                <?php foreach ( $products_by_cat as $category_name => $items ) : ?>
                    <div class="slw-price-list-section">
                        <h4 class="slw-price-list-section__title"><?php echo esc_html( $category_name ); ?> <span class="slw-price-list-section__count"><?php echo count( $items ); ?></span></h4>
                        <div class="slw-price-list-table-wrap">
                            <table class="slw-price-list-table">
                                <thead>
                                    <tr>
                                        <th>Product</th>
                                        <th class="slw-price-list-table__sku">SKU</th>
                                        <th class="slw-price-list-table__qty">Min Qty</th>
                                        <th class="slw-price-list-table__price">Retail</th>
                                        <th class="slw-price-list-table__price">Wholesale</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ( $items as $item ) : ?>
                                        <tr>
                                            <td class="slw-price-list-table__name"><?php echo esc_html( $item['name'] ); ?></td>
                                            <td class="slw-price-list-table__sku"><?php echo $item['sku'] ? esc_html( $item['sku'] ) : '&mdash;'; ?></td>
                                            <td class="slw-price-list-table__qty"><?php echo $item['min_qty'] ? esc_html( $item['min_qty'] ) : '&mdash;'; ?></td>
                                            <td class="slw-price-list-table__price slw-price-list-table__price--retail"><?php echo wp_kses_post( wc_price( $item['retail_price'] ) ); ?></td>
                                            <td class="slw-price-list-table__price slw-price-list-table__price--wholesale"><?php echo wp_kses_post( wc_price( $item['wholesale_price'] ) ); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endforeach; ?>

                <p class="slw-help-text slw-price-list-footnote">Prices reflect your current wholesale tier and update automatically. Confidential. For approved wholesale partners only.</p>
            <?php else : ?>
                <div class="slw-notice slw-notice-info">No products are currently set up for wholesale. Please contact us for pricing information.</div>
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
