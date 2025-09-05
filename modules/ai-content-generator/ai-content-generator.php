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
        // Inject body images at view time so images remain editable via ACF/meta
        add_filter('the_content', array($this, 'inject_images_into_content'), 20);
        // Heartbeat server receiver for live notifications
        add_filter('heartbeat_received', array($this, 'heartbeat_received'), 10, 2);
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
        // Build default keyword from sanitized post title (remove punctuation like commas or dashes)
        $raw_title = isset($post->post_title) ? wp_strip_all_tags(get_the_title($post)) : '';
        $clean_title = trim(preg_replace('/[^\p{L}\p{N}\s]/u', '', $raw_title));
        // Collapse multiple spaces to a single space
        $clean_title = preg_replace('/\s+/', ' ', $clean_title);
        $default_keyword = 'dog training in ' . $clean_title;
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
            <?php
            $last_ai_ts = 0;
            if (!empty($post) && isset($post->ID)) {
                $last_ai_ts = (int) get_option('dh_ai_last_update_' . (int) $post->ID, 0);
            }
            $status_html = '';
            if ($last_ai_ts) {
                $disp = esc_html(wp_date('Y-m-d g:ia', $last_ai_ts));
                $status_html = '<span class="dashicons dashicons-yes-alt" style="color:#46b450;vertical-align:middle;margin-right:6px;"></span>'
                    . '<strong style="vertical-align:middle;">' . esc_html__('AI Content', 'directory-helpers') . ' - ' . $disp . '</strong>';
            }
            ?>
            <div id="dh-ai-status" style="margin-top: 10px; font-size: 12px;"><?php echo $status_html; ?></div>
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

        // Ensure Heartbeat API is available for live notifications
        wp_enqueue_script('heartbeat');
        // Ensure Dashicons are available for status icons
        wp_enqueue_style('dashicons');

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
            'postId' => isset($post->ID) ? (int) $post->ID : 0,
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
        $post_title = wp_strip_all_tags(get_the_title($post_id));
        $body = wp_json_encode(array(
            'postId'     => $post_id,
            'postUrl'    => $post_url,
            'postTitle'  => $post_title,
            'keyword'    => $keyword,
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
        // Optional: two body images to be injected at render time
        $image_1_id = isset($params['image_1_id']) ? absint($params['image_1_id']) : 0;
        $image_2_id = isset($params['image_2_id']) ? absint($params['image_2_id']) : 0;

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

        // Store body images in ACF fields if available, else in post meta
        if ($image_1_id) {
            if (function_exists('update_field')) {
                // ACF field key/name 'body_image_1'
                update_field('body_image_1', $image_1_id, $post_id);
            } else {
                update_post_meta($post_id, 'body_image_1', $image_1_id);
            }
        }
        if ($image_2_id) {
            if (function_exists('update_field')) {
                update_field('body_image_2', $image_2_id, $post_id);
            } else {
                update_post_meta($post_id, 'body_image_2', $image_2_id);
            }
        }

        $response = array('message' => 'Content updated successfully.');
        if (!is_null($featured_set)) {
            $response['featured_media_set'] = $featured_set;
            if (!$featured_set && $featured_error) {
                $response['warning'] = $featured_error;
            }
        }
        if ($image_1_id) {
            $response['image_1_saved'] = (bool) $image_1_id;
        }
        if ($image_2_id) {
            $response['image_2_saved'] = (bool) $image_2_id;
        }

        // Mark last update time for this post (used by Heartbeat to notify editors)
        if ($post_id) {
            update_option('dh_ai_last_update_' . $post_id, time());
        }

        // Fire an action so other modules (e.g., External Link Management) can react to new AI content
        do_action('directory_helpers/ai_content_updated', $post_id, $content);

        return new WP_REST_Response($response, 200);
    }

    /**
     * Inject body images into post content at render time based on heading rules.
     * - Image 1: after the 3rd <h2>-<h4> (fallback: after last h2-h4, or at start if none)
     * - Image 2: right before the last FAQ-like heading (h2-h6 containing: FAQ, FAQs, Frequently Asked, Commonly Asked, Common Questions).
     * Skips insertion if the corresponding image is already present in content.
     *
     * @param string $content
     * @return string
     */
    public function inject_images_into_content($content) {
        if (is_admin()) {
            return $content;
        }

        if (!is_singular()) {
            return $content;
        }

        $post = get_post();
        if (!$post || !in_array($post->post_type, array('city-listing', 'state-listing'), true)) {
            return $content;
        }

        // Retrieve image IDs from ACF or post meta
        $img1_id = function_exists('get_field') ? get_field('body_image_1', $post->ID) : get_post_meta($post->ID, 'body_image_1', true);
        $img2_id = function_exists('get_field') ? get_field('body_image_2', $post->ID) : get_post_meta($post->ID, 'body_image_2', true);

        // If ACF returns an array, pull the ID
        if (is_array($img1_id) && isset($img1_id['ID'])) {
            $img1_id = $img1_id['ID'];
        }
        if (is_array($img2_id) && isset($img2_id['ID'])) {
            $img2_id = $img2_id['ID'];
        }

        $img1_id = absint($img1_id);
        $img2_id = absint($img2_id);

        if (!$img1_id && !$img2_id) {
            return $content;
        }

        // Avoid duplicates if image markup already present
        if ($img1_id && strpos($content, 'wp-image-' . $img1_id) !== false) {
            $img1_id = 0;
        }
        if ($img2_id && strpos($content, 'wp-image-' . $img2_id) !== false) {
            $img2_id = 0;
        }
        if (!$img1_id && !$img2_id) {
            return $content;
        }

        $html = $content;

        $ops = array();

        // Compute insertion for Image 1: after the 3rd H2–H4 (fallbacks applied)
        if ($img1_id) {
            $pattern = '/<h([2-4])\b[^>]*>.*?<\/h\1>/is';
            if (preg_match_all($pattern, $html, $m, PREG_OFFSET_CAPTURE)) {
                if (count($m[0]) >= 3) {
                    $third = $m[0][2];
                    $pos = $third[1] + strlen($third[0]);
                } elseif (count($m[0]) >= 1) {
                    $last = $m[0][count($m[0]) - 1];
                    $pos = $last[1] + strlen($last[0]);
                } else {
                    $pos = 0; // no headings
                }
            } else {
                $pos = 0; // no headings
            }
            $ops[] = array('pos' => $pos, 'snippet' => "\n\n" . $this->build_image_html($img1_id) . "\n\n");
        }

        // Compute insertion for Image 2: before the last FAQ-like heading, else at end
        if ($img2_id) {
            $patternFaq = '/<h([2-6])\b[^>]*>(.*?)<\/h\1>/is';
            $faqPos = -1;
            if (preg_match_all($patternFaq, $html, $m2, PREG_OFFSET_CAPTURE)) {
                $total = count($m2[0]);
                for ($i = 0; $i < $total; $i++) {
                    $inner = $m2[2][$i][0];
                    $text = trim(wp_strip_all_tags($inner));
                    if (preg_match('/\bfaq\b|\bfaqs\b|frequently\s+asked|commonly\s+asked|common\s+questions/i', $text)) {
                        // keep the last matching heading position
                        $faqPos = $m2[0][$i][1];
                    }
                }
            }
            $pos2 = ($faqPos >= 0) ? $faqPos : strlen($html);
            $ops[] = array('pos' => $pos2, 'snippet' => "\n\n" . $this->build_image_html($img2_id) . "\n\n");
        }

        // Apply insertions in ascending order of position
        usort($ops, function ($a, $b) {
            if ($a['pos'] === $b['pos']) { return 0; }
            return ($a['pos'] < $b['pos']) ? 1 : -1; // insert later positions first to avoid offset adjustments
        });

        foreach ($ops as $op) {
            $html = substr($html, 0, $op['pos']) . $op['snippet'] . substr($html, $op['pos']);
        }

        return $html;
    }

    /**
     * Build HTML for an attachment image, optionally wrapped in a figure with caption.
     *
     * @param int $attachment_id
     * @return string
     */
    private function build_image_html($attachment_id) {
        $attachment_id = absint($attachment_id);
        if (!$attachment_id) {
            return '';
        }
        $img = wp_get_attachment_image($attachment_id, 'full', false, array(
            'class' => 'alignnone size-full wp-image-' . $attachment_id,
        ));
        if (!$img) {
            return '';
        }
        $caption = wp_get_attachment_caption($attachment_id);
        if ($caption) {
            return '<figure class="wp-caption alignnone">' . $img . '<figcaption class="wp-caption-text">' . esc_html($caption) . '</figcaption></figure>';
        }
        return $img;
    }

    /**
     * Heartbeat receiver to notify the post editor when AI content has landed.
     *
     * @param array $response
     * @param array $data
     * @return array
     */
    public function heartbeat_received($response, $data) {
        if (!is_array($data) || empty($data['dh_ai_check'])) {
            return $response;
        }
        $payload = $data['dh_ai_check'];
        $post_id = isset($payload['postId']) ? absint($payload['postId']) : 0;
        $last_seen = isset($payload['lastSeen']) ? (int) $payload['lastSeen'] : 0;
        if (!$post_id) {
            return $response;
        }
        $last = (int) get_option('dh_ai_last_update_' . $post_id, 0);
        if ($last > $last_seen) {
            $response['dh_ai'] = array(
                'updated' => true,
                'timestamp' => $last,
            );
        }
        return $response;
    }
}
