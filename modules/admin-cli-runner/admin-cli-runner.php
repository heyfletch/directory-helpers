<?php
/**
 * Admin CLI Runner Module
 *
 * Provides admin UI buttons to trigger WP-CLI commands via background processes.
 *
 * @package Directory_Helpers
 */

if (!defined('ABSPATH')) {
    exit;
}

class DH_Admin_CLI_Runner {

    const OPTION_RUNNING_COMMAND = 'dh_cli_running_command';
    const OPTION_COMMAND_LOG = 'dh_cli_command_log';
    const OPTION_COMMAND_STATUS = 'dh_cli_command_status';

    public function __construct() {
        // AJAX handlers
        add_action('wp_ajax_dh_run_cli_command', array($this, 'ajax_run_command'));
        add_action('wp_ajax_dh_get_cli_status', array($this, 'ajax_get_status'));
        add_action('wp_ajax_dh_stop_cli_command', array($this, 'ajax_stop_command'));

        // Enqueue scripts on admin pages
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));

        // Add term meta boxes for area and state taxonomies
        add_action('area_edit_form_fields', array($this, 'render_area_term_actions'), 10, 2);
        add_action('state_edit_form_fields', array($this, 'render_state_term_actions'), 10, 2);
    }

    /**
     * Enqueue admin scripts
     */
    public function enqueue_admin_scripts($hook) {
        // Load on directory-helpers admin page, term edit pages, and post edit pages
        $load_on = array(
            'toplevel_page_directory-helpers',
            'term.php',
            'post.php',
            'post-new.php',
        );

        if (!in_array($hook, $load_on)) {
            return;
        }

        // Check if we're on area or state term edit
        if ($hook === 'term.php') {
            $taxonomy = isset($_GET['taxonomy']) ? sanitize_key($_GET['taxonomy']) : '';
            if (!in_array($taxonomy, array('area', 'state'))) {
                return;
            }
        }

        // Check if we're on city-listing post edit
        if (in_array($hook, array('post.php', 'post-new.php'))) {
            $post_type = isset($_GET['post']) ? get_post_type($_GET['post']) : (isset($_POST['post_type']) ? sanitize_key($_POST['post_type']) : '');
            if ($post_type !== 'city-listing') {
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
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('dh_cli_runner'),
            'strings' => array(
                'running' => __('Running...', 'directory-helpers'),
                'completed' => __('Completed!', 'directory-helpers'),
                'failed' => __('Failed', 'directory-helpers'),
                'stopped' => __('Stopped', 'directory-helpers'),
                'confirm_stop' => __('Are you sure you want to stop this command?', 'directory-helpers'),
            ),
        ));

        wp_enqueue_style(
            'dh-cli-runner',
            plugin_dir_url(__FILE__) . 'assets/css/cli-runner.css',
            array(),
            filemtime(plugin_dir_path(__FILE__) . 'assets/css/cli-runner.css')
        );
    }

    /**
     * AJAX: Run a CLI command
     */
    public function ajax_run_command() {
        check_ajax_referer('dh_cli_runner', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permission denied'));
        }

        $command = isset($_POST['command']) ? sanitize_text_field($_POST['command']) : '';
        
        // Whitelist of allowed commands
        $allowed_commands = array(
            'update-rankings',
            'update-state-rankings',
            'analyze-radius',
            'prime-cache',
        );

        // Parse command to validate
        $parts = explode(' ', $command);
        $base_command = isset($parts[0]) ? $parts[0] : '';

        if (!in_array($base_command, $allowed_commands)) {
            wp_send_json_error(array('message' => 'Command not allowed: ' . $base_command));
        }

        // Check if a command is already running
        $running = get_option(self::OPTION_RUNNING_COMMAND, '');
        if (!empty($running)) {
            wp_send_json_error(array('message' => 'Another command is already running: ' . $running));
        }

        // Build full WP-CLI command with logging
        $wp_path = ABSPATH;
        $log_file = wp_upload_dir()['basedir'] . '/dh-cli-log-' . time() . '.log';
        
        // Find wp-cli executable - try common paths
        $wp_cli_path = false;
        $possible_paths = array(
            '/usr/local/bin/wp',
            '/usr/bin/wp',
            '/opt/homebrew/bin/wp',
            exec('which wp 2>/dev/null'),
        );
        
        foreach ($possible_paths as $path) {
            if (!empty($path) && file_exists($path)) {
                $wp_cli_path = $path;
                break;
            }
        }
        
        if (!$wp_cli_path) {
            wp_send_json_error(array('message' => 'WP-CLI not found. Tried: ' . implode(', ', $possible_paths)));
        }
        
        $full_command = sprintf(
            'cd %s && %s directory-helpers %s --path=%s > %s 2>&1 & echo $! > %s.pid',
            escapeshellarg($wp_path),
            escapeshellarg($wp_cli_path),
            $command,
            escapeshellarg($wp_path),
            escapeshellarg($log_file),
            escapeshellarg($log_file)
        );

        // Store command state
        update_option(self::OPTION_RUNNING_COMMAND, $command);
        update_option(self::OPTION_COMMAND_STATUS, 'running');
        update_option(self::OPTION_COMMAND_LOG, 'Started: ' . $command . "\nLog file: " . $log_file . "\n");
        update_option('dh_cli_log_file', $log_file);
        update_option('dh_cli_log_size', 0);
        update_option('dh_cli_log_check_time', time());

        // Execute command in background
        exec($full_command);

        // Store PID for tracking
        $pid_file = $log_file . '.pid';
        if (file_exists($pid_file)) {
            $pid = trim(file_get_contents($pid_file));
            update_option('dh_cli_command_pid', $pid);
        }
        
        wp_send_json_success(array(
            'message' => 'Command started: ' . $command,
            'command' => $command,
        ));
    }

    /**
     * AJAX: Get command status
     */
    public function ajax_get_status() {
        check_ajax_referer('dh_cli_runner', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permission denied'));
        }

        $running = get_option(self::OPTION_RUNNING_COMMAND, '');
        $status = get_option(self::OPTION_COMMAND_STATUS, 'idle');
        $log = get_option(self::OPTION_COMMAND_LOG, '');

        // Check if the command process is still running
        if ($status === 'running' && !empty($running)) {
            $log_file = get_option('dh_cli_log_file', '');
            $last_size = (int) get_option('dh_cli_log_size', 0);
            $last_check = (int) get_option('dh_cli_log_check_time', 0);
            $current_time = time();
            
            $is_running = false;
            $current_size = 0;
            
            if ($log_file && file_exists($log_file)) {
                $current_size = filesize($log_file);
                clearstatcache(true, $log_file);
                
                // Check if log file is still growing (command still running)
                if ($current_size > $last_size) {
                    $is_running = true;
                    update_option('dh_cli_log_size', $current_size);
                    update_option('dh_cli_log_check_time', $current_time);
                } elseif ($current_time - $last_check < 10) {
                    // Give it 10 seconds of no growth before declaring complete
                    $is_running = true;
                } else {
                    // Log file hasn't grown in 10+ seconds, check for Success message
                    $log_content = file_get_contents($log_file);
                    if (strpos($log_content, 'Success:') !== false || strpos($log_content, 'Error:') !== false) {
                        $is_running = false;
                    } else {
                        // Still running but slow
                        $is_running = true;
                    }
                }
                
                // Get last 500 chars of log for display
                $log_tail = substr(file_get_contents($log_file), -2000);
                $log = "Command: {$running}\nLog file: {$log_file}\nSize: {$current_size} bytes\n\n--- Recent output ---\n{$log_tail}";
            } else {
                // Log file doesn't exist yet, still starting
                if ($current_time - $last_check < 30) {
                    $is_running = true;
                    $log = "Command starting... waiting for log file.\nLog file: {$log_file}";
                }
            }
            
            if (!$is_running) {
                update_option(self::OPTION_COMMAND_STATUS, 'completed');
                update_option(self::OPTION_RUNNING_COMMAND, '');
                update_option('dh_cli_command_pid', 0);
                $status = 'completed';
                
                if ($log_file && file_exists($log_file)) {
                    $log_content = file_get_contents($log_file);
                    $log = "Completed!\n\n" . substr($log_content, -3000);
                }
            }
        }

        wp_send_json_success(array(
            'running' => !empty($running),
            'command' => $running,
            'status' => $status,
            'log' => $log,
        ));
    }

    /**
     * AJAX: Stop running command
     */
    public function ajax_stop_command() {
        check_ajax_referer('dh_cli_runner', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permission denied'));
        }

        // Kill any running WP-CLI directory-helpers processes
        exec("pkill -f 'wp directory-helpers'");

        update_option(self::OPTION_RUNNING_COMMAND, '');
        update_option(self::OPTION_COMMAND_STATUS, 'stopped');

        wp_send_json_success(array('message' => 'Command stopped'));
    }

    /**
     * Render action buttons for area term edit page
     */
    public function render_area_term_actions($term, $taxonomy) {
        // Only render once per page load
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
     * Render action buttons for state term edit page
     */
    public function render_state_term_actions($term, $taxonomy) {
        // Only render once per page load
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

    /**
     * Get HTML for admin page CLI runner section
     */
    public static function get_admin_section_html() {
        $running = get_option(self::OPTION_RUNNING_COMMAND, '');
        $status = get_option(self::OPTION_COMMAND_STATUS, 'idle');
        
        // Get all niche terms
        $niches = get_terms(array(
            'taxonomy' => 'niche',
            'hide_empty' => false,
            'orderby' => 'name',
        ));
        
        $default_niche = 'dog-trainer';
        
        ob_start();
        ?>
        <div class="directory-helpers-settings" style="margin-top: 20px;">
            <h2><?php esc_html_e('Run Commands', 'directory-helpers'); ?></h2>
            <p class="description"><?php esc_html_e('Run ranking and radius analysis commands directly from the admin. Commands run in the background.', 'directory-helpers'); ?></p>
            
            <div class="dh-cli-runner-panel" style="background: #fff; border: 1px solid #ccd0d4; border-radius: 4px; padding: 20px; margin: 15px 0;">
                
                <div class="dh-cli-status-box" style="margin-bottom: 20px; padding: 10px; background: #f9f9f9; border-radius: 4px;">
                    <strong><?php esc_html_e('Status:', 'directory-helpers'); ?></strong>
                    <span id="dh-cli-global-status">
                        <?php if (!empty($running)): ?>
                            <span style="color: #0073aa;">⏳ <?php echo esc_html($running); ?></span>
                        <?php else: ?>
                            <span style="color: #46b450;">✓ <?php esc_html_e('Ready', 'directory-helpers'); ?></span>
                        <?php endif; ?>
                    </span>
                    <?php if (!empty($running)): ?>
                        <button type="button" class="button button-small dh-cli-stop-btn" style="margin-left: 10px;">
                            <?php esc_html_e('Stop', 'directory-helpers'); ?>
                        </button>
                    <?php endif; ?>
                </div>
                
                <div id="dh-cli-log-box" style="display: none; margin-bottom: 20px; padding: 15px; background: #1e1e1e; border-radius: 4px; max-height: 300px; overflow-y: auto;">
                    <pre id="dh-cli-log-output" style="color: #d4d4d4; margin: 0; white-space: pre-wrap; font-size: 12px; font-family: monospace;"></pre>
                </div>

                <div style="margin-bottom: 20px; padding: 15px; background: #f0f8ff; border: 1px solid #b3d9ff; border-radius: 4px;">
                    <label for="dh-cli-niche-select" style="display: block; margin-bottom: 8px;">
                        <strong><?php esc_html_e('Select Niche:', 'directory-helpers'); ?></strong>
                    </label>
                    <select id="dh-cli-niche-select" style="min-width: 200px; padding: 5px;">
                        <?php if (!is_wp_error($niches) && !empty($niches)): ?>
                            <?php foreach ($niches as $niche): ?>
                                <option value="<?php echo esc_attr($niche->slug); ?>" <?php selected($niche->slug, $default_niche); ?>>
                                    <?php echo esc_html($niche->name); ?>
                                </option>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <option value="dog-trainer"><?php esc_html_e('Dog Trainer', 'directory-helpers'); ?></option>
                        <?php endif; ?>
                    </select>
                </div>

                <h3 style="margin-top: 0;"><?php esc_html_e('City Rankings', 'directory-helpers'); ?></h3>
                <p><?php esc_html_e('Update city_rank for all profiles. Takes ~12 minutes for 1000 cities.', 'directory-helpers'); ?></p>
                <div class="dh-cli-actions" style="margin-bottom: 20px;">
                    <button type="button" class="button button-primary dh-cli-run-btn" 
                            data-command-template="update-rankings {niche}">
                        <span class="dashicons dashicons-sort" style="margin-top: 4px;"></span>
                        <?php esc_html_e('Update City Rankings', 'directory-helpers'); ?>
                    </button>
                    <span class="dh-cli-status"></span>
                </div>

                <h3><?php esc_html_e('State Rankings', 'directory-helpers'); ?></h3>
                <p><?php esc_html_e('Update state_rank for all profiles. Takes ~80 seconds for all states (optimized bulk operations).', 'directory-helpers'); ?></p>
                <div class="dh-cli-actions" style="margin-bottom: 20px;">
                    <button type="button" class="button button-primary dh-cli-run-btn" 
                            data-command-template="update-state-rankings {niche}">
                        <span class="dashicons dashicons-sort" style="margin-top: 4px;"></span>
                        <?php esc_html_e('Update State Rankings', 'directory-helpers'); ?>
                    </button>
                    <span class="dh-cli-status"></span>
                </div>

                <h3><?php esc_html_e('Radius Analysis', 'directory-helpers'); ?></h3>
                <p><?php esc_html_e('Analyze and update recommended radius for all cities. Takes ~15-20 minutes.', 'directory-helpers'); ?></p>
                <div class="dh-cli-actions" style="margin-bottom: 20px;">
                    <button type="button" class="button button-primary dh-cli-run-btn" 
                            data-command-template="analyze-radius {niche} --update-meta">
                        <span class="dashicons dashicons-location-alt" style="margin-top: 4px;"></span>
                        <?php esc_html_e('Analyze Radius (All Cities)', 'directory-helpers'); ?>
                    </button>
                    <span class="dh-cli-status"></span>
                </div>

                <hr style="margin: 30px 0; border-top: 1px solid #ccd0d4;">

                <h3><?php esc_html_e('Cache Priming', 'directory-helpers'); ?></h3>
                <p><?php esc_html_e('Prime cache for key pages using bot User-Agent (excluded from analytics). Faster than full LiteSpeed crawler.', 'directory-helpers'); ?></p>
                
                <div class="dh-cli-actions" style="margin-bottom: 15px;">
                    <button type="button" class="button button-primary dh-cli-run-btn" 
                            data-command="prime-cache --preset=priority">
                        <span class="dashicons dashicons-performance" style="margin-top: 4px;"></span>
                        <?php esc_html_e('Prime Priority Pages', 'directory-helpers'); ?>
                    </button>
                    <span class="dh-cli-status"></span>
                    <span class="description" style="margin-left: 10px;"><?php esc_html_e('Pages, States, Certifications', 'directory-helpers'); ?></span>
                </div>

                <div class="dh-cli-actions" style="margin-bottom: 15px;">
                    <button type="button" class="button dh-cli-run-btn" 
                            data-command="prime-cache --preset=listings">
                        <span class="dashicons dashicons-location" style="margin-top: 4px;"></span>
                        <?php esc_html_e('Prime Listing Pages', 'directory-helpers'); ?>
                    </button>
                    <span class="dh-cli-status"></span>
                    <span class="description" style="margin-left: 10px;"><?php esc_html_e('City & State listings', 'directory-helpers'); ?></span>
                </div>

                <div class="dh-cli-actions" style="margin-bottom: 20px;">
                    <button type="button" class="button dh-cli-run-btn" 
                            data-command="prime-cache --preset=profiles">
                        <span class="dashicons dashicons-businessman" style="margin-top: 4px;"></span>
                        <?php esc_html_e('Prime Profile Pages', 'directory-helpers'); ?>
                    </button>
                    <span class="dh-cli-status"></span>
                    <span class="description" style="margin-left: 10px;"><?php esc_html_e('All profiles (may take a while)', 'directory-helpers'); ?></span>
                </div>

                <p class="description">
                    <strong><?php esc_html_e('CLI Usage:', 'directory-helpers'); ?></strong><br>
                    <code>wp directory-helpers prime-cache page-sitemap.xml state-listing-sitemap.xml</code><br>
                    <code>wp directory-helpers prime-cache --preset=priority --delay=200</code>
                </p>

            </div>
        </div>
        <?php
        return ob_get_clean();
    }
}

// Initialize
new DH_Admin_CLI_Runner();
