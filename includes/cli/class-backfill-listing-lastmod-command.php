<?php
/**
 * WP-CLI Command: Backfill listing lastmod
 *
 * City and state listing posts are never written to when their roster changes - profiles
 * are attached by taxonomy, so the listing's own post_modified has sat frozen since the
 * page was created. That is what Rank Math's sitemap reports as <lastmod>, so thousands of
 * listing pages have been telling Google "unchanged since February" while their rosters
 * turned over repeatedly.
 *
 * This sets each listing's post_modified to the TRUE last-change time of its content: the
 * most recent post_modified among the published profiles in its term. Deliberately not
 * "now" for every page - that would be a false spike of thousands of simultaneous edits.
 * Only ever moves a lastmod forward.
 *
 * Going forward this stays correct on its own: update-rankings-for-profile and
 * refresh-closed both bump the listings they affect.
 *
 * @package Directory_Helpers
 */

if ( ! defined( 'WP_CLI' ) ) {
    return;
}

class DH_Backfill_Listing_Lastmod_Command {

    /**
     * Set city/state listing post_modified to the newest post_modified in their roster.
     *
     * ## OPTIONS
     *
     * [--dry-run]
     * : Report what would move without writing.
     *
     * [--post-type=<type>]
     * : city-listing, state-listing, or both (default: both)
     *
     * [--submit]
     * : Also submit every moved listing URL to IndexNow. Off by default - the backfill is
     *   a correction of historical metadata, not news.
     *
     * ## EXAMPLES
     *
     *     wp directory-helpers backfill-listing-lastmod --dry-run
     *     wp directory-helpers backfill-listing-lastmod
     *     wp directory-helpers backfill-listing-lastmod --post-type=city-listing --submit
     *
     * @when after_wp_load
     */
    public function __invoke( $args, $assoc_args ) {
        global $wpdb;

        $dry_run   = isset( $assoc_args['dry-run'] );
        $submit    = isset( $assoc_args['submit'] );
        $post_type = isset( $assoc_args['post-type'] ) ? $assoc_args['post-type'] : 'both';

        $map = array(
            'city-listing'  => 'area',
            'state-listing' => 'state',
        );
        if ( $post_type !== 'both' ) {
            if ( ! isset( $map[ $post_type ] ) ) {
                WP_CLI::error( "Unknown post type '{$post_type}'. Use city-listing, state-listing, or both." );
            }
            $map = array( $post_type => $map[ $post_type ] );
        }

        WP_CLI::line( '=== Backfill Listing Lastmod ===' );
        WP_CLI::line( 'Dry run: ' . ( $dry_run ? 'Yes' : 'No' ) );
        WP_CLI::line( '' );

        $all_moved = array();

        foreach ( $map as $type => $taxonomy ) {

            // True content age per listing: newest published profile in the same term.
            $rows = $wpdb->get_results( $wpdb->prepare( "
                SELECT l.ID,
                       l.post_title,
                       l.post_modified AS old_mod,
                       x.m             AS new_mod,
                       x.mg            AS new_mod_gmt
                FROM {$wpdb->posts} l
                INNER JOIN {$wpdb->term_relationships} ltr ON ltr.object_id = l.ID
                INNER JOIN {$wpdb->term_taxonomy} ltt
                        ON ltt.term_taxonomy_id = ltr.term_taxonomy_id AND ltt.taxonomy = %s
                INNER JOIN (
                    SELECT tt.term_id,
                           MAX(p.post_modified)     AS m,
                           MAX(p.post_modified_gmt) AS mg
                    FROM {$wpdb->posts} p
                    INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID
                    INNER JOIN {$wpdb->term_taxonomy} tt
                            ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = %s
                    WHERE p.post_type = 'profile' AND p.post_status = 'publish'
                    GROUP BY tt.term_id
                ) x ON x.term_id = ltt.term_id
                WHERE l.post_type = %s
                  AND l.post_status = 'publish'
                  AND x.m > l.post_modified
            ", $taxonomy, $taxonomy, $type ) );

            WP_CLI::line( $type . ': ' . count( $rows ) . ' listings have a stale lastmod.' );

            if ( empty( $rows ) ) {
                continue;
            }

            // Show the extremes so the size of the correction is visible.
            $sample = array_slice( $rows, 0, 3 );
            foreach ( $sample as $r ) {
                WP_CLI::line( "  e.g. [{$r->ID}] {$r->post_title}: {$r->old_mod} -> {$r->new_mod}" );
            }

            if ( $dry_run ) {
                continue;
            }

            foreach ( $rows as $r ) {
                $wpdb->update(
                    $wpdb->posts,
                    array( 'post_modified' => $r->new_mod, 'post_modified_gmt' => $r->new_mod_gmt ),
                    array( 'ID' => (int) $r->ID ),
                    array( '%s', '%s' ),
                    array( '%d' )
                );
                clean_post_cache( (int) $r->ID );
                $all_moved[] = (int) $r->ID;
            }

            // One sitemap cache drop per post type, not per post.
            if ( class_exists( '\\RankMath\\Sitemap\\Cache_Watcher' ) ) {
                \RankMath\Sitemap\Cache_Watcher::invalidate( $type );
            }

            WP_CLI::line( '  Moved ' . count( $rows ) . ' ' . $type . ' lastmods.' );
        }

        if ( $dry_run ) {
            WP_CLI::success( 'Dry run complete - nothing written.' );
            return;
        }

        if ( $submit && ! empty( $all_moved ) && class_exists( 'DH_IndexNow_Helper' ) ) {
            WP_CLI::line( '' );
            $urls = array();
            foreach ( $all_moved as $id ) {
                $u = get_permalink( $id );
                if ( $u ) {
                    $urls[] = $u;
                }
            }
            $res = DH_IndexNow_Helper::submit_urls( $urls );
            WP_CLI::line( 'Submitted ' . count( $urls ) . ' URLs to IndexNow (' . ( ! empty( $res['success'] ) ? 'OK' : 'FAILED' ) . ').' );
        }

        WP_CLI::success( 'Done. ' . count( $all_moved ) . ' listing lastmods corrected.' );
    }
}
