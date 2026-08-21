<?php
/**
 * WP-CLI command that runs the full cache priming sequence as one job:
 * search cache rebuild, object cache pre-warm, then the four prime-cache
 * presets in order (paying profiles go early, before the long tail). Mirrors the "Cache Priming" block from the admin page
 * so the Prime All Caches button (and ~/prime-cache.sh) run one command.
 *
 * @package Directory_Helpers
 */

if (!class_exists('DH_Prime_All_Command')) {
    class DH_Prime_All_Command extends WP_CLI_Command {

        /**
         * Run the complete cache priming sequence.
         *
         * ## OPTIONS
         *
         * [--concurrency=<num>]
         * : Concurrent requests for each prime-cache preset. Default: 7
         *
         * ## EXAMPLES
         *
         *     wp directory-helpers prime-all
         *     wp directory-helpers prime-all --concurrency=5
         *
         * @when after_wp_load
         */
        public function __invoke($args, $assoc_args) {
            $concurrency = isset($assoc_args['concurrency']) ? (int) $assoc_args['concurrency'] : 7;
            $concurrency = max(1, min(10, $concurrency));

            $steps = array(
                'dh search rebuild-cache',
                'directory-helpers pre-warm-object-cache',
                "directory-helpers prime-cache --preset=priority --concurrency={$concurrency}",
                "directory-helpers prime-cache --preset=paid --concurrency={$concurrency}",
                "directory-helpers prime-cache --preset=listings --concurrency={$concurrency}",
                "directory-helpers prime-cache --preset=profiles --concurrency={$concurrency}",
            );

            $started  = time();
            $failures = array();

            foreach ($steps as $i => $step) {
                WP_CLI::log(sprintf('=== [%d/%d] wp %s - %s', $i + 1, count($steps), $step, date('H:i:s')));
                // In-process (launch=false) keeps the whole run under one PID so
                // the admin Stop button can kill it cleanly.
                $result = WP_CLI::runcommand($step, array(
                    'launch'     => false,
                    'exit_error' => false,
                    'return'     => 'return_code',
                ));
                if ($result !== 0) {
                    $failures[] = $step;
                    WP_CLI::warning("Step failed (exit {$result}): wp {$step} - continuing.");
                }
            }

            $mins = round((time() - $started) / 60, 1);
            if ($failures) {
                WP_CLI::error(sprintf('prime-all finished in %s min with %d failed step(s): %s', $mins, count($failures), implode('; ', $failures)));
            }
            WP_CLI::success("prime-all completed all " . count($steps) . " steps in {$mins} min.");
        }
    }
}
