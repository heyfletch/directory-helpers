<?php

if ( ! defined( 'WP_CLI' ) ) {
    return;
}

/**
 * WP-CLI command to analyze area terms and calculate recommended radius
 */
class DH_Analyze_Radius_Command extends WP_CLI_Command {

    /**
     * Analyze all area terms to calculate recommended radius based on profile density
     *
     * ## OPTIONS
     *
     * [--dry-run]
     * : Show analysis without updating term meta
     *
     * [--niche=<term_id>]
     * : Limit analysis to specific niche (default: all niches)
     *
     * [--min-profiles=<number>]
     * : Target minimum number of profiles (default: from settings or 10)
     *
     * [--max-radius=<number>]
     * : Maximum radius to test in miles (default: 30)
     *
     * [--update-meta]
     * : Update recommended_radius term meta for areas needing proximity
     *
     * ## EXAMPLES
     *
     *     wp directory-helpers analyze-radius
     *     wp directory-helpers analyze-radius --dry-run
     *     wp directory-helpers analyze-radius --niche=123 --min-profiles=15
     *     wp directory-helpers analyze-radius --update-meta
     *
     * @when after_wp_load
     */
    public function __invoke( $args, $assoc_args ) {
        $dry_run = isset( $assoc_args['dry-run'] );
        $update_meta = isset( $assoc_args['update-meta'] );
        $niche_id = isset( $assoc_args['niche'] ) ? intval( $assoc_args['niche'] ) : null;
        
        // Get settings
        $options = get_option('directory_helpers_options', []);
        $min_profiles = isset( $assoc_args['min-profiles'] ) 
            ? intval( $assoc_args['min-profiles'] )
            : ( isset( $options['min_profiles_threshold'] ) ? intval( $options['min_profiles_threshold'] ) : 10 );
        $max_radius = isset( $assoc_args['max-radius'] ) ? intval( $assoc_args['max-radius'] ) : 30;

        WP_CLI::line( "=== Area Radius Analysis ===" );
        WP_CLI::line( "Target minimum profiles: $min_profiles" );
        WP_CLI::line( "Maximum radius to test: $max_radius miles" );
        WP_CLI::line( "Dry run: " . ( $dry_run ? 'Yes' : 'No' ) );
        WP_CLI::line( "Update meta: " . ( $update_meta ? 'Yes' : 'No' ) );
        WP_CLI::line( "" );

        global $wpdb;

        // Get all area terms with coordinates
        $terms = get_terms([
            'taxonomy' => 'area',
            'hide_empty' => false,
        ]);

        if ( empty( $terms ) || is_wp_error( $terms ) ) {
            WP_CLI::error( "No area terms found." );
            return;
        }

        $progress = \WP_CLI\Utils\make_progress_bar( 'Analyzing areas', count( $terms ) );
        
        $summary = [
            'sufficient' => 0,
            'needs_proximity' => 0,
            'no_profiles' => 0,
            'no_coordinates' => 0,
        ];

        $results = [];

        foreach ( $terms as $term ) {
            $lat = get_term_meta( $term->term_id, 'latitude', true );
            $lng = get_term_meta( $term->term_id, 'longitude', true );

            if ( ! $lat || ! $lng ) {
                $summary['no_coordinates']++;
                $progress->tick();
                continue;
            }

            // Build tax query
            $tax_query = [
                'relation' => 'AND',
                [
                    'taxonomy' => 'area',
                    'field' => 'term_id',
                    'terms' => $term->term_id,
                ]
            ];

            if ( $niche_id ) {
                $tax_query[] = [
                    'taxonomy' => 'niche',
                    'field' => 'term_id',
                    'terms' => $niche_id,
                ];
            }

            // Check direct area-tagged profiles
            $area_count_query = new WP_Query([
                'post_type' => 'profile',
                'post_status' => 'publish',
                'posts_per_page' => 1,
                'fields' => 'ids',
                'tax_query' => $tax_query,
            ]);

            $area_count = $area_count_query->found_posts;

            // If sufficient, no proximity needed
            if ( $area_count >= $min_profiles ) {
                $summary['sufficient']++;
                $results[] = [
                    'term_id' => $term->term_id,
                    'name' => $term->name,
                    'area_profiles' => $area_count,
                    'status' => 'sufficient',
                    'recommended_radius' => 0,
                ];
                $progress->tick();
                continue;
            }

            // Test increasing radii
            $test_radii = [2, 5, 10, 15, 20, 25, 30];
            $recommended_radius = null;

            foreach ( $test_radii as $radius ) {
                if ( $radius > $max_radius ) {
                    break;
                }

                // Query proximity profiles
                $niche_join = $niche_id ? " INNER JOIN {$wpdb->term_relationships} tr_niche ON p.ID = tr_niche.object_id
                    INNER JOIN {$wpdb->term_taxonomy} tt_niche ON tr_niche.term_taxonomy_id = tt_niche.term_taxonomy_id 
                    AND tt_niche.taxonomy = 'niche' AND tt_niche.term_id = " . intval($niche_id) : '';

                $sql = $wpdb->prepare( "
                    SELECT COUNT(DISTINCT p.ID) as total
                    FROM {$wpdb->posts} p
                    INNER JOIN {$wpdb->postmeta} lat ON p.ID = lat.post_id AND lat.meta_key = 'latitude'
                    INNER JOIN {$wpdb->postmeta} lng ON p.ID = lng.post_id AND lng.meta_key = 'longitude'
                    {$niche_join}
                    WHERE p.post_type = 'profile'
                    AND p.post_status = 'publish'
                    HAVING ( 3959 * acos(
                        cos( radians(%f) ) *
                        cos( radians( lat.meta_value ) ) *
                        cos( radians( lng.meta_value ) - radians(%f) ) +
                        sin( radians(%f) ) *
                        sin( radians( lat.meta_value ) )
                    ) ) < %d
                ", $lat, $lng, $lat, $radius );

                $proximity_result = $wpdb->get_var( $sql );
                $proximity_count = $proximity_result ? intval( $proximity_result ) : 0;

                // Combine area-tagged + proximity (dedupe will happen in actual query)
                // For analysis, estimate combined count
                $estimated_total = $area_count + $proximity_count;

                if ( $estimated_total >= $min_profiles ) {
                    $recommended_radius = $radius;
                    break;
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

            $progress->tick();
        }

        $progress->finish();

        // Output summary
        WP_CLI::line( "" );
        WP_CLI::line( "=== Analysis Summary ===" );
        WP_CLI::success( sprintf( 
            "Total areas: %d | Sufficient: %d | Needs proximity: %d | Insufficient: %d | No coords: %d",
            count( $terms ),
            $summary['sufficient'],
            $summary['needs_proximity'],
            $summary['no_profiles'],
            $summary['no_coordinates']
        ) );

        // Output detailed results for areas needing attention
        if ( ! empty( $results ) ) {
            WP_CLI::line( "" );
            WP_CLI::line( "=== Areas Needing Proximity Search ===" );
            
            $needs_proximity = array_filter( $results, function( $r ) { 
                return $r['status'] === 'needs_proximity' || $r['status'] === 'insufficient'; 
            });

            if ( ! empty( $needs_proximity ) ) {
                $table_data = [];
                foreach ( $needs_proximity as $result ) {
                    $table_data[] = [
                        'Term ID' => $result['term_id'],
                        'Name' => $result['name'],
                        'Direct' => $result['area_profiles'],
                        'Status' => $result['status'],
                        'Radius' => $result['recommended_radius'] . ' mi',
                    ];
                }

                \WP_CLI\Utils\format_items( 'table', $table_data, ['Term ID', 'Name', 'Direct', 'Status', 'Radius'] );
            }

            if ( $update_meta && ! $dry_run ) {
                WP_CLI::success( "Term meta 'recommended_radius' has been updated for areas needing proximity search." );
            } elseif ( $dry_run || ! $update_meta ) {
                WP_CLI::line( "" );
                WP_CLI::line( "To update term meta with recommended radius values, run with --update-meta flag." );
            }
        }
    }
}
