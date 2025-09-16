<?php

if ( ! defined( 'WP_CLI' ) ) {
    return;
}

/**
 * WP-CLI command to geocode area terms using Nominatim API
 */
class DH_Geocode_Area_Terms_Command extends WP_CLI_Command {

    /**
     * Geocode area terms using OpenStreetMap Nominatim API
     *
     * ## OPTIONS
     *
     * [--dry-run]
     * : Show what would be updated without making changes
     *
     * [--limit=<number>]
     * : Limit number of terms to process
     *
     * [--start-from=<term_id>]
     * : Start processing from specific term ID
     *
     * [--force]
     * : Update coordinates even if already verified
     *
     * [--source=<source>]
     * : Set the coordinates source (default: nominatim)
     * ---
     * default: nominatim
     * ---
     *
     * ## EXAMPLES
     *
     *     wp dh geocode-areas
     *     wp dh geocode-areas --dry-run
     *     wp dh geocode-areas --limit=50 --start-from=1000
     *     wp dh geocode-areas --force --source=manual
     *
     * @when after_wp_load
     */
    public function __invoke( $args, $assoc_args ) {
        $dry_run = isset( $assoc_args['dry-run'] );
        $limit = isset( $assoc_args['limit'] ) ? intval( $assoc_args['limit'] ) : 0;
        $start_from = isset( $assoc_args['start-from'] ) ? intval( $assoc_args['start-from'] ) : 0;
        $force = isset( $assoc_args['force'] );
        $source = isset( $assoc_args['source'] ) ? $assoc_args['source'] : 'nominatim';

        WP_CLI::line( "Starting geocoding process..." );
        WP_CLI::line( "Dry run: " . ( $dry_run ? 'Yes' : 'No' ) );
        WP_CLI::line( "Force update: " . ( $force ? 'Yes' : 'No' ) );
        WP_CLI::line( "Source: " . $source );

        // Get area terms
        $args_query = array(
            'taxonomy' => 'area',
            'hide_empty' => false,
            'number' => $limit > 0 ? $limit : 0,
            'offset' => $start_from,
            'orderby' => 'term_id',
            'order' => 'ASC'
        );

        $terms = get_terms( $args_query );

        if ( is_wp_error( $terms ) ) {
            WP_CLI::error( "Failed to get area terms: " . $terms->get_error_message() );
        }

        if ( empty( $terms ) ) {
            WP_CLI::success( "No area terms found to process." );
            return;
        }

        WP_CLI::line( sprintf( "Found %d terms to process", count( $terms ) ) );

        $processed = 0;
        $updated = 0;
        $skipped = 0;
        $errors = 0;

        foreach ( $terms as $term ) {
            $processed++;
            
            WP_CLI::line( sprintf( "[%d/%d] Processing: %s (ID: %d)", 
                $processed, count( $terms ), $term->name, $term->term_id ) );

            // Check if already verified (unless force is used)
            if ( ! $force ) {
                $coordinates_updated_date = get_term_meta( $term->term_id, 'coordinates_updated_date', true );
                if ( ! empty( $coordinates_updated_date ) ) {
                    WP_CLI::line( "  → Skipped (already verified on " . $coordinates_updated_date . ")" );
                    $skipped++;
                    continue;
                }
            }

            // Parse city and state from slug
            $location_data = $this->parse_location_from_slug( $term->slug );
            if ( ! $location_data ) {
                WP_CLI::warning( "  → Could not parse location from slug: " . $term->slug );
                $errors++;
                continue;
            }

            WP_CLI::line( sprintf( "  → Parsed: %s, %s", $location_data['city'], $location_data['state'] ) );

            if ( $source === 'nominatim' ) {
                // Get coordinates from Nominatim
                $coordinates = $this->get_coordinates_from_nominatim( $location_data['city'], $location_data['state'] );
                
                if ( ! $coordinates ) {
                    WP_CLI::warning( "  → Failed to get coordinates from Nominatim" );
                    $errors++;
                    continue;
                }

                WP_CLI::line( sprintf( "  → Found coordinates: %s, %s", $coordinates['lat'], $coordinates['lon'] ) );

                if ( ! $dry_run ) {
                    // Update coordinates and tracking meta
                    update_term_meta( $term->term_id, 'latitude', $coordinates['lat'] );
                    update_term_meta( $term->term_id, 'longitude', $coordinates['lon'] );
                    update_term_meta( $term->term_id, 'coordinates_updated_date', current_time( 'Y-m-d H:i:s' ) );
                    update_term_meta( $term->term_id, 'coordinates_source', $source );
                    
                    WP_CLI::success( "  → Updated coordinates" );
                } else {
                    WP_CLI::line( "  → Would update coordinates (dry run)" );
                }

                $updated++;

                // Rate limiting for Nominatim (1 request per second)
                if ( $processed < count( $terms ) ) {
                    WP_CLI::line( "  → Waiting 1 second (rate limiting)..." );
                    sleep( 1 );
                }
            }
        }

        // Summary
        WP_CLI::line( "\n" . str_repeat( "=", 50 ) );
        WP_CLI::line( "GEOCODING SUMMARY" );
        WP_CLI::line( str_repeat( "=", 50 ) );
        WP_CLI::line( sprintf( "Processed: %d", $processed ) );
        WP_CLI::line( sprintf( "Updated: %d", $updated ) );
        WP_CLI::line( sprintf( "Skipped: %d", $skipped ) );
        WP_CLI::line( sprintf( "Errors: %d", $errors ) );

        if ( $dry_run ) {
            WP_CLI::line( "\nThis was a dry run. No changes were made." );
            WP_CLI::line( "Run without --dry-run to apply changes." );
        }

        WP_CLI::success( "Geocoding process completed!" );
    }

    /**
     * Parse city and state from area term slug
     */
    private function parse_location_from_slug( $slug ) {
        // Handle different slug patterns
        // Examples: arlington-tx, abita-springs-la, north-arlington-nj
        
        // Look for state code at the end (2 letters after last dash)
        if ( preg_match( '/^(.+)-([a-z]{2})$/', $slug, $matches ) ) {
            $city_part = $matches[1];
            $state_code = strtoupper( $matches[2] );
            
            // Convert dashes to spaces and title case
            $city = ucwords( str_replace( '-', ' ', $city_part ) );
            
            return array(
                'city' => $city,
                'state' => $state_code
            );
        }
        
        return false;
    }

    /**
     * Get coordinates from Nominatim API
     */
    private function get_coordinates_from_nominatim( $city, $state ) {
        $query = urlencode( $city . ', ' . $state . ', USA' );
        $url = "https://nominatim.openstreetmap.org/search?q={$query}&format=json&limit=1&countrycodes=us";
        
        // Set user agent as required by Nominatim
        $args = array(
            'headers' => array(
                'User-Agent' => 'Directory Helpers WordPress Plugin'
            ),
            'timeout' => 10
        );
        
        $response = wp_remote_get( $url, $args );
        
        if ( is_wp_error( $response ) ) {
            WP_CLI::debug( "API Error: " . $response->get_error_message() );
            return false;
        }
        
        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );
        
        if ( empty( $data ) || ! is_array( $data ) ) {
            WP_CLI::debug( "No results found for: {$city}, {$state}" );
            return false;
        }
        
        $result = $data[0];
        
        if ( ! isset( $result['lat'] ) || ! isset( $result['lon'] ) ) {
            WP_CLI::debug( "Invalid response format" );
            return false;
        }
        
        return array(
            'lat' => floatval( $result['lat'] ),
            'lon' => floatval( $result['lon'] )
        );
    }
}

WP_CLI::add_command( 'dh geocode-areas', 'DH_Geocode_Area_Terms_Command' );
