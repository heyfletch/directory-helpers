<?php
/**
 * Owner Requests: settings.
 *
 * One option holds every switch. The two that matter before launch:
 *  - preview_only (on): the box renders, the form accepts submissions and the
 *    token pages answer only for logged-in users with manage_options.
 *  - dry_run (on): every outbound email is written to the events table as the
 *    message it would have been, and nothing is sent.
 *
 * Lanes: only lane A (trainers who gave their own email) exists in this build.
 * Lanes B and C have no sender code, so no setting can switch them on.
 *
 * @package Directory_Helpers
 * @subpackage Owner_Requests
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DH_OR_Settings {

    const OPTION = 'dh_or_settings';

    /** Lanes that have sending code in this build. */
    const LANES_BUILT = array( 'A' );

    public static function defaults() {
        return array(
            'preview_only'       => 1,
            'dry_run'            => 1,
            'kill_switch'        => 0,
            'paused_lanes'       => array(),
            'active_lanes'       => array( 'A' ),
            'auto_inject'        => 1,
            'form_id'            => 0,
            // Caps Joe approved (wave one questions 3 and 4).
            'max_trainers'       => 5,
            'trainer_weekly_cap' => 3,
            'owner_email_day'    => 1,
            'owner_email_week'   => 3,
            'ip_day'             => 3,
            'retention_months'   => 12,
            // Plan values: "start at 15 miles, widen to 25, then 50" and "at least three".
            'radii'              => array( 15, 25, 50 ),
            'min_trainers'       => 3,
            // Not approved by Joe: 0 means no per-ZIP cap.
            'zip_day'            => 0,
            // Input limits chosen for the build, listed in the report for Joe.
            'text_min'           => 15,
            'text_max'           => 1000,
            // Needed before any real send (CAN-SPAM); Joe supplies it.
            'postal_address'     => '',
            'from_name'          => 'Goody Doggy',
            'from_email'         => 'no-reply@goodydoggy.com',
        );
    }

    public static function all() {
        $saved = get_option( self::OPTION, array() );
        return array_merge( self::defaults(), is_array( $saved ) ? $saved : array() );
    }

    public static function get( $key ) {
        $all = self::all();
        return isset( $all[ $key ] ) ? $all[ $key ] : null;
    }

    public static function update( array $values ) {
        $clean = array_intersect_key( $values, self::defaults() );
        update_option( self::OPTION, array_merge( self::all(), $clean ), false );
    }

    /** True when real visitors may use the box, form and token pages. */
    public static function public_open() {
        return ! self::get( 'preview_only' );
    }

    /** May the current visitor see the box and use the form right now? */
    public static function visitor_allowed() {
        return self::public_open() || current_user_can( 'manage_options' );
    }

    public static function dry_run() {
        return (bool) self::get( 'dry_run' );
    }

    public static function lane_active( $lane ) {
        if ( self::get( 'kill_switch' ) ) {
            return false;
        }
        if ( ! in_array( $lane, self::LANES_BUILT, true ) ) {
            return false;
        }
        if ( in_array( strtoupper( $lane ), array_map( 'strtoupper', (array) self::get( 'paused_lanes' ) ), true ) ) {
            return false;
        }
        return in_array( $lane, (array) self::get( 'active_lanes' ), true );
    }

    /** @return string[] Lanes that may send right now. */
    public static function active_lanes() {
        $out = array();
        foreach ( self::LANES_BUILT as $lane ) {
            if ( self::lane_active( $lane ) ) {
                $out[] = $lane;
            }
        }
        return $out;
    }

    public static function paused() {
        return empty( self::active_lanes() );
    }
}
