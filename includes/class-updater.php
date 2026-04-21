<?php
/**
 * Self-Updater — GitHub Release Checker
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

    /** @var string GitHub owner/repo slug */
    private static $github_repo = 'louievillaverde/sego-lily-wholesale';

    /** @var string Transient key for caching the latest release info */
    private static $cache_key = 'slw_github_release';

    /** @var int Cache TTL in seconds (12 hours) */
    private static $cache_ttl = 43200;

    /** @var string Plugin basename as WP sees it */
    private static $plugin_file = 'sego-lily-wholesale/sego-lily-wholesale.php';

    public static function init() {
        add_filter( 'site_transient_update_plugins', array( __CLASS__, 'check_update' ) );
        add_filter( 'plugins_api', array( __CLASS__, 'plugin_info' ), 20, 3 );

        // Clear cached release data when the admin manually checks for updates
        add_action( 'load-update-core.php', array( __CLASS__, 'flush_cache_on_manual_check' ) );
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

        if ( version_compare( SLW_VERSION, $remote['version'], '<' ) ) {
            $transient->response[ self::$plugin_file ] = (object) array(
                'slug'         => 'sego-lily-wholesale',
                'plugin'       => self::$plugin_file,
                'new_version'  => $remote['version'],
                'url'          => 'https://github.com/' . self::$github_repo,
                'package'      => $remote['download_url'],
                'icons'        => array(
                    'svg' => SLW_PLUGIN_URL . 'assets/icon.svg',
                ),
                'banners'      => array(),
                'requires'     => '6.0',
                'tested'       => '6.8',
                'requires_php' => '7.4',
            );
        } else {
            // Tell WP we're current so it doesn't keep re-checking
            $transient->no_update[ self::$plugin_file ] = (object) array(
                'slug'        => 'sego-lily-wholesale',
                'plugin'      => self::$plugin_file,
                'new_version' => SLW_VERSION,
                'url'         => 'https://github.com/' . self::$github_repo,
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

        $changelog_url = 'https://github.com/' . self::$github_repo . '/releases/tag/v' . $remote['version'];

        return (object) array(
            'name'          => 'Wholesale Portal',
            'slug'          => 'sego-lily-wholesale',
            'version'       => $remote['version'],
            'author'        => '<a href="https://leadpiranha.com">Lead Piranha</a>',
            'homepage'      => 'https://github.com/' . self::$github_repo,
            'download_link' => $remote['download_url'],
            'requires'      => '6.0',
            'tested'        => '6.8',
            'requires_php'  => '7.4',
            'sections'      => array(
                'description' => 'Custom wholesale portal for Sego Lily Skincare. Handles wholesale pricing, applications, order minimums, NET 30 terms, tax exemption, tiered pricing, wholesale-only products, category pricing, wholesale-only coupons, shipping restrictions, bulk user import, and AIOS webhook integration.',
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
            'https://api.github.com/repos/' . self::$github_repo . '/releases/latest',
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
}
