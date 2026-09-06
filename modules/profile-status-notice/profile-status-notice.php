<?php
/**
 * Profile Status Notice
 *
 * Owns the "permanently closed" state: profiles whose `gbp_status` postmeta is
 * `closed_forever` (set by the ratings-refresh flow from Google Business data).
 *
 * - Renders a closed banner at the top of the profile page. The profile stays
 *   published and indexed, and flips back on its own if a later refresh finds
 *   the business reopened.
 * - Drops closed profiles from every Bricks query loop of profiles (city and
 *   state trainer lists, Featured cards, maps) via `bricks/posts/query_vars`.
 * - Exposes closed_profile_ids() so Listing Counts can exclude them too.
 * The rank engine excludes closed profiles from numeric ranks separately.
 */

if (!defined('ABSPATH')) {
    exit;
}

class DH_Profile_Status_Notice {

    const META_KEY = 'gbp_status';
    const CLOSED   = 'closed_forever';

    public function __construct() {
        add_action('wp_body_open', array($this, 'render_notice'));
        add_filter('bricks/posts/query_vars', array($this, 'exclude_closed_from_loops'), 10, 1);
    }

    /**
     * IDs of every profile marked permanently closed. One indexed postmeta read
     * per request (a few hundred rows), memoised for the rest of the request.
     *
     * @return int[]
     */
    public static function closed_profile_ids() {
        static $ids = null;
        if ($ids !== null) {
            return $ids;
        }
        global $wpdb;
        $ids = array_map('intval', $wpdb->get_col($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s",
            self::META_KEY,
            self::CLOSED
        )));
        return $ids;
    }

    /**
     * Keep closed profiles out of any Bricks posts loop that lists profiles.
     * Runs for the proximity list (post__in from DH_Bricks_Query_Helpers), the
     * state list, Featured cards and maps alike, including AJAX pagination.
     *
     * @param array $query_vars WP_Query vars Bricks is about to run.
     * @return array
     */
    public function exclude_closed_from_loops($query_vars) {
        $post_types = isset($query_vars['post_type']) ? (array) $query_vars['post_type'] : array();
        if (!in_array('profile', $post_types, true)) {
            return $query_vars;
        }

        $closed = self::closed_profile_ids();
        if (empty($closed)) {
            return $query_vars;
        }

        if (!empty($query_vars['post__in'])) {
            // WP_Query ignores post__not_in when post__in is set, so trim the list itself.
            $keep = array_values(array_diff(array_map('intval', (array) $query_vars['post__in']), $closed));
            $query_vars['post__in'] = !empty($keep) ? $keep : array(0);
            return $query_vars;
        }

        $not_in = isset($query_vars['post__not_in']) ? array_map('intval', (array) $query_vars['post__not_in']) : array();
        $query_vars['post__not_in'] = array_values(array_unique(array_merge($not_in, $closed)));
        return $query_vars;
    }

    public function render_notice() {
        if (!is_singular('profile')) {
            return;
        }
        $post_id = get_the_ID();
        if (get_post_meta($post_id, self::META_KEY, true) !== self::CLOSED) {
            return;
        }

        $city_link = '';
        if (class_exists('DH_Taxonomy_Helpers')) {
            $area = DH_Taxonomy_Helpers::get_primary_area_term($post_id);
            if ($area) {
                $listing = get_posts(array(
                    'post_type'      => 'city-listing',
                    'post_status'    => 'publish',
                    'posts_per_page' => 1,
                    'fields'         => 'ids',
                    'tax_query'      => array(
                        array(
                            'taxonomy' => 'area',
                            'field'    => 'term_id',
                            'terms'    => $area->term_id,
                        ),
                    ),
                ));
                if (!empty($listing)) {
                    $city_name = class_exists('DH_Taxonomy_Helpers') ? DH_Taxonomy_Helpers::get_city_name($post_id) : $area->name;
                    $city_link = ' <a href="' . esc_url(get_permalink($listing[0])) . '" style="color:#7a1f1f;font-weight:600;text-decoration:underline;">'
                               . esc_html(sprintf(__('See top dog trainers in %s', 'directory-helpers'), $city_name)) . '</a>';
                }
            }
        }

        echo '<div class="dh-closed-notice" style="background:#fdecec;border-bottom:2px solid #d9534f;color:#7a1f1f;'
           . 'padding:12px 20px;text-align:center;font-size:15px;line-height:1.5;">'
           . '<strong>' . esc_html__('This business has been marked as permanently closed on Google.', 'directory-helpers') . '</strong>'
           . $city_link
           . '</div>';
    }
}
