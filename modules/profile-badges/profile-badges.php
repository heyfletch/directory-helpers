<?php
/**
 * Profile Badges Module
 *
 * Dynamic SVG badge system for dog trainer profiles displaying rankings,
 * featured status, and providing embed codes.
 *
 * @package Directory_Helpers
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Profile Badges class
 */
class DH_Profile_Badges {
    /**
     * Module text domain
     *
     * @var string
     */
    private $text_domain = 'directory-helpers';
    
    /**
     * Internal cache TTL for badge data and eligibility (30 days)
     * Cleared automatically when profile is saved
     *
     * @var int
     */
    private $cache_ttl = 2592000; // 30 days
    
    /**
     * HTTP Cache-Control header for external badge embeds (6 hours)
     * Shorter duration ensures external sites see rank updates within reasonable time
     *
     * @var int
     */
    private $http_cache_ttl = 21600; // 6 hours
    
    /**
     * Cache TTL for city/state listing lookups (60 days)
     * These rarely change and are cleared when listings are updated
     *
     * @var int
     */
    private $listing_cache_ttl = 5184000; // 60 days
    
    /**
     * Rate limit per IP (requests per minute)
     *
     * @var int
     */
    private $rate_limit = 120;
    
    /**
     * Constructor
     */
    public function __construct() {
        
        // Register rewrite rules
        add_action('init', array($this, 'register_rewrite_rules'));
        add_filter('query_vars', array($this, 'register_query_vars'));
        
        // Handle badge requests
        add_action('template_redirect', array($this, 'handle_badge_request'));
        
        // Register shortcodes
        add_shortcode('dh_accolades', array($this, 'accolades_shortcode'));
        add_shortcode('dh_celebration', array($this, 'celebration_shortcode'));
        add_shortcode('dh_best_location_ranked_name', array($this, 'best_location_ranked_name_shortcode'));
        
        // Enqueue frontend scripts for celebration shortcode
        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_scripts'));
        
        // Add activation notice for flushing rewrite rules
        add_action('admin_notices', array($this, 'activation_notice'));
        
        // Clear badge cache when profile is updated
        add_action('acf/save_post', array($this, 'clear_badge_cache_on_save'), 25);
        
        // Add Bricks dynamic data integration
        add_action('init', array($this, 'init_bricks_integration'));
    }
    
    /**
     * Initialize Bricks Builder integration
     */
    public function init_bricks_integration() {
        if (class_exists('Bricks\Integrations\Dynamic_Data\Providers')) {
            add_filter('bricks/dynamic_tags_list', array($this, 'add_bricks_badge_tags'));
            add_filter('bricks/dynamic_data/render_tag', array($this, 'render_bricks_badge_tags'), 20, 3);
            add_filter('bricks/dynamic_data/render_content', array($this, 'render_bricks_badge_content'), 20, 3);
            add_filter('bricks/frontend/render_data', array($this, 'render_bricks_badge_content'), 20, 2);
        }
    }
    
    /**
     * Add badge-related dynamic data tags to Bricks Builder
     */
    public function add_bricks_badge_tags($tags) {
        $badge_tags = [
            'profile_badge_status' => [
                'name'  => '{profile_badge_status}',
                'label' => 'Profile Badge Status',
                'group' => 'Profile Badges',
            ],
            'profile_has_ranking_badge' => [
                'name'  => '{profile_has_ranking_badge}',
                'label' => 'Has Ranking Badge',
                'group' => 'Profile Badges',
            ],
            'profile_has_featured_badge' => [
                'name'  => '{profile_has_featured_badge}',
                'label' => 'Has Featured Badge',
                'group' => 'Profile Badges',
            ],
        ];
        
        // Check for existing tags to prevent duplicates
        $existing_names = array_column($tags, 'name');
        
        foreach ($badge_tags as $key => $tag) {
            if (!in_array($tag['name'], $existing_names)) {
                $tags[] = $tag;
            }
        }
        
        return $tags;
    }
    
    /**
     * Render individual badge dynamic data tags
     */
    public function render_bricks_badge_tags($tag, $post, $context = 'text') {
        if (!is_string($tag)) {
            return $tag;
        }
        
        $clean_tag = str_replace(['{', '}'], '', $tag);
        
        if (!is_singular('profile')) {
            return '';
        }
        
        $post_id = get_the_ID();
        
        switch ($clean_tag) {
            case 'profile_badge_status':
                return $this->get_profile_badge_status($post_id);
                
            case 'profile_has_ranking_badge':
                return $this->has_ranking_badge($post_id) ? '1' : '0';
                
            case 'profile_has_featured_badge':
                return $this->has_featured_badge($post_id) ? '1' : '0';
        }
        
        return $tag;
    }
    
    /**
     * Render badge tags in content
     */
    public function render_bricks_badge_content($content, $post, $context = 'text') {
        if (!is_singular('profile')) {
            return $content;
        }
        
        // Quick check if any badge tags exist (single strpos for efficiency)
        if (strpos($content, '{profile_') === false) {
            return $content;
        }
        
        $post_id = get_the_ID();
        
        // Only replace tags that actually exist in content
        if (strpos($content, '{profile_badge_status}') !== false) {
            $content = str_replace('{profile_badge_status}', $this->get_profile_badge_status($post_id), $content);
        }
        if (strpos($content, '{profile_has_ranking_badge}') !== false) {
            $content = str_replace('{profile_has_ranking_badge}', $this->has_ranking_badge($post_id) ? '1' : '0', $content);
        }
        if (strpos($content, '{profile_has_featured_badge}') !== false) {
            $content = str_replace('{profile_has_featured_badge}', $this->has_featured_badge($post_id) ? '1' : '0', $content);
        }
        
        return $content;
    }
    
    /**
     * Get profile badge status string
     */
    public function get_profile_badge_status($post_id) {
        if ($this->has_ranking_badge($post_id)) {
            return 'ranking';
        } elseif ($this->has_featured_badge($post_id)) {
            return 'featured';
        } else {
            return 'recognized';
        }
    }
    
    /**
     * Check if profile has ranking badge
     */
    public function has_ranking_badge($post_id) {
        $eligible = $this->get_eligible_badges($post_id);
        return $eligible['city'] || $eligible['state'];
    }
    
    /**
     * Check if profile has featured badge
     */
    public function has_featured_badge($post_id) {
        $featured = get_field('featured', $post_id);
        return $featured && $featured > 0;
    }
    
    /**
     * Register rewrite rules for badge URLs
     */
    public function register_rewrite_rules() {
        add_rewrite_rule(
            'badge/([0-9]+)/(city|state|profile)\.svg$',
            'index.php?badge_request=1&badge_post_id=$matches[1]&badge_type=$matches[2]',
            'top'
        );
    }
    
    /**
     * Register custom query vars
     *
     * @param array $vars Existing query vars
     * @return array Modified query vars
     */
    public function register_query_vars($vars) {
        $vars[] = 'badge_request';
        $vars[] = 'badge_post_id';
        $vars[] = 'badge_type';
        return $vars;
    }
    
    /**
     * Show admin notice to flush rewrite rules after activation
     */
    public function activation_notice() {
        // Handle dismissal with nonce verification
        if (isset($_GET['dh_dismiss_badge_notice']) && isset($_GET['_wpnonce'])) {
            if (wp_verify_nonce($_GET['_wpnonce'], 'dh_dismiss_badge_notice') && current_user_can('manage_options')) {
                update_option('dh_badges_rewrite_flushed', true);
                wp_safe_redirect(remove_query_arg(array('dh_dismiss_badge_notice', '_wpnonce')));
                exit;
            }
        }
        
        $dismissed = get_option('dh_badges_rewrite_flushed', false);
        
        if (!$dismissed && current_user_can('manage_options')) {
            $dismiss_url = wp_nonce_url(
                add_query_arg('dh_dismiss_badge_notice', '1'),
                'dh_dismiss_badge_notice'
            );
            ?>
            <div class="notice notice-warning is-dismissible">
                <p>
                    <strong>Directory Helpers - Profile Badges:</strong> 
                    Rewrite rules need to be flushed. Please visit 
                    <a href="<?php echo esc_url(admin_url('options-permalink.php')); ?>">Settings > Permalinks</a> 
                    and click "Save Changes" to activate badge URLs.
                    <a href="<?php echo esc_url($dismiss_url); ?>" style="float:right;">Dismiss</a>
                </p>
            </div>
            <?php
        }
    }
    
    /**
     * Check rate limit for current IP
     *
     * @return bool True if within rate limit, false if exceeded
     */
    private function check_rate_limit() {
        $ip = $this->get_client_ip();
        $transient_key = 'dh_badge_rate_' . md5($ip);
        
        $requests = get_transient($transient_key);
        
        if ($requests === false) {
            // First request in this minute
            set_transient($transient_key, 1, 60);
            return true;
        }
        
        if ($requests >= $this->rate_limit) {
            return false;
        }
        
        set_transient($transient_key, $requests + 1, 60);
        return true;
    }
    
    /**
     * Get client IP address
     * Uses REMOTE_ADDR as primary source to prevent spoofing
     *
     * @return string IP address
     */
    private function get_client_ip() {
        // Use REMOTE_ADDR as it cannot be spoofed (set by web server)
        // Only use forwarded headers if behind a trusted proxy
        $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
        
        // Optional: If you're behind a trusted proxy (CloudFlare, etc.),
        // uncomment and configure this section:
        /*
        if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
            // CloudFlare
            $ip = $_SERVER['HTTP_CF_CONNECTING_IP'];
        } elseif (!empty($_SERVER['HTTP_X_REAL_IP'])) {
            // Nginx proxy
            $ip = $_SERVER['HTTP_X_REAL_IP'];
        }
        */
        
        return sanitize_text_field($ip);
    }
    
    /**
     * Handle badge endpoint requests
     */
    public function handle_badge_request() {
        if (!get_query_var('badge_request')) {
            return;
        }
        
        // Check rate limit
        if (!$this->check_rate_limit()) {
            status_header(429);
            header('Retry-After: 60');
            exit('Rate limit exceeded');
        }
        
        $post_id = absint(get_query_var('badge_post_id'));
        $badge_type = sanitize_key(get_query_var('badge_type'));
        
        // Check for active parameter (strips internal <a> tag for nested embed)
        $active = isset($_GET['active']) && $_GET['active'] === '1';
        
        // Validate post exists and is published profile
        $post = get_post($post_id);
        if (!$post || $post->post_type !== 'profile' || $post->post_status !== 'publish') {
            status_header(404);
            exit('Profile not found');
        }
        
        // Validate badge type
        if (!in_array($badge_type, array('city', 'state', 'profile'), true)) {
            status_header(404);
            exit('Invalid badge type');
        }
        
        // Check if badge type is eligible (profile badge is always eligible)
        $eligible = $this->get_eligible_badges($post_id);
        if (!$eligible[$badge_type]) {
            status_header(404);
            exit('Badge not available');
        }
        
        // Try to get cached SVG (use wp_cache which uses Redis/Memcached when available)
        $cache_key = 'dh_badge_' . $post_id . '_' . $badge_type . ($active ? '_active' : '');
        $svg = wp_cache_get($cache_key, 'dh_badges');
        
        if ($svg === false) {
            // Try to get cached badge data (separate cache to avoid regenerating data)
            $data_cache_key = 'dh_badge_data_' . $post_id . '_' . $badge_type;
            $badge_data = wp_cache_get($data_cache_key, 'dh_badges');
            
            if ($badge_data === false) {
                // Generate badge data (expensive queries here)
                $badge_data = $this->get_badge_data($post_id, $badge_type);
                if (!$badge_data) {
                    status_header(500);
                    exit('Failed to generate badge');
                }
                
                // Cache badge data in wp_cache (Redis/Memcached)
                wp_cache_set($data_cache_key, $badge_data, 'dh_badges', $this->cache_ttl);
            }
            
            // Generate SVG from cached data
            $svg = $this->generate_badge_svg($badge_data, $active);
            
            // Cache final SVG in wp_cache (Redis/Memcached)
            wp_cache_set($cache_key, $svg, 'dh_badges', $this->cache_ttl);
        }
        
        // Serve SVG with appropriate headers
        header('Content-Type: image/svg+xml');
        header('Cache-Control: public, max-age=' . $this->http_cache_ttl);
        header('Access-Control-Allow-Origin: *');
        header('X-Content-Type-Options: nosniff');
        
        echo $svg;
        exit;
    }
    
    /**
     * Get city listing post ID for a profile (cached)
     *
     * @param int $post_id Profile post ID
     * @param WP_Term $primary_area_term Primary area term
     * @return int|false City listing post ID or false
     */
    private function get_city_listing_id($post_id, $primary_area_term) {
        if (!$primary_area_term) {
            return false;
        }
        
        $cache_key = 'dh_city_listing_' . $primary_area_term->term_id;
        $city_post_id = wp_cache_get($cache_key, 'dh_badges');
        
        if ($city_post_id === false) {
            $city_posts = get_posts(array(
                'post_type' => 'city-listing',
                'post_status' => 'publish',
                'posts_per_page' => 1,
                'tax_query' => array(
                    array(
                        'taxonomy' => 'area',
                        'field' => 'term_id',
                        'terms' => $primary_area_term->term_id,
                    ),
                ),
                'fields' => 'ids',
            ));
            
            $city_post_id = !empty($city_posts) ? $city_posts[0] : 0;
            wp_cache_set($cache_key, $city_post_id, 'dh_badges', $this->listing_cache_ttl);
        }
        
        return $city_post_id ? $city_post_id : false;
    }
    
    /**
     * Get state listing post ID for a profile (cached)
     *
     * @param int $post_id Profile post ID
     * @param WP_Term $state_term State term
     * @return int|false State listing post ID or false
     */
    private function get_state_listing_id($post_id, $state_term) {
        if (!$state_term) {
            return false;
        }
        
        $cache_key = 'dh_state_listing_' . $state_term->slug;
        $state_post_id = wp_cache_get($cache_key, 'dh_badges');
        
        if ($state_post_id === false) {
            $state_posts = get_posts(array(
                'post_type' => 'state-listing',
                'post_status' => 'publish',
                'posts_per_page' => 1,
                'tax_query' => array(
                    array(
                        'taxonomy' => 'state',
                        'field' => 'slug',
                        'terms' => $state_term->slug,
                    ),
                ),
                'fields' => 'ids',
            ));
            
            $state_post_id = !empty($state_posts) ? $state_posts[0] : 0;
            wp_cache_set($cache_key, $state_post_id, 'dh_badges', $this->listing_cache_ttl);
        }
        
        return $state_post_id ? $state_post_id : false;
    }
    
    /**
     * Get eligible badges for a profile
     *
     * @param int $post_id Profile post ID
     * @return array Array of badge eligibility [city => bool, state => bool, profile => bool]
     */
    public function get_eligible_badges($post_id) {
        // Check cache first
        $cache_key = 'dh_badge_eligible_' . $post_id;
        $eligible = wp_cache_get($cache_key, 'dh_badges');
        
        if ($eligible !== false) {
            return $eligible;
        }
        
        $eligible = array(
            'city' => false,
            'state' => false,
            'profile' => true, // Always eligible
        );
        
        // Check city rank eligibility
        $city_rank = get_field('city_rank', $post_id);
        $primary_area_term = DH_Taxonomy_Helpers::get_primary_area_term($post_id);
        
        if ($primary_area_term && $city_rank && $city_rank != 99999) {
            // Get city listing post (cached)
            $city_post_id = $this->get_city_listing_id($post_id, $primary_area_term);
            
            if ($city_post_id) {
                $profile_count = (int) get_post_meta($city_post_id, '_profile_count', true);
                
                // Use ranking logic to determine if badge should show
                $tier_label = $this->get_ranking_tier_label($city_rank, $profile_count);
                if ($tier_label) {
                    $eligible['city'] = true;
                }
            }
        }
        
        // Check state rank eligibility
        $state_rank = get_field('state_rank', $post_id);
        $state_terms = get_the_terms($post_id, 'state');
        
        if (!empty($state_terms) && !is_wp_error($state_terms) && $state_rank && $state_rank != 99999) {
            // Get state listing post (cached)
            $state_post_id = $this->get_state_listing_id($post_id, $state_terms[0]);
            
            if ($state_post_id) {
                $profile_count = (int) get_post_meta($state_post_id, '_profile_count', true);
                
                // Use ranking logic to determine if badge should show
                $tier_label = $this->get_ranking_tier_label($state_rank, $profile_count);
                if ($tier_label) {
                    $eligible['state'] = true;
                }
            }
        }
        
        // Cache the result
        wp_cache_set($cache_key, $eligible, 'dh_badges', $this->cache_ttl);
        
        return $eligible;
    }
    
    /**
     * Get ranking tier label (mirrors logic from Profile Rankings module)
     *
     * @param int $rank The profile's rank
     * @param int $profile_count Total number of profiles
     * @return string|false Tier label or false
     */
    private function get_ranking_tier_label($rank, $profile_count) {
        $rank = (int) $rank;
        $profile_count = (int) $profile_count;

        if ($rank === 1) {
            return '#1 Ranked';
        }

        if ($rank >= 2 && $rank <= 5 && $profile_count >= 6) {
            return 'Top 5';
        }

        if ($rank >= 2 && $rank <= 3 && ($profile_count === 4 || $profile_count === 5)) {
            return 'Top 3';
        }

        if ($rank >= 6 && $rank <= 10 && $profile_count >= 11) {
            return 'Top 10';
        }

        if ($rank >= 11 && $rank <= 25 && $profile_count >= 50) {
            return 'Top 25';
        }

        return false;
    }
    
    /**
     * Get badge data for SVG generation
     *
     * @param int $post_id Profile post ID
     * @param string $badge_type Badge type (city, state, profile)
     * @return array|false Badge data array or false on failure
     */
    public function get_badge_data($post_id, $badge_type) {
        $post = get_post($post_id);
        if (!$post) {
            return false;
        }
        
        $data = array(
            'rank_label' => '',
            'niche' => 'Dog Trainer', // Default
            'name' => $post->post_title,
            'site' => get_bloginfo('name'),
            'location' => '',
            'profile_url' => get_permalink($post_id),
        );
        
        // Get niche term
        $niche_terms = get_the_terms($post_id, 'niche');
        if (!empty($niche_terms) && !is_wp_error($niche_terms)) {
            $data['niche'] = $niche_terms[0]->name;
        }
        
        // Get area term for location
        $primary_area_term = DH_Taxonomy_Helpers::get_primary_area_term($post_id);
        if ($primary_area_term) {
            $data['location'] = $primary_area_term->name;
        }
        
        // Badge-specific data
        if ($badge_type === 'city') {
            $city_rank = get_field('city_rank', $post_id);
            
            if ($city_rank && $city_rank != 99999) {
                // Get city listing post (cached)
                $city_post_id = $this->get_city_listing_id($post_id, $primary_area_term);
                
                if ($city_post_id) {
                    $profile_count = (int) get_post_meta($city_post_id, '_profile_count', true);
                    $tier_label = $this->get_ranking_tier_label($city_rank, $profile_count);
                    
                    // Set rank label (may be empty if dropped out of tier)
                    $data['rank_label'] = $tier_label ? $tier_label : '';
                    
                    // Link to city-listing page
                    $data['profile_url'] = get_permalink($city_post_id);
                }
            }
            
        } elseif ($badge_type === 'state') {
            $state_rank = get_field('state_rank', $post_id);
            $state_terms = get_the_terms($post_id, 'state');
            
            // Use state description or name for location
            if (!empty($state_terms) && !is_wp_error($state_terms)) {
                $data['location'] = !empty($state_terms[0]->description) 
                    ? $state_terms[0]->description 
                    : $state_terms[0]->name;
            }
            
            if ($state_rank && $state_rank != 99999) {
                // Get state listing post (cached)
                if (!empty($state_terms) && !is_wp_error($state_terms)) {
                    $state_post_id = $this->get_state_listing_id($post_id, $state_terms[0]);
                    
                    if ($state_post_id) {
                        $profile_count = (int) get_post_meta($state_post_id, '_profile_count', true);
                        $tier_label = $this->get_ranking_tier_label($state_rank, $profile_count);
                        
                        // Set rank label (may be empty if dropped out of tier)
                        $data['rank_label'] = $tier_label ? $tier_label : '';
                        
                        // Link to state-listing page
                        $data['profile_url'] = get_permalink($state_post_id);
                    }
                }
            }
            
        } elseif ($badge_type === 'profile') {
            $featured = get_field('featured', $post_id);
            $data['rank_label'] = ($featured && $featured > 0) ? 'Featured' : 'Recognized';
        }
        
        return $data;
    }
    
    /**
     * Get template file path based on rank label
     *
     * @param string $rank_label Rank label (e.g., "Top 1", "Top 3", "Featured", "Recognized")
     * @return string Template file path
     */
    private function get_template_path($rank_label) {
        $template_dir = plugin_dir_path(__FILE__) . 'templates/';
        
        // Map rank labels to template files
        $template_map = array(
            '#1 Ranked'      => 'top-1-template.svg',
            'Top 3'      => 'top-3-template.svg',
            'Top 5'      => 'top-5-template.svg',
            'Top 10'     => 'top-10-template.svg',
            'Top 25'     => 'top-25-template.svg',
            'Featured'   => 'featured-template.svg',
            'Recognized' => 'recognized-template.svg',
        );
        
        $template_file = isset($template_map[$rank_label]) ? $template_map[$rank_label] : 'recognized-template.svg';
        
        return $template_dir . $template_file;
    }
    
    /**
     * Generate SVG badge from template
     *
     * @param array $data Badge data
     * @param bool $active Whether to strip internal <a> tag (for nested embeds)
     * @return string SVG markup
     */
    private function generate_badge_svg($data, $active = false) {
        $rank_label = $data['rank_label'];
        $name = $data['name']; // Trainer name (post title)
        $location = $data['location']; // City or state name
        
        // Get template file
        $template_path = $this->get_template_path($rank_label);
        
        // Check if template exists
        if (!file_exists($template_path)) {
            // Fallback to old SVG generation if template doesn't exist
            return $this->generate_badge_svg_legacy($data, $active);
        }
        
        // Read template
        $svg = @file_get_contents($template_path);
        if ($svg === false) {
            // File read failed, use legacy fallback
            return $this->generate_badge_svg_legacy($data, $active);
        }
        
        // Use DOM to modify SVG: add title/desc and replace text elements with foreignObject
        $dom = new DOMDocument();
        libxml_use_internal_errors(true); // Suppress XML warnings
        $load_result = $dom->loadXML($svg);
        libxml_clear_errors();
        
        if ($load_result === false) {
            // XML parsing failed, use legacy fallback
            return $this->generate_badge_svg_legacy($data, $active);
        }
        
        // Find the root SVG element
        $svgElement = $dom->getElementsByTagName('svg')->item(0);
        
        if ($svgElement) {
            // Create title element
            $titleElement = $dom->createElement('title');
            $titleElement->textContent = $data['rank_label'] . ' ' . $data['niche'] . ' Badge - ' . $location . ' - ' . $name;
            $svgElement->insertBefore($titleElement, $svgElement->firstChild);
            
            // Create desc element
            $descElement = $dom->createElement('desc');
            $descElement->textContent = 'Award badge indicating recognition level for ' . $data['niche'] . ' services';
            $svgElement->insertBefore($descElement, $titleElement->nextSibling);
            
            // Find and replace text elements with foreignObject
            $xpath = new DOMXPath($dom);
            
            // Replace LOCATION text element
            $locationGroup = $xpath->query("//*[local-name()='g' and @id='CITY-DYNAMIC-TEXT']")->item(0);
            
            if ($locationGroup) {
                $foreignObjectLocation = $dom->createElement('foreignObject');
                $foreignObjectLocation->setAttribute('x', '20');
                $foreignObjectLocation->setAttribute('y', '79');
                $foreignObjectLocation->setAttribute('width', '115');
                $foreignObjectLocation->setAttribute('height', '50');
                
                $divLocation = $dom->createElement('div');
                $divLocation->setAttribute('xmlns', 'http://www.w3.org/1999/xhtml');
                $divLocation->setAttribute('lang', 'en');
                $divLocation->setAttribute('style', '
                    font-family: Tahoma, sans-serif;
                    font-weight: 700;
                    font-size: 11px;
                    text-align: center;
                    text-transform: uppercase;
                    letter-spacing: -0.05em;
                    line-height: 1.1;
                    word-wrap: break-word;
                ');
                $divLocation->textContent = $location;
                
                $foreignObjectLocation->appendChild($divLocation);
                $locationGroup->parentNode->replaceChild($foreignObjectLocation, $locationGroup);
            }
            
            // Replace TRAINER_NAME text element
            $trainerGroup = $xpath->query("//*[local-name()='g' and @id='Trainer-Name-Dynamic-Text']")->item(0);
            
            if ($trainerGroup) {
                $foreignObjectTrainer = $dom->createElement('foreignObject');
                $foreignObjectTrainer->setAttribute('x', '15');
                $foreignObjectTrainer->setAttribute('y', '124');
                $foreignObjectTrainer->setAttribute('width', '125');
                $foreignObjectTrainer->setAttribute('height', '50');
                
                $divTrainer = $dom->createElement('div');
                $divTrainer->setAttribute('xmlns', 'http://www.w3.org/1999/xhtml');
                $divTrainer->setAttribute('lang', 'en');
                $divTrainer->setAttribute('style', '
                    font-family: Tahoma, sans-serif;
                    font-size: 10px;
                    text-align: center;
                    line-height: 1.1;
                    word-wrap: break-word;
                ');
                $divTrainer->textContent = $name;
                
                $foreignObjectTrainer->appendChild($divTrainer);
                $trainerGroup->parentNode->replaceChild($foreignObjectTrainer, $trainerGroup);
            }
            
            // Save modified SVG
            $svg = $dom->saveXML();
        }
        
        return $svg;
    }
    
    /**
     * Legacy SVG generation (fallback)
     *
     * @param array $data Badge data
     * @param bool $active Whether to strip internal <a> tag (for nested embeds)
     * @return string SVG markup
     */
    private function generate_badge_svg_legacy($data, $active = false) {
        // Escape data for SVG output
        $rank_label = esc_attr($data['rank_label']);
        $niche = esc_attr($data['niche']);
        $name = esc_attr($data['name']);
        $site = esc_attr($data['site']);
        $location = esc_attr($data['location']);
        $url = esc_url($data['profile_url']);
        
        // SVG styling
        $border_color = '#046aae';
        $text_color = '#046aae';
        $bg_color = '#ebf7fe';
        
        // Calculate dynamic height based on whether rank label is present
        $base_y = 30;
        $line_height = 24;
        $padding = 20;
        
        $lines = array();
        if (!empty($rank_label)) {
            $lines[] = array('text' => $rank_label, 'weight' => 'bold', 'size' => 12);
        }
        $lines[] = array('text' => $niche, 'weight' => 'normal', 'size' => 12);
        $lines[] = array('text' => $name, 'weight' => 'bold', 'size' => 12);
        $lines[] = array('text' => $site, 'weight' => 'normal', 'size' => 12);
        $lines[] = array('text' => $location, 'weight' => 'normal', 'size' => 12);
        
        $total_lines = count($lines);
        $height = ($total_lines * $line_height) + ($padding * 2);
        $width = 125;
        
        // Start SVG
        $svg = '<?xml version="1.0" encoding="UTF-8"?>';
        $svg .= '<svg xmlns="http://www.w3.org/2000/svg" width="' . $width . '" height="' . $height . '" viewBox="0 0 ' . $width . ' ' . $height . '">';
        
        // Add title and description for accessibility
        $svg .= '<title>' . esc_attr($data['rank_label'] . ' ' . $data['niche'] . ' Badge - ' . $data['location'] . ' - ' . $data['name']) . '</title>';
        $svg .= '<desc>' . esc_attr('Award badge indicating recognition level for ' . $data['niche'] . ' services') . '</desc>';
        
        // No clickable link in SVG (badges displayed on page should not be hyperlinked)
        
        // Background with rounded corners and border
        $svg .= '<rect x="1" y="1" width="' . ($width - 2) . '" height="' . ($height - 2) . '" rx="8" ry="8" fill="' . $bg_color . '" stroke="' . $border_color . '" stroke-width="2"/>';
        
        // Add text lines
        $y = $base_y;
        foreach ($lines as $line) {
            $font_size = $line['size'];
            $font_weight = $line['weight'];
            $text = $line['text'];
            
            $svg .= '<text x="' . ($width / 2) . '" y="' . $y . '" font-family="Arial, sans-serif" font-size="' . $font_size . '" font-weight="' . $font_weight . '" fill="' . $text_color . '" text-anchor="middle">';
            $svg .= $text;
            $svg .= '</text>';
            
            $y += $line_height;
        }
        
        // Close SVG
        $svg .= '</svg>';
        
        return $svg;
    }
    
    /**
     * Accolades shortcode - displays badges without embed code
     * Excludes "recognized" (profile) badge
     * INLINES SVG for instant rendering (no HTTP requests)
     *
     * @param array $atts Shortcode attributes
     * @return string HTML output
     */
    public function accolades_shortcode($atts) {
        if (!is_singular('profile')) {
            return '';
        }
        
        $post_id = get_the_ID();
        $eligible = $this->get_eligible_badges($post_id);
        
        $badge_types = array('city', 'state');
        
        // Include profile badge only if featured
        $profile_data = $this->get_badge_data($post_id, 'profile');
        if ($profile_data && $profile_data['rank_label'] === 'Featured') {
            $badge_types[] = 'profile';
        }
        $has_badges = false;
        $output = '';
        
        foreach ($badge_types as $type) {
            if ($eligible[$type]) {
                $has_badges = true;
                
                // Get cached SVG directly and inline it (no HTTP request!)
                $cache_key = 'dh_badge_' . $post_id . '_' . $type;
                $svg = wp_cache_get($cache_key, 'dh_badges');
                
                // Always get badge data (needed for aria-label)
                $data_cache_key = 'dh_badge_data_' . $post_id . '_' . $type;
                $badge_data = wp_cache_get($data_cache_key, 'dh_badges');
                
                if ($badge_data === false) {
                    $badge_data = $this->get_badge_data($post_id, $type);
                    if ($badge_data) {
                        wp_cache_set($data_cache_key, $badge_data, 'dh_badges', $this->cache_ttl);
                    }
                }
                
                if ($svg === false && $badge_data) {
                    // Generate SVG if not cached
                    $svg = $this->generate_badge_svg($badge_data, false);
                    if ($svg) {
                        wp_cache_set($cache_key, $svg, 'dh_badges', $this->cache_ttl);
                    }
                }
                
                if ($svg && $badge_data) {
                    $aria_label = esc_attr($badge_data['rank_label'] . ' ' . $badge_data['niche'] . ' in ' . $badge_data['location'] . ' - ' . $badge_data['name']);
                    $output .= '<div class="dh-badge dh-badge-' . esc_attr($type) . '" style="display: inline-block;" role="img" aria-label="' . $aria_label . '">' . $svg . '</div>';
                }
            }
        }
        
        // Only return output if there are badges to show
        if (!$has_badges) {
            return '';
        }
        
        // Get niche for container aria-label (use first available badge_data)
        $container_aria_label = '';
        foreach ($badge_types as $type) {
            if ($eligible[$type]) {
                $data_cache_key = 'dh_badge_data_' . $post_id . '_' . $type;
                $badge_data = wp_cache_get($data_cache_key, 'dh_badges');
                if ($badge_data === false) {
                    $badge_data = $this->get_badge_data($post_id, $type);
                }
                if ($badge_data && !empty($badge_data['niche'])) {
                    $container_aria_label = esc_attr($badge_data['niche'] . ' rankings and recognition');
                    break;
                }
            }
        }
        
        $container_attrs = 'class="dh-accolades" role="list"';
        if ($container_aria_label) {
            $container_attrs .= ' aria-label="' . $container_aria_label . '"';
        }
        
        return '<div ' . $container_attrs . '>' . $output . '</div>';
    }
    
    /**
     * Celebration shortcode - displays badges with embed code copy buttons
     * INLINES SVG for instant rendering (no HTTP requests)
     *
     * @param array $atts Shortcode attributes
     * @return string HTML output
     */
    public function celebration_shortcode($atts) {
        if (!is_singular('profile')) {
            return '';
        }
        
        $post_id = get_the_ID();
        $eligible = $this->get_eligible_badges($post_id);
        
        // Determine badge types to display (do this once)
        $badge_types = array('city', 'state');
        
        // Include profile badge if featured, or if recognized and no other badges
        $profile_data = $this->get_badge_data($post_id, 'profile');
        if ($profile_data) {
            $has_other_badges = $eligible['city'] || $eligible['state'];
            if ($profile_data['rank_label'] === 'Featured' || (!$has_other_badges && $profile_data['rank_label'] === 'Recognized')) {
                $badge_types[] = 'profile';
            }
        }
        
        // Get niche for container aria-label (use first available badge_data)
        $container_aria_label = '';
        foreach ($badge_types as $type) {
            if ($eligible[$type]) {
                $data_cache_key = 'dh_badge_data_' . $post_id . '_' . $type;
                $badge_data = wp_cache_get($data_cache_key, 'dh_badges');
                if ($badge_data === false) {
                    $badge_data = $this->get_badge_data($post_id, $type);
                }
                if ($badge_data && !empty($badge_data['niche'])) {
                    $container_aria_label = esc_attr($badge_data['niche'] . ' recognition badge embed code');
                    break;
                }
            }
        }
        
        $container_attrs = 'class="dh-celebration" role="list"';
        if ($container_aria_label) {
            $container_attrs .= ' aria-label="' . $container_aria_label . '"';
        }
        
        $output = '<div ' . $container_attrs . '>';
        foreach ($badge_types as $type) {
            if ($eligible[$type]) {
                // Get cached SVG directly and inline it (no HTTP request!)
                $cache_key = 'dh_badge_' . $post_id . '_' . $type;
                $svg = wp_cache_get($cache_key, 'dh_badges');
                
                if ($svg === false) {
                    // Generate if not cached
                    $data_cache_key = 'dh_badge_data_' . $post_id . '_' . $type;
                    $badge_data = wp_cache_get($data_cache_key, 'dh_badges');
                    
                    if ($badge_data === false) {
                        $badge_data = $this->get_badge_data($post_id, $type);
                        if ($badge_data) {
                            wp_cache_set($data_cache_key, $badge_data, 'dh_badges', $this->cache_ttl);
                        }
                    }
                    
                    if ($badge_data) {
                        $svg = $this->generate_badge_svg($badge_data, false);
                        wp_cache_set($cache_key, $svg, 'dh_badges', $this->cache_ttl);
                    }
                } else {
                    // Also need badge data for embed code
                    $data_cache_key = 'dh_badge_data_' . $post_id . '_' . $type;
                    $badge_data = wp_cache_get($data_cache_key, 'dh_badges');
                    
                    if ($badge_data === false) {
                        $badge_data = $this->get_badge_data($post_id, $type);
                        if ($badge_data) {
                            wp_cache_set($data_cache_key, $badge_data, 'dh_badges', $this->cache_ttl);
                        }
                    }
                }
                
                if ($svg && $badge_data) {
                    // Use profile_url from badge data (already set to city/state listing URL in get_badge_data)
                    $target_url = $badge_data['profile_url'];
                    
                    $badge_url_active = home_url('/badge/' . $post_id . '/' . $type . '.svg?active=1');
                    
                    // Generate alt text based on badge type
                    $site_title = get_bloginfo('name');
                    if ($badge_data['rank_label'] === 'Featured' || $badge_data['rank_label'] === 'Recognized') {
                        // Featured/Recognized badge format: site_title rank_label niche in location - name
                        $alt_text = $site_title . ' ' . $badge_data['rank_label'] . ' ' . $badge_data['niche'] . ' in ' . $badge_data['location'] . ' - ' . $badge_data['name'];
                    } else {
                        // City/State ranking badge format: site_title Top niches in location
                        $alt_text = $site_title . ' Top ' . $badge_data['niche'] . 's in ' . $badge_data['location'];
                    }
                    
                    // Generate embed code with active=1 to prevent nested links
                    $embed_code = '<a style="display: inline-block; margin: 3px; vertical-align: middle;" href="' . esc_url($target_url) . '" target="_blank" rel="noopener"><img src="' . esc_url($badge_url_active) . '" alt="' . esc_attr($alt_text) . '" width="125" height="auto" /></a>';
                    
                    $output .= '<div class="dh-badge-wrap">';
                    $aria_label = esc_attr($badge_data['rank_label'] . ' ' . $badge_data['niche'] . ' in ' . $badge_data['location'] . ' - ' . $badge_data['name']);
                    $output .= '<div class="dh-badge dh-badge-' . esc_attr($type) . '" style="display: inline-block;" role="img" aria-label="' . $aria_label . '">' . $svg . '</div>';
                    $output .= '<button type="button" class="dh-copy-embed" data-embed-code="' . esc_attr($embed_code) . '">Copy Embed Code</button>';
                    $output .= '</div>';
                }
            }
        }
        
        $output .= '</div>';
        
        // Add help text div underneath the celebration container
        $output .= '<div class="dh-embed-help" style="display: none; margin-top: 20px; padding: 15px; background: #f9f9f9; border: 1px solid #ddd; border-radius: 4px; font-size: 14px; line-height: 1.4; max-width: 600px;"><p style="margin:0 0 1em 0">Paste the embed code into your website / CMS wherever it accepts HTML code. For example, in WordPress, add an HTML block and paste the code there. If there\'s no HTML option, look for text boxes or code boxes that accept simple HTML.</p><p>To change the size, simply change the value for width="125". To align multiple badges in a row, paste each code next to each other with no spaces in between.</p></div>';
        
        return $output;
    }
    
    /**
     * Best location ranked name shortcode
     * Returns city name if ranked in city, otherwise state name if ranked in state,
     * otherwise falls back to city name
     *
     * @param array $atts Shortcode attributes
     * @return string Location name
     */
    public function best_location_ranked_name_shortcode($atts) {
        if (!is_singular('profile')) {
            return '';
        }
        
        $post_id = get_the_ID();
        $eligible = $this->get_eligible_badges($post_id);
        
        // Priority: City first, then State, then fallback to City
        if ($eligible['city']) {
            // Return city name
            $primary_area_term = DH_Taxonomy_Helpers::get_primary_area_term($post_id);
            if ($primary_area_term) {
                return esc_html($primary_area_term->name);
            }
        } elseif ($eligible['state']) {
            // Return state name
            $state_terms = get_the_terms($post_id, 'state');
            if (!empty($state_terms) && !is_wp_error($state_terms)) {
                return esc_html($state_terms[0]->name);
            }
        }
        
        // No ranking badges - fallback to city name
        $primary_area_term = DH_Taxonomy_Helpers::get_primary_area_term($post_id);
        if ($primary_area_term) {
            return esc_html($primary_area_term->name);
        }
        
        return '';
    }
    
    /**
     * Enqueue frontend scripts for celebration shortcode
     */
    public function enqueue_frontend_scripts() {
        // Only enqueue if on a profile page (shortcodes will check this too)
        if (!is_singular('profile')) {
            return;
        }
        
        wp_add_inline_script('jquery', "
            jQuery(document).ready(function($) {
                $('.dh-copy-embed').on('click', function(e) {
                    e.preventDefault();
                    var button = $(this);
                    var embedCode = button.attr('data-embed-code');
                    var helpDiv = button.closest('.dh-celebration').siblings('.dh-embed-help');
                    
                    // Show help text
                    helpDiv.slideDown(300);
                    
                    // Try modern Clipboard API first
                    if (navigator.clipboard && navigator.clipboard.writeText) {
                        navigator.clipboard.writeText(embedCode).then(function() {
                            button.text('Copied!');
                            setTimeout(function() {
                                button.text('Copy Embed Code');
                            }, 2000);
                        }).catch(function() {
                            // Fallback if clipboard API fails
                            fallbackCopy(embedCode, button);
                        });
                    } else {
                        fallbackCopy(embedCode, button);
                    }
                });
                
                function fallbackCopy(text, button) {
                    var temp = $('<textarea>');
                    $('body').append(temp);
                    temp.val(text).select();
                    document.execCommand('copy');
                    temp.remove();
                    button.text('Copied!');
                    setTimeout(function() {
                        button.text('Copy Embed Code');
                    }, 2000);
                }
            });
        ");
    }
    
    /**
     * Clear badge cache when profile is saved
     *
     * @param int $post_id Post ID
     */
    public function clear_badge_cache_on_save($post_id) {
        // Only process profiles
        if (get_post_type($post_id) !== 'profile') {
            return;
        }
        
        // Clear wp_cache for all badge types (SVG + data cache)
        $badge_types = array('city', 'state', 'profile');
        foreach ($badge_types as $type) {
            // Clear SVG cache
            wp_cache_delete('dh_badge_' . $post_id . '_' . $type, 'dh_badges');
            wp_cache_delete('dh_badge_' . $post_id . '_' . $type . '_active', 'dh_badges');
            // Clear badge data cache
            wp_cache_delete('dh_badge_data_' . $post_id . '_' . $type, 'dh_badges');
        }
        
        // Clear eligibility cache
        wp_cache_delete('dh_badge_eligible_' . $post_id, 'dh_badges');
        
        // Clear city/state listing caches if they might have changed
        $primary_area_term = DH_Taxonomy_Helpers::get_primary_area_term($post_id);
        if ($primary_area_term) {
            wp_cache_delete('dh_city_listing_' . $primary_area_term->term_id, 'dh_badges');
        }
        
        $state_terms = get_the_terms($post_id, 'state');
        if (!empty($state_terms) && !is_wp_error($state_terms)) {
            wp_cache_delete('dh_state_listing_' . $state_terms[0]->slug, 'dh_badges');
        }
    }
}

// Initialize the module
new DH_Profile_Badges();
