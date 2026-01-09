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
         * [--concurrency=<num>]
         * : Number of concurrent requests. Default: 2. Recommended: 3-5 for multi-CPU servers.
         *   Higher values = faster but more server load. Max: 10
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
            $concurrency = isset($assoc_args['concurrency']) ? (int) $assoc_args['concurrency'] : 2;
            $concurrency = max(1, min($concurrency, 10)); // Cap at 10 for safety
            $limit = isset($assoc_args['limit']) ? (int) $assoc_args['limit'] : 0;
            $dry_run = isset($assoc_args['dry-run']);
            $preset = isset($assoc_args['preset']) ? $assoc_args['preset'] : null;

            // Get URLs to process (sitemaps or direct URLs)
            $all_urls = $this->resolve_urls($args, $preset);

            if (empty($all_urls)) {
                WP_CLI::error("No URLs specified. Use sitemap filenames, full URLs, or --preset=<name>");
                return;
            }

            // Count sitemap vs direct URLs
            $sitemap_count = 0;
            $direct_count = 0;
            foreach ($args as $arg) {
                if (filter_var($arg, FILTER_VALIDATE_URL) && !$this->is_sitemap($arg)) {
                    $direct_count++;
                } else {
                    $sitemap_count++;
                }
            }

            if ($sitemap_count > 0) {
                WP_CLI::line("Sitemaps to process: {$sitemap_count}");
            }
            if ($direct_count > 0) {
                WP_CLI::line("Direct URLs to process: {$direct_count}");
            }
            WP_CLI::line("");

            if ($dry_run) {
                WP_CLI::line("DRY RUN MODE - URLs will be listed but not fetched");
                WP_CLI::line("");
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
            WP_CLI::line("Concurrency: {$concurrency} request(s) at a time");
            WP_CLI::line("Delay between batches: {$delay_ms}ms");
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

            if ($concurrency > 1) {
                // Process URLs in batches for concurrent requests
                $batches = array_chunk($all_urls, $concurrency);
                $processed = 0;
                
                foreach ($batches as $batch_index => $batch) {
                    $results = $this->prime_urls_concurrent($batch, $timeout);
                    
                    foreach ($batch as $batch_pos => $url) {
                        $processed++;
                        $result = $results[$url];
                        
                        if ($result['success']) {
                            $success_count++;
                            $status_char = '✓';
                            $cache_status = $result['cache_status'];
                            
                            if ($cache_status === 'hit') {
                                $cache_hit_count++;
                            } else {
                                $cache_miss_count++;
                            }
                            
                            WP_CLI::line("[{$processed}/{$total_urls}] {$status_char} {$result['status_code']} ({$cache_status}) {$url}");
                        } else {
                            $error_count++;
                            WP_CLI::line("[{$processed}/{$total_urls}] ✗ ERROR: {$result['error']} - {$url}");
                        }
                    }
                    
                    // Delay between batches (convert ms to microseconds)
                    if ($delay_ms > 0 && $batch_index < count($batches) - 1) {
                        usleep($delay_ms * 1000);
                    }
                    
                    // Progress update every ~50 URLs
                    if ($processed % 50 < $concurrency) {
                        $elapsed = round(microtime(true) - $start_time, 1);
                        $rate = round($processed / $elapsed, 1);
                        WP_CLI::line("--- Progress: {$processed}/{$total_urls} ({$rate} URLs/sec) ---");
                    }
                }
            } else {
                // Sequential processing (original behavior)
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
         * Check if a URL is a sitemap
         *
         * @param string $url URL to check
         * @return bool True if sitemap
         */
        private function is_sitemap($url) {
            return (strpos($url, 'sitemap.xml') !== false) || 
                   (strpos($url, 'sitemap_index.xml') !== false) ||
                   (preg_match('/\/sitemap.*\.xml$/i', $url));
        }

        /**
         * Find all numbered sitemaps for a given base name
         *
         * @param string $base_name Base sitemap name (e.g., 'city-listing-sitemap')
         * @return array Array of sitemap filenames
         */
        private function find_numbered_sitemaps($base_name) {
            $sitemaps = array();
            
            // Always check the base (no number)
            $base_sitemap = $base_name . '.xml';
            $base_url = trailingslashit($this->site_url) . $base_sitemap;
            if ($this->sitemap_exists($base_url)) {
                $sitemaps[] = $base_sitemap;
            }
            
            // Check numbered versions (1, 2, 3, etc.)
            $max_number = 200; // Reasonable limit to prevent infinite loops
            for ($i = 1; $i <= $max_number; $i++) {
                $numbered_sitemap = $base_name . $i . '.xml';
                $numbered_url = trailingslashit($this->site_url) . $numbered_sitemap;
                
                if ($this->sitemap_exists($numbered_url)) {
                    $sitemaps[] = $numbered_sitemap;
                } else {
                    // Stop at the first missing number (assuming sequential)
                    break;
                }
            }
            
            return $sitemaps;
        }

        /**
         * Check if a sitemap exists by making a HEAD request
         *
         * @param string $url Full URL to check
         * @return bool True if sitemap exists
         */
        private function sitemap_exists($url) {
            $response = wp_remote_head($url, array(
                'timeout' => 10,
                'user-agent' => $this->user_agent,
                'sslverify' => false,
            ));

            if (is_wp_error($response)) {
                return false;
            }

            $status_code = wp_remote_retrieve_response_code($response);
            return $status_code === 200;
        }

        /**
         * Resolve arguments to URLs (from sitemaps or direct URLs)
         *
         * @param array $args Sitemap filenames, full URLs, or sitemap URLs
         * @param string|null $preset Preset name
         * @return array Array of URLs to prime
         */
        private function resolve_urls($args, $preset) {
            $urls = array();

            // Handle presets
            if ($preset) {
                if ($preset === 'priority') {
                    $args = array(
                        'page-sitemap.xml',
                        'state-listing-sitemap.xml',
                        'certification-sitemap.xml',
                    );
                } elseif ($preset === 'listings') {
                    $args = array('state-listing-sitemap.xml');
                    
                    // Find all city-listing sitemaps (city-listing-sitemap.xml, city-listing-sitemap1.xml, etc.)
                    $city_sitemaps = $this->find_numbered_sitemaps('city-listing-sitemap');
                    $args = array_merge($args, $city_sitemaps);
                    
                } elseif ($preset === 'profiles') {
                    // Find all profile sitemaps (profile-sitemap.xml, profile-sitemap1.xml, etc.)
                    $args = $this->find_numbered_sitemaps('profile-sitemap');
                } else {
                    WP_CLI::error("Unknown preset: {$preset}. Available: priority, listings, profiles");
                    return array();
                }
            }

            foreach ($args as $arg) {
                // If it's a full URL
                if (filter_var($arg, FILTER_VALIDATE_URL)) {
                    if ($this->is_sitemap($arg)) {
                        // Parse sitemap and add its URLs
                        $sitemap_urls = $this->parse_sitemap($arg);
                        if ($sitemap_urls !== false) {
                            WP_CLI::line("Found " . count($sitemap_urls) . " URLs in " . basename($arg));
                            $urls = array_merge($urls, $sitemap_urls);
                        } else {
                            WP_CLI::warning("Failed to parse sitemap: {$arg}");
                        }
                    } else {
                        // Direct URL, add as-is
                        $urls[] = $arg;
                    }
                } else {
                    // Assume it's a sitemap filename, prepend site URL
                    $sitemap_url = trailingslashit($this->site_url) . ltrim($arg, '/');
                    $sitemap_urls = $this->parse_sitemap($sitemap_url);
                    if ($sitemap_urls !== false) {
                        WP_CLI::line("Found " . count($sitemap_urls) . " URLs in " . basename($arg));
                        $urls = array_merge($urls, $sitemap_urls);
                    } else {
                        WP_CLI::warning("Failed to parse sitemap: {$arg}");
                    }
                }
            }

            return $urls;
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
         * Prime multiple URLs concurrently using curl_multi
         *
         * @param array $urls Array of URLs to fetch
         * @param int $timeout Request timeout in seconds
         * @return array Associative array of URL => result
         */
        private function prime_urls_concurrent($urls, $timeout) {
            $mh = curl_multi_init();
            $handles = array();
            $results = array();
            
            // Initialize all curl handles
            foreach ($urls as $url) {
                $ch = curl_init($url);
                curl_setopt_array($ch, array(
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => $timeout,
                    CURLOPT_USERAGENT => $this->user_agent,
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_HEADER => true,
                    CURLOPT_NOBODY => false,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_MAXREDIRS => 5,
                ));
                curl_multi_add_handle($mh, $ch);
                $handles[(int)$ch] = array('url' => $url, 'handle' => $ch);
            }
            
            // Execute all handles
            $running = null;
            do {
                curl_multi_exec($mh, $running);
                curl_multi_select($mh, 0.1);
            } while ($running > 0);
            
            // Collect results
            foreach ($handles as $data) {
                $ch = $data['handle'];
                $url = $data['url'];
                $response = curl_multi_getcontent($ch);
                $info = curl_getinfo($ch);
                $error = curl_error($ch);
                
                if ($error) {
                    $results[$url] = array(
                        'success' => false,
                        'error' => $error,
                        'status_code' => 0,
                        'cache_status' => 'error',
                    );
                } else {
                    // Parse headers for cache status
                    $header_size = $info['header_size'];
                    $headers = substr($response, 0, $header_size);
                    $cache_status = 'miss';
                    
                    if (preg_match('/x-qc-cache:\s*(hit|miss)/i', $headers, $match)) {
                        $cache_status = strtolower($match[1]);
                    } elseif (preg_match('/x-litespeed-cache:\s*([^\r\n]+)/i', $headers, $match)) {
                        $cache_status = (strpos($match[1], 'hit') !== false) ? 'hit' : 'miss';
                    } elseif (preg_match('/x-cache:\s*([^\r\n]+)/i', $headers, $match)) {
                        $cache_status = (strpos(strtolower($match[1]), 'hit') !== false) ? 'hit' : 'miss';
                    }
                    
                    $success = ($info['http_code'] >= 200 && $info['http_code'] < 400);
                    
                    $results[$url] = array(
                        'success' => $success,
                        'status_code' => $info['http_code'],
                        'cache_status' => $cache_status,
                        'error' => $success ? null : "HTTP {$info['http_code']}",
                    );
                }
                
                curl_multi_remove_handle($mh, $ch);
                curl_close($ch);
            }
            
            curl_multi_close($mh);
            return $results;
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

            // Check for cache status - LiteSpeed uses multiple headers
            $cache_status = 'miss';
            
            // Check x-qc-cache header (primary LiteSpeed cache status)
            if (isset($headers['x-qc-cache'])) {
                $cache_status = (strpos(strtolower($headers['x-qc-cache']), 'hit') !== false) ? 'hit' : 'miss';
            }
            // Fallback to x-litespeed-cache header
            elseif (isset($headers['x-litespeed-cache'])) {
                $cache_status = (strpos($headers['x-litespeed-cache'], 'hit') !== false) ? 'hit' : 'miss';
            }
            // Fallback to x-cache header
            elseif (isset($headers['x-cache'])) {
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
