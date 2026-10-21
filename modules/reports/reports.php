<?php
/**
 * Reports module: serves the data reports pipeline's site bundle.
 *
 * What it serves (every address comes from the bundle's manifest, nothing is hard-coded here except the two
 * address prefixes in DH_Reports_Routes):
 *   /reports/                                   index
 *   /reports/<report>/                          the newest edition the viewer may see
 *   /reports/<report>/<edition>/                a frozen edition, plus its downloads and chart files
 *   /reports/press/                             press page
 *   /reports/embed/<chart>/                     framable chart page (noindex)
 *   /dog-training-statistics/                   statistics hub, plus its downloads
 *
 * Nothing is public until two switches are on: the page's WordPress record is published, and the site option
 * dh_reports_public is 1 (default 0). Until then every address answers 404 for visitors and renders only for
 * logged-in users with manage_options, noindex, uncached, with an admin ribbon.
 *
 * A bundle is a directory the pipeline writes (manifest.json, pages/*.html, files/*). It is stored outside the
 * web root, so no file is reachable except through this module's gate. Each page is one dh_report record
 * (draft until published). Import, publish, rollback and takedown run through WP-CLI:
 *   wp directory-helpers report-publish [<bundle-dir>] [--dry-run] [--publish] [--unpublish] [--rollback] [--status]
 *
 * @package Directory_Helpers
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/class-dh-reports-routes.php';

class DH_Reports {

    const POST_TYPE    = 'dh_report';
    const OPT_ROUTES   = 'dh_reports_routes';
    const OPT_PUBLIC   = 'dh_reports_public';
    const SCHEMA       = 1;
    const KINDS        = array('edition', 'hub', 'press', 'index', 'embed');
    const RENDERS      = array('chrome', 'raw');
    const FILE_TYPES   = array('csv', 'json', 'svg', 'png', 'pdf', 'txt');
    const MAX_FILE     = 8388608; // 8 MB per file.

    /** @var array<string, array> manifest cache per request, by bundle id */
    private static $manifests = array();

    /** @var array|null page being rendered, for the head callbacks */
    private static $current = null;

    public function __construct() {
        add_action('init', array($this, 'register'), 5);
        add_filter('query_vars', array($this, 'query_vars'));
        add_action('template_redirect', array($this, 'maybe_serve'), 0);
        add_filter('redirect_canonical', array($this, 'keep_our_urls'), 10, 2);
        add_filter('post_type_link', array($this, 'post_link'), 10, 2);
        add_action('wp_loaded', array($this, 'maybe_flush_rewrites'));
        add_filter('rank_math/sitemap/providers', array($this, 'sitemap_providers'));

        if (is_admin()) {
            add_filter('post_row_actions', array($this, 'row_actions'), 10, 2);
            add_action('add_meta_boxes_' . self::POST_TYPE, array($this, 'add_meta_box'));
            add_filter('manage_' . self::POST_TYPE . '_posts_columns', array($this, 'columns'));
            add_action('manage_' . self::POST_TYPE . '_posts_custom_column', array($this, 'column_value'), 10, 2);
        }

        if (defined('WP_CLI') && WP_CLI) {
            require_once __DIR__ . '/cli.php';
            WP_CLI::add_command('directory-helpers report-publish', 'DH_Reports_CLI');
        }
    }

    // ---------------------------------------------------------------------------------------------
    // Registration
    // ---------------------------------------------------------------------------------------------

    public function register() {
        register_post_type(self::POST_TYPE, array(
            'labels' => array(
                'name' => __('Reports', 'directory-helpers'),
                'singular_name' => __('Report page', 'directory-helpers'),
                'menu_name' => __('Reports', 'directory-helpers'),
                'edit_item' => __('Report page', 'directory-helpers'),
                'search_items' => __('Search report pages', 'directory-helpers'),
                'not_found' => __('No report pages. Import a bundle with wp directory-helpers report-publish.', 'directory-helpers'),
            ),
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => 'directory-helpers',
            'show_in_rest' => false,
            'supports' => array('title'),
            'capability_type' => 'post',
            'map_meta_cap' => true,
            'capabilities' => array('create_posts' => 'do_not_allow'),
            'rewrite' => false,
            'query_var' => false,
            'has_archive' => false,
            'exclude_from_search' => true,
        ));

        // Pages and files arrive as raw request paths; the query var only marks "this is ours".
        add_rewrite_rule('^reports(/.*)?$', 'index.php?dh_reports=1', 'top');
        add_rewrite_rule('^dog-training-statistics(/.*)?$', 'index.php?dh_reports=1', 'top');
    }

    public function query_vars($vars) {
        $vars[] = 'dh_reports';
        return $vars;
    }

    /** Flush once when our rule is missing from the stored rules (same pattern as profile-badges). */
    public function maybe_flush_rewrites() {
        $rules = get_option('rewrite_rules');
        if (!is_array($rules) || empty($rules)) {
            return;
        }
        if (!isset($rules['^reports(/.*)?$']) || !isset($rules['^dog-training-statistics(/.*)?$'])) {
            flush_rewrite_rules(false);
        }
    }

    /** WordPress must not "fix" our paths (add slashes to files, guess 404s). */
    public function keep_our_urls($redirect, $requested) {
        if (get_query_var('dh_reports')) {
            return false;
        }
        return $redirect;
    }

    /** get_permalink() for a report page (used by the IndexNow helper). */
    public function post_link($link, $post) {
        if ($post instanceof WP_Post && $post->post_type === self::POST_TYPE) {
            $url = self::canonical_url_for_post($post->ID);
            if ($url) {
                return $url;
            }
        }
        return $link;
    }

    public function sitemap_providers($providers) {
        if (interface_exists('\RankMath\Sitemap\Providers\Provider')) {
            require_once __DIR__ . '/class-dh-reports-sitemap.php';
            $providers[] = new DH_Reports_Sitemap_Provider();
        }
        return $providers;
    }

    // ---------------------------------------------------------------------------------------------
    // Gate
    // ---------------------------------------------------------------------------------------------

    public static function public_enabled() {
        return (string) get_option(self::OPT_PUBLIC, '0') === '1';
    }

    /** Admins always; everyone else only when the module is switched on and the record is published. */
    public static function can_view($post_id) {
        if (current_user_can('manage_options')) {
            return true;
        }
        return self::public_enabled() && get_post_status((int) $post_id) === 'publish';
    }

    /** True when the viewer is looking at something visitors could also see. */
    public static function is_public_view($post_id) {
        return self::public_enabled() && get_post_status((int) $post_id) === 'publish';
    }

    // ---------------------------------------------------------------------------------------------
    // Storage
    // ---------------------------------------------------------------------------------------------

    /** Bundle store, outside the web root. Filter dh_reports_store_dir overrides the default (home directory of the PHP user). */
    public static function store_dir() {
        $dir = (string) apply_filters('dh_reports_store_dir', '');
        if ($dir === '') {
            $home = '';
            if (function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
                $pw = posix_getpwuid(posix_geteuid());
                $home = is_array($pw) && !empty($pw['dir']) ? $pw['dir'] : '';
            }
            if ($home === '') {
                $home = dirname(rtrim(ABSPATH, '/'));
            }
            $dir = rtrim($home, '/') . '/dh-reports';
        }
        return rtrim($dir, '/');
    }

    public static function store_is_outside_web_root($dir = null) {
        $dir = $dir === null ? self::store_dir() : $dir;
        $root = rtrim(ABSPATH, '/') . '/';
        return strpos(rtrim($dir, '/') . '/', $root) !== 0;
    }

    public static function valid_bundle_id($id) {
        return is_string($id) && preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}-[a-f0-9]{8}$/', $id) === 1;
    }

    public static function bundle_dir($id) {
        return self::valid_bundle_id($id) ? self::store_dir() . '/bundles/' . $id : '';
    }

    /** Manifest of a stored bundle, cached per request and in the object cache. */
    public static function manifest($id) {
        if (!self::valid_bundle_id($id)) {
            return null;
        }
        if (isset(self::$manifests[$id])) {
            return self::$manifests[$id];
        }
        $cached = wp_cache_get('manifest_' . $id, 'dh_reports');
        if (is_array($cached)) {
            return self::$manifests[$id] = $cached;
        }
        $file = self::bundle_dir($id) . '/manifest.json';
        if (!is_readable($file)) {
            return null;
        }
        $m = json_decode((string) file_get_contents($file), true);
        if (!is_array($m)) {
            return null;
        }
        wp_cache_set('manifest_' . $id, $m, 'dh_reports', 300);
        return self::$manifests[$id] = $m;
    }

    /** The manifest entry for a post's page in its active bundle. */
    public static function page_entry($post_id) {
        $id = (string) get_post_meta($post_id, '_dh_report_bundle', true);
        $key = (string) get_post_meta($post_id, '_dh_report_key', true);
        $m = self::manifest($id);
        if (!$m || $key === '') {
            return null;
        }
        foreach ($m['pages'] as $p) {
            if ($p['key'] === $key) {
                return array('bundle_id' => $id, 'bundle_dir' => self::bundle_dir($id), 'page' => $p, 'manifest' => $m);
            }
        }
        return null;
    }

    public static function find_post($key) {
        $q = get_posts(array(
            'post_type' => self::POST_TYPE, 'post_status' => 'any', 'posts_per_page' => 1, 'no_found_rows' => true,
            'meta_key' => '_dh_report_key', 'meta_value' => $key, 'fields' => 'ids',
        ));
        return $q ? (int) $q[0] : 0;
    }

    // ---------------------------------------------------------------------------------------------
    // Route table
    // ---------------------------------------------------------------------------------------------

    public static function routes() {
        $t = get_option(self::OPT_ROUTES);
        if (!is_array($t) || !isset($t['pages'], $t['files'], $t['latest'])) {
            return array('pages' => array(), 'files' => array(), 'latest' => array());
        }
        return $t;
    }

    /** Rebuild the stored route table from every report record's active bundle. */
    public static function rebuild_routes() {
        $ids = get_posts(array('post_type' => self::POST_TYPE, 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 'no_found_rows' => true));
        $entries = array();
        foreach ($ids as $post_id) {
            $e = self::page_entry($post_id);
            if (!$e) {
                continue;
            }
            $p = $e['page'];
            $entries[] = array(
                'post_id' => (int) $post_id, 'key' => $p['key'], 'kind' => $p['kind'],
                'report' => isset($p['report']) ? (string) $p['report'] : '', 'edition' => isset($p['edition']) ? (string) $p['edition'] : '',
                'urls' => $p['urls'], 'files' => isset($p['files']) ? $p['files'] : array(),
            );
        }
        $table = DH_Reports_Routes::build_table($entries);
        update_option(self::OPT_ROUTES, $table, false);
        return $table;
    }

    public static function canonical_url_for_post($post_id) {
        $table = self::routes();
        foreach ($table['pages'] as $url => $p) {
            if ((int) $p['post_id'] === (int) $post_id) {
                $hit = array('url' => $url, 'post_id' => (int) $post_id, 'kind' => $p['kind'], 'report' => $p['report']);
                return home_url(DH_Reports_Routes::canonical($hit, $table, function ($id) {
                    return get_post_status($id) === 'publish' || self::can_view($id);
                }));
            }
        }
        return '';
    }

    // ---------------------------------------------------------------------------------------------
    // Request handling
    // ---------------------------------------------------------------------------------------------

    public function maybe_serve() {
        if (!get_query_var('dh_reports')) {
            return;
        }
        $uri  = isset($_SERVER['REQUEST_URI']) ? wp_unslash($_SERVER['REQUEST_URI']) : '';
        $path = DH_Reports_Routes::normalize((string) wp_parse_url($uri, PHP_URL_PATH));

        if ($path === '/reports/_status/') {
            if (!current_user_can('manage_options')) {
                return self::not_found();
            }
            return self::serve_status();
        }

        $table = self::routes();
        $hit = DH_Reports_Routes::resolve($path, $table, array(__CLASS__, 'can_view'));
        if (!$hit) {
            return self::not_found();
        }
        if ($hit['type'] === 'redirect') {
            wp_safe_redirect(home_url($hit['to']), 301);
            exit;
        }
        if ($hit['type'] === 'file') {
            return self::serve_file($hit);
        }
        return self::serve_page($hit, $table);
    }

    /** A hidden page is indistinguishable from a missing one. */
    private static function not_found() {
        global $wp_query;
        if ($wp_query) {
            $wp_query->set_404();
        }
        status_header(404);
        // Never cache this 404: the same address may be published later, and a cached 404 would mask it.
        if (!defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }
        do_action('litespeed_control_set_nocache', 'dh-reports 404');
        nocache_headers();
        header('X-Robots-Tag: noindex, nofollow');
        // Falls through to the theme's 404 template.
    }

    private static function no_cache_for_preview() {
        if (!defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }
        do_action('litespeed_control_set_nocache', 'dh-reports admin preview');
        nocache_headers();
        header('Cache-Control: private, no-store, max-age=0');
    }

    private static function serve_file($hit) {
        $e = self::page_entry($hit['post_id']);
        if (!$e) {
            return self::not_found();
        }
        $base = realpath($e['bundle_dir']);
        $full = realpath($e['bundle_dir'] . '/' . $hit['path']);
        if (!$base || !$full || strpos($full, $base . '/') !== 0 || !is_file($full)) {
            return self::not_found();
        }
        $public = self::is_public_view($hit['post_id']);
        $etag = '"' . md5($e['bundle_id'] . $hit['path'] . filesize($full)) . '"';
        if ($public) {
            header('Cache-Control: public, max-age=3600');
            header('ETag: ' . $etag);
            if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim(wp_unslash($_SERVER['HTTP_IF_NONE_MATCH'])) === $etag) {
                status_header(304);
                exit;
            }
        } else {
            self::no_cache_for_preview();
            header('X-Robots-Tag: noindex, nofollow');
            header('X-DH-Reports: admin-preview');
        }
        status_header(200);
        header('Content-Type: ' . $hit['mime']);
        header('Content-Length: ' . filesize($full));
        header('X-Content-Type-Options: nosniff');
        if ($public) {
            header('X-Robots-Tag: noindex');
        }
        if (!empty($hit['download'])) {
            header('Content-Disposition: attachment; filename="' . DH_Reports_Routes::download_name($hit['url']) . '"');
        }
        readfile($full);
        exit;
    }

    private static function serve_page($hit, $table) {
        $e = self::page_entry($hit['post_id']);
        if (!$e) {
            return self::not_found();
        }
        $page = $e['page'];
        $file = realpath($e['bundle_dir'] . '/' . $page['file']);
        $base = realpath($e['bundle_dir']);
        if (!$file || !$base || strpos($file, $base . '/') !== 0 || !is_file($file)) {
            return self::not_found();
        }
        $html = (string) file_get_contents($file);
        $public = self::is_public_view($hit['post_id']);
        $canonical = home_url(DH_Reports_Routes::canonical($hit, $table, function ($id) {
            return get_post_status($id) === 'publish' || self::can_view($id);
        }));

        if (!$public) {
            self::no_cache_for_preview();
            header('X-DH-Reports: admin-preview');
        }
        status_header(200);
        global $wp_query;
        if ($wp_query) {
            $wp_query->is_404 = false;
            $wp_query->is_home = false;
            $wp_query->is_page = false;
            $wp_query->is_singular = false;
        }

        $noindex = !$public || strpos((string) $page['robots'], 'noindex') !== false;
        if ($noindex) {
            header('X-Robots-Tag: noindex, nofollow');
        }

        if ($page['render'] === 'raw') {
            // A standalone document, framable from any site. The Cloudflare rule note covers the edge; this is the origin side.
            header('Content-Type: text/html; charset=utf-8');
            header_remove('X-Frame-Options');
            header('Content-Security-Policy: frame-ancestors *');
            echo $html;
            exit;
        }

        self::$current = array(
            'page' => $page, 'canonical' => $canonical, 'noindex' => $noindex, 'public' => $public, 'post_id' => $hit['post_id'],
            'as_of' => isset($e['manifest']['as_of']) ? $e['manifest']['as_of'] : '', 'bundle_id' => $e['bundle_id'],
        );
        remove_all_actions('rank_math/head');
        remove_action('wp_head', 'rel_canonical');
        add_filter('pre_get_document_title', array(__CLASS__, 'document_title'), 99);
        add_filter('wp_robots', array(__CLASS__, 'robots'), 99);
        add_filter('body_class', array(__CLASS__, 'body_class'));
        add_action('wp_head', array(__CLASS__, 'print_head'), 2);

        get_header();
        echo '<main id="brx-content" class="dh-reports-main">';
        if (!$public) {
            echo self::ribbon($hit['post_id'], $e['bundle_id'], isset($e['manifest']['as_of']) ? $e['manifest']['as_of'] : '');
        }
        echo $html; // Trusted: written by the pipeline, hash-checked at import.
        echo '</main>';
        get_footer();
        exit;
    }

    private static function ribbon($post_id, $bundle_id, $as_of) {
        $status = get_post_status($post_id);
        $why = self::public_enabled() ? 'This page is not published.' : 'Reports are switched off for visitors.';
        return '<div style="background:#fff3c4;border-bottom:2px dashed #b38900;color:#011e32;font:500 14px/1.4 system-ui,sans-serif;padding:8px 16px;text-align:center">'
            . 'Admin preview. ' . esc_html($why) . ' Visitors get a 404. Status: ' . esc_html((string) $status)
            . '. Bundle ' . esc_html($bundle_id) . ', data as of ' . esc_html($as_of) . '.</div>';
    }

    public static function title_with_site($t) {
        return stripos($t, 'Goody Doggy') !== false ? $t : $t . ' | Goody Doggy';
    }

    public static function document_title() {
        $t = self::$current ? self::$current['page']['title'] : '';
        return $t !== '' ? self::title_with_site($t) : '';
    }

    public static function robots($robots) {
        if (self::$current && self::$current['noindex']) {
            return array('noindex' => true, 'nofollow' => true);
        }
        return array('index' => true, 'follow' => true, 'max-image-preview' => 'large');
    }

    public static function body_class($classes) {
        $classes[] = 'dh-reports';
        return $classes;
    }

    public static function print_head() {
        if (!self::$current) {
            return;
        }
        $c = self::$current;
        $p = $c['page'];
        $title = esc_attr(self::title_with_site($p['title']));
        $desc = esc_attr($p['description']);
        $url = esc_url($c['canonical']);
        if (!has_action('wp_head', '_wp_render_title_tag')) {
            // Rank Math takes over the title on this site; its head output is removed on these pages, so print it here.
            echo "\n" . '<title>' . esc_html(self::title_with_site($p['title'])) . '</title>';
        }
        echo "\n" . '<meta name="description" content="' . $desc . '">' . "\n";
        echo '<link rel="canonical" href="' . $url . '">' . "\n";
        echo '<meta property="og:type" content="article">' . "\n";
        echo '<meta property="og:title" content="' . $title . '">' . "\n";
        echo '<meta property="og:description" content="' . $desc . '">' . "\n";
        echo '<meta property="og:url" content="' . $url . '">' . "\n";
        echo '<meta property="og:site_name" content="Goody Doggy">' . "\n";
        echo '<meta name="twitter:card" content="summary">' . "\n";
        foreach (self::jsonld_for($c) as $obj) {
            echo '<script type="application/ld+json">' . wp_json_encode($obj, JSON_UNESCAPED_UNICODE) . '</script>' . "\n";
        }
    }

    /**
     * JSON-LD objects from the manifest, with the dates filled from WordPress: datePublished is the day the record was
     * first published (the data date while it is a draft), dateModified is the day the bundle was imported.
     */
    public static function jsonld_for($c) {
        $out = array();
        $post_id = $c['post_id'];
        $published = get_post_status($post_id) === 'publish';
        $pub = $published ? gmdate('Y-m-d', strtotime(get_post_field('post_date_gmt', $post_id))) : $c['as_of'];
        $imported = (string) get_post_meta($post_id, '_dh_report_imported_at', true);
        $mod = $imported !== '' ? gmdate('Y-m-d', (int) $imported) : $c['as_of'];
        foreach (isset($c['page']['jsonld']) ? $c['page']['jsonld'] : array() as $obj) {
            if (isset($obj['datePublished'])) {
                $obj['datePublished'] = $pub;
            }
            if (isset($obj['dateModified'])) {
                $obj['dateModified'] = $mod;
            }
            $out[] = $obj;
        }
        return $out;
    }

    private static function serve_status() {
        $dir = self::store_dir();
        $bundles = is_dir($dir . '/bundles') ? array_values(array_diff((array) scandir($dir . '/bundles'), array('.', '..'))) : array();
        $posts = array();
        foreach (get_posts(array('post_type' => self::POST_TYPE, 'post_status' => 'any', 'posts_per_page' => -1, 'no_found_rows' => true)) as $p) {
            $posts[] = array('id' => $p->ID, 'key' => get_post_meta($p->ID, '_dh_report_key', true), 'status' => $p->post_status,
                'bundle' => get_post_meta($p->ID, '_dh_report_bundle', true), 'prev_bundle' => get_post_meta($p->ID, '_dh_report_prev_bundle', true));
        }
        $table = self::routes();
        nocache_headers();
        header('X-Robots-Tag: noindex, nofollow');
        header('Content-Type: application/json; charset=utf-8');
        echo wp_json_encode(array(
            'public_switch' => self::public_enabled(),
            'store_dir' => $dir,
            'store_outside_web_root' => self::store_is_outside_web_root($dir),
            'store_readable' => is_readable($dir), 'store_writable' => is_writable($dir),
            'bundles_on_disk' => $bundles, 'records' => $posts,
            'routes' => array('pages' => array_keys($table['pages']), 'files' => count($table['files']), 'aliases' => array_keys($table['latest'])),
            'rewrite_rule_present' => isset((get_option('rewrite_rules') ?: array())['^reports(/.*)?$']),
            'php' => PHP_VERSION,
        ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }

    // ---------------------------------------------------------------------------------------------
    // Bundle validation (used by the CLI before anything is written)
    // ---------------------------------------------------------------------------------------------

    /**
     * Check a bundle directory without writing anything.
     *
     * @return array{errors: string[], warnings: string[], manifest: ?array, open_items: array<string,int>}
     */
    public static function validate_bundle($dir) {
        $errors = array();
        $warnings = array();
        $open = array();
        $dir = rtrim($dir, '/');
        $mf = $dir . '/manifest.json';
        if (!is_readable($mf)) {
            return array('errors' => array("No readable manifest.json in {$dir}"), 'warnings' => array(), 'manifest' => null, 'open_items' => array());
        }
        $m = json_decode((string) file_get_contents($mf), true);
        if (!is_array($m)) {
            return array('errors' => array('manifest.json is not valid JSON'), 'warnings' => array(), 'manifest' => null, 'open_items' => array());
        }
        if ((int) ($m['schema'] ?? 0) !== self::SCHEMA) {
            $errors[] = 'Unsupported manifest schema: ' . var_export($m['schema'] ?? null, true);
        }
        if (!self::valid_bundle_id($m['bundle_id'] ?? '')) {
            $errors[] = 'Bad bundle_id (want YYYY-MM-DD-xxxxxxxx)';
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($m['as_of'] ?? ''))) {
            $errors[] = 'Bad as_of date';
        }
        $pages = isset($m['pages']) && is_array($m['pages']) ? $m['pages'] : array();
        if (!$pages) {
            $errors[] = 'No pages in the manifest';
        }
        $base = realpath($dir);
        $page_urls = array();
        $file_urls = array();
        $keys = array();
        $seen_paths = array();
        $check_file = function ($rel, $sha, $label) use (&$errors, $base, &$seen_paths) {
            if (!is_string($rel) || $rel === '' || strpos($rel, '..') !== false || $rel[0] === '/') {
                $errors[] = "{$label}: bad path " . var_export($rel, true);
                return null;
            }
            $full = realpath($base . '/' . $rel);
            if (!$full || strpos($full, $base . '/') !== 0 || !is_file($full)) {
                $errors[] = "{$label}: file missing {$rel}";
                return null;
            }
            if (filesize($full) > DH_Reports::MAX_FILE) {
                $errors[] = "{$label}: file over " . (DH_Reports::MAX_FILE / 1048576) . " MB: {$rel}";
            }
            if (!is_string($sha) || !hash_equals($sha, hash_file('sha256', $full))) {
                $errors[] = "{$label}: sha256 does not match {$rel}";
            }
            $seen_paths[$rel] = true;
            return $full;
        };
        foreach ($pages as $p) {
            $key = (string) ($p['key'] ?? '');
            $label = 'page ' . ($key !== '' ? $key : '(no key)');
            if (!preg_match('/^[a-z0-9-]{3,80}$/', $key)) {
                $errors[] = "{$label}: bad key";
            }
            if (isset($keys[$key])) {
                $errors[] = "{$label}: duplicate key";
            }
            $keys[$key] = true;
            if (!in_array($p['kind'] ?? '', self::KINDS, true)) {
                $errors[] = "{$label}: bad kind";
            }
            if (!in_array($p['render'] ?? '', self::RENDERS, true)) {
                $errors[] = "{$label}: bad render";
            }
            if (($p['kind'] ?? '') === 'edition' && (!preg_match('/^[a-z0-9-]+$/', (string) ($p['report'] ?? '')) || !preg_match('/^\d{4}$/', (string) ($p['edition'] ?? '')))) {
                $errors[] = "{$label}: an edition needs a report slug and a four-digit edition";
            }
            foreach (array('title', 'description', 'robots') as $req) {
                if (!isset($p[$req]) || !is_string($p[$req]) || $p[$req] === '') {
                    $errors[] = "{$label}: missing {$req}";
                }
            }
            if (!in_array($p['robots'] ?? '', array('index,follow', 'noindex,follow', 'noindex,nofollow'), true)) {
                $errors[] = "{$label}: bad robots value";
            }
            if (empty($p['urls']) || !is_array($p['urls'])) {
                $errors[] = "{$label}: no urls";
            } else {
                $page_urls = array_merge($page_urls, $p['urls']);
            }
            $full = $check_file($p['file'] ?? null, $p['sha256'] ?? null, $label);
            if ($full) {
                $html = (string) file_get_contents($full);
                if (stripos($html, '<?php') !== false || stripos($html, '<?=') !== false) {
                    $errors[] = "{$label}: page contains PHP tags";
                }
                if (stripos($html, 'aggregaterating') !== false) {
                    $errors[] = "{$label}: AggregateRating is not allowed";
                }
                $open[$key] = substr_count($html, 'data-todo');
                if (($p['render'] ?? '') === 'chrome' && (stripos($html, '<html') !== false || stripos($html, '<body') !== false)) {
                    $errors[] = "{$label}: a chrome page must be a fragment, not a document";
                }
                if (($p['render'] ?? '') === 'raw' && stripos($html, '<!doctype') === false) {
                    $errors[] = "{$label}: a raw page must be a full document";
                }
            }
            foreach (isset($p['jsonld']) && is_array($p['jsonld']) ? $p['jsonld'] : array() as $i => $ld) {
                if (!is_array($ld) || empty($ld['@type'])) {
                    $errors[] = "{$label}: JSON-LD item {$i} has no @type";
                } elseif (stripos(wp_json_encode($ld), 'aggregaterating') !== false) {
                    $errors[] = "{$label}: AggregateRating in JSON-LD";
                }
            }
            foreach (isset($p['files']) && is_array($p['files']) ? $p['files'] : array() as $f) {
                $flabel = $label . ' file ' . ($f['url'] ?? '?');
                $file_urls[] = (string) ($f['url'] ?? '');
                $rel = $f['path'] ?? null;
                if (!is_string($rel) || strpos($rel, 'files/') !== 0) {
                    $errors[] = "{$flabel}: path must be under files/";
                }
                $check_file($rel, $f['sha256'] ?? null, $flabel);
                $ext = strtolower(pathinfo((string) ($f['url'] ?? ''), PATHINFO_EXTENSION));
                if (!in_array($ext, self::FILE_TYPES, true)) {
                    $errors[] = "{$flabel}: file type not allowed";
                }
                if (empty($f['type']) || !is_string($f['type'])) {
                    $errors[] = "{$flabel}: missing type";
                }
            }
        }
        foreach (DH_Reports_Routes::validate_urls($page_urls, $file_urls) as $e) {
            $errors[] = $e;
        }
        return array('errors' => $errors, 'warnings' => $warnings, 'manifest' => $m, 'open_items' => $open);
    }

    // ---------------------------------------------------------------------------------------------
    // Admin (read-only views of the records)
    // ---------------------------------------------------------------------------------------------

    public function row_actions($actions, $post) {
        if ($post->post_type !== self::POST_TYPE) {
            return $actions;
        }
        unset($actions['inline hide-if-no-js'], $actions['view']);
        $url = self::canonical_url_for_post($post->ID);
        if ($url) {
            $actions['view'] = '<a href="' . esc_url($url) . '">View page</a>';
        }
        return $actions;
    }

    public function columns($cols) {
        return array('cb' => $cols['cb'], 'title' => __('Page', 'directory-helpers'), 'dh_url' => __('Address', 'directory-helpers'),
            'dh_kind' => __('Kind', 'directory-helpers'), 'dh_bundle' => __('Bundle', 'directory-helpers'), 'date' => $cols['date']);
    }

    public function column_value($col, $post_id) {
        if ($col === 'dh_url') {
            $e = self::page_entry($post_id);
            echo $e ? esc_html(implode(', ', $e['page']['urls'])) : '-';
        } elseif ($col === 'dh_kind') {
            echo esc_html((string) get_post_meta($post_id, '_dh_report_kind', true));
        } elseif ($col === 'dh_bundle') {
            echo esc_html((string) get_post_meta($post_id, '_dh_report_bundle', true));
        }
    }

    public function add_meta_box() {
        add_meta_box('dh_report_details', __('Report page details', 'directory-helpers'), array($this, 'render_meta_box'), self::POST_TYPE, 'normal', 'high');
    }

    public function render_meta_box($post) {
        $e = self::page_entry($post->ID);
        echo '<p>Visitors see this page only when its status is Published and the site option <code>dh_reports_public</code> is 1 (now: '
            . (self::public_enabled() ? '1' : '0') . '). Administrators always see it.</p>';
        echo '<table class="widefat striped"><tbody>';
        $rows = array(
            'Key' => get_post_meta($post->ID, '_dh_report_key', true),
            'Kind' => get_post_meta($post->ID, '_dh_report_kind', true),
            'Bundle' => get_post_meta($post->ID, '_dh_report_bundle', true),
            'Previous bundle (rollback)' => get_post_meta($post->ID, '_dh_report_prev_bundle', true),
            'Address' => $e ? implode(', ', $e['page']['urls']) : '',
            'Data as of' => $e ? $e['manifest']['as_of'] : '',
        );
        foreach ($rows as $k => $v) {
            echo '<tr><th style="width:220px">' . esc_html($k) . '</th><td>' . esc_html((string) $v) . '</td></tr>';
        }
        echo '</tbody></table><p>Change content by importing a new bundle: <code>wp directory-helpers report-publish --help</code>.</p>';
    }
}
