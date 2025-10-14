<?php
/**
 * Plugin Name: Directory Helpers
 * Plugin URI: 
 * Description: A modular plugin for directory-related functionality
 * Version: 1.0.0
 * Author: 
 * Author URI: 
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: directory-helpers
 * Domain Path: /languages
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('DIRECTORY_HELPERS_VERSION', '1.0.0');
define('DIRECTORY_HELPERS_PATH', plugin_dir_path(__FILE__));
define('DIRECTORY_HELPERS_URL', plugin_dir_url(__FILE__));
define('DIRECTORY_HELPERS_BASENAME', plugin_basename(__FILE__));

/**
 * Main plugin class
 */
class Directory_Helpers {
    /**
     * Instance of this class
     *
     * @var Directory_Helpers
     */
    private static $instance;

    /**
     * Available modules
     *
     * @var array
     */
    private $modules = [];

    /**
     * Active modules
     *
     * @var array
     */
    private $active_modules = [];

    /**
     * Get instance of this class
     *
     * @return Directory_Helpers
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        // Register activation and deactivation hooks
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
        
        // Initialize the plugin on init hook (not plugins_loaded)
        add_action('init', array($this, 'init'), 0);
        
        // Setup admin hooks separately
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        // Frontend assets
        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_assets'));

        // Make prompts available on post edit screens (Classic and Block editor)
        add_action('admin_head-post.php', array($this, 'output_prompts_js'));
        add_action('admin_head-post-new.php', array($this, 'output_prompts_js'));

        // Add Classic Editor content show/hide toggle on post edit screens
        add_action('admin_head-post.php', array($this, 'output_classic_editor_toggle'));
        add_action('admin_head-post-new.php', array($this, 'output_classic_editor_toggle'));

        // Enqueue TinyMCE shortcode highlighter (Classic editor) on post edit screens
        add_action('admin_enqueue_scripts', array($this, 'enqueue_editor_shortcodes_assets'));

        // Register Prompts display meta box on edit screens (targets configured on admin page)
        add_action('add_meta_boxes', array($this, 'register_prompts_display_meta_box'));
    }

    /**
     * Plugin activation
     */
    public function activate() {
        // Set default options
        $default_options = array(
            'active_modules' => array(),
            'prompts' => array()
        );
        
        if (!get_option('directory_helpers_options')) {
            add_option('directory_helpers_options', $default_options);
        }
    }

    /**
     * Plugin deactivation
     */
    public function deactivate() {
        // Cleanup if needed
    }

    /**
     * Initialize the plugin
     */
    public function init() {
        // Load text domain for internationalization directly on init
        load_plugin_textdomain('directory-helpers', false, dirname(plugin_basename(__FILE__)) . '/languages');

        // Load modules
        $this->load_modules();
        
        // Initialize active modules
        $this->init_active_modules();

        // Register WP-CLI commands
        if ( defined( 'WP_CLI' ) && WP_CLI ) {
            $this->register_cli_commands();
        }
    }

    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        $hook = add_menu_page(
            __('Directory Helpers', 'directory-helpers'),
            __('Directory Helpers', 'directory-helpers'),
            'manage_options',
            'directory-helpers',
            array($this, 'render_admin_page'),
            'dashicons-networking',
            30
        );

        add_action("load-{$hook}", array($this, 'handle_settings_save'));
    }

    /**
     * Enqueue admin assets
     */
    public function enqueue_admin_assets($hook) {
        // Only load on plugin pages
        if (strpos($hook, 'directory-helpers') === false) {
            return;
        }
        
        // Add admin CSS
        wp_enqueue_style(
            'directory-helpers-admin',
            DIRECTORY_HELPERS_URL . 'assets/css/admin.css',
            array(),
            DIRECTORY_HELPERS_VERSION
        );
        
        // Add admin JS
        wp_enqueue_script(
            'directory-helpers-admin',
            DIRECTORY_HELPERS_URL . 'assets/js/admin.js',
            array('jquery'),
            DIRECTORY_HELPERS_VERSION,
            true
        );
    }

    /**
     * Enqueue frontend assets (shared styles for shortcodes/modules)
     */
    public function enqueue_frontend_assets() {
        $rel = 'assets/css/frontend.css';
        $path = DIRECTORY_HELPERS_PATH . $rel;
        if (file_exists($path)) {
            $ver = (string) @filemtime($path);
            wp_enqueue_style(
                'directory-helpers-frontend',
                DIRECTORY_HELPERS_URL . $rel,
                array(),
                $ver
            );
        }
    }

    /**
     * Render admin page
     */
    public function render_admin_page() {
        // Check user capabilities
        if (!current_user_can('manage_options')) {
            return;
        }
        
        // Include admin view
        include DIRECTORY_HELPERS_PATH . 'views/admin-page.php';
    }

    /**
     * Handle settings save
     */
    public function handle_settings_save() {
        if (isset($_POST['submit']) && isset($_POST['directory_helpers_nonce']) && wp_verify_nonce($_POST['directory_helpers_nonce'], 'directory_helpers_save_settings')) {
            $this->save_settings();
        }
    }

    /**
     * Register WP-CLI commands.
     */
    public function register_cli_commands() {
        require_once DIRECTORY_HELPERS_PATH . 'includes/cli/class-deduplicate-area-terms-command.php';
        require_once DIRECTORY_HELPERS_PATH . 'includes/cli/class-geocode-area-terms-command.php';
        WP_CLI::add_command( 'directory-helpers deduplicate_area_terms', 'DH_Deduplicate_Area_Terms_Command' );
        WP_CLI::add_command( 'directory-helpers update_area_term_format', 'DH_Deduplicate_Area_Terms_Command' );
        WP_CLI::add_command( 'directory-helpers update_state_listing_titles', 'DH_Deduplicate_Area_Terms_Command' );
    }

    /**
     * Output prompts as a JS global on post edit screens
     */
    public function output_prompts_js() {
        if (!current_user_can('edit_posts')) {
            return;
        }
        $prompts = self::get_prompts();
        if (!is_array($prompts)) {
            $prompts = array();
        }
        echo '<script type="text/javascript">window.DH_PROMPTS = ' . wp_json_encode($prompts) . ';</script>';
    }

    /**
     * Output a toggle to show/hide the main post content section for Classic Editor.
     */
    public function output_classic_editor_toggle() {
        if (!current_user_can('edit_posts')) {
            return;
        }
        ?>
        <style>
            .dh-toggle-content-wrap { margin: 8px 0 12px; }
            .dh-toggle-content-wrap .button { vertical-align: middle; }
        </style>
        <script type="text/javascript">
        (function(){
            function ready(fn){ if(document.readyState!='loading'){ fn(); } else { document.addEventListener('DOMContentLoaded', fn); } }
            ready(function(){
                var editorDiv = document.getElementById('postdivrich') || document.getElementById('postdiv');
                if(!editorDiv){ return; }
                var titleDiv = document.getElementById('titlediv');
                var wrap = document.createElement('div');
                wrap.className = 'dh-toggle-content-wrap';
                var btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'button button-secondary';
                var postTypeInput = document.getElementById('post_type');
                var postType = postTypeInput && postTypeInput.value ? postTypeInput.value : 'post';
                var storageKey = 'dh_hide_content_' + postType;
                function isHidden(){ return window.localStorage.getItem(storageKey) === '1'; }
                function apply(initial){
                    var hidden = isHidden();
                    if (initial === true) {
                        hidden = false; // always show on initial load to allow TinyMCE to layout correctly
                    }
                    editorDiv.style.display = hidden ? 'none' : 'block';
                    btn.textContent = hidden ? 'Show Content Editor' : 'Hide Content Editor';
                }
                btn.addEventListener('click', function(){
                    var hidden = isHidden();
                    window.localStorage.setItem(storageKey, hidden ? '0' : '1');
                    apply();
                });
                apply(true); // force visible on first paint
                if (isHidden()) {
                    var doHide = function(){ apply(); };
                    // Hide after TinyMCE is fully initialized to prevent broken toolbar icons
                    if (window.jQuery && jQuery(document).one) {
                        jQuery(document).one('tinymce-editor-init', function(e, ed){
                            // Default editor id is 'content'; if ed is missing, still proceed
                            if (!ed || ed.id === 'content') {
                                setTimeout(doHide, 0);
                            }
                        });
                    }
                    // Fallback: hide after a short delay in case the init event didn't fire
                    setTimeout(doHide, 2000);
                }
                wrap.appendChild(btn);
                if(titleDiv && titleDiv.parentNode){
                    titleDiv.parentNode.insertBefore(wrap, titleDiv.nextSibling);
                } else {
                    var postBodyContent = document.getElementById('post-body-content');
                    if(postBodyContent){ postBodyContent.insertBefore(wrap, postBodyContent.firstChild); }
                }
            });
        })();
        </script>
        <?php
    }

    /**
     * Enqueue TinyMCE shortcode highlighter script and inject editor CSS for post edit screens.
     */
    public function enqueue_editor_shortcodes_assets($hook) {
        // Only run on post edit screens (Classic editor)
        if ($hook !== 'post.php' && $hook !== 'post-new.php') { return; }
        if (!current_user_can('edit_posts')) { return; }

        // Enqueue the highlighter script (listens for tinymce-editor-init)
        $sc_js_rel = 'assets/js/editor-shortcode-highlighter.js';
        $sc_js_path = DIRECTORY_HELPERS_PATH . $sc_js_rel;
        $sc_js_ver = file_exists($sc_js_path) ? (string) @filemtime($sc_js_path) : DIRECTORY_HELPERS_VERSION;
        wp_enqueue_script(
            'dh-editor-shortcode-highlighter',
            DIRECTORY_HELPERS_URL . $sc_js_rel,
            array('jquery'),
            $sc_js_ver,
            true
        );

        // Ensure our editor CSS is loaded inside TinyMCE iframe
        add_filter('mce_css', function($mce_css) {
            $css_rel = 'assets/css/editor-shortcodes.css';
            $css_path = DIRECTORY_HELPERS_PATH . $css_rel;
            $ver = file_exists($css_path) ? (string) @filemtime($css_path) : DIRECTORY_HELPERS_VERSION;
            $css_url = DIRECTORY_HELPERS_URL . $css_rel . '?ver=' . rawurlencode($ver);
            if (strpos((string)$mce_css, $css_url) === false) {
                $mce_css = $mce_css ? ($mce_css . ',' . $css_url) : $css_url;
            }
            return $mce_css;
        });
    }

    /**
     * Save plugin settings
     */
    public function save_settings() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $options = get_option('directory_helpers_options', []);
        if (!is_array($options)) {
            $options = [];
        }

        // Handle AI Content Generator settings
        if (isset($_POST['directory_helpers_options']) && is_array($_POST['directory_helpers_options'])) {
            $submitted_options = $_POST['directory_helpers_options'];
            $options['n8n_webhook_url'] = isset($submitted_options['n8n_webhook_url']) ? esc_url_raw($submitted_options['n8n_webhook_url']) : '';
            $options['notebook_webhook_url'] = isset($submitted_options['notebook_webhook_url']) ? esc_url_raw($submitted_options['notebook_webhook_url']) : '';
            $options['shared_secret_key'] = isset($submitted_options['shared_secret_key']) ? sanitize_text_field($submitted_options['shared_secret_key']) : '';
            $options['video_queue_max_retries'] = isset($submitted_options['video_queue_max_retries']) ? absint($submitted_options['video_queue_max_retries']) : 0;
            // DataForSEO credentials (Basic Auth)
            $options['dataforseo_login'] = isset($submitted_options['dataforseo_login']) ? sanitize_text_field($submitted_options['dataforseo_login']) : '';
            $options['dataforseo_password'] = isset($submitted_options['dataforseo_password']) ? sanitize_text_field($submitted_options['dataforseo_password']) : '';

            // Handle Instant Search defaults (placeholder and labels)
            $options['instant_search_placeholder'] = isset($submitted_options['instant_search_placeholder']) ? sanitize_text_field($submitted_options['instant_search_placeholder']) : '';
            $options['instant_search_label_c'] = isset($submitted_options['instant_search_label_c']) ? sanitize_text_field($submitted_options['instant_search_label_c']) : '';
            $options['instant_search_label_p'] = isset($submitted_options['instant_search_label_p']) ? sanitize_text_field($submitted_options['instant_search_label_p']) : '';
            $options['instant_search_label_s'] = isset($submitted_options['instant_search_label_s']) ? sanitize_text_field($submitted_options['instant_search_label_s']) : '';
            // Handle Instant Search ZIP minimum digits
            if (isset($submitted_options['instant_search_zip_min_digits'])) {
                $min = (int) $submitted_options['instant_search_zip_min_digits'];
                if ($min < 1) { $min = 1; }
                if ($min > 5) { $min = 5; }
                $options['instant_search_zip_min_digits'] = $min;
            }
        }

        // Handle AI Prompts and their post type targets
        // Build prompts array and keep an index => key mapping to join with targets checkboxes
        $prompts = array();
        $idx_to_key = array();
        if (isset($_POST['directory_helpers_prompts']) && is_array($_POST['directory_helpers_prompts'])) {
            foreach ($_POST['directory_helpers_prompts'] as $i => $row) {
                $key = isset($row['key']) ? sanitize_key($row['key']) : '';
                $value_raw = isset($row['value']) ? wp_unslash((string) $row['value']) : '';
                $value = sanitize_textarea_field($value_raw); // preserves multi-line text
                if ($value !== '') {
                    // Normalize apostrophes to curly to prevent escaping issues
                    $value = str_replace("'", "’", $value);
                }
                if ($key !== '' && $value !== '') {
                    $prompts[$key] = $value;
                    $idx_to_key[(string)$i] = $key;
                }
            }
        }
        $options['prompts'] = $prompts;

        // Map posted targets (by row index) to sanitized prompt keys
        $prompt_targets = array();
        if (!empty($idx_to_key) && isset($_POST['directory_helpers_prompt_targets']) && is_array($_POST['directory_helpers_prompt_targets'])) {
            $allowed_pts = get_post_types(array('show_ui' => true), 'names');
            $exclude = array('attachment','revision','nav_menu_item','custom_css','customize_changeset','oembed_cache','user_request','wp_block','wp_navigation','wp_template','wp_template_part');
            $allowed_pts = array_values(array_diff($allowed_pts, $exclude));
            foreach ($_POST['directory_helpers_prompt_targets'] as $i => $pts) {
                $i = (string) $i;
                if (!isset($idx_to_key[$i])) { continue; }
                $k = $idx_to_key[$i];
                if (!is_array($pts)) { continue; }
                $clean = array();
                foreach ($pts as $pt) {
                    $pt = sanitize_key($pt);
                    if (in_array($pt, $allowed_pts, true)) {
                        $clean[] = $pt;
                    }
                }
                $prompt_targets[$k] = array_values(array_unique($clean));
            }
        }
        $options['prompt_targets'] = $prompt_targets;

        // Handle active modules
        // This part is not currently used for activation/deactivation from the UI, but we keep it for future use.
        if (isset($_POST['active_modules']) && is_array($_POST['active_modules'])) {
            $options['active_modules'] = array_map('sanitize_text_field', $_POST['active_modules']);
        } else {
            // Ensure 'active_modules' is an array, even if not submitted.
            if (!isset($options['active_modules']) || !is_array($options['active_modules'])) {
                $options['active_modules'] = [];
            }
        }

        update_option('directory_helpers_options', $options);

        // Reinitialize active modules
        $this->active_modules = $options['active_modules'];
        $this->init_active_modules();

        add_settings_error(
            'directory_helpers_messages',
            'directory_helpers_message',
            __('Settings saved.', 'directory-helpers'),
            'updated'
        );
    }

    /**
     * Helper: get prompt targets mapping from options
     *
     * @return array [prompt_key => string[] post_types]
     */
    private function get_prompt_targets() {
        $options = get_option('directory_helpers_options', array());
        $targets = isset($options['prompt_targets']) && is_array($options['prompt_targets']) ? $options['prompt_targets'] : array();
        foreach ($targets as $k => $arr) {
            if (!is_array($arr)) { $targets[$k] = array(); continue; }
            $targets[$k] = array_values(array_filter(array_map('sanitize_key', $arr)));
        }
        return $targets;
    }

    /**
     * Derive US state full name for a city-listing post by parsing slug or title for a 2-letter code.
     * Returns empty string if not determinable.
     *
     * @param WP_Post|int $post
     * @return string
     */
    private function derive_state_name_for_city_post($post) {
        $post = is_object($post) ? $post : get_post($post);
        if (!$post || $post->post_type !== 'city-listing') { return ''; }
        $code = '';
        $slug = isset($post->post_name) ? (string) $post->post_name : '';
        if ($slug && preg_match('/-([a-z]{2})(?:-|$)/i', $slug, $m)) {
            $code = strtoupper($m[1]);
        } else {
            $title = (string) $post->post_title;
            if ($title && preg_match('/,\s*([A-Za-z]{2})(\b|$)/', $title, $mm)) {
                $code = strtoupper($mm[1]);
            }
        }
        if (!$code) { return ''; }
        $us = array(
            'AL'=>'Alabama','AK'=>'Alaska','AZ'=>'Arizona','AR'=>'Arkansas','CA'=>'California','CO'=>'Colorado','CT'=>'Connecticut','DE'=>'Delaware','FL'=>'Florida','GA'=>'Georgia','HI'=>'Hawaii','ID'=>'Idaho','IL'=>'Illinois','IN'=>'Indiana','IA'=>'Iowa','KS'=>'Kansas','KY'=>'Kentucky','LA'=>'Louisiana','ME'=>'Maine','MD'=>'Maryland','MA'=>'Massachusetts','MI'=>'Michigan','MN'=>'Minnesota','MS'=>'Mississippi','MO'=>'Missouri','MT'=>'Montana','NE'=>'Nebraska','NV'=>'Nevada','NH'=>'New Hampshire','NJ'=>'New Jersey','NM'=>'New Mexico','NY'=>'New York','NC'=>'North Carolina','ND'=>'North Dakota','OH'=>'Ohio','OK'=>'Oklahoma','OR'=>'Oregon','PA'=>'Pennsylvania','RI'=>'Rhode Island','SC'=>'South Carolina','SD'=>'South Dakota','TN'=>'Tennessee','TX'=>'Texas','UT'=>'Utah','VT'=>'Vermont','VA'=>'Virginia','WA'=>'Washington','WV'=>'West Virginia','WI'=>'Wisconsin','WY'=>'Wyoming'
        );
        return isset($us[$code]) ? $us[$code] : '';
    }

    /**
     * Register Prompts display meta box across post types; it only displays prompts targeted to the current post type.
     */
    public function register_prompts_display_meta_box() {
        if (!current_user_can('edit_posts')) { return; }
        $post_types = get_post_types(array('show_ui' => true), 'names');
        $exclude = array('attachment','revision','nav_menu_item','custom_css','customize_changeset','oembed_cache','user_request','wp_block','wp_navigation','wp_template','wp_template_part');
        foreach ($post_types as $pt) {
            if (in_array($pt, $exclude, true)) { continue; }
            add_meta_box(
                'dh-prompts',
                __('Prompts', 'directory-helpers'),
                array($this, 'render_prompts_display_meta_box'),
                $pt,
                'side',
                'default'
            );
        }
    }

    /**
     * Render the display-only Prompts meta box on edit screens.
     */
    public function render_prompts_display_meta_box($post) {
        $prompts = self::get_prompts();
        if (empty($prompts)) {
            echo '<p>' . esc_html__('No prompts configured.', 'directory-helpers') . '</p>';
            return;
        }
        $targets = $this->get_prompt_targets();
        $current_pt = get_post_type($post);

        $shown = 0;
        foreach ($prompts as $key => $text) {
            $san_key = sanitize_key($key);
            $selected_pts = isset($targets[$san_key]) ? (array)$targets[$san_key] : array();
            if (!in_array($current_pt, $selected_pts, true)) { continue; }
            $shown++;
            // Build replacement map for tokens
            $post_title = get_the_title($post);
            $replacements = array(
                '{title}' => (string) $post_title,
            );
            // Back-compat for older token name
            $replacements['{city-state}'] = (string) $post_title;

            // Shortlink replacement (if Shortlinks module created one)
            $slug_meta_key = class_exists('DH_Shortlinks') ? DH_Shortlinks::META_SLUG : '_dh_shortlink_slug';
            $short_slug = (string) get_post_meta($post->ID, $slug_meta_key, true);
            $shortlink = $short_slug ? home_url('/' . ltrim($short_slug, '/')) : '';
            $replacements['{shortlink}'] = $shortlink;

            // Taxonomy tokens: replace {taxonomy} with the assigned term names (comma-separated)
            $tax_objs = get_object_taxonomies($current_pt, 'objects');
            if (is_array($tax_objs)) {
                foreach ($tax_objs as $tax_name => $tax_obj) {
                    $terms = get_the_terms($post->ID, $tax_name);
                    $val = '';
                    if (is_array($terms) && !empty($terms)) {
                        $names = wp_list_pluck($terms, 'name');
                        $val = implode(', ', array_map('wp_strip_all_tags', array_map('strval', $names)));
                    }
                    $replacements['{' . $tax_name . '}'] = $val;
                }
            }

            // Special fallback for {state} on city-listing posts when taxonomy missing/empty
            if (strpos($text, '{state}') !== false) {
                $current_state_val = isset($replacements['{state}']) ? (string) $replacements['{state}'] : '';
                if ($current_state_val === '') {
                    $derived = $this->derive_state_name_for_city_post($post);
                    if ($derived !== '') {
                        $replacements['{state}'] = $derived;
                    } else {
                        // Ensure we explicitly blank it if undeterminable
                        $replacements['{state}'] = '';
                    }
                }
            }

            // Ensure all tokens present in the text are defined in the replacements map; default to blank
            if (preg_match_all('/\{[a-z0-9_-]+\}/i', (string) $text, $m)) {
                foreach (array_unique($m[0]) as $tok) {
                    if (!array_key_exists($tok, $replacements)) {
                        $replacements[$tok] = '';
                    }
                }
            }

            // Apply replacements and normalize apostrophes for clean display/copy
            $display_text = strtr($text, $replacements);
            $display_text = str_replace("'", "’", $display_text);
            echo '<div class="dh-prompt-wrap" style="margin-bottom:12px;">';
            echo '<div class="dh-prompt-header" style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px;">';
            echo '<strong>' . esc_html($key) . '</strong>';
            echo '<button type="button" class="button button-secondary dh-copy-prompt" data-target="' . esc_attr($san_key) . '" style="padding:0 8px;height:24px;line-height:22px;">' . esc_html__('Copy', 'directory-helpers') . '</button>';
            echo '</div>';
            // Important: give the text an element id equal to the prompt key for easy targeting via #key
            echo '<textarea readonly id="' . esc_attr($san_key) . '" class="widefat code dh-prompt-text" rows="5" data-prompt-key="' . esc_attr($san_key) . '" style="min-height:100px;">' . esc_textarea($display_text) . '</textarea>';
            echo '</div>';
        }
        if ($shown === 0) {
            echo '<p style="color:#666;">' . esc_html__('No prompts targeted to this post type.', 'directory-helpers') . '</p>';
        }
        // Inline copy-to-clipboard handler (loaded once per page)
        ?>
        <script type="text/javascript">
        (function(){
            if (window.__dhCopyPromptInit) { return; }
            window.__dhCopyPromptInit = true;
            document.addEventListener('click', function(e){
                var btn = e.target && e.target.closest && e.target.closest('.dh-copy-prompt');
                if (!btn) { return; }
                e.preventDefault();
                var id = btn.getAttribute('data-target');
                var ta = id ? document.getElementById(id) : null;
                if (!ta) { return; }
                var text = ta.value;
                function done(){
                    var orig = btn.textContent;
                    btn.textContent = 'Copied!';
                    btn.disabled = true;
                    setTimeout(function(){ btn.textContent = orig; btn.disabled = false; }, 1200);
                }
                function fallback(){
                    try {
                        ta.focus();
                        ta.select();
                        ta.setSelectionRange(0, ta.value.length);
                        document.execCommand('copy');
                    } catch (err) { /* ignore */ }
                    try { window.getSelection().removeAllRanges(); } catch (e) { /* ignore */ }
                    ta.blur();
                    done();
                }
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(text).then(done, fallback);
                } else {
                    fallback();
                }
            }, false);
        })();
        </script>
        <?php
    }

    /**
     * Load available modules
     */
    private function load_modules() {
        // Define available modules
        $this->modules = array(
            'breadcrumbs' => array(
                'name' => __('Breadcrumbs', 'directory-helpers'),
                'description' => __('Adds breadcrumb navigation for custom post types and taxonomies. Example: [dh_breadcrumbs home_text="Dog Trainers" home_separator=" in " show_niche="false" show_city="false" show_state="false" show_home="true" separator=" > "]', 'directory-helpers'),
                'file' => DIRECTORY_HELPERS_PATH . 'modules/breadcrumbs/breadcrumbs.php',
                'class' => 'DH_Breadcrumbs'
            ),
            'video-overview' => array(
                'name' => __('Video Overview', 'directory-helpers'),
                'description' => __('Shortcode to embed a YouTube video from the ACF field "video_overview" on the current post. Use [dh_video_overview].', 'directory-helpers'),
                'file' => DIRECTORY_HELPERS_PATH . 'modules/video-overview/video-overview.php',
                'class' => 'DH_Video_Overview'
            ),
            'profile-rankings' => array(
                'name' => __('Profile Rankings', 'directory-helpers'),
                'description' => __('Rankings for profiles based on ratings and review counts within respective cities and states. Use shortcodes [dh_city_rank] and [dh_state_rank]. [dh_state_rank show_ranking_data="true"] to show rating and review count used. [dh_state_rank show_prefix="false"] to remove "Ranked " prefix. ', 'directory-helpers'),
                'file' => DIRECTORY_HELPERS_PATH . 'modules/profile-rankings/profile-rankings.php',
                'class' => 'DH_Profile_Rankings'
            ),
            'profile-structured-data' => array(
                'name' => __('Profile Structured Data', 'directory-helpers'),
                'description' => __('Generates structured data for profile pages including LocalBusiness, ProfilePage, and Service List schemas. Automatically adds structured data to profile pages.', 'directory-helpers'),
                'file' => DIRECTORY_HELPERS_PATH . 'modules/profile-structured-data/profile-structured-data.php',
                'class' => 'DH_Profile_Structured_Data'
            ),
            'ai-content-generator' => array(
                'name' => __('AI Content Generator', 'directory-helpers'),
                'description' => __('Triggers an n8n workflow to generate AI content for posts.', 'directory-helpers'),
                'file' => DIRECTORY_HELPERS_PATH . 'modules/ai-content-generator/ai-content-generator.php',
                'class' => 'DH_AI_Content_Generator'
            ),
            'external-link-management' => array(
                'name' => __('External Link Management', 'directory-helpers'),
                'description' => __('Scans AI-generated content for external links, converts to shortcodes, stores metadata, and checks link status.', 'directory-helpers'),
                'file' => DIRECTORY_HELPERS_PATH . 'modules/external-link-management/external-link-management.php',
                'class' => 'DH_External_Link_Management'
            ),
            'prep-profiles-by-state' => array(
                'name' => __('Prep Profiles by State', 'directory-helpers'),
                'description' => __('Admin tools to prepare and publish profiles by state, list duplicate-city profiles, and trigger re-ranking.', 'directory-helpers'),
                'file' => DIRECTORY_HELPERS_PATH . 'modules/prep-profiles-by-state/prep-profiles-by-state.php',
                'class' => 'DH_Prep_Profiles_By_State'
            ),
            'instant-search' => array(
                'name' => __('Instant Search', 'directory-helpers'),
                'description' => __('Client-side instant search for titles of selected post types with typeahead dropdown and ARIA support. Shortcode: [dh_instant_search].', 'directory-helpers'),
                'file' => DIRECTORY_HELPERS_PATH . 'modules/instant-search/instant-search.php',
                'class' => 'DH_Instant_Search'
            ),
            'shortlinks' => array(
                'name' => __('Shortlinks', 'directory-helpers'),
                'description' => __('Creates one-time shortlinks for city/state listings and repairs malformed Rank Math redirection sources safely.', 'directory-helpers'),
                'file' => DIRECTORY_HELPERS_PATH . 'modules/shortlinks/shortlinks.php',
                'class' => 'DH_Shortlinks'
            )
            ,
            'cache-integration' => array(
                'name' => __('Cache Integration', 'directory-helpers'),
                'description' => __('On first publish of a city, purges the related state-listing page from LiteSpeed Cache.', 'directory-helpers'),
                'file' => DIRECTORY_HELPERS_PATH . 'modules/cache-integration/cache-integration.php',
                'class' => 'DH_LSCache_Integration'
            ),
            'nearest-cities' => array(
                'name' => __('Nearest Cities', 'directory-helpers'),
                'description' => __('Shortcode to list the 5 closest city pages based on area lat/lng. Use [dh_nearest_cities limit="5"].', 'directory-helpers'),
                'file' => DIRECTORY_HELPERS_PATH . 'modules/nearest-cities/nearest-cities.php',
                'class' => 'DH_Nearest_Cities'
            ),
            'bing-webmaster-tools' => array(
                'name' => __('Bing Webmaster Tools', 'directory-helpers'),
                'description' => __('Adds buttons to post edit screens that open Bing Webmaster Tools URL inspection in a new tab.', 'directory-helpers'),
                'file' => DIRECTORY_HELPERS_PATH . 'modules/bing-webmaster-tools/bing-webmaster-tools.php',
                'class' => 'DH_Bing_Webmaster_Tools'
            ),
            'custom-post-statuses' => array(
                'name' => __('Custom Post Statuses', 'directory-helpers'),
                'description' => __('Registers custom post statuses including "refining" with full admin interface support.', 'directory-helpers'),
                'file' => DIRECTORY_HELPERS_PATH . 'modules/custom-post-statuses/custom-post-statuses.php',
                'class' => 'DH_Custom_Post_Statuses'
            ),
            'admin-webhook-trigger' => array(
                'name' => __('Admin Webhook Trigger', 'directory-helpers'),
                'description' => __('Adds a "Notebook" column and row action to city-listing and state-listing admin pages to trigger the Notebook webhook for individual posts.', 'directory-helpers'),
                'file' => DIRECTORY_HELPERS_PATH . 'modules/admin-webhook-trigger/admin-webhook-trigger.php',
                'class' => 'DH_Admin_Webhook_Trigger'
            ),
            'video-production-queue' => array(
                'name' => __('Video Production Queue', 'directory-helpers'),
                'description' => __('Automated video production queue for city and state listings. Manages sequential video creation via Zero Work webhook with auto-continuation.', 'directory-helpers'),
                'file' => DIRECTORY_HELPERS_PATH . 'modules/video-production-queue/video-production-queue.php',
                'class' => 'DH_Video_Production_Queue'
            ),
            'content-production-queue' => array(
                'name' => __('Content Production Queue', 'directory-helpers'),
                'description' => __('Automated content publishing queue for city and state listings. Publishes draft posts that meet all content requirements (images, link health) sequentially.', 'directory-helpers'),
                'file' => DIRECTORY_HELPERS_PATH . 'modules/content-production-queue/content-production-queue.php',
                'class' => 'DH_Content_Production_Queue'
            )
            // Add more modules here as needed
        );
        
        // Get active modules from options
        $options = get_option('directory_helpers_options', array());
        $this->active_modules = isset($options['active_modules']) ? $options['active_modules'] : array();
    }

    /**
     * Initialize all available modules
     */
    private function init_active_modules() {
        // Initialize all available modules
        foreach ($this->modules as $module_id => $module) {
            if (file_exists($module['file'])) {
                require_once $module['file'];
                
                $class_name = $module['class'];
                if (class_exists($class_name)) {
                    new $class_name();
                }
            }
        }
    }

    /**
     * Get available modules
     *
     * @return array
     */
    public function get_modules() {
        return $this->modules;
    }

    /**
     * Get active modules
     *
     * @return array
     */
    public function get_active_modules() {
        return $this->active_modules;
    }

    /**
     * Get all saved prompts from options.
     *
     * @return array key => prompt text
     */
    public static function get_prompts() {
        $options = get_option('directory_helpers_options', array());
        $prompts = (isset($options['prompts']) && is_array($options['prompts'])) ? $options['prompts'] : array();
        if (empty($prompts)) { return array(); }
        $clean = array();
        foreach ($prompts as $k => $v) {
            $t = is_string($v) ? wp_unslash($v) : '';
            if ($t !== '') {
                $t = str_replace("'", "’", $t);
            }
            $clean[$k] = $t;
        }
        return $clean;
    }

    /**
     * Get a single prompt by key.
     *
     * @param string $key
     * @param string $default
     * @return string
     */
    public static function get_prompt($key, $default = '') {
        $prompts = self::get_prompts();
        return isset($prompts[$key]) ? $prompts[$key] : $default;
    }
}

/**
 * Get all configured prompts.
 *
 * @return array key => prompt text
 */
function dh_get_prompts() {
    return Directory_Helpers::get_prompts();
}

/**
 * Get a single prompt by key.
 *
 * @param string $key
 * @param string $default
 * @return string
 */
function dh_get_prompt($key, $default = '') {
    return Directory_Helpers::get_prompt($key, $default);
}

// Static getters implemented on Directory_Helpers class for prompt access
if (class_exists('Directory_Helpers')) {
    if (!method_exists('Directory_Helpers', 'get_prompts')) {
        // Add static methods via runkit-like approach isn't available; instead, we provide
        // wrapper functions above and ensure core code uses them. For direct static access,
        // define the methods within the class in future refactors.
    }
}

// Initialize the plugin
Directory_Helpers::get_instance();
