<?php
/**
 * Debug REST routes in web context
 * 
 * Access this file directly: https://goodydoggy.com/wp-content/plugins/directory-helpers/debug-routes.php
 */

// Load WordPress
require_once('../../../wp-load.php');

header('Content-Type: text/plain');

echo "=== REST Route Debug ===\n\n";

// Check if class exists
echo "DH_AI_Content_Generator class exists: " . (class_exists('DH_AI_Content_Generator') ? 'YES' : 'NO') . "\n\n";

// Get all routes
$routes = rest_get_server()->get_routes();
echo "Total REST routes: " . count($routes) . "\n\n";

// Check specific route
if (isset($routes['/directory-helpers/v1/trigger-webhook'])) {
    echo "✓ trigger-webhook route IS registered!\n\n";
    echo "Route details:\n";
    print_r($routes['/directory-helpers/v1/trigger-webhook']);
} else {
    echo "✗ trigger-webhook route NOT found!\n\n";
}

// List all directory-helpers routes
echo "\n=== All directory-helpers routes ===\n";
foreach ($routes as $route => $handlers) {
    if (strpos($route, 'directory-helpers') !== false) {
        echo "  - " . $route . "\n";
    }
}

// Check if ai-content-generator module is loaded
echo "\n=== Module Status ===\n";
$dh = Directory_Helpers::get_instance();
$modules = $dh->get_modules();
echo "Total modules: " . count($modules) . "\n";
if (isset($modules['ai-content-generator'])) {
    echo "✓ ai-content-generator module is registered\n";
} else {
    echo "✗ ai-content-generator module NOT registered\n";
}
