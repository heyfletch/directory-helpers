<?php
/**
 * Reports routing decisions in one pure class.
 *
 * No WordPress calls: safe to unit test with plain PHP (tests/reports-routes-test.php).
 *
 * The route table is built at import time from a bundle manifest and stored in an option:
 *
 *   pages  => url => [post_id, key, kind, report, edition]
 *   files  => url => [post_id, path, type, download]
 *   latest => report alias url => [[edition, url], ...] newest first
 *
 * @package Directory_Helpers
 */

if (!defined('ABSPATH')) {
    exit;
}

class DH_Reports_Routes {

    /** Every URL a bundle may claim starts with one of these. */
    const PREFIXES = array('/reports/', '/dog-training-statistics/');

    /** Served by the module itself, never claimable by a bundle. */
    const RESERVED = array('/reports/_status/');

    /**
     * Normalize a request path: leading slash, no query or fragment, no doubled slashes.
     * Returns '' for anything with a dot segment or a NUL byte.
     */
    public static function normalize($path) {
        $path = (string) $path;
        $path = preg_replace('/[?#].*$/', '', $path);
        if ($path === '' || strpos($path, "\0") !== false) {
            return '';
        }
        $path = '/' . ltrim($path, '/');
        $path = preg_replace('#/{2,}#', '/', $path);
        foreach (explode('/', $path) as $seg) {
            if ($seg === '..' || $seg === '.') {
                return '';
            }
        }
        return $path;
    }

    /** True when the path belongs to the reports module's address space. */
    public static function is_ours($path) {
        $path = self::normalize($path);
        if ($path === '') {
            return false;
        }
        $slashed = rtrim($path, '/') . '/';
        foreach (self::PREFIXES as $prefix) {
            if (strpos($slashed, $prefix) === 0) {
                return true;
            }
        }
        return false;
    }

    /** Problems with the URLs a bundle claims. Empty array means fine. */
    public static function validate_urls(array $page_urls, array $file_urls) {
        $errors = array();
        $seen = array();
        foreach ($page_urls as $url) {
            if (self::normalize($url) !== $url || substr($url, -1) !== '/') {
                $errors[] = "Page URL must be normalized and end with a slash: {$url}";
            }
            if (!self::is_ours($url)) {
                $errors[] = "Page URL is outside the reports address space: {$url}";
            }
            if (in_array($url, self::RESERVED, true)) {
                $errors[] = "Page URL is reserved: {$url}";
            }
            if (isset($seen[$url])) {
                $errors[] = "URL claimed twice: {$url}";
            }
            $seen[$url] = true;
        }
        foreach ($file_urls as $url) {
            if (self::normalize($url) !== $url || substr($url, -1) === '/') {
                $errors[] = "File URL must be normalized and must not end with a slash: {$url}";
            }
            if (!self::is_ours($url)) {
                $errors[] = "File URL is outside the reports address space: {$url}";
            }
            if (isset($seen[$url])) {
                $errors[] = "URL claimed twice: {$url}";
            }
            $seen[$url] = true;
        }
        return $errors;
    }

    /**
     * Build the route table.
     *
     * @param array $entries Each: post_id, key, kind, report (or ''), edition (or ''), urls[], files[ {url, path, type, download} ].
     */
    public static function build_table(array $entries) {
        $table = array('pages' => array(), 'files' => array(), 'latest' => array());
        foreach ($entries as $e) {
            foreach ($e['urls'] as $url) {
                $table['pages'][$url] = array(
                    'post_id' => (int) $e['post_id'], 'key' => $e['key'], 'kind' => $e['kind'],
                    'report' => $e['report'], 'edition' => $e['edition'],
                );
                if ($e['kind'] === 'edition' && $e['report'] !== '' && $e['edition'] !== '') {
                    $alias = '/reports/' . $e['report'] . '/';
                    $table['latest'][$alias][] = array('edition' => $e['edition'], 'url' => $url, 'post_id' => (int) $e['post_id']);
                }
            }
            foreach ($e['files'] as $f) {
                $table['files'][$f['url']] = array(
                    'post_id' => (int) $e['post_id'], 'path' => $f['path'], 'type' => $f['type'], 'download' => !empty($f['download']),
                );
            }
        }
        foreach ($table['latest'] as $alias => $list) {
            usort($list, function ($a, $b) {
                return strcmp($b['edition'], $a['edition']);
            });
            $table['latest'][$alias] = $list;
        }
        return $table;
    }

    /**
     * Newest edition under a report alias that the viewer may see.
     *
     * @param callable $can_view fn(int $post_id): bool
     */
    public static function latest_visible($alias, array $table, $can_view) {
        foreach (isset($table['latest'][$alias]) ? $table['latest'][$alias] : array() as $item) {
            if (call_user_func($can_view, $item['post_id'])) {
                return $item;
            }
        }
        return null;
    }

    /**
     * Decide what a request path is.
     *
     * Returns null (nothing here), or an array with type:
     *   redirect  to
     *   page      url, post_id, key, kind, report, edition, alias (bool)
     *   file      url, post_id, path, mime, download
     *
     * A page or file the viewer cannot see resolves to null, so a draft is indistinguishable from a missing URL.
     */
    public static function resolve($path, array $table, $can_view) {
        $path = self::normalize($path);
        if ($path === '' || !self::is_ours($path)) {
            return null;
        }
        if (isset($table['files'][$path])) {
            $f = $table['files'][$path];
            if (!call_user_func($can_view, $f['post_id'])) {
                return null;
            }
            return array('type' => 'file', 'url' => $path, 'post_id' => $f['post_id'], 'path' => $f['path'],
                'mime' => $f['type'], 'download' => $f['download']);
        }
        $slashed = substr($path, -1) === '/' ? $path : $path . '/';
        $alias = $slashed;
        $page_url = $slashed;
        $is_alias = false;
        if (!isset($table['pages'][$slashed]) && isset($table['latest'][$slashed])) {
            $latest = self::latest_visible($slashed, $table, $can_view);
            if ($latest === null) {
                return null;
            }
            $page_url = $latest['url'];
            $is_alias = true;
        }
        if (!isset($table['pages'][$page_url])) {
            return null;
        }
        $p = $table['pages'][$page_url];
        if (!call_user_func($can_view, $p['post_id'])) {
            return null;
        }
        if ($slashed !== $path) {
            return array('type' => 'redirect', 'to' => $slashed);
        }
        return array('type' => 'page', 'url' => $page_url, 'requested' => $slashed, 'post_id' => $p['post_id'], 'key' => $p['key'],
            'kind' => $p['kind'], 'report' => $p['report'], 'edition' => $p['edition'], 'alias' => $is_alias);
    }

    /**
     * Canonical path for a page hit. An edition that is the newest visible one canonicalizes to the report's
     * permanent address; an older edition canonicalizes to itself.
     */
    public static function canonical(array $hit, array $table, $can_view) {
        if ($hit['kind'] === 'edition' && $hit['report'] !== '') {
            $alias = '/reports/' . $hit['report'] . '/';
            $latest = self::latest_visible($alias, $table, $can_view);
            if ($latest !== null && $latest['post_id'] === $hit['post_id']) {
                return $alias;
            }
        }
        return $hit['url'];
    }

    /** Media type for a served file, from its name. */
    public static function mime($filename) {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $map = array(
            'csv' => 'text/csv; charset=utf-8', 'json' => 'application/json; charset=utf-8', 'svg' => 'image/svg+xml',
            'png' => 'image/png', 'pdf' => 'application/pdf', 'txt' => 'text/plain; charset=utf-8',
        );
        return isset($map[$ext]) ? $map[$ext] : 'application/octet-stream';
    }

    /** Dollar-free, quote-free filename safe for a Content-Disposition header. */
    public static function download_name($url) {
        $name = basename($url);
        return preg_replace('/[^A-Za-z0-9._-]/', '-', $name);
    }
}
