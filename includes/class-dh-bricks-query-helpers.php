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
        $meta_lat = 'latitude';
        $meta_lng = 'longitude';
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
                $target_term = $terms[0];
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
            // Build niche filter for SQL (already validated as non-empty above, but double-check)
            $niche_ids_sql = implode( ',', array_map( 'intval', $niche_ids ) );
            
            $sql = $wpdb->prepare( "
                SELECT p.ID, 
                    ( 3959 * acos(
                        cos( radians(%f) ) *
                        cos( radians( lat.meta_value ) ) *
                        cos( radians( lng.meta_value ) - radians(%f) ) +
                        sin( radians(%f) ) *
                        sin( radians( lat.meta_value ) )
                    ) ) AS distance
                FROM {$wpdb->posts} p
                INNER JOIN {$wpdb->postmeta} lat ON p.ID = lat.post_id AND lat.meta_key = %s
                INNER JOIN {$wpdb->postmeta} lng ON p.ID = lng.post_id AND lng.meta_key = %s
                INNER JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
                INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id 
                    AND tt.taxonomy = 'niche' AND tt.term_id IN ({$niche_ids_sql})
                WHERE p.post_type = 'profile'
                AND p.post_status = 'publish'
                HAVING distance < %d
            ", $city_lat, $city_lng, $city_lat, $meta_lat, $meta_lng, $radius );
            
            $proximity_results = $wpdb->get_results( $sql );
            foreach ( $proximity_results as $row ) {
                $proximity_data[ $row->ID ] = (float) $row->distance;
            }
        }

        // 4. Merge results (proximity OR area term)
        $all_post_ids = array_unique( array_merge( array_keys( $proximity_data ), $area_query->posts ) );

        if ( empty( $all_post_ids ) ) {
            return [ 'post__in' => [0] ];
        }

        // Track which profiles have the area term (for prioritization)
        $area_tagged_ids = $area_query->posts;

        // 6. Fetch city_rank for all posts
        $rank_sql = $wpdb->prepare( "
            SELECT post_id, meta_value 
            FROM {$wpdb->postmeta} 
            WHERE post_id IN (" . implode(',', array_map('intval', $all_post_ids)) . ")
            AND meta_key = %s
        ", $city_rank_meta );
        
        $rank_results = $wpdb->get_results( $rank_sql );
        $city_ranks = [];
        foreach ( $rank_results as $row ) {
            $city_ranks[ $row->post_id ] = (int) $row->meta_value;
        }

        // 7. Build sortable data structure
        $profiles = [];
        foreach ( $all_post_ids as $post_id ) {
            $profiles[] = [
                'id' => $post_id,
                'has_area_term' => in_array( $post_id, $area_tagged_ids ),
                'city_rank' => isset( $city_ranks[ $post_id ] ) ? $city_ranks[ $post_id ] : 999999,
                'distance' => isset( $proximity_data[ $post_id ] ) ? $proximity_data[ $post_id ] : 999999,
            ];
        }

        // 8. Sort: Area-tagged profiles first, then by city_rank, then by distance
        usort( $profiles, function( $a, $b ) {
            // Primary: Area term match (true before false)
            if ( $a['has_area_term'] !== $b['has_area_term'] ) {
                return $b['has_area_term'] - $a['has_area_term'];
            }
            // Secondary: city_rank (ascending)
            if ( $a['city_rank'] !== $b['city_rank'] ) {
                return $a['city_rank'] - $b['city_rank'];
            }
            // Tertiary: distance (ascending)
            return $a['distance'] <=> $b['distance'];
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
            // Clear all cache for this area (flush entire group would be better but not all object caches support it)
            wp_cache_flush(); // Nuclear option - only use when necessary
        }
    }
}
