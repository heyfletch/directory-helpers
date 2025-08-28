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
                        <tr>
                            <th scope="row">
                                <strong><?php esc_html_e( 'City Listing Generator', 'directory-helpers' ); ?></strong>
                            </th>
                            <td>
                                <p><?php esc_html_e( 'Bulk-create city listing pages from a list of cities.', 'directory-helpers' ); ?></p>
                                <p><a href="<?php echo esc_url( admin_url( 'admin.php?page=dh-city-listing-generator' ) ); ?>"><?php esc_html_e( 'Go to Generator', 'directory-helpers' ); ?></a></p>
                            </td>
                        </tr>
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
                                $index = 0;
                                ?>
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
                                            <p>
                                                <button type="button" class="button-link-delete dh-remove-prompt"><?php esc_html_e('Remove', 'directory-helpers'); ?></button>
                                            </p>
                                        </div>
                                        <?php $index = 1; ?>
                                    <?php endif; ?>
                                </div>
                                <p>
                                    <button type="button" class="button" id="dh-add-prompt" data-next-index="<?php echo (int)$index; ?>"><?php esc_html_e('Add Prompt', 'directory-helpers'); ?></button>
                                </p>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
        <?php submit_button(__('Save Settings', 'directory-helpers')); ?>
    </form>
</div>
