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
        add_action('add_meta_boxes', array($this, 'add_area_term_link_meta_box'));
    }
    
    /**
     * Add meta box for area term edit link
     */
    public function add_area_term_link_meta_box() {
        add_meta_box(
            'dh-area-term-link',
            __('Edit City Term', 'directory-helpers'),
            array($this, 'render_area_term_link_meta_box'),
            'city-listing',
            'side',
            'high'
        );
    }
    
    /**
     * Render the area term edit link meta box
     */
    public function render_area_term_link_meta_box($post) {
        // Get the area terms for this city-listing
        $area_terms = get_the_terms($post->ID, 'area');
        
        if (empty($area_terms) || is_wp_error($area_terms)) {
            echo '<p>' . esc_html__('No area term assigned yet.', 'directory-helpers') . '</p>';
            return;
        }
        
        // Display edit link for each area term (usually just one)
        foreach ($area_terms as $term) {
            $edit_url = admin_url('term.php?taxonomy=area&tag_ID=' . $term->term_id);
            echo '<p style="margin: 0 0 10px 0;">';
            echo '<strong>' . esc_html($term->name) . '</strong><br>';
            echo '<a href="' . esc_url($edit_url) . '" target="_blank" class="button button-secondary" style="margin-top: 5px;">';
            echo '📝 ' . esc_html__('Edit City Term', 'directory-helpers');
            echo '</a>';
            echo '</p>';
        }
    }
}

// Initialize the module
new DH_Area_Term_Links();
