<?php
/**
 * WP-CLI command: update-rankings-for-profile
 *
 * Recalculates city_rank for every area term on the profile, and state_rank
 * for the profile's primary state term. Purges LiteSpeed page cache for all
 * affected city-listing and state-listing pages.
 *
 * Usage:
 *   wp directory-helpers update-rankings-for-profile --profile=<post_id>
 *   wp directory-helpers update-rankings-for-profile --profile=<post_id> --dry-run
 */

if ( ! defined( 'WP_CLI' ) ) {
    return;
}

class DH_Update_Rankings_For_Profile_Command extends WP_CLI_Command {

    /**
     * Update city and state rankings for all areas associated with a profile.
     *
     * Recalculates city_rank for every area term tagged on the profile and
     * state_rank for the profile's primary state term. Purges LiteSpeed cache
     * for all affected listing pages when done.
     *
     * ## OPTIONS
     *
     * --profile=<post_id>
     * : Profile post ID (required)
     *
     * [--dry-run]
     * : Show what would be done without writing any data
     *
     * ## EXAMPLES
     *
     *     wp directory-helpers update-rankings-for-profile --profile=1234
     *     wp directory-helpers update-rankings-for-profile --profile=1234 --dry-run
     *
     * @when after_wp_load
     */
    public function __invoke( $args, $assoc_args ) {

        while ( ob_get_level() ) {
            ob_end_flush();
        }

        // ── Validate profile ──────────────────────────────────────────────────
        $profile_id = isset( $assoc_args['profile'] ) ? (int) $assoc_args['profile'] : 0;
        if ( ! $profile_id ) {
            WP_CLI::error( '--profile=<post_id> is required.' );
            return;
        }

        $post = get_post( $profile_id );
        if ( ! $post || $post->post_type !== 'profile' || $post->post_status !== 'publish' ) {
            WP_CLI::error( "Post {$profile_id} is not a published profile." );
            return;
        }

        $dry_run = isset( $assoc_args['dry-run'] );

        WP_CLI::line( "=== Update Rankings for Profile ===" );
        WP_CLI::line( "Profile: [{$profile_id}] " . get_the_title( $profile_id ) );
        WP_CLI::line( "Dry run: " . ( $dry_run ? 'Yes' : 'No' ) );
        WP_CLI::line( '' );

        // ── Gather all area terms on this profile ─────────────────────────────
        $area_terms = get_the_terms( $profile_id, 'area' );
        if ( empty( $area_terms ) || is_wp_error( $area_terms ) ) {
            WP_CLI::error( "Profile {$profile_id} has no area terms." );
            return;
        }

        WP_CLI::line( "Area terms (" . count( $area_terms ) . "):" );
        foreach ( $area_terms as $t ) {
            WP_CLI::line( "  • {$t->name} (ID {$t->term_id}, slug: {$t->slug})" );
        }
        WP_CLI::line( '' );

        // ── All state terms on this profile ───────────────────────────────────
        $state_terms = get_the_terms( $profile_id, 'state' );
        if ( empty( $state_terms ) || is_wp_error( $state_terms ) ) {
            $state_terms = [];
        }

        WP_CLI::line( "State terms (" . count( $state_terms ) . "):" );
        foreach ( $state_terms as $t ) {
            WP_CLI::line( "  • {$t->name} (ID {$t->term_id}, slug: {$t->slug})" );
        }
        WP_CLI::line( '' );

        if ( $dry_run ) {
            WP_CLI::line( "Would recalculate city_rank for:" );
            foreach ( $area_terms as $t ) {
                WP_CLI::line( "  • {$t->name}" );
            }
            if ( ! empty( $state_terms ) ) {
                WP_CLI::line( "Would recalculate state_rank for:" );
                foreach ( $state_terms as $t ) {
                    WP_CLI::line( "  • {$t->name}" );
                }
            }
            WP_CLI::success( 'Dry run complete - no changes made.' );
            return;
        }

        $start_total = microtime( true );
        $affected_city_listing_ids  = [];
        $affected_state_listing_ids = [];

        // ── Recalculate city_rank for every area term ─────────────────────────
        WP_CLI::line( "=== City Rankings ===" );
        foreach ( $area_terms as $area_term ) {
            $t_start = microtime( true );
            WP_CLI::line( "Processing area: {$area_term->name} (ID {$area_term->term_id})" );

            $this->recalculate_city_rank_for_area( $area_term );

            $t_elapsed = round( microtime( true ) - $t_start, 2 );
            WP_CLI::line( "  → city_rank updated in {$t_elapsed}s" );

            // Find the matching city-listing page to purge later.
            $city_listing_ids = get_posts( array(
                'post_type'      => 'city-listing',
                'post_status'    => 'publish',
                'posts_per_page' => 1,
                'fields'         => 'ids',
                'tax_query'      => array(
                    array(
                        'taxonomy' => 'area',
                        'field'    => 'term_id',
                        'terms'    => $area_term->term_id,
                    ),
                ),
            ) );
            if ( ! empty( $city_listing_ids ) ) {
                $affected_city_listing_ids[] = $city_listing_ids[0];
            }
        }
        WP_CLI::line( '' );

        // ── Recalculate state_rank for every state term on the profile ────────
        if ( ! empty( $state_terms ) ) {
            WP_CLI::line( "=== State Rankings ===" );
            foreach ( $state_terms as $state_term ) {
                $t_start = microtime( true );
                WP_CLI::line( "Processing state: {$state_term->name} (ID {$state_term->term_id})" );

                $this->recalculate_state_rank_for_state( $state_term );

                $t_elapsed = round( microtime( true ) - $t_start, 2 );
                WP_CLI::line( "  → state_rank updated in {$t_elapsed}s" );

                // Find the matching state-listing page.
                $state_listing_ids = get_posts( array(
                    'post_type'      => 'state-listing',
                    'post_status'    => 'publish',
                    'posts_per_page' => 1,
                    'fields'         => 'ids',
                    'tax_query'      => array(
                        array(
                            'taxonomy' => 'state',
                            'field'    => 'term_id',
                            'terms'    => $state_term->term_id,
                        ),
                    ),
                ) );
                if ( ! empty( $state_listing_ids ) ) {
                    $affected_state_listing_ids[] = $state_listing_ids[0];
                }
            }
            WP_CLI::line( '' );
        }

        // ── Purge LiteSpeed page cache for affected listing pages ─────────────
        WP_CLI::line( '' );
        WP_CLI::line( "=== Purging Page Cache ===" );

        $purged = 0;
        $all_listing_ids = array_merge( $affected_city_listing_ids, $affected_state_listing_ids );

        foreach ( $all_listing_ids as $listing_id ) {
            do_action( 'litespeed_purge_post', $listing_id );
            $url = get_permalink( $listing_id );
            WP_CLI::line( "  ✓ Purged: [{$listing_id}] " . ( $url ?: '(no URL)' ) );
            $purged++;
        }

        if ( $purged === 0 ) {
            WP_CLI::line( "  No matching listing pages found to purge." );
        }

        // Also purge the profile page itself so rank badges update immediately.
        do_action( 'litespeed_purge_post', $profile_id );
        WP_CLI::line( "  ✓ Purged profile page: [{$profile_id}] " . ( get_permalink( $profile_id ) ?: '' ) );

        $total_elapsed = round( microtime( true ) - $start_total, 2 );
        WP_CLI::line( '' );
        WP_CLI::success( "Done in {$total_elapsed}s. City pages purged: " . count( $affected_city_listing_ids ) . ", state pages purged: " . count( $affected_state_listing_ids ) . "." );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // City rank helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Recalculate city_rank for all profiles tagged with the given area term.
     */
    private function recalculate_city_rank_for_area( WP_Term $area_term ) {
        global $wpdb;

        $profile_ids = $wpdb->get_col( $wpdb->prepare( "
            SELECT DISTINCT p.ID
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
            INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
                AND tt.taxonomy = 'area' AND tt.term_id = %d
            WHERE p.post_type = 'profile'
              AND p.post_status = 'publish'
        ", $area_term->term_id ) );

        if ( empty( $profile_ids ) ) {
            WP_CLI::line( "  No published profiles found for area {$area_term->name}, skipping." );
            return;
        }

        WP_CLI::line( "  " . count( $profile_ids ) . " profiles in pool" );

        DH_Profile_Rankings::recalc_pool( $profile_ids, 'city_rank' );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // State rank helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Recalculate state_rank for profiles whose primary state is the given term.
     */
    private function recalculate_state_rank_for_state( WP_Term $state_term ) {
        global $wpdb;

        // All profiles tagged with this state term (any niche).
        $all_ids = $wpdb->get_col( $wpdb->prepare( "
            SELECT DISTINCT p.ID
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
            INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
                AND tt.taxonomy = 'state' AND tt.term_id = %d
            WHERE p.post_type = 'profile'
              AND p.post_status = 'publish'
        ", $state_term->term_id ) );

        if ( empty( $all_ids ) ) {
            WP_CLI::line( "  No profiles found for state {$state_term->name}, skipping." );
            return;
        }

        // Keep only profiles where this is their PRIMARY state (matches ACF state field).
        $profile_ids = [];
        foreach ( $all_ids as $pid ) {
            $primary = DH_Taxonomy_Helpers::get_primary_state_term( (int) $pid );
            if ( $primary && (int) $primary->term_id === (int) $state_term->term_id ) {
                $profile_ids[] = (int) $pid;
            }
        }

        $excluded = count( $all_ids ) - count( $profile_ids );
        WP_CLI::line( "  " . count( $profile_ids ) . " profiles in pool" . ( $excluded ? " ({$excluded} excluded - not primary state)" : '' ) );

        if ( empty( $profile_ids ) ) {
            WP_CLI::line( "  No profiles have this as primary state, skipping." );
            return;
        }

        DH_Profile_Rankings::recalc_pool( $profile_ids, 'state_rank' );
    }

}
