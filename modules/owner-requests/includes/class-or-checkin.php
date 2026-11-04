<?php
/**
 * Trainer check-in (Joe approved Nov 4). Once a day it emails each trainer whose
 * request went out 7 to 14 days ago, who has not tapped an outcome, and who has
 * not had a check-in for that request, while the request is still open and the
 * trainer has not unsubscribed. The email links to the same request page through
 * a second token, so the first email's link keeps working. Joe's test requests
 * (route status "test") check in to his test inbox.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DH_OR_Checkin {

    const HOOK = 'dh_or_checkin';

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
        self::send_due( false );
    }

    /** Routes due a check-in now. */
    public static function due() {
        global $wpdb;
        $rt  = DH_OR_Schema::table( 'request_routes' );
        $rq  = DH_OR_Schema::table( 'requests' );
        $now = strtotime( DH_OR_Store::now() );
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT r.*, q.lat, q.lng, q.zip, q.owner_email FROM {$rt} r JOIN {$rq} q ON q.id = r.request_id
             WHERE r.status IN ('sent','test') AND r.outcome = '' AND r.checkin_at IS NULL
               AND r.notified_at <= %s AND r.notified_at >= %s AND q.status = 'routed'
             ORDER BY r.id",
            gmdate( 'Y-m-d H:i:s', $now - 7 * DAY_IN_SECONDS ),
            gmdate( 'Y-m-d H:i:s', $now - 14 * DAY_IN_SECONDS )
        ) );
    }

    /**
     * Sends the due check-ins. With $dry true it only lists them.
     * Returns one row per due route: route id, trainer, where it went, and what happened.
     */
    public static function send_due( $dry ) {
        $rows       = self::due();
        $suppressed = $rows ? DH_OR_Store::suppressed_ids( array_map( 'intval', wp_list_pluck( $rows, 'profile_id' ) ) ) : array();
        $out        = array();
        foreach ( $rows as $r ) {
            $name = DH_OR_Candidates::trainer_name( $r->profile_id );
            if ( in_array( (int) $r->profile_id, array_map( 'intval', (array) $suppressed ), true ) ) {
                $out[] = array( 'route' => (int) $r->id, 'trainer' => $name, 'to' => '', 'result' => 'unsubscribed, skipped' );
                continue;
            }
            $to = 'test' === $r->status ? (string) DH_OR_Settings::get( 'test_inbox' ) : DH_OR_Candidates::trainer_email( $r->profile_id );
            if ( '' === $to ) {
                $out[] = array( 'route' => (int) $r->id, 'trainer' => $name, 'to' => '', 'result' => 'no email, skipped' );
                continue;
            }
            if ( $dry ) {
                $out[] = array( 'route' => (int) $r->id, 'trainer' => $name, 'to' => $to, 'result' => 'due' );
                continue;
            }
            $raw   = DH_OR_Store::token();
            $city  = DH_OR_Candidates::nearest_city_name( (float) $r->lat, (float) $r->lng );
            $msg   = DH_OR_Messages::trainer_checkin( array(
                'trainer_name' => $name,
                'where'        => $city ? 'near ' . $city : 'near ZIP ' . $r->zip,
                'open_url'     => DH_OR_Pages::url( 'trainer', $raw ),
                'optout_url'   => DH_OR_Pages::url( 'optout', $raw ),
                'postal'       => DH_OR_Flow::postal(),
            ) );
            DH_OR_Store::update_route( $r->id, array( 'checkin_token_hash' => DH_OR_Store::hash_token( $raw ) ) );
            $status = DH_OR_Mailer::send( 'trainer', $to, $msg, array(
                'request_id'      => (int) $r->request_id,
                'route_id'        => (int) $r->id,
                'profile_id'      => (int) $r->profile_id,
                'kind'            => 'trainer_checkin',
                'unsubscribe_url' => DH_OR_Pages::url( 'optout', $raw ),
            ) );
            if ( in_array( $status, array( 'sent', 'dry_run' ), true ) ) {
                DH_OR_Store::update_route( $r->id, array( 'checkin_at' => DH_OR_Store::now() ) );
                DH_OR_Store::add_event( $r->request_id, $r->id, 'checkin_' . $status, array( 'test' => 'test' === $r->status ) );
            }
            $out[] = array( 'route' => (int) $r->id, 'trainer' => $name, 'to' => $to, 'result' => $status );
        }
        return $out;
    }
}
