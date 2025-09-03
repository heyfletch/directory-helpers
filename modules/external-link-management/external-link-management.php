<?php
/**
 * External Link Management Module
 *
 * @package Directory_Helpers
 */

if (!defined('ABSPATH')) { exit; }

class DH_External_Link_Management {
    const DB_VERSION = '1';

    public function __construct() {
        // Ensure DB exists (immediately and on next init)
        $this->maybe_install_db();
        add_action('init', array($this, 'maybe_install_db'));

        // Shortcode to render stored external links
        add_action('init', function(){
            add_shortcode('ext_link', array($this, 'render_shortcoded_link'));
        });

        // React when AI content is saved
        add_action('directory_helpers/ai_content_updated', array($this, 'on_ai_content_updated'), 10, 2);

        // Per-post meta box
        add_action('add_meta_boxes', array($this, 'register_meta_box'));

        // AJAX: re-check a single link from the meta box
        add_action('wp_ajax_dh_elm_recheck_link', array($this, 'ajax_recheck_link'));
    }

    private function table_name() {
        global $wpdb;
        return $wpdb->prefix . 'dh_external_links';
    }

    public function maybe_install_db() {
        global $wpdb;
        $installed = get_option('dh_elm_db_version');
        if ($installed === self::DB_VERSION) { return; }

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset_collate = $wpdb->get_charset_collate();
        $table = $this->table_name();
        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            post_id BIGINT UNSIGNED NOT NULL,
            original_url TEXT NOT NULL,
            current_url TEXT NOT NULL,
            anchor_text TEXT,
            context_sentence TEXT,
            status_code INT,
            status_text VARCHAR(255),
            last_checked DATETIME,
            ai_suggestion_url TEXT,
            ai_suggestion_sentence TEXT,
            is_duplicate TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY post_id (post_id),
            KEY status_code (status_code)
        ) {$charset_collate};";
        dbDelta($sql);
        update_option('dh_elm_db_version', self::DB_VERSION);
    }

    public function register_meta_box() {
        $pts = array('city-listing', 'state-listing');
        foreach ($pts as $pt) {
            add_meta_box(
                'dh_link_manager',
                __('Link Manager', 'directory-helpers'),
                array($this, 'render_meta_box'),
                $pt,
                'normal',
                'default'
            );
        }
    }

    public function render_meta_box($post) {
        global $wpdb;
        $table = $this->table_name();
        $rows = $wpdb->get_results($wpdb->prepare("SELECT id, anchor_text, current_url, status_code, status_text, last_checked, is_duplicate FROM {$table} WHERE post_id=%d ORDER BY id ASC", $post->ID));
        if (!$rows) {
            echo '<p>' . esc_html__('No external links captured yet. Use the AI generator or run a scan to populate.', 'directory-helpers') . '</p>';
            return;
        }
        $nonce = wp_create_nonce('dh_elm_recheck_' . $post->ID);
        echo '<table class="widefat striped dh-elm-table"><thead><tr>';
        echo '<th style="width:70px;">' . esc_html__('ID', 'directory-helpers') . '</th>';
        echo '<th>' . esc_html__('Anchor', 'directory-helpers') . '</th>';
        echo '<th>' . esc_html__('URL', 'directory-helpers') . '</th>';
        echo '<th style="width:110px;">' . esc_html__('Status', 'directory-helpers') . '</th>';
        echo '<th style="width:140px;">' . esc_html__('Last checked', 'directory-helpers') . '</th>';
        echo '<th style="width:100px;">' . esc_html__('Duplicate', 'directory-helpers') . '</th>';
        echo '<th style="width:110px;">' . esc_html__('Actions', 'directory-helpers') . '</th>';
        echo '</tr></thead><tbody>';
        foreach ($rows as $r) {
            $status = is_null($r->status_code) ? '—' : (int)$r->status_code;
            $status_title = $r->status_text ? ' title="' . esc_attr($r->status_text) . '"' : '';
            $last_checked = $r->last_checked ? esc_html(date_i18n('Y-m-d g:ia', strtotime($r->last_checked))) : '—';
            $anchor_disp = esc_html(mb_strimwidth((string)$r->anchor_text, 0, 80, '…'));
            $anchor_q = rawurlencode((string)$r->anchor_text);
            $anchor_link = '<a href="https://www.google.com/search?q=' . $anchor_q . '" target="_blank" rel="noopener">' . $anchor_disp . '</a>';
            $url_disp = esc_html((string)$r->current_url);
            $url_link = '<a href="' . esc_url((string)$r->current_url) . '" target="_blank" rel="noopener" style="word-break:break-all;">' . $url_disp . '</a>';
            echo '<tr data-link-id="' . (int)$r->id . '">';
            echo '<td>' . (int)$r->id . '</td>';
            echo '<td>' . $anchor_link . '</td>';
            echo '<td>' . $url_link . '</td>';
            echo '<td class="dh-elm-status"' . $status_title . '>' . esc_html($status) . '</td>';
            echo '<td class="dh-elm-last-checked">' . $last_checked . '</td>';
            echo '<td>' . ($r->is_duplicate ? '<span class="dashicons dashicons-warning" title="Duplicate"></span>' : '') . '</td>';
            echo '<td><button type="button" class="button button-small dh-elm-recheck" data-nonce="' . esc_attr($nonce) . '">Re-check</button></td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
        echo '<p style="margin-top:8px;color:#666;">' . esc_html__('First occurrence of a URL remains linked. Subsequent occurrences are captured and flagged as duplicates (rendered as plain text).', 'directory-helpers') . '</p>';

        // Inline JS for per-link recheck
        ?>
        <script type="text/javascript">
        (function(){
            function onClick(e){
                var btn = e.target && e.target.closest && e.target.closest('.dh-elm-recheck');
                if(!btn){ return; }
                e.preventDefault();
                var tr = btn.closest('tr');
                if(!tr){ return; }
                var id = tr.getAttribute('data-link-id');
                if(!id){ return; }
                btn.disabled = true; btn.textContent = 'Checking…';
                var fd = new FormData();
                fd.append('action','dh_elm_recheck_link');
                fd.append('id', id);
                fd.append('post_id', <?php echo (int) $post->ID; ?>);
                fd.append('_ajax_nonce', btn.getAttribute('data-nonce'));
                var xhr = new XMLHttpRequest();
                xhr.open('POST', (window.ajaxurl || '<?php echo admin_url('admin-ajax.php'); ?>'));
                xhr.onload = function(){
                    btn.disabled = false; btn.textContent = 'Re-check';
                    try {
                        var res = JSON.parse(xhr.responseText);
                        if(res && res.success && res.data){
                            var cell = tr.querySelector('.dh-elm-status');
                            if(cell){
                                cell.textContent = String(res.data.status_code);
                                if(res.data.status_text){ cell.setAttribute('title', res.data.status_text); } else { cell.removeAttribute('title'); }
                            }
                            tr.querySelector('.dh-elm-last-checked').textContent = res.data.last_checked_display || '—';
                        } else {
                            alert('Check failed');
                        }
                    } catch(err){ alert('Check failed'); }
                };
                xhr.onerror = function(){ btn.disabled = false; btn.textContent = 'Re-check'; alert('Network error'); };
                xhr.send(fd);
            }
            document.addEventListener('click', onClick, false);
        })();
        </script>
        <?php
    }

    public function render_shortcoded_link($atts) {
        $atts = shortcode_atts(array('id' => 0), $atts, 'ext_link');
        $id = absint($atts['id']);
        if (!$id) { return ''; }
        global $wpdb;
        $table = $this->table_name();
        $row = $wpdb->get_row($wpdb->prepare("SELECT current_url, anchor_text, is_duplicate, status_code FROM {$table} WHERE id=%d", $id));
        if (!$row) { return ''; }
        $anchor = esc_html((string)$row->anchor_text);
        // Render as plain text for duplicates or non-200 status codes
        if ($row->is_duplicate || (isset($row->status_code) && (int)$row->status_code !== 200)) {
            // Render as plain text for duplicates
            return $anchor;
        }
        $url = esc_url((string)$row->current_url);
        if (!$url) { return $anchor; }
        // Default attributes: open in new tab with safe rel including nofollow
        return '<a href="' . $url . '" target="_blank" rel="noopener noreferrer nofollow">' . $anchor . '</a>';
    }

    public function on_ai_content_updated($post_id, $content) {
        $pt = get_post_type($post_id);
        if (!in_array($pt, array('city-listing','state-listing'), true)) {
            return;
        }
        // Convert external links to shortcodes and persist
        $updated = $this->scan_convert_and_save($post_id, $content);
        if ($updated !== false) {
            // Save updated HTML back to post content
            wp_update_post(array('ID' => $post_id, 'post_content' => $updated));
        }
        // Initial link status check (batched) — AI replacement to be implemented in a later phase
        $this->check_links_for_post($post_id);
    }

    private function is_internal_url($href) {
        $href = (string)$href;
        if ($href === '' || $href[0] === '#' || substr($href, 0, 1) === '/') { return true; }
        $scheme = parse_url($href, PHP_URL_SCHEME);
        if (!$scheme) { return true; }
        $scheme = strtolower($scheme);
        if (in_array($scheme, array('mailto','tel','javascript'), true)) { return true; }
        if (!in_array($scheme, array('http','https'), true)) { return false; }
        $host = strtolower((string) parse_url($href, PHP_URL_HOST));
        $site = parse_url(home_url('/'), PHP_URL_HOST);
        $site = strtolower((string)$site);
        if (!$host || !$site) { return false; }
        if ($host === $site) { return true; }
        // Subdomains of our site are internal
        if (substr($host, -strlen('.'.$site)) === '.'.$site) { return true; }
        return false;
    }

    private function extract_context_sentence($blockText, $anchorText) {
        $t = trim(preg_replace('/\s+/', ' ', (string)$blockText));
        if ($t === '') { return ''; }
        // Split into sentences rudimentarily
        $parts = preg_split('/(?<=[.!?])\s+/', $t);
        if (!is_array($parts) || empty($parts)) { return $t; }
        $a = trim((string)$anchorText);
        foreach ($parts as $p) {
            if ($a && mb_stripos($p, $a) !== false) { return trim($p); }
        }
        // Fallback to first sentence
        return trim($parts[0]);
    }

    private function scan_convert_and_save($post_id, $html) {
        if (!$post_id) { return false; }
        // Quick exit if content already contains our shortcodes — assume already processed
        if (strpos($html, '[ext_link') !== false) { return false; }

        // Use DOMDocument to parse and replace
        $doc = new DOMDocument();
        $internalErrors = libxml_use_internal_errors(true);
        $htmlWrapper = '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body>' . $html . '</body></html>';
        $doc->loadHTML($htmlWrapper);
        libxml_clear_errors();
        libxml_use_internal_errors($internalErrors);

        $anchors = $doc->getElementsByTagName('a');
        if (!$anchors || $anchors->length === 0) { return false; }

        // We will collect anchors first since live NodeList updates while iterating can be tricky
        $toProcess = array();
        foreach ($anchors as $a) { $toProcess[] = $a; }

        $seenUrls = array();
        global $wpdb;
        $table = $this->table_name();

        foreach ($toProcess as $a) {
            if (!$a->hasAttribute('href')) { continue; }
            $href = trim((string)$a->getAttribute('href'));
            if ($href === '') { continue; }
            if ($this->is_internal_url($href)) { continue; }

            // Extract anchor text
            $anchor_text = trim($a->textContent);

            // Attempt context sentence from closest block-level ancestor
            $block = $a;
            $maxHops = 6;
            while ($block && $maxHops-- > 0) {
                $tag = strtolower($block->nodeName);
                if (in_array($tag, array('p','li','div','section','article'), true) || $tag === 'body') { break; }
                $block = $block->parentNode;
            }
            $blockText = '';
            if ($block && method_exists($block, 'textContent')) { $blockText = $block->textContent; }
            $context_sentence = $this->extract_context_sentence($blockText, $anchor_text);

            // Determine duplicate based on current run
            $norm = strtolower($href);
            $is_duplicate = isset($seenUrls[$norm]) ? 1 : 0;
            if (!isset($seenUrls[$norm])) { $seenUrls[$norm] = true; }

            // Insert row
            $now = current_time('mysql');
            $ins = array(
                'post_id' => $post_id,
                'original_url' => $href,
                'current_url' => $href,
                'anchor_text' => $anchor_text,
                'context_sentence' => $context_sentence,
                'status_code' => null,
                'status_text' => null,
                'last_checked' => null,
                'ai_suggestion_url' => null,
                'ai_suggestion_sentence' => null,
                'is_duplicate' => $is_duplicate,
                'created_at' => $now,
                'updated_at' => $now,
            );
            $ok = $wpdb->insert($table, $ins, array(
                '%d','%s','%s','%s','%s','%d','%s','%s','%s','%s','%d','%s','%s'
            ));
            if ($ok === false) { continue; }
            $new_id = (int) $wpdb->insert_id;

            // Replace <a> node with shortcode text node
            $short = $doc->createTextNode('[ext_link id="' . $new_id . '"]');
            $a->parentNode->replaceChild($short, $a);
        }

        // Extract inner HTML of body
        $bodies = $doc->getElementsByTagName('body');
        if ($bodies->length) {
            $body = $bodies->item(0);
            $out = '';
            foreach ($body->childNodes as $child) {
                $out .= $doc->saveHTML($child);
            }
            return $out;
        }
        return false;
    }

    private function http_check_url($url, $timeout = 10) {
        $headers = array(
            'user-agent' => 'Mozilla/5.0 (compatible; DirectoryHelpersBot/1.0; +' . home_url('/') . ')',
            'referer' => home_url('/'),
        );
        $args = array('timeout' => $timeout, 'redirection' => 3, 'headers' => $headers);
        $resp = wp_remote_head($url, $args);
        $code = is_wp_error($resp) ? 0 : (int) wp_remote_retrieve_response_code($resp);
        if (is_wp_error($resp) || $code === 405) {
            $resp = wp_remote_get($url, $args);
        }
        if (is_wp_error($resp)) {
            return array(0, $resp->get_error_message());
        }
        return array((int) wp_remote_retrieve_response_code($resp), (string) wp_remote_retrieve_response_message($resp));
    }

    private function check_links_for_post($post_id) {
        global $wpdb;
        $table = $this->table_name();
        $rows = $wpdb->get_results($wpdb->prepare("SELECT id, current_url FROM {$table} WHERE post_id=%d", $post_id));
        if (!$rows) { return; }
        $timeout = 10; // per request
        foreach ($rows as $r) {
            $url = (string)$r->current_url;
            if (!$url) { continue; }
            list($code, $text) = $this->http_check_url($url, $timeout);
            $wpdb->update(
                $table,
                array(
                    'status_code' => $code,
                    'status_text' => $text,
                    'last_checked' => current_time('mysql'),
                    'updated_at' => current_time('mysql'),
                ),
                array('id' => $r->id),
                array('%d','%s','%s','%s'),
                array('%d')
            );
        }
    }

    public function ajax_recheck_link() {
        $id = isset($_POST['id']) ? absint($_POST['id']) : 0;
        $post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;
        if (!$id || !$post_id) { wp_send_json_error(array('message' => 'missing params')); }
        check_ajax_referer('dh_elm_recheck_' . $post_id);
        if (!current_user_can('edit_post', $post_id)) { wp_send_json_error(array('message' => 'forbidden')); }
        global $wpdb;
        $table = $this->table_name();
        $row = $wpdb->get_row($wpdb->prepare("SELECT id, current_url FROM {$table} WHERE id=%d AND post_id=%d", $id, $post_id));
        if (!$row) { wp_send_json_error(array('message' => 'not found')); }
        list($code, $text) = $this->http_check_url((string)$row->current_url, 10);
        $wpdb->update(
            $table,
            array(
                'status_code' => $code,
                'status_text' => $text,
                'last_checked' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
            ),
            array('id' => $id),
            array('%d','%s','%s','%s'),
            array('%d')
        );
        $last_display = date_i18n('Y-m-d g:ia');
        wp_send_json_success(array(
            'status_code' => (int)$code,
            'status_text' => (string)$text,
            'last_checked_display' => $last_display,
        ));
    }
}
