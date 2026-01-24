<?php
/**
 * WP-CLI Command: IndexNow Backfill
 *
 * Submits all published profiles and city-listings to IndexNow API.
 *
 * @package Directory_Helpers
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * IndexNow backfill command
 */
class DH_IndexNow_Backfill_Command {

    /**
     * Backfill all published profiles and city-listings to IndexNow
     *
     * ## OPTIONS
     *
     * [--limit=<number>]
     * : Maximum number of posts to process
     * ---
     * default: 0
     * ---
     *
     * [--offset=<number>]
     * : Number of posts to skip
     * ---
     * default: 0
     * ---
     *
     * [--post-type=<type>]
     * : Post type to process (profile, city-listing, or 'all')
     * ---
     * default: all
     * ---
     *
     * [--dry-run]
     * : Show what would be submitted without actually submitting
     *
     * ## EXAMPLES
     *
     *     # Backfill all profiles and city-listings
     *     wp directory-helpers indexnow backfill
     *
     *     # Backfill first 100 profiles only
     *     wp directory-helpers indexnow backfill --post-type=profile --limit=100
     *
     *     # Dry run to see what would be submitted
     *     wp directory-helpers indexnow backfill --dry-run
     *
     * @when after_wp_load
     */
    public function __invoke($args, $assoc_args) {
        $limit = isset($assoc_args['limit']) ? absint($assoc_args['limit']) : 0;
        $offset = isset($assoc_args['offset']) ? absint($assoc_args['offset']) : 0;
        $post_type = isset($assoc_args['post-type']) ? sanitize_text_field($assoc_args['post-type']) : 'all';
        $dry_run = isset($assoc_args['dry-run']);

        // Determine post types to process
        $post_types = array();
        if ($post_type === 'all') {
            $post_types = array('profile', 'city-listing');
        } elseif (in_array($post_type, array('profile', 'city-listing'), true)) {
            $post_types = array($post_type);
        } else {
            WP_CLI::error("Invalid post type. Use 'profile', 'city-listing', or 'all'");
            return;
        }

        WP_CLI::log('Starting IndexNow backfill...');
        if ($dry_run) {
            WP_CLI::warning('DRY RUN MODE - No URLs will be submitted');
        }

        $total_urls = array();

        foreach ($post_types as $current_post_type) {
            WP_CLI::log('');
            WP_CLI::log("Processing post type: {$current_post_type}");

            // Query posts
            $query_args = array(
                'post_type' => $current_post_type,
                'post_status' => 'publish',
                'posts_per_page' => $limit > 0 ? $limit : -1,
                'offset' => $offset,
                'fields' => 'ids',
                'no_found_rows' => false,
            );

            $query = new WP_Query($query_args);
            $post_ids = $query->posts;
            $found_posts = $query->found_posts;

            if (empty($post_ids)) {
                WP_CLI::log("No published {$current_post_type} posts found.");
                continue;
            }

            WP_CLI::log(sprintf(
                'Found %d %s post(s)%s',
                $found_posts,
                $current_post_type,
                $limit > 0 ? " (processing {$limit})" : ''
            ));

            // Collect URLs with progress bar
            $progress = \WP_CLI\Utils\make_progress_bar(
                "Collecting {$current_post_type} URLs",
                count($post_ids)
            );

            $urls = array();
            foreach ($post_ids as $post_id) {
                $url = get_permalink($post_id);
                if ($url && !is_wp_error($url)) {
                    $urls[] = $url;
                }
                $progress->tick();
            }
            $progress->finish();

            WP_CLI::log(sprintf('Collected %d URLs', count($urls)));

            // Submit URLs
            if (!$dry_run && !empty($urls)) {
                WP_CLI::log('Submitting to IndexNow API...');

                $result = DH_IndexNow_Helper::submit_urls($urls);

                if ($result['success']) {
                    WP_CLI::success(sprintf(
                        'Successfully submitted %d URLs in %d batch(es)',
                        $result['total_urls'],
                        $result['batches']
                    ));
                } else {
                    WP_CLI::warning(sprintf(
                        'Submission completed with errors. %d URLs in %d batch(es)',
                        $result['total_urls'],
                        $result['batches']
                    ));

                    // Show error details
                    foreach ($result['batch_results'] as $batch_result) {
                        if (!$batch_result['success']) {
                            WP_CLI::log(sprintf(
                                '  Batch %d: FAILED - %s',
                                $batch_result['batch'],
                                isset($batch_result['error']) ? $batch_result['error'] : 'HTTP ' . $batch_result['http_code']
                            ));
                        }
                    }
                }

                $total_urls = array_merge($total_urls, $urls);
            } elseif ($dry_run) {
                WP_CLI::log('Sample URLs (first 5):');
                foreach (array_slice($urls, 0, 5) as $url) {
                    WP_CLI::log("  {$url}");
                }
                $total_urls = array_merge($total_urls, $urls);
            }
        }

        // Summary
        WP_CLI::log('');
        WP_CLI::log('=== Summary ===');
        WP_CLI::log(sprintf('Total URLs collected: %d', count($total_urls)));
        if ($dry_run) {
            WP_CLI::log('No URLs were submitted (dry run mode)');
        } else {
            WP_CLI::success('Backfill complete!');
        }
    }
}
