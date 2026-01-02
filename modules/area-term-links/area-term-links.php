<?php
/**
 * Area Term Links Module
 *
 * Adds direct links to area taxonomy terms in the city-listing meta box.
 *
 * @package Directory_Helpers
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class DH_Area_Term_Links
 */
class DH_Area_Term_Links {
    
    /**
     * Constructor
     */
    public function __construct() {
        add_action('add_meta_boxes', array($this, 'add_term_edit_meta_boxes'));
    }
    
    /**
     * Add meta boxes for term edit links
     */
    public function add_term_edit_meta_boxes() {
        // Add to city-listing
        add_meta_box(
            'dh-term-links',
            __('Edit Terms', 'directory-helpers'),
            array($this, 'render_term_links_meta_box'),
            'city-listing',
            'side',
            'high'
        );
        
        // Add to profile
        add_meta_box(
            'dh-term-links',
            __('Edit Terms', 'directory-helpers'),
            array($this, 'render_term_links_meta_box'),
            'profile',
            'side',
            'high'
        );
        
        // Add to state-listing
        add_meta_box(
            'dh-term-links',
            __('Edit Terms', 'directory-helpers'),
            array($this, 'render_term_links_meta_box'),
            'state-listing',
            'side',
            'high'
        );
    }
    
    /**
     * Render the term edit links meta box
     */
    public function render_term_links_meta_box($post) {
        $post_type = get_post_type($post);
        $has_terms = false;
        $area_slug = null;
        
        // Define which taxonomies to show for each post type
        $taxonomies = array();
        if ($post_type === 'city-listing') {
            $taxonomies = array('area', 'niche');
        } elseif ($post_type === 'profile') {
            $taxonomies = array('area', 'state', 'niche');
        } elseif ($post_type === 'state-listing') {
            $taxonomies = array('state', 'niche');
        }
        
        // Loop through each taxonomy and display edit links
        foreach ($taxonomies as $taxonomy) {
            $terms = get_the_terms($post->ID, $taxonomy);
            
            if (!empty($terms) && !is_wp_error($terms)) {
                $has_terms = true;
                foreach ($terms as $term) {
                    $edit_url = admin_url('term.php?taxonomy=' . $taxonomy . '&tag_ID=' . $term->term_id);
                    echo '<p style="margin: 0 0 10px 0;">';
                    echo '<a href="' . esc_url($edit_url) . '" target="_blank" class="button button-secondary" style="width: 100%;">';
                    echo esc_html($term->name) . ' (edit term)';
                    echo '</a>';
                    echo '</p>';
                    
                    // Store area slug for city-listing action buttons
                    if ($post_type === 'city-listing' && $taxonomy === 'area') {
                        $area_slug = $term->slug;
                    }
                }
            }
        }
        
        // Add action buttons for city-listing
        if ($post_type === 'city-listing' && $area_slug) {
            echo '<div style="border-top: 1px solid #ddd; padding-top: 10px; margin-top: 10px;">';
            echo '<p style="margin: 0 0 8px 0; font-weight: bold;">' . esc_html__('City Actions:', 'directory-helpers') . '</p>';
            
            // Update Rankings button
            echo '<div class="dh-cli-actions" style="margin-bottom: 8px;">';
            echo '<button type="button" class="button dh-cli-run-btn" ';
            echo 'data-command="update-rankings dog-trainer --city=' . esc_attr($area_slug) . '">';
            echo '<span class="dashicons dashicons-sort" style="font-size: 16px; line-height: 1.4;"></span> ';
            echo esc_html__('Update Rankings', 'directory-helpers');
            echo '</button>';
            echo '<span class="dh-cli-status"></span>';
            echo '</div>';
            
            // Analyze Radius button
            echo '<div class="dh-cli-actions">';
            echo '<button type="button" class="button dh-cli-run-btn" ';
            echo 'data-command="analyze-radius dog-trainer ' . esc_attr($area_slug) . ' --update-meta">';
            echo '<span class="dashicons dashicons-location-alt" style="font-size: 16px; line-height: 1.4;"></span> ';
            echo esc_html__('Analyze Radius', 'directory-helpers');
            echo '</button>';
            echo '<span class="dh-cli-status"></span>';
            echo '</div>';
            
            echo '</div>';
        }
        
        // Show message if no terms assigned
        if (!$has_terms) {
            echo '<p>' . esc_html__('No terms assigned yet.', 'directory-helpers') . '</p>';
        }
    }
}

// Initialize the module
new DH_Area_Term_Links();
