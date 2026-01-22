<?php

if ( ! defined( 'WP_CLI' ) ) {
    return;
}

if ( ! class_exists( 'DH_Migrate_Main_Image_Command' ) ) {
    /**
     * WP-CLI command to migrate main_image ACF field to featured images for profile CPTs.
     */
    class DH_Migrate_Main_Image_Command extends WP_CLI_Command {
        /**
         * Migrate main_image ACF field to featured image for profile CPTs.
         *
         * ## OPTIONS
         *
         * [--dry-run]
         * : Show what would be changed without saving.
         *
         * [--limit=<number>]
         * : Limit the number of profiles to process (useful for testing).
         *
         * [--force]
         * : Process profiles even if they already have a featured image (will NOT override existing featured images, but will show them in the report).
         *
         * [--regenerate-thumbs]
         * : Regenerate thumbnail sizes after setting featured image (slower but ensures all sizes exist).
         *
         * ## EXAMPLES
         *
         *     # Test with first 5 profiles (dry-run)
         *     wp directory-helpers migrate-main-image --dry-run --limit=5
         *
         *     # Migrate first 10 profiles
         *     wp directory-helpers migrate-main-image --limit=10
         *
         *     # Migrate all profiles
         *     wp directory-helpers migrate-main-image
         *
         *     # Migrate all and regenerate thumbnails
         *     wp directory-helpers migrate-main-image --regenerate-thumbs
         *
         * @when after_wp_load
         */
        public function __invoke( $args, $assoc_args ) {
            // Flush output buffers for real-time progress
            while ( ob_get_level() ) {
                ob_end_flush();
            }

            $dry_run = isset( $assoc_args['dry-run'] );
            $limit = isset( $assoc_args['limit'] ) ? intval( $assoc_args['limit'] ) : -1;
            $force = isset( $assoc_args['force'] );
            $regenerate_thumbs = isset( $assoc_args['regenerate-thumbs'] );

            WP_CLI::line( '=== Main Image to Featured Image Migration ===' );
            WP_CLI::line( 'Post Type: profile' );
            WP_CLI::line( 'Dry run: ' . ( $dry_run ? 'Yes' : 'No' ) );
            WP_CLI::line( 'Limit: ' . ( $limit > 0 ? $limit : 'None (all profiles)' ) );
            WP_CLI::line( 'Regenerate Thumbnails: ' . ( $regenerate_thumbs ? 'Yes' : 'No' ) );
            WP_CLI::line( '' );

            // Query for profiles with main_image field
            $query_args = [
                'post_type' => 'profile',
                'post_status' => 'any',
                'posts_per_page' => $limit,
                'fields' => 'ids',
                'meta_query' => [
                    [
                        'key' => 'main_image',
                        'compare' => 'EXISTS',
                    ],
                ],
            ];

            $query = new WP_Query( $query_args );
            $profile_ids = $query->posts;
            $total = count( $profile_ids );

            WP_CLI::line( "Found {$total} profile(s) with main_image field." );
            WP_CLI::line( '' );

            if ( $total === 0 ) {
                WP_CLI::success( 'No profiles found with main_image field. Nothing to migrate.' );
                return;
            }

            $migrated = 0;
            $skipped_has_featured = 0;
            $skipped_no_image = 0;
            $skipped_invalid = 0;
            $errors = 0;
            $progress = \WP_CLI\Utils\make_progress_bar( 'Processing profiles', $total );

            foreach ( $profile_ids as $profile_id ) {
                $progress->tick();

                // Get current featured image
                $current_featured = get_post_thumbnail_id( $profile_id );
                
                // Skip if already has featured image (unless force flag)
                if ( $current_featured && ! $force ) {
                    $skipped_has_featured++;
                    continue;
                }

                // Get main_image ACF field
                $main_image = get_field( 'main_image', $profile_id );

                // Skip if no main_image value
                if ( empty( $main_image ) ) {
                    $skipped_no_image++;
                    continue;
                }

                // Extract attachment ID from ACF field value
                $attachment_id = $this->get_attachment_id_from_acf_field( $main_image );

                if ( ! $attachment_id ) {
                    $skipped_invalid++;
                    WP_CLI::warning( "Profile ID {$profile_id}: Could not extract valid attachment ID from main_image field." );
                    continue;
                }

                // Verify attachment exists
                $attachment = get_post( $attachment_id );
                if ( ! $attachment || $attachment->post_type !== 'attachment' ) {
                    $skipped_invalid++;
                    WP_CLI::warning( "Profile ID {$profile_id}: Attachment ID {$attachment_id} does not exist." );
                    continue;
                }

                // Skip if profile already has this as featured image
                if ( $current_featured && $current_featured == $attachment_id ) {
                    WP_CLI::line( "Profile ID {$profile_id}: Already has attachment {$attachment_id} as featured image. Clearing main_image field..." );
                    
                    if ( ! $dry_run ) {
                        delete_field( 'main_image', $profile_id );
                    }
                    
                    $migrated++;
                    continue;
                }

                // Perform the migration
                if ( ! $dry_run ) {
                    // Set featured image
                    $result = set_post_thumbnail( $profile_id, $attachment_id );
                    
                    if ( ! $result ) {
                        $errors++;
                        WP_CLI::error( "Profile ID {$profile_id}: Failed to set featured image (attachment {$attachment_id}).", false );
                        continue;
                    }

                    // Set alt text for the attachment
                    $profile_title = get_the_title( $profile_id );
                    $alt_text = 'Featured image for ' . $profile_title;
                    update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt_text );

                    // Regenerate thumbnails if requested
                    if ( $regenerate_thumbs ) {
                        $this->regenerate_attachment_thumbnails( $attachment_id );
                    }

                    // Clear main_image field
                    delete_field( 'main_image', $profile_id );

                    // Clear any LiteSpeed cache for this post
                    if ( class_exists( 'LiteSpeed_Cache_API' ) ) {
                        LiteSpeed_Cache_API::purge_post( $profile_id );
                    }
                }

                $migrated++;
            }

            $progress->finish();

            WP_CLI::line( '' );
            WP_CLI::line( '=== Summary ===' );
            WP_CLI::line( "Total processed: {$total}" );
            WP_CLI::line( "Migrated: {$migrated}" );
            WP_CLI::line( "Skipped (has featured image): {$skipped_has_featured}" );
            WP_CLI::line( "Skipped (no main_image value): {$skipped_no_image}" );
            WP_CLI::line( "Skipped (invalid attachment): {$skipped_invalid}" );
            WP_CLI::line( "Errors: {$errors}" );

            if ( $dry_run ) {
                WP_CLI::success( 'Dry run complete. No changes made.' );
            } else {
                WP_CLI::success( "Migration complete. {$migrated} profile(s) updated." );
                
                if ( $migrated > 0 ) {
                    WP_CLI::line( '' );
                    WP_CLI::line( 'Recommended next steps:' );
                    WP_CLI::line( '1. Verify a few migrated profiles to ensure images display correctly' );
                    WP_CLI::line( '2. Consider purging and rebuilding cache if needed' );
                }
            }
        }

        /**
         * Extract attachment ID from ACF field value.
         *
         * ACF image fields can return:
         * - Array with 'ID' key (when return format is 'array')
         * - Integer attachment ID (when return format is 'id')
         * - String URL (when return format is 'url')
         *
         * @param mixed $field_value The ACF field value.
         * @return int|false Attachment ID or false if not found.
         */
        private function get_attachment_id_from_acf_field( $field_value ) {
            // Array format
            if ( is_array( $field_value ) && isset( $field_value['ID'] ) ) {
                return intval( $field_value['ID'] );
            }

            // ID format
            if ( is_numeric( $field_value ) ) {
                return intval( $field_value );
            }

            // URL format - try to find attachment by URL
            if ( is_string( $field_value ) && filter_var( $field_value, FILTER_VALIDATE_URL ) ) {
                return attachment_url_to_postid( $field_value );
            }

            return false;
        }

        /**
         * Regenerate all thumbnail sizes for an attachment.
         *
         * @param int $attachment_id Attachment ID.
         * @return bool True on success, false on failure.
         */
        private function regenerate_attachment_thumbnails( $attachment_id ) {
            $file = get_attached_file( $attachment_id );
            
            if ( ! $file || ! file_exists( $file ) ) {
                return false;
            }

            // Require image functions
            require_once( ABSPATH . 'wp-admin/includes/image.php' );

            // Generate thumbnails
            $metadata = wp_generate_attachment_metadata( $attachment_id, $file );
            
            if ( is_wp_error( $metadata ) ) {
                return false;
            }

            // Update attachment metadata
            wp_update_attachment_metadata( $attachment_id, $metadata );

            return true;
        }
    }
}
