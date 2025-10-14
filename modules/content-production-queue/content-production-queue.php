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
    
    /**
     * Rate limit in seconds between batch cycles
     */
    const RATE_LIMIT_SECONDS = 2;
    
    /**
     * Number of posts to process per batch cycle
     */
    const BATCH_SIZE = 4;
    
    /**
     * Constructor
     */
    public function __construct() {
        // Add admin menu
        add_action('admin_menu', array($this, 'add_admin_menu'));
        
        // Enqueue admin scripts
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        
        // AJAX handlers
        add_action('wp_ajax_dh_start_content_queue', array($this, 'ajax_start_queue'));
        add_action('wp_ajax_dh_stop_content_queue', array($this, 'ajax_stop_queue'));
        add_action('wp_ajax_dh_get_content_queue_status', array($this, 'ajax_get_queue_status'));
        add_action('wp_ajax_dh_reset_content_queue', array($this, 'ajax_reset_queue'));
        
        // Hook for scheduled event to publish next post
        add_action('dh_content_queue_publish_next', array($this, 'process_next_in_queue'));
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
        $published_count = (int) get_option(self::OPTION_PUBLISHED_COUNT, 0);
        
        // Get eligible posts
        $eligible_posts = $this->get_eligible_posts();
        $total_eligible = count($eligible_posts);
        
        $current_post_title = '';
        if ($current_post_id && $is_active) {
            $current_post_title = get_the_title($current_post_id);
        }
        
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Content Production Queue', 'directory-helpers'); ?></h1>
            
            <div class="dh-cpq-status-box" style="background: #fff; border: 1px solid #ccd0d4; border-radius: 4px; padding: 20px; margin: 20px 0;">
                <h2 style="margin-top: 0;"><?php esc_html_e('Queue Status', 'directory-helpers'); ?></h2>
                
                <div class="dh-cpq-status-info">
                    <p>
                        <strong><?php esc_html_e('Status:', 'directory-helpers'); ?></strong>
                        <span id="dh-cpq-status-text">
                            <?php if ($is_active): ?>
                                <span style="color: #46b450;">▶️ <?php esc_html_e('Running', 'directory-helpers'); ?></span>
                            <?php else: ?>
                                <span style="color: #999;">⏸️ <?php esc_html_e('Idle', 'directory-helpers'); ?></span>
                            <?php endif; ?>
                        </span>
                    </p>
                    
                    <p>
                        <strong><?php esc_html_e('Eligible Posts:', 'directory-helpers'); ?></strong>
                        <span id="dh-cpq-eligible-count"><?php echo esc_html($total_eligible); ?></span>
                    </p>
                    
                    <p>
                        <strong><?php esc_html_e('Published:', 'directory-helpers'); ?></strong>
                        <span id="dh-cpq-published-count"><?php echo esc_html($published_count); ?></span>
                        <span id="dh-cpq-published-total"> / <?php echo esc_html($total_eligible); ?></span>
                    </p>
                    
                    <?php if ($is_active && $current_post_title): ?>
                    <p>
                        <strong><?php esc_html_e('Current:', 'directory-helpers'); ?></strong>
                        <span id="dh-cpq-current-post"><?php echo esc_html($current_post_title); ?></span>
                    </p>
                    <?php endif; ?>
                </div>
                
                <div class="dh-cpq-controls" style="margin-top: 20px;">
                    <?php if (!$is_active && $total_eligible > 0): ?>
                        <button type="button" id="dh-start-cpq-btn" class="button button-primary">
                            <?php esc_html_e('Start Publishing Queue', 'directory-helpers'); ?>
                        </button>
                    <?php elseif ($is_active): ?>
                        <button type="button" id="dh-stop-cpq-btn" class="button">
                            <?php esc_html_e('Stop Queue', 'directory-helpers'); ?>
                        </button>
                    <?php else: ?>
                        <button type="button" class="button button-primary" disabled>
                            <?php esc_html_e('Start Publishing Queue', 'directory-helpers'); ?>
                        </button>
                        <p class="description"><?php esc_html_e('No eligible posts to publish', 'directory-helpers'); ?></p>
                    <?php endif; ?>
                    
                    <button type="button" id="dh-reset-cpq-btn" class="button" style="margin-left: 10px;">
                        <?php esc_html_e('Reset Counters', 'directory-helpers'); ?>
                    </button>
                </div>
            </div>
            
            <h2><?php esc_html_e('Eligible Posts', 'directory-helpers'); ?></h2>
            <p class="description">
                <?php esc_html_e('Posts must have: Featured Image, Body Image 1, Body Image 2, Draft Status, and Link Health (All Ok or Warning).', 'directory-helpers'); ?>
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
                    <?php if (empty($eligible_posts)): ?>
                        <tr>
                            <td colspan="4"><?php esc_html_e('No eligible posts found.', 'directory-helpers'); ?></td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($eligible_posts as $post): ?>
                            <?php
                            $link_health = get_post_meta($post->ID, '_dh_link_health', true);
                            $link_health_display = $this->get_link_health_display($link_health);
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
                                <td><span style="color: #46b450;">✓ <?php esc_html_e('All Present', 'directory-helpers'); ?></span></td>
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
     * Get eligible posts for publishing
     *
     * @return array Array of WP_Post objects
     */
    private function get_eligible_posts() {
        $args = array(
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
                // Link Health must be all_ok, warning, or not exist (no links)
                array(
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
                ),
            ),
        );
        
        return get_posts($args);
    }
    
    /**
     * AJAX: Start the queue
     */
    public function ajax_start_queue() {
        check_ajax_referer('dh_content_queue', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'));
        }
        
        // Get eligible posts
        $posts = $this->get_eligible_posts();
        
        if (empty($posts)) {
            wp_send_json_error(array('message' => 'No eligible posts to publish'));
        }
        
        // Reset counters and set queue active
        update_option(self::OPTION_QUEUE_ACTIVE, true);
        update_option(self::OPTION_PUBLISHED_COUNT, 0);
        update_option(self::OPTION_CURRENT_POST, 0);
        
        // Schedule first post immediately
        wp_schedule_single_event(time(), 'dh_content_queue_publish_next');
        
        wp_send_json_success(array(
            'message' => 'Queue started',
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
        
        // Clear any scheduled events
        wp_clear_scheduled_hook('dh_content_queue_publish_next');
        
        wp_send_json_success(array('message' => 'Queue stopped'));
    }
    
    /**
     * AJAX: Get queue status
     */
    public function ajax_get_queue_status() {
        check_ajax_referer('dh_content_queue', 'nonce');
        
        $is_active = (bool) get_option(self::OPTION_QUEUE_ACTIVE, false);
        $current_post_id = (int) get_option(self::OPTION_CURRENT_POST, 0);
        $published_count = (int) get_option(self::OPTION_PUBLISHED_COUNT, 0);
        
        $eligible_posts = $this->get_eligible_posts();
        $total_eligible = count($eligible_posts);
        
        $current_post_title = '';
        if ($current_post_id) {
            $current_post_title = get_the_title($current_post_id);
        }
        
        wp_send_json_success(array(
            'is_active' => $is_active,
            'published_count' => $published_count,
            'total_eligible' => $total_eligible,
            'current_post_title' => $current_post_title,
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
        
        // Get eligible posts
        $posts = $this->get_eligible_posts();
        
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
        $remaining_posts = $this->get_eligible_posts();
        
        if (!empty($remaining_posts)) {
            // Schedule next batch with rate limit
            wp_schedule_single_event(time() + self::RATE_LIMIT_SECONDS, 'dh_content_queue_publish_next');
        } else {
            // All done, stop queue
            update_option(self::OPTION_QUEUE_ACTIVE, false);
            update_option(self::OPTION_CURRENT_POST, 0);
        }
    }
}
