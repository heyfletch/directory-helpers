<div class="wrap">
    <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

    <?php settings_errors('directory_helpers_messages'); ?>

    <form method="post" action="">
        <?php
        // Nonce for security
        wp_nonce_field('directory_helpers_save_settings', 'directory_helpers_nonce');
        ?>

        <div class="directory-helpers-admin">
            <div class="directory-helpers-modules">
                <h2><?php esc_html_e('Available Modules', 'directory-helpers'); ?></h2>
                <p><?php esc_html_e('The following modules are automatically active and available for use.', 'directory-helpers'); ?></p>
                
                <table class="form-table">
                    <tbody>
                        <?php
                        $modules = Directory_Helpers::get_instance()->get_modules();
                        foreach ($modules as $module_id => $module) :
                        ?>
                            <tr>
                                <th scope="row">
                                    <strong><?php echo esc_html($module['name']); ?></strong>
                                </th>
                                <td>
                                    <p><?php echo esc_html($module['description']); ?></p>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php $options = get_option('directory_helpers_options', []); ?>
            <div class="directory-helpers-settings" style="margin-top: 20px;">
                <h2><?php esc_html_e('AI Content Generator Settings', 'directory-helpers'); ?></h2>
                <table class="form-table">
                    <tbody>
                        <tr>
                            <th scope="row">
                                <label for="n8n_webhook_url"><?php esc_html_e('n8n Webhook URL', 'directory-helpers'); ?></label>
                            </th>
                            <td>
                                <input type="url" id="n8n_webhook_url" name="directory_helpers_options[n8n_webhook_url]" value="<?php echo esc_attr($options['n8n_webhook_url'] ?? ''); ?>" class="regular-text">
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="shared_secret_key"><?php esc_html_e('Shared Secret Key (n8n)', 'directory-helpers'); ?></label>
                            </th>
                            <td>
                                <input type="password" id="shared_secret_key" name="directory_helpers_options[shared_secret_key]" value="<?php echo esc_attr($options['shared_secret_key'] ?? ''); ?>" class="regular-text">
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="dataforseo_login"><?php esc_html_e('DataForSEO Login', 'directory-helpers'); ?></label>
                            </th>
                            <td>
                                <input type="text" id="dataforseo_login" name="directory_helpers_options[dataforseo_login]" value="<?php echo esc_attr($options['dataforseo_login'] ?? ''); ?>" class="regular-text" placeholder="email@example.com">
                                <p class="description"><?php esc_html_e('Used for authenticating with DataForSEO API (Basic Auth).', 'directory-helpers'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="dataforseo_password"><?php esc_html_e('DataForSEO Password', 'directory-helpers'); ?></label>
                            </th>
                            <td>
                                <input type="password" id="dataforseo_password" name="directory_helpers_options[dataforseo_password]" value="<?php echo esc_attr($options['dataforseo_password'] ?? ''); ?>" class="regular-text">
                                <p class="description"><?php esc_html_e('Your DataForSEO account password. Stored in plugin options.', 'directory-helpers'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="notebook_webhook_url"><?php esc_html_e('Notebook Webhook URL', 'directory-helpers'); ?></label>
                            </th>
                            <td>
                                <input type="url" id="notebook_webhook_url" name="directory_helpers_options[notebook_webhook_url]" value="<?php echo esc_attr($options['notebook_webhook_url'] ?? ''); ?>" class="regular-text" placeholder="https://webhook.zerowork.io/trigger?s=...">
                                <p class="description"><?php esc_html_e('Used by the Create Notebook button in the editor.', 'directory-helpers'); ?></p>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="directory-helpers-settings" style="margin-top: 20px;">
                <h2><?php esc_html_e('Instant Search Settings', 'directory-helpers'); ?></h2>
                <table class="form-table">
                    <tbody>
                        <tr>
                            <th scope="row">
                                <label for="instant_search_placeholder"><?php esc_html_e('Default Placeholder', 'directory-helpers'); ?></label>
                            </th>
                            <td>
                                <input type="text" id="instant_search_placeholder" name="directory_helpers_options[instant_search_placeholder]" value="<?php echo esc_attr($options['instant_search_placeholder'] ?? ''); ?>" class="regular-text" placeholder="<?php echo esc_attr__('Search…', 'directory-helpers'); ?>">
                                <p class="description"><?php esc_html_e('Default text shown in the search input. Can be overridden per shortcode with placeholder="...".', 'directory-helpers'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="instant_search_zip_min_digits"><?php esc_html_e('Minimum digits for ZIP matches', 'directory-helpers'); ?></label>
                            </th>
                            <td>
                                <?php $zip_min = isset($options['instant_search_zip_min_digits']) ? (int) $options['instant_search_zip_min_digits'] : 3; ?>
                                <select id="instant_search_zip_min_digits" name="directory_helpers_options[instant_search_zip_min_digits]">
                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                        <option value="<?php echo (int)$i; ?>" <?php selected($zip_min, $i); ?>><?php echo (int)$i; ?></option>
                                    <?php endfor; ?>
                                </select>
                                <p class="description">
                                    <?php esc_html_e('Numeric queries with at least this many digits will return ZIP code matches. Default is 3.', 'directory-helpers'); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="instant_search_label_p"><?php esc_html_e('Profiles Label', 'directory-helpers'); ?></label>
                            </th>
                            <td>
                                <input type="text" id="instant_search_label_p" name="directory_helpers_options[instant_search_label_p]" value="<?php echo esc_attr($options['instant_search_label_p'] ?? ''); ?>" class="regular-text" placeholder="<?php echo esc_attr__('Profiles', 'directory-helpers'); ?>">
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="instant_search_label_c"><?php esc_html_e('City Listings Label', 'directory-helpers'); ?></label>
                            </th>
                            <td>
                                <input type="text" id="instant_search_label_c" name="directory_helpers_options[instant_search_label_c]" value="<?php echo esc_attr($options['instant_search_label_c'] ?? ''); ?>" class="regular-text" placeholder="<?php echo esc_attr__('City Listings', 'directory-helpers'); ?>">
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="instant_search_label_s"><?php esc_html_e('States Label', 'directory-helpers'); ?></label>
                            </th>
                            <td>
                                <input type="text" id="instant_search_label_s" name="directory_helpers_options[instant_search_label_s]" value="<?php echo esc_attr($options['instant_search_label_s'] ?? ''); ?>" class="regular-text" placeholder="<?php echo esc_attr__('States', 'directory-helpers'); ?>">
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="directory-helpers-settings" style="margin-top: 20px;">
                <h2><?php esc_html_e('AI Prompts', 'directory-helpers'); ?></h2>
                <p class="description"><?php esc_html_e('Save named prompts. They will be available on post edit screens via window.DH_PROMPTS and helper functions dh_get_prompt()/dh_get_prompts().', 'directory-helpers'); ?></p>
                <table class="form-table">
                    <tbody>
                        <tr>
                            <th scope="row"><?php esc_html_e('Prompts', 'directory-helpers'); ?></th>
                            <td>
                                <?php
                                $saved_prompts = isset($options['prompts']) && is_array($options['prompts']) ? $options['prompts'] : [];
                                $prompt_targets = isset($options['prompt_targets']) && is_array($options['prompt_targets']) ? $options['prompt_targets'] : [];
                                $index = 0;

                                // Build list of selectable post types (include ACF CPTs with show_ui)
                                $pts = get_post_types(['show_ui' => true], 'objects');
                                $exclude = ['attachment','revision','nav_menu_item','custom_css','customize_changeset','oembed_cache','user_request','wp_block','wp_navigation','wp_template','wp_template_part'];
                                foreach ($exclude as $ex) { unset($pts[$ex]); }
                                ?>
                                <script type="text/template" id="dh-prompt-pt-template">
                                    <div class="dh-prompt-pt-select" style="margin:6px 0;">
                                        <small><?php echo esc_html__('Show on post types:', 'directory-helpers'); ?></small><br />
                                        <?php foreach ($pts as $pt_name => $pt_obj): ?>
                                            <label style="display:block;">
                                                <input type="checkbox" name="directory_helpers_prompt_targets[__INDEX__][]" value="<?php echo esc_attr($pt_name); ?>" /> <?php echo esc_html($pt_obj->labels->singular_name); ?>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                </script>
                                <div id="dh-prompts-rows">
                                    <?php if (!empty($saved_prompts)) : ?>
                                        <?php foreach ($saved_prompts as $k => $v) : ?>
                                            <div class="dh-prompt-row" style="margin-bottom:12px; border:1px solid #ccd0d4; padding:12px; background:#fff;">
                                                <p>
                                                    <label>
                                                        <strong><?php esc_html_e('Key', 'directory-helpers'); ?></strong><br>
                                                        <input type="text" name="directory_helpers_prompts[<?php echo (int)$index; ?>][key]" value="<?php echo esc_attr($k); ?>" class="regular-text" placeholder="e.g. city_page_intro">
                                                    </label>
                                                </p>
                                                <p>
                                                    <label>
                                                        <strong><?php esc_html_e('Prompt', 'directory-helpers'); ?></strong><br>
                                                        <textarea name="directory_helpers_prompts[<?php echo (int)$index; ?>][value]" rows="6" class="large-text code" placeholder="Paste your prompt here..."><?php echo esc_textarea($v); ?></textarea>
                                                    </label>
                                                </p>
                                                <?php
                                                $san_key = sanitize_key($k);
                                                $selected_pts = isset($prompt_targets[$san_key]) && is_array($prompt_targets[$san_key]) ? $prompt_targets[$san_key] : [];
                                                ?>
                                                <div class="dh-prompt-pt-select" style="margin:6px 0;">
                                                    <small><?php esc_html_e('Show on post types:', 'directory-helpers'); ?></small><br />
                                                    <?php foreach ($pts as $pt_name => $pt_obj): ?>
                                                        <label style="display:block;">
                                                            <input type="checkbox" name="directory_helpers_prompt_targets[<?php echo (int)$index; ?>][]" value="<?php echo esc_attr($pt_name); ?>" <?php checked(in_array($pt_name, $selected_pts, true)); ?> /> <?php echo esc_html($pt_obj->labels->singular_name); ?>
                                                        </label>
                                                    <?php endforeach; ?>
                                                </div>
                                                <p>
                                                    <button type="button" class="button-link-delete dh-remove-prompt"><?php esc_html_e('Remove', 'directory-helpers'); ?></button>
                                                </p>
                                            </div>
                                            <?php $index++; ?>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <div class="dh-prompt-row" style="margin-bottom:12px; border:1px solid #ccd0d4; padding:12px; background:#fff;">
                                            <p>
                                                <label>
                                                    <strong><?php esc_html_e('Key', 'directory-helpers'); ?></strong><br>
                                                    <input type="text" name="directory_helpers_prompts[0][key]" value="" class="regular-text" placeholder="e.g. city_page_intro">
                                                </label>
                                            </p>
                                            <p>
                                                <label>
                                                    <strong><?php esc_html_e('Prompt', 'directory-helpers'); ?></strong><br>
                                                    <textarea name="directory_helpers_prompts[0][value]" rows="6" class="large-text code" placeholder="Paste your prompt here..."></textarea>
                                                </label>
                                            </p>
                                            <div class="dh-prompt-pt-select" style="margin:6px 0;">
                                                <small><?php esc_html_e('Show on post types:', 'directory-helpers'); ?></small><br />
                                                <?php foreach ($pts as $pt_name => $pt_obj): ?>
                                                    <label style="display:block;">
                                                        <input type="checkbox" name="directory_helpers_prompt_targets[0][]" value="<?php echo esc_attr($pt_name); ?>" /> <?php echo esc_html($pt_obj->labels->singular_name); ?>
                                                    </label>
                                                <?php endforeach; ?>
                                            </div>
                                            <p>
                                                <button type="button" class="button-link-delete dh-remove-prompt"><?php esc_html_e('Remove', 'directory-helpers'); ?></button>
                                            </p>
                                        </div>
                                        <?php $index = 1; ?>
                                    <?php endif; ?>
                                </div>
                                <p>
                                    <button type="button" class="button" id="dh-add-prompt" data-next-index="<?php echo (int)$index; ?>" onclick="if(window.dhAddPrompt){return window.dhAddPrompt(event);} return false;">&nbsp;<?php esc_html_e('Add Prompt', 'directory-helpers'); ?></button>
                                </p>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <script type="text/javascript">
            (function(){
                if (window.dhAddPrompt) { return; }
                window.dhAddPrompt = function(e){
                    if (e) { e.preventDefault(); e.stopPropagation(); }
                    var btn = document.getElementById('dh-add-prompt');
                    if (!btn) { return false; }
                    var nextIndex = parseInt(btn.getAttribute('data-next-index') || '0', 10) || 0;
                    var wrap = document.getElementById('dh-prompts-rows');
                    if (!wrap) { return false; }
                    var html = ''+
                    '<div class="dh-prompt-row" style="margin-bottom:12px; border:1px solid #ccd0d4; padding:12px; background:#fff;">'+
                        '<p>'+
                            '<label>'+
                                '<strong>Key</strong><br>'+
                                '<input type="text" name="directory_helpers_prompts['+ nextIndex +'][key]" value="" class="regular-text" placeholder="e.g. city_page_intro">'+
                            '</label>'+
                        '</p>'+
                        '<p>'+
                            '<label>'+
                                '<strong>Prompt</strong><br>'+
                                '<textarea name="directory_helpers_prompts['+ nextIndex +'][value]" rows="6" class="large-text code" placeholder="Paste your prompt here..."></textarea>'+
                            '</label>'+
                        '</p>'+
                        '<p>'+
                            '<button type="button" class="button-link-delete dh-remove-prompt">Remove</button>'+
                        '</p>'+
                    '</div>';
                    var tmp = document.createElement('div');
                    tmp.innerHTML = html;
                    var row = tmp.firstChild;
                    wrap.appendChild(row);
                    var tplEl = document.getElementById('dh-prompt-pt-template');
                    if (tplEl && tplEl.innerHTML) {
                        var ptHtml = tplEl.innerHTML.replace(/__INDEX__/g, String(nextIndex));
                        var lastP = row.querySelector('p:last-of-type');
                        if (lastP) {
                            var holder = document.createElement('div');
                            holder.innerHTML = ptHtml;
                            lastP.parentNode.insertBefore(holder.firstChild, lastP);
                        }
                    }
                    btn.setAttribute('data-next-index', String(nextIndex + 1));
                    return false;
                };
                // Also support remove buttons for dynamically-added rows
                document.addEventListener('click', function(ev){
                    var t = ev.target && ev.target.closest ? ev.target.closest('.dh-remove-prompt') : null;
                    if (!t) { return; }
                    ev.preventDefault();
                    var row = t.closest('.dh-prompt-row');
                    if (row && row.parentNode) { row.parentNode.removeChild(row); }
                });
            })();
            </script>
        </div>
        <?php submit_button(__('Save Settings', 'directory-helpers')); ?>
    </form>
</div>
