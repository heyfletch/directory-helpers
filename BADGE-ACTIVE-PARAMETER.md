# Badge Active Parameter - Implementation Summary

## Overview
Added support for `?active=1` query parameter on SVG badge endpoints to prevent invalid nested links when badges are embedded in `<a>` tags.

## Problem Solved
When clients embed badges using the provided HTML code that wraps the badge in an `<a>` tag, the SVG's internal `<a>` tag creates invalid nested links:

```html
<!-- INVALID: Nested links -->
<a href="https://site.com/city-page">
  <svg>
    <a href="https://site.com/profile">  <!-- ❌ Nested link -->
      <!-- badge content -->
    </a>
  </svg>
</a>
```

## Solution
The `?active=1` parameter strips the internal `<a>` tag from the SVG, allowing clean nesting:

```html
<!-- VALID: No nested links -->
<a href="https://site.com/city-page">
  <svg>
    <!-- ✅ No internal link, just visual content -->
    <rect ... />
    <text>Top 5</text>
  </svg>
</a>
```

## Implementation Details

### 1. Badge Endpoint Detection
**File:** `modules/profile-badges/profile-badges.php` (Line 179)

```php
// Check for active parameter (strips internal <a> tag for nested embed)
$active = isset($_GET['active']) && $_GET['active'] == '1';
```

### 2. Separate Caching
**Line 202:**
```php
// Try to get cached SVG (separate cache for active/inactive)
$cache_key = 'dh_badge_' . $post_id . '_' . $badge_type . ($active ? '_active' : '');
```

Each mode has its own cache to avoid serving the wrong version.

### 3. SVG Generation
**Line 466-534:**

The `generate_badge_svg()` method now accepts an `$active` parameter:

```php
private function generate_badge_svg($data, $active = false) {
    // ... setup code ...
    
    // Add clickable link wrapper (only if not active mode)
    if (!$active) {
        $svg .= '<a href="' . $url . '" target="_parent">';
    }
    
    // ... badge content ...
    
    // Close link (only if not active mode) and SVG
    if (!$active) {
        $svg .= '</a>';
    }
    $svg .= '</svg>';
}
```

### 4. Embed Code Generation
**Line 589-603:**

The `[dh_celebration]` shortcode now generates embed codes with `?active=1`:

```php
$badge_url_active = home_url('/badge/' . $post_id . '/' . $type . '.svg?active=1');

// Determine target URL based on badge type
$target_url = $badge_data['profile_url'];
if ($type === 'city' && !empty($badge_data['city_url'])) {
    $target_url = $badge_data['city_url'];
} elseif ($type === 'state' && !empty($badge_data['state_url'])) {
    $target_url = $badge_data['state_url'];
}

// Generate embed code with active=1 to prevent nested links
$embed_code = '<a href="' . esc_url($target_url) . '">' . "\n";
$embed_code .= '  <img src="' . esc_url($badge_url_active) . '" alt="' . esc_attr($alt_text) . '" width="250" height="auto" />' . "\n";
$embed_code .= '</a>';
```

**Key improvements:**
- City badges link to city-listing page
- State badges link to state-listing page
- Profile badges link to profile page
- All use `?active=1` to strip internal SVG link

## Usage Examples

### Standard Mode (Self-Linking)
```html
<!-- SVG includes internal <a> tag -->
<img src="https://staggd.1wp.site/badge/7281/state.svg" alt="State Badge" width="250" height="auto" />
```

**When clicked:** Navigates to the profile/city/state page (depending on badge type)

### Active Mode (For Nested Embeds)
```html
<!-- SVG has no internal <a> tag -->
<a href="https://staggd.1wp.site/top/maryland-dog-trainers/">
  <img src="https://staggd.1wp.site/badge/7281/state.svg?active=1" alt="State Badge" width="250" height="auto" />
</a>
```

**When clicked:** Navigates to the wrapper link URL (state listing page)

## Embed Code Provided by [dh_celebration]

### City Badge
```html
<a href="https://staggd.1wp.site/city/milwaukee-wi/">
  <img src="https://staggd.1wp.site/badge/1713/city.svg?active=1" alt="City Badge for Profile Name" width="250" height="auto" />
</a>
```

### State Badge
```html
<a href="https://staggd.1wp.site/top/wisconsin-dog-trainers/">
  <img src="https://staggd.1wp.site/badge/1713/state.svg?active=1" alt="State Badge for Profile Name" width="250" height="auto" />
</a>
```

### Profile Badge
```html
<a href="https://staggd.1wp.site/profile/profile-name/">
  <img src="https://staggd.1wp.site/badge/1713/profile.svg?active=1" alt="Profile Badge for Profile Name" width="250" height="auto" />
</a>
```

## Cache Strategy

### Two Separate Caches Per Badge
Each badge now has two cached versions:

1. **Standard:** `dh_badge_{post_id}_{type}`
   - Includes internal `<a>` tag
   - Used for direct embedding

2. **Active:** `dh_badge_{post_id}_{type}_active`
   - No internal `<a>` tag
   - Used for nested embeds

**Cache TTL:** 1 minute (for testing) - change to 1 hour for production

**Example:**
- `dh_badge_7281_state` → SVG with `<a>` tag
- `dh_badge_7281_state_active` → SVG without `<a>` tag

## Testing Checklist

### Standard Mode (No ?active=1)
- [ ] Badge URL works: `/badge/7281/state.svg`
- [ ] SVG includes `<a href="...">` tag
- [ ] Clicking badge navigates to correct page
- [ ] Badge displays correctly in browser
- [ ] Badge caches properly

### Active Mode (?active=1)
- [ ] Badge URL works: `/badge/7281/state.svg?active=1`
- [ ] SVG does NOT include `<a>` tag
- [ ] Badge displays identically to standard mode
- [ ] Badge caches separately from standard mode
- [ ] Embed code from `[dh_celebration]` uses `?active=1`

### Embed Code Testing
- [ ] Copy embed code from `[dh_celebration]`
- [ ] Paste into external website HTML
- [ ] Verify no nested link warnings in browser console
- [ ] Clicking badge navigates to correct listing page
- [ ] Badge displays correctly on external site

### Cache Validation
- [ ] Visit standard badge URL
- [ ] Check transient: `dh_badge_7281_state` exists
- [ ] Visit active badge URL
- [ ] Check transient: `dh_badge_7281_state_active` exists
- [ ] Both transients have different content
- [ ] Standard has `<a>` tag, active doesn't

## Browser Compatibility

### SVG Support
- ✅ All modern browsers support SVG
- ✅ SVG `<a>` tags work in all browsers
- ✅ Conditional `<a>` tag doesn't affect rendering

### Nested Links
Without `?active=1`:
- ❌ Invalid HTML (nested `<a>` tags)
- ⚠️ Unpredictable click behavior
- ⚠️ May fail HTML validation

With `?active=1`:
- ✅ Valid HTML (single `<a>` tag)
- ✅ Predictable click behavior
- ✅ Passes HTML validation

## Performance Impact

### Minimal
- **Cache:** Separate cache keys prevent conflicts
- **Query:** Single `$_GET` check (negligible overhead)
- **SVG Generation:** Two conditional `if` statements
- **Memory:** Doubles cache storage (but SVGs are small ~2-3KB each)

### Cache Storage
- **Before:** ~15,000 cached SVGs (5000 profiles × 3 types)
- **After:** ~30,000 cached SVGs (5000 profiles × 3 types × 2 modes)
- **Total:** ~90MB cache footprint (still minimal)

## Security Considerations

### Parameter Validation
```php
$active = isset($_GET['active']) && $_GET['active'] == '1';
```

- Only accepts exact value `'1'`
- Any other value treated as false
- No SQL injection risk (not used in queries)
- No XSS risk (not output to HTML)

### Obscurity
The `active=1` parameter is intentionally obscure:
- Not documented in public-facing areas
- Only provided in embed codes
- Prevents users from accidentally breaking embeds

## Documentation Updates

### Admin Page
**File:** `views/admin-page.php`

Added comprehensive documentation:
- Badge URLs section updated
- New "Embed Modes" table
- Example embed codes
- Explanation of when to use each mode

### README
**File:** `BADGE-ACTIVE-PARAMETER.md` (this file)

Complete implementation details for developers.

## Migration Notes

### No Breaking Changes
- Existing badge URLs continue to work
- Standard mode is default (backward compatible)
- New `?active=1` parameter is opt-in
- Existing embeds won't break

### Recommended Actions
1. Update any hardcoded embed codes to use `?active=1`
2. Test embed codes on external sites
3. Verify cache is working for both modes
4. Monitor for any nested link issues

## Future Enhancements

### 1. Additional Parameters
Consider adding more query parameters:
- `?size=small|medium|large` - Different badge sizes
- `?theme=light|dark` - Color schemes
- `?format=png` - Rasterized output

### 2. Embed Code Variations
Provide multiple embed code options:
- Minimal (just image)
- Standard (with link)
- Advanced (with tracking parameters)

### 3. Admin Preview
Add preview in admin showing both modes side-by-side.

### 4. Validation Tool
Create tool to scan external sites and detect nested link issues.

## Rollback Plan

If issues arise:

1. **Remove active parameter check:**
   ```php
   // Line 179 - Comment out
   // $active = isset($_GET['active']) && $_GET['active'] == '1';
   $active = false; // Always use standard mode
   ```

2. **Revert embed codes:**
   ```php
   // Line 589 - Remove ?active=1
   $badge_url_active = home_url('/badge/' . $post_id . '/' . $type . '.svg');
   ```

3. **Clear cache:**
   ```php
   // Delete all badge transients
   global $wpdb;
   $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_dh_badge_%'");
   ```

## Summary

✅ **Implemented** `?active=1` parameter for badge endpoints  
✅ **Prevents** invalid nested links in embed codes  
✅ **Maintains** backward compatibility with existing URLs  
✅ **Provides** clean embed codes via `[dh_celebration]`  
✅ **Caches** both modes separately for performance  
✅ **Documents** usage in admin page  

**Result:** Clients can safely embed badges on their websites without HTML validation errors or unpredictable link behavior.
