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
        add_action('wp_ajax_dh_trigger_featured_image_webhook', array($this, 'ajax_trigger_featured_image_webhook'));
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
            'successMessageThumb' => __('✅ Featured image generation triggered!', 'directory-helpers'),
            'errorMessage' => __('Error triggering webhook. Please try again.', 'directory-helpers'),
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
                        <?php /* Hidden for future use
                        <button type="button" id="dh-start-cpq-all-btn" class="button button-secondary" data-mode="all" title="<?php esc_attr_e('Publish Cities including Link Health Warnings or Unchecked', 'directory-helpers'); ?>" style="margin-left: 10px;">
                            <?php esc_html_e('Publish All Cities', 'directory-helpers'); ?>
                        </button>
                        */ ?>
                    <?php elseif ($is_active): ?>
                        <button type="button" id="dh-stop-cpq-btn" class="button button-secondary">
                            <?php esc_html_e('Stop Queue', 'directory-helpers'); ?>
                        </button>
                    <?php else: ?>
                        <button type="button" class="button button-primary" disabled>
                            <?php esc_html_e('Publish Healthy Cities', 'directory-helpers'); ?>
                        </button>
                        <p class="description"><?php esc_html_e('No eligible posts to publish', 'directory-helpers'); ?></p>
                    <?php endif; ?>
                    
                    <?php if (!$is_active): ?>
                        <button type="button" id="dh-recheck-all-health-btn" class="button" style="margin-left: 10px;">
                            <?php esc_html_e('Recheck All Link Health', 'directory-helpers'); ?>
                        </button>
                        <button type="button" id="dh-open-non-healthy-btn" class="button" style="margin-left: 10px;">
                            <?php esc_html_e('↗️ Open Non-Healthy Cities', 'directory-helpers'); ?>
                        </button>
                        <button type="button" id="dh-open-unchecked-btn" class="button" style="margin-left: 10px;">
                            <?php esc_html_e('↗️ Open Unchecked Cities', 'directory-helpers'); ?>
                        </button>
                        <button type="button" id="dh-refresh-page-btn" class="button" style="margin-left: 10px;">
                            <?php esc_html_e('Refresh Page', 'directory-helpers'); ?>
                        </button>
                        <span id="dh-recheck-status" style="margin-left: 10px;"></span>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="dh-cpq-draft-posts">
                <h2><?php esc_html_e('Draft Cities', 'directory-helpers'); ?></h2>
                <p class="description">
                    <?php esc_html_e('Posts eligible for publishing must have: Featured Image, Body Image 1, Body Image 2, and Link Health (All Ok or Warning).', 'directory-helpers'); ?>
                </p>
                
                <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width: 120px;"><?php esc_html_e('Title', 'directory-helpers'); ?></th>
                        <th style="width: 1215px;"><?php esc_html_e('Images', 'directory-helpers'); ?></th>
                        <th style="width: 150px;"><?php esc_html_e('Link Health', 'directory-helpers'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($all_draft_posts)): ?>
                        <tr>
                            <td colspan="3"><?php esc_html_e('No draft posts found.', 'directory-helpers'); ?></td>
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
                            $has_images = $all_images_present ? 1 : 0;
                            ?>
                            <tr data-post-id="<?php echo esc_attr($post->ID); ?>" data-health="<?php echo esc_attr($link_health); ?>" data-has-images="<?php echo $has_images; ?>">
                                <td>
                                    <strong>
                                        <a href="<?php echo esc_url(get_edit_post_link($post->ID)); ?>" target="_blank">
                                            <?php echo esc_html(get_the_title($post->ID)); ?>
                                        </a>
                                    </strong>
                                </td>
                                <td style="white-space: nowrap;">
                                    <?php
                                    // Featured Image - clickable to trigger webhook
                                    $nonce_thumb = wp_create_nonce('dh_trigger_thumb_' . $post->ID);
                                    if ($has_featured) {
                                        echo '<a href="#" class="dh-trigger-thumb-link" data-post-id="' . esc_attr($post->ID) . '" data-nonce="' . esc_attr($nonce_thumb) . '" title="' . esc_attr__('Click to generate new featured image', 'directory-helpers') . '" style="display: inline-block; margin-right: 5px; vertical-align: middle; border: 2px solid transparent; transition: border-color 0.2s;" onmouseover="this.style.borderColor=\'#0073aa\'" onmouseout="this.style.borderColor=\'transparent\'">';
                                        echo wp_get_attachment_image(get_post_thumbnail_id($post->ID), 'medium', false, array('style' => 'width: 400px; height: auto; display: block;'));
                                        echo '</a>';
                                    } else {
                                        echo '<span style="display: inline-block; width: 400px; height: 300px; background: #ddd; margin-right: 5px; vertical-align: middle; text-align: center; line-height: 300px; color: #999; font-size: 14px;">No Featured</span>';
                                    }
                                    
                                    // Body Image 1
                                    $body1_id = get_post_meta($post->ID, 'body_image_1', true);
                                    if ($has_body1 && $body1_id) {
                                        echo wp_get_attachment_image($body1_id, 'medium', false, array('style' => 'width: 400px; height: auto; margin-right: 5px; vertical-align: middle;'));
                                    } else {
                                        echo '<span style="display: inline-block; width: 400px; height: 300px; background: #ddd; margin-right: 5px; vertical-align: middle; text-align: center; line-height: 300px; color: #999; font-size: 14px;">No Body 1</span>';
                                    }
                                    
                                    // Body Image 2
                                    $body2_id = get_post_meta($post->ID, 'body_image_2', true);
                                    if ($has_body2 && $body2_id) {
                                        echo wp_get_attachment_image($body2_id, 'medium', false, array('style' => 'width: 400px; height: auto; vertical-align: middle;'));
                                    } else {
                                        echo '<span style="display: inline-block; width: 400px; height: 300px; background: #ddd; vertical-align: middle; text-align: center; line-height: 300px; color: #999; font-size: 14px;">No Body 2</span>';
                                    }
                                    ?>
                                </td>
                                <td><?php echo wp_kses_post($link_health_display); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            </div><!-- .dh-cpq-draft-posts -->
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
            'posts_per_page' => 100, // Limit to 100 most recent drafts for performance
            'orderby' => 'date',
            'order' => 'DESC', // Newest first
            'fields' => 'all', // Get all post data
        );
        
        $posts = get_posts($args);
        
        // Prime meta cache to avoid N+1 queries
        if (!empty($posts)) {
            $post_ids = wp_list_pluck($posts, 'ID');
            update_meta_cache('post', $post_ids);
            update_post_thumbnail_cache(get_posts(array('post__in' => $post_ids, 'post_type' => 'any')));
        }
        
        // Sort by link health: all_ok first, then warning, then not checked, then red_alert
        usort($posts, function($a, $b) {
            $health_a = get_post_meta($a->ID, '_dh_link_health', true);
            $health_b = get_post_meta($b->ID, '_dh_link_health', true);
            
            // Define priority order (lower number = higher priority)
            $priority_map = array(
                'all_ok' => 1,
                'warning' => 2,
                '' => 3, // Not checked
                'red_alert' => 4,
            );
            
            $priority_a = isset($priority_map[$health_a]) ? $priority_map[$health_a] : 3;
            $priority_b = isset($priority_map[$health_b]) ? $priority_map[$health_b] : 3;
            
            if ($priority_a !== $priority_b) {
                return $priority_a - $priority_b;
            }
            
            // Within same priority, maintain date order (newest first)
            return strtotime($b->post_date) - strtotime($a->post_date);
        });
        
        return $posts;
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
            'posts_per_page' => 100, // Match UI limit - only show/publish what user sees
            'orderby' => 'date',
            'order' => 'DESC', // Newest first to match UI
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
        delete_option('dh_cpq_affected_states'); // Clear old tracking
        
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
        delete_option('dh_cpq_affected_states');
        
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
        
        // Disable expensive cache operations during batch publish
        global $dh_lscache_integration;
        $prime_hooked = false;
        if ($dh_lscache_integration && method_exists($dh_lscache_integration, 'prime_on_publish_or_update')) {
            remove_action('transition_post_status', array($dh_lscache_integration, 'prime_on_publish_or_update'), 11);
            $prime_hooked = true;
        }
        
        // Track affected state slugs across entire queue run
        $affected_states = get_option('dh_cpq_affected_states', array());
        
        // Track published city slugs for ranking updates and cache purging
        $published_cities = get_option('dh_cpq_published_cities', array());
        
        $last_post_id = 0;
        for ($i = 0; $i < $batch_count; $i++) {
            $post = $posts[$i];
            $last_post_id = $post->ID;
            
            // Track state and city for this city-listing
            if ($post->post_type === 'city-listing') {
                $state_terms = get_the_terms($post->ID, 'state');
                if (!empty($state_terms) && !is_wp_error($state_terms)) {
                    $affected_states[$state_terms[0]->slug] = $state_terms[0]->slug;
                }
                
                // Track city area slug for post-publishing actions
                $area_terms = get_the_terms($post->ID, 'area');
                if (!empty($area_terms) && !is_wp_error($area_terms)) {
                    $city_slug = $area_terms[0]->slug;
                    $published_cities[$city_slug] = $city_slug;
                }
            }
            
            // Publish the post using direct database update for speed
            global $wpdb;
            $result = $wpdb->update(
                $wpdb->posts,
                array(
                    'post_status' => 'publish',
                    'post_date' => current_time('mysql'),
                    'post_date_gmt' => current_time('mysql', 1),
                    'post_modified' => current_time('mysql'),
                    'post_modified_gmt' => current_time('mysql', 1)
                ),
                array('ID' => $post->ID),
                array('%s', '%s', '%s', '%s', '%s'),
                array('%d')
            );
            
            if ($result !== false) {
                // Clear WordPress object cache so the post shows as published
                clean_post_cache($post->ID);
                
                // Update profile count for city-listings so they appear in video queue
                if ($post->post_type === 'city-listing') {
                    $area_terms = get_the_terms($post->ID, 'area');
                    if (!empty($area_terms) && !is_wp_error($area_terms)) {
                        // City-listings should only have one area term
                        $area_term_id = $area_terms[0]->term_id;
                        // Count published profiles in this city
                        $profile_count = count(get_posts(array(
                            'post_type' => 'profile',
                            'post_status' => 'publish',
                            'posts_per_page' => -1,
                            'tax_query' => array(
                                array('taxonomy' => 'area', 'field' => 'term_id', 'terms' => $area_term_id),
                            ),
                            'fields' => 'ids',
                        )));
                        update_post_meta($post->ID, '_profile_count', (int) $profile_count);
                    }
                }
                
                // Create shortlink for city/state listings (e.g., goodydoggy.com/burlington-vt)
                if (in_array($post->post_type, array('city-listing', 'state-listing')) && class_exists('DH_Shortlinks')) {
                    $shortlinks = new DH_Shortlinks();
                    $shortlinks->create_shortlink_for_post($post->ID);
                }
                
                $published_count++;
            }
        }
        
        // Update all options once at end of batch
        update_option(self::OPTION_CURRENT_POST, $last_post_id);
        update_option(self::OPTION_PUBLISHED_COUNT, $published_count);
        update_option('dh_cpq_affected_states', $affected_states, false);
        update_option('dh_cpq_published_cities', $published_cities, false);
        
        // Re-hook cache priming
        if ($prime_hooked && $dh_lscache_integration) {
            add_action('transition_post_status', array($dh_lscache_integration, 'prime_on_publish_or_update'), 11, 3);
        }
        
        // Check if there are more posts to process
        $remaining_posts = $this->get_eligible_posts($mode);
        
        if (empty($remaining_posts)) {
            // All done, stop queue
            update_option(self::OPTION_QUEUE_ACTIVE, false);
            update_option(self::OPTION_CURRENT_POST, 0);
            
            // Get niche from queue mode option or default to dog-trainer
            $niche_slug = 'dog-trainer'; // Default
            $niche_term = get_term_by('slug', $niche_slug, 'niche');
            $niche_id = $niche_term ? $niche_term->term_id : 0;
            
            // Process published cities: update rankings and purge caches
            $published_cities_final = get_option('dh_cpq_published_cities', array());
            if (!empty($published_cities_final) && $niche_id) {
                foreach ($published_cities_final as $city_slug) {
                    // Update rankings for this city
                    $this->update_city_rankings($city_slug, $niche_id);
                    
                    // Purge caches for this city
                    $this->purge_city_caches($city_slug);
                }
            }
            
            // Clear affected state-listing caches at completion - do this SYNCHRONOUSLY
            $affected_states_final = get_option('dh_cpq_affected_states', array());
            if (!empty($affected_states_final)) {
                foreach ($affected_states_final as $state_slug) {
                    $state_listing_id = $this->get_state_listing_by_slug($state_slug);
                    if ($state_listing_id) {
                        do_action('litespeed_purge_post', $state_listing_id);
                    }
                }
            }
            
            // Clean up tracking
            delete_option('dh_cpq_affected_states');
            delete_option('dh_cpq_published_cities');
        }
        // If there are more posts, they will be processed by the next AJAX poll
    }
    
    /**
     * Get state-listing post ID by state slug
     */
    private function get_state_listing_by_slug($state_slug) {
        $q = new WP_Query(array(
            'post_type' => 'state-listing',
            'post_status' => 'publish',
            'posts_per_page' => 1,
            'tax_query' => array(
                array('taxonomy' => 'state', 'field' => 'slug', 'terms' => $state_slug),
            ),
            'fields' => 'ids',
        ));
        return !empty($q->posts) ? $q->posts[0] : 0;
    }
    
    /**
     * Update rankings for a city by saving one profile to trigger recalculation
     * 
     * @param string $city_slug Area term slug (e.g., 'winter-park-fl')
     * @param int $niche_id Niche term ID
     */
    private function update_city_rankings($city_slug, $niche_id) {
        // Get one profile from this city with no ranking (or any profile if all have rankings)
        $profile = get_posts(array(
            'post_type' => 'profile',
            'post_status' => 'publish',
            'posts_per_page' => 1,
            'tax_query' => array(
                array('taxonomy' => 'area', 'field' => 'slug', 'terms' => $city_slug),
                array('taxonomy' => 'niche', 'field' => 'term_id', 'terms' => $niche_id),
            ),
            'meta_query' => array(
                array(
                    'key' => 'ranking',
                    'compare' => 'NOT EXISTS',
                ),
            ),
            'fields' => 'ids',
        ));
        
        // If no profiles without rankings, get any profile from this city
        if (empty($profile)) {
            $profile = get_posts(array(
                'post_type' => 'profile',
                'post_status' => 'publish',
                'posts_per_page' => 1,
                'tax_query' => array(
                    array('taxonomy' => 'area', 'field' => 'slug', 'terms' => $city_slug),
                    array('taxonomy' => 'niche', 'field' => 'term_id', 'terms' => $niche_id),
                ),
                'fields' => 'ids',
            ));
        }
        
        if (!empty($profile)) {
            // Trigger save to recalculate all rankings in this city
            // Use a minimal update to avoid triggering unnecessary hooks
            wp_update_post(array(
                'ID' => $profile[0],
                'post_modified' => current_time('mysql'),
                'post_modified_gmt' => current_time('mysql', 1),
            ));
        }
    }
    
    /**
     * Purge caches for all posts in a city (profiles, city-listing)
     * 
     * @param string $city_slug Area term slug (e.g., 'winter-park-fl')
     */
    private function purge_city_caches($city_slug) {
        // 1. Purge city-listing
        $city_listing = get_posts(array(
            'post_type' => 'city-listing',
            'post_status' => 'publish',
            'posts_per_page' => 1,
            'tax_query' => array(
                array('taxonomy' => 'area', 'field' => 'slug', 'terms' => $city_slug),
            ),
            'fields' => 'ids',
        ));
        
        if (!empty($city_listing)) {
            do_action('litespeed_purge_post', $city_listing[0]);
        }
        
        // 2. Purge all profiles in this city
        $profiles = get_posts(array(
            'post_type' => 'profile',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'tax_query' => array(
                array('taxonomy' => 'area', 'field' => 'slug', 'terms' => $city_slug),
            ),
            'fields' => 'ids',
        ));
        
        foreach ($profiles as $profile_id) {
            do_action('litespeed_purge_post', $profile_id);
        }
    }
    
    /**
     * Clear caches for affected state-listing pages only
     */
    private function clear_affected_state_caches() {
        $affected_states = get_option('dh_cpq_affected_states', array());
        
        if (empty($affected_states)) {
            return;
        }
        
        foreach ($affected_states as $state_slug) {
            $state_listing_id = $this->get_state_listing_by_slug($state_slug);
            if ($state_listing_id) {
                // Purge LiteSpeed cache
                do_action('litespeed_purge_post', $state_listing_id);
                // Prime cache with non-blocking request
                $state_url = get_permalink($state_listing_id);
                if ($state_url) {
                    wp_remote_get($state_url, array('blocking' => false, 'timeout' => 0.01));
                }
            }
        }
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
    
    /**
     * AJAX: Trigger featured image webhook
     */
    public function ajax_trigger_featured_image_webhook() {
        $post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;
        $nonce = isset($_POST['nonce']) ? sanitize_text_field($_POST['nonce']) : '';
        
        // Verify nonce
        if (!wp_verify_nonce($nonce, 'dh_trigger_thumb_' . $post_id)) {
            wp_send_json_error(array('message' => __('Invalid security token.', 'directory-helpers')));
            return;
        }
        
        // Check permissions
        if (!current_user_can('edit_post', $post_id)) {
            wp_send_json_error(array('message' => __('Insufficient permissions.', 'directory-helpers')));
            return;
        }
        
        // Get post
        $post = get_post($post_id);
        if (!$post || !in_array($post->post_type, array('city-listing', 'state-listing'), true)) {
            wp_send_json_error(array('message' => __('Invalid post.', 'directory-helpers')));
            return;
        }
        
        // Get webhook URL from settings
        $options = get_option('directory_helpers_options');
        $webhook_url = $options['featured_image_webhook_url'] ?? '';
        
        if (empty($webhook_url)) {
            wp_send_json_error(array('message' => __('Featured Image webhook URL not configured.', 'directory-helpers')));
            return;
        }
        
        // Build keyword from post title
        $raw_title = wp_strip_all_tags(get_the_title($post));
        $clean_title = trim(preg_replace('/[^\p{L}\p{N}\s,]/u', '', $raw_title));
        $clean_title = preg_replace('/\s+/', ' ', $clean_title);
        
        // Get niche from taxonomy description (or fallback to name, then default)
        $niche_text = 'dog training'; // Default fallback
        $niche_terms = get_the_terms($post_id, 'niche');
        if ($niche_terms && !is_wp_error($niche_terms) && !empty($niche_terms)) {
            $term_description = trim($niche_terms[0]->description);
            if (!empty($term_description)) {
                $niche_text = $term_description;
            } else {
                // Fallback to Title Case name if description is empty
                $niche_text = ucwords(strtolower($niche_terms[0]->name));
            }
        }
        
        $keyword = $niche_text . ' in ' . $clean_title;
        
        // Get post URL and title
        $post_url = get_permalink($post_id);
        $post_title = wp_strip_all_tags(get_the_title($post_id));
        
        // Build payload
        $payload = array(
            'postId' => $post_id,
            'postUrl' => $post_url,
            'postTitle' => $post_title,
            'keyword' => $keyword,
        );
        
        // Send webhook request
        $response = wp_remote_post($webhook_url, array(
            'headers' => array('Content-Type' => 'application/json'),
            'body' => wp_json_encode($payload),
            'timeout' => 20,
        ));
        
        if (is_wp_error($response)) {
            wp_send_json_error(array('message' => $response->get_error_message()));
            return;
        }
        
        $code = (int) wp_remote_retrieve_response_code($response);
        
        if ($code >= 200 && $code < 300) {
            wp_send_json_success(array(
                'message' => __('Featured image generation triggered successfully!', 'directory-helpers'),
            ));
        } else {
            $message = wp_remote_retrieve_response_message($response);
            wp_send_json_error(array('message' => sprintf(__('Webhook returned error: %s (code: %d)', 'directory-helpers'), $message, $code)));
        }
    }
}
