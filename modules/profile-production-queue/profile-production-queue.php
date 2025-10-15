<?php
/**
 * Profile Production Queue Module
 *
 * Manages automated profile production queue with filters and batch processing.
 * Based on Prep Profiles functionality with queue automation.
 *
 * @package Directory_Helpers
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class DH_Profile_Production_Queue
 */
class DH_Profile_Production_Queue {
    
    /**
     * Option keys for queue state
     */
    const OPTION_QUEUE_ACTIVE = 'dh_profile_queue_active';
    const OPTION_QUEUE_DATA = 'dh_profile_queue_data';
    const OPTION_CURRENT_BATCH = 'dh_profile_queue_current_batch';
    const OPTION_PROCESSED_COUNT = 'dh_profile_queue_processed_count';
    const OPTION_LAST_ERROR = 'dh_profile_queue_last_error';
    
    /**
     * Batch size - number of profiles to process per cycle
     */
    const BATCH_SIZE = 5;
    
    /**
     * Rate limit in seconds between batches
     */
    const RATE_LIMIT_SECONDS = 5;
    
    /**
     * Constructor
     */
    public function __construct() {
        // Add admin menu
        add_action('admin_menu', array($this, 'add_admin_menu'));
        
        // Enqueue admin scripts
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        
        // AJAX handlers
        add_action('wp_ajax_dh_add_to_pipeline', array($this, 'ajax_add_to_pipeline'));
        add_action('wp_ajax_dh_start_profile_queue', array($this, 'ajax_start_queue'));
        add_action('wp_ajax_dh_stop_profile_queue', array($this, 'ajax_stop_queue'));
        add_action('wp_ajax_dh_get_profile_queue_status', array($this, 'ajax_get_queue_status'));
        add_action('wp_ajax_dh_process_profile_batch', array($this, 'ajax_process_batch'));
        add_action('wp_ajax_dh_reset_profile_queue', array($this, 'ajax_reset_queue'));
    }
    
    /**
     * Add admin menu page
     */
    public function add_admin_menu() {
        add_submenu_page(
            'directory-helpers',
            __('Profile Production Queue', 'directory-helpers'),
            __('Profile Production', 'directory-helpers'),
            'manage_options',
            'dh-profile-production',
            array($this, 'render_admin_page')
        );
    }
    
    /**
     * Enqueue admin scripts
     */
    public function enqueue_admin_scripts($hook) {
        if ($hook !== 'directory-helpers_page_dh-profile-production') {
            return;
        }
        
        wp_enqueue_style('dashicons');
        
        wp_enqueue_script(
            'dh-profile-production-queue',
            plugin_dir_url(__FILE__) . 'assets/js/profile-production-queue.js',
            array('jquery'),
            filemtime(plugin_dir_path(__FILE__) . 'assets/js/profile-production-queue.js'),
            true
        );
        
        wp_localize_script('dh-profile-production-queue', 'dhProfileQueue', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('dh_profile_queue'),
            'isActive' => (bool) get_option(self::OPTION_QUEUE_ACTIVE, false),
        ));
    }
    
    /**
     * Get state terms
     */
    private function get_state_terms() {
        $terms = get_terms(array(
            'taxonomy' => 'state',
            'hide_empty' => false,
        ));
        if (is_wp_error($terms)) {
            return array();
        }
        return $terms;
    }
    
    /**
     * Query profiles by state and status with filters
     * Only returns profiles from cities that have at least min_count profiles
     */
    private function query_profiles_by_state_and_status($state_slug, $post_status, $min_count = 2, $city_slug = '', $niche_slug = 'dog-trainer', $city_search = '') {
        global $wpdb;
        if (empty($state_slug)) {
            return array();
        }
        $min_count = max(1, min(5, (int) $min_count));
        $prefix = $wpdb->prefix;
        
        // Subquery to find cities with at least min_count profiles
        $sql = "
            SELECT p.*, t2.name AS area_name, t2.slug AS area_slug, t2.term_id AS area_id
            FROM {$prefix}posts p
            JOIN {$prefix}term_relationships tr1 ON p.ID = tr1.object_id
            JOIN {$prefix}term_taxonomy tt1 ON tr1.term_taxonomy_id = tt1.term_taxonomy_id
            JOIN {$prefix}terms t1 ON tt1.term_id = t1.term_id
            JOIN {$prefix}term_relationships tr2 ON p.ID = tr2.object_id
            JOIN {$prefix}term_taxonomy tt2 ON tr2.term_taxonomy_id = tt2.term_taxonomy_id
            JOIN {$prefix}terms t2 ON tt2.term_id = t2.term_id
            JOIN {$prefix}term_relationships tr5 ON p.ID = tr5.object_id
            JOIN {$prefix}term_taxonomy tt5 ON tr5.term_taxonomy_id = tt5.term_taxonomy_id
            JOIN {$prefix}terms t5 ON tt5.term_id = t5.term_id
            WHERE p.post_type = 'profile'
              AND tt1.taxonomy = 'state'
              AND t1.slug = %s
              AND tt2.taxonomy = 'area'
              AND tt5.taxonomy = 'niche'
              AND t5.slug = %s
              AND t2.term_id IN (
                  SELECT area_term_id FROM (
                      SELECT tt_area.term_id AS area_term_id, COUNT(DISTINCT p_count.ID) AS profile_count
                      FROM {$prefix}posts p_count
                      JOIN {$prefix}term_relationships tr_count ON p_count.ID = tr_count.object_id
                      JOIN {$prefix}term_taxonomy tt_area ON tr_count.term_taxonomy_id = tt_area.term_taxonomy_id
                      JOIN {$prefix}term_relationships tr_state ON p_count.ID = tr_state.object_id
                      JOIN {$prefix}term_taxonomy tt_state ON tr_state.term_taxonomy_id = tt_state.term_taxonomy_id
                      JOIN {$prefix}terms t_state ON tt_state.term_id = t_state.term_id
                      JOIN {$prefix}term_relationships tr_niche ON p_count.ID = tr_niche.object_id
                      JOIN {$prefix}term_taxonomy tt_niche ON tr_niche.term_taxonomy_id = tt_niche.term_taxonomy_id
                      JOIN {$prefix}terms t_niche ON tt_niche.term_id = t_niche.term_id
                      WHERE p_count.post_type = 'profile'
                        AND tt_area.taxonomy = 'area'
                        AND tt_state.taxonomy = 'state'
                        AND t_state.slug = %s
                        AND tt_niche.taxonomy = 'niche'
                        AND t_niche.slug = %s";

        $params = array($state_slug, $niche_slug, $state_slug, $niche_slug);

        if ($post_status !== 'all') {
            $sql .= "\n                        AND p_count.post_status = %s";
            $params[] = $post_status;
        }

        $sql .= "\n                      GROUP BY tt_area.term_id
                      HAVING profile_count >= %d
                  ) AS city_counts
              )";
        $params[] = $min_count;

        if ($post_status !== 'all') {
            $sql .= "\n              AND p.post_status = %s";
            $params[] = $post_status;
        }
        if (!empty($city_slug)) {
            $sql .= "\n              AND t2.slug = %s";
            $params[] = $city_slug;
        }
        if (!empty($city_search)) {
            $sql .= "\n              AND t2.name LIKE %s";
            $params[] = '%' . $wpdb->esc_like($city_search) . '%';
        }

        $sql .= "\n            ORDER BY t2.name ASC, p.post_title ASC";

        $query = $wpdb->prepare($sql, $params);
        $results = $wpdb->get_results($query);
        return $results ? $results : array();
    }
    
    /**
     * Render admin page
     */
    public function render_admin_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        // Get filter values
        $state_slug = isset($_REQUEST['state']) ? sanitize_text_field(wp_unslash($_REQUEST['state'])) : '';
        $post_status = isset($_REQUEST['post_status']) ? sanitize_key($_REQUEST['post_status']) : 'refining';
        $min_count = isset($_REQUEST['min_count']) ? max(1, min(5, (int) $_REQUEST['min_count'])) : 4;
        $city_slug = isset($_REQUEST['city']) ? sanitize_title(wp_unslash($_REQUEST['city'])) : '';
        $niche_slug = isset($_REQUEST['niche']) ? sanitize_title(wp_unslash($_REQUEST['niche'])) : 'dog-trainer';
        $city_search = isset($_REQUEST['city_search']) ? sanitize_text_field(wp_unslash($_REQUEST['city_search'])) : '';
        
        if (!in_array($post_status, array('refining', 'publish', 'private', 'all'), true)) {
            $post_status = 'refining';
        }

        // Get queue state
        $is_active = (bool) get_option(self::OPTION_QUEUE_ACTIVE, false);
        $queue_data = get_option(self::OPTION_QUEUE_DATA, array());
        $processed_count = (int) get_option(self::OPTION_PROCESSED_COUNT, 0);
        $last_error = get_option(self::OPTION_LAST_ERROR, '');
        
        $total_profiles = isset($queue_data['profile_ids']) ? count($queue_data['profile_ids']) : 0;
        $remaining = $total_profiles - $processed_count;
        
        // Fetch data for display
        $states = $this->get_state_terms();
        $all_profiles = !empty($state_slug) ? $this->query_profiles_by_state_and_status($state_slug, $post_status, $min_count, $city_slug, $niche_slug, $city_search) : array();
        
        // Filter out profiles already in queue
        $queued_profile_ids = isset($queue_data['profile_ids']) ? $queue_data['profile_ids'] : array();
        $profiles = array_filter($all_profiles, function($profile) use ($queued_profile_ids) {
            return !in_array($profile->ID, $queued_profile_ids);
        });
        
        $profile_count = count($profiles);
        $queued_count = count($all_profiles) - $profile_count;
        
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Profile Production Queue', 'directory-helpers'); ?></h1>
            
            <?php if ($last_error): ?>
                <div class="notice notice-error is-dismissible">
                    <p><strong><?php esc_html_e('Error:', 'directory-helpers'); ?></strong> <?php echo esc_html($last_error); ?></p>
                </div>
            <?php endif; ?>
            
            <!-- Filter Form -->
            <form method="get" action="admin.php" style="background: #fff; padding: 20px; margin: 20px 0; border: 1px solid #ccd0d4; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
                <input type="hidden" name="page" value="dh-profile-production" />
                
                <div style="display:flex; gap:12px; align-items:center; flex-wrap:wrap;">
                    <!-- State selector -->
                    <label><strong><?php esc_html_e('State:', 'directory-helpers'); ?></strong>
                        <select name="state" required>
                            <option value=""><?php esc_html_e('Select State', 'directory-helpers'); ?></option>
                            <?php foreach ($states as $term): ?>
                                <?php $label = !empty($term->description) ? $term->description : $term->name; ?>
                                <option value="<?php echo esc_attr($term->slug); ?>" <?php selected($state_slug, $term->slug); ?>>
                                    <?php echo esc_html($label); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    
                    <!-- City search -->
                    <label><strong><?php esc_html_e('Find:', 'directory-helpers'); ?></strong>
                        <input type="text" name="city_search" value="<?php echo esc_attr($city_search); ?>" placeholder="<?php esc_attr_e('Filter by city name', 'directory-helpers'); ?>" />
                    </label>
                    
                    <!-- Status -->
                    <label><strong><?php esc_html_e('Status:', 'directory-helpers'); ?></strong>
                        <select name="post_status">
                            <option value="refining" <?php selected($post_status, 'refining'); ?>><?php esc_html_e('Refining', 'directory-helpers'); ?></option>
                            <option value="publish" <?php selected($post_status, 'publish'); ?>><?php esc_html_e('Published', 'directory-helpers'); ?></option>
                            <option value="private" <?php selected($post_status, 'private'); ?>><?php esc_html_e('Private', 'directory-helpers'); ?></option>
                            <option value="all" <?php selected($post_status, 'all'); ?>><?php esc_html_e('All', 'directory-helpers'); ?></option>
                        </select>
                    </label>
                    
                    <!-- Niche -->
                    <label><strong><?php esc_html_e('Niche:', 'directory-helpers'); ?></strong>
                        <select name="niche">
                            <option value="dog-trainer" <?php selected($niche_slug, 'dog-trainer'); ?>><?php esc_html_e('Dog Trainer', 'directory-helpers'); ?></option>
                            <option value="dog-daycare" <?php selected($niche_slug, 'dog-daycare'); ?>><?php esc_html_e('Dog Daycare', 'directory-helpers'); ?></option>
                        </select>
                    </label>
                    
                    <!-- Min Count -->
                    <label><strong><?php esc_html_e('Profiles:', 'directory-helpers'); ?></strong>
                        <input type="number" name="min_count" value="<?php echo esc_attr($min_count); ?>" min="1" max="5" style="width: 60px;" />
                    </label>
                    
                    <button type="submit" class="button button-primary"><?php esc_html_e('Filter', 'directory-helpers'); ?></button>
                </div>
            </form>
            
            <!-- Add to Pipeline Button -->
            <div style="margin: 20px 0; display: flex; gap: 15px; align-items: center;">
                <button type="button" id="dh-add-to-pipeline-btn" class="button button-primary" <?php echo $profile_count === 0 ? 'disabled' : ''; ?>>
                    <?php echo sprintf(esc_html__('Add Profiles to Production Pipeline (%d profiles)', 'directory-helpers'), $profile_count); ?>
                </button>
                <a href="<?php echo esc_url(admin_url('admin.php?page=dh-content-production')); ?>"><?php esc_html_e('→ Go to Content Production Queue', 'directory-helpers'); ?></a>
                <span class="dashicons dashicons-info" style="color: #2271b1; cursor: help;" title="<?php esc_attr_e('How it works: 1) Use filters to select profiles by state, city, status, and niche. 2) Click Add Profiles to Production Pipeline to queue the filtered profiles. 3) The queue will automatically process profiles in batches. 4) Each batch: creates city pages, publishes profiles, reranks, and triggers AI content generation.', 'directory-helpers'); ?>"></span>
            </div>
            
            <!-- Status Line -->
            <div style="background: #fff; padding: 15px; margin: 20px 0; border: 1px solid #ccd0d4; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
                <p style="margin: 0; display: flex; gap: 20px; align-items: center; flex-wrap: wrap;">
                    <strong><?php esc_html_e('Status:', 'directory-helpers'); ?></strong>
                    <span id="dh-ppq-status">
                        <?php if ($is_active): ?>
                            <span style="color: #46b450;">● <?php esc_html_e('Running', 'directory-helpers'); ?></span>
                        <?php else: ?>
                            <span style="color: #999;">● <?php esc_html_e('Stopped', 'directory-helpers'); ?></span>
                        <?php endif; ?>
                    </span>
                    <span><strong><?php esc_html_e('Total:', 'directory-helpers'); ?></strong> <span id="dh-ppq-total"><?php echo esc_html($total_profiles); ?></span></span>
                    <span><strong><?php esc_html_e('Processed:', 'directory-helpers'); ?></strong> <span id="dh-ppq-processed"><?php echo esc_html($processed_count); ?></span></span>
                    <span><strong><?php esc_html_e('Remaining:', 'directory-helpers'); ?></strong> <span id="dh-ppq-remaining"><?php echo esc_html($remaining); ?></span></span>
                    <?php if ($is_active): ?>
                        <button type="button" id="dh-stop-ppq-btn" class="button"><?php esc_html_e('Stop Queue', 'directory-helpers'); ?></button>
                    <?php elseif ($total_profiles > 0): ?>
                        <button type="button" id="dh-start-ppq-btn" class="button button-primary"><?php esc_html_e('Start Queue', 'directory-helpers'); ?></button>
                    <?php endif; ?>
                    <button type="button" id="dh-reset-ppq-btn" class="button"><?php esc_html_e('Reset Queue', 'directory-helpers'); ?></button>
                </p>
            </div>
            
            <!-- Profiles Table -->
            <?php if (!empty($profiles)): ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Profile', 'directory-helpers'); ?></th>
                            <th><?php esc_html_e('City', 'directory-helpers'); ?></th>
                            <th><?php esc_html_e('Status', 'directory-helpers'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($profiles as $profile): ?>
                            <tr>
                                <td>
                                    <strong>
                                        <a href="<?php echo esc_url(get_edit_post_link($profile->ID)); ?>" target="_blank">
                                            <?php echo esc_html($profile->post_title); ?>
                                        </a>
                                    </strong>
                                </td>
                                <td><?php echo esc_html($profile->area_name); ?></td>
                                <td><?php echo esc_html(ucfirst($profile->post_status)); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p><?php esc_html_e('No profiles found with the selected filters.', 'directory-helpers'); ?></p>
            <?php endif; ?>
            
            <?php if ($queued_count > 0): ?>
                <p style="color: #666; font-style: italic; margin-top: 10px;">
                    <?php echo sprintf(esc_html__('Note: %d profiles from this filter are already in the queue and hidden from this list.', 'directory-helpers'), $queued_count); ?>
                </p>
            <?php endif; ?>
        </div>
        <?php
    }
    
    /**
     * AJAX: Add profiles to pipeline
     */
    public function ajax_add_to_pipeline() {
        check_ajax_referer('dh_profile_queue', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'));
        }
        
        // Get filter values from request
        $state_slug = isset($_POST['state']) ? sanitize_text_field(wp_unslash($_POST['state'])) : '';
        $post_status = isset($_POST['post_status']) ? sanitize_key($_POST['post_status']) : 'refining';
        $min_count = isset($_POST['min_count']) ? max(1, min(5, (int) $_POST['min_count'])) : 4;
        $city_slug = isset($_POST['city']) ? sanitize_title(wp_unslash($_POST['city'])) : '';
        $niche_slug = isset($_POST['niche']) ? sanitize_title(wp_unslash($_POST['niche'])) : 'dog-trainer';
        $city_search = isset($_POST['city_search']) ? sanitize_text_field(wp_unslash($_POST['city_search'])) : '';
        
        if (empty($state_slug)) {
            wp_send_json_error(array('message' => 'State is required'));
        }
        
        // Query profiles
        $profiles = $this->query_profiles_by_state_and_status($state_slug, $post_status, $min_count, $city_slug, $niche_slug, $city_search);
        
        if (empty($profiles)) {
            wp_send_json_error(array('message' => 'No profiles found'));
        }
        
        $new_profile_ids = wp_list_pluck($profiles, 'ID');
        
        // Get existing queue data
        $existing_queue_data = get_option(self::OPTION_QUEUE_DATA, array());
        $existing_profile_ids = isset($existing_queue_data['profile_ids']) ? $existing_queue_data['profile_ids'] : array();
        
        // Merge and remove duplicates
        $merged_profile_ids = array_unique(array_merge($existing_profile_ids, $new_profile_ids));
        $added_count = count($merged_profile_ids) - count($existing_profile_ids);
        
        // Store updated queue data
        update_option(self::OPTION_QUEUE_DATA, array(
            'profile_ids' => $merged_profile_ids,
            'state_slug' => $state_slug,
            'niche_slug' => $niche_slug,
            'created_city_ids' => isset($existing_queue_data['created_city_ids']) ? $existing_queue_data['created_city_ids'] : array(),
        ));
        
        // Only reset counters if this is a fresh queue (no existing profiles)
        if (empty($existing_profile_ids)) {
            update_option(self::OPTION_PROCESSED_COUNT, 0);
            update_option(self::OPTION_CURRENT_BATCH, 0);
            delete_option(self::OPTION_LAST_ERROR);
        }
        
        // Auto-start the queue
        update_option(self::OPTION_QUEUE_ACTIVE, true);
        
        wp_send_json_success(array(
            'message' => sprintf('Added %d new profiles to pipeline (total: %d) - Queue started!', $added_count, count($merged_profile_ids)),
            'total' => count($merged_profile_ids),
            'added' => $added_count,
        ));
    }
    
    /**
     * AJAX: Start queue
     */
    public function ajax_start_queue() {
        check_ajax_referer('dh_profile_queue', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'));
        }
        
        update_option(self::OPTION_QUEUE_ACTIVE, true);
        
        wp_send_json_success(array('message' => 'Queue started'));
    }
    
    /**
     * AJAX: Stop queue
     */
    public function ajax_stop_queue() {
        check_ajax_referer('dh_profile_queue', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'));
        }
        
        update_option(self::OPTION_QUEUE_ACTIVE, false);
        
        wp_send_json_success(array('message' => 'Queue stopped'));
    }
    
    /**
     * AJAX: Get queue status
     */
    public function ajax_get_queue_status() {
        check_ajax_referer('dh_profile_queue', 'nonce');
        
        $is_active = (bool) get_option(self::OPTION_QUEUE_ACTIVE, false);
        $queue_data = get_option(self::OPTION_QUEUE_DATA, array());
        $processed_count = (int) get_option(self::OPTION_PROCESSED_COUNT, 0);
        
        $total_profiles = isset($queue_data['profile_ids']) ? count($queue_data['profile_ids']) : 0;
        $remaining = $total_profiles - $processed_count;
        
        wp_send_json_success(array(
            'is_active' => $is_active,
            'total' => $total_profiles,
            'processed' => $processed_count,
            'remaining' => $remaining,
        ));
    }
    
    /**
     * AJAX: Process next batch (called by frontend polling)
     */
    public function ajax_process_batch() {
        check_ajax_referer('dh_profile_queue', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'));
        }
        
        // Check if queue is active
        $is_active = (bool) get_option(self::OPTION_QUEUE_ACTIVE, false);
        
        if (!$is_active) {
            wp_send_json_success(array(
                'is_active' => false,
                'message' => 'Queue is not active',
            ));
            return;
        }
        
        // Process the next batch
        $this->process_next_batch();
        
        // Get updated status
        $is_active = (bool) get_option(self::OPTION_QUEUE_ACTIVE, false);
        $processed_count = (int) get_option(self::OPTION_PROCESSED_COUNT, 0);
        $queue_data = get_option(self::OPTION_QUEUE_DATA, array());
        $total = isset($queue_data['profile_ids']) ? count($queue_data['profile_ids']) : 0;
        $last_error = get_option(self::OPTION_LAST_ERROR, '');
        
        wp_send_json_success(array(
            'is_active' => $is_active,
            'processed_count' => $processed_count,
            'processed' => $processed_count,
            'total' => $total,
            'remaining' => $total - $processed_count,
            'last_error' => $last_error,
            'message' => $is_active ? 'Batch processed' : 'Queue completed',
        ));
    }
    
    /**
     * Process next batch of profiles
     */
    private function process_next_batch() {
        // Check if queue is still active
        if (!get_option(self::OPTION_QUEUE_ACTIVE, false)) {
            return;
        }
        
        $queue_data = get_option(self::OPTION_QUEUE_DATA, array());
        $processed_count = (int) get_option(self::OPTION_PROCESSED_COUNT, 0);
        
        if (empty($queue_data['profile_ids'])) {
            // No profiles, stop queue
            update_option(self::OPTION_QUEUE_ACTIVE, false);
            return;
        }
        
        $profile_ids = $queue_data['profile_ids'];
        $state_slug = $queue_data['state_slug'];
        $niche_slug = $queue_data['niche_slug'];
        $created_city_ids = isset($queue_data['created_city_ids']) ? $queue_data['created_city_ids'] : array();
        
        // Get next batch
        $batch = array_slice($profile_ids, $processed_count, self::BATCH_SIZE);
        
        if (empty($batch)) {
            // All done - run final steps
            $this->run_final_steps($queue_data);
            update_option(self::OPTION_QUEUE_ACTIVE, false);
            return;
        }
        
        // Process this batch
        try {
            $result = $this->process_profile_batch($batch, $state_slug, $niche_slug, $created_city_ids);
            
            // Update created city IDs
            if (!empty($result['created_city_ids'])) {
                $queue_data['created_city_ids'] = array_merge($created_city_ids, $result['created_city_ids']);
                update_option(self::OPTION_QUEUE_DATA, $queue_data);
            }
            
            // Update processed count
            $processed_count += count($batch);
            update_option(self::OPTION_PROCESSED_COUNT, $processed_count);
            
        } catch (Exception $e) {
            update_option(self::OPTION_LAST_ERROR, $e->getMessage());
            update_option(self::OPTION_QUEUE_ACTIVE, false);
            error_log('Profile Queue Error: ' . $e->getMessage());
        }
    }
    
    /**
     * Process a batch of profiles
     */
    private function process_profile_batch($profile_ids, $state_slug, $niche_slug, $existing_city_ids) {
        $created_city_ids = array();
        
        // Extract unique cities from this batch
        $unique_cities = array();
        foreach ($profile_ids as $pid) {
            $area_terms = get_the_terms($pid, 'area');
            if (!empty($area_terms) && !is_wp_error($area_terms)) {
                $area = $area_terms[0];
                $unique_cities[$area->slug] = $area->name;
            }
        }
        
        // Create city pages if needed
        $niche_term = !empty($niche_slug) ? get_term_by('slug', $niche_slug, 'niche') : false;
        
        foreach ($unique_cities as $area_slug => $area_name) {
            $area_term = get_term_by('slug', $area_slug, 'area');
            if (!$area_term || is_wp_error($area_term)) {
                continue;
            }
            
            // Check if city already exists
            $exists_id = $this->city_listing_exists((int)$area_term->term_id, ($niche_term && !is_wp_error($niche_term)) ? (int)$niche_term->term_id : 0);
            if ($exists_id) {
                continue;
            }
            
            $new_id = $this->create_city_listing($area_term, $state_slug, $niche_term);
            if ($new_id) {
                $created_city_ids[] = $new_id;
            }
        }
        
        // Trigger AI for newly created cities immediately
        if (!empty($created_city_ids)) {
            $this->trigger_ai_for_cities($created_city_ids);
        }
        
        // Publish profiles in this batch (only if not already published)
        $published_now = array();
        foreach ($profile_ids as $pid) {
            $pid = (int) $pid;
            $current = get_post_status($pid);
            if ('publish' !== $current) {
                $result = wp_update_post(array(
                    'ID' => $pid,
                    'post_status' => 'publish',
                ), true);
                if (!is_wp_error($result) && $result) {
                    $published_now[] = $pid;
                }
            }
        }
        
        // Clean area terms only for newly published profiles
        if (!empty($published_now)) {
            $this->cleanup_area_terms($published_now);
        }
        
        return array(
            'created_city_ids' => $created_city_ids,
        );
    }
    
    /**
     * Run final steps after all batches complete
     */
    private function run_final_steps($queue_data) {
        $state_slug = $queue_data['state_slug'];
        $profile_ids = $queue_data['profile_ids'];
        
        // Rerank all published profiles
        $this->rerank_posts($profile_ids, $state_slug);
    }
    
    /**
     * Helper: Check if city listing exists
     */
    private function city_listing_exists($area_term_id, $niche_term_id = 0) {
        $tax_query = array(
            'relation' => 'AND',
            array(
                'taxonomy' => 'area',
                'field' => 'term_id',
                'terms' => array((int)$area_term_id),
            ),
        );
        
        if ($niche_term_id) {
            $tax_query[] = array(
                'taxonomy' => 'niche',
                'field' => 'term_id',
                'terms' => array((int)$niche_term_id),
            );
        }
        
        $q = new WP_Query(array(
            'post_type' => 'city-listing',
            'post_status' => array('draft', 'publish'),
            'posts_per_page' => 1,
            'tax_query' => $tax_query,
            'fields' => 'ids',
            'no_found_rows' => true,
        ));
        
        if (!is_wp_error($q) && !empty($q->posts)) {
            return (int)$q->posts[0];
        }
        return 0;
    }
    
    /**
     * Helper: Create city listing
     */
    private function create_city_listing($area_term, $state_slug, $niche_term) {
        if (!$area_term || is_wp_error($area_term)) {
            return false;
        }
        
        // City Name: strip trailing " - ST"
        $city_name = $area_term->name;
        $city_name = preg_replace('/\s+-\s+[A-Za-z]{2}$/', '', $city_name);
        $city_name = trim($city_name);
        
        // Determine state code
        $state_term = get_term_by('slug', $state_slug, 'state');
        $state_code = '';
        if ($state_term && !is_wp_error($state_term)) {
            if (strlen($state_term->slug) === 2) {
                $state_code = strtoupper($state_term->slug);
            } elseif (!empty($state_term->description) && preg_match('/^[A-Za-z]{2}$/', $state_term->description)) {
                $state_code = strtoupper($state_term->description);
            }
        }
        if (!$state_code && preg_match('/\s-\s([A-Za-z]{2})$/', $area_term->name, $m)) {
            $state_code = strtoupper($m[1]);
        }
        if (!$state_code && strlen($state_slug) >= 2) {
            $state_code = strtoupper(substr($state_slug, 0, 2));
        }
        
        // Title: "City, ST"
        $title = $city_name . ($state_code ? ', ' . $state_code : '');
        
        // Niche pluralization for slug
        $niche_name = ($niche_term && !is_wp_error($niche_term)) ? $niche_term->name : '';
        $plural_niche = $niche_name;
        if ($plural_niche) {
            if (preg_match('/[^aeiou]y$/i', $plural_niche)) {
                $plural_niche = preg_replace('/y$/i', 'ies', $plural_niche);
            } elseif (!preg_match('/s$/i', $plural_niche)) {
                $plural_niche .= 's';
            }
        }
        
        // Slug
        $slug_base = $title . ($plural_niche ? ' ' . $plural_niche : '');
        $desired_slug = sanitize_title($slug_base);
        
        $post_id = wp_insert_post(array(
            'post_type' => 'city-listing',
            'post_status' => 'draft',
            'post_title' => $title,
            'post_name' => $desired_slug,
        ), true);
        
        if (is_wp_error($post_id) || !$post_id) {
            return false;
        }
        
        // Assign taxonomy terms
        wp_set_object_terms($post_id, $area_term->term_id, 'area');
        wp_set_object_terms($post_id, $state_term->term_id, 'state');
        if ($niche_term && !is_wp_error($niche_term)) {
            wp_set_object_terms($post_id, $niche_term->term_id, 'niche');
        }
        
        return $post_id;
    }
    
    /**
     * Helper: Clean area terms
     */
    private function cleanup_area_terms($post_ids) {
        if (empty($post_ids)) {
            return;
        }
        
        $unique_terms = array();
        foreach ($post_ids as $pid) {
            $terms = get_the_terms((int)$pid, 'area');
            if (!empty($terms) && !is_wp_error($terms)) {
                foreach ($terms as $t) {
                    $unique_terms[$t->term_id] = $t;
                }
            }
        }
        
        foreach ($unique_terms as $term) {
            $name = $term->name;
            if (preg_match('/\s-\s[A-Za-z]{2}$/', $name)) {
                $new_name = trim(preg_replace('/\s-\s[A-Za-z]{2}$/', '', $name));
                if ($new_name && $new_name !== $name) {
                    wp_update_term((int)$term->term_id, 'area', array('name' => $new_name));
                }
            }
        }
    }
    
    /**
     * Helper: Rerank posts
     */
    private function rerank_posts($post_ids, $state_slug) {
        if (empty($post_ids)) {
            return;
        }
        
        // Only consider published posts
        $published_ids = array();
        foreach ($post_ids as $pid) {
            if (get_post_status($pid) === 'publish') {
                $published_ids[] = $pid;
            }
        }
        if (empty($published_ids)) {
            return;
        }
        
        // Re-rank city by city
        $area_terms = $this->get_unique_area_terms_for_posts($published_ids);
        foreach ($area_terms as $term_id => $term) {
            $rep = $this->find_post_in_term($published_ids, $term_id, 'area');
            if ($rep) {
                do_action('acf/save_post', $rep);
            }
        }
        
        // Trigger state ranking
        $rep_state_post = $this->find_post_in_state($published_ids, $state_slug);
        if ($rep_state_post) {
            do_action('acf/save_post', $rep_state_post);
        }
    }
    
    /**
     * Helper: Get unique area terms
     */
    private function get_unique_area_terms_for_posts($post_ids) {
        $unique = array();
        foreach ($post_ids as $pid) {
            $terms = get_the_terms($pid, 'area');
            if (!empty($terms) && !is_wp_error($terms)) {
                foreach ($terms as $t) {
                    $unique[$t->term_id] = $t;
                }
            }
        }
        return $unique;
    }
    
    /**
     * Helper: Find post in term
     */
    private function find_post_in_term($post_ids, $term_id, $taxonomy) {
        foreach ($post_ids as $pid) {
            $terms = wp_get_post_terms($pid, $taxonomy, array('fields' => 'ids'));
            if (!is_wp_error($terms) && in_array((int)$term_id, array_map('intval', (array)$terms), true)) {
                return $pid;
            }
        }
        return 0;
    }
    
    /**
     * Helper: Find post in state
     */
    private function find_post_in_state($post_ids, $state_slug) {
        foreach ($post_ids as $pid) {
            $terms = get_the_terms($pid, 'state');
            if (!empty($terms) && !is_wp_error($terms)) {
                foreach ($terms as $t) {
                    if ($t->slug === $state_slug) {
                        return $pid;
                    }
                }
            }
        }
        return 0;
    }
    
    /**
     * Helper: Trigger AI for cities
     */
    private function trigger_ai_for_cities($city_ids) {
        $options = get_option('directory_helpers_options');
        $url = isset($options['n8n_webhook_url']) ? $options['n8n_webhook_url'] : '';
        
        if (empty($url)) {
            return;
        }
        
        foreach ($city_ids as $cid) {
            $content = get_post_field('post_content', $cid);
            if (empty($content) || trim(wp_strip_all_tags($content)) === '') {
                $raw_title = wp_strip_all_tags(get_the_title($cid));
                $clean_title = trim(preg_replace('/[^\p{L}\p{N}\s]/u', '', $raw_title));
                $clean_title = preg_replace('/\s+/', ' ', $clean_title);
                $keyword = 'dog training in ' . $clean_title;
                
                $body = wp_json_encode(array(
                    'postId' => $cid,
                    'postUrl' => get_permalink($cid),
                    'postTitle' => wp_strip_all_tags(get_the_title($cid)),
                    'keyword' => $keyword,
                ));
                
                wp_remote_post($url, array(
                    'headers' => array('Content-Type' => 'application/json'),
                    'body' => $body,
                    'timeout' => 20,
                ));
                
                sleep(1);
            }
        }
    }
    
    /**
     * AJAX: Reset queue
     */
    public function ajax_reset_queue() {
        check_ajax_referer('dh_profile_queue', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'));
        }
        
        delete_option(self::OPTION_QUEUE_DATA);
        delete_option(self::OPTION_PROCESSED_COUNT);
        delete_option(self::OPTION_CURRENT_BATCH);
        delete_option(self::OPTION_LAST_ERROR);
        update_option(self::OPTION_QUEUE_ACTIVE, false);
        
        wp_send_json_success(array('message' => 'Queue reset'));
    }
}

// Module is initialized by directory-helpers.php module loader
// Do NOT instantiate here to avoid duplicate registration
