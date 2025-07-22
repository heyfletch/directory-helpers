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
    }

    /**
     * Plugin activation
     */
    public function activate() {
        // Set default options
        $default_options = array(
            'active_modules' => array()
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
        
        // Load the City Listing Generator module.
        require_once plugin_dir_path( __FILE__ ) . 'modules/city-listing-generator/city-listing-generator.php';

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
        WP_CLI::add_command( 'directory-helpers deduplicate_area_terms', 'DH_Deduplicate_Area_Terms_Command' );
        WP_CLI::add_command( 'directory-helpers update_area_term_format', 'DH_Deduplicate_Area_Terms_Command' );
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
            $options['shared_secret_key'] = isset($submitted_options['shared_secret_key']) ? sanitize_text_field($submitted_options['shared_secret_key']) : '';
        }

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
}

// Initialize the plugin
Directory_Helpers::get_instance();
