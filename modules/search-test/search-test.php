<?php
/**
 * Search Test Module
 *
 * The Goody Doggy Search Plan test (https://claude.ai/artifact/9SqcmACY6uQobSqq3oX6ZX).
 * Half the cities, picked by crc32(salt|area slug), are test cities. While the test runs:
 * - test city pages get the heading "Dog Training in [City], [ST]" (titles live in DH_SEO_Listing_Titles)
 * - test city profiles get rating-led titles and trainer-only descriptions (DH_SEO_Listing_Titles)
 *   and a "Comparing trainers?" line after the profile's own sections, linking to the city page
 * - Featured Placement profiles keep today's version
 * Every city page drops "(Updated for YYYY)" from its cost heading at display time (dh_cost_year_cleanup).
 *
 * Nothing changes until `wp directory-helpers search-test start`. Once a salt is set, adding
 * ?dh_st_preview=<key> to any page shows it as it will look while the test runs, never cached.
 * Cached pages change as the nightly job purges about 1,000 changed pages a night.
 *
 * @package Directory_Helpers
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class DH_Search_Test
 */
class DH_Search_Test {

    const OPTION             = 'dh_search_test';       // array: status (on|off), salt, started (Y-m-d)
    const CLEANUP_OPTION     = 'dh_cost_year_cleanup'; // '1' strips "(Updated for YYYY)" from city pages
    const QUEUE_OPTION       = 'dh_search_test_queue'; // array: mode, ids, pos, built
    const PREVIEW_PARAM      = 'dh_st_preview';
    const CRON_HOOK          = 'dh_search_test_nightly';
    const CITY_H1_ID         = 'kgojdx'; // H1 on the Cities - Field Kit template (143327)
    const PROFILE_SECTION_ID = 'kyyxrz'; // Profile Section on the Profiles - Field Kit template (143319)

    /**
     * @var bool|null
     */
    private static $preview = null;

    /**
     * Constructor
     */
    public function __construct() {
        add_filter('bricks/element/settings', array($this, 'filter_city_heading'), 10, 2);
        add_filter('bricks/frontend/render_element', array($this, 'append_city_link'), 10, 2);
        add_action('wp_head', array($this, 'print_city_link_style'));
        add_filter('the_content', array($this, 'strip_cost_year'), 20);
        add_action('template_redirect', array($this, 'no_cache_preview'), 0);
        add_action(self::CRON_HOOK, array($this, 'run_nightly'));
    }

    /**
     * True while the test runs, or on a valid preview request.
     *
     * @return bool
     */
    public static function is_running() {
        $opt = get_option(self::OPTION, array());
        if (!empty($opt['salt']) && isset($opt['status']) && $opt['status'] === 'on') {
            return true;
        }
        return self::is_preview();
    }

    /**
     * True when the request carries the preview key.
     *
     * @return bool
     */
    public static function is_preview() {
        if (self::$preview === null) {
            $given = isset($_GET[self::PREVIEW_PARAM]) ? sanitize_text_field(wp_unslash($_GET[self::PREVIEW_PARAM])) : '';
            $opt   = get_option(self::OPTION, array());
            self::$preview = $given !== '' && !empty($opt['salt']) && hash_equals(self::preview_key(), $given);
        }
        return self::$preview;
    }

    /**
     * The preview query value.
     *
     * @return string
     */
    public static function preview_key() {
        return substr(wp_hash('dh_search_test_preview'), 0, 16);
    }

    /**
     * 'test' or 'control' for an area slug, '' before a salt is set.
     *
     * @param string $slug Area term slug, e.g. leander-tx
     * @return string
     */
    public static function group_for_area($slug) {
        $opt = get_option(self::OPTION, array());
        if (empty($opt['salt']) || $slug === '') {
            return '';
        }
        return (crc32($opt['salt'] . '|' . $slug) % 2) ? 'test' : 'control';
    }

    /**
     * Area slug of a city-listing, or of a profile's primary area.
     *
     * @param WP_Post $post
     * @return string
     */
    public static function area_slug($post) {
        if ($post->post_type === 'city-listing') {
            $terms = get_the_terms($post->ID, 'area');
            return (!empty($terms) && !is_wp_error($terms)) ? $terms[0]->slug : '';
        }
        if ($post->post_type === 'profile') {
            $area = DH_Taxonomy_Helpers::get_primary_area_term($post->ID);
            return $area ? $area->slug : '';
        }
        return '';
    }

    /**
     * Featured Placement profiles set the ACF featured field above 0.
     *
     * @param int $post_id
     * @return bool
     */
    public static function is_featured($post_id) {
        return (int) get_post_meta($post_id, 'featured', true) > 0;
    }

    /**
     * Whether a city-listing or profile shows the test version.
     *
     * @param int|WP_Post $post
     * @return bool
     */
    public static function is_test_page($post) {
        $post = get_post($post);
        if (!$post || !in_array($post->post_type, array('city-listing', 'profile'), true) || !self::is_running()) {
            return false;
        }
        if ($post->post_type === 'profile' && self::is_featured($post->ID)) {
            return false;
        }
        return self::group_for_area(self::area_slug($post)) === 'test';
    }

    /**
     * Published city-listing for an area term.
     *
     * @param int $term_id
     * @return int Post ID or 0
     */
    public static function city_for_area($term_id) {
        $ids = get_posts(array(
            'post_type'      => 'city-listing',
            'post_status'    => 'publish',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'tax_query'      => array(array('taxonomy' => 'area', 'field' => 'term_id', 'terms' => (int) $term_id)),
        ));
        return $ids ? (int) $ids[0] : 0;
    }

    /**
     * Test city pages: heading "Dog Training in [City], [ST]".
     *
     * @param array  $settings
     * @param object $element Bricks element instance
     * @return array
     */
    public function filter_city_heading($settings, $element) {
        if (!isset($element->id) || $element->id !== self::CITY_H1_ID || !is_singular('city-listing')) {
            return $settings;
        }
        $post = get_queried_object();
        if (self::is_test_page($post)) {
            $settings['text'] = 'Dog Training in ' . $post->post_title;
        }
        return $settings;
    }

    /**
     * Test city profiles: the "Comparing trainers?" line after the Profile Section.
     *
     * @param string $html
     * @param object $element Bricks element instance
     * @return string
     */
    public function append_city_link($html, $element) {
        if (!isset($element->id) || $element->id !== self::PROFILE_SECTION_ID || !is_singular('profile')) {
            return $html;
        }
        if ((function_exists('bricks_is_builder') && bricks_is_builder()) || (function_exists('bricks_is_builder_call') && bricks_is_builder_call())) {
            return $html;
        }
        $post = get_queried_object();
        if (!self::is_test_page($post)) {
            return $html;
        }
        $area    = DH_Taxonomy_Helpers::get_primary_area_term($post->ID);
        $city_id = $area ? self::city_for_area($area->term_id) : 0;
        if (!$city_id) {
            return $html;
        }
        $city  = get_post_field('post_title', $city_id);
        $count = (int) get_post_meta($city_id, '_profile_count', true);
        $label = $count >= 2
            ? sprintf('See all %d dog trainers in %s', $count, $city)
            : sprintf('See dog trainers in and near %s', $city);
        // brxe-text gives the link the site's in-text link style.
        return $html . sprintf(
            '<div class="gd2-root gd2-city-link"><div class="gd2-container brxe-text"><p>Comparing trainers? <a href="%s" data-gd-track="profile_to_city">%s</a></p></div></div>',
            esc_url(get_permalink($city_id)),
            esc_html($label)
        );
    }

    /**
     * A sidebar ad that ends the Profile Section keeps its own bottom margin, which would
     * leave more space above the line than below it; drop that margin when the line follows.
     */
    public function print_city_link_style() {
        if (!is_singular('profile') || !self::is_test_page(get_queried_object())) {
            return;
        }
        echo '<style id="dh-search-test">.brx-body .gd2-profile-section:has(+ .gd2-city-link) .gd2-profile-side > .gd2-sponsor:last-child{margin-bottom:0}</style>' . "\n";
    }

    /**
     * Every city page: drop "(Updated for YYYY)", since the cost figures were never re-checked.
     *
     * @param string $content
     * @return string
     */
    public function strip_cost_year($content) {
        if (get_post_type() !== 'city-listing' || (!get_option(self::CLEANUP_OPTION) && !self::is_preview())) {
            return $content;
        }
        return preg_replace('/\s*\(Updated for \d{4}\)/', '', $content);
    }

    /**
     * Preview requests are never cached.
     */
    public function no_cache_preview() {
        if (!self::is_preview()) {
            return;
        }
        do_action('litespeed_control_set_nocache', 'search test preview');
        if (!defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }
    }

    /**
     * WP-Cron, 2:00 AM Eastern: start tonight's purge as its own WP-CLI process, so the
     * night's batch never holds the WP-Cron lock. WP-Cron here runs through `wp cron event run`;
     * a web-triggered run does nothing, because web requests warm pages without waiting.
     */
    public function run_nightly() {
        $queue = get_option(self::QUEUE_OPTION, array());
        if (empty($queue['ids']) || (int) $queue['pos'] >= count($queue['ids'])) {
            wp_clear_scheduled_hook(self::CRON_HOOK);
            return;
        }
        if (!defined('WP_CLI') || !WP_CLI || !function_exists('exec')) {
            return;
        }
        $wp = file_exists('/usr/local/bin/wp') ? '/usr/local/bin/wp' : trim((string) shell_exec('which wp 2>/dev/null'));
        if (!$wp) {
            return;
        }
        $log = (getenv('HOME') ? getenv('HOME') : sys_get_temp_dir()) . '/dh-search-test-purge.log';
        exec(sprintf(
            'cd %s && nohup %s %s directory-helpers search-test purge-night --path=%s >> %s 2>&1 &',
            escapeshellarg(ABSPATH),
            escapeshellarg(PHP_BINARY),
            escapeshellarg($wp),
            escapeshellarg(ABSPATH),
            escapeshellarg($log)
        ));
    }

    /**
     * Schedule the nightly purge for the next 2:00 AM Eastern.
     */
    public static function schedule_nightly() {
        wp_clear_scheduled_hook(self::CRON_HOOK);
        $next = new DateTime('today 02:00', new DateTimeZone('America/New_York'));
        if ($next->getTimestamp() <= time()) {
            $next->modify('+1 day');
        }
        wp_schedule_event($next->getTimestamp(), 'daily', self::CRON_HOOK);
    }
}
