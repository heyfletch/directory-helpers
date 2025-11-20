<?php
/**
 * ACF Field Registration for Directory Helpers
 * Programmatically adds custom fields to taxonomies
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DH_ACF_Fields {
    
    /**
     * Initialize ACF field registration
     */
    public static function init() {
        add_action( 'acf/init', [ __CLASS__, 'register_area_taxonomy_fields' ] );
    }
    
    /**
     * Register fields for the Area taxonomy
     * Adds custom_radius and recommended_radius fields to existing field group
     */
    public static function register_area_taxonomy_fields() {
        if ( ! function_exists( 'acf_add_local_field' ) ) {
            return;
        }
        
        // Try to find existing area field group
        $field_groups = acf_get_field_groups( [ 'taxonomy' => 'area' ] );
        
        if ( empty( $field_groups ) ) {
            // No existing field group, create a new one
            acf_add_local_field_group([
                'key' => 'group_dh_area_fields',
                'title' => 'Area Taxonomy Fields',
                'fields' => [
                    [
                        'key' => 'field_dh_latitude',
                        'label' => 'Latitude',
                        'name' => 'latitude',
                        'type' => 'text',
                        'instructions' => 'Latitude coordinate for this area',
                        'required' => 0,
                    ],
                    [
                        'key' => 'field_dh_longitude',
                        'label' => 'Longitude',
                        'name' => 'longitude',
                        'type' => 'text',
                        'instructions' => 'Longitude coordinate for this area',
                        'required' => 0,
                    ],
                    [
                        'key' => 'field_dh_custom_radius',
                        'label' => 'Custom Radius (miles)',
                        'name' => 'custom_radius',
                        'type' => 'number',
                        'instructions' => 'Override the default proximity search radius for this area. Leave blank to use recommended radius or default (10 miles).',
                        'required' => 0,
                        'min' => 1,
                        'max' => 100,
                        'step' => 1,
                    ],
                    [
                        'key' => 'field_dh_recommended_radius',
                        'label' => 'Recommended Radius (miles)',
                        'name' => 'recommended_radius',
                        'type' => 'number',
                        'instructions' => 'System-calculated recommended radius. Set by WP-CLI analyze-radius command. Read-only.',
                        'required' => 0,
                        'readonly' => 1,
                        'disabled' => 1,
                    ],
                ],
                'location' => [
                    [
                        [
                            'param' => 'taxonomy',
                            'operator' => '==',
                            'value' => 'area',
                        ],
                    ],
                ],
                'menu_order' => 0,
                'position' => 'normal',
                'style' => 'default',
                'label_placement' => 'top',
                'instruction_placement' => 'label',
            ]);
        } else {
            // Existing field group found, add fields to it
            $field_group = $field_groups[0];
            $parent_key = $field_group['key'];
            
            // Check if fields already exist
            $existing_fields = acf_get_fields( $parent_key );
            $field_names = wp_list_pluck( $existing_fields, 'name' );
            
            // Add custom_radius if it doesn't exist
            if ( ! in_array( 'custom_radius', $field_names ) ) {
                acf_add_local_field([
                    'key' => 'field_dh_custom_radius',
                    'label' => 'Custom Radius (miles)',
                    'name' => 'custom_radius',
                    'type' => 'number',
                    'parent' => $parent_key,
                    'instructions' => 'Override the default proximity search radius for this area. Leave blank to use recommended radius or default (10 miles).',
                    'required' => 0,
                    'min' => 1,
                    'max' => 100,
                    'step' => 1,
                    'menu_order' => 100,
                ]);
            }
            
            // Add recommended_radius if it doesn't exist
            if ( ! in_array( 'recommended_radius', $field_names ) ) {
                acf_add_local_field([
                    'key' => 'field_dh_recommended_radius',
                    'label' => 'Recommended Radius (miles)',
                    'name' => 'recommended_radius',
                    'type' => 'number',
                    'parent' => $parent_key,
                    'instructions' => 'System-calculated recommended radius. Set by WP-CLI analyze-radius command. Read-only.',
                    'required' => 0,
                    'readonly' => 1,
                    'disabled' => 1,
                    'menu_order' => 101,
                ]);
            }
        }
    }
}

// Initialize
DH_ACF_Fields::init();
