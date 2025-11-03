<?php
/**
 * Taxonomy Display Module
 *
 * Simple shortcodes to display taxonomy term names for profiles
 *
 * @package Directory_Helpers
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Taxonomy Display class
 */
class DH_Taxonomy_Display {
    /**
     * Constructor
     */
    public function __construct() {
        // Register shortcodes
        add_shortcode('dh_city_name', array($this, 'city_name_shortcode'));
        add_shortcode('dh_state_name', array($this, 'state_name_shortcode'));
        add_shortcode('dh_niche_name', array($this, 'niche_name_shortcode'));
    }
    
    /**
     * Get primary area term for a profile
     * Uses ACF 'city' field to determine which area term is primary when multiple exist
     *
     * @param int $post_id Profile post ID
     * @return WP_Term|false Primary area term or false
     */
    private function get_primary_area_term($post_id) {
        $area_terms = get_the_terms($post_id, 'area');
        if (empty($area_terms) || is_wp_error($area_terms)) {
            return false;
        }
        
        // Single term - easy
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
        
        // Fallback to first term
        return $area_terms[0];
    }
    
    /**
     * City name shortcode
     * Displays the city name from area taxonomy, cleaned of state suffix
     *
     * @param array $atts Shortcode attributes
     * @return string City name
     */
    public function city_name_shortcode($atts) {
        // Only show on profile pages by default
        $atts = shortcode_atts(
            array(
                'post_id' => get_the_ID(),
                'strip_state' => 'true', // Remove " - ST" suffix
            ),
            $atts,
            'dh_city_name'
        );
        
        $post_id = absint($atts['post_id']);
        $strip_state = filter_var($atts['strip_state'], FILTER_VALIDATE_BOOLEAN);
        
        if (!$post_id) {
            return '';
        }
        
        $area_term = $this->get_primary_area_term($post_id);
        if (!$area_term) {
            return '';
        }
        
        $city_name = $area_term->name;
        
        // Strip " - ST" suffix if requested
        if ($strip_state) {
            $city_name = preg_replace('/\s+-\s+[A-Za-z]{2}$/', '', $city_name);
            $city_name = trim($city_name);
        }
        
        return esc_html($city_name);
    }
    
    /**
     * State name shortcode
     * Displays the state name from state taxonomy
     * Uses term description if available, otherwise term name
     *
     * @param array $atts Shortcode attributes
     * @return string State name
     */
    public function state_name_shortcode($atts) {
        // Only show on profile pages by default
        $atts = shortcode_atts(
            array(
                'post_id' => get_the_ID(),
                'format' => 'full', // 'full' (Wisconsin) or 'abbr' (WI)
            ),
            $atts,
            'dh_state_name'
        );
        
        $post_id = absint($atts['post_id']);
        $format = sanitize_key($atts['format']);
        
        if (!$post_id) {
            return '';
        }
        
        $state_terms = get_the_terms($post_id, 'state');
        if (empty($state_terms) || is_wp_error($state_terms)) {
            return '';
        }
        
        $state_term = $state_terms[0];
        
        if ($format === 'abbr') {
            // Return abbreviation (term name or slug uppercase)
            $abbr = !empty($state_term->name) ? $state_term->name : $state_term->slug;
            return esc_html(strtoupper($abbr));
        }
        
        // Return full name (description if available, else name)
        $state_name = !empty($state_term->description) 
            ? $state_term->description 
            : $state_term->name;
        
        return esc_html($state_name);
    }
    
    /**
     * Niche name shortcode
     * Displays the niche name from niche taxonomy
     *
     * @param array $atts Shortcode attributes
     * @return string Niche name
     */
    public function niche_name_shortcode($atts) {
        // Only show on profile pages by default
        $atts = shortcode_atts(
            array(
                'post_id' => get_the_ID(),
                'plural' => 'false', // Simple pluralization (add 's')
            ),
            $atts,
            'dh_niche_name'
        );
        
        $post_id = absint($atts['post_id']);
        $plural = filter_var($atts['plural'], FILTER_VALIDATE_BOOLEAN);
        
        if (!$post_id) {
            return '';
        }
        
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
        
        return esc_html($niche_name);
    }
}

// Initialize the module
new DH_Taxonomy_Display();
