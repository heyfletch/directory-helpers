<?php
/**
 * Video Production Queue Module
 *
 * Manages automated video production queue for city and state listings.
 * Integrates with Zero Work webhook for sequential video creation.
 *
 * @package Directory_Helpers
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class DH_Video_Production_Queue
 */
class DH_Video_Production_Queue {
    
    /**
     * Option keys for queue state
     */
    const OPTION_QUEUE_ACTIVE = 'dh_video_queue_active';
    const OPTION_CURRENT_POST = 'dh_video_queue_current_post';
    const OPTION_LAST_SENT = 'dh_video_queue_last_sent';
    const OPTION_RETRY_COUNT = 'dh_video_queue_retry_count';
    const OPTION_ATTEMPT_MAP = 'dh_video_queue_attempt_map';
    const OPTION_LAST_ERROR = 'dh_video_queue_last_error';
    
    /**
     * Rate limit in seconds
     */
    const RATE_LIMIT_SECONDS = 10;
    
    /**
     * Constructor
     */
    public function __construct() {
        // Add admin menu
        add_action('admin_menu', array($this, 'add_admin_menu'));
        
        // Enqueue admin scripts
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        
        // AJAX handlers
        add_action('wp_ajax_dh_start_video_queue', array($this, 'ajax_start_queue'));
        add_action('wp_ajax_dh_stop_video_queue', array($this, 'ajax_stop_queue'));
        add_action('wp_ajax_dh_get_video_queue_status', array($this, 'ajax_get_queue_status'));
        add_action('wp_ajax_dh_reset_video_queue', array($this, 'ajax_reset_queue'));
        add_action('wp_ajax_dh_process_video_next', array($this, 'ajax_process_next'));
        
        // Zero Work webhook handler
        add_action('rest_api_init', array($this, 'register_webhook_endpoint'));
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
        if (!wp_next_scheduled('dh_video_queue_process')) {
            wp_schedule_event(time(), 'five_minutes', 'dh_video_queue_process');
        }
    }
    
    /**
     * Add admin menu page
     */
    public function add_admin_menu() {
        add_submenu_page(
            'directory-helpers',
            __('Video Production', 'directory-helpers'),
            __('Video Production', 'directory-helpers'),
            'manage_options',
            'dh-video-production',
            array($this, 'render_admin_page')
        );
    }
    
    /**
     * Enqueue admin scripts
     */
    public function enqueue_admin_scripts($hook) {
        if ($hook !== 'directory-helpers_page_dh-video-production') {
            return;
        }
        
        wp_enqueue_style('dashicons');
        
        wp_enqueue_script(
            'dh-video-production-queue',
            plugin_dir_url(__FILE__) . 'assets/js/video-production-queue.js',
            array('jquery'),
            filemtime(plugin_dir_path(__FILE__) . 'assets/js/video-production-queue.js'),
            true
        );
        
        wp_localize_script('dh-video-production-queue', 'dhVideoQueue', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('dh_video_queue'),
            'isActive' => (bool) get_option(self::OPTION_QUEUE_ACTIVE, false),
        ));
    }
    
    /**
     * Register REST API callback endpoints
     */
    public function register_callback_endpoint() {
        // Endpoint for Zero Work to notify video completion (triggers next in queue)
        register_rest_route('directory-helpers/v1', '/video-completed', array(
            'methods' => 'POST',
            'callback' => array($this, 'handle_video_completed'),
            'permission_callback' => '__return_true', // We'll validate with secret key
        ));
        
        // Endpoint for Zero Work to update video_overview ACF field
        register_rest_route('directory-helpers/v1', '/update-video', array(
            'methods' => 'POST',
            'callback' => array($this, 'handle_update_video'),
            'permission_callback' => '__return_true', // We'll validate with secret key
        ));
    }
    
    /**
     * Render admin page
     */
    public function render_admin_page() {
        // Get queue state
        $is_active = (bool) get_option(self::OPTION_QUEUE_ACTIVE, false);
        $current_post_id = (int) get_option(self::OPTION_CURRENT_POST, 0);
        $last_error = get_option(self::OPTION_LAST_ERROR, '');
        
        // Get posts without video only
        $posts = $this->get_posts_without_video();
        
        // Get next eligible post for queue
        $next_post = $this->get_next_eligible_post();
        
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Video Production Queue', 'directory-helpers'); ?></h1>
            
            <?php if ($last_error): ?>
                <div class="notice notice-error is-dismissible" id="dh-video-error-notice">
                    <p><strong><?php esc_html_e('Zero Work Taskbot Workflow Failed:', 'directory-helpers'); ?></strong></p>
                    <p><?php echo esc_html($last_error); ?></p>
                    <button type="button" class="notice-dismiss" onclick="dhDismissError();"></button>
                </div>
                <script>
                function dhDismissError() {
                    jQuery.post(ajaxurl, {
                        action: 'dh_clear_video_error',
                        nonce: '<?php echo wp_create_nonce('dh_video_queue'); ?>'
                    });
                    jQuery('#dh-video-error-notice').fadeOut();
                }
                </script>
            <?php endif; ?>
            
            <div class="dh-video-queue-controls" style="background: #fff; padding: 20px; margin: 20px 0; border: 1px solid #ccd0d4; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
                <div style="margin-bottom: 15px;">
                    <strong><?php esc_html_e('Status:', 'directory-helpers'); ?></strong>
                    <span id="dh-queue-status">
                        <?php if ($is_active): ?>
                            <span style="color: #46b450;">● <?php esc_html_e('Active', 'directory-helpers'); ?></span>
                        <?php else: ?>
                            <span style="color: #999;">● <?php esc_html_e('Stopped', 'directory-helpers'); ?></span>
                        <?php endif; ?>
                    </span>
                </div>
                
                <div id="dh-current-processing" style="margin-bottom: 15px;<?php echo !$current_post_id ? ' display:none;' : ''; ?>">
                    <strong><?php esc_html_e('Currently Processing:', 'directory-helpers'); ?></strong>
                    <span id="dh-current-post-title">
                        <?php if ($current_post_id): ?>
                            <a href="<?php echo esc_url(get_edit_post_link($current_post_id)); ?>" target="_blank">
                                <?php echo esc_html(get_the_title($current_post_id)); ?>
                            </a>
                        <?php endif; ?>
                    </span>
                </div>
                
                <div id="dh-next-in-queue" style="margin-bottom: 15px;<?php echo !$next_post ? ' display:none;' : ''; ?>">
                    <strong><?php esc_html_e('Next in Queue:', 'directory-helpers'); ?></strong>
                    <span id="dh-next-post-title">
                        <?php if ($next_post): ?>
                            <a href="<?php echo esc_url(get_edit_post_link($next_post->ID)); ?>" target="_blank">
                                <?php echo esc_html(get_the_title($next_post->ID)); ?>
                            </a>
                            <span style="color: #666;">(<?php echo esc_html($next_post->post_type === 'state-listing' ? 'State' : 'City'); ?>)</span>
                        <?php endif; ?>
                    </span>
                </div>
                
                <div id="dh-no-posts-msg" style="margin-bottom: 15px; color: #999;<?php echo $next_post ? ' display:none;' : ''; ?>">
                    <?php esc_html_e('No posts in queue (all published posts have videos)', 'directory-helpers'); ?>
                </div>
                
                <div>
                    <?php if (!$is_active && $next_post): ?>
                        <button type="button" id="dh-start-queue-btn" class="button button-primary">
                            <?php esc_html_e('Start Video Production Queue', 'directory-helpers'); ?>
                        </button>
                    <?php elseif ($is_active): ?>
                        <button type="button" id="dh-stop-queue-btn" class="button button-secondary">
                            <?php esc_html_e('Stop Queue', 'directory-helpers'); ?>
                        </button>
                    <?php else: ?>
                        <button type="button" class="button button-primary" disabled>
                            <?php esc_html_e('Start Video Production Queue', 'directory-helpers'); ?>
                        </button>
                        <p class="description"><?php esc_html_e('No posts available in queue', 'directory-helpers'); ?></p>
                    <?php endif; ?>
                    
                    <button type="button" id="dh-reset-queue-btn" class="button" style="margin-left: 10px;">
                        <?php esc_html_e('Reset Queue Counters', 'directory-helpers'); ?>
                    </button>
                </div>
            </div>
            
            <div class="card">
                <h2><?php esc_html_e('How It Works', 'directory-helpers'); ?></h2>
                <p><?php esc_html_e('The video production queue automatically processes posts sequentially:', 'directory-helpers'); ?></p>
                <ol>
                    <li><?php esc_html_e('Finds the next eligible post (draft city/state listing without a video)', 'directory-helpers'); ?></li>
                    <li><?php esc_html_e('Sends the post to Zero Work for video creation', 'directory-helpers'); ?></li>
                    <li><?php esc_html_e('Waits for Zero Work to complete the video and send a webhook callback', 'directory-helpers'); ?></li>
                    <li><?php esc_html_e('Automatically continues to the next post', 'directory-helpers'); ?></li>
                </ol>
                <p><strong><?php esc_html_e('Note:', 'directory-helpers'); ?></strong> <?php esc_html_e('Videos typically take 10-15 minutes to create. Keep this page open for continuous processing.', 'directory-helpers'); ?></p>
            </div>
            
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Title', 'directory-helpers'); ?></th>
                        <th><?php esc_html_e('Type', 'directory-helpers'); ?></th>
                        <th><?php esc_html_e('Status', 'directory-helpers'); ?></th>
                        <th><?php esc_html_e('Video', 'directory-helpers'); ?></th>
                        <th><?php esc_html_e('Published', 'directory-helpers'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($posts)): ?>
                        <tr>
                            <td colspan="5"><?php esc_html_e('No posts found.', 'directory-helpers'); ?></td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($posts as $post): ?>
                            <?php
                            $video_url = get_field('video_overview', $post->ID);
                            $has_video = !empty($video_url);
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
                                <td><?php echo esc_html(ucfirst($post->post_status)); ?></td>
                                <td>
                                    <?php if ($has_video): ?>
                                        <span style="color: #46b450;">✓ <?php esc_html_e('Yes', 'directory-helpers'); ?></span>
                                    <?php else: ?>
                                        <span style="color: #999;">— <?php esc_html_e('No', 'directory-helpers'); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html(get_the_date('Y-m-d', $post->ID)); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
    
    /**
     * Get posts without video
     *
     * @return array Array of WP_Post objects (states first, then cities)
     */
    private function get_posts_without_video() {
        // Get state listings first
        $state_args = array(
            'post_type' => 'state-listing',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'orderby' => 'title',
            'order' => 'ASC',
        );
        $state_posts = get_posts($state_args);
        
        // Get city listings
        $city_args = array(
            'post_type' => 'city-listing',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'orderby' => 'title',
            'order' => 'ASC',
        );
        $city_posts = get_posts($city_args);
        
        // Combine: states first, then cities
        $posts = array_merge($state_posts, $city_posts);
        
        // Filter to only posts without video
        return array_filter($posts, function($post) {
            $video_url = get_field('video_overview', $post->ID);
            return empty($video_url);
        });
    }
    
    /**
     * Get next eligible post for video production
     * Priority: State listings first, then cities by oldest publish date
     *
     * @return WP_Post|null
     */
    private function get_next_eligible_post() {
        $attempt_map = get_option(self::OPTION_ATTEMPT_MAP, array());
        $options = get_option('directory_helpers_options', array());
        $max_retries = isset($options['video_queue_max_retries']) ? (int) $options['video_queue_max_retries'] : 0;
        
        // Get all state listings first (oldest first)
        $state_posts = get_posts(array(
            'post_type' => 'state-listing',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'orderby' => 'date',
            'order' => 'ASC',
        ));
        
        // Check state listings
        foreach ($state_posts as $post) {
            $video = get_field('video_overview', $post->ID);
            if (empty($video)) {
                $attempts = isset($attempt_map[$post->ID]) ? (int) $attempt_map[$post->ID] : 0;
                if ($attempts <= $max_retries) {
                    return $post;
                }
            }
        }
        
        // Get all city listings (oldest first)
        $city_posts = get_posts(array(
            'post_type' => 'city-listing',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'orderby' => 'date',
            'order' => 'ASC',
        ));
        
        // Check city listings
        foreach ($city_posts as $post) {
            $video = get_field('video_overview', $post->ID);
            if (empty($video)) {
                $attempts = isset($attempt_map[$post->ID]) ? (int) $attempt_map[$post->ID] : 0;
                if ($attempts <= $max_retries) {
                    return $post;
                }
            }
        }
        
        return null;
    }
    
    /**
     * Send post to Zero Work webhook
     *
     * @param int $post_id Post ID
     * @return array Result array with 'success' and 'message'
     */
    private function send_to_zerowork($post_id) {
        $post = get_post($post_id);
        if (!$post) {
            return array('success' => false, 'message' => 'Invalid post ID');
        }
        
        // Get webhook URL from settings
        $options = get_option('directory_helpers_options');
        $webhook_url = $options['notebook_webhook_url'] ?? '';
        
        if (empty($webhook_url)) {
            return array('success' => false, 'message' => 'Notebook webhook URL not configured');
        }
        
        // Build keyword from post title
        $raw_title = wp_strip_all_tags(get_the_title($post));
        $clean_title = trim(preg_replace('/[^\p{L}\p{N}\s]/u', '', $raw_title));
        $clean_title = preg_replace('/\s+/', ' ', $clean_title);
        $keyword = 'dog training in ' . $clean_title;
        
        // Get post data
        $post_url = get_permalink($post_id);
        $post_title = wp_strip_all_tags(get_the_title($post_id));
        
        // Get featured image URL
        $featured_image_url = '';
        $thumb_id = get_post_thumbnail_id($post_id);
        if ($thumb_id) {
            $src = wp_get_attachment_image_src($thumb_id, 'full');
            if (is_array($src) && !empty($src[0])) {
                $featured_image_url = esc_url_raw($src[0]);
            } else {
                $maybe = wp_get_attachment_url($thumb_id);
                if ($maybe) {
                    $featured_image_url = esc_url_raw($maybe);
                }
            }
        }
        
        // Get video title and YouTube description using AI Content Generator methods
        $video_title = '';
        $youtube_description = '';
        if (class_exists('DH_AI_Content_Generator')) {
            $ai_generator = new DH_AI_Content_Generator();
            $reflection = new ReflectionClass($ai_generator);
            
            try {
                $video_title_method = $reflection->getMethod('generate_video_title');
                $video_title_method->setAccessible(true);
                $video_title = $video_title_method->invoke($ai_generator, $post_id);
                
                $youtube_desc_method = $reflection->getMethod('generate_youtube_description');
                $youtube_desc_method->setAccessible(true);
                $youtube_description = $youtube_desc_method->invoke($ai_generator, $post_id);
            } catch (Exception $e) {
                $video_title = $post_title;
                $youtube_description = '';
            }
        } else {
            $video_title = $post_title;
        }
        
        // Build payload
        $payload = array(
            'postId' => $post_id,
            'postUrl' => $post_url,
            'postTitle' => $post_title,
            'keyword' => $keyword,
            'videoTitle' => $video_title,
            'youtubeDescription' => $youtube_description,
            'featuredImage' => $featured_image_url,
            'source' => 'queue',
        );
        
        // Send webhook request
        $response = wp_remote_post($webhook_url, array(
            'headers' => array('Content-Type' => 'application/json'),
            'body' => wp_json_encode($payload),
            'timeout' => 20,
        ));
        
        if (is_wp_error($response)) {
            return array('success' => false, 'message' => $response->get_error_message());
        }
        
        $code = (int) wp_remote_retrieve_response_code($response);
        
        if ($code >= 200 && $code < 300) {
            return array('success' => true, 'message' => 'Webhook sent successfully');
        } else {
            $message = wp_remote_retrieve_response_message($response);
            
            // Add helpful context for common errors
            if ($code === 405) {
                $message .= ' - Make sure ZeroWork Webhook is Active in its settings.';
            }
            
            return array('success' => false, 'message' => sprintf('Webhook error: %s (code: %d)', $message, $code));
        }
    }
    
    /**
     * AJAX handler: Start queue
     */
    public function ajax_start_queue() {
        check_ajax_referer('dh_video_queue', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'));
            return;
        }
        
        // Check rate limit
        $last_sent = (int) get_option(self::OPTION_LAST_SENT, 0);
        $time_since = time() - $last_sent;
        
        if ($time_since < self::RATE_LIMIT_SECONDS) {
            $wait_time = self::RATE_LIMIT_SECONDS - $time_since;
            wp_send_json_error(array('message' => sprintf('Please wait %d seconds before starting queue', $wait_time)));
            return;
        }
        
        // Get next post
        $next_post = $this->get_next_eligible_post();
        
        if (!$next_post) {
            wp_send_json_error(array('message' => 'No posts in queue'));
            return;
        }
        
        // Update attempt count
        $attempt_map = get_option(self::OPTION_ATTEMPT_MAP, array());
        $post_id = $next_post->ID;
        $attempts = isset($attempt_map[$post_id]) ? (int) $attempt_map[$post_id] : 0;
        $attempts++;
        $attempt_map[$post_id] = $attempts;
        update_option(self::OPTION_ATTEMPT_MAP, $attempt_map);
        
        // Check if exceeded max retries
        $options = get_option('directory_helpers_options', array());
        $max_retries = isset($options['video_queue_max_retries']) ? (int) $options['video_queue_max_retries'] : 0;
        if ($attempts > ($max_retries + 1)) {
            // Stop queue
            update_option(self::OPTION_QUEUE_ACTIVE, false);
            update_option(self::OPTION_CURRENT_POST, 0);
            update_option(self::OPTION_LAST_ERROR, sprintf('Post "%s" exceeded maximum retry attempts (%d)', get_the_title($post_id), $max_retries + 1));
            
            wp_send_json_error(array('message' => 'Post exceeded maximum retry attempts. Queue stopped.'));
            return;
        }
        
        // Mark queue as active
        update_option(self::OPTION_QUEUE_ACTIVE, true);
        update_option(self::OPTION_CURRENT_POST, $post_id);
        update_option(self::OPTION_LAST_SENT, time());
        delete_option(self::OPTION_LAST_ERROR);
        
        // Send to Zero Work
        $result = $this->send_to_zerowork($post_id);
        
        if ($result['success']) {
            wp_send_json_success(array(
                'message' => sprintf('Started processing: %s', get_the_title($post_id)),
                'postTitle' => get_the_title($post_id),
            ));
        } else {
            // Mark queue as inactive on failure
            update_option(self::OPTION_QUEUE_ACTIVE, false);
            update_option(self::OPTION_CURRENT_POST, 0);
            update_option(self::OPTION_LAST_ERROR, $result['message']);
            
            wp_send_json_error(array('message' => $result['message']));
        }
    }
    
    /**
     * AJAX handler: Stop queue
     */
    public function ajax_stop_queue() {
        check_ajax_referer('dh_video_queue', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'));
            return;
        }
        
        update_option(self::OPTION_QUEUE_ACTIVE, false);
        update_option(self::OPTION_CURRENT_POST, 0);
        
        wp_send_json_success(array('message' => 'Queue stopped'));
    }
    
    /**
     * AJAX handler: Process next post (called by frontend polling)
     */
    public function ajax_process_next() {
        check_ajax_referer('dh_video_queue', 'nonce');
        
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
        
        // Check if we're waiting for a video to complete
        $current_post_id = (int) get_option(self::OPTION_CURRENT_POST, 0);
        if ($current_post_id) {
            // Still processing current post, don't send next
            wp_send_json_success(array(
                'is_active' => true,
                'waiting' => true,
                'current_post_id' => $current_post_id,
                'current_post_title' => get_the_title($current_post_id),
                'message' => 'Waiting for current video to complete',
            ));
            return;
        }
        
        // Process the next post
        $this->process_next_in_queue();
        
        // Get updated status
        $is_active = (bool) get_option(self::OPTION_QUEUE_ACTIVE, false);
        $current_post_id = (int) get_option(self::OPTION_CURRENT_POST, 0);
        $last_error = get_option(self::OPTION_LAST_ERROR, '');
        
        $current_post_title = '';
        if ($current_post_id) {
            $current_post_title = get_the_title($current_post_id);
        }
        
        wp_send_json_success(array(
            'is_active' => $is_active,
            'current_post_id' => $current_post_id,
            'current_post_title' => $current_post_title,
            'last_error' => $last_error,
            'message' => $is_active ? 'Processing' : 'Queue completed',
        ));
    }
    
    /**
     * AJAX handler: Get queue status
     */
    public function ajax_get_queue_status() {
        check_ajax_referer('dh_video_queue', 'nonce');
        
        $is_active = (bool) get_option(self::OPTION_QUEUE_ACTIVE, false);
        $current_post_id = (int) get_option(self::OPTION_CURRENT_POST, 0);
        
        $current_post_title = '';
        $current_post_link = '';
        if ($current_post_id) {
            $current_post_title = get_the_title($current_post_id);
            $current_post_link = get_edit_post_link($current_post_id);
        }
        
        $next_post = $this->get_next_eligible_post();
        $next_post_title = '';
        $next_post_link = '';
        $next_post_type = '';
        if ($next_post) {
            $next_post_title = get_the_title($next_post->ID);
            $next_post_link = get_edit_post_link($next_post->ID);
            $next_post_type = $next_post->post_type === 'state-listing' ? 'State' : 'City';
        }
        
        wp_send_json_success(array(
            'is_active' => $is_active,
            'current_post_title' => $current_post_title,
            'current_post_link' => $current_post_link,
            'next_post_title' => $next_post_title,
            'next_post_link' => $next_post_link,
            'next_post_type' => $next_post_type,
        ));
    }
    
    /**
     * AJAX handler: Clear error message
     */
    public function ajax_clear_error() {
        check_ajax_referer('dh_video_queue', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'));
            return;
        }
        
        delete_option(self::OPTION_LAST_ERROR);
        
        wp_send_json_success(array('message' => 'Error cleared'));
    }
    
    /**
     * AJAX handler: Reset queue counters
     */
    public function ajax_reset_queue() {
        check_ajax_referer('dh_video_queue', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'));
            return;
        }
        
        // Clear all queue state
        delete_option(self::OPTION_ATTEMPT_MAP);
        delete_option(self::OPTION_LAST_ERROR);
        update_option(self::OPTION_QUEUE_ACTIVE, false);
        update_option(self::OPTION_CURRENT_POST, 0);
        
        wp_send_json_success(array('message' => 'Queue counters reset. Page will reload.'));
    }
    
    /**
     * Handle video completion callback from Zero Work
     */
    public function handle_video_completed(WP_REST_Request $request) {
        $params = $request->get_json_params();
        
        // Log the callback for debugging
        error_log('Video Queue Callback Received: ' . print_r($params, true));
        
        // Validate secret key
        $options = get_option('directory_helpers_options');
        $secret_key = $options['shared_secret_key'] ?? '';
        $received_secret = isset($params['secretKey']) ? sanitize_text_field($params['secretKey']) : '';
        
        if (empty($secret_key) || $received_secret !== $secret_key) {
            error_log('Video Queue Callback: Invalid secret key');
            return new WP_Error('rest_forbidden', 'Invalid secret key', array('status' => 403));
        }
        
        $post_id = isset($params['postId']) ? absint($params['postId']) : 0;
        $status = isset($params['status']) ? sanitize_text_field($params['status']) : 'success';
        
        if (!$post_id) {
            return new WP_Error('missing_parameters', 'Missing postId', array('status' => 400));
        }
        
        // Clear current post
        update_option(self::OPTION_CURRENT_POST, 0);
        
        // Check if queue is still active
        $is_active = (bool) get_option(self::OPTION_QUEUE_ACTIVE, false);
        
        if (!$is_active) {
            return new WP_REST_Response(array('message' => 'Queue is stopped'), 200);
        }
        
        if ($status === 'success') {
            error_log('Video Queue Callback: Success status received');
            
            // Clear current post so next one can be processed
            update_option(self::OPTION_CURRENT_POST, 0);
            
            // Return response immediately so TaskBot can complete
            // Next post will be processed by AJAX polling or cron
            return new WP_REST_Response(array('message' => 'Callback received, next post will be processed shortly'), 200);
        } else {
            // Failure: Stop queue and log error
            error_log('Video Queue Callback: Failure status received');
            update_option(self::OPTION_QUEUE_ACTIVE, false);
            $error_msg = isset($params['error']) ? sanitize_text_field($params['error']) : 'Unknown error';
            update_option(self::OPTION_LAST_ERROR, sprintf('Zero Work failed for post %d: %s', $post_id, $error_msg));
            
            return new WP_REST_Response(array('message' => 'Queue stopped due to failure'), 200);
        }
    }
    
    /**
     * Process next post in queue (called by scheduled event)
     */
    public function process_next_in_queue() {
        error_log('Video Queue: Processing next post after delay');
        
        // Check if queue is still active
        $is_active = (bool) get_option(self::OPTION_QUEUE_ACTIVE, false);
        
        if (!$is_active) {
            error_log('Video Queue: Queue is not active, stopping');
            return;
        }
        
        $next_post = $this->get_next_eligible_post();
        
        if ($next_post) {
            error_log('Video Queue: Next post found - ID: ' . $next_post->ID . ', Title: ' . get_the_title($next_post->ID));
            
            // Update attempt count
            $attempt_map = get_option(self::OPTION_ATTEMPT_MAP, array());
            $next_id = $next_post->ID;
            $attempts = isset($attempt_map[$next_id]) ? (int) $attempt_map[$next_id] : 0;
            $attempts++;
            $attempt_map[$next_id] = $attempts;
            update_option(self::OPTION_ATTEMPT_MAP, $attempt_map);
            
            // Check retry limit
            $options = get_option('directory_helpers_options', array());
            $max_retries = isset($options['video_queue_max_retries']) ? (int) $options['video_queue_max_retries'] : 0;
            if ($attempts > ($max_retries + 1)) {
                update_option(self::OPTION_QUEUE_ACTIVE, false);
                update_option(self::OPTION_LAST_ERROR, sprintf('Post "%s" exceeded maximum retry attempts (%d)', get_the_title($next_id), $max_retries + 1));
                error_log('Video Queue: Max retries exceeded for post ' . $next_id);
                return;
            }
            
            update_option(self::OPTION_CURRENT_POST, $next_id);
            update_option(self::OPTION_LAST_SENT, time());
            
            $result = $this->send_to_zerowork($next_id);
            
            if (!$result['success']) {
                update_option(self::OPTION_QUEUE_ACTIVE, false);
                update_option(self::OPTION_CURRENT_POST, 0);
                update_option(self::OPTION_LAST_ERROR, $result['message']);
                error_log('Video Queue: Failed to send next post - ' . $result['message']);
            } else {
                error_log('Video Queue: Successfully sent next post to Zero Work');
            }
        } else {
            // No more posts, stop queue
            error_log('Video Queue: No more posts in queue, stopping');
            update_option(self::OPTION_QUEUE_ACTIVE, false);
        }
    }
    
    /**
     * Handle update video URL from Zero Work
     */
    public function handle_update_video(WP_REST_Request $request) {
        $params = $request->get_json_params();
        
        // Validate secret key
        $options = get_option('directory_helpers_options');
        $secret_key = $options['shared_secret_key'] ?? '';
        $received_secret = isset($params['secretKey']) ? sanitize_text_field($params['secretKey']) : '';
        
        if (empty($secret_key) || $received_secret !== $secret_key) {
            return new WP_Error('rest_forbidden', 'Invalid secret key', array('status' => 403));
        }
        
        $post_id = isset($params['postId']) ? absint($params['postId']) : 0;
        $video_url = isset($params['videoUrl']) ? esc_url_raw($params['videoUrl']) : '';
        
        if (!$post_id) {
            return new WP_Error('missing_parameters', 'Missing postId', array('status' => 400));
        }
        
        if (empty($video_url)) {
            return new WP_Error('missing_parameters', 'Missing videoUrl', array('status' => 400));
        }
        
        // Verify post exists
        $post = get_post($post_id);
        if (!$post) {
            return new WP_Error('invalid_post', 'Post not found', array('status' => 404));
        }
        
        // Update ACF field
        $updated = update_field('video_overview', $video_url, $post_id);
        
        if ($updated) {
            return new WP_REST_Response(array(
                'success' => true,
                'message' => 'Video URL updated successfully',
                'postId' => $post_id,
                'videoUrl' => $video_url
            ), 200);
        } else {
            return new WP_Error('update_failed', 'Failed to update video URL', array('status' => 500));
        }
    }
}
