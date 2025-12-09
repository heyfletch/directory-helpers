<?php

if ( ! defined( 'WP_CLI' ) ) {
    return;
}

/**
 * WP-CLI command to update rankings by saving one profile per city
 */
class DH_Update_Rankings_Command extends WP_CLI_Command {

    /**
     * Update rankings by saving one profile per city to trigger ranking recalculation
     *
     * ## OPTIONS
     *
     * [--dry-run]
     * : Show what would be done without making changes
     *
     * <niche>
     * : Niche taxonomy slug (required, e.g., dog-trainer)
     *
     * [--batch-size=<number>]
     * : Number of cities to process per batch (default: 10)
     *
     * [--delay=<number>]
     * : Seconds to wait between profile saves (default: 5)
     *
     * [--resume]
     * : Resume from where a previous interrupted run left off
     *
     * [--batch-pause=<number>]
     * : Seconds to wait between batches (default: 2)
     *
     * ## EXAMPLES
     *
     *     wp directory-helpers update-rankings dog-trainer --dry-run
     *     wp directory-helpers update-rankings dog-trainer --batch-size=5 --delay=3
     *     wp directory-helpers update-rankings dog-trainer --resume
     *
     * @when after_wp_load
     */
    public function __invoke( $args, $assoc_args ) {
        // Disable output buffering for real-time progress
        while ( ob_get_level() ) {
            ob_end_flush();
        }

        WP_CLI::line( "Starting rankings update..." );

        // Niche is required
        if ( empty( $args[0] ) ) {
            WP_CLI::error( "Niche slug is required. Example: wp directory-helpers update-rankings dog-trainer" );
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
        $batch_size = isset( $assoc_args['batch-size'] ) ? intval( $assoc_args['batch-size'] ) : 10;
        $delay = isset( $assoc_args['delay'] ) ? floatval( $assoc_args['delay'] ) : 5;
        $batch_pause = isset( $assoc_args['batch-pause'] ) ? intval( $assoc_args['batch-pause'] ) : 2;
        $resume = isset( $assoc_args['resume'] );
        $force = isset( $assoc_args['force'] );

        // Progress tracking file
        $progress_file = WP_CONTENT_DIR . '/uploads/rankings-update-progress-' . $niche_slug . '.json';

        WP_CLI::line( "=== Rankings Update ===" );
        WP_CLI::line( "Niche: {$niche_term->name} (slug: {$niche_slug})" );
        WP_CLI::line( "Batch size: {$batch_size} cities" );
        WP_CLI::line( "Delay between saves: {$delay} seconds" );
        WP_CLI::line( "Batch pause: {$batch_pause} seconds" );
        WP_CLI::line( "Dry run: " . ( $dry_run ? 'Yes' : 'No' ) );
        if ( $resume ) {
            WP_CLI::line( "Resume mode: Yes" );
        }
        WP_CLI::line( "" );

        // Check for existing progress
        $progress = [];
        if ( file_exists( $progress_file ) && ( $resume || $force ) ) {
            $progress_data = json_decode( file_get_contents( $progress_file ), true );
            if ( $progress_data ) {
                $progress = $progress_data;
                WP_CLI::line( "Resuming from previous run..." );
                WP_CLI::line( "Previously completed: " . count( $progress['completed_cities'] ?? [] ) . " cities" );
            }
        } elseif ( file_exists( $progress_file ) && ! $force ) {
            WP_CLI::warning( "Progress file exists. Use --resume to continue or --force to start fresh." );
            return;
        }

        // Step 1: Find cities with profiles
        WP_CLI::line( "Finding cities with profiles..." );

        global $wpdb;

        // Get all unique cities that have profiles with this niche
        $cities_query = $wpdb->prepare( "
            SELECT DISTINCT
                pm.meta_value as city_name,
                COUNT(p.ID) as profile_count,
                GROUP_CONCAT(DISTINCT p.ID ORDER BY p.ID SEPARATOR ',') as profile_ids
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = 'city'
            INNER JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
            INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
                AND tt.taxonomy = 'niche' AND tt.term_id = %d
            WHERE p.post_type = 'profile'
            AND p.post_status = 'publish'
            AND pm.meta_value != ''
            AND pm.meta_value IS NOT NULL
            GROUP BY pm.meta_value
            ORDER BY pm.meta_value
        ", $niche_id );

        $cities = $wpdb->get_results( $cities_query );

        if ( empty( $cities ) ) {
            WP_CLI::error( "No cities found with profiles for niche '{$niche_slug}'." );
            return;
        }

        // Filter out completed cities if resuming
        $completed_cities = $progress['completed_cities'] ?? [];
        if ( ! empty( $completed_cities ) && $resume ) {
            $cities = array_filter( $cities, function( $city ) use ( $completed_cities ) {
                return ! in_array( $city->city_name, $completed_cities );
            } );
        }

        $total_cities = count( $cities );
        WP_CLI::line( "Found {$total_cities} cities to process." );

        if ( $dry_run ) {
            WP_CLI::line( "" );
            WP_CLI::line( "=== DRY RUN: Cities that would be processed ===" );
            foreach ( $cities as $index => $city ) {
                $profile_ids = explode( ',', $city->profile_ids );
                $sample_profile_id = $profile_ids[0];
                WP_CLI::line( sprintf(
                    "%d. %s (%d profiles) - Would save profile ID: %d",
                    $index + 1,
                    $city->city_name,
                    $city->profile_count,
                    $sample_profile_id
                ) );
            }
            WP_CLI::line( "" );
            WP_CLI::success( "Dry run complete. Found {$total_cities} cities ready for ranking updates." );
            return;
        }

        // Step 2: Process cities in batches
        $processed = 0;
        $errors = 0;
        $start_time = microtime( true );

        $batches = array_chunk( $cities, $batch_size );

        WP_CLI::line( "" );
        WP_CLI::line( "Starting processing..." );

        foreach ( $batches as $batch_index => $batch ) {
            $batch_start_time = microtime( true );

            WP_CLI::line( "" );
            WP_CLI::line( "=== Processing Batch " . ($batch_index + 1) . " of " . count( $batches ) . " ===" );

            foreach ( $batch as $city_index => $city ) {
                $city_start_time = microtime( true );
                $profile_ids = explode( ',', $city->profile_ids );
                $profile_id_to_save = $profile_ids[0]; // Use first profile ID

                WP_CLI::line( sprintf(
                    "[%d/%d] Processing %s (%d profiles) - Saving profile ID: %d",
                    $processed + $city_index + 1,
                    $total_cities,
                    $city->city_name,
                    $city->profile_count,
                    $profile_id_to_save
                ) );

                try {
                    // Verify profile exists and can be saved
                    $profile = get_post( $profile_id_to_save );
                    if ( ! $profile || $profile->post_type !== 'profile' ) {
                        throw new Exception( "Profile ID {$profile_id_to_save} not found or invalid" );
                    }

                    // Save the profile (triggers ACF ranking hooks)
                    wp_update_post( [
                        'ID' => $profile_id_to_save,
                        'post_modified' => current_time( 'mysql' ),
                        'post_modified_gmt' => current_time( 'mysql', 1 )
                    ] );

                    $city_time = round( microtime( true ) - $city_start_time, 2 );
                    WP_CLI::line( "  → Completed in {$city_time}s" );

                    // Update progress
                    $progress['completed_cities'][] = $city->city_name;
                    $progress['last_updated'] = current_time( 'mysql' );

                } catch ( Exception $e ) {
                    $errors++;
                    WP_CLI::warning( "  → Failed to save profile {$profile_id_to_save}: " . $e->getMessage() );
                }

                // Delay between saves (except for last item in batch)
                if ( $city_index < count( $batch ) - 1 ) {
                    WP_CLI::line( "  Waiting {$delay} seconds..." );
                    if ( is_float( $delay ) ) {
                        usleep( $delay * 1000000 ); // Convert seconds to microseconds
                    } else {
                        sleep( $delay );
                    }
                }
            }

            $batch_time = round( microtime( true ) - $batch_start_time, 2 );
            $processed += count( $batch );

            // Estimate total time remaining
            $elapsed = microtime( true ) - $start_time;
            $cities_per_second = $processed / $elapsed;
            $remaining_cities = $total_cities - $processed;
            $estimated_remaining = $remaining_cities / $cities_per_second;

            WP_CLI::line( "" );
            WP_CLI::line( "Batch completed in {$batch_time}s" );
            WP_CLI::line( "Progress: {$processed}/{$total_cities} cities ({$errors} errors)" );
            WP_CLI::line( sprintf( "Estimated time remaining: %.1f minutes", $estimated_remaining / 60 ) );

            // Save progress between batches
            if ( false === file_put_contents( $progress_file, json_encode( $progress, JSON_PRETTY_PRINT ) ) ) {
                WP_CLI::warning( "Could not save progress file to: {$progress_file}" );
            }

            // Longer pause between batches (except for last batch)
            if ( $batch_index < count( $batches ) - 1 ) {
                WP_CLI::line( "Pausing {$batch_pause} seconds before next batch..." );
                if ( is_float( $batch_pause ) ) {
                    usleep( $batch_pause * 1000000 ); // Convert seconds to microseconds
                } else {
                    sleep( $batch_pause );
                }
            }
        }

        $total_time = round( microtime( true ) - $start_time, 2 );

        WP_CLI::line( "" );
        WP_CLI::success( sprintf(
            "Rankings update completed! Processed %d cities in %.2f seconds (%d errors)",
            $total_cities,
            $total_time,
            $errors
        ) );

        // Clean up progress file on successful completion
        if ( file_exists( $progress_file ) ) {
            unlink( $progress_file );
        }

        WP_CLI::line( "" );
        WP_CLI::line( "To verify rankings were updated, check city_rank and state_rank ACF fields on profiles." );
    }
}
