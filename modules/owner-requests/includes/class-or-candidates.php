<?php
/**
 * Owner Requests: loads candidate trainers for the matcher.
 *
 * Candidates are published `profile` posts in the dog-trainer niche that are
 * not closed, restricted or store-chain listings, each tagged with the lane a
 * message to them would use:
 *   A  the trainer gave their own email (ACF contact_email)
 *   B  an email in the profile's contact_info data that nobody opted in
 *   C  a contact page only
 * Only lane A can be sent to in this build; B and C are loaded so the
 * request log can say how many more trainers the other lanes would add.
 *
 * Emails are never put in the candidate array; trainer_email() reads the lane A
 * address at send time.
 *
 * @package Directory_Helpers
 * @subpackage Owner_Requests
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DH_OR_Candidates {

    const NICHE = 'dog-trainer';

    /** Certifications that count as a behavior credential for aggression and medical requests. */
    const BEHAVIOR_CERTS = array( 'CBCC-KA', 'IAABC-CDBC' );

    public static function behavior_certs() {
        return apply_filters( 'dh_or_behavior_certs', self::BEHAVIOR_CERTS );
    }

    /**
     * Trainers within $max_miles of a point, plus the viewed profile wherever it is.
     *
     * @return array[] candidate rows for DH_OR_Matcher::match()
     */
    public static function load( $lat, $lng, $max_miles, $viewed_id = 0, $video_anywhere = false ) {
        global $wpdb;

        $lat_delta = $max_miles / 69.0;
        $lng_delta = $max_miles / max( 1.0, 69.172 * cos( deg2rad( $lat ) ) );

        $ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT p.ID
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} la ON la.post_id = p.ID AND la.meta_key = 'latitude'
             INNER JOIN {$wpdb->postmeta} lo ON lo.post_id = p.ID AND lo.meta_key = 'longitude'
             WHERE p.post_type = 'profile' AND p.post_status = 'publish'
               AND la.meta_value <> '' AND lo.meta_value <> ''
               AND CAST(la.meta_value AS DECIMAL(10,6)) BETWEEN %f AND %f
               AND CAST(lo.meta_value AS DECIMAL(10,6)) BETWEEN %f AND %f",
            $lat - $lat_delta,
            $lat + $lat_delta,
            $lng - $lng_delta,
            $lng + $lng_delta
        ) );
        $ids = array_map( 'intval', $ids );

        if ( $video_anywhere ) {
            // Video trainers at any distance: only those a lane-A message could reach.
            $far = $wpdb->get_col(
                "SELECT DISTINCT s.post_id FROM {$wpdb->postmeta} s
                 INNER JOIN {$wpdb->postmeta} e ON e.post_id = s.post_id AND e.meta_key = 'contact_email' AND e.meta_value <> ''
                 INNER JOIN {$wpdb->posts} p ON p.ID = s.post_id AND p.post_type = 'profile' AND p.post_status = 'publish'
                 WHERE s.meta_key = 'service_types' AND s.meta_value LIKE '%\"video\"%'"
            );
            $ids = array_merge( $ids, array_map( 'intval', $far ) );
        }
        if ( $viewed_id ) {
            $ids[] = (int) $viewed_id;
        }
        $ids = array_values( array_unique( $ids ) );
        if ( ! $ids ) {
            return array();
        }

        $excluded = class_exists( 'DH_Profile_Status_Notice' ) ? array_flip( DH_Profile_Status_Notice::excluded_profile_ids() ) : array();
        update_meta_cache( 'post', $ids );

        $b_ids = self::lane_b_ids( $ids );

        $out = array();
        foreach ( $ids as $id ) {
            if ( isset( $excluded[ $id ] ) ) {
                continue;
            }
            $post = get_post( $id );
            if ( ! $post || 'profile' !== $post->post_type || 'publish' !== $post->post_status || ! has_term( self::NICHE, 'niche', $id ) ) {
                continue;
            }
            $plat = get_post_meta( $id, 'latitude', true );
            $plng = get_post_meta( $id, 'longitude', true );
            $unplaced = false;
            if ( ! is_numeric( $plat ) || ! is_numeric( $plng ) ) {
                // No map location. Fine for the trainer whose page the owner is on (the owner chose them); nobody else can be placed.
                if ( (int) $viewed_id !== (int) $id ) {
                    continue;
                }
                $plat     = $lat;
                $plng     = $lng;
                $unplaced = true;
            }
            $lane = '';
            $ce   = trim( (string) get_post_meta( $id, 'contact_email', true ) );
            if ( '' !== $ce && is_email( $ce ) ) {
                $lane = 'A';
            } elseif ( isset( $b_ids[ $id ] ) ) {
                $lane = 'B';
            } elseif ( '' !== trim( (string) get_post_meta( $id, 'contact_url', true ) ) ) {
                $lane = 'C';
            }
            if ( '' === $lane ) {
                continue;
            }
            $out[] = array(
                'id'          => $id,
                'name'        => html_entity_decode( $post->post_title, ENT_QUOTES, 'UTF-8' ),
                'url'         => (string) get_post_meta( $id, 'url', true ),
                'city'        => trim( (string) get_post_meta( $id, 'city', true ) . ', ' . (string) get_post_meta( $id, 'state', true ), ', ' ),
                'lat'         => (float) $plat,
                'lng'         => (float) $plng,
                'rating'      => (float) get_post_meta( $id, 'rating_value', true ),
                'reviews'     => (int) get_post_meta( $id, 'rating_votes_count', true ),
                'specialties' => self::meta_list( $id, 'program_specialties' ),
                'ages'        => self::meta_list( $id, 'dog_age' ),
                'services'    => self::meta_list( $id, 'service_types' ),
                'certs'       => self::meta_list( $id, 'certifications' ),
                'lane'        => $lane,
                'unplaced'    => $unplaced,
            );
        }
        return $out;
    }

    /** @return string[] */
    private static function meta_list( $id, $key ) {
        $v = get_post_meta( $id, $key, true );
        if ( is_string( $v ) && '' !== $v ) {
            $v = maybe_unserialize( $v );
        }
        return is_array( $v ) ? array_values( array_map( 'strval', $v ) ) : array();
    }

    /**
     * IDs (as keys) whose contact_info repeater holds an email.
     *
     * @param int[] $ids
     * @return array<int,true>
     */
    private static function lane_b_ids( array $ids ) {
        global $wpdb;
        if ( ! $ids ) {
            return array();
        }
        $in   = implode( ',', array_map( 'intval', $ids ) );
        $rows = $wpdb->get_col( "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE post_id IN ({$in}) AND meta_key REGEXP '^contact_info_[0-9]+_value$' AND meta_value LIKE '%@%'" );
        return array_fill_keys( array_map( 'intval', $rows ), true );
    }

    /**
     * Name of the nearest city page's area within 25 miles of a point, e.g. "Milwaukee, WI".
     * Used only to say where an owner is in a trainer message; '' when nothing is close.
     */
    public static function nearest_city_name( $lat, $lng ) {
        global $wpdb;
        $box_lat = 25 / 69.0;
        $box_lng = 25 / max( 1.0, 69.172 * cos( deg2rad( $lat ) ) );
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT t.name, la.meta_value AS lat, lo.meta_value AS lng
             FROM {$wpdb->terms} t
             INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id AND tt.taxonomy = 'area'
             INNER JOIN {$wpdb->termmeta} la ON la.term_id = t.term_id AND la.meta_key = 'latitude'
             INNER JOIN {$wpdb->termmeta} lo ON lo.term_id = t.term_id AND lo.meta_key = 'longitude'
             WHERE CAST(la.meta_value AS DECIMAL(10,6)) BETWEEN %f AND %f
               AND CAST(lo.meta_value AS DECIMAL(10,6)) BETWEEN %f AND %f",
            $lat - $box_lat,
            $lat + $box_lat,
            $lng - $box_lng,
            $lng + $box_lng
        ) );
        $best = '';
        $best_d = null;
        foreach ( $rows as $r ) {
            $d = DH_OR_Matcher::miles( $lat, $lng, (float) $r->lat, (float) $r->lng );
            if ( $d <= 25 && ( null === $best_d || $d < $best_d ) ) {
                $best_d = $d;
                $best   = html_entity_decode( $r->name, ENT_QUOTES, 'UTF-8' );
            }
        }
        // The site names duplicate towns "Freeport - IL"; write it the way people do.
        return preg_replace( '/\s+-\s+([A-Z]{2})$/', ', $1', $best );
    }

    /**
     * The address a lane A message goes to. Empty unless the profile is lane A.
     */
    public static function trainer_email( $profile_id ) {
        $ce = trim( (string) get_post_meta( (int) $profile_id, 'contact_email', true ) );
        return ( '' !== $ce && is_email( $ce ) ) ? strtolower( $ce ) : '';
    }

    /** Who a message to this trainer is addressed to. */
    public static function trainer_name( $profile_id ) {
        return html_entity_decode( wp_strip_all_tags( get_the_title( (int) $profile_id ) ), ENT_QUOTES, 'UTF-8' );
    }
}
