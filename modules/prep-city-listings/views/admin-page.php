<?php
/**
 * Prep City Listings Admin Page View
 */
if (!defined('ABSPATH')) exit;
?>

<div class="wrap">
    <h1><?php esc_html_e('Prep City Listings', 'directory-helpers'); ?></h1>
    
    <?php if (isset($_GET['created'])): ?>
        <?php 
        $created_city_ids = get_transient('dh_prep_city_listings_created_' . get_current_user_id());
        if ($created_city_ids) {
            delete_transient('dh_prep_city_listings_created_' . get_current_user_id());
        }
        ?>
        <div class="notice notice-success is-dismissible">
            <p><strong>Created <?php echo (int)$_GET['created']; ?> city-listing pages.</strong></p>
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
    
    <?php if (isset($_GET['error'])): ?>
        <div class="notice notice-error is-dismissible">
            <p><strong>No cities selected for creation.</strong></p>
        </div>
    <?php endif; ?>
    
    <!-- Niche Selection -->
    <div style="background: #fff; padding: 20px; margin: 20px 0; border: 1px solid #ccd0d4; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
        <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>">
            <input type="hidden" name="page" value="dh-prep-city-listings" />
            
            <div style="display: flex; gap: 12px; align-items: center;">
                <label><strong><?php esc_html_e('Niche:', 'directory-helpers'); ?></strong>
                    <select name="niche">
                        <?php foreach ($niches as $niche): ?>
                            <option value="<?php echo esc_attr($niche->slug); ?>" <?php selected($niche_slug, $niche->slug); ?>>
                                <?php echo esc_html($niche->name); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                
                <button type="submit" class="button button-secondary"><?php esc_html_e('Filter', 'directory-helpers'); ?></button>
            </div>
        </form>
    </div>
    
    <!-- City Listings Section -->
    <?php if (!empty($cities_needing_listings)): ?>
        <div style="background: #fff; padding: 20px; margin: 20px 0; border: 1px solid #ccd0d4; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
            <h2 style="margin-top: 0;">🏙️ Cities Needing Listings (<?php echo count($cities_needing_listings); ?>)</h2>
            <p>These cities have published profiles but no city-listing page. Select cities to create listings in batches.</p>
            
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('Create city-listing pages for the selected cities? This will also trigger AI content generation.');">
                <?php wp_nonce_field('dh_prep_city_listings_create'); ?>
                <input type="hidden" name="action" value="dh_prep_city_listings_create" />
                <input type="hidden" name="niche" value="<?php echo esc_attr($niche_slug); ?>" />
                
                <div style="display: flex; gap: 12px; align-items: center; margin-bottom: 15px; flex-wrap: wrap;">
                    <label><strong>Batch Size:</strong>
                        <select name="batch_size">
                            <option value="1">1</option>
                            <option value="2">2</option>
                            <option value="3">3</option>
                            <option value="4">4</option>
                            <option value="5" selected>5</option>
                            <option value="10">10</option>
                        </select>
                    </label>
                    
                    <button type="button" class="button button-secondary" onclick="selectFirstN(5);">Select First 5</button>
                    <button type="button" class="button button-secondary" onclick="selectAll();">Select All</button>
                    <button type="button" class="button button-secondary" onclick="deselectAll();">Deselect All</button>
                    
                    <button type="submit" class="button button-primary">Create Selected City Listings</button>
                </div>
                
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th style="width: 40px;"><input type="checkbox" id="select-all-cities" onclick="toggleAllCities(this);" /></th>
                            <th>City</th>
                            <th>State</th>
                            <th style="width: 100px; text-align: center;">Profiles</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($cities_needing_listings as $city): ?>
                            <tr>
                                <td>
                                    <input type="checkbox" name="area_ids[]" value="<?php echo esc_attr($city->term_id); ?>" class="city-checkbox" />
                                </td>
                                <td><?php echo esc_html($city->name); ?></td>
                                <td><?php echo esc_html($city->state_name); ?></td>
                                <td style="text-align: center;"><strong><?php echo (int)$city->profile_count; ?></strong></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </form>
            
            <script>
            function toggleAllCities(checkbox) {
                var checkboxes = document.querySelectorAll('.city-checkbox');
                checkboxes.forEach(function(cb) { cb.checked = checkbox.checked; });
            }
            function selectFirstN(n) {
                deselectAll();
                var checkboxes = document.querySelectorAll('.city-checkbox');
                for (var i = 0; i < Math.min(n, checkboxes.length); i++) {
                    checkboxes[i].checked = true;
                }
            }
            function selectAll() {
                var checkboxes = document.querySelectorAll('.city-checkbox');
                checkboxes.forEach(function(cb) { cb.checked = true; });
                document.getElementById('select-all-cities').checked = true;
            }
            function deselectAll() {
                var checkboxes = document.querySelectorAll('.city-checkbox');
                checkboxes.forEach(function(cb) { cb.checked = false; });
                document.getElementById('select-all-cities').checked = false;
            }
            </script>
        </div>
    <?php else: ?>
        <div style="background: #fff; padding: 20px; margin: 20px 0; border: 1px solid #ccd0d4; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
            <p><?php esc_html_e('No cities found that need listings for the selected niche.', 'directory-helpers'); ?></p>
        </div>
    <?php endif; ?>
</div>
