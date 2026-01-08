<?php
/**
 * Taxonomy Helper Functions
 *
 * Shared utility methods for handling taxonomy terms across modules
 *
 * @package Directory_Helpers
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Taxonomy Helpers class
 */
class DH_Taxonomy_Helpers {
    /**
     * Get primary area term for a profile
     * 
     * When a profile has multiple area taxonomy terms, this method determines
     * which one is the "primary" city by matching against the ACF 'city' field.
     * 
     * Logic:
     * 1. If only one area term exists, return it
     * 2. If multiple area terms exist, match against ACF 'city' field
     * 3. Fallback to first term if no match found
     *
     * @param int $post_id Profile post ID
     * @return WP_Term|false Primary area term or false if none found
     */
    public static function get_primary_area_term($post_id) {
        $area_terms = get_the_terms($post_id, 'area');
        if (empty($area_terms) || is_wp_error($area_terms)) {
            return false;
        }
        
        // Single term - no ambiguity
        if (count($area_terms) === 1) {
            return $area_terms[0];
        }
        
        // Multiple terms - use ACF city field to determine primary
        $acf_city = get_field('city', $post_id);
        if ($acf_city && is_string($acf_city)) {
            $acf_city_clean = strtolower(trim($acf_city));
            
            foreach ($area_terms as $term) {
                $term_name_clean = strtolower($term->name);
                // Check if term name starts with ACF city value
                // e.g., ACF city "Milwaukee" matches "milwaukee-wi" or "Milwaukee - WI"
                if (strpos($term_name_clean, $acf_city_clean) === 0) {
                    return $term;
                }
            }
        }
        
        // Fallback to first term (alphabetical by term_id)
        return $area_terms[0];
    }
    
    /**
     * Get primary state term for a profile
     * 
     * When a profile has multiple state taxonomy terms, this method determines
     * which one is the "primary" state by matching against the ACF 'state' field.
     * 
     * Logic:
     * 1. If only one state term exists, return it
     * 2. If multiple state terms exist, match against ACF 'state' field
     * 3. Fallback to first term if no match found
     *
     * @param int $post_id Profile post ID
     * @return WP_Term|false Primary state term or false if none found
     */
    public static function get_primary_state_term($post_id) {
        $state_terms = get_the_terms($post_id, 'state');
        if (empty($state_terms) || is_wp_error($state_terms)) {
            return false;
        }
        
        // Single term - no ambiguity
        if (count($state_terms) === 1) {
            return $state_terms[0];
        }
        
        // Multiple terms - use ACF state field to determine primary
        $acf_state = get_field('state', $post_id);
        if ($acf_state && is_string($acf_state)) {
            $acf_state_clean = strtolower(trim($acf_state));
            
            foreach ($state_terms as $term) {
                // Check term description (full state name) first
                if (!empty($term->description)) {
                    $desc_clean = strtolower($term->description);
                    if ($desc_clean === $acf_state_clean || strpos($desc_clean, $acf_state_clean) === 0) {
                        return $term;
                    }
                }
                
                // Check term name (may be abbreviation or full name)
                $term_name_clean = strtolower($term->name);
                if ($term_name_clean === $acf_state_clean || strpos($term_name_clean, $acf_state_clean) === 0) {
                    return $term;
                }
            }
        }
        
        // Fallback to first term
        return $state_terms[0];
    }
    
    /**
     * Get city name for a profile
     * 
     * @param int $post_id Profile post ID
     * @param bool $strip_state Whether to remove " - ST" suffix (default: true)
     * @return string City name or empty string
     */
    public static function get_city_name($post_id, $strip_state = true) {
        $area_term = self::get_primary_area_term($post_id);
        if (!$area_term) {
            return '';
        }
        
        $city_name = $area_term->name;
        
        // Strip " - ST" suffix if requested
        if ($strip_state) {
            $city_name = preg_replace('/\s+-\s+[A-Za-z]{2}$/', '', $city_name);
            $city_name = trim($city_name);
        }
        
        return $city_name;
    }
    
    /**
     * Get state name for a profile
     * 
     * Uses get_primary_state_term() to determine the correct state when
     * a profile has multiple state terms assigned.
     * 
     * @param int $post_id Profile post ID
     * @param string $format 'full' for full name, 'abbr' for abbreviation
     * @return string State name or empty string
     */
    public static function get_state_name($post_id, $format = 'full') {
        $state_term = self::get_primary_state_term($post_id);
        if (!$state_term) {
            return '';
        }
        
        if ($format === 'abbr') {
            // Return abbreviation (term name or slug uppercase)
            $abbr = !empty($state_term->name) ? $state_term->name : $state_term->slug;
            return strtoupper($abbr);
        }
        
        // Return full name (description if available, else name)
        return !empty($state_term->description) 
            ? $state_term->description 
            : $state_term->name;
    }
    
    /**
     * Get niche name for a profile
     * 
     * @param int $post_id Profile post ID
     * @param bool $plural Whether to pluralize the name
     * @return string Niche name or empty string
     */
    public static function get_niche_name($post_id, $plural = false) {
        $niche_terms = get_the_terms($post_id, 'niche');
        if (empty($niche_terms) || is_wp_error($niche_terms)) {
            return '';
        }
        
        $niche_name = $niche_terms[0]->name;
        
        // Simple pluralization if requested
        if ($plural) {
            // Basic rules (can be enhanced later)
            if (substr($niche_name, -2) === 'er') {
                $niche_name .= 's'; // "Trainer" -> "Trainers"
            } elseif (substr($niche_name, -1) !== 's') {
                $niche_name .= 's'; // Generic pluralization
            }
        }
        
        return $niche_name;
    }
}
