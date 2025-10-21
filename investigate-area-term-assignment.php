<?php
/**
 * Investigation: How did wrong area terms get assigned to city listings?
 * 
 * This traces the logic flow to understand the root cause.
 */

if (!defined('ABSPATH')) {
    require_once(__DIR__ . '/../../../wp-load.php');
}

echo "=== Area Term Assignment Investigation ===\n\n";

// Test case: Richmond (has 5 different area terms)
echo "TEST CASE: Richmond\n";
echo str_repeat("=", 80) . "\n\n";

// Get all Richmond area terms
$richmond_terms = get_terms(array(
    'taxonomy' => 'area',
    'name__like' => 'Richmond',
    'hide_empty' => false,
));

echo "All Richmond area terms:\n";
foreach ($richmond_terms as $term) {
    echo sprintf("  - ID: %d, Name: '%s', Slug: '%s'\n", $term->term_id, $term->name, $term->slug);
}
echo "\n";

// Simulate what happens when cleanup_area_terms runs
echo "SCENARIO 1: What cleanup_area_terms does\n";
echo str_repeat("-", 80) . "\n";
echo "The cleanup function removes ' - ST' suffix from area term NAMES (not slugs).\n";
echo "After cleanup, multiple terms can have the same name:\n\n";

foreach ($richmond_terms as $term) {
    $original_name = $term->name;
    $cleaned_name = trim(preg_replace('/\s-\s[A-Za-z]{2}$/', '', $original_name));
    if ($cleaned_name !== $original_name) {
        echo sprintf("  '%s' → '%s' (slug stays: %s)\n", $original_name, $cleaned_name, $term->slug);
    } else {
        echo sprintf("  '%s' (no change, slug: %s)\n", $original_name, $term->slug);
    }
}
echo "\n";

// Now check how city_listing_exists works
echo "SCENARIO 2: How city listings are created\n";
echo str_repeat("-", 80) . "\n";
echo "When creating a city listing, the code:\n";
echo "1. Gets area term from profiles (by slug - CORRECT)\n";
echo "2. Checks if city listing exists using city_listing_exists()\n";
echo "3. Creates city listing and assigns the area term by term_id\n\n";

// Check if there's a potential issue with city_listing_exists
echo "The city_listing_exists() function queries by term_id, so it should be safe.\n";
echo "Let's check if profiles have the wrong area terms assigned...\n\n";

// Check Richmond, KY profiles
echo "SCENARIO 3: Checking profiles in Richmond, KY\n";
echo str_repeat("-", 80) . "\n";

$richmond_ky_term = get_term_by('slug', 'richmond-ky', 'area');
if ($richmond_ky_term) {
    echo "Richmond, KY area term: ID {$richmond_ky_term->term_id}, Name: '{$richmond_ky_term->name}'\n\n";
    
    // Get profiles with this area term
    $profiles = get_posts(array(
        'post_type' => 'profile',
        'post_status' => 'publish',
        'posts_per_page' => 5,
        'tax_query' => array(
            array(
                'taxonomy' => 'area',
                'field' => 'term_id',
                'terms' => $richmond_ky_term->term_id,
            ),
        ),
    ));
    
    echo "Sample profiles with richmond-ky area term: " . count($profiles) . " found\n";
    foreach ($profiles as $profile) {
        $area_terms = get_the_terms($profile->ID, 'area');
        $area_slugs = array();
        if (!empty($area_terms)) {
            foreach ($area_terms as $at) {
                $area_slugs[] = $at->slug;
            }
        }
        echo "  - Profile #{$profile->ID}: {$profile->post_title} - Area terms: " . implode(', ', $area_slugs) . "\n";
    }
}

echo "\n\n";

// Check if profiles might have multiple area terms
echo "SCENARIO 4: Checking for profiles with multiple area terms\n";
echo str_repeat("-", 80) . "\n";

global $wpdb;
$multi_area_profiles = $wpdb->get_results("
    SELECT p.ID, p.post_title, COUNT(DISTINCT tr.term_taxonomy_id) as area_count
    FROM {$wpdb->posts} p
    INNER JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
    INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
    WHERE p.post_type = 'profile'
    AND p.post_status = 'publish'
    AND tt.taxonomy = 'area'
    GROUP BY p.ID
    HAVING area_count > 1
    LIMIT 10
");

if (!empty($multi_area_profiles)) {
    echo "Found profiles with multiple area terms:\n";
    foreach ($multi_area_profiles as $profile) {
        $area_terms = get_the_terms($profile->ID, 'area');
        $area_info = array();
        foreach ($area_terms as $at) {
            $area_info[] = "{$at->name} ({$at->slug})";
        }
        echo "  - Profile #{$profile->ID}: {$profile->post_title}\n";
        echo "    Area terms: " . implode(', ', $area_info) . "\n";
    }
} else {
    echo "No profiles found with multiple area terms.\n";
}

echo "\n\n";

// Root cause analysis
echo "ROOT CAUSE ANALYSIS\n";
echo str_repeat("=", 80) . "\n\n";

echo "The issue likely occurs in this sequence:\n\n";

echo "1. INITIAL STATE:\n";
echo "   - Area terms have unique names: 'Richmond - VA', 'Richmond - KY', etc.\n";
echo "   - Profiles are assigned to correct area terms by slug\n\n";

echo "2. CLEANUP RUNS (cleanup_area_terms):\n";
echo "   - Removes ' - ST' suffix from area term names\n";
echo "   - Now multiple terms have name 'Richmond' but different slugs\n";
echo "   - This is INTENTIONAL and OK for display purposes\n\n";

echo "3. CITY LISTING CREATION:\n";
echo "   - Code gets area term from profiles (line 587-591 in profile-production-queue.php)\n";
echo "   - Uses get_the_terms() which returns terms by term_id\n";
echo "   - Should be correct IF profiles have correct area terms\n\n";

echo "4. POTENTIAL ISSUE:\n";
echo "   - If a profile was manually edited or imported with wrong area term\n";
echo "   - OR if there's a bug in how profiles get their initial area term\n";
echo "   - The city listing inherits that wrong area term\n\n";

echo "5. ANOTHER POTENTIAL ISSUE:\n";
echo "   - If city_listing_exists() fails to find existing city listing\n";
echo "   - A duplicate might be created with different area term\n";
echo "   - Then one gets deleted/merged, leaving wrong area term\n\n";

echo "=== END OF INVESTIGATION ===\n";
