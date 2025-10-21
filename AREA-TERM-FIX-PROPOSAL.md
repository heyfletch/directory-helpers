# Area Term Assignment Fix Proposal

## Investigation Summary

### What We Found
8 city listings had incorrect area terms assigned:
- Carlisle, IA → had `carlisle-pa` instead of `carlisle-ia`
- Richmond, KY → had `richmond-va` instead of `richmond-ky`
- Greenville, SC → had `greenville-tx` instead of `greenville-sc`
- Dayton, OH → had `dayton-tx` instead of `dayton-oh`
- Milford, OH → had `milford-ct` instead of `milford-oh`
- Columbia, TN → had `columbia-md` instead of `columbia-tn`
- Richmond, IN → had `richmond-va` instead of `richmond-in`
- Lancaster, NY → had `lancaster-pa` instead of `lancaster-ny`

### Root Cause Analysis

The `cleanup_area_terms()` function is **working as intended** - it removes " - ST" suffixes from area term **names** (not slugs) for display purposes. This is correct behavior.

The issue is **NOT** with the cleanup function itself, but with how city listings inherit area terms from profiles.

#### The Problem Flow:

1. **Initial State**: Area terms have unique names like "Richmond - VA", "Richmond - KY"
2. **Cleanup Runs**: Names become "Richmond" but slugs stay unique (richmond-va, richmond-ky)
3. **City Listing Creation**: 
   ```php
   // Line 587-591 in profile-production-queue.php
   foreach ($profile_ids as $pid) {
       $area_terms = get_the_terms($pid, 'area');
       if (!empty($area_terms) && !is_wp_error($area_terms)) {
           $area = $area_terms[0];  // ← TAKES FIRST TERM
           $unique_cities[$area->slug] = $area->name;
       }
   }
   ```

4. **The Bug**: If a profile has multiple area terms (or the wrong area term), the city listing inherits it

### Evidence from Investigation

- Profiles with correct area terms: Richmond, KY profiles have `richmond-ky` ✓
- Some profiles have multiple area terms (e.g., Baltimore profiles have both baltimore-md and sparrows-point-md)
- The `get_the_terms()` call returns `$area_terms[0]` - the FIRST term, which might not be the correct one

## Proposed Solution

### Option A: Validate Area Term Against State (RECOMMENDED)

When creating city listings, validate that the area term's slug matches the expected state code.

**Changes needed in 3 files:**

1. **profile-production-queue.php** (line 584-592)
2. **prep-pro.php** (similar location)
3. **prep-profiles-by-state.php** (similar location)

**Implementation:**

```php
// Extract unique cities from this batch
$unique_cities = array();
foreach ($profile_ids as $pid) {
    $area_terms = get_the_terms($pid, 'area');
    if (!empty($area_terms) && !is_wp_error($area_terms)) {
        // NEW: Find the area term that matches the state we're processing
        $correct_area_term = null;
        foreach ($area_terms as $area_term) {
            // Check if area term slug ends with the state code
            // e.g., richmond-ky matches state 'ky' or 'kentucky'
            if (preg_match('/-' . preg_quote($state_slug, '/') . '$/', $area_term->slug)) {
                $correct_area_term = $area_term;
                break;
            }
            // Also check if state slug is 2 letters and matches
            if (strlen($state_slug) === 2 && 
                preg_match('/-' . preg_quote($state_slug, '/') . '$/', $area_term->slug)) {
                $correct_area_term = $area_term;
                break;
            }
        }
        
        // If we found a matching area term, use it
        if ($correct_area_term) {
            $unique_cities[$correct_area_term->slug] = $correct_area_term->name;
        } else {
            // Fallback to first term (current behavior)
            $area = $area_terms[0];
            $unique_cities[$area->slug] = $area->name;
        }
    }
}
```

### Option B: Use Only Single Area Term Per Profile

Enforce that profiles have only ONE area term, and clean up profiles with multiple area terms.

**Pros:**
- Simpler logic
- Cleaner data model

**Cons:**
- Some businesses legitimately serve multiple cities
- Would require data cleanup of existing profiles

### Option C: Match by State Taxonomy

Since profiles have state taxonomy terms, we could validate:

```php
$state_terms = get_the_terms($pid, 'state');
$area_terms = get_the_terms($pid, 'area');

// Find area term that matches the state we're processing
foreach ($area_terms as $area_term) {
    // Extract state from area slug (e.g., richmond-ky → ky)
    if (preg_match('/-([a-z]{2})$/', $area_term->slug, $matches)) {
        $area_state_code = $matches[1];
        // Check if this matches our target state
        if ($state_slug === $area_state_code || 
            (isset($state_terms[0]) && $state_terms[0]->slug === $area_state_code)) {
            $correct_area_term = $area_term;
            break;
        }
    }
}
```

## Recommendation

**Use Option A** - Validate area term against state during city listing creation.

### Why Option A is Best:

1. **Keeps cleanup_area_terms()** - Display names stay clean ("Richmond" not "Richmond - KY")
2. **Prevents future issues** - Even if profiles have wrong/multiple area terms, city listings get the right one
3. **Non-breaking** - Doesn't require data cleanup or schema changes
4. **Defensive** - Validates data at the point of use

### Implementation Plan:

1. Update `process_profile_batch()` in profile-production-queue.php
2. Update similar logic in prep-pro.php
3. Update similar logic in prep-profiles-by-state.php
4. Test with cities that have duplicate names (Richmond, Dallas, etc.)
5. Run investigation script to verify no new issues

### Code Locations to Update:

- `/modules/profile-production-queue/profile-production-queue.php` lines 584-592
- `/modules/prep-pro/prep-pro.php` (find similar foreach loop)
- `/modules/prep-profiles-by-state/prep-profiles-by-state.php` (if it creates city listings)

## Testing Checklist

After implementing the fix:

- [ ] Create test profiles in Richmond, VA and Richmond, KY
- [ ] Run production queue for both states
- [ ] Verify city listings get correct area terms
- [ ] Check that cleanup_area_terms still works (names have no " - ST")
- [ ] Run investigate-city-area-terms.php to verify no issues
- [ ] Test with other duplicate city names (Columbia, Springfield, etc.)
