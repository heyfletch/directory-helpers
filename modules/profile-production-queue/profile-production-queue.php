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

        $sql .= "\n            GROUP BY p.ID
            HAVING COUNT(DISTINCT tr2.term_taxonomy_id) >= %d
            ORDER BY t2.name ASC, p.post_title ASC";
        $params[] = $min_count;

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
        $profiles = !empty($state_slug) ? $this->query_profiles_by_state_and_status($state_slug, $post_status, $min_count, $city_slug, $niche_slug, $city_search) : array();
        $profile_count = count($profiles);
        
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Profile Production Queue', 'directory-helpers'); ?></h1>
            
            <?php if ($last_error): ?>
                <div class="notice notice-error is-dismissible">
                    <p><strong><?php esc_html_e('Error:', 'directory-helpers'); ?></strong> <?php echo esc_html($last_error); ?></p>
                </div>
            <?php endif; ?>
            
            <!-- Filter Form -->
            <form method="get" action="" style="background: #fff; padding: 20px; margin: 20px 0; border: 1px solid #ccd0d4; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
                <input type="hidden" name="post_type" value="state-listing" />
                <input type="hidden" name="page" value="dh-profile-production-queue" />
                
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
            <div style="margin: 20px 0;">
                <button type="button" id="dh-add-to-pipeline-btn" class="button button-primary" <?php echo $profile_count === 0 ? 'disabled' : ''; ?>>
                    <?php echo sprintf(esc_html__('Add Profiles to Production Pipeline (%d profiles)', 'directory-helpers'), $profile_count); ?>
                </button>
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
                    <button type="button" id="dh-reset-ppq-btn" class="button"><?php esc_html_e('Reset Queue', 'directory-helpers'); ?></button>
                </p>
                <p style="margin: 10px 0 0 0;">
                    <a href="<?php echo esc_url(admin_url('edit.php?post_type=state-listing&page=dh-content-production-queue')); ?>"><?php esc_html_e('→ Go to Content Production Queue', 'directory-helpers'); ?></a>
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
            
            <!-- Instructions -->
            <div class="card" style="margin-top: 20px;">
                <h2><?php esc_html_e('How It Works', 'directory-helpers'); ?></h2>
                <ol>
                    <li><?php esc_html_e('Use the filters to select profiles by state, city, status, and niche', 'directory-helpers'); ?></li>
                    <li><?php esc_html_e('Click "Add Profiles to Production Pipeline" to queue the filtered profiles', 'directory-helpers'); ?></li>
                    <li><?php esc_html_e('The queue will automatically process profiles in batches', 'directory-helpers'); ?></li>
                    <li><?php esc_html_e('Each batch: creates city pages, publishes profiles, reranks, and triggers AI content generation', 'directory-helpers'); ?></li>
                </ol>
            </div>
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
        
        $profile_ids = wp_list_pluck($profiles, 'ID');
        
        // Store queue data
        update_option(self::OPTION_QUEUE_DATA, array(
            'profile_ids' => $profile_ids,
            'state_slug' => $state_slug,
            'niche_slug' => $niche_slug,
        ));
        update_option(self::OPTION_PROCESSED_COUNT, 0);
        update_option(self::OPTION_CURRENT_BATCH, 0);
        delete_option(self::OPTION_LAST_ERROR);
        
        wp_send_json_success(array(
            'message' => sprintf('Added %d profiles to pipeline', count($profile_ids)),
            'total' => count($profile_ids),
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
     * AJAX: Process batch (placeholder - implement actual batch processing)
     */
    public function ajax_process_batch() {
        check_ajax_referer('dh_profile_queue', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'));
        }
        
        // TODO: Implement actual batch processing logic
        wp_send_json_success(array('message' => 'Batch processed'));
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
