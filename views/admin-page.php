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
                        <tr>
                            <th scope="row">
                                <label for="featured_image_webhook_url"><?php esc_html_e('Featured Image Webhook URL', 'directory-helpers'); ?></label>
                            </th>
                            <td>
                                <input type="url" id="featured_image_webhook_url" name="directory_helpers_options[featured_image_webhook_url]" value="<?php echo esc_attr($options['featured_image_webhook_url'] ?? ''); ?>" class="regular-text" placeholder="https://flow.pressento.com/webhook/...">
                                <p class="description"><?php esc_html_e('Used by the Replace Featured Image button in the editor.', 'directory-helpers'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="video_queue_max_retries"><?php esc_html_e('Video Queue Max Retries', 'directory-helpers'); ?></label>
                            </th>
                            <td>
                                <?php $max_retries = isset($options['video_queue_max_retries']) ? (int) $options['video_queue_max_retries'] : 0; ?>
                                <select id="video_queue_max_retries" name="directory_helpers_options[video_queue_max_retries]">
                                    <?php for ($i = 0; $i <= 5; $i++): ?>
                                        <option value="<?php echo (int)$i; ?>" <?php selected($max_retries, $i); ?>><?php echo (int)$i; ?></option>
                                    <?php endfor; ?>
                                </select>
                                <p class="description"><?php esc_html_e('Number of times to retry failed video production for a post. 0 = skip failed posts immediately.', 'directory-helpers'); ?></p>
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
                        <tr>
                            <th scope="row">
                                <?php esc_html_e('Cache Management', 'directory-helpers'); ?>
                            </th>
                            <td>
                                <?php
                                $rebuild_url = wp_nonce_url(
                                    admin_url('admin-post.php?action=dh_rebuild_search_cache'),
                                    'dh_rebuild_search_cache'
                                );
                                ?>
                                <a href="<?php echo esc_url($rebuild_url); ?>" class="button button-secondary">
                                    <?php esc_html_e('Rebuild Search Cache Now', 'directory-helpers'); ?>
                                </a>
                                <p class="description">
                                    <?php esc_html_e('Manually rebuild the search index. The cache automatically rebuilds when posts are published/unpublished and lasts 7 days.', 'directory-helpers'); ?>
                                </p>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="directory-helpers-settings" style="margin-top: 20px;">
                <h2><?php esc_html_e('Proximity Query Settings', 'directory-helpers'); ?></h2>
                <p class="description"><?php esc_html_e('Configure proximity-based profile queries for city listing pages.', 'directory-helpers'); ?></p>
                <table class="form-table">
                    <tbody>
                        <tr>
                            <th scope="row">
                                <label for="min_profiles_threshold"><?php esc_html_e('Minimum Profiles Threshold', 'directory-helpers'); ?></label>
                            </th>
                            <td>
                                <?php $min_profiles = isset($options['min_profiles_threshold']) ? (int) $options['min_profiles_threshold'] : 10; ?>
                                <input type="number" id="min_profiles_threshold" name="directory_helpers_options[min_profiles_threshold]" value="<?php echo (int)$min_profiles; ?>" min="1" max="100" step="1" class="small-text">
                                <p class="description"><?php esc_html_e('Minimum number of profiles to show on a city listing page. If fewer area-tagged profiles exist, proximity search will be used. Default: 10', 'directory-helpers'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="default_city_radius"><?php esc_html_e('Default City Radius', 'directory-helpers'); ?></label>
                            </th>
                            <td>
                                <?php $default_radius = isset($options['default_city_radius']) ? (int) $options['default_city_radius'] : 5; ?>
                                <input type="number" id="default_city_radius" name="directory_helpers_options[default_city_radius]" value="<?php echo (int)$default_radius; ?>" min="1" max="50" step="1" class="small-text"> miles
                                <p class="description"><?php esc_html_e('Default radius in miles when no custom or recommended radius is set. This is the absolute fallback radius for proximity searches (no expansion). Default: 5', 'directory-helpers'); ?></p>
                            </td>
                        </tr>
                    </tbody>
                </table>
                
                <div style="margin-top: 20px; padding: 15px; background: #f0f6fc; border-left: 4px solid #2271b1;">
                    <h3 style="margin-top: 0;"><?php esc_html_e('WP-CLI: Analyze Radius Command', 'directory-helpers'); ?></h3>
                    <p><?php esc_html_e('Use WP-CLI to analyze area terms for published city-listing pages and calculate recommended radius values. Requires a niche slug (e.g., dog-trainer). Only analyzes areas that have published city-listing pages with that niche.', 'directory-helpers'); ?></p>
                    
                    <h4><?php esc_html_e('Basic Usage:', 'directory-helpers'); ?></h4>
                    <pre style="background: #fff; padding: 10px; border: 1px solid #ddd; overflow-x: auto;"><code># Dry run (preview results without updating)
wp directory-helpers analyze-radius dog-trainer --dry-run

# Analyze and update recommended_radius term meta
wp directory-helpers analyze-radius dog-trainer --update-meta

# Custom thresholds
wp directory-helpers analyze-radius dog-trainer --min-profiles=15 --max-radius=40 --update-meta</code></pre>
                    
                    <h4><?php esc_html_e('How It Works:', 'directory-helpers'); ?></h4>
                    <ul style="list-style: disc; margin-left: 20px;">
                        <li><?php esc_html_e('Tests radii: 2, 5, 10, 15, 20, 25, 30 miles', 'directory-helpers'); ?></li>
                        <li><?php esc_html_e('Finds smallest radius that reaches your minimum threshold', 'directory-helpers'); ?></li>
                        <li><?php esc_html_e('Updates <code>recommended_radius</code> term meta for each area', 'directory-helpers'); ?></li>
                        <li><?php esc_html_e('Areas with sufficient direct profiles are marked as not needing proximity', 'directory-helpers'); ?></li>
                    </ul>
                    
                    <h4><?php esc_html_e('Radius Priority:', 'directory-helpers'); ?></h4>
                    <ol style="margin-left: 20px;">
                        <li><strong><?php esc_html_e('Custom Radius', 'directory-helpers'); ?></strong> <?php esc_html_e('(set manually in area term edit screen - absolute)', 'directory-helpers'); ?></li>
                        <li><strong><?php esc_html_e('Recommended Radius', 'directory-helpers'); ?></strong> <?php esc_html_e('(calculated by WP-CLI command - absolute)', 'directory-helpers'); ?></li>
                        <li><strong><?php esc_html_e('Default City Radius', 'directory-helpers'); ?></strong> <?php esc_html_e('(from settings above - absolute)', 'directory-helpers'); ?></li>
                    </ol>
                    <p><em><?php esc_html_e('All radius values are absolute - no automatic expansion. Use the WP-CLI command to calculate optimal radius values.', 'directory-helpers'); ?></em></p>
                    
                    <p><strong><?php esc_html_e('Recommendation:', 'directory-helpers'); ?></strong> <?php esc_html_e('Run this command quarterly or when you add 100+ new profiles to keep radius values optimized.', 'directory-helpers'); ?></p>
                </div>

                <div style="margin-top: 20px; padding: 15px; background: #f0f8e7; border-left: 4px solid #46b450;">
                    <h3 style="margin-top: 0;"><?php esc_html_e('WP-CLI: Update Rankings Command', 'directory-helpers'); ?></h3>
                    <p><?php esc_html_e('Trigger ranking recalculation for ALL profiles across all cities by saving one profile per city. Essential after bulk imports or data changes. Requires a niche slug (e.g., dog-trainer).', 'directory-helpers'); ?></p>

                    <h4><?php esc_html_e('Basic Usage:', 'directory-helpers'); ?></h4>
                    <pre style="background: #fff; padding: 10px; border: 1px solid #ddd; overflow-x: auto;"><code># Dry run (preview cities that would be processed)
wp directory-helpers update-rankings dog-trainer --dry-run

# Update rankings (smart resume - resumes if progress exists, starts fresh if not)
wp directory-helpers update-rankings dog-trainer

# Force fresh start (ignore any existing progress)
wp directory-helpers update-rankings dog-trainer --fresh

# Custom batch settings
wp directory-helpers update-rankings dog-trainer --batch-size=10 --delay=1</code></pre>

                    <h4><?php esc_html_e('How It Works:', 'directory-helpers'); ?></h4>
                    <ul style="list-style: disc; margin-left: 20px;">
                        <li><?php esc_html_e('Finds all cities with city-listing pages in the specified niche', 'directory-helpers'); ?></li>
                        <li><?php esc_html_e('Selects one profile per city to trigger ranking recalculation', 'directory-helpers'); ?></li>
                        <li><?php esc_html_e('Updates city_rank ACF field for all profiles in each city', 'directory-helpers'); ?></li>
                        <li><?php esc_html_e('Uses bulk database operations for optimal performance', 'directory-helpers'); ?></li>
                    </ul>

                    <h4><?php esc_html_e('Performance Optimized Defaults:', 'directory-helpers'); ?></h4>
                    <ul style="list-style: disc; margin-left: 20px;">
                        <li><strong><?php esc_html_e('Batch Size:', 'directory-helpers'); ?></strong> <?php esc_html_e('20 cities (balances speed vs. memory)', 'directory-helpers'); ?></li>
                        <li><strong><?php esc_html_e('Delay:', 'directory-helpers'); ?></strong> <?php esc_html_e('0.5 seconds (allows ranking hooks to complete)', 'directory-helpers'); ?></li>
                        <li><strong><?php esc_html_e('Batch Pause:', 'directory-helpers'); ?></strong> <?php esc_html_e('2 seconds (prevents system overload)', 'directory-helpers'); ?></li>
                    </ul>

                    <h4><?php esc_html_e('Progress Tracking:', 'directory-helpers'); ?></h4>
                    <ul style="list-style: disc; margin-left: 20px;">
                        <li><?php esc_html_e('Real-time progress display with city names and profile counts', 'directory-helpers'); ?></li>
                        <li><?php esc_html_e('Progress files allow resuming interrupted runs', 'directory-helpers'); ?></li>
                        <li><?php esc_html_e('Estimated completion time based on current progress', 'directory-helpers'); ?></li>
                        <li><?php esc_html_e('Error handling continues processing even if individual saves fail', 'directory-helpers'); ?></li>
                    </ul>

                    <p><strong><?php esc_html_e('Recommendation:', 'directory-helpers'); ?></strong> <?php esc_html_e('Run this command after bulk profile imports or major data changes. The dry-run mode lets you verify which cities will be processed before committing.', 'directory-helpers'); ?></p>
                </div>

                <div style="margin-top: 20px; padding: 15px; background: #f9f9f9; border-left: 4px solid #0073aa;">
                    <h3 style="margin-top: 0;"><?php esc_html_e('State Rankings Command', 'directory-helpers'); ?></h3>
                    <p><?php esc_html_e('Update state_rank for all profiles. Processes each state only once using optimized bulk operations.', 'directory-helpers'); ?></p>
                    
                    <h4><?php esc_html_e('Basic Usage:', 'directory-helpers'); ?></h4>
                    <pre style="background: #fff; padding: 10px; border: 1px solid #ddd; overflow-x: auto;"><code># Dry run (preview states that would be processed)
wp directory-helpers update-state-rankings dog-trainer --dry-run

# Update all state rankings
wp directory-helpers update-state-rankings dog-trainer

# Update a specific state only
wp directory-helpers update-state-rankings dog-trainer --state=ca</code></pre>

                    <h4><?php esc_html_e('How It Works:', 'directory-helpers'); ?></h4>
                    <ul style="list-style: disc; margin-left: 20px;">
                        <li><?php esc_html_e('Finds all states with profiles in the specified niche', 'directory-helpers'); ?></li>
                        <li><?php esc_html_e('Processes each state ONCE (not per city like the old method)', 'directory-helpers'); ?></li>
                        <li><?php esc_html_e('Uses bulk database queries to fetch all profile data efficiently', 'directory-helpers'); ?></li>
                        <li><?php esc_html_e('Updates state_rank ACF field for all profiles in each state', 'directory-helpers'); ?></li>
                    </ul>

                    <h4><?php esc_html_e('Performance:', 'directory-helpers'); ?></h4>
                    <ul style="list-style: disc; margin-left: 20px;">
                        <li><?php esc_html_e('California (1,200+ profiles): ~6 seconds', 'directory-helpers'); ?></li>
                        <li><?php esc_html_e('Texas (800+ profiles): ~2 seconds', 'directory-helpers'); ?></li>
                        <li><?php esc_html_e('All 51 states: ~40 seconds total', 'directory-helpers'); ?></li>
                    </ul>

                    <p><strong><?php esc_html_e('Recommended Workflow:', 'directory-helpers'); ?></strong></p>
                    <pre style="background: #fff; padding: 10px; border: 1px solid #ddd; overflow-x: auto;"><code># Step 1: Run city rankings first
wp directory-helpers update-rankings dog-trainer

# Step 2: Run state rankings after city rankings complete
wp directory-helpers update-state-rankings dog-trainer</code></pre>
                </div>
            </div>

            <?php 
            // Include CLI Runner section if module is loaded
            if (class_exists('DH_Admin_CLI_Runner')) {
                echo DH_Admin_CLI_Runner::get_admin_section_html();
            }
            ?>

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
    
    <!-- Shortcode Documentation -->
    <div class="directory-helpers-documentation" style="margin-top: 40px;">
        <h2><?php esc_html_e('Shortcode Reference', 'directory-helpers'); ?></h2>
        
        <div class="card" style="max-width: none;">
            <h3><?php esc_html_e('Taxonomy Display Shortcodes', 'directory-helpers'); ?></h3>
            <p><?php esc_html_e('Display taxonomy term names on profile pages:', 'directory-helpers'); ?></p>
            
            <table class="widefat" style="margin-top: 15px;">
                <thead>
                    <tr>
                        <th style="width: 30%;"><?php esc_html_e('Shortcode', 'directory-helpers'); ?></th>
                        <th style="width: 35%;"><?php esc_html_e('Description', 'directory-helpers'); ?></th>
                        <th style="width: 35%;"><?php esc_html_e('Example Output', 'directory-helpers'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><code>[dh_city_name]</code></td>
                        <td><?php esc_html_e('Display city name (strips " - ST" suffix by default)', 'directory-helpers'); ?></td>
                        <td><em>Milwaukee</em></td>
                    </tr>
                    <tr>
                        <td><code>[dh_city_name strip_state="false"]</code></td>
                        <td><?php esc_html_e('Display city name with state suffix', 'directory-helpers'); ?></td>
                        <td><em>Milwaukee - WI</em></td>
                    </tr>
                    <tr>
                        <td><code>[dh_state_name]</code></td>
                        <td><?php esc_html_e('Display full state name', 'directory-helpers'); ?></td>
                        <td><em>Wisconsin</em></td>
                    </tr>
                    <tr>
                        <td><code>[dh_state_name format="abbr"]</code></td>
                        <td><?php esc_html_e('Display state abbreviation', 'directory-helpers'); ?></td>
                        <td><em>WI</em></td>
                    </tr>
                    <tr>
                        <td><code>[dh_niche_name]</code></td>
                        <td><?php esc_html_e('Display niche name (lowercase by default)', 'directory-helpers'); ?></td>
                        <td><em>dog trainer</em></td>
                    </tr>
                    <tr>
                        <td><code>[dh_niche_name case="title"]</code></td>
                        <td><?php esc_html_e('Display niche name in title case', 'directory-helpers'); ?></td>
                        <td><em>Dog Trainer</em></td>
                    </tr>
                    <tr>
                        <td><code>[dh_niche_name plural="true"]</code></td>
                        <td><?php esc_html_e('Display pluralized niche name', 'directory-helpers'); ?></td>
                        <td><em>dog trainers</em></td>
                    </tr>
                </tbody>
            </table>
            
            <h4 style="margin-top: 20px;"><?php esc_html_e('Usage Examples', 'directory-helpers'); ?></h4>
            <pre style="background: #f5f5f5; padding: 15px; border-left: 4px solid #2271b1; overflow-x: auto;"><code>&lt;h1&gt;Meet [dh_niche_name case="title"] in [dh_city_name]&lt;/h1&gt;
&lt;p&gt;Serving [dh_city_name], [dh_state_name]&lt;/p&gt;

Output:
&lt;h1&gt;Meet Dog Trainer in Milwaukee&lt;/h1&gt;
&lt;p&gt;Serving Milwaukee, Wisconsin&lt;/p&gt;

&lt;!-- Use lowercase for consistency --&gt;
&lt;h2&gt;[dh_niche_name] services&lt;/h2&gt;

Output:
&lt;h2&gt;dog trainer services&lt;/h2&gt;</code></pre>
        </div>
        
        <div class="card" style="max-width: none; margin-top: 20px;">
            <h3><?php esc_html_e('Profile Badge Shortcodes', 'directory-helpers'); ?></h3>
            <p><?php esc_html_e('Display ranking badges on profile pages:', 'directory-helpers'); ?></p>
            
            <table class="widefat" style="margin-top: 15px;">
                <thead>
                    <tr>
                        <th style="width: 30%;"><?php esc_html_e('Shortcode', 'directory-helpers'); ?></th>
                        <th style="width: 70%;"><?php esc_html_e('Description', 'directory-helpers'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><code>[dh_accolades]</code></td>
                        <td><?php esc_html_e('Display all eligible badges (city rank, state rank, featured/recognized) as images', 'directory-helpers'); ?></td>
                    </tr>
                    <tr>
                        <td><code>[dh_celebration]</code></td>
                        <td><?php esc_html_e('Display badges with "Copy Embed Code" buttons for trainers to share on their websites', 'directory-helpers'); ?></td>
                    </tr>
                </tbody>
            </table>
            
            <h4 style="margin-top: 20px;"><?php esc_html_e('Badge URLs', 'directory-helpers'); ?></h4>
            <p><?php esc_html_e('Badges are also accessible via direct URLs:', 'directory-helpers'); ?></p>
            <ul style="list-style: disc; margin-left: 20px;">
                <li><code><?php echo esc_html(home_url('/badge/{post_id}/city.svg')); ?></code> - <?php esc_html_e('City ranking badge (self-linking)', 'directory-helpers'); ?></li>
                <li><code><?php echo esc_html(home_url('/badge/{post_id}/state.svg')); ?></code> - <?php esc_html_e('State ranking badge (self-linking)', 'directory-helpers'); ?></li>
                <li><code><?php echo esc_html(home_url('/badge/{post_id}/profile.svg')); ?></code> - <?php esc_html_e('Featured/Recognized badge (self-linking)', 'directory-helpers'); ?></li>
                <li><code><?php echo esc_html(home_url('/badge/{post_id}/city.svg?active=1')); ?></code> - <?php esc_html_e('Badge without internal link (for nested embeds)', 'directory-helpers'); ?></li>
            </ul>
            <p><em><?php esc_html_e('Replace {post_id} with the actual profile post ID.', 'directory-helpers'); ?></em></p>
            
            <h4 style="margin-top: 20px;"><?php esc_html_e('Embed Modes', 'directory-helpers'); ?></h4>
            <p><?php esc_html_e('Badges support two embed modes to prevent nested links:', 'directory-helpers'); ?></p>
            <table class="widefat" style="margin-top: 10px;">
                <thead>
                    <tr>
                        <th style="width: 30%;"><?php esc_html_e('Mode', 'directory-helpers'); ?></th>
                        <th style="width: 70%;"><?php esc_html_e('Description', 'directory-helpers'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><strong><?php esc_html_e('Standard', 'directory-helpers'); ?></strong></td>
                        <td><?php esc_html_e('SVG includes internal <a> tag. Use when embedding badge directly without wrapper link.', 'directory-helpers'); ?></td>
                    </tr>
                    <tr>
                        <td><strong><?php esc_html_e('Active (?active=1)', 'directory-helpers'); ?></strong></td>
                        <td><?php esc_html_e('SVG has internal <a> tag stripped. Use when wrapping badge in an <a> tag to prevent invalid nested links.', 'directory-helpers'); ?></td>
                    </tr>
                </tbody>
            </table>
            <p style="margin-top: 10px;"><strong><?php esc_html_e('Recommended Embed Code:', 'directory-helpers'); ?></strong></p>
            <pre style="background: #f5f5f5; padding: 15px; border-left: 4px solid #2271b1; overflow-x: auto;"><code>&lt;!-- Full embed code (prevents nested links) --&gt;
&lt;a href="<?php echo esc_html(home_url('/top/state-trainers/')); ?>"&gt;
  &lt;img src="<?php echo esc_html(home_url('/badge/{post_id}/state.svg?active=1')); ?>" alt="State Badge" width="250" height="auto" /&gt;
&lt;/a&gt;

&lt;!-- Standalone (self-linking) --&gt;
&lt;img src="<?php echo esc_html(home_url('/badge/{post_id}/state.svg')); ?>" alt="State Badge" width="250" height="auto" /&gt;</code></pre>
        </div>
        
        <div class="card" style="max-width: none; margin-top: 20px;">
            <h3><?php esc_html_e('Ranking Shortcodes', 'directory-helpers'); ?></h3>
            <table class="widefat" style="margin-top: 15px;">
                <thead>
                    <tr>
                        <th style="width: 30%;"><?php esc_html_e('Shortcode', 'directory-helpers'); ?></th>
                        <th style="width: 35%;"><?php esc_html_e('Description', 'directory-helpers'); ?></th>
                        <th style="width: 35%;"><?php esc_html_e('Example Output', 'directory-helpers'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><code>[dh_city_rank]</code></td>
                        <td><?php esc_html_e('Display city ranking', 'directory-helpers'); ?></td>
                        <td><em>Ranked Top 5 in Milwaukee</em></td>
                    </tr>
                    <tr>
                        <td><code>[dh_city_rank show_prefix="false"]</code></td>
                        <td><?php esc_html_e('Display without "Ranked" prefix', 'directory-helpers'); ?></td>
                        <td><em>Top 5 in Milwaukee</em></td>
                    </tr>
                    <tr>
                        <td><code>[dh_state_rank]</code></td>
                        <td><?php esc_html_e('Display state ranking', 'directory-helpers'); ?></td>
                        <td><em>Ranked Top 10 in Wisconsin</em></td>
                    </tr>
                    <tr>
                        <td><code>[dh_state_rank show_ranking_data="true"]</code></td>
                        <td><?php esc_html_e('Include rating and review count', 'directory-helpers'); ?></td>
                        <td><em>Ranked Top 10 in Wisconsin based on 42 reviews and a 4.8 rating</em></td>
                    </tr>
                </tbody>
            </table>
        </div>
        
        <div class="card" style="max-width: none; margin-top: 20px;">
            <h3><?php esc_html_e('Other Shortcodes', 'directory-helpers'); ?></h3>
            <table class="widefat" style="margin-top: 15px;">
                <thead>
                    <tr>
                        <th style="width: 30%;"><?php esc_html_e('Shortcode', 'directory-helpers'); ?></th>
                        <th style="width: 70%;"><?php esc_html_e('Description', 'directory-helpers'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><code>[dh_breadcrumbs]</code></td>
                        <td><?php esc_html_e('Display breadcrumb navigation. Supports attributes: home_text, home_separator, show_niche, show_city, show_state, show_home, separator', 'directory-helpers'); ?></td>
                    </tr>
                    <tr>
                        <td><code>[dh_video_overview]</code></td>
                        <td><?php esc_html_e('Embed YouTube video from ACF "video_overview" field', 'directory-helpers'); ?></td>
                    </tr>
                    <tr>
                        <td><code>[dh_instant_search]</code></td>
                        <td><?php esc_html_e('Display instant search widget with typeahead dropdown', 'directory-helpers'); ?></td>
                    </tr>
                    <tr>
                        <td><code>[dh_nearest_cities limit="5"]</code></td>
                        <td><?php esc_html_e('List nearest cities based on area lat/lng coordinates', 'directory-helpers'); ?></td>
                    </tr>
                </tbody>
            </table>
        </div>
        
        <div class="card" style="max-width: none; margin-top: 20px; background: #fffbcc; border-left: 4px solid #ffb900;">
            <h3><?php esc_html_e('⚠️ Multiple Area Terms', 'directory-helpers'); ?></h3>
            <p><?php esc_html_e('When a profile has multiple area taxonomy terms, the plugin uses the ACF "city" field to determine which is the primary city.', 'directory-helpers'); ?></p>
            <p><strong><?php esc_html_e('Logic:', 'directory-helpers'); ?></strong></p>
            <ol style="margin-left: 20px;">
                <li><?php esc_html_e('If only one area term exists → use it', 'directory-helpers'); ?></li>
                <li><?php esc_html_e('If multiple area terms exist → match against ACF "city" field value', 'directory-helpers'); ?></li>
                <li><?php esc_html_e('If no match found → fallback to first term (alphabetical)', 'directory-helpers'); ?></li>
            </ol>
            <p><strong><?php esc_html_e('Example:', 'directory-helpers'); ?></strong> <?php esc_html_e('Profile has area terms "milwaukee-wi" and "waukesha-wi". ACF city field = "Milwaukee". Result: Uses "milwaukee-wi" for rankings and display.', 'directory-helpers'); ?></p>
            <p><em><?php esc_html_e('This ensures accurate city rankings and badge display for profiles serving multiple cities.', 'directory-helpers'); ?></em></p>
        </div>
    </div>
</div>
