<?php
/**
 * Investigation script to find city listings with incorrect area terms
 * 
 * This checks city listings where the city name appears in multiple states,
 * and verifies that each city listing is using the correct area term based on
 * the state code in the slug.
 */

if (!defined('ABSPATH')) {
    require_once(__DIR__ . '/../../../wp-load.php');
}

echo "=== City Listing Area Term Investigation ===\n\n";

// Get all published city listings
$city_listings = get_posts(array(
    'post_type' => 'city-listing',
    'post_status' => 'publish',
    'posts_per_page' => -1,
    'fields' => 'ids',
));

echo "Total city listings to check: " . count($city_listings) . "\n\n";

$issues_found = array();
$checked = 0;

foreach ($city_listings as $post_id) {
    $checked++;
    
    $post = get_post($post_id);
    $slug = $post->post_name;
    $title = $post->post_title;
    
    // Extract state code from slug pattern: city-name-ST-dog-trainers
    if (preg_match('/^(.+)-([a-z]{2})-dog-trainers?$/', $slug, $matches)) {
        $city_part = $matches[1];
        $state_code = $matches[2];
        
        // Get the area term assigned to this city listing
        $area_terms = get_the_terms($post_id, 'area');
        
        if (empty($area_terms) || is_wp_error($area_terms)) {
            $issues_found[] = array(
                'post_id' => $post_id,
                'title' => $title,
                'slug' => $slug,
                'issue' => 'No area term assigned',
                'expected_state' => strtoupper($state_code),
                'actual_area_slug' => 'NONE',
            );
            continue;
        }
        
        $area_term = $area_terms[0];
        $area_slug = $area_term->slug;
        
        // Check if the area term slug matches the expected state code
        // Expected pattern: city-name-ST (e.g., dallas-ga, dallas-tx)
        $expected_area_slug_pattern = '-' . $state_code;
        
        if (!str_contains($area_slug, $expected_area_slug_pattern)) {
            // Extract state from actual area slug
            $actual_state = 'UNKNOWN';
            if (preg_match('/-([a-z]{2})$/', $area_slug, $state_matches)) {
                $actual_state = strtoupper($state_matches[1]);
            }
            
            $issues_found[] = array(
                'post_id' => $post_id,
                'title' => $title,
                'slug' => $slug,
                'issue' => 'Area term state mismatch',
                'expected_state' => strtoupper($state_code),
                'actual_area_slug' => $area_slug,
                'actual_state' => $actual_state,
                'area_term_id' => $area_term->term_id,
                'area_term_name' => $area_term->name,
            );
        }
    }
    
    if ($checked % 100 == 0) {
        echo "Checked $checked city listings...\n";
    }
}

echo "\n=== RESULTS ===\n\n";
echo "Total checked: $checked\n";
echo "Issues found: " . count($issues_found) . "\n\n";

if (!empty($issues_found)) {
    echo "CITY LISTINGS WITH INCORRECT AREA TERMS:\n";
    echo str_repeat("=", 120) . "\n";
    printf("%-8s %-30s %-35s %-15s %-15s %-20s\n", 
        "Post ID", "Title", "Slug", "Expected State", "Actual State", "Area Term Slug");
    echo str_repeat("=", 120) . "\n";
    
    foreach ($issues_found as $issue) {
        printf("%-8s %-30s %-35s %-15s %-15s %-20s\n",
            $issue['post_id'],
            substr($issue['title'], 0, 30),
            substr($issue['slug'], 0, 35),
            $issue['expected_state'],
            isset($issue['actual_state']) ? $issue['actual_state'] : 'N/A',
            substr($issue['actual_area_slug'], 0, 20)
        );
    }
    
    echo str_repeat("=", 120) . "\n\n";
    
    // Group by city name to show patterns
    $by_city = array();
    foreach ($issues_found as $issue) {
        // Extract city name from title (before comma)
        $city_name = explode(',', $issue['title'])[0];
        if (!isset($by_city[$city_name])) {
            $by_city[$city_name] = array();
        }
        $by_city[$city_name][] = $issue;
    }
    
    echo "\nGROUPED BY CITY NAME (showing cities with multiple issues):\n";
    echo str_repeat("=", 80) . "\n";
    foreach ($by_city as $city_name => $issues) {
        if (count($issues) > 1) {
            echo "\n$city_name (" . count($issues) . " issues):\n";
            foreach ($issues as $issue) {
                echo "  - Post #{$issue['post_id']}: {$issue['title']} -> Expected: {$issue['expected_state']}, ";
                echo "Got: " . (isset($issue['actual_state']) ? $issue['actual_state'] : 'N/A') . "\n";
            }
        }
    }
} else {
    echo "✓ All city listings have correct area terms!\n";
}

echo "\n=== END OF INVESTIGATION ===\n";
