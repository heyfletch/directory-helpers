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
 * Search Test cities (modules/search-test) get "Dog Training in ..." city titles and
 * rating-led profile titles with trainer-only descriptions; Featured Placement profiles never do.
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
        if (class_exists('DH_Search_Test') && DH_Search_Test::is_test_page($post)) {
            if ($count >= 2) {
                return sprintf('Dog Training in %s: Compare %d Trainers by Real Reviews (%s)', $city, $count, $year);
            }
            return sprintf('Dog Training in %s: Local Trainers and Real Reviews (%s)', $city, $year);
        }
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
            $location = trim(preg_replace('/\s*[---,]+\s*[A-Z]{2}$/', '', $location));
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
        if (class_exists('DH_Search_Test') && DH_Search_Test::is_test_page($post)) {
            $rating = $this->get_rating($post->ID);
            if (!$rating) {
                return $post->post_title . ' - Services and Contact Info';
            }
            return sprintf('%s - Rated %s by %s %s', $post->post_title, $rating[0], number_format($rating[1]), $rating[1] === 1 ? 'Review' : 'Reviews');
        }
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
        if (class_exists('DH_Search_Test') && DH_Search_Test::is_test_page($post)) {
            return $this->get_test_profile_desc($post, $niche, $where);
        }
        return sprintf('%s%s: reviews, ratings, services offered, and contact info. See how this %s compares to other local pros on Goody Doggy.', $post->post_title, $where, strtolower($niche));
    }

    /**
     * Search Test profile description, about the trainer only, e.g. "Happy Dog Sports in Leander, TX
     * is rated 5.0 by 18 reviews, with 21+ years of experience and CPDT-KA and CTC credentials.
     * See services and contact info."
     *
     * @param WP_Post $post
     * @param string  $niche
     * @param string  $where " in City, ST" or ''
     * @return string
     */
    private function get_test_profile_desc($post, $niche, $where) {
        $facts = array();
        $years = (int) get_post_meta($post->ID, 'years_experience', true);
        if ($years > 0) {
            $facts[] = sprintf('%d+ %s of experience', $years, $years === 1 ? 'year' : 'years');
        }
        // "Other" is the generic "Credentialed" choice, not a named credential.
        $certs = array_slice(array_values(array_diff((array) get_post_meta($post->ID, 'certifications', true), array('Other', ''))), 0, 3);
        if ($certs) {
            $last    = array_pop($certs);
            $facts[] = ($certs ? implode(', ', $certs) . ' and ' : '') . $last . ' ' . ($certs ? 'credentials' : 'credential');
        }
        // Imported records store "true"/"false" strings, so only these two mean yes.
        if (in_array(get_post_meta($post->ID, 'free_consult', true), array('true', '1'), true)) {
            $facts[] = 'a free consultation';
        }
        $with = '';
        if ($facts) {
            $last = array_pop($facts);
            $with = ($facts ? implode(', ', $facts) . (count($facts) > 1 ? ',' : '') . ' and ' : '') . $last;
        }
        $rating = $this->get_rating($post->ID);
        if ($rating) {
            $lead = sprintf('%s%s is rated %s by %s %s', $post->post_title, $where, $rating[0], number_format($rating[1]), $rating[1] === 1 ? 'review' : 'reviews');
            $lead .= $with ? ', with ' . $with : '';
        } else {
            $lead = sprintf('%s is a %s%s', $post->post_title, strtolower($niche), $where);
            $lead .= $with ? ' with ' . $with : '';
        }
        return $lead . '. See services and contact info.';
    }

    /**
     * The profile's rating as [one-decimal value, review count] when it has reviews.
     *
     * @param int $post_id
     * @return array|null
     */
    private function get_rating($post_id) {
        $count = (int) get_post_meta($post_id, 'rating_votes_count', true);
        $value = (float) get_post_meta($post_id, 'rating_value', true);
        if ($count < 1 || $value <= 0) {
            return null;
        }
        return array(number_format($value, 1), $count);
    }
}
