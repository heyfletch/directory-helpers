<div class="wrap">
    <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
    
    <?php settings_errors('directory_helpers_messages'); ?>
    
    <div class="directory-helpers-admin">
        <form method="post" action="">
            <?php wp_nonce_field('directory_helpers_save_settings', 'directory_helpers_nonce'); ?>
            
            <div class="directory-helpers-modules">
                <h2><?php esc_html_e('Available Modules', 'directory-helpers'); ?></h2>
                <p><?php esc_html_e('Enable or disable modules as needed.', 'directory-helpers'); ?></p>
                
                <table class="form-table">
                    <tbody>
                        <?php 
                        $modules = Directory_Helpers::get_instance()->get_modules();
                        foreach ($modules as $module_id => $module) : 
                        ?>
                            <tr>
                                <th scope="row">
                                    <label for="module-<?php echo esc_attr($module_id); ?>">
                                        <?php echo esc_html($module['name']); ?>
                                    </label>
                                </th>
                                <td>
                                    <label>
                                        <input type="checkbox" 
                                               name="active_modules[]" 
                                               id="module-<?php echo esc_attr($module_id); ?>" 
                                               value="<?php echo esc_attr($module_id); ?>"
                                               <?php checked(in_array($module_id, $active_modules)); ?>>
                                        <?php echo esc_html($module['description']); ?>
                                    </label>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <p class="submit">
                <input type="submit" name="directory_helpers_save_settings" class="button button-primary" value="<?php esc_attr_e('Save Settings', 'directory-helpers'); ?>">
            </p>
        </form>
    </div>
</div>
