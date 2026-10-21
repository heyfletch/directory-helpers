<?php
/**
 * Rank Math sitemap provider for the reports module: /reports-sitemap.xml.
 *
 * Lists only pages that visitors can actually load: published records, with the reports switch on. Each listed
 * address is the page's canonical one, so the frozen copy of the newest edition is not listed beside the
 * permanent address. Loaded only when Rank Math's provider interface exists.
 *
 * @package Directory_Helpers
 */

if (!defined('ABSPATH')) {
    exit;
}

use RankMath\Sitemap\Router;

class DH_Reports_Sitemap_Provider implements \RankMath\Sitemap\Providers\Provider {

    const SLUG = 'reports';

    /** @return array[] each: loc, mod (W3C date) */
    public static function entries() {
        if (!DH_Reports::public_enabled()) {
            return array();
        }
        $table = DH_Reports::routes();
        $published = function ($id) {
            return get_post_status($id) === 'publish';
        };
        $seen = array();
        $out = array();
        foreach ($table['pages'] as $url => $p) {
            if (!$published($p['post_id'])) {
                continue;
            }
            $e = DH_Reports::page_entry($p['post_id']);
            if (!$e || empty($e['page']['sitemap'])) {
                continue;
            }
            $hit = array('url' => $url, 'post_id' => $p['post_id'], 'kind' => $p['kind'], 'report' => $p['report']);
            $loc = home_url(DH_Reports_Routes::canonical($hit, $table, $published));
            if (isset($seen[$loc])) {
                continue;
            }
            $seen[$loc] = true;
            $mod = (string) get_post_meta($p['post_id'], '_dh_report_imported_at', true);
            $out[] = array('loc' => $loc, 'mod' => gmdate(DATE_W3C, $mod !== '' ? (int) $mod : time()));
        }
        return $out;
    }

    public function handles_type($type) {
        return $type === self::SLUG;
    }

    public function get_index_links($max_entries) {
        $entries = self::entries();
        if (!$entries) {
            return array();
        }
        $latest = 0;
        foreach ($entries as $e) {
            $latest = max($latest, strtotime($e['mod']));
        }
        return array(array(
            'loc' => Router::get_base_url(self::SLUG . '-sitemap.xml'),
            'lastmod' => '@' . $latest,
        ));
    }

    public function get_sitemap_links($type, $max_entries, $current_page) {
        return array_slice(self::entries(), 0, $max_entries);
    }
}
