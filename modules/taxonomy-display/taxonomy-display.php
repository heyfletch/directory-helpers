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
        
        // Add Bricks Builder integration
        add_action('init', array($this, 'init_bricks_integration'));
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
                'case' => 'lower', // 'lower' or 'title'
            ),
            $atts,
            'dh_niche_name'
        );
        
        $post_id = absint($atts['post_id']);
        $plural = filter_var($atts['plural'], FILTER_VALIDATE_BOOLEAN);
        $case = sanitize_key($atts['case']);
        
        if (!$post_id) {
            return '';
        }
        
        $niche_name = DH_Taxonomy_Helpers::get_niche_name($post_id, $plural);
        
        // Apply case transformation
        if ($case === 'title') {
            $niche_name = ucwords(strtolower($niche_name));
        } else {
            $niche_name = strtolower($niche_name);
        }
        
        return esc_html($niche_name);
    }
    
    /**
     * Initialize Bricks Builder integration
     */
    public function init_bricks_integration() {
        if (class_exists('Bricks\Integrations\Dynamic_Data\Providers')) {
            add_filter('bricks/dynamic_tags_list', array($this, 'add_bricks_taxonomy_tags'));
            add_filter('bricks/dynamic_data/render_tag', array($this, 'render_bricks_taxonomy_tags'), 20, 3);
            add_filter('bricks/dynamic_data/render_content', array($this, 'render_bricks_taxonomy_content'), 20, 3);
            add_filter('bricks/frontend/render_data', array($this, 'render_bricks_taxonomy_content'), 20, 2);
        }
    }
    
    /**
     * Add taxonomy-related dynamic data tags to Bricks Builder
     */
    public function add_bricks_taxonomy_tags($tags) {
        $taxonomy_tags = [
            'dh_city_name' => [
                'name'  => '{dh_city_name}',
                'label' => 'City Name (Directory Helpers)',
                'group' => 'Directory Helpers',
            ],
        ];
        
        // Check for existing tags to prevent duplicates
        $existing_names = array_column($tags, 'name');
        
        foreach ($taxonomy_tags as $key => $tag) {
            if (!in_array($tag['name'], $existing_names)) {
                $tags[] = $tag;
            }
        }
        
        return $tags;
    }
    
    /**
     * Render individual taxonomy dynamic data tags
     */
    public function render_bricks_taxonomy_tags($tag, $post, $context = 'text') {
        if (!is_string($tag)) {
            return $tag;
        }
        
        $clean_tag = str_replace(['{', '}'], '', $tag);
        
        if ($clean_tag === 'dh_city_name') {
            return DH_Taxonomy_Helpers::get_city_name(get_the_ID(), true);
        }
        
        return $tag;
    }
    
    /**
     * Render taxonomy tags in content
     */
    public function render_bricks_taxonomy_content($content, $post, $context = 'text') {
        // Quick check if any taxonomy tags exist
        if (strpos($content, '{dh_city_name}') === false) {
            return $content;
        }
        
        $post_id = get_the_ID();
        
        // Replace {dh_city_name} tag
        if (strpos($content, '{dh_city_name}') !== false) {
            $city_name = DH_Taxonomy_Helpers::get_city_name($post_id, true);
            $content = str_replace('{dh_city_name}', $city_name, $content);
        }
        
        return $content;
    }
}

// Initialize the module
new DH_Taxonomy_Display();
