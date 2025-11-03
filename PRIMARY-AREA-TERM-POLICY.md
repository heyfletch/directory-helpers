# Primary Area Term Policy

## Overview
When referencing a profile's city/area, **always use the primary area term** via the `DH_Taxonomy_Helpers::get_primary_area_term()` helper function.

## Background
Some profiles have multiple area taxonomy terms (e.g., a trainer serves both Milwaukee and Waukesha). To ensure consistency across the site, we determine a single "primary" city for each profile.

## How Primary Area Term is Determined

The `DH_Taxonomy_Helpers::get_primary_area_term()` method uses this logic:

1. **Single area term** → Use it (no ambiguity)
2. **Multiple area terms** → Match against ACF `city` field value
   - Compare (case-insensitive) the ACF `city` field against each area term name
   - Return the first area term whose name starts with the ACF city value
   - Example: ACF city "Milwaukee" matches area term "Milwaukee - WI"
3. **No match or no ACF city** → Fallback to first term (alphabetical by term_id)

## Required Helper Function

```php
// ✅ CORRECT - Use the helper
$primary_area_term = DH_Taxonomy_Helpers::get_primary_area_term($post_id);
if ($primary_area_term) {
    $city_name = $primary_area_term->name;
    $city_term_id = $primary_area_term->term_id;
}

// ❌ INCORRECT - Do not use directly
$area_terms = get_the_terms($post_id, 'area');
$city_name = $area_terms[0]->name; // WRONG!
```

## When to Use Primary Area Term

### ✅ Always use for PROFILES:
- Displaying city name in content
- Generating URLs/slugs
- Calculating rankings (`city_rank`)
- Badge generation
- Breadcrumbs
- AI content generation
- Shortlinks
- Nearest cities queries
- Any user-facing display

### ⚠️ Exception for CITY-LISTINGS:
City-listing posts should only have ONE area term, so you can use `$area_terms[0]` for these:
```php
if ($post->post_type === 'city-listing') {
    $area_terms = get_the_terms($post->ID, 'area');
    $area_term = $area_terms[0]; // OK for city-listings
}
```

## Modules Updated (Nov 3, 2025)

### ✅ High Priority (User-Facing):
- **Profile Rankings** - Uses primary term for rank calculation and display
- **Profile Badges** - Uses primary term for badge generation
- **Taxonomy Display** - Uses helper for all shortcodes
- **Breadcrumbs** - Uses primary term for navigation
- **Nearest Cities** - Uses primary term for city queries
- **AI Content Generator** - Uses primary term for profile content

### ✅ Medium Priority:
- **Shortlinks** - Uses primary term for URL generation
- **Listing Counts** - Uses primary term when updating from profile saves

### ✅ Low Priority (Admin Tools):
- **Content Production Queue** - Documented that city-listings use first term
- Other batch processing tools remain unchanged (low impact)

## Helper Class Location

```php
// File: /includes/class-dh-taxonomy-helpers.php
// Loaded in: directory-helpers.php (line 26)

class DH_Taxonomy_Helpers {
    public static function get_primary_area_term($post_id) { ... }
    public static function get_city_name($post_id, $strip_state = true) { ... }
    public static function get_state_name($post_id, $format = 'full') { ... }
    public static function get_niche_name($post_id, $plural = false) { ... }
}
```

## Convenience Methods

For common use cases, use these helper methods instead:

```php
// Get just the city name (stripped of " - ST" suffix)
$city = DH_Taxonomy_Helpers::get_city_name($post_id);
// Output: "Milwaukee"

// Get state name (full name from description or term name)
$state = DH_Taxonomy_Helpers::get_state_name($post_id);
// Output: "Wisconsin"

// Get state abbreviation
$state_abbr = DH_Taxonomy_Helpers::get_state_name($post_id, 'abbr');
// Output: "WI"

// Get niche name
$niche = DH_Taxonomy_Helpers::get_niche_name($post_id);
// Output: "Dog Trainer"

// Get plural niche
$niches = DH_Taxonomy_Helpers::get_niche_name($post_id, true);
// Output: "Dog Trainers"
```

## Testing Checklist

When adding new features that reference a profile's city:

- [ ] Does it use `DH_Taxonomy_Helpers::get_primary_area_term()`?
- [ ] Does it check if the result is false/null before using?
- [ ] Have you tested with profiles that have multiple area terms?
- [ ] Have you tested with profiles where ACF city field doesn't match any area term?

## Future Considerations

### Data Quality
- Consider adding an admin notice when profiles have multiple area terms without matching ACF city field
- Consider adding a WP-CLI command to audit and fix mismatched area terms

### Performance
- The helper method is lightweight (just taxonomy queries + simple string comparison)
- Results are not cached - consider adding transient caching if performance issues arise

### Alternative Approaches Considered
1. **Store primary term ID in ACF** - Rejected: adds complexity, data could get out of sync
2. **Only allow one area term per profile** - Rejected: some trainers legitimately serve multiple cities
3. **Store separate rank per city** - Rejected: ACF fields can only store one value, would require custom table

## Questions?

If you're unsure whether to use the primary area term helper, ask:
1. Am I working with a **profile** post type? → Use the helper
2. Am I working with a **city-listing** post type? → First term is OK (they should only have one)
3. Am I displaying something to **users**? → Definitely use the helper
4. Am I in an **admin-only batch tool**? → Use the helper for consistency, but lower priority

---

**Last Updated:** November 3, 2025  
**Related Files:**
- `/includes/class-dh-taxonomy-helpers.php`
- `/TAXONOMY-DISPLAY-README.md`
