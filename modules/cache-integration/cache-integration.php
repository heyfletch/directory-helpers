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
        // Handler to warm a URL via WP-Cron fallback
        add_action( 'dh_warm_url', [ $this, 'warm_url_handler' ], 10, 1 );
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
}
