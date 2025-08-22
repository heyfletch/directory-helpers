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
            add_action('save_post', array($this, 'maybe_invalidate_index'), 10, 3);
            add_action('deleted_post', array($this, 'invalidate_index'));
            add_action('trashed_post', array($this, 'invalidate_index'));
            add_action('untrashed_post', array($this, 'invalidate_index'));
        }

        public function register_shortcodes() {
            add_shortcode('dh_instant_search', array($this, 'render_shortcode'));
        }

        public function register_assets() {
            $base_url = DIRECTORY_HELPERS_URL . 'modules/instant-search/assets/';

            wp_register_style(
                'dh-instant-search',
                $base_url . 'css/instant-search.css',
                array(),
                DIRECTORY_HELPERS_VERSION
            );

            wp_register_script(
                'dh-instant-search',
                $base_url . 'js/instant-search.js',
                array(),
                DIRECTORY_HELPERS_VERSION,
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
            wp_localize_script('dh-instant-search', 'dhInstantSearch', array(
                'restUrl' => esc_url_raw( rest_url('dh/v1/instant-index') ),
                'version' => (string) get_option(self::OPTION_VERSION, '0'),
                'labels'  => $labels,
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
                    : __('Search…', 'directory-helpers');
                $placeholder = apply_filters('dh_instant_search_default_placeholder', $default_ph);
            }

            ob_start();
            ?>
            <!-- dh-instant-search: data-* props below map to shortcode attributes (min_chars, debounce, limit, post_types, labels) -->
            <div class="dh-instant-search" id="<?php echo esc_attr($instance_id); ?>" role="combobox" aria-expanded="false" aria-haspopup="listbox"
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
         * Hooked to 'save_post' to invalidate the index when a target post type
         * is published/trashed. Target post types are controlled via the
         * 'dh_instant_search_post_types' filter.
         */
        public function maybe_invalidate_index($post_ID, $post, $update) {
            if (!($post instanceof WP_Post)) {
                return;
            }
            if ($post->post_status !== 'publish' && $post->post_status !== 'trash') {
                return;
            }
            // Allow site-wide control of which post types are indexed.
            $target_pts = apply_filters('dh_instant_search_post_types', $this->default_post_types);
            if (in_array($post->post_type, $target_pts, true)) {
                $this->invalidate_index();
            }
        }

        public function invalidate_index($maybe_id = null) {
            // Delete the server-side cached index and bump the version so
            // browsers drop their localStorage cache.
            delete_transient(self::TRANSIENT_INDEX);
            $version = (string) ((int) get_option(self::OPTION_VERSION, 0) + 1);
            update_option(self::OPTION_VERSION, $version, false);
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

            // Cache for 12 hours; external invalidation bumps version and deletes transient
            set_transient(self::TRANSIENT_INDEX, $data, HOUR_IN_SECONDS * 12);
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
                if ($title === '' || $title === null) {
                    continue;
                }
                $url = get_permalink($id);
                if (!$url) {
                    continue;
                }
                $n = $this->normalize($title);
                $items[] = array(
                    'i' => (int) $id,            // id
                    't' => $title,                // title
                    'u' => $url,                  // url
                    'y' => isset($this->type_map[$pt]) ? $this->type_map[$pt] : substr($pt, 0, 1), // type letter
                    'n' => $n,                    // normalized title
                );
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
    }
}
