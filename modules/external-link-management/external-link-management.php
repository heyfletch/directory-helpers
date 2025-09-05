<?php
/**
 * External Link Management Module
 *
 * @package Directory_Helpers
 */

if (!defined('ABSPATH')) { exit; }

class DH_External_Link_Management {
    const DB_VERSION = '2';

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
        // AJAX: set/clear override (treat as OK for a period)
        add_action('wp_ajax_dh_elm_set_override', array($this, 'ajax_set_override'));
        // AJAX: AI suggest/apply
        add_action('wp_ajax_dh_elm_ai_suggest_link', array($this, 'ajax_ai_suggest_link'));
        add_action('wp_ajax_dh_elm_ai_apply_suggestion', array($this, 'ajax_ai_apply_suggestion'));
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
            status_override_code INT,
            status_override_expires DATETIME,
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
        $rows = $wpdb->get_results($wpdb->prepare("SELECT id, anchor_text, current_url, status_code, status_text, last_checked, is_duplicate, status_override_code, status_override_expires FROM {$table} WHERE post_id=%d ORDER BY id ASC", $post->ID));
        if (!$rows) {
            echo '<p>' . esc_html__('No external links captured yet. Use the AI generator or click Scan to populate.', 'directory-helpers') . '</p>';
        }
        $nonce = wp_create_nonce('dh_elm_recheck_' . $post->ID);
        // Scan Now button
        $scan_nonce = wp_create_nonce('dh_elm_scan_' . $post->ID);
        // Manage nonce (edit/delete)
        $manage_nonce = wp_create_nonce('dh_elm_manage_' . $post->ID);
        // AI nonce
        $ai_nonce = wp_create_nonce('dh_elm_ai_' . $post->ID);
        echo '<p>'
            . '<button type="button" class="button button-primary dh-elm-scan-now" data-nonce="' . esc_attr($scan_nonce) . '">Scan HTML and create shortcodes</button> '
            . '<button type="button" class="button dh-elm-open-200">' . esc_html__('↗️ Open all links', 'directory-helpers') . '</button>'
            . '</p>';

        // Inline styles for status coloring
        echo '<style>
        .dh-elm-table .dh-elm-status{ white-space: nowrap; }
        /* Actions and ID columns: minimal width and no wrapping */
        .dh-elm-table th.dh-col-actions, .dh-elm-table td.dh-elm-manage { width:1%; white-space:nowrap; }
        .dh-elm-table th.dh-col-id, .dh-elm-table td.dh-cell-id { width:1%; white-space:nowrap; }
        /* Color rows: include URL cell text now that it is not a hyperlink */
        tr.dh-status-ok .dh-elm-status, tr.dh-status-ok .dh-cell-url, tr.dh-status-ok .dh-cell-anchor a { color:#1f8a3b; font-weight:600; }
        tr.dh-status-4xx .dh-elm-status, tr.dh-status-4xx .dh-cell-url, tr.dh-status-4xx .dh-cell-anchor a { color:#d63638; font-weight:600; }
        tr.dh-status-0 .dh-elm-status, tr.dh-status-0 .dh-cell-url, tr.dh-status-0 .dh-cell-anchor a { color:#555; font-weight:600; }
        .dh-ai-msg{ display:inline-block; margin-left:6px; font-size:12px; color:#444; }
        .dh-ai-msg.dh-ai-loading{ color:#3c434a; }
        .dh-ai-msg.dh-ai-error{ color:#b32d2e; }
        .dh-ai-msg a{ text-decoration:none; }
        </style>';

        echo '<table class="widefat striped dh-elm-table"><thead><tr>';
        echo '<th class="dh-sort dh-col-id" data-key="id" style="cursor:pointer;">' . esc_html__('ID', 'directory-helpers') . '</th>';
        echo '<th class="dh-col-actions">' . esc_html__('Actions', 'directory-helpers') . '</th>';
        echo '<th class="dh-sort" data-key="anchor" style="cursor:pointer;">' . esc_html__('Anchor', 'directory-helpers') . '</th>';
        echo '<th class="dh-sort" data-key="url" style="cursor:pointer;">' . esc_html__('URL', 'directory-helpers') . '</th>';
        echo '<th class="dh-sort" data-key="status" style="width:110px; cursor:pointer;">' . esc_html__('Status', 'directory-helpers') . '</th>';
        echo '<th class="dh-sort" data-key="checked" style="width:140px; cursor:pointer;">' . esc_html__('Last checked', 'directory-helpers') . '</th>';
        echo '</tr></thead><tbody>';
        foreach ($rows as $r) {
            $now_ts = time();
            $ovr_code = isset($r->status_override_code) ? (int)$r->status_override_code : null;
            $ovr_exp = isset($r->status_override_expires) && $r->status_override_expires ? strtotime((string)$r->status_override_expires) : 0;
            $ovr_active = ($ovr_code && $ovr_exp && $ovr_exp > $now_ts);
            $status_val = is_null($r->status_code) ? null : (int)$r->status_code;
            $status_disp = $ovr_active ? $ovr_code : ($status_val === null ? '—' : $status_val);
            $eff_code = $ovr_active ? (int)$ovr_code : (int)($status_val === null ? 0 : $status_val);
            $row_class = '';
            if ($eff_code === 200) { $row_class = 'dh-status-ok'; }
            else if ($eff_code >= 400 && $eff_code < 500) { $row_class = 'dh-status-4xx'; }
            else if ($eff_code === 0) { $row_class = 'dh-status-0'; }
            $status_title_text = $r->status_text ?: '';
            if ($ovr_active) {
                $status_title_text = trim(($status_title_text ? ($status_title_text . ' | ') : '') . 'Override until ' . wp_date('Y-m-d', $ovr_exp));
            }
            $status_title = $status_title_text ? ' title="' . esc_attr($status_title_text) . '"' : '';
            $last_checked = '—';
            if ($r->last_checked) {
                $ts = strtotime($r->last_checked);
                if ($ts) { $last_checked = esc_html(wp_date('Y-m-d g:ia', $ts)); }
            }
            $anchor_disp = esc_html(mb_strimwidth((string)$r->anchor_text, 0, 80, '…'));
            $post_title_for_search = get_the_title($post);
            $search_query = trim(((string)$r->anchor_text) . ' ' . (string)$post_title_for_search);
            $anchor_q = rawurlencode($search_query);
            $anchor_link = '<a href="https://www.google.com/search?q=' . $anchor_q . '" target="_blank" rel="noopener">' . $anchor_disp . '</a>';
            $url_disp = esc_html((string)$r->current_url);
            $data_anchor = esc_attr(strtolower((string)$r->anchor_text));
            $data_url = esc_attr(strtolower((string)$r->current_url));
            // Use effective status for data-status and coloring
            $data_status = (int)$eff_code;
            $data_checked = $r->last_checked ? (int) strtotime($r->last_checked) : 0;
            $data_ovr = $ovr_active ? 1 : 0;
            $data_ovrexp = $ovr_active ? $ovr_exp : 0;
            echo '<tr class="' . esc_attr($row_class) . '" data-link-id="' . (int)$r->id . '" data-anchor="' . $data_anchor . '" data-url="' . $data_url . '" data-status="' . (int)$data_status . '" data-checked="' . (int)$data_checked . '" data-override="' . (int)$data_ovr . '" data-override-expires="' . (int)$data_ovrexp . '">';
            echo '<td class="dh-cell-id">' . (int)$r->id . '</td>';
            echo '<td class="dh-elm-manage">'
                . '<button type="button" class="button button-small dh-elm-delete" data-nonce="' . esc_attr($manage_nonce) . '">Delete</button> '
                . '<button type="button" class="button button-small dh-elm-edit" data-nonce="' . esc_attr($manage_nonce) . '">Edit</button>'
                . ' <button type="button" class="button button-small dh-elm-ai-suggest" data-nonce-ai="' . esc_attr($ai_nonce) . '">AI Suggest</button>'
                . '</td>';
            echo '<td class="dh-cell-anchor">' . $anchor_link . '</td>';
            echo '<td class="dh-cell-url" style="word-break:break-all; cursor:pointer;">' . $url_disp . '</td>';
            echo '<td class="dh-elm-status"' . $status_title . '><span class="dh-status-code">' . esc_html($status_disp) . '</span> '
                . '<button type="button" class="button button-small dh-elm-recheck" data-nonce="' . esc_attr($nonce) . '">Re-check</button> '
                . ($ovr_active
                    ? '<button type="button" class="button button-small dh-elm-override-clear" data-nonce="' . esc_attr($manage_nonce) . '">Clear override</button>'
                    : '<button type="button" class="button button-small dh-elm-override-ok" data-nonce="' . esc_attr($manage_nonce) . '">Mark OK (30d)</button>'
                  )
                . '</td>';
            echo '<td class="dh-elm-last-checked">' . $last_checked . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
        echo '<p style="margin-top:8px;color:#666;">' . esc_html__('First occurrence of a URL is stored and converted to a shortcode. Subsequent occurrences are unlinked and not stored as records.', 'directory-helpers') . '</p>';

        // Inline JS for per-link recheck
        ?>
        <script type="text/javascript">
        (function(){
            var dhPostTitle = '<?php echo esc_js(get_the_title($post)); ?>';
            function setRowVisual(tr, effectiveCode){
                if(!tr) return;
                tr.classList.remove('dh-status-ok','dh-status-4xx','dh-status-0');
                var cls = '';
                if(effectiveCode === 200){ cls = 'dh-status-ok'; }
                else if(effectiveCode >= 400 && effectiveCode < 500){ cls = 'dh-status-4xx'; }
                else if(effectiveCode === 0){ cls = 'dh-status-0'; }
                if(cls){ tr.classList.add(cls); }
                tr.setAttribute('data-status', String(effectiveCode||0));
            }

            function setStatusCell(tr, code, title){
                var cell = tr && tr.querySelector && tr.querySelector('.dh-elm-status');
                if(!cell) return;
                var span = cell.querySelector('.dh-status-code');
                if(!span){
                    span = document.createElement('span');
                    span.className = 'dh-status-code';
                    cell.insertBefore(span, cell.firstChild);
                }
                span.textContent = String(code);
                if(title){ cell.setAttribute('title', title); } else { cell.removeAttribute('title'); }
            }

            function onClick(e){
                // Initial sort by ID asc (already default), but ensure dataset present
            sortRows(currentSortKey);

            // Auto-run AI Suggest on page load for non-200 rows without a suggestion
            function autoSuggestNon200(){
                var rows = Array.prototype.slice.call(document.querySelectorAll('.dh-elm-table tbody tr'));
                var queue = rows.filter(function(tr){
                    var status = parseInt(tr.getAttribute('data-status')||'0',10);
                    var ovr = tr.getAttribute('data-override') === '1';
                    if(ovr) return false; // skip overrides
                    if(status === 200) return false;
                    var msg = tr.querySelector('.dh-ai-msg');
                    if(!msg) return true;
                    var txt = (msg.textContent || '').trim().toLowerCase();
                    return !txt || txt === 'no suggestion';
                });
                var i = 0;
                function next(){
                    if(i >= queue.length) return;
                    var tr = queue[i++];
                    var btn = tr.querySelector('.dh-elm-ai-suggest');
                    if(btn){ btn.click(); }
                    setTimeout(next, 600); // stagger requests to be friendly to API
                }
                if(queue.length){ next(); }
            }
            autoSuggestNon200();

                // Open all 200 links button
                var openBtn = e.target && e.target.closest && e.target.closest('.dh-elm-open-200');
                if(openBtn){
                    e.preventDefault();
                    var rows = document.querySelectorAll('.dh-elm-table tbody tr');
                    if(!rows || !rows.length){ return; }
                    rows.forEach(function(tr){
                        var status = parseInt(tr.getAttribute('data-status')||'0',10);
                        if(status === 200){
                            var cell = tr.querySelector('.dh-cell-url');
                            var href = cell ? (cell.textContent || '').trim() : '';
                            if(/^https?:\/\//i.test(href)){ window.open(href, '_blank'); }
                        }
                    });
                    return;
                }

                // Override buttons
                var ovrSetBtn = e.target && e.target.closest && e.target.closest('.dh-elm-override-ok');
                if(ovrSetBtn){
                    e.preventDefault();
                    var tr = ovrSetBtn.closest('tr'); if(!tr){ return; }
                    var id = tr.getAttribute('data-link-id'); if(!id){ return; }
                    ovrSetBtn.disabled = true; ovrSetBtn.textContent = 'Marking…';
                    var fd = new FormData();
                    fd.append('action','dh_elm_set_override');
                    fd.append('mode','set');
                    fd.append('code','200');
                    fd.append('days','30');
                    fd.append('id', id);
                    fd.append('post_id', <?php echo (int) $post->ID; ?>);
                    fd.append('_ajax_nonce', ovrSetBtn.getAttribute('data-nonce'));
                    var xhr = new XMLHttpRequest();
                    xhr.open('POST', (window.ajaxurl || '<?php echo admin_url('admin-ajax.php'); ?>'));
                    xhr.onload = function(){
                        ovrSetBtn.disabled = false; ovrSetBtn.textContent = 'Mark OK (30d)';
                        try {
                            var res = JSON.parse(xhr.responseText);
                            if(res && res.success && res.data){
                                setStatusCell(tr, res.data.status_code, res.data.status_title || '');
                                var lc = tr.querySelector('.dh-elm-last-checked'); if(lc){ lc.textContent = res.data.last_checked_display || '—'; }
                                tr.setAttribute('data-override','1');
                                tr.setAttribute('data-override-expires', String(res.data.override_expires_ts||0));
                                // Swap buttons in Status cell
                                var statusCell = tr.querySelector('.dh-elm-status');
                                if(statusCell){
                                    var btn = statusCell.querySelector('.dh-elm-override-ok');
                                    if(btn){ btn.classList.remove('dh-elm-override-ok'); btn.classList.add('dh-elm-override-clear'); btn.textContent = 'Clear override'; }
                                    else {
                                        var newBtn = document.createElement('button');
                                        newBtn.type = 'button'; newBtn.className = 'button button-small dh-elm-override-clear';
                                        newBtn.setAttribute('data-nonce', ovrSetBtn.getAttribute('data-nonce'));
                                        newBtn.textContent = 'Clear override';
                                        statusCell.appendChild(document.createTextNode(' '));
                                        statusCell.appendChild(newBtn);
                                    }
                                }
                                setRowVisual(tr, 200);
                            } else { alert('Override failed'); }
                        } catch(err){ alert('Override failed'); }
                    };
                    xhr.onerror = function(){ ovrSetBtn.disabled = false; ovrSetBtn.textContent = 'Mark OK (30d)'; alert('Network error'); };
                    xhr.send(fd);
                    return;
                }

                var ovrClrBtn = e.target && e.target.closest && e.target.closest('.dh-elm-override-clear');
                if(ovrClrBtn){
                    e.preventDefault();
                    var tr = ovrClrBtn.closest('tr'); if(!tr){ return; }
                    var id = tr.getAttribute('data-link-id'); if(!id){ return; }
                    ovrClrBtn.disabled = true; ovrClrBtn.textContent = 'Clearing…';
                    var fd = new FormData();
                    fd.append('action','dh_elm_set_override');
                    fd.append('mode','clear');
                    fd.append('id', id);
                    fd.append('post_id', <?php echo (int) $post->ID; ?>);
                    fd.append('_ajax_nonce', ovrClrBtn.getAttribute('data-nonce'));
                    var xhr = new XMLHttpRequest();
                    xhr.open('POST', (window.ajaxurl || '<?php echo admin_url('admin-ajax.php'); ?>'));
                    xhr.onload = function(){
                        ovrClrBtn.disabled = false; ovrClrBtn.textContent = 'Clear override';
                        try {
                            var res = JSON.parse(xhr.responseText);
                            if(res && res.success && res.data){
                                setStatusCell(tr, res.data.status_code_disp, res.data.status_title || '');
                                tr.setAttribute('data-override','0');
                                tr.setAttribute('data-override-expires', '0');
                                // Swap buttons in Status cell
                                var statusCell = tr.querySelector('.dh-elm-status');
                                if(statusCell){
                                    var btn = statusCell.querySelector('.dh-elm-override-clear');
                                    if(btn){ btn.classList.remove('dh-elm-override-clear'); btn.classList.add('dh-elm-override-ok'); btn.textContent = 'Mark OK (30d)'; }
                                    else {
                                        var newBtn = document.createElement('button');
                                        newBtn.type = 'button'; newBtn.className = 'button button-small dh-elm-override-ok';
                                        newBtn.setAttribute('data-nonce', ovrClrBtn.getAttribute('data-nonce'));
                                        newBtn.textContent = 'Mark OK (30d)';
                                        statusCell.appendChild(document.createTextNode(' '));
                                        statusCell.appendChild(newBtn);
                                    }
                                }
                                var eff = parseInt(res.data.status_code_disp,10) || 0;
                                setRowVisual(tr, eff);
                            } else { alert('Clear override failed'); }
                        } catch(err){ alert('Clear override failed'); }
                    };
                    xhr.onerror = function(){ ovrClrBtn.disabled = false; ovrClrBtn.textContent = 'Clear override'; alert('Network error'); };
                    xhr.send(fd);
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
                                var now = Math.floor(Date.now()/1000);
                                var ovActive = (tr.getAttribute('data-override') === '1') && (parseInt(tr.getAttribute('data-override-expires')||'0',10) > now);
                                var code = parseInt(res.data.status_code,10) || 0;
                                var eff = ovActive ? 200 : code;
                                var title = res.data.status_text || '';
                                if(ovActive){
                                    var exp = parseInt(tr.getAttribute('data-override-expires')||'0',10);
                                    if(exp){
                                        var d = new Date(exp*1000);
                                        var y = d.getFullYear(); var m = ('0'+(d.getMonth()+1)).slice(-2); var da = ('0'+d.getDate()).slice(-2);
                                        title = (title ? (title + ' | ') : '') + 'Override until ' + y + '-' + m + '-' + da;
                                    }
                                }
                                setStatusCell(tr, eff, title);
                                tr.querySelector('.dh-elm-last-checked').textContent = res.data.last_checked_display || '—';
                                // update sort/visual attributes
                                setRowVisual(tr, eff);
                                tr.setAttribute('data-checked', String(Math.floor(Date.now()/1000)));
                                // ensure button presence in status cell matches override state
                                var statusCell = tr.querySelector('.dh-elm-status');
                                if(statusCell){
                                    var hasClear = statusCell.querySelector('.dh-elm-override-clear');
                                    var hasOk = statusCell.querySelector('.dh-elm-override-ok');
                                    if(ovActive){
                                        if(!hasClear){
                                            if(hasOk){ hasOk.classList.remove('dh-elm-override-ok'); hasOk.classList.add('dh-elm-override-clear'); hasOk.textContent = 'Clear override'; }
                                            else {
                                                var b = document.createElement('button'); b.type='button'; b.className='button button-small dh-elm-override-clear'; b.setAttribute('data-nonce', reBtn.getAttribute('data-nonce')); b.textContent='Clear override'; statusCell.appendChild(document.createTextNode(' ')); statusCell.appendChild(b);
                                            }
                                        }
                                    } else {
                                        if(!hasOk){
                                            if(hasClear){ hasClear.classList.remove('dh-elm-override-clear'); hasClear.classList.add('dh-elm-override-ok'); hasClear.textContent = 'Mark OK (30d)'; }
                                            else {
                                                var b2 = document.createElement('button'); b2.type='button'; b2.className='button button-small dh-elm-override-ok'; b2.setAttribute('data-nonce', reBtn.getAttribute('data-nonce')); b2.textContent='Mark OK (30d)'; statusCell.appendChild(document.createTextNode(' ')); statusCell.appendChild(b2);
                                            }
                                        }
                                    }
                                }
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
                                // After scan completes, reload to reflect new rows; onload hook will auto-suggest for non-200
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

                // AI Suggest button
                var aiBtn = e.target && e.target.closest && e.target.closest('.dh-elm-ai-suggest');
                if(aiBtn){
                    e.preventDefault();
                    var tr = aiBtn.closest('tr');
                    if(!tr){ return; }
                    var id = tr.getAttribute('data-link-id'); if(!id){ return; }
                    aiBtn.disabled = true; var oldTxt = aiBtn.textContent; aiBtn.textContent = 'AI suggesting…';
                    var actionsCell = tr.querySelector('.dh-elm-manage');
                    var msg = actionsCell ? actionsCell.querySelector('.dh-ai-msg') : null;
                    if(!msg && actionsCell){ msg = document.createElement('span'); msg.className = 'dh-ai-msg dh-ai-loading'; msg.textContent = 'AI suggesting…'; actionsCell.appendChild(document.createTextNode(' ')); actionsCell.appendChild(msg); }
                    else if(msg){ msg.className = 'dh-ai-msg dh-ai-loading'; msg.textContent = 'AI suggesting…'; }
                    var fdA = new FormData();
                    fdA.append('action','dh_elm_ai_suggest_link');
                    fdA.append('id', id);
                    fdA.append('post_id', <?php echo (int) $post->ID; ?>);
                    fdA.append('_ajax_nonce', aiBtn.getAttribute('data-nonce-ai'));
                    var xhrA = new XMLHttpRequest();
                    xhrA.open('POST', (window.ajaxurl || '<?php echo admin_url('admin-ajax.php'); ?>'));
                    xhrA.onload = function(){
                        aiBtn.disabled = false; aiBtn.textContent = oldTxt;
                        var txt = xhrA.responseText || '';
                        if(txt === '-1'){ if(msg){ msg.className='dh-ai-msg dh-ai-error'; msg.textContent='Security check failed. Refresh and try again.'; } return; }
                        var res = null; try { res = JSON.parse(txt); } catch(e){}
                        if(res && res.success && res.data){
                            var sUrl = (res.data.suggested_url || '').trim();
                            if(msg){
                                if(sUrl){
                                    msg.className = 'dh-ai-msg';
                                    msg.innerHTML = 'Suggested: <a href="'+ sUrl.replace(/"/g,'&quot;') +'" target="_blank" rel="noopener">'+ sUrl.replace(/&/g,'&amp;').replace(/</g,'&lt;') +'</a>';
                                } else {
                                    msg.className = 'dh-ai-msg dh-ai-error';
                                    msg.textContent = 'No suggestion returned';
                                }
                            }
                            if(sUrl){
                                // Add/ensure Apply button
                                var actionsCell2 = tr.querySelector('.dh-elm-manage');
                                if(actionsCell2){
                                    var applyBtn = actionsCell2.querySelector('.dh-elm-ai-apply');
                                    if(!applyBtn){
                                        applyBtn = document.createElement('button');
                                        applyBtn.type = 'button'; applyBtn.className = 'button button-small dh-elm-ai-apply';
                                        applyBtn.textContent = 'AI Replace';
                                        applyBtn.setAttribute('data-nonce-ai', aiBtn.getAttribute('data-nonce-ai'));
                                        actionsCell2.appendChild(document.createTextNode(' '));
                                        actionsCell2.appendChild(applyBtn);
                                    }
                                    applyBtn.setAttribute('data-suggested-url', sUrl);
                                }
                            }
                        } else {
                            var err = (res && res.data && res.data.message) ? res.data.message : 'AI suggest failed';
                            if(msg){ msg.className = 'dh-ai-msg dh-ai-error'; msg.textContent = err; }
                        }
                    };
                    xhrA.onerror = function(){ aiBtn.disabled = false; aiBtn.textContent = oldTxt; if(msg){ msg.className='dh-ai-msg dh-ai-error'; msg.textContent='Network error'; } };
                    xhrA.send(fdA);
                    return;
                }

                // AI Apply button
                var aiApply = e.target && e.target.closest && e.target.closest('.dh-elm-ai-apply');
                if(aiApply){
                    e.preventDefault();
                    var tr = aiApply.closest('tr'); if(!tr){ return; }
                    var id = tr.getAttribute('data-link-id'); if(!id){ return; }
                    aiApply.disabled = true; var old = aiApply.textContent; aiApply.textContent = 'Replacing…';
                    var actionsCell = tr.querySelector('.dh-elm-manage');
                    var msg = actionsCell ? actionsCell.querySelector('.dh-ai-msg') : null;
                    if(!msg && actionsCell){ msg = document.createElement('span'); msg.className = 'dh-ai-msg dh-ai-loading'; msg.textContent = 'Replacing…'; actionsCell.appendChild(document.createTextNode(' ')); actionsCell.appendChild(msg); }
                    else if(msg){ msg.className = 'dh-ai-msg dh-ai-loading'; msg.textContent = 'Replacing…'; }
                    var fdB = new FormData();
                    fdB.append('action','dh_elm_ai_apply_suggestion');
                    fdB.append('id', id);
                    fdB.append('post_id', <?php echo (int) $post->ID; ?>);
                    fdB.append('_ajax_nonce', aiApply.getAttribute('data-nonce-ai'));
                    var xhrB = new XMLHttpRequest();
                    xhrB.open('POST', (window.ajaxurl || '<?php echo admin_url('admin-ajax.php'); ?>'));
                    xhrB.onload = function(){
                        aiApply.disabled = false; aiApply.textContent = old;
                        var txt = xhrB.responseText || '';
                        if(txt === '-1'){ if(msg){ msg.className='dh-ai-msg dh-ai-error'; msg.textContent='Security check failed. Refresh and try again.'; } return; }
                        var res = null; try { res = JSON.parse(txt); } catch(e){}
                        if(res && res.success && res.data){
                            // Update UI similar to manual Save path
                            var urlCell = tr.querySelector('.dh-cell-url');
                            var anchorCell = tr.querySelector('.dh-cell-anchor');
                            var updatedAnchor = res.data.anchor_text || (anchorCell ? anchorCell.textContent.trim() : '');
                            var updatedUrl = res.data.current_url || '';
                            if(anchorCell && updatedAnchor){
                                anchorCell.innerHTML = '';
                                var a = document.createElement('a');
                                a.href = 'https://www.google.com/search?q=' + encodeURIComponent((updatedAnchor ? (updatedAnchor + ' ') : '') + dhPostTitle);
                                a.target = '_blank'; a.rel = 'noopener'; a.textContent = updatedAnchor; anchorCell.appendChild(a);
                            }
                            if(urlCell && updatedUrl){ urlCell.textContent = updatedUrl; }
                            var statusCell = tr.querySelector('.dh-elm-status');
                            var newCode = (res.data.status_code != null ? parseInt(res.data.status_code,10) : 0) || 0;
                            setStatusCell(tr, newCode, res.data.status_title || '');
                            setRowVisual(tr, newCode);
                            var lcCell = tr.querySelector('.dh-elm-last-checked'); if(lcCell){ lcCell.textContent = res.data.last_checked_display || '—'; }
                            if(msg){ msg.className='dh-ai-msg'; msg.textContent='Replaced'; }
                            // Remove the AI Replace button after successful replacement
                            var aiApplyBtn = tr.querySelector('.dh-elm-ai-apply');
                            if(aiApplyBtn) { aiApplyBtn.remove(); }
                        } else {
                            var err = (res && res.data && res.data.message) ? res.data.message : 'AI replace failed';
                            if(msg){ msg.className='dh-ai-msg dh-ai-error'; msg.textContent=err; }
                        }
                    };
                    xhrB.onerror = function(){ aiApply.disabled = false; aiApply.textContent = old; if(msg){ msg.className='dh-ai-msg dh-ai-error'; msg.textContent='Network error'; } };
                    xhrB.send(fdB);
                    return;
                }

                // Click URL cell -> enter edit and select URL input
                var urlCellClick = e.target && e.target.closest && e.target.closest('.dh-cell-url');
                if(urlCellClick){
                    e.preventDefault();
                    var tr = urlCellClick.closest('tr');
                    if(!tr){ return; }
                    if(tr.classList.contains('editing')){ return; }
                    var btn = tr.querySelector('.dh-elm-edit');
                    if(btn){
                        btn.click();
                        setTimeout(function(){
                            var inp = tr.querySelector('.dh-cell-url input');
                            if(inp){ inp.focus(); inp.select(); }
                        }, 0);
                    }
                    return;
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
                    var currentUrl = urlCell ? (urlCell.textContent || '').trim() : '';
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

                    // Keyboard: Enter saves, Escape cancels (and do not submit the post form)
                    function handleEditKey(e){
                        var k = e.key || e.keyCode;
                        if(k === 'Enter' || k === 13){ e.preventDefault(); e.stopPropagation(); saveBtn.click(); }
                        else if(k === 'Escape' || k === 'Esc' || k === 27){ e.preventDefault(); e.stopPropagation(); cancelBtn.click(); }
                    }
                    aInput.addEventListener('keydown', handleEditKey);
                    uInput.addEventListener('keydown', handleEditKey);
                    saveBtn.addEventListener('keydown', handleEditKey);
                    cancelBtn.addEventListener('keydown', handleEditKey);

                    // Global ESC while editing: cancel
                    var docEscHandler = function(e){
                        var k = e.key || e.keyCode;
                        if(k === 'Escape' || k === 'Esc' || k === 27){ e.preventDefault(); e.stopPropagation(); cancelBtn.click(); }
                    };
                    document.addEventListener('keydown', docEscHandler, true);

                    cancelBtn.addEventListener('click', function(){
                        tr.classList.remove('editing');
                        document.removeEventListener('keydown', docEscHandler, true);
                        // Restore view
                        anchorCell.textContent = currentAnchorText;
                        if(currentAnchorText){
                            var a = document.createElement('a');
                            a.href = 'https://www.google.com/search?q=' + encodeURIComponent((currentAnchorText ? (currentAnchorText + ' ') : '') + dhPostTitle);
                            a.target = '_blank'; a.rel = 'noopener'; a.textContent = currentAnchorText;
                            anchorCell.innerHTML = ''; anchorCell.appendChild(a);
                        }
                        urlCell.innerHTML = '';
                        urlCell.textContent = currentUrl;
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
                                    var a = document.createElement('a'); a.href = 'https://www.google.com/search?q=' + encodeURIComponent((updatedAnchor ? (updatedAnchor + ' ') : '') + dhPostTitle); a.target = '_blank'; a.rel = 'noopener'; a.textContent = updatedAnchor; anchorCell.appendChild(a);
                                }
                                urlCell.innerHTML = '';
                                if(updatedUrl){ urlCell.textContent = updatedUrl; }
                                // Update Status and Last checked cells
                                var statusCell = tr.querySelector('.dh-elm-status');
                                var newCode = (res.data.status_code != null ? parseInt(res.data.status_code,10) : 0) || 0;
                                setStatusCell(tr, newCode, '');
                                setRowVisual(tr, newCode);
                                // ensure Mark OK button exists after save (no override state change here)
                                if(statusCell){
                                    var btn = statusCell.querySelector('.dh-elm-override-ok, .dh-elm-override-clear');
                                    if(!btn){
                                        var mk = document.createElement('button'); mk.type='button'; mk.className='button button-small dh-elm-override-ok';
                                        mk.setAttribute('data-nonce', actionsCell.querySelector('[data-nonce]') ? actionsCell.querySelector('[data-nonce]').getAttribute('data-nonce') : '');
                                        mk.textContent='Mark OK (30d)'; statusCell.appendChild(document.createTextNode(' ')); statusCell.appendChild(mk);
                                    }
                                }
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
                                document.removeEventListener('keydown', docEscHandler, true);
                                actionsCell.innerHTML = actionsCell.getAttribute('data-orig') || '';
                                // Automatically trigger a re-check for this row after saving
                                var reAfterSave = tr.querySelector('.dh-elm-recheck');
                                if(reAfterSave){ reAfterSave.click(); }
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
        $row = $wpdb->get_row($wpdb->prepare("SELECT current_url, anchor_text, is_duplicate, status_code, status_override_code, status_override_expires FROM {$table} WHERE id=%d", $id));
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
        // Only render hyperlink when effective status is 200 (consider active overrides)
        $effective = 0;
        $base = isset($row->status_code) ? (int)$row->status_code : 0;
        $ov_code = isset($row->status_override_code) ? (int)$row->status_override_code : 0;
        $ov_exp  = isset($row->status_override_expires) && $row->status_override_expires ? strtotime((string)$row->status_override_expires) : 0;
        $now_ts = current_time('timestamp');
        if ($ov_code && $ov_exp && $ov_exp > $now_ts) {
            $effective = (int)$ov_code;
        } else {
            $effective = $base;
        }
        if ($effective === 200) {
            // Default attributes: open in new tab with safe rel including nofollow
            return '<a href="' . $url . '" target="_blank" rel="noopener noreferrer nofollow">' . $anchor . '</a>';
        }
        return $anchor;
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
            'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36',
            'referer'    => home_url('/'),
            'accept'     => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'accept-language' => 'en-US,en;q=0.9',
            'cache-control' => 'no-cache',
            'pragma' => 'no-cache',
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
                // If still 403, try alternate headers (no referer)
                if ($code === 403) {
                    $alt_args = $base_args;
                    unset($alt_args['headers']['referer']);
                    $alt_args['headers']['accept-language'] = 'en-US,en;q=0.9';
                    $resp = wp_remote_get($url, $alt_args);
                    if (!is_wp_error($resp)) {
                        $code = (int) wp_remote_retrieve_response_code($resp);
                        $text = (string) wp_remote_retrieve_response_message($resp);
                    }
                }
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
        $code = (int) wp_remote_retrieve_response_code($resp);
        $text = (string) wp_remote_retrieve_response_message($resp);
        if ($code === 403) {
            // Try alternate headers typical of browsers and no referer
            $alt_args = $base_args;
            unset($alt_args['headers']['referer']);
            $alt_args['headers']['accept-language'] = 'en-US,en;q=0.9';
            $resp2 = wp_remote_get($url, $alt_args);
            if (!is_wp_error($resp2)) {
                $code = (int) wp_remote_retrieve_response_code($resp2);
                $text = (string) wp_remote_retrieve_response_message($resp2);
            }
            // If still 403, try referer=self
            if ($code === 403) {
                $alt2 = $base_args;
                $alt2['headers']['referer'] = $url;
                $resp3 = wp_remote_get($url, $alt2);
                if (!is_wp_error($resp3)) {
                    $code = (int) wp_remote_retrieve_response_code($resp3);
                    $text = (string) wp_remote_retrieve_response_message($resp3);
                }
            }
        }
        return array($code, $text);
    }

    /**
     * Validate that a suggested URL is a direct, usable destination (not a Google/Vertex redirect, shortener, or tracker)
     */
    private function is_blocked_suggestion_url($url) {
        if (!is_string($url) || $url === '') { return true; }
        $parts = wp_parse_url($url);
        if (!$parts || empty($parts['host'])) { return true; }
        $host = strtolower($parts['host']);
        $path = isset($parts['path']) ? strtolower($parts['path']) : '';
        $query = isset($parts['query']) ? strtolower($parts['query']) : '';

        // Hard blocklist of hosts we never want to accept
        $blocked_hosts = array(
            'vertexaisearch.cloud.google.com',
            'cloud.google.com', 'console.cloud.google.com',
            'google.com', 'www.google.com', 'news.google.com', 'maps.google.com', 'support.google.com', 'developers.google.com', 'accounts.google.com',
            'googleusercontent.com', 'www.googleusercontent.com',
            'g.co', 'goo.gl', 'bit.ly', 't.co'
        );
        if (in_array($host, $blocked_hosts, true)) { return true; }
        // Any subdomain of google.com or googleusercontent.com
        if (preg_match('/(^|\.)google\.com$/', $host) || preg_match('/(^|\.)googleusercontent\.com$/', $host)) { return true; }
        if (preg_match('/(^|\.)cloud\.google\.com$/', $host)) { return true; }

        // Block common redirect/tracker paths or query params
        if (strpos($path, '/url') !== false || strpos($path, 'grounding-api-redirect') !== false) { return true; }
        if (strpos($path, '/search') !== false || strpos($path, '/aclk') !== false) { return true; }
        if (strpos($query, 'q=') !== false || strpos($query, 'url=') !== false) { return true; }

        // Must be http(s)
        $scheme = isset($parts['scheme']) ? strtolower($parts['scheme']) : '';
        if ($scheme !== 'http' && $scheme !== 'https') { return true; }

        return false; // not blocked
    }

    private function check_links_for_post($post_id) {
        global $wpdb;
        $table = $this->table_name();
        $rows = $wpdb->get_results($wpdb->prepare("SELECT id, current_url, status_override_code, status_override_expires FROM {$table} WHERE post_id=%d", $post_id));
        if (!$rows) { return; }
        $timeout = 10; // per request
        
        // Track non-200 URLs for AI suggestions
        $non_200_urls = array();
        
        foreach ($rows as $r) {
            $url = (string)$r->current_url;
            if (!$url) { continue; }
            // Skip automated checks if override is active
            $ovr_exp = isset($r->status_override_expires) && $r->status_override_expires ? strtotime((string)$r->status_override_expires) : 0;
            $ovr_code = isset($r->status_override_code) ? (int)$r->status_override_code : 0;
            if ($ovr_code && $ovr_exp && $ovr_exp > time()) { continue; }
            
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
            
            // Queue non-200 URLs for AI suggestions
            if ($code !== 200) {
                $non_200_urls[] = array(
                    'id' => $r->id,
                    'url' => $url,
                    'post_id' => $post_id
                );
            }
        }
        
        // Process AI suggestions for non-200 URLs
        if (!empty($non_200_urls)) {
            foreach ($non_200_urls as $link) {
                // Get the full row data needed for AI suggestion
                $row = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM {$table} WHERE id = %d AND post_id = %d",
                    $link['id'],
                    $post_id
                ));
                
                if ($row) {
            // Call the AI suggestion function (DataForSEO)
            $suggestion = $this->call_ai_for_link($post_id, $row);
                    
                    // If we got a suggestion, update the record
                    if (!is_wp_error($suggestion) && !empty($suggestion['suggested_url'])) {
                        $wpdb->update(
                            $table,
                            array(
                                'ai_suggestion_url' => $suggestion['suggested_url'],
                                'updated_at' => current_time('mysql'),
                            ),
                            array('id' => $link['id']),
                            array('%s', '%s'),
                            array('%d')
                        );
                    }
                }
                
                // Add a small delay between API calls to avoid rate limiting
                if (count($non_200_urls) > 1) {
                    usleep(500000); // 0.5 second delay
                }
            }
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

    private function get_dataforseo_credentials() {
        $opts = get_option('directory_helpers_options');
        $login = '';
        $password = '';
        if (is_array($opts)) {
            if (!empty($opts['dataforseo_login'])) { $login = (string) $opts['dataforseo_login']; }
            if (!empty($opts['dataforseo_password'])) { $password = (string) $opts['dataforseo_password']; }
        }
        return array('login' => $login, 'password' => $password);
    }

    private function call_dataforseo_for_link($post_id, $row) {
        // Build the keyword from anchor text and post title
        $post = get_post($post_id);
        if (!$post) {
            return new WP_Error('no_post', 'Post not found.');
        }
        $anchor = trim((string)($row->anchor_text ?? ''));
        $title  = get_the_title($post_id);
        $keyword = trim($anchor . ' ' . $title);
        if ($keyword === '') {
            return new WP_Error('empty_keyword', 'No keyword to search (missing anchor text and title).');
        }

        // Credentials
        $creds = $this->get_dataforseo_credentials();
        $login = isset($creds['login']) ? (string) $creds['login'] : '';
        $password = isset($creds['password']) ? (string) $creds['password'] : '';
        if ($login === '' || $password === '') {
            return new WP_Error('no_dataforseo_creds', 'DataForSEO credentials are not configured.');
        }

        // Endpoint and payload per DataForSEO Google Organic Live Advanced
        $endpoint = 'https://api.dataforseo.com/v3/serp/google/organic/live/advanced';
        $lang = substr(get_locale(), 0, 2) ?: 'en';
        $payload = array(
            array(
                'language_code' => $lang,
                'location_code' => 2840, // United States
                'keyword' => $keyword,
                'device' => 'desktop',
                'os' => 'windows',
                'depth' => 20,
            )
        );

        $args = array(
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode($login . ':' . $password),
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ),
            'timeout' => 30,
            'sslverify' => true,
            'httpversion' => '1.1',
            'blocking' => true,
            'body' => wp_json_encode($payload),
        );

        $response = wp_remote_post($endpoint, $args);
        if (is_wp_error($response)) {
            return new WP_Error('http_request_failed', 'Failed to connect to DataForSEO API: ' . $response->get_error_message());
        }
        $rc = (int) wp_remote_retrieve_response_code($response);
        if ($rc < 200 || $rc >= 300) {
            return new WP_Error('http_error', sprintf('DataForSEO API request failed with code %d: %s', $rc, wp_remote_retrieve_response_message($response)));
        }
        $body = (string) wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        if (!is_array($data)) {
            return new WP_Error('invalid_response', 'Invalid response from DataForSEO API');
        }

        // Parse results.
        // 1) Prefer the first safe organic result with rank_group = 1.
        // 2) If none, fall back to the best (lowest rank_group) safe organic result.
        $best_fallback = null;
        $best_rank = PHP_INT_MAX;
        if (!empty($data['tasks']) && is_array($data['tasks'])) {
            foreach ($data['tasks'] as $task) {
                if (empty($task['result']) || !is_array($task['result'])) { continue; }
                foreach ($task['result'] as $res) {
                    if (empty($res['items']) || !is_array($res['items'])) { continue; }
                    foreach ($res['items'] as $item) {
                        $type = isset($item['type']) ? (string)$item['type'] : '';
                        $rank_group = isset($item['rank_group']) ? (int)$item['rank_group'] : 0;
                        $url = isset($item['url']) ? trim((string)$item['url']) : '';
                        if ($type !== 'organic' || !$url || !filter_var($url, FILTER_VALIDATE_URL)) { continue; }
                        if ($this->is_blocked_suggestion_url($url)) { continue; }
                        // Primary: rank_group === 1
                        if ($rank_group === 1) {
                            return array('suggested_url' => esc_url_raw($url));
                        }
                        // Fallback: track the best (lowest) rank_group seen
                        if ($rank_group > 0 && $rank_group < $best_rank) {
                            $best_rank = $rank_group;
                            $best_fallback = $url;
                        }
                    }
                }
            }
        }

        if ($best_fallback) {
            return array('suggested_url' => esc_url_raw($best_fallback));
        }

        return new WP_Error('no_match', 'No suitable organic result found from DataForSEO response.');
    }

    
    private function call_ai_for_link($post_id, $row) {
        // Delegates to DataForSEO for AI suggestion
        return $this->call_dataforseo_for_link($post_id, $row);
    }

    
    public function ajax_set_override() {
        $id = isset($_POST['id']) ? absint($_POST['id']) : 0;
        $post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;
        if (!$id || !$post_id) { wp_send_json_error(array('message' => 'missing params')); }
        check_ajax_referer('dh_elm_manage_' . $post_id);
        if (!current_user_can('edit_post', $post_id)) { wp_send_json_error(array('message' => 'forbidden')); }
        $mode = isset($_POST['mode']) ? sanitize_key((string)$_POST['mode']) : 'set';
        global $wpdb; $table = $this->table_name();
        $row = $wpdb->get_row($wpdb->prepare("SELECT id, status_code, status_text FROM {$table} WHERE id=%d AND post_id=%d", $id, $post_id));
        if (!$row) { wp_send_json_error(array('message' => 'not found')); }
        if ($mode === 'clear') {
            $wpdb->update($table, array(
                'status_override_code' => null,
                'status_override_expires' => null,
                'updated_at' => current_time('mysql'),
            ), array('id' => $id), array('%s','%s','%s'), array('%d'));
            $status_title = $row->status_text ? (string)$row->status_text : '';
            wp_send_json_success(array(
                'status_code_disp' => is_null($row->status_code) ? '—' : (int)$row->status_code,
                'status_title' => $status_title,
            ));
        }
        $code = isset($_POST['code']) ? intval($_POST['code']) : 200;
        if ($code < 100 || $code > 999) { $code = 200; }
        $days = isset($_POST['days']) ? intval($_POST['days']) : 30;
        if ($days < 1) { $days = 30; }
        $expires_ts = time() + ($days * DAY_IN_SECONDS);
        $expires = date_i18n('Y-m-d H:i:s', $expires_ts);
        $now = current_time('mysql');
        $wpdb->update($table, array(
            'status_override_code' => $code,
            'status_override_expires' => $expires,
            'status_code' => $code,
            'status_text' => 'OK (override)',
            'last_checked' => $now,
            'updated_at' => $now,
        ), array('id' => $id), array('%d','%s','%d','%s','%s','%s'), array('%d'));
        $status_title = 'Override until ' . date_i18n('Y-m-d', $expires_ts);
        wp_send_json_success(array(
            'status_code' => $code,
            'status_title' => $status_title,
            'last_checked_display' => date_i18n('Y-m-d g:ia', strtotime($now)),
            'override_expires_ts' => $expires_ts,
        ));
    }

    public function ajax_ai_suggest_link() {
        $id = isset($_POST['id']) ? absint($_POST['id']) : 0;
        $post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;
        if (!$id || !$post_id) { wp_send_json_error(array('message' => 'missing params')); }
        check_ajax_referer('dh_elm_ai_' . $post_id);
        if (!current_user_can('edit_post', $post_id)) { wp_send_json_error(array('message' => 'forbidden')); }
        global $wpdb; $table = $this->table_name();
        $row = $wpdb->get_row($wpdb->prepare("SELECT id, anchor_text, current_url, context_sentence FROM {$table} WHERE id=%d AND post_id=%d", $id, $post_id));
        if (!$row) { wp_send_json_error(array('message' => 'not found')); }

        // Use DataForSEO for link suggestion
        $ai = $this->call_ai_for_link($post_id, $row);
        if (is_wp_error($ai)) {
            wp_send_json_error(array('message' => $ai->get_error_message()));
        }

        $sug_url = isset($ai['suggested_url']) ? esc_url_raw($ai['suggested_url']) : '';
        $now = current_time('mysql');
        $wpdb->update($table, array(
            'ai_suggestion_url' => $sug_url ? $sug_url : null,
            'updated_at' => $now,
        ), array('id' => $id), array('%s','%s'), array('%d'));

        wp_send_json_success(array(
            'suggested_url' => $sug_url,
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
            wp_send_json_error(array('message' => 'PHP DOM extension is not available on this server. Unable to scan.' ));
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
            // Save updated content if changes were made
            wp_update_post(array('ID' => $post_id, 'post_content' => $updated));
        }

        // After scanning, recalc duplicates and perform HTTP status checks
        // plus AI suggestions for non-200 links server-side, so the full workflow
        // runs from this single action.
        $this->recalc_duplicates_for_post($post_id);
        $this->check_links_for_post($post_id);

        // Report whether content was updated
        wp_send_json_success(array('updated' => $updated !== false));
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

    public function ajax_ai_apply_suggestion() {
        $id = isset($_POST['id']) ? absint($_POST['id']) : 0;
        $post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;
        if (!$id || !$post_id) { wp_send_json_error(array('message' => 'missing params')); }
        check_ajax_referer('dh_elm_ai_' . $post_id);
        if (!current_user_can('edit_post', $post_id)) { wp_send_json_error(array('message' => 'forbidden')); }
        global $wpdb; $table = $this->table_name();
        $row = $wpdb->get_row($wpdb->prepare("SELECT id, anchor_text, ai_suggestion_url FROM {$table} WHERE id=%d AND post_id=%d", $id, $post_id));
        if (!$row) { wp_send_json_error(array('message' => 'not found')); }
        $sug = isset($row->ai_suggestion_url) ? esc_url_raw((string)$row->ai_suggestion_url) : '';
        if (!$sug || !preg_match('#^https?://#i', $sug)) { wp_send_json_error(array('message' => 'No valid AI suggestion to apply')); }
        // Block Google/search/redirect/shortener suggestions, ask user to re-run Suggest
        if ($this->is_blocked_suggestion_url($sug)) {
            wp_send_json_error(array('message' => 'AI suggestion points to a redirect/search URL. Please run AI Suggest again.'));
        }
        // Check status of suggested URL
        list($code, $text) = $this->http_check_url($sug, 10);
        $now = current_time('mysql');
        $wpdb->update(
            $table,
            array(
                'current_url' => $sug,
                'status_code' => $code,
                'status_text' => $text,
                'last_checked' => $now,
                'updated_at' => $now,
            ),
            array('id' => $id),
            array('%s','%d','%s','%s','%s'),
            array('%d')
        );
        // Make this row canonical for its URL; mark others as duplicates
        $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET is_duplicate=1, updated_at=%s WHERE post_id=%d AND id<>%d AND LOWER(current_url)=LOWER(%s)",
            $now, $post_id, $id, $sug
        ));
        $wpdb->update($table, array('is_duplicate' => 0, 'updated_at' => $now), array('id' => $id), array('%d','%s'), array('%d'));

        $last_checked_display = date_i18n('Y-m-d g:ia', strtotime($now));
        wp_send_json_success(array(
            'anchor_text' => (string)($row->anchor_text ?? ''),
            'current_url' => $sug,
            'status_code' => (int) $code,
            'status_title' => (string) $text,
            'last_checked_display' => $last_checked_display,
            'should_hide_button' => true // Flag to indicate the button should be hidden
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
