<?php
/**
 * Audit Log
 *
 * Lightweight audit trail stored in wp_options. Records admin actions
 * like application approvals, wholesale status changes, and tier upgrades.
 * Keeps the last 200 entries and does not autoload to stay out of the
 * object cache on every page load.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class SLW_Audit_Log {

    public static function init() {
        // Hook into tier upgrades for automatic logging.
        add_action( 'slw_tier_upgraded', array( __CLASS__, 'log_tier_upgrade' ), 10, 3 );
    }

    /**
     * Record an audit log entry.
     *
     * @param string   $action  Short action identifier (e.g. 'application_approved').
     * @param string   $details Human-readable detail string.
     * @param int|null $user_id Acting user ID. Defaults to current user.
     */
    public static function log( $action, $details = '', $user_id = null ) {
        if ( ! $user_id ) {
            $user_id = get_current_user_id();
        }
        $user_data = get_userdata( $user_id );
        $log = get_option( 'slw_audit_log', array() );
        array_unshift( $log, array(
            'action'    => $action,
            'details'   => $details,
            'user_id'   => $user_id,
            'user_name' => $user_data ? $user_data->display_name : 'System',
            'time'      => current_time( 'mysql' ),
        ) );
        $log = array_slice( $log, 0, 200 ); // Keep last 200 entries.
        update_option( 'slw_audit_log', $log, false ); // No autoload.
    }

    /**
     * Log tier upgrade events fired by SLW_Tiers.
     *
     * @param int    $user_id  User being upgraded.
     * @param string $old_tier Previous tier slug.
     * @param string $new_tier New tier slug.
     */
    public static function log_tier_upgrade( $user_id, $old_tier, $new_tier ) {
        $user = get_userdata( $user_id );
        $name = $user ? $user->display_name : 'User #' . $user_id;
        self::log(
            'tier_upgraded',
            sprintf( 'Tier upgraded to %s for user %s (was %s)', $new_tier, $name, $old_tier )
        );
    }
}
