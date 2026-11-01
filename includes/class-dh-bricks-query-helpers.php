<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Bricks Query Helpers for Directory Profiles
 * 
 * CACHING STRATEGY (IMPLEMENTED):
 * - Cache key format: "dh_nearby_profiles_{area_term_id}_{niche_ids}_{radius}"
 * - Caches the final WP_Query arguments array (with sorted post__in IDs)
 * - TTL: 30 days (2592000 seconds) - long-lived, event-driven invalidation
 * - Cache group: 'directory_helpers'
 * - Uses wp_cache_* functions (Redis Object Cache when available)
 * 
 * AUTOMATIC INVALIDATION:
 * - Profile save/update: Clears cache for all areas the profile is tagged with
 * - Area term meta update: Clears when latitude, longitude, custom_radius, or recommended_radius changes
 * - Manual: Use DH_Bricks_Query_Helpers::clear_proximity_cache()
 */
class DH_Bricks_Query_Helpers {

    /**
     * Bricks element id of the city template's provider-list query loop.
     *
     * The state template's own loop is xvnnyo and is deliberately NOT covered here.
     */
    const CITY_LIST_ELEMENT_ID = 'onimid';

    /**
     * Object cache key (group directory_helpers) for profile_coordinates().
     */
    const COORDINATES_CACHE_KEY = 'dh_profile_coordinates';

    /**
     * Drop Featured profiles from the city page's main trainer grid.
     *
     * Round 17 (2026-09-06): the city page now shows its Featured trainers in a section of their own
     * above the list ("Featured Dog Trainers" band, its own Bricks query: profile + this page's area
     * terms + featured >= 1), so the "All Dog Trainers" grid below it must not repeat them.
     *
     * Only profiles the band actually shows are removed - a profile has to be Featured AND carry one of
     * this page's own `area` terms. The city query also pulls in nearby trainers by proximity, and a
     * Featured trainer from the next town is not in this page's band; dropping it would delete it from
     * the page entirely (caught on the staged run: Miami lost a trainer it never showed as Featured).
     *
     * Scoped to one Bricks element: the city template's provider-list loop. Nothing here touches the
     * band, the filter elements, or the results-count element - the count follows this query, so it
     * reports the grid it heads, filtered or not.
     *
     * post__in is filtered rather than post__not_in added: the nvmbls global query returns an ordered
     * post__in list with orderby=post__in, and WP_Query ignores post__not_in when post__in is set.
     *
     * Not cached: one indexed lookup over the ids already in hand, on a page that is served from
     * full-page cache almost every time. A cache here would need its own invalidation the moment a
     * trainer's `featured` value changed, and would show them twice until it expired.
     *
     * @param array  $query_vars WP_Query args Bricks is about to run.
     * @param array  $settings   The query element's settings.
     * @param string $element_id The Bricks element id running the query.
     * @return array
     */
    public static function exclude_featured_from_city_list( $query_vars, $settings, $element_id ) {
        if ( self::CITY_LIST_ELEMENT_ID !== $element_id ) {
            return $query_vars;
        }
        if ( empty( $query_vars['post__in'] ) || ! is_array( $query_vars['post__in'] ) ) {
            return $query_vars;
        }

        $ids = array_filter( array_map( 'intval', $query_vars['post__in'] ) );
        if ( empty( $ids ) ) {
            return $query_vars;
        }

        // The same terms the band's own tax_query uses ({post_terms_area:plain} on this post).
        $object   = get_queried_object();
        $term_ids = array();
        if ( $object instanceof WP_Term && 'area' === $object->taxonomy ) {
            $term_ids = array( (int) $object->term_id );
        } elseif ( $object instanceof WP_Post ) {
            $terms = wp_get_post_terms( $object->ID, 'area', array( 'fields' => 'ids' ) );
            if ( ! is_wp_error( $terms ) ) {
                $term_ids = array_map( 'intval', $terms );
            }
        }
        if ( empty( $term_ids ) ) {
            return $query_vars;
        }

        global $wpdb;
        $featured = $wpdb->get_col(
            "SELECT DISTINCT m.post_id
             FROM {$wpdb->postmeta} m
             INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = m.post_id
             INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
                 AND tt.taxonomy = 'area'
                 AND tt.term_id IN (" . implode( ',', $term_ids ) . ")
             WHERE m.meta_key = 'featured'
               AND m.post_id IN (" . implode( ',', $ids ) . ")
               AND CAST(m.meta_value AS UNSIGNED) >= 1"
        );
        $featured = array_filter( array_map( 'intval', (array) $featured ) );
        if ( empty( $featured ) ) {
            return $query_vars;
        }

        $remaining = array_values( array_diff( $ids, $featured ) );
        // post__in => [] means "no post__in filter at all" to WP_Query, which would return every
        // profile on the site. A city whose only trainers are Featured gets an empty grid instead.
        $query_vars['post__in'] = ! empty( $remaining ) ? $remaining : array( 0 );

        return $query_vars;
    }

    /**
     * Get query arguments for profiles within a certain radius OR tagged with area term.
     * 
     * Radius Priority (absolute, no expansion):
     * 1. Custom Radius (set manually in area term meta)
     * 2. Recommended Radius (calculated by WP-CLI analyze-radius command)
     * 3. Default City Radius (from plugin settings, default: 5 miles)
     * 
     * Results are sorted by:
     * 1. Area-tagged profiles first (have the area term)
     * 2. Within each group: city_rank (ASC)
     * 3. Within same rank: proximity (closest first)
     * 
     * @param int $radius DEPRECATED - now determined automatically from term meta/settings
     * @return array WP_Query arguments with post__in and orderby
     */
    public static function get_nearby_profiles_query_args( $radius = null ) {
        // Configuration
        $niche_tax = 'niche';
        $area_tax = 'area';
        $city_rank_meta = 'city_rank';
        
        // Get plugin settings
        $options = get_option('directory_helpers_options', []);
        $default_radius = isset($options['default_city_radius']) ? (int) $options['default_city_radius'] : 5;

        // 1. Get Context
        $object = get_queried_object();
        $target_term = null;

        // Determine the target term (Area)
        if ( $object instanceof WP_Term ) {
            $target_term = $object;
        } elseif ( $object instanceof WP_Post ) {
            $terms = get_the_terms( $object->ID, $area_tax );
            if ( ! empty( $terms ) && ! is_wp_error( $terms ) ) {
                // Match area term slug against post slug to avoid confusing cities with same name
                // e.g., milford-nh-dog-trainers should use milford-nh, not milford-ct
                $post_slug = $object->post_name;
                $target_term = $terms[0]; // fallback
                foreach ( $terms as $term ) {
                    if ( strpos( $post_slug, $term->slug ) !== false ) {
                        $target_term = $term;
                        break;
                    }
                }
            }
        }

        // Get Niche from Bricks dynamic data
        $niche_ids = [];
        if ( function_exists( 'bricks_render_dynamic_data' ) ) {
             $niche_string = bricks_render_dynamic_data('{post_terms_niche:term_id:plain}');
             $niche_ids = !empty($niche_string) ? explode(',', $niche_string) : [];
        }

        // Safety checks
        if ( ! $target_term || ! isset( $target_term->term_id ) || empty( $niche_ids ) ) {
            return [ 'post__in' => [0] ];
        }
        
        // 1. Determine radius to use
        $radius = $default_radius; // Default fallback from settings
        $custom_radius = get_term_meta( $target_term->term_id, 'custom_radius', true );
        $recommended_radius = get_term_meta( $target_term->term_id, 'recommended_radius', true );
        
        if ( $custom_radius ) {
            $radius = intval( $custom_radius );
        } elseif ( $recommended_radius ) {
            $radius = intval( $recommended_radius );
        }
        
        // 2. Check cache before expensive queries
        $niche_ids_str = implode( '_', array_map( 'intval', $niche_ids ) );
        $cache_key = "dh_nearby_profiles_{$target_term->term_id}_{$niche_ids_str}_{$radius}";
        $cached_result = wp_cache_get( $cache_key, 'directory_helpers' );
        
        if ( false !== $cached_result && is_array( $cached_result ) ) {
            return $cached_result;
        }

        global $wpdb;
        
        // 2. Get area term match results (all profiles tagged with this area)
        $area_query = new WP_Query([
            'post_type' => 'profile',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'tax_query' => [
                'relation' => 'AND',
                [
                    'taxonomy' => $area_tax,
                    'field' => 'term_id',
                    'terms' => $target_term->term_id,
                ],
                [
                    'taxonomy' => $niche_tax,
                    'field' => 'term_id',
                    'terms' => $niche_ids,
                ]
            ]
        ]);

        // 3. Get proximity results (profiles within radius with coordinates)
        $proximity_data = [];
        $city_lat = get_term_meta( $target_term->term_id, 'latitude', true );
        $city_lng = get_term_meta( $target_term->term_id, 'longitude', true );

        if ( $city_lat && $city_lng && ! empty( $niche_ids ) ) {
            $proximity_data = self::nearby_profile_distances( $city_lat, $city_lng, $niche_ids, $radius );
        }

        // 4. Merge results (proximity OR area term)
        $all_post_ids = array_unique( array_merge( array_keys( $proximity_data ), $area_query->posts ) );

        if ( empty( $all_post_ids ) ) {
            return [ 'post__in' => [0] ];
        }

        // Track which profiles have the area term (for prioritization)
        $area_tagged_ids = $area_query->posts;

        // 6. Fetch city_rank and state_rank for all posts
        $rank_sql = $wpdb->prepare( "
            SELECT post_id, meta_key, meta_value 
            FROM {$wpdb->postmeta} 
            WHERE post_id IN (" . implode(',', array_map('intval', $all_post_ids)) . ")
            AND meta_key IN (%s, %s)
        ", $city_rank_meta, 'state_rank' );
        
        $rank_results = $wpdb->get_results( $rank_sql );
        $city_ranks = [];
        $state_ranks = [];
        foreach ( $rank_results as $row ) {
            if ( $row->meta_key === $city_rank_meta ) {
                $city_ranks[ $row->post_id ] = (int) $row->meta_value;
            } elseif ( $row->meta_key === 'state_rank' ) {
                $state_ranks[ $row->post_id ] = (int) $row->meta_value;
            }
        }

        // 7. Build sortable data structure
        $profiles = [];
        foreach ( $all_post_ids as $post_id ) {
            $profiles[] = [
                'id' => $post_id,
                'has_area_term' => in_array( $post_id, $area_tagged_ids ),
                'city_rank' => isset( $city_ranks[ $post_id ] ) ? $city_ranks[ $post_id ] : 999999,
                'state_rank' => isset( $state_ranks[ $post_id ] ) ? $state_ranks[ $post_id ] : 999999,
                'distance' => isset( $proximity_data[ $post_id ] ) ? $proximity_data[ $post_id ] : 999999,
            ];
        }

        // 8. Sort: Area-tagged profiles first (by city_rank), then proximity profiles (by state_rank)
        usort( $profiles, function( $a, $b ) {
            // Primary: Area term match (true before false)
            if ( $a['has_area_term'] !== $b['has_area_term'] ) {
                return $b['has_area_term'] - $a['has_area_term'];
            }
            
            // For area-tagged profiles: sort by city_rank (unique within city)
            if ( $a['has_area_term'] && $b['has_area_term'] ) {
                return $a['city_rank'] - $b['city_rank'];
            }
            
            // For proximity profiles: sort by state_rank (unique within state)
            return $a['state_rank'] - $b['state_rank'];
        });

        $sorted_ids = wp_list_pluck( $profiles, 'id' );

        // 9. Build final query args
        $query_args = [
            'post_type' => 'profile',
            'post__in'  => $sorted_ids,
            'orderby'   => 'post__in',
            'posts_per_page' => -1,
        ];
        
        // 10. Cache the result (30 days = 2592000 seconds)
        wp_cache_set( $cache_key, $query_args, 'directory_helpers', 2592000 );
        
        return $query_args;
    }
    
    /**
     * Published profiles in the given niches within $radius miles of a point, as
     * [ profile ID => miles ] in ascending ID order.
     *
     * Distances are computed in PHP from profile_coordinates(). The old single SQL
     * join read each profile's coordinates through the post_id index and took ~18s
     * on every uncached city page.
     *
     * @param float $lat       Point latitude (degrees)
     * @param float $lng       Point longitude (degrees)
     * @param int[] $niche_ids Niche term IDs
     * @param int   $radius    Miles
     * @return array<int, float>
     */
    public static function nearby_profile_distances( $lat, $lng, $niche_ids, $radius ) {
        global $wpdb;

        $niche_ids_sql = implode( ',', array_map( 'intval', $niche_ids ) );
        $candidate_ids = $wpdb->get_col( "
            SELECT DISTINCT p.ID
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
            INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
                AND tt.taxonomy = 'niche' AND tt.term_id IN ({$niche_ids_sql})
            WHERE p.post_type = 'profile'
            AND p.post_status = 'publish'
            ORDER BY p.ID
        " );

        $coords = self::profile_coordinates();
        $lat_r  = deg2rad( (float) $lat );
        $lng_r  = deg2rad( (float) $lng );

        $distances = [];
        foreach ( $candidate_ids as $id ) {
            $id = (int) $id;
            if ( ! isset( $coords[ $id ] ) ) {
                continue;
            }
            $p_lat = deg2rad( $coords[ $id ][0] );
            $cos_d = cos( $lat_r ) * cos( $p_lat ) * cos( deg2rad( $coords[ $id ][1] ) - $lng_r ) + sin( $lat_r ) * sin( $p_lat );
            $miles = 3959 * acos( max( -1, min( 1, $cos_d ) ) );
            if ( $miles < $radius ) {
                $distances[ $id ] = $miles;
            }
        }
        return $distances;
    }

    /**
     * Latitude and longitude of every post that has both, as [ post ID => [ lat, lng ] ].
     *
     * Two meta_key index scans (~1s), cached for a day and cleared by
     * clear_coordinates_on_meta_change() whenever a latitude or longitude changes.
     *
     * @return array<int, float[]>
     */
    private static function profile_coordinates() {
        $coords = wp_cache_get( self::COORDINATES_CACHE_KEY, 'directory_helpers' );
        if ( is_array( $coords ) ) {
            return $coords;
        }

        global $wpdb;
        $lats = [];
        foreach ( $wpdb->get_results( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = 'latitude'", ARRAY_N ) as $row ) {
            $lats[ (int) $row[0] ] = (float) $row[1];
        }
        $coords = [];
        foreach ( $wpdb->get_results( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = 'longitude'", ARRAY_N ) as $row ) {
            $id = (int) $row[0];
            if ( isset( $lats[ $id ] ) ) {
                $coords[ $id ] = [ $lats[ $id ], (float) $row[1] ];
            }
        }

        wp_cache_set( self::COORDINATES_CACHE_KEY, $coords, 'directory_helpers', DAY_IN_SECONDS );
        return $coords;
    }

    /**
     * added/updated/deleted_post_meta listener: drop the coordinate cache when a
     * latitude or longitude changes.
     *
     * @param int|int[] $meta_id
     * @param int       $object_id
     * @param string    $meta_key
     */
    public static function clear_coordinates_on_meta_change( $meta_id, $object_id, $meta_key ) {
        if ( 'latitude' === $meta_key || 'longitude' === $meta_key ) {
            wp_cache_delete( self::COORDINATES_CACHE_KEY, 'directory_helpers' );
        }
    }

    /**
     * Clear proximity cache for a specific area and niche combination
     * 
     * @param int $area_term_id Area term ID
     * @param array $niche_ids Array of niche term IDs (optional, clears all if empty)
     */
    public static function clear_proximity_cache( $area_term_id, $niche_ids = [] ) {
        // If specific niches provided, clear those
        if ( ! empty( $niche_ids ) ) {
            $radii = [ 2, 5, 8, 10, 15, 20, 25, 30 ]; // Common radii to clear
            foreach ( $radii as $radius ) {
                $niche_ids_str = implode( '_', array_map( 'intval', $niche_ids ) );
                $cache_key = "dh_nearby_profiles_{$area_term_id}_{$niche_ids_str}_{$radius}";
                wp_cache_delete( $cache_key, 'directory_helpers' );
            }
        } else {
            // Every niche, every common radius plus this area's own radii; never a Redis-wide flush.
            $niches = get_terms( array( 'taxonomy' => 'niche', 'hide_empty' => false, 'fields' => 'ids' ) );
            $radii  = array( 2, 5, 8, 10, 15, 20, 25, 30 );
            foreach ( array( 'custom_radius', 'recommended_radius' ) as $key ) {
                $r = (int) get_term_meta( $area_term_id, $key, true );
                if ( $r > 0 ) {
                    $radii[] = $r;
                }
            }
            foreach ( is_array( $niches ) ? $niches : array() as $niche_id ) {
                foreach ( array_unique( $radii ) as $radius ) {
                    wp_cache_delete( "dh_nearby_profiles_{$area_term_id}_" . (int) $niche_id . "_{$radius}", 'directory_helpers' );
                }
            }
        }
    }
}

add_filter( 'bricks/posts/query_vars', array( 'DH_Bricks_Query_Helpers', 'exclude_featured_from_city_list' ), 20, 3 );
add_action( 'added_post_meta', array( 'DH_Bricks_Query_Helpers', 'clear_coordinates_on_meta_change' ), 10, 3 );
add_action( 'updated_post_meta', array( 'DH_Bricks_Query_Helpers', 'clear_coordinates_on_meta_change' ), 10, 3 );
add_action( 'deleted_post_meta', array( 'DH_Bricks_Query_Helpers', 'clear_coordinates_on_meta_change' ), 10, 3 );
