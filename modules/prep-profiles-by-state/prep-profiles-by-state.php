<?php
/**
 * Prep Profiles by State Module
 *
 * Overview:
 * - Adds "Prep Profiles" submenu under State Listings and renders the admin page.
 * - Provides filter form: State, City, Status (All/Refining/Published/Private), Niche, and minimum Profiles count.
 * - Lists unique Cities in Results and links each city (with confirmation) to create a draft city-listing.
 * - Action buttons:
 *   - Publish All Profiles: publishes filtered profiles (hidden when Status = Published).
 *   - Rerank These Profiles: available when Status = Published; triggers ACF save hooks per city/state to re-rank.
 * - Query respects selected niche and 'All' status (skips post_status filter) and enforces minimum profile count per city.
 * - City-listing creation flow (admin-post `dh_create_city_listing`):
 *   - Capability + nonce checks; builds title "City, ST" and slug "city-st-niche(+s)".
 *   - Assigns area and niche terms; redirects to edit screen with success + next steps notice.
 * - Security: nonce verification for actions; capability checks; sanitization of all inputs.
 *
 * Related docs: see docs/CITY-STATE-RELATIONSHIPS.md for city/state slug conventions.
 *
 * @package Directory_Helpers
 */

if (!defined('ABSPATH')) {
    exit;
}

class DH_Prep_Profiles_By_State {
    private static $submenu_registered = false;
    public function __construct() {
        add_action('admin_menu', array($this, 'register_submenu_page'));
        add_action('admin_post_dh_create_city_listing', array($this, 'handle_create_city_listing'));
        add_action('admin_notices', array($this, 'maybe_show_city_created_notice'));
    }

    public function register_submenu_page() {
        if (self::$submenu_registered) {
            return;
        }
        self::$submenu_registered = true;
        add_submenu_page(
            'edit.php?post_type=state-listing',
            __('Prep Profiles for Publishing', 'directory-helpers'),
            __('Prep Profiles', 'directory-helpers'),
            'manage_options',
            'dh-prep-profiles',
            array($this, 'render_page')
        );
    }

    private function get_state_terms() {
        $terms = get_terms(array(
            'taxonomy' => 'state',
            'hide_empty' => false,
        ));
        if (is_wp_error($terms)) {
            return array();
        }
        return $terms;
    }

    private function query_profiles_by_state_and_status($state_slug, $post_status, $min_count = 2, $city_slug = '', $niche_slug = 'dog-trainer', $city_search = '') {
        global $wpdb;
        if (empty($state_slug)) {
            return array();
        }
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
              AND t5.slug = %s";

        $params = array($state_slug, $niche_slug);

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

        $sql .= "\n              AND t2.term_id IN (
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
                  AND t4.slug = %s\n";
        $params[] = $state_slug;

        if ($post_status !== 'all') {
            $sql .= "                  AND p3.post_status = %s\n";
            $params[] = $post_status;
        }

        $sql .= "                  AND tt3.taxonomy = 'area'
                  AND tt5b.taxonomy = 'niche'
                  AND t5b.slug = %s
                GROUP BY t3.term_id
                HAVING COUNT(DISTINCT p3.ID) >= %d
              )
            ORDER BY t2.name ASC, p.post_title ASC";
        $params[] = $niche_slug;
        $params[] = $min_count;

        $prepared = $wpdb->prepare($sql, $params);
        return $wpdb->get_results($prepared);
    }

    private function get_first_area_term_name($post_id) {
        $terms = get_the_terms($post_id, 'area');
        if (!empty($terms) && !is_wp_error($terms)) {
            return $terms[0]->name;
        }
        return '';
    }

    private function get_unique_area_terms_for_posts($post_ids) {
        $unique = array();
        foreach ($post_ids as $pid) {
            $terms = get_the_terms($pid, 'area');
            if (!empty($terms) && !is_wp_error($terms)) {
                // If multiple area terms, select the one matching the profile's primary state
                $selected_term = $terms[0]; // fallback
                if (count($terms) > 1) {
                    $primary_state = DH_Taxonomy_Helpers::get_primary_state_term($pid);
                    if ($primary_state) {
                        $state_slug = strtolower($primary_state->slug);
                        foreach ($terms as $term) {
                            // Match area term slug ending with state code (e.g., milford-nh)
                            if (preg_match('/-' . preg_quote($state_slug, '/') . '$/', $term->slug)) {
                                $selected_term = $term;
                                break;
                            }
                        }
                    }
                }
                $unique[$selected_term->term_id] = $selected_term;
            }
        }
        return $unique; // term_id => WP_Term
    }

    private function publish_posts($post_ids) {
        $published_now = array();
        foreach ($post_ids as $pid) {
            $pid = (int) $pid;
            // Only update if not already publish
            $current = get_post_status($pid);
            if ('publish' !== $current) {
                $result = wp_update_post(array(
                    'ID' => $pid,
                    'post_status' => 'publish',
                ), true);
                if (!is_wp_error($result) && $result) {
                    $published_now[] = $pid;
                }
            }
        }
        return $published_now;
    }

    // Remove trailing " - ST" from area term names for posts that were just published
    private function cleanup_area_terms_for_posts($post_ids) {
        if (empty($post_ids)) {
            return;
        }
        $unique_terms = array(); // term_id => WP_Term
        foreach ($post_ids as $pid) {
            $terms = get_the_terms((int)$pid, 'area');
            if (!empty($terms) && !is_wp_error($terms)) {
                foreach ($terms as $t) {
                    $unique_terms[$t->term_id] = $t;
                }
            }
        }
        foreach ($unique_terms as $term) {
            $name = $term->name;
            if (preg_match('/\s-\s[A-Za-z]{2}$/', $name)) {
                $new_name = trim(preg_replace('/\s-\s[A-Za-z]{2}$/', '', $name));
                if ($new_name && $new_name !== $name) {
                    // Update only the name; keep slug unchanged
                    wp_update_term((int)$term->term_id, 'area', array('name' => $new_name));
                }
            }
        }
    }

    private function rerank_posts($post_ids, $state_slug) {
        if (empty($post_ids)) {
            return;
        }

        // Only consider published posts for ranking
        $published_ids = array();
        foreach ($post_ids as $pid) {
            if (get_post_status($pid) === 'publish') {
                $published_ids[] = $pid;
            }
        }
        if (empty($published_ids)) {
            return;
        }

        // Re-rank city by city (area terms)
        $area_terms = $this->get_unique_area_terms_for_posts($published_ids);
        foreach ($area_terms as $term_id => $term) {
            // Use a representative post in this city to trigger ACF save hook ranking
            $rep = $this->find_post_in_term($published_ids, $term_id, 'area');
            if ($rep) {
                do_action('acf/save_post', $rep);
            }
        }

        // Finally, trigger a state ranking once using a representative post in the selected state
        $rep_state_post = $this->find_post_in_state($published_ids, $state_slug);
        if ($rep_state_post) {
            do_action('acf/save_post', $rep_state_post);
        }
    }

    private function find_post_in_term($post_ids, $term_id, $taxonomy) {
        foreach ($post_ids as $pid) {
            $terms = wp_get_post_terms($pid, $taxonomy, array('fields' => 'ids'));
            if (!is_wp_error($terms) && in_array((int)$term_id, array_map('intval', (array)$terms), true)) {
                return $pid;
            }
        }
        return 0;
    }

    private function find_post_in_state($post_ids, $state_slug) {
        foreach ($post_ids as $pid) {
            $terms = get_the_terms($pid, 'state');
            if (!empty($terms) && !is_wp_error($terms)) {
                foreach ($terms as $t) {
                    if ($t->slug === $state_slug) {
                        return $pid;
                    }
                }
            }
        }
        return 0;
    }

    public function handle_create_city_listing() {
        if (!current_user_can('edit_posts')) {
            wp_die(esc_html__('You do not have permission to create posts.', 'directory-helpers'));
        }
        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'dh_create_city_listing')) {
            wp_die(esc_html__('Security check failed.', 'directory-helpers'));
        }

        $area_slug  = isset($_GET['area']) ? sanitize_title(wp_unslash($_GET['area'])) : '';
        $state_slug = isset($_GET['state']) ? sanitize_title(wp_unslash($_GET['state'])) : '';
        $niche_slug = isset($_GET['niche']) ? sanitize_title(wp_unslash($_GET['niche'])) : 'dog-trainer';

        if (empty($area_slug) || empty($state_slug)) {
            wp_die(esc_html__('Missing required parameters.', 'directory-helpers'));
        }

        $area_term  = get_term_by('slug', $area_slug, 'area');
        $state_term = get_term_by('slug', $state_slug, 'state');
        $niche_term = get_term_by('slug', $niche_slug, 'niche');

        if (!$area_term || is_wp_error($area_term)) {
            wp_die(esc_html__('Invalid area term.', 'directory-helpers'));
        }

        // City Name: strip trailing " - ST"
        $city_name = $area_term->name;
        $city_name = preg_replace('/\s+-\s+[A-Za-z]{2}$/', '', $city_name);
        $city_name = trim($city_name);

        // Determine state code (prefer 2-letter slug, else term description, else parse from area name)
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

        // Title: "City, ST"
        $title = $city_name . ( $state_code ? ', ' . $state_code : '' );

        // Niche pluralization (simple rules)
        $niche_name = ($niche_term && !is_wp_error($niche_term)) ? $niche_term->name : str_replace('-', ' ', $niche_slug);
        $plural_niche = $niche_name;
        if (preg_match('/[^aeiou]y$/i', $plural_niche)) {
            $plural_niche = preg_replace('/y$/i', 'ies', $plural_niche);
        } elseif (!preg_match('/s$/i', $plural_niche)) {
            $plural_niche .= 's';
        }

        // Slug base: "Title + plural niche"
        $slug_base = $title . ' ' . $plural_niche;
        $desired_slug = sanitize_title($slug_base);

        $post_id = wp_insert_post(array(
            'post_type'   => 'city-listing',
            'post_status' => 'draft',
            'post_title'  => $title,
            'post_name'   => $desired_slug,
        ), true);

        if (is_wp_error($post_id) || !$post_id) {
            wp_die(esc_html__('Failed to create city listing post.', 'directory-helpers'));
        }

        // Assign taxonomy terms
        wp_set_object_terms($post_id, (int) $area_term->term_id, 'area', false);
        if ($niche_term && !is_wp_error($niche_term)) {
            wp_set_object_terms($post_id, (int) $niche_term->term_id, 'niche', false);
        }

        // Redirect to edit screen with recap vars
        $edit_url = add_query_arg(array(
            'post'           => $post_id,
            'action'         => 'edit',
            'dh_cl_created'  => '1',
            'dh_cl_title'    => rawurlencode($title),
            'dh_cl_slug'     => rawurlencode(get_post_field('post_name', $post_id)),
            'dh_cl_area'     => rawurlencode($area_term->name),
            'dh_cl_niche'    => rawurlencode($niche_name),
        ), admin_url('post.php'));

        wp_safe_redirect($edit_url);
        exit;
    }

    /**
     * Check if a city-listing already exists for a given area and niche, with status draft or publish.
     *
     * @param int $area_term_id
     * @param int $niche_term_id 0 to ignore niche filter
     * @return int Post ID if exists, 0 otherwise
     */
    private function city_listing_exists($area_term_id, $niche_term_id = 0) {
        $tax_query = array(
            'relation' => 'AND',
            array(
                'taxonomy' => 'area',
                'field'    => 'term_id',
                'terms'    => array((int)$area_term_id),
            ),
        );
        if ($niche_term_id) {
            $tax_query[] = array(
                'taxonomy' => 'niche',
                'field'    => 'term_id',
                'terms'    => array((int)$niche_term_id),
            );
        }
        $q = new WP_Query(array(
            'post_type'      => 'city-listing',
            'post_status'    => array('draft', 'publish'),
            'posts_per_page' => 1,
            'tax_query'      => $tax_query,
            'fields'         => 'ids',
            'no_found_rows'  => true,
        ));
        if (!is_wp_error($q) && !empty($q->posts)) {
            return (int)$q->posts[0];
        }
        return 0;
    }

    /**
     * Programmatically create a city-listing post using same logic as handle_create_city_listing (no redirect).
     *
     * @param WP_Term $area_term
     * @param string  $state_slug
     * @param WP_Term|null $niche_term
     * @return int Created post ID or 0 on failure
     */
    private function create_city_listing_programmatic($area_term, $state_slug, $niche_term = null) {
        if (!$area_term || is_wp_error($area_term)) {
            return 0;
        }

        // City Name: strip trailing " - ST"
        $city_name = $area_term->name;
        $city_name = preg_replace('/\s+-\s+[A-Za-z]{2}$/', '', $city_name);
        $city_name = trim($city_name);

        // Determine state code (prefer 2-letter slug, else term description, else parse from area name)
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

        // Title: "City, ST"
        $title = $city_name . ( $state_code ? ', ' . $state_code : '' );

        // Niche pluralization (simple rules)
        $niche_name = ($niche_term && !is_wp_error($niche_term)) ? $niche_term->name : '';
        if (empty($niche_name) && !empty($niche_term) && !is_wp_error($niche_term)) {
            $niche_name = $niche_term->name;
        }
        $plural_niche = $niche_name;
        if ($plural_niche) {
            if (preg_match('/[^aeiou]y$/i', $plural_niche)) {
                $plural_niche = preg_replace('/y$/i', 'ies', $plural_niche);
            } elseif (!preg_match('/s$/i', $plural_niche)) {
                $plural_niche .= 's';
            }
        }

        // Slug base: "Title + plural niche"
        $slug_base = $title . ( $plural_niche ? ' ' . $plural_niche : '' );
        $desired_slug = sanitize_title($slug_base);

        $post_id = wp_insert_post(array(
            'post_type'   => 'city-listing',
            'post_status' => 'draft',
            'post_title'  => $title,
            'post_name'   => $desired_slug,
        ), true);

        if (is_wp_error($post_id) || !$post_id) {
            return 0;
        }

        // Assign taxonomy terms
        wp_set_object_terms($post_id, (int) $area_term->term_id, 'area', false);
        if ($niche_term && !is_wp_error($niche_term)) {
            wp_set_object_terms($post_id, (int) $niche_term->term_id, 'niche', false);
        }

        return (int)$post_id;
    }

    public function maybe_show_city_created_notice() {
        if (!is_admin()) {
            return;
        }
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || $screen->base !== 'post') {
            return;
        }
        if (!isset($_GET['dh_cl_created']) || $_GET['dh_cl_created'] !== '1') {
            return;
        }
        $title = isset($_GET['dh_cl_title']) ? sanitize_text_field(wp_unslash($_GET['dh_cl_title'])) : '';
        $slug  = isset($_GET['dh_cl_slug']) ? sanitize_title(wp_unslash($_GET['dh_cl_slug'])) : '';
        $area  = isset($_GET['dh_cl_area']) ? sanitize_text_field(wp_unslash($_GET['dh_cl_area'])) : '';
        $niche = isset($_GET['dh_cl_niche']) ? sanitize_text_field(wp_unslash($_GET['dh_cl_niche'])) : '';

        echo '<div class="notice notice-success is-dismissible"><p>'
            . sprintf(
                /* translators: 1: title, 2: slug, 3: area name, 4: niche name */
                esc_html__('New city listing page "%1$s" created with slug "%2$s", area "%3$s", niche "%4$s". Next Steps: Generate AI Content, Create Thumbnail, Find Photos, Create Video, Post to Youtube.', 'directory-helpers'),
                esc_html($title),
                esc_html($slug),
                esc_html($area),
                esc_html($niche)
            )
            . '</p></div>';

        echo '<div class="notice notice-warning is-dismissible"><p>'
            . esc_html__('Your next step is to click Generate AI Content.', 'directory-helpers')
            . '</p></div>';
    }

    public function render_page() {
        static $rendered = false;
        if ($rendered) {
            return;
        }
        $rendered = true;
        if (!current_user_can('manage_options')) {
            return;
        }
        
        // SHOW DEPRECATION NOTICE IMMEDIATELY
        ?>
        <div class="wrap">
            <div style="background: #dc3232; color: white; padding: 30px; margin: 20px 0; border-left: 4px solid #a00;">
                <h1 style="margin: 0 0 15px 0; font-size: 32px; color: white;">⚠️ DEPRECATED - USE PROFILE PRODUCTION INSTEAD</h1>
                <p style="font-size: 18px; margin: 0;"><strong><a href="<?php echo esc_url(admin_url('admin.php?page=dh-profile-production')); ?>" style="font-size: 20px; color: white; text-decoration: underline;">→ GO TO PROFILE PRODUCTION QUEUE</a></strong></p>
            </div>
        </div>
        <?php

        $state_slug = isset($_REQUEST['state']) ? sanitize_text_field(wp_unslash($_REQUEST['state'])) : '';
        $post_status = isset($_REQUEST['post_status']) ? sanitize_key($_REQUEST['post_status']) : 'refining';
        $min_count = isset($_REQUEST['min_count']) ? max(1, min(5, (int) $_REQUEST['min_count'])) : 5;
        $city_slug = isset($_REQUEST['city']) ? sanitize_title(wp_unslash($_REQUEST['city'])) : '';
        $niche_slug = isset($_REQUEST['niche']) ? sanitize_title(wp_unslash($_REQUEST['niche'])) : 'dog-trainer';
        $city_search = isset($_REQUEST['city_search']) ? sanitize_text_field(wp_unslash($_REQUEST['city_search'])) : '';
        if (!in_array($post_status, array('refining', 'publish', 'private', 'all'), true)) {
            $post_status = 'refining';
        }

        $action_message = '';

        // Handle actions
        if (isset($_POST['dh_action']) && isset($_POST['_wpnonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), 'dh_prepprofiles')) {
            $action = sanitize_text_field(wp_unslash($_POST['dh_action']));
            if ($action === 'publish_all' && !empty($state_slug)) {
                $posts = $this->query_profiles_by_state_and_status($state_slug, $post_status, $min_count, $city_slug, $niche_slug, $city_search);
                $ids = wp_list_pluck($posts, 'ID');
                $published_now_ids = $this->publish_posts($ids);
                if (!empty($published_now_ids)) {
                    $this->cleanup_area_terms_for_posts($published_now_ids);
                }
                $post_status = 'publish';
                $action_message = __('Published all filtered profiles.', 'directory-helpers');
            } elseif ($action === 'rerank' && !empty($state_slug)) {
                // For re-ranking, use published profiles only
                $published = $this->query_profiles_by_state_and_status($state_slug, 'publish', $min_count, $city_slug, $niche_slug, $city_search);
                $ids = wp_list_pluck($published, 'ID');
                $this->rerank_posts($ids, $state_slug);
                $action_message = __('Re-ranked profiles for selected cities and state.', 'directory-helpers');
            } elseif ($action === 'one_click_flow' && !empty($state_slug)) {
                // Step 1: Determine cities from current filters
                $posts = $this->query_profiles_by_state_and_status($state_slug, $post_status, $min_count, $city_slug, $niche_slug, $city_search);
                $unique_cities = array(); // slug => name
                foreach ($posts as $p) {
                    if (!empty($p->area_slug) && !empty($p->area_name)) {
                        $unique_cities[$p->area_slug] = $p->area_name;
                    }
                }

                $created_city_ids = array();
                $niche_term = get_term_by('slug', $niche_slug, 'niche');
                // Step 2: Create city pages if not already Draft/Published
                foreach ($unique_cities as $area_slug_k => $area_name_v) {
                    $area_term = get_term_by('slug', $area_slug_k, 'area');
                    if (!$area_term || is_wp_error($area_term)) { continue; }
                    $exists = $this->city_listing_exists((int)$area_term->term_id, ($niche_term && !is_wp_error($niche_term)) ? (int)$niche_term->term_id : 0);
                    if ($exists) { continue; }
                    $new_id = $this->create_city_listing_programmatic($area_term, $state_slug, $niche_term);
                    if ($new_id) {
                        $created_city_ids[] = $new_id;
                    }
                }

                // Step 3: Publish all filtered profiles (current status filter), then clean area terms
                $ids = wp_list_pluck($posts, 'ID');
                $published_now_ids = $this->publish_posts($ids);
                if (!empty($published_now_ids)) {
                    $this->cleanup_area_terms_for_posts($published_now_ids);
                }

                // Step 4: Rerank (use published only)
                $published = $this->query_profiles_by_state_and_status($state_slug, 'publish', $min_count, $city_slug, $niche_slug, $city_search);
                $pub_ids = wp_list_pluck($published, 'ID');
                $this->rerank_posts($pub_ids, $state_slug);

                // Step 5: Trigger AI content for newly created city pages with empty content
                $ai_triggered = 0;
                if (!empty($created_city_ids)) {
                    $options = get_option('directory_helpers_options');
                    $url = isset($options['n8n_webhook_url']) ? $options['n8n_webhook_url'] : '';
                    foreach ($created_city_ids as $cid) {
                        $content = get_post_field('post_content', $cid);
                        if (!empty($url) && (empty($content) || trim(wp_strip_all_tags($content)) === '')) {
                            $raw_title = wp_strip_all_tags(get_the_title($cid));
                            $clean_title = trim(preg_replace('/[^\p{L}\p{N}\s,]/u', '', $raw_title));
                            $clean_title = preg_replace('/\s+/', ' ', $clean_title);
                            
                            // Get niche from taxonomy description (or fallback to name, then default)
                            $niche_text = 'dog training'; // Default fallback
                            $niche_terms = get_the_terms($cid, 'niche');
                            if ($niche_terms && !is_wp_error($niche_terms) && !empty($niche_terms)) {
                                $term_description = trim($niche_terms[0]->description);
                                if (!empty($term_description)) {
                                    $niche_text = $term_description;
                                } else {
                                    // Fallback to Title Case name if description is empty
                                    $niche_text = ucwords(strtolower($niche_terms[0]->name));
                                }
                            }
                            
                            $keyword = $niche_text . ' in ' . $clean_title;
                            $body = wp_json_encode(array(
                                'postId'    => $cid,
                                'postUrl'   => get_permalink($cid),
                                'postTitle' => wp_strip_all_tags(get_the_title($cid)),
                                'keyword'   => $keyword,
                            ));
                            $resp = wp_remote_post($url, array(
                                'headers' => array('Content-Type' => 'application/json'),
                                'body'    => $body,
                                'timeout' => 20,
                            ));
                            if (!is_wp_error($resp)) {
                                $ai_triggered++;
                            }
                            // Avoid overloading the AI API
                            sleep(1);
                        }
                    }
                }

                // Build action message and redirect to draft city listings
                $action_message = sprintf(
                    /* translators: 1: cities created, 2: profiles published, 3: AI triggers */
                    esc_html__('Created %1$d city page(s). Published %2$d profile(s). Re-ranked profiles. Triggered AI for %3$d new city page(s).', 'directory-helpers'),
                    count($created_city_ids),
                    isset($published_now_ids) ? count($published_now_ids) : 0,
                    $ai_triggered
                );
                
                // Redirect to draft city listings page
                wp_safe_redirect(admin_url('edit.php?post_status=draft&post_type=city-listing'));
                exit;
            }
        }

        // Fetch data for display
        $states = $this->get_state_terms();
        $niches = get_terms(array(
            'taxonomy' => 'niche',
            'hide_empty' => false,
        ));
        if (empty($state_slug) && !empty($states)) {
            $state_slug = $states[0]->slug; // default to first
        }
        $profiles = !empty($state_slug) ? $this->query_profiles_by_state_and_status($state_slug, $post_status, $min_count, $city_slug, $niche_slug, $city_search) : array();
        // Build unique city names and slugs from query results to match ordering and selection
        $unique_cities = array(); // slug => name
        foreach ($profiles as $p) {
            if (!empty($p->area_slug) && !empty($p->area_name)) {
                $unique_cities[$p->area_slug] = $p->area_name;
            }
        }

        echo '<h1>' . esc_html__('Prep Profiles for Publishing', 'directory-helpers') . '</h1>';

        if (!empty($action_message)) {
            echo '<div class="notice notice-success"><p>' . esc_html($action_message) . '</p></div>';
        }

        // Filter form
        echo '<form method="get" action="edit.php">';
        echo '<input type="hidden" name="post_type" value="state-listing" />';
        echo '<input type="hidden" name="page" value="dh-prep-profiles" />';

        echo '<div style="display:flex; gap:12px; align-items:center; margin:12px 0; flex-wrap:wrap;">';
        // State selector
        echo '<label><strong>' . esc_html__('State:', 'directory-helpers') . '</strong> ';
        echo '<select name="state">';
        foreach ($states as $term) {
            $label = !empty($term->description) ? $term->description : $term->name;
            printf('<option value="%s" %s>%s</option>', esc_attr($term->slug), selected($state_slug, $term->slug, false), esc_html($label));
        }
        echo '</select></label>';

        // City search input
        echo '<label><strong>' . esc_html__('Find:', 'directory-helpers') . '</strong> ';
        echo '<input type="text" name="city_search" value="' . esc_attr($city_search) . '" placeholder="' . esc_attr__('Search city name...', 'directory-helpers') . '" style="width:150px;" />';
        echo '</label>';

        // City selector
        echo '<label><strong>' . esc_html__('City:', 'directory-helpers') . '</strong> ';
        echo '<select name="city">';
        printf('<option value="" %s>%s</option>', selected($city_slug, '', false), esc_html__('All Cities', 'directory-helpers'));
        if (!empty($unique_cities)) {
            // Sort by name
            asort($unique_cities, SORT_FLAG_CASE | SORT_STRING);
            foreach ($unique_cities as $slug => $name) {
                printf('<option value="%s" %s>%s</option>', esc_attr($slug), selected($city_slug, $slug, false), esc_html($name));
            }
        }
        echo '</select></label>';

        // Status selector
        echo '<label><strong>' . esc_html__('Status:', 'directory-helpers') . '</strong> ';
        echo '<select name="post_status">';
        printf('<option value="all" %s>%s</option>', selected($post_status, 'all', false), esc_html__('All', 'directory-helpers'));
        printf('<option value="refining" %s>%s</option>', selected($post_status, 'refining', false), esc_html__('Refining', 'directory-helpers'));
        printf('<option value="publish" %s>%s</option>', selected($post_status, 'publish', false), esc_html__('Published', 'directory-helpers'));
        printf('<option value="private" %s>%s</option>', selected($post_status, 'private', false), esc_html__('Private', 'directory-helpers'));
        echo '</select></label>';

        // Niche selector
        echo '<label><strong>' . esc_html__('Niche:', 'directory-helpers') . '</strong> ';
        echo '<select name="niche">';
        if (!is_wp_error($niches) && !empty($niches)) {
            foreach ($niches as $term) {
                printf('<option value="%s" %s>%s</option>', esc_attr($term->slug), selected($niche_slug, $term->slug, false), esc_html($term->name));
            }
        } else {
            printf('<option value="%s" %s>%s</option>', 'dog-trainer', selected($niche_slug, 'dog-trainer', false), esc_html__('Dog Trainer', 'directory-helpers'));
        }
        echo '</select></label>';

        // Min count selector
        echo '<label><strong>' . esc_html__('Profiles:', 'directory-helpers') . '</strong> ';
        echo '<select name="min_count">';
        for ($i = 1; $i <= 5; $i++) {
            printf('<option value="%d" %s>≥ %d</option>', $i, selected($min_count, $i, false), $i);
        }
        echo '</select></label>';

        // Right-side Filter button
        submit_button(__('Filter'), 'secondary', '', false);

        echo '</div>';
        echo '</form>';

        // Action buttons

        if ($post_status === 'publish') {
            echo '<form method="post" style="display:inline-block;" onsubmit="return confirm(\'Are you sure you want to re-rank these profiles?\')">'; 
            wp_nonce_field('dh_prepprofiles');
            echo '<input type="hidden" name="state" value="' . esc_attr($state_slug) . '" />';
            echo '<input type="hidden" name="city" value="' . esc_attr($city_slug) . '" />';
            echo '<input type="hidden" name="city_search" value="' . esc_attr($city_search) . '" />';
            echo '<input type="hidden" name="min_count" value="' . esc_attr($min_count) . '" />';
            echo '<input type="hidden" name="niche" value="' . esc_attr($niche_slug) . '" />';
            echo '<input type="hidden" name="dh_action" value="rerank" />';
            submit_button(__('Rerank These Profiles', 'directory-helpers'), 'secondary', 'submit', false);
            echo '</form>';
        }

        // One-click flow button (always available for current filters)
        echo '<form method="post" style="display:inline-block; margin-left:8px;" onsubmit="return confirm(\'Run one-click flow? This will create city pages (if missing), publish profiles, re-rank, and trigger AI generation.\')">'; 
        wp_nonce_field('dh_prepprofiles');
        echo '<input type="hidden" name="state" value="' . esc_attr($state_slug) . '" />';
        echo '<input type="hidden" name="post_status" value="' . esc_attr($post_status) . '" />';
        echo '<input type="hidden" name="city" value="' . esc_attr($city_slug) . '" />';
        echo '<input type="hidden" name="city_search" value="' . esc_attr($city_search) . '" />';
        echo '<input type="hidden" name="min_count" value="' . esc_attr($min_count) . '" />';
        echo '<input type="hidden" name="niche" value="' . esc_attr($niche_slug) . '" />';
        echo '<input type="hidden" name="dh_action" value="one_click_flow" />';
        submit_button(__('Create Cities, Publish, Rerank, and Generate AI', 'directory-helpers'), 'primary', 'submit', false);
        echo '</form>';
        
        // Add Profiles to Production Pipeline button
        if (!empty($profiles) && is_array($profiles)) {
            try {
                $profile_ids = array();
                foreach ($profiles as $p) {
                    if (isset($p->ID)) {
                        $profile_ids[] = (int)$p->ID;
                    }
                }
                
                if (!empty($profile_ids)) {
                    echo '<form method="post" id="dh-add-to-pipeline-form" style="margin-top: 10px;">';
                    wp_nonce_field('dh_profile_queue', 'dh_ppq_nonce');
                    echo '<input type="hidden" name="profile_ids" value="' . esc_attr(implode(',', $profile_ids)) . '" />';
                    echo '<input type="hidden" name="state_slug" value="' . esc_attr($state_slug) . '" />';
                    echo '<input type="hidden" name="niche_slug" value="' . esc_attr($niche_slug) . '" />';
                    echo '<input type="hidden" name="min_count" value="' . esc_attr($min_count) . '" />';
                    echo '<input type="hidden" name="city_slug" value="' . esc_attr($city_slug) . '" />';
                    echo '<input type="hidden" name="city_search" value="' . esc_attr($city_search) . '" />';
                    echo '<button type="button" id="dh-add-to-pipeline-btn" class="button button-secondary">';
                    echo esc_html__('Add Profiles to Production Pipeline', 'directory-helpers');
                    echo ' (' . count($profile_ids) . ' profiles)';
                    echo '</button>';
                    echo '</form>';
                    
                    // Add JavaScript for AJAX submission
                    $profile_count = count($profile_ids);
                    $pipeline_url = esc_url(admin_url('admin.php?page=dh-profile-production'));
                    ?>
                    <script type="text/javascript">
                    jQuery(document).ready(function($) {
                        $('#dh-add-to-pipeline-btn').on('click', function(e) {
                            e.preventDefault();
                            
                            var button = $(this);
                            var form = $('#dh-add-to-pipeline-form');
                            var profileCount = <?php echo (int)$profile_count; ?>;
                            
                            if (!confirm('Add ' + profileCount + ' profiles to production pipeline? They will be processed in batches.')) {
                                return;
                            }
                            
                            button.prop('disabled', true);
                            button.text('Adding to pipeline...');
                            
                            const profileIds = form.find('input[name="profile_ids"]').val().split(',').map(Number);
                            const stateSlug = form.find('input[name="state_slug"]').val();
                            const nicheSlug = form.find('input[name="niche_slug"]').val();
                            const minCount = form.find('input[name="min_count"]').val();
                            const citySlug = form.find('input[name="city_slug"]').val();
                            const citySearch = form.find('input[name="city_search"]').val();
                            const nonce = form.find('input[name="dh_ppq_nonce"]').val();
                            
                            $.ajax({
                                url: ajaxurl,
                                type: 'POST',
                                data: {
                                    action: 'dh_start_profile_queue',
                                    nonce: nonce,
                                    profile_ids: profileIds,
                                    state_slug: stateSlug,
                                    niche_slug: nicheSlug,
                                    min_count: minCount,
                                    city_slug: citySlug,
                                    city_search: citySearch
                                },
                                success: function(response) {
                                    if (response.success) {
                                        // Redirect immediately to queue page
                                        window.location.href = '<?php echo $pipeline_url; ?>';
                                    } else {
                                        alert('Error: ' + (response.data && response.data.message ? response.data.message : 'Failed to start queue'));
                                        button.prop('disabled', false);
                                        button.text('Add Profiles to Production Pipeline (' + profileCount + ' profiles)');
                                    }
                                },
                                error: function(xhr, status, error) {
                                    alert('Error: ' + error);
                                    button.prop('disabled', false);
                                    button.text('Add Profiles to Production Pipeline (' + profileCount + ' profiles)');
                                }
                            });
                        });
                    });
                    </script>
                    <?php
                }
            } catch (Exception $e) {
                // Silently fail if there's an issue with the button
                error_log('Profile Pipeline Button Error: ' . $e->getMessage());
            }
        }


        // Results table
        echo '<h2 style="margin-top:12px;">' . esc_html__('Profiles', 'directory-helpers') . '</h2>';
        echo '<table class="widefat fixed striped"><thead><tr>';
        echo '<th>' . esc_html__('Title', 'directory-helpers') . '</th>';
        echo '<th>' . esc_html__('City (Area)', 'directory-helpers') . '</th>';
        echo '<th>' . esc_html__('Status', 'directory-helpers') . '</th>';
        echo '</tr></thead><tbody>';

        if (!empty($profiles)) {
            foreach ($profiles as $p) {
                $title = get_the_title($p->ID);
                $edit_link = get_edit_post_link($p->ID, '');
                $city = isset($p->area_name) ? $p->area_name : $this->get_first_area_term_name($p->ID);
                $status = get_post_status($p->ID);
                echo '<tr>';
                echo '<td><a href="' . esc_url($edit_link) . '">' . esc_html($title) . '</a></td>';
                echo '<td>' . esc_html($city) . '</td>';
                echo '<td>' . esc_html($status) . '</td>';
                echo '</tr>';
            }
        } else {
            echo '<tr><td colspan="3">' . esc_html__('No profiles match the current filters.', 'directory-helpers') . '</td></tr>';
        }

        echo '</tbody></table>';
        echo '</div>';
    }
}
