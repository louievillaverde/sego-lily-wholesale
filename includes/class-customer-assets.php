<?php
/**
 * Wholesale Customer Assets
 *
 * Onboarding asset library shown on the customer portal's Assets tab.
 * Holly maintains a default set of assets (images, PDFs, videos, links)
 * that every wholesale customer sees, plus optional per-customer overrides
 * for special-case partners (extra co-branded materials, exclusive
 * shelf-talkers, etc.).
 *
 * Storage:
 *  - 'slw_assets_default' option: array of asset records (the global library)
 *  - user_meta 'slw_assets_overrides': per-customer add/remove overrides
 *
 * Asset record shape:
 *   [
 *     'id'          => 'unique-string',
 *     'title'       => 'Brand Logo (PNG)',
 *     'description' => 'High-res transparent logo for retail signage.',
 *     'type'        => 'image' | 'pdf' | 'video' | 'link',
 *     'url'         => 'https://…' (download/link target),
 *     'thumbnail'   => 'https://…' (optional preview thumb),
 *     'created_at'  => 'YYYY-MM-DD HH:MM:SS',
 *   ]
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class SLW_Customer_Assets {

    const OPTION_KEY    = 'slw_assets_default';
    const INTERNAL_KEY  = 'slw_internal_references';
    const META_KEY      = 'slw_assets_overrides';
    const SEED_FLAG     = 'slw_assets_seeded_v1';

    public static function init() {
        add_action( 'admin_post_slw_save_asset',     array( __CLASS__, 'handle_save_asset' ) );
        add_action( 'admin_post_slw_delete_asset',   array( __CLASS__, 'handle_delete_asset' ) );
        add_action( 'admin_post_slw_save_asset_overrides', array( __CLASS__, 'handle_save_overrides' ) );
        add_action( 'admin_post_slw_save_internal_ref',    array( __CLASS__, 'handle_save_internal_ref' ) );
        add_action( 'admin_post_slw_delete_internal_ref',  array( __CLASS__, 'handle_delete_internal_ref' ) );

        // Enqueue WP Media Library picker on the Assets admin page.
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_media_picker' ) );

        // One-shot seed for the initial library so the page isn't empty out of the box.
        add_action( 'admin_init', array( __CLASS__, 'maybe_seed_initial_assets' ) );
    }

    /**
     * Enqueue the WP Media Library JS so the Assets admin form can launch
     * the standard "Choose File" modal alongside a manual URL field.
     */
    public static function enqueue_media_picker( $hook ) {
        // Only on the Assets admin page.
        if ( strpos( (string) $hook, 'slw-assets' ) === false ) {
            return;
        }
        wp_enqueue_media();
    }

    /**
     * Seed the default library + internal references with the known starter
     * set the first time admin loads after this update. Idempotent — gated
     * by SEED_FLAG so admin edits aren't overwritten.
     */
    public static function maybe_seed_initial_assets() {
        if ( get_option( self::SEED_FLAG ) ) {
            return;
        }

        // Customer-facing PDFs (live in the wholesale portal Assets tab).
        if ( ! get_option( self::OPTION_KEY ) ) {
            update_option( self::OPTION_KEY, array(
                array(
                    'id'          => 'seed-manager-pdf',
                    'title'       => 'Manager Reference (PDF)',
                    'description' => 'How to place orders, manage stock, and run your wholesale relationship with us. Print one for yourself.',
                    'type'        => 'pdf',
                    'url'         => 'https://drive.google.com/file/d/1xnskWy7U4FRJPwx70tC2-qrt3RCQsQgP/view',
                    'thumbnail'   => '',
                    'created_at'  => current_time( 'mysql' ),
                ),
                array(
                    'id'          => 'seed-team-pdf',
                    'title'       => 'Staff Reference (PDF)',
                    'description' => 'Print for your floor team — bestsellers, talking points, and the two questions every customer asks.',
                    'type'        => 'pdf',
                    'url'         => 'https://drive.google.com/file/d/1bAyz4aSr_uOGunXO0qAYVVx7htp8Rlm6/view',
                    'thumbnail'   => '',
                    'created_at'  => current_time( 'mysql' ),
                ),
                array(
                    'id'          => 'seed-orderform-pdf',
                    'title'       => 'Wholesale Order Form (PDF)',
                    'description' => 'Printable order form. Most partners order online via the portal, but the PDF is here if you need it.',
                    'type'        => 'pdf',
                    'url'         => 'https://drive.google.com/file/d/1HcOKRGVpD5937EdFRvHpYowp8phZoctf/view',
                    'thumbnail'   => '',
                    'created_at'  => current_time( 'mysql' ),
                ),
            ) );
        }

        // Internal masters (admin-only — for Holly + her team to edit).
        if ( ! get_option( self::INTERNAL_KEY ) ) {
            update_option( self::INTERNAL_KEY, array(
                array(
                    'id'          => 'seed-manager-doc',
                    'title'       => 'Manager Reference (editable)',
                    'description' => 'Master doc for the Manager Reference. Edit here, then export to PDF and replace the customer-facing version.',
                    'type'        => 'link',
                    'url'         => 'https://drive.google.com/file/d/1-DhoGydeejDbGSYrHCK-5vBdPG4uZhSA/view',
                    'created_at'  => current_time( 'mysql' ),
                ),
                array(
                    'id'          => 'seed-team-doc',
                    'title'       => 'Staff Reference (editable)',
                    'description' => 'Master doc for the Staff Reference. Edit here, then export to PDF.',
                    'type'        => 'link',
                    'url'         => 'https://drive.google.com/file/d/1l2ChlytBJIzt8BA3Cw-50oO7b9yXyLgk/view',
                    'created_at'  => current_time( 'mysql' ),
                ),
                array(
                    'id'          => 'seed-orderform-doc',
                    'title'       => 'Wholesale Order Form (editable)',
                    'description' => 'Master doc for the Wholesale Order Form. Edit here, then export to PDF.',
                    'type'        => 'link',
                    'url'         => 'https://docs.google.com/document/d/18XDBMdKqczS1XNm6tJqtrLIHKBQaHxvyzZgQaqEHIkY/edit',
                    'created_at'  => current_time( 'mysql' ),
                ),
            ) );
        }

        update_option( self::SEED_FLAG, 1 );
    }

    /**
     * Get the internal references library (admin-only docs).
     */
    public static function get_internal_references() {
        $stored = get_option( self::INTERNAL_KEY, array() );
        return is_array( $stored ) ? $stored : array();
    }

    /**
     * Save (create or update) an internal reference.
     */
    public static function handle_save_internal_ref() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( 'Unauthorized', 403 );
        }
        check_admin_referer( 'slw_save_internal_ref' );

        $id          = sanitize_key( $_POST['ref_id'] ?? '' );
        $title       = sanitize_text_field( wp_unslash( $_POST['ref_title'] ?? '' ) );
        $description = sanitize_textarea_field( wp_unslash( $_POST['ref_description'] ?? '' ) );
        $url         = esc_url_raw( wp_unslash( $_POST['ref_url'] ?? '' ) );

        if ( $title === '' || $url === '' ) {
            wp_safe_redirect( add_query_arg( 'slw_assets_error', 'missing', admin_url( 'admin.php?page=slw-assets#internal' ) ) );
            exit;
        }

        $refs = self::get_internal_references();
        $found = false;
        if ( $id !== '' ) {
            foreach ( $refs as $i => $ref ) {
                if ( ( $ref['id'] ?? '' ) === $id ) {
                    $refs[ $i ] = array_merge( $ref, array(
                        'title'       => $title,
                        'description' => $description,
                        'url'         => $url,
                    ) );
                    $found = true;
                    break;
                }
            }
        }
        if ( ! $found ) {
            $refs[] = array(
                'id'          => $id !== '' ? $id : 'r' . wp_generate_password( 10, false, false ),
                'title'       => $title,
                'description' => $description,
                'type'        => 'link',
                'url'         => $url,
                'created_at'  => current_time( 'mysql' ),
            );
        }
        update_option( self::INTERNAL_KEY, $refs );

        wp_safe_redirect( add_query_arg( 'slw_assets_saved', '1', admin_url( 'admin.php?page=slw-assets' ) ) . '#internal' );
        exit;
    }

    /**
     * Delete an internal reference.
     */
    public static function handle_delete_internal_ref() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( 'Unauthorized', 403 );
        }
        check_admin_referer( 'slw_delete_internal_ref' );

        $id = sanitize_key( $_POST['ref_id'] ?? '' );
        if ( $id === '' ) {
            wp_safe_redirect( admin_url( 'admin.php?page=slw-assets' ) );
            exit;
        }

        $refs = self::get_internal_references();
        $refs = array_values( array_filter( $refs, function ( $r ) use ( $id ) {
            return ( $r['id'] ?? '' ) !== $id;
        } ) );
        update_option( self::INTERNAL_KEY, $refs );

        wp_safe_redirect( add_query_arg( 'slw_assets_saved', '1', admin_url( 'admin.php?page=slw-assets' ) ) . '#internal' );
        exit;
    }

    /**
     * Get the default asset library.
     *
     * @return array Asset records.
     */
    public static function get_default_assets() {
        $stored = get_option( self::OPTION_KEY, array() );
        return is_array( $stored ) ? $stored : array();
    }

    /**
     * Get the per-customer override block for a user.
     * Shape: [ 'add' => [ asset_record, … ], 'remove' => [ asset_id, … ] ].
     */
    public static function get_user_overrides( $user_id ) {
        $stored = get_user_meta( $user_id, self::META_KEY, true );
        if ( ! is_array( $stored ) ) {
            return array( 'add' => array(), 'remove' => array() );
        }
        return wp_parse_args( $stored, array( 'add' => array(), 'remove' => array() ) );
    }

    /**
     * Resolve the effective asset list for a wholesale customer:
     * default library minus their removed IDs, plus their custom additions.
     *
     * @return array Asset records visible to this user.
     */
    public static function get_assets_for_user( $user_id ) {
        $defaults  = self::get_default_assets();
        $overrides = self::get_user_overrides( $user_id );
        $remove    = array_map( 'strval', (array) ( $overrides['remove'] ?? array() ) );
        $add       = is_array( $overrides['add'] ?? null ) ? $overrides['add'] : array();

        $effective = array();
        foreach ( $defaults as $asset ) {
            if ( ! in_array( (string) ( $asset['id'] ?? '' ), $remove, true ) ) {
                $effective[] = $asset;
            }
        }
        foreach ( $add as $asset ) {
            $effective[] = $asset;
        }
        return $effective;
    }

    /**
     * Save (create or update) an asset in the default library.
     */
    public static function handle_save_asset() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( 'Unauthorized', 403 );
        }
        check_admin_referer( 'slw_save_asset' );

        $id          = sanitize_key( $_POST['asset_id'] ?? '' );
        $title       = sanitize_text_field( wp_unslash( $_POST['asset_title'] ?? '' ) );
        $description = sanitize_textarea_field( wp_unslash( $_POST['asset_description'] ?? '' ) );
        $type        = sanitize_key( $_POST['asset_type'] ?? 'link' );
        $url         = esc_url_raw( wp_unslash( $_POST['asset_url'] ?? '' ) );
        $thumbnail   = esc_url_raw( wp_unslash( $_POST['asset_thumbnail'] ?? '' ) );

        if ( ! in_array( $type, array( 'image', 'pdf', 'video', 'link' ), true ) ) {
            $type = 'link';
        }
        if ( $title === '' || $url === '' ) {
            wp_safe_redirect( add_query_arg( 'slw_assets_error', 'missing', admin_url( 'admin.php?page=slw-assets' ) ) );
            exit;
        }

        $assets = self::get_default_assets();

        // If editing an existing record, replace by id; otherwise append.
        $found = false;
        if ( $id !== '' ) {
            foreach ( $assets as $i => $asset ) {
                if ( ( $asset['id'] ?? '' ) === $id ) {
                    $assets[ $i ] = array_merge( $asset, array(
                        'title'       => $title,
                        'description' => $description,
                        'type'        => $type,
                        'url'         => $url,
                        'thumbnail'   => $thumbnail,
                    ) );
                    $found = true;
                    break;
                }
            }
        }
        if ( ! $found ) {
            $assets[] = array(
                'id'          => $id !== '' ? $id : 'a' . wp_generate_password( 10, false, false ),
                'title'       => $title,
                'description' => $description,
                'type'        => $type,
                'url'         => $url,
                'thumbnail'   => $thumbnail,
                'created_at'  => current_time( 'mysql' ),
            );
        }
        update_option( self::OPTION_KEY, $assets );

        wp_safe_redirect( add_query_arg( 'slw_assets_saved', '1', admin_url( 'admin.php?page=slw-assets' ) ) );
        exit;
    }

    /**
     * Delete an asset from the default library by id.
     */
    public static function handle_delete_asset() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( 'Unauthorized', 403 );
        }
        check_admin_referer( 'slw_delete_asset' );

        $id = sanitize_key( $_POST['asset_id'] ?? '' );
        if ( $id === '' ) {
            wp_safe_redirect( admin_url( 'admin.php?page=slw-assets' ) );
            exit;
        }

        $assets = self::get_default_assets();
        $assets = array_values( array_filter( $assets, function ( $a ) use ( $id ) {
            return ( $a['id'] ?? '' ) !== $id;
        } ) );
        update_option( self::OPTION_KEY, $assets );

        wp_safe_redirect( add_query_arg( 'slw_assets_saved', '1', admin_url( 'admin.php?page=slw-assets' ) ) );
        exit;
    }

    /**
     * Save per-customer overrides from the user-edit assets UI.
     */
    public static function handle_save_overrides() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( 'Unauthorized', 403 );
        }
        check_admin_referer( 'slw_save_asset_overrides' );

        $user_id = absint( $_POST['user_id'] ?? 0 );
        if ( ! $user_id ) {
            wp_die( 'Invalid user.', 400 );
        }

        $remove = array();
        if ( ! empty( $_POST['remove'] ) && is_array( $_POST['remove'] ) ) {
            foreach ( $_POST['remove'] as $rid ) {
                $rid = sanitize_key( $rid );
                if ( $rid !== '' ) {
                    $remove[] = $rid;
                }
            }
        }

        $add = array();
        if ( ! empty( $_POST['add_title'] ) && is_array( $_POST['add_title'] ) ) {
            foreach ( $_POST['add_title'] as $i => $title ) {
                $title = sanitize_text_field( wp_unslash( $title ) );
                $url   = esc_url_raw( wp_unslash( $_POST['add_url'][ $i ] ?? '' ) );
                $type  = sanitize_key( $_POST['add_type'][ $i ] ?? 'link' );
                $desc  = sanitize_textarea_field( wp_unslash( $_POST['add_description'][ $i ] ?? '' ) );
                if ( ! in_array( $type, array( 'image', 'pdf', 'video', 'link' ), true ) ) {
                    $type = 'link';
                }
                if ( $title === '' || $url === '' ) continue;
                $add[] = array(
                    'id'          => 'u' . wp_generate_password( 10, false, false ),
                    'title'       => $title,
                    'description' => $desc,
                    'type'        => $type,
                    'url'         => $url,
                    'thumbnail'   => '',
                    'created_at'  => current_time( 'mysql' ),
                );
            }
        }

        update_user_meta( $user_id, self::META_KEY, array(
            'remove' => $remove,
            'add'    => $add,
        ) );

        wp_safe_redirect( add_query_arg( 'slw_assets_saved', '1', admin_url( 'admin.php?page=slw-assets&user=' . $user_id ) ) );
        exit;
    }

    // ── Admin UI ──────────────────────────────────────────────────────────

    /**
     * Render the admin Assets management page. Default library + (when
     * ?user=N is present) the per-customer overrides editor.
     */
    public static function render_admin_page() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }

        $user_id = absint( $_GET['user'] ?? 0 );
        if ( $user_id ) {
            self::render_user_overrides_page( $user_id );
            return;
        }

        $assets = self::get_default_assets();
        $just_saved = ! empty( $_GET['slw_assets_saved'] );
        $error = sanitize_key( $_GET['slw_assets_error'] ?? '' );
        $editing = sanitize_key( $_GET['edit'] ?? '' );
        $editing_record = null;
        if ( $editing !== '' ) {
            foreach ( $assets as $a ) {
                if ( ( $a['id'] ?? '' ) === $editing ) {
                    $editing_record = $a;
                    break;
                }
            }
        }
        ?>
        <div class="wrap">
            <h1>Wholesale Customer Assets</h1>
            <p>Files, links, and videos shown to wholesale customers on the Assets tab of their portal. Every customer sees this default library; you can add or remove assets per customer below.</p>

            <?php if ( $just_saved ) : ?>
                <div class="notice notice-success is-dismissible"><p>Saved.</p></div>
            <?php endif; ?>
            <?php if ( $error === 'missing' ) : ?>
                <div class="notice notice-error is-dismissible"><p>Title and URL are required.</p></div>
            <?php endif; ?>

            <h2><?php echo $editing_record ? 'Edit Asset' : 'Add Asset'; ?></h2>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="background:#fff;border:1px solid #e0ddd8;border-radius:6px;padding:16px 20px;max-width:760px;">
                <?php wp_nonce_field( 'slw_save_asset' ); ?>
                <input type="hidden" name="action" value="slw_save_asset" />
                <input type="hidden" name="asset_id" value="<?php echo esc_attr( $editing_record['id'] ?? '' ); ?>" />

                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="asset_title">Title</label></th>
                        <td><input type="text" id="asset_title" name="asset_title" value="<?php echo esc_attr( $editing_record['title'] ?? '' ); ?>" class="regular-text" required /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="asset_type">Type</label></th>
                        <td>
                            <select id="asset_type" name="asset_type">
                                <?php foreach ( array( 'image' => 'Image', 'pdf' => 'PDF', 'video' => 'Video link', 'link' => 'Other link' ) as $val => $label ) : ?>
                                    <option value="<?php echo esc_attr( $val ); ?>" <?php selected( ( $editing_record['type'] ?? 'link' ), $val ); ?>><?php echo esc_html( $label ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="asset_url">URL</label></th>
                        <td>
                            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                                <input type="url" id="asset_url" name="asset_url" value="<?php echo esc_attr( $editing_record['url'] ?? '' ); ?>" class="regular-text" required placeholder="https://…" style="flex:1;min-width:280px;" />
                                <button type="button" class="button" id="slw-asset-pick-url">Choose from Media Library</button>
                            </div>
                            <p class="description">Pick a file you've uploaded to WordPress, or paste any URL — Drive share links, YouTube, etc.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="asset_thumbnail">Thumbnail URL <span style="color:#888;font-weight:normal;">(optional)</span></label></th>
                        <td>
                            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                                <input type="url" id="asset_thumbnail" name="asset_thumbnail" value="<?php echo esc_attr( $editing_record['thumbnail'] ?? '' ); ?>" class="regular-text" placeholder="https://…" style="flex:1;min-width:280px;" />
                                <button type="button" class="button" id="slw-asset-pick-thumb">Choose from Media Library</button>
                            </div>
                            <p class="description">Optional preview image. Picker filters to images only. If left blank, customers see a generic icon based on the type.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="asset_description">Description <span style="color:#888;font-weight:normal;">(optional)</span></label></th>
                        <td><textarea id="asset_description" name="asset_description" rows="2" class="large-text"><?php echo esc_textarea( $editing_record['description'] ?? '' ); ?></textarea></td>
                    </tr>
                </table>

                <?php submit_button( $editing_record ? 'Update Asset' : 'Add Asset' ); ?>
                <?php if ( $editing_record ) : ?>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=slw-assets' ) ); ?>" class="button">Cancel</a>
                <?php endif; ?>
            </form>

            <h2 style="margin-top:32px;">Default Library (<?php echo count( $assets ); ?>)</h2>
            <?php if ( empty( $assets ) ) : ?>
                <p style="color:#628393;font-style:italic;">No assets yet. Add one above to get started.</p>
            <?php else : ?>
                <table class="widefat striped" style="max-width:1000px;">
                    <thead>
                        <tr>
                            <th style="width:80px;">Type</th>
                            <th>Title</th>
                            <th>URL</th>
                            <th style="width:160px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $assets as $asset ) : ?>
                            <tr>
                                <td><?php echo esc_html( ucfirst( $asset['type'] ?? 'link' ) ); ?></td>
                                <td>
                                    <strong><?php echo esc_html( $asset['title'] ?? '' ); ?></strong>
                                    <?php if ( ! empty( $asset['description'] ) ) : ?>
                                        <br><span style="color:#628393;font-size:13px;"><?php echo esc_html( $asset['description'] ); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td style="word-break:break-all;font-size:12px;">
                                    <a href="<?php echo esc_url( $asset['url'] ?? '#' ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $asset['url'] ?? '' ); ?></a>
                                </td>
                                <td style="white-space:nowrap;">
                                    <a href="<?php echo esc_url( add_query_arg( 'edit', $asset['id'] ?? '', admin_url( 'admin.php?page=slw-assets' ) ) ); ?>" class="button button-small">Edit</a>
                                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;" onsubmit="return confirm('Delete this asset from the default library? Customers using only the default library will lose access to it.');">
                                        <?php wp_nonce_field( 'slw_delete_asset' ); ?>
                                        <input type="hidden" name="action" value="slw_delete_asset" />
                                        <input type="hidden" name="asset_id" value="<?php echo esc_attr( $asset['id'] ?? '' ); ?>" />
                                        <button type="submit" class="button button-small" style="color:#c62828;">Delete</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <h2 style="margin-top:32px;">Per-Customer Overrides</h2>
            <p>Add or remove assets for a specific wholesale customer (special-case partners with extra co-branded materials, etc.).</p>
            <p>
                <select id="slw-asset-user-picker" style="min-width:280px;">
                    <option value="">— Pick a wholesale customer —</option>
                    <?php
                    $users = get_users( array( 'role' => 'wholesale_customer', 'orderby' => 'display_name' ) );
                    foreach ( $users as $u ) {
                        $business = get_user_meta( $u->ID, 'slw_business_name', true );
                        $label = $u->display_name . ( $business ? ' · ' . $business : '' );
                        echo '<option value="' . esc_attr( $u->ID ) . '">' . esc_html( $label ) . '</option>';
                    }
                    ?>
                </select>
                <button type="button" class="button button-primary" id="slw-asset-user-go">Edit Overrides &rarr;</button>
            </p>

            <hr style="margin:32px 0;" />
            <h2 id="internal">Internal References</h2>
            <p>Editable master docs and internal links — visible only to you and your team in this admin area, plus on your dashboard's Resources card. Wholesale customers don't see these.</p>

            <?php $internal_refs = self::get_internal_references(); $editing_ref = sanitize_key( $_GET['edit_ref'] ?? '' ); $editing_ref_record = null;
            if ( $editing_ref !== '' ) {
                foreach ( $internal_refs as $r ) { if ( ( $r['id'] ?? '' ) === $editing_ref ) { $editing_ref_record = $r; break; } }
            } ?>

            <h3><?php echo $editing_ref_record ? 'Edit Reference' : 'Add Reference'; ?></h3>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="background:#fff;border:1px solid #e0ddd8;border-radius:6px;padding:16px 20px;max-width:760px;">
                <?php wp_nonce_field( 'slw_save_internal_ref' ); ?>
                <input type="hidden" name="action" value="slw_save_internal_ref" />
                <input type="hidden" name="ref_id" value="<?php echo esc_attr( $editing_ref_record['id'] ?? '' ); ?>" />
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="ref_title">Title</label></th>
                        <td><input type="text" id="ref_title" name="ref_title" value="<?php echo esc_attr( $editing_ref_record['title'] ?? '' ); ?>" class="regular-text" required /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ref_url">URL</label></th>
                        <td><input type="url" id="ref_url" name="ref_url" value="<?php echo esc_attr( $editing_ref_record['url'] ?? '' ); ?>" class="regular-text" required placeholder="https://…" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ref_description">Description <span style="color:#888;font-weight:normal;">(optional)</span></label></th>
                        <td><textarea id="ref_description" name="ref_description" rows="2" class="large-text"><?php echo esc_textarea( $editing_ref_record['description'] ?? '' ); ?></textarea></td>
                    </tr>
                </table>
                <?php submit_button( $editing_ref_record ? 'Update Reference' : 'Add Reference' ); ?>
                <?php if ( $editing_ref_record ) : ?>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=slw-assets#internal' ) ); ?>" class="button">Cancel</a>
                <?php endif; ?>
            </form>

            <h3 style="margin-top:24px;">Current References (<?php echo count( $internal_refs ); ?>)</h3>
            <?php if ( empty( $internal_refs ) ) : ?>
                <p style="color:#628393;font-style:italic;">No internal references yet.</p>
            <?php else : ?>
                <table class="widefat striped" style="max-width:1000px;">
                    <thead><tr><th>Title</th><th>URL</th><th style="width:160px;">Actions</th></tr></thead>
                    <tbody>
                        <?php foreach ( $internal_refs as $ref ) : ?>
                            <tr>
                                <td>
                                    <strong><?php echo esc_html( $ref['title'] ?? '' ); ?></strong>
                                    <?php if ( ! empty( $ref['description'] ) ) : ?>
                                        <br><span style="color:#628393;font-size:13px;"><?php echo esc_html( $ref['description'] ); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td style="word-break:break-all;font-size:12px;">
                                    <a href="<?php echo esc_url( $ref['url'] ?? '#' ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $ref['url'] ?? '' ); ?></a>
                                </td>
                                <td style="white-space:nowrap;">
                                    <a href="<?php echo esc_url( add_query_arg( 'edit_ref', $ref['id'] ?? '', admin_url( 'admin.php?page=slw-assets' ) ) . '#internal' ); ?>" class="button button-small">Edit</a>
                                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;" onsubmit="return confirm('Delete this internal reference?');">
                                        <?php wp_nonce_field( 'slw_delete_internal_ref' ); ?>
                                        <input type="hidden" name="action" value="slw_delete_internal_ref" />
                                        <input type="hidden" name="ref_id" value="<?php echo esc_attr( $ref['id'] ?? '' ); ?>" />
                                        <button type="submit" class="button button-small" style="color:#c62828;">Delete</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <script>
            (function() {
                var picker = document.getElementById('slw-asset-user-picker');
                var btn    = document.getElementById('slw-asset-user-go');
                if (picker && btn) {
                    btn.addEventListener('click', function() {
                        var uid = picker.value;
                        if (!uid) return;
                        window.location.href = '<?php echo esc_js( admin_url( 'admin.php?page=slw-assets&user=' ) ); ?>' + encodeURIComponent(uid);
                    });
                }

                // Media Library picker — wires the two "Choose from Media Library" buttons
                // to the WP media modal. Selected attachment URL drops into the field.
                function bindMediaPicker(btnId, fieldId, mediaType, modalTitle) {
                    var btn = document.getElementById(btnId);
                    var field = document.getElementById(fieldId);
                    if (!btn || !field || typeof wp === 'undefined' || !wp.media) return;
                    var frame = null;
                    btn.addEventListener('click', function(e) {
                        e.preventDefault();
                        if (frame) { frame.open(); return; }
                        var args = { title: modalTitle, button: { text: 'Use this file' }, multiple: false };
                        if (mediaType) { args.library = { type: mediaType }; }
                        frame = wp.media(args);
                        frame.on('select', function() {
                            var att = frame.state().get('selection').first().toJSON();
                            field.value = att.url;
                        });
                        frame.open();
                    });
                }
                bindMediaPicker('slw-asset-pick-url',   'asset_url',       null,    'Choose an asset file');
                bindMediaPicker('slw-asset-pick-thumb', 'asset_thumbnail', 'image', 'Choose a thumbnail image');
            })();
            </script>
        </div>
        <?php
    }

    /**
     * Render the per-customer overrides editor.
     */
    private static function render_user_overrides_page( $user_id ) {
        $user = get_userdata( $user_id );
        if ( ! $user ) {
            echo '<div class="wrap"><p>User not found.</p></div>';
            return;
        }

        $defaults  = self::get_default_assets();
        $overrides = self::get_user_overrides( $user_id );
        $remove    = array_map( 'strval', (array) $overrides['remove'] );
        $add       = is_array( $overrides['add'] ) ? $overrides['add'] : array();

        $just_saved = ! empty( $_GET['slw_assets_saved'] );
        ?>
        <div class="wrap">
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=slw-assets' ) ); ?>" class="button" style="margin-bottom:12px;">&larr; Back to Asset Library</a>
            <h1>Asset Overrides — <?php echo esc_html( $user->display_name ); ?></h1>
            <p style="color:#628393;">Tick a default asset to <em>hide</em> it from this customer, or add custom assets only this customer will see.</p>

            <?php if ( $just_saved ) : ?>
                <div class="notice notice-success is-dismissible"><p>Overrides saved.</p></div>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'slw_save_asset_overrides' ); ?>
                <input type="hidden" name="action" value="slw_save_asset_overrides" />
                <input type="hidden" name="user_id" value="<?php echo esc_attr( $user_id ); ?>" />

                <h2>Hide from default library</h2>
                <?php if ( empty( $defaults ) ) : ?>
                    <p style="color:#628393;font-style:italic;">No default assets to hide.</p>
                <?php else : ?>
                    <table class="widefat striped" style="max-width:760px;">
                        <thead><tr><th style="width:60px;">Hide</th><th>Asset</th><th style="width:80px;">Type</th></tr></thead>
                        <tbody>
                            <?php foreach ( $defaults as $asset ) :
                                $aid = $asset['id'] ?? '';
                                $checked = in_array( (string) $aid, $remove, true );
                            ?>
                                <tr>
                                    <td><input type="checkbox" name="remove[]" value="<?php echo esc_attr( $aid ); ?>" <?php checked( $checked ); ?> /></td>
                                    <td><strong><?php echo esc_html( $asset['title'] ?? '' ); ?></strong></td>
                                    <td><?php echo esc_html( ucfirst( $asset['type'] ?? 'link' ) ); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>

                <h2 style="margin-top:32px;">Custom assets for this customer</h2>
                <table class="widefat striped" style="max-width:980px;" id="slw-custom-assets-table">
                    <thead>
                        <tr>
                            <th style="width:200px;">Title</th>
                            <th style="width:90px;">Type</th>
                            <th>URL</th>
                            <th>Description</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        // Pad to at least 3 visible rows so it's easy to add more.
                        $rows = max( 3, count( $add ) + 1 );
                        for ( $i = 0; $i < $rows; $i++ ) :
                            $r = $add[ $i ] ?? array();
                        ?>
                            <tr>
                                <td><input type="text" name="add_title[]" value="<?php echo esc_attr( $r['title'] ?? '' ); ?>" style="width:100%;" /></td>
                                <td>
                                    <select name="add_type[]">
                                        <?php foreach ( array( 'link' => 'Link', 'image' => 'Image', 'pdf' => 'PDF', 'video' => 'Video' ) as $val => $label ) : ?>
                                            <option value="<?php echo esc_attr( $val ); ?>" <?php selected( ( $r['type'] ?? 'link' ), $val ); ?>><?php echo esc_html( $label ); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td><input type="url" name="add_url[]" value="<?php echo esc_attr( $r['url'] ?? '' ); ?>" style="width:100%;" placeholder="https://…" /></td>
                                <td><input type="text" name="add_description[]" value="<?php echo esc_attr( $r['description'] ?? '' ); ?>" style="width:100%;" /></td>
                            </tr>
                        <?php endfor; ?>
                    </tbody>
                </table>

                <?php submit_button( 'Save Overrides' ); ?>
            </form>
        </div>
        <?php
    }
}
