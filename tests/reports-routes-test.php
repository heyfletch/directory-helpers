<?php
/**
 * Plain-PHP tests for DH_Reports_Routes.
 *
 * Run: php tests/reports-routes-test.php
 * Exits 0 on pass, 1 on any failure.
 */

define('ABSPATH', dirname(__DIR__) . '/');
require_once dirname(__DIR__) . '/modules/reports/class-dh-reports-routes.php';

$failures = 0;
$tests = 0;

function check($label, $actual, $expected) {
    global $failures, $tests;
    $tests++;
    if ($actual === $expected) {
        echo "PASS: {$label}\n";
    } else {
        $failures++;
        $a = var_export($actual, true);
        $e = var_export($expected, true);
        echo "FAIL: {$label}\n      expected {$e}\n      got      {$a}\n";
    }
}

// normalize and is_ours
check('normalize adds leading slash', DH_Reports_Routes::normalize('reports/x/'), '/reports/x/');
check('normalize drops query', DH_Reports_Routes::normalize('/reports/x/?a=1#b'), '/reports/x/');
check('normalize collapses slashes', DH_Reports_Routes::normalize('//reports///x/'), '/reports/x/');
check('normalize rejects dot segments', DH_Reports_Routes::normalize('/reports/../wp-config.php'), '');
check('normalize rejects NUL', DH_Reports_Routes::normalize("/reports/x\0"), '');
check('is_ours /reports', DH_Reports_Routes::is_ours('/reports'), true);
check('is_ours /reports/', DH_Reports_Routes::is_ours('/reports/'), true);
check('is_ours stats hub', DH_Reports_Routes::is_ours('/dog-training-statistics/'), true);
check('is_ours stats hub file', DH_Reports_Routes::is_ours('/dog-training-statistics/data/state-table.csv'), true);
check('is_ours not reports-sitemap', DH_Reports_Routes::is_ours('/reports-sitemap.xml'), false);
check('is_ours not report-a-concern', DH_Reports_Routes::is_ours('/report-a-concern/'), false);
check('is_ours not other', DH_Reports_Routes::is_ours('/how-we-rank/'), false);

// validate_urls
check('valid urls pass', DH_Reports_Routes::validate_urls(array('/reports/a/2026/', '/reports/press/'), array('/reports/a/2026/x.csv')), array());
check('page url needs slash', count(DH_Reports_Routes::validate_urls(array('/reports/a'), array())) > 0, true);
check('page url outside space', count(DH_Reports_Routes::validate_urls(array('/how-we-rank/'), array())) > 0, true);
check('file url must not end with slash', count(DH_Reports_Routes::validate_urls(array(), array('/reports/a/'))) > 0, true);
check('reserved url refused', count(DH_Reports_Routes::validate_urls(array('/reports/_status/'), array())) > 0, true);
check('duplicate url refused', count(DH_Reports_Routes::validate_urls(array('/reports/a/', '/reports/a/'), array())) > 0, true);

// table
$entries = array(
    array('post_id' => 10, 'key' => 'btr-2026', 'kind' => 'edition', 'report' => 'board-and-train-report', 'edition' => '2026',
          'urls' => array('/reports/board-and-train-report/2026/'),
          'files' => array(array('url' => '/reports/board-and-train-report/2026/state-table.csv', 'path' => 'files/state-table.csv', 'type' => 'text/csv', 'download' => true))),
    array('post_id' => 11, 'key' => 'btr-2027', 'kind' => 'edition', 'report' => 'board-and-train-report', 'edition' => '2027',
          'urls' => array('/reports/board-and-train-report/2027/'), 'files' => array()),
    array('post_id' => 12, 'key' => 'hub', 'kind' => 'hub', 'report' => '', 'edition' => '',
          'urls' => array('/dog-training-statistics/'), 'files' => array()),
);
$table = DH_Reports_Routes::build_table($entries);
$all = function ($id) { return true; };
$only10 = function ($id) { return $id === 10; };
$none = function ($id) { return false; };

check('latest list newest first', array_column($table['latest']['/reports/board-and-train-report/'], 'edition'), array('2027', '2026'));
$r = DH_Reports_Routes::resolve('/reports/board-and-train-report/', $table, $all);
check('alias resolves to newest edition', array($r['type'], $r['url'], $r['alias']), array('page', '/reports/board-and-train-report/2027/', true));
$r = DH_Reports_Routes::resolve('/reports/board-and-train-report/', $table, $only10);
check('alias skips an edition the viewer cannot see', array($r['type'], $r['url']), array('page', '/reports/board-and-train-report/2026/'));
check('alias with nothing visible is null', DH_Reports_Routes::resolve('/reports/board-and-train-report/', $table, $none), null);
$r = DH_Reports_Routes::resolve('/reports/board-and-train-report/2026/', $table, $all);
check('edition page resolves', array($r['type'], $r['alias'], $r['key']), array('page', false, 'btr-2026'));
check('edition page hidden is null', DH_Reports_Routes::resolve('/reports/board-and-train-report/2027/', $table, $only10), null);
$r = DH_Reports_Routes::resolve('/reports/board-and-train-report/2026', $table, $all);
check('missing slash redirects', array($r['type'], $r['to']), array('redirect', '/reports/board-and-train-report/2026/'));
check('missing slash on hidden page is null', DH_Reports_Routes::resolve('/reports/board-and-train-report/2027', $table, $only10), null);
$r = DH_Reports_Routes::resolve('/reports/board-and-train-report/2026/state-table.csv', $table, $all);
check('file resolves', array($r['type'], $r['path'], $r['mime'], $r['download']), array('file', 'files/state-table.csv', 'text/csv', true));
check('file of hidden edition is null', DH_Reports_Routes::resolve('/reports/board-and-train-report/2026/state-table.csv', $table, $none), null);
check('unknown path is null', DH_Reports_Routes::resolve('/reports/nothing/', $table, $all), null);
check('path outside space is null', DH_Reports_Routes::resolve('/about/', $table, $all), null);
check('dot segments are null', DH_Reports_Routes::resolve('/reports/../x/', $table, $all), null);
$r = DH_Reports_Routes::resolve('/dog-training-statistics/', $table, $all);
check('hub resolves', array($r['type'], $r['kind']), array('page', 'hub'));

// canonical
$hit2026 = DH_Reports_Routes::resolve('/reports/board-and-train-report/2026/', $table, $all);
check('older edition canonical is itself', DH_Reports_Routes::canonical($hit2026, $table, $all), '/reports/board-and-train-report/2026/');
$hit2026b = DH_Reports_Routes::resolve('/reports/board-and-train-report/2026/', $table, $only10);
check('only visible edition canonical is the alias', DH_Reports_Routes::canonical($hit2026b, $table, $only10), '/reports/board-and-train-report/');
$hitHub = DH_Reports_Routes::resolve('/dog-training-statistics/', $table, $all);
check('hub canonical is itself', DH_Reports_Routes::canonical($hitHub, $table, $all), '/dog-training-statistics/');

// mime and download name
check('mime csv', DH_Reports_Routes::mime('a.csv'), 'text/csv; charset=utf-8');
check('mime png', DH_Reports_Routes::mime('a.PNG'), 'image/png');
check('mime unknown', DH_Reports_Routes::mime('a.exe'), 'application/octet-stream');
check('download name strips odd characters', DH_Reports_Routes::download_name('/reports/x/a b"c.csv'), 'a-b-c.csv');

echo "\n{$tests} tests, {$failures} failures\n";
exit($failures ? 1 : 0);
