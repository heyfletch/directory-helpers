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
                                <label for="shared_secret_key"><?php esc_html_e('Shared Secret Key', 'directory-helpers'); ?></label>
                            </th>
                            <td>
                                <input type="password" id="shared_secret_key" name="directory_helpers_options[shared_secret_key]" value="<?php echo esc_attr($options['shared_secret_key'] ?? ''); ?>" class="regular-text">
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
        <?php submit_button(__('Save Settings', 'directory-helpers')); ?>
    </form>
</div>
