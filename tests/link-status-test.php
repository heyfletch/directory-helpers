<?php
/**
 * Plain-PHP tests for DH_Link_Status.
 *
 * Run: php tests/link-status-test.php
 * Exits 0 on pass, 1 on any failure.
 */

define('ABSPATH', dirname(__DIR__) . '/');
require_once dirname(__DIR__) . '/includes/class-dh-link-status.php';

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

$now = '2026-10-19 12:00:00';
$future = '2026-12-31 23:59:59';
$past = '2026-01-01 00:00:00';

// classify(): every bucket
check('classify 200 -> ok', DH_Link_Status::classify(200), 'ok');
check('classify 204 -> ok', DH_Link_Status::classify(204), 'ok');
check('classify 301 -> ok', DH_Link_Status::classify(301), 'ok');
check('classify 302 -> ok', DH_Link_Status::classify(302), 'ok');
check('classify 401 -> blocked', DH_Link_Status::classify(401), 'blocked');
check('classify 403 -> blocked', DH_Link_Status::classify(403), 'blocked');
check('classify 429 -> blocked', DH_Link_Status::classify(429), 'blocked');
check('classify 999 -> blocked', DH_Link_Status::classify(999), 'blocked');
check('classify 0 -> broken', DH_Link_Status::classify(0), 'broken');
check('classify 404 -> broken', DH_Link_Status::classify(404), 'broken');
check('classify 410 -> broken', DH_Link_Status::classify(410), 'broken');
check('classify 500 -> broken', DH_Link_Status::classify(500), 'broken');
check('classify 503 -> broken', DH_Link_Status::classify(503), 'broken');
check('classify 599 -> broken', DH_Link_Status::classify(599), 'broken');
check('classify 402 -> unknown', DH_Link_Status::classify(402), 'unknown');
check('classify 418 -> unknown', DH_Link_Status::classify(418), 'unknown');

// renders_as_link()
check('renders_as_link 200 -> true', DH_Link_Status::renders_as_link(200), true);
check('renders_as_link 301 -> true', DH_Link_Status::renders_as_link(301), true);
check('renders_as_link 403 -> true', DH_Link_Status::renders_as_link(403), true);
check('renders_as_link 404 -> false', DH_Link_Status::renders_as_link(404), false);
check('renders_as_link 0 -> false', DH_Link_Status::renders_as_link(0), false);
check('renders_as_link 500 -> false', DH_Link_Status::renders_as_link(500), false);
check('renders_as_link 503 -> false', DH_Link_Status::renders_as_link(503), false);

// effective_code(): expired and unexpired overrides
check('effective_code: unexpired override wins', DH_Link_Status::effective_code(404, 200, $future, $now), 200);
check('effective_code: expired override ignored', DH_Link_Status::effective_code(404, 200, $past, $now), 404);
check('effective_code: null override ignored', DH_Link_Status::effective_code(404, null, null, $now), 404);
check('effective_code: override without expiry ignored', DH_Link_Status::effective_code(404, 200, null, $now), 404);
check('effective_code: zero override ignored', DH_Link_Status::effective_code(404, 0, $future, $now), 404);
check('effective_code: no override returns base', DH_Link_Status::effective_code(301, null, null, $now), 301);

// needs_recheck(): never-checked, fresh, stale, fresh-but-broken
check('needs_recheck: never checked (null)', DH_Link_Status::needs_recheck(null, 30, $now, 200), true);
check('needs_recheck: never checked (empty string)', DH_Link_Status::needs_recheck('', 30, $now, 200), true);
check('needs_recheck: fresh row', DH_Link_Status::needs_recheck('2026-10-18 12:00:00', 30, $now, 200), false);
check('needs_recheck: stale row', DH_Link_Status::needs_recheck('2026-09-01 12:00:00', 30, $now, 200), true);
check('needs_recheck: fresh but broken', DH_Link_Status::needs_recheck('2026-10-18 12:00:00', 30, $now, 500), true);
check('needs_recheck: fresh but timeout', DH_Link_Status::needs_recheck('2026-10-18 12:00:00', 30, $now, 0), true);
check('needs_recheck: fresh blocked row not rechecked', DH_Link_Status::needs_recheck('2026-10-18 12:00:00', 30, $now, 403), false);
check('needs_recheck: exactly at cutoff not stale', DH_Link_Status::needs_recheck('2026-09-19 12:00:00', 30, $now, 200), false);

echo "\n{$tests} tests, {$failures} failures\n";
exit($failures === 0 ? 0 : 1);
