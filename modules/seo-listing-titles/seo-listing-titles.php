<?php
/**
 * SEO Listing Titles Module
 *
 * Registers Rank Math replacement variables for city-listing SEO titles and
 * meta descriptions that lead with the real trainer count (from the
 * listing-counts module's _profile_count meta):
 * - %dh_city_seo_title%: "12 Dog Trainers in Yucaipa, CA - Ranked by Real Reviews (2026)"
 * - %dh_city_seo_desc%:  matching meta description
 * Cities with fewer than 2 published profiles fall back to a no-count pattern
 * so titles never read "1 Dog Trainers".
 *
 * @package Directory_Helpers
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class DH_SEO_Listing_Titles
 */
class DH_SEO_Listing_Titles {

    /**
     * Constructor
     */
    public function __construct() {
        add_action('rank_math/vars/register_extra_replacements', array($this, 'register_vars'));
    }

    /**
     * Register the Rank Math replacement variables
     */
    public function register_vars() {
        if (!function_exists('rank_math_register_var_replacement')) {
            return;
        }
        rank_math_register_var_replacement(
            'dh_city_seo_title',
            array(
                'name'        => __('City SEO Title', 'directory-helpers'),
                'description' => __('Count-led city-listing title, e.g. "12 Dog Trainers in Yucaipa, CA - Ranked by Real Reviews (2026)".', 'directory-helpers'),
                'variable'    => 'dh_city_seo_title',
                'example'     => '12 Dog Trainers in Yucaipa, CA - Ranked by Real Reviews (' . date('Y') . ')',
            ),
            array($this, 'get_city_seo_title')
        );
        rank_math_register_var_replacement(
            'dh_city_seo_desc',
            array(
                'name'        => __('City SEO Description', 'directory-helpers'),
                'description' => __('Count-led city-listing meta description.', 'directory-helpers'),
                'variable'    => 'dh_city_seo_desc',
                'example'     => 'Compare 12 dog trainers in Yucaipa, CA, ranked by real customer reviews.',
            ),
            array($this, 'get_city_seo_desc')
        );
        rank_math_register_var_replacement(
            'dh_profile_seo_title',
            array(
                'name'        => __('Profile SEO Title', 'directory-helpers'),
                'description' => __('Review-led profile title, e.g. "Marquis Dog Training Reviews & Ratings - Dog Trainer in Kansas City, MO".', 'directory-helpers'),
                'variable'    => 'dh_profile_seo_title',
                'example'     => 'Marquis Dog Training Reviews & Ratings - Dog Trainer in Kansas City, MO',
            ),
            array($this, 'get_profile_seo_title')
        );
        rank_math_register_var_replacement(
            'dh_profile_seo_desc',
            array(
                'name'        => __('Profile SEO Description', 'directory-helpers'),
                'description' => __('Review-led profile meta description.', 'directory-helpers'),
                'variable'    => 'dh_profile_seo_desc',
                'example'     => 'Marquis Dog Training in Kansas City, MO: reviews, ratings, services offered, and contact info.',
            ),
            array($this, 'get_profile_seo_desc')
        );
    }

    /**
     * Get the current city-listing post and its published-profile count.
     *
     * @return array|null [WP_Post, int count] or null when not on a city-listing
     */
    private function get_city_context() {
        $post = get_post();
        if (!$post || $post->post_type !== 'city-listing') {
            return null;
        }
        $count = (int) get_post_meta($post->ID, '_profile_count', true);
        return array($post, $count);
    }

    /**
     * SEO title for the current city-listing
     *
     * @return string
     */
    public function get_city_seo_title() {
        $ctx = $this->get_city_context();
        if (!$ctx) {
            return '';
        }
        list($post, $count) = $ctx;
        $city = $post->post_title;
        $year = date('Y');
        if ($count >= 2) {
            return sprintf('%d Dog Trainers in %s - Ranked by Real Reviews (%s)', $count, $city, $year);
        }
        return sprintf('Dog Trainers in %s - Ratings & Reviews (%s)', $city, $year);
    }

    /**
     * Meta description for the current city-listing
     *
     * @return string
     */
    public function get_city_seo_desc() {
        $ctx = $this->get_city_context();
        if (!$ctx) {
            return '';
        }
        list($post, $count) = $ctx;
        $city = $post->post_title;
        if ($count >= 2) {
            return sprintf('Compare %d dog trainers in %s, ranked by real customer reviews. Ratings, services, and contact info for every local trainer.', $count, $city);
        }
        return sprintf('Find dog trainers in %s. Real ratings, services, and contact info, plus nearby options.', $city);
    }

    /**
     * Get the current profile post plus its niche label and "City, ST" location.
     *
     * @return array|null [WP_Post, string niche, string location] or null when not on a profile
     */
    private function get_profile_context() {
        $post = get_post();
        if (!$post || $post->post_type !== 'profile') {
            return null;
        }
        $niche = 'Dog Trainer';
        $niche_terms = get_the_terms($post->ID, 'niche');
        if (!empty($niche_terms) && !is_wp_error($niche_terms)) {
            $niche = $niche_terms[0]->name;
        }
        $location = '';
        $area = DH_Taxonomy_Helpers::get_primary_area_term($post->ID);
        if ($area) {
            $location = trim(preg_replace('/\s*\([^)]*\)/', '', $area->name));
            if (preg_match('/-([a-z]{2})$/', $area->slug, $m)) {
                $location .= ', ' . strtoupper($m[1]);
            }
        }
        return array($post, $niche, $location);
    }

    /**
     * SEO title for the current profile
     *
     * @return string
     */
    public function get_profile_seo_title() {
        $ctx = $this->get_profile_context();
        if (!$ctx) {
            return '';
        }
        list($post, $niche, $location) = $ctx;
        $title = sprintf('%s Reviews & Ratings - %s', $post->post_title, $niche);
        if ($location) {
            $title .= ' in ' . $location;
        }
        return $title;
    }

    /**
     * Meta description for the current profile
     *
     * @return string
     */
    public function get_profile_seo_desc() {
        $ctx = $this->get_profile_context();
        if (!$ctx) {
            return '';
        }
        list($post, $niche, $location) = $ctx;
        $where = $location ? ' in ' . $location : '';
        return sprintf('%s%s: reviews, ratings, services offered, and contact info. See how this %s compares to other local pros on Goody Doggy.', $post->post_title, $where, strtolower($niche));
    }
}
