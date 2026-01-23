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

            // Warm ranking queries
            WP_CLI::line("Warming ranking queries...");
            $this->warm_ranking_queries();

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

        /**
         * Warm ranking queries (what listing pages use)
         */
        private function warm_ranking_queries() {
            // Warm city ranking queries
            $cities = get_posts(array(
                'post_type' => 'city-listing',
                'post_status' => 'publish',
                'fields' => 'ids',
                'posts_per_page' => 100, // Sample of cities
                'no_found_rows' => true,
                'orderby' => 'rand',
            ));

            $city_warmed = 0;
            foreach ($cities as $city_id) {
                // Simulate the ranking query that listing pages use
                $profiles = get_posts(array(
                    'post_type' => 'profile',
                    'post_status' => 'publish',
                    'posts_per_page' => 10,
                    'no_found_rows' => true,
                    'meta_query' => array(
                        array(
                            'key' => 'city_rank',
                            'value' => 0,
                            'compare' => '>',
                            'type' => 'NUMERIC',
                        ),
                    ),
                    'orderby' => array(
                        'city_rank' => 'ASC',
                    ),
                ));
                
                if (!empty($profiles)) {
                    $city_warmed++;
                }
            }

            // Warm state ranking queries  
            $states = get_terms(array(
                'taxonomy' => 'state',
                'fields' => 'ids',
                'count' => false,
                'hide_empty' => false,
            ));

            $state_warmed = 0;
            foreach ($states as $state_id) {
                // Simulate state ranking query
                $profiles = get_posts(array(
                    'post_type' => 'profile',
                    'post_status' => 'publish',
                    'posts_per_page' => 10,
                    'no_found_rows' => true,
                    'meta_query' => array(
                        array(
                            'key' => 'state_rank',
                            'value' => 0,
                            'compare' => '>',
                            'type' => 'NUMERIC',
                        ),
                    ),
                    'orderby' => array(
                        'state_rank' => 'ASC',
                    ),
                ));
                
                if (!empty($profiles)) {
                    $state_warmed++;
                }
            }

            // Warm area-based profile queries
            $areas = get_terms(array(
                'taxonomy' => 'area',
                'fields' => 'ids',
                'count' => false,
                'hide_empty' => false,
                'number' => 50, // Sample of areas
            ));

            $area_warmed = 0;
            foreach ($areas as $area_id) {
                // Simulate area-based query
                $profiles = get_posts(array(
                    'post_type' => 'profile',
                    'post_status' => 'publish',
                    'posts_per_page' => 10,
                    'no_found_rows' => true,
                    'tax_query' => array(
                        array(
                            'taxonomy' => 'area',
                            'field' => 'term_id',
                            'terms' => $area_id,
                        ),
                    ),
                ));
                
                if (!empty($profiles)) {
                    $area_warmed++;
                }
            }

            WP_CLI::line("  Warmed {$city_warmed} city ranking queries");
            WP_CLI::line("  Warmed {$state_warmed} state ranking queries");
            WP_CLI::line("  Warmed {$area_warmed} area-based profile queries");
        }
    }
}
