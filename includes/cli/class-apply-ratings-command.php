<?php
/**
 * WP-CLI command: apply-ratings
 *
 * Applies a batch of Google rating/review-count changes produced by the
 * Mac-side fetcher (goodydoggy-ratings-refresh --all) and recalculates every
 * affected city/state rank pool exactly once, with targeted cache purging.
 *
 * Input file format:
 * {
 *   "generated_at": "2026-08-28T20:00:00Z",
 *   "changes": [
 *     { "id": 119446, "fields": { "rating_value": 4.8, "rating_votes_count": 123, "place_id": "ChIJ..." } }
 *   ]
 * }
 *
 * Usage:
 *   wp directory-helpers apply-ratings --file=/path/changes.json --dry-run
 *   wp directory-helpers apply-ratings --file=/path/changes.json --offset=0 --limit=500
 *
 * Idempotent: values already on the profile are skipped, so resuming after an
 * interruption is just re-running the same file.
 */

if ( ! defined( 'WP_CLI' ) ) {
    return;
}

class DH_Apply_Ratings_Command extends WP_CLI_Command {

    const ALLOWED_FIELDS = array( 'rating_value', 'rating_votes_count', 'place_id', 'cid' );
    const RANK_FIELDS    = array( 'rating_value', 'rating_votes_count' );

    /**
     * Apply batched rating changes and recalculate affected rank pools.
     *
     * ## OPTIONS
     *
     * --file=<path>
     * : Path to the changes JSON file (required)
     *
     * [--dry-run]
     * : Print every would-be write without changing anything
     *
     * [--offset=<n>]
     * : Skip the first n change rows (for chunked/trickled applies)
     *
     * [--limit=<n>]
     * : Process at most n change rows
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

        $file = isset( $assoc_args['file'] ) ? $assoc_args['file'] : '';
        if ( ! $file || ! file_exists( $file ) ) {
            WP_CLI::error( '--file=<changes.json> is required and must exist.' );
            return;
        }

        $dry_run     = isset( $assoc_args['dry-run'] );
        $offset      = isset( $assoc_args['offset'] ) ? max( 0, (int) $assoc_args['offset'] ) : 0;
        $limit       = isset( $assoc_args['limit'] ) ? max( 0, (int) $assoc_args['limit'] ) : 0;
        $throttle_ms = isset( $assoc_args['purge-throttle-ms'] ) ? max( 0, (int) $assoc_args['purge-throttle-ms'] ) : 200;

        $data = json_decode( file_get_contents( $file ), true );
        if ( ! is_array( $data ) || ! isset( $data['changes'] ) || ! is_array( $data['changes'] ) ) {
            WP_CLI::error( 'Invalid JSON: expected an object with a "changes" array.' );
            return;
        }

        $changes = $data['changes'];
        $total   = count( $changes );
        if ( $offset || $limit ) {
            $changes = array_slice( $changes, $offset, $limit ? $limit : null );
        }

        WP_CLI::line( '=== Apply Ratings ===' );
        WP_CLI::line( 'File: ' . $file . ( isset( $data['generated_at'] ) ? ' (generated ' . $data['generated_at'] . ')' : '' ) );
        WP_CLI::line( 'Rows: ' . count( $changes ) . " of {$total} (offset {$offset})" );
        WP_CLI::line( 'Dry run: ' . ( $dry_run ? 'Yes' : 'No' ) );
        WP_CLI::line( '' );

        // ── Phase 1: field writes ─────────────────────────────────────────────
        $applied       = 0;
        $skipped       = 0;
        $rank_affected = array(); // published profiles whose rating/votes changed

        foreach ( $changes as $row ) {
            $pid  = isset( $row['id'] ) ? (int) $row['id'] : 0;
            $post = $pid ? get_post( $pid ) : null;

            if ( ! $post || $post->post_type !== 'profile' || ! in_array( $post->post_status, array( 'publish', 'private' ), true ) ) {
                WP_CLI::warning( "Skip {$pid}: not a live profile." );
                $skipped++;
                continue;
            }

            $fields     = isset( $row['fields'] ) && is_array( $row['fields'] ) ? $row['fields'] : array();
            $wrote_any  = false;
            $rank_touch = false;

            foreach ( $fields as $key => $value ) {
                if ( ! in_array( $key, self::ALLOWED_FIELDS, true ) ) {
                    continue;
                }
                // Never blank an existing value from a batch file.
                if ( $value === '' || $value === null ) {
                    continue;
                }
                $current = get_post_meta( $pid, $key, true );
                if ( (string) $current === (string) $value ) {
                    continue;
                }
                WP_CLI::line( ( $dry_run ? '[dry] ' : '' ) . "{$pid} {$key}: '{$current}' -> '{$value}'" );
                if ( ! $dry_run ) {
                    update_field( $key, $value, $pid );
                }
                $wrote_any = true;
                if ( in_array( $key, self::RANK_FIELDS, true ) ) {
                    $rank_touch = true;
                }
            }

            if ( $wrote_any ) {
                if ( ! $dry_run ) {
                    update_field( 'last_updated_ai', current_time( 'mysql' ), $pid );
                }
                $applied++;
            }
            if ( $rank_touch && $post->post_status === 'publish' ) {
                $rank_affected[ $pid ] = true;
            }
        }

        WP_CLI::line( '' );
        WP_CLI::line( "Fields applied on {$applied} profiles ({$skipped} skipped, " . count( $rank_affected ) . ' rank-affected).' );

        if ( $dry_run || empty( $rank_affected ) ) {
            WP_CLI::success( $dry_run ? 'Dry run complete - nothing written.' : 'Done - no rank-affecting changes.' );
            return;
        }

        // ── Phase 2: recalc each affected pool once ───────────────────────────
        WP_CLI::line( '' );
        WP_CLI::line( '=== Recalculating Rank Pools ===' );

        $area_terms  = array(); // term_id => WP_Term
        $state_terms = array(); // term_id => WP_Term
        foreach ( array_keys( $rank_affected ) as $pid ) {
            $terms = get_the_terms( $pid, 'area' );
            if ( ! empty( $terms ) && ! is_wp_error( $terms ) ) {
                foreach ( $terms as $t ) {
                    $area_terms[ $t->term_id ] = $t;
                }
            }
            $primary_state = DH_Taxonomy_Helpers::get_primary_state_term( $pid );
            if ( $primary_state ) {
                $state_terms[ $primary_state->term_id ] = $primary_state;
            }
        }

        WP_CLI::line( count( $area_terms ) . ' city pools, ' . count( $state_terms ) . ' state pools.' );

        $rank_changed = array(); // pid => true, across every pool

        foreach ( $area_terms as $term ) {
            $pool = $this->city_pool( $term->term_id );
            if ( empty( $pool ) ) {
                continue;
            }
            $changed = DH_Profile_Rankings::recalc_pool( $pool, 'city_rank' );
            foreach ( $changed as $pid ) {
                $rank_changed[ $pid ] = true;
            }
            WP_CLI::line( "  {$term->name}: " . count( $pool ) . ' in pool, ' . count( $changed ) . ' ranks moved' );
        }

        foreach ( $state_terms as $term ) {
            $pool = $this->state_pool( $term->term_id );
            if ( empty( $pool ) ) {
                continue;
            }
            $changed = DH_Profile_Rankings::recalc_pool( $pool, 'state_rank' );
            foreach ( $changed as $pid ) {
                $rank_changed[ $pid ] = true;
            }
            WP_CLI::line( "  {$term->name} (state): " . count( $pool ) . ' in pool, ' . count( $changed ) . ' ranks moved' );
        }

        // ── Phase 3: targeted purge ───────────────────────────────────────────
        WP_CLI::line( '' );
        WP_CLI::line( '=== Purging (targeted) ===' );

        $purge_ids = array_keys( $rank_affected + $rank_changed );

        foreach ( $area_terms as $term ) {
            $listing = $this->listing_for_term( 'city-listing', 'area', $term->term_id );
            if ( $listing ) {
                $purge_ids[] = $listing;
            }
        }
        foreach ( $state_terms as $term ) {
            $listing = $this->listing_for_term( 'state-listing', 'state', $term->term_id );
            if ( $listing ) {
                $purge_ids[] = $listing;
            }
        }

        $purge_ids = array_values( array_unique( array_map( 'intval', $purge_ids ) ) );
        foreach ( $purge_ids as $purge_id ) {
            do_action( 'litespeed_purge_post', $purge_id );
            if ( $throttle_ms ) {
                usleep( $throttle_ms * 1000 );
            }
        }

        WP_CLI::line( 'Purged ' . count( $purge_ids ) . ' pages (profiles + affected listings), throttled ' . $throttle_ms . 'ms.' );
        WP_CLI::success( "Applied {$applied} profiles; " . count( $rank_changed ) . ' rank changes across ' . ( count( $area_terms ) + count( $state_terms ) ) . ' pools.' );
    }

    /**
     * Published profile IDs tagged with the given area term.
     */
    private function city_pool( $term_id ) {
        global $wpdb;
        return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "
            SELECT DISTINCT p.ID
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
            INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
                AND tt.taxonomy = 'area' AND tt.term_id = %d
            WHERE p.post_type = 'profile'
              AND p.post_status = 'publish'
        ", $term_id ) ) );
    }

    /**
     * Published profile IDs whose PRIMARY state is the given state term.
     */
    private function state_pool( $term_id ) {
        global $wpdb;
        $all_ids = $wpdb->get_col( $wpdb->prepare( "
            SELECT DISTINCT p.ID
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
            INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
                AND tt.taxonomy = 'state' AND tt.term_id = %d
            WHERE p.post_type = 'profile'
              AND p.post_status = 'publish'
        ", $term_id ) );

        $pool = array();
        foreach ( $all_ids as $pid ) {
            $primary = DH_Taxonomy_Helpers::get_primary_state_term( (int) $pid );
            if ( $primary && (int) $primary->term_id === (int) $term_id ) {
                $pool[] = (int) $pid;
            }
        }
        return $pool;
    }

    /**
     * The single listing post for a term, or 0.
     */
    private function listing_for_term( $post_type, $taxonomy, $term_id ) {
        $ids = get_posts( array(
            'post_type'      => $post_type,
            'post_status'    => 'publish',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'tax_query'      => array(
                array(
                    'taxonomy' => $taxonomy,
                    'field'    => 'term_id',
                    'terms'    => $term_id,
                ),
            ),
        ) );
        return ! empty( $ids ) ? (int) $ids[0] : 0;
    }
}
