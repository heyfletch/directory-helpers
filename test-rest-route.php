<?php
/**
 * Test if REST route is registered
 * 
 * Add this to your theme's functions.php temporarily:
 * 
 * add_action('rest_api_init', function() {
 *     error_log('REST API Init fired');
 *     error_log('DH_AI_Content_Generator exists: ' . (class_exists('DH_AI_Content_Generator') ? 'YES' : 'NO'));
 * }, 999);
 * 
 * Then check your error log after loading any page.
 * 
 * OR run this via WP-CLI:
 * wp eval-file test-rest-route.php
 */

if (defined('WP_CLI') && WP_CLI) {
    // Check if class exists
    WP_CLI::line('DH_AI_Content_Generator class exists: ' . (class_exists('DH_AI_Content_Generator') ? 'YES' : 'NO'));
    
    // Check REST routes
    $routes = rest_get_server()->get_routes();
    WP_CLI::line('Total REST routes: ' . count($routes));
    
    if (isset($routes['/directory-helpers/v1/trigger-webhook'])) {
        WP_CLI::success('trigger-webhook route IS registered!');
    } else {
        WP_CLI::error('trigger-webhook route NOT found!');
        
        // List all directory-helpers routes
        WP_CLI::line('Looking for directory-helpers routes:');
        foreach ($routes as $route => $handlers) {
            if (strpos($route, 'directory-helpers') !== false) {
                WP_CLI::line('  - ' . $route);
            }
        }
    }
}
