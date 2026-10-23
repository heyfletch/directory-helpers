<?php
/**
 * Owner Requests: WP-CLI.
 *
 *   wp dh-requests install-db | load-data | install-form
 *   wp dh-requests stats [--days=<n>] [--include-test] [--format=json]
 *   wp dh-requests pause [--lane=<a|b|c|all>] | resume [--lane=<a|b|c|all>]
 *   wp dh-requests retract <id>
 *   wp dh-requests suppress <profile id> <reason> | unsuppress <profile id>
 *   wp dh-requests purge-old [--dry-run]
 *   wp dh-requests delete-test [--yes]
 *   wp dh-requests match --zip=<zip> [--profile=<id>] [--text=<text>] [--chips=<a,b>] [--age=<age>] [--format=<fmt>] [--video] [--lanes=<A,B,C>]
 *   wp dh-requests setting <key> [<value>]
 *   wp dh-requests ledger-pending | ledger-ack <route id> <state> [<note>] | suppress-sync
 *
 * @package Directory_Helpers
 * @subpackage Owner_Requests
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DH_OR_CLI {

    /** Create or update the tables. */
    public function install_db() {
        DH_OR_Schema::install();
        WP_CLI::success( 'Tables ready (db version ' . DH_OR_Schema::DB_VERSION . ').' );
    }

    /** Load the Census ZIP centroid table (data/zip-centroids.csv). */
    public function load_data() {
        DH_OR_Schema::maybe_install();
        $n = DH_OR_Schema::load_zip_table();
        WP_CLI::success( "Loaded {$n} ZIP centroids." );
    }

    /**
     * Create or update the Fluent Forms form "Ask trainers for help" and store its id in the settings.
     */
    public function install_form() {
        global $wpdb;
        require_once dirname( __DIR__ ) . '/form-definition.php';
        $def   = dh_or_form_definition();
        $forms = $wpdb->prefix . 'fluentform_forms';
        $metas = $wpdb->prefix . 'fluentform_form_meta';
        $now   = current_time( 'mysql' );

        $id = (int) DH_OR_Settings::get( 'form_id' );
        if ( $id && ! $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$forms} WHERE id = %d", $id ) ) ) {
            $id = 0;
        }
        $row = array(
            'title'       => $def['title'],
            'status'      => 'published',
            'form_fields' => wp_json_encode( $def['fields'] ),
            'has_payment' => 0,
            'type'        => 'form',
            'created_by'  => 1,
            'updated_at'  => $now,
        );
        if ( $id ) {
            $wpdb->update( $forms, $row, array( 'id' => $id ) );
        } else {
            $row['created_at'] = $now;
            $wpdb->insert( $forms, $row );
            $id = (int) $wpdb->insert_id;
        }
        if ( ! $id ) {
            WP_CLI::error( 'Could not write the form row.' );
        }

        $meta = array(
            'formSettings'              => wp_json_encode( $def['settings'] ),
            'template_name'             => 'blank_form',
            '_primary_email_field'      => 'email',
            'advancedValidationSettings' => wp_json_encode( array( 'status' => false, 'type' => 'all', 'conditions' => array( array( 'field' => '', 'operator' => '=', 'value' => '' ) ), 'error_message' => '', 'validation_type' => 'fail_on_condition_met' ) ),
            '_ff_form_styles'           => '[]',
        );
        foreach ( $meta as $key => $value ) {
            $exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$metas} WHERE form_id = %d AND meta_key = %s", $id, $key ) );
            if ( $exists ) {
                $wpdb->update( $metas, array( 'value' => $value ), array( 'id' => $exists ) );
            } else {
                $wpdb->insert( $metas, array( 'form_id' => $id, 'meta_key' => $key, 'value' => $value ) );
            }
        }
        // This form sends no Fluent Forms notification email.
        $wpdb->delete( $metas, array( 'form_id' => $id, 'meta_key' => 'notifications' ) );

        DH_OR_Settings::update( array( 'form_id' => $id ) );
        WP_CLI::success( "Form #{$id} ready: [fluentform id=\"{$id}\"]" );
    }

    /**
     * Counts from the tables.
     *
     * ## OPTIONS
     *
     * [--days=<n>]
     * : Window in days (default 7).
     *
     * [--include-test]
     * : Count preview and test requests too.
     *
     * [--format=<format>]
     * : table or json.
     */
    public function stats( $args, $assoc ) {
        global $wpdb;
        $days = isset( $assoc['days'] ) ? max( 1, (int) $assoc['days'] ) : 7;
        $test = isset( $assoc['include-test'] ) ? '' : ' AND is_test = 0';
        $since = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - $days * DAY_IN_SECONDS );
        $rq   = DH_OR_Schema::table( 'requests' );
        $rt   = DH_OR_Schema::table( 'request_routes' );
        $ev   = DH_OR_Schema::table( 'request_events' );

        $by_status = $wpdb->get_results( $wpdb->prepare( "SELECT status, COUNT(*) n FROM {$rq} WHERE created_at >= %s {$test} GROUP BY status", $since ), OBJECT_K );
        $by_tier   = $wpdb->get_results( $wpdb->prepare( "SELECT tier, COUNT(*) n FROM {$rq} WHERE created_at >= %s {$test} GROUP BY tier", $since ), OBJECT_K );
        $by_source = $wpdb->get_results( $wpdb->prepare( "SELECT source_type, COUNT(*) n FROM {$rq} WHERE created_at >= %s {$test} GROUP BY source_type", $since ), OBJECT_K );
        $thin      = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$rq} WHERE created_at >= %s AND thin_market = 1 {$test}", $since ) );
        $routes    = $wpdb->get_results( $wpdb->prepare( "SELECT r.lane, r.status, COUNT(*) n FROM {$rt} r JOIN {$rq} q ON q.id = r.request_id WHERE q.created_at >= %s " . ( isset( $assoc['include-test'] ) ? '' : 'AND q.is_test = 0' ) . ' GROUP BY r.lane, r.status', $since ) );
        $outcomes  = $wpdb->get_results( $wpdb->prepare( "SELECT r.outcome, COUNT(*) n FROM {$rt} r JOIN {$rq} q ON q.id = r.request_id WHERE q.created_at >= %s AND r.outcome <> '' " . ( isset( $assoc['include-test'] ) ? '' : 'AND q.is_test = 0' ) . ' GROUP BY r.outcome', $since ) );
        $viewed    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$rt} r JOIN {$rq} q ON q.id = r.request_id WHERE q.created_at >= %s AND r.viewed_at IS NOT NULL " . ( isset( $assoc['include-test'] ) ? '' : 'AND q.is_test = 0' ), $since ) );

        $out = array(
            'days'          => $days,
            'requests'      => array_map( function ( $r ) { return (int) $r->n; }, $by_status ),
            'tiers'         => array_map( function ( $r ) { return (int) $r->n; }, $by_tier ),
            'sources'       => array_map( function ( $r ) { return (int) $r->n; }, $by_source ),
            'thin_market'   => $thin,
            'routes'        => array_map( function ( $r ) { return array( 'lane' => $r->lane, 'status' => $r->status, 'n' => (int) $r->n ); }, $routes ),
            'routes_viewed' => $viewed,
            'outcomes'      => array_map( function ( $r ) { return (int) $r->n; }, array_column( $outcomes, null, 'outcome' ) ),
            'settings'      => array(
                'preview_only' => (int) DH_OR_Settings::get( 'preview_only' ),
                'dry_run'      => (int) DH_OR_Settings::get( 'dry_run' ),
                'kill_switch'  => (int) DH_OR_Settings::get( 'kill_switch' ),
                'lanes_active' => DH_OR_Settings::active_lanes(),
            ),
        );
        WP_CLI::log( wp_json_encode( $out, JSON_PRETTY_PRINT ) );
    }

    /**
     * Pause lanes (sends stop at once).
     *
     * [--lane=<lane>]
     * : a, b, c or all (default all).
     */
    public function pause( $args, $assoc ) {
        $lane = strtoupper( isset( $assoc['lane'] ) ? $assoc['lane'] : 'all' );
        $set  = 'ALL' === $lane ? array( 'A', 'B', 'C' ) : array_unique( array_merge( (array) DH_OR_Settings::get( 'paused_lanes' ), array( $lane ) ) );
        DH_OR_Settings::update( array( 'paused_lanes' => $set ) );
        WP_CLI::success( 'Paused lanes: ' . implode( ',', $set ) );
    }

    /**
     * Resume lanes.
     *
     * [--lane=<lane>]
     */
    public function resume( $args, $assoc ) {
        $lane = strtoupper( isset( $assoc['lane'] ) ? $assoc['lane'] : 'all' );
        $set  = 'ALL' === $lane ? array() : array_values( array_diff( (array) DH_OR_Settings::get( 'paused_lanes' ), array( $lane ) ) );
        DH_OR_Settings::update( array( 'paused_lanes' => $set, 'kill_switch' => 0 ) );
        WP_CLI::success( 'Paused lanes now: ' . ( $set ? implode( ',', $set ) : 'none' ) );
    }

    /**
     * Withdraw a request: trainers and the owner get a short note and it closes.
     *
     * <id>
     */
    public function retract( $args ) {
        if ( DH_OR_Flow::retract( (int) $args[0] ) ) {
            WP_CLI::success( 'Request ' . (int) $args[0] . ' withdrawn.' );
        } else {
            WP_CLI::error( 'Nothing to withdraw (missing, dropped or already closed).' );
        }
    }

    /**
     * Stop requests to a trainer on the site's mirror. Also run `gd-contact-ledger suppress` on the Mac.
     *
     * <profile_id> <reason>...
     */
    public function suppress( $args ) {
        $id = (int) array_shift( $args );
        DH_OR_Store::suppress( $id, implode( ' ', $args ) ?: 'not given', 'manual' );
        WP_CLI::success( "Profile {$id} suppressed on the site mirror." );
    }

    /**
     * Remove a site-mirror suppression row. For cleaning up tests only; the ledger stays the source of truth.
     *
     * <profile_id>
     */
    public function unsuppress( $args ) {
        global $wpdb;
        $wpdb->delete( DH_OR_Schema::table( 'contact_suppress' ), array( 'profile_id' => (int) $args[0] ) );
        WP_CLI::success( 'Mirror row removed for profile ' . (int) $args[0] . '.' );
    }

    /**
     * Blank owner details older than the retention period, close unconfirmed holds after 7 days,
     * and clear dropped requests after 30 days.
     *
     * [--dry-run]
     */
    public function purge_old( $args, $assoc ) {
        global $wpdb;
        $rq     = DH_OR_Schema::table( 'requests' );
        $months = max( 1, (int) DH_OR_Settings::get( 'retention_months' ) );
        $dry    = isset( $assoc['dry-run'] );
        $now    = current_time( 'timestamp' );
        $rules  = array(
            'retention'      => array( gmdate( 'Y-m-d H:i:s', strtotime( "-{$months} months", $now ) ), "status NOT IN ('expired','deleted') AND owner_email <> '' AND last_event_at < %s", 'expired' ),
            'unconfirmed 7d' => array( gmdate( 'Y-m-d H:i:s', $now - 7 * DAY_IN_SECONDS ), "status = 'held' AND created_at < %s", 'expired' ),
            'dropped 30d'    => array( gmdate( 'Y-m-d H:i:s', $now - 30 * DAY_IN_SECONDS ), "status = 'dropped' AND owner_email <> '' AND created_at < %s", 'expired' ),
        );
        $total = 0;
        foreach ( $rules as $label => $r ) {
            $ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$rq} WHERE {$r[1]}", $r[0] ) );
            WP_CLI::log( sprintf( '%s: %d request(s)%s', $label, count( $ids ), $dry ? ' (dry run, nothing changed)' : '' ) );
            if ( ! $dry ) {
                foreach ( $ids as $id ) {
                    DH_OR_Store::scrub_request( (int) $id, $r[2] );
                }
            }
            $total += count( $ids );
        }
        WP_CLI::success( $total . ' request(s) ' . ( $dry ? 'would be ' : '' ) . 'blanked.' );
    }

    /**
     * Delete every request flagged as a test (preview) with its routes and events.
     *
     * [--yes]
     */
    public function delete_test( $args, $assoc ) {
        global $wpdb;
        $rq  = DH_OR_Schema::table( 'requests' );
        $ids = array_map( 'intval', $wpdb->get_col( "SELECT id FROM {$rq} WHERE is_test = 1" ) );
        if ( ! $ids ) {
            WP_CLI::success( 'No test requests.' );
            return;
        }
        WP_CLI::confirm( count( $ids ) . ' test request(s) will be deleted. Continue?', $assoc );
        $in = implode( ',', $ids );
        $wpdb->query( "DELETE FROM " . DH_OR_Schema::table( 'request_events' ) . " WHERE request_id IN ({$in})" );
        $wpdb->query( "DELETE FROM " . DH_OR_Schema::table( 'request_routes' ) . " WHERE request_id IN ({$in})" );
        $wpdb->query( "DELETE FROM {$rq} WHERE id IN ({$in})" );
        WP_CLI::success( 'Deleted test requests: ' . $in );
    }

    /**
     * Run the matcher for a ZIP without saving anything. Prints who a request would go to.
     *
     * --zip=<zip>
     * [--profile=<id>] [--text=<text>] [--chips=<a,b>] [--age=<age>] [--format=<fmt>] [--video] [--lanes=<A,B,C>]
     */
    public function match( $args, $assoc ) {
        $zip = DH_OR_Text::normalize_zip( isset( $assoc['zip'] ) ? $assoc['zip'] : '' );
        $pt  = $zip ? DH_OR_Schema::zip_point( $zip ) : null;
        if ( ! $pt ) {
            WP_CLI::error( 'Unknown ZIP.' );
        }
        $chips  = isset( $assoc['chips'] ) ? array_filter( explode( ',', $assoc['chips'] ) ) : array();
        $text   = isset( $assoc['text'] ) ? DH_OR_Text::clean_problem( $assoc['text'] ) : '';
        $screen = DH_OR_Screen::screen( $text, $chips, isset( $assoc['age'] ) ? $assoc['age'] : '' );
        $lanes  = isset( $assoc['lanes'] ) ? array_map( 'trim', explode( ',', strtoupper( $assoc['lanes'] ) ) ) : DH_OR_Settings::active_lanes();
        $format = isset( $assoc['format'] ) ? $assoc['format'] : 'any';
        $video  = isset( $assoc['video'] ) || 'video' === $format;
        $radii  = array_map( 'intval', (array) DH_OR_Settings::get( 'radii' ) );
        $viewed = isset( $assoc['profile'] ) ? (int) $assoc['profile'] : 0;

        $cands = DH_OR_Candidates::load( $pt['lat'], $pt['lng'], max( $radii ), $viewed, $video );
        $ids   = wp_list_pluck( $cands, 'id' );
        $res   = DH_OR_Matcher::match( $cands, array(
            'lat' => $pt['lat'], 'lng' => $pt['lng'], 'specialties' => $screen['specialties'], 'dog_age' => $screen['dog_age'],
            'format' => $format, 'video_ok' => $video, 'flags' => $screen['flags'], 'viewed_id' => $viewed,
        ), array(
            'lanes' => $lanes, 'min' => (int) DH_OR_Settings::get( 'min_trainers' ), 'max' => (int) DH_OR_Settings::get( 'max_trainers' ),
            'radii' => $radii, 'weekly_cap' => (int) DH_OR_Settings::get( 'trainer_weekly_cap' ),
            'recent' => DH_OR_Store::recent_route_counts( $ids ), 'suppressed' => DH_OR_Store::suppressed_ids( $ids ),
            'behavior_certs' => DH_OR_Candidates::behavior_certs(),
        ) );
        WP_CLI::log( wp_json_encode( array(
            'zip' => $zip, 'screen' => $screen, 'lanes' => $lanes, 'candidates_loaded' => count( $cands ),
            'radius_used' => $res['radius_used'], 'thin_market' => $res['thin_market'], 'pool_size' => $res['pool_size'],
            'routes' => $res['routes'], 'skipped' => array_map( 'count', $res['skipped_reasons'] ),
        ), JSON_PRETTY_PRINT ) );
    }

    /**
     * Read or set one setting.
     *
     * <key> [<value>]
     */
    public function setting( $args ) {
        $key = $args[0];
        if ( ! array_key_exists( $key, DH_OR_Settings::defaults() ) ) {
            WP_CLI::error( 'Unknown setting. Keys: ' . implode( ', ', array_keys( DH_OR_Settings::defaults() ) ) );
        }
        if ( isset( $args[1] ) ) {
            $val = $args[1];
            $cur = DH_OR_Settings::get( $key );
            if ( is_int( $cur ) ) {
                $val = (int) $val;
            } elseif ( is_array( $cur ) ) {
                $val = array_values( array_filter( array_map( 'trim', explode( ',', $val ) ) ) );
                if ( in_array( $key, array( 'radii' ), true ) ) {
                    $val = array_map( 'intval', $val );
                }
            }
            DH_OR_Settings::update( array( $key => $val ) );
        }
        $v = DH_OR_Settings::get( $key );
        WP_CLI::log( $key . ' = ' . ( is_array( $v ) ? wp_json_encode( $v ) : (string) $v ) );
    }

    /**
     * Routes waiting for a ledger check, as JSON lines, plus opt-outs not yet in the ledger. For the Mac sync.
     */
    public function ledger_pending() {
        global $wpdb;
        $rt = DH_OR_Schema::table( 'request_routes' );
        $rq = DH_OR_Schema::table( 'requests' );
        $rows = $wpdb->get_results( "SELECT r.id route_id, r.request_id, r.profile_id, r.status, r.notified_at, q.is_test FROM {$rt} r JOIN {$rq} q ON q.id = r.request_id WHERE r.ledger_state = 'pending' AND r.status IN ('sent','dry_run') ORDER BY r.id" );
        foreach ( $rows as $r ) {
            WP_CLI::log( wp_json_encode( array( 'type' => 'route', 'route_id' => (int) $r->route_id, 'request_id' => (int) $r->request_id, 'profile_id' => (int) $r->profile_id, 'status' => $r->status, 'is_test' => (int) $r->is_test, 'at' => $r->notified_at ) ) );
        }
        $sup = DH_OR_Schema::table( 'contact_suppress' );
        foreach ( $wpdb->get_results( "SELECT profile_id, reason, at FROM {$sup} WHERE source = 'optout'" ) as $s ) {
            WP_CLI::log( wp_json_encode( array( 'type' => 'optout', 'profile_id' => (int) $s->profile_id, 'reason' => $s->reason, 'at' => $s->at ) ) );
        }
    }

    /**
     * Record the ledger result for a route: ok, hold, logged or skip.
     *
     * <route_id> <state> [<note>]
     */
    public function ledger_ack( $args ) {
        DH_OR_Store::update_route( (int) $args[0], array( 'ledger_state' => mb_substr( $args[1], 0, 12 ), 'ledger_note' => isset( $args[2] ) ? mb_substr( $args[2], 0, 160 ) : '' ) );
        WP_CLI::success( 'Route ' . (int) $args[0] . ' ledger state set.' );
    }

    /**
     * Read JSON lines {"id":<profile id>,"reason":"..."} from STDIN (the ledger's suppress.jsonl) into the site mirror.
     */
    public function suppress_sync() {
        $n = 0;
        while ( ( $line = fgets( STDIN ) ) !== false ) {
            $row = json_decode( trim( $line ), true );
            if ( is_array( $row ) && ! empty( $row['id'] ) && ctype_digit( (string) $row['id'] ) ) {
                DH_OR_Store::suppress( (int) $row['id'], isset( $row['reason'] ) ? $row['reason'] : 'ledger', 'ledger' );
                $n++;
            }
        }
        WP_CLI::success( "Mirrored {$n} suppression row(s)." );
    }
}
