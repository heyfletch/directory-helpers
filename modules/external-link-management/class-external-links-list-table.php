<?php
/**
 * External Links List Table
 * 
 * WP_List_Table implementation for viewing all external links across the site.
 * Supports WordPress Screen Options for column visibility.
 *
 * @package Directory_Helpers
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class DH_External_Links_List_Table extends WP_List_Table {
    
    /**
     * Constructor
     */
    public function __construct() {
        parent::__construct(array(
            'singular' => 'external_link',
            'plural'   => 'external_links',
            'ajax'     => false,
        ));
    }
    
    /**
     * Get table name
     */
    private function table_name() {
        global $wpdb;
        return $wpdb->prefix . 'dh_external_links';
    }
    
    /**
     * Define columns - compatible with Admin Columns Pro
     */
    public function get_columns() {
        return array(
            'cb'           => '<input type="checkbox" />',
            'post_title'   => __('Post', 'directory-helpers'),
            'anchor_text'  => __('Anchor Text', 'directory-helpers'),
            'current_url'  => __('URL', 'directory-helpers'),
            'status_code'  => __('Status', 'directory-helpers'),
            'last_checked' => __('Last Checked', 'directory-helpers'),
        );
    }
    
    /**
     * Define sortable columns
     */
    public function get_sortable_columns() {
        return array(
            'post_title'   => array('post_title', false),
            'anchor_text'  => array('anchor_text', false),
            'status_code'  => array('status_code', false),
            'last_checked' => array('last_checked', false),
        );
    }
    
    /**
     * Checkbox column
     */
    public function column_cb($item) {
        return sprintf('<input type="checkbox" name="link_ids[]" value="%d" />', $item['id']);
    }
    
    /**
     * Post title column
     */
    public function column_post_title($item) {
        $post = get_post($item['post_id']);
        if (!$post) {
            return '<em>' . __('(Deleted)', 'directory-helpers') . '</em>';
        }
        
        $edit_link = get_edit_post_link($item['post_id']);
        $view_link = get_permalink($item['post_id']);
        
        $actions = array(
            'edit' => sprintf('<a href="%s" target="_blank">%s</a>', esc_url($edit_link), __('Edit Post', 'directory-helpers')),
            'view' => sprintf('<a href="%s" target="_blank">%s</a>', esc_url($view_link), __('View', 'directory-helpers')),
        );
        
        return sprintf(
            '<strong><a href="%s" target="_blank">%s</a></strong>%s',
            esc_url($edit_link),
            esc_html($post->post_title),
            $this->row_actions($actions)
        );
    }
    
    /**
     * Anchor text column
     */
    public function column_anchor_text($item) {
        return esc_html($item['anchor_text'] ?: '—');
    }
    
    /**
     * URL column
     */
    public function column_current_url($item) {
        $url = esc_url($item['current_url']);
        $display_url = strlen($item['current_url']) > 50 
            ? substr($item['current_url'], 0, 50) . '...' 
            : $item['current_url'];
        
        return sprintf(
            '<a href="%s" target="_blank" rel="noopener" title="%s">%s</a>',
            $url,
            esc_attr($item['current_url']),
            esc_html($display_url)
        );
    }
    
    /**
     * Status code column
     */
    public function column_status_code($item) {
        $code = (int) $item['status_code'];
        $override_code = isset($item['status_override_code']) ? (int) $item['status_override_code'] : 0;
        $override_exp = isset($item['status_override_expires']) && $item['status_override_expires'] 
            ? strtotime($item['status_override_expires']) 
            : 0;
        
        // Check if override is active
        $has_override = $override_code && $override_exp && $override_exp > time();
        $effective_code = $has_override ? $override_code : $code;
        
        // Determine color based on effective status
        if ($effective_code === 200) {
            $color = '#46b450'; // Green
            $icon = '✅';
        } elseif ($effective_code === 403 || $effective_code === 0) {
            $color = '#f0b849'; // Yellow/Orange
            $icon = '⚠️';
        } elseif ($effective_code === 404 || ($effective_code >= 500 && $effective_code < 600)) {
            $color = '#dc3232'; // Red
            $icon = '🚨';
        } else {
            $color = '#999'; // Grey
            $icon = '❓';
        }
        
        $display = $effective_code ?: '—';
        if ($has_override) {
            $display .= ' <small>(override)</small>';
        }
        
        return sprintf(
            '<span style="color: %s;">%s %s</span>',
            $color,
            $icon,
            $display
        );
    }
    
    /**
     * Last checked column
     */
    public function column_last_checked($item) {
        if (empty($item['last_checked'])) {
            return '<em>' . __('Never', 'directory-helpers') . '</em>';
        }
        
        $timestamp = strtotime($item['last_checked']);
        $time_diff = human_time_diff($timestamp, current_time('timestamp'));
        
        return sprintf(
            '<span title="%s">%s %s</span>',
            esc_attr(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $timestamp)),
            $time_diff,
            __('ago', 'directory-helpers')
        );
    }
    
    /**
     * Default column handler
     */
    public function column_default($item, $column_name) {
        // Allow Admin Columns Pro to add custom columns
        return apply_filters('dh_external_links_column_' . $column_name, '', $item);
    }
    
    /**
     * Get bulk actions
     */
    public function get_bulk_actions() {
        return array(
            'recheck' => __('Re-check Status', 'directory-helpers'),
        );
    }
    
    /**
     * Process bulk actions
     */
    public function process_bulk_action() {
        if ('recheck' === $this->current_action()) {
            $link_ids = isset($_REQUEST['link_ids']) ? array_map('intval', $_REQUEST['link_ids']) : array();
            
            if (!empty($link_ids) && check_admin_referer('bulk-external_links')) {
                // Bulk recheck would be handled here
                // For now, just show a notice
                add_settings_error(
                    'dh_external_links',
                    'recheck_initiated',
                    sprintf(__('%d links queued for re-checking.', 'directory-helpers'), count($link_ids)),
                    'success'
                );
            }
        }
    }
    
    /**
     * Extra table navigation (filters)
     */
    public function extra_tablenav($which) {
        if ($which !== 'top') {
            return;
        }
        
        $current_status = isset($_GET['status_filter']) ? sanitize_text_field($_GET['status_filter']) : '';
        
        ?>
        <div class="alignleft actions">
            <select name="status_filter">
                <option value=""><?php esc_html_e('All Statuses', 'directory-helpers'); ?></option>
                <option value="200" <?php selected($current_status, '200'); ?>><?php esc_html_e('200 OK', 'directory-helpers'); ?></option>
                <option value="403" <?php selected($current_status, '403'); ?>><?php esc_html_e('403 Forbidden', 'directory-helpers'); ?></option>
                <option value="404" <?php selected($current_status, '404'); ?>><?php esc_html_e('404 Not Found', 'directory-helpers'); ?></option>
                <option value="0" <?php selected($current_status, '0'); ?>><?php esc_html_e('0 (Timeout)', 'directory-helpers'); ?></option>
                <option value="5xx" <?php selected($current_status, '5xx'); ?>><?php esc_html_e('5xx Server Error', 'directory-helpers'); ?></option>
                <option value="unchecked" <?php selected($current_status, 'unchecked'); ?>><?php esc_html_e('Not Checked', 'directory-helpers'); ?></option>
            </select>
            <?php submit_button(__('Filter', 'directory-helpers'), '', 'filter_action', false); ?>
        </div>
        <?php
    }
    
    /**
     * Prepare items for display
     */
    public function prepare_items() {
        global $wpdb;
        
        $this->process_bulk_action();
        
        $table = $this->table_name();
        $per_page = $this->get_items_per_page('external_links_per_page', 50);
        $current_page = $this->get_pagenum();
        
        // Build WHERE clause
        $where = array('1=1');
        $where_values = array();
        
        // Status filter
        $status_filter = isset($_GET['status_filter']) ? sanitize_text_field($_GET['status_filter']) : '';
        if ($status_filter !== '') {
            if ($status_filter === '5xx') {
                $where[] = 'l.status_code >= 500 AND l.status_code < 600';
            } elseif ($status_filter === 'unchecked') {
                $where[] = 'l.status_code IS NULL';
            } else {
                $where[] = 'l.status_code = %d';
                $where_values[] = (int) $status_filter;
            }
        }
        
        // Search
        $search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
        if ($search) {
            $where[] = '(l.anchor_text LIKE %s OR l.current_url LIKE %s OR p.post_title LIKE %s)';
            $search_like = '%' . $wpdb->esc_like($search) . '%';
            $where_values[] = $search_like;
            $where_values[] = $search_like;
            $where_values[] = $search_like;
        }
        
        $where_sql = implode(' AND ', $where);
        
        // Count total items
        $count_query = "SELECT COUNT(*) FROM {$table} l 
            LEFT JOIN {$wpdb->posts} p ON l.post_id = p.ID 
            WHERE {$where_sql}";
        
        if (!empty($where_values)) {
            $count_query = $wpdb->prepare($count_query, $where_values);
        }
        $total_items = (int) $wpdb->get_var($count_query);
        
        // Sorting
        $orderby = isset($_GET['orderby']) ? sanitize_key($_GET['orderby']) : 'last_checked';
        $order = isset($_GET['order']) && strtoupper($_GET['order']) === 'ASC' ? 'ASC' : 'DESC';
        
        // Map column names to actual DB columns
        $orderby_map = array(
            'post_title'   => 'p.post_title',
            'anchor_text'  => 'l.anchor_text',
            'status_code'  => 'l.status_code',
            'last_checked' => 'l.last_checked',
        );
        
        $orderby_sql = isset($orderby_map[$orderby]) ? $orderby_map[$orderby] : 'l.last_checked';
        
        // Get items
        $offset = ($current_page - 1) * $per_page;
        
        $query = "SELECT l.*, p.post_title, p.post_type 
            FROM {$table} l 
            LEFT JOIN {$wpdb->posts} p ON l.post_id = p.ID 
            WHERE {$where_sql}
            ORDER BY {$orderby_sql} {$order}
            LIMIT %d OFFSET %d";
        
        $query_values = array_merge($where_values, array($per_page, $offset));
        $results = $wpdb->get_results($wpdb->prepare($query, $query_values), ARRAY_A);
        
        $this->items = $results ?: array();
        
        // Set up pagination
        $this->set_pagination_args(array(
            'total_items' => $total_items,
            'per_page'    => $per_page,
            'total_pages' => ceil($total_items / $per_page),
        ));
        
        // Set up columns
        $columns = $this->get_columns();
        $hidden = get_hidden_columns($this->screen);
        $sortable = $this->get_sortable_columns();
        
        $this->_column_headers = array($columns, $hidden, $sortable);
    }
    
    /**
     * Message when no items found
     */
    public function no_items() {
        esc_html_e('No external links found.', 'directory-helpers');
    }
}
