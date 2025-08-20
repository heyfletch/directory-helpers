<?php
/**
 * Prep Profiles by State Module
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
    }

    public function register_submenu_page() {
        if (self::$submenu_registered) {
            return;
        }
        self::$submenu_registered = true;
        add_submenu_page(
            'edit.php?post_type=state-listing',
            __('Prep Profiles by State', 'directory-helpers'),
            __('Prep Profiles by State', 'directory-helpers'),
            'manage_options',
            'dh-prep-profiles-by-state',
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

    private function query_profiles_by_state_and_status($state_slug, $post_status) {
        global $wpdb;
        if (empty($state_slug)) {
            return array();
        }
        $prefix = $wpdb->prefix;
        $sql = "
            SELECT p.*
            FROM {$prefix}posts p
            JOIN {$prefix}term_relationships tr1 ON p.ID = tr1.object_id
            JOIN {$prefix}term_taxonomy tt1 ON tr1.term_taxonomy_id = tt1.term_taxonomy_id
            JOIN {$prefix}terms t1 ON tt1.term_id = t1.term_id
            JOIN {$prefix}term_relationships tr2 ON p.ID = tr2.object_id
            JOIN {$prefix}term_taxonomy tt2 ON tr2.term_taxonomy_id = tt2.term_taxonomy_id
            JOIN {$prefix}terms t2 ON tt2.term_id = t2.term_id
            WHERE p.post_type = 'profile'
              AND tt1.taxonomy = 'state'
              AND t1.slug = %s
              AND p.post_status = %s
              AND tt2.taxonomy = 'area'
              AND t2.term_id IN (
                SELECT t3.term_id
                FROM {$prefix}posts p3
                JOIN {$prefix}term_relationships tr3 ON p3.ID = tr3.object_id
                JOIN {$prefix}term_taxonomy tt3 ON tr3.term_taxonomy_id = tt3.term_taxonomy_id
                JOIN {$prefix}terms t3 ON tt3.term_id = t3.term_id
                JOIN {$prefix}term_relationships tr4 ON p3.ID = tr4.object_id
                JOIN {$prefix}term_taxonomy tt4 ON tr4.term_taxonomy_id = tt4.term_taxonomy_id
                JOIN {$prefix}terms t4 ON tt4.term_id = t4.term_id
                WHERE p3.post_type = 'profile'
                  AND tt4.taxonomy = 'state'
                  AND t4.slug = %s
                  AND p3.post_status = %s
                  AND tt3.taxonomy = 'area'
                GROUP BY t3.term_id
                HAVING COUNT(DISTINCT p3.ID) > 1
              )
            ORDER BY p.post_title ASC
        ";
        $prepared = $wpdb->prepare($sql, $state_slug, $post_status, $state_slug, $post_status);
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
                $unique[$terms[0]->term_id] = $terms[0];
            }
        }
        return $unique; // term_id => WP_Term
    }

    private function publish_posts($post_ids) {
        foreach ($post_ids as $pid) {
            // Only update if not already publish
            $current = get_post_status($pid);
            if ('publish' !== $current) {
                wp_update_post(array(
                    'ID' => $pid,
                    'post_status' => 'publish',
                ));
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

    public function render_page() {
        static $rendered = false;
        if ($rendered) {
            return;
        }
        $rendered = true;
        if (!current_user_can('manage_options')) {
            return;
        }

        $state_slug = isset($_REQUEST['state']) ? sanitize_text_field(wp_unslash($_REQUEST['state'])) : '';
        $post_status = isset($_REQUEST['post_status']) ? sanitize_key($_REQUEST['post_status']) : 'refining';
        if (!in_array($post_status, array('refining', 'publish'), true)) {
            $post_status = 'refining';
        }

        $action_message = '';

        // Handle actions
        if (isset($_POST['dh_action']) && isset($_POST['_wpnonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), 'dh_prepprofiles')) {
            $action = sanitize_text_field(wp_unslash($_POST['dh_action']));
            if ($action === 'publish_all' && !empty($state_slug)) {
                $posts = $this->query_profiles_by_state_and_status($state_slug, $post_status);
                $ids = wp_list_pluck($posts, 'ID');
                $this->publish_posts($ids);
                $post_status = 'publish';
                $action_message = __('Published all filtered profiles.', 'directory-helpers');
            } elseif ($action === 'rerank' && !empty($state_slug)) {
                // For re-ranking, use published profiles only
                $published = $this->query_profiles_by_state_and_status($state_slug, 'publish');
                $ids = wp_list_pluck($published, 'ID');
                $this->rerank_posts($ids, $state_slug);
                $action_message = __('Re-ranked profiles for selected cities and state.', 'directory-helpers');
            }
        }

        // Fetch data for display
        $states = $this->get_state_terms();
        if (empty($state_slug) && !empty($states)) {
            $state_slug = $states[0]->slug; // default to first
        }
        $profiles = !empty($state_slug) ? $this->query_profiles_by_state_and_status($state_slug, $post_status) : array();
        $profile_ids = wp_list_pluck($profiles, 'ID');
        $unique_cities = $this->get_unique_area_terms_for_posts($profile_ids);

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Prep Profiles by State', 'directory-helpers') . '</h1>';

        if (!empty($action_message)) {
            echo '<div class="notice notice-success"><p>' . esc_html($action_message) . '</p></div>';
        }

        // Filter form
        echo '<form method="get" action="">';
        echo '<input type="hidden" name="post_type" value="state-listing" />';
        echo '<input type="hidden" name="page" value="dh-prep-profiles-by-state" />';

        echo '<div style="display:flex; gap:12px; align-items:center; margin:12px 0;">';
        // State selector
        echo '<label><strong>' . esc_html__('State:', 'directory-helpers') . '</strong> ';
        echo '<select name="state">';
        foreach ($states as $term) {
            $label = !empty($term->description) ? $term->description : $term->name;
            printf('<option value="%s" %s>%s</option>', esc_attr($term->slug), selected($state_slug, $term->slug, false), esc_html($label));
        }
        echo '</select></label>';

        // Status selector
        echo '<label><strong>' . esc_html__('Status:', 'directory-helpers') . '</strong> ';
        echo '<select name="post_status">';
        printf('<option value="refining" %s>%s</option>', selected($post_status, 'refining', false), esc_html__('Refining', 'directory-helpers'));
        printf('<option value="publish" %s>%s</option>', selected($post_status, 'publish', false), esc_html__('Publish', 'directory-helpers'));
        echo '</select></label>';

        submit_button(__('Filter'), 'secondary', '', false);
        echo '</div>';
        echo '</form>';

        // Action buttons
        echo '<form method="post" style="display:inline-block; margin-right:8px;">';
        wp_nonce_field('dh_prepprofiles');
        echo '<input type="hidden" name="state" value="' . esc_attr($state_slug) . '" />';
        echo '<input type="hidden" name="post_status" value="' . esc_attr($post_status) . '" />';
        echo '<input type="hidden" name="dh_action" value="publish_all" />';
        submit_button(__('Publish All Profiles', 'directory-helpers'), 'primary', 'submit', false);
        echo '</form>';

        echo '<form method="post" style="display:inline-block;">';
        wp_nonce_field('dh_prepprofiles');
        echo '<input type="hidden" name="state" value="' . esc_attr($state_slug) . '" />';
        echo '<input type="hidden" name="dh_action" value="rerank" />';
        submit_button(__('Rerank These Profiles', 'directory-helpers'), 'secondary', 'submit', false);
        echo '</form>';

        // Unique cities list
        echo '<h2 style="margin-top:24px;">' . esc_html__('Cities in Results', 'directory-helpers') . '</h2>';
        if (!empty($unique_cities)) {
            echo '<p>';
            $city_names = array_map(function($t){ return esc_html($t->name); }, array_values($unique_cities));
            echo implode(', ', $city_names);
            echo '</p>';
        } else {
            echo '<p>' . esc_html__('No cities found for current filters.', 'directory-helpers') . '</p>';
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
                $city = $this->get_first_area_term_name($p->ID);
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
