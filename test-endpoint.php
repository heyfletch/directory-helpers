<?php
// Test the REST endpoint directly
require_once('/home/u_mix_132270_goo/files/wp-config.php');
require_once('/home/u_mix_132270_goo/files/wp-load.php');

// Include the prep city listings class
include_once('/home/u_mix_132270_goo/files/wp-content/plugins/directory-helpers/modules/prep-city-listings/prep-city-listings.php');

// Create instance and test
$prep_city = new DH_Prep_City_Listings();
$cities = $prep_city->get_cities_needing_listings('dog-trainer');

echo "Total cities: " . count($cities) . "\n";
echo "First 10 cities:\n";

$duplicate_check = array();
$has_duplicates = false;

for ($i = 0; $i < min(10, count($cities)); $i++) {
    $city = $cities[$i];
    echo ($i + 1) . ". {$city->slug} ({$city->profile_count} profiles)\n";
    
    // Check for duplicates
    if (in_array($city->slug, $duplicate_check)) {
        echo "*** DUPLICATE FOUND: {$city->slug} ***\n";
        $has_duplicates = true;
    }
    $duplicate_check[] = $city->slug;
}

// Check for bristol-tn specifically
$bristol_count = 0;
foreach ($cities as $city) {
    if ($city->slug === 'bristol-tn') {
        $bristol_count++;
        echo "Found bristol-tn at position " . ($bristol_count) . " with {$city->profile_count} profiles\n";
    }
}

if ($bristol_count > 1) {
    echo "*** bristol-tn appears {$bristol_count} times ***\n";
}

// Show sorting order
echo "\nChecking sort order (should be descending by profile count):\n";
for ($i = 0; $i < min(5, count($cities)); $i++) {
    echo ($i + 1) . ". {$cities[$i]->slug}: {$cities[$i]->profile_count} profiles\n";
}

// Check if properly sorted
$properly_sorted = true;
for ($i = 1; $i < min(10, count($cities)); $i++) {
    if ($cities[$i]->profile_count > $cities[$i-1]->profile_count) {
        echo "*** SORTING ERROR: {$cities[$i]->slug} ({$cities[$i]->profile_count}) > {$cities[$i-1]->slug} ({$cities[$i-1]->profile_count}) ***\n";
        $properly_sorted = false;
    }
}

if ($properly_sorted) {
    echo "✓ Sorting is correct (descending by profile count)\n";
}
