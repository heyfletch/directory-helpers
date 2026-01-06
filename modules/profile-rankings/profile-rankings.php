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
        // Formula: (rating * 0.9) + (log10($review_count + 1) / 2 * 5 * 0.1) + (boost * 2)
        // This gives 90% weight to rating and 10% to review count using logarithmic scaling
        // The log10 function helps prevent reviews from dominating the score while still giving them weight
        // Adding 1 to review_count prevents log10(0) errors
        // Dividing by 2 scales the log value for reasonable growth
        // Multiplying by 5 scales it to the same range as ratings (0-5)
        // Boost is multiplied by 2 to give more granular control
        // No cap on review component - allows profiles with 1000+ reviews to score higher
        
        $rating_component = $rating * 0.9;
        $review_component = (log10($review_count + 1) / 2) * 5 * 0.1;
        return $rating_component + $review_component + ((float)$boost * 2);
    }
    
    /**
     * Get ranking tier label based on rank and total profile count
     * 
     * @param int $rank The profile's rank
     * @param int $profile_count Total number of profiles in the area/state
     * @return string|false Tier label or false if no badge should show
     */
    private function get_ranking_tier_label($rank, $profile_count) {
        // Cast to int to handle string values from ACF
        $rank = (int) $rank;
        $profile_count = (int) $profile_count;

        if ($rank === 1) {
            return '#1';
        }

        // Top 5: if there are 6+ profiles
        if ($rank >= 2 && $rank <= 5 && $profile_count >= 6) {
            return 'Top 5';
        }

        // Top 3: only if there are 4 or 5 total profiles
        if ($rank >= 2 && $rank <= 3 && ($profile_count === 4 || $profile_count === 5)) {
            return 'Top 3';
        }

        // Top 10: if there are 11+ profiles
        if ($rank >= 6 && $rank <= 10 && $profile_count >= 11) {
            return 'Top 10';
        }

        // Top 25: if there are 50+ profiles
        if ($rank >= 11 && $rank <= 25 && $profile_count >= 50) {
            return 'Top 25';
        }

        // No badge for ranks beyond 25 or insufficient peer counts
        return false;
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
        $primary_area_term = DH_Taxonomy_Helpers::get_primary_area_term($post_id);
        if (!$primary_area_term) {
            return '';
        }
        $city_name = $primary_area_term->name;
        
        // If no rating or reviews, show "not yet ranked"
        $city_rank = get_field('city_rank', $post_id);

        // If no rating/reviews or rank is 0/empty/99999, show "not yet ranked"
        if (empty($rating) || empty($review_count) || empty($city_rank) || $city_rank == 99999) {
            return 'not yet ranked in ' . $city_name;
        }
        
        // Get profile count for this city from Listing Counts meta
        $profile_count = 0;
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
        }
        
        // Determine tier label
        $tier_label = $this->get_ranking_tier_label($city_rank, $profile_count);
        if (!$tier_label) {
            // No badge for this rank/peer count
            return '';
        }
        
        // Base output with optional "Ranked" prefix
        if ($show_prefix) {
            $output = sprintf(
                'Ranked %s in %s',
                $tier_label,
                $city_name
            );
        } else {
            $output = sprintf(
                '%s in %s',
                $tier_label,
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

        // If no rating/reviews or rank is 0/empty/99999, show "not yet ranked"
        if (empty($rating) || empty($review_count) || empty($state_rank) || $state_rank == 99999) {
            return 'not yet ranked in ' . $state_display_name;
        }

        // Get profile count for this state from Listing Counts meta
        $profile_count = 0;
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
        }

        // Determine tier label
        $tier_label = $this->get_ranking_tier_label($state_rank, $profile_count);
        if (!$tier_label) {
            // No badge for this rank/peer count
            return '';
        }
        
        // Base output with optional "Ranked" prefix
        if ($show_prefix) {
            $output = sprintf(
                'Ranked %s in %s',
                $tier_label,
                $state_display_name
            );
        } else {
            $output = sprintf(
                '%s in %s',
                $tier_label,
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

        // Add timeout protection for ranking calculation
        $start_time = microtime(true);
        $timeout = 30; // 30 seconds timeout per ranking calculation
        
        try {
            $this->recalculate_and_save_ranks($post_id);
            
            // Check if we exceeded timeout
            $elapsed = microtime(true) - $start_time;
            if ($elapsed > $timeout) {
                error_log("Ranking calculation for profile {$post_id} took {$elapsed} seconds (exceeded {$timeout}s timeout)");
                if (defined('WP_CLI') && WP_CLI) {
                    WP_CLI::warning("  [Rankings] Profile {$post_id} ranking took {$elapsed}s (slow performance)");
                }
            }
        } catch (Exception $e) {
            error_log("Ranking calculation failed for profile {$post_id}: " . $e->getMessage());
            if (defined('WP_CLI') && WP_CLI) {
                WP_CLI::warning("  [Rankings] Failed to calculate rankings for profile {$post_id}: " . $e->getMessage());
            }
        }

        // Clear the WordPress object cache to ensure changes are reflected immediately.
        wp_cache_flush();

        // Re-add the action
        add_action('acf/save_post', array($this, 'update_ranks_on_save'), 20);
    }

    /**
     * Recalculate and save ranks for all profiles in the same city and state as the given profile.
     *
     * @param int $post_id The ID of the profile that was updated.
     */
    private function recalculate_and_save_ranks($post_id) {
        // Recalculate for City - use primary area term
        $primary_area_term = DH_Taxonomy_Helpers::get_primary_area_term($post_id);
        if ($primary_area_term) {
            $this->update_ranks_for_term($primary_area_term, 'area', 'city_rank');
        }

        // State ranking is now handled separately via CLI command
        // to avoid recalculating entire state on every city save
        // Use: wp directory-helpers update-state-rankings <niche>
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

        // OPTIMIZATION: Bulk fetch all ACF values in single queries
        $profile_data = $this->bulk_fetch_profile_data($profiles);
        
        // Calculate scores
        $scores = array();
        
        foreach ($profiles as $profile_id) {
            $data = $profile_data[$profile_id];
            
            if (empty($data['rating']) || empty($data['review_count'])) {
                $scores[$profile_id] = ['score' => -1, 'review_count' => 0];
                continue;
            }

            $scores[$profile_id] = [
                'score' => $this->calculate_ranking_score($data['rating'], $data['review_count'], $data['boost']),
                'review_count' => (int)$data['review_count'],
            ];
        }

        // Sort by score descending with stable ordering for precision
        // Use sprintf to format scores with high precision to avoid float comparison issues
        
        // Extract scores, review counts, and profile IDs for sorting
        $profile_ids = array_keys($scores);
        $score_values = [];
        $review_counts = [];
        
        foreach ($scores as $profile_id => $data) {
            // Format score with 15 decimal places for precise comparison
            $score_values[] = sprintf('%.15f', $data['score']);
            $review_counts[] = $data['review_count'];
        }
        
        // Use array_multisort with profile_id as final tie-breaker for stable sorting
        array_multisort(
            $score_values, SORT_DESC, SORT_STRING,  // String comparison for precise decimal handling
            $review_counts, SORT_DESC, SORT_NUMERIC,
            $profile_ids, SORT_ASC, SORT_NUMERIC    // Tie-breaker: lower ID wins
        );
        
        // Rebuild scores array in sorted order
        $sorted_scores = [];
        foreach ($profile_ids as $profile_id) {
            $sorted_scores[$profile_id] = $scores[$profile_id];
        }
        $scores = $sorted_scores;

        // OPTIMIZATION: Bulk update all ranks in single query
        $this->bulk_update_ranks($scores, $rank_field);
    }

    /**
     * Bulk fetch all ACF values for multiple profiles in minimal queries
     */
    private function bulk_fetch_profile_data($profile_ids) {
        global $wpdb;
        
        // Create placeholders for IN clause
        $profile_id_placeholders = implode(',', array_fill(0, count($profile_ids), '%d'));
        
        // Fetch all meta values in 3 queries instead of 3 * N queries
        $ratings = $wpdb->get_results($wpdb->prepare("
            SELECT post_id, meta_value as rating_value
            FROM {$wpdb->postmeta}
            WHERE post_id IN ({$profile_id_placeholders})
            AND meta_key = %s
        ", array_merge($profile_ids, array('rating_value'))));
        
        $review_counts = $wpdb->get_results($wpdb->prepare("
            SELECT post_id, meta_value as review_count
            FROM {$wpdb->postmeta}
            WHERE post_id IN ({$profile_id_placeholders})
            AND meta_key = %s
        ", array_merge($profile_ids, array('rating_votes_count'))));
        
        $boosts = $wpdb->get_results($wpdb->prepare("
            SELECT post_id, meta_value as boost
            FROM {$wpdb->postmeta}
            WHERE post_id IN ({$profile_id_placeholders})
            AND meta_key = %s
        ", array_merge($profile_ids, array('ranking_boost'))));
        
        // Organize data by profile_id
        $profile_data = [];
        
        // Initialize with defaults
        foreach ($profile_ids as $profile_id) {
            $profile_data[$profile_id] = [
                'rating' => null,
                'review_count' => null,
                'boost' => 0
            ];
        }
        
        // Fill in actual values
        foreach ($ratings as $row) {
            $profile_data[$row->post_id]['rating'] = $row->rating_value;
        }
        
        foreach ($review_counts as $row) {
            $profile_data[$row->post_id]['review_count'] = $row->review_count;
        }
        
        foreach ($boosts as $row) {
            $profile_data[$row->post_id]['boost'] = $row->boost ?: 0;
        }
        
        return $profile_data;
    }

    /**
     * Bulk update all rank values in minimal queries
     */
    private function bulk_update_ranks($scores, $rank_field) {
        global $wpdb;
        
        // Prepare bulk delete and insert queries
        $profile_ids = array_keys($scores);
        $profile_id_placeholders = implode(',', array_fill(0, count($profile_ids), '%d'));
        
        // Delete all existing rank values in one query
        $wpdb->query($wpdb->prepare("
            DELETE FROM {$wpdb->postmeta}
            WHERE post_id IN ({$profile_id_placeholders})
            AND meta_key = %s
        ", array_merge($profile_ids, array($rank_field))));
        
        // Prepare bulk insert data
        $insert_data = [];
        $rank = 1;
        
        foreach ($scores as $profile_id => $data) {
            $rank_value = ($data['score'] < 0) ? 99999 : $rank;
            $insert_data[] = "({$profile_id}, '" . esc_sql($rank_field) . "', {$rank_value})";
            
            if ($data['score'] >= 0) {
                $rank++;
            }
        }
        
        // Insert all new rank values in one query
        if (!empty($insert_data)) {
            $insert_string = implode(',', $insert_data);
            $wpdb->query("
                INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value)
                VALUES {$insert_string}
            ");
        }
    }
}

// Initialize the module
new DH_Profile_Rankings();
