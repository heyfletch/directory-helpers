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
        
        $city_name = DH_Taxonomy_Helpers::get_city_name($post_id, $strip_state);
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
        
        $state_name = DH_Taxonomy_Helpers::get_state_name($post_id, $format);
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
        
        $niche_name = DH_Taxonomy_Helpers::get_niche_name($post_id, $plural);
        return esc_html($niche_name);
    }
}

// Initialize the module
new DH_Taxonomy_Display();
