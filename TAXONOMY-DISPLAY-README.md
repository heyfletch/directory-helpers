# Taxonomy Display Module

## Overview
Simple shortcodes to display taxonomy term names on profile pages.

## Shortcodes

### `[dh_city_name]`
Displays the city name from the area taxonomy.

**Attributes:**
- `post_id` - Profile post ID (default: current post)
- `strip_state` - Remove " - ST" suffix (default: `true`)

**Examples:**
```
[dh_city_name]
→ "Milwaukee"

[dh_city_name strip_state="false"]
→ "Milwaukee - WI"

[dh_city_name post_id="123"]
→ City name for profile #123
```

---

### `[dh_state_name]`
Displays the state name from the state taxonomy.

**Attributes:**
- `post_id` - Profile post ID (default: current post)
- `format` - Display format: `full` or `abbr` (default: `full`)

**Examples:**
```
[dh_state_name]
→ "Wisconsin"

[dh_state_name format="abbr"]
→ "WI"

[dh_state_name post_id="123"]
→ State name for profile #123
```

**Note:** Uses term description if available, otherwise term name.

---

### `[dh_niche_name]`
Displays the niche name from the niche taxonomy.

**Attributes:**
- `post_id` - Profile post ID (default: current post)
- `plural` - Pluralize the name (default: `false`)

**Examples:**
```
[dh_niche_name]
→ "Dog Trainer"

[dh_niche_name plural="true"]
→ "Dog Trainers"

[dh_niche_name post_id="123"]
→ Niche name for profile #123
```

---

## Multiple Area Terms Handling

### Problem
Some profiles have multiple area taxonomy terms (e.g., "Milwaukee - WI" AND "Waukesha - WI"). The plugin needs to determine which is the **primary** city.

### Solution
The module uses the ACF `city` field as the authoritative source:

1. **Single area term** → Use it (no ambiguity)
2. **Multiple area terms** → Match against ACF `city` field
   - ACF city: "Milwaukee"
   - Area terms: ["milwaukee-wi", "waukesha-wi"]
   - Match: Find term whose name starts with "milwaukee" (case-insensitive)
3. **No match** → Fallback to first term (alphabetical)

### Implementation
```php
private function get_primary_area_term($post_id) {
    $area_terms = get_the_terms($post_id, 'area');
    
    if (count($area_terms) === 1) {
        return $area_terms[0];
    }
    
    // Use ACF city field to determine primary
    $acf_city = get_field('city', $post_id);
    if ($acf_city) {
        foreach ($area_terms as $term) {
            if (stripos($term->name, $acf_city) === 0) {
                return $term;
            }
        }
    }
    
    return $area_terms[0]; // Fallback
}
```

### Modules Updated
- ✅ **Taxonomy Display** - Uses `get_primary_area_term()`
- ✅ **Profile Badges** - Uses `get_primary_area_term()`
- ⚠️ **Profile Rankings** - Still uses `$city_terms[0]` (needs update)
- ⚠️ **Listing Counts** - Still uses `$area_terms[0]` (needs update)

---

## Usage Examples

### Profile Template
```html
<h1>Meet [dh_niche_name] in [dh_city_name]</h1>
<p>Serving [dh_city_name], [dh_state_name]</p>

<!-- Output: -->
<h1>Meet Dog Trainer in Milwaukee</h1>
<p>Serving Milwaukee, Wisconsin</p>
```

### With Abbreviations
```html
<p>Located in [dh_city_name], [dh_state_name format="abbr"]</p>

<!-- Output: -->
<p>Located in Milwaukee, WI</p>
```

### Plural Niche
```html
<h2>Top [dh_niche_name plural="true"] in [dh_state_name]</h2>

<!-- Output: -->
<h2>Top Dog Trainers in Wisconsin</h2>
```

---

## Future Enhancements

### 1. Update Other Modules
Add `get_primary_area_term()` to:
- Profile Rankings (for accurate city rank calculations)
- Listing Counts (for accurate profile counts per city)
- Profile Production Queue (for city creation logic)

### 2. Shared Utility Class
Create a centralized helper class to avoid code duplication:
```php
// includes/class-dh-taxonomy-helpers.php
class DH_Taxonomy_Helpers {
    public static function get_primary_area_term($post_id) { ... }
    public static function get_city_name($post_id, $strip_state = true) { ... }
    public static function get_state_name($post_id, $format = 'full') { ... }
}
```

### 3. Enhanced Matching
Improve ACF city matching with fuzzy logic:
- Handle typos/variations
- Support multiple city name formats
- Log mismatches for manual review

### 4. Admin Warning
Add admin notice when profiles have multiple area terms without matching ACF city field.

---

## Testing

### Test Cases

1. **Single area term**
   - Profile with only "milwaukee-wi"
   - Should display: "Milwaukee"

2. **Multiple area terms with ACF match**
   - Profile with ["milwaukee-wi", "waukesha-wi"]
   - ACF city: "Milwaukee"
   - Should display: "Milwaukee"

3. **Multiple area terms without ACF match**
   - Profile with ["milwaukee-wi", "waukesha-wi"]
   - ACF city: empty or "Chicago"
   - Should display: First term (alphabetical)

4. **State abbreviation**
   - `[dh_state_name format="abbr"]`
   - Should display: "WI"

5. **Niche pluralization**
   - `[dh_niche_name plural="true"]`
   - Should display: "Dog Trainers"

---

## Notes

- All shortcodes return empty string if taxonomy term not found
- Output is escaped with `esc_html()` for security
- Shortcodes work on any post type but are designed for profiles
- Use `post_id` attribute to display data from other profiles
