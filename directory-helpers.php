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
        
        // Load modules
        $this->load_modules();
        
        // Initialize active modules
        $this->init_active_modules();
    }

    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_menu_page(
            __('Directory Helpers', 'directory-helpers'),
            __('Directory Helpers', 'directory-helpers'),
            'manage_options',
            'directory-helpers',
            array($this, 'render_admin_page'),
            'dashicons-networking',
            30
        );
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
        
        // Save settings if form is submitted
        if (isset($_POST['directory_helpers_save_settings']) && check_admin_referer('directory_helpers_save_settings', 'directory_helpers_nonce')) {
            $this->save_settings();
        }
        
        // Get current settings
        $options = get_option('directory_helpers_options', array());
        $active_modules = isset($options['active_modules']) ? $options['active_modules'] : array();
        
        // Include admin view
        include DIRECTORY_HELPERS_PATH . 'views/admin-page.php';
    }

    /**
     * Save plugin settings
     */
    private function save_settings() {
        $options = get_option('directory_helpers_options', array());
        
        // Update active modules
        $active_modules = isset($_POST['active_modules']) ? (array) $_POST['active_modules'] : array();
        $options['active_modules'] = array_map('sanitize_text_field', $active_modules);
        
        // Save options
        update_option('directory_helpers_options', $options);
        
        // Reinitialize active modules
        $this->active_modules = $options['active_modules'];
        $this->init_active_modules();
        
        // Add success message
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
            )
            // Add more modules here as needed
        );
        
        // Get active modules from options
        $options = get_option('directory_helpers_options', array());
        $this->active_modules = isset($options['active_modules']) ? $options['active_modules'] : array();
    }

    /**
     * Initialize active modules
     */
    private function init_active_modules() {
        foreach ($this->active_modules as $module_id) {
            if (isset($this->modules[$module_id]) && file_exists($this->modules[$module_id]['file'])) {
                require_once $this->modules[$module_id]['file'];
                
                $class_name = $this->modules[$module_id]['class'];
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
