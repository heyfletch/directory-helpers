<?php
/**
 * Custom Post Statuses Module
 *
 * Registers custom post statuses including "refining" and "closed"
 * with full admin interface support.
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class DH_Custom_Post_Statuses {
    
    /**
     * Constructor
     */
    public function __construct() {
        add_action('init', array($this, 'register_custom_post_statuses'));
        
        // Classic Editor support
        add_action('admin_footer-post.php', array($this, 'add_refining_to_post_status_dropdown'));
        add_action('admin_footer-post-new.php', array($this, 'add_refining_to_post_status_dropdown'));
        
        // Quick Edit and Bulk Edit support
        add_action('admin_footer-edit.php', array($this, 'add_refining_to_quick_edit_dropdown'));
        add_action('save_post', array($this, 'save_refining_status_from_quick_edit'), 10, 1);
        
        // Display states
        add_filter('display_post_states', array($this, 'fix_refining_display_state'), 10, 2);
        
        // REST API support
        add_filter('rest_post_valid_statuses', array($this, 'filter_rest_valid_statuses'), 10, 2);
        add_filter('rest_prepare_post', array($this, 'add_refining_to_rest_response'), 10, 3);
        add_filter('rest_prepare_page', array($this, 'add_refining_to_rest_response'), 10, 3);
        
        // Gutenberg support
        add_action('admin_footer', array($this, 'fix_gutenberg_refining_button'));
        
        // Body class
        add_filter('admin_body_class', array($this, 'add_refining_status_body_class'));
    }
    
    /**
     * Register custom post statuses
     */
    public function register_custom_post_statuses() {
        // Register the "refining" post status
        register_post_status('refining', array(
            'label'                     => _x('Refining', 'post'),
            'public'                    => false,
            'exclude_from_search'       => true,
            'show_in_admin_all_list'    => true,
            'show_in_admin_status_list' => true,
            'label_count'               => _n_noop('Refining <span class="count">(%s)</span>', 'Refining <span class="count">(%s)</span>'),
            'private'                   => true,
            'show_in_rest'              => true,
            'date_floating'             => false,
        ));
    }
    
    /**
     * Add Refining status to Classic Editor dropdown
     */
    public function add_refining_to_post_status_dropdown() {
        global $post;
        
        if (!is_object($post) || !property_exists($post, 'post_type') || $post->post_type !== 'post') {
            return;
        }
        
        // Skip for block editor
        if (get_current_screen()->is_block_editor()) {
            return;
        }
        ?>
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            // Add to status dropdown if not already present
            if ($("select#post_status").length && $("select#post_status option[value='refining']").length === 0) {
                $("select#post_status").append('<option value="refining"<?php echo selected($post->post_status, 'refining', false); ?>>Refining</option>');
            }
            
            // Update display if current status is refining
            if ("<?php echo $post->post_status; ?>" === "refining") {
                $("#post-status-display").text("Refining");
                if ($("select#post_status").length) {
                    $("select#post_status").val("refining");
                }
            }
        });
        </script>
        <?php
    }
    
    /**
     * Add Refining status to Quick Edit and Bulk Edit dropdowns
     */
    public function add_refining_to_quick_edit_dropdown() {
        // Skip for block editor
        if (function_exists('get_current_screen') && get_current_screen() && get_current_screen()->is_block_editor()) {
            return;
        }
        ?>
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            // Wait for Quick Edit to be initialized
            if (typeof inlineEditPost !== 'undefined') {
                const originalEdit = inlineEditPost.edit;
                inlineEditPost.edit = function(id) {
                    originalEdit.apply(this, arguments);
                    
                    // Add to Quick Edit dropdown
                    const $select = $('select[name="_status"]');
                    if ($select.length && $select.find('option[value="refining"]').length === 0) {
                        $select.append('<option value="refining">Refining</option>');
                    }
                    
                    // Set selected if current status is refining
                    const postRow = $('#post-' + id);
                    const currentStatus = postRow.find('.column-title .post-state').text().trim();
                    if (currentStatus === 'Refining') {
                        $select.val('refining');
                    }
                };
            }
            
            // Add to Bulk Edit dropdown
            $('select[name="_status"]').each(function() {
                if ($(this).find('option[value="refining"]').length === 0) {
                    $(this).append('<option value="refining">Refining</option>');
                }
            });
        });
        </script>
        <?php
    }
    
    /**
     * Handle saving from Quick Edit
     */
    public function save_refining_status_from_quick_edit($post_id) {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return $post_id;
        }
        
        if (!current_user_can('edit_post', $post_id)) {
            return $post_id;
        }
        
        // Check if this is a Quick Edit request
        if (isset($_REQUEST['action']) && $_REQUEST['action'] == 'inline-save' && 
            isset($_REQUEST['_status']) && $_REQUEST['_status'] == 'refining') {
            
            // Unhook to prevent infinite loops
            remove_action('save_post', array($this, 'save_refining_status_from_quick_edit'), 10);
            
            wp_update_post(array(
                'ID'          => $post_id,
                'post_status' => 'refining'
            ));
            
            // Re-hook
            add_action('save_post', array($this, 'save_refining_status_from_quick_edit'), 10, 1);
        }
        
        return $post_id;
    }
    
    /**
     * Display "Refining" state in post list
     */
    public function fix_refining_display_state($states, $post) {
        if ($post->post_status == 'refining') {
            $current_screen = get_current_screen();
            if ($current_screen && $current_screen->id === 'edit-post') {
                return array('Refining');
            }
        }
        return $states;
    }
    
    /**
     * REST API: Add refining to valid statuses
     */
    public function filter_rest_valid_statuses($valid_statuses, $request) {
        if (!in_array('refining', $valid_statuses, true)) {
            $valid_statuses[] = 'refining';
        }
        return $valid_statuses;
    }
    
    /**
     * REST API: Ensure refining status in responses
     */
    public function add_refining_to_rest_response($response, $post, $request) {
        if ($post->post_status === 'refining') {
            $response->data['status'] = 'refining';
        }
        return $response;
    }
    
    /**
     * Simple Gutenberg fix: Just make sure the button shows "Refining" text
     */
    public function fix_gutenberg_refining_button() {
        $screen = get_current_screen();
        if (!$screen || !$screen->is_block_editor()) {
            return;
        }
        
        global $post;
        $is_refining = ($post && $post->post_status === 'refining') ? 'true' : 'false';
        
        ?>
        <script type="text/javascript">
        (function() {
            // Simple function to set button text
            function setRefiningButtonText() {
                const buttons = document.querySelectorAll('.editor-post-status__toggle');
                buttons.forEach(button => {
                    if (button && button.textContent.trim() === '') {
                        button.textContent = 'Refining';
                    }
                });
            }
            
            // Run when page loads
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', function() {
                    if (<?php echo $is_refining; ?>) {
                        setTimeout(setRefiningButtonText, 100);
                    }
                });
            } else {
                if (<?php echo $is_refining; ?>) {
                    setTimeout(setRefiningButtonText, 100);
                }
            }
            
            // Watch for changes with MutationObserver
            if (<?php echo $is_refining; ?>) {
                const observer = new MutationObserver(function(mutations) {
                    mutations.forEach(function(mutation) {
                        if (mutation.type === 'childList' || mutation.type === 'characterData') {
                            const buttons = document.querySelectorAll('.editor-post-status__toggle');
                            buttons.forEach(button => {
                                if (button && button.textContent.trim() === '') {
                                    button.textContent = 'Refining';
                                }
                            });
                        }
                    });
                });
                
                // Start observing when DOM is ready
                setTimeout(function() {
                    const targetNode = document.querySelector('.editor-layout__content, .edit-post-layout__content, .editor, body');
                    if (targetNode) {
                        observer.observe(targetNode, {
                            childList: true,
                            subtree: true,
                            characterData: true
                        });
                    }
                    setRefiningButtonText();
                }, 500);
            }
        })();
        </script>
        
        <style>
        /* CSS fallback for empty refining status button */
        .editor-post-status__toggle:empty::after {
            content: 'Refining';
        }
        </style>
        <?php
    }
    
    /**
     * Add 'status-refining' body class to the post editor.
     * Works for both Classic and Block editors.
     */
    public function add_refining_status_body_class($classes) {
        global $post;
        $screen = get_current_screen();

        // Ensure we are on a post edit screen and the post object is available
        if (isset($screen->base) && $screen->base == 'post' && is_object($post)) {
            if ($post->post_status == 'refining') {
                $classes .= ' status-refining';
            }
        }
        
        return $classes;
    }
}

// Initialize the module
new DH_Custom_Post_Statuses();
