<?php
/**
 * Test script to check and fix image injection for specific posts
 * Run this in WordPress admin or via WP-CLI
 */

// Get the Elkridge post (replace with actual post ID)
$post_id = 12345; // Replace with the actual Elkridge post ID

echo "Testing image injection for post ID: $post_id\n";

// Check if post exists and is correct type
$post = get_post($post_id);
if (!$post) {
    echo "ERROR: Post not found\n";
    exit;
}

echo "Post title: " . $post->post_title . "\n";
echo "Post type: " . $post->post_type . "\n";

// Check for existing image meta
$img1_acf = function_exists('get_field') ? get_field('body_image_1', $post_id) : null;
$img2_acf = function_exists('get_field') ? get_field('body_image_2', $post_id) : null;
$img1_meta = get_post_meta($post_id, 'body_image_1', true);
$img2_meta = get_post_meta($post_id, 'body_image_2', true);

echo "ACF Image 1: " . print_r($img1_acf, true) . "\n";
echo "ACF Image 2: " . print_r($img2_acf, true) . "\n";
echo "Meta Image 1: $img1_meta\n";
echo "Meta Image 2: $img2_meta\n";

// If no images found, add test images
if (!$img1_meta && !$img2_meta) {
    echo "No images found. Adding test images...\n";
    
    // Find any attachment IDs to use as test
    $attachments = get_posts(array(
        'post_type' => 'attachment',
        'post_mime_type' => 'image',
        'posts_per_page' => 2,
        'post_status' => 'inherit'
    ));
    
    if (count($attachments) >= 2) {
        $test_img1 = $attachments[0]->ID;
        $test_img2 = $attachments[1]->ID;
        
        echo "Using test images: $test_img1, $test_img2\n";
        
        // Save via ACF if available
        if (function_exists('update_field')) {
            update_field('body_image_1', $test_img1, $post_id);
            update_field('body_image_2', $test_img2, $post_id);
        }
        
        // Always save to meta as fallback
        update_post_meta($post_id, 'body_image_1', $test_img1);
        update_post_meta($post_id, 'body_image_2', $test_img2);
        
        echo "Test images saved. Check the post now.\n";
    } else {
        echo "ERROR: Not enough attachments found for testing\n";
    }
}

// Test the injection logic manually
echo "\nTesting injection logic...\n";

$content = $post->post_content;
echo "Content length: " . strlen($content) . "\n";

// Count headings
preg_match_all('/<h([2-4])\b[^>]*>.*?<\/h\1>/is', $content, $headings);
echo "H2-H4 headings found: " . count($headings[0]) . "\n";

// Check for FAQ headings
preg_match_all('/<h([2-6])\b[^>]*>(.*?)<\/h\1>/is', $content, $all_headings, PREG_OFFSET_CAPTURE);
$faq_found = false;
foreach ($all_headings[2] as $heading) {
    $text = trim(wp_strip_all_tags($heading[0]));
    if (preg_match('/\bfaq\b|\bfaqs\b|frequently\s+asked|commonly\s+asked|common\s+questions|questions\s+about/i', $text)) {
        echo "FAQ heading found: $text\n";
        $faq_found = true;
    }
}

if (!$faq_found) {
    echo "No FAQ heading found\n";
}

echo "\nDone. Visit the post URL to see if images appear.\n";
