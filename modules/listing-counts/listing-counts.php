<?php
/**
 * Listing Counts Module
 *
 * Maintains cached counts for city and state listings:
 * - City listings: _profile_count (published profiles in that city)
 * - State listings: _city_count and _profile_count
 *
 * Updates happen via hooks (no cron needed):
 * - When profiles are saved/published/trashed
 * - When city listings are saved/published/trashed
 * - When taxonomy terms change
 *
 * @package Directory_Helpers
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class DH_Listing_Counts
 */
class DH_Listing_Counts {
    
    /**
     * Constructor
     */
    public function __construct() {
        // Hook into profile saves to update city and state counts
        add_action('acf/save_post', array($this, 'update_counts_on_profile_save'), 25); // After rankings (priority 20)
        
        // Hook into city/state listing saves
        add_action('save_post_city-listing', array($this, 'update_counts_on_city_save'), 10, 3);
        add_action('save_post_state-listing', array($this, 'update_counts_on_state_save'), 10, 3);
        
        // Hook into post status transitions (publish/unpublish/trash)
        add_action('transition_post_status', array($this, 'handle_status_transition'), 10, 3);
        
        // Hook into taxonomy term changes
        add_action('set_object_terms', array($this, 'handle_term_change'), 10, 6);
        
        // Admin action to bulk update all counts
        add_action('admin_action_dh_bulk_update_listing_counts', array($this, 'bulk_update_all_counts'));
        add_action('admin_notices', array($this, 'show_success_notice'));
        add_action('admin_footer', array($this, 'show_update_button'));
    }
    
    /**
     * Update counts when a profile is saved
     * Piggybacks on the ranking system which already queries all profiles
     */
    public function update_counts_on_profile_save($post_id) {
        // Only process profiles
        if (get_post_type($post_id) !== 'profile') {
            return;
        }
        
        // Only process published profiles
        if (get_post_status($post_id) !== 'publish') {
            return;
        }
        
        // Update city count
        $area_terms = get_the_terms($post_id, 'area');
        if (!empty($area_terms) && !is_wp_error($area_terms)) {
            $this->update_city_profile_count($area_terms[0]->term_id);
        }
        
        // Update state counts
        $state_terms = get_the_terms($post_id, 'state');
        if (!empty($state_terms) && !is_wp_error($state_terms)) {
            $this->update_state_counts($state_terms[0]->slug);
        }
    }
    
    /**
     * Update counts when a city listing is saved
     */
    public function update_counts_on_city_save($post_id, $post, $update) {
        // Avoid infinite loops
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        
        // Only process published cities
        if ($post->post_status !== 'publish') {
            return;
        }
        
        // Get area term and update profile count
        $area_terms = get_the_terms($post_id, 'area');
        if (!empty($area_terms) && !is_wp_error($area_terms)) {
            $this->update_city_profile_count($area_terms[0]->term_id);
        }
        
        // Update state city count (extract state from slug)
        $state_code = $this->extract_state_from_city_slug($post->post_name);
        if ($state_code) {
            $this->update_state_counts($state_code);
        }
    }
    
    /**
     * Update counts when a state listing is saved
     */
    public function update_counts_on_state_save($post_id, $post, $update) {
        // Avoid infinite loops
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        
        // Only process published states
        if ($post->post_status !== 'publish') {
            return;
        }
        
        // Get state slug and update counts
        $state_terms = get_the_terms($post_id, 'state');
        if (!empty($state_terms) && !is_wp_error($state_terms)) {
            $this->update_state_counts($state_terms[0]->slug);
        }
    }
    
    /**
     * Handle post status transitions (publish/unpublish/trash)
     */
    public function handle_status_transition($new_status, $old_status, $post) {
        // Only care about profiles and city listings
        if (!in_array($post->post_type, array('profile', 'city-listing'), true)) {
            return;
        }
        
        // Only update if status actually changed to/from publish
        if ($new_status === $old_status) {
            return;
        }
        
        if ($new_status === 'publish' || $old_status === 'publish') {
            // Trigger count updates
            if ($post->post_type === 'profile') {
                $this->update_counts_on_profile_save($post->ID);
            } elseif ($post->post_type === 'city-listing') {
                $this->update_counts_on_city_save($post->ID, $post, true);
            }
        }
    }
    
    /**
     * Handle taxonomy term changes
     */
    public function handle_term_change($object_id, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids) {
        // Only care about area and state taxonomies
        if (!in_array($taxonomy, array('area', 'state'), true)) {
            return;
        }
        
        $post_type = get_post_type($object_id);
        
        // Only care about profiles and city listings
        if (!in_array($post_type, array('profile', 'city-listing'), true)) {
            return;
        }
        
        // Only update if published
        if (get_post_status($object_id) !== 'publish') {
            return;
        }
        
        // Update counts for old and new terms
        if ($taxonomy === 'area' && $post_type === 'profile') {
            // Update old city counts
            foreach ($old_tt_ids as $tt_id) {
                $term = get_term_by('term_taxonomy_id', $tt_id, 'area');
                if ($term) {
                    $this->update_city_profile_count($term->term_id);
                }
            }
            // Update new city counts
            foreach ($tt_ids as $tt_id) {
                $term = get_term_by('term_taxonomy_id', $tt_id, 'area');
                if ($term) {
                    $this->update_city_profile_count($term->term_id);
                }
            }
        }
        
        if ($taxonomy === 'state') {
            // Update old state counts
            foreach ($old_tt_ids as $tt_id) {
                $term = get_term_by('term_taxonomy_id', $tt_id, 'state');
                if ($term) {
                    $this->update_state_counts($term->slug);
                }
            }
            // Update new state counts
            foreach ($tt_ids as $tt_id) {
                $term = get_term_by('term_taxonomy_id', $tt_id, 'state');
                if ($term) {
                    $this->update_state_counts($term->slug);
                }
            }
        }
    }
    
    /**
     * Update profile count for a city listing
     * 
     * @param int $area_term_id Area term ID
     */
    private function update_city_profile_count($area_term_id) {
        // Find the city-listing post for this area
        $city_posts = get_posts(array(
            'post_type' => 'city-listing',
            'post_status' => 'publish',
            'posts_per_page' => 1,
            'tax_query' => array(
                array(
                    'taxonomy' => 'area',
                    'field' => 'term_id',
                    'terms' => $area_term_id,
                ),
            ),
            'fields' => 'ids',
        ));
        
        if (empty($city_posts)) {
            return;
        }
        
        $city_id = $city_posts[0];
        
        // Count published profiles in this area
        $profile_count = $this->count_profiles_by_area($area_term_id);
        
        // Update meta (cast to int for proper sorting in admin columns)
        update_post_meta($city_id, '_profile_count', (int) $profile_count);
    }
    
    /**
     * Update city count and profile count for a state listing
     * 
     * @param string $state_slug State slug
     */
    private function update_state_counts($state_slug) {
        // Find the state-listing post
        $state_posts = get_posts(array(
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
            'fields' => 'ids',
        ));
        
        if (empty($state_posts)) {
            return;
        }
        
        $state_id = $state_posts[0];
        
        // Count published city listings in this state
        $city_count = $this->count_cities_by_state($state_slug);
        
        // Count published profiles in this state
        $profile_count = $this->count_profiles_by_state($state_slug);
        
        // Update meta (cast to int for proper sorting in admin columns)
        update_post_meta($state_id, '_city_count', (int) $city_count);
        update_post_meta($state_id, '_profile_count', (int) $profile_count);
    }
    
    /**
     * Count published profiles by area term
     * 
     * @param int $area_term_id Area term ID
     * @return int Profile count
     */
    private function count_profiles_by_area($area_term_id) {
        $args = array(
            'post_type' => 'profile',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'tax_query' => array(
                array(
                    'taxonomy' => 'area',
                    'field' => 'term_id',
                    'terms' => $area_term_id,
                ),
            ),
            'fields' => 'ids',
        );
        
        $query = new WP_Query($args);
        return $query->found_posts;
    }
    
    /**
     * Count published profiles by state slug
     * 
     * @param string $state_slug State slug
     * @return int Profile count
     */
    private function count_profiles_by_state($state_slug) {
        $args = array(
            'post_type' => 'profile',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'tax_query' => array(
                array(
                    'taxonomy' => 'state',
                    'field' => 'slug',
                    'terms' => $state_slug,
                ),
            ),
            'fields' => 'ids',
        );
        
        $query = new WP_Query($args);
        return $query->found_posts;
    }
    
    /**
     * Count published city listings by state slug
     * 
     * @param string $state_slug State slug (e.g., 'ma', 'tx')
     * @return int City count
     */
    private function count_cities_by_state($state_slug) {
        // Cities don't have state taxonomy, so we match by slug pattern
        global $wpdb;
        
        $pattern = '%-' . $wpdb->esc_like($state_slug) . '-%';
        
        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} 
            WHERE post_type = 'city-listing' 
            AND post_status = 'publish' 
            AND post_name LIKE %s",
            $pattern
        ));
        
        return (int) $count;
    }
    
    /**
     * Extract state code from city slug (e.g., 'boston-ma-dog-trainers' -> 'ma')
     * 
     * @param string $slug City slug
     * @return string|false State code or false
     */
    private function extract_state_from_city_slug($slug) {
        // Pattern: city-name-ST-niche
        if (preg_match('/^(.+)-([a-z]{2})-(.+)$/', $slug, $matches)) {
            return $matches[2];
        }
        return false;
    }
    
    /**
     * Bulk update all listing counts
     */
    public function bulk_update_all_counts() {
        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }
        
        // Check nonce
        check_admin_referer('dh_bulk_update_counts');
        
        // Update all city listings
        $cities = get_posts(array(
            'post_type' => 'city-listing',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'fields' => 'ids',
        ));
        
        foreach ($cities as $city_id) {
            $area_terms = get_the_terms($city_id, 'area');
            if (!empty($area_terms) && !is_wp_error($area_terms)) {
                $this->update_city_profile_count($area_terms[0]->term_id);
            }
        }
        
        // Update all state listings
        $states = get_posts(array(
            'post_type' => 'state-listing',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'fields' => 'ids',
        ));
        
        foreach ($states as $state_id) {
            $state_terms = get_the_terms($state_id, 'state');
            if (!empty($state_terms) && !is_wp_error($state_terms)) {
                $this->update_state_counts($state_terms[0]->slug);
            }
        }
        
        // Redirect back with success message
        wp_redirect(add_query_arg('dh_counts_updated', '1', wp_get_referer()));
        exit;
    }
    
    /**
     * Show success notice after bulk update
     */
    public function show_success_notice() {
        // Prevent duplicate notices
        static $notice_shown = false;
        if ($notice_shown) {
            return;
        }
        $notice_shown = true;
        
        if (isset($_GET['dh_counts_updated']) && $_GET['dh_counts_updated'] === '1') {
            ?>
            <div class="notice notice-success is-dismissible">
                <p><strong>Listing counts updated successfully!</strong> All city and state listing counts have been recalculated.</p>
            </div>
            <script>
            // Remove the query parameter from URL to prevent message from persisting
            if (window.history.replaceState) {
                var url = new URL(window.location);
                url.searchParams.delete('dh_counts_updated');
                window.history.replaceState({}, document.title, url);
            }
            </script>
            <?php
        }
    }
    
    /**
     * Show update button at bottom of page
     */
    public function show_update_button() {
        // Show update button on city/state listing pages
        $screen = get_current_screen();
        if ($screen && in_array($screen->id, array('edit-city-listing', 'edit-state-listing'), true)) {
            $url = wp_nonce_url(admin_url('admin.php?action=dh_bulk_update_listing_counts'), 'dh_bulk_update_counts');
            ?>
            <div style="background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; padding: 15px; margin: 20px 20px 20px 0; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
                <p style="margin: 0;">
                    <strong>Listing Counts Module:</strong> 
                    <a href="<?php echo esc_url($url); ?>" class="button button-secondary" onclick="return confirm('Update all listing counts? This may take a minute.');">
                        Update All Counts Now
                    </a>
                    <span style="margin-left: 10px; color: #646970;">Updates _profile_count for cities and _city_count + _profile_count for states.</span>
                </p>
            </div>
            <?php
        }
    }
}

// Initialize the module
new DH_Listing_Counts();
