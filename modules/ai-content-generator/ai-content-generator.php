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
    }

    /**
     * Add meta box to the post editor screen.
     */
    public function add_meta_box() {
        add_meta_box(
            'dh_ai_content_generator',
            __('AI Content Generator', 'directory-helpers'),
            array($this, 'render_meta_box'),
            'city-listing', // Target custom post type
            'side',
            'high'
        );
    }

    /**
     * Render the meta box content.
     */
    public function render_meta_box($post) {
        ?>
        <div class="dh-ai-content-generator-wrapper">
            <p>
                <label for="dh-ai-keyword"><?php esc_html_e('Keyword', 'directory-helpers'); ?></label>
                <input type="text" id="dh-ai-keyword" name="dh-ai-keyword" value="<?php echo esc_attr($post->post_title); ?>" style="width: 100%;" />
            </p>
            <button type="button" id="dh-generate-ai-content" class="button button-primary" style="width: 100%;">
                <?php esc_html_e('Generate AI Content', 'directory-helpers'); ?>
            </button>
            <div id="dh-ai-status" style="margin-top: 10px; font-size: 12px;"></div>
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
        if ('city-listing' !== $post->post_type) {
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
        wp_localize_script('dh-ai-content-generator-js', 'aiContentGenerator', array(
            'webhookUrl' => $options['n8n_webhook_url'] ?? '',
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

        return new WP_REST_Response(array('message' => 'Content updated successfully.'), 200);
    }
}
