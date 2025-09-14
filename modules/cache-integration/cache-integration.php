<?php
/**
 * LiteSpeed Cache integration: on a city's first publish, purge the related state-listing page
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DH_LSCache_Integration {
    public function __construct() {
        // Only trigger on first transition to publish
        add_action( 'transition_post_status', [ $this, 'maybe_purge_on_first_publish' ], 10, 3 );
        // Also handle first publish for state-listing to purge all its cities
        add_action( 'transition_post_status', [ $this, 'maybe_purge_cities_on_state_first_publish' ], 10, 3 );
        // Handler to warm a URL via WP-Cron fallback
        add_action( 'dh_warm_url', [ $this, 'warm_url_handler' ], 10, 1 );
        // Prime when LSCache is asked to purge a specific post or URL
        add_action( 'litespeed_purge_post', [ $this, 'on_lscache_purge_post' ], 999, 1 );
        add_action( 'litespeed_purge_url', [ $this, 'on_lscache_purge_url' ], 999, 1 );
        // Prime on publish and subsequent updates (as long as status is publish) for all post types
        add_action( 'transition_post_status', [ $this, 'prime_on_publish_or_update' ], 11, 3 );
    }

    /**
     * On first publish of a city-listing, purge the corresponding state-listing page.
     *
     * @param string  $new_status
     * @param string  $old_status
     * @param WP_Post $post
     */
    public function maybe_purge_on_first_publish( $new_status, $old_status, $post ) {
        if ( $new_status !== 'publish' || $old_status === 'publish' ) {
            return; // Only first publish
        }
        if ( ! $post || $post->post_type !== 'city-listing' ) {
            return;
        }

        // Derive the 2-letter state code from the city slug pattern:
        // e.g., city-name-PA-dog-trainers -> "pa"
        $slug = isset( $post->post_name ) ? (string) $post->post_name : '';
        if ( ! $slug || ! preg_match( '/^(.+)-([a-z]{2})-dog-trainers$/', $slug, $m ) ) {
            return; // Can't determine state; nothing to purge
        }
        $state_slug = strtolower( $m[2] );

        // Find the state-listing post that has the matching 'state' taxonomy term (by slug)
        $q = new WP_Query( [
            'post_type'      => 'state-listing',
            'post_status'    => 'publish',
            'posts_per_page' => 1,
            'no_found_rows'  => true,
            'tax_query'      => [
                [
                    'taxonomy' => 'state',
                    'field'    => 'slug',
                    'terms'    => $state_slug,
                ],
            ],
        ] );
        if ( ! $q->have_posts() ) {
            return;
        }
        $state_post = $q->posts[0];

        // Purge the state listing page (preferred: by post ID, and also explicit URL for safety)
        do_action( 'litespeed_purge_post', (int) $state_post->ID );
        $state_url = get_permalink( $state_post );
        if ( $state_url ) {
            do_action( 'litespeed_purge_url', esc_url_raw( $state_url ) );
            // Prime the cache for the state page (non-blocking immediate request + cron fallback)
            $this->prime_cache_url( $state_url );
        }

        // Also purge all published profiles in this city (match by 'area' term assigned to the city)
        $this->purge_profiles_for_city( $post );
    }

    /**
     * Fire-and-forget request to warm a URL and schedule a cron fallback.
     *
     * @param string $url
     * @return void
     */
    private function prime_cache_url( $url ) {
        $url = esc_url_raw( (string) $url );
        if ( ! $url ) { return; }

        // In-request dedupe to avoid duplicate immediate warms
        static $warmed = [];
        $key = md5( $url );
        if ( isset( $warmed[ $key ] ) ) { return; }
        $warmed[ $key ] = true;

        // Attempt immediate non-blocking warm
        $args = [
            'timeout'     => 5,
            'redirection' => 3,
            'blocking'    => false, // do not block the publish flow
            'headers'     => [
                'User-Agent' => 'DHCacheWarmer/1.0 (+ ' . home_url( '/' ) . ')',
                'Accept'     => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            ],
        ];
        // Suppress errors; we don't need the body here
        try {
            wp_remote_get( $url, $args );
        } catch ( \Exception $e ) { /* no-op */ }

        // Schedule a fallback warm shortly after (in case non-blocking transport is unavailable)
        if ( ! wp_next_scheduled( 'dh_warm_url', [ $url ] ) ) {
            wp_schedule_single_event( time() + 30, 'dh_warm_url', [ $url ] );
        }
    }

    /**
     * WP-Cron handler to warm a URL with a normal blocking request (fallback).
     *
     * @param string $url
     * @return void
     */
    public function warm_url_handler( $url ) {
        $url = esc_url_raw( (string) $url );
        if ( ! $url ) { return; }
        $args = [
            'timeout'     => 10,
            'redirection' => 3,
            'headers'     => [
                'User-Agent' => 'DHCacheWarmer/1.0 (+ ' . home_url( '/' ) . ')',
                'Accept'     => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            ],
        ];
        try {
            wp_remote_get( $url, $args );
        } catch ( \Exception $e ) { /* no-op */ }
    }

    /**
     * Purge all published profiles that belong to the same 'area' as the given city post.
     *
     * @param WP_Post $city_post
     * @return void
     */
    private function purge_profiles_for_city( $city_post ) {
        $city_post = is_object( $city_post ) ? $city_post : get_post( $city_post );
        if ( ! $city_post || $city_post->post_type !== 'city-listing' ) { return; }
        $area_terms = get_the_terms( $city_post->ID, 'area' );
        if ( ! is_array( $area_terms ) || empty( $area_terms ) ) { return; }
        $area_ids = wp_list_pluck( $area_terms, 'term_id' );
        $area_ids = array_values( array_filter( array_map( 'intval', $area_ids ) ) );
        if ( empty( $area_ids ) ) { return; }

        $q = new WP_Query( [
            'post_type'      => 'profile',
            'post_status'    => 'publish',
            'fields'         => 'ids',
            'posts_per_page' => -1,
            'no_found_rows'  => true,
            'tax_query'      => [
                [
                    'taxonomy' => 'area',
                    'field'    => 'term_id',
                    'terms'    => $area_ids,
                ],
            ],
        ] );
        if ( empty( $q->posts ) ) { return; }
        foreach ( $q->posts as $pid ) {
            do_action( 'litespeed_purge_post', (int) $pid );
            $u = get_permalink( $pid );
            if ( $u ) { do_action( 'litespeed_purge_url', esc_url_raw( $u ) ); }
        }
    }

    /**
     * On first publish of a state-listing, purge all published city-listing pages that belong to that state.
     * Uses the 'state' taxonomy slug (2-letter code) to match city post_name suffix "-{code}-dog-trainers".
     *
     * @param string  $new_status
     * @param string  $old_status
     * @param WP_Post $post
     * @return void
     */
    public function maybe_purge_cities_on_state_first_publish( $new_status, $old_status, $post ) {
        if ( $new_status !== 'publish' || $old_status === 'publish' ) { return; }
        if ( ! $post || $post->post_type !== 'state-listing' ) { return; }
        $state_terms = get_the_terms( $post->ID, 'state' );
        if ( ! is_array( $state_terms ) || empty( $state_terms ) ) { return; }
        $state_slug = strtolower( (string) $state_terms[0]->slug );
        if ( ! $state_slug || strlen( $state_slug ) !== 2 ) { return; }

        global $wpdb;
        $like = $wpdb->esc_like( '-' . $state_slug . '-dog-trainers' );
        $sql  = $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish' AND post_name LIKE %s",
            'city-listing',
            '%' . $like
        );
        $city_ids = $wpdb->get_col( $sql );
        if ( empty( $city_ids ) ) { return; }
        foreach ( $city_ids as $cid ) {
            do_action( 'litespeed_purge_post', (int) $cid );
            $cu = get_permalink( $cid );
            if ( $cu ) { do_action( 'litespeed_purge_url', esc_url_raw( $cu ) ); }
        }
    }

    /**
     * Listener: when LSCache is asked to purge a post ID, prime that post's URL if published.
     *
     * @param int $post_id
     * @return void
     */
    public function on_lscache_purge_post( $post_id ) {
        $post_id = (int) $post_id;
        if ( ! $post_id ) { return; }
        $post = get_post( $post_id );
        if ( ! $post || $post->post_status !== 'publish' ) { return; }
        $url = get_permalink( $post );
        if ( $url ) { $this->prime_cache_url( $url ); }
    }

    /**
     * Listener: when LSCache is asked to purge a URL, prime it if it maps to a published post.
     *
     * @param string $url
     * @return void
     */
    public function on_lscache_purge_url( $url ) {
        $url = esc_url_raw( (string) $url );
        if ( ! $url ) { return; }
        // Limit to same host
        $home_host = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
        $url_host  = wp_parse_url( $url, PHP_URL_HOST );
        if ( ! $home_host || ! $url_host || strcasecmp( $home_host, $url_host ) !== 0 ) { return; }
        $maybe_id = url_to_postid( $url );
        if ( $maybe_id ) {
            $post = get_post( $maybe_id );
            if ( $post && $post->post_status === 'publish' ) {
                $this->prime_cache_url( $url );
            }
        }
    }

    /**
     * Prime the post's own URL whenever a post becomes or remains published after an update.
     * Applies to all post types.
     *
     * @param string  $new_status
     * @param string  $old_status
     * @param WP_Post $post
     * @return void
     */
    public function prime_on_publish_or_update( $new_status, $old_status, $post ) {
        if ( ! $post ) { return; }
        if ( $new_status !== 'publish' ) { return; }
        // Avoid autosaves/revisions
        if ( wp_is_post_revision( $post->ID ) || wp_is_post_autosave( $post->ID ) ) { return; }
        $url = get_permalink( $post );
        if ( $url ) { $this->prime_cache_url( $url ); }
    }
}
