<?php
/**
 * WP-CLI: wp directory-helpers report-publish
 *
 * Imports a data reports site bundle (written by the pipeline's build_site.py) as report records, and moves them
 * through draft, published, rolled back and taken down. Nothing here makes a page public by itself: a page is
 * visible to visitors only when its record is published AND the site option dh_reports_public is 1.
 */

if (!defined('WP_CLI')) {
    return;
}

class DH_Reports_CLI extends WP_CLI_Command {

    /**
     * Import a bundle as draft report records, or publish, roll back or take down.
     *
     * Default (no mode flag): validate the bundle, copy it into the store, create or update one record per page (new records
     * are drafts, existing records keep their status), rebuild the route table. Each page keeps its previous bundle for rollback.
     *
     * ## OPTIONS
     *
     * [<bundle>]
     * : Bundle directory. Default: <store>/incoming.
     *
     * [--dry-run]
     * : Validate and print everything that would happen. Writes nothing, purges nothing, submits nothing.
     *
     * [--publish]
     * : After importing, set every page in the bundle to published. Refuses while any page still carries an open item
     * (a yellow pending marker) unless --allow-open-items. With the dh_reports_public option on, also purges only these
     * pages' URLs and submits them to IndexNow. With it off, pages stay admin-only.
     *
     * [--allow-open-items]
     * : Publish even though a page has pending markers.
     *
     * [--unpublish]
     * : Set every page in the bundle (or every report page when no bundle is given and --key is absent) back to draft,
     * purge their URLs and resubmit them to IndexNow so engines recrawl.
     *
     * [--rollback]
     * : Point every page back to its previous bundle, rebuild routes, purge the URLs.
     *
     * [--key=<key>]
     * : Limit --publish, --unpublish or --rollback to one page key.
     *
     * [--status]
     * : Print the records, their bundles, the route count and the public switch. Writes nothing.
     *
     * ## EXAMPLES
     *
     *     wp directory-helpers report-publish --status
     *     wp directory-helpers report-publish /home/site/dh-reports/incoming --dry-run
     *     wp directory-helpers report-publish /home/site/dh-reports/incoming
     *     wp directory-helpers report-publish --rollback --key=statistics-hub
     */
    public function __invoke($args, $assoc_args) {
        if (isset($assoc_args['status'])) {
            return $this->status();
        }
        if (isset($assoc_args['rollback'])) {
            return $this->rollback($assoc_args);
        }
        if (isset($assoc_args['unpublish'])) {
            return $this->unpublish($assoc_args);
        }
        return $this->import($args, $assoc_args);
    }

    private function status() {
        $dir = DH_Reports::store_dir();
        WP_CLI::line('Store: ' . $dir . ' (' . (is_dir($dir) ? 'exists' : 'missing') . ', ' . (DH_Reports::store_is_outside_web_root($dir) ? 'outside the web root' : 'INSIDE THE WEB ROOT') . ')');
        WP_CLI::line('dh_reports_public: ' . (DH_Reports::public_enabled() ? '1 (published pages are visible to visitors)' : '0 (every page is admin-only)'));
        $rows = array();
        foreach (get_posts(array('post_type' => DH_Reports::POST_TYPE, 'post_status' => 'any', 'posts_per_page' => -1, 'no_found_rows' => true)) as $p) {
            $e = DH_Reports::page_entry($p->ID);
            $rows[] = array(
                'id' => $p->ID, 'key' => get_post_meta($p->ID, '_dh_report_key', true), 'status' => $p->post_status,
                'bundle' => get_post_meta($p->ID, '_dh_report_bundle', true), 'previous' => get_post_meta($p->ID, '_dh_report_prev_bundle', true),
                'address' => $e ? $e['page']['urls'][0] : '(bundle missing)',
            );
        }
        if ($rows) {
            WP_CLI\Utils\format_items('table', $rows, array('id', 'key', 'status', 'bundle', 'previous', 'address'));
        } else {
            WP_CLI::line('No report records yet.');
        }
        $t = DH_Reports::routes();
        WP_CLI::line('Routes: ' . count($t['pages']) . ' pages, ' . count($t['files']) . ' files, ' . count($t['latest']) . ' latest-edition aliases.');
    }

    private function import($args, $assoc) {
        $dry = isset($assoc['dry-run']);
        $store = DH_Reports::store_dir();
        $dir = isset($args[0]) ? rtrim($args[0], '/') : $store . '/incoming';
        if (!DH_Reports::store_is_outside_web_root($store)) {
            WP_CLI::error("The store {$store} is inside the web root. Point the dh_reports_store_dir filter at a directory outside it.");
        }
        WP_CLI::line('=== Reports import ' . ($dry ? '(dry run) ' : '') . "from {$dir} ===");
        $v = DH_Reports::validate_bundle($dir);
        foreach ($v['warnings'] as $w) {
            WP_CLI::warning($w);
        }
        if ($v['errors']) {
            foreach ($v['errors'] as $e) {
                WP_CLI::warning($e);
            }
            WP_CLI::error(count($v['errors']) . ' problem(s) in the bundle. Nothing was written.');
        }
        $m = $v['manifest'];
        $id = $m['bundle_id'];
        WP_CLI::success("Bundle {$id} is valid: " . count($m['pages']) . ' pages, data as of ' . $m['as_of'] . '.');

        $rows = array();
        $urls_all = array();
        foreach ($m['pages'] as $p) {
            $post_id = DH_Reports::find_post($p['key']);
            $current = $post_id ? (string) get_post_meta($post_id, '_dh_report_bundle', true) : '';
            $rows[] = array(
                'key' => $p['key'], 'kind' => $p['kind'], 'address' => $p['urls'][0], 'render' => $p['render'],
                'record' => $post_id ? "update #{$post_id} (" . get_post_status($post_id) . ')' : 'create draft',
                'bundle_change' => ($current === '' ? '(none)' : $current) . ' -> ' . $id,
                'open_items' => $v['open_items'][$p['key']],
            );
            foreach ($p['urls'] as $u) {
                $urls_all[] = $u;
                $existing = get_page_by_path(trim($u, '/'), OBJECT, array('page', 'post'));
                if ($existing) {
                    WP_CLI::warning("{$u} is also the address of WordPress page #{$existing->ID} ({$existing->post_status}). The module answers first and would hide it.");
                }
            }
        }
        WP_CLI\Utils\format_items('table', $rows, array('key', 'kind', 'address', 'render', 'record', 'bundle_change', 'open_items'));
        $open_total = array_sum($v['open_items']);
        if ($open_total) {
            WP_CLI::warning("{$open_total} open item(s) (pending markers) across the pages. --publish would be refused.");
        }

        $publish = isset($assoc['publish']);
        if ($publish && $open_total && !isset($assoc['allow-open-items'])) {
            WP_CLI::error('Refusing to publish with open items. Fill them in the pipeline (site-facts.json) and rebuild, or pass --allow-open-items.');
        }
        if ($dry) {
            WP_CLI::line('Public switch dh_reports_public: ' . (DH_Reports::public_enabled() ? '1' : '0') . '.');
            if ($publish) {
                WP_CLI::line('With --publish these records would become published; visitors would see them ' . (DH_Reports::public_enabled() ? 'immediately.' : 'only after the switch is turned on.'));
            }
            WP_CLI::line('Sitemap (/reports-sitemap.xml) would list, once published and the switch is on:');
            foreach ($m['pages'] as $p) {
                if (!empty($p['sitemap'])) {
                    // An edition is listed at the report's permanent address while it is the newest.
                    $loc = ($p['kind'] === 'edition' && !empty($p['report'])) ? '/reports/' . $p['report'] . '/' : $p['urls'][0];
                    WP_CLI::line('  ' . home_url($loc));
                }
            }
            WP_CLI::line('IndexNow would be sent the same addresses at publish time, with the switch on. Cache purge: only those addresses.');
            WP_CLI::success('Dry run finished. Nothing was written.');
            return;
        }

        // Copy the bundle into the store (once), then verify the copy.
        $dest = DH_Reports::bundle_dir($id);
        if (!is_dir($dest)) {
            if (!wp_mkdir_p($dest)) {
                WP_CLI::error("Cannot create {$dest}. Check that the PHP user can write to " . $store . '.');
            }
            $this->copy_tree($dir, $dest);
            $again = DH_Reports::validate_bundle($dest);
            if ($again['errors']) {
                foreach ($again['errors'] as $e) {
                    WP_CLI::warning($e);
                }
                $this->remove_tree($dest);
                WP_CLI::error('The stored copy failed validation and was removed.');
            }
            WP_CLI::line("Stored bundle at {$dest}");
        } else {
            WP_CLI::line("Bundle {$id} was already stored.");
        }

        $post_ids = array();
        foreach ($m['pages'] as $p) {
            $post_id = DH_Reports::find_post($p['key']);
            if (!$post_id) {
                $post_id = wp_insert_post(array(
                    'post_type' => DH_Reports::POST_TYPE, 'post_status' => 'draft', 'post_title' => $p['title'], 'post_name' => $p['key'],
                    'post_date_gmt' => '0000-00-00 00:00:00',
                ), true);
                if (is_wp_error($post_id)) {
                    WP_CLI::error('Could not create record for ' . $p['key'] . ': ' . $post_id->get_error_message());
                }
                update_post_meta($post_id, '_dh_report_key', $p['key']);
            } else {
                wp_update_post(array('ID' => $post_id, 'post_title' => $p['title']));
                $old = (string) get_post_meta($post_id, '_dh_report_bundle', true);
                if ($old !== '' && $old !== $id) {
                    update_post_meta($post_id, '_dh_report_prev_bundle', $old);
                }
            }
            update_post_meta($post_id, '_dh_report_kind', $p['kind']);
            update_post_meta($post_id, '_dh_report_bundle', $id);
            update_post_meta($post_id, '_dh_report_imported_at', time());
            $post_ids[$p['key']] = $post_id;
        }
        wp_cache_delete('manifest_' . $id, 'dh_reports');
        DH_Reports::rebuild_routes();
        flush_rewrite_rules(false);
        WP_CLI::success('Records written: ' . implode(', ', array_map(function ($k, $i) {
            return "{$k} #{$i}";
        }, array_keys($post_ids), $post_ids)));

        $published_ids = array();
        if ($publish) {
            foreach ($post_ids as $key => $post_id) {
                wp_update_post(array('ID' => $post_id, 'post_status' => 'publish'));
                $published_ids[] = $post_id;
            }
            DH_Reports::rebuild_routes();
            WP_CLI::success('Published ' . count($published_ids) . ' record(s).');
        }
        // Purge and submit only for pages that are actually visible to visitors.
        $this->after_change($post_ids, $publish);
    }

    private function unpublish($assoc) {
        $ids = $this->target_ids($assoc);
        if (!$ids) {
            WP_CLI::error('No matching report records.');
        }
        $urls = array();
        foreach ($ids as $post_id) {
            $url = DH_Reports::canonical_url_for_post($post_id);
            wp_update_post(array('ID' => $post_id, 'post_status' => 'draft'));
            $e = DH_Reports::page_entry($post_id);
            if ($e) {
                foreach ($e['page']['urls'] as $u) {
                    $urls[] = home_url($u);
                }
            }
            if ($url) {
                $urls[] = $url;
            }
        }
        DH_Reports::rebuild_routes();
        $urls = array_values(array_unique($urls));
        $this->purge($urls);
        if (DH_Reports::public_enabled() && class_exists('DH_IndexNow_Helper')) {
            $r = DH_IndexNow_Helper::submit_urls($urls);
            WP_CLI::line('IndexNow: ' . (!empty($r['success']) ? 'submitted ' . count($urls) . ' URL(s) for recrawl' : 'not submitted: ' . ($r['error'] ?? 'see log')));
        }
        WP_CLI::success('Set ' . count($ids) . ' record(s) to draft. Visitors now get a 404.');
    }

    private function rollback($assoc) {
        $ids = $this->target_ids($assoc);
        $n = 0;
        foreach ($ids as $post_id) {
            $prev = (string) get_post_meta($post_id, '_dh_report_prev_bundle', true);
            $cur = (string) get_post_meta($post_id, '_dh_report_bundle', true);
            if ($prev === '' || !is_dir(DH_Reports::bundle_dir($prev))) {
                WP_CLI::warning('Record #' . $post_id . ' has no stored previous bundle; skipped.');
                continue;
            }
            update_post_meta($post_id, '_dh_report_bundle', $prev);
            update_post_meta($post_id, '_dh_report_prev_bundle', $cur);
            update_post_meta($post_id, '_dh_report_imported_at', time());
            $n++;
        }
        DH_Reports::rebuild_routes();
        $this->after_change($ids, false);
        WP_CLI::success("Rolled back {$n} record(s). Run again to swap forward.");
    }

    private function target_ids($assoc) {
        $ids = array();
        foreach (get_posts(array('post_type' => DH_Reports::POST_TYPE, 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 'no_found_rows' => true)) as $id) {
            if (isset($assoc['key']) && get_post_meta($id, '_dh_report_key', true) !== $assoc['key']) {
                continue;
            }
            $ids[] = (int) $id;
        }
        return $ids;
    }

    private function after_change($post_ids, $announce) {
        $post_ids = array_values($post_ids);
        $urls = array();
        $published = array();
        foreach ($post_ids as $id) {
            if (get_post_status($id) !== 'publish') {
                continue;
            }
            $published[] = $id;
            $e = DH_Reports::page_entry($id);
            if ($e) {
                foreach ($e['page']['urls'] as $u) {
                    $urls[] = home_url($u);
                }
                foreach (isset($e['page']['files']) ? $e['page']['files'] : array() as $f) {
                    $urls[] = home_url($f['url']);
                }
            }
        }
        if (!$published) {
            WP_CLI::line('No published records changed: nothing purged, nothing submitted.');
            return;
        }
        $this->purge($urls);
        if ($announce && DH_Reports::public_enabled() && class_exists('DH_IndexNow_Helper')) {
            $r = DH_IndexNow_Helper::refresh_and_submit($published);
            WP_CLI::line('IndexNow: submitted ' . count($r['submitted']) . ' URL(s).');
        } elseif ($announce) {
            WP_CLI::line('IndexNow not sent: dh_reports_public is 0.');
        }
    }

    /** Targeted purge: only these URLs, never a site-wide purge. */
    private function purge($urls) {
        foreach ($urls as $u) {
            do_action('litespeed_purge_url', esc_url_raw($u));
        }
        WP_CLI::line('Purged ' . count($urls) . ' URL(s) from the page cache.');
    }

    private function copy_tree($src, $dst) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
        foreach ($it as $item) {
            $rel = substr($item->getPathname(), strlen(rtrim($src, '/')) + 1);
            $target = $dst . '/' . $rel;
            if ($item->isDir()) {
                wp_mkdir_p($target);
            } else {
                wp_mkdir_p(dirname($target));
                if (!copy($item->getPathname(), $target)) {
                    WP_CLI::error('Copy failed: ' . $rel);
                }
            }
        }
    }

    private function remove_tree($dir) {
        if (!is_dir($dir) || strpos(realpath($dir), realpath(DH_Reports::store_dir() . '/bundles') . '/') !== 0) {
            return;
        }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }
}
