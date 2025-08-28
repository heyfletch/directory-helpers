<?php
/**
 * AI Content Generator Module
 *
 * @package  Directory_Helpers
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class DH_AI_Content_Generator
 */
class DH_AI_Content_Generator {
    /**
     * Constructor
     */
    public function __construct() {
        add_action('add_meta_boxes', array($this, 'add_meta_box'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('rest_api_init', array($this, 'register_rest_route'));
        add_action('rest_api_init', array($this, 'register_trigger_route'));
    }

    /**
     * Add meta box to the post editor screen.
     */
    public function add_meta_box() {
        add_meta_box(
            'dh_ai_content_generator',
            __('AI Content Generator', 'directory-helpers'),
            array($this, 'render_meta_box'),
            array('city-listing', 'state-listing'),
            'side',
            'high'
        );

        // New Process Shortcuts postbox
        add_meta_box(
            'dh_process_shortcuts',
            __('Process Shortcuts', 'directory-helpers'),
            array($this, 'render_shortcuts_meta_box'),
            array('city-listing', 'state-listing'),
            'side',
            'high'
        );
    }

    /**
     * Render the meta box content.
     */
    public function render_meta_box($post) {
        $default_keyword = '';

        if ($post->post_type === 'state-listing') {
            // For state listings, use the 'state' taxonomy; prefer description for display if available
            $terms = get_the_terms($post->ID, 'state');
            if ($terms && !is_wp_error($terms)) {
                $state_display = !empty($terms[0]->description) ? $terms[0]->description : $terms[0]->name;
                $default_keyword = 'dog training in ' . $state_display;
            } else {
                $default_keyword = $post->post_title;
            }
        } else {
            // For city listings, use the 'area' taxonomy
            $terms = get_the_terms($post->ID, 'area');
            if ($terms && !is_wp_error($terms)) {
                $area_name = $terms[0]->name;
                $default_keyword = 'dog training in ' . $area_name;
            } else {
                $default_keyword = $post->post_title;
            }
        }
        ?>
        <div class="dh-ai-content-generator-wrapper">
            <p>
                <label for="dh-ai-keyword"><?php esc_html_e('Keyword', 'directory-helpers'); ?></label>
                <input type="text" id="dh-ai-keyword" name="dh-ai-keyword" value="<?php echo esc_attr($default_keyword); ?>" style="width: 100%;" />
            </p>
            <button type="button" id="dh-generate-ai-content" class="button button-primary" style="width: 100%;">
                <?php esc_html_e('Generate AI Content', 'directory-helpers'); ?>
            </button>
            <p style="margin: 8px 0 0 0;">
                <button type="button" id="dh-create-notebook" class="button" style="width: 100%;">
                    <?php esc_html_e('Create Notebook', 'directory-helpers'); ?>
                </button>
            </p>
            <div id="dh-ai-status" style="margin-top: 10px; font-size: 12px;"></div>
        </div>
        <?php
    }

    /**
     * Render the Process Shortcuts meta box.
     */
    public function render_shortcuts_meta_box($post) {
        ?>
        <div class="dh-process-shortcuts-wrapper">
            <p style="margin: 0;">
                <a class="button" style="width: 100%; text-align:center;" target="_blank" rel="noopener" href="<?php echo esc_url( 'https://notebooklm.google.com/' ); ?>"><?php esc_html_e('Create Notebook', 'directory-helpers'); ?></a>
            </p>
            <p style="margin: 8px 0 0 0;">
                <button type="button" id="dh-unsplash-photos-btn" class="button" style="width: 100%;">
                    <?php esc_html_e( 'Unsplash Photos', 'directory-helpers' ); ?>
                </button>
            </p>
        </div>
        <?php
    }

    /**
     * Enqueue scripts and styles for the admin interface.
     */
    public function enqueue_scripts($hook) {
        global $post;
        if ('post.php' != $hook && 'post-new.php' != $hook) {
            return;
        }
        if (!in_array($post->post_type, array('city-listing', 'state-listing'), true)) {
            return;
        }

        wp_enqueue_script(
            'dh-ai-content-generator-js',
            plugin_dir_url(__FILE__) . 'assets/js/ai-content-generator.js',
            array('jquery'),
            filemtime(plugin_dir_path(__FILE__) . 'assets/js/ai-content-generator.js'),
            true
        );

        $options = get_option('directory_helpers_options');

        // Compute Unsplash slug from area term (without trailing " - ST") when on city-listing
        $unsplash_slug = '';
        if ($post && isset($post->post_type) && $post->post_type === 'city-listing') {
            $area_terms = get_the_terms($post->ID, 'area');
            if ($area_terms && !is_wp_error($area_terms)) {
                $area_name = $area_terms[0]->name;
                // Remove trailing " - ST" pattern if present
                $area_no_state = preg_replace('/\s-\s[A-Za-z]{2}$/', '', $area_name);
                $unsplash_slug = sanitize_title($area_no_state);
            }
        }

        wp_localize_script('dh-ai-content-generator-js', 'aiContentGenerator', array(
            'webhookUrl'   => $options['n8n_webhook_url'] ?? '',
            'notebookWebhookUrl' => $options['notebook_webhook_url'] ?? '',
            'postTitle'    => isset($post->post_title) ? wp_strip_all_tags(get_the_title($post)) : '',
            'unsplashSlug' => $unsplash_slug,
            'triggerEndpoint' => rest_url('directory-helpers/v1/trigger-webhook'),
            'nonce' => wp_create_nonce('wp_rest'),
        ));
    }

    /**
     * Register the custom REST API route.
     */
    public function register_rest_route() {
        register_rest_route('ai-content-plugin/v1', '/receive-content',
            array(
                'methods'  => 'POST',
                'callback' => array($this, 'handle_receive_content'),
                'permission_callback' => '__return_true' // We will handle security via shared secret
            )
        );
    }

    /**
     * Register internal trigger route to proxy external webhooks (avoids CORS from browser).
     */
    public function register_trigger_route() {
        register_rest_route('directory-helpers/v1', '/trigger-webhook', array(
            'methods'  => 'POST',
            'callback' => array($this, 'handle_trigger_webhook'),
            'permission_callback' => function () {
                return current_user_can('edit_posts');
            }
        ));
    }

    /**
     * Handle trigger webhook by server-side posting to configured URLs.
     */
    public function handle_trigger_webhook( WP_REST_Request $request ) {
        // Verify nonce
        $nonce = $request->get_header('x-wp-nonce');
        if (!wp_verify_nonce($nonce, 'wp_rest')) {
            return new WP_Error('rest_forbidden', 'Invalid nonce.', array('status' => 403));
        }

        $params = $request->get_json_params();
        $post_id = isset($params['postId']) ? absint($params['postId']) : 0;
        $keyword = isset($params['keyword']) ? sanitize_text_field($params['keyword']) : '';
        $target  = isset($params['target']) ? sanitize_key($params['target']) : '';

        if (!$post_id || !$target) {
            return new WP_Error('missing_parameters', 'Missing postId or target.', array('status' => 400));
        }

        // Check capability for specific post
        if (!current_user_can('edit_post', $post_id)) {
            return new WP_Error('rest_forbidden', 'Insufficient permissions.', array('status' => 403));
        }

        $options = get_option('directory_helpers_options');
        $url = '';
        if ($target === 'notebook') {
            $url = $options['notebook_webhook_url'] ?? '';
        } elseif ($target === 'ai') {
            $url = $options['n8n_webhook_url'] ?? '';
        }
        if (empty($url)) {
            return new WP_Error('missing_configuration', 'Webhook URL not configured.', array('status' => 400));
        }

        // Unified payload for simplicity across services
        $post_url = get_permalink($post_id);
        $body = wp_json_encode(array(
            'postId'  => $post_id,
            'postUrl' => $post_url,
            'keyword' => $keyword,
        ));

        $response = wp_remote_post($url, array(
            'headers' => array('Content-Type' => 'application/json'),
            'body'    => $body,
            'timeout' => 20,
        ));

        if (is_wp_error($response)) {
            return new WP_Error('request_failed', $response->get_error_message(), array('status' => 500));
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $message = wp_remote_retrieve_response_message($response);
        $resp_body = wp_remote_retrieve_body($response);

        return new WP_REST_Response(array(
            'code'    => $code,
            'message' => $message,
            'body'    => $resp_body,
        ), ($code >= 200 && $code < 300) ? 200 : $code);
    }

    /**
     * Handle the incoming content from the n8n workflow.
     */
    public function handle_receive_content($request) {
        $options = get_option('directory_helpers_options');
        $secret_key = $options['shared_secret_key'] ?? '';

        $params = $request->get_json_params();
        $received_secret = isset($params['secretKey']) ? sanitize_text_field($params['secretKey']) : '';

        if (empty($secret_key) || $received_secret !== $secret_key) {
            return new WP_Error('rest_forbidden', 'Invalid secret key.', array('status' => 403));
        }

        $post_id = isset($params['postId']) ? absint($params['postId']) : 0;
        $content = isset($params['content']) ? wp_kses_post($params['content']) : '';
        // Optional: featured image attachment ID from media library
        $featured_media_id = isset($params['featured_media']) ? absint($params['featured_media']) : 0;

        if (empty($post_id) || empty($content)) {
            return new WP_Error('missing_parameters', 'Missing postId or content.', array('status' => 400));
        }

        $post_data = array(
            'ID'           => $post_id,
            'post_content' => $content,
        );

        $result = wp_update_post($post_data, true);

        if (is_wp_error($result)) {
            return new WP_Error('update_failed', 'Failed to update the post.', array('status' => 500));
        }

        // If a featured media ID was provided, attempt to set it as the featured image
        $featured_set = null;
        $featured_error = '';
        if ($featured_media_id) {
            $attachment = get_post($featured_media_id);
            if ($attachment && $attachment->post_type === 'attachment') {
                $set = set_post_thumbnail($post_id, $featured_media_id);
                if ($set) {
                    $featured_set = true;
                } else {
                    $featured_set = false;
                    $featured_error = 'Failed to set featured image (post type may not support thumbnails).';
                }
            } else {
                $featured_set = false;
                $featured_error = 'Invalid featured_media ID.';
            }
        }

        $response = array('message' => 'Content updated successfully.');
        if (!is_null($featured_set)) {
            $response['featured_media_set'] = $featured_set;
            if (!$featured_set && $featured_error) {
                $response['warning'] = $featured_error;
            }
        }

        return new WP_REST_Response($response, 200);
    }
}
