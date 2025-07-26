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
        $this->text_domain = 'directory-helpers';
        
        // Cache translations to avoid early textdomain loading
        $this->cache_translations();
        
        // Add shortcode
        add_shortcode('dh_breadcrumbs', array($this, 'breadcrumbs_shortcode'));
        
        // Register settings
        add_action('admin_init', array($this, 'register_settings'));
        
        // Register and enqueue styles
        add_action('wp_enqueue_scripts', array($this, 'enqueue_styles'));
        
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
     * Register and enqueue styles
     */
    public function enqueue_styles() {
        $css_path = plugin_dir_path(__FILE__) . 'breadcrumbs.css';
        $css_url = plugin_dir_url(__FILE__) . 'breadcrumbs.css';
        
        if (file_exists($css_path)) {
            wp_enqueue_style(
                'directory-helpers-breadcrumbs',
                $css_url,
                array(),
                filemtime($css_path)
            );
        }
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
            'show_niche' => __('Show Niche', $this->text_domain),
            'show_city' => __('Show City', $this->text_domain),
            'show_state' => __('Show State', $this->text_domain),
            'display_options' => __('Display Options', $this->text_domain),
            'separator_options' => __('Separator Options', $this->text_domain),
            'home_separator' => __('Home Separator', $this->text_domain),
            'niche_separator' => __('Niche Separator', $this->text_domain),
            'city_separator' => __('City Separator', $this->text_domain),
            'state_separator' => __('State Separator', $this->text_domain),
            'configure_settings' => __('Configure the breadcrumbs settings.', $this->text_domain),
            'home' => __('Home', $this->text_domain),
            'leave_empty_default' => __('Leave empty to use default separator', $this->text_domain)
        );
    }
    
    /**
     * Register settings
     */
    public function register_settings() {
        register_setting('directory_helpers_breadcrumbs', 'directory_helpers_breadcrumbs_options');
        
        // Add general settings section
        add_settings_section(
            'directory_helpers_breadcrumbs_section',
            isset($this->translations['breadcrumbs_settings']) ? $this->translations['breadcrumbs_settings'] : 'Breadcrumbs Settings',
            array($this, 'breadcrumbs_section_callback'),
            'directory_helpers_breadcrumbs'
        );
        
        // Add general settings fields
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
        
        // Add display options section
        add_settings_section(
            'directory_helpers_breadcrumbs_display_section',
            isset($this->translations['display_options']) ? $this->translations['display_options'] : 'Display Options',
            array($this, 'display_section_callback'),
            'directory_helpers_breadcrumbs'
        );
        
        // Add display settings fields
        add_settings_field(
            'show_home',
            isset($this->translations['show_home']) ? $this->translations['show_home'] : 'Show Home',
            array($this, 'show_home_field_callback'),
            'directory_helpers_breadcrumbs',
            'directory_helpers_breadcrumbs_display_section'
        );
        
        add_settings_field(
            'show_niche',
            isset($this->translations['show_niche']) ? $this->translations['show_niche'] : 'Show Niche',
            array($this, 'show_niche_field_callback'),
            'directory_helpers_breadcrumbs',
            'directory_helpers_breadcrumbs_display_section'
        );
        
        add_settings_field(
            'show_city',
            isset($this->translations['show_city']) ? $this->translations['show_city'] : 'Show City',
            array($this, 'show_city_field_callback'),
            'directory_helpers_breadcrumbs',
            'directory_helpers_breadcrumbs_display_section'
        );
        
        add_settings_field(
            'show_state',
            isset($this->translations['show_state']) ? $this->translations['show_state'] : 'Show State',
            array($this, 'show_state_field_callback'),
            'directory_helpers_breadcrumbs',
            'directory_helpers_breadcrumbs_display_section'
        );
        
        // Add custom separators section
        add_settings_section(
            'directory_helpers_breadcrumbs_separators_section',
            isset($this->translations['separator_options']) ? $this->translations['separator_options'] : 'Separator Options',
            array($this, 'separators_section_callback'),
            'directory_helpers_breadcrumbs'
        );
        
        // Add custom separator fields
        add_settings_field(
            'home_separator',
            isset($this->translations['home_separator']) ? $this->translations['home_separator'] : 'Home Separator',
            array($this, 'home_separator_field_callback'),
            'directory_helpers_breadcrumbs',
            'directory_helpers_breadcrumbs_separators_section'
        );
        
        add_settings_field(
            'niche_separator',
            isset($this->translations['niche_separator']) ? $this->translations['niche_separator'] : 'Niche Separator',
            array($this, 'niche_separator_field_callback'),
            'directory_helpers_breadcrumbs',
            'directory_helpers_breadcrumbs_separators_section'
        );
        
        add_settings_field(
            'city_separator',
            isset($this->translations['city_separator']) ? $this->translations['city_separator'] : 'City Separator',
            array($this, 'city_separator_field_callback'),
            'directory_helpers_breadcrumbs',
            'directory_helpers_breadcrumbs_separators_section'
        );
        
        add_settings_field(
            'state_separator',
            isset($this->translations['state_separator']) ? $this->translations['state_separator'] : 'State Separator',
            array($this, 'state_separator_field_callback'),
            'directory_helpers_breadcrumbs',
            'directory_helpers_breadcrumbs_separators_section'
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
     * Display options section callback
     */
    public function display_section_callback() {
        echo '<p>' . esc_html__('Control which elements appear in the breadcrumbs.', 'directory-helpers') . '</p>';
    }
    
    /**
     * Separators section callback
     */
    public function separators_section_callback() {
        echo '<p>' . esc_html__('Customize separators for each breadcrumb element. Leave empty to use the default separator.', 'directory-helpers') . '</p>';
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
     * Show niche field callback
     */
    public function show_niche_field_callback() {
        $options = get_option('directory_helpers_breadcrumbs_options', array());
        $show_niche = isset($options['show_niche']) ? $options['show_niche'] : true;
        
        echo '<input type="checkbox" name="directory_helpers_breadcrumbs_options[show_niche]" ' . checked($show_niche, true, false) . ' value="1">';
    }
    
    /**
     * Show city field callback
     */
    public function show_city_field_callback() {
        $options = get_option('directory_helpers_breadcrumbs_options', array());
        $show_city = isset($options['show_city']) ? $options['show_city'] : true;
        
        echo '<input type="checkbox" name="directory_helpers_breadcrumbs_options[show_city]" ' . checked($show_city, true, false) . ' value="1">';
    }
    
    /**
     * Show state field callback
     */
    public function show_state_field_callback() {
        $options = get_option('directory_helpers_breadcrumbs_options', array());
        $show_state = isset($options['show_state']) ? $options['show_state'] : true;
        
        echo '<input type="checkbox" name="directory_helpers_breadcrumbs_options[show_state]" ' . checked($show_state, true, false) . ' value="1">';
    }
    
    /**
     * Home separator field callback
     */
    public function home_separator_field_callback() {
        $options = get_option('directory_helpers_breadcrumbs_options', array());
        $home_separator = isset($options['home_separator']) ? $options['home_separator'] : '';
        
        echo '<input type="text" name="directory_helpers_breadcrumbs_options[home_separator]" value="' . esc_attr($home_separator) . '" class="regular-text">';
        echo '<p class="description">' . esc_html__('Leave empty to use default separator', 'directory-helpers') . '</p>';
    }
    
    /**
     * Niche separator field callback
     */
    public function niche_separator_field_callback() {
        $options = get_option('directory_helpers_breadcrumbs_options', array());
        $niche_separator = isset($options['niche_separator']) ? $options['niche_separator'] : '';
        
        echo '<input type="text" name="directory_helpers_breadcrumbs_options[niche_separator]" value="' . esc_attr($niche_separator) . '" class="regular-text">';
        echo '<p class="description">' . esc_html__('Leave empty to use default separator', 'directory-helpers') . '</p>';
    }
    
    /**
     * City separator field callback
     */
    public function city_separator_field_callback() {
        $options = get_option('directory_helpers_breadcrumbs_options', array());
        $city_separator = isset($options['city_separator']) ? $options['city_separator'] : '';
        
        echo '<input type="text" name="directory_helpers_breadcrumbs_options[city_separator]" value="' . esc_attr($city_separator) . '" class="regular-text">';
        echo '<p class="description">' . esc_html__('Leave empty to use default separator', 'directory-helpers') . '</p>';
    }
    
    /**
     * State separator field callback
     */
    public function state_separator_field_callback() {
        $options = get_option('directory_helpers_breadcrumbs_options', array());
        $state_separator = isset($options['state_separator']) ? $options['state_separator'] : '';
        
        echo '<input type="text" name="directory_helpers_breadcrumbs_options[state_separator]" value="' . esc_attr($state_separator) . '" class="regular-text">';
        echo '<p class="description">' . esc_html__('Leave empty to use default separator', 'directory-helpers') . '</p>';
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
                'show_niche' => '',
                'show_city' => '',
                'show_state' => '',
                'home_separator' => '',
                'niche_separator' => '',
                'city_separator' => '',
                'state_separator' => '',
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
        
        // Show/hide options
        $show_home = !empty($atts['show_home']) ? filter_var($atts['show_home'], FILTER_VALIDATE_BOOLEAN) : (isset($options['show_home']) ? (bool) $options['show_home'] : true);
        $show_niche = !empty($atts['show_niche']) ? filter_var($atts['show_niche'], FILTER_VALIDATE_BOOLEAN) : (isset($options['show_niche']) ? (bool) $options['show_niche'] : true);
        $show_city = !empty($atts['show_city']) ? filter_var($atts['show_city'], FILTER_VALIDATE_BOOLEAN) : (isset($options['show_city']) ? (bool) $options['show_city'] : true);
        $show_state = !empty($atts['show_state']) ? filter_var($atts['show_state'], FILTER_VALIDATE_BOOLEAN) : (isset($options['show_state']) ? (bool) $options['show_state'] : true);
        
        // Custom separators
        $home_separator = !empty($atts['home_separator']) ? $atts['home_separator'] : $separator;
        $niche_separator = !empty($atts['niche_separator']) ? $atts['niche_separator'] : $separator;
        $city_separator = !empty($atts['city_separator']) ? $atts['city_separator'] : $separator;
        $state_separator = !empty($atts['state_separator']) ? $atts['state_separator'] : $separator;
        
        // Generate breadcrumbs
        return $this->generate_breadcrumbs(
            $separator,
            $home_text,
            $show_home,
            $show_niche,
            $show_city,
            $show_state,
            $home_separator,
            $niche_separator,
            $city_separator,
            $state_separator
        );
    }
    
    /**
     * Generate breadcrumbs
     *
     * @param string $separator Default breadcrumbs separator
     * @param string $home_text Home text
     * @param bool $show_home Whether to show home link
     * @param bool $show_niche Whether to show niche
     * @param bool $show_city Whether to show city
     * @param bool $show_state Whether to show state
     * @param string $home_separator Custom separator after home
     * @param string $niche_separator Custom separator after niche
     * @param string $city_separator Custom separator after city
     * @param string $state_separator Custom separator after state
     * @return string Breadcrumbs HTML with JSON-LD structured data
     */
    private function generate_breadcrumbs(
        $separator, 
        $home_text, 
        $show_home, 
        $show_niche = true, 
        $show_city = true, 
        $show_state = true,
        $home_separator = '',
        $niche_separator = '',
        $city_separator = '',
        $state_separator = ''
    ) {
        // Start output with semantic nav and ol elements
        $output = '<nav aria-label="Breadcrumb"><ol class="breadcrumbs">';
        
        // DEBUG: Uncomment line below for debugging post type detection
        // $output = '<div style="background: red; color: white; padding: 5px; margin: 5px 0;">DEBUG: Post type = ' . get_post_type() . ', is_singular(city-listing) = ' . (is_singular('city-listing') ? 'YES' : 'NO') . ', is_singular(profile) = ' . (is_singular('profile') ? 'YES' : 'NO') . '</div>' . $output;
        $items = array();
        $separators = array(); // We'll keep this for structured data but not use for visual separators
        
        // For structured data
        $json_ld_items = array();
        $position = 1;
        
        // Check if we're on a profile page
        if (is_singular('profile')) {
            global $post;
            
            // Add home link if needed
            if ($show_home) {
                $home_url = home_url('/');
                // We'll set the separator temporarily but may change it later
                $home_sep = !empty($home_separator) ? $home_separator : $separator;
                
                // We'll store the home item index to possibly update its separator later
                $home_item_index = count($items);
                $items[] = '<li class="home-item" data-separator="' . esc_attr($home_sep) . '"><a href="' . esc_url($home_url) . '">' . esc_html($home_text) . '</a></li>';
                $separators[] = $home_sep;
                
                // Add to structured data
                $json_ld_items[] = array(
                    '@type' => 'ListItem',
                    'position' => $position++,
                    'name' => $home_text,
                    'item' => $home_url
                );
            }
            
            // Get the profile's niche term and move it after Home
            $niche_terms = get_the_terms($post->ID, 'niche');
            if ($show_niche && !empty($niche_terms) && !is_wp_error($niche_terms)) {
                // Get the first niche term
                $niche_term = $niche_terms[0];
                $niche_url = get_term_link($niche_term);
                
                // Add niche to breadcrumbs with link to taxonomy archive
                $niche_sep = !empty($niche_separator) ? $niche_separator : $separator;
                $items[] = '<li class="niche-item" data-separator="' . esc_attr($niche_sep) . '"><a href="' . esc_url($niche_url) . '">' . esc_html($niche_term->name) . '</a></li>';
                $separators[] = $niche_sep;
                
                // Add to structured data
                $json_ld_items[] = array(
                    '@type' => 'ListItem',
                    'position' => $position++,
                    'name' => $niche_term->name,
                    'item' => $niche_url
                );
            }
            
            // Get the profile's state term
            $state_terms = get_the_terms($post->ID, 'state');
            if ($show_state && !empty($state_terms) && !is_wp_error($state_terms)) {
                // Get the first state term (or primary term if implemented)
                $state_term = $state_terms[0];
                
                // Find the corresponding State Listing CPT
                $state_listing = $this->get_cpt_by_term('state-listing', 'state', $state_term->term_id);
                
                if ($state_listing) {
                    // Use the term description if available, otherwise use the term name
                    $state_display_name = !empty($state_term->description) ? $state_term->description : $state_term->name;
                    
                    $state_url = get_permalink($state_listing);
                    $state_sep = !empty($state_separator) ? $state_separator : $separator;
                    $items[] = '<li class="state-item" data-separator="' . esc_attr($state_sep) . '"><a href="' . esc_url($state_url) . '">' . esc_html($state_display_name) . '</a></li>';
                    $separators[] = $state_sep;
                    
                    // Add to structured data
                    $json_ld_items[] = array(
                        '@type' => 'ListItem',
                        'position' => $position++,
                        'name' => $state_display_name,
                        'item' => $state_url
                    );
                }
                
                // Get the profile's city term (area taxonomy)
                $city_terms = get_the_terms($post->ID, 'area');
                if ($show_city && !empty($city_terms) && !is_wp_error($city_terms)) {
                    // Get the first city term (or primary term if implemented)
                    $city_term = $city_terms[0];
                    
                    // Find the corresponding City Listing CPT
                    $city_listing = $this->get_cpt_by_term('city-listing', 'area', $city_term->term_id);
                
                    if ($city_listing) {
                        $city_url = get_permalink($city_listing);
                        $city_sep = !empty($city_separator) ? $city_separator : $separator;
                        $items[] = '<li class="city-item" data-separator="' . esc_attr($city_sep) . '"><a href="' . esc_url($city_url) . '">' . esc_html($city_term->name) . '</a></li>';
                        $separators[] = $city_sep;
                        
                        // Add to structured data
                        $json_ld_items[] = array(
                            '@type' => 'ListItem',
                            'position' => $position++,
                            'name' => $city_term->name,
                            'item' => $city_url
                        );
                    }
                }
            
            }
            
            // Add current page with aria-current attribute
            $current_title = get_the_title();
            $items[] = '<li aria-current="page">' . esc_html($current_title) . '</li>';
            
            // Add current page to structured data (without item property as it's the current page)
            $json_ld_items[] = array(
                '@type' => 'ListItem',
                'position' => $position++,
                'name' => $current_title
            );
            
            // Check if we need to update the home separator
            // If no city or state is shown, use default separator for home
            if ($show_home && isset($home_item_index) && 
                ((!$show_city || empty($city_term) || empty($city_listing)) && 
                 (!$show_state || empty($state_term) || empty($state_listing)))) {
                // Update the home item to use default separator
                $default_sep = $separator;
                $items[$home_item_index] = str_replace(
                    'data-separator="' . esc_attr($home_sep) . '"', 
                    'data-separator="' . esc_attr($default_sep) . '"', 
                    $items[$home_item_index]
                );
                $separators[$home_item_index] = $default_sep;
            }
        }
        
        // Check if we're on a city page
        if (is_singular('city-listing')) {
            global $post;
            
            // Add home link if needed
            if ($show_home) {
                $home_url = home_url('/');
                $home_sep = !empty($home_separator) ? $home_separator : $separator;
                
                $items[] = '<li class="home-item" data-separator="' . esc_attr($home_sep) . '"><a href="' . esc_url($home_url) . '">' . esc_html($home_text) . '</a></li>';
                $separators[] = $home_sep;
                
                // Add to structured data
                $json_ld_items[] = array(
                    '@type' => 'ListItem',
                    'position' => $position++,
                    'name' => $home_text,
                    'item' => $home_url
                );
            }
            
            // Find the corresponding state listing by analyzing the city post slug
            // City slugs contain state slug in format: city-name-state-slug-dog-trainers
            $city_slug = $post->post_name;
            
            // DEBUG: Uncomment line below for debugging city slug extraction
            // $items[] = '<li style="background: orange; color: black;">DEBUG: City slug = ' . $city_slug . '</li>';
            
            if ($show_state) {
                // Extract potential state slug from city slug
                // Pattern: city-name-STATE-dog-trainers
                if (preg_match('/^(.+)-([a-z]{2})-dog-trainers$/', $city_slug, $matches)) {
                    $state_slug = $matches[2]; // Extract the 2-letter state code
                    
                    // Find state-listing post with this state slug
                    $state_listing_args = array(
                        'post_type' => 'state-listing',
                        'post_status' => 'publish',
                        'posts_per_page' => 1,
                        'tax_query' => array(
                            array(
                                'taxonomy' => 'state',
                                'field' => 'slug',
                                'terms' => $state_slug,
                            ),
                        ),
                    );
                    
                    $state_query = new WP_Query($state_listing_args);
                    
                    if ($state_query->have_posts()) {
                        $state_listing = $state_query->posts[0];
                        
                        // Get the state term to get proper display name
                        $state_terms = get_the_terms($state_listing->ID, 'state');
                        if (!empty($state_terms) && !is_wp_error($state_terms)) {
                            $state_term = $state_terms[0];
                            $state_display_name = !empty($state_term->description) ? $state_term->description : $state_term->name;
                            
                            $state_url = get_permalink($state_listing);
                            $state_sep = !empty($state_separator) ? $state_separator : $separator;
                            $items[] = '<li class="state-item" data-separator="' . esc_attr($state_sep) . '"><a href="' . esc_url($state_url) . '">' . esc_html($state_display_name) . '</a></li>';
                            $separators[] = $state_sep;
                            
                            // Add to structured data
                            $json_ld_items[] = array(
                                '@type' => 'ListItem',
                                'position' => $position++,
                                'name' => $state_display_name,
                                'item' => $state_url
                            );
                        }
                    }
                }
            }
            
            // Add current city page (no link since it's the current page)
            $current_title = get_the_title();
            $items[] = '<li aria-current="page">' . esc_html($current_title) . '</li>';
            
            // Add current page to structured data
            $json_ld_items[] = array(
                '@type' => 'ListItem',
                'position' => $position++,
                'name' => $current_title
            );
        }
        
        // Build the breadcrumbs (no need for visual separators in HTML)
        if (!empty($items)) {
            $output .= implode('', $items);
        }
        
        $output .= '</ol></nav>';
        
        // Add JSON-LD structured data if we have items
        if (!empty($json_ld_items)) {
            $json_ld = array(
                '@context' => 'https://schema.org',
                '@type' => 'BreadcrumbList',
                'itemListElement' => $json_ld_items
            );
            
            $output .= '<script type="application/ld+json">' . wp_json_encode($json_ld) . '</script>';
        }
        
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
