<?php
/**
 * Content Production Queue Module
 *
 * Manages automated content publishing queue for city and state listings.
 * Publishes draft posts that meet all content requirements sequentially.
 *
 * @package Directory_Helpers
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class DH_Content_Production_Queue
 */
class DH_Content_Production_Queue {
    
    /**
     * Option keys for queue state
     */
    const OPTION_QUEUE_ACTIVE = 'dh_content_queue_active';
    const OPTION_CURRENT_POST = 'dh_content_queue_current_post';
    const OPTION_PUBLISHED_COUNT = 'dh_content_queue_published_count';
    const OPTION_QUEUE_MODE = 'dh_content_queue_mode';
    
    /**
     * Rate limit in seconds between batch cycles
     */
    const RATE_LIMIT_SECONDS = 2;
    
    /**
     * Number of posts to process per batch cycle
     */
    const BATCH_SIZE = 5;
    
    /**
     * Constructor
     */
    public function __construct() {
        // Add admin menu with priority 20 to ensure parent menu exists first
        add_action('admin_menu', array($this, 'add_admin_menu'), 20);
        
        // Enqueue admin scripts
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        
        // AJAX handlers
        add_action('wp_ajax_dh_start_content_queue', array($this, 'ajax_start_queue'));
        add_action('wp_ajax_dh_stop_content_queue', array($this, 'ajax_stop_queue'));
        add_action('wp_ajax_dh_get_content_queue_status', array($this, 'ajax_get_queue_status'));
        add_action('wp_ajax_dh_reset_content_queue', array($this, 'ajax_reset_queue'));
        add_action('wp_ajax_dh_process_content_batch', array($this, 'ajax_process_batch'));
        add_action('wp_ajax_dh_recheck_all_link_health', array($this, 'ajax_recheck_all_link_health'));
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
     * Add admin menu page
     */
    public function add_admin_menu() {
        add_submenu_page(
            'directory-helpers',
            __('Content Production', 'directory-helpers'),
            __('Content Production', 'directory-helpers'),
            'manage_options',
            'dh-content-production',
            array($this, 'render_admin_page')
        );
    }
    
    /**
     * Enqueue admin scripts
     */
    public function enqueue_admin_scripts($hook) {
        if ($hook !== 'directory-helpers_page_dh-content-production') {
            return;
        }
        
        wp_enqueue_style('dashicons');
        
        wp_enqueue_script(
            'dh-content-production-queue',
            plugin_dir_url(__FILE__) . 'assets/js/content-production-queue.js',
            array('jquery'),
            filemtime(plugin_dir_path(__FILE__) . 'assets/js/content-production-queue.js'),
            true
        );
        
        wp_localize_script('dh-content-production-queue', 'dhContentQueue', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('dh_content_queue'),
            'isActive' => (bool) get_option(self::OPTION_QUEUE_ACTIVE, false),
        ));
    }
    
    /**
     * Render admin page
     */
    public function render_admin_page() {
        $is_active = (bool) get_option(self::OPTION_QUEUE_ACTIVE, false);
        $current_post_id = (int) get_option(self::OPTION_CURRENT_POST, 0);
        
        // Get ALL draft posts for display (including those missing images)
        $all_draft_posts = $this->get_all_draft_posts();
        
        $current_post_title = '';
        if ($current_post_id && $is_active) {
            $current_post_title = get_the_title($current_post_id);
        }
        
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Content Production Queue', 'directory-helpers'); ?></h1>
            
            <div class="dh-cpq-status-box" style="background: #fff; border: 1px solid #ccd0d4; border-radius: 4px; padding: 20px; margin: 20px 0;">
                <div class="dh-cpq-status-info">
                    <p>
                        <strong><?php esc_html_e('Status:', 'directory-helpers'); ?></strong>
                        <span id="dh-cpq-status-text">
                            <?php if ($is_active): ?>
                                <span style="color: #46b450;">▶️ <?php esc_html_e('Running', 'directory-helpers'); ?></span>
                            <?php else: ?>
                                <span style="color: #999;">⏸️ <?php esc_html_e('Stopped', 'directory-helpers'); ?></span>
                            <?php endif; ?>
                        </span>
                    </p>
                    
                    <?php if ($is_active && $current_post_title): ?>
                    <p>
                        <strong><?php esc_html_e('Current:', 'directory-helpers'); ?></strong>
                        <span id="dh-cpq-current-post"><?php echo esc_html($current_post_title); ?></span>
                    </p>
                    <?php endif; ?>
                </div>
                
                <div class="dh-cpq-controls" style="margin-top: 20px;">
                    <?php if (!$is_active && !empty($all_draft_posts)): ?>
                        <button type="button" id="dh-start-cpq-healthy-btn" class="button button-primary" data-mode="healthy">
                            <?php esc_html_e('Publish Healthy Cities', 'directory-helpers'); ?>
                        </button>
                        <button type="button" id="dh-start-cpq-all-btn" class="button button-secondary" data-mode="all" title="<?php esc_attr_e('Publish Cities including Link Health Warnings or Unchecked', 'directory-helpers'); ?>" style="margin-left: 10px;">
                            <?php esc_html_e('Publish All Cities', 'directory-helpers'); ?>
                        </button>
                    <?php elseif ($is_active): ?>
                        <button type="button" id="dh-stop-cpq-btn" class="button button-secondary">
                            <?php esc_html_e('Stop Queue', 'directory-helpers'); ?>
                        </button>
                        <button type="button" id="dh-reset-cpq-btn" class="button" style="margin-left: 10px;">
                            <?php esc_html_e('Reset Counters', 'directory-helpers'); ?>
                        </button>
                    <?php else: ?>
                        <button type="button" class="button button-primary" disabled>
                            <?php esc_html_e('Publish Healthy Cities', 'directory-helpers'); ?>
                        </button>
                        <button type="button" class="button button-secondary" disabled style="margin-left: 10px;">
                            <?php esc_html_e('Publish All Cities', 'directory-helpers'); ?>
                        </button>
                        <p class="description"><?php esc_html_e('No eligible posts to publish', 'directory-helpers'); ?></p>
                    <?php endif; ?>
                    
                    <?php if (!$is_active): ?>
                        <button type="button" id="dh-reset-cpq-btn" class="button" style="margin-left: 10px;">
                            <?php esc_html_e('Reset Counters', 'directory-helpers'); ?>
                        </button>
                    <?php endif; ?>
                </div>
            </div>
            
            <h2><?php esc_html_e('Draft Posts', 'directory-helpers'); ?></h2>
            <p class="description">
                <?php esc_html_e('Posts eligible for publishing must have: Featured Image, Body Image 1, Body Image 2, and Link Health (All Ok or Warning).', 'directory-helpers'); ?>
            </p>
            <p>
                <button type="button" id="dh-recheck-all-health-btn" class="button">
                    <?php esc_html_e('Recheck All Link Health', 'directory-helpers'); ?>
                </button>
                <span id="dh-recheck-status" style="margin-left: 10px;"></span>
            </p>
            
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Title', 'directory-helpers'); ?></th>
                        <th><?php esc_html_e('Type', 'directory-helpers'); ?></th>
                        <th><?php esc_html_e('Link Health', 'directory-helpers'); ?></th>
                        <th><?php esc_html_e('Images', 'directory-helpers'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($all_draft_posts)): ?>
                        <tr>
                            <td colspan="4"><?php esc_html_e('No draft posts found.', 'directory-helpers'); ?></td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($all_draft_posts as $post): ?>
                            <?php
                            $link_health = get_post_meta($post->ID, '_dh_link_health', true);
                            $link_health_display = $this->get_link_health_display($link_health);
                            
                            // Check if all images are present
                            $has_featured = has_post_thumbnail($post->ID);
                            $has_body1 = !empty(get_post_meta($post->ID, 'body_image_1', true));
                            $has_body2 = !empty(get_post_meta($post->ID, 'body_image_2', true));
                            $all_images_present = $has_featured && $has_body1 && $has_body2;
                            ?>
                            <tr>
                                <td>
                                    <strong>
                                        <a href="<?php echo esc_url(get_edit_post_link($post->ID)); ?>" target="_blank">
                                            <?php echo esc_html(get_the_title($post->ID)); ?>
                                        </a>
                                    </strong>
                                </td>
                                <td><?php echo esc_html($post->post_type === 'state-listing' ? 'State' : 'City'); ?></td>
                                <td><?php echo wp_kses_post($link_health_display); ?></td>
                                <td>
                                    <?php if ($all_images_present): ?>
                                        <span style="color: #46b450;">✓ <?php esc_html_e('All Present', 'directory-helpers'); ?></span>
                                    <?php else: ?>
                                        <span style="color: #dc3232;">❌ <?php esc_html_e('Missing Images', 'directory-helpers'); ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
    
    /**
     * Get link health display HTML
     */
    private function get_link_health_display($health) {
        switch ($health) {
            case 'all_ok':
                return '<span style="color: #46b450;">✅ All Ok</span>';
            case 'warning':
                return '<span style="color: #f0b849;">⚠️ Warning</span>';
            case 'red_alert':
                return '<span style="color: #dc3232;">🚨 Red Alert</span>';
            default:
                return '<span style="color: #999;">❓ Not Checked</span>';
        }
    }
    
    /**
     * Get all draft posts for display (including those missing images)
     * Sorted by: All Ok, Warning, Not Checked, then by date
     *
     * @return array Array of WP_Post objects
     */
    private function get_all_draft_posts() {
        // Simple query without sorting - just get drafts ordered by date
        $args = array(
            'post_type' => array('city-listing', 'state-listing'),
            'post_status' => 'draft',
            'posts_per_page' => 500, // Limit to 500 most recent drafts for performance
            'orderby' => 'date',
            'order' => 'DESC', // Newest first
            'fields' => 'all', // Get all post data
        );
        
        return get_posts($args);
    }
    
    /**
     * Get eligible posts for publishing
     *
     * @param string $mode 'healthy' = only all_ok/not_exists, 'all' = include warnings
     * @return array Array of WP_Post objects
     */
    private function get_eligible_posts($mode = 'all') {
        $base_args = array(
            'post_type' => array('city-listing', 'state-listing'),
            'post_status' => 'draft',
            'posts_per_page' => -1,
            'orderby' => 'date',
            'order' => 'ASC',
            'meta_query' => array(
                'relation' => 'AND',
                // Must have featured image
                array(
                    'key' => '_thumbnail_id',
                    'compare' => 'EXISTS',
                ),
                // Must have body_image_1
                array(
                    'key' => 'body_image_1',
                    'compare' => 'EXISTS',
                ),
                // Must have body_image_2
                array(
                    'key' => 'body_image_2',
                    'compare' => 'EXISTS',
                ),
            ),
        );
        
        // Add link health filter based on mode
        if ($mode === 'healthy') {
            // Only all_ok or not exists (no warnings)
            $base_args['meta_query'][] = array(
                'relation' => 'OR',
                array(
                    'key' => '_dh_link_health',
                    'value' => 'all_ok',
                    'compare' => '=',
                ),
                array(
                    'key' => '_dh_link_health',
                    'compare' => 'NOT EXISTS',
                ),
            );
        } else {
            // Include warnings
            $base_args['meta_query'][] = array(
                'relation' => 'OR',
                array(
                    'key' => '_dh_link_health',
                    'value' => 'all_ok',
                    'compare' => '=',
                ),
                array(
                    'key' => '_dh_link_health',
                    'value' => 'warning',
                    'compare' => '=',
                ),
                array(
                    'key' => '_dh_link_health',
                    'compare' => 'NOT EXISTS',
                ),
            );
        }
        
        $posts = get_posts($base_args);
        
        // Sort: all_ok and not_exists first, warnings last
        if ($mode === 'all') {
            usort($posts, function($a, $b) {
                $health_a = get_post_meta($a->ID, '_dh_link_health', true);
                $health_b = get_post_meta($b->ID, '_dh_link_health', true);
                
                // Warning posts go to end
                $priority_a = ($health_a === 'warning') ? 1 : 0;
                $priority_b = ($health_b === 'warning') ? 1 : 0;
                
                if ($priority_a !== $priority_b) {
                    return $priority_a - $priority_b;
                }
                
                // Within same priority, maintain date order
                return strtotime($a->post_date) - strtotime($b->post_date);
            });
        }
        
        return $posts;
    }
    
    /**
     * Ensure cron is scheduled on init
     */
    public function ensure_cron_scheduled() {
        if (!wp_next_scheduled('dh_content_queue_process')) {
            wp_schedule_event(time(), 'five_minutes', 'dh_content_queue_process');
        }
    }
    
    /**
     * Activate cron on plugin activation
     */
    public function activate_cron() {
        if (!wp_next_scheduled('dh_content_queue_process')) {
            wp_schedule_event(time(), 'five_minutes', 'dh_content_queue_process');
        }
    }
    
    /**
     * Deactivate cron on plugin deactivation
     */
    public function deactivate_cron() {
        wp_clear_scheduled_hook('dh_content_queue_process');
    }
    
    /**
     * AJAX: Start the queue
     */
    public function ajax_start_queue() {
        check_ajax_referer('dh_content_queue', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'));
        }
        
        // Get mode from request (healthy or all)
        $mode = isset($_POST['mode']) && $_POST['mode'] === 'healthy' ? 'healthy' : 'all';
        
        // Get eligible posts based on mode
        $posts = $this->get_eligible_posts($mode);
        
        if (empty($posts)) {
            wp_send_json_error(array('message' => 'No eligible posts to publish'));
        }
        
        // Reset counters and set queue active
        update_option(self::OPTION_QUEUE_ACTIVE, true);
        update_option(self::OPTION_PUBLISHED_COUNT, 0);
        update_option(self::OPTION_CURRENT_POST, 0);
        update_option(self::OPTION_QUEUE_MODE, $mode);
        
        // Process first batch immediately
        $this->process_next_in_queue();
        
        wp_send_json_success(array(
            'message' => 'Queue started - processing in batches',
            'total' => count($posts),
        ));
    }
    
    /**
     * AJAX: Stop the queue
     */
    public function ajax_stop_queue() {
        check_ajax_referer('dh_content_queue', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'));
        }
        
        update_option(self::OPTION_QUEUE_ACTIVE, false);
        update_option(self::OPTION_CURRENT_POST, 0);
        
        wp_send_json_success(array('message' => 'Queue stopped'));
    }
    
    /**
     * AJAX: Process next batch (called by frontend polling)
     */
    public function ajax_process_batch() {
        check_ajax_referer('dh_content_queue', 'nonce');
        
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
        $this->process_next_in_queue();
        
        // Get updated status
        $is_active = (bool) get_option(self::OPTION_QUEUE_ACTIVE, false);
        $current_post_id = (int) get_option(self::OPTION_CURRENT_POST, 0);
        $published_count = (int) get_option(self::OPTION_PUBLISHED_COUNT, 0);
        
        $current_post_title = '';
        if ($current_post_id) {
            $current_post_title = get_the_title($current_post_id);
        }
        
        wp_send_json_success(array(
            'is_active' => $is_active,
            'current_post_title' => $current_post_title,
            'published_count' => $published_count,
            'message' => $is_active ? 'Batch processed' : 'Queue completed',
        ));
    }
    
    /**
     * AJAX: Get queue status
     */
    public function ajax_get_queue_status() {
        check_ajax_referer('dh_content_queue', 'nonce');
        
        $is_active = (bool) get_option(self::OPTION_QUEUE_ACTIVE, false);
        $current_post_id = (int) get_option(self::OPTION_CURRENT_POST, 0);
        $published_count = (int) get_option(self::OPTION_PUBLISHED_COUNT, 0);
        
        $current_post_title = '';
        if ($current_post_id) {
            $current_post_title = get_the_title($current_post_id);
        }
        
        wp_send_json_success(array(
            'is_active' => $is_active,
            'current_post_title' => $current_post_title,
            'published_count' => $published_count,
        ));
    }
    
    /**
     * AJAX: Reset queue counters
     */
    public function ajax_reset_queue() {
        check_ajax_referer('dh_content_queue', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'));
        }
        
        update_option(self::OPTION_PUBLISHED_COUNT, 0);
        update_option(self::OPTION_CURRENT_POST, 0);
        
        wp_send_json_success(array('message' => 'Counters reset'));
    }
    
    /**
     * Process next batch of posts in queue
     */
    public function process_next_in_queue() {
        // Check if queue is still active
        if (!get_option(self::OPTION_QUEUE_ACTIVE, false)) {
            return;
        }
        
        // Get mode from stored option
        $mode = get_option(self::OPTION_QUEUE_MODE, 'all');
        
        // Get eligible posts based on stored mode
        $posts = $this->get_eligible_posts($mode);
        
        if (empty($posts)) {
            // No more posts, stop queue
            update_option(self::OPTION_QUEUE_ACTIVE, false);
            update_option(self::OPTION_CURRENT_POST, 0);
            return;
        }
        
        // Process batch of posts (up to BATCH_SIZE)
        $batch_count = min(self::BATCH_SIZE, count($posts));
        $published_count = (int) get_option(self::OPTION_PUBLISHED_COUNT, 0);
        
        for ($i = 0; $i < $batch_count; $i++) {
            $post = $posts[$i];
            
            // Update current post for UI display
            update_option(self::OPTION_CURRENT_POST, $post->ID);
            
            // Publish the post
            $result = wp_update_post(array(
                'ID' => $post->ID,
                'post_status' => 'publish',
            ), true);
            
            if (!is_wp_error($result)) {
                $published_count++;
                update_option(self::OPTION_PUBLISHED_COUNT, $published_count);
            }
            
            // Small delay between posts in the batch to avoid overwhelming the server
            if ($i < $batch_count - 1) {
                usleep(100000); // 0.1 second delay between posts in batch
            }
        }
        
        // Check if there are more posts to process
        $remaining_posts = $this->get_eligible_posts($mode);
        
        if (empty($remaining_posts)) {
            // All done, stop queue
            update_option(self::OPTION_QUEUE_ACTIVE, false);
            update_option(self::OPTION_CURRENT_POST, 0);
        }
        // If there are more posts, they will be processed by the next AJAX poll
    }
    
    /**
     * AJAX: Recheck all link health for draft posts
     */
    public function ajax_recheck_all_link_health() {
        check_ajax_referer('dh_content_queue', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'));
        }
        
        // Get all draft posts
        $posts = $this->get_all_draft_posts();
        
        if (empty($posts)) {
            wp_send_json_success(array('message' => 'No draft posts to check'));
        }
        
        // Check if External Link Management class exists
        if (!class_exists('DH_External_Link_Management')) {
            wp_send_json_error(array('message' => 'External Link Management module not found'));
        }
        
        $elm = new DH_External_Link_Management();
        $checked = 0;
        
        // Process all posts - just recalculate health from existing status codes (no HTTP checks)
        foreach ($posts as $post) {
            // Use reflection to call the private update_post_link_health method
            $reflection = new ReflectionClass($elm);
            $method = $reflection->getMethod('update_post_link_health');
            $method->setAccessible(true);
            $method->invoke($elm, $post->ID);
            $checked++;
        }
        
        wp_send_json_success(array(
            'message' => sprintf('Rechecked %d post(s)', $checked),
            'checked' => $checked,
            'total' => count($posts)
        ));
    }
}
