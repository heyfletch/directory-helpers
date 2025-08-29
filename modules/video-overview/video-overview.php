<?php
/**
 * Video Overview Module
 *
 * Shortcode: [dh_video_overview]
 * Renders a YouTube video embed based on the ACF field 'video_overview' on the current post.
 * - Uses WordPress oEmbed when possible.
 * - Falls back to a sanitized YouTube iframe when oEmbed fails but URL is valid.
 * - Outputs only an HTML comment when the field is empty or not a valid YouTube URL.
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('DH_Video_Overview')) {
    class DH_Video_Overview {
        /** @var bool ensure we only output schema once per request */
        private $did_output_schema = false;
        public function __construct() {
            add_shortcode('dh_video_overview', array($this, 'render_shortcode'));

            // Admin UI and refresh handling.
            add_action('add_meta_boxes', array($this, 'register_metabox'));
            add_action('admin_post_dh_refresh_video_overview', array($this, 'handle_refresh_request'));
            add_action('admin_notices', array($this, 'maybe_admin_notice'));
            add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
            add_action('wp_ajax_dh_refresh_video_overview', array($this, 'ajax_refresh_request'));

            // Frontend: inject schema in head when a valid ACF URL exists.
            add_action('wp_head', array($this, 'maybe_output_schema_in_head'));
        }

        /**
         * Render the [dh_video_overview] shortcode.
         *
         * @return string
         */
        public function render_shortcode() {
            global $post;
            if (!$post || !isset($post->ID)) {
                return '<!-- no YouTube video available here -->';
            }

            $url = $this->get_video_url($post->ID);
            if (empty($url)) {
                return '<!-- no YouTube video available here -->';
            }

            if (!$this->is_youtube_url($url)) {
                return '<!-- no YouTube video available here -->';
            }

            // Try WordPress oEmbed first for maximum compatibility with themes and providers.
            $embed = wp_oembed_get($url);
            if ($embed) {
                return "<!-- Video Overview Added by Shortcode -->\n" . $embed;
            }

            // Fallback: build a sanitized iframe embed if we can extract a video ID.
            $video_id = $this->extract_youtube_id($url);
            if (!$video_id) {
                return '<!-- no YouTube video available here -->';
            }

            $src = esc_url('https://www.youtube.com/embed/' . $video_id);
            $iframe = sprintf(
                '<iframe width="560" height="315" src="%s" title="YouTube video" frameborder="0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" allowfullscreen></iframe>',
                $src
            );

            return "<!-- Video Overview Added by Shortcode -->\n" . $iframe;
        }

        /**
         * If on a singular post with a valid YouTube URL in ACF, ensure cached schema
         * and print it into the document head.
         */
        public function maybe_output_schema_in_head() {
            static $printed_once = false; // extra safety across multiple instances
            if (!is_singular()) { return; }
            global $post;
            if (!$post || empty($post->ID)) { return; }
            $pt = get_post_type($post);
            if ($pt !== 'city-listing' && $pt !== 'state-listing') { return; }
            if ($this->did_output_schema || $printed_once) { return; }

            $url = $this->get_video_url($post->ID);
            if (empty($url) || !$this->is_youtube_url($url)) { return; }

            // Ensure schema cache exists or is refreshed if URL changed.
            $schema_json = $this->maybe_ensure_cached_schema($post->ID, $url);
            if (empty($schema_json)) { return; }

            // Allow other plugins/themes to opt-out to avoid duplicates.
            $should_output = apply_filters('dh_video_overview_output_schema', true, $post->ID, $url, $schema_json);
            if (!$should_output) { return; }

            echo '<script type="application/ld+json">' . $schema_json . '</script>' . "\n";
            $this->did_output_schema = true;
            $printed_once = true;
        }

        /**
         * Ensure cached schema exists and is up-to-date for the given URL.
         * Rebuild cache if URL hash changed or schema is missing.
         *
         * @param int $post_id
         * @param string $url
         * @return string JSON string or empty string
         */
        private function maybe_ensure_cached_schema($post_id, $url) {
            $new_hash = md5($url);
            $old_hash = (string) get_post_meta($post_id, '_video_overview_url_hash', true);
            $schema_json = (string) get_post_meta($post_id, '_video_overview_schema_json', true);

            if ($new_hash !== $old_hash || empty($schema_json)) {
                $result = $this->rebuild_cache($post_id, $url);
                if (!$result['ok']) {
                    return '';
                }
                $schema_json = (string) get_post_meta($post_id, '_video_overview_schema_json', true);
            }

            return $schema_json;
        }

        /**
         * Rebuild and store cached video metadata and schema.
         *
         * @param int $post_id
         * @param string $url
         * @return array { ok: bool, msg?: string }
         */
        private function rebuild_cache($post_id, $url) {
            $video_id = $this->extract_youtube_id($url);
            if (!$video_id) {
                return array('ok' => false, 'msg' => 'Invalid YouTube URL or unable to extract video ID.');
            }

            $oembed = $this->fetch_youtube_oembed($url);
            $title = '';
            $thumb_from_oembed = '';
            if ($oembed && is_array($oembed)) {
                if (!empty($oembed['title'])) {
                    $title = sanitize_text_field($oembed['title']);
                }
                if (!empty($oembed['thumbnail_url'])) {
                    $thumb_from_oembed = esc_url_raw($oembed['thumbnail_url']);
                }
            }
            if ($title === '') {
                $title = get_the_title($post_id);
            }

            $embed_url = 'https://www.youtube.com/embed/' . $video_id;
            $thumbnail = 'https://i.ytimg.com/vi/' . $video_id . '/maxresdefault.jpg';

            // Build thumbnail candidates: prefer self-hosted featured image, then YouTube maxres, then oEmbed thumbnail.
            $thumbnails = array();
            $feat_id = get_post_thumbnail_id($post_id);
            if ($feat_id) {
                $feat_src = wp_get_attachment_image_src($feat_id, 'full');
                if (is_array($feat_src) && !empty($feat_src[0])) {
                    $thumbnails[] = esc_url_raw($feat_src[0]);
                }
            }
            if (!empty($thumbnail)) { $thumbnails[] = esc_url_raw($thumbnail); }
            if (!empty($thumb_from_oembed)) { $thumbnails[] = esc_url_raw($thumb_from_oembed); }
            // De-duplicate and allow customization.
            $thumbnails = array_values(array_unique(array_filter($thumbnails)));
            $thumbnails = apply_filters('dh_video_overview_thumbnails', $thumbnails, $post_id, $video_id);

            // Preserve the original uploadDate unless the video URL changes.
            $new_hash = md5($url);
            $old_hash = (string) get_post_meta($post_id, '_video_overview_url_hash', true);
            $existing_upload_date = (string) get_post_meta($post_id, '_video_overview_upload_date', true);
            if ($new_hash === $old_hash && !empty($existing_upload_date)) {
                $upload_date = $existing_upload_date;
            } else {
                $upload_date = current_time('c');
            }
            $desc_default = sprintf(
                'Find the perfect dog trainer in %s. This short guide covers how to evaluate professionals on key factors like their training methods, certifications, and costs.',
                get_the_title($post_id)
            );
            $description = apply_filters('dh_video_overview_description', $desc_default, $post_id, $oembed);

            // Canonical page URL and publisher for disambiguation/branding.
            $page_url = get_permalink($post_id);
            $logo = $this->get_publisher_logo();
            $publisher = array(
                '@type' => 'Organization',
                '@id'   => home_url('#organization'),
                'name'  => get_bloginfo('name'),
                'logo'  => $logo,
            );

            $schema = array(
                '@context' => 'https://schema.org',
                '@type' => 'VideoObject',
                'name' => $title,
                'description' => $description,
                'thumbnailUrl' => !empty($thumbnails) ? $thumbnails : $thumbnail,
                'uploadDate' => $upload_date,
                'embedUrl' => $embed_url,
                'url' => $page_url,
                'publisher' => $publisher,
                'potentialAction' => array(
                    '@type' => 'WatchAction',
                    'target' => $embed_url,
                ),
            );

            $schema_json = wp_json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (!$schema_json) {
                return array('ok' => false, 'msg' => 'Failed to encode schema JSON.');
            }

            // Persist minimal cache.
            update_post_meta($post_id, '_video_overview_url_hash', md5($url));
            update_post_meta($post_id, '_video_overview_id', $video_id);
            update_post_meta($post_id, '_video_overview_title', $title);
            if ($thumb_from_oembed) {
                update_post_meta($post_id, '_video_overview_thumbnail_url', $thumb_from_oembed);
            }
            update_post_meta($post_id, '_video_overview_upload_date', $upload_date);
            update_post_meta($post_id, '_video_overview_schema_json', $schema_json);
            update_post_meta($post_id, '_video_overview_last_refreshed_gmt', gmdate('Y-m-d H:i:s'));

            return array('ok' => true);
        }

        /**
         * Fetch YouTube oEmbed for a given URL using wp_remote_get.
         *
         * @param string $url
         * @return array|null
         */
        private function fetch_youtube_oembed($url) {
            $endpoint = 'https://www.youtube.com/oembed?format=json&url=' . rawurlencode($url);
            $response = wp_remote_get($endpoint, array('timeout' => 5));
            if (is_wp_error($response)) {
                return null;
            }
            $code = wp_remote_retrieve_response_code($response);
            if ($code !== 200) {
                return null;
            }
            $body = wp_remote_retrieve_body($response);
            if (!$body) {
                return null;
            }
            $data = json_decode($body, true);
            if (!is_array($data)) {
                return null;
            }
            return $data;
        }

        /**
         * Register metabox to refresh video metadata on edit screens.
         */
        public function register_metabox() {
            // City Listing
            if (post_type_exists('city-listing')) {
                add_meta_box(
                    'dh_video_overview_meta',
                    __('Video Overview', 'directory-helpers'),
                    array($this, 'render_metabox'),
                    'city-listing',
                    'side',
                    'default'
                );
            }
            // State Listing
            if (post_type_exists('state-listing')) {
                add_meta_box(
                    'dh_video_overview_meta',
                    __('Video Overview', 'directory-helpers'),
                    array($this, 'render_metabox'),
                    'state-listing',
                    'side',
                    'default'
                );
            }
        }

        /**
         * Render the metabox.
         */
        public function render_metabox($post) {
            $url = $this->get_video_url($post->ID);
            $hash = (string) get_post_meta($post->ID, '_video_overview_url_hash', true);
            $cached = !empty(get_post_meta($post->ID, '_video_overview_schema_json', true));
            $url_hash = $url ? md5($url) : '';
            $needs_refresh = $url && ($hash !== $url_hash || !$cached);

            echo '<p><strong>' . esc_html__('YouTube URL (ACF video_overview):', 'directory-helpers') . '</strong><br>';
            if ($url) {
                echo '<code>' . esc_html($url) . '</code>';
            } else {
                echo '<em>' . esc_html__('None set', 'directory-helpers') . '</em>';
            }
            echo '</p>';

            if ($url) {
                $btn_label = $needs_refresh ? __('Refresh Video Metadata (URL changed)', 'directory-helpers') : __('Refresh Video Metadata', 'directory-helpers');
                $nonce = wp_create_nonce('dh_refresh_video_overview_' . $post->ID);
                echo '<button type="button" class="button dh-refresh-video-overview" data-post-id="' . esc_attr($post->ID) . '" data-nonce="' . esc_attr($nonce) . '">' . esc_html($btn_label) . '</button>';
                echo ' <span class="dh-vr-status" style="display:none; margin-left:6px;"></span>';
            } else {
                echo '<p>' . esc_html__('Set the ACF field "video_overview" to enable refresh.', 'directory-helpers') . '</p>';
            }
        }

        /**
         * Handle the admin-post refresh request.
         */
        public function handle_refresh_request() {
            $post_id = isset($_POST['post_id']) ? (int) $_POST['post_id'] : 0;
            if (!$post_id || !current_user_can('edit_post', $post_id)) {
                wp_die(__('Unauthorized request', 'directory-helpers'));
            }
            if (!isset($_POST['_dh_vr_nonce']) || !wp_verify_nonce($_POST['_dh_vr_nonce'], 'dh_refresh_video_overview_' . $post_id)) {
                wp_die(__('Security check failed', 'directory-helpers'));
            }

            $url = $this->get_video_url($post_id);
            if (empty($url) || !$this->is_youtube_url($url)) {
                $redirect = add_query_arg(array('post' => $post_id, 'action' => 'edit', 'dh_vr' => 'err', 'dh_msg' => rawurlencode('Missing or invalid YouTube URL.')), admin_url('post.php'));
                wp_safe_redirect($redirect);
                exit;
            }

            $result = $this->rebuild_cache($post_id, $url);
            if ($result['ok']) {
                $redirect = add_query_arg(array('post' => $post_id, 'action' => 'edit', 'dh_vr' => 'ok'), admin_url('post.php'));
            } else {
                $msg = isset($result['msg']) ? $result['msg'] : 'Unknown error';
                $redirect = add_query_arg(array('post' => $post_id, 'action' => 'edit', 'dh_vr' => 'err', 'dh_msg' => rawurlencode($msg)), admin_url('post.php'));
            }
            wp_safe_redirect($redirect);
            exit;
        }

        /**
         * AJAX handler: refresh without leaving the edit screen.
         */
        public function ajax_refresh_request() {
            if (!isset($_POST['post_id'])) {
                wp_send_json_error(array('message' => __('Missing post_id', 'directory-helpers')));
            }
            $post_id = (int) $_POST['post_id'];
            if (!$post_id || !current_user_can('edit_post', $post_id)) {
                wp_send_json_error(array('message' => __('Unauthorized', 'directory-helpers')));
            }
            $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
            if (!$nonce || !wp_verify_nonce($nonce, 'dh_refresh_video_overview_' . $post_id)) {
                wp_send_json_error(array('message' => __('Security check failed', 'directory-helpers')));
            }

            $url = $this->get_video_url($post_id);
            if (empty($url) || !$this->is_youtube_url($url)) {
                wp_send_json_error(array('message' => __('Missing or invalid YouTube URL.', 'directory-helpers')));
            }
            $result = $this->rebuild_cache($post_id, $url);
            if (!$result['ok']) {
                $msg = isset($result['msg']) ? $result['msg'] : __('Unknown error', 'directory-helpers');
                wp_send_json_error(array('message' => $msg));
            }
            wp_send_json_success(array('message' => __('Video metadata refreshed.', 'directory-helpers')));
        }

        /**
         * Enqueue small admin JS to handle AJAX refresh button.
         */
        public function enqueue_admin_assets($hook) {
            // Only enqueue on post edit screens.
            if ($hook !== 'post.php' && $hook !== 'post-new.php') {
                return;
            }
            $handle = 'dh-video-overview-admin';
            wp_register_script($handle, false, array('jquery'), DIRECTORY_HELPERS_VERSION, true);
            $script = <<<'JS'
jQuery(document).on('click', '.dh-refresh-video-overview', function(e){
  e.preventDefault();
  var $btn = jQuery(this);
  var postId = $btn.data('post-id');
  var nonce = $btn.data('nonce');
  var $status = $btn.siblings('.dh-vr-status');
  $status.text('Refreshing...').css('color','#555').show();
  jQuery.post(ajaxurl, { action: 'dh_refresh_video_overview', post_id: postId, nonce: nonce }, function(resp){
    if(resp && resp.success){
      $status.text(resp.data && resp.data.message ? resp.data.message : 'Done').css('color','#2271b1');
    } else {
      var msg = resp && resp.data && resp.data.message ? resp.data.message : 'Error';
      $status.text(msg).css('color','#d63638');
    }
  }).fail(function(){
    $status.text('Request failed').css('color','#d63638');
  });
});
JS;
            wp_add_inline_script($handle, $script);
            wp_enqueue_script($handle);
        }

        /**
         * Show admin notice after refresh.
         */
        public function maybe_admin_notice() {
            if (!is_admin()) { return; }
            if (!isset($_GET['dh_vr'])) { return; }
            $screen = function_exists('get_current_screen') ? get_current_screen() : null;
            if (!$screen || $screen->base !== 'post') { return; }

            $status = sanitize_text_field(wp_unslash($_GET['dh_vr']));
            if ($status === 'ok') {
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Video metadata refreshed.', 'directory-helpers') . '</p></div>';
            } elseif ($status === 'err') {
                $msg = isset($_GET['dh_msg']) ? sanitize_text_field(wp_unslash($_GET['dh_msg'])) : __('Error refreshing video metadata.', 'directory-helpers');
                echo '<div class="notice notice-error is-dismissible"><p>' . esc_html($msg) . '</p></div>';
            }
        }

        /**
         * Get the video URL from ACF field 'video_overview' or post meta fallback.
         *
         * @param int $post_id
         * @return string
         */
        private function get_video_url($post_id) {
            // Prefer ACF get_field if ACF is active.
            if (function_exists('get_field')) {
                $val = get_field('video_overview', $post_id);
                if (!empty($val) && is_string($val)) {
                    return trim($val);
                }
            }
            // Fallback to regular post meta if ACF isn't available.
            $val = get_post_meta($post_id, 'video_overview', true);
            if (!empty($val) && is_string($val)) {
                return trim($val);
            }
            return '';
        }

        /**
         * Basic validation for YouTube URLs (youtube.com or youtu.be).
         *
         * @param string $url
         * @return bool
         */
        private function is_youtube_url($url) {
            $parts = wp_parse_url($url);
            if (!$parts || empty($parts['host'])) {
                return false;
            }
            $host = strtolower($parts['host']);
            // Accept common YouTube hosts
            $allowed_hosts = array(
                'youtube.com',
                'www.youtube.com',
                'm.youtube.com',
                'youtu.be',
                'www.youtu.be'
            );
            if (!in_array($host, $allowed_hosts, true)) {
                return false;
            }
            // Must have a path component that isn't just '/'
            return !empty($parts['path']) && $parts['path'] !== '/';
        }

        /**
         * Extract a YouTube video ID from various URL formats.
         *
         * @param string $url
         * @return string|false
         */
        private function extract_youtube_id($url) {
            // youtu.be/<id>
            if (preg_match('~youtu\.be/([A-Za-z0-9_-]{6,})~', $url, $m)) {
                return $m[1];
            }
            // youtube.com/watch?v=<id>
            if (preg_match('~v=([A-Za-z0-9_-]{6,})~', $url, $m)) {
                return $m[1];
            }
            // youtube.com/embed/<id>
            if (preg_match('~/embed/([A-Za-z0-9_-]{6,})~', $url, $m)) {
                return $m[1];
            }
            // youtube.com/shorts/<id>
            if (preg_match('~/shorts/([A-Za-z0-9_-]{6,})~', $url, $m)) {
                return $m[1];
            }
            return false;
        }

        /**
         * Attempt to retrieve the Knowledge Graph logo configured in Rank Math.
         * Falls back to the site favicon provided by the user.
         *
         * @return array ImageObject with url, width, height, @type, @id
         */
        private function get_publisher_logo() {
            $default = array(
                '@type'  => 'ImageObject',
                '@id'    => home_url('#logo'),
                'url'    => 'https://goodydoggy.com/wp-content/uploads/cropped-goody-doggy-favicon.png',
                'width'  => 512,
                'height' => 512,
            );

            // Try to read Rank Math general options for knowledge graph logo.
            $candidates = array();
            $opts = get_option('rank-math-options-general');
            if (is_array($opts)) {
                // Common keys observed in Rank Math options structures.
                if (isset($opts['knowledgegraph_logo'])) {
                    $candidates[] = $opts['knowledgegraph_logo'];
                }
                if (isset($opts['logo'])) {
                    $candidates[] = $opts['logo'];
                }
                if (isset($opts['knowledgegraph']) && is_array($opts['knowledgegraph'])) {
                    if (isset($opts['knowledgegraph']['logo'])) {
                        $candidates[] = $opts['knowledgegraph']['logo'];
                    }
                }
            }

            foreach ($candidates as $val) {
                $img = $this->normalize_image_value($val);
                if ($img) {
                    // Ensure ImageObject typing and ID.
                    $img['@type'] = 'ImageObject';
                    if (!isset($img['@id'])) {
                        $img['@id'] = home_url('#logo');
                    }
                    return $img;
                }
            }

            // Allow external override (e.g., if Rank Math changes option schema).
            $filtered = apply_filters('dh_video_overview_publisher_logo', $default);
            if (is_array($filtered) && !empty($filtered['url'])) {
                // Basic sanitation of the filtered result.
                $filtered['@type'] = 'ImageObject';
                if (!isset($filtered['@id'])) {
                    $filtered['@id'] = home_url('#logo');
                }
                return $filtered;
            }

            return $default;
        }

        /**
         * Normalize a variety of WP/RM image option formats into an array with url/width/height.
         * Accepts: attachment ID (int), array with ['id'] or ['url'], or direct URL (string).
         *
         * @param mixed $val
         * @return array|null { url, width?, height? }
         */
        private function normalize_image_value($val) {
            // Attachment ID.
            if (is_numeric($val)) {
                $id = (int) $val;
                if ($id > 0) {
                    $src = wp_get_attachment_image_src($id, 'full');
                    if (is_array($src)) {
                        return array('url' => esc_url_raw($src[0]), 'width' => (int) $src[1], 'height' => (int) $src[2]);
                    }
                    $url = wp_get_attachment_url($id);
                    if ($url) {
                        return array('url' => esc_url_raw($url));
                    }
                }
                return null;
            }

            // Array shape.
            if (is_array($val)) {
                // Prefer nested attachment ID when present.
                if (isset($val['id']) && is_numeric($val['id'])) {
                    return $this->normalize_image_value($val['id']);
                }
                if (isset($val['url']) && is_string($val['url'])) {
                    $url = esc_url_raw($val['url']);
                    $w = isset($val['width']) ? (int) $val['width'] : 0;
                    $h = isset($val['height']) ? (int) $val['height'] : 0;
                    if ($w === 0 || $h === 0) {
                        // Try to resolve from attachment if possible.
                        $att_id = attachment_url_to_postid($url);
                        if ($att_id) {
                            $src = wp_get_attachment_image_src($att_id, 'full');
                            if (is_array($src)) {
                                $w = (int) $src[1];
                                $h = (int) $src[2];
                            }
                        }
                    }
                    $out = array('url' => $url);
                    if ($w > 0 && $h > 0) {
                        $out['width'] = $w;
                        $out['height'] = $h;
                    }
                    return $out;
                }
                return null;
            }

            // Direct URL string.
            if (is_string($val) && $val !== '') {
                $url = esc_url_raw($val);
                $att_id = attachment_url_to_postid($url);
                if ($att_id) {
                    $src = wp_get_attachment_image_src($att_id, 'full');
                    if (is_array($src)) {
                        return array('url' => esc_url_raw($src[0]), 'width' => (int) $src[1], 'height' => (int) $src[2]);
                    }
                }
                return array('url' => $url);
            }

            return null;
        }
    }

    // Initialize the module
    new DH_Video_Overview();
}
