<?php
/**
 * Test script for the new /receive-featured-media endpoint
 * 
 * Usage examples:
 * 
 * 1. Test with only required fields (featured media):
 *    curl -X POST https://goodydoggy.com/wp-json/ai-content-plugin/v1/receive-featured-media \
 *      -H "Content-Type: application/json" \
 *      -d '{"secretKey":"YOUR_SECRET","postId":123,"featured_media":456}'
 * 
 * 2. Test with optional content:
 *    curl -X POST https://goodydoggy.com/wp-json/ai-content-plugin/v1/receive-featured-media \
 *      -H "Content-Type: application/json" \
 *      -d '{"secretKey":"YOUR_SECRET","postId":123,"featured_media":456,"content":"<p>New content</p>"}'
 * 
 * 3. Test with all optional fields:
 *    curl -X POST https://goodydoggy.com/wp-json/ai-content-plugin/v1/receive-featured-media \
 *      -H "Content-Type: application/json" \
 *      -d '{"secretKey":"YOUR_SECRET","postId":123,"featured_media":456,"content":"<p>New content</p>","image_1_id":789,"image_2_id":101}'
 * 
 * Required fields:
 * - secretKey: Your shared secret key from Directory Helpers settings
 * - postId: The ID of the post to update
 * - featured_media: The attachment ID to set as featured image
 * 
 * Optional fields:
 * - content: HTML content to update the post with
 * - image_1_id: Attachment ID for body image 1 (saved to ACF field)
 * - image_2_id: Attachment ID for body image 2 (saved to ACF field)
 * 
 * Response examples:
 * 
 * Success:
 * {
 *   "message": "Featured media set successfully.",
 *   "featured_media_set": true
 * }
 * 
 * With optional fields:
 * {
 *   "message": "Featured media set successfully.",
 *   "featured_media_set": true,
 *   "content_updated": true,
 *   "image_1_saved": true,
 *   "image_2_saved": true
 * }
 * 
 * Error (invalid secret):
 * {
 *   "code": "rest_forbidden",
 *   "message": "Invalid secret key.",
 *   "data": {"status": 403}
 * }
 * 
 * Error (missing postId):
 * {
 *   "code": "missing_parameters",
 *   "message": "Missing postId.",
 *   "data": {"status": 400}
 * }
 */

// This is a documentation file only - no executable code
