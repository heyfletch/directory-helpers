<?php
/**
 * Owner Requests: tables and one-time data load.
 *
 * dh_requests          one row per owner request
 * dh_request_routes    one row per trainer a request was routed to
 * dh_request_events    what happened, including the dry-run messages
 * dh_contact_suppress  the site's mirror of the shared trainer suppression list
 * dh_zip_centroids     Census ZIP centroids, loaded once from data/zip-centroids.csv
 *
 * @package Directory_Helpers
 * @subpackage Owner_Requests
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DH_OR_Schema {

    const DB_VERSION     = '2';
    const VERSION_OPTION = 'dh_or_db_version';

    public static function table( $name ) {
        global $wpdb;
        return $wpdb->prefix . 'dh_' . $name;
    }

    public static function maybe_install() {
        if ( get_option( self::VERSION_OPTION ) === self::DB_VERSION ) {
            return;
        }
        self::install();
    }

    public static function install() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();

        $requests = self::table( 'requests' );
        $routes   = self::table( 'request_routes' );
        $events   = self::table( 'request_events' );
        $suppress = self::table( 'contact_suppress' );
        $zips     = self::table( 'zip_centroids' );

        dbDelta( "CREATE TABLE {$requests} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            last_event_at datetime NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'received',
            tier varchar(8) NOT NULL DEFAULT '',
            drop_reason varchar(80) NOT NULL DEFAULT '',
            is_test tinyint(1) NOT NULL DEFAULT 0,
            source_post_id bigint(20) unsigned NOT NULL DEFAULT 0,
            source_type varchar(20) NOT NULL DEFAULT '',
            source_url varchar(255) NOT NULL DEFAULT '',
            utm_source varchar(100) NOT NULL DEFAULT '',
            utm_medium varchar(100) NOT NULL DEFAULT '',
            utm_campaign varchar(100) NOT NULL DEFAULT '',
            viewed_profile_id bigint(20) unsigned NOT NULL DEFAULT 0,
            owner_first_name varchar(60) NOT NULL DEFAULT '',
            owner_email varchar(254) NOT NULL DEFAULT '',
            owner_phone varchar(30) NOT NULL DEFAULT '',
            zip char(5) NOT NULL DEFAULT '',
            lat decimal(9,6) DEFAULT NULL,
            lng decimal(9,6) DEFAULT NULL,
            problem_text text,
            chips varchar(255) NOT NULL DEFAULT '',
            dog_age varchar(12) NOT NULL DEFAULT '',
            format varchar(12) NOT NULL DEFAULT '',
            video_ok tinyint(1) NOT NULL DEFAULT 0,
            specialty_primary varchar(20) NOT NULL DEFAULT '',
            specialties varchar(255) NOT NULL DEFAULT '',
            flags varchar(60) NOT NULL DEFAULT '',
            screen_version varchar(20) NOT NULL DEFAULT '',
            screen_reasons varchar(255) NOT NULL DEFAULT '',
            ip_hash char(64) NOT NULL DEFAULT '',
            consent_at datetime DEFAULT NULL,
            consent_text text,
            radius_used smallint(5) unsigned NOT NULL DEFAULT 0,
            matched_count tinyint(3) unsigned NOT NULL DEFAULT 0,
            notified_count tinyint(3) unsigned NOT NULL DEFAULT 0,
            thin_market tinyint(1) NOT NULL DEFAULT 0,
            other_lane_count smallint(5) unsigned NOT NULL DEFAULT 0,
            owner_token_hash char(64) NOT NULL DEFAULT '',
            PRIMARY KEY  (id),
            KEY created_at (created_at),
            KEY status (status),
            KEY owner_email (owner_email(100)),
            KEY ip_hash (ip_hash),
            KEY zip (zip),
            KEY owner_token_hash (owner_token_hash)
        ) {$charset};" );

        dbDelta( "CREATE TABLE {$routes} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            request_id bigint(20) unsigned NOT NULL,
            profile_id bigint(20) unsigned NOT NULL,
            lane char(1) NOT NULL DEFAULT 'A',
            rank_pos tinyint(3) unsigned NOT NULL DEFAULT 0,
            distance decimal(6,1) NOT NULL DEFAULT 0,
            score decimal(6,3) NOT NULL DEFAULT 0,
            fit_notes varchar(255) NOT NULL DEFAULT '',
            is_viewed tinyint(1) NOT NULL DEFAULT 0,
            status varchar(20) NOT NULL DEFAULT 'queued',
            token_hash char(64) NOT NULL DEFAULT '',
            created_at datetime NOT NULL,
            notified_at datetime DEFAULT NULL,
            viewed_at datetime DEFAULT NULL,
            outcome varchar(20) NOT NULL DEFAULT '',
            outcome_at datetime DEFAULT NULL,
            ledger_state varchar(12) NOT NULL DEFAULT 'pending',
            ledger_note varchar(160) NOT NULL DEFAULT '',
            PRIMARY KEY  (id),
            KEY request_id (request_id),
            KEY profile_created (profile_id, created_at),
            KEY token_hash (token_hash),
            KEY ledger_state (ledger_state)
        ) {$charset};" );

        dbDelta( "CREATE TABLE {$events} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            request_id bigint(20) unsigned NOT NULL DEFAULT 0,
            route_id bigint(20) unsigned NOT NULL DEFAULT 0,
            at datetime NOT NULL,
            event varchar(40) NOT NULL,
            detail longtext,
            PRIMARY KEY  (id),
            KEY request_id (request_id),
            KEY event_at (event, at)
        ) {$charset};" );

        dbDelta( "CREATE TABLE {$suppress} (
            profile_id bigint(20) unsigned NOT NULL,
            reason varchar(200) NOT NULL DEFAULT '',
            source varchar(20) NOT NULL DEFAULT '',
            at datetime NOT NULL,
            PRIMARY KEY  (profile_id)
        ) {$charset};" );

        dbDelta( "CREATE TABLE {$zips} (
            zip char(5) NOT NULL,
            lat decimal(8,5) NOT NULL,
            lng decimal(8,5) NOT NULL,
            PRIMARY KEY  (zip)
        ) {$charset};" );

        update_option( self::VERSION_OPTION, self::DB_VERSION, false );
    }

    /**
     * Load data/zip-centroids.csv into dh_zip_centroids (idempotent: replaces rows).
     *
     * @return int Rows loaded.
     */
    public static function load_zip_table() {
        global $wpdb;
        $file = dirname( __DIR__ ) . '/data/zip-centroids.csv';
        $h    = fopen( $file, 'r' );
        if ( ! $h ) {
            return 0;
        }
        $table = self::table( 'zip_centroids' );
        $wpdb->query( "TRUNCATE TABLE {$table}" );
        fgetcsv( $h ); // header
        $batch = array();
        $count = 0;
        while ( ( $row = fgetcsv( $h ) ) !== false ) {
            if ( count( $row ) < 3 ) {
                continue;
            }
            $batch[] = $wpdb->prepare( '(%s,%f,%f)', $row[0], $row[1], $row[2] );
            if ( count( $batch ) >= 1000 ) {
                $wpdb->query( "INSERT INTO {$table} (zip,lat,lng) VALUES " . implode( ',', $batch ) );
                $count += count( $batch );
                $batch  = array();
            }
        }
        if ( $batch ) {
            $wpdb->query( "INSERT INTO {$table} (zip,lat,lng) VALUES " . implode( ',', $batch ) );
            $count += count( $batch );
        }
        fclose( $h );
        return $count;
    }

    /** @return array{lat:float,lng:float}|null */
    public static function zip_point( $zip ) {
        global $wpdb;
        $table = self::table( 'zip_centroids' );
        $row   = $wpdb->get_row( $wpdb->prepare( "SELECT lat, lng FROM {$table} WHERE zip = %s", $zip ) );
        return $row ? array( 'lat' => (float) $row->lat, 'lng' => (float) $row->lng ) : null;
    }
}
