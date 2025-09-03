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

        // Shortcodes to render stored external links (new: link; legacy: ext_link)
        add_action('init', function(){
            add_shortcode('link', array($this, 'render_shortcoded_link'));
            add_shortcode('ext_link', array($this, 'render_shortcoded_link'));
        });

        // React when AI content is saved
        add_action('directory_helpers/ai_content_updated', array($this, 'on_ai_content_updated'), 10, 2);

        // Per-post meta box
        add_action('add_meta_boxes', array($this, 'register_meta_box'));

        // AJAX: re-check a single link from the meta box
        add_action('wp_ajax_dh_elm_recheck_link', array($this, 'ajax_recheck_link'));

        // AJAX: scan current post content and convert external links to shortcodes
        add_action('wp_ajax_dh_elm_scan_now', array($this, 'ajax_scan_now'));

        // AJAX: update and delete link rows
        add_action('wp_ajax_dh_elm_update_link', array($this, 'ajax_update_link'));
        add_action('wp_ajax_dh_elm_delete_link', array($this, 'ajax_delete_link'));
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
            echo '<p>' . esc_html__('No external links captured yet. Use the AI generator or click Scan to populate.', 'directory-helpers') . '</p>';
        }
        $nonce = wp_create_nonce('dh_elm_recheck_' . $post->ID);
        // Scan Now button
        $scan_nonce = wp_create_nonce('dh_elm_scan_' . $post->ID);
        // Manage nonce (edit/delete)
        $manage_nonce = wp_create_nonce('dh_elm_manage_' . $post->ID);
        echo '<p>'
            . '<button type="button" class="button button-primary dh-elm-scan-now" data-nonce="' . esc_attr($scan_nonce) . '">Scan HTML and create shortcodes</button> '
            . '<button type="button" class="button dh-elm-open-200">' . esc_html__('↗️ Open all links', 'directory-helpers') . '</button>'
            . '</p>';

        echo '<table class="widefat striped dh-elm-table"><thead><tr>';
        echo '<th class="dh-sort" data-key="id" style="width:70px; cursor:pointer;">' . esc_html__('ID', 'directory-helpers') . '</th>';
        echo '<th style="width:170px;">' . esc_html__('Manage', 'directory-helpers') . '</th>';
        echo '<th class="dh-sort" data-key="anchor" style="cursor:pointer;">' . esc_html__('Anchor', 'directory-helpers') . '</th>';
        echo '<th class="dh-sort" data-key="url" style="cursor:pointer;">' . esc_html__('URL', 'directory-helpers') . '</th>';
        echo '<th class="dh-sort" data-key="status" style="width:110px; cursor:pointer;">' . esc_html__('Status', 'directory-helpers') . '</th>';
        echo '<th class="dh-sort" data-key="checked" style="width:140px; cursor:pointer;">' . esc_html__('Last checked', 'directory-helpers') . '</th>';
        echo '<th style="width:120px;">' . esc_html__('Re-check', 'directory-helpers') . '</th>';
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
            $data_anchor = esc_attr(strtolower((string)$r->anchor_text));
            $data_url = esc_attr(strtolower((string)$r->current_url));
            $data_status = is_null($r->status_code) ? 999999 : (int)$r->status_code;
            $data_checked = $r->last_checked ? (int) strtotime($r->last_checked) : 0;
            echo '<tr data-link-id="' . (int)$r->id . '" data-anchor="' . $data_anchor . '" data-url="' . $data_url . '" data-status="' . (int)$data_status . '" data-checked="' . (int)$data_checked . '">';
            echo '<td>' . (int)$r->id . '</td>';
            echo '<td class="dh-elm-manage">'
                . '<button type="button" class="button button-small dh-elm-edit" data-nonce="' . esc_attr($manage_nonce) . '">Edit</button> '
                . '<button type="button" class="button button-small dh-elm-delete" data-nonce="' . esc_attr($manage_nonce) . '">Delete</button>'
                . '</td>';
            echo '<td class="dh-cell-anchor">' . $anchor_link . '</td>';
            echo '<td class="dh-cell-url">' . $url_link . '</td>';
            echo '<td class="dh-elm-status"' . $status_title . '>' . esc_html($status) . '</td>';
            echo '<td class="dh-elm-last-checked">' . $last_checked . '</td>';
            echo '<td>' . '<button type="button" class="button button-small dh-elm-recheck" data-nonce="' . esc_attr($nonce) . '">Re-check</button>' . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
        echo '<p style="margin-top:8px;color:#666;">' . esc_html__('First occurrence of a URL is stored and converted to a shortcode. Subsequent occurrences are unlinked and not stored as records.', 'directory-helpers') . '</p>';

        // Inline JS for per-link recheck
        ?>
        <script type="text/javascript">
        (function(){
            function onClick(e){
                // Open all 200 links button
                var openBtn = e.target && e.target.closest && e.target.closest('.dh-elm-open-200');
                if(openBtn){
                    e.preventDefault();
                    var rows = document.querySelectorAll('.dh-elm-table tbody tr');
                    if(!rows || !rows.length){ return; }
                    rows.forEach(function(tr){
                        var status = parseInt(tr.getAttribute('data-status')||'0',10);
                        if(status === 200){
                            var a = tr.querySelector('.dh-cell-url a');
                            var href = a ? (a.getAttribute('href')||'') : '';
                            if(href){ window.open(href, '_blank'); }
                        }
                    });
                    return;
                }

                // Re-check button
                var reBtn = e.target && e.target.closest && e.target.closest('.dh-elm-recheck');
                if(reBtn){
                    e.preventDefault();
                    var tr = reBtn.closest('tr');
                    if(!tr){ return; }
                    var id = tr.getAttribute('data-link-id');
                    if(!id){ return; }
                    reBtn.disabled = true; reBtn.textContent = 'Checking…';
                    var fd = new FormData();
                    fd.append('action','dh_elm_recheck_link');
                    fd.append('id', id);
                    fd.append('post_id', <?php echo (int) $post->ID; ?>);
                    fd.append('_ajax_nonce', reBtn.getAttribute('data-nonce'));
                    var xhr = new XMLHttpRequest();
                    xhr.open('POST', (window.ajaxurl || '<?php echo admin_url('admin-ajax.php'); ?>'));
                    xhr.onload = function(){
                        reBtn.disabled = false; reBtn.textContent = 'Re-check';
                        try {
                            var res = JSON.parse(xhr.responseText);
                            if(res && res.success && res.data){
                                var cell = tr.querySelector('.dh-elm-status');
                                if(cell){
                                    cell.textContent = String(res.data.status_code);
                                    if(res.data.status_text){ cell.setAttribute('title', res.data.status_text); } else { cell.removeAttribute('title'); }
                                }
                                tr.querySelector('.dh-elm-last-checked').textContent = res.data.last_checked_display || '—';
                                // update sort attributes
                                tr.setAttribute('data-status', String(parseInt(res.data.status_code,10)||0));
                                tr.setAttribute('data-checked', String(Math.floor(Date.now()/1000)));
                            } else {
                                alert('Check failed');
                            }
                        } catch(err){ alert('Check failed'); }
                    };
                    xhr.onerror = function(){ reBtn.disabled = false; reBtn.textContent = 'Re-check'; alert('Network error'); };
                    xhr.send(fd);
                    return;
                }

                // Scan Now button
                var scanBtn = e.target && e.target.closest && e.target.closest('.dh-elm-scan-now');
                if(scanBtn){
                    e.preventDefault();
                    scanBtn.disabled = true; scanBtn.textContent = 'Scanning…';
                    var fd2 = new FormData();
                    fd2.append('action','dh_elm_scan_now');
                    fd2.append('post_id', <?php echo (int) $post->ID; ?>);
                    fd2.append('_ajax_nonce', scanBtn.getAttribute('data-nonce'));
                    var xhr2 = new XMLHttpRequest();
                    xhr2.open('POST', (window.ajaxurl || '<?php echo admin_url('admin-ajax.php'); ?>'));
                    xhr2.onload = function(){
                        scanBtn.disabled = false; scanBtn.textContent = 'Scan HTML and create shortcodes';
                        var txt = xhr2.responseText;
                        if(txt === '-1') { alert('Security check failed (nonce). Please refresh the page and try again.'); return; }
                        if(txt === '0') { alert('Permission denied or unexpected response.'); return; }
                        var res = null;
                        try { res = JSON.parse(txt); } catch(e){ }
                        if(res && res.success === true){
                            if(res.data && res.data.updated){
                                location.reload();
                            } else {
                                alert('No changes found.');
                            }
                        } else {
                            var msg = (res && res.data && res.data.message) ? res.data.message : ('Scan failed: ' + (txt || 'unknown error'));
                            alert(msg);
                        }
                    };
                    xhr2.onerror = function(){ scanBtn.disabled = false; scanBtn.textContent = 'Scan HTML and create shortcodes'; alert('Network error'); };
                    xhr2.send(fd2);
                }

                // Edit button
                var editBtn = e.target && e.target.closest && e.target.closest('.dh-elm-edit');
                if(editBtn){
                    e.preventDefault();
                    var tr = editBtn.closest('tr');
                    if(!tr){ return; }
                    if(tr.classList.contains('editing')){ return; }
                    tr.classList.add('editing');
                    var anchorCell = tr.querySelector('.dh-cell-anchor');
                    var urlCell = tr.querySelector('.dh-cell-url');
                    var actionsCell = editBtn.parentNode;
                    var currentAnchorText = anchorCell ? anchorCell.textContent.trim() : '';
                    var currentUrl = '';
                    var linkEl = urlCell ? urlCell.querySelector('a') : null;
                    if(linkEl){ currentUrl = linkEl.getAttribute('href') || linkEl.textContent.trim(); }
                    // Build inputs
                    anchorCell.innerHTML = '';
                    var aInput = document.createElement('input');
                    aInput.type = 'text'; aInput.style.width = '100%'; aInput.value = currentAnchorText;
                    anchorCell.appendChild(aInput);

                    urlCell.innerHTML = '';
                    var uInput = document.createElement('input');
                    uInput.type = 'url'; uInput.style.width = '100%'; uInput.value = currentUrl;
                    urlCell.appendChild(uInput);

                    // Replace actions with Save/Cancel
                    var nonce = editBtn.getAttribute('data-nonce');
                    var reBtnHtml = actionsCell.innerHTML; // stash to restore on cancel/save
                    actionsCell.setAttribute('data-orig', reBtnHtml);
                    actionsCell.innerHTML = '';

                    var saveBtn = document.createElement('button');
                    saveBtn.type = 'button'; saveBtn.className = 'button button-small'; saveBtn.textContent = 'Save';
                    var cancelBtn = document.createElement('button');
                    cancelBtn.type = 'button'; cancelBtn.className = 'button button-small'; cancelBtn.textContent = 'Cancel';
                    actionsCell.appendChild(saveBtn);
                    actionsCell.appendChild(document.createTextNode(' '));
                    actionsCell.appendChild(cancelBtn);

                    cancelBtn.addEventListener('click', function(){
                        tr.classList.remove('editing');
                        // Restore view
                        anchorCell.textContent = currentAnchorText;
                        if(currentAnchorText){
                            var a = document.createElement('a');
                            a.href = 'https://www.google.com/search?q=' + encodeURIComponent(currentAnchorText);
                            a.target = '_blank'; a.rel = 'noopener'; a.textContent = currentAnchorText;
                            anchorCell.innerHTML = ''; anchorCell.appendChild(a);
                        }
                        urlCell.textContent = currentUrl;
                        if(currentUrl){
                            var u = document.createElement('a');
                            u.href = currentUrl; u.target = '_blank'; u.rel = 'noopener'; u.style.wordBreak = 'break-all'; u.textContent = currentUrl;
                            urlCell.innerHTML = ''; urlCell.appendChild(u);
                        }
                        actionsCell.innerHTML = actionsCell.getAttribute('data-orig') || '';
                    });

                    saveBtn.addEventListener('click', function(){
                        var newAnchor = (aInput.value || '').trim();
                        var newUrl = (uInput.value || '').trim();
                        if(!newUrl || !/^https?:\/\//i.test(newUrl)){
                            alert('Please enter a valid http(s) URL.'); return;
                        }
                        saveBtn.disabled = true; saveBtn.textContent = 'Saving…';
                        var fd3 = new FormData();
                        fd3.append('action','dh_elm_update_link');
                        fd3.append('id', tr.getAttribute('data-link-id'));
                        fd3.append('post_id', <?php echo (int) $post->ID; ?>);
                        fd3.append('anchor_text', newAnchor);
                        fd3.append('current_url', newUrl);
                        fd3.append('_ajax_nonce', nonce);
                        var xhr3 = new XMLHttpRequest();
                        xhr3.open('POST', (window.ajaxurl || '<?php echo admin_url('admin-ajax.php'); ?>'));
                        xhr3.onload = function(){
                            saveBtn.disabled = false; saveBtn.textContent = 'Save';
                            var txt = xhr3.responseText || '';
                            if(txt === '-1'){ alert('Security check failed (nonce).'); return; }
                            var res = null; try { res = JSON.parse(txt); } catch(e){}
                            if(res && res.success && res.data){
                                var updatedAnchor = res.data.anchor_text || '';
                                var updatedUrl = res.data.current_url || newUrl;
                                // Update display
                                anchorCell.innerHTML = '';
                                if(updatedAnchor){
                                    var a = document.createElement('a'); a.href = 'https://www.google.com/search?q=' + encodeURIComponent(updatedAnchor); a.target = '_blank'; a.rel = 'noopener'; a.textContent = updatedAnchor; anchorCell.appendChild(a);
                                }
                                urlCell.innerHTML = '';
                                if(updatedUrl){ var u = document.createElement('a'); u.href = updatedUrl; u.target = '_blank'; u.rel = 'noopener'; u.style.wordBreak = 'break-all'; u.textContent = updatedUrl; urlCell.appendChild(u); }
                                // Update Status and Last checked cells
                                var statusCell = tr.querySelector('.dh-elm-status');
                                if(statusCell){ statusCell.textContent = String((res.data.status_code != null ? res.data.status_code : '—')); statusCell.removeAttribute('title'); }
                                var lcCell = tr.querySelector('.dh-elm-last-checked');
                                if(lcCell){ lcCell.textContent = res.data.last_checked_display || '—'; }
                                // Update row data attributes for sorting
                                tr.setAttribute('data-anchor', String(updatedAnchor).toLowerCase());
                                tr.setAttribute('data-url', String(updatedUrl).toLowerCase());
                                if(typeof res.data.status_code !== 'undefined'){
                                    tr.setAttribute('data-status', String(parseInt(res.data.status_code,10)||0));
                                }
                                tr.setAttribute('data-checked', String(Math.floor(Date.now()/1000)));
                                // Restore actions
                                tr.classList.remove('editing');
                                actionsCell.innerHTML = actionsCell.getAttribute('data-orig') || '';
                            } else {
                                var msg = (res && res.data && res.data.message) ? res.data.message : 'Save failed.';
                                alert(msg);
                            }
                        };
                        xhr3.onerror = function(){ saveBtn.disabled = false; saveBtn.textContent = 'Save'; alert('Network error'); };
                        xhr3.send(fd3);
                    });
                }

                // Delete button
                var delBtn = e.target && e.target.closest && e.target.closest('.dh-elm-delete');
                if(delBtn){
                    e.preventDefault();
                    var tr = delBtn.closest('tr');
                    if(!tr){ return; }
                    if(!window.confirm('Delete this link record? This cannot be undone.')){ return; }
                    delBtn.disabled = true; delBtn.textContent = 'Deleting…';
                    var fd4 = new FormData();
                    fd4.append('action','dh_elm_delete_link');
                    fd4.append('id', tr.getAttribute('data-link-id'));
                    fd4.append('post_id', <?php echo (int) $post->ID; ?>);
                    fd4.append('_ajax_nonce', delBtn.getAttribute('data-nonce'));
                    var xhr4 = new XMLHttpRequest();
                    xhr4.open('POST', (window.ajaxurl || '<?php echo admin_url('admin-ajax.php'); ?>'));
                    xhr4.onload = function(){
                        delBtn.disabled = false; delBtn.textContent = 'Delete';
                        var txt = xhr4.responseText || '';
                        if(txt === '-1'){ alert('Security check failed (nonce).'); return; }
                        var res = null; try { res = JSON.parse(txt); } catch(e){}
                        if(res && res.success){ tr.parentNode.removeChild(tr); }
                        else { var msg = (res && res.data && res.data.message) ? res.data.message : 'Delete failed.'; alert(msg); }
                    };
                    xhr4.onerror = function(){ delBtn.disabled = false; delBtn.textContent = 'Delete'; alert('Network error'); };
                    xhr4.send(fd4);
                }
            }
            document.addEventListener('click', onClick, false);

            // Sorting
            var currentSortKey = 'id';
            var currentSortDir = 'asc';
            function sortRows(key){
                var tbody = document.querySelector('.dh-elm-table tbody');
                if(!tbody){ return; }
                var rows = Array.prototype.slice.call(tbody.querySelectorAll('tr'));
                var mul = (currentSortDir === 'asc') ? 1 : -1;
                rows.sort(function(a, b){
                    var av, bv;
                    if(key === 'id'){ av = parseInt(a.getAttribute('data-link-id')||'0',10); bv = parseInt(b.getAttribute('data-link-id')||'0',10); }
                    else if(key === 'anchor'){ av = a.getAttribute('data-anchor')||''; bv = b.getAttribute('data-anchor')||''; }
                    else if(key === 'url'){ av = a.getAttribute('data-url')||''; bv = b.getAttribute('data-url')||''; }
                    else if(key === 'status'){ av = parseInt(a.getAttribute('data-status')||'0',10); bv = parseInt(b.getAttribute('data-status')||'0',10); }
                    else if(key === 'checked'){ av = parseInt(a.getAttribute('data-checked')||'0',10); bv = parseInt(b.getAttribute('data-checked')||'0',10); }
                    else { av = 0; bv = 0; }
                    if(av < bv) return -1*mul;
                    if(av > bv) return 1*mul;
                    return 0;
                });
                // Re-append sorted
                rows.forEach(function(r){ tbody.appendChild(r); });
            }
            document.querySelectorAll('.dh-elm-table thead .dh-sort').forEach(function(th){
                th.addEventListener('click', function(){
                    var key = th.getAttribute('data-key') || 'id';
                    if(currentSortKey === key){ currentSortDir = (currentSortDir === 'asc') ? 'desc' : 'asc'; }
                    else { currentSortKey = key; currentSortDir = 'asc'; }
                    sortRows(currentSortKey);
                });
            });
            // Initial sort by ID asc (already default), but ensure dataset present
            sortRows(currentSortKey);
        })();
        </script>
        <?php
    }

    public function render_shortcoded_link($atts, $content = null, $tag = 'link') {
        $atts = shortcode_atts(array('id' => 0, 't' => ''), $atts, $tag ?: 'link');
        $id = absint($atts['id']);
        if (!$id) { return ''; }
        global $wpdb;
        $table = $this->table_name();
        $row = $wpdb->get_row($wpdb->prepare("SELECT current_url, anchor_text, is_duplicate, status_code FROM {$table} WHERE id=%d", $id));
        if (!$row) { return ''; }
        $t_decoded = html_entity_decode((string)($atts['t'] ?? ''), ENT_QUOTES | ENT_HTML5);
        $anchor_source = ($row->anchor_text !== null && $row->anchor_text !== '') ? $row->anchor_text : $t_decoded;
        $anchor = esc_html((string)$anchor_source);
        // Render as plain text only for duplicates; allow linking even if status is unknown/non-200
        if ($row->is_duplicate) {
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
        // Do not bail if content already contains shortcodes; we only transform remaining <a> tags.

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
        // Build map of existing URLs to their record IDs (do not mark as seen yet)
        $existing_rows = $wpdb->get_results($wpdb->prepare("SELECT id, LOWER(current_url) AS url FROM {$table} WHERE post_id=%d ORDER BY id ASC", $post_id));
        $existing_map = array();
        if (is_array($existing_rows)) {
            foreach ($existing_rows as $er) {
                $u = isset($er->url) ? (string)$er->url : '';
                if ($u !== '') { $existing_map[$u] = (int)$er->id; }
            }
        }

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

            // Determine handling based on current scan and existing DB
            $norm = strtolower($href);
            if (isset($seenUrls[$norm])) {
                // Already handled one occurrence in this scan: unwrap duplicates
                $frag = $doc->createDocumentFragment();
                while ($a->firstChild) { $frag->appendChild($a->firstChild); }
                if ($a->parentNode) { $a->parentNode->replaceChild($frag, $a); }
                continue;
            }

            // First occurrence encountered in this scan
            if (isset($existing_map[$norm])) {
                // Reuse existing record ID and convert to shortcode
                $existing_id = (int) $existing_map[$norm];
                // Optionally refresh anchor_text and updated_at
                $wpdb->update($table, array(
                    'anchor_text' => $anchor_text,
                    'updated_at' => current_time('mysql'),
                ), array('id' => $existing_id), array('%s','%s'), array('%d'));
                $t_attr = esc_attr($anchor_text);
                $short = $doc->createTextNode('[link id="' . $existing_id . '" t="' . $t_attr . '"]');
                if ($a->parentNode) { $a->parentNode->replaceChild($short, $a); }
                $seenUrls[$norm] = true;
                continue;
            }

            // No existing record: create one and convert to shortcode
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
                'is_duplicate' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            );
            $ok = $wpdb->insert($table, $ins, array(
                '%d','%s','%s','%s','%s','%d','%s','%s','%s','%s','%d','%s','%s'
            ));
            if ($ok === false) { continue; }
            $new_id = (int) $wpdb->insert_id;

            $t_attr = esc_attr($anchor_text);
            $short = $doc->createTextNode('[link id="' . $new_id . '" t="' . $t_attr . '"]');
            if ($a->parentNode) { $a->parentNode->replaceChild($short, $a); }
            $seenUrls[$norm] = true;
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
        // Common headers and args
        $headers = array(
            'user-agent' => 'Mozilla/5.0 (compatible; DirectoryHelpersBot/1.0; +' . home_url('/') . ')',
            'referer' => home_url('/'),
            'accept' => '*/*',
        );
        $base_args = array('timeout' => $timeout, 'redirection' => 5, 'headers' => $headers);

        // Detect binary assets (PDF, images, archives, media)
        $path = (string) parse_url($url, PHP_URL_PATH);
        $is_binary_ext = (bool) preg_match('/\.(pdf|zip|rar|7z|gz|tar|docx|xlsx|pptx|csv|jpg|jpeg|png|gif|webp|svg|bmp|tiff|mp4|mov|avi|wmv|m4v|mp3|wav)(?:$|[?#])/i', $path ?: '');

        // For binary assets, many servers do not support HEAD or return misleading codes.
        // Use a lightweight GET with Range to avoid large downloads.
        if ($is_binary_ext) {
            $args = $base_args;
            $args['headers']['range'] = 'bytes=0-0';
            $resp = wp_remote_get($url, $args);
            if (is_wp_error($resp)) {
                return array(0, $resp->get_error_message());
            }
            $code = (int) wp_remote_retrieve_response_code($resp);
            $text = (string) wp_remote_retrieve_response_message($resp);
            // Map 206 Partial Content to 200 OK for binary assets (we successfully reached the resource)
            if ($code === 206) { $code = 200; $text = 'OK'; }
            // If server rejects Range or returns suspicious code, try a normal GET as fallback
            if (in_array($code, array(0, 403, 404, 405, 416), true)) {
                $resp = wp_remote_get($url, $base_args);
                if (is_wp_error($resp)) {
                    return array(0, $resp->get_error_message());
                }
                $code = (int) wp_remote_retrieve_response_code($resp);
                $text = (string) wp_remote_retrieve_response_message($resp);
                if ($code === 206) { $code = 200; $text = 'OK'; }
            }
            return array($code, $text);
        }

        // Default: try HEAD first for non-binaries
        $resp = wp_remote_head($url, $base_args);
        $code = is_wp_error($resp) ? 0 : (int) wp_remote_retrieve_response_code($resp);
        // Fallback to GET when HEAD is not reliable or not allowed
        if (is_wp_error($resp) || in_array($code, array(0, 405, 403, 404), true)) {
            $resp = wp_remote_get($url, $base_args); // full GET, let WP handle redirects
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

    public function ajax_scan_now() {
        $post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;
        if (!$post_id) { wp_send_json_error(array('message' => 'missing post')); }
        check_ajax_referer('dh_elm_scan_' . $post_id);
        if (!current_user_can('edit_post', $post_id)) { wp_send_json_error(array('message' => 'forbidden')); }
        $post = get_post($post_id);
        if (!$post) { wp_send_json_error(array('message' => 'not found')); }
        if (!class_exists('DOMDocument')) {
            wp_send_json_error(array('message' => 'PHP DOM extension is not available on this server. Unable to scan.'));
        }
        try {
            $updated = $this->scan_convert_and_save($post_id, (string)$post->post_content);
        } catch (Throwable $e) {
            wp_send_json_error(array('message' => 'Scan exception: ' . $e->getMessage()));
            return;
        } catch (Exception $e) { // for older PHP versions
            wp_send_json_error(array('message' => 'Scan exception: ' . $e->getMessage()));
            return;
        }
        if ($updated !== false) {
            wp_update_post(array('ID' => $post_id, 'post_content' => $updated));
            wp_send_json_success(array('updated' => true));
        }
        wp_send_json_success(array('updated' => false));
    }

    private function recalc_duplicates_for_post($post_id) {
        global $wpdb;
        $table = $this->table_name();
        $rows = $wpdb->get_results($wpdb->prepare("SELECT id, current_url FROM {$table} WHERE post_id=%d ORDER BY id ASC", $post_id));
        if (!$rows) { return; }
        $seen = array();
        foreach ($rows as $row) {
            $norm = strtolower((string)$row->current_url);
            $is_dup = isset($seen[$norm]) ? 1 : 0;
            if (!isset($seen[$norm])) { $seen[$norm] = true; }
            $wpdb->update($table, array('is_duplicate' => $is_dup, 'updated_at' => current_time('mysql')), array('id' => $row->id), array('%d','%s'), array('%d'));
        }
    }

    public function ajax_update_link() {
        $id = isset($_POST['id']) ? absint($_POST['id']) : 0;
        $post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;
        $anchor_text = isset($_POST['anchor_text']) ? wp_unslash((string)$_POST['anchor_text']) : '';
        $current_url = isset($_POST['current_url']) ? esc_url_raw((string)$_POST['current_url']) : '';
        if (!$id || !$post_id) { wp_send_json_error(array('message' => 'missing params')); }
        check_ajax_referer('dh_elm_manage_' . $post_id);
        if (!current_user_can('edit_post', $post_id)) { wp_send_json_error(array('message' => 'forbidden')); }
        if (!$current_url || !preg_match('#^https?://#i', $current_url)) {
            wp_send_json_error(array('message' => 'Invalid URL'));
        }
        global $wpdb;
        $table = $this->table_name();
        $row = $wpdb->get_row($wpdb->prepare("SELECT id FROM {$table} WHERE id=%d AND post_id=%d", $id, $post_id));
        if (!$row) { wp_send_json_error(array('message' => 'not found')); }
        $now = current_time('mysql');
        $wpdb->update(
            $table,
            array(
                'anchor_text' => $anchor_text,
                'current_url' => $current_url,
                'status_code' => 200,
                'status_text' => 'OK',
                'last_checked' => $now,
                'updated_at' => $now,
            ),
            array('id' => $id),
            array('%s','%s','%d','%s','%s','%s'),
            array('%d')
        );
        // Make this edited row canonical for its URL; mark others as duplicates
        $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET is_duplicate=1, updated_at=%s WHERE post_id=%d AND id<>%d AND LOWER(current_url)=LOWER(%s)",
            $now, $post_id, $id, $current_url
        ));
        $wpdb->update($table, array('is_duplicate' => 0, 'updated_at' => $now), array('id' => $id), array('%d','%s'), array('%d'));
        // Return updated row
        $dup = (int) $wpdb->get_var($wpdb->prepare("SELECT is_duplicate FROM {$table} WHERE id=%d", $id));
        $last_checked_display = date_i18n('Y-m-d g:ia', strtotime($now));
        wp_send_json_success(array(
            'anchor_text' => $anchor_text,
            'current_url' => $current_url,
            'is_duplicate' => $dup,
            'status_code' => 200,
            'last_checked_display' => $last_checked_display,
        ));
    }

    public function ajax_delete_link() {
        $id = isset($_POST['id']) ? absint($_POST['id']) : 0;
        $post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;
        if (!$id || !$post_id) { wp_send_json_error(array('message' => 'missing params')); }
        check_ajax_referer('dh_elm_manage_' . $post_id);
        if (!current_user_can('edit_post', $post_id)) { wp_send_json_error(array('message' => 'forbidden')); }
        global $wpdb;
        $table = $this->table_name();
        $row = $wpdb->get_row($wpdb->prepare("SELECT id FROM {$table} WHERE id=%d AND post_id=%d", $id, $post_id));
        if (!$row) { wp_send_json_error(array('message' => 'not found')); }
        $wpdb->delete($table, array('id' => $id), array('%d'));
        // Recalculate duplicates after removal
        $this->recalc_duplicates_for_post($post_id);
        wp_send_json_success();
    }
}
