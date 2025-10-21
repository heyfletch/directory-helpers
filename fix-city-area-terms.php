<?php
/**
 * Fix city listings with incorrect area terms
 * 
 * This script corrects the area terms for city listings where the area term
 * doesn't match the state code in the slug.
 */

if (!defined('ABSPATH')) {
    require_once(__DIR__ . '/../../../wp-load.php');
}

echo "=== Fixing City Listing Area Terms ===\n\n";

// List of city listings that need fixing (from investigation)
$fixes = array(
    array('post_id' => 116580, 'title' => 'Carlisle, IA', 'expected_slug' => 'carlisle-ia'),
    array('post_id' => 116465, 'title' => 'Richmond, KY', 'expected_slug' => 'richmond-ky'),
    array('post_id' => 115967, 'title' => 'Greenville, SC', 'expected_slug' => 'greenville-sc'),
    array('post_id' => 110763, 'title' => 'Dayton, OH', 'expected_slug' => 'dayton-oh'),
    array('post_id' => 110789, 'title' => 'Milford, OH', 'expected_slug' => 'milford-oh'),
    array('post_id' => 110654, 'title' => 'Columbia, TN', 'expected_slug' => 'columbia-tn'),
    array('post_id' => 110434, 'title' => 'Richmond, IN', 'expected_slug' => 'richmond-in'),
    array('post_id' => 109054, 'title' => 'Lancaster, NY', 'expected_slug' => 'lancaster-ny'),
);

$fixed = 0;
$errors = 0;

foreach ($fixes as $fix) {
    $post_id = $fix['post_id'];
    $title = $fix['title'];
    $expected_slug = $fix['expected_slug'];
    
    echo "Processing: $title (Post #$post_id)\n";
    
    // Get current area term
    $current_terms = get_the_terms($post_id, 'area');
    $current_slug = !empty($current_terms) && !is_wp_error($current_terms) ? $current_terms[0]->slug : 'NONE';
    
    echo "  Current area term: $current_slug\n";
    
    // Find the correct area term by slug
    $correct_term = get_term_by('slug', $expected_slug, 'area');
    
    if (!$correct_term || is_wp_error($correct_term)) {
        echo "  ✗ ERROR: Could not find area term with slug '$expected_slug'\n";
        $errors++;
        continue;
    }
    
    echo "  Found correct term: {$correct_term->name} (ID: {$correct_term->term_id}, slug: {$correct_term->slug})\n";
    
    // Update the area term
    $result = wp_set_object_terms($post_id, $correct_term->term_id, 'area');
    
    if (is_wp_error($result)) {
        echo "  ✗ ERROR: Failed to update - " . $result->get_error_message() . "\n";
        $errors++;
    } else {
        echo "  ✓ SUCCESS: Updated area term from '$current_slug' to '$expected_slug'\n";
        $fixed++;
    }
    
    echo "\n";
}

echo "=== SUMMARY ===\n";
echo "Total processed: " . count($fixes) . "\n";
echo "Successfully fixed: $fixed\n";
echo "Errors: $errors\n";
echo "\n=== END ===\n";
