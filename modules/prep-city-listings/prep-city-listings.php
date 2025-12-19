<?php
/**
 * Prep City Listings Module - Create city-listing pages for cities with published profiles
 *
 * Finds cities (area terms) that have published profiles but no city-listing page,
 * and creates them in batches.
 *
 * @package Directory_Helpers
 */

if (!defined('ABSPATH')) {
    exit;
}

class DH_Prep_City_Listings {
    
    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'), 21);
        add_action('admin_post_dh_prep_city_listings_create', array($this, 'handle_create_cities'));
    }
    
    public function add_admin_menu() {
        add_submenu_page(
            'directory-helpers',
            __('Prep City Listings', 'directory-helpers'),
            __('Prep City Listings', 'directory-helpers'),
            'manage_options',
            'dh-prep-city-listings',
            array($this, 'render_page')
        );
    }
    
    private function get_niche_terms() {
        $terms = get_terms(array('taxonomy' => 'niche', 'hide_empty' => false));
        return is_wp_error($terms) ? array() : $terms;
    }
    
    /**
     * Find cities (area terms) that have published profiles but no city-listing page.
     *
     * @param string $niche_slug Niche slug to filter by.
     * @return array Array of area terms needing city-listings.
     */
    private function get_cities_needing_listings($niche_slug = 'dog-trainer') {
        global $wpdb;
        $prefix = $wpdb->prefix;
        
        $niche_term = get_term_by('slug', $niche_slug, 'niche');
        if (!$niche_term || is_wp_error($niche_term)) {
            return array();
        }
        $niche_term_id = (int) $niche_term->term_id;
        
        // Find all area terms that have at least one published profile with the niche
        $sql = "
            SELECT DISTINCT t.term_id, t.name, t.slug,
                   ts.name AS state_name, ts.slug AS state_slug
            FROM {$prefix}posts p
            JOIN {$prefix}term_relationships tr_area ON p.ID = tr_area.object_id
            JOIN {$prefix}term_taxonomy tt_area ON tr_area.term_taxonomy_id = tt_area.term_taxonomy_id
            JOIN {$prefix}terms t ON tt_area.term_id = t.term_id
            JOIN {$prefix}term_relationships tr_niche ON p.ID = tr_niche.object_id
            JOIN {$prefix}term_taxonomy tt_niche ON tr_niche.term_taxonomy_id = tt_niche.term_taxonomy_id
            JOIN {$prefix}term_relationships tr_state ON p.ID = tr_state.object_id
            JOIN {$prefix}term_taxonomy tt_state ON tr_state.term_taxonomy_id = tt_state.term_taxonomy_id
            JOIN {$prefix}terms ts ON tt_state.term_id = ts.term_id
            WHERE p.post_type = 'profile'
              AND p.post_status = 'publish'
              AND tt_area.taxonomy = 'area'
              AND tt_niche.taxonomy = 'niche'
              AND tt_niche.term_id = %d
              AND tt_state.taxonomy = 'state'
            ORDER BY ts.name ASC, t.name ASC
        ";
        
        $areas = $wpdb->get_results($wpdb->prepare($sql, $niche_term_id));
        if (empty($areas)) {
            return array();
        }
        
        // Filter out areas that already have a city-listing
        $cities_needing = array();
        foreach ($areas as $area) {
            if (!$this->city_listing_exists((int) $area->term_id, $niche_term_id)) {
                $cities_needing[] = $area;
            }
        }
        
        return $cities_needing;
    }
    
    private function city_listing_exists($area_term_id, $niche_term_id = 0) {
        $tax_query = array(
            'relation' => 'AND',
            array('taxonomy' => 'area', 'field' => 'term_id', 'terms' => array((int)$area_term_id)),
        );
        if ($niche_term_id) {
            $tax_query[] = array('taxonomy' => 'niche', 'field' => 'term_id', 'terms' => array((int)$niche_term_id));
        }
        
        $q = new WP_Query(array(
            'post_type' => 'city-listing',
            'post_status' => array('draft', 'publish'),
            'posts_per_page' => 1,
            'tax_query' => $tax_query,
            'fields' => 'ids',
            'no_found_rows' => true,
        ));
        
        return (!is_wp_error($q) && !empty($q->posts)) ? (int)$q->posts[0] : 0;
    }
    
    private function create_city_listing($area_term, $state_slug, $niche_term) {
        if (!$area_term || is_wp_error($area_term)) return 0;
        
        $city_name = preg_replace('/\s+-\s+[A-Za-z]{2}$/', '', $area_term->name);
        $city_name = trim($city_name);
        
        $state_term = get_term_by('slug', $state_slug, 'state');
        $state_code = '';
        if ($state_term && !is_wp_error($state_term)) {
            if (strlen($state_term->slug) === 2) {
                $state_code = strtoupper($state_term->slug);
            } elseif (!empty($state_term->description) && preg_match('/^[A-Za-z]{2}$/', $state_term->description)) {
                $state_code = strtoupper($state_term->description);
            }
        }
        if (!$state_code && preg_match('/-([A-Za-z]{2})$/', $area_term->slug, $m)) {
            $state_code = strtoupper($m[1]);
        }
        if (!$state_code && strlen($state_slug) >= 2) {
            $state_code = strtoupper(substr($state_slug, 0, 2));
        }
        
        $title = $city_name . ($state_code ? ', ' . $state_code : '');
        
        $niche_name = ($niche_term && !is_wp_error($niche_term)) ? $niche_term->name : '';
        $plural_niche = $niche_name;
        if ($plural_niche) {
            if (preg_match('/[^aeiou]y$/i', $plural_niche)) {
                $plural_niche = preg_replace('/y$/i', 'ies', $plural_niche);
            } elseif (!preg_match('/s$/i', $plural_niche)) {
                $plural_niche .= 's';
            }
        }
        
        $slug_base = $title . ($plural_niche ? ' ' . $plural_niche : '');
        $desired_slug = sanitize_title($slug_base);
        
        $post_id = wp_insert_post(array(
            'post_type' => 'city-listing',
            'post_status' => 'draft',
            'post_title' => $title,
            'post_name' => $desired_slug,
        ), true);
        
        if (is_wp_error($post_id) || !$post_id) return 0;
        
        wp_set_object_terms($post_id, (int)$area_term->term_id, 'area', false);
        if ($niche_term && !is_wp_error($niche_term)) {
            wp_set_object_terms($post_id, (int)$niche_term->term_id, 'niche', false);
        }
        
        return (int)$post_id;
    }
    
    private function trigger_ai_for_cities($city_ids) {
        $options = get_option('directory_helpers_options');
        $url = isset($options['n8n_webhook_url']) ? $options['n8n_webhook_url'] : '';
        if (empty($url)) return 0;
        
        $count = 0;
        foreach ($city_ids as $cid) {
            $content = get_post_field('post_content', $cid);
            if (empty($content) || trim(wp_strip_all_tags($content)) === '') {
                $raw_title = wp_strip_all_tags(get_the_title($cid));
                $clean_title = trim(preg_replace('/[^\p{L}\p{N}\s,]/u', '', $raw_title));
                $clean_title = preg_replace('/\s+/', ' ', $clean_title);
                
                $niche_text = 'dog training';
                $niche_terms = get_the_terms($cid, 'niche');
                if ($niche_terms && !is_wp_error($niche_terms) && !empty($niche_terms)) {
                    $term_description = trim($niche_terms[0]->description);
                    if (!empty($term_description)) {
                        $niche_text = $term_description;
                    } else {
                        $niche_text = ucwords(strtolower($niche_terms[0]->name));
                    }
                }
                
                $keyword = $niche_text . ' in ' . $clean_title;
                
                $body = wp_json_encode(array(
                    'postId' => $cid,
                    'postUrl' => get_permalink($cid),
                    'postTitle' => wp_strip_all_tags(get_the_title($cid)),
                    'keyword' => $keyword,
                ));
                
                $resp = wp_remote_post($url, array(
                    'headers' => array('Content-Type' => 'application/json'),
                    'body' => $body,
                    'timeout' => 20,
                ));
                
                if (!is_wp_error($resp)) {
                    $count++;
                }
                usleep(250000);
            }
        }
        return $count;
    }
    
    private function get_state_for_area($area_term_id, $niche_slug) {
        global $wpdb;
        $prefix = $wpdb->prefix;
        
        $niche_term = get_term_by('slug', $niche_slug, 'niche');
        if (!$niche_term || is_wp_error($niche_term)) {
            return '';
        }
        
        $sql = "
            SELECT ts.slug
            FROM {$prefix}posts p
            JOIN {$prefix}term_relationships tr_area ON p.ID = tr_area.object_id
            JOIN {$prefix}term_taxonomy tt_area ON tr_area.term_taxonomy_id = tt_area.term_taxonomy_id
            JOIN {$prefix}term_relationships tr_state ON p.ID = tr_state.object_id
            JOIN {$prefix}term_taxonomy tt_state ON tr_state.term_taxonomy_id = tt_state.term_taxonomy_id
            JOIN {$prefix}terms ts ON tt_state.term_id = ts.term_id
            JOIN {$prefix}term_relationships tr_niche ON p.ID = tr_niche.object_id
            JOIN {$prefix}term_taxonomy tt_niche ON tr_niche.term_taxonomy_id = tt_niche.term_taxonomy_id
            WHERE p.post_type = 'profile'
              AND p.post_status = 'publish'
              AND tt_area.taxonomy = 'area'
              AND tt_area.term_id = %d
              AND tt_state.taxonomy = 'state'
              AND tt_niche.taxonomy = 'niche'
              AND tt_niche.term_id = %d
            LIMIT 1
        ";
        
        return $wpdb->get_var($wpdb->prepare($sql, $area_term_id, $niche_term->term_id));
    }
    
    public function handle_create_cities() {
        if (!current_user_can('manage_options')) wp_die('Unauthorized');
        check_admin_referer('dh_prep_city_listings_create');
        
        $niche_slug = isset($_POST['niche']) ? sanitize_title(wp_unslash($_POST['niche'])) : 'dog-trainer';
        $batch_size = isset($_POST['batch_size']) ? max(1, min(10, (int) $_POST['batch_size'])) : 5;
        $area_ids = isset($_POST['area_ids']) ? array_map('intval', (array) $_POST['area_ids']) : array();
        
        if (empty($area_ids)) {
            wp_safe_redirect(add_query_arg(array('page' => 'dh-prep-city-listings', 'error' => 'no_cities'), admin_url('admin.php')));
            exit;
        }
        
        $area_ids = array_slice($area_ids, 0, $batch_size);
        
        $niche_term = get_term_by('slug', $niche_slug, 'niche');
        $created_city_ids = array();
        
        foreach ($area_ids as $area_term_id) {
            $area_term = get_term_by('term_id', $area_term_id, 'area');
            if (!$area_term || is_wp_error($area_term)) {
                continue;
            }
            
            $state_slug = $this->get_state_for_area($area_term_id, $niche_slug);
            if (!$state_slug) {
                continue;
            }
            
            $new_id = $this->create_city_listing($area_term, $state_slug, $niche_term);
            if ($new_id) {
                $created_city_ids[] = $new_id;
            }
        }
        
        if (!empty($created_city_ids)) {
            $this->trigger_ai_for_cities($created_city_ids);
            set_transient('dh_prep_city_listings_created_' . get_current_user_id(), $created_city_ids, 60);
        }
        
        wp_safe_redirect(add_query_arg(array(
            'page' => 'dh-prep-city-listings',
            'created' => count($created_city_ids),
        ), admin_url('admin.php')));
        exit;
    }
    
    public function render_page() {
        if (!current_user_can('manage_options')) return;
        
        $niche_slug = isset($_REQUEST['niche']) ? sanitize_title(wp_unslash($_REQUEST['niche'])) : 'dog-trainer';
        
        $niches = $this->get_niche_terms();
        $cities_needing_listings = $this->get_cities_needing_listings($niche_slug);
        
        require_once __DIR__ . '/views/admin-page.php';
    }
}

new DH_Prep_City_Listings();
