<?php
/**
 * Breadcrumbs Module
 *
 * @package Directory_Helpers
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Breadcrumbs class
 */
class DH_Breadcrumbs {
    /**
     * Module text domain
     *
     * @var string
     */
    private $text_domain = 'directory-helpers';
    
    /**
     * Cached translations
     *
     * @var array
     */
    private $translations = array();
    
    /**
     * Constructor
     */
    public function __construct() {
        // Register shortcode
        add_shortcode('dh_breadcrumbs', array($this, 'breadcrumbs_shortcode'));
        
        // Register settings after init
        add_action('admin_init', array($this, 'register_settings'));
        
        // Make sure we initialize after WordPress is fully loaded
        add_action('init', array($this, 'init'), 20); // Higher priority to ensure textdomain is loaded
    }
    
    /**
     * Initialize module
     */
    public function init() {
        // Cache translations after textdomain is loaded
        $this->cache_translations();
    }
    
    /**
     * Cache translations to avoid early textdomain loading
     */
    private function cache_translations() {
        $this->translations = array(
            'breadcrumbs_settings' => __('Breadcrumbs Settings', $this->text_domain),
            'separator' => __('Separator', $this->text_domain),
            'home_text' => __('Home Text', $this->text_domain),
            'show_home' => __('Show Home', $this->text_domain),
            'configure_settings' => __('Configure the breadcrumbs settings.', $this->text_domain),
            'home' => __('Home', $this->text_domain)
        );
    }
    
    /**
     * Register settings
     */
    public function register_settings() {
        register_setting('directory_helpers_breadcrumbs', 'directory_helpers_breadcrumbs_options');
        
        // Add settings section
        add_settings_section(
            'directory_helpers_breadcrumbs_section',
            isset($this->translations['breadcrumbs_settings']) ? $this->translations['breadcrumbs_settings'] : 'Breadcrumbs Settings',
            array($this, 'breadcrumbs_section_callback'),
            'directory_helpers_breadcrumbs'
        );
        
        // Add settings fields
        add_settings_field(
            'separator',
            isset($this->translations['separator']) ? $this->translations['separator'] : 'Separator',
            array($this, 'separator_field_callback'),
            'directory_helpers_breadcrumbs',
            'directory_helpers_breadcrumbs_section'
        );
        
        add_settings_field(
            'home_text',
            isset($this->translations['home_text']) ? $this->translations['home_text'] : 'Home Text',
            array($this, 'home_text_field_callback'),
            'directory_helpers_breadcrumbs',
            'directory_helpers_breadcrumbs_section'
        );
        
        add_settings_field(
            'show_home',
            isset($this->translations['show_home']) ? $this->translations['show_home'] : 'Show Home',
            array($this, 'show_home_field_callback'),
            'directory_helpers_breadcrumbs',
            'directory_helpers_breadcrumbs_section'
        );
    }
    
    /**
     * Breadcrumbs section callback
     */
    public function breadcrumbs_section_callback() {
        $text = isset($this->translations['configure_settings']) ? $this->translations['configure_settings'] : 'Configure the breadcrumbs settings.';
        echo '<p>' . esc_html($text) . '</p>';
    }
    
    /**
     * Separator field callback
     */
    public function separator_field_callback() {
        $options = get_option('directory_helpers_breadcrumbs_options', array());
        $separator = isset($options['separator']) ? $options['separator'] : ' &raquo; ';
        
        echo '<input type="text" name="directory_helpers_breadcrumbs_options[separator]" value="' . esc_attr($separator) . '" class="regular-text">';
    }
    
    /**
     * Home text field callback
     */
    public function home_text_field_callback() {
        $options = get_option('directory_helpers_breadcrumbs_options', array());
        $default_home_text = isset($this->translations['home']) ? $this->translations['home'] : 'Home';
        $home_text = isset($options['home_text']) ? $options['home_text'] : $default_home_text;
        
        echo '<input type="text" name="directory_helpers_breadcrumbs_options[home_text]" value="' . esc_attr($home_text) . '" class="regular-text">';
    }
    
    /**
     * Show home field callback
     */
    public function show_home_field_callback() {
        $options = get_option('directory_helpers_breadcrumbs_options', array());
        $show_home = isset($options['show_home']) ? $options['show_home'] : true;
        
        echo '<input type="checkbox" name="directory_helpers_breadcrumbs_options[show_home]" ' . checked($show_home, true, false) . ' value="1">';
    }
    
    /**
     * Breadcrumbs shortcode callback
     *
     * @param array $atts Shortcode attributes
     * @return string Breadcrumbs HTML
     */
    public function breadcrumbs_shortcode($atts) {
        // Parse shortcode attributes
        $atts = shortcode_atts(
            array(
                'separator' => '',
                'home_text' => '',
                'show_home' => '',
            ),
            $atts,
            'dh_breadcrumbs'
        );
        
        // Get breadcrumbs options
        $options = get_option('directory_helpers_breadcrumbs_options', array());
        
        // Set defaults if not provided
        $separator = !empty($atts['separator']) ? $atts['separator'] : (isset($options['separator']) ? $options['separator'] : ' &raquo; ');
        $default_home_text = isset($this->translations['home']) ? $this->translations['home'] : 'Home';
        $home_text = !empty($atts['home_text']) ? $atts['home_text'] : (isset($options['home_text']) ? $options['home_text'] : $default_home_text);
        $show_home = !empty($atts['show_home']) ? filter_var($atts['show_home'], FILTER_VALIDATE_BOOLEAN) : (isset($options['show_home']) ? (bool) $options['show_home'] : true);
        
        // Generate breadcrumbs
        return $this->generate_breadcrumbs($separator, $home_text, $show_home);
    }
    
    /**
     * Generate breadcrumbs
     *
     * @param string $separator Breadcrumbs separator
     * @param string $home_text Home text
     * @param bool $show_home Whether to show home link
     * @return string Breadcrumbs HTML
     */
    private function generate_breadcrumbs($separator, $home_text, $show_home) {
        // Start output
        $output = '<div class="directory-helpers-breadcrumbs">';
        $items = array();
        
        // Add home link if needed
        if ($show_home) {
            $items[] = '<a href="' . esc_url(home_url('/')) . '">' . esc_html($home_text) . '</a>';
        }
        
        // Check if we're on a profile page
        if (is_singular('profile')) {
            global $post;
            
            // Get the profile's city term (area taxonomy)
            $city_terms = get_the_terms($post->ID, 'area');
            if (!empty($city_terms) && !is_wp_error($city_terms)) {
                // Get the first city term (or primary term if implemented)
                $city_term = $city_terms[0];
                
                // Find the corresponding City Listing CPT
                $city_listing = $this->get_cpt_by_term('city-listing', 'area', $city_term->term_id);
                
                if ($city_listing) {
                    $items[] = '<a href="' . esc_url(get_permalink($city_listing)) . '">' . esc_html($city_term->name) . '</a>';
                }
            }
            
            // Get the profile's state term
            $state_terms = get_the_terms($post->ID, 'state');
            if (!empty($state_terms) && !is_wp_error($state_terms)) {
                // Get the first state term (or primary term if implemented)
                $state_term = $state_terms[0];
                
                // Find the corresponding State Listing CPT
                $state_listing = $this->get_cpt_by_term('state-listing', 'state', $state_term->term_id);
                
                if ($state_listing) {
                    $items[] = '<a href="' . esc_url(get_permalink($state_listing)) . '">' . esc_html($state_term->name) . '</a>';
                }
            }
            
            // Get the profile's niche term
            $niche_terms = get_the_terms($post->ID, 'niche');
            if (!empty($niche_terms) && !is_wp_error($niche_terms)) {
                // Get the first niche term
                $niche_term = $niche_terms[0];
                
                // Add niche to breadcrumbs (no link for now)
                $items[] = esc_html($niche_term->name);
            }
            
            // Add current page
            $items[] = get_the_title();
        }
        
        // Build the breadcrumbs
        $output .= implode($separator, $items);
        $output .= '</div>';
        
        return $output;
    }
    
    /**
     * Get CPT by term
     *
     * Find a custom post type that has a specific term assigned
     *
     * @param string $post_type Post type to search
     * @param string $taxonomy Taxonomy name
     * @param int $term_id Term ID to search for
     * @return WP_Post|false Post object or false if not found
     */
    private function get_cpt_by_term($post_type, $taxonomy, $term_id) {
        $args = array(
            'post_type' => $post_type,
            'post_status' => 'publish',
            'posts_per_page' => 1,
            'tax_query' => array(
                array(
                    'taxonomy' => $taxonomy,
                    'field' => 'term_id',
                    'terms' => $term_id,
                ),
            ),
        );
        
        $query = new WP_Query($args);
        
        if ($query->have_posts()) {
            return $query->posts[0];
        }
        
        return false;
    }
}
