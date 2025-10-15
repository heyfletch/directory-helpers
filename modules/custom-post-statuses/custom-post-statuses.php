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
        add_action('admin_footer-post.php', array($this, 'add_custom_statuses_to_post_status_dropdown'));
        add_action('admin_footer-post-new.php', array($this, 'add_custom_statuses_to_post_status_dropdown'));
        
        // Quick Edit and Bulk Edit support
        add_action('admin_footer-edit.php', array($this, 'add_custom_statuses_to_quick_edit_dropdown'));
        add_action('save_post', array($this, 'save_custom_status_from_quick_edit'), 10, 1);
        
        // Display states
        add_filter('display_post_states', array($this, 'fix_custom_status_display_state'), 10, 2);
        
        // REST API support
        add_filter('rest_post_valid_statuses', array($this, 'filter_rest_valid_statuses'), 10, 2);
        add_filter('rest_prepare_post', array($this, 'add_custom_statuses_to_rest_response'), 10, 3);
        add_filter('rest_prepare_page', array($this, 'add_custom_statuses_to_rest_response'), 10, 3);
        
        // Gutenberg support
        add_action('admin_footer', array($this, 'fix_gutenberg_custom_status_button'));
        
        // Body class
        add_filter('admin_body_class', array($this, 'add_custom_status_body_class'));
        
        // Closed status redirect functionality
        add_action('pre_get_posts', array($this, 'handle_closed_status_query'));
        add_action('template_redirect', array($this, 'handle_closed_status_redirect'), 1);
        add_action('wp', array($this, 'handle_404_redirect_for_closed_profiles'));
        
        // Clear cache when profile status changes
        add_action('transition_post_status', array($this, 'clear_closed_profile_cache'), 10, 3);
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
        
        // Register the "closed" post status
        register_post_status('closed', array(
            'label'                     => _x('Closed', 'post'),
            'public'                    => true,  // Make it public so WordPress can find it
            'exclude_from_search'       => true,
            'show_in_admin_all_list'    => true,
            'show_in_admin_status_list' => true,
            'label_count'               => _n_noop('Closed <span class="count">(%s)</span>', 'Closed <span class="count">(%s)</span>'),
            'show_in_rest'              => true,
            'date_floating'             => false,
        ));
    }
    
    /**
     * Add custom statuses to Classic Editor dropdown
     */
    public function add_custom_statuses_to_post_status_dropdown() {
        global $post;
        
        if (!is_object($post) || !property_exists($post, 'post_type')) {
            return;
        }
        
        // Only add to post and profile post types
        if (!in_array($post->post_type, array('post', 'profile'), true)) {
            return;
        }
        
        // Skip for block editor
        if (get_current_screen()->is_block_editor()) {
            return;
        }
        ?>
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            // Add refining status to dropdown if not already present
            if ($("select#post_status").length && $("select#post_status option[value='refining']").length === 0) {
                $("select#post_status").append('<option value="refining"<?php echo selected($post->post_status, 'refining', false); ?>>Refining</option>');
            }
            
            // Add closed status to dropdown if not already present (profiles only)
            <?php if ($post->post_type === 'profile'): ?>
            if ($("select#post_status").length && $("select#post_status option[value='closed']").length === 0) {
                $("select#post_status").append('<option value="closed"<?php echo selected($post->post_status, 'closed', false); ?>>Closed</option>');
            }
            <?php endif; ?>
            
            // Update display if current status is refining
            if ("<?php echo $post->post_status; ?>" === "refining") {
                $("#post-status-display").text("Refining");
                if ($("select#post_status").length) {
                    $("select#post_status").val("refining");
                }
            }
            
            // Update display if current status is closed
            if ("<?php echo $post->post_status; ?>" === "closed") {
                $("#post-status-display").text("Closed");
                if ($("select#post_status").length) {
                    $("select#post_status").val("closed");
                }
            }
        });
        </script>
        <?php
    }
    
    /**
     * Add custom statuses to Quick Edit and Bulk Edit dropdowns
     */
    public function add_custom_statuses_to_quick_edit_dropdown() {
        // Skip for block editor
        if (function_exists('get_current_screen') && get_current_screen() && get_current_screen()->is_block_editor()) {
            return;
        }
        
        $screen = get_current_screen();
        $is_profile_screen = ($screen && $screen->post_type === 'profile');
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
                    
                    <?php if ($is_profile_screen): ?>
                    // Add closed status for profiles
                    if ($select.length && $select.find('option[value="closed"]').length === 0) {
                        $select.append('<option value="closed">Closed</option>');
                    }
                    <?php endif; ?>
                    
                    // Set selected if current status is refining
                    const postRow = $('#post-' + id);
                    const currentStatus = postRow.find('.column-title .post-state').text().trim();
                    if (currentStatus === 'Refining') {
                        $select.val('refining');
                    } else if (currentStatus === 'Closed') {
                        $select.val('closed');
                    }
                };
            }
            
            // Add to Bulk Edit dropdown
            $('select[name="_status"]').each(function() {
                if ($(this).find('option[value="refining"]').length === 0) {
                    $(this).append('<option value="refining">Refining</option>');
                }
                <?php if ($is_profile_screen): ?>
                if ($(this).find('option[value="closed"]').length === 0) {
                    $(this).append('<option value="closed">Closed</option>');
                }
                <?php endif; ?>
            });
        });
        </script>
        <?php
    }
    
    /**
     * Handle saving from Quick Edit
     */
    public function save_custom_status_from_quick_edit($post_id) {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return $post_id;
        }
        
        if (!current_user_can('edit_post', $post_id)) {
            return $post_id;
        }
        
        // Check if this is a Quick Edit request
        if (isset($_REQUEST['action']) && $_REQUEST['action'] == 'inline-save' && 
            isset($_REQUEST['_status']) && in_array($_REQUEST['_status'], array('refining', 'closed'), true)) {
            
            $new_status = sanitize_key($_REQUEST['_status']);
            
            // Unhook to prevent infinite loops
            remove_action('save_post', array($this, 'save_custom_status_from_quick_edit'), 10);
            
            wp_update_post(array(
                'ID'          => $post_id,
                'post_status' => $new_status
            ));
            
            // Re-hook
            add_action('save_post', array($this, 'save_custom_status_from_quick_edit'), 10, 1);
        }
        
        return $post_id;
    }
    
    /**
     * Display custom status states in post list
     */
    public function fix_custom_status_display_state($states, $post) {
        if ($post->post_status == 'refining') {
            $current_screen = get_current_screen();
            if ($current_screen && in_array($current_screen->id, array('edit-post', 'edit-profile'), true)) {
                return array('Refining');
            }
        } elseif ($post->post_status == 'closed') {
            $current_screen = get_current_screen();
            if ($current_screen && $current_screen->id === 'edit-profile') {
                return array('Closed');
            }
        }
        return $states;
    }
    
    /**
     * REST API: Add custom statuses to valid statuses
     */
    public function filter_rest_valid_statuses($valid_statuses, $request) {
        if (!in_array('refining', $valid_statuses, true)) {
            $valid_statuses[] = 'refining';
        }
        if (!in_array('closed', $valid_statuses, true)) {
            $valid_statuses[] = 'closed';
        }
        return $valid_statuses;
    }
    
    /**
     * REST API: Ensure custom statuses in responses
     */
    public function add_custom_statuses_to_rest_response($response, $post, $request) {
        if ($post->post_status === 'refining') {
            $response->data['status'] = 'refining';
        } elseif ($post->post_status === 'closed') {
            $response->data['status'] = 'closed';
        }
        return $response;
    }
    
    /**
     * Simple Gutenberg fix: Just make sure the button shows correct status text
     */
    public function fix_gutenberg_custom_status_button() {
        $screen = get_current_screen();
        if (!$screen || !$screen->is_block_editor()) {
            return;
        }
        
        global $post;
        $is_refining = ($post && $post->post_status === 'refining') ? 'true' : 'false';
        $is_closed = ($post && $post->post_status === 'closed') ? 'true' : 'false';
        $status_text = '';
        if ($post && $post->post_status === 'refining') {
            $status_text = 'Refining';
        } elseif ($post && $post->post_status === 'closed') {
            $status_text = 'Closed';
        }
        
        if ($status_text): ?>
        <script type="text/javascript">
        (function() {
            const statusText = '<?php echo esc_js($status_text); ?>';
            
            // Simple function to set button text
            function setCustomStatusButtonText() {
                const buttons = document.querySelectorAll('.editor-post-status__toggle');
                buttons.forEach(button => {
                    if (button && button.textContent.trim() === '') {
                        button.textContent = statusText;
                    }
                });
            }
            
            // Run when page loads
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', function() {
                    setTimeout(setCustomStatusButtonText, 100);
                });
            } else {
                setTimeout(setCustomStatusButtonText, 100);
            }
            
            // Watch for changes with MutationObserver
            const observer = new MutationObserver(function(mutations) {
                mutations.forEach(function(mutation) {
                    if (mutation.type === 'childList' || mutation.type === 'characterData') {
                        const buttons = document.querySelectorAll('.editor-post-status__toggle');
                        buttons.forEach(button => {
                            if (button && button.textContent.trim() === '') {
                                button.textContent = statusText;
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
                setCustomStatusButtonText();
            }, 500);
        })();
        </script>
        
        <style>
        /* CSS fallback for empty custom status button */
        .editor-post-status__toggle:empty::after {
            content: '<?php echo esc_attr($status_text); ?>';
        }
        </style>
        <?php endif;
    }
    
    /**
     * Add custom status body classes to the post editor.
     * Works for both Classic and Block editors.
     */
    public function add_custom_status_body_class($classes) {
        global $post;
        $screen = get_current_screen();

        // Ensure we are on a post edit screen and the post object is available
        if (isset($screen->base) && $screen->base == 'post' && is_object($post)) {
            if ($post->post_status == 'refining') {
                $classes .= ' status-refining';
            } elseif ($post->post_status == 'closed') {
                $classes .= ' status-closed';
            }
        }
        
        return $classes;
    }
    
    
    /**
     * Handle query for closed status posts to make them findable
     */
    public function handle_closed_status_query($query) {
        // Only handle main query on frontend for single posts
        if (is_admin() || !$query->is_main_query() || !$query->is_single()) {
            return;
        }
        
        // Get the post name from the query
        $post_name = $query->get('name');
        if (!$post_name) {
            return;
        }
        
        // Only check for profile post type queries
        $post_type = $query->get('post_type');
        if ($post_type && $post_type !== 'profile') {
            return;
        }
        
        // Use transient cache to avoid repeated queries (1 hour cache)
        $cache_key = 'dh_closed_profile_' . md5($post_name);
        $cached = get_transient($cache_key);
        
        if ($cached === 'exists') {
            $query->set('post_status', array('publish', 'closed'));
            return;
        } elseif ($cached === 'not_found') {
            return;
        }
        
        // Check if there's a closed profile with this slug
        $closed_post = get_posts(array(
            'name' => $post_name,
            'post_type' => 'profile',
            'post_status' => 'closed',
            'numberposts' => 1,
            'fields' => 'ids', // Only get IDs for performance
        ));
        
        if ($closed_post) {
            set_transient($cache_key, 'exists', HOUR_IN_SECONDS);
            $query->set('post_status', array('publish', 'closed'));
        } else {
            set_transient($cache_key, 'not_found', HOUR_IN_SECONDS);
        }
    }
    
    /**
     * Handle redirect for closed status posts
     */
    public function handle_closed_status_redirect() {
        
        // Check for closed profile by URL parameters first
        if (isset($_GET['post_type']) && $_GET['post_type'] === 'profile' && isset($_GET['p'])) {
            $post_id = intval($_GET['p']);
            $post = get_post($post_id);
            
            
            if ($post && $post->post_type === 'profile' && $post->post_status === 'closed' && !is_user_logged_in()) {
                $city_url = $this->get_city_url_for_profile($post);
                $redirect_to = $city_url ?: home_url();
                
                // Log suspicious closed profile access
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('CLOSED PROFILE REDIRECT: Profile "' . $post->post_title . '" (ID: ' . $post->ID . ', URL: ' . get_permalink($post) . ') -> ' . $redirect_to . ' | Referrer: ' . ($_SERVER['HTTP_REFERER'] ?? 'none') . ' | User-Agent: ' . ($_SERVER['HTTP_USER_AGENT'] ?? 'none'));
                }
                
                wp_redirect($redirect_to, 301);
                exit;
            }
        }
        
        // Check for closed profile by slug in URL
        $request_uri = $_SERVER['REQUEST_URI'] ?? '';
        if (preg_match('#^/profile/([^/]+)/?$#', $request_uri, $matches)) {
            $slug = $matches[1];
            $post = get_page_by_path($slug, OBJECT, 'profile');
            
            if ($post && $post->post_status === 'closed' && !is_user_logged_in()) {
                $city_url = $this->get_city_url_for_profile($post);
                $redirect_to = $city_url ?: home_url();
                
                // Log suspicious closed profile access
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('CLOSED PROFILE REDIRECT: Profile "' . $post->post_title . '" (ID: ' . $post->ID . ', URL: ' . get_permalink($post) . ') -> ' . $redirect_to . ' | Referrer: ' . ($_SERVER['HTTP_REFERER'] ?? 'none') . ' | User-Agent: ' . ($_SERVER['HTTP_USER_AGENT'] ?? 'none'));
                }
                
                wp_redirect($redirect_to, 301);
                exit;
            }
        }
        
        // Fallback: check if we're on a single post page
        if (is_single()) {
            global $post;
            
            // Only handle profile post type with closed status
            if ($post && $post->post_type === 'profile' && $post->post_status === 'closed' && !is_user_logged_in()) {
                $city_url = $this->get_city_url_for_profile($post);
                $redirect_to = $city_url ?: home_url();
                
                // Log suspicious closed profile access
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('CLOSED PROFILE REDIRECT: Profile "' . $post->post_title . '" (ID: ' . $post->ID . ', URL: ' . get_permalink($post) . ') -> ' . $redirect_to . ' | Referrer: ' . ($_SERVER['HTTP_REFERER'] ?? 'none') . ' | User-Agent: ' . ($_SERVER['HTTP_USER_AGENT'] ?? 'none'));
                }
                
                wp_redirect($redirect_to, 301);
                exit;
            }
        }
    }
    
    /**
     * Get the city URL for a profile post
     */
    private function get_city_url_for_profile($post) {
        if (!$post || $post->post_type !== 'profile') {
            return false;
        }
        
        // Get area and niche terms from the profile
        $area_terms = wp_get_post_terms($post->ID, 'area');
        $niche_terms = wp_get_post_terms($post->ID, 'niche');
        
        if (empty($area_terms) || empty($niche_terms) || is_wp_error($area_terms) || is_wp_error($niche_terms)) {
            return false;
        }
        
        $area_slug = $area_terms[0]->slug;
        $niche_slug = $niche_terms[0]->slug;
        
        // Build expected city-listing slug pattern: area-slug + niche-plural
        $niche_plural = $niche_slug . 's'; // Simple pluralization
        $city_slug = $area_slug . '-' . $niche_plural;
        
        // Find the city-listing post
        $city_post = get_page_by_path($city_slug, OBJECT, 'city-listing');
        
        if ($city_post && $city_post->post_status === 'publish') {
            return get_permalink($city_post);
        }
        
        // If exact match not found, try to find city-listing with matching area and niche terms
        $city_posts = get_posts(array(
            'post_type' => 'city-listing',
            'post_status' => 'publish',
            'numberposts' => 1,
            'tax_query' => array(
                'relation' => 'AND',
                array(
                    'taxonomy' => 'area',
                    'field' => 'term_id',
                    'terms' => $area_terms[0]->term_id,
                ),
                array(
                    'taxonomy' => 'niche',
                    'field' => 'term_id',
                    'terms' => $niche_terms[0]->term_id,
                )
            )
        ));
        
        if (!empty($city_posts)) {
            return get_permalink($city_posts[0]);
        }
        
        return false;
    }
    
    /**
     * Handle 404 redirects for closed profiles
     */
    public function handle_404_redirect_for_closed_profiles() {
        // Only handle 404 pages
        if (!is_404()) {
            return;
        }
        
        // Don't redirect logged-in users
        if (is_user_logged_in()) {
            return;
        }
        
        // Get the requested URL
        $request_uri = $_SERVER['REQUEST_URI'] ?? '';
        
        // Check if this looks like a profile URL
        if (preg_match('#^/profile/([^/]+)/?$#', $request_uri, $matches)) {
            $slug = $matches[1];
            
            // Look for a closed profile with this slug
            $posts = get_posts(array(
                'name' => $slug,
                'post_type' => 'profile',
                'post_status' => 'closed',
                'numberposts' => 1
            ));
            
            if (!empty($posts)) {
                $post = $posts[0];
                $city_url = $this->get_city_url_for_profile($post);
                $redirect_to = $city_url ?: home_url();
                
                // Log suspicious closed profile access
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('CLOSED PROFILE REDIRECT (404): Profile "' . $post->post_title . '" (ID: ' . $post->ID . ', URL: ' . get_permalink($post) . ') -> ' . $redirect_to . ' | Referrer: ' . ($_SERVER['HTTP_REFERER'] ?? 'none') . ' | User-Agent: ' . ($_SERVER['HTTP_USER_AGENT'] ?? 'none'));
                }
                
                wp_redirect($redirect_to, 301);
                exit;
            }
        }
    }
    
    /**
     * Clear closed profile cache when post status changes
     */
    public function clear_closed_profile_cache($new_status, $old_status, $post) {
        // Only clear cache for profile post type
        if ($post->post_type !== 'profile') {
            return;
        }
        
        // Clear cache if status changed to or from 'closed'
        if ($new_status === 'closed' || $old_status === 'closed') {
            $cache_key = 'dh_closed_profile_' . md5($post->post_name);
            delete_transient($cache_key);
        }
    }
}

// Initialize the module
new DH_Custom_Post_Statuses();
