<?php
/**
 * Profile Status Notice
 *
 * Renders a "permanently closed" banner at the top of profile pages whose
 * `gbp_status` postmeta is `closed_forever` (set by the ratings-refresh flow
 * from Google Business data). The profile stays published and indexed; the
 * rank engine excludes closed profiles from numeric ranks separately.
 */

if (!defined('ABSPATH')) {
    exit;
}

class DH_Profile_Status_Notice {

    const META_KEY = 'gbp_status';
    const CLOSED   = 'closed_forever';

    public function __construct() {
        add_action('wp_body_open', array($this, 'render_notice'));
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
