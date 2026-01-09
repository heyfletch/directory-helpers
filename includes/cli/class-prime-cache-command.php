<?php
/**
 * WP-CLI command for selective cache priming from XML sitemaps.
 * 
 * Uses bot User-Agent to avoid triggering analytics tracking.
 * Makes server-side HTTP requests that don't execute JavaScript.
 *
 * @package Directory_Helpers
 */

if (!class_exists('DH_Prime_Cache_Command')) {
    class DH_Prime_Cache_Command extends WP_CLI_Command {

        /**
         * Bot User-Agent string - recognized by analytics tools and excluded from tracking
         */
        private $user_agent;

        /**
         * Site URL for sitemap resolution
         */
        private $site_url;

        /**
         * Constructor
         */
        public function __construct() {
            $this->site_url = home_url();
            $this->user_agent = 'Mozilla/5.0 (compatible; WordPress-CachePrimer/1.0; +' . $this->site_url . ')';
        }

        /**
         * Prime cache for URLs from specific XML sitemaps.
         *
         * Fetches URLs from XML sitemaps and makes HTTP requests to prime the cache.
         * Uses a bot User-Agent to avoid triggering analytics (GA, AnalyticsWP, etc.).
         *
         * ## OPTIONS
         *
         * [<sitemap>...]
         * : One or more sitemap filenames or URLs to process.
         *   Can be full URLs or just filenames (e.g., page-sitemap.xml)
         *
         * [--preset=<name>]
         * : Use a predefined sitemap group instead of specifying individual sitemaps.
         *   Available presets:
         *   - priority: page, state-listing, certification sitemaps
         *   - listings: city-listing, state-listing sitemaps
         *   - profiles: profile sitemaps
         *
         * [--delay=<ms>]
         * : Delay between requests in milliseconds. Default: 100
         *
         * [--timeout=<seconds>]
         * : Request timeout in seconds. Default: 30
         *
         * [--limit=<num>]
         * : Maximum number of URLs to process. Default: no limit
         *
         * [--dry-run]
         * : Show URLs without fetching them
         *
         * ## EXAMPLES
         *
         *     # Prime cache for specific sitemaps
         *     wp directory-helpers prime-cache page-sitemap.xml state-listing-sitemap.xml
         *
         *     # Use a preset group
         *     wp directory-helpers prime-cache --preset=priority
         *
         *     # Dry run to see what would be fetched
         *     wp directory-helpers prime-cache --preset=priority --dry-run
         *
         *     # With custom delay (slower, gentler on server)
         *     wp directory-helpers prime-cache --preset=listings --delay=500
         *
         * @when after_wp_load
         */
        public function __invoke($args, $assoc_args) {
            // Flush output buffers for real-time output
            while (ob_get_level()) {
                ob_end_flush();
            }

            WP_CLI::line("=== Cache Primer ===");
            WP_CLI::line("User-Agent: {$this->user_agent}");
            WP_CLI::line("(Bot UA ensures analytics tools exclude these requests)");
            WP_CLI::line("");

            // Parse options
            $delay_ms = isset($assoc_args['delay']) ? (int) $assoc_args['delay'] : 100;
            $timeout = isset($assoc_args['timeout']) ? (int) $assoc_args['timeout'] : 30;
            $limit = isset($assoc_args['limit']) ? (int) $assoc_args['limit'] : 0;
            $dry_run = isset($assoc_args['dry-run']);
            $preset = isset($assoc_args['preset']) ? $assoc_args['preset'] : null;

            // Get sitemap URLs
            $sitemaps = $this->resolve_sitemaps($args, $preset);

            if (empty($sitemaps)) {
                WP_CLI::error("No sitemaps specified. Use sitemap filenames or --preset=<name>");
                return;
            }

            WP_CLI::line("Sitemaps to process:");
            foreach ($sitemaps as $sitemap) {
                WP_CLI::line("  - {$sitemap}");
            }
            WP_CLI::line("");

            if ($dry_run) {
                WP_CLI::line("DRY RUN MODE - URLs will be listed but not fetched");
                WP_CLI::line("");
            }

            // Collect all URLs from sitemaps
            $all_urls = array();
            foreach ($sitemaps as $sitemap_url) {
                $urls = $this->parse_sitemap($sitemap_url);
                if ($urls === false) {
                    WP_CLI::warning("Failed to parse sitemap: {$sitemap_url}");
                    continue;
                }
                WP_CLI::line("Found " . count($urls) . " URLs in " . basename($sitemap_url));
                $all_urls = array_merge($all_urls, $urls);
            }

            // Remove duplicates
            $all_urls = array_unique($all_urls);
            $total_urls = count($all_urls);

            if ($total_urls === 0) {
                WP_CLI::warning("No URLs found in specified sitemaps.");
                return;
            }

            // Apply limit if specified
            if ($limit > 0 && $total_urls > $limit) {
                $all_urls = array_slice($all_urls, 0, $limit);
                WP_CLI::line("Limited to {$limit} URLs (of {$total_urls} total)");
                $total_urls = $limit;
            }

            WP_CLI::line("");
            WP_CLI::line("Total URLs to process: {$total_urls}");
            WP_CLI::line("Delay between requests: {$delay_ms}ms");
            WP_CLI::line("");

            if ($dry_run) {
                WP_CLI::line("--- URL List (dry run) ---");
                foreach ($all_urls as $url) {
                    WP_CLI::line($url);
                }
                WP_CLI::success("Dry run complete. {$total_urls} URLs would be fetched.");
                return;
            }

            // Prime cache for each URL
            $start_time = microtime(true);
            $success_count = 0;
            $error_count = 0;
            $cache_hit_count = 0;
            $cache_miss_count = 0;

            foreach ($all_urls as $index => $url) {
                $num = $index + 1;
                $result = $this->prime_url($url, $timeout);

                if ($result['success']) {
                    $success_count++;
                    $status_char = '✓';
                    $cache_status = $result['cache_status'];
                    
                    if ($cache_status === 'hit') {
                        $cache_hit_count++;
                    } else {
                        $cache_miss_count++;
                    }
                    
                    WP_CLI::line("[{$num}/{$total_urls}] {$status_char} {$result['status_code']} ({$cache_status}) {$url}");
                } else {
                    $error_count++;
                    WP_CLI::line("[{$num}/{$total_urls}] ✗ ERROR: {$result['error']} - {$url}");
                }

                // Delay between requests (convert ms to microseconds)
                if ($delay_ms > 0 && $index < $total_urls - 1) {
                    usleep($delay_ms * 1000);
                }

                // Progress update every 50 URLs
                if ($num % 50 === 0) {
                    $elapsed = round(microtime(true) - $start_time, 1);
                    $rate = round($num / $elapsed, 1);
                    WP_CLI::line("--- Progress: {$num}/{$total_urls} ({$rate} URLs/sec) ---");
                }
            }

            $total_time = round(microtime(true) - $start_time, 2);
            $rate = $total_urls > 0 ? round($total_urls / $total_time, 1) : 0;

            WP_CLI::line("");
            WP_CLI::line("=== Summary ===");
            WP_CLI::line("Total URLs: {$total_urls}");
            WP_CLI::line("Successful: {$success_count}");
            WP_CLI::line("Errors: {$error_count}");
            WP_CLI::line("Cache Hits: {$cache_hit_count} (already cached)");
            WP_CLI::line("Cache Misses: {$cache_miss_count} (newly cached)");
            WP_CLI::line("Time: {$total_time}s ({$rate} URLs/sec)");

            WP_CLI::success("Cache priming complete!");
        }

        /**
         * Resolve sitemap arguments to full URLs
         *
         * @param array $args Sitemap filenames or URLs
         * @param string|null $preset Preset name
         * @return array Full sitemap URLs
         */
        private function resolve_sitemaps($args, $preset) {
            $sitemaps = array();

            // Handle presets
            if ($preset) {
                $presets = array(
                    'priority' => array(
                        'page-sitemap.xml',
                        'state-listing-sitemap.xml',
                        'certification-sitemap.xml',
                    ),
                    'listings' => array(
                        'city-listing-sitemap.xml',
                        'state-listing-sitemap.xml',
                    ),
                    'profiles' => array(
                        'profile-sitemap.xml',
                        'profile-sitemap2.xml',
                        'profile-sitemap3.xml',
                    ),
                );

                if (!isset($presets[$preset])) {
                    WP_CLI::error("Unknown preset: {$preset}. Available: " . implode(', ', array_keys($presets)));
                    return array();
                }

                $args = $presets[$preset];
            }

            // Resolve each sitemap to full URL
            foreach ($args as $sitemap) {
                if (filter_var($sitemap, FILTER_VALIDATE_URL)) {
                    // Already a full URL
                    $sitemaps[] = $sitemap;
                } else {
                    // Assume it's a filename, prepend site URL
                    $sitemaps[] = trailingslashit($this->site_url) . ltrim($sitemap, '/');
                }
            }

            return $sitemaps;
        }

        /**
         * Parse an XML sitemap and extract URLs
         *
         * @param string $sitemap_url Full URL to the sitemap
         * @return array|false Array of URLs or false on failure
         */
        private function parse_sitemap($sitemap_url) {
            $response = wp_remote_get($sitemap_url, array(
                'timeout' => 60,
                'user-agent' => $this->user_agent,
                'sslverify' => false,
            ));

            if (is_wp_error($response)) {
                WP_CLI::warning("Failed to fetch sitemap: " . $response->get_error_message());
                return false;
            }

            $body = wp_remote_retrieve_body($response);
            if (empty($body)) {
                WP_CLI::warning("Empty response from sitemap");
                return false;
            }

            // Suppress XML errors and parse
            libxml_use_internal_errors(true);
            $xml = simplexml_load_string($body);
            libxml_clear_errors();

            if ($xml === false) {
                WP_CLI::warning("Failed to parse XML");
                return false;
            }

            $urls = array();

            // Handle sitemap index (contains other sitemaps)
            if (isset($xml->sitemap)) {
                foreach ($xml->sitemap as $sitemap) {
                    if (isset($sitemap->loc)) {
                        $nested_urls = $this->parse_sitemap((string) $sitemap->loc);
                        if ($nested_urls !== false) {
                            $urls = array_merge($urls, $nested_urls);
                        }
                    }
                }
            }

            // Handle regular sitemap (contains URLs)
            if (isset($xml->url)) {
                foreach ($xml->url as $url_entry) {
                    if (isset($url_entry->loc)) {
                        $urls[] = (string) $url_entry->loc;
                    }
                }
            }

            return $urls;
        }

        /**
         * Prime a single URL by making an HTTP request
         *
         * @param string $url URL to fetch
         * @param int $timeout Request timeout in seconds
         * @return array Result with success, status_code, cache_status, error
         */
        private function prime_url($url, $timeout) {
            $response = wp_remote_get($url, array(
                'timeout' => $timeout,
                'user-agent' => $this->user_agent,
                'sslverify' => false,
                'redirection' => 5,
            ));

            if (is_wp_error($response)) {
                return array(
                    'success' => false,
                    'error' => $response->get_error_message(),
                    'status_code' => 0,
                    'cache_status' => 'error',
                );
            }

            $status_code = wp_remote_retrieve_response_code($response);
            $headers = wp_remote_retrieve_headers($response);

            // Check for LiteSpeed cache header
            $cache_status = 'miss';
            if (isset($headers['x-litespeed-cache'])) {
                $cache_status = (strpos($headers['x-litespeed-cache'], 'hit') !== false) ? 'hit' : 'miss';
            } elseif (isset($headers['x-cache'])) {
                $cache_status = (strpos(strtolower($headers['x-cache']), 'hit') !== false) ? 'hit' : 'miss';
            }

            $success = ($status_code >= 200 && $status_code < 400);

            return array(
                'success' => $success,
                'status_code' => $status_code,
                'cache_status' => $cache_status,
                'error' => $success ? null : "HTTP {$status_code}",
            );
        }
    }
}
