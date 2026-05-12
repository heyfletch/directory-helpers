<?php
/**
 * Admin CLI Runner Module
 *
 * Provides admin UI buttons to trigger WP-CLI commands via background processes.
 * Supports a configurable number of concurrent jobs; additional requests are queued
 * and automatically promoted when a running slot opens up.
 *
 * @package Directory_Helpers
 */

if (!defined('ABSPATH')) {
    exit;
}

class DH_Admin_CLI_Runner {

    /**
     * WP option key that stores the entire job queue (JSON array).
     */
    const OPTION_QUEUE = 'dh_cli_queue';

    /**
     * Maximum number of jobs that may run simultaneously.
     * Increase this to match your server's thread count.
     */
    const MAX_CONCURRENT = 2;

    public function __construct() {
        // AJAX handlers
        add_action('wp_ajax_dh_run_cli_command',  array($this, 'ajax_run_command'));
        add_action('wp_ajax_dh_get_cli_status',   array($this, 'ajax_get_status'));
        add_action('wp_ajax_dh_stop_cli_command', array($this, 'ajax_stop_command'));

        // Enqueue scripts on admin pages
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));

        // Add term meta boxes for area and state taxonomies
        add_action('area_edit_form_fields',  array($this, 'render_area_term_actions'),  10, 2);
        add_action('state_edit_form_fields', array($this, 'render_state_term_actions'), 10, 2);

        // Add meta box on profile post edit pages
        add_action('add_meta_boxes', array($this, 'register_profile_meta_box'));
    }

    // -------------------------------------------------------------------------
    // Queue helpers
    // -------------------------------------------------------------------------

    /**
     * Load the queue from the DB. Returns an array of job objects (assoc arrays).
     */
    private function load_queue() {
        $raw = get_option(self::OPTION_QUEUE, '[]');
        $queue = json_decode($raw, true);
        return is_array($queue) ? $queue : array();
    }

    /**
     * Persist the queue to the DB.
     */
    private function save_queue(array $queue) {
        update_option(self::OPTION_QUEUE, json_encode(array_values($queue)));
    }

    /**
     * Check whether a PID is actually alive on this system.
     */
    private function pid_is_alive($pid) {
        $pid = (int) $pid;
        if ($pid <= 0) {
            return false;
        }
        // kill -0 does not send a signal; it just checks whether the process exists.
        exec("kill -0 {$pid} 2>/dev/null", $out, $rc);
        return $rc === 0;
    }

    /**
     * Reap finished jobs and promote queued ones into open slots.
     * Call this at the start of every status poll.
     *
     * @return array The updated queue.
     */
    private function reap_and_promote() {
        $queue = $this->load_queue();

        // 1. Reap: mark running jobs whose process has died as completed/failed.
        foreach ($queue as &$job) {
            if ($job['status'] !== 'running') {
                continue;
            }
            if (!$this->pid_is_alive($job['pid'])) {
                // Process is gone — determine success/failure from log tail.
                $log_file = $job['log_file'];
                if ($log_file && file_exists($log_file)) {
                    $tail = file_get_contents($log_file);
                    if (strpos($tail, 'Success:') !== false) {
                        $job['status'] = 'completed';
                    } elseif (strpos($tail, 'Error:') !== false || strpos($tail, 'Warning:') !== false) {
                        $job['status'] = 'failed';
                    } else {
                        // Log exists but no clear signal — treat as completed.
                        $job['status'] = 'completed';
                    }
                } else {
                    $job['status'] = 'failed';
                }
                $job['finished_at'] = time();
            }
        }
        unset($job);

        // 2. Count open slots.
        $running_count = count(array_filter($queue, function($j) { return $j['status'] === 'running'; }));
        $open_slots    = self::MAX_CONCURRENT - $running_count;

        // 3. Promote: move queued jobs into open slots.
        foreach ($queue as &$job) {
            if ($open_slots <= 0) {
                break;
            }
            if ($job['status'] !== 'queued') {
                continue;
            }
            $pid = $this->launch_process($job['command'], $job['log_file']);
            if ($pid) {
                $job['status']     = 'running';
                $job['pid']        = $pid;
                $job['started_at'] = time();
                $open_slots--;
            } else {
                $job['status'] = 'failed';
            }
        }
        unset($job);

        $this->save_queue($queue);
        return $queue;
    }

    /**
     * Launch a WP-CLI command in the background and return its PID (or 0 on failure).
     */
    private function launch_process($command, $log_file) {
        $wp_path = ABSPATH;

        // Find wp-cli executable.
        $wp_cli_path = false;
        foreach (array('/usr/local/bin/wp', '/usr/bin/wp', '/opt/homebrew/bin/wp') as $p) {
            if (file_exists($p)) {
                $wp_cli_path = $p;
                break;
            }
        }
        if (!$wp_cli_path) {
            $found = trim(shell_exec('which wp 2>/dev/null'));
            if (!empty($found) && file_exists($found)) {
                $wp_cli_path = $found;
            }
        }
        if (!$wp_cli_path) {
            return 0;
        }

        $pid_file = $log_file . '.pid';

        $full_command = sprintf(
            'cd %s && %s directory-helpers %s --path=%s > %s 2>&1 & echo $! > %s',
            escapeshellarg($wp_path),
            escapeshellarg($wp_cli_path),
            $command,
            escapeshellarg($wp_path),
            escapeshellarg($log_file),
            escapeshellarg($pid_file)
        );

        exec($full_command);

        // Give the shell a moment to write the PID file.
        usleep(100000); // 0.1 s

        if (file_exists($pid_file)) {
            $pid = (int) trim(file_get_contents($pid_file));
            return $pid > 0 ? $pid : 0;
        }
        return 0;
    }

    // -------------------------------------------------------------------------
    // Enqueue scripts
    // -------------------------------------------------------------------------

    /**
     * Enqueue admin scripts
     */
    public function enqueue_admin_scripts($hook) {
        $load_on = array(
            'toplevel_page_directory-helpers',
            'term.php',
            'post.php',
            'post-new.php',
        );

        if (!in_array($hook, $load_on)) {
            return;
        }

        if ($hook === 'term.php') {
            $taxonomy = isset($_GET['taxonomy']) ? sanitize_key($_GET['taxonomy']) : '';
            if (!in_array($taxonomy, array('area', 'state'))) {
                return;
            }
        }

        if (in_array($hook, array('post.php', 'post-new.php'))) {
            $post_type = isset($_GET['post']) ? get_post_type($_GET['post']) : (isset($_POST['post_type']) ? sanitize_key($_POST['post_type']) : '');
            if (!in_array($post_type, array('city-listing', 'profile'))) {
                return;
            }
        }

        wp_enqueue_script(
            'dh-cli-runner',
            plugin_dir_url(__FILE__) . 'assets/js/cli-runner.js',
            array('jquery'),
            filemtime(plugin_dir_path(__FILE__) . 'assets/js/cli-runner.js'),
            true
        );

        wp_localize_script('dh-cli-runner', 'dhCliRunner', array(
            'ajaxUrl'      => admin_url('admin-ajax.php'),
            'nonce'        => wp_create_nonce('dh_cli_runner'),
            'maxConcurrent' => self::MAX_CONCURRENT,
            'strings'      => array(
                'running'      => __('Running…', 'directory-helpers'),
                'queued'       => __('Queued…', 'directory-helpers'),
                'completed'    => __('Completed!', 'directory-helpers'),
                'failed'       => __('Failed', 'directory-helpers'),
                'stopped'      => __('Stopped', 'directory-helpers'),
                'confirm_stop' => __('Stop this command?', 'directory-helpers'),
                'confirm_stop_all' => __('Stop ALL running and queued commands?', 'directory-helpers'),
            ),
        ));

        wp_enqueue_style(
            'dh-cli-runner',
            plugin_dir_url(__FILE__) . 'assets/css/cli-runner.css',
            array(),
            filemtime(plugin_dir_path(__FILE__) . 'assets/css/cli-runner.css')
        );
    }

    // -------------------------------------------------------------------------
    // AJAX handlers
    // -------------------------------------------------------------------------

    /**
     * AJAX: Enqueue (and possibly immediately start) a CLI command.
     */
    public function ajax_run_command() {
        check_ajax_referer('dh_cli_runner', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permission denied'));
        }

        $command = isset($_POST['command']) ? sanitize_text_field($_POST['command']) : '';

        // Whitelist of allowed commands.
        $allowed_commands = array(
            'update-rankings',
            'update-state-rankings',
            'update-rankings-for-profile',
            'analyze-radius',
            'prime-cache',
        );

        $parts        = explode(' ', $command);
        $base_command = isset($parts[0]) ? $parts[0] : '';

        if (!in_array($base_command, $allowed_commands)) {
            wp_send_json_error(array('message' => 'Command not allowed: ' . $base_command));
        }

        // Reap dead jobs first so we have an accurate count.
        $queue = $this->reap_and_promote();

        // Reject duplicate: same command already queued or running.
        foreach ($queue as $job) {
            if (in_array($job['status'], array('queued', 'running')) && $job['command'] === $command) {
                wp_send_json_error(array('message' => 'This command is already queued or running.'));
            }
        }

        // Build a unique log file for this job.
        $log_file = wp_upload_dir()['basedir'] . '/dh-cli-log-' . time() . '-' . mt_rand(1000, 9999) . '.log';

        $job_id = uniqid('dhjob_', true);
        $new_job = array(
            'id'          => $job_id,
            'command'     => $command,
            'status'      => 'queued',
            'pid'         => 0,
            'log_file'    => $log_file,
            'queued_at'   => time(),
            'started_at'  => 0,
            'finished_at' => 0,
        );

        $queue[] = $new_job;
        $this->save_queue($queue);

        // Immediately try to promote (may launch right away if a slot is free).
        $queue = $this->reap_and_promote();

        // Find this job's current status.
        $job_status = 'queued';
        foreach ($queue as $j) {
            if ($j['id'] === $job_id) {
                $job_status = $j['status'];
                break;
            }
        }

        wp_send_json_success(array(
            'message' => $job_status === 'running'
                ? 'Command started: ' . $command
                : 'Command queued: ' . $command,
            'job_id'  => $job_id,
            'status'  => $job_status,
            'queue'   => $this->queue_for_response($queue),
        ));
    }

    /**
     * AJAX: Return full queue state (reaps dead jobs, promotes queued ones).
     */
    public function ajax_get_status() {
        check_ajax_referer('dh_cli_runner', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permission denied'));
        }

        $queue = $this->reap_and_promote();

        // Prune finished jobs quickly (30 s) so they don't appear on other pages.
        $cutoff = time() - 30;
        $queue = array_filter($queue, function($j) use ($cutoff) {
            if (in_array($j['status'], array('completed', 'failed', 'stopped'))) {
                return ($j['finished_at'] > $cutoff);
            }
            return true;
        });
        $this->save_queue($queue);

        $has_active = false;
        foreach ($queue as $j) {
            if (in_array($j['status'], array('queued', 'running'))) {
                $has_active = true;
                break;
            }
        }

        wp_send_json_success(array(
            'has_active' => $has_active,
            'queue'      => $this->queue_for_response($queue),
        ));
    }

    /**
     * AJAX: Stop one job (by job_id) or all jobs.
     */
    public function ajax_stop_command() {
        check_ajax_referer('dh_cli_runner', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permission denied'));
        }

        $job_id   = isset($_POST['job_id']) ? sanitize_text_field($_POST['job_id']) : '';
        $stop_all = !empty($_POST['stop_all']);

        $queue = $this->load_queue();

        foreach ($queue as &$job) {
            if (!in_array($job['status'], array('queued', 'running'))) {
                continue;
            }
            if (!$stop_all && $job['id'] !== $job_id) {
                continue;
            }

            // Kill the process if running.
            if ($job['status'] === 'running' && $job['pid'] > 0) {
                exec("kill -TERM {$job['pid']} 2>/dev/null");
                // Give child processes a moment to die, then SIGKILL.
                usleep(200000);
                exec("kill -KILL {$job['pid']} 2>/dev/null");
            }
            $job['status']      = 'stopped';
            $job['finished_at'] = time();
        }
        unset($job);

        $this->save_queue($queue);

        wp_send_json_success(array(
            'message' => $stop_all ? 'All commands stopped.' : 'Command stopped.',
            'queue'   => $this->queue_for_response($queue),
        ));
    }

    // -------------------------------------------------------------------------
    // Response helpers
    // -------------------------------------------------------------------------

    /**
     * Build a sanitised queue array suitable for JSON response.
     * Includes the last 2000 chars of each job's log file.
     */
    private function queue_for_response(array $queue) {
        $out = array();
        foreach ($queue as $job) {
            $log_tail = '';
            if (!empty($job['log_file']) && file_exists($job['log_file'])) {
                $log_tail = substr(file_get_contents($job['log_file']), -2000);
            }
            $out[] = array(
                'id'          => $job['id'],
                'command'     => $job['command'],
                'status'      => $job['status'],
                'pid'         => $job['pid'],
                'queued_at'   => $job['queued_at'],
                'started_at'  => $job['started_at'],
                'finished_at' => $job['finished_at'],
                'log'         => $log_tail,
            );
        }
        return $out;
    }

    // -------------------------------------------------------------------------
    // Term meta boxes
    // -------------------------------------------------------------------------

    /**
     * Render action buttons for area term edit page
     */
    public function render_area_term_actions($term, $taxonomy) {
        static $area_rendered = false;
        if ($area_rendered) {
            return;
        }
        $area_rendered = true;

        $term_slug = $term->slug;
        ?>
        <tr class="form-field">
            <th scope="row"><?php esc_html_e('Quick Actions', 'directory-helpers'); ?></th>
            <td>
                <div class="dh-cli-actions">
                    <button type="button" class="button dh-cli-run-btn"
                            data-command="update-rankings dog-trainer --city=<?php echo esc_attr($term_slug); ?>">
                        <span class="dashicons dashicons-sort"></span>
                        <?php esc_html_e('Update Rankings', 'directory-helpers'); ?>
                    </button>
                    <span class="dh-cli-status"></span>
                </div>
                <div class="dh-cli-actions" style="margin-top: 10px;">
                    <button type="button" class="button dh-cli-run-btn"
                            data-command="analyze-radius dog-trainer <?php echo esc_attr($term_slug); ?> --update-meta">
                        <span class="dashicons dashicons-location-alt"></span>
                        <?php esc_html_e('Analyze Radius', 'directory-helpers'); ?>
                    </button>
                    <span class="dh-cli-status"></span>
                </div>
                <p class="description">
                    <?php esc_html_e('Update Rankings: Recalculates profile rankings for this city. Analyze Radius: Updates the recommended radius based on nearby profiles.', 'directory-helpers'); ?>
                </p>
            </td>
        </tr>
        <?php
    }

    /**
     * Register meta box on profile post edit pages
     */
    public function register_profile_meta_box() {
        add_meta_box(
            'dh-update-rankings-for-profile',
            __('Update Rankings', 'directory-helpers'),
            array($this, 'render_profile_meta_box'),
            'profile',
            'side',
            'default'
        );
    }

    /**
     * Render the "Update Rankings" meta box on profile edit pages
     */
    public function render_profile_meta_box($post) {
        $post_id = $post->ID;

        // Collect area terms for display.
        $area_terms  = get_the_terms($post_id, 'area');
        $state_terms = get_the_terms($post_id, 'state');

        $area_names  = (!empty($area_terms) && !is_wp_error($area_terms))
            ? implode(', ', wp_list_pluck($area_terms, 'name'))
            : '—';

        $state_names = (!empty($state_terms) && !is_wp_error($state_terms))
            ? implode(', ', wp_list_pluck($state_terms, 'name'))
            : '—';

        $command = 'update-rankings-for-profile --profile=' . $post_id;
        ?>
        <div class="dh-cli-actions">
            <p style="margin:0 0 8px;">
                <strong><?php esc_html_e('Cities:', 'directory-helpers'); ?></strong>
                <span style="color:#555;"><?php echo esc_html($area_names); ?></span>
            </p>
            <p style="margin:0 0 10px;">
                <strong><?php esc_html_e('States:', 'directory-helpers'); ?></strong>
                <span style="color:#555;"><?php echo esc_html($state_names); ?></span>
            </p>
            <button type="button" class="button button-primary dh-cli-run-btn"
                    data-command="<?php echo esc_attr($command); ?>"
                    style="width:100%;">
                <span class="dashicons dashicons-sort" style="margin-top:3px;"></span>
                <?php esc_html_e('Update All Rankings', 'directory-helpers'); ?>
            </button>
            <span class="dh-cli-status" style="display:block;margin-top:6px;"></span>
        </div>
        <p class="description" style="margin-top:8px;">
            <?php esc_html_e('Recalculates city_rank for every city this profile is tagged with, and state_rank for every state. Purges page cache for all affected listing pages.', 'directory-helpers'); ?>
        </p>
        <?php
    }

    /**
     * Render action buttons for state term edit page
     */
    public function render_state_term_actions($term, $taxonomy) {
        static $state_rendered = false;
        if ($state_rendered) {
            return;
        }
        $state_rendered = true;

        $term_slug = $term->slug;
        ?>
        <tr class="form-field">
            <th scope="row"><?php esc_html_e('Quick Actions', 'directory-helpers'); ?></th>
            <td>
                <div class="dh-cli-actions">
                    <button type="button" class="button dh-cli-run-btn"
                            data-command="update-state-rankings dog-trainer --state=<?php echo esc_attr($term_slug); ?>">
                        <span class="dashicons dashicons-sort"></span>
                        <?php esc_html_e('Update State Rankings', 'directory-helpers'); ?>
                    </button>
                    <span class="dh-cli-status"></span>
                </div>
                <p class="description">
                    <?php esc_html_e('Recalculates state_rank for all profiles in this state.', 'directory-helpers'); ?>
                </p>
            </td>
        </tr>
        <?php
    }
}
