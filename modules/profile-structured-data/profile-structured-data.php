<?php
/**
 * Profile Structured Data Module
 * 
 * Generates structured data for profile pages based on ACF fields.
 * 
 * @package Directory_Helpers
 * @subpackage Profile_Structured_Data
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Profile Structured Data class.
 */
class DH_Profile_Structured_Data {

    /**
     * Constructor.
     */
    public function __construct() {
        // Register shortcode
        add_shortcode( 'dh_profile_structured_data', array( $this, 'profile_structured_data_shortcode' ) );
        
        // Add structured data to profile pages automatically
        add_action( 'wp_head', array( $this, 'output_structured_data' ) );
    }

    /**
     * Shortcode handler for profile structured data.
     *
     * @param array $atts Shortcode attributes.
     * @return string Empty string as this shortcode doesn't output visible content.
     */
    public function profile_structured_data_shortcode( $atts ) {
        // This shortcode doesn't output visible content
        return '';
    }

    /**
     * Output structured data in the head of profile pages using a @graph.
     */
    public function output_structured_data() {
        if ( is_singular( 'profile' ) ) {
            if ( did_action( 'dh_profile_structured_data_output' ) ) {
                return;
            }

            $post_id = get_the_ID();
            $permalink = get_permalink( $post_id );
            $business_name = get_the_title( $post_id );

            // --- Reusable Address Schema ---
            $address_schema = null;
            $street_address = get_field( 'street', $post_id );
            $city = $this->get_first_term_name( $post_id, 'area' );
            $state_abbr = $this->get_first_term_name( $post_id, 'state' );
            $zip = get_field( 'zip', $post_id );
            if ( !empty( $street_address ) || !empty( $city ) || !empty( $state_abbr ) || !empty( $zip ) ) {
                $address_schema = array(
                    '@type' => 'PostalAddress',
                    'streetAddress' => $street_address,
                    'addressLocality' => $city,
                    'addressRegion' => $state_abbr,
                    'postalCode' => $zip,
                    'addressCountry' => get_field('country', $post_id) ?: 'US'
                );
            }

            // --- Image & Logo Data ---
            $main_image_obj = $this->get_acf_image_object(get_field('main_image', $post_id));
            $logo_obj = $this->get_acf_image_object(get_field('logo', $post_id));

            // --- Main LocalBusiness entity ---
            $local_business = array(
                '@type' => 'LocalBusiness',
                '@id' => $permalink,
                'name' => $business_name,
            );
            if ($address_schema) {
                $local_business['address'] = $address_schema;
            }
            if ($logo_obj) {
                $local_business['logo'] = $logo_obj;
            }
            if ($main_image_obj) {
                $local_business['image'] = $main_image_obj;
            } elseif ($logo_obj) {
                $local_business['image'] = $logo_obj;
            }

            // Phone
            $phone = get_field( 'phone', $post_id );
            if ( !empty( $phone ) ) {
                $local_business['telephone'] = $phone;
            }

            // Rating
            $rating_value = get_field( 'rating_value', $post_id );
            $rating_count = get_field( 'rating_votes_count', $post_id );
            if ( !empty( $rating_value ) && !empty( $rating_count ) ) {
                $local_business['aggregateRating'] = array(
                    '@type' => 'AggregateRating',
                    'ratingValue' => $rating_value,
                    'reviewCount' => $rating_count,
                    'bestRating' => '5',
                    'worstRating' => '1'
                );
            }

            // --- Publisher / Organization ---
            $publisher = array(
                '@type' => 'Organization',
                'name' => 'Goody Doggy',
                'url' => get_home_url()
            );
            if ($logo_obj) {
                $publisher['logo'] = $logo_obj;
            }
            if ($address_schema) {
                $publisher['address'] = $address_schema;
            }

            // --- ProfilePage entity ---
            $profile_page = array(
                '@type' => 'ProfilePage',
                '@id' => $permalink . '#profilepage',
                'mainEntity' => array('@id' => $permalink),
                'publisher' => $publisher
            );

            $graph = array($local_business, $profile_page);

            // --- Service List ---
            $program_types = get_field( 'program_types', $post_id );
            if ( !empty( $program_types ) ) {
                $service_items = array();
                $position = 1;
                foreach ( $program_types as $service ) {
                     if (is_array($service) && isset($service['label'])) {
                        $service_name = $service['label'];
                    } else {
                        $service_name = $service;
                    }

                    $service_items[] = array(
                        '@type' => 'ListItem',
                        'position' => $position++,
                        'item' => array(
                            '@type' => 'Service',
                            'name' => $service_name,
                            'provider' => array('@id' => $permalink)
                        )
                    );
                }

                if (!empty($service_items)) {
                    $service_list = array(
                        '@type' => 'ItemList',
                        'name' => 'Services offered by ' . $business_name,
                        'numberOfItems' => count( $service_items ),
                        'itemListElement' => $service_items
                    );
                    $graph[] = $service_list;
                }
            }

            $schema = array(
                '@context' => 'https://schema.org',
                '@graph' => $graph
            );

            $json = wp_json_encode( $schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
            echo "\n<!-- Profile Structured Data by DH -->\n<script type=\"application/ld+json\">\n{$json}\n</script>\n";

            do_action( 'dh_profile_structured_data_output' );
        }
    }

    /**
     * Get the name of the first term assigned to a post from a specific taxonomy.
     *
     * @param int $post_id The post ID.
     * @param string $taxonomy The taxonomy slug.
     * @return string The term name.
     */
    private function get_first_term_name( $post_id, $taxonomy ) {
        $terms = get_the_terms( $post_id, $taxonomy );
        
        if ( $terms && ! is_wp_error( $terms ) ) {
            return $terms[0]->name;
        }
        
        return '';
    }

    /**
     * Get the description of the first term assigned to a post from a specific taxonomy.
     *
     * @param int $post_id The post ID.
     * @param string $taxonomy The taxonomy slug.
     * @return string The term description.
     */
    private function get_first_term_description( $post_id, $taxonomy ) {
        $terms = get_the_terms( $post_id, $taxonomy );
        
        if ( !empty( $terms ) && !is_wp_error( $terms ) ) {
            return $terms[0]->description;
        }
        
        return '';
    }

    /**
     * Get an ImageObject schema from an ACF image field.
     *
     * @param mixed $field_value The value from get_field().
     * @return array|null The ImageObject schema or null.
     */
    private function get_acf_image_object($field_value) {
        if (empty($field_value)) {
            return null;
        }

        $image_data = [];

        if (is_array($field_value) && isset($field_value['url'])) {
            // ACF field returns an array
            $image_data['url'] = $field_value['url'];
            $image_data['width'] = $field_value['width'];
            $image_data['height'] = $field_value['height'];
        } elseif (is_numeric($field_value)) {
            // ACF field returns an ID
            $image_src = wp_get_attachment_image_src($field_value, 'full');
            if ($image_src) {
                $image_data['url'] = $image_src[0];
                $image_data['width'] = $image_src[1];
                $image_data['height'] = $image_src[2];
            }
        } elseif (is_string($field_value) && filter_var($field_value, FILTER_VALIDATE_URL)) {
            // ACF field returns a URL
            $image_data['url'] = $field_value;
        }

        if (empty($image_data['url'])) {
            return null;
        }

        $image_object = ['@type' => 'ImageObject'];
        $image_object['url'] = $image_data['url'];
        if (isset($image_data['width'])) {
            $image_object['width'] = $image_data['width'];
        }
        if (isset($image_data['height'])) {
            $image_object['height'] = $image_data['height'];
        }

        return $image_object;
    }
}

// Initialize the module
new DH_Profile_Structured_Data();
