<?php
/**
 * Profile Benefits Module
 *
 * Single source of truth for per-profile benefit flags (ad visibility, owner box).
 * A profile is ad-free when its ACF `featured` value is greater than 0 OR its
 * ACF `no_ads` toggle is on. Bricks templates consume this through the
 * {dh_show_ads} and {dh_show_owner_box} dynamic tags, so ad elements need only
 * one uniform condition instead of per-field logic.
 */

if (!defined('ABSPATH')) {
    exit;
}

class DH_Profile_Benefits {

    public function __construct() {
        add_action('init', array($this, 'init_bricks_integration'));
        add_filter('body_class', array($this, 'add_body_class'));
        // Grow / Mediavine Control Panel enqueue the ad script wrapper at priority 11.
        add_action('wp_enqueue_scripts', array($this, 'maybe_remove_ads_script'), 20);
    }

    /**
     * Whether ads may show for this profile. Non-profile contexts always show ads.
     */
    public static function show_ads($post_id = null) {
        $post_id = $post_id ? $post_id : get_the_ID();
        if (!$post_id || get_post_type($post_id) !== 'profile') {
            return true;
        }
        $featured = (float) get_post_meta($post_id, 'featured', true);
        if ($featured > 0) {
            return false;
        }
        if (get_post_meta($post_id, 'no_ads', true)) {
            return false;
        }
        return true;
    }

    /**
     * Whether the Owner's Box may show for this profile.
     */
    public static function show_owner_box($post_id = null) {
        $post_id = $post_id ? $post_id : get_the_ID();
        if (!$post_id || get_post_type($post_id) !== 'profile') {
            return true;
        }
        return !get_post_meta($post_id, 'hide_owner_box', true);
    }

    /**
     * Initialize Bricks Builder integration
     */
    public function init_bricks_integration() {
        if (class_exists('Bricks\Integrations\Dynamic_Data\Providers')) {
            add_filter('bricks/dynamic_tags_list', array($this, 'add_bricks_tags'));
            add_filter('bricks/dynamic_data/render_tag', array($this, 'render_bricks_tags'), 20, 3);
            add_filter('bricks/dynamic_data/render_content', array($this, 'render_bricks_content'), 20, 3);
            add_filter('bricks/frontend/render_data', array($this, 'render_bricks_content'), 20, 2);
        }
    }

    /**
     * Add benefit dynamic data tags to Bricks Builder
     */
    public function add_bricks_tags($tags) {
        $benefit_tags = [
            'dh_show_ads' => [
                'name'  => '{dh_show_ads}',
                'label' => 'Show Ads (1/0)',
                'group' => 'Profile Benefits',
            ],
            'dh_show_owner_box' => [
                'name'  => '{dh_show_owner_box}',
                'label' => 'Show Owner Box (1/0)',
                'group' => 'Profile Benefits',
            ],
        ];

        $existing_names = array_column($tags, 'name');

        foreach ($benefit_tags as $tag) {
            if (!in_array($tag['name'], $existing_names)) {
                $tags[] = $tag;
            }
        }

        return $tags;
    }

    /**
     * Render individual benefit dynamic data tags
     */
    public function render_bricks_tags($tag, $post, $context = 'text') {
        if (!is_string($tag)) {
            return $tag;
        }

        switch (str_replace(['{', '}'], '', $tag)) {
            case 'dh_show_ads':
                return self::show_ads() ? '1' : '0';

            case 'dh_show_owner_box':
                return self::show_owner_box() ? '1' : '0';
        }

        return $tag;
    }

    /**
     * Render benefit tags in content (also used by Bricks element conditions)
     */
    public function render_bricks_content($content, $post, $context = 'text') {
        if (!is_string($content) || strpos($content, '{dh_show_') === false) {
            return $content;
        }

        if (strpos($content, '{dh_show_ads}') !== false) {
            $content = str_replace('{dh_show_ads}', self::show_ads() ? '1' : '0', $content);
        }
        if (strpos($content, '{dh_show_owner_box}') !== false) {
            $content = str_replace('{dh_show_owner_box}', self::show_owner_box() ? '1' : '0', $content);
        }

        return $content;
    }

    /**
     * Body class hook for script-injected ads (popups, widgets) to self-suppress.
     */
    public function add_body_class($classes) {
        if (is_singular('profile') && !self::show_ads(get_queried_object_id())) {
            $classes[] = 'dh-no-ads';
        }
        return $classes;
    }

    /**
     * Remove the Mediavine script wrapper on ad-free profiles.
     * Handle 'mv-script-wrapper' is shared by Grow (Journey) and Mediavine Control Panel.
     */
    public function maybe_remove_ads_script() {
        if (is_singular('profile') && !self::show_ads(get_queried_object_id())) {
            wp_dequeue_script('mv-script-wrapper');
            wp_deregister_script('mv-script-wrapper');
        }
    }
}
