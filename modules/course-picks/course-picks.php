<?php
/**
 * Course Picks Module
 *
 * Up to three listed trainers per ZIP code and topic for the free training course emails.
 * n8n posts the course's ZIP codes and topics to POST /wp-json/dh/v1/course-picks once a week
 * and stores the answer in its trainer_picks table.
 *
 * Uses the Owner Requests matcher for distance and fit (15, then 25, then 50 miles until three
 * trainers fit; closed and restricted profiles never appear), then puts Featured trainers first.
 * A topic is a site specialty slug (puppy, leash, fearfulness...) or a dog age as "age:adolescent".
 *
 * Auth: the X-Course-Key header must match the dh_course_picks_key option.
 *
 * @package Directory_Helpers
 * @subpackage Course_Picks
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DH_Course_Picks {

    const MAX_ZIPS   = 2000;
    const MAX_TOPICS = 20;
    const PER_TOPIC  = 3;

    public function __construct() {
        add_action( 'rest_api_init', array( $this, 'routes' ) );
    }

    public function routes() {
        register_rest_route( 'dh/v1', '/course-picks', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'handle' ),
            'permission_callback' => array( $this, 'allowed' ),
        ) );
    }

    public function allowed( WP_REST_Request $request ) {
        $key = (string) get_option( 'dh_course_picks_key', '' );
        return '' !== $key && hash_equals( $key, (string) $request->get_header( 'x-course-key' ) );
    }

    public function handle( WP_REST_Request $request ) {
        if ( ! class_exists( 'DH_OR_Matcher' ) || ! class_exists( 'DH_OR_Candidates' ) || ! class_exists( 'DH_OR_Schema' ) ) {
            return new WP_Error( 'dh_course_picks_unavailable', 'The Owner Requests module is not loaded.', array( 'status' => 503 ) );
        }
        $zips   = array_slice( array_values( array_unique( array_filter( array_map( array( $this, 'zip' ), (array) $request->get_param( 'zips' ) ) ) ) ), 0, self::MAX_ZIPS );
        $topics = array_slice( array_values( array_unique( array_filter( array_map( 'sanitize_text_field', (array) $request->get_param( 'topics' ) ) ) ) ), 0, self::MAX_TOPICS );
        $rows   = array();
        foreach ( $zips as $zip ) {
            $rows = array_merge( $rows, self::picks_for_zip( $zip, $topics ) );
        }
        return rest_ensure_response( array( 'rows' => $rows, 'zips' => count( $zips ), 'topics' => $topics ) );
    }

    private function zip( $z ) {
        $z = substr( preg_replace( '/\D/', '', (string) $z ), 0, 5 );
        return 5 === strlen( $z ) ? $z : '';
    }

    /**
     * @return array[] rows: zip, state, specialty, rank, trainer_name, trainer_city, profile_url, city_page_url, featured
     */
    public static function picks_for_zip( $zip, array $topics ) {
        $point = DH_OR_Schema::zip_point( $zip );
        if ( ! $point ) {
            return array();
        }
        $city       = self::nearest_city_page( $point['lat'], $point['lng'] );
        $candidates = DH_OR_Candidates::load( $point['lat'], $point['lng'], 50 );
        $out        = array();
        foreach ( $topics as $topic ) {
            $req = array( 'lat' => $point['lat'], 'lng' => $point['lng'], 'format' => 'none', 'specialties' => array(), 'dog_age' => '' );
            if ( 0 === strpos( $topic, 'age:' ) ) {
                $req['dog_age'] = substr( $topic, 4 );
            } else {
                $req['specialties'] = array( $topic );
            }
            $result = DH_OR_Matcher::match( $candidates, $req, array(
                'lanes' => array( 'A', 'B', 'C' ), 'min' => self::PER_TOPIC, 'max' => 50,
                'radii' => array( 15, 25, 50 ), 'weekly_cap' => 0,
            ) );
            // Matcher order (public rank score, then distance), with Featured trainers moved to the front.
            $routes   = $result['routes'];
            $featured = array_values( array_filter( $routes, function ( $r ) {
                return (int) get_post_meta( (int) $r['profile_id'], 'featured', true ) > 0;
            } ) );
            $ids      = wp_list_pluck( $featured, 'profile_id' );
            $rest     = array_values( array_filter( $routes, function ( $r ) use ( $ids ) {
                return ! in_array( $r['profile_id'], $ids, true );
            } ) );
            $rank = 0;
            foreach ( array_slice( array_merge( $featured, $rest ), 0, self::PER_TOPIC ) as $r ) {
                $id    = (int) $r['profile_id'];
                $out[] = array(
                    'zip'           => $zip,
                    'state'         => $city['state'] ?: strtoupper( (string) get_post_meta( $id, 'state', true ) ),
                    'specialty'     => $topic,
                    'rank'          => ++$rank,
                    'trainer_name'  => html_entity_decode( get_the_title( $id ), ENT_QUOTES, 'UTF-8' ),
                    'trainer_city'  => (string) get_post_meta( $id, 'city', true ),
                    'profile_url'   => get_permalink( $id ),
                    'city_page_url' => $city['url'],
                    'featured'      => in_array( $id, $ids, true ),
                );
            }
        }
        return $out;
    }

    /**
     * The published city page closest to a point, within 25 miles: url and two-letter state.
     */
    public static function nearest_city_page( $lat, $lng ) {
        global $wpdb;
        $box_lat = 25 / 69.0;
        $box_lng = 25 / max( 1.0, 69.172 * cos( deg2rad( $lat ) ) );
        $rows    = $wpdb->get_results( $wpdb->prepare(
            "SELECT p.ID, la.meta_value AS lat, lo.meta_value AS lng
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID
             INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'area'
             INNER JOIN {$wpdb->termmeta} la ON la.term_id = tt.term_id AND la.meta_key = 'latitude'
             INNER JOIN {$wpdb->termmeta} lo ON lo.term_id = tt.term_id AND lo.meta_key = 'longitude'
             WHERE p.post_type = 'city-listing' AND p.post_status = 'publish'
               AND CAST(la.meta_value AS DECIMAL(10,6)) BETWEEN %f AND %f
               AND CAST(lo.meta_value AS DECIMAL(10,6)) BETWEEN %f AND %f",
            $lat - $box_lat, $lat + $box_lat, $lng - $box_lng, $lng + $box_lng
        ) );
        $best   = null;
        $best_d = null;
        foreach ( $rows as $r ) {
            $d = DH_OR_Matcher::miles( $lat, $lng, (float) $r->lat, (float) $r->lng );
            if ( $d <= 25 && ( null === $best_d || $d < $best_d ) ) {
                $best_d = $d;
                $best   = (int) $r->ID;
            }
        }
        if ( ! $best ) {
            return array( 'url' => '', 'state' => '' );
        }
        // City page slugs end in "-xx-dog-trainers", where xx is the state.
        $state = preg_match( '/-([a-z]{2})-dog-trainers$/', get_post_field( 'post_name', $best ), $m ) ? strtoupper( $m[1] ) : '';
        return array( 'url' => get_permalink( $best ), 'state' => $state );
    }
}
