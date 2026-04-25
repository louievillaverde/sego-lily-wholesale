<?php
/**
 * Premium Features: category pricing, wholesale coupons, shipping
 * restrictions, and bulk user import.
 *
 * Kept in a dedicated class so the architecture stays tidy as we add more
 * wholesale tiers (Bronze/Silver/Gold) later. Each feature is independent
 * and hook-based so enabling/disabling does not affect the others.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class SLW_Premium_Features {

    public static function init() {
        // 1. Category-level pricing override
        add_action( 'product_cat_edit_form_fields', array( __CLASS__, 'category_pricing_field_edit' ), 10, 2 );
        add_action( 'product_cat_add_form_fields', array( __CLASS__, 'category_pricing_field_add' ) );
        add_action( 'edited_product_cat', array( __CLASS__, 'save_category_pricing' ) );
        add_action( 'created_product_cat', array( __CLASS__, 'save_category_pricing' ) );
        // Hook into the main price filter so category overrides apply automatically
        add_filter( 'slw_resolve_wholesale_price', array( __CLASS__, 'resolve_category_price' ), 10, 3 );

        // 2. Wholesale-only coupons
        add_action( 'woocommerce_coupon_options', array( __CLASS__, 'coupon_wholesale_only_field' ) );
        add_action( 'woocommerce_coupon_options_save', array( __CLASS__, 'save_coupon_wholesale_only' ) );
        add_filter( 'woocommerce_coupon_is_valid', array( __CLASS__, 'validate_wholesale_only_coupon' ), 10, 2 );
        add_filter( 'woocommerce_get_shop_coupon_data', array( __CLASS__, 'hide_retail_coupon_hints' ), 10, 2 );

        // 3. Shipping method restrictions per role
        add_filter( 'woocommerce_package_rates', array( __CLASS__, 'filter_shipping_methods' ), 100, 2 );
        add_action( 'woocommerce_shipping_zone_method_added', array( __CLASS__, 'clear_shipping_cache' ) );
        // Admin UI for shipping restrictions lives under plugin settings

        // 4. Bulk user import (admin menu registered centrally by SLW_Admin_Menu)
        add_action( 'admin_post_slw_bulk_import', array( __CLASS__, 'handle_csv_upload' ) );
        add_action( 'admin_post_slw_download_import_template', array( __CLASS__, 'download_csv_template' ) );
        add_action( 'admin_post_slw_single_customer', array( __CLASS__, 'handle_single_customer' ) );
    }

    // ── 1. Category-level pricing ─────────────────────────────────────────

    /**
     * Render the wholesale discount override field on the Edit Category page.
     */
    public static function category_pricing_field_edit( $term, $taxonomy ) {
        $discount = get_term_meta( $term->term_id, 'slw_category_discount', true );
        ?>
        <tr class="form-field">
            <th scope="row"><label for="slw_category_discount">Wholesale Discount Override (%)</label></th>
            <td>
                <input type="number" name="slw_category_discount" id="slw_category_discount" value="<?php echo esc_attr( $discount ); ?>" step="0.01" min="0" max="100" />
                <p class="description">Override the default wholesale discount for products in this category. Leave blank to use the global discount. Per-product override still wins if set.</p>
            </td>
        </tr>
        <?php
    }

    /**
     * Same field on the Add New Category page (different HTML structure).
     */
    public static function category_pricing_field_add() {
        ?>
        <div class="form-field">
            <label for="slw_category_discount">Wholesale Discount Override (%)</label>
            <input type="number" name="slw_category_discount" id="slw_category_discount" value="" step="0.01" min="0" max="100" />
            <p>Override the default wholesale discount for products in this category. Leave blank to use the global discount.</p>
        </div>
        <?php
    }

    public static function save_category_pricing( $term_id ) {
        if ( isset( $_POST['slw_category_discount'] ) ) {
            $val = wc_clean( $_POST['slw_category_discount'] );
            update_term_meta( $term_id, 'slw_category_discount', $val );
        }
    }

    /**
     * Resolve a price using category-level override. Called via the
     * slw_resolve_wholesale_price filter from the main pricing hook.
     * Priority order: per-product override (handled elsewhere) -> category
     * override (here) -> global discount (handled elsewhere).
     *
     * $price is the retail (regular) price. Returns the wholesale price or
     * the original $price if no category override applies.
     */
    public static function resolve_category_price( $resolved, $retail_price, $product ) {
        if ( $resolved !== null ) return $resolved;  // per-product already resolved
        if ( ! $product ) return null;

        $term_ids = wc_get_product_term_ids( $product->get_id(), 'product_cat' );
        if ( empty( $term_ids ) ) return null;

        // Use the LOWEST discount across all categories the product is in
        // (so if a product is in both "Core Line" at 50% and "Gift Sets"
        // at 40%, wholesale users get 50%)
        $best_discount = null;
        foreach ( $term_ids as $term_id ) {
            $discount = get_term_meta( $term_id, 'slw_category_discount', true );
            if ( $discount !== '' && is_numeric( $discount ) ) {
                $discount = (float) $discount;
                if ( $best_discount === null || $discount > $best_discount ) {
                    $best_discount = $discount;
                }
            }
        }

        if ( $best_discount === null ) return null;

        $multiplier = 1 - ( $best_discount / 100 );
        return round( (float) $retail_price * $multiplier, 2 );
    }

    // ── 2. Wholesale-only coupons ─────────────────────────────────────────

    /**
     * Add a "Wholesale only" checkbox to the WooCommerce coupon edit page.
     */
    public static function coupon_wholesale_only_field() {
        woocommerce_wp_checkbox( array(
            'id'          => 'slw_wholesale_only_coupon',
            'label'       => 'Wholesale only',
            'description' => 'Only wholesale customers can use this coupon. Retail customers see an invalid-coupon error when they try to apply it.',
        ));
    }

    public static function save_coupon_wholesale_only( $post_id ) {
        $value = isset( $_POST['slw_wholesale_only_coupon'] ) ? 'yes' : 'no';
        update_post_meta( $post_id, 'slw_wholesale_only_coupon', $value );
    }

    /**
     * Reject the coupon at checkout if the user is not wholesale and the
     * coupon is marked wholesale-only.
     */
    public static function validate_wholesale_only_coupon( $valid, $coupon ) {
        if ( ! $valid ) return $valid;
        $is_wholesale_only = get_post_meta( $coupon->get_id(), 'slw_wholesale_only_coupon', true ) === 'yes';
        if ( ! $is_wholesale_only ) return $valid;
        if ( ! slw_is_wholesale_context() ) {
            throw new Exception( 'This coupon is for wholesale partners only.' );
        }
        return $valid;
    }

    /**
     * Hide wholesale-only coupons from the retail customer coupon hints
     * (WooCommerce sometimes exposes coupon metadata on the cart page).
     */
    public static function hide_retail_coupon_hints( $data, $coupon_code ) {
        if ( slw_is_wholesale_context() ) return $data;
        $coupon = new WC_Coupon( $coupon_code );
        if ( get_post_meta( $coupon->get_id(), 'slw_wholesale_only_coupon', true ) === 'yes' ) {
            return false;  // pretend coupon doesn't exist for retail users
        }
        return $data;
    }

    // ── 3. Shipping method restrictions ───────────────────────────────────

    /**
     * Hide shipping methods from certain user roles based on settings.
     * Admin configures which methods are hidden from retail vs wholesale
     * in the plugin settings page. Useful when wholesale orders ship via
     * freight and retail orders ship via standard mail.
     */
    public static function filter_shipping_methods( $rates, $package ) {
        if ( is_admin() && ! defined( 'DOING_AJAX' ) ) return $rates;
        $is_wholesale = slw_is_wholesale_context();

        // Always disable free shipping for wholesale orders
        if ( $is_wholesale ) {
            foreach ( $rates as $rate_id => $rate ) {
                if ( strpos( $rate_id, 'free_shipping' ) === 0 ) {
                    unset( $rates[ $rate_id ] );
                }
            }
        }

        // Allowed methods per role, stored as arrays of method IDs in options
        $wholesale_allowed = (array) get_option( 'slw_wholesale_shipping_methods', array() );
        $retail_allowed    = (array) get_option( 'slw_retail_shipping_methods', array() );

        // If no restrictions configured for this role, return all rates
        $allowed = $is_wholesale ? $wholesale_allowed : $retail_allowed;
        if ( empty( $allowed ) ) return $rates;

        foreach ( $rates as $rate_id => $rate ) {
            // $rate_id looks like "flat_rate:3" or "free_shipping:1"; the
            // method ID is the part before the colon
            $method_id = strstr( $rate_id, ':', true ) ?: $rate_id;
            if ( ! in_array( $method_id, $allowed, true ) && ! in_array( $rate_id, $allowed, true ) ) {
                unset( $rates[ $rate_id ] );
            }
        }

        return $rates;
    }

    public static function clear_shipping_cache() {
        // Forces WC to recalc shipping rates on the next request
        WC_Cache_Helper::get_transient_version( 'shipping', true );
    }

    // ── 4. Bulk user import ───────────────────────────────────────────────

    /**
     * Add the "Import Users" submenu under Wholesale Applications.
     */
    public static function add_import_menu() {
        add_submenu_page(
            'slw-applications',
            'Import Wholesale Users',
            'Import Users',
            'manage_woocommerce',
            'slw-import',
            array( __CLASS__, 'render_import_page' )
        );
    }

    public static function render_import_page() {
        $last_result = get_transient( 'slw_last_import_result' );
        delete_transient( 'slw_last_import_result' );
        $single_result = get_transient( 'slw_single_customer_result' );
        delete_transient( 'slw_single_customer_result' );
        ?>
        <div class="wrap">
            <h1>Import Wholesale Users</h1>
            <p>Create individual wholesale accounts or import in bulk via CSV.</p>

            <?php if ( $last_result ) : ?>
                <div class="notice notice-<?php echo esc_attr( $last_result['type'] ); ?> is-dismissible">
                    <p><?php echo esc_html( $last_result['message'] ); ?></p>
                    <?php if ( ! empty( $last_result['errors'] ) ) : ?>
                        <ul><?php foreach ( $last_result['errors'] as $err ) echo '<li>' . esc_html( $err ) . '</li>'; ?></ul>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if ( $single_result ) : ?>
                <div class="notice notice-<?php echo esc_attr( $single_result['type'] ); ?> is-dismissible">
                    <p><?php echo esc_html( $single_result['message'] ); ?></p>
                </div>
            <?php endif; ?>

            <!-- Add Single Customer -->
            <div class="slw-import-section">
                <h2 class="slw-import-section__heading"><span class="dashicons dashicons-admin-users"></span> Add Single Customer</h2>
                <p class="slw-import-section__desc">Quickly create a wholesale account for an individual customer.</p>

                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <?php wp_nonce_field( 'slw_single_customer' ); ?>
                    <input type="hidden" name="action" value="slw_single_customer" />

                    <div class="slw-single-customer-form">
                        <div class="slw-form-field">
                            <label for="slw_sc_first_name">First Name <span class="slw-required">*</span></label>
                            <input type="text" id="slw_sc_first_name" name="first_name" required />
                        </div>
                        <div class="slw-form-field">
                            <label for="slw_sc_last_name">Last Name <span class="slw-required">*</span></label>
                            <input type="text" id="slw_sc_last_name" name="last_name" required />
                        </div>
                        <div class="slw-form-field slw-form-field--full">
                            <label for="slw_sc_email">Email <span class="slw-required">*</span></label>
                            <input type="email" id="slw_sc_email" name="email" required />
                        </div>
                        <div class="slw-form-field">
                            <label for="slw_sc_business">Business Name</label>
                            <input type="text" id="slw_sc_business" name="business_name" />
                        </div>
                        <div class="slw-form-field">
                            <label for="slw_sc_phone">Phone</label>
                            <input type="tel" id="slw_sc_phone" name="phone" />
                        </div>
                        <div class="slw-form-actions">
                            <button type="submit" class="button button-primary">Create Wholesale Account</button>
                            <label style="font-size:13px;color:#628393;">
                                <input type="checkbox" name="send_welcome" value="1" checked /> Send welcome email
                            </label>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Bulk Import via CSV -->
            <div class="slw-import-section">
                <h2 class="slw-import-section__heading"><span class="dashicons dashicons-upload"></span> Bulk Import via CSV</h2>
                <p class="slw-import-section__desc">Upload a CSV to create multiple wholesale accounts at once. Existing users matched by email are promoted to wholesale instead of duplicated.</p>

                <!-- Required Columns -->
                <h3 style="font-size:14px;font-weight:700;color:#1E2A30;margin:0 0 10px;">Required Columns</h3>
                <table class="slw-columns-table">
                    <thead>
                        <tr>
                            <th>Column</th>
                            <th>Description</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><code>email</code></td>
                            <td>Customer email address (used for login and matching existing users)</td>
                            <td><span class="slw-badge slw-badge--required">Required</span></td>
                        </tr>
                        <tr>
                            <td><code>first_name</code></td>
                            <td>Customer first name</td>
                            <td><span class="slw-badge slw-badge--required">Required</span></td>
                        </tr>
                        <tr>
                            <td><code>last_name</code></td>
                            <td>Customer last name</td>
                            <td><span class="slw-badge slw-badge--required">Required</span></td>
                        </tr>
                        <tr>
                            <td><code>business_name</code></td>
                            <td>Wholesale business or store name</td>
                            <td><span class="slw-badge slw-badge--required">Required</span></td>
                        </tr>
                    </tbody>
                </table>

                <!-- Optional Columns (collapsible) -->
                <div class="slw-collapsible" id="slw-optional-columns">
                    <button type="button" class="slw-collapsible__toggle" onclick="this.parentElement.classList.toggle('slw-collapsible--open')">
                        <span class="dashicons dashicons-arrow-down-alt2 slw-collapsible__arrow"></span>
                        Optional Columns (7 fields)
                    </button>
                    <div class="slw-collapsible__body">
                        <table class="slw-columns-table">
                            <thead>
                                <tr>
                                    <th>Column</th>
                                    <th>Description</th>
                                    <th>Default</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td><code>phone</code></td>
                                    <td>Business phone number</td>
                                    <td>-</td>
                                </tr>
                                <tr>
                                    <td><code>address</code></td>
                                    <td>Business address</td>
                                    <td>-</td>
                                </tr>
                                <tr>
                                    <td><code>ein</code></td>
                                    <td>Employer Identification Number / Tax ID</td>
                                    <td>-</td>
                                </tr>
                                <tr>
                                    <td><code>business_type</code></td>
                                    <td>Type of business (e.g. boutique, salon, spa)</td>
                                    <td>-</td>
                                </tr>
                                <tr>
                                    <td><code>net30_approved</code></td>
                                    <td>Approve NET 30 payment terms (yes/no)</td>
                                    <td>no</td>
                                </tr>
                                <tr>
                                    <td><code>tax_exempt</code></td>
                                    <td>Mark customer as tax exempt (yes/no)</td>
                                    <td>no</td>
                                </tr>
                                <tr>
                                    <td><code>send_welcome_email</code></td>
                                    <td>Send login credentials via email (yes/no)</td>
                                    <td>yes</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div style="margin:20px 0 24px;">
                    <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=slw_download_import_template' ), 'slw_csv_template' ) ); ?>" class="button">
                        <span class="dashicons dashicons-download" style="margin-top:3px;"></span> Download CSV Template
                    </a>
                </div>

                <form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <?php wp_nonce_field( 'slw_bulk_import' ); ?>
                    <input type="hidden" name="action" value="slw_bulk_import" />
                    <table class="form-table">
                        <tr>
                            <th><label for="slw_csv_file">CSV File</label></th>
                            <td><input type="file" name="csv_file" id="slw_csv_file" accept=".csv" required /></td>
                        </tr>
                        <tr>
                            <th><label for="slw_send_welcome_all">Force welcome email</label></th>
                            <td>
                                <label><input type="checkbox" name="send_welcome_all" id="slw_send_welcome_all" value="1" /> Send to all imported users regardless of CSV column</label>
                            </td>
                        </tr>
                    </table>
                    <p class="submit"><button type="submit" class="button button-primary">Import CSV</button></p>
                </form>
            </div>
        </div>
        <?php
    }

    /**
     * Handle the single customer creation form.
     */
    public static function handle_single_customer() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized', 403 );
        check_admin_referer( 'slw_single_customer' );

        $email      = sanitize_email( $_POST['email'] ?? '' );
        $first_name = sanitize_text_field( $_POST['first_name'] ?? '' );
        $last_name  = sanitize_text_field( $_POST['last_name'] ?? '' );
        $business   = sanitize_text_field( $_POST['business_name'] ?? '' );
        $phone      = sanitize_text_field( $_POST['phone'] ?? '' );
        $send_welcome = ! empty( $_POST['send_welcome'] );

        if ( ! $email || ! is_email( $email ) ) {
            set_transient( 'slw_single_customer_result', array(
                'type' => 'error', 'message' => 'Please provide a valid email address.',
            ), 60 );
            wp_redirect( admin_url( 'admin.php?page=slw-import' ) );
            exit;
        }

        if ( ! $first_name || ! $last_name ) {
            set_transient( 'slw_single_customer_result', array(
                'type' => 'error', 'message' => 'First name and last name are required.',
            ), 60 );
            wp_redirect( admin_url( 'admin.php?page=slw-import' ) );
            exit;
        }

        $existing = get_user_by( 'email', $email );
        if ( $existing ) {
            if ( ! slw_is_wholesale_user( $existing->ID ) ) {
                $existing->add_role( 'wholesale_customer' );
                if ( $business ) update_user_meta( $existing->ID, 'slw_business_name', $business );
                if ( $phone )    update_user_meta( $existing->ID, 'billing_phone', $phone );
                set_transient( 'slw_single_customer_result', array(
                    'type' => 'success', 'message' => 'Existing user ' . $email . ' has been promoted to wholesale customer.',
                ), 60 );
            } else {
                set_transient( 'slw_single_customer_result', array(
                    'type' => 'warning', 'message' => $email . ' is already a wholesale customer.',
                ), 60 );
            }
            wp_redirect( admin_url( 'admin.php?page=slw-import' ) );
            exit;
        }

        $username = self::generate_username( $email, $business );
        $password = wp_generate_password( 14, true );
        $user_id  = wp_insert_user( array(
            'user_login' => $username,
            'user_email' => $email,
            'user_pass'  => $password,
            'first_name' => $first_name,
            'last_name'  => $last_name,
            'role'       => 'wholesale_customer',
        ) );

        if ( is_wp_error( $user_id ) ) {
            set_transient( 'slw_single_customer_result', array(
                'type' => 'error', 'message' => 'Could not create user: ' . $user_id->get_error_message(),
            ), 60 );
            wp_redirect( admin_url( 'admin.php?page=slw-import' ) );
            exit;
        }

        if ( $business ) update_user_meta( $user_id, 'slw_business_name', $business );
        if ( $phone )    update_user_meta( $user_id, 'billing_phone', $phone );

        if ( $send_welcome ) {
            $user = get_userdata( $user_id );
            self::send_bulk_welcome_email( $user, $password, $business );
        }

        set_transient( 'slw_single_customer_result', array(
            'type' => 'success', 'message' => 'Wholesale account created for ' . $first_name . ' ' . $last_name . ' (' . $email . ').' . ( $send_welcome ? ' Welcome email sent.' : '' ),
        ), 60 );
        wp_redirect( admin_url( 'admin.php?page=slw-import' ) );
        exit;
    }

    public static function handle_csv_upload() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized', 403 );
        check_admin_referer( 'slw_bulk_import' );

        if ( empty( $_FILES['csv_file']['tmp_name'] ) ) {
            set_transient( 'slw_last_import_result', array(
                'type' => 'error', 'message' => 'No file uploaded.'
            ), 60 );
            wp_redirect( admin_url( 'admin.php?page=slw-import' ) );
            exit;
        }

        $rows = self::parse_csv( $_FILES['csv_file']['tmp_name'] );
        if ( empty( $rows ) ) {
            set_transient( 'slw_last_import_result', array(
                'type' => 'error', 'message' => 'CSV appears empty or unreadable.'
            ), 60 );
            wp_redirect( admin_url( 'admin.php?page=slw-import' ) );
            exit;
        }

        $force_welcome = ! empty( $_POST['send_welcome_all'] );
        $created = 0;
        $promoted = 0;
        $errors = array();

        foreach ( $rows as $index => $row ) {
            $email = sanitize_email( $row['email'] ?? '' );
            if ( ! $email || ! is_email( $email ) ) {
                $errors[] = 'Row ' . ( $index + 2 ) . ': missing or invalid email (skipped)';
                continue;
            }

            $first_name    = sanitize_text_field( $row['first_name'] ?? '' );
            $last_name     = sanitize_text_field( $row['last_name'] ?? '' );
            $business_name = sanitize_text_field( $row['business_name'] ?? '' );
            $phone         = sanitize_text_field( $row['phone'] ?? '' );
            $net30         = strtolower( trim( $row['net30_approved'] ?? 'no' ) ) === 'yes';
            $tax_exempt    = strtolower( trim( $row['tax_exempt'] ?? 'no' ) ) === 'yes';
            $send_welcome  = $force_welcome || strtolower( trim( $row['send_welcome_email'] ?? 'yes' ) ) === 'yes';

            $existing = get_user_by( 'email', $email );
            if ( $existing ) {
                // Promote existing user to wholesale
                $user = $existing;
                if ( ! slw_is_wholesale_user( $user->ID ) ) {
                    $user->add_role( 'wholesale_customer' );
                    $promoted++;
                } else {
                    $errors[] = 'Row ' . ( $index + 2 ) . ': ' . $email . ' already wholesale (no change)';
                }
            } else {
                // Create new user
                $username = self::generate_username( $email, $business_name );
                $password = wp_generate_password( 14, true );
                $user_id = wp_insert_user( array(
                    'user_login' => $username,
                    'user_email' => $email,
                    'user_pass'  => $password,
                    'first_name' => $first_name,
                    'last_name'  => $last_name,
                    'role'       => 'wholesale_customer',
                ));
                if ( is_wp_error( $user_id ) ) {
                    $errors[] = 'Row ' . ( $index + 2 ) . ': ' . $user_id->get_error_message();
                    continue;
                }
                $user = get_userdata( $user_id );
                $created++;

                if ( $send_welcome ) {
                    self::send_bulk_welcome_email( $user, $password, $business_name );
                }
            }

            if ( $business_name ) update_user_meta( $user->ID, 'slw_business_name', $business_name );
            if ( $phone )         update_user_meta( $user->ID, 'billing_phone', $phone );
            update_user_meta( $user->ID, 'slw_net30_approved', $net30 ? '1' : '0' );
            update_user_meta( $user->ID, 'slw_resale_cert_verified', $tax_exempt ? '1' : '0' );
        }

        set_transient( 'slw_last_import_result', array(
            'type'    => $created || $promoted ? 'success' : 'warning',
            'message' => "Import done. Created: {$created} new users. Promoted: {$promoted} existing users. Errors: " . count( $errors ) . '.',
            'errors'  => $errors,
        ), 300 );
        wp_redirect( admin_url( 'admin.php?page=slw-import' ) );
        exit;
    }

    public static function download_csv_template() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized', 403 );
        check_admin_referer( 'slw_csv_template' );

        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename=wholesale-users-template.csv' );
        $out = fopen( 'php://output', 'w' );
        fputcsv( $out, array( 'email', 'first_name', 'last_name', 'business_name', 'phone', 'address', 'ein', 'business_type', 'net30_approved', 'tax_exempt', 'send_welcome_email' ) );
        fputcsv( $out, array( 'shop@example.com', 'Alex', 'Smith', 'Example Boutique', '555-123-4567', '123 Main St, Austin TX', '12-3456789', 'boutique', 'no', 'yes', 'yes' ) );
        fclose( $out );
        exit;
    }

    private static function parse_csv( $file_path ) {
        $rows = array();
        if ( ( $handle = fopen( $file_path, 'r' ) ) !== false ) {
            $header = fgetcsv( $handle );
            if ( ! $header ) { fclose( $handle ); return $rows; }
            $header = array_map( 'strtolower', array_map( 'trim', $header ) );
            while ( ( $data = fgetcsv( $handle ) ) !== false ) {
                if ( count( $data ) < 1 ) continue;
                $row = array();
                foreach ( $header as $i => $key ) {
                    $row[ $key ] = $data[ $i ] ?? '';
                }
                $rows[] = $row;
            }
            fclose( $handle );
        }
        return $rows;
    }

    private static function generate_username( $email, $business_name ) {
        $base = $business_name ? sanitize_user( strtolower( preg_replace( '/\s+/', '', $business_name ) ), true ) : '';
        if ( ! $base ) {
            $base = sanitize_user( strstr( $email, '@', true ), true );
        }
        $username = $base;
        $suffix = 1;
        while ( username_exists( $username ) ) {
            $username = $base . $suffix;
            $suffix++;
        }
        return $username;
    }

    private static function send_bulk_welcome_email( $user, $password, $business_name ) {
        $login_url     = wp_login_url( home_url( '/wholesale-dashboard' ) );
        $first_name    = $user->first_name ?: 'there';
        $brand_name    = SLW_Email_Settings::get_business_name();
        $reply_email   = SLW_Email_Settings::get( 'reply_to' );
        $subject       = 'Welcome to ' . $brand_name . ' Wholesale!';

        $body  = "Hi {$first_name},\n\n";
        $body .= "You have been granted wholesale partner access to " . $brand_name . ".\n\n";
        $body .= "Your login details:\n";
        $body .= "Username: {$user->user_login}\n";
        $body .= "Password: {$password}\n";
        $body .= "Login: {$login_url}\n\n";
        $body .= "You can place orders at " . home_url( '/wholesale-order' ) . " once logged in.\n\n";
        $body .= "Questions? Reply to this email or reach out at {$reply_email}.\n\n";
        $body .= "Welcome,\n" . SLW_Email_Settings::get_signature();

        wp_mail( $user->user_email, $subject, $body, SLW_Email_Settings::get_headers() );
    }
}
