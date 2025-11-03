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
     * Cache TTL in seconds (24 hours)
     *
     * @var int
     */
    private $cache_ttl = 86400;
    
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
        $this->text_domain = 'directory-helpers';
        
        // Register rewrite rules
        add_action('init', array($this, 'register_rewrite_rules'));
        add_filter('query_vars', array($this, 'register_query_vars'));
        
        // Handle badge requests
        add_action('template_redirect', array($this, 'handle_badge_request'));
        
        // Register shortcodes
        add_shortcode('dh_accolades', array($this, 'accolades_shortcode'));
        add_shortcode('dh_celebration', array($this, 'celebration_shortcode'));
        
        // Enqueue frontend scripts for celebration shortcode
        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_scripts'));
        
        // Add activation notice for flushing rewrite rules
        add_action('admin_notices', array($this, 'activation_notice'));
        
        // Clear badge cache when profile is updated
        add_action('acf/save_post', array($this, 'clear_badge_cache_on_save'), 25);
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
        $dismissed = get_option('dh_badges_rewrite_flushed', false);
        
        if (!$dismissed && current_user_can('manage_options')) {
            ?>
            <div class="notice notice-warning is-dismissible">
                <p>
                    <strong>Directory Helpers - Profile Badges:</strong> 
                    Rewrite rules need to be flushed. Please visit 
                    <a href="<?php echo esc_url(admin_url('options-permalink.php')); ?>">Settings > Permalinks</a> 
                    and click "Save Changes" to activate badge URLs.
                    <a href="<?php echo esc_url(add_query_arg('dh_dismiss_badge_notice', '1')); ?>" style="float:right;">Dismiss</a>
                </p>
            </div>
            <?php
        }
        
        if (isset($_GET['dh_dismiss_badge_notice'])) {
            update_option('dh_badges_rewrite_flushed', true);
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
     *
     * @return string IP address
     */
    private function get_client_ip() {
        $ip = '';
        
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
        } else {
            $ip = $_SERVER['REMOTE_ADDR'];
        }
        
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
        $active = isset($_GET['active']) && $_GET['active'] == '1';
        
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
        
        // Try to get cached SVG using wp_cache (APCu/Memcached if available)
        $cache_key = 'dh_badge_' . $post_id . '_' . $badge_type . ($active ? '_active' : '');
        $svg = wp_cache_get($cache_key, 'dh_badges');
        
        if ($svg === false) {
            // Generate new SVG
            $badge_data = $this->get_badge_data($post_id, $badge_type);
            if (!$badge_data) {
                status_header(500);
                exit('Failed to generate badge');
            }
            
            $svg = $this->generate_badge_svg($badge_data, $active);
            
            // Cache using wp_cache (uses APCu/Memcached if available)
            wp_cache_set($cache_key, $svg, 'dh_badges', $this->cache_ttl);
        }
        
        // Serve SVG with appropriate headers
        header('Content-Type: image/svg+xml');
        header('Cache-Control: public, max-age=' . $this->cache_ttl);
        header('Access-Control-Allow-Origin: *');
        header('X-Content-Type-Options: nosniff');
        
        echo $svg;
        exit;
    }
    
    /**
     * Get eligible badges for a profile
     *
     * @param int $post_id Profile post ID
     * @return array Array of badge eligibility [city => bool, state => bool, profile => bool]
     */
    public function get_eligible_badges($post_id) {
        $eligible = array(
            'city' => false,
            'state' => false,
            'profile' => true, // Always eligible
        );
        
        // Check city rank eligibility
        $city_rank = get_field('city_rank', $post_id);
        $primary_area_term = DH_Taxonomy_Helpers::get_primary_area_term($post_id);
        
        if ($primary_area_term && $city_rank && $city_rank != 99999) {
            // Get profile count for this city
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
            
            if (!empty($city_posts)) {
                $profile_count = (int) get_post_meta($city_posts[0], '_profile_count', true);
                
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
            // Get profile count for this state
            $state_posts = get_posts(array(
                'post_type' => 'state-listing',
                'post_status' => 'publish',
                'posts_per_page' => 1,
                'tax_query' => array(
                    array(
                        'taxonomy' => 'state',
                        'field' => 'slug',
                        'terms' => $state_terms[0]->slug,
                    ),
                ),
                'fields' => 'ids',
            ));
            
            if (!empty($state_posts)) {
                $profile_count = (int) get_post_meta($state_posts[0], '_profile_count', true);
                
                // Use ranking logic to determine if badge should show
                $tier_label = $this->get_ranking_tier_label($state_rank, $profile_count);
                if ($tier_label) {
                    $eligible['state'] = true;
                }
            }
        }
        
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
                // Get profile count
                if ($primary_area_term) {
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
                    
                    if (!empty($city_posts)) {
                        $profile_count = (int) get_post_meta($city_posts[0], '_profile_count', true);
                        $tier_label = $this->get_ranking_tier_label($city_rank, $profile_count);
                        
                        // Set rank label (may be empty if dropped out of tier)
                        $data['rank_label'] = $tier_label ? $tier_label : '';
                        
                        // Link to city-listing page
                        $data['profile_url'] = get_permalink($city_posts[0]);
                    }
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
                // Get profile count
                if (!empty($state_terms) && !is_wp_error($state_terms)) {
                    $state_posts = get_posts(array(
                        'post_type' => 'state-listing',
                        'post_status' => 'publish',
                        'posts_per_page' => 1,
                        'tax_query' => array(
                            array(
                                'taxonomy' => 'state',
                                'field' => 'slug',
                                'terms' => $state_terms[0]->slug,
                            ),
                        ),
                        'fields' => 'ids',
                    ));
                    
                    if (!empty($state_posts)) {
                        $profile_count = (int) get_post_meta($state_posts[0], '_profile_count', true);
                        $tier_label = $this->get_ranking_tier_label($state_rank, $profile_count);
                        
                        // Set rank label (may be empty if dropped out of tier)
                        $data['rank_label'] = $tier_label ? $tier_label : '';
                        
                        // Link to state-listing page
                        $data['profile_url'] = get_permalink($state_posts[0]);
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
     * Generate SVG badge
     *
     * @param array $data Badge data
     * @param bool $active Whether to strip internal <a> tag (for nested embeds)
     * @return string SVG markup
     */
    private function generate_badge_svg($data, $active = false) {
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
            $lines[] = array('text' => $rank_label, 'weight' => 'bold', 'size' => 18);
        }
        $lines[] = array('text' => $niche, 'weight' => 'normal', 'size' => 14);
        $lines[] = array('text' => $name, 'weight' => 'bold', 'size' => 16);
        $lines[] = array('text' => $site, 'weight' => 'normal', 'size' => 14);
        $lines[] = array('text' => $location, 'weight' => 'normal', 'size' => 14);
        
        $total_lines = count($lines);
        $height = ($total_lines * $line_height) + ($padding * 2);
        $width = 250;
        
        // Start SVG
        $svg = '<?xml version="1.0" encoding="UTF-8"?>';
        $svg .= '<svg xmlns="http://www.w3.org/2000/svg" width="' . $width . '" height="' . $height . '" viewBox="0 0 ' . $width . ' ' . $height . '">';
        
        // Add title and description for accessibility
        $svg .= '<title>' . esc_attr($name . ' - ' . $niche) . '</title>';
        $svg .= '<desc>Badge for ' . esc_attr($name) . ' in ' . esc_attr($location) . '</desc>';
        
        // Add clickable link wrapper (only if not active mode)
        if (!$active) {
            $svg .= '<a href="' . $url . '" target="_parent">';
        }
        
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
        
        // Close link (only if not active mode) and SVG
        if (!$active) {
            $svg .= '</a>';
        }
        $svg .= '</svg>';
        
        return $svg;
    }
    
    /**
     * Accolades shortcode - displays badges without embed code
     * Excludes "recognized" (profile) badge
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
        
        // Only show city and state badges (exclude profile/recognized badge)
        $badge_types = array('city', 'state');
        $has_badges = false;
        $output = '';
        
        foreach ($badge_types as $type) {
            if ($eligible[$type]) {
                $has_badges = true;
                $badge_url = home_url('/badge/' . $post_id . '/' . $type . '.svg');
                $alt_text = ucfirst($type) . ' Badge for ' . get_the_title($post_id);
                
                $output .= '<img src="' . esc_url($badge_url) . '" alt="' . esc_attr($alt_text) . '" class="dh-badge dh-badge-' . esc_attr($type) . '" width="250" height="auto" />';
            }
        }
        
        // Only return output if there are badges to show
        if (!$has_badges) {
            return '';
        }
        
        return '<div class="dh-accolades">' . $output . '</div>';
    }
    
    /**
     * Celebration shortcode - displays badges with embed code copy buttons
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
        
        $output = '<div class="dh-celebration">';
        
        $badge_types = array('city', 'state', 'profile');
        foreach ($badge_types as $type) {
            if ($eligible[$type]) {
                $badge_data = $this->get_badge_data($post_id, $type);
                $badge_url = home_url('/badge/' . $post_id . '/' . $type . '.svg');
                $badge_url_active = home_url('/badge/' . $post_id . '/' . $type . '.svg?active=1');
                $alt_text = ucfirst($type) . ' Badge for ' . get_the_title($post_id);
                
                // Determine target URL based on badge type
                $target_url = $badge_data['profile_url'];
                if ($type === 'city' && !empty($badge_data['city_url'])) {
                    $target_url = $badge_data['city_url'];
                } elseif ($type === 'state' && !empty($badge_data['state_url'])) {
                    $target_url = $badge_data['state_url'];
                }
                
                // Generate embed code with active=1 to prevent nested links
                $embed_code = '<a href="' . esc_url($target_url) . '">' . "\n";
                $embed_code .= '  <img src="' . esc_url($badge_url_active) . '" alt="' . esc_attr($alt_text) . '" width="250" height="auto" />' . "\n";
                $embed_code .= '</a>';
                
                $output .= '<div class="dh-badge-wrap">';
                $output .= '<img src="' . esc_url($badge_url) . '" alt="' . esc_attr($alt_text) . '" class="dh-badge dh-badge-' . esc_attr($type) . '" width="250" height="auto" />';
                $output .= '<button type="button" class="dh-copy-embed button" data-embed-code="' . esc_attr($embed_code) . '">Copy Embed Code</button>';
                $output .= '</div>';
            }
        }
        
        $output .= '</div>';
        
        return $output;
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
        
        // Clear wp_cache for all badge types
        $badge_types = array('city', 'state', 'profile');
        foreach ($badge_types as $type) {
            wp_cache_delete('dh_badge_' . $post_id . '_' . $type, 'dh_badges');
            wp_cache_delete('dh_badge_' . $post_id . '_' . $type . '_active', 'dh_badges');
        }
    }
}

// Initialize the module
new DH_Profile_Badges();
