<?php
/**
 * WP-CLI command for updating state rankings.
 * Processes each state only once, avoiding redundant recalculations.
 */

if (!class_exists('DH_Update_State_Rankings_Command')) {
    class DH_Update_State_Rankings_Command extends WP_CLI_Command {

        /**
         * Update state rankings for all profiles in a niche.
         *
         * ## OPTIONS
         *
         * <niche>
         * : Niche taxonomy slug (required, e.g., dog-trainer)
         *
         * [--state=<slug>]
         * : Process only a specific state by term slug (e.g., ca, tx, ny)
         *
         * [--dry-run]
         * : Show what would be done without making changes
         *
         * ## EXAMPLES
         *
         *     wp directory-helpers update-state-rankings dog-trainer
         *     wp directory-helpers update-state-rankings dog-trainer --state=ca
         *
         * @when after_wp_load
         */
        public function __invoke($args, $assoc_args) {
            while (ob_get_level()) {
                ob_end_flush();
            }

            WP_CLI::line("Starting state rankings update...");

            if (empty($args[0])) {
                WP_CLI::error("Niche slug is required.");
                return;
            }

            $niche_slug = sanitize_title($args[0]);
            $niche_term = get_term_by('slug', $niche_slug, 'niche');

            if (!$niche_term) {
                WP_CLI::error("Niche term '{$niche_slug}' not found.");
                return;
            }

            $niche_id = $niche_term->term_id;
            $dry_run = isset($assoc_args['dry-run']);
            $single_state = isset($assoc_args['state']) ? sanitize_title($assoc_args['state']) : null;

            WP_CLI::line("=== State Rankings Update ===");
            WP_CLI::line("Niche: {$niche_term->name}");
            WP_CLI::line("Dry run: " . ($dry_run ? 'Yes' : 'No'));
            WP_CLI::line("");

            global $wpdb;

            // Get all states with profiles for this niche
            $states_query = $wpdb->prepare("
                SELECT DISTINCT
                    t.term_id,
                    t.name as state_name,
                    t.slug as state_slug,
                    COUNT(DISTINCT p.ID) as profile_count
                FROM {$wpdb->posts} p
                INNER JOIN {$wpdb->term_relationships} tr_state ON p.ID = tr_state.object_id
                INNER JOIN {$wpdb->term_taxonomy} tt_state ON tr_state.term_taxonomy_id = tt_state.term_taxonomy_id
                    AND tt_state.taxonomy = 'state'
                INNER JOIN {$wpdb->terms} t ON tt_state.term_id = t.term_id
                INNER JOIN {$wpdb->term_relationships} tr_niche ON p.ID = tr_niche.object_id
                INNER JOIN {$wpdb->term_taxonomy} tt_niche ON tr_niche.term_taxonomy_id = tt_niche.term_taxonomy_id
                    AND tt_niche.taxonomy = 'niche' AND tt_niche.term_id = %d
                WHERE p.post_type = 'profile'
                AND p.post_status = 'publish'
                GROUP BY t.term_id
                ORDER BY profile_count DESC
            ", $niche_id);

            $states = $wpdb->get_results($states_query);

            if ($single_state) {
                $states = array_filter($states, function($s) use ($single_state) {
                    return $s->state_slug === $single_state;
                });
                $states = array_values($states);
                
                if (empty($states)) {
                    WP_CLI::error("State '{$single_state}' not found or has no profiles.");
                    return;
                }
            }

            WP_CLI::line("Found " . count($states) . " states to process.");

            if ($dry_run) {
                WP_CLI::line("");
                foreach ($states as $state) {
                    WP_CLI::line("{$state->state_name} ({$state->state_slug}): {$state->profile_count} profiles");
                }
                WP_CLI::success("Dry run complete.");
                return;
            }

            $total_states = count($states);
            $start_time = microtime(true);

            foreach ($states as $index => $state) {
                $state_start = microtime(true);
                
                WP_CLI::line("");
                WP_CLI::line("[" . ($index + 1) . "/{$total_states}] {$state->state_name} ({$state->state_slug}) - {$state->profile_count} profiles");

                // Get all profile IDs for this state and niche
                $profile_ids = $wpdb->get_col($wpdb->prepare("
                    SELECT DISTINCT p.ID
                    FROM {$wpdb->posts} p
                    INNER JOIN {$wpdb->term_relationships} tr_state ON p.ID = tr_state.object_id
                    INNER JOIN {$wpdb->term_taxonomy} tt_state ON tr_state.term_taxonomy_id = tt_state.term_taxonomy_id
                        AND tt_state.taxonomy = 'state' AND tt_state.term_id = %d
                    INNER JOIN {$wpdb->term_relationships} tr_niche ON p.ID = tr_niche.object_id
                    INNER JOIN {$wpdb->term_taxonomy} tt_niche ON tr_niche.term_taxonomy_id = tt_niche.term_taxonomy_id
                        AND tt_niche.taxonomy = 'niche' AND tt_niche.term_id = %d
                    WHERE p.post_type = 'profile'
                    AND p.post_status = 'publish'
                ", $state->term_id, $niche_id));

                if (empty($profile_ids)) {
                    WP_CLI::line("  No profiles found, skipping...");
                    continue;
                }

                // Bulk fetch all meta data in one query
                $profile_id_string = implode(',', array_map('intval', $profile_ids));
                
                $meta_query = "
                    SELECT post_id, meta_key, meta_value
                    FROM {$wpdb->postmeta}
                    WHERE post_id IN ({$profile_id_string})
                    AND meta_key IN ('rating_value', 'rating_votes_count', 'ranking_boost')
                ";
                $meta_results = $wpdb->get_results($meta_query);

                // Organize meta by profile ID
                $profile_meta = [];
                foreach ($profile_ids as $pid) {
                    $profile_meta[$pid] = ['rating' => null, 'review_count' => null, 'boost' => 0];
                }
                foreach ($meta_results as $row) {
                    if ($row->meta_key === 'rating_value') {
                        $profile_meta[$row->post_id]['rating'] = $row->meta_value;
                    } elseif ($row->meta_key === 'rating_votes_count') {
                        $profile_meta[$row->post_id]['review_count'] = $row->meta_value;
                    } elseif ($row->meta_key === 'ranking_boost') {
                        $profile_meta[$row->post_id]['boost'] = $row->meta_value ?: 0;
                    }
                }

                // Calculate scores
                $scores = [];
                foreach ($profile_ids as $pid) {
                    $data = $profile_meta[$pid];
                    if (empty($data['rating']) || empty($data['review_count'])) {
                        $scores[$pid] = ['score' => -1, 'review_count' => 0];
                    } else {
                        $rating = (float)$data['rating'];
                        $review_count = (int)$data['review_count'];
                        $boost = (float)$data['boost'];
                        
                        $rating_component = $rating * 0.9;
                        $review_component = min(1, log10($review_count + 1) / 2) * 5 * 0.1;
                        $score = $rating_component + $review_component + $boost;
                        
                        $scores[$pid] = ['score' => $score, 'review_count' => $review_count];
                    }
                }

                // Sort by score desc, then review count desc
                $pids = array_keys($scores);
                $score_vals = array_column($scores, 'score');
                $review_vals = array_column($scores, 'review_count');
                array_multisort($score_vals, SORT_DESC, $review_vals, SORT_DESC, $pids);

                // Bulk delete existing state_rank values
                $wpdb->query("
                    DELETE FROM {$wpdb->postmeta}
                    WHERE post_id IN ({$profile_id_string})
                    AND meta_key = 'state_rank'
                ");

                // Bulk insert new state_rank values
                $insert_values = [];
                $rank = 1;
                foreach ($pids as $pid) {
                    $rank_value = ($scores[$pid]['score'] < 0) ? 99999 : $rank;
                    $insert_values[] = "({$pid}, 'state_rank', {$rank_value})";
                    if ($scores[$pid]['score'] >= 0) {
                        $rank++;
                    }
                }

                if (!empty($insert_values)) {
                    $wpdb->query("
                        INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value)
                        VALUES " . implode(',', $insert_values)
                    );
                }

                $state_time = round(microtime(true) - $state_start, 2);
                WP_CLI::line("  → Completed in {$state_time}s");
            }

            $total_time = round(microtime(true) - $start_time, 2);
            WP_CLI::line("");
            WP_CLI::success("State rankings update completed! Processed {$total_states} states in {$total_time}s");
        }
    }
}
