<?php
/**
 * Test token replacement cleanup
 * Run: php test-token-cleanup.php
 */

function clean_token_replacement_artifacts($text) {
    if (!is_string($text) || $text === '') { return ''; }
    
    // Fix orphaned comma patterns: " , " → " "
    $text = preg_replace('/ , /', ' ', $text);
    
    // Fix orphaned comma at start: "  , word" → " word"
    $text = preg_replace('/^\s*,\s*/', '', $text);
    
    // Collapse only double+ spaces (not newlines) to single space
    $text = preg_replace('/ {2,}/', ' ', $text);
    
    return $text;
}

echo "=== TEST 1: Video Title (State Page - Missing {area}) ===\n";
$input1 = "Find the Best Dog Training in  Montana";
$output1 = clean_token_replacement_artifacts($input1);
echo "INPUT:  '$input1'\n";
echo "OUTPUT: '$output1'\n";
echo "EXPECT: 'Find the Best Dog Training in Montana'\n";
echo "PASS:   " . ($output1 === "Find the Best Dog Training in Montana" ? "✓" : "✗") . "\n\n";

echo "=== TEST 2: YouTube Description (State Page - Missing {area}) ===\n";
$input2 = "For our ultimate guide to  , Montana dog trainers, costs, and local resources, visit:";
$output2 = clean_token_replacement_artifacts($input2);
echo "INPUT:  '$input2'\n";
echo "OUTPUT: '$output2'\n";
echo "EXPECT: 'For our ultimate guide to Montana dog trainers, costs, and local resources, visit:'\n";
echo "PASS:   " . ($output2 === "For our ultimate guide to Montana dog trainers, costs, and local resources, visit:" ? "✓" : "✗") . "\n\n";

echo "=== TEST 3: City Page (Has {area}) - Should Not Break ===\n";
$input3 = "Find the Best Dog Training in Burlington, Vermont";
$output3 = clean_token_replacement_artifacts($input3);
echo "INPUT:  '$input3'\n";
echo "OUTPUT: '$output3'\n";
echo "EXPECT: 'Find the Best Dog Training in Burlington, Vermont'\n";
echo "PASS:   " . ($output3 === "Find the Best Dog Training in Burlington, Vermont" ? "✓" : "✗") . "\n\n";

echo "=== TEST 4: Multi-line Description with Newlines ===\n";
$input4 = "For our ultimate guide to Burlington, Vermont dog trainers, costs, and local resources, visit:\nhttps://example.com\n\nThis video covers training methods, typical costs, certifications.";
$output4 = clean_token_replacement_artifacts($input4);
echo "INPUT:\n$input4\n\n";
echo "OUTPUT:\n$output4\n\n";
echo "EXPECT: (newlines preserved, no changes)\n";
echo "PASS:   " . ($output4 === $input4 ? "✓" : "✗") . "\n\n";

echo "=== TEST 5: State Page Multi-line with Missing {area} ===\n";
$input5 = "For our ultimate guide to  , Montana dog trainers, costs, and local resources, visit:\nhttps://example.com\n\nThis video covers training methods, typical costs, certifications.";
$output5 = clean_token_replacement_artifacts($input5);
echo "INPUT:\n$input5\n\n";
echo "OUTPUT:\n$output5\n\n";
$expected5 = "For our ultimate guide to Montana dog trainers, costs, and local resources, visit:\nhttps://example.com\n\nThis video covers training methods, typical costs, certifications.";
echo "EXPECT:\n$expected5\n\n";
echo "PASS:   " . ($output5 === $expected5 ? "✓" : "✗") . "\n\n";

echo "=== TEST 6: Multiple Double Spaces ===\n";
$input6 = "Find  the  Best  Dog  Training";
$output6 = clean_token_replacement_artifacts($input6);
echo "INPUT:  '$input6'\n";
echo "OUTPUT: '$output6'\n";
echo "EXPECT: 'Find the Best Dog Training'\n";
echo "PASS:   " . ($output6 === "Find the Best Dog Training" ? "✓" : "✗") . "\n\n";
