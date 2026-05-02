<?php
/**
 * Self-Updater: GitHub Release Checker
 *
 * Hooks into the native WordPress plugin update system so Sego Lily
 * Wholesale appears in Dashboard > Updates alongside every other plugin.
 * Checks the GitHub releases API once every 12 hours, caches the result,
 * and injects the update into WP's update_plugins transient if a newer
 * version is available. No third-party plugin needed.
 *
 * This replaces the need for Git Updater, Deployer for Git, or any other
 * external auto-update solution. Holly sees "Update Now" on the same
 * page she updates WooCommerce and Elementor from.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class SLW_Updater {

    /** @var string Default GitHub owner/repo slug. Admin can override via the setting on Wholesale > Settings. */
    private static $github_repo_default = 'louievillaverde/sego-lily-wholesale';

    /**
     * Resolve the GitHub repo slug. Reads the admin-configured option and
     * falls back to the canonical Lead Piranha repo if unset or malformed.
     */
    private static function get_github_repo() {
        $configured = trim( (string) get_option( 'slw_github_repo', '' ) );
        if ( $configured !== '' && strpos( $configured, '/' ) !== false ) {
            return $configured;
        }
        return self::$github_repo_default;
    }

    /** @var string Transient key for caching the latest release info */
    private static $cache_key = 'slw_github_release';

    /** @var int Cache TTL in seconds (12 hours) */
    private static $cache_ttl = 43200;

    /** @var string Plugin basename as WP sees it */
    private static $plugin_file = 'sego-lily-wholesale/sego-lily-wholesale.php';

    public static function init() {
        add_filter( 'site_transient_update_plugins', array( __CLASS__, 'check_update' ) );
        add_filter( 'plugins_api', array( __CLASS__, 'plugin_info' ), 20, 3 );

        // No CSS injection needed. Icon shows via transient on the updates page.

        // Clear cached release data when the admin manually checks for updates
        add_action( 'load-update-core.php', array( __CLASS__, 'flush_cache_on_manual_check' ) );

        // One-time flush: clear stale icon data from pre-3.0.1 transients
        if ( get_option( 'slw_icon_cache_version' ) !== '3.1.2' ) {
            delete_transient( self::$cache_key );
            delete_site_transient( 'update_plugins' );
            delete_transient( 'slw_mautic_campaigns' );
            delete_transient( 'slw_mautic_email_stats' );
            update_option( 'slw_icon_cache_version', '3.1.2' );
        }

        // Flush stale icon/transient data after plugin updates so the new
        // icon renders immediately instead of showing the old cached one.
        add_action( 'upgrader_process_complete', array( __CLASS__, 'flush_after_update' ), 10, 2 );
    }

    /**
     * After any plugin update completes, flush our cache so the next
     * transient check picks up the current icon + version data.
     */
    public static function flush_after_update( $upgrader, $options ) {
        if ( $options['action'] !== 'update' || $options['type'] !== 'plugin' ) {
            return;
        }
        $our_plugin = 'sego-lily-wholesale/sego-lily-wholesale.php';
        $plugins = $options['plugins'] ?? array();
        if ( in_array( $our_plugin, $plugins, true ) ) {
            delete_transient( self::$cache_key );
            delete_site_transient( 'update_plugins' );
        }
    }

    /**
     * Inject our plugin into the WP update_plugins transient when a newer
     * GitHub release exists. This is the hook that makes the plugin show
     * up on Dashboard > Updates.
     */
    public static function check_update( $transient ) {
        if ( empty( $transient->checked ) ) {
            return $transient;
        }

        $remote = self::get_remote_version();
        if ( ! $remote ) {
            return $transient;
        }

        $icons = self::get_icon_urls();

        if ( version_compare( SLW_VERSION, $remote['version'], '<' ) ) {
            $transient->response[ self::$plugin_file ] = (object) array(
                'slug'         => 'sego-lily-wholesale',
                'plugin'       => self::$plugin_file,
                'new_version'  => $remote['version'],
                'url'          => 'https://leadpiranha.com',
                'package'      => $remote['download_url'],
                'icons'        => $icons,
                'banners'      => array(),
                'requires'     => '6.0',
                'tested'       => get_bloginfo( 'version' ),
                'requires_php' => '7.4',
            );
        } else {
            // Tell WP we're current. Include icons so the Plugins page shows them.
            $transient->no_update[ self::$plugin_file ] = (object) array(
                'slug'        => 'sego-lily-wholesale',
                'plugin'      => self::$plugin_file,
                'new_version' => SLW_VERSION,
                'url'         => 'https://leadpiranha.com',
                'icons'       => $icons,
            );
        }

        return $transient;
    }

    /**
     * Provide plugin details for the "View details" popup in the WordPress
     * Plugins list and Updates screen.
     */
    public static function plugin_info( $result, $action, $args ) {
        if ( $action !== 'plugin_information' ) {
            return $result;
        }
        if ( ( $args->slug ?? '' ) !== 'sego-lily-wholesale' ) {
            return $result;
        }

        $remote = self::get_remote_version();
        if ( ! $remote ) {
            return $result;
        }

        $changelog_url = 'https://github.com/' . self::get_github_repo() . '/releases/tag/v' . $remote['version'];

        return (object) array(
            'name'          => 'Wholesale Portal',
            'slug'          => 'sego-lily-wholesale',
            'version'       => $remote['version'],
            'author'        => '<a href="https://leadpiranha.com">Lead Piranha</a>',
            'homepage'      => 'https://leadpiranha.com',
            'download_link' => $remote['download_url'],
            'icons'         => self::get_icon_urls(),
            'requires'      => '6.0',
            'tested'        => get_bloginfo( 'version' ),
            'requires_php'  => '7.4',
            'sections'      => array(
                'description' => 'All-in-one B2B wholesale portal for WooCommerce. Customer portal, tiered pricing, application workflow, PDF invoices, email sequences, NET payment terms, lead capture, trade show tools, and automated reminders.',
                'changelog'   => ! empty( $remote['changelog'] )
                    ? nl2br( esc_html( $remote['changelog'] ) )
                    : 'See the <a href="' . esc_url( $changelog_url ) . '">release notes on GitHub</a>.',
            ),
        );
    }

    /**
     * When the admin clicks "Check Again" on Dashboard > Updates, flush our
     * cached release so the next transient filter hits the GitHub API fresh.
     */
    public static function flush_cache_on_manual_check() {
        delete_transient( self::$cache_key );
    }

    /**
     * Fetch and cache the latest GitHub release. Returns an associative
     * array with 'version', 'download_url', and 'changelog' keys, or null
     * on failure.
     */
    private static function get_remote_version() {
        $cached = get_transient( self::$cache_key );
        if ( is_array( $cached ) ) {
            return $cached;
        }

        $response = wp_remote_get(
            'https://api.github.com/repos/' . self::get_github_repo() . '/releases/latest',
            array(
                'timeout' => 10,
                'headers' => array(
                    'Accept'     => 'application/vnd.github.v3+json',
                    'User-Agent' => 'Sego-Lily-Wholesale/' . SLW_VERSION,
                ),
            )
        );

        if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
            // Cache failure for 1 hour so we don't hammer the API
            set_transient( self::$cache_key, 'error', HOUR_IN_SECONDS );
            return null;
        }

        $release = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( empty( $release['tag_name'] ) ) {
            set_transient( self::$cache_key, 'error', HOUR_IN_SECONDS );
            return null;
        }

        // "v1.3.2" → "1.3.2"
        $version = ltrim( $release['tag_name'], 'v' );

        // Find the .zip release asset
        $download_url = '';
        foreach ( $release['assets'] ?? array() as $asset ) {
            if ( substr( $asset['name'], -4 ) === '.zip' ) {
                $download_url = $asset['browser_download_url'];
                break;
            }
        }

        if ( ! $download_url ) {
            set_transient( self::$cache_key, 'error', HOUR_IN_SECONDS );
            return null;
        }

        $data = array(
            'version'      => $version,
            'download_url' => $download_url,
            'changelog'    => $release['body'] ?? '',
        );

        set_transient( self::$cache_key, $data, self::$cache_ttl );
        return $data;
    }

    /**
     * Return icon URLs for the updates screen.
     * Simple format: just the SVG URL. This is the exact pattern that
     * worked when the lily icon was showing on the updates page.
     */
    private static function get_icon_urls() {
        return array(
            'svg' => SLW_PLUGIN_URL . 'assets/icon.svg',
        );
    }

    /**
     * Inject CSS on the plugins list and updates pages that adds our icon
     * next to the plugin name. WordPress only auto-renders icons for
     * wordpress.org plugins. Self-hosted plugins need this manual approach.
     */
    public static function inject_plugin_icon_css() {
        $icon_url = SLW_PLUGIN_URL . 'assets/icon.svg';
        $slug     = 'sego-lily-wholesale';
        ?>
        <style>
        /* Plugin icon on plugins.php list */
        tr[data-slug="<?php echo esc_attr( $slug ); ?>"] .plugin-title strong,
        tr[data-plugin="<?php echo esc_attr( self::$plugin_file ); ?>"] .plugin-title strong {
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        tr[data-slug="<?php echo esc_attr( $slug ); ?>"] .plugin-title strong::before,
        tr[data-plugin="<?php echo esc_attr( self::$plugin_file ); ?>"] .plugin-title strong::before {
            content: '';
            display: inline-block;
            width: 24px;
            height: 24px;
            min-width: 24px;
            background: url('<?php echo esc_url( $icon_url ); ?>') no-repeat center center;
            background-size: contain;
            vertical-align: middle;
        }
        /* Plugin icon on update-core.php */
        .plugins .plugin-title p:has(strong:contains("Wholesale Portal"))::before,
        #update-plugins-table tr td p strong {
            /* fallback handled by the transient icons */
        }
        </style>
        <?php
    }
}
