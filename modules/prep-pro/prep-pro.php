<?php
/**
 * Prep Pro Module - Streamlined Profile Production
 *
 * Fast publishing without reranking, with separate maintenance buttons.
 * Tracks processed items for targeted maintenance operations.
 *
 * @package Directory_Helpers
 */

if (!defined('ABSPATH')) {
    exit;
}

class DH_Prep_Pro {
    
    const TRACKING_OPTION = 'dh_prep_pro_tracking';
    
    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'), 20);
        add_action('admin_post_dh_prep_pro_publish', array($this, 'handle_fast_publish'));
        add_action('admin_post_dh_prep_pro_rerank', array($this, 'handle_rerank'));
        add_action('admin_post_dh_prep_pro_clear_cache', array($this, 'handle_clear_cache'));
        add_action('admin_post_dh_prep_pro_prime_cache', array($this, 'handle_prime_cache'));
        add_action('admin_post_dh_prep_pro_clear_prime', array($this, 'handle_clear_prime'));
        add_action('admin_post_dh_prep_pro_rerank_purge_prime', array($this, 'handle_rerank_purge_prime'));
        add_action('admin_post_dh_prep_pro_reset', array($this, 'handle_reset_tracking'));
    }
    
    public function add_admin_menu() {
        add_submenu_page(
            'directory-helpers',
            __('Prep Pro', 'directory-helpers'),
            __('Prep Pro', 'directory-helpers'),
            'manage_options',
            'dh-prep-pro',
            array($this, 'render_page')
        );
    }
    
    private function get_state_terms() {
        $terms = get_terms(array('taxonomy' => 'state', 'hide_empty' => false));
        return is_wp_error($terms) ? array() : $terms;
    }
    
    private function get_niche_terms() {
        $terms = get_terms(array('taxonomy' => 'niche', 'hide_empty' => false));
        return is_wp_error($terms) ? array() : $terms;
    }
    
    private function query_profiles($state_slug, $post_status, $min_count, $city_slug, $niche_slug, $city_search) {
        global $wpdb;
        if (empty($state_slug)) return array();
        
        $min_count = max(1, min(5, (int) $min_count));
        $prefix = $wpdb->prefix;
        
        $sql = "
            SELECT p.*, t2.name AS area_name, t2.slug AS area_slug, t2.term_id AS area_id
            FROM {$prefix}posts p
            JOIN {$prefix}term_relationships tr1 ON p.ID = tr1.object_id
            JOIN {$prefix}term_taxonomy tt1 ON tr1.term_taxonomy_id = tt1.term_taxonomy_id
            JOIN {$prefix}terms t1 ON tt1.term_id = t1.term_id
            JOIN {$prefix}term_relationships tr2 ON p.ID = tr2.object_id
            JOIN {$prefix}term_taxonomy tt2 ON tr2.term_taxonomy_id = tt2.term_taxonomy_id
            JOIN {$prefix}terms t2 ON tt2.term_id = t2.term_id
            JOIN {$prefix}term_relationships tr5 ON p.ID = tr5.object_id
            JOIN {$prefix}term_taxonomy tt5 ON tr5.term_taxonomy_id = tt5.term_taxonomy_id
            JOIN {$prefix}terms t5 ON tt5.term_id = t5.term_id
            WHERE p.post_type = 'profile'
              AND tt1.taxonomy = 'state'
              AND t1.slug = %s
              AND tt2.taxonomy = 'area'
              AND tt5.taxonomy = 'niche'
              AND t5.slug = %s
              AND t2.term_id IN (
                SELECT t3.term_id
                FROM {$prefix}posts p3
                JOIN {$prefix}term_relationships tr3 ON p3.ID = tr3.object_id
                JOIN {$prefix}term_taxonomy tt3 ON tr3.term_taxonomy_id = tt3.term_taxonomy_id
                JOIN {$prefix}terms t3 ON tt3.term_id = t3.term_id
                JOIN {$prefix}term_relationships tr4 ON p3.ID = tr4.object_id
                JOIN {$prefix}term_taxonomy tt4 ON tr4.term_taxonomy_id = tt4.term_taxonomy_id
                JOIN {$prefix}terms t4 ON tt4.term_id = t4.term_id
                JOIN {$prefix}term_relationships tr5b ON p3.ID = tr5b.object_id
                JOIN {$prefix}term_taxonomy tt5b ON tr5b.term_taxonomy_id = tt5b.term_taxonomy_id
                JOIN {$prefix}terms t5b ON tt5b.term_id = t5b.term_id
                WHERE p3.post_type = 'profile'
                  AND tt4.taxonomy = 'state'
                  AND t4.slug = %s
                  AND tt3.taxonomy = 'area'
                  AND tt5b.taxonomy = 'niche'
                  AND t5b.slug = %s";
        
        $params = array($state_slug, $niche_slug, $state_slug, $niche_slug);
        
        if ($post_status !== 'all') {
            $sql .= "\n                  AND p3.post_status = %s";
            $params[] = $post_status;
        }
        
        $sql .= "\n                GROUP BY t3.term_id
                HAVING COUNT(DISTINCT p3.ID) >= %d
              )";
        $params[] = $min_count;
        
        if ($post_status !== 'all') {
            $sql .= "\n              AND p.post_status = %s";
            $params[] = $post_status;
        }
        if (!empty($city_slug)) {
            $sql .= "\n              AND t2.slug = %s";
            $params[] = $city_slug;
        }
        if (!empty($city_search)) {
            $sql .= "\n              AND t2.name LIKE %s";
            $params[] = '%' . $wpdb->esc_like($city_search) . '%';
        }
        
        $sql .= "\n            ORDER BY t2.name ASC, p.post_title ASC";
        
        return $wpdb->get_results($wpdb->prepare($sql, $params));
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
        if (!$state_code && preg_match('/\s-\s([A-Za-z]{2})$/', $area_term->name, $m)) {
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
        
        // Assign area and niche only, NO state taxonomy
        wp_set_object_terms($post_id, (int)$area_term->term_id, 'area', false);
        if ($niche_term && !is_wp_error($niche_term)) {
            wp_set_object_terms($post_id, (int)$niche_term->term_id, 'niche', false);
        }
        
        return (int)$post_id;
    }
    
    private function cleanup_area_terms($post_ids) {
        if (empty($post_ids)) return;
        
        $unique_terms = array();
        foreach ($post_ids as $pid) {
            $terms = get_the_terms((int)$pid, 'area');
            if (!empty($terms) && !is_wp_error($terms)) {
                foreach ($terms as $t) {
                    $unique_terms[$t->term_id] = $t;
                }
            }
        }
        
        foreach ($unique_terms as $term) {
            if (preg_match('/\s-\s[A-Za-z]{2}$/', $term->name)) {
                $new_name = trim(preg_replace('/\s-\s[A-Za-z]{2}$/', '', $term->name));
                if ($new_name && $new_name !== $term->name) {
                    wp_update_term((int)$term->term_id, 'area', array('name' => $new_name));
                }
            }
        }
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
                $clean_title = trim(preg_replace('/[^\p{L}\p{N}\s]/u', '', $raw_title));
                $clean_title = preg_replace('/\s+/', ' ', $clean_title);
                $keyword = 'dog training in ' . $clean_title;
                
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
                sleep(1);
            }
        }
        return $count;
    }
    
    // Store tracking data
    private function save_tracking($profile_ids, $city_ids, $state_slug) {
        // Get unique city area term IDs
        $city_term_ids = array();
        foreach ($profile_ids as $pid) {
            $terms = get_the_terms($pid, 'area');
            if ($terms && !is_wp_error($terms)) {
                $city_term_ids[] = $terms[0]->term_id;
            }
        }
        $city_term_ids = array_unique($city_term_ids);
        
        update_option(self::TRACKING_OPTION, array(
            'profile_ids' => $profile_ids,
            'city_listing_ids' => $city_ids,
            'city_term_ids' => $city_term_ids,
            'state_slug' => $state_slug,
            'timestamp' => time(),
        ), false);
    }
    
    private function get_tracking() {
        return get_option(self::TRACKING_OPTION, array());
    }
    
    private function clear_tracking() {
        delete_option(self::TRACKING_OPTION);
    }
    
    // === ACTION HANDLERS ===
    
    public function handle_fast_publish() {
        if (!current_user_can('manage_options')) wp_die('Unauthorized');
        check_admin_referer('dh_prep_pro_publish');
        
        $state_slug = isset($_POST['state']) ? sanitize_text_field(wp_unslash($_POST['state'])) : '';
        $post_status = isset($_POST['post_status']) ? sanitize_key($_POST['post_status']) : 'refining';
        $min_count = isset($_POST['min_count']) ? max(1, min(5, (int)$_POST['min_count'])) : 3;
        $city_slug = isset($_POST['city']) ? sanitize_title(wp_unslash($_POST['city'])) : '';
        $niche_slug = isset($_POST['niche']) ? sanitize_title(wp_unslash($_POST['niche'])) : 'dog-trainer';
        $city_search = isset($_POST['city_search']) ? sanitize_text_field(wp_unslash($_POST['city_search'])) : '';
        
        // 1. Query profiles
        $profiles = $this->query_profiles($state_slug, $post_status, $min_count, $city_slug, $niche_slug, $city_search);
        $profile_ids = wp_list_pluck($profiles, 'ID');
        
        // 2. Create missing city-listings
        $created_city_ids = array();
        $niche_term = get_term_by('slug', $niche_slug, 'niche');
        
        $unique_cities = array();
        foreach ($profiles as $p) {
            if (!empty($p->area_slug)) {
                $unique_cities[$p->area_slug] = $p->area_id;
            }
        }
        
        foreach ($unique_cities as $area_slug => $area_term_id) {
            $area_term = get_term_by('term_id', $area_term_id, 'area');
            if (!$area_term) continue;
            
            $exists = $this->city_listing_exists((int)$area_term->term_id, ($niche_term && !is_wp_error($niche_term)) ? (int)$niche_term->term_id : 0);
            if (!$exists) {
                $new_id = $this->create_city_listing($area_term, $state_slug, $niche_term);
                if ($new_id) {
                    $created_city_ids[] = $new_id;
                }
            }
        }
        
        // 3. Send cities to AI
        $this->trigger_ai_for_cities($created_city_ids);
        
        // 4. Publish profiles
        $published_count = 0;
        foreach ($profile_ids as $pid) {
            if (get_post_status($pid) !== 'publish') {
                wp_update_post(array('ID' => $pid, 'post_status' => 'publish'));
                $published_count++;
            }
        }
        
        // 5. Clean area terms
        $this->cleanup_area_terms($profile_ids);
        
        // Save tracking
        $this->save_tracking($profile_ids, $created_city_ids, $state_slug);
        
        // Store created city IDs in transient for display on success page
        if (!empty($created_city_ids)) {
            set_transient('dh_prep_pro_created_cities_' . get_current_user_id(), $created_city_ids, 60);
        }
        
        wp_safe_redirect(add_query_arg(array(
            'page' => 'dh-prep-pro',
            'published' => $published_count,
            'cities_created' => count($created_city_ids),
        ), admin_url('admin.php')));
        exit;
    }
    
    public function handle_rerank() {
        if (!current_user_can('manage_options')) wp_die('Unauthorized');
        check_admin_referer('dh_prep_pro_maintenance');
        
        $tracking = $this->get_tracking();
        if (empty($tracking)) {
            wp_safe_redirect(add_query_arg('page', 'dh-prep-pro', admin_url('admin.php')));
            exit;
        }
        
        // Rerank: trigger acf/save_post on one profile per city + once for state
        if (!empty($tracking['city_term_ids'])) {
            foreach ($tracking['city_term_ids'] as $city_term_id) {
                // Find one published profile in this city
                $rep_profile = $this->find_profile_in_city($city_term_id);
                if ($rep_profile) {
                    do_action('acf/save_post', $rep_profile);
                }
            }
        }
        
        // Rerank state
        if (!empty($tracking['state_slug'])) {
            $rep_profile = $this->find_profile_in_state($tracking['state_slug']);
            if ($rep_profile) {
                do_action('acf/save_post', $rep_profile);
            }
        }
        
        wp_safe_redirect(add_query_arg(array('page' => 'dh-prep-pro', 'reranked' => '1'), admin_url('admin.php')));
        exit;
    }
    
    public function handle_clear_cache() {
        if (!current_user_can('manage_options')) wp_die('Unauthorized');
        check_admin_referer('dh_prep_pro_maintenance');
        
        $tracking = $this->get_tracking();
        if (empty($tracking)) {
            wp_safe_redirect(add_query_arg('page', 'dh-prep-pro', admin_url('admin.php')));
            exit;
        }
        
        $this->clear_cache_for_tracking($tracking);
        
        wp_safe_redirect(add_query_arg(array('page' => 'dh-prep-pro', 'cache_cleared' => '1'), admin_url('admin.php')));
        exit;
    }
    
    public function handle_prime_cache() {
        if (!current_user_can('manage_options')) wp_die('Unauthorized');
        check_admin_referer('dh_prep_pro_maintenance');
        
        $tracking = $this->get_tracking();
        if (empty($tracking)) {
            wp_safe_redirect(add_query_arg('page', 'dh-prep-pro', admin_url('admin.php')));
            exit;
        }
        
        $this->prime_cache_for_tracking($tracking);
        
        wp_safe_redirect(add_query_arg(array('page' => 'dh-prep-pro', 'cache_primed' => '1'), admin_url('admin.php')));
        exit;
    }
    
    public function handle_clear_prime() {
        if (!current_user_can('manage_options')) wp_die('Unauthorized');
        check_admin_referer('dh_prep_pro_maintenance');
        
        $tracking = $this->get_tracking();
        if (empty($tracking)) {
            wp_safe_redirect(add_query_arg('page', 'dh-prep-pro', admin_url('admin.php')));
            exit;
        }
        
        $this->clear_cache_for_tracking($tracking);
        $this->prime_cache_for_tracking($tracking);
        
        wp_safe_redirect(add_query_arg(array('page' => 'dh-prep-pro', 'cache_cleared_primed' => '1'), admin_url('admin.php')));
        exit;
    }
    
    public function handle_rerank_purge_prime() {
        if (!current_user_can('manage_options')) wp_die('Unauthorized');
        check_admin_referer('dh_prep_pro_maintenance');
        
        $tracking = $this->get_tracking();
        if (empty($tracking)) {
            wp_safe_redirect(add_query_arg('page', 'dh-prep-pro', admin_url('admin.php')));
            exit;
        }
        
        // Rerank
        if (!empty($tracking['city_term_ids'])) {
            foreach ($tracking['city_term_ids'] as $city_term_id) {
                $rep_profile = $this->find_profile_in_city($city_term_id);
                if ($rep_profile) {
                    do_action('acf/save_post', $rep_profile);
                }
            }
        }
        if (!empty($tracking['state_slug'])) {
            $rep_profile = $this->find_profile_in_state($tracking['state_slug']);
            if ($rep_profile) {
                do_action('acf/save_post', $rep_profile);
            }
        }
        
        // Clear and prime cache
        $this->clear_cache_for_tracking($tracking);
        $this->prime_cache_for_tracking($tracking);
        
        wp_safe_redirect(add_query_arg(array('page' => 'dh-prep-pro', 'all_complete' => '1'), admin_url('admin.php')));
        exit;
    }
    
    public function handle_reset_tracking() {
        if (!current_user_can('manage_options')) wp_die('Unauthorized');
        check_admin_referer('dh_prep_pro_maintenance');
        
        $this->clear_tracking();
        
        wp_safe_redirect(add_query_arg(array('page' => 'dh-prep-pro', 'reset' => '1'), admin_url('admin.php')));
        exit;
    }
    
    // === HELPER FUNCTIONS ===
    
    private function find_profile_in_city($city_term_id) {
        $q = new WP_Query(array(
            'post_type' => 'profile',
            'post_status' => 'publish',
            'posts_per_page' => 1,
            'tax_query' => array(
                array('taxonomy' => 'area', 'field' => 'term_id', 'terms' => (int)$city_term_id),
            ),
            'fields' => 'ids',
        ));
        return !empty($q->posts) ? $q->posts[0] : 0;
    }
    
    private function find_profile_in_state($state_slug) {
        $q = new WP_Query(array(
            'post_type' => 'profile',
            'post_status' => 'publish',
            'posts_per_page' => 1,
            'tax_query' => array(
                array('taxonomy' => 'state', 'field' => 'slug', 'terms' => $state_slug),
            ),
            'fields' => 'ids',
        ));
        return !empty($q->posts) ? $q->posts[0] : 0;
    }
    
    private function clear_cache_for_tracking($tracking) {
        // Clear cache for city-listing posts
        if (!empty($tracking['city_listing_ids'])) {
            foreach ($tracking['city_listing_ids'] as $city_id) {
                clean_post_cache($city_id);
                wp_cache_delete($city_id, 'posts');
                wp_cache_delete($city_id, 'post_meta');
            }
        }
        
        // Clear cache for state-listing
        if (!empty($tracking['state_slug'])) {
            $state_listing = $this->get_state_listing_by_slug($tracking['state_slug']);
            if ($state_listing) {
                clean_post_cache($state_listing);
                wp_cache_delete($state_listing, 'posts');
                wp_cache_delete($state_listing, 'post_meta');
            }
        }
        
        // Clear profile caches
        if (!empty($tracking['profile_ids'])) {
            foreach ($tracking['profile_ids'] as $pid) {
                clean_post_cache($pid);
            }
        }
    }
    
    private function prime_cache_for_tracking($tracking) {
        // Prime city-listing caches by loading them
        if (!empty($tracking['city_listing_ids'])) {
            foreach ($tracking['city_listing_ids'] as $city_id) {
                get_post($city_id);
                get_post_meta($city_id);
            }
        }
        
        // Prime state-listing cache
        if (!empty($tracking['state_slug'])) {
            $state_listing = $this->get_state_listing_by_slug($tracking['state_slug']);
            if ($state_listing) {
                get_post($state_listing);
                get_post_meta($state_listing);
            }
        }
    }
    
    private function get_state_listing_by_slug($state_slug) {
        $q = new WP_Query(array(
            'post_type' => 'state-listing',
            'posts_per_page' => 1,
            'tax_query' => array(
                array('taxonomy' => 'state', 'field' => 'slug', 'terms' => $state_slug),
            ),
            'fields' => 'ids',
        ));
        return !empty($q->posts) ? $q->posts[0] : 0;
    }
    
    // === RENDER PAGE ===
    
    public function render_page() {
        if (!current_user_can('manage_options')) return;
        
        $state_slug = isset($_REQUEST['state']) ? sanitize_text_field(wp_unslash($_REQUEST['state'])) : '';
        $post_status = isset($_REQUEST['post_status']) ? sanitize_key($_REQUEST['post_status']) : 'refining';
        $min_count = isset($_REQUEST['min_count']) ? max(1, min(5, (int)$_REQUEST['min_count'])) : 3;
        $city_slug = isset($_REQUEST['city']) ? sanitize_title(wp_unslash($_REQUEST['city'])) : '';
        $niche_slug = isset($_REQUEST['niche']) ? sanitize_title(wp_unslash($_REQUEST['niche'])) : 'dog-trainer';
        $city_search = isset($_REQUEST['city_search']) ? sanitize_text_field(wp_unslash($_REQUEST['city_search'])) : '';
        
        if (!in_array($post_status, array('refining', 'publish', 'private', 'all'), true)) {
            $post_status = 'refining';
        }
        
        $states = $this->get_state_terms();
        $niches = $this->get_niche_terms();
        $profiles = !empty($state_slug) ? $this->query_profiles($state_slug, $post_status, $min_count, $city_slug, $niche_slug, $city_search) : array();
        $tracking = $this->get_tracking();
        
        // Build city list for dropdown
        $unique_cities = array();
        foreach ($profiles as $p) {
            if (!empty($p->area_slug) && !empty($p->area_name)) {
                $unique_cities[$p->area_slug] = $p->area_name;
            }
        }
        asort($unique_cities, SORT_FLAG_CASE | SORT_STRING);
        
        require_once __DIR__ . '/views/admin-page.php';
    }
}

new DH_Prep_Pro();
