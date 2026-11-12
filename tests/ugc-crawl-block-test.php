<?php
/**
 * Plain-PHP tests for the update-profile crawl block (robots.txt rule + nofollow).
 *
 * Run: php tests/ugc-crawl-block-test.php
 * Exits 0 on pass, 1 on any failure.
 */

define('ABSPATH', dirname(__DIR__) . '/');
function add_filter() {}
function add_action() {}
function add_shortcode() {}
require_once dirname(__DIR__) . '/modules/ugc-update-requests/ugc-update-requests.php';

$failures = 0;
function check($label, $ok) {
    global $failures;
    echo ($ok ? 'PASS' : 'FAIL') . ": {$label}\n";
    if (!$ok) {
        $failures++;
    }
}

$live = "User-agent: *\nDisallow: /wp-admin/\nAllow: /wp-admin/admin-ajax.php\n\nSitemap: https://goodydoggy.com/sitemap_index.xml\n";
$out  = DH_UGC_Update_Requests::robots_disallow_identified($live, true);
check('rule sits inside the User-agent: * group', strpos($out, "User-agent: *\nDisallow: /update-profile/?\nDisallow: /wp-admin/") === 0);
check('sitemap line kept', strpos($out, 'Sitemap: https://goodydoggy.com/sitemap_index.xml') !== false);
check('running twice adds the rule once', substr_count(DH_UGC_Update_Requests::robots_disallow_identified($out, true), 'Disallow: /update-profile/?') === 1);
check('no User-agent group: a new group is added', strpos(DH_UGC_Update_Requests::robots_disallow_identified("Sitemap: x\n", true), "User-agent: *\nDisallow: /update-profile/?\n") !== false);
check('non-public site left alone', DH_UGC_Update_Requests::robots_disallow_identified($live, false) === $live);

$html = '<p><a href="/update-profile/?pid=10887">Send us your changes</a> <a href="/get-listed/">Get listed</a></p>';
$got  = DH_UGC_Update_Requests::nofollow_pid_links($html);
check('pid link gets rel=nofollow', strpos($got, '<a rel="nofollow" href="/update-profile/?pid=10887">') !== false);
check('other links untouched', strpos($got, '<a href="/get-listed/">') !== false);
check('non-string passes through', DH_UGC_Update_Requests::nofollow_pid_links(array('x')) === array('x'));

exit($failures ? 1 : 0);
