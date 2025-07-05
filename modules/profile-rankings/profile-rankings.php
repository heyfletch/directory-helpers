<?php
/**
 * Profile Rankings Module
 * 
 * Calculates and stores rankings for profiles based on ratings and review counts
 * within their respective cities and states.
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Profile Rankings Module Class
 */
class DH_Profile_Rankings {
    /**
     * Text domain
     */
    private $text_domain;
    
    /**
     * Cached translations
     */
    private $translations = array();
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->text_domain = 'directory-helpers';
        
        // Cache translations to avoid early textdomain loading
        $this->cache_translations();
        
        // Register settings
        add_action('admin_init', array($this, 'register_settings'));
        
        // Register cron job for recalculating rankings
        add_action('init', array($this, 'init'), 20);
        
        // Add custom cron schedule
        add_filter('cron_schedules', array($this, 'add_cron_schedule'));
        
        // Register the cron action
        add_action('dh_recalculate_rankings', array($this, 'recalculate_all_rankings'));
        
        // Register shortcodes
        add_shortcode('dh_city_rank', array($this, 'city_rank_shortcode'));
        add_shortcode('dh_state_rank', array($this, 'state_rank_shortcode'));
    }
    
    /**
     * Initialize module
     */
    public function init() {
        // Cache translations after textdomain is loaded
        $this->cache_translations();
        
        // Schedule the cron job if not already scheduled
        if (!wp_next_scheduled('dh_recalculate_rankings')) {
            wp_schedule_event(time(), 'daily', 'dh_recalculate_rankings');
        }
    }
    
    /**
     * Add custom cron schedule
     */
    public function add_cron_schedule($schedules) {
        // Add daily schedule if it doesn't exist
        if (!isset($schedules['daily'])) {
            $schedules['daily'] = array(
                'interval' => 86400, // 24 hours in seconds
                'display'  => __('Once Daily', 'directory-helpers')
            );
        }
        
        return $schedules;
    }
    
    /**
     * Cache translations to avoid early textdomain loading
     */
    private function cache_translations() {
        $this->translations = array(
            'profile_rankings' => __('Profile Rankings', $this->text_domain),
            'profile_rankings_description' => __('Calculates and stores rankings for profiles based on ratings and review counts within their respective cities and states.', $this->text_domain),
            'ranking_settings' => __('Ranking Settings', $this->text_domain),
            'ranking_settings_description' => __('Configure how profile rankings are calculated.', $this->text_domain),
            'rating_weight' => __('Rating Weight', $this->text_domain),
            'rating_weight_description' => __('Weight given to the rating score (1-10). Higher values prioritize rating over review count.', $this->text_domain),
            'review_count_weight' => __('Review Count Weight', $this->text_domain),
            'review_count_weight_description' => __('Weight given to the review count (1-10). Higher values prioritize review count over rating.', $this->text_domain),
            'acf_rating_field' => __('ACF Rating Field', $this->text_domain),
            'acf_rating_field_description' => __('The ACF field name that contains the profile rating.', $this->text_domain),
            'acf_review_count_field' => __('ACF Review Count Field', $this->text_domain),
            'acf_review_count_field_description' => __('The ACF field name that contains the profile review count.', $this->text_domain),
            'recalculate_rankings' => __('Recalculate Rankings', $this->text_domain),
            'recalculate_rankings_description' => __('Manually trigger recalculation of all profile rankings.', $this->text_domain),
            'rankings_recalculated' => __('Profile rankings have been recalculated.', $this->text_domain)
        );
    }
    
    /**
     * Register settings
     */
    public function register_settings() {
        // Register settings
        register_setting(
            'directory_helpers_profile_rankings',
            'directory_helpers_profile_rankings',
            array($this, 'sanitize_settings')
        );
        
        // Add settings section
        add_settings_section(
            'dh_profile_rankings_settings',
            $this->translations['ranking_settings'],
            array($this, 'render_settings_section'),
            'directory_helpers_profile_rankings'
        );
        
        // Add settings fields
        add_settings_field(
            'rating_weight',
            $this->translations['rating_weight'],
            array($this, 'render_rating_weight_field'),
            'directory_helpers_profile_rankings',
            'dh_profile_rankings_settings'
        );
        
        add_settings_field(
            'review_count_weight',
            $this->translations['review_count_weight'],
            array($this, 'render_review_count_weight_field'),
            'directory_helpers_profile_rankings',
            'dh_profile_rankings_settings'
        );
        
        add_settings_field(
            'acf_rating_field',
            $this->translations['acf_rating_field'],
            array($this, 'render_acf_rating_field'),
            'directory_helpers_profile_rankings',
            'dh_profile_rankings_settings'
        );
        
        add_settings_field(
            'acf_review_count_field',
            $this->translations['acf_review_count_field'],
            array($this, 'render_acf_review_count_field'),
            'directory_helpers_profile_rankings',
            'dh_profile_rankings_settings'
        );
        
        add_settings_field(
            'recalculate_rankings',
            $this->translations['recalculate_rankings'],
            array($this, 'render_recalculate_rankings_field'),
            'directory_helpers_profile_rankings',
            'dh_profile_rankings_settings'
        );
    }
    
    /**
     * Sanitize settings
     */
    public function sanitize_settings($input) {
        $sanitized = array();
        
        // Sanitize rating weight (1-10)
        $sanitized['rating_weight'] = isset($input['rating_weight']) 
            ? max(1, min(10, intval($input['rating_weight']))) 
            : 7; // Default value
        
        // Sanitize review count weight (1-10)
        $sanitized['review_count_weight'] = isset($input['review_count_weight']) 
            ? max(1, min(10, intval($input['review_count_weight']))) 
            : 3; // Default value
        
        // Sanitize ACF field names
        $sanitized['acf_rating_field'] = isset($input['acf_rating_field']) 
            ? sanitize_text_field($input['acf_rating_field']) 
            : 'rating';
        
        $sanitized['acf_review_count_field'] = isset($input['acf_review_count_field']) 
            ? sanitize_text_field($input['acf_review_count_field']) 
            : 'review_count';
        
        // Check if recalculation was requested
        if (isset($input['recalculate']) && $input['recalculate'] == '1') {
            // Trigger recalculation
            $this->recalculate_all_rankings();
            
            // Add success message
            add_settings_error(
                'directory_helpers_profile_rankings',
                'rankings_recalculated',
                $this->translations['rankings_recalculated'],
                'updated'
            );
        }
        
        return $sanitized;
    }
    
    /**
     * Render settings section
     */
    public function render_settings_section() {
        echo '<p>' . esc_html($this->translations['ranking_settings_description']) . '</p>';
    }
    
    /**
     * Render rating weight field
     */
    public function render_rating_weight_field() {
        $options = get_option('directory_helpers_profile_rankings', array(
            'rating_weight' => 7 // Default value
        ));
        
        echo '<input type="number" min="1" max="10" name="directory_helpers_profile_rankings[rating_weight]" value="' . 
            esc_attr($options['rating_weight']) . '" class="small-text" />';
        echo '<p class="description">' . esc_html($this->translations['rating_weight_description']) . '</p>';
    }
    
    /**
     * Render review count weight field
     */
    public function render_review_count_weight_field() {
        $options = get_option('directory_helpers_profile_rankings', array(
            'review_count_weight' => 3 // Default value
        ));
        
        echo '<input type="number" min="1" max="10" name="directory_helpers_profile_rankings[review_count_weight]" value="' . 
            esc_attr($options['review_count_weight']) . '" class="small-text" />';
        echo '<p class="description">' . esc_html($this->translations['review_count_weight_description']) . '</p>';
    }
    
    /**
     * Render ACF rating field
     */
    public function render_acf_rating_field() {
        $options = get_option('directory_helpers_profile_rankings', array(
            'acf_rating_field' => 'rating' // Default value
        ));
        
        echo '<input type="text" name="directory_helpers_profile_rankings[acf_rating_field]" value="' . 
            esc_attr($options['acf_rating_field']) . '" class="regular-text" />';
        echo '<p class="description">' . esc_html($this->translations['acf_rating_field_description']) . '</p>';
    }
    
    /**
     * Render ACF review count field
     */
    public function render_acf_review_count_field() {
        $options = get_option('directory_helpers_profile_rankings', array(
            'acf_review_count_field' => 'review_count' // Default value
        ));
        
        echo '<input type="text" name="directory_helpers_profile_rankings[acf_review_count_field]" value="' . 
            esc_attr($options['acf_review_count_field']) . '" class="regular-text" />';
        echo '<p class="description">' . esc_html($this->translations['acf_review_count_field_description']) . '</p>';
    }
    
    /**
     * Render recalculate rankings field
     */
    public function render_recalculate_rankings_field() {
        echo '<input type="checkbox" name="directory_helpers_profile_rankings[recalculate]" value="1" />';
        echo '<p class="description">' . esc_html($this->translations['recalculate_rankings_description']) . '</p>';
    }
    
    /**
     * Calculate ranking score for a profile
     * 
     * Uses a simplified formula that prioritizes rating but also considers review count:
     * score = rating * (1 + (review_count / 100))
     * 
     * This gives profiles with more reviews a boost while keeping rating as the primary factor.
     */
    public function calculate_ranking_score($rating, $review_count) {
        // Simple formula that prioritizes rating but gives weight to review count
        $score = floatval($rating) * (1 + (intval($review_count) / 100));
        
        return $score;
    }
    
    /**
     * Get average rating across all profiles
     */
    public function get_average_rating() {
        // Try to get from cache
        $avg_rating = get_transient('dh_average_profile_rating');
        
        if ($avg_rating === false) {
            // Need to calculate
            $options = get_option('directory_helpers_profile_rankings', array(
                'acf_rating_field' => 'rating'
            ));
            
            $acf_rating_field = $options['acf_rating_field'];
            
            // Get all profiles
            $profiles = get_posts(array(
                'post_type' => 'profile',
                'posts_per_page' => -1,
                'fields' => 'ids'
            ));
            
            $total_rating = 0;
            $count = 0;
            
            // Calculate average rating
            foreach ($profiles as $profile_id) {
                $rating = get_field($acf_rating_field, $profile_id);
                
                if ($rating) {
                    $total_rating += floatval($rating);
                    $count++;
                }
            }
            
            $avg_rating = $count > 0 ? $total_rating / $count : 0;
            
            // Cache for 24 hours
            set_transient('dh_average_profile_rating', $avg_rating, 24 * HOUR_IN_SECONDS);
        }
        
        return $avg_rating;
    }
    
    /**
     * Recalculate rankings for all profiles
     */
    public function recalculate_all_rankings() {
        // Clear average rating cache
        delete_transient('dh_average_profile_rating');
        
        // Get settings
        $options = get_option('directory_helpers_profile_rankings', array(
            'acf_rating_field' => 'rating',
            'acf_review_count_field' => 'review_count'
        ));
        
        $acf_rating_field = $options['acf_rating_field'];
        $acf_review_count_field = $options['acf_review_count_field'];
        
        // Get all profiles
        $profiles = get_posts(array(
            'post_type' => 'profile',
            'posts_per_page' => -1
        ));
        
        // Group profiles by city and state
        $city_profiles = array();
        $state_profiles = array();
        
        foreach ($profiles as $profile) {
            // Get profile data
            $profile_id = $profile->ID;
            $rating = get_field($acf_rating_field, $profile_id);
            $review_count = get_field($acf_review_count_field, $profile_id);
            
            // Skip if no rating or review count
            if (!$rating || !$review_count) {
                continue;
            }
            
            // Calculate ranking score
            $score = $this->calculate_ranking_score($rating, $review_count);
            
            // Get city terms
            $city_terms = get_the_terms($profile_id, 'area');
            if (!empty($city_terms) && !is_wp_error($city_terms)) {
                foreach ($city_terms as $city_term) {
                    if (!isset($city_profiles[$city_term->term_id])) {
                        $city_profiles[$city_term->term_id] = array();
                    }
                    
                    $city_profiles[$city_term->term_id][] = array(
                        'profile_id' => $profile_id,
                        'score' => $score
                    );
                }
            }
            
            // Get state terms
            $state_terms = get_the_terms($profile_id, 'state');
            if (!empty($state_terms) && !is_wp_error($state_terms)) {
                foreach ($state_terms as $state_term) {
                    if (!isset($state_profiles[$state_term->term_id])) {
                        $state_profiles[$state_term->term_id] = array();
                    }
                    
                    $state_profiles[$state_term->term_id][] = array(
                        'profile_id' => $profile_id,
                        'score' => $score
                    );
                }
            }
        }
        
        // Calculate rankings for each city
        foreach ($city_profiles as $city_term_id => $city_profile_data) {
            // Sort profiles by score (descending)
            usort($city_profile_data, function($a, $b) {
                return $b['score'] <=> $a['score'];
            });
            
            // Assign rankings
            foreach ($city_profile_data as $rank => $profile_data) {
                // Rank is 0-based, so add 1
                update_post_meta($profile_data['profile_id'], '_dh_city_rank_' . $city_term_id, $rank + 1);
                update_post_meta($profile_data['profile_id'], '_dh_city_rank_score_' . $city_term_id, $profile_data['score']);
            }
        }
        
        // Calculate rankings for each state
        foreach ($state_profiles as $state_term_id => $state_profile_data) {
            // Sort profiles by score (descending)
            usort($state_profile_data, function($a, $b) {
                return $b['score'] <=> $a['score'];
            });
            
            // Assign rankings
            foreach ($state_profile_data as $rank => $profile_data) {
                // Rank is 0-based, so add 1
                update_post_meta($profile_data['profile_id'], '_dh_state_rank_' . $state_term_id, $rank + 1);
                update_post_meta($profile_data['profile_id'], '_dh_state_rank_score_' . $state_term_id, $profile_data['score']);
            }
        }
    }
    
    /**
     * Get city ranking for a profile
     */
    public function get_city_ranking($profile_id, $city_term_id = null) {
        // If city term ID is not provided, try to get it from the profile
        if ($city_term_id === null) {
            $city_terms = get_the_terms($profile_id, 'area');
            if (empty($city_terms) || is_wp_error($city_terms)) {
                return false;
            }
            $city_term_id = $city_terms[0]->term_id;
        }
        
        // Get ranking
        $rank = get_post_meta($profile_id, '_dh_city_rank_' . $city_term_id, true);
        $score = get_post_meta($profile_id, '_dh_city_rank_score_' . $city_term_id, true);
        
        return array(
            'rank' => $rank ? intval($rank) : false,
            'score' => $score ? floatval($score) : false
        );
    }
    
    /**
     * Get state ranking for a profile
     */
    public function get_state_ranking($profile_id, $state_term_id = null) {
        // If state term ID is not provided, try to get it from the profile
        if ($state_term_id === null) {
            $state_terms = get_the_terms($profile_id, 'state');
            if (empty($state_terms) || is_wp_error($state_terms)) {
                return false;
            }
            $state_term_id = $state_terms[0]->term_id;
        }
        
        // Get ranking
        $rank = get_post_meta($profile_id, '_dh_state_rank_' . $state_term_id, true);
        $score = get_post_meta($profile_id, '_dh_state_rank_score_' . $state_term_id, true);
        
        return array(
            'rank' => $rank ? intval($rank) : false,
            'score' => $score ? floatval($score) : false
        );
    }
    
    /**
     * City rank shortcode callback
     * 
     * Usage: [dh_city_rank]
     * Returns the rank with city name, e.g., "#12 in Westport, MA"
     */
    public function city_rank_shortcode($atts) {
        // Get current profile ID
        $profile_id = get_the_ID();
        
        if (!$profile_id) {
            return '#1 in City'; // Default fallback
        }
        
        // Check if this is a profile or allow other post types too
        if (get_post_type($profile_id) !== 'profile') {
            // For testing, allow any post type
            // In production, uncomment the following line:
            // return '#1 in City';
        }
        
        // Get city terms from the profile (same as used in breadcrumbs)
        $city_terms = get_the_terms($profile_id, 'area');
        if (empty($city_terms) || is_wp_error($city_terms)) {
            return '#1 in City'; // Default fallback
        }
        
        // Use the first city term (same as breadcrumbs)
        $city_term = $city_terms[0];
        $city_term_id = $city_term->term_id;
        $city_name = $city_term->name;
        
        // Try to get state for this city
        $state_name = '';
        $city_post = get_posts(array(
            'post_type' => 'city-listing',
            'posts_per_page' => 1,
            'tax_query' => array(
                array(
                    'taxonomy' => 'area',
                    'field' => 'term_id',
                    'terms' => $city_term_id
                )
            )
        ));
        
        if (!empty($city_post)) {
            $state_terms = get_the_terms($city_post[0]->ID, 'state');
            if (!empty($state_terms) && !is_wp_error($state_terms)) {
                $state_name = $state_terms[0]->name;
            }
        }
        
        // Get cached ranking or calculate it
        $rank = get_post_meta($profile_id, '_dh_city_rank_' . $city_term_id, true);
        
        if (!$rank) {
            // If no rank is found, trigger a calculation for this profile
            $this->calculate_profile_rankings($profile_id);
            $rank = get_post_meta($profile_id, '_dh_city_rank_' . $city_term_id, true);
            
            // If still no rank, force a full recalculation
            if (!$rank) {
                $this->recalculate_all_rankings();
                $rank = get_post_meta($profile_id, '_dh_city_rank_' . $city_term_id, true);
            }
            
            // If still no rank, use default
            if (!$rank) {
                $rank = 1; // Default rank
            }
        }
        
        // Format the output
        $output = '#' . intval($rank) . ' in ' . $city_name;
        if ($state_name) {
            $output .= ', ' . $state_name;
        }
        
        return $output;
    }
    
    /**
     * State rank shortcode callback
     * 
     * Usage: [dh_state_rank]
     * Returns the rank with state name, e.g., "#40 in MA"
     */
    public function state_rank_shortcode($atts) {
        // Get current profile ID
        $profile_id = get_the_ID();
        
        if (!$profile_id) {
            return '#1 in State'; // Default fallback
        }
        
        // Check if this is a profile or allow other post types too
        if (get_post_type($profile_id) !== 'profile') {
            // For testing, allow any post type
            // In production, uncomment the following line:
            // return '#1 in State';
        }
        
        // Get state terms from the profile (same as used in breadcrumbs)
        $state_terms = get_the_terms($profile_id, 'state');
        if (empty($state_terms) || is_wp_error($state_terms)) {
            return '#1 in State'; // Default fallback
        }
        
        // Use the first state term (same as breadcrumbs)
        $state_term = $state_terms[0];
        $state_term_id = $state_term->term_id;
        $state_name = $state_term->name;
        
        // Get cached ranking or calculate it
        $rank = get_post_meta($profile_id, '_dh_state_rank_' . $state_term_id, true);
        
        if (!$rank) {
            // If no rank is found, trigger a calculation for this profile
            $this->calculate_profile_rankings($profile_id);
            $rank = get_post_meta($profile_id, '_dh_state_rank_' . $state_term_id, true);
            
            // If still no rank, force a full recalculation
            if (!$rank) {
                $this->recalculate_all_rankings();
                $rank = get_post_meta($profile_id, '_dh_state_rank_' . $state_term_id, true);
            }
            
            // If still no rank, use default
            if (!$rank) {
                $rank = 1; // Default rank
            }
        }
        
        // Format the output
        $output = '#' . intval($rank) . ' in ' . $state_name;
        
        return $output;
    }
    
    /**
     * Calculate rankings for a specific profile
     */
    public function calculate_profile_rankings($profile_id) {
        // Get settings
        $options = get_option('directory_helpers_profile_rankings', array(
            'acf_rating_field' => 'rating',
            'acf_review_count_field' => 'review_count'
        ));
        
        $acf_rating_field = $options['acf_rating_field'];
        $acf_review_count_field = $options['acf_review_count_field'];
        
        // Get profile data
        $rating = get_field($acf_rating_field, $profile_id);
        $review_count = get_field($acf_review_count_field, $profile_id);
        
        // Skip if no rating or review count
        if (!$rating || !$review_count) {
            return;
        }
        
        // Calculate ranking score
        $score = $this->calculate_ranking_score($rating, $review_count);
        
        // Get city terms
        $city_terms = get_the_terms($profile_id, 'area');
        if (!empty($city_terms) && !is_wp_error($city_terms)) {
            foreach ($city_terms as $city_term) {
                $this->calculate_city_ranking($profile_id, $city_term->term_id, $score);
            }
        }
        
        // Get state terms
        $state_terms = get_the_terms($profile_id, 'state');
        if (!empty($state_terms) && !is_wp_error($state_terms)) {
            foreach ($state_terms as $state_term) {
                $this->calculate_state_ranking($profile_id, $state_term->term_id, $score);
            }
        }
    }
    
    /**
     * Calculate city ranking for a profile
     */
    private function calculate_city_ranking($profile_id, $city_term_id, $profile_score) {
        // Get all profiles in this city
        $city_profiles = new WP_Query(array(
            'post_type' => 'profile',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'tax_query' => array(
                array(
                    'taxonomy' => 'area',
                    'field' => 'term_id',
                    'terms' => $city_term_id
                )
            )
        ));
        
        if (empty($city_profiles->posts)) {
            return;
        }
        
        // Get options
        $options = get_option('directory_helpers_profile_rankings', array(
            'acf_rating_field' => 'rating',
            'acf_review_count_field' => 'review_count'
        ));
        
        $acf_rating_field = $options['acf_rating_field'];
        $acf_review_count_field = $options['acf_review_count_field'];
        
        // Calculate scores for all profiles
        $scores = array();
        foreach ($city_profiles->posts as $pid) {
            $rating = get_field($acf_rating_field, $pid);
            $review_count = get_field($acf_review_count_field, $pid);
            
            if ($rating && $review_count) {
                $scores[$pid] = $this->calculate_ranking_score($rating, $review_count);
            }
        }
        
        // Sort by score (descending)
        arsort($scores);
        
        // Find rank of our profile
        $rank = 0;
        foreach ($scores as $pid => $score) {
            $rank++;
            if ($pid == $profile_id) {
                break;
            }
        }
        
        // Save rank
        update_post_meta($profile_id, '_dh_city_rank_' . $city_term_id, $rank);
        update_post_meta($profile_id, '_dh_city_rank_score_' . $city_term_id, $profile_score);
    }
    
    /**
     * Calculate state ranking for a profile
     */
    private function calculate_state_ranking($profile_id, $state_term_id, $profile_score) {
        // Get all profiles in this state
        $state_profiles = new WP_Query(array(
            'post_type' => 'profile',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'tax_query' => array(
                array(
                    'taxonomy' => 'state',
                    'field' => 'term_id',
                    'terms' => $state_term_id
                )
            )
        ));
        
        if (empty($state_profiles->posts)) {
            return;
        }
        
        // Get options
        $options = get_option('directory_helpers_profile_rankings', array(
            'acf_rating_field' => 'rating',
            'acf_review_count_field' => 'review_count'
        ));
        
        $acf_rating_field = $options['acf_rating_field'];
        $acf_review_count_field = $options['acf_review_count_field'];
        
        // Calculate scores for all profiles
        $scores = array();
        foreach ($state_profiles->posts as $pid) {
            $rating = get_field($acf_rating_field, $pid);
            $review_count = get_field($acf_review_count_field, $pid);
            
            if ($rating && $review_count) {
                $scores[$pid] = $this->calculate_ranking_score($rating, $review_count);
            }
        }
        
        // Sort by score (descending)
        arsort($scores);
        
        // Find rank of our profile
        $rank = 0;
        foreach ($scores as $pid => $score) {
            $rank++;
            if ($pid == $profile_id) {
                break;
            }
        }
        
        // Save rank
        update_post_meta($profile_id, '_dh_state_rank_' . $state_term_id, $rank);
        update_post_meta($profile_id, '_dh_state_rank_score_' . $state_term_id, $profile_score);
    }
}
