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
        ), $atts, 'dh_nearest_cities');

        $limit = (int) $atts['limit'];
        if ($limit < 1) { $limit = 5; }
        if ($limit > 20) { $limit = 20; }

        $post = get_post();
        if (!$post || $post->post_type !== 'city-listing') {
            return '';
        }

        $current = $this->get_post_area_and_coords($post->ID);
        if (!$current || empty($current['lat']) || empty($current['lng'])) {
            return '';
        }

        $candidates = $this->query_nearest_city_candidates($post->ID, (float)$current['lat'], (float)$current['lng'], $limit);
        if (empty($candidates)) {
            return '';
        }

        // Determine list class (vertical default, or inline)
        $list_mode = strtolower(trim((string)$atts['class']));
        $list_mode = ($list_mode === 'inline') ? 'inline' : 'vertical';
        $show_niche_attr = strtolower(trim((string)$atts['show-niche']));
        $show_niche = !in_array($show_niche_attr, array('no','false','0','off'), true);

        $items = array();
        foreach ($candidates as $row) {
            $pid = (int) $row->post_id;
            // Ensure target has a niche term
            $niches = get_the_terms($pid, 'niche');
            if (empty($niches) || is_wp_error($niches)) { continue; }
            $niche_name = trim((string) $niches[0]->name);
            if ($niche_name === '') { continue; }
            // Simple pluralization per request: add 's'
            $niche_label = $niche_name . 's';

            // Area name for anchor text
            $area_terms = get_the_terms($pid, 'area');
            if (empty($area_terms) || is_wp_error($area_terms)) { continue; }
            $area_name = trim((string) $area_terms[0]->name);
            if ($area_name === '') { continue; }

            $url = get_permalink($pid);
            if (!$url) { continue; }

            $anchor_text = $show_niche ? ($niche_label . ' in ' . $area_name) : $area_name;
            $items[] = sprintf(
                '<li class="nc-item"><a class="nc-link" href="%s">%s</a></li>',
                esc_url($url),
                esc_html($anchor_text)
            );

            if (count($items) >= $limit) {
                break;
            }
        }

        if (empty($items)) { return ''; }

        $ul_class = 'nc-list ' . $list_mode;
        return '<ul class="' . esc_attr($ul_class) . '">' . implode('', $items) . '</ul>';
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
        $t = $terms[0];
        // Prefer raw term meta to avoid hard dependency on ACF functions
        $lat = get_term_meta($t->term_id, 'latitude', true);
        $lng = get_term_meta($t->term_id, 'longitude', true);
        if ($lat === '' || $lng === '') {
            // Fallback to ACF get_field if available
            if (function_exists('get_field')) {
                $lat = $lat !== '' ? $lat : get_field('latitude', 'term_' . $t->term_id);
                $lng = $lng !== '' ? $lng : get_field('longitude', 'term_' . $t->term_id);
            }
        }
        return array(
            'term_id' => (int) $t->term_id,
            'lat' => is_numeric($lat) ? (float) $lat : null,
            'lng' => is_numeric($lng) ? (float) $lng : null,
        );
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
    private function query_nearest_city_candidates($current_post_id, $clat, $clng, $limit) {
        global $wpdb;
        $prefix = $wpdb->prefix;

        // Haversine formula with Earth radius in miles; adjust to kilometers if needed.
        $earth_radius = 3959; // miles

        // Build SQL
        $sql = "
            SELECT
                p.ID AS post_id,
                area_t.term_id AS area_term_id,
                latmeta.meta_value AS lat,
                lngmeta.meta_value AS lng,
                (
                    %d * 2 * ASIN(
                        SQRT(
                            POWER(SIN(RADIANS((%f - CAST(latmeta.meta_value AS DECIMAL(10,6)))/2)), 2) +
                            COS(RADIANS(%f)) * COS(RADIANS(CAST(latmeta.meta_value AS DECIMAL(10,6)))) *
                            POWER(SIN(RADIANS((%f - CAST(lngmeta.meta_value AS DECIMAL(10,6)))/2)), 2)
                        )
                    )
                ) AS distance
            FROM {$prefix}posts p
            INNER JOIN {$prefix}term_relationships tr_area ON p.ID = tr_area.object_id
            INNER JOIN {$prefix}term_taxonomy tt_area ON tr_area.term_taxonomy_id = tt_area.term_taxonomy_id AND tt_area.taxonomy = 'area'
            INNER JOIN {$prefix}terms area_t ON tt_area.term_id = area_t.term_id
            INNER JOIN {$prefix}termmeta latmeta ON latmeta.term_id = area_t.term_id AND latmeta.meta_key = 'latitude'
            INNER JOIN {$prefix}termmeta lngmeta ON lngmeta.term_id = area_t.term_id AND lngmeta.meta_key = 'longitude'
            INNER JOIN {$prefix}term_relationships tr_niche ON p.ID = tr_niche.object_id
            INNER JOIN {$prefix}term_taxonomy tt_niche ON tr_niche.term_taxonomy_id = tt_niche.term_taxonomy_id AND tt_niche.taxonomy = 'niche'
            WHERE p.post_type = 'city-listing'
              AND p.post_status = 'publish'
              AND p.ID <> %d
              AND latmeta.meta_value <> ''
              AND lngmeta.meta_value <> ''
            GROUP BY p.ID, area_t.term_id, latmeta.meta_value, lngmeta.meta_value
            ORDER BY distance ASC
            LIMIT %d
        ";

        $prepared = $wpdb->prepare(
            $sql,
            $earth_radius,
            $clat,
            $clat,
            $clng,
            $current_post_id,
            $limit * 3 // fetch extra to allow for post-loop filtering (e.g., missing niche names)
        );

        $rows = $wpdb->get_results($prepared);
        if (is_wp_error($rows) || empty($rows)) { return array(); }

        // We'll slice to the first $limit valid items in the render loop; return rows as-is.
        return $rows;
    }
}
