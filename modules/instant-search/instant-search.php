<?php
/**
 * Instant Search Module
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('DH_Instant_Search')) {
    class DH_Instant_Search {
        const OPTION_VERSION = 'dh_instant_search_index_version';
        const TRANSIENT_INDEX = 'dh_instant_search_index_json';

        // Default indexed post types. Adjust defaults here, or use the
        // 'dh_instant_search_post_types' filter to set site-wide types.
        private $default_post_types = array('city-listing', 'state-listing', 'profile');
        // Maps post type slugs to single-letter codes used by the client and
        // the REST filter parameter ?pt= (e.g., c,p,s).
        private $type_map = array(
            'city-listing'  => 'c',
            'state-listing' => 's',
            'profile'       => 'p',
        );

        public function __construct() {
            add_action('init', array($this, 'register_shortcodes'));
            add_action('wp_enqueue_scripts', array($this, 'register_assets'));

            add_action('rest_api_init', array($this, 'register_rest_routes'));

            // Invalidate index when relevant posts change
            add_action('transition_post_status', array($this, 'maybe_invalidate_on_transition'), 10, 3);
            add_action('deleted_post', array($this, 'invalidate_and_rebuild_index'));
            add_action('trashed_post', array($this, 'invalidate_and_rebuild_index'));
            add_action('untrashed_post', array($this, 'invalidate_and_rebuild_index'));
            
            // Admin actions
            add_action('admin_post_dh_rebuild_search_cache', array($this, 'handle_manual_cache_rebuild'));
            add_action('admin_notices', array($this, 'show_cache_rebuild_notice'));
            
            // WP-CLI command
            if (defined('WP_CLI') && WP_CLI) {
                WP_CLI::add_command('dh search rebuild-cache', array($this, 'cli_rebuild_cache'));
            }
        }

        public function register_shortcodes() {
            add_shortcode('dh_instant_search', array($this, 'render_shortcode'));
        }

        public function register_assets() {
            $base_url  = DIRECTORY_HELPERS_URL . 'modules/instant-search/assets/';
            $base_path = DIRECTORY_HELPERS_PATH . 'modules/instant-search/assets/';

            // Cache-busting: append filemtime to versions to avoid stale assets in browser/CDN
            $css_path = $base_path . 'css/instant-search.css';
            $js_path  = $base_path . 'js/instant-search.js';
            $css_ver  = DIRECTORY_HELPERS_VERSION . (file_exists($css_path) ? ('.' . filemtime($css_path)) : '');
            $js_ver   = DIRECTORY_HELPERS_VERSION . (file_exists($js_path) ? ('.' . filemtime($js_path)) : '');

            wp_register_style(
                'dh-instant-search',
                $base_url . 'css/instant-search.css',
                array(),
                $css_ver
            );

            wp_register_script(
                'dh-instant-search',
                $base_url . 'js/instant-search.js',
                array(),
                $js_ver,
                true
            );

            // Front-end config injected into window.dhInstantSearch for the JS.
            // Defaults can be set in admin (directory_helpers_options) and overridden via the
            // 'dh_instant_search_labels' filter.
            $opts = get_option('directory_helpers_options', []);
            $labels_from_opts = array(
                'c' => isset($opts['instant_search_label_c']) && $opts['instant_search_label_c'] !== '' ? $opts['instant_search_label_c'] : __('City Listings', 'directory-helpers'),
                'p' => isset($opts['instant_search_label_p']) && $opts['instant_search_label_p'] !== '' ? $opts['instant_search_label_p'] : __('Profiles', 'directory-helpers'),
                's' => isset($opts['instant_search_label_s']) && $opts['instant_search_label_s'] !== '' ? $opts['instant_search_label_s'] : __('States', 'directory-helpers'),
            );
            $labels = apply_filters('dh_instant_search_labels', $labels_from_opts);
            // ZIP min digits option
            $zip_min_digits = isset($opts['instant_search_zip_min_digits']) ? (int) $opts['instant_search_zip_min_digits'] : 3;
            if ($zip_min_digits < 1) { $zip_min_digits = 1; }
            if ($zip_min_digits > 5) { $zip_min_digits = 5; }
            wp_localize_script('dh-instant-search', 'dhInstantSearch', array(
                'restUrl' => esc_url_raw( rest_url('dh/v1/instant-index') ),
                'version' => (string) get_option(self::OPTION_VERSION, '0'),
                'labels'  => $labels,
                'zipMinDigits' => $zip_min_digits,
            ));
        }

        public function render_shortcode($atts = array(), $content = '') {
            // Shortcode attributes (per-instance). Tweak defaults here.
            // - post_types: CSV of post type slugs to index/filter (mapped to letters for client)
            // - min_chars: minimum characters before search runs
            // - debounce: input debounce in milliseconds
            // - limit: maximum results to display
            // - placeholder: input placeholder text (default filterable)
            // - label_c/label_p/label_s: per-instance headings for result groups
            $atts = shortcode_atts(array(
                'post_types' => implode(',', $this->default_post_types),
                'min_chars'  => 2,
                'debounce'   => 120,
                'limit'      => 12,
                'placeholder'=> '',
                'label_c'    => '',
                'label_p'    => '',
                'label_s'    => '',
                'theme'      => 'light', // 'light' (default) or 'dark'
            ), $atts, 'dh_instant_search');

            // Ensure assets are loaded
            wp_enqueue_style('dh-instant-search');
            wp_enqueue_script('dh-instant-search');

            $instance_id = 'dhis-' . wp_generate_password(6, false, false);

            // Normalize post types to letters for the client, but also pass raw list
            $pts = array_filter(array_map('trim', explode(',', $atts['post_types'])));
            $pts_letters = array();
            foreach ($pts as $pt) {
                $pts_letters[] = isset($this->type_map[$pt]) ? $this->type_map[$pt] : substr(sanitize_key($pt), 0, 1);
            }

            // Resolve placeholder default via admin option or filter when not provided.
            $placeholder = $atts['placeholder'];
            if ($placeholder === '' || $placeholder === null) {
                $opts = get_option('directory_helpers_options', []);
                $default_ph = isset($opts['instant_search_placeholder']) && $opts['instant_search_placeholder'] !== ''
                    ? $opts['instant_search_placeholder']
                    : __('Search by City, State, Zip, or Name …', 'directory-helpers');
                $placeholder = apply_filters('dh_instant_search_default_placeholder', $default_ph);
            }

            // Resolve theme class
            $theme = is_string($atts['theme']) ? strtolower($atts['theme']) : 'light';
            $theme = in_array($theme, array('light','dark'), true) ? $theme : 'light';
            $theme_class = ($theme === 'dark') ? ' dhis--dark' : '';

            ob_start();
            ?>
            <!-- dh-instant-search: data-* props below map to shortcode attributes (min_chars, debounce, limit, post_types, labels) -->
            <div class="dh-instant-search<?php echo esc_attr($theme_class); ?>" id="<?php echo esc_attr($instance_id); ?>" role="combobox" aria-expanded="false" aria-haspopup="listbox"
                data-label-c="<?php echo esc_attr($atts['label_c']); ?>"
                data-label-p="<?php echo esc_attr($atts['label_p']); ?>"
                data-label-s="<?php echo esc_attr($atts['label_s']); ?>"
            >
                <input type="search"
                    class="dhis-input"
                    aria-autocomplete="list"
                    aria-controls="<?php echo esc_attr($instance_id); ?>-list"
                    aria-activedescendant=""
                    placeholder="<?php echo esc_attr($placeholder); ?>"
                    data-min-chars="<?php echo (int) $atts['min_chars']; ?>"
                    data-debounce="<?php echo (int) $atts['debounce']; ?>"
                    data-limit="<?php echo (int) $atts['limit']; ?>"
                    data-post-types="<?php echo esc_attr(implode(',', $pts_letters)); ?>"
                />
                <div class="dhis-results" id="<?php echo esc_attr($instance_id); ?>-list" role="listbox" aria-label="<?php echo esc_attr__('Search results', 'directory-helpers'); ?>"></div>
            </div>
            <?php
            return trim(ob_get_clean());
        }

        public function register_rest_routes() {
            // REST endpoint for the prebuilt index. Optional filter by type letters: ?pt=c,p
            register_rest_route('dh/v1', '/instant-index', array(
                'methods'  => WP_REST_Server::READABLE,
                'callback' => array($this, 'rest_get_index'),
                'permission_callback' => '__return_true',
                'args' => array(
                    'pt' => array(
                        'description' => 'Comma-separated post type letters to include (c,s,p).',
                        'type' => 'string',
                        'required' => false,
                    ),
                ),
            ));
        }

        public function rest_get_index(WP_REST_Request $request) {
            $index = $this->get_index_data();

            // Optional filter by type letters via ?pt=c,p
            $pt_letters = array();
            $arg_pt = $request->get_param('pt');
            if (is_string($arg_pt) && $arg_pt !== '') {
                $pt_letters = array_filter(array_map('trim', explode(',', strtolower($arg_pt))));
                if (!empty($pt_letters)) {
                    $index['items'] = array_values(array_filter($index['items'], function($item) use ($pt_letters) {
                        return in_array($item['y'], $pt_letters, true);
                    }));
                }
            }

            return rest_ensure_response($index);
        }

        /**
         * Hooked to 'transition_post_status' to invalidate the index ONLY when
         * a post transitions TO or FROM publish status. This prevents cache clearing
         * on every save and only triggers when content actually becomes visible/invisible.
         */
        public function maybe_invalidate_on_transition($new_status, $old_status, $post) {
            if (!($post instanceof WP_Post)) {
                return;
            }
            
            // Only invalidate if transitioning TO or FROM publish
            $is_becoming_published = ($new_status === 'publish' && $old_status !== 'publish');
            $is_becoming_unpublished = ($old_status === 'publish' && $new_status !== 'publish');
            
            if (!$is_becoming_published && !$is_becoming_unpublished) {
                return;
            }
            
            // Allow site-wide control of which post types are indexed.
            $target_pts = apply_filters('dh_instant_search_post_types', $this->default_post_types);
            if (in_array($post->post_type, $target_pts, true)) {
                $this->invalidate_and_rebuild_index();
            }
        }

        /**
         * Invalidate the cache and immediately rebuild it in the background.
         * This ensures the cache is always ready and users never experience
         * the slow rebuild delay.
         */
        public function invalidate_and_rebuild_index($maybe_id = null) {
            // Delete the server-side cached index and bump the version so
            // browsers drop their localStorage cache.
            delete_transient(self::TRANSIENT_INDEX);
            $version = (string) ((int) get_option(self::OPTION_VERSION, 0) + 1);
            update_option(self::OPTION_VERSION, $version, false);
            
            // Immediately rebuild the cache in the background so it's ready
            // for the next request. This prevents users from experiencing
            // the 10-second rebuild delay.
            $this->get_index_data();
        }
        
        /**
         * Legacy method - kept for backwards compatibility.
         * Now calls the new invalidate_and_rebuild_index method.
         */
        public function invalidate_index($maybe_id = null) {
            $this->invalidate_and_rebuild_index($maybe_id);
        }

        // Retrieves cached index (12h transient). If missing/stale, rebuilds and caches.
        private function get_index_data() {
            $cached = get_transient(self::TRANSIENT_INDEX);
            if (is_array($cached) && isset($cached['items']) && isset($cached['version'])) {
                return $cached;
            }

            $items = $this->build_index_items();
            $data = array(
                'version' => (string) get_option(self::OPTION_VERSION, '1'),
                'items'   => $items,
            );

            // Cache for 7 days; only invalidated when posts are published/unpublished
            // Longer cache duration prevents unnecessary rebuilds and ensures fast search
            set_transient(self::TRANSIENT_INDEX, $data, WEEK_IN_SECONDS);
            return $data;
        }

        // Builds the index: queries all published posts for configured post types
        // and returns compact records used by the client.
        private function build_index_items() {
            $post_types = apply_filters('dh_instant_search_post_types', $this->default_post_types);

            $ids = array();
            foreach ($post_types as $pt) {
                $q = new WP_Query(array(
                    'post_type'      => $pt,
                    'post_status'    => 'publish',
                    'posts_per_page' => -1,
                    'fields'         => 'ids',
                    'no_found_rows'  => true,
                    'orderby'        => 'ID',
                    'order'          => 'DESC',
                ));
                if (!empty($q->posts)) {
                    foreach ($q->posts as $id) {
                        $ids[] = array($id, $pt);
                    }
                }
                wp_reset_postdata();
            }

            $items = array();
            foreach ($ids as $pair) {
                list($id, $pt) = $pair;
                $title = get_the_title($id);
                // Decode HTML entities so the client displays proper characters and normalization uses clean text.
                if ($title !== '' && $title !== null) {
                    $title = html_entity_decode($title, ENT_QUOTES | ENT_HTML5, get_bloginfo('charset'));
                }
                if ($title === '' || $title === null) {
                    continue;
                }
                $url = get_permalink($id);
                if (!$url) {
                    continue;
                }
                $n = $this->normalize($title);
                // Base item
                $item = array(
                    'i' => (int) $id,            // id
                    't' => $title,                // title
                    'u' => $url,                  // url
                    'y' => isset($this->type_map[$pt]) ? $this->type_map[$pt] : substr($pt, 0, 1), // type letter
                    'n' => $n,                    // normalized title
                );

                // Attach ZIP (as 'z') for profiles (type letter 'p') when available. The meta key is filterable.
                if (isset($item['y']) && $item['y'] === 'p') {
                    $zip_meta_key = apply_filters('dh_instant_search_profile_zip_meta_key', 'zip', $id);
                    $zip_raw = (string) get_post_meta($id, $zip_meta_key, true);
                    if ($zip_raw !== '') {
                        // Keep only digits; store the first 5 digits if available (handles ZIP+4).
                        $zip_digits = preg_replace('/\D+/', '', $zip_raw);
                        if (is_string($zip_digits) && strlen($zip_digits) >= 5) {
                            $item['z'] = substr($zip_digits, 0, 5);
                        }
                    }
                }

                $items[] = $item;
            }

            return $items;
        }

        // Server-side normalization to match the client-side tokenizer.
        private function normalize($str) {
            if (!function_exists('remove_accents')) {
                require_once ABSPATH . 'wp-includes/formatting.php';
            }
            $s = remove_accents((string) $str);
            $s = strtolower($s);
            // Replace non-alphanumeric with space
            $s = preg_replace('/[^a-z0-9]+/i', ' ', $s);
            // Collapse spaces
            $s = preg_replace('/\s+/', ' ', $s);
            return trim($s);
        }
        
        /**
         * Handle manual cache rebuild from admin page
         */
        public function handle_manual_cache_rebuild() {
            // Check permissions
            if (!current_user_can('manage_options')) {
                wp_die(__('You do not have permission to perform this action.', 'directory-helpers'));
            }
            
            // Verify nonce
            if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'dh_rebuild_search_cache')) {
                wp_die(__('Security check failed.', 'directory-helpers'));
            }
            
            // Rebuild the cache
            $this->invalidate_and_rebuild_index();
            
            // Set transient for success message
            set_transient('dh_search_cache_rebuilt', true, 30);
            
            // Redirect back to settings page
            wp_safe_redirect(admin_url('admin.php?page=directory-helpers'));
            exit;
        }
        
        /**
         * Show admin notice after cache rebuild
         */
        public function show_cache_rebuild_notice() {
            if (get_transient('dh_search_cache_rebuilt')) {
                delete_transient('dh_search_cache_rebuilt');
                ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php _e('Search cache has been successfully rebuilt!', 'directory-helpers'); ?></p>
                </div>
                <?php
            }
        }
        
        /**
         * WP-CLI command to rebuild search cache
         * 
         * ## EXAMPLES
         * 
         *     wp dh search rebuild-cache
         */
        public function cli_rebuild_cache($args, $assoc_args) {
            WP_CLI::log('Rebuilding instant search cache...');
            
            $start = microtime(true);
            $this->invalidate_and_rebuild_index();
            $elapsed = round(microtime(true) - $start, 2);
            
            $cached = get_transient(self::TRANSIENT_INDEX);
            $count = isset($cached['items']) ? count($cached['items']) : 0;
            
            WP_CLI::success("Search cache rebuilt in {$elapsed}s with {$count} items indexed.");
        }
    }
}
