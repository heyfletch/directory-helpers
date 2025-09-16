<?php
/**
 * Nearest Cities Shortcode Module
 *
 * Shortcode: [dh_nearest_cities limit="5"]
 *
 * Renders a list of the N closest published city-listing posts relative to the
 * current city-listing post, based on the latitude/longitude ACF fields on the
 * assigned 'area' taxonomy term. Each link text: "{niche} in {area}".
 * City listings without a niche term are excluded.
 *
 * @package Directory_Helpers
 */

if (!defined('ABSPATH')) {
    exit;
}

class DH_Nearest_Cities {
    public function __construct() {
        add_shortcode('dh_nearest_cities', array($this, 'render_shortcode'));
    }

    /**
     * Render the shortcode output
     *
     * @param array $atts
     * @return string
     */
    public function render_shortcode($atts = array()) {
        $atts = shortcode_atts(array(
            'limit' => 5,
            'class' => 'vertical',
            'show-niche' => 'yes',
            'lat' => '',
            'lng' => '',
            'max_miles' => 100,
            'debug' => '0',
        ), $atts, 'dh_nearest_cities');

        $limit = (int) $atts['limit'];
        if ($limit < 1) { $limit = 5; }
        if ($limit > 20) { $limit = 20; }

        $post = get_post();
        if (!$post || $post->post_type !== 'city-listing') {
            return ''; // Not a city-listing page.
        }

        $current = $this->get_post_area_and_coords($post->ID);
        if ($atts['lat'] !== '' && is_numeric($atts['lat'])) { $current['lat'] = (float) $atts['lat']; }
        if ($atts['lng'] !== '' && is_numeric($atts['lng'])) { $current['lng'] = (float) $atts['lng']; }

        if (!$current || !isset($current['lat']) || !isset($current['lng'])) {
            return ''; // No valid origin coordinates.
        }

        $list_mode = ($atts['class'] === 'inline') ? 'inline' : 'vertical';
        $show_niche = !in_array(strtolower((string)$atts['show-niche']), array('no','false','0','off'), true);
        $max_miles = is_numeric($atts['max_miles']) ? (float) $atts['max_miles'] : 100.0;
        if ($max_miles <= 0) { $max_miles = 100.0; }

        $do_debug = in_array(strtolower((string)$atts['debug']), array('1','true','yes','on'), true);
        $debug_lines = array();

        if ($do_debug) {
            $origin_term_info = 'N/A';
            if (!empty($current['term_id'])) {
                $origin_term = get_term($current['term_id']);
                $origin_slug = is_wp_error($origin_term) ? 'ERR' : $origin_term->slug;
                $origin_term_info = sprintf('term_id=%d slug=%s', $current['term_id'], $origin_slug);
            }
            $debug_lines[] = sprintf('Origin: %s lat=%s, lng=%s', $origin_term_info, $current['lat'], $current['lng']);
        }

        if ($do_debug) {
            $candidates = $this->query_nearest_city_candidates($post->ID, (float)$current['lat'], (float)$current['lng'], $limit, $show_niche, $max_miles, $debug_lines);
        } else {
            $null_ref = null;
            $candidates = $this->query_nearest_city_candidates($post->ID, (float)$current['lat'], (float)$current['lng'], $limit, $show_niche, $max_miles, $null_ref);
        }

        if ($do_debug) {
            $debug_lines[] = '--- Candidates (pre-render) ---';
        }

        if (empty($candidates)) {
            if ($do_debug) { return "\n<!--\n" . implode("\n", $debug_lines) . "\n-->"; }
            return '';
        }

        $items = array();
        $seen_pids = array();
        foreach ($candidates as $row) {
            if (isset($seen_pids[$row->post_id])) { continue; }
            $seen_pids[$row->post_id] = true;

            $niche_label = '';
            if ($show_niche) {
                $niches = get_the_terms($row->post_id, 'niche');
                if (empty($niches) || is_wp_error($niches)) { continue; }
                $niche_name = trim($niches[0]->name);
                if ($niche_name === '') { continue; }
                $niche_label = $niche_name . 's';
            }

            $area_terms = get_the_terms($row->post_id, 'area');
            if (empty($area_terms) || is_wp_error($area_terms)) { continue; }
            $area_name = trim($area_terms[0]->name);
            if ($area_name === '') { continue; }

            $url = get_permalink($row->post_id);
            if (!$url) { continue; }

            if ($do_debug) {
                $debug_lines[] = sprintf(' - %-30s | dist=%7.2f mi | lat=%s, lng=%s', $area_name, $row->distance, $row->lat, $row->lng);
            }

            $anchor_text = $show_niche ? ($niche_label . ' in ' . $area_name) : $area_name;
            $items[] = sprintf('<li class="nc-item"><a class="nc-link" href="%s">%s</a></li>', esc_url($url), esc_html($anchor_text));

            if (count($items) >= $limit) { break; }
        }

        if (empty($items)) {
            if ($do_debug) { return "\n<!--\n" . implode("\n", $debug_lines) . "\n-->"; }
            return '';
        }

        $ul_class = 'nc-list ' . $list_mode;
        $out = '<ul class="' . esc_attr($ul_class) . '">' . implode('', $items) . '</ul>';
        if ($do_debug) {
            $out .= "\n<!--\n" . implode("\n", array_slice($debug_lines, 0, 40)) . "\n-->";
        }
        return $out;
    }

    /**
     * Get the first assigned area term and its coordinates for a city-listing post.
     *
     * @param int $post_id
     * @return array{term_id:int,lat:float|string,lng:float|string}|null
     */
    private function get_post_area_and_coords($post_id) {
        $terms = get_the_terms($post_id, 'area');
        if (empty($terms) || is_wp_error($terms)) { return null; }

        $candidates = array();
        foreach ($terms as $term) {
            $lat = get_term_meta($term->term_id, 'latitude', true);
            $lng = get_term_meta($term->term_id, 'longitude', true);
            if (is_numeric($lat) && is_numeric($lng)) {
                $candidates[] = array('term_id' => $term->term_id, 'lat' => (float) $lat, 'lng' => (float) $lng);
            }
        }

        if (empty($candidates)) { return null; }
        if (count($candidates) === 1) { return $candidates[0]; }

        // If multiple terms have coords, find the one closest to the geographic center of all terms
        $avg_lat = array_sum(array_column($candidates, 'lat')) / count($candidates);
        $avg_lng = array_sum(array_column($candidates, 'lng')) / count($candidates);

        $closest_cand = null;
        $min_dist_sq = -1;

        foreach ($candidates as $cand) {
            $dist_sq = pow($cand['lat'] - $avg_lat, 2) + pow($cand['lng'] - $avg_lng, 2);
            if ($min_dist_sq < 0 || $dist_sq < $min_dist_sq) {
                $min_dist_sq = $dist_sq;
                $closest_cand = $cand;
            }
        }
        return $closest_cand;
    }

    /**
     * Query candidate nearest city-listing posts using SQL (Haversine in MySQL) and return up to $limit results.
     * Ensures: published, has at least one 'niche' term, area term has lat/lng, excludes current post.
     *
     * @param int $current_post_id
     * @param float $clat
     * @param float $clng
     * @param int $limit
     * @return array<object>
     */
    private function query_nearest_city_candidates($current_post_id, $clat, $clng, $limit, $require_niche, $max_miles, &$debug_lines) {
        global $wpdb;

        $earth_radius = 3959; // miles
        $miles_per_deg_lat = 69.0;
        $lat_rad = deg2rad($clat);
        $miles_per_deg_lng = ($lat_rad > 1.5 || $lat_rad < -1.5) ? 0 : (69.172 * cos($lat_rad));
        if ($miles_per_deg_lng < 1) { $miles_per_deg_lng = 1; } // Avoid division by zero near poles

        $lat_delta = $max_miles / $miles_per_deg_lat;
        $lng_delta = $max_miles / $miles_per_deg_lng;

        $lat_min = $clat - $lat_delta;
        $lat_max = $clat + $lat_delta;
        $lng_min = $clng - $lng_delta;
        $lng_max = $clng + $lng_delta;

        $niche_join = $require_niche ? " INNER JOIN {$wpdb->prefix}term_relationships tr_niche ON p.ID = tr_niche.object_id INNER JOIN {$wpdb->prefix}term_taxonomy tt_niche ON tr_niche.term_taxonomy_id = tt_niche.term_taxonomy_id AND tt_niche.taxonomy = 'niche'" : '';

        $sql = "
            SELECT
                p.ID AS post_id,
                latmeta.meta_value AS lat,
                lngmeta.meta_value AS lng,
                ( %d * acos( cos( radians(%f) ) * cos( radians( latmeta.meta_value ) ) * cos( radians( lngmeta.meta_value ) - radians(%f) ) + sin( radians(%f) ) * sin( radians( latmeta.meta_value ) ) ) ) AS distance
            FROM {$wpdb->prefix}posts p
            INNER JOIN {$wpdb->prefix}term_relationships tr_area ON p.ID = tr_area.object_id
            INNER JOIN {$wpdb->prefix}term_taxonomy tt_area ON tr_area.term_taxonomy_id = tt_area.term_taxonomy_id AND tt_area.taxonomy = 'area'
            INNER JOIN {$wpdb->prefix}termmeta latmeta ON tt_area.term_id = latmeta.term_id AND latmeta.meta_key = 'latitude'
            INNER JOIN {$wpdb->prefix}termmeta lngmeta ON tt_area.term_id = lngmeta.term_id AND lngmeta.meta_key = 'longitude'
            {$niche_join}
            WHERE
                p.post_type = 'city-listing'
                AND p.post_status = 'publish'
                AND p.ID <> %d
                AND latmeta.meta_value IS NOT NULL AND latmeta.meta_value <> ''
                AND lngmeta.meta_value IS NOT NULL AND lngmeta.meta_value <> ''
                AND CAST(latmeta.meta_value AS DECIMAL(10,6)) BETWEEN %f AND %f
                AND CAST(lngmeta.meta_value AS DECIMAL(10,6)) BETWEEN %f AND %f
            GROUP BY p.ID
            HAVING distance IS NOT NULL AND distance <= %f
            ORDER BY distance ASC
            LIMIT %d
        ";

        $params = array(
            $earth_radius, $clat, $clng, $clat, // for acos formula
            $current_post_id,
            $lat_min, $lat_max,
            $lng_min, $lng_max,
            $max_miles,
            max($limit * 5, 20) // fetch extra for filtering
        );

        $prepared_sql = $wpdb->prepare($sql, $params);

        if ($debug_lines !== null) {
            $debug_lines[] = '--- SQL Query ---';
            $debug_lines[] = $prepared_sql;
        }

        return $wpdb->get_results($prepared_sql);
    }

    // Removed state derivation; proximity is determined purely by area term geocoordinates
}
