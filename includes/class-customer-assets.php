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

    const OPTION_KEY = 'slw_assets_default';
    const META_KEY   = 'slw_assets_overrides';

    public static function init() {
        add_action( 'admin_post_slw_save_asset',     array( __CLASS__, 'handle_save_asset' ) );
        add_action( 'admin_post_slw_delete_asset',   array( __CLASS__, 'handle_delete_asset' ) );
        add_action( 'admin_post_slw_save_asset_overrides', array( __CLASS__, 'handle_save_overrides' ) );
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
                            <input type="url" id="asset_url" name="asset_url" value="<?php echo esc_attr( $editing_record['url'] ?? '' ); ?>" class="regular-text" required placeholder="https://…" />
                            <p class="description">For files: upload via Media Library, then paste the file URL here. Videos: paste the YouTube/Vimeo link.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="asset_thumbnail">Thumbnail URL <span style="color:#888;font-weight:normal;">(optional)</span></label></th>
                        <td>
                            <input type="url" id="asset_thumbnail" name="asset_thumbnail" value="<?php echo esc_attr( $editing_record['thumbnail'] ?? '' ); ?>" class="regular-text" placeholder="https://…" />
                            <p class="description">Optional preview image. If left blank we'll use a generic icon based on the type.</p>
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
            <script>
            (function() {
                var picker = document.getElementById('slw-asset-user-picker');
                var btn    = document.getElementById('slw-asset-user-go');
                if (!picker || !btn) return;
                btn.addEventListener('click', function() {
                    var uid = picker.value;
                    if (!uid) return;
                    window.location.href = '<?php echo esc_js( admin_url( 'admin.php?page=slw-assets&user=' ) ); ?>' + encodeURIComponent(uid);
                });
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
