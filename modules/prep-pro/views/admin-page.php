<?php
/**
 * Prep Pro Admin Page View
 */
if (!defined('ABSPATH')) exit;
?>

<div class="wrap">
    <h1><?php esc_html_e('Prep Pro - Prepare Profiles', 'directory-helpers'); ?></h1>
    
    <?php if (isset($_GET['published'])): ?>
        <div class="notice notice-success is-dismissible">
            <p><strong>Published <?php echo (int)$_GET['published']; ?> profiles. Created <?php echo (int)$_GET['cities_created']; ?> cities.</strong></p>
        </div>
    <?php endif; ?>
    
    <?php if (isset($_GET['reranked'])): ?>
        <div class="notice notice-success is-dismissible">
            <p><strong>Reranking complete.</strong></p>
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
        <h2 style="margin-top: 0;">Filter Profiles</h2>
        <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>">
            <input type="hidden" name="page" value="dh-prep-pro" />
            
            <div style="display:flex; gap:12px; align-items:center; flex-wrap:wrap;">
                <label><strong><?php esc_html_e('State:', 'directory-helpers'); ?></strong>
                    <select name="state">
                        <?php foreach ($states as $term): ?>
                            <?php $label = !empty($term->description) ? $term->description : $term->name; ?>
                            <option value="<?php echo esc_attr($term->slug); ?>" <?php selected($state_slug, $term->slug); ?>>
                                <?php echo esc_html($label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                
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
                
                <!-- Status hidden, always refining -->
                <input type="hidden" name="post_status" value="refining" />
                
                <label><strong><?php esc_html_e('Niche:', 'directory-helpers'); ?></strong>
                    <select name="niche">
                        <?php if (!empty($niches)): ?>
                            <?php foreach ($niches as $term): ?>
                                <option value="<?php echo esc_attr($term->slug); ?>" <?php selected($niche_slug, $term->slug); ?>>
                                    <?php echo esc_html($term->name); ?>
                                </option>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <option value="dog-trainer"><?php esc_html_e('Dog Trainer', 'directory-helpers'); ?></option>
                        <?php endif; ?>
                    </select>
                </label>
                
                <label><strong><?php esc_html_e('Profiles:', 'directory-helpers'); ?></strong>
                    <input type="number" name="min_count" value="<?php echo esc_attr($min_count); ?>" min="1" max="10" style="width: 60px;" />
                </label>
                
                <button type="submit" class="button button-secondary"><?php esc_html_e('Filter', 'directory-helpers'); ?></button>
            </div>
        </form>
    </div>
    
    <!-- Fast Publishing -->
    <?php if (!empty($profiles)): ?>
        <div style="background: #fff; padding: 20px; margin: 20px 0; border: 1px solid #ccd0d4; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
            <h2 style="margin-top: 0;">🚀 Publish Profiles & Create Cities</h2>
            <p>This will create missing city pages, publish all filtered profiles, and send cities for AI content. <strong>Reranking is skipped for speed.</strong></p>
            
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('Publish <?php echo count($profiles); ?> profiles? This will create missing city pages and trigger AI content generation.');">
                <?php wp_nonce_field('dh_prep_pro_publish'); ?>
                <input type="hidden" name="action" value="dh_prep_pro_publish" />
                <input type="hidden" name="state" value="<?php echo esc_attr($state_slug); ?>" />
                <input type="hidden" name="post_status" value="<?php echo esc_attr($post_status); ?>" />
                <input type="hidden" name="min_count" value="<?php echo esc_attr($min_count); ?>" />
                <input type="hidden" name="city" value="<?php echo esc_attr($city_slug); ?>" />
                <input type="hidden" name="niche" value="<?php echo esc_attr($niche_slug); ?>" />
                <input type="hidden" name="city_search" value="<?php echo esc_attr($city_search); ?>" />
                
                <button type="submit" class="button button-primary button-hero">
                    <?php echo sprintf(esc_html__('Publish Profiles & Create Cities (%d profiles)', 'directory-helpers'), count($profiles)); ?>
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
                    <button type="submit" class="button button-primary">Rerank, Purge, Prime</button>
                </form>
                
                <!-- Reset Tracking -->
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display: inline-block; margin-left: 20px;" onsubmit="return confirm('Reset tracking? This will clear the list of tracked profiles.');">
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
