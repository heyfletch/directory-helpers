# Primary Area Term Update - Implementation Summary

## Overview
Updated all modules to use a centralized `get_primary_area_term()` helper that intelligently handles profiles with multiple area taxonomy terms.

## Problem Solved
Previously, when a profile had multiple area terms (e.g., "Milwaukee - WI" and "Waukesha - WI"), the plugin would arbitrarily use the first term returned by `get_the_terms()`, which could be incorrect. This caused:
- Rankings calculated for the wrong city
- Badges displaying the wrong city name
- Profile counts attributed to the wrong city

## Solution
Created a shared utility class (`DH_Taxonomy_Helpers`) that uses the ACF `city` field to determine which area term is the "primary" city.

### Logic Flow
1. **Single area term** → Use it (no ambiguity)
2. **Multiple area terms** → Match against ACF `city` field value
   - ACF city: "Milwaukee"
   - Area terms: ["milwaukee-wi", "waukesha-wi"]
   - Match: Find term whose name starts with "milwaukee" (case-insensitive)
3. **No match** → Fallback to first term

## Files Created

### 1. `/includes/class-dh-taxonomy-helpers.php`
Shared utility class with static methods:
- `get_primary_area_term($post_id)` - Returns primary WP_Term
- `get_city_name($post_id, $strip_state)` - Returns city name string
- `get_state_name($post_id, $format)` - Returns state name (full or abbr)
- `get_niche_name($post_id, $plural)` - Returns niche name

### 2. `/modules/taxonomy-display/taxonomy-display.php`
New module providing shortcodes:
- `[dh_city_name]` - Display city name
- `[dh_state_name]` - Display state name
- `[dh_niche_name]` - Display niche name

## Modules Updated

### ✅ Profile Rankings
**File:** `modules/profile-rankings/profile-rankings.php`

**Changes:**
- Line 134: `city_rank_shortcode()` uses `DH_Taxonomy_Helpers::get_primary_area_term()`
- Line 158: City listing query uses primary area term
- Line 332: `recalculate_and_save_ranks()` uses primary area term

**Impact:** Rankings now calculated for the correct city when profiles have multiple area terms.

---

### ✅ Profile Badges
**File:** `modules/profile-badges/profile-badges.php`

**Changes:**
- Line 241: `get_eligible_badges()` uses shared helper
- Line 368: `get_badge_data()` uses shared helper
- Removed duplicate `get_primary_area_term()` method

**Impact:** Badges display correct city name and link to correct city-listing page.

---

### ✅ Listing Counts
**File:** `modules/listing-counts/listing-counts.php`

**Changes:**
- Line 65: `update_counts_on_profile_save()` uses shared helper

**Impact:** Profile counts now attributed to the correct city.

---

### ✅ Taxonomy Display
**File:** `modules/taxonomy-display/taxonomy-display.php`

**Changes:**
- Removed duplicate helper methods
- All shortcodes now use `DH_Taxonomy_Helpers` static methods

**Impact:** Shortcodes display correct city/state/niche names.

---

## Admin Page Documentation

### Added Comprehensive Shortcode Reference
**File:** `views/admin-page.php`

New documentation section includes:
- **Taxonomy Display Shortcodes** - Table with examples
- **Profile Badge Shortcodes** - Usage and badge URLs
- **Ranking Shortcodes** - City/state rank display options
- **Other Shortcodes** - Breadcrumbs, video, search, etc.
- **Multiple Area Terms Warning** - Explains ACF city field logic

Accessible at: **WP Admin → Directory Helpers**

---

## Testing Required

### 1. Profiles with Single Area Term
- ✅ Should work exactly as before
- ✅ No changes to existing behavior

### 2. Profiles with Multiple Area Terms + Matching ACF City
**Test Case:**
- Area terms: ["milwaukee-wi", "waukesha-wi"]
- ACF city field: "Milwaukee"

**Expected Results:**
- `[dh_city_name]` → "Milwaukee"
- `[dh_city_rank]` → Ranking for Milwaukee
- City badge → Links to Milwaukee city-listing
- Profile counted in Milwaukee's `_profile_count`

### 3. Profiles with Multiple Area Terms + No ACF City
**Test Case:**
- Area terms: ["milwaukee-wi", "waukesha-wi"]
- ACF city field: empty

**Expected Results:**
- Uses first term (alphabetical by term_id)
- Behavior is consistent but may not be ideal
- **Recommendation:** Populate ACF city field for these profiles

### 4. Shortcode Tests
```
[dh_city_name] → "Milwaukee"
[dh_city_name strip_state="false"] → "Milwaukee - WI"
[dh_state_name] → "Wisconsin"
[dh_state_name format="abbr"] → "WI"
[dh_niche_name] → "Dog Trainer"
[dh_niche_name plural="true"] → "Dog Trainers"
```

### 5. Badge Tests
- City badge displays correct city name
- City badge links to correct city-listing page
- Rankings calculated for correct city
- Profile counts accurate per city

---

## Migration Notes

### No Database Changes Required
- Uses existing ACF `city` field
- No new tables or meta fields
- Backward compatible with existing data

### ACF City Field Importance
The ACF `city` field is now **critical** for profiles with multiple area terms:
- Previously unused or inconsistent
- Now used as tie-breaker for primary city
- Recommend auditing profiles with multiple area terms to ensure ACF city field is populated

### Query to Find Profiles Needing Attention
```sql
-- Profiles with multiple area terms
SELECT p.ID, p.post_title, COUNT(tr.term_taxonomy_id) as term_count
FROM wp_posts p
INNER JOIN wp_term_relationships tr ON p.ID = tr.object_id
INNER JOIN wp_term_taxonomy tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
WHERE p.post_type = 'profile'
AND p.post_status = 'publish'
AND tt.taxonomy = 'area'
GROUP BY p.ID
HAVING term_count > 1
ORDER BY term_count DESC;
```

---

## Future Enhancements

### 1. Admin Warning System
Add admin notice when profiles have multiple area terms without matching ACF city field:
```php
// In admin_notices hook
if (profile has multiple area terms && ACF city doesn't match any) {
    echo "Warning: Profile #{$post_id} has multiple cities but ACF city field doesn't match. Please review.";
}
```

### 2. Bulk Update Tool
Create admin tool to:
- Find all profiles with multiple area terms
- Attempt to auto-populate ACF city field based on term names
- Show list for manual review

### 3. Enhanced Matching
Improve fuzzy matching for ACF city field:
- Handle typos/variations ("Milwaukie" vs "Milwaukee")
- Support multiple formats ("St. Louis" vs "Saint Louis")
- Log mismatches for manual review

### 4. Profile Edit Screen Meta Box
Add meta box showing:
- All area terms assigned to profile
- Which one is considered "primary"
- Warning if ACF city doesn't match

---

## Rollback Plan

If issues arise, you can revert by:

1. **Restore old module files** from backup
2. **Remove shared utility class:**
   - Delete `/includes/class-dh-taxonomy-helpers.php`
   - Remove `require_once` from `directory-helpers.php` line 27
3. **Disable Taxonomy Display module** (won't break anything, just removes shortcodes)

**Note:** Profile Rankings and Listing Counts will revert to using first area term.

---

## Performance Impact

### Minimal
- `get_primary_area_term()` is lightweight (simple term loop)
- Results are not cached (terms already cached by WordPress)
- No additional database queries
- ACF field read is already cached

### Optimization Opportunities
If performance becomes an issue:
1. Cache primary area term in post meta
2. Update cache when area terms or ACF city field changes
3. Use object cache for frequently accessed profiles

---

## Summary

✅ **All modules updated** to use centralized primary area term logic  
✅ **Shared utility class** created to avoid code duplication  
✅ **New shortcodes** added for taxonomy display  
✅ **Admin documentation** added with comprehensive examples  
✅ **Backward compatible** with existing data  
✅ **No database migrations** required  

**Next Steps:**
1. Test on staging with profiles that have multiple area terms
2. Audit ACF city field data for profiles with multiple area terms
3. Deploy to production after verification
4. Monitor for any issues with rankings or badge display
