<?php

if ( ! defined( 'WP_CLI' ) ) {
    return;
}

if ( ! class_exists( 'DH_Migrate_Years_Experience_Command' ) ) {
    /**
     * WP-CLI command to migrate years_experience values on profile CPTs.
     */
    class DH_Migrate_Years_Experience_Command extends WP_CLI_Command {
        /**
         * Adjust years_experience values once across all profiles.
         *
         * ## OPTIONS
         *
         * [--dry-run]
         * : Show what would be changed without saving.
         *
         * [--force]
         * : Run even if the migration has already been marked complete.
         *
         * ## EXAMPLES
         *
         *     wp directory-helpers migrate-years-experience --dry-run
         *     wp directory-helpers migrate-years-experience
         *     wp directory-helpers migrate-years-experience --force
         *
         * @when after_wp_load
         */
        public function __invoke( $args, $assoc_args ) {
            while ( ob_get_level() ) {
                ob_end_flush();
            }

            $dry_run = isset( $assoc_args['dry-run'] );
            $force = isset( $assoc_args['force'] );
            $option_key = 'dh_migrate_years_experience_done';

            if ( get_option( $option_key ) && ! $force ) {
                WP_CLI::error( 'Migration already marked complete. Use --force to run again.' );
                return;
            }

            $query = new WP_Query([
                'post_type' => 'profile',
                'post_status' => 'any',
                'posts_per_page' => -1,
                'fields' => 'ids',
                'meta_query' => [
                    [
                        'key' => 'years_experience',
                        'compare' => 'EXISTS',
                    ],
                ],
            ]);

            $profile_ids = $query->posts;
            $total = count( $profile_ids );

            WP_CLI::line( '=== Years Experience Migration ===' );
            WP_CLI::line( 'Profiles with years_experience: ' . $total );
            WP_CLI::line( 'Dry run: ' . ( $dry_run ? 'Yes' : 'No' ) );
            WP_CLI::line( '' );

            $updated = 0;
            $cleared = 0;
            $skipped = 0;

            foreach ( $profile_ids as $profile_id ) {
                $raw_value = get_post_meta( $profile_id, 'years_experience', true );

                if ( $raw_value === '' || $raw_value === null ) {
                    $skipped++;
                    continue;
                }

                if ( ! is_numeric( $raw_value ) ) {
                    $skipped++;
                    WP_CLI::warning( "Profile {$profile_id}: Non-numeric years_experience '{$raw_value}', skipped." );
                    continue;
                }

                $value = (int) $raw_value;

                if ( $value <= 0 ) {
                    if ( ! $dry_run ) {
                        delete_post_meta( $profile_id, 'years_experience' );
                    }
                    $cleared++;
                } else {
                    $new_value = $value + 1;
                    if ( ! $dry_run ) {
                        update_post_meta( $profile_id, 'years_experience', $new_value );
                    }
                    $updated++;
                }
            }

            WP_CLI::line( '' );
            WP_CLI::line( '=== Summary ===' );
            WP_CLI::line( "Updated: {$updated}" );
            WP_CLI::line( "Cleared: {$cleared}" );
            WP_CLI::line( "Skipped: {$skipped}" );

            if ( $dry_run ) {
                WP_CLI::success( 'Dry run complete. No changes saved.' );
                return;
            }

            update_option( $option_key, current_time( 'mysql' ), true );
            WP_CLI::success( 'Migration complete. Marked as done.' );
        }
    }
}
