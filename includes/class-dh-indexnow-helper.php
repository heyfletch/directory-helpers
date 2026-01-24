<?php
/**
 * IndexNow Helper Class
 *
 * Handles URL submission to IndexNow API (Bing, Yandex, etc.)
 * Uses RankMath's API key for authentication.
 *
 * @package Directory_Helpers
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class DH_IndexNow_Helper {

    /**
     * IndexNow API endpoint
     */
    const API_ENDPOINT = 'https://api.indexnow.org/indexnow';

    /**
     * Maximum URLs per batch (IndexNow limit is 10,000)
     */
    const MAX_BATCH_SIZE = 10000;

    /**
     * Get IndexNow API key from RankMath settings
     *
     * @return string|false API key or false if not found
     */
    private static function get_api_key() {
        // Try RankMath Instant Indexing option (main plugin)
        $options = get_option('rank-math-options-instant-indexing', array());

        if (!empty($options)) {
            // Check for 'indexnow_api_key' (RankMath standard)
            if (isset($options['indexnow_api_key']) && !empty(trim($options['indexnow_api_key']))) {
                return trim($options['indexnow_api_key']);
            }

            // Check for 'api_key' (possible variant)
            if (isset($options['api_key']) && !empty(trim($options['api_key']))) {
                return trim($options['api_key']);
            }

            // Check for 'apiKey' (camelCase variant)
            if (isset($options['apiKey']) && !empty(trim($options['apiKey']))) {
                return trim($options['apiKey']);
            }
        }

        // Try standalone instant indexing plugin option
        $standalone_options = get_option('instant_indexing_settings', array());
        if (!empty($standalone_options) && isset($standalone_options['api_key']) && !empty(trim($standalone_options['api_key']))) {
            return trim($standalone_options['api_key']);
        }

        // API key not found in any location
        error_log('DH IndexNow: API key not found. Checked rank-math-options-instant-indexing (indexnow_api_key, api_key, apiKey) and instant_indexing_settings (api_key)');
        return false;
    }

    /**
     * Submit URLs to IndexNow API
     *
     * @param string|array $urls Single URL string or array of URLs
     * @param int $batch_size Optional batch size (default: 10000, max: 10000)
     * @return array Results array with success/error info
     */
    public static function submit_urls($urls, $batch_size = 10000) {
        // Normalize input to array
        if (is_string($urls)) {
            $urls = array($urls);
        }

        if (empty($urls) || !is_array($urls)) {
            error_log('DH IndexNow: No URLs provided for submission');
            return array('success' => false, 'error' => 'No URLs provided');
        }

        // Get API key
        $api_key = self::get_api_key();
        if ($api_key === false) {
            return array('success' => false, 'error' => 'API key not configured. Check RankMath Instant Indexing settings.');
        }

        // Get site URL for host and keyLocation
        $site_url = home_url();
        $parsed_url = wp_parse_url($site_url);
        $host = isset($parsed_url['host']) ? $parsed_url['host'] : '';

        if (empty($host)) {
            error_log('DH IndexNow: Could not determine site host from ' . $site_url);
            return array('success' => false, 'error' => 'Could not determine site host');
        }

        // Build key location URL
        $key_location = trailingslashit($site_url) . $api_key . '.txt';

        // Validate and cap batch size
        $batch_size = max(1, min($batch_size, self::MAX_BATCH_SIZE));

        // Process URLs in batches
        $url_chunks = array_chunk($urls, $batch_size);
        $results = array(
            'success' => true,
            'total_urls' => count($urls),
            'batches' => count($url_chunks),
            'batch_size' => $batch_size,
            'batch_results' => array(),
        );

        foreach ($url_chunks as $batch_index => $batch_urls) {
            $batch_num = $batch_index + 1;

            // Build request payload
            $payload = array(
                'host' => $host,
                'key' => $api_key,
                'keyLocation' => $key_location,
                'urlList' => array_values($batch_urls), // Ensure numeric array
            );

            // Send request
            $response = wp_remote_post(self::API_ENDPOINT, array(
                'headers' => array(
                    'Content-Type' => 'application/json; charset=utf-8',
                ),
                'body' => wp_json_encode($payload),
                'timeout' => 30,
            ));

            // Process response
            if (is_wp_error($response)) {
                $error_message = $response->get_error_message();
                error_log(sprintf(
                    'DH IndexNow: Batch %d/%d failed - %s',
                    $batch_num,
                    count($url_chunks),
                    $error_message
                ));

                $results['success'] = false;
                $results['batch_results'][] = array(
                    'batch' => $batch_num,
                    'urls_count' => count($batch_urls),
                    'success' => false,
                    'error' => $error_message,
                );
                continue;
            }

            $response_code = wp_remote_retrieve_response_code($response);
            $response_body = wp_remote_retrieve_body($response);

            // IndexNow returns 200 for success, 202 for accepted
            $is_success = in_array($response_code, array(200, 202), true);

            if ($is_success) {
                error_log(sprintf(
                    'DH IndexNow: Batch %d/%d submitted successfully - %d URLs (HTTP %d)',
                    $batch_num,
                    count($url_chunks),
                    count($batch_urls),
                    $response_code
                ));
            } else {
                error_log(sprintf(
                    'DH IndexNow: Batch %d/%d failed - HTTP %d - %s',
                    $batch_num,
                    count($url_chunks),
                    $response_code,
                    $response_body
                ));
                $results['success'] = false;
            }

            $results['batch_results'][] = array(
                'batch' => $batch_num,
                'urls_count' => count($batch_urls),
                'success' => $is_success,
                'http_code' => $response_code,
                'response' => $response_body,
            );
        }

        return $results;
    }
}
