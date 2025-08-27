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
        public function __construct() {
            add_shortcode('dh_video_overview', array($this, 'render_shortcode'));
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
                return $embed;
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

            return $iframe;
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
    }

    // Initialize the module
    new DH_Video_Overview();
}
