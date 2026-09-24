<?php
/**
 * Sitemap freshness and completeness, enforced in one place.
 *
 * 1. Lastmod follows content, whoever writes it. Rank Math's <lastmod> is post_modified,
 *    and update_field() / update_post_meta() never touch it. Instead of every writer
 *    remembering DH_IndexNow_Helper::refresh_and_submit() (the Sep 16 fix, which Featured
 *    billing and every future script could still miss), this watches the writes themselves:
 *    - a displayed ACF field changes on a published profile or listing -> that page;
 *    - a profile's Featured value or rating changes, it is published or unpublished, or its
 *      area/state terms change -> the city and state pages it appears on.
 *    Everything collected in a request is flushed once at shutdown, so a bulk run sends one
 *    IndexNow batch. Pages stamped in the last minute are skipped, so a normal editor save or
 *    a writer that already called refresh_and_submit() is not bumped or submitted twice.
 *
 * 2. Every page appears in the sitemap exactly once. Rank Math pages each sitemap with
 *    ORDER BY p.post_modified DESC LIMIT/OFFSET and no tiebreaker. Thousands of posts share a
 *    post_modified second, so tied rows shuffled between pages: on 2026-09-24 ~1,340 city
 *    pages and ~1,950 profiles were listed twice or not at all. Adding p.ID makes the order
 *    total. If Rank Math rewrites that SQL the filter stops matching; goodydoggy-sitemap-check
 *    counts duplicates and missing pages daily and fails loudly when that happens.
 *
 * @package Directory_Helpers
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DH_Sitemap_Freshness {

    const WATCHED_TYPES = array( 'profile', 'city-listing', 'state-listing' );

    // ACF fields that never show on a page: identifiers, notes, tokens, bookkeeping, and
    // ranking_boost (it reaches the page only through a rank recalculation, whose writers
    // freshen the listings themselves).
    const IGNORED_FIELDS = array(
        'cid',
        'place_id',
        'admin_notes',
        'contact_email_source',
        'contact_email_pending',
        'last_updated_ai',
        'ugc_submitted_date',
        'update_token_hash',
        'update_token_issued',
        'ranking_boost',
    );

    // Profile fields that also change the city and state pages the profile appears on
    // (Featured section, stars, order).
    const ROSTER_FIELDS = array( 'featured', 'rating_value', 'rating_votes_count' );

    const FRESH_SECONDS = 60;

    private static $posts           = array(); // post_id => true: bump these pages.
    private static $roster_profiles = array(); // profile_id => true: bump the listings they appear on.
    private static $roster_tt_ids   = array(); // taxonomy => term_taxonomy_ids whose listings changed.
    private static $fields          = array(); // post_type => array( 'exact' => [], 'prefixes' => [] ).

    public static function init() {
        add_action( 'added_post_meta', array( __CLASS__, 'on_meta' ), 10, 3 );
        add_action( 'updated_post_meta', array( __CLASS__, 'on_meta' ), 10, 3 );
        add_action( 'deleted_post_meta', array( __CLASS__, 'on_meta' ), 10, 3 );
        add_action( 'set_object_terms', array( __CLASS__, 'on_terms' ), 10, 6 );
        add_action( 'transition_post_status', array( __CLASS__, 'on_status' ), 10, 3 );
        add_action( 'shutdown', array( __CLASS__, 'flush' ) );
        add_filter( 'query', array( __CLASS__, 'total_sitemap_order' ) );
    }

    public static function on_meta( $meta_id, $post_id, $meta_key ) {
        // ACF field references and WP internals (_edit_lock, _thumbnail_id...) start with "_".
        if ( '' === $meta_key || '_' === $meta_key[0] ) {
            return;
        }

        $type = get_post_type( $post_id );
        if ( ! in_array( $type, self::WATCHED_TYPES, true ) || 'publish' !== get_post_status( $post_id ) ) {
            return;
        }
        if ( ! self::is_displayed_field( $type, $meta_key ) ) {
            return;
        }

        self::$posts[ $post_id ] = true;
        if ( 'profile' === $type && in_array( $meta_key, self::ROSTER_FIELDS, true ) ) {
            self::$roster_profiles[ $post_id ] = true;
        }
    }

    public static function on_terms( $object_id, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids ) {
        if ( ! in_array( $taxonomy, array( 'area', 'state' ), true ) ) {
            return;
        }
        if ( 'profile' !== get_post_type( $object_id ) || 'publish' !== get_post_status( $object_id ) ) {
            return;
        }

        $new     = array_map( 'intval', (array) $tt_ids );
        $old     = array_map( 'intval', (array) $old_tt_ids );
        $changed = array_diff( $new, $old );
        if ( ! $append ) {
            $changed = array_merge( $changed, array_diff( $old, $new ) );
        }
        if ( empty( $changed ) ) {
            return;
        }

        self::$posts[ $object_id ] = true;
        $known = isset( self::$roster_tt_ids[ $taxonomy ] ) ? self::$roster_tt_ids[ $taxonomy ] : array();
        self::$roster_tt_ids[ $taxonomy ] = array_merge( $known, $changed );
    }

    public static function on_status( $new_status, $old_status, $post ) {
        if ( 'profile' !== $post->post_type || ( 'publish' === $new_status ) === ( 'publish' === $old_status ) ) {
            return;
        }
        // Terms are read at shutdown: on insert they may be set after the status transition.
        self::$roster_profiles[ $post->ID ] = true;
    }

    public static function flush() {
        $posts    = self::$posts;
        $profiles = self::$roster_profiles;
        $tt_ids   = self::$roster_tt_ids;

        self::$posts           = array();
        self::$roster_profiles = array();
        self::$roster_tt_ids   = array();

        if ( empty( $posts ) && empty( $profiles ) && empty( $tt_ids ) ) {
            return;
        }

        $listing_types = array( 'area' => 'city-listing', 'state' => 'state-listing' );

        foreach ( $listing_types as $taxonomy => $listing_type ) {
            $ids = isset( $tt_ids[ $taxonomy ] ) ? $tt_ids[ $taxonomy ] : array();
            if ( ! empty( $profiles ) ) {
                $current = wp_get_object_terms( array_keys( $profiles ), $taxonomy, array( 'fields' => 'tt_ids' ) );
                if ( ! is_wp_error( $current ) ) {
                    $ids = array_merge( $ids, $current );
                }
            }
            if ( empty( $ids ) ) {
                continue;
            }

            $listing_ids = get_posts( array(
                'post_type'      => $listing_type,
                'post_status'    => 'publish',
                'posts_per_page' => -1,
                'fields'         => 'ids',
                'no_found_rows'  => true,
                'tax_query'      => array(
                    array(
                        'taxonomy'         => $taxonomy,
                        'field'            => 'term_taxonomy_id',
                        'terms'            => array_values( array_unique( array_map( 'intval', $ids ) ) ),
                        'include_children' => false,
                    ),
                ),
            ) );
            foreach ( $listing_ids as $listing_id ) {
                $posts[ $listing_id ] = true;
            }
        }

        $cutoff = time() - self::FRESH_SECONDS;
        $stale  = array();
        foreach ( array_keys( $posts ) as $post_id ) {
            $post = get_post( $post_id );
            if ( $post && strtotime( $post->post_modified_gmt . ' UTC' ) < $cutoff ) {
                $stale[] = $post_id;
            }
        }

        if ( ! empty( $stale ) ) {
            DH_IndexNow_Helper::refresh_and_submit( $stale );
        }
    }

    public static function total_sitemap_order( $sql ) {
        if ( false === strpos( $sql, 'ORDER BY p.post_modified DESC LIMIT' ) || false === strpos( $sql, 'rank_math_robots' ) ) {
            return $sql;
        }
        return str_replace( 'ORDER BY p.post_modified DESC LIMIT', 'ORDER BY p.post_modified DESC, p.ID DESC LIMIT', $sql );
    }

    private static function is_displayed_field( $type, $key ) {
        if ( ! isset( self::$fields[ $type ] ) ) {
            // Before ACF has loaded its field groups there is nothing to match against, and
            // caching that empty list would blind the rest of the request.
            if ( ! function_exists( 'acf_get_field_groups' ) || ! did_action( 'acf/init' ) ) {
                return false;
            }
            $exact    = array();
            $prefixes = array();
            foreach ( acf_get_field_groups( array( 'post_type' => $type ) ) as $group ) {
                foreach ( (array) acf_get_fields( $group ) as $field ) {
                    if ( '' === $field['name'] || in_array( $field['name'], self::IGNORED_FIELDS, true ) ) {
                        continue;
                    }
                    $exact[ $field['name'] ] = true;
                    // Sub-fields are stored as <name>_<n>_<sub> (repeater) or <name>_<sub> (group).
                    if ( in_array( $field['type'], array( 'repeater', 'group', 'flexible_content' ), true ) ) {
                        $prefixes[] = $field['name'] . '_';
                    }
                }
            }
            self::$fields[ $type ] = array( 'exact' => $exact, 'prefixes' => $prefixes );
        }

        if ( isset( self::$fields[ $type ]['exact'][ $key ] ) ) {
            return true;
        }
        foreach ( self::$fields[ $type ]['prefixes'] as $prefix ) {
            if ( 0 === strpos( $key, $prefix ) ) {
                return true;
            }
        }
        return false;
    }
}
