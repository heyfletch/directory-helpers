# City-State Relationships in Directory Helpers Plugin

## Overview
This document explains how city-listing and state-listing custom post types are related in the Directory Helpers plugin. This is crucial for AI coders working on breadcrumbs, queries, and other features that need to connect cities to their states.

## Key Relationship Logic

### The Problem
- City-listing posts do NOT have state taxonomy terms directly assigned
- The relationship between cities and states is established through post slug patterns
- This is different from profile posts which DO have taxonomy terms assigned

### The Solution: Slug Pattern Matching

#### City Post Slug Pattern
City-listing posts follow this slug pattern:
```
{city-name}-{state-code}-dog-trainers
```

**Examples:**
- `austin-tx-dog-trainers`
- `miami-fl-dog-trainers` 
- `new-york-ny-dog-trainers`

#### State Code Extraction
To find the state for a city-listing post:

1. **Get the city post slug**: `$city_slug = $post->post_name;`
2. **Extract state code using regex**: 
   ```php
   if (preg_match('/^(.+)-([a-z]{2})-dog-trainers$/', $city_slug, $matches)) {
       $state_slug = $matches[2]; // Extract the 2-letter state code
   }
   ```
3. **Find corresponding state-listing post**:
   ```php
   $state_listing_args = array(
       'post_type' => 'state-listing',
       'post_status' => 'publish',
       'posts_per_page' => 1,
       'tax_query' => array(
           array(
               'taxonomy' => 'state',
               'field' => 'slug',
               'terms' => $state_slug,
           ),
       ),
   );
   $state_query = new WP_Query($state_listing_args);
   ```

## Reverse Relationship: State to Cities

From `/test-bricks-queries.php`, to find all cities in a state:

```php
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
    }
}
```

## Implementation Examples

### Breadcrumbs (Current Implementation)
- **File**: `/modules/breadcrumbs/breadcrumbs.php`
- **Method**: `generate_breadcrumbs()` in city-listing section
- **Usage**: Creates Home > State > City breadcrumb trail

### Query Loops
- **File**: `/test-bricks-queries.php`
- **Usage**: Find all cities within a state for listing pages

## Important Notes for AI Coders

1. **Never assume city-listing posts have state taxonomy terms** - they don't!
2. **Always use slug pattern matching** to connect cities to states
3. **State-listing posts DO have state taxonomy terms** - use those for display names
4. **The regex pattern is critical**: `^(.+)-([a-z]{2})-dog-trainers$`
5. **Debug helpers are available** - uncomment debug lines in breadcrumbs.php if needed

## Taxonomy Structure

### What HAS taxonomy terms:
- **profile posts**: Have state, area (city), and niche taxonomy terms
- **state-listing posts**: Have state taxonomy terms

### What DOESN'T have taxonomy terms:
- **city-listing posts**: No state taxonomy terms (use slug pattern instead)

## Future Development

When building features that need city-state relationships:
1. Check if you're working with city-listing or state-listing posts
2. Use the appropriate method (slug pattern vs taxonomy terms)
3. Reference this document and existing implementations
4. Test with debug helpers when needed

## Related Files
- `/modules/breadcrumbs/breadcrumbs.php` - Breadcrumb implementation
- `/test-bricks-queries.php` - Query examples
- `/modules/city-listing-generator/city-listing-generator.php` - How cities are created
