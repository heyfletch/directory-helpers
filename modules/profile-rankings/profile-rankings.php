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

        // Hook to update ranks when a profile is saved
        add_action('acf/save_post', array($this, 'update_ranks_on_save'), 20);
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
    private function calculate_ranking_score($rating, $review_count, $boost = 0) {
        // Formula: (rating * 0.8) + (min(1, log10($review_count + 1) / 2) * 5 * 0.2)
        // This gives 80% weight to rating and 20% to review count using logarithmic scaling
        // The log10 function helps prevent reviews from dominating the score while still giving them weight
        // Adding 1 to review_count prevents log10(0) errors
        // Dividing by 2 scales the log value (log10(100) = 2, so we divide by 2 to get a value between 0-1)
        // Multiplying by 5 scales it to the same range as ratings (0-5)
        
        $rating_component = $rating * 0.8;
        $review_component = min(1, log10($review_count + 1) / 2) * 5 * 0.2;
        return $rating_component + $review_component + (float)$boost;
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
                'show_prefix' => 'true',
            ),
            $atts,
            'dh_city_rank'
        );
        
        // Convert string 'true'/'false' to boolean
        $show_ranking_data = filter_var($atts['show_ranking_data'], FILTER_VALIDATE_BOOLEAN);
        $show_prefix = filter_var($atts['show_prefix'], FILTER_VALIDATE_BOOLEAN);
        
        $post_id = get_the_ID();
        
        // Check if this profile has rating data
        $rating = get_field('rating_value', $post_id);
        $review_count = get_field('rating_votes_count', $post_id);
        
        // Get city name for the profile
        $city_terms = get_the_terms($post_id, 'area');
        if (empty($city_terms) || is_wp_error($city_terms)) {
            return '';
        }
        $city_name = $city_terms[0]->name;
        
        // If no rating or reviews, show "not yet ranked"
        $city_rank = get_field('city_rank', $post_id);

        // If no rating/reviews or rank is 0/empty, show "not yet ranked"
        if (empty($rating) || empty($review_count) || empty($city_rank)) {
            return 'not yet ranked in ' . $city_name;
        }
        
        // Base output with optional "Ranked" prefix
        if ($show_prefix) {
            $output = sprintf(
                'Ranked #%d in %s',
                $city_rank,
                $city_name
            );
        } else {
            $output = sprintf(
                '#%d in %s',
                $city_rank,
                $city_name
            );
        }
        
        // Add rating data if requested
        if ($show_ranking_data) {
            $output .= sprintf(
                ' based on %d reviews and a %.1f rating',
                $review_count,
                $rating
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
                'show_prefix' => 'true',
            ),
            $atts,
            'dh_state_rank'
        );
        
        // Convert string 'true'/'false' to boolean
        $show_ranking_data = filter_var($atts['show_ranking_data'], FILTER_VALIDATE_BOOLEAN);
        $show_prefix = filter_var($atts['show_prefix'], FILTER_VALIDATE_BOOLEAN);
        
        $post_id = get_the_ID();
        
        // Check if this profile has rating data
        $rating = get_field('rating_value', $post_id);
        $review_count = get_field('rating_votes_count', $post_id);
        
        // Get state name for the profile
        $state_terms = get_the_terms($post_id, 'state');
        if (empty($state_terms) || is_wp_error($state_terms)) {
            return '';
        }
        
        // Use the term description if available, otherwise use the term name
        $state_display_name = !empty($state_terms[0]->description) ? $state_terms[0]->description : $state_terms[0]->name;
        
        // If no rating or reviews, show "not yet ranked"
        $state_rank = get_field('state_rank', $post_id);

        // If no rating/reviews or rank is 0/empty, show "not yet ranked"
        if (empty($rating) || empty($review_count) || empty($state_rank)) {
            return 'not yet ranked in ' . $state_display_name;
        }

        // Base output with optional "Ranked" prefix
        if ($show_prefix) {
            $output = sprintf(
                'Ranked #%d in %s',
                $state_rank,
                $state_display_name
            );
        } else {
            $output = sprintf(
                '#%d in %s',
                $state_rank,
                $state_display_name
            );
        }
        
        // Add rating data if requested
        if ($show_ranking_data) {
            $output .= sprintf(
                ' based on %d reviews and a %.1f rating',
                $review_count,
                $rating
            );
        }
        
        return $output;
    }

    /**
     * Trigger rank updates when a profile is saved.
     *
     * @param int $post_id The ID of the post being saved.
     */
    public function update_ranks_on_save($post_id) {
        // Check if the post is a 'profile'
        if (get_post_type($post_id) !== 'profile') {
            return;
        }

        // To prevent infinite loops, remove the action before updating fields and add it back after.
        remove_action('acf/save_post', array($this, 'update_ranks_on_save'), 20);

        $this->recalculate_and_save_ranks($post_id);

        add_action('acf/save_post', array($this, 'update_ranks_on_save'), 20);
    }

    /**
     * Recalculate and save ranks for all profiles in the same city and state as the given profile.
     *
     * @param int $post_id The ID of the profile that was updated.
     */
    private function recalculate_and_save_ranks($post_id) {
        // Recalculate for City
        $city_terms = get_the_terms($post_id, 'area');
        if (!empty($city_terms) && !is_wp_error($city_terms)) {
            $this->update_ranks_for_term($city_terms[0], 'area', 'city_rank');
        }

        // Recalculate for State
        $state_terms = get_the_terms($post_id, 'state');
        if (!empty($state_terms) && !is_wp_error($state_terms)) {
            $this->update_ranks_for_term($state_terms[0], 'state', 'state_rank');
        }
    }

    /**
     * Helper function to update ranks for a given term.
     *
     * @param WP_Term $term The term object (city or state).
     * @param string  $taxonomy The taxonomy ('area' or 'state').
     * @param string  $rank_field The ACF field name to save the rank to ('city_rank' or 'state_rank').
     */
    private function update_ranks_for_term($term, $taxonomy, $rank_field) {
        $args = array(
            'post_type' => 'profile',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'tax_query' => array(
                array(
                    'taxonomy' => $taxonomy,
                    'field' => 'term_id',
                    'terms' => $term->term_id,
                ),
            ),
            'fields' => 'ids', // We only need post IDs
        );

        $query = new WP_Query($args);
        $profiles = $query->posts;

        if (empty($profiles)) {
            return;
        }

        $scores = array();
        foreach ($profiles as $profile_id) {
            $rating = get_field('rating_value', $profile_id);
            $review_count = get_field('rating_votes_count', $profile_id);
            $boost = get_field('ranking_boost', $profile_id) ?: 0;

            if (empty($rating) || empty($review_count)) {
                // Profiles without reviews are not ranked
                $scores[$profile_id] = ['score' => -1, 'review_count' => 0]; 
                continue;
            }

            $scores[$profile_id] = [
                'score' => $this->calculate_ranking_score($rating, $review_count, $boost),
                'review_count' => (int)$review_count,
            ];
        }

        // Sort by score descending
        uasort($scores, function($a, $b) {
            if (bccomp((string)$a['score'], (string)$b['score'], 8) === 0) {
                return $b['review_count'] - $a['review_count'];
            }
            return bccomp((string)$b['score'], (string)$a['score'], 8);
        });

        // Update rank field for each profile
        $rank = 1;
        foreach ($scores as $profile_id => $data) {
            if ($data['score'] < 0) {
                // Not ranked, update field to 0 or null
                update_field($rank_field, 0, $profile_id);
            } else {
                update_field($rank_field, $rank, $profile_id);
                $rank++;
            }
        }
    }
}

// Initialize the module
new DH_Profile_Rankings();
