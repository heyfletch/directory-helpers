<?php
/**
 * Helper method to analyze radius for a city
 * Extracted from CLI command for use in CPQ
 */

if (!defined('ABSPATH')) {
    exit;
}

function dh_analyze_radius_for_city($city_slug, $niche_id) {
    global $wpdb;
    
    // Get the area term
    $area_term = get_term_by('slug', $city_slug, 'area');
    if (!$area_term || is_wp_error($area_term)) {
        error_log('DH CPQ: analyze_radius - area term not found for ' . $city_slug);
        return false;
    }
    
    // Get coordinates
    $lat = get_term_meta($area_term->term_id, 'latitude', true);
    $lng = get_term_meta($area_term->term_id, 'longitude', true);
    
    if (!$lat || !$lng) {
        error_log('DH CPQ: analyze_radius - no coordinates found for ' . $city_slug);
        return false;
    }
    
    // Get settings
    $options = get_option('directory_helpers_options', array());
    $min_profiles = isset($options['min_profiles_threshold']) ? intval($options['min_profiles_threshold']) : 10;
    $max_radius = 30;
    
    // Check direct area-tagged profiles
    $area_count_query = new WP_Query(array(
        'post_type' => 'profile',
        'post_status' => 'publish',
        'posts_per_page' => -1,
        'tax_query' => array(
            'relation' => 'AND',
            array('taxonomy' => 'area', 'field' => 'term_id', 'terms' => $area_term->term_id),
            array('taxonomy' => 'niche', 'field' => 'term_id', 'terms' => $niche_id),
        ),
        'fields' => 'ids',
    ));
    $area_count = $area_count_query->found_posts;
    
    // If sufficient, set radius to 1
    if ($area_count >= $min_profiles) {
        update_term_meta($area_term->term_id, 'recommended_radius', 1);
        error_log('DH CPQ: analyze_radius - sufficient profiles (' . $area_count . '), radius set to 1');
        return true;
    }
    
    // Find optimal radius
    $radius = 2;
    $recommended_radius = null;
    $radius_increment = 3;
    
    while ($radius <= $max_radius) {
        // Bounding box approximation
        $lat_offset = $radius / 69.0;
        $lng_offset = $radius / (69.0 * cos(deg2rad($lat)));
        $lat_min = $lat - $lat_offset;
        $lat_max = $lat + $lat_offset;
        $lng_min = $lng - $lng_offset;
        $lng_max = $lng + $lng_offset;
        
        // Count proximity profiles (excluding area-tagged)
        $sql = $wpdb->prepare("
            SELECT COUNT(DISTINCT p.ID) as total
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} lat ON p.ID = lat.post_id AND lat.meta_key = 'latitude'
            INNER JOIN {$wpdb->postmeta} lng ON p.ID = lng.post_id AND lng.meta_key = 'longitude'
            INNER JOIN {$wpdb->term_relationships} tr_niche ON p.ID = tr_niche.object_id
            INNER JOIN {$wpdb->term_taxonomy} tt_niche ON tr_niche.term_taxonomy_id = tt_niche.term_taxonomy_id 
                AND tt_niche.taxonomy = 'niche' AND tt_niche.term_id = %d
            WHERE p.post_type = 'profile'
            AND p.post_status = 'publish'
            AND CAST(lat.meta_value AS DECIMAL(10,6)) BETWEEN %f AND %f
            AND CAST(lng.meta_value AS DECIMAL(10,6)) BETWEEN %f AND %f
            AND p.ID NOT IN (
                SELECT tr.object_id 
                FROM {$wpdb->term_relationships} tr
                INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
                WHERE tt.taxonomy = 'area' AND tt.term_id = %d
            )
        ", $niche_id, $lat_min, $lat_max, $lng_min, $lng_max, $area_term->term_id);
        
        $proximity_count = intval($wpdb->get_var($sql));
        $estimated_total = $area_count + $proximity_count;
        
        if ($estimated_total >= $min_profiles) {
            $recommended_radius = $radius;
            break;
        }
        
        $radius += $radius_increment;
        
        // Use larger increments as we go higher
        if ($radius > 15) {
            $radius_increment = 5;
        }
    }
    
    // Update radius meta
    if ($recommended_radius) {
        update_term_meta($area_term->term_id, 'recommended_radius', $recommended_radius);
        error_log('DH CPQ: analyze_radius - set radius to ' . $recommended_radius . ' miles');
    } else {
        update_term_meta($area_term->term_id, 'recommended_radius', $max_radius);
        error_log('DH CPQ: analyze_radius - set radius to ' . $max_radius . ' miles (max)');
    }
    
    return true;
}
