<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Bricks Query Helpers for Directory Profiles
 * 
 * CACHING STRATEGY (for future implementation):
 * - Cache key format: "dh_nearby_profiles_{area_term_id}_{niche_id}_{radius}"
 * - Cache the final sorted post IDs array
 * - TTL: 1 hour (3600 seconds)
 * - Invalidate on: profile save/update, area term update, city_rank meta change
 * - Implementation options:
 *   1. Redis Object Cache (wp_cache_get/set) - best performance
 *   2. WordPress Transients (get_transient/set_transient) - native fallback
 */
class DH_Bricks_Query_Helpers {

    /**
     * Get query arguments for profiles within a certain radius OR tagged with area term.
     * Sorted by city_rank (ASC), then proximity.
     * 
     * @param int $radius Radius in miles. Default 20.
     * @return array WP_Query arguments.
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
        $min_threshold = isset($options['min_profiles_threshold']) ? (int) $options['min_profiles_threshold'] : 10;
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

        if ( $city_lat && $city_lng ) {
            // Build niche filter for SQL
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

        // 5. Check if merged results meet threshold; if not, expand radius
        // BUT: Do NOT expand if custom_radius OR recommended_radius is set (explicit values must be respected)
        if ( count( $all_post_ids ) < $min_threshold && $city_lat && $city_lng && ! $custom_radius && ! $recommended_radius ) {
            // Try expanding radius in increments: +5, +10, +15, +20 miles
            $test_radii = [5, 10, 15, 20];
            foreach ( $test_radii as $increment ) {
                $expanded_radius = $radius + $increment;
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
                ", $city_lat, $city_lng, $city_lat, $meta_lat, $meta_lng, $expanded_radius );
                
                $expanded_results = $wpdb->get_results( $sql );
                foreach ( $expanded_results as $row ) {
                    if ( ! isset( $proximity_data[ $row->ID ] ) ) {
                        $proximity_data[ $row->ID ] = (float) $row->distance;
                    }
                }
                
                $all_post_ids = array_unique( array_merge( array_keys( $proximity_data ), $area_query->posts ) );
                
                // Stop expanding if threshold is met
                if ( count( $all_post_ids ) >= $min_threshold ) {
                    break;
                }
            }
        }

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

        // 9. Return query args with sorted IDs
        return [
            'post_type' => 'profile',
            'post__in'  => $sorted_ids,
            'orderby'   => 'post__in',
            'posts_per_page' => -1,
        ];
    }
}
