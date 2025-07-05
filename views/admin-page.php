<div class="wrap">
    <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
    
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
    </div>
</div>
