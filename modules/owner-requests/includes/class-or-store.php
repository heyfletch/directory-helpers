<?php
/**
 * Owner Requests: reads and writes on the module's tables.
 *
 * @package Directory_Helpers
 * @subpackage Owner_Requests
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DH_OR_Store {

    /** PII columns blanked when a request is deleted or expires. */
    const PII_COLUMNS = array(
        'owner_first_name' => '',
        'owner_email'      => '',
        'owner_phone'      => '',
        'problem_text'     => '',
        'ip_hash'          => '',
        'consent_text'     => '',
        'owner_token_hash' => '',
    );

    public static function now() {
        return current_time( 'mysql' );
    }

    public static function token() {
        return bin2hex( random_bytes( 16 ) );
    }

    public static function hash_token( $raw ) {
        return hash( 'sha256', (string) $raw );
    }

    public static function valid_token( $raw ) {
        return (bool) preg_match( '/^[a-f0-9]{32}$/', (string) $raw );
    }

    public static function ip_hash( $ip ) {
        return $ip ? hash_hmac( 'sha256', $ip, wp_salt( 'auth' ) ) : '';
    }

    /* ---------- requests ---------- */

    public static function insert_request( array $row ) {
        global $wpdb;
        $now = self::now();
        $row = array_merge( array(
            'created_at'    => $now,
            'updated_at'    => $now,
            'last_event_at' => $now,
        ), $row );
        $wpdb->insert( DH_OR_Schema::table( 'requests' ), $row );
        return (int) $wpdb->insert_id;
    }

    public static function update_request( $id, array $row ) {
        global $wpdb;
        $row['updated_at']    = self::now();
        $row['last_event_at'] = self::now();
        return $wpdb->update( DH_OR_Schema::table( 'requests' ), $row, array( 'id' => (int) $id ) );
    }

    public static function get_request( $id ) {
        global $wpdb;
        $t = DH_OR_Schema::table( 'requests' );
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", (int) $id ) );
    }

    public static function request_by_owner_token( $raw ) {
        global $wpdb;
        if ( ! self::valid_token( $raw ) ) {
            return null;
        }
        $t = DH_OR_Schema::table( 'requests' );
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE owner_token_hash = %s", self::hash_token( $raw ) ) );
    }

    /**
     * Blank every owner detail and keep only counts. Used by the owner's delete
     * link, retract-and-erase and the retention job.
     */
    public static function scrub_request( $id, $status ) {
        global $wpdb;
        $id = (int) $id;
        self::update_request( $id, array_merge( self::PII_COLUMNS, array( 'status' => $status ) ) );
        // Message events carry the owner's name and email; the counts events do not.
        $events = DH_OR_Schema::table( 'request_events' );
        $wpdb->query( $wpdb->prepare( "UPDATE {$events} SET detail = '{}' WHERE request_id = %d AND event IN ('message_dry_run','message_sent')", $id ) );
        self::add_event( $id, 0, 'scrubbed', array( 'status' => $status ) );
    }

    /* ---------- routes ---------- */

    public static function insert_route( array $row ) {
        global $wpdb;
        $row = array_merge( array( 'created_at' => self::now() ), $row );
        $wpdb->insert( DH_OR_Schema::table( 'request_routes' ), $row );
        return (int) $wpdb->insert_id;
    }

    public static function update_route( $id, array $row ) {
        global $wpdb;
        return $wpdb->update( DH_OR_Schema::table( 'request_routes' ), $row, array( 'id' => (int) $id ) );
    }

    public static function routes_for( $request_id ) {
        global $wpdb;
        $t = DH_OR_Schema::table( 'request_routes' );
        return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE request_id = %d ORDER BY rank_pos ASC", (int) $request_id ) );
    }

    public static function route_by_token( $raw ) {
        global $wpdb;
        if ( ! self::valid_token( $raw ) ) {
            return null;
        }
        $t = DH_OR_Schema::table( 'request_routes' );
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE token_hash = %s", self::hash_token( $raw ) ) );
    }

    /**
     * Routes in the last 7 days per profile, for the weekly cap. Dry-run routes
     * count so the preview behaves like the real thing.
     *
     * @param int[] $profile_ids
     * @return array<int,int>
     */
    public static function recent_route_counts( array $profile_ids ) {
        global $wpdb;
        $profile_ids = array_values( array_filter( array_map( 'intval', $profile_ids ) ) );
        if ( ! $profile_ids ) {
            return array();
        }
        $t     = DH_OR_Schema::table( 'request_routes' );
        $in    = implode( ',', $profile_ids );
        $since = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - 7 * DAY_IN_SECONDS );
        $rows  = $wpdb->get_results( $wpdb->prepare( "SELECT profile_id, COUNT(*) n FROM {$t} WHERE profile_id IN ({$in}) AND status IN ('sent','dry_run') AND created_at >= %s GROUP BY profile_id", $since ) );
        $out   = array();
        foreach ( $rows as $r ) {
            $out[ (int) $r->profile_id ] = (int) $r->n;
        }
        return $out;
    }

    /** Whether a trainer has already been sent a real request (dry runs never reached them). */
    public static function has_earlier_notice( $profile_id, $request_id ) {
        global $wpdb;
        $t = DH_OR_Schema::table( 'request_routes' );
        return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$t} WHERE profile_id = %d AND request_id <> %d AND status = 'sent' LIMIT 1", (int) $profile_id, (int) $request_id ) );
    }

    /* ---------- events ---------- */

    public static function add_event( $request_id, $route_id, $event, $detail = array() ) {
        global $wpdb;
        $wpdb->insert( DH_OR_Schema::table( 'request_events' ), array(
            'request_id' => (int) $request_id,
            'route_id'   => (int) $route_id,
            'at'         => self::now(),
            'event'      => $event,
            'detail'     => wp_json_encode( $detail ),
        ) );
        if ( $request_id ) {
            $wpdb->update( DH_OR_Schema::table( 'requests' ), array( 'last_event_at' => self::now() ), array( 'id' => (int) $request_id ) );
        }
        return (int) $wpdb->insert_id;
    }

    public static function events_for( $request_id ) {
        global $wpdb;
        $t = DH_OR_Schema::table( 'request_events' );
        return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE request_id = %d ORDER BY id ASC", (int) $request_id ) );
    }

    /* ---------- suppression mirror ---------- */

    public static function suppressed_ids( array $profile_ids ) {
        global $wpdb;
        $profile_ids = array_values( array_filter( array_map( 'intval', $profile_ids ) ) );
        if ( ! $profile_ids ) {
            return array();
        }
        $t  = DH_OR_Schema::table( 'contact_suppress' );
        $in = implode( ',', $profile_ids );
        return array_map( 'intval', $wpdb->get_col( "SELECT profile_id FROM {$t} WHERE profile_id IN ({$in})" ) );
    }

    public static function suppress( $profile_id, $reason, $source ) {
        global $wpdb;
        $t = DH_OR_Schema::table( 'contact_suppress' );
        $wpdb->query( $wpdb->prepare(
            "INSERT INTO {$t} (profile_id, reason, source, at) VALUES (%d, %s, %s, %s) ON DUPLICATE KEY UPDATE reason = VALUES(reason), source = VALUES(source), at = VALUES(at)",
            (int) $profile_id,
            mb_substr( (string) $reason, 0, 200 ),
            $source,
            self::now()
        ) );
    }

    /* ---------- rate caps ---------- */

    /**
     * How many requests this email, IP hash or ZIP made since a time. Every
     * request row counts, whatever happened to it.
     */
    public static function count_since( $column, $value, $seconds ) {
        global $wpdb;
        if ( ! in_array( $column, array( 'owner_email', 'ip_hash', 'zip' ), true ) || '' === $value ) {
            return 0;
        }
        $t     = DH_OR_Schema::table( 'requests' );
        $since = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - (int) $seconds );
        return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE {$column} = %s AND created_at >= %s", $value, $since ) );
    }
}
