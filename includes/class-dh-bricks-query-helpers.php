<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DH_Bricks_Query_Helpers {

    /**
     * Get query arguments for profiles within a certain radius of the current city term.
     * 
     * @param int $radius Radius in miles. Default 20.
     * @return array WP_Query arguments.
     */
    public static function get_nearby_profiles_query_args( $radius = 20 ) {
        // Configuration
        $meta_lat = 'latitude';
        $meta_lng = 'longitude';
        $niche_tax = 'niche';

        // 1. Get Context
        $object = get_queried_object();
        $target_term = null;

        // Determine the target term (Area)
        if ( $object instanceof WP_Term ) {
            // We are on a taxonomy archive
            $target_term = $object;
        } elseif ( $object instanceof WP_Post ) {
            // We are on a post (e.g., city-listing), need to find the attached 'area' term
            $terms = get_the_terms( $object->ID, 'area' );
            if ( ! empty( $terms ) && ! is_wp_error( $terms ) ) {
                $target_term = $terms[0]; // Use the first assigned area term
            }
        }

        // Get Niche from Bricks dynamic data if available
        $niche_ids = [];
        if ( function_exists( 'bricks_render_dynamic_data' ) ) {
             $niche_string = bricks_render_dynamic_data('{post_terms_niche:term_id:plain}');
             $niche_ids = !empty($niche_string) ? explode(',', $niche_string) : [];
        }

        // Safety checks: Must have a valid term and niche(s)
        if ( ! $target_term || ! isset( $target_term->term_id ) ) {
            return [ 'post__in' => [0] ];
        }
        
        if ( empty( $niche_ids ) ) {
            return [ 'post__in' => [0] ];
        }

        $city_lat = get_term_meta( $target_term->term_id, 'latitude', true );
        $city_lng = get_term_meta( $target_term->term_id, 'longitude', true );

        if ( ! $city_lat || ! $city_lng ) {
             return [ 'post__in' => [0] ];
        }

        // 2. SQL for Proximity
        global $wpdb;
        
        $sql = $wpdb->prepare( "
            SELECT $wpdb->posts.ID,
                ( 3959 * acos(
                    cos( radians(%f) ) *
                    cos( radians( lat.meta_value ) ) *
                    cos( radians( lng.meta_value ) - radians(%f) ) +
                    sin( radians(%f) ) *
                    sin( radians( lat.meta_value ) )
                ) ) AS distance
            FROM $wpdb->posts
            INNER JOIN $wpdb->postmeta AS lat ON $wpdb->posts.ID = lat.post_id
            INNER JOIN $wpdb->postmeta AS lng ON $wpdb->posts.ID = lng.post_id
            WHERE 1=1
            AND $wpdb->posts.post_type = 'profile'
            AND $wpdb->posts.post_status = 'publish'
            AND lat.meta_key = %s
            AND lng.meta_key = %s
            HAVING distance < %d
            ORDER BY distance ASC
        ", $city_lat, $city_lng, $city_lat, $meta_lat, $meta_lng, $radius );
        
        $results = $wpdb->get_results( $sql );
        $post_ids = wp_list_pluck( $results, 'ID' );

        if ( empty( $post_ids ) ) {
            return [ 'post__in' => [0] ];
        }

        // 3. Return Args
        return [
            'post_type' => 'profile',
            'post__in'  => $post_ids,
            'orderby'   => 'post__in', // Preserve distance order
            'posts_per_page' => -1,
            'tax_query' => [
                [
                    'taxonomy' => $niche_tax,
                    'field'    => 'term_id',
                    'terms'    => $niche_ids,
                ]
            ]
        ];
    }
}
