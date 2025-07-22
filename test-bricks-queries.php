// Copy everything below this line into Bricks Query Loop PHP Editor

if (is_singular('state-listing')) {
    $terms = get_the_terms(get_the_ID(), 'state');
    if ($terms && !is_wp_error($terms)) {
        $state_slug = $terms[0]->slug;
        
        global $wpdb;
        $city_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} 
             WHERE post_type = 'city-listing' 
             AND post_status = 'publish' 
             AND post_name LIKE %s",
            '%-' . $state_slug . '-%'
        ));
        
        return [
            'post_type' => 'city-listing',
            'post_status' => 'publish',
            'post__in' => !empty($city_ids) ? $city_ids : [0],
            'posts_per_page' => -1,
            'orderby' => 'title',
            'order' => 'ASC'
        ];
    }
}

return ['post__in' => [0]];
