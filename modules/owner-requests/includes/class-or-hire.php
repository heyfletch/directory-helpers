<?php
/**
 * "Did you hire?" email to dog owners. Once a day it emails each dog owner whose
 * request went to trainers 14 to 20 days ago and who has not been asked yet. The
 * email links to /gd-request/hired/<token>/, where one tap records the answer.
 * Stays off until the hire_ask setting is on (Joe approves the wording first).
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DH_OR_Hire {

    const HOOK = 'dh_or_hire_ask';

    public function __construct() {
        add_action( self::HOOK, array( __CLASS__, 'run' ) );
        add_action( 'init', array( __CLASS__, 'schedule' ) );
    }

    /** Daily at 10:00 AM Eastern. */
    public static function schedule() {
        if ( ! wp_next_scheduled( self::HOOK ) ) {
            $next = new DateTime( 'today 10:00', new DateTimeZone( 'America/New_York' ) );
            if ( $next->getTimestamp() <= time() ) {
                $next->modify( '+1 day' );
            }
            wp_schedule_event( $next->getTimestamp(), 'daily', self::HOOK );
        }
    }

    public static function run() {
        if ( DH_OR_Settings::get( 'hire_ask' ) ) {
            self::send_due( false );
        }
    }

    /** Requests due the question now. */
    public static function due() {
        global $wpdb;
        $rq  = DH_OR_Schema::table( 'requests' );
        $now = strtotime( DH_OR_Store::now() );
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$rq}
             WHERE status = 'routed' AND notified_count > 0 AND hire_ask_at IS NULL AND owner_email <> ''
               AND created_at <= %s AND created_at >= %s
             ORDER BY id",
            gmdate( 'Y-m-d H:i:s', $now - 14 * DAY_IN_SECONDS ),
            gmdate( 'Y-m-d H:i:s', $now - 20 * DAY_IN_SECONDS )
        ) );
    }

    /** Sends the due emails. With $dry true it only lists them. */
    public static function send_due( $dry ) {
        $out = array();
        foreach ( self::due() as $r ) {
            $trainers = DH_OR_Flow::hire_trainers( $r );
            if ( ! $trainers ) {
                $out[] = array( 'request' => (int) $r->id, 'to' => $r->owner_email, 'result' => 'no trainers, skipped' );
                continue;
            }
            if ( $dry ) {
                $out[] = array( 'request' => (int) $r->id, 'to' => $r->owner_email, 'result' => 'due' );
                continue;
            }
            $raw    = DH_OR_Store::token();
            $city   = DH_OR_Candidates::nearest_city_name( (float) $r->lat, (float) $r->lng );
            $msg    = DH_OR_Messages::owner_hire_ask( array(
                'first_name' => $r->owner_first_name,
                'where'      => $city ? 'near ' . $city : 'near ZIP ' . $r->zip,
                'trainers'   => array_values( $trainers ),
                'answer_url' => DH_OR_Pages::url( 'hired', $raw ),
                'postal'     => DH_OR_Flow::postal(),
            ) );
            DH_OR_Store::update_request( $r->id, array( 'hire_token_hash' => DH_OR_Store::hash_token( $raw ) ) );
            $status = DH_OR_Mailer::send( 'owner', $r->owner_email, $msg, array( 'request_id' => (int) $r->id, 'kind' => 'owner_hire_ask' ) );
            if ( in_array( $status, array( 'sent', 'dry_run' ), true ) ) {
                DH_OR_Store::update_request( $r->id, array( 'hire_ask_at' => DH_OR_Store::now() ) );
            }
            $out[] = array( 'request' => (int) $r->id, 'to' => $r->owner_email, 'result' => $status );
        }
        return $out;
    }
}
