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
        // Featured image URL (full)
        $featured_image_url = '';
        $thumb_id = get_post_thumbnail_id($post_id);
        if ($thumb_id) {
            $src = wp_get_attachment_image_src($thumb_id, 'full');
            if (is_array($src) && !empty($src[0])) {
                $featured_image_url = esc_url_raw($src[0]);
            } else {
                $maybe = wp_get_attachment_url($thumb_id);
                if ($maybe) { $featured_image_url = esc_url_raw($maybe); }
            }
        }
        // Derive a useful video title for downstream automations
        $video_title = $this->generate_video_title($post_id);
        // Derive a YouTube description from prompt or fallback
        $youtube_description = $this->generate_youtube_description($post_id);
        $body = wp_json_encode(array(
            'postId'     => $post_id,
            'postUrl'    => $post_url,
            'postTitle'  => $post_title,
            'keyword'    => $keyword,
            'videoTitle' => $video_title,
            'youtubeDescription' => $youtube_description,
            'featuredImage' => $featured_image_url,
            'source'     => 'post',
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

        // Store body images in ACF fields only
        if ($image_1_id && function_exists('update_field')) {
            update_field('body_image_1', $image_1_id, $post_id);
        }
        if ($image_2_id && function_exists('update_field')) {
            update_field('body_image_2', $image_2_id, $post_id);
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

        // Timestamp tracking removed - no longer needed

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

        // Retrieve image IDs from ACF fields only
        if (!function_exists('get_field')) {
            return $content; // No ACF, no injection
        }

        // Read raw ACF values to avoid formatting/return-format dependencies
        $img1_id_raw = get_field('body_image_1', $post->ID, false);
        $img2_id_raw = get_field('body_image_2', $post->ID, false);
        // If raw is empty (e.g., stale field mapping), read the stored meta directly (read-only)
        if (!$img1_id_raw) { $img1_id_raw = get_post_meta($post->ID, 'body_image_1', true); }
        if (!$img2_id_raw) { $img2_id_raw = get_post_meta($post->ID, 'body_image_2', true); }

        $img1_id = $this->normalize_acf_image_id($img1_id_raw);
        $img2_id = $this->normalize_acf_image_id($img2_id_raw);
        $img1_url = $this->extract_acf_image_url($img1_id_raw);
        $img2_url = $this->extract_acf_image_url($img2_id_raw);

        // Ensure attachments exist and are images
        if ($img1_id) {
            $att1 = get_post($img1_id);
            if (!$att1 || $att1->post_type !== 'attachment' || strpos(get_post_mime_type($img1_id), 'image/') !== 0) {
                $img1_id = 0;
            }
        }
        if ($img2_id) {
            $att2 = get_post($img2_id);
            if (!$att2 || $att2->post_type !== 'attachment' || strpos(get_post_mime_type($img2_id), 'image/') !== 0) {
                $img2_id = 0;
            }
        }
        

        if (!$img1_id && !$img2_id && empty($img1_url) && empty($img2_url)) {
            return $content;
        }

        // Avoid duplicates if image markup already present (match class attribute with wp-image-{ID})
        if ($img1_id) {
            $pattern1 = '/class\s*=\s*"[^"]*\bwp-image-' . preg_quote((string)$img1_id, '/') . '\b[^"]*"/i';
            if (preg_match($pattern1, $content)) {
                $img1_id = 0;
            }
        } elseif (!empty($img1_url)) {
            $pattern1url = '/<img\b[^>]*src\s*=\s*"' . preg_quote($img1_url, '/') . '"/i';
            if (preg_match($pattern1url, $content)) {
                $img1_url = '';
            }
        }
        if ($img2_id) {
            $pattern2 = '/class\s*=\s*"[^"]*\bwp-image-' . preg_quote((string)$img2_id, '/') . '\b[^"]*"/i';
            if (preg_match($pattern2, $content)) {
                $img2_id = 0;
            }
        } elseif (!empty($img2_url)) {
            $pattern2url = '/<img\b[^>]*src\s*=\s*"' . preg_quote($img2_url, '/') . '"/i';
            if (preg_match($pattern2url, $content)) {
                $img2_url = '';
            }
        }
        if (!$img1_id && !$img2_id && empty($img1_url) && empty($img2_url)) {
            return $content;
        }

        $html = $content;

        $ops = array();

        // Compute insertion for Image 1: after the 3rd H2–H4 (fallbacks applied)
        if ($img1_id || !empty($img1_url)) {
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
            $img1_markup = $this->build_image_markup_from_acf($img1_id, $img1_url, $img1_id_raw);
            if ($img1_markup) {
                $ops[] = array('pos' => $pos, 'snippet' => "\n\n" . $img1_markup . "\n\n");
            }
        }

        // Compute insertion for Image 2: before the last FAQ-like heading, else at end
        if ($img2_id || !empty($img2_url)) {
            $patternFaq = '/<h([2-6])\b[^>]*>(.*?)<\/h\1>/is';
            $faqPos = -1;
            if (preg_match_all($patternFaq, $html, $m2, PREG_OFFSET_CAPTURE)) {
                $total = count($m2[0]);
                for ($i = 0; $i < $total; $i++) {
                    $inner = $m2[2][$i][0];
                    $text = trim(wp_strip_all_tags($inner));
                    // Enhanced FAQ detection including "questions"
                    if (preg_match('/\bfaq\b|\bfaqs\b|frequently\s+asked|commonly\s+asked|common\s+questions|questions\s+about/i', $text)) {
                        // keep the last matching heading position
                        $faqPos = $m2[0][$i][1];
                    }
                }
            }
            $pos2 = ($faqPos >= 0) ? $faqPos : strlen($html);
            $img2_markup = $this->build_image_markup_from_acf($img2_id, $img2_url, $img2_id_raw);
            if ($img2_markup) {
                $ops[] = array('pos' => $pos2, 'snippet' => "\n\n" . $img2_markup . "\n\n");
            }
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
     * Normalize an ACF image field value into an attachment ID.
     * Supports ID, array with 'ID' or 'id', or URL string/array.
     *
     * @param mixed $value
     * @return int Attachment ID or 0 if not resolvable
     */
    private function normalize_acf_image_id($value) {
        if (empty($value)) {
            return 0;
        }
        // If already numeric ID
        if (is_numeric($value)) {
            return absint($value);
        }
        // If ACF array
        if (is_array($value)) {
            if (isset($value['ID'])) {
                return absint($value['ID']);
            }
            if (isset($value['id'])) {
                return absint($value['id']);
            }
            if (isset($value['url']) && is_string($value['url'])) {
                $maybe = attachment_url_to_postid($value['url']);
                if ($maybe) {
                    return (int) $maybe;
                }
            }
            if (isset($value['sizes']) && is_array($value['sizes'])) {
                foreach ($value['sizes'] as $size) {
                    if (is_string($size) && preg_match('#^https?://#i', $size)) {
                        $maybe = attachment_url_to_postid($size);
                        if ($maybe) {
                            return (int) $maybe;
                        }
                    } elseif (is_array($size) && isset($size['url'])) {
                        $maybe = attachment_url_to_postid($size['url']);
                        if ($maybe) {
                            return (int) $maybe;
                        }
                    }
                }
            }
        }
        // If URL string
        if (is_string($value) && preg_match('#^https?://#i', $value)) {
            $maybe = attachment_url_to_postid($value);
            if ($maybe) {
                return (int) $maybe;
            }
        }
        return 0;
    }

    /**
     * Extract a URL from an ACF image field value (array or string or ID).
     * Returns empty string if no URL resolvable.
     *
     * @param mixed $value
     * @return string
     */
    private function extract_acf_image_url($value) {
        if (empty($value)) {
            return '';
        }
        if (is_string($value) && preg_match('#^https?://#i', $value)) {
            return $value;
        }
        if (is_numeric($value)) {
            $url = wp_get_attachment_url((int) $value);
            return $url ? $url : '';
        }
        if (is_array($value)) {
            if (!empty($value['url']) && is_string($value['url'])) {
                return $value['url'];
            }
            // Try common sized entries
            if (!empty($value['sizes']) && is_array($value['sizes'])) {
                foreach (['full', 'large', 'medium_large', 'medium'] as $k) {
                    if (isset($value['sizes'][$k]) && is_string($value['sizes'][$k])) {
                        return $value['sizes'][$k];
                    }
                    if (isset($value['sizes'][$k]) && is_array($value['sizes'][$k]) && !empty($value['sizes'][$k]['url'])) {
                        return $value['sizes'][$k]['url'];
                    }
                }
            }
        }
        return '';
    }

    /**
     * Generate a video title for the given post.
     * Priority:
     * 1) Use the saved Directory Helpers prompt with key 'thumbnail-title' (with token replacement)
     * 2) Fallback to "{area} {state} Dog Training - How to Choose the Best Trainer"
     *
     * @param int $post_id
     * @return string
     */
    private function generate_video_title($post_id) {
        $post = get_post($post_id);
        if (!$post) { return ''; }

        // Try prompt-based title first
        $prompt_text = '';
        if (class_exists('Directory_Helpers')) {
            $prompts = Directory_Helpers::get_prompts();
            if (is_array($prompts)) {
                if (!empty($prompts['thumbnail-title'])) {
                    $prompt_text = (string) $prompts['thumbnail-title'];
                } elseif (!empty($prompts['thumnail-title'])) { // tolerate common misspelling
                    $prompt_text = (string) $prompts['thumnail-title'];
                }
            }
        }

        if ($prompt_text !== '') {
            $current_pt = get_post_type($post);
            $replacements = array(
                '{title}' => (string) get_the_title($post),
            );
            // Back-compat legacy token used in prompts UI
            $replacements['{city-state}'] = (string) get_the_title($post);

            // Populate taxonomy tokens {taxonomy}
            $tax_objs = get_object_taxonomies($current_pt, 'objects');
            if (is_array($tax_objs)) {
                foreach ($tax_objs as $tax_name => $tax_obj) {
                    $terms = get_the_terms($post->ID, $tax_name);
                    $val = '';
                    if (is_array($terms) && !empty($terms)) {
                        $names = wp_list_pluck($terms, 'name');
                        $val = implode(', ', array_map('wp_strip_all_tags', array_map('strval', $names)));
                    }
                    $replacements['{' . $tax_name . '}'] = $val;
                }
            }

            // Ensure {state} has a best-effort value on city pages
            if (strpos($prompt_text, '{state}') !== false) {
                $cur = isset($replacements['{state}']) ? (string) $replacements['{state}'] : '';
                if ($cur === '') {
                    $derived = $this->derive_state_name_for_city_post($post);
                    $replacements['{state}'] = $derived;
                }
            }

            // Ensure all tokens present in the text are defined in the replacement map
            if (preg_match_all('/\{[a-z0-9_-]+\}/i', (string) $prompt_text, $m)) {
                foreach (array_unique($m[0]) as $tok) {
                    if (!array_key_exists($tok, $replacements)) {
                        $replacements[$tok] = '';
                    }
                }
            }

            $title = strtr($prompt_text, $replacements);
            $title = str_replace("'", "’", $title);
            $title = trim($title);
            if ($title !== '') { return $title; }
        }

        // Fallback: build from area + state
        $city = '';
        $state_full = '';
        $area_terms = get_the_terms($post_id, 'area');
        if (is_array($area_terms) && !empty($area_terms)) {
            $area_name = (string) $area_terms[0]->name;
            // Strip trailing " - ST"
            $city = preg_replace('/\s-\s[A-Za-z]{2}$/', '', $area_name);
        }
        // Prefer state taxonomy name when present (e.g., state-listing)
        $state_terms = get_the_terms($post_id, 'state');
        if (is_array($state_terms) && !empty($state_terms)) {
            $state_full = (string) $state_terms[0]->name;
        }
        if ($state_full === '') {
            $state_full = $this->derive_state_name_for_city_post($post);
        }
        if ($city === '') {
            $city = wp_strip_all_tags(get_the_title($post));
        }
        $suffix = 'Dog Training - How to Choose the Best Trainer';
        $parts = array_filter(array($city, $state_full));
        $prefix = trim(implode(' ', $parts));
        $title = $prefix ? ($prefix . ' ' . $suffix) : $suffix;
        return $title;
    }

    /**
     * Best-effort derivation of US state full name for a city-listing post.
     * Uses slug/title to detect a 2-letter code and maps to full name.
     * Returns empty string if not determinable.
     *
     * @param WP_Post|int $post
     * @return string
     */
    private function derive_state_name_for_city_post($post) {
        $post = is_object($post) ? $post : get_post($post);
        if (!$post) { return ''; }
        // Only attempt for city-listing; state-listing uses taxonomy name elsewhere
        $pt = get_post_type($post);
        if ($pt !== 'city-listing') { return ''; }
        $code = '';
        $slug = isset($post->post_name) ? (string) $post->post_name : '';
        if ($slug && preg_match('/-([a-z]{2})(?:-|$)/i', $slug, $m)) {
            $code = strtoupper($m[1]);
        } else {
            $title = (string) $post->post_title;
            if ($title && preg_match('/,\s*([A-Za-z]{2})(\b|$)/', $title, $mm)) {
                $code = strtoupper($mm[1]);
            }
        }
        if (!$code) { return ''; }
        $us = array(
            'AL'=>'Alabama','AK'=>'Alaska','AZ'=>'Arizona','AR'=>'Arkansas','CA'=>'California','CO'=>'Colorado','CT'=>'Connecticut','DE'=>'Delaware','FL'=>'Florida','GA'=>'Georgia','HI'=>'Hawaii','ID'=>'Idaho','IL'=>'Illinois','IN'=>'Indiana','IA'=>'Iowa','KS'=>'Kansas','KY'=>'Kentucky','LA'=>'Louisiana','ME'=>'Maine','MD'=>'Maryland','MA'=>'Massachusetts','MI'=>'Michigan','MN'=>'Minnesota','MS'=>'Mississippi','MO'=>'Missouri','MT'=>'Montana','NE'=>'Nebraska','NV'=>'Nevada','NH'=>'New Hampshire','NJ'=>'New Jersey','NM'=>'New Mexico','NY'=>'New York','NC'=>'North Carolina','ND'=>'North Dakota','OH'=>'Ohio','OK'=>'Oklahoma','OR'=>'Oregon','PA'=>'Pennsylvania','RI'=>'Rhode Island','SC'=>'South Carolina','SD'=>'South Dakota','TN'=>'Tennessee','TX'=>'Texas','UT'=>'Utah','VT'=>'Vermont','VA'=>'Virginia','WA'=>'Washington','WV'=>'West Virginia','WI'=>'Wisconsin','WY'=>'Wyoming'
        );
        return isset($us[$code]) ? $us[$code] : '';
    }

    /**
     * Build a YouTube description string from a saved prompt 'youtube-description' with token replacement,
     * or fallback to a generic description using area/state and post URL.
     *
     * @param int $post_id
     * @return string
     */
    private function generate_youtube_description($post_id) {
        $post = get_post($post_id);
        if (!$post) { return ''; }

        $post_url = get_permalink($post_id);

        // Attempt prompt-based description first
        $prompt_text = '';
        if (class_exists('Directory_Helpers')) {
            $prompts = Directory_Helpers::get_prompts();
            if (is_array($prompts) && !empty($prompts['youtube-description'])) {
                $prompt_text = (string) $prompts['youtube-description'];
            }
        }

        if ($prompt_text !== '') {
            $current_pt = get_post_type($post);
            $replacements = array(
                '{title}'    => (string) get_the_title($post),
                '{postUrl}'  => (string) $post_url,
            );
            // Back-compat token
            $replacements['{city-state}'] = (string) get_the_title($post);

            // Shortlink token (if created by Shortlinks module)
            $slug_meta_key = class_exists('DH_Shortlinks') ? DH_Shortlinks::META_SLUG : '_dh_shortlink_slug';
            $short_slug = (string) get_post_meta($post->ID, $slug_meta_key, true);
            $shortlink = $short_slug ? home_url('/' . ltrim($short_slug, '/')) : '';
            $replacements['{shortlink}'] = $shortlink;

            // Taxonomy tokens
            $tax_objs = get_object_taxonomies($current_pt, 'objects');
            if (is_array($tax_objs)) {
                foreach ($tax_objs as $tax_name => $tax_obj) {
                    $terms = get_the_terms($post->ID, $tax_name);
                    $val = '';
                    if (is_array($terms) && !empty($terms)) {
                        $names = wp_list_pluck($terms, 'name');
                        $val = implode(', ', array_map('wp_strip_all_tags', array_map('strval', $names)));
                    }
                    $replacements['{' . $tax_name . '}'] = $val;
                }
            }

            // Ensure {state} has value on city pages when missing
            if (strpos($prompt_text, '{state}') !== false) {
                $cur = isset($replacements['{state}']) ? (string) $replacements['{state}'] : '';
                if ($cur === '') {
                    $derived = $this->derive_state_name_for_city_post($post);
                    $replacements['{state}'] = $derived;
                }
            }

            // Define any missing tokens to blank
            if (preg_match_all('/\{[a-z0-9_-]+\}/i', (string) $prompt_text, $m)) {
                foreach (array_unique($m[0]) as $tok) {
                    if (!array_key_exists($tok, $replacements)) {
                        $replacements[$tok] = '';
                    }
                }
            }

            $out = strtr($prompt_text, $replacements);
            return $this->normalize_multiline_text($out);
        }

        // Fallback generic description
        $city = '';
        $state_full = '';
        $area_terms = get_the_terms($post_id, 'area');
        if (is_array($area_terms) && !empty($area_terms)) {
            $area_name = (string) $area_terms[0]->name;
            $city = preg_replace('/\s-\s[A-Za-z]{2}$/', '', $area_name);
        }
        $state_terms = get_the_terms($post_id, 'state');
        if (is_array($state_terms) && !empty($state_terms)) {
            $state_full = (string) $state_terms[0]->name;
        }
        if ($state_full === '') {
            $state_full = $this->derive_state_name_for_city_post($post);
        }

        $loc = trim(implode(', ', array_filter(array($city, $state_full))));
        $intro = $loc ? ("For our ultimate guide to $loc dog trainers, costs, and local resources, visit:\n$post_url")
                      : ("For our ultimate guide to dog trainers, costs, and local resources, visit:\n$post_url");
        $body = "\n\nThis video covers training methods, typical costs, certifications, legal requirements, program formats, local resources, and must-ask questions.\n\nLearn more here: $post_url";
        return $this->normalize_multiline_text($intro . $body);
    }

    /**
     * Normalize multiline text for external systems (e.g., YouTube description):
     * - Convert CRLF/CR to LF
     * - Remove BOM and zero‑width characters
     * - Replace non‑breaking spaces with normal spaces
     * - Trim trailing spaces on each line
     * - Collapse 3+ blank lines to 1 blank line (two \n)
     *
     * @param string $text
     * @return string
     */
    private function normalize_multiline_text($text) {
        if (!is_string($text) || $text === '') { return ''; }
        // Normalize line endings
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        // Remove BOM and zero-width chars
        $text = str_replace(["\xEF\xBB\xBF", "\xE2\x80\x8B", "\xE2\x80\x8C", "\xE2\x80\x8D", "\xE2\x80\x8E", "\xE2\x80\x8F"], '', $text);
        // Replace non-breaking space with normal space
        $text = str_replace("\xC2\xA0", ' ', $text);
        // Trim trailing spaces per line
        $lines = explode("\n", $text);
        foreach ($lines as &$l) { $l = rtrim($l, " \t"); }
        unset($l);
        $text = implode("\n", $lines);
        // Collapse 3+ newlines to exactly 2 (one blank line)
        $text = preg_replace("/\n{3,}/", "\n\n", $text);
        // Final trim
        return trim($text);
    }

    /**
     * Build <img>/<figure> markup from either attachment ID or URL.
     * If $id is valid, defer to build_image_html().
     * For URLs, construct a basic <img> with alt derived from ACF array when available.
     *
     * @param int $id
     * @param string $url
     * @param mixed $raw
     * @return string
     */
    private function build_image_markup_from_acf($id, $url, $raw) {
        $id = absint($id);
        if ($id) {
            return $this->build_image_html($id);
        }
        $url = is_string($url) ? trim($url) : '';
        if (!$url) {
            return '';
        }
        $alt = '';
        if (is_array($raw)) {
            if (!empty($raw['alt']) && is_string($raw['alt'])) {
                $alt = $raw['alt'];
            } elseif (!empty($raw['title']) && is_string($raw['title'])) {
                $alt = $raw['title'];
            }
        }
        $alt_attr = $alt !== '' ? ' alt="' . esc_attr($alt) . '"' : ' alt=""';
        return '<img class="alignnone size-full" src="' . esc_url($url) . '"' . $alt_attr . ' />';
    }

}
