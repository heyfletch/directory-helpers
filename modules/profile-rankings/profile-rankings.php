<?php
/**
 * Profile Rankings Module
 *
 * @package Directory_Helpers
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Profile Rankings class
 */
class DH_Profile_Rankings {
    /**
     * Module text domain
     *
     * @var string
     */
    private $text_domain = 'directory-helpers';
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->text_domain = 'directory-helpers';
        
        // Add shortcodes
        add_shortcode('dh_city_rank', array($this, 'city_rank_shortcode'));
        add_shortcode('dh_state_rank', array($this, 'state_rank_shortcode'));
    }
    
    /**
     * Calculate ranking score
     * 
     * Formula prioritizes rating but also factors in review count
     * A higher weight is given to profiles with more reviews
     * 
     * @param float $rating Rating value (typically 0-5)
     * @param int $review_count Number of reviews
     * @return float Calculated score
     */
    private function calculate_ranking_score($rating, $review_count) {
        // Formula: (rating * 0.8) + (min(1, log10($review_count + 1) / 2) * 5 * 0.2)
        // This gives 80% weight to rating and 20% to review count using logarithmic scaling
        // The log10 function helps prevent reviews from dominating the score while still giving them weight
        // Adding 1 to review_count prevents log10(0) errors
        // Dividing by 2 scales the log value (log10(100) = 2, so we divide by 2 to get a value between 0-1)
        // Multiplying by 5 scales it to the same range as ratings (0-5)
        
        $rating_component = $rating * 0.8;
        $review_component = min(1, log10($review_count + 1) / 2) * 5 * 0.2;
        
        return $rating_component + $review_component;
    }
    
    /**
     * Get city rank
     * 
     * @param int $post_id Post ID
     * @return array Rank data with position, city name, and total profiles
     */
    private function get_city_rank($post_id) {
        // Get the post's city term (area taxonomy) - using the same approach as breadcrumbs
        $city_terms = get_the_terms($post_id, 'area');
        
        if (empty($city_terms) || is_wp_error($city_terms)) {
            return false;
        }
        
        // Get the first city term (same as breadcrumbs)
        $city_term = $city_terms[0];
        
        // Get all profiles with this city term
        $args = array(
            'post_type' => 'profile',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'tax_query' => array(
                array(
                    'taxonomy' => 'area',
                    'field' => 'term_id',
                    'terms' => $city_term->term_id,
                ),
            ),
        );
        
        $query = new WP_Query($args);
        
        if (!$query->have_posts()) {
            return false;
        }
        
        // Calculate scores for all profiles
        $scores = array();
        
        foreach ($query->posts as $profile) {
            $rating = get_field('rating_value', $profile->ID);
            $review_count = get_field('rating_votes_count', $profile->ID);
            
            // Skip if no rating or reviews
            if (empty($rating) || empty($review_count)) {
                continue;
            }
            
            $score = $this->calculate_ranking_score($rating, $review_count);
            
            // Store with post ID to handle ties correctly
            $scores[$profile->ID] = array(
                'post_id' => $profile->ID,
                'score' => $score,
                'rating' => $rating,
                'review_count' => $review_count
            );
        }
        
        // Sort by score in descending order
        uasort($scores, function($a, $b) {
            if ($a['score'] == $b['score']) {
                // If scores are equal, use review count as tiebreaker
                return $b['review_count'] - $a['review_count'];
            }
            return $b['score'] - $a['score'];
        });
        
        // Find the current post's position
        $position = 0;
        $total = count($scores);
        
        foreach ($scores as $index => $data) {
            $position++;
            if ($data['post_id'] == $post_id) {
                break;
            }
        }
        
        return array(
            'position' => $position,
            'city_name' => $city_term->name,
            'total' => $total,
            'rating' => isset($scores[$post_id]) ? $scores[$post_id]['rating'] : 0,
            'review_count' => isset($scores[$post_id]) ? $scores[$post_id]['review_count'] : 0
        );
    }
    
    /**
     * Get state rank
     * 
     * @param int $post_id Post ID
     * @return array Rank data with position, state name, and total profiles
     */
    private function get_state_rank($post_id) {
        // Get the post's state term
        $state_terms = get_the_terms($post_id, 'state');
        
        if (empty($state_terms) || is_wp_error($state_terms)) {
            return false;
        }
        
        // Get the first state term (same as breadcrumbs)
        $state_term = $state_terms[0];
        
        // Get all profiles with this state term
        $args = array(
            'post_type' => 'profile',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'tax_query' => array(
                array(
                    'taxonomy' => 'state',
                    'field' => 'term_id',
                    'terms' => $state_term->term_id,
                ),
            ),
        );
        
        $query = new WP_Query($args);
        
        if (!$query->have_posts()) {
            return false;
        }
        
        // Calculate scores for all profiles
        $scores = array();
        
        foreach ($query->posts as $profile) {
            $rating = get_field('rating_value', $profile->ID);
            $review_count = get_field('rating_votes_count', $profile->ID);
            
            // Skip if no rating or reviews
            if (empty($rating) || empty($review_count)) {
                continue;
            }
            
            $score = $this->calculate_ranking_score($rating, $review_count);
            
            // Store with post ID to handle ties correctly
            $scores[$profile->ID] = array(
                'post_id' => $profile->ID,
                'score' => $score,
                'rating' => $rating,
                'review_count' => $review_count
            );
        }
        
        // Sort by score in descending order
        uasort($scores, function($a, $b) {
            if ($a['score'] == $b['score']) {
                // If scores are equal, use review count as tiebreaker
                return $b['review_count'] - $a['review_count'];
            }
            return $b['score'] - $a['score'];
        });
        
        // Find the current post's position
        $position = 0;
        $total = count($scores);
        
        foreach ($scores as $index => $data) {
            $position++;
            if ($data['post_id'] == $post_id) {
                break;
            }
        }
        
        return array(
            'position' => $position,
            'state_name' => $state_term->name,
            'total' => $total,
            'rating' => isset($scores[$post_id]) ? $scores[$post_id]['rating'] : 0,
            'review_count' => isset($scores[$post_id]) ? $scores[$post_id]['review_count'] : 0
        );
    }
    
    /**
     * City rank shortcode
     * 
     * @param array $atts Shortcode attributes
     * @return string Formatted city rank
     */
    public function city_rank_shortcode($atts) {
        // Only show on profile pages
        if (!is_singular('profile')) {
            return '';
        }
        
        // Parse attributes
        $atts = shortcode_atts(
            array(
                'show_ranking_data' => 'false',
            ),
            $atts,
            'dh_city_rank'
        );
        
        // Convert string 'true'/'false' to boolean
        $show_ranking_data = filter_var($atts['show_ranking_data'], FILTER_VALIDATE_BOOLEAN);
        
        $post_id = get_the_ID();
        $rank_data = $this->get_city_rank($post_id);
        
        if (!$rank_data) {
            return '';
        }
        
        // Base output with "Ranked" prefix
        $output = sprintf(
            'Ranked #%d in %s',
            $rank_data['position'],
            $rank_data['city_name']
        );
        
        // Add rating data if requested
        if ($show_ranking_data) {
            $output .= sprintf(
                ' based on %d reviews and a %.1f rating',
                $rank_data['review_count'],
                $rank_data['rating']
            );
        }
        
        return $output;
    }
    
    /**
     * State rank shortcode
     * 
     * @param array $atts Shortcode attributes
     * @return string Formatted state rank
     */
    public function state_rank_shortcode($atts) {
        // Only show on profile pages
        if (!is_singular('profile')) {
            return '';
        }
        
        // Parse attributes
        $atts = shortcode_atts(
            array(
                'show_ranking_data' => 'false',
            ),
            $atts,
            'dh_state_rank'
        );
        
        // Convert string 'true'/'false' to boolean
        $show_ranking_data = filter_var($atts['show_ranking_data'], FILTER_VALIDATE_BOOLEAN);
        
        $post_id = get_the_ID();
        $rank_data = $this->get_state_rank($post_id);
        
        if (!$rank_data) {
            return '';
        }
        
        // Base output with "Ranked" prefix
        $output = sprintf(
            'Ranked #%d in %s',
            $rank_data['position'],
            $rank_data['state_name']
        );
        
        // Add rating data if requested
        if ($show_ranking_data) {
            $output .= sprintf(
                ' based on %d reviews and a %.1f rating',
                $rank_data['review_count'],
                $rank_data['rating']
            );
        }
        
        return $output;
    }
}

// Initialize the module
new DH_Profile_Rankings();
