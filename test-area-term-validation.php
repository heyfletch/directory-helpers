<?php
/**
 * Test script to verify area term validation works correctly
 */

if (!defined('ABSPATH')) {
    require_once(__DIR__ . '/../../../wp-load.php');
}

echo "=== Testing Area Term Validation ===\n\n";

// Test the validation logic
function test_area_term_validation($area_terms, $state_slug) {
    $correct_area_term = null;
    foreach ($area_terms as $area_term) {
        if (preg_match('/-' . preg_quote($state_slug, '/') . '$/', $area_term->slug)) {
            $correct_area_term = $area_term;
            break;
        }
    }
    return $correct_area_term ? $correct_area_term : $area_terms[0];
}

// Test Case 1: Profile with correct single area term
echo "TEST 1: Profile with single correct area term\n";
echo str_repeat("-", 60) . "\n";
$richmond_ky_term = get_term_by('slug', 'richmond-ky', 'area');
$area_terms = array($richmond_ky_term);
$state_slug = 'ky';
$result = test_area_term_validation($area_terms, $state_slug);
echo "State: $state_slug\n";
echo "Area terms: richmond-ky\n";
echo "Selected: {$result->slug}\n";
echo "Result: " . ($result->slug === 'richmond-ky' ? "✓ PASS" : "✗ FAIL") . "\n\n";

// Test Case 2: Profile with multiple area terms (correct state)
echo "TEST 2: Profile with multiple area terms (one matches state)\n";
echo str_repeat("-", 60) . "\n";
$richmond_va_term = get_term_by('slug', 'richmond-va', 'area');
$richmond_ky_term = get_term_by('slug', 'richmond-ky', 'area');
if ($richmond_va_term && $richmond_ky_term) {
    $area_terms = array($richmond_va_term, $richmond_ky_term);
    $state_slug = 'ky';
    $result = test_area_term_validation($area_terms, $state_slug);
    echo "State: $state_slug\n";
    echo "Area terms: richmond-va, richmond-ky\n";
    echo "Selected: {$result->slug}\n";
    echo "Result: " . ($result->slug === 'richmond-ky' ? "✓ PASS" : "✗ FAIL") . "\n\n";
}

// Test Case 3: Profile with wrong area term (fallback)
echo "TEST 3: Profile with wrong area term (no match, use fallback)\n";
echo str_repeat("-", 60) . "\n";
$richmond_va_term = get_term_by('slug', 'richmond-va', 'area');
if ($richmond_va_term) {
    $area_terms = array($richmond_va_term);
    $state_slug = 'ky';
    $result = test_area_term_validation($area_terms, $state_slug);
    echo "State: $state_slug\n";
    echo "Area terms: richmond-va\n";
    echo "Selected: {$result->slug}\n";
    echo "Result: " . ($result->slug === 'richmond-va' ? "✓ PASS (fallback)" : "✗ FAIL") . "\n\n";
}

// Test Case 4: Multiple area terms, first is wrong
echo "TEST 4: Multiple area terms, first is wrong, second is correct\n";
echo str_repeat("-", 60) . "\n";
$greenville_tx_term = get_term_by('slug', 'greenville-tx', 'area');
$greenville_sc_term = get_term_by('slug', 'greenville-sc', 'area');
if ($greenville_tx_term && $greenville_sc_term) {
    $area_terms = array($greenville_tx_term, $greenville_sc_term);
    $state_slug = 'sc';
    $result = test_area_term_validation($area_terms, $state_slug);
    echo "State: $state_slug\n";
    echo "Area terms: greenville-tx, greenville-sc\n";
    echo "Selected: {$result->slug}\n";
    echo "Result: " . ($result->slug === 'greenville-sc' ? "✓ PASS" : "✗ FAIL") . "\n\n";
}

// Test Case 5: Full state name (kentucky vs ky)
echo "TEST 5: State slug is full name (kentucky), area has code (ky)\n";
echo str_repeat("-", 60) . "\n";
$richmond_ky_term = get_term_by('slug', 'richmond-ky', 'area');
if ($richmond_ky_term) {
    $area_terms = array($richmond_ky_term);
    $state_slug = 'kentucky';
    $result = test_area_term_validation($area_terms, $state_slug);
    echo "State: $state_slug\n";
    echo "Area terms: richmond-ky\n";
    echo "Selected: {$result->slug}\n";
    echo "Result: " . ($result->slug === 'richmond-ky' ? "✗ FAIL (expected, needs 2-letter code)" : "✓ PASS (fallback)") . "\n";
    echo "Note: This is expected behavior - state slug should be 2-letter code\n\n";
}

echo "=== END OF TESTS ===\n";
