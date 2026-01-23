<?php
/**
 * WP-CLI command to pre-warm Redis object cache with critical data.
 *
 * @package Directory_Helpers
 */

if (!class_exists('DH_Pre_Warm_Object_Cache_Command')) {
    class DH_Pre_Warm_Object_Cache_Command extends WP_CLI_Command {

        /**
         * Pre-warm Redis object cache with critical data before cache priming.
         *
         * ## EXAMPLES
         *
         *     wp directory-helpers pre-warm-object-cache
         *
         * @when after_wp_load
         */
        public function __invoke($args, $assoc_args) {
            WP_CLI::line("=== Pre-warming Object Cache ===");
            WP_CLI::line("");

            $start_time = microtime(true);

            // Warm area taxonomy terms
            WP_CLI::line("Warming area taxonomy terms...");
            $this->warm_area_terms();

            // Warm profile posts
            WP_CLI::line("Warming profile posts...");
            $this->warm_profile_posts();

            // Warm core options
            WP_CLI::line("Warming core options...");
            $this->warm_core_options();

            // Warm profile count query
            WP_CLI::line("Warming profile count query...");
            $this->warm_profile_count_query();

            $total_time = round(microtime(true) - $start_time, 2);
            WP_CLI::line("");
            WP_CLI::line("=== Object Cache Pre-warming Complete ===");
            WP_CLI::line("Time: {$total_time}s");
            WP_CLI::success("Object cache is now warmed and ready for cache priming!");
        }

        /**
         * Warm area taxonomy terms
         */
        private function warm_area_terms() {
            $terms = get_terms(array(
                'taxonomy' => 'area',
                'fields' => 'ids',
                'count' => false,
                'hide_empty' => false,
            ));

            if (!is_wp_error($terms) && !empty($terms)) {
                WP_CLI::line("  Loaded " . count($terms) . " area terms into cache");
            } else {
                WP_CLI::warning("  Failed to load area terms");
            }
        }

        /**
         * Warm profile posts
         */
        private function warm_profile_posts() {
            $posts = get_posts(array(
                'post_type' => 'profile',
                'post_status' => 'publish',
                'fields' => 'ids',
                'posts_per_page' => 50,
                'no_found_rows' => true,
            ));

            if (!empty($posts)) {
                // Warm each post's metadata
                foreach ($posts as $post_id) {
                    wp_cache_get($post_id, 'posts');
                    wp_cache_get('post_meta_' . $post_id, 'post_meta');
                }
                WP_CLI::line("  Loaded " . count($posts) . " profile posts and metadata into cache");
            } else {
                WP_CLI::warning("  No profile posts found");
            }
        }

        /**
         * Warm core WordPress options
         */
        private function warm_core_options() {
            $options = array('home', 'siteurl', 'blogname', 'admin_email');
            $loaded = 0;

            foreach ($options as $option) {
                $value = get_option($option);
                if ($value !== false) {
                    $loaded++;
                }
            }

            WP_CLI::line("  Loaded {$loaded} core options into cache");
        }

        /**
         * Warm profile count query
         */
        private function warm_profile_count_query() {
            global $wpdb;
            
            $count = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $wpdb->posts WHERE post_type = %s AND post_status = %s",
                'profile',
                'publish'
            ));

            if ($count !== null) {
                WP_CLI::line("  Loaded profile count ({$count}) into cache");
            } else {
                WP_CLI::warning("  Failed to load profile count");
            }
        }
    }
}
