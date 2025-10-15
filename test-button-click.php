<?php
/**
 * Test the Replace Featured Image button endpoint
 * Run with: wp eval-file wp-content/plugins/directory-helpers/test-button-click.php
 */

if (!defined('WP_CLI') || !WP_CLI) {
    die('This script must be run via WP-CLI');
}

// Get an admin user
$admin = get_users(['role' => 'administrator', 'number' => 1])[0];
if (!$admin) {
    WP_CLI::error('No admin user found');
}

// Set current user
wp_set_current_user($admin->ID);

WP_CLI::line('Testing as user: ' . $admin->user_login . ' (ID: ' . $admin->ID . ')');

// Get a test post
$post = get_posts([
    'post_type' => 'city-listing',
    'posts_per_page' => 1,
    'post_status' => 'any'
])[0];

if (!$post) {
    WP_CLI::error('No city-listing post found');
}

WP_CLI::line('Testing with post: ' . $post->post_title . ' (ID: ' . $post->ID . ')');

// Create request object
$request = new WP_REST_Request('POST', '/directory-helpers/v1/trigger-webhook');
$request->set_header('Content-Type', 'application/json');
$request->set_body(json_encode([
    'postId' => $post->ID,
    'postTitle' => $post->post_title,
    'keyword' => 'test keyword',
    'target' => 'featured-image'
]));

// Get the REST server
$server = rest_get_server();

// Dispatch the request
WP_CLI::line("\nSending request to endpoint...");
$response = $server->dispatch($request);

// Check response
if (is_wp_error($response)) {
    WP_CLI::error('Request failed: ' . $response->get_error_message());
}

$data = $response->get_data();
$status = $response->get_status();

WP_CLI::line("\nResponse Status: " . $status);
WP_CLI::line("Response Data:");
print_r($data);

if ($status === 200) {
    WP_CLI::success('Endpoint works correctly!');
} else {
    WP_CLI::error('Endpoint returned error status: ' . $status);
}
