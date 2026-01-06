<?php
/**
 * Test script to debug ranking precision issues
 * Run with: wp eval-file test-ranking-precision.php 119446 0.1747
 */

if (!defined('WP_CLI') || !WP_CLI) {
    die('This script must be run via WP-CLI');
}

global $argv;
$profile_id = isset($argv[3]) ? (int)$argv[3] : 0;
$test_boost = isset($argv[4]) ? (float)$argv[4] : 0;

if (!$profile_id) {
    WP_CLI::error('Usage: wp eval-file test-ranking-precision.php PROFILE_ID BOOST_VALUE');
}

WP_CLI::line("Testing ranking precision for profile ID: {$profile_id}");
WP_CLI::line("Test boost value: {$test_boost}");
WP_CLI::line("");

// Get the profile's city
$area_terms = wp_get_post_terms($profile_id, 'area');
if (empty($area_terms)) {
    WP_CLI::error('Profile has no area/city assigned');
}

$area_term = $area_terms[0];
WP_CLI::line("City: {$area_term->name} (ID: {$area_term->term_id})");
WP_CLI::line("");

// Get all profiles in this city
global $wpdb;
$profile_ids = $wpdb->get_col($wpdb->prepare("
    SELECT DISTINCT p.ID
    FROM {$wpdb->posts} p
    INNER JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
    INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
        AND tt.taxonomy = 'area' AND tt.term_id = %d
    WHERE p.post_type = 'profile'
    AND p.post_status = 'publish'
", $area_term->term_id));

WP_CLI::line("Found " . count($profile_ids) . " profiles in this city");
WP_CLI::line("");

// Fetch all meta data
$profile_id_placeholders = implode(',', array_fill(0, count($profile_ids), '%d'));
$meta_results = $wpdb->get_results($wpdb->prepare("
    SELECT post_id, meta_key, meta_value
    FROM {$wpdb->postmeta}
    WHERE post_id IN ({$profile_id_placeholders})
    AND meta_key IN (%s, %s, %s)
", array_merge($profile_ids, array('rating_value', 'rating_votes_count', 'ranking_boost'))));

// Organize meta
$profile_meta = [];
foreach ($profile_ids as $pid) {
    $profile_meta[$pid] = ['rating' => null, 'review_count' => null, 'boost' => 0, 'title' => get_the_title($pid)];
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

// Override the test profile's boost
$profile_meta[$profile_id]['boost'] = $test_boost;

// Calculate scores
$scores = [];
foreach ($profile_ids as $pid) {
    $data = $profile_meta[$pid];
    if (empty($data['rating']) || empty($data['review_count'])) {
        $scores[$pid] = ['score' => -1, 'review_count' => 0, 'title' => $data['title']];
    } else {
        $rating = (float)$data['rating'];
        $review_count = (int)$data['review_count'];
        $boost = (float)$data['boost'];
        
        $rating_component = $rating * 0.9;
        $review_component = min(1, log10($review_count + 1) / 2) * 5 * 0.1;
        $score = $rating_component + $review_component + $boost;
        
        $scores[$pid] = [
            'score' => $score,
            'review_count' => $review_count,
            'title' => $data['title'],
            'rating' => $rating,
            'boost' => $boost
        ];
    }
}

// Sort using the same method as the ranking system
$pids = array_keys($scores);
$score_vals = [];
$review_vals = [];

foreach ($scores as $pid => $data) {
    $score_vals[] = sprintf('%.15f', $data['score']);
    $review_vals[] = $data['review_count'];
}

array_multisort(
    $score_vals, SORT_DESC, SORT_STRING,
    $review_vals, SORT_DESC, SORT_NUMERIC,
    $pids, SORT_ASC, SORT_NUMERIC
);

// Display results
WP_CLI::line("Ranking Results:");
WP_CLI::line(str_repeat('=', 120));

$rank = 1;
foreach ($pids as $pid) {
    $data = $scores[$pid];
    if ($data['score'] < 0) continue;
    
    $is_test = ($pid == $profile_id) ? ' ← TEST PROFILE' : '';
    $title = substr($data['title'], 0, 40);
    
    WP_CLI::line(sprintf(
        "Rank %2d | ID: %6d | Score: %.15f | Rating: %.1f | Reviews: %4d | Boost: %+.15f | %s%s",
        $rank,
        $pid,
        $data['score'],
        $data['rating'],
        $data['review_count'],
        $data['boost'],
        $title,
        $is_test
    ));
    
    $rank++;
}
