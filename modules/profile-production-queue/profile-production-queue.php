<?php
/**
 * Profile Production Queue Module
 *
 * Manages automated profile production queue for processing profiles in batches.
 * Creates cities, publishes profiles, reranks, and generates AI content sequentially.
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
    const BATCH_SIZE = 2;
    
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
        add_action('wp_ajax_dh_start_profile_queue', array($this, 'ajax_start_queue'));
        add_action('wp_ajax_dh_stop_profile_queue', array($this, 'ajax_stop_queue'));
        add_action('wp_ajax_dh_get_profile_queue_status', array($this, 'ajax_get_queue_status'));
        add_action('wp_ajax_dh_process_profile_batch', array($this, 'ajax_process_batch'));
        add_action('wp_ajax_dh_reset_profile_queue', array($this, 'ajax_reset_queue'));
    }
    
    /**
     * Add custom cron schedules
     */
    public function add_cron_schedules($schedules) {
        if (!isset($schedules['five_minutes'])) {
            $schedules['five_minutes'] = array(
                'interval' => 300, // 5 minutes in seconds
                'display' => __('Every 5 Minutes', 'directory-helpers'),
            );
        }
        return $schedules;
    }
    
    /**
     * Ensure cron is scheduled on init
     */
    public function ensure_cron_scheduled() {
        if (!wp_next_scheduled('dh_profile_queue_process')) {
            wp_schedule_event(time(), 'five_minutes', 'dh_profile_queue_process');
        }
    }
    
    /**
     * Add admin menu page
     */
    public function add_admin_menu() {
        add_submenu_page(
            'directory-helpers',
            __('Profile Production', 'directory-helpers'),
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
     * Render admin page
     */
    public function render_admin_page() {
        $is_active = (bool) get_option(self::OPTION_QUEUE_ACTIVE, false);
        $queue_data = get_option(self::OPTION_QUEUE_DATA, array());
        $processed_count = (int) get_option(self::OPTION_PROCESSED_COUNT, 0);
        $last_error = get_option(self::OPTION_LAST_ERROR, '');
        
        $total_profiles = isset($queue_data['profile_ids']) ? count($queue_data['profile_ids']) : 0;
        $remaining = $total_profiles - $processed_count;
        
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('Profile Production Queue', 'directory-helpers'); ?></h1>
            
            <?php if ($last_error): ?>
                <div class="notice notice-error is-dismissible">
                    <p><strong><?php echo esc_html__('Error:', 'directory-helpers'); ?></strong> <?php echo esc_html($last_error); ?></p>
                    <button type="button" class="button" id="dh-clear-ppq-error"><?php echo esc_html__('Clear Error', 'directory-helpers'); ?></button>
                </div>
            <?php endif; ?>
            
            <div class="card">
                <h2><?php echo esc_html__('Queue Status', 'directory-helpers'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th><?php echo esc_html__('Status:', 'directory-helpers'); ?></th>
                        <td id="dh-ppq-status">
                            <?php if ($is_active): ?>
                                <span style="color: #46b450;">● Active</span>
                            <?php else: ?>
                                <span style="color: #999;">● Stopped</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th><?php echo esc_html__('Total Profiles:', 'directory-helpers'); ?></th>
                        <td id="dh-ppq-total"><?php echo esc_html($total_profiles); ?></td>
                    </tr>
                    <tr>
                        <th><?php echo esc_html__('Processed:', 'directory-helpers'); ?></th>
                        <td id="dh-ppq-processed"><?php echo esc_html($processed_count); ?></td>
                    </tr>
                    <tr>
                        <th><?php echo esc_html__('Remaining:', 'directory-helpers'); ?></th>
                        <td id="dh-ppq-remaining"><?php echo esc_html($remaining); ?></td>
                    </tr>
                </table>
                
                <p>
                    <?php if ($is_active): ?>
                        <button type="button" class="button" id="dh-stop-ppq-btn"><?php echo esc_html__('Stop Queue', 'directory-helpers'); ?></button>
                    <?php else: ?>
                        <button type="button" class="button" id="dh-reset-ppq-btn"><?php echo esc_html__('Reset Queue', 'directory-helpers'); ?></button>
                    <?php endif; ?>
                </p>
            </div>
            
            <div class="card">
                <h2><?php echo esc_html__('Instructions', 'directory-helpers'); ?></h2>
                <p><?php echo esc_html__('To add profiles to the production queue:', 'directory-helpers'); ?></p>
                <ol>
                    <li><?php echo esc_html__('Go to the Prep Profiles by State page', 'directory-helpers'); ?></li>
                    <li><?php echo esc_html__('Use the filters to select the profiles you want to process', 'directory-helpers'); ?></li>
                    <li><?php echo esc_html__('Click "Add Profiles to Production Pipeline"', 'directory-helpers'); ?></li>
                    <li><?php echo esc_html__('The queue will automatically process profiles in batches', 'directory-helpers'); ?></li>
                </ol>
                <p>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=dh-prep-profiles')); ?>" class="button button-primary">
                        <?php echo esc_html__('Go to Prep Profiles', 'directory-helpers'); ?>
                    </a>
                </p>
            </div>
        </div>
        <?php
    }
    
    /**
     * AJAX: Start queue with profile data
     */
    public function ajax_start_queue() {
        check_ajax_referer('dh_profile_queue', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'));
        }
        
        $profile_ids = isset($_POST['profile_ids']) ? array_map('intval', $_POST['profile_ids']) : array();
        $state_slug = isset($_POST['state_slug']) ? sanitize_text_field($_POST['state_slug']) : '';
        $niche_slug = isset($_POST['niche_slug']) ? sanitize_text_field($_POST['niche_slug']) : '';
        $min_count = isset($_POST['min_count']) ? (int)$_POST['min_count'] : 2;
        $city_slug = isset($_POST['city_slug']) ? sanitize_text_field($_POST['city_slug']) : '';
        $city_search = isset($_POST['city_search']) ? sanitize_text_field($_POST['city_search']) : '';
        
        if (empty($profile_ids)) {
            wp_send_json_error(array('message' => 'No profiles selected'));
        }
        
        if (empty($state_slug)) {
            wp_send_json_error(array('message' => 'State is required'));
        }
        
        // Store queue data
        $queue_data = array(
            'profile_ids' => $profile_ids,
            'state_slug' => $state_slug,
            'niche_slug' => $niche_slug,
            'min_count' => $min_count,
            'city_slug' => $city_slug,
            'city_search' => $city_search,
            'created_city_ids' => array(),
        );
        
        update_option(self::OPTION_QUEUE_DATA, $queue_data);
        update_option(self::OPTION_QUEUE_ACTIVE, true);
        update_option(self::OPTION_PROCESSED_COUNT, 0);
        update_option(self::OPTION_CURRENT_BATCH, 0);
        delete_option(self::OPTION_LAST_ERROR);
        
        wp_send_json_success(array(
            'message' => 'Queue started - processing will begin immediately',
            'total' => count($profile_ids),
        ));
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
            'total' => $total,
            'remaining' => $total - $processed_count,
            'last_error' => $last_error,
            'message' => $is_active ? 'Batch processed' : 'Queue completed',
        ));
    }
    
    /**
     * AJAX: Get queue status
     */
    public function ajax_get_queue_status() {
        check_ajax_referer('dh_profile_queue', 'nonce');
        
        $is_active = (bool) get_option(self::OPTION_QUEUE_ACTIVE, false);
        $processed_count = (int) get_option(self::OPTION_PROCESSED_COUNT, 0);
        $queue_data = get_option(self::OPTION_QUEUE_DATA, array());
        $total = isset($queue_data['profile_ids']) ? count($queue_data['profile_ids']) : 0;
        
        wp_send_json_success(array(
            'is_active' => $is_active,
            'processed_count' => $processed_count,
            'total' => $total,
            'remaining' => $total - $processed_count,
        ));
    }
    
    /**
     * AJAX: Reset queue counters
     */
    public function ajax_reset_queue() {
        check_ajax_referer('dh_profile_queue', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'));
        }
        
        update_option(self::OPTION_PROCESSED_COUNT, 0);
        update_option(self::OPTION_CURRENT_BATCH, 0);
        delete_option(self::OPTION_QUEUE_DATA);
        delete_option(self::OPTION_LAST_ERROR);
        
        wp_send_json_success(array('message' => 'Queue reset'));
    }
    
    /**
     * Process next batch of profiles
     */
    public function process_next_batch() {
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
        $niche_slug = $queue_data['niche_slug'];
        $min_count = isset($queue_data['min_count']) ? $queue_data['min_count'] : 2;
        $city_slug = isset($queue_data['city_slug']) ? $queue_data['city_slug'] : '';
        $city_search = isset($queue_data['city_search']) ? $queue_data['city_search'] : '';
        $created_city_ids = isset($queue_data['created_city_ids']) ? $queue_data['created_city_ids'] : array();
        
        // Step 4: Rerank (use published only) - EXACTLY like one-click button
        $published = $this->query_profiles_by_state_and_status($state_slug, 'publish', $min_count, $city_slug, $niche_slug, $city_search);
        $pub_ids = wp_list_pluck($published, 'ID');
        $this->rerank_posts($pub_ids, $state_slug);
        
        // Step 5: Trigger AI for new city pages
        if (!empty($created_city_ids)) {
            $this->trigger_ai_for_cities($created_city_ids);
        }
    }
    
    /**
     * Query profiles by state and status - COPIED from prep-profiles-by-state
     */
    private function query_profiles_by_state_and_status($state_slug, $post_status, $min_count = 2, $city_slug = '', $niche_slug = 'dog-trainer', $city_search = '') {
        global $wpdb;
        if (empty($state_slug)) {
            return array();
        }
        $min_count = max(1, min(5, (int) $min_count));
        $prefix = $wpdb->prefix;
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
              AND t5.slug = %s";

        $params = array($state_slug, $niche_slug);

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

        $sql .= "\n              AND t2.term_id IN (
                SELECT t3.term_id
                FROM {$prefix}posts p3
                JOIN {$prefix}term_relationships tr3 ON p3.ID = tr3.object_id
                JOIN {$prefix}term_taxonomy tt3 ON tr3.term_taxonomy_id = tt3.term_taxonomy_id
                JOIN {$prefix}terms t3 ON tt3.term_id = t3.term_id
                JOIN {$prefix}term_relationships tr4 ON p3.ID = tr4.object_id
                JOIN {$prefix}term_taxonomy tt4 ON tr4.term_taxonomy_id = tt4.term_taxonomy_id
                JOIN {$prefix}terms t4 ON tt4.term_id = t4.term_id
                JOIN {$prefix}term_relationships tr5b ON p3.ID = tr5b.object_id
                JOIN {$prefix}term_taxonomy tt5b ON tr5b.term_taxonomy_id = tt5b.term_taxonomy_id
                JOIN {$prefix}terms t5b ON tt5b.term_id = t5b.term_id
                WHERE p3.post_type = 'profile'
                  AND tt4.taxonomy = 'state'
                  AND t4.slug = %s\n";
        $params[] = $state_slug;

        if ($post_status !== 'all') {
            $sql .= "                  AND p3.post_status = %s\n";
            $params[] = $post_status;
        }

        $sql .= "                  AND tt3.taxonomy = 'area'
                  AND tt5b.taxonomy = 'niche'
                  AND t5b.slug = %s
                GROUP BY t3.term_id
                HAVING COUNT(DISTINCT p3.ID) >= %d
              )
            ORDER BY t2.name ASC, p.post_title ASC";
        $params[] = $niche_slug;
        $params[] = $min_count;

        $prepared = $wpdb->prepare($sql, $params);
        return $wpdb->get_results($prepared);
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
        
        // Determine state code (prefer 2-letter slug, else term description, else parse from area name)
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
        
        // Title: "City, ST" (NO niche in title)
        $title = $city_name . ($state_code ? ', ' . $state_code : '');
        
        // Niche pluralization for slug (simple rules)
        $niche_name = ($niche_term && !is_wp_error($niche_term)) ? $niche_term->name : '';
        $plural_niche = $niche_name;
        if ($plural_niche) {
            if (preg_match('/[^aeiou]y$/i', $plural_niche)) {
                $plural_niche = preg_replace('/y$/i', 'ies', $plural_niche);
            } elseif (!preg_match('/s$/i', $plural_niche)) {
                $plural_niche .= 's';
            }
        }
        
        // Slug base: "Title + plural niche"
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
     * Helper: Clean area terms - removes " - ST" suffix from area term names
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
        
        // Only consider published posts for ranking
        $published_ids = array();
        foreach ($post_ids as $pid) {
            if (get_post_status($pid) === 'publish') {
                $published_ids[] = $pid;
            }
        }
        if (empty($published_ids)) {
            return;
        }
        
        // Re-rank city by city (area terms)
        $area_terms = $this->get_unique_area_terms_for_posts($published_ids);
        foreach ($area_terms as $term_id => $term) {
            // Use a representative post in this city to trigger ACF save hook ranking
            $rep = $this->find_post_in_term($published_ids, $term_id, 'area');
            if ($rep) {
                do_action('acf/save_post', $rep);
            }
        }
        
        // Finally, trigger a state ranking once using a representative post in the selected state
        $rep_state_post = $this->find_post_in_state($published_ids, $state_slug);
        if ($rep_state_post) {
            do_action('acf/save_post', $rep_state_post);
        }
    }
    
    /**
     * Helper: Get unique area terms for posts
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
                
                sleep(1); // Avoid overloading
            }
        }
    }
}
