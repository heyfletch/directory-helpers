<?php

if ( ! defined( 'WP_CLI' ) ) {
    return;
}

/**
 * WP-CLI command to analyze area terms and calculate recommended radius
 */
class DH_Analyze_Radius_Command extends WP_CLI_Command {

    /**
     * Analyze area terms for published city-listing pages to calculate recommended radius
     *
     * ## OPTIONS
     *
     * [--dry-run]
     * : Show analysis without updating term meta
     *
     * <niche>
     * : Niche taxonomy slug (required, e.g., dog-trainer)
     *
     * [--min-profiles=<number>]
     * : Target minimum number of profiles (default: from settings or 10)
     *
     * [--max-radius=<number>]
     * : Maximum radius to test in miles (default: 30)
     *
     * [--limit=<number>]
     * : Limit analysis to first N areas (default: all)
     *
     * [--update-meta]
     * : Update recommended_radius term meta for areas needing proximity
     *
     * ## EXAMPLES
     *
     *     wp directory-helpers analyze-radius dog-trainer --dry-run --limit=10
     *     wp directory-helpers analyze-radius dog-trainer --update-meta
     *     wp directory-helpers analyze-radius dog-trainer --min-profiles=15 --limit=50 --update-meta
     *
     * @when after_wp_load
     */
    public function __invoke( $args, $assoc_args ) {
        WP_CLI::line( "Starting..." );
        
        // Niche is now required
        if ( empty( $args[0] ) ) {
            WP_CLI::error( "Niche slug is required. Example: wp directory-helpers analyze-radius dog-trainer" );
            return;
        }
        
        $niche_slug = sanitize_title( $args[0] );
        $niche_term = get_term_by( 'slug', $niche_slug, 'niche' );
        
        if ( ! $niche_term ) {
            WP_CLI::error( "Niche term '{$niche_slug}' not found in 'niche' taxonomy." );
            return;
        }
        
        $niche_id = $niche_term->term_id;
        
        $dry_run = isset( $assoc_args['dry-run'] );
        $update_meta = isset( $assoc_args['update-meta'] );
        
        // Get settings
        $options = get_option('directory_helpers_options', []);
        $min_profiles = isset( $assoc_args['min-profiles'] ) 
            ? intval( $assoc_args['min-profiles'] )
            : ( isset( $options['min_profiles_threshold'] ) ? intval( $options['min_profiles_threshold'] ) : 10 );
        $max_radius = isset( $assoc_args['max-radius'] ) ? intval( $assoc_args['max-radius'] ) : 30;
        $limit = isset( $assoc_args['limit'] ) ? intval( $assoc_args['limit'] ) : null;

        WP_CLI::line( "=== Area Radius Analysis ===" );
        WP_CLI::line( "Niche: {$niche_term->name} (slug: {$niche_slug})" );
        WP_CLI::line( "Target minimum profiles: $min_profiles" );
        WP_CLI::line( "Maximum radius to test: $max_radius miles" );
        if ( $limit ) {
            WP_CLI::line( "Limit: First $limit areas only" );
        }
        WP_CLI::line( "Dry run: " . ( $dry_run ? 'Yes' : 'No' ) );
        WP_CLI::line( "Update meta: " . ( $update_meta ? 'Yes' : 'No' ) );
        WP_CLI::line( "" );

        global $wpdb;

        WP_CLI::line( "Fetching city-listing pages..." );
        
        // Get published city-listing pages that have this niche term  
        $city_listings = get_posts([
            'post_type' => 'city-listing',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'tax_query' => [
                [
                    'taxonomy' => 'niche',
                    'field' => 'term_id',
                    'terms' => $niche_id,
                ]
            ]
        ]);

        if ( empty( $city_listings ) ) {
            WP_CLI::error( "No published city-listing pages found with niche '{$niche_slug}'." );
            return;
        }

        WP_CLI::line( "Found " . count( $city_listings ) . " published city-listing pages with this niche." );
        WP_CLI::line( "" );

        // Extract area terms from these city-listing pages
        $area_term_ids = [];
        foreach ( $city_listings as $post ) {
            $area_terms = get_the_terms( $post->ID, 'area' );
            if ( ! empty( $area_terms ) && ! is_wp_error( $area_terms ) ) {
                foreach ( $area_terms as $term ) {
                    $area_term_ids[] = $term->term_id;
                }
            }
        }

        $area_term_ids = array_unique( $area_term_ids );

        if ( empty( $area_term_ids ) ) {
            WP_CLI::error( "No area terms found on these city-listing pages." );
            return;
        }

        // Get the actual term objects
        $terms = [];
        foreach ( $area_term_ids as $term_id ) {
            $term = get_term( $term_id, 'area' );
            if ( $term && ! is_wp_error( $term ) ) {
                $terms[] = $term;
            }
        }

        // Apply limit if specified
        if ( $limit && count( $terms ) > $limit ) {
            $terms = array_slice( $terms, 0, $limit );
            WP_CLI::line( "Limiting to first $limit areas." );
        }

        WP_CLI::line( "Analyzing " . count( $terms ) . " areas..." );
        WP_CLI::line( "" );
        
        $summary = [
            'sufficient' => 0,
            'needs_proximity' => 0,
            'no_profiles' => 0,
            'no_coordinates' => 0,
        ];

        $results = [];
        $count = 0;
        $total = count( $terms );

        foreach ( $terms as $term ) {
            $count++;
            if ( $count % 50 == 0 || $count == 1 ) {
                WP_CLI::line( "Processed {$count}/{$total} areas..." );
            }

            $lat = get_term_meta( $term->term_id, 'latitude', true );
            $lng = get_term_meta( $term->term_id, 'longitude', true );

            if ( ! $lat || ! $lng ) {
                $summary['no_coordinates']++;
                continue;
            }

            // Check direct area-tagged profiles with this niche
            $area_count_query = new WP_Query([
                'post_type' => 'profile',
                'post_status' => 'publish',
                'posts_per_page' => 1,
                'fields' => 'ids',
                'tax_query' => [
                    'relation' => 'AND',
                    [
                        'taxonomy' => 'area',
                        'field' => 'term_id',
                        'terms' => $term->term_id,
                    ],
                    [
                        'taxonomy' => 'niche',
                        'field' => 'term_id',
                        'terms' => $niche_id,
                    ]
                ],
            ]);

            $area_count = $area_count_query->found_posts;

            // If sufficient, no proximity needed - skip expensive proximity testing
            if ( $area_count >= $min_profiles ) {
                $summary['sufficient']++;
                $results[] = [
                    'term_id' => $term->term_id,
                    'name' => $term->name,
                    'area_profiles' => $area_count,
                    'status' => 'sufficient',
                    'recommended_radius' => 0,
                ];
                continue;
            }

            // Fast incremental radius expansion starting at 2 miles
            $radius = 2;
            $recommended_radius = null;
            $radius_increment = 3; // Start with 3-mile increments

            while ( $radius <= $max_radius ) {
                // Use simple bounding box approximation (MUCH faster than Haversine)
                $lat_offset = $radius / 69.0; // 1 degree latitude ≈ 69 miles
                $lng_offset = $radius / (69.0 * cos(deg2rad($lat))); // Adjust for latitude
                $lat_min = $lat - $lat_offset;
                $lat_max = $lat + $lat_offset;
                $lng_min = $lng - $lng_offset;
                $lng_max = $lng + $lng_offset;

                // Fast bounding box count (no Haversine calculation)
                $sql = $wpdb->prepare( "
                    SELECT COUNT(DISTINCT p.ID) as total
                    FROM {$wpdb->posts} p
                    INNER JOIN {$wpdb->postmeta} lat ON p.ID = lat.post_id AND lat.meta_key = 'latitude'
                    INNER JOIN {$wpdb->postmeta} lng ON p.ID = lng.post_id AND lng.meta_key = 'longitude'
                    INNER JOIN {$wpdb->term_relationships} tr_niche ON p.ID = tr_niche.object_id
                    INNER JOIN {$wpdb->term_taxonomy} tt_niche ON tr_niche.term_taxonomy_id = tt_niche.term_taxonomy_id 
                        AND tt_niche.taxonomy = 'niche' AND tt_niche.term_id = %d
                    WHERE p.post_type = 'profile'
                    AND p.post_status = 'publish'
                    AND CAST(lat.meta_value AS DECIMAL(10,6)) BETWEEN %f AND %f
                    AND CAST(lng.meta_value AS DECIMAL(10,6)) BETWEEN %f AND %f
                ", $niche_id, $lat_min, $lat_max, $lng_min, $lng_max );

                $proximity_count = intval( $wpdb->get_var( $sql ) );

                // Combine area-tagged + proximity
                $estimated_total = $area_count + $proximity_count;

                if ( $estimated_total >= $min_profiles ) {
                    $recommended_radius = $radius;
                    break;
                }

                // Increase radius and try again
                $radius += $radius_increment;
                
                // Use larger increments as we go higher
                if ( $radius > 15 ) {
                    $radius_increment = 5;
                }
            }

            if ( $recommended_radius ) {
                $summary['needs_proximity']++;
                $results[] = [
                    'term_id' => $term->term_id,
                    'name' => $term->name,
                    'area_profiles' => $area_count,
                    'status' => 'needs_proximity',
                    'recommended_radius' => $recommended_radius,
                ];

                // Update meta if requested
                if ( $update_meta && ! $dry_run ) {
                    update_term_meta( $term->term_id, 'recommended_radius', $recommended_radius );
                }
            } else {
                // Even max radius doesn't reach threshold
                $summary['no_profiles']++;
                $results[] = [
                    'term_id' => $term->term_id,
                    'name' => $term->name,
                    'area_profiles' => $area_count,
                    'status' => 'insufficient',
                    'recommended_radius' => $max_radius,
                ];

                // Still set max radius as recommendation
                if ( $update_meta && ! $dry_run ) {
                    update_term_meta( $term->term_id, 'recommended_radius', $max_radius );
                }
            }
        }
        
        WP_CLI::line( "Processed {$total}/{$total} areas." );

        // Write results to log file
        $upload_dir = wp_upload_dir();
        $log_dir = $upload_dir['basedir'] . '/radius-analysis';
        
        // Create directory if it doesn't exist
        if ( ! file_exists( $log_dir ) ) {
            wp_mkdir_p( $log_dir );
        }
        
        $log_file = $log_dir . '/radius-analysis-' . $niche_slug . '-' . date('Y-m-d-His') . '.log';
        $log_content = [];
        
        $log_content[] = "=== Radius Analysis for {$niche_term->name} ===";
        $log_content[] = "Date: " . date('Y-m-d H:i:s');
        $log_content[] = "Minimum profiles threshold: {$min_profiles}";
        $log_content[] = "Maximum radius tested: {$max_radius} miles";
        $log_content[] = "";
        $log_content[] = "=== Summary ===";
        $log_content[] = sprintf(
            "Total areas: %d | Sufficient: %d | Needs proximity: %d | Insufficient: %d | No coords: %d",
            count( $terms ),
            $summary['sufficient'],
            $summary['needs_proximity'],
            $summary['no_profiles'],
            $summary['no_coordinates']
        );
        $log_content[] = "";
        
        if ( ! empty( $results ) ) {
            $log_content[] = "=== Areas Needing Proximity Search ===";
            $log_content[] = sprintf( "%-10s %-30s %-10s %-15s %-10s", 'Term ID', 'Name', 'Direct', 'Status', 'Radius' );
            $log_content[] = str_repeat( '-', 80 );
            
            foreach ( $results as $result ) {
                if ( $result['status'] === 'sufficient' ) {
                    continue;
                }
                $log_content[] = sprintf(
                    "%-10s %-30s %-10s %-15s %-10s",
                    $result['term_id'],
                    substr( $result['name'], 0, 30 ),
                    $result['area_profiles'],
                    $result['status'],
                    $result['recommended_radius'] . ' mi'
                );
            }
        }
        
        file_put_contents( $log_file, implode( "\n", $log_content ) );
        
        // Output summary
        WP_CLI::line( "" );
        WP_CLI::success( sprintf( 
            "Total areas: %d | Sufficient: %d | Needs proximity: %d | Insufficient: %d | No coords: %d",
            count( $terms ),
            $summary['sufficient'],
            $summary['needs_proximity'],
            $summary['no_profiles'],
            $summary['no_coordinates']
        ) );
        
        WP_CLI::line( "" );
        WP_CLI::line( "Results saved to: {$log_file}" );
        
        if ( $dry_run ) {
            WP_CLI::line( "To update term meta with recommended radius values, run with --update-meta flag." );
        } else if ( $update_meta ) {
            WP_CLI::success( "Term meta updated successfully." );
        }
    }
}
