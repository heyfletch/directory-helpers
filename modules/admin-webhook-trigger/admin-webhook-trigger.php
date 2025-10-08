<?php
/**
 * Admin Webhook Trigger Module
 *
 * Adds a custom column and row action to city-listing and state-listing admin pages
 * to trigger the Notebook webhook for individual posts.
 *
 * @package Directory_Helpers
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class DH_Admin_Webhook_Trigger
 */
class DH_Admin_Webhook_Trigger {
    
    /**
     * Constructor
     */
    public function __construct() {
        // Add custom column to admin post list
        add_filter('manage_city-listing_posts_columns', array($this, 'add_webhook_column'));
        add_filter('manage_state-listing_posts_columns', array($this, 'add_webhook_column'));
        
        // Populate custom column content
        add_action('manage_city-listing_posts_custom_column', array($this, 'render_webhook_column'), 10, 2);
        add_action('manage_state-listing_posts_custom_column', array($this, 'render_webhook_column'), 10, 2);
        
        // Add row action
        add_filter('post_row_actions', array($this, 'add_webhook_row_action'), 10, 2);
        
        // Enqueue admin scripts
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        
        // AJAX handler for triggering webhook
        add_action('wp_ajax_dh_trigger_notebook_webhook', array($this, 'ajax_trigger_webhook'));
    }
    
    /**
     * Add custom column to post list table
     *
     * @param array $columns Existing columns
     * @return array Modified columns
     */
    public function add_webhook_column($columns) {
        // Insert before the date column
        $new_columns = array();
        foreach ($columns as $key => $value) {
            if ($key === 'date') {
                $new_columns['dh_notebook'] = __('Notebook', 'directory-helpers');
            }
            $new_columns[$key] = $value;
        }
        return $new_columns;
    }
    
    /**
     * Render the custom column content
     *
     * @param string $column_name Column name
     * @param int $post_id Post ID
     */
    public function render_webhook_column($column_name, $post_id) {
        if ($column_name !== 'dh_notebook') {
            return;
        }
        
        // Check if post already has a video
        $video_url = get_field('video_overview', $post_id);
        if (!empty($video_url)) {
            // Post already has a video, show checkmark and "done"
            echo '<span style="color: #46b450;">✓ Done</span>';
            return;
        }
        
        $nonce = wp_create_nonce('dh_trigger_notebook_' . $post_id);
        ?>
        <button 
            type="button" 
            class="button button-small dh-trigger-notebook-btn" 
            data-post-id="<?php echo esc_attr($post_id); ?>"
            data-nonce="<?php echo esc_attr($nonce); ?>"
            title="<?php esc_attr_e('Create Notebook for this post', 'directory-helpers'); ?>"
        >
            <?php esc_html_e('Make Video', 'directory-helpers'); ?>
        </button>
        <?php
    }
    
    /**
     * Add row action to post list
     *
     * @param array $actions Existing actions
     * @param WP_Post $post Post object
     * @return array Modified actions
     */
    public function add_webhook_row_action($actions, $post) {
        if (!in_array($post->post_type, array('city-listing', 'state-listing'), true)) {
            return $actions;
        }
        
        if (!current_user_can('edit_post', $post->ID)) {
            return $actions;
        }
        
        $nonce = wp_create_nonce('dh_trigger_notebook_' . $post->ID);
        
        $actions['dh_notebook'] = sprintf(
            '<a href="#" class="dh-trigger-notebook-link" data-post-id="%d" data-nonce="%s">%s</a>',
            $post->ID,
            esc_attr($nonce),
            __('Create Notebook', 'directory-helpers')
        );
        
        return $actions;
    }
    
    /**
     * Enqueue admin scripts
     *
     * @param string $hook Current admin page hook
     */
    public function enqueue_admin_scripts($hook) {
        // Only load on edit.php pages for our post types
        if ($hook !== 'edit.php') {
            return;
        }
        
        $screen = get_current_screen();
        if (!$screen || !in_array($screen->post_type, array('city-listing', 'state-listing'), true)) {
            return;
        }
        
        wp_enqueue_script(
            'dh-admin-webhook-trigger',
            plugin_dir_url(__FILE__) . 'assets/js/admin-webhook-trigger.js',
            array('jquery'),
            filemtime(plugin_dir_path(__FILE__) . 'assets/js/admin-webhook-trigger.js'),
            true
        );
        
        wp_localize_script('dh-admin-webhook-trigger', 'dhWebhookTrigger', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'confirmMessage' => __('Are you sure you want to create a Notebook for this post?', 'directory-helpers'),
            'successMessage' => __('✅ Notebook creation triggered!', 'directory-helpers'),
            'errorMessage' => __('Error triggering webhook. Please try again.', 'directory-helpers'),
        ));
    }
    
    /**
     * AJAX handler for triggering the Notebook webhook
     */
    public function ajax_trigger_webhook() {
        $post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;
        $nonce = isset($_POST['nonce']) ? sanitize_text_field($_POST['nonce']) : '';
        
        // Verify nonce
        if (!wp_verify_nonce($nonce, 'dh_trigger_notebook_' . $post_id)) {
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
        
        // If post is not published, publish it first
        if ($post->post_status !== 'publish') {
            $update_result = wp_update_post(array(
                'ID' => $post_id,
                'post_status' => 'publish'
            ), true);
            
            if (is_wp_error($update_result)) {
                wp_send_json_error(array('message' => __('Failed to publish post: ', 'directory-helpers') . $update_result->get_error_message()));
                return;
            }
            
            // Refresh post object after publishing
            $post = get_post($post_id);
        }
        
        // Get webhook URL from settings
        $options = get_option('directory_helpers_options');
        $webhook_url = $options['notebook_webhook_url'] ?? '';
        
        if (empty($webhook_url)) {
            wp_send_json_error(array('message' => __('Notebook webhook URL not configured.', 'directory-helpers')));
            return;
        }
        
        // Build keyword from post title (same logic as AI Content Generator)
        $raw_title = wp_strip_all_tags(get_the_title($post));
        $clean_title = trim(preg_replace('/[^\p{L}\p{N}\s]/u', '', $raw_title));
        $clean_title = preg_replace('/\s+/', ' ', $clean_title);
        $keyword = 'dog training in ' . $clean_title;
        
        // Get post URL
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
        
        // Get video title and YouTube description (reuse AI Content Generator logic if available)
        $video_title = '';
        $youtube_description = '';
        if (class_exists('DH_AI_Content_Generator')) {
            $ai_generator = new DH_AI_Content_Generator();
            $reflection = new ReflectionClass($ai_generator);
            
            // Try to access private methods via reflection
            try {
                $video_title_method = $reflection->getMethod('generate_video_title');
                $video_title_method->setAccessible(true);
                $video_title = $video_title_method->invoke($ai_generator, $post_id);
                
                $youtube_desc_method = $reflection->getMethod('generate_youtube_description');
                $youtube_desc_method->setAccessible(true);
                $youtube_description = $youtube_desc_method->invoke($ai_generator, $post_id);
            } catch (Exception $e) {
                // Fallback if reflection fails
                $video_title = $post_title;
                $youtube_description = '';
            }
        } else {
            $video_title = $post_title;
        }
        
        // Build payload (same as AI Content Generator)
        $payload = array(
            'postId' => $post_id,
            'postUrl' => $post_url,
            'postTitle' => $post_title,
            'keyword' => $keyword,
            'videoTitle' => $video_title,
            'youtubeDescription' => $youtube_description,
            'featuredImage' => $featured_image_url,
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
                'message' => __('Notebook creation triggered successfully!', 'directory-helpers'),
            ));
        } else {
            $message = wp_remote_retrieve_response_message($response);
            wp_send_json_error(array('message' => sprintf(__('Webhook returned error: %s (code: %d)', 'directory-helpers'), $message, $code)));
        }
    }
}
