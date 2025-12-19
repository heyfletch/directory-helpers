<?php
/**
 * Prep Pro Admin Page View
 */
if (!defined('ABSPATH')) exit;
?>

<div class="wrap">
    <h1><?php esc_html_e('Prep Pro - Prepare Profiles', 'directory-helpers'); ?></h1>
    
    <?php if (isset($_GET['published'])): ?>
        <?php 
        $created_city_ids = get_transient('dh_prep_pro_created_cities_' . get_current_user_id());
        if ($created_city_ids) {
            delete_transient('dh_prep_pro_created_cities_' . get_current_user_id());
        }
        ?>
        <div class="notice notice-success is-dismissible">
            <p><strong>Published <?php echo (int)$_GET['published']; ?> profiles. Created <?php echo (int)$_GET['cities_created']; ?> cities.</strong><br>
            <em>Reranking and cache clearing running in background...</em></p>
            <?php if (!empty($created_city_ids)): ?>
                <p><strong>Created Cities:</strong></p>
                <ul style="margin-top: 5px;">
                    <?php foreach ($created_city_ids as $city_id): ?>
                        <li>
                            <a href="<?php echo esc_url(get_edit_post_link($city_id)); ?>" target="_blank">
                                <?php echo esc_html(get_the_title($city_id)); ?>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    
    <?php if (isset($_GET['reranked'])): ?>
        <div class="notice notice-success is-dismissible">
            <p><strong>Reranking complete.</strong> 
            <?php if (isset($_GET['cities']) && $_GET['cities'] > 0): ?>
                Reranked <?php echo (int)$_GET['cities']; ?> cities.
            <?php endif; ?>
            </p>
        </div>
    <?php endif; ?>
    
    <?php if (isset($_GET['rerank_clear'])): ?>
        <div class="notice notice-success is-dismissible">
            <p><strong>Reranking and cache clear complete.</strong> 
            <?php if (isset($_GET['cities']) && $_GET['cities'] > 0): ?>
                Reranked <?php echo (int)$_GET['cities']; ?> cities and cleared cache.
            <?php endif; ?>
            </p>
        </div>
    <?php endif; ?>
    
    <?php if (isset($_GET['cache_cleared'])): ?>
        <div class="notice notice-success is-dismissible">
            <p><strong>Cache cleared.</strong></p>
        </div>
    <?php endif; ?>
    
    <?php if (isset($_GET['cache_primed'])): ?>
        <div class="notice notice-success is-dismissible">
            <p><strong>Cache primed.</strong></p>
        </div>
    <?php endif; ?>
    
    <?php if (isset($_GET['cache_cleared_primed'])): ?>
        <div class="notice notice-success is-dismissible">
            <p><strong>Cache cleared and primed.</strong></p>
        </div>
    <?php endif; ?>
    
    <?php if (isset($_GET['all_complete'])): ?>
        <div class="notice notice-success is-dismissible">
            <p><strong>Rerank, purge, and prime complete.</strong></p>
        </div>
    <?php endif; ?>
    
    <?php if (isset($_GET['reset'])): ?>
        <div class="notice notice-success is-dismissible">
            <p><strong>Tracking reset.</strong></p>
        </div>
    <?php endif; ?>
    
    <!-- Filter Form -->
    <div style="background: #fff; padding: 20px; margin: 20px 0; border: 1px solid #ccd0d4; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
        
        <!-- State Selection Grid -->
        <div style="margin-bottom: 20px;">
            <div style="display: flex; flex-wrap: wrap; gap: 8px;">
                <?php
                // Separate states by published status and sort
                $published_states = array();
                $unpublished_states = array();
                
                foreach ($states as $term) {
                    // Check if state has published state-listing
                    $state_listing = get_posts(array(
                        'post_type' => 'state-listing',
                        'post_status' => 'publish',
                        'posts_per_page' => 1,
                        'tax_query' => array(
                            array(
                                'taxonomy' => 'state',
                                'field' => 'term_id',
                                'terms' => $term->term_id
                            )
                        ),
                        'fields' => 'ids'
                    ));
                    
                    if (!empty($state_listing)) {
                        $published_states[] = $term;
                    } else {
                        $unpublished_states[] = $term;
                    }
                }
                
                // Sort each group alphabetically
                usort($published_states, function($a, $b) {
                    $a_label = !empty($a->description) ? $a->description : $a->name;
                    $b_label = !empty($b->description) ? $b->description : $b->name;
                    return strcmp($a_label, $b_label);
                });
                usort($unpublished_states, function($a, $b) {
                    $a_label = !empty($a->description) ? $a->description : $a->name;
                    $b_label = !empty($b->description) ? $b->description : $b->name;
                    return strcmp($a_label, $b_label);
                });
                
                // Display published states first, then unpublished
                $all_states_ordered = array_merge($published_states, $unpublished_states);
                
                foreach ($all_states_ordered as $term):
                    $label = !empty($term->description) ? $term->description : $term->name;
                    $is_published = in_array($term, $published_states, true);
                    $is_selected = ($state_slug === $term->slug);
                    
                    // Style based on status
                    if ($is_selected) {
                        $style = 'background: #2271b1; color: white; font-weight: bold;';
                    } elseif ($is_published) {
                        $style = 'background: #46b450; color: white;';
                    } else {
                        $style = 'background: #dcdcde; color: #2c3338;';
                    }
                    
                    $url = add_query_arg(array(
                        'page' => 'dh-prep-pro',
                        'state' => $term->slug
                    ), admin_url('admin.php'));
                    ?>
                    <a href="<?php echo esc_url($url); ?>" 
                       style="display: inline-block; padding: 6px 12px; text-decoration: none; border-radius: 3px; <?php echo $style; ?>">
                        <?php echo esc_html($label); ?>
                    </a>
                <?php endforeach; ?>
            </div>
            <p style="margin-top: 10px; font-size: 12px; color: #646970;">
                <span style="display: inline-block; width: 12px; height: 12px; background: #46b450; border-radius: 2px; margin-right: 4px;"></span> Published
                <span style="display: inline-block; width: 12px; height: 12px; background: #dcdcde; border-radius: 2px; margin: 0 4px 0 12px;"></span> Not Published
                <span style="display: inline-block; width: 12px; height: 12px; background: #2271b1; border-radius: 2px; margin: 0 4px 0 12px;"></span> Selected
            </p>
        </div>
        
        <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>">
            <input type="hidden" name="page" value="dh-prep-pro" />
            <input type="hidden" name="state" value="<?php echo esc_attr($state_slug); ?>" />
            
            <!-- Status hidden, always refining -->
            <input type="hidden" name="post_status" value="refining" />
            
            <!-- Niche hidden, always dog-trainer -->
            <input type="hidden" name="niche" value="dog-trainer" />
            
            <div style="display:flex; gap:12px; align-items:center; flex-wrap:wrap;">
                <label><strong><?php esc_html_e('Find:', 'directory-helpers'); ?></strong>
                    <input type="text" name="city_search" value="<?php echo esc_attr($city_search); ?>" placeholder="<?php esc_attr_e('Search city name...', 'directory-helpers'); ?>" style="width:150px;" />
                </label>
                
                <label><strong><?php esc_html_e('City:', 'directory-helpers'); ?></strong>
                    <select name="city">
                        <option value="" <?php selected($city_slug, ''); ?>><?php esc_html_e('All Cities', 'directory-helpers'); ?></option>
                        <?php foreach ($unique_cities as $slug => $name): ?>
                            <option value="<?php echo esc_attr($slug); ?>" <?php selected($city_slug, $slug); ?>>
                                <?php echo esc_html($name); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                
                <label><strong><?php esc_html_e('Profiles:', 'directory-helpers'); ?></strong>
                    <input type="number" name="min_count" value="<?php echo esc_attr($min_count); ?>" min="1" max="10" style="width: 60px;" />
                </label>
                
                <label><strong><?php esc_html_e('City Status:', 'directory-helpers'); ?></strong>
                    <select name="city_status">
                        <option value="all" <?php selected($city_status, 'all'); ?>><?php esc_html_e('All', 'directory-helpers'); ?></option>
                        <option value="new" <?php selected($city_status, 'new'); ?>><?php esc_html_e('New', 'directory-helpers'); ?></option>
                        <option value="existing" <?php selected($city_status, 'existing'); ?>><?php esc_html_e('Existing', 'directory-helpers'); ?></option>
                    </select>
                </label>
                
                <button type="submit" class="button button-secondary"><?php esc_html_e('Filter', 'directory-helpers'); ?></button>
            </div>
        </form>
    </div>
    
    <!-- Fast Publishing -->
    <?php if (!empty($profiles)): ?>
        <style>
            .button-success {
                background: #46b450 !important;
                border-color: #46b450 !important;
                color: #fff !important;
            }
            .button-success:hover {
                background: #54c55f !important;
                border-color: #54c55f !important;
            }
        </style>
        <div style="background: #fff; padding: 20px; margin: 20px 0; border: 1px solid #ccd0d4; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
            <h2 style="margin-top: 0;">🚀 Publish Profiles & Create Cities</h2>
            <p>This creates new city pages, publishes selected profiles, and sends cities for AI content (1 second delay between each city request).</p>
            
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('Publish <?php echo count($profiles); ?> profiles? This will create missing city pages and trigger AI content generation.');">
                <?php wp_nonce_field('dh_prep_pro_publish'); ?>
                <input type="hidden" name="action" value="dh_prep_pro_publish" />
                <input type="hidden" name="state" value="<?php echo esc_attr($state_slug); ?>" />
                <input type="hidden" name="post_status" value="<?php echo esc_attr($post_status); ?>" />
                <input type="hidden" name="min_count" value="<?php echo esc_attr($min_count); ?>" />
                <input type="hidden" name="city" value="<?php echo esc_attr($city_slug); ?>" />
                <input type="hidden" name="niche" value="<?php echo esc_attr($niche_slug); ?>" />
                <input type="hidden" name="city_search" value="<?php echo esc_attr($city_search); ?>" />
                <input type="hidden" name="city_status" value="<?php echo esc_attr($city_status); ?>" />
                
                <button type="submit" class="button button-success button-hero">
                    <?php echo sprintf(esc_html__('Publish Profiles & Create Cities if New (%d profiles)', 'directory-helpers'), count($profiles)); ?>
                </button>
            </form>
        </div>
    <?php endif; ?>
    
    <!-- Maintenance Buttons -->
    <?php if (!empty($tracking)): ?>
        <div style="background: #fff; padding: 20px; margin: 20px 0; border: 1px solid #ccd0d4; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
            <h2 style="margin-top: 0;">⚙️ Maintenance (Run After Publishing)</h2>
            <p>Operate on the <?php echo count($tracking['profile_ids'] ?? array()); ?> profiles you just published.</p>
            
            <div style="display: flex; gap: 10px; flex-wrap: wrap; margin-top: 15px;">
                <!-- Rerank & Clear Cache -->
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display: inline-block;" onsubmit="return confirm('Rerank profiles and clear cache?');">
                    <?php wp_nonce_field('dh_prep_pro_maintenance'); ?>
                    <input type="hidden" name="action" value="dh_prep_pro_rerank_clear" />
                    <button type="submit" class="button button-secondary">Rerank & Clear Cache</button>
                </form>
                
                <!-- Rerank Profiles -->
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display: inline-block;" onsubmit="return confirm('Rerank profiles?');">
                    <?php wp_nonce_field('dh_prep_pro_maintenance'); ?>
                    <input type="hidden" name="action" value="dh_prep_pro_rerank" />
                    <button type="submit" class="button button-secondary">Rerank Profiles</button>
                </form>
                
                <!-- Clear Cache -->
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display: inline-block;">
                    <?php wp_nonce_field('dh_prep_pro_maintenance'); ?>
                    <input type="hidden" name="action" value="dh_prep_pro_clear_cache" />
                    <button type="submit" class="button button-secondary">Clear Cache</button>
                </form>
                
                <!-- Prime Cache -->
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display: inline-block;">
                    <?php wp_nonce_field('dh_prep_pro_maintenance'); ?>
                    <input type="hidden" name="action" value="dh_prep_pro_prime_cache" />
                    <button type="submit" class="button button-secondary">Prime Cache</button>
                </form>
                
                <!-- Clear and Prime Cache -->
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display: inline-block;">
                    <?php wp_nonce_field('dh_prep_pro_maintenance'); ?>
                    <input type="hidden" name="action" value="dh_prep_pro_clear_prime" />
                    <button type="submit" class="button button-secondary">Clear and Prime Cache</button>
                </form>
                
                <!-- Rerank, Purge, Prime (All in One) -->
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display: inline-block;" onsubmit="return confirm('Run rerank, purge, and prime all together?');">
                    <?php wp_nonce_field('dh_prep_pro_maintenance'); ?>
                    <input type="hidden" name="action" value="dh_prep_pro_rerank_purge_prime" />
                    <button type="submit" class="button button-secondary">Rerank, Purge, Prime</button>
                </form>
                
                <!-- Reset Tracking -->
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display: inline-block; margin-left: 20px;" onsubmit="return confirm('Reset tracking? This clears the list of recent profiles.');">
                    <?php wp_nonce_field('dh_prep_pro_maintenance'); ?>
                    <input type="hidden" name="action" value="dh_prep_pro_reset" />
                    <button type="submit" class="button">Reset Tracking</button>
                </form>
            </div>
        </div>
    <?php endif; ?>
    
    <!-- Results Table -->
    <div style="background: #fff; padding: 20px; margin: 20px 0; border: 1px solid #ccd0d4; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
        <h2 style="margin-top: 0;">Profiles (<?php echo count($profiles); ?>)</h2>
        
        <?php if (!empty($profiles)): ?>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Profile', 'directory-helpers'); ?></th>
                        <th><?php esc_html_e('City', 'directory-helpers'); ?></th>
                        <th><?php esc_html_e('Status', 'directory-helpers'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($profiles as $profile): ?>
                        <tr>
                            <td>
                                <strong>
                                    <a href="<?php echo esc_url(get_edit_post_link($profile->ID)); ?>" target="_blank">
                                        <?php echo esc_html($profile->post_title); ?>
                                    </a>
                                </strong>
                            </td>
                            <td><?php echo esc_html($profile->area_name); ?></td>
                            <td><?php echo esc_html(ucfirst($profile->post_status)); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p><?php esc_html_e('No profiles found with the selected filters.', 'directory-helpers'); ?></p>
        <?php endif; ?>
    </div>
</div>
