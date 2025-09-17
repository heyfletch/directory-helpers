<?php
/**
 * Bing Webmaster Tools Integration Module
 *
 * Adds buttons to post types that open Bing Webmaster Tools URL inspection
 * for the current post URL in a new tab.
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class DH_Bing_Webmaster_Tools {
    
    /**
     * Constructor
     */
    public function __construct() {
        add_action('init', array($this, 'init'));
    }
    
    /**
     * Initialize the module
     */
    public function init() {
        // Add meta box to specified post types
        add_action('add_meta_boxes', array($this, 'add_meta_box'));
        
        // Enqueue scripts for admin
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
    }
    
    /**
     * Add meta box to specified post types
     */
    public function add_meta_box() {
        $post_types = array('city-listing', 'state-listing', 'page', 'post', 'certifications');
        
        foreach ($post_types as $post_type) {
            add_meta_box(
                'dh-bing-webmaster-tools',
                __('Bing Webmaster Tools', 'directory-helpers'),
                array($this, 'render_meta_box'),
                $post_type,
                'side',
                'low'
            );
        }
    }
    
    /**
     * Render the meta box
     */
    public function render_meta_box($post) {
        // Get the post URL
        $post_url = get_permalink($post->ID);
        
        if (!$post_url) {
            echo '<p>' . esc_html__('Post URL not available.', 'directory-helpers') . '</p>';
            return;
        }
        
        // Get site URL for Bing Webmaster Tools
        $site_url = home_url('/');
        
        // Build Bing Webmaster Tools URL
        $bing_url = $this->build_bing_url($site_url, $post_url);
        
        ?>
        <div class="dh-bing-webmaster-tools-wrap">
            <p>
                <button type="button" 
                        class="button button-secondary dh-bing-inspect-btn" 
                        data-bing-url="<?php echo esc_attr($bing_url); ?>">
                    <span class="dashicons dashicons-external" style="vertical-align: middle; margin-right: 5px;"></span>
                    <?php esc_html_e('Inspect in Bing', 'directory-helpers'); ?>
                </button>
            </p>
            <p class="description">
                <?php esc_html_e('Opens Bing Webmaster Tools URL inspection for this post in a new tab.', 'directory-helpers'); ?>
            </p>
        </div>
        <?php
    }
    
    /**
     * Build Bing Webmaster Tools URL
     */
    private function build_bing_url($site_url, $post_url) {
        $base_url = 'https://www.bing.com/webmasters/urlinspection';
        
        // Double encode the post URL for the urlToInspect parameter
        $encoded_post_url = rawurlencode(rawurlencode($post_url));
        
        // Single encode the site URL for the siteUrl parameter
        $encoded_site_url = rawurlencode($site_url);
        
        return $base_url . '?siteUrl=' . $encoded_site_url . '&urlToInspect=' . $encoded_post_url;
    }
    
    /**
     * Enqueue admin scripts
     */
    public function enqueue_admin_scripts($hook) {
        // Only load on post edit screens
        if (!in_array($hook, array('post.php', 'post-new.php'))) {
            return;
        }
        
        // Check if we're on one of the supported post types
        global $post;
        if (!$post) {
            return;
        }
        
        $supported_types = array('city-listing', 'state-listing', 'page', 'post', 'certifications');
        if (!in_array($post->post_type, $supported_types)) {
            return;
        }
        
        // Enqueue inline script
        wp_add_inline_script('jquery', $this->get_inline_script());
    }
    
    /**
     * Get inline JavaScript for button functionality
     */
    private function get_inline_script() {
        return "
        jQuery(document).ready(function($) {
            $('.dh-bing-inspect-btn').on('click', function(e) {
                e.preventDefault();
                var bingUrl = $(this).data('bing-url');
                if (bingUrl) {
                    window.open(bingUrl, '_blank', 'noopener,noreferrer');
                }
            });
        });
        ";
    }
}

// Initialize the module
new DH_Bing_Webmaster_Tools();
