<?php
/**
 * WP-CLI command: refresh-closed
 *
 * Brings every city and state that contains a permanently-closed profile
 * (gbp_status = closed_forever) up to date:
 *   - recalculates each affected city_rank / state_rank pool once via
 *     DH_Profile_Rankings::recalc_pool() (closed profiles hold the 99999
 *     sentinel and take no numbered rank)
 *   - recounts the listing _profile_count, which excludes closed profiles
 *
 * Purges nothing unless --purge is passed; then it purges only the affected
 * city and state listing pages (throttled). Never site-wide.
 *
 * Usage:
 *   wp directory-helpers refresh-closed --dry-run
 *   wp directory-helpers refresh-closed
 *   wp directory-helpers refresh-closed --purge
 */

if ( ! defined( 'WP_CLI' ) ) {
    return;
}

class DH_Refresh_Closed_Command extends WP_CLI_Command {

    /**
     * Recalculate rank pools and listing counts for cities/states holding closed profiles.
     *
     * ## OPTIONS
     *
     * [--dry-run]
     * : Report affected pools and count changes without writing anything
     *
     * [--purge]
     * : Purge the affected city and state listing pages after writing (off by default)
     *
     * [--purge-throttle-ms=<n>]
     * : Sleep between purges so re-priming trickles (default 200)
     *
     * @when after_wp_load
     */
    public function __invoke( $args, $assoc_args ) {

        while ( ob_get_level() ) {
            ob_end_flush();
        }

        if ( ! class_exists( 'DH_Profile_Status_Notice' ) || ! class_exists( 'DH_Apply_Ratings_Command' ) ) {
            WP_CLI::error( 'Profile Status Notice module and apply-ratings command must be loaded.' );
            return;
        }
        $counts = class_exists( 'DH_Listing_Counts' ) ? DH_Listing_Counts::instance() : null;
        if ( ! $counts ) {
            WP_CLI::error( 'Listing Counts module is not loaded.' );
            return;
        }

        $dry_run     = isset( $assoc_args['dry-run'] );
        $purge       = isset( $assoc_args['purge'] );
        $throttle_ms = isset( $assoc_args['purge-throttle-ms'] ) ? max( 0, (int) $assoc_args['purge-throttle-ms'] ) : 200;

        // ── Closed, published profiles ────────────────────────────────────────
        $closed = array_values( array_filter( DH_Profile_Status_Notice::closed_profile_ids(), function ( $pid ) {
            return get_post_type( $pid ) === 'profile' && get_post_status( $pid ) === 'publish';
        } ) );

        WP_CLI::line( count( $closed ) . ' published profiles are marked closed_forever.' );
        if ( empty( $closed ) ) {
            WP_CLI::success( 'Nothing to do.' );
            return;
        }

        // ── Affected terms ────────────────────────────────────────────────────
        $area_terms  = array(); // term_id => WP_Term
        $state_terms = array(); // term_id => WP_Term
        foreach ( $closed as $pid ) {
            $terms = get_the_terms( $pid, 'area' );
            if ( ! empty( $terms ) && ! is_wp_error( $terms ) ) {
                foreach ( $terms as $t ) {
                    $area_terms[ $t->term_id ] = $t;
                }
            }
            $terms = get_the_terms( $pid, 'state' );
            if ( ! empty( $terms ) && ! is_wp_error( $terms ) ) {
                foreach ( $terms as $t ) {
                    $state_terms[ $t->term_id ] = $t;
                }
            }
        }
        WP_CLI::line( count( $area_terms ) . ' city pools, ' . count( $state_terms ) . ' state pools.' );

        // ── Rank pools ────────────────────────────────────────────────────────
        WP_CLI::line( '' );
        WP_CLI::line( '=== Rank Pools ===' );
        $rank_changed = array();
        if ( $dry_run ) {
            WP_CLI::line( 'Dry run: pools listed above would be recalculated via recalc_pool().' );
        } else {
            foreach ( $area_terms as $term ) {
                $pool = DH_Apply_Ratings_Command::city_pool( $term->term_id );
                if ( empty( $pool ) ) {
                    continue;
                }
                $changed = DH_Profile_Rankings::recalc_pool( $pool, 'city_rank' );
                foreach ( $changed as $pid ) {
                    $rank_changed[ $pid ] = true;
                }
                if ( ! empty( $changed ) ) {
                    WP_CLI::line( "  {$term->name}: " . count( $pool ) . ' in pool, ' . count( $changed ) . ' ranks moved' );
                }
            }
            foreach ( $state_terms as $term ) {
                $pool = DH_Apply_Ratings_Command::state_pool( $term->term_id );
                if ( empty( $pool ) ) {
                    continue;
                }
                $changed = DH_Profile_Rankings::recalc_pool( $pool, 'state_rank' );
                foreach ( $changed as $pid ) {
                    $rank_changed[ $pid ] = true;
                }
                if ( ! empty( $changed ) ) {
                    WP_CLI::line( "  {$term->name} (state): " . count( $pool ) . ' in pool, ' . count( $changed ) . ' ranks moved' );
                }
            }
            WP_CLI::line( count( $rank_changed ) . ' profiles changed rank.' );
        }

        // ── Listing counts ────────────────────────────────────────────────────
        WP_CLI::line( '' );
        WP_CLI::line( '=== Listing Counts (_profile_count) ===' );
        $listing_ids   = array();
        $count_changed = 0;

        foreach ( $area_terms as $term ) {
            $listing = DH_Apply_Ratings_Command::listing_for_term( 'city-listing', 'area', $term->term_id );
            if ( ! $listing ) {
                continue;
            }
            $listing_ids[] = $listing;
            $old = (int) get_post_meta( $listing, '_profile_count', true );
            $new = (int) $counts->count_profiles_by_area( $term->term_id );
            if ( $old !== $new ) {
                $count_changed++;
                WP_CLI::line( "  {$term->name}: {$old} -> {$new}" );
                if ( ! $dry_run ) {
                    $counts->update_city_profile_count( $term->term_id );
                }
            }
        }
        foreach ( $state_terms as $term ) {
            $listing = DH_Apply_Ratings_Command::listing_for_term( 'state-listing', 'state', $term->term_id );
            if ( ! $listing ) {
                continue;
            }
            $listing_ids[] = $listing;
            $old = (int) get_post_meta( $listing, '_profile_count', true );
            $new = (int) $counts->count_profiles_by_state( $term->slug );
            if ( $old !== $new ) {
                $count_changed++;
                WP_CLI::line( "  {$term->name} (state): {$old} -> {$new}" );
                if ( ! $dry_run ) {
                    $counts->update_state_counts( $term->slug );
                }
            }
        }
        WP_CLI::line( "{$count_changed} listing counts " . ( $dry_run ? 'would change.' : 'updated.' ) );

        // ── Pages that render a closed trainer ────────────────────────────────
        // A closed profile is listed on its own city and state pages AND on every
        // city page whose proximity radius reaches it (DH_Bricks_Query_Helpers),
        // so the purge set is wider than the rank/count set above.
        $own_ids   = array_values( array_unique( array_map( 'intval', $listing_ids ) ) );
        $near_ids  = array_values( array_diff( self::proximity_listing_ids( $closed ), $own_ids ) );
        $purge_ids = array_merge( $own_ids, $near_ids );
        WP_CLI::line( '' );
        WP_CLI::line( count( $purge_ids ) . ' listing pages render a closed trainer: ' . count( $own_ids ) . ' own city/state pages + ' . count( $near_ids ) . ' proximity neighbours.' );

        if ( $dry_run ) {
            WP_CLI::success( 'Dry run complete - nothing written.' );
            return;
        }

        // ── Purge (opt-in, targeted) ──────────────────────────────────────────
        WP_CLI::line( '' );
        if ( ! $purge ) {
            WP_CLI::success( 'Done. No cache purged (pass --purge to purge those ' . count( $purge_ids ) . ' listing pages).' );
            return;
        }

        foreach ( $purge_ids as $listing_id ) {
            do_action( 'litespeed_purge_post', $listing_id );
            if ( $throttle_ms ) {
                usleep( $throttle_ms * 1000 );
            }
        }
        WP_CLI::success( 'Done. Purged ' . count( $purge_ids ) . ' listing pages, throttled ' . $throttle_ms . 'ms.' );
    }

    /**
     * Published city-listing IDs whose proximity list reaches any of the given
     * profiles: same niche, within the city's radius (custom_radius >
     * recommended_radius > plugin default). Mirrors
     * DH_Bricks_Query_Helpers::get_nearby_profiles_query_args().
     *
     * @param int[] $profile_ids
     * @return int[]
     */
    private static function proximity_listing_ids( array $profile_ids ) {
        global $wpdb;

        $options        = get_option( 'directory_helpers_options', array() );
        $default_radius = isset( $options['default_city_radius'] ) ? (int) $options['default_city_radius'] : 5;

        $profiles = array();
        foreach ( $profile_ids as $pid ) {
            $lat = get_post_meta( $pid, 'latitude', true );
            $lng = get_post_meta( $pid, 'longitude', true );
            if ( $lat === '' || $lng === '' ) {
                continue;
            }
            $profiles[] = array(
                'lat'    => deg2rad( (float) $lat ),
                'lng'    => deg2rad( (float) $lng ),
                'niches' => self::term_ids( $pid, 'niche' ),
            );
        }
        if ( empty( $profiles ) ) {
            return array();
        }

        $listings = $wpdb->get_results( "
            SELECT p.ID, tt.term_id
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID
            INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'area'
            WHERE p.post_type = 'city-listing'
              AND p.post_status = 'publish'
        " );

        $hits = array();
        foreach ( $listings as $row ) {
            $term_id = (int) $row->term_id;
            $lat     = get_term_meta( $term_id, 'latitude', true );
            $lng     = get_term_meta( $term_id, 'longitude', true );
            if ( $lat === '' || $lng === '' ) {
                continue;
            }
            $custom      = get_term_meta( $term_id, 'custom_radius', true );
            $recommended = get_term_meta( $term_id, 'recommended_radius', true );
            $radius      = $custom ? (int) $custom : ( $recommended ? (int) $recommended : $default_radius );
            $niches      = self::term_ids( (int) $row->ID, 'niche' );
            $lat_r       = deg2rad( (float) $lat );
            $lng_r       = deg2rad( (float) $lng );

            foreach ( $profiles as $p ) {
                if ( ! array_intersect( $niches, $p['niches'] ) ) {
                    continue;
                }
                $cos_d = cos( $lat_r ) * cos( $p['lat'] ) * cos( $p['lng'] - $lng_r ) + sin( $lat_r ) * sin( $p['lat'] );
                $miles = 3959 * acos( max( -1, min( 1, $cos_d ) ) );
                if ( $miles <= $radius ) {
                    $hits[] = (int) $row->ID;
                    break;
                }
            }
        }
        return $hits;
    }

    /**
     * @return int[]
     */
    private static function term_ids( $post_id, $taxonomy ) {
        $terms = wp_get_post_terms( $post_id, $taxonomy, array( 'fields' => 'ids' ) );
        return is_wp_error( $terms ) ? array() : array_map( 'intval', (array) $terms );
    }
}
