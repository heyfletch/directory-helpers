<?php
/**
 * LiteSpeed Cache integration: purge city page on first publish
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DH_LSCache_Integration {
    public function __construct() {
        // Only trigger on first transition to publish
        add_action( 'transition_post_status', [ $this, 'maybe_purge_on_first_publish' ], 10, 3 );
    }

    /**
     * Purge the just-published city-listing page from LiteSpeed Cache on first publish.
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

        // Purge this page using LiteSpeed Cache hooks if available.
        // Equivalent to clicking "Purge this page - LSCache" in the admin bar.
        // Use both post- and URL-based hooks defensively (no-op if LSCache not active).
        do_action( 'litespeed_purge_post', (int) $post->ID );

        $permalink = get_permalink( $post );
        if ( $permalink ) {
            do_action( 'litespeed_purge_url', esc_url_raw( $permalink ) );
        }
    }
}
