<?php
/**
 * WP-CLI command to pre-warm ranking queries for listing pages.
 *
 * @package Directory_Helpers
 */

if (!class_exists('DH_Pre_Warm_Rankings_Command')) {
    class DH_Pre_Warm_Rankings_Command extends WP_CLI_Command {

        /**
         * Pre-warm ranking-related queries that listing pages use.
         *
         * ## EXAMPLES
         *
         *     wp directory-helpers pre-warm-rankings
         *
         * @when after_wp_load
         */
        public function __invoke($args, $assoc_args) {
            WP_CLI::line("=== Pre-warming Ranking Queries ===");
            WP_CLI::line("");

            $start_time = microtime(true);

            // Warm city rankings queries
            WP_CLI::line("Warming city ranking queries...");
            $this->warm_city_rankings();

            // Warm state rankings queries  
            WP_CLI::line("Warming state ranking queries...");
            $this->warm_state_rankings();

            // Warm area-based profile queries
            WP_CLI::line("Warming area-based profile queries...");
            $this->warm_area_profiles();

            // Warm proximity queries
            WP_CLI::line("Warming proximity queries...");
            $this->warm_proximity_queries();

            $total_time = round(microtime(true) - $start_time, 2);
            WP_CLI::line("");
            WP_CLI::line("=== Ranking Query Pre-warming Complete ===");
            WP_CLI::line("Time: {$total_time}s");
            WP_CLI::success("Ranking queries are now warmed and ready for cache priming!");
        }

        /**
         * Warm city ranking queries (what city listing pages use)
         */
        private function warm_city_rankings() {
            $cities = get_posts(array(
                'post_type' => 'city-listing',
                'post_status' => 'publish',
                'fields' => 'ids',
                'posts_per_page' => 100, // Sample of cities
                'no_found_rows' => true,
                'orderby' => 'rand',
            ));

            $warmed = 0;
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
                    $warmed++;
                }
            }

            WP_CLI::line("  Warmed {$warmed} city ranking queries");
        }

        /**
         * Warm state ranking queries
         */
        private function warm_state_rankings() {
            $states = get_terms(array(
                'taxonomy' => 'state',
                'fields' => 'ids',
                'count' => false,
                'hide_empty' => false,
            ));

            $warmed = 0;
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
                    $warmed++;
                }
            }

            WP_CLI::line("  Warmed {$warmed} state ranking queries");
        }

        /**
         * Warm area-based profile queries
         */
        private function warm_area_profiles() {
            $areas = get_terms(array(
                'taxonomy' => 'area',
                'fields' => 'ids',
                'count' => false,
                'hide_empty' => false,
                'number' => 50, // Sample of areas
            ));

            $warmed = 0;
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
                    $warmed++;
                }
            }

            WP_CLI::line("  Warmed {$warmed} area-based profile queries");
        }

        /**
         * Warm proximity queries
         */
        private function warm_proximity_queries() {
            // Sample a few cities for proximity queries
            $cities = get_posts(array(
                'post_type' => 'city-listing',
                'post_status' => 'publish',
                'fields' => 'ids',
                'posts_per_page' => 20,
                'no_found_rows' => true,
                'orderby' => 'rand',
            ));

            $warmed = 0;
            foreach ($cities as $city_id) {
                // Get city coordinates
                $lat = get_post_meta($city_id, 'latitude', true);
                $lng = get_post_meta($city_id, 'longitude', true);
                
                if ($lat && $lng) {
                    // Simulate proximity query (simplified version)
                    $profiles = get_posts(array(
                        'post_type' => 'profile',
                        'post_status' => 'publish',
                        'posts_per_page' => 10,
                        'no_found_rows' => true,
                        'meta_query' => array(
                            'relation' => 'AND',
                            array(
                                'key' => 'latitude',
                                'value' => array($lat - 0.1, $lat + 0.1),
                                'compare' => 'BETWEEN',
                                'type' => 'DECIMAL(10,8)',
                            ),
                            array(
                                'key' => 'longitude',
                                'value' => array($lng - 0.1, $lng + 0.1),
                                'compare' => 'BETWEEN',
                                'type' => 'DECIMAL(11,8)',
                            ),
                        ),
                    ));
                    
                    if (!empty($profiles)) {
                        $warmed++;
                    }
                }
            }

            WP_CLI::line("  Warmed {$warmed} proximity queries");
        }
    }
}
