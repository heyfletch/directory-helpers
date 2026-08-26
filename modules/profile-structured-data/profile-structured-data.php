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
            // Profiles can carry many area/state terms (a Featured profile has one per
            // city it buys). The first term is alphabetical, not the business's own city,
            // so both must come from the primary-term helpers.
            $city = DH_Taxonomy_Helpers::get_city_name( $post_id );
            $state_abbr = $this->get_state_abbr( $post_id );
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
                $local_business['telephone'] = $this->format_e164( $phone );
            }

            // Trainer's own website
            $trainer_url = get_field( 'url', $post_id );
            if ( !empty( $trainer_url ) ) {
                $local_business['url'] = $trainer_url;
            }

            // Description
            $description = get_field( 'description', $post_id );
            if ( !empty( $description ) ) {
                $local_business['description'] = wp_strip_all_tags( $description );
            }

            // Geo coordinates
            $latitude = get_field( 'latitude', $post_id );
            $longitude = get_field( 'longitude', $post_id );
            if ( !empty( $latitude ) && !empty( $longitude ) ) {
                $local_business['geo'] = array(
                    '@type' => 'GeoCoordinates',
                    'latitude' => $latitude,
                    'longitude' => $longitude
                );
            }

            // Social profiles
            $same_as = array_values( array_filter( array(
                get_field( 'facebook_url', $post_id ),
                get_field( 'instagram_url', $post_id )
            ) ) );
            if ( !empty( $same_as ) ) {
                $local_business['sameAs'] = $same_as;
            }

            // Awards - states in markup what the badges on the page only show as images
            $awards = $this->get_awards( $post_id );
            if ( !empty( $awards ) ) {
                $local_business['award'] = $awards;
            }

            // --- Publisher / Organization ---
            $publisher = array(
                '@type' => 'Organization',
                'name' => 'Goody Doggy',
                'url' => get_home_url()
            );

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
     * Two-letter state code for the profile's primary state.
     *
     * The state term slug is the postal code ("mo"); the term name is the full
     * state name, which is not what addressRegion wants.
     *
     * @param int $post_id The post ID.
     * @return string Two-letter code, or '' when no state term is set.
     */
    private function get_state_abbr( $post_id ) {
        $state_term = DH_Taxonomy_Helpers::get_primary_state_term( $post_id );
        if ( ! $state_term ) {
            return '';
        }

        if ( ! empty( $state_term->slug ) && strlen( $state_term->slug ) === 2 ) {
            return strtoupper( $state_term->slug );
        }

        return DH_Taxonomy_Helpers::get_state_name( $post_id, 'abbr' );
    }

    /**
     * Normalize a phone number to E.164 (+15551234567).
     *
     * Returns the original string when it does not look like a NANP number, so a
     * non-US or already-formatted international number is left alone.
     *
     * @param string $phone Raw phone value.
     * @return string
     */
    private function format_e164( $phone ) {
        $digits = preg_replace( '/\D+/', '', (string) $phone );

        if ( strlen( $digits ) === 10 ) {
            return '+1' . $digits;
        }

        if ( strlen( $digits ) === 11 && $digits[0] === '1' ) {
            return '+' . $digits;
        }

        return $phone;
    }

    /**
     * Award strings for the badges this profile currently displays.
     *
     * Mirrors the badge module's eligibility so the markup never claims an award
     * the page is not showing.
     *
     * @param int $post_id The post ID.
     * @return array List of award strings.
     */
    private function get_awards( $post_id ) {
        $awards = array();

        if ( ! class_exists( 'DH_Profile_Badges' ) ) {
            return $awards;
        }

        $badges = new DH_Profile_Badges();
        $eligible = $badges->get_eligible_badges( $post_id );
        $year = date( 'Y' );

        foreach ( array( 'city', 'state' ) as $type ) {
            if ( empty( $eligible[ $type ] ) ) {
                continue;
            }

            $data = $badges->get_badge_data( $post_id, $type );
            if ( ! $data || empty( $data['rank_label'] ) || empty( $data['location'] ) ) {
                continue;
            }

            $awards[] = sprintf(
                'Goody Doggy %s %s, %s, %s',
                $data['rank_label'],
                $data['niche'],
                $data['location'],
                $year
            );
        }

        // Third-party awards the trainer supplied (see dh_award_badges).
        $award_ids = get_post_meta( $post_id, 'dh_award_badges', true );
        if ( $award_ids ) {
            foreach ( array_filter( array_map( 'intval', explode( ',', $award_ids ) ) ) as $award_id ) {
                $title = get_the_title( $award_id );
                if ( $title ) {
                    $awards[] = $title;
                }
            }
        }

        return $awards;
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
