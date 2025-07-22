<?php
/**
 * Update Area Term Format Script
 * 
 * This script updates area term names from "City, ST" format to "City - ST" format.
 * Run this script by accessing it directly in your browser or via command line.
 * 
 * @package Directory_Helpers
 */

// Load WordPress
require_once('../../../wp-load.php');

// Check if user has admin capabilities when run via browser
if (!defined('WP_CLI') && (!is_user_logged_in() || !current_user_can('manage_options'))) {
    wp_die('You do not have permission to run this script.');
}

// Get the dry_run parameter
$dry_run = isset($_GET['dry_run']) || (isset($argv) && in_array('--dry-run', $argv));

if ($dry_run) {
    echo "<h2>DRY RUN MODE - No changes will be made</h2>\n";
} else {
    echo "<h2>UPDATING AREA TERMS</h2>\n";
}

// Get all area terms
$terms = get_terms(array(
    'taxonomy'   => 'area',
    'hide_empty' => false,
));

if (is_wp_error($terms)) {
    echo "Error: Could not retrieve area terms.\n";
    exit;
}

$updated_count = 0;
$changes = array();

foreach ($terms as $term) {
    // Check if the term name matches the "City, ST" pattern
    if (preg_match('/^(.+), ([A-Z]{2})$/', $term->name, $matches)) {
        $city_name = $matches[1];
        $state_abbr = $matches[2];
        $new_name = $city_name . ' - ' . $state_abbr;
        
        $changes[] = array(
            'term_id' => $term->term_id,
            'old_name' => $term->name,
            'new_name' => $new_name
        );
        
        if (!$dry_run) {
            $result = wp_update_term($term->term_id, 'area', array('name' => $new_name));
            if (is_wp_error($result)) {
                echo "Warning: Failed to update term {$term->term_id}: " . $result->get_error_message() . "\n";
            } else {
                $updated_count++;
            }
        }
    }
}

// Display results
echo "<h3>Results:</h3>\n";
echo "<p>Found " . count($changes) . " terms to update:</p>\n";

if (!empty($changes)) {
    echo "<ul>\n";
    foreach ($changes as $change) {
        echo "<li>Term ID {$change['term_id']}: \"{$change['old_name']}\" → \"{$change['new_name']}\"</li>\n";
    }
    echo "</ul>\n";
}

if ($dry_run) {
    echo "<p><strong>Dry run complete. No changes were made.</strong></p>\n";
    echo "<p>To apply these changes, run the script without the dry_run parameter:</p>\n";
    echo "<p><a href=\"" . remove_query_arg('dry_run') . "\">Apply Changes</a></p>\n";
} else {
    echo "<p><strong>Operation complete. Updated {$updated_count} term(s).</strong></p>\n";
}
?>
