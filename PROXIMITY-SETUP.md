# Proximity Query Setup Guide

## Overview
The proximity query system allows city listing pages to show profiles within a configurable radius when there aren't enough area-tagged profiles. This guide covers setup for new installations (staging → production).

---

## Initial Setup (Required on Each Site)

### 1. **Plugin Files**
Ensure these files are present:
- `includes/class-dh-bricks-query-helpers.php`
- `includes/class-dh-acf-fields.php`
- `includes/cli/class-analyze-radius-command.php`
- `views/admin-page.php` (updated with Proximity Query Settings)

### 2. **Configure Settings**
Go to: **WP Admin → Directory Helpers → Proximity Query Settings**

Set your **Minimum Profiles Threshold** (default: 10)
- This determines when proximity search activates
- If an area has fewer than this many profiles, proximity search will be used

### 3. **ACF Fields (Auto-Created)**
The plugin automatically adds these fields to the `area` taxonomy:
- `custom_radius` - Manual override (editable)
- `recommended_radius` - System-calculated (read-only)

**No manual ACF configuration needed** - fields are registered programmatically.

### 4. **Bricks Query Setup**
In your Bricks Query Loop for profiles, use this code in the **PHP** query editor:

```php
if ( class_exists( 'DH_Bricks_Query_Helpers' ) ) {
  return DH_Bricks_Query_Helpers::get_nearby_profiles_query_args();
}
return [];
```

**Why the `if` statement?**
- Prevents fatal errors if the plugin is deactivated
- Safe fallback (returns empty array)

**Optional: Force a specific radius**
```php
if ( class_exists( 'DH_Bricks_Query_Helpers' ) ) {
  return DH_Bricks_Query_Helpers::get_nearby_profiles_query_args( 15 );
}
return [];
```

**Recommended: Let the system decide**
Remove any hardcoded radius parameter and let the system use:
1. Custom radius (if set manually)
2. Recommended radius (from WP-CLI analysis)
3. Default 10 miles (fallback)

---

## Radius Analysis (Recommended)

### Run WP-CLI Command
After initial setup, analyze areas for a specific niche to calculate optimal radius values:

```bash
# Preview results (dry run) - niche slug is required
wp directory-helpers analyze-radius dog-trainer --dry-run

# Update term meta with recommended values
wp directory-helpers analyze-radius dog-trainer --update-meta
```

**Note:** The command only analyzes areas that have published city-listing pages with the specified niche.

### What This Does
- Tests radii: 2, 5, 10, 15, 20, 25, 30 miles
- Finds smallest radius that reaches your minimum threshold
- Updates `recommended_radius` term meta for each area
- Outputs summary table of areas needing attention

### When to Re-Run
- Quarterly maintenance
- After adding 100+ new profiles
- When changing minimum threshold setting

---

## How Radius is Determined

The system uses this priority order:

1. **Custom Radius** (if set manually in area term)
2. **Recommended Radius** (if calculated by WP-CLI)
3. **Default: 10 miles** (fallback)

---

## Behavior Explanation

### Threshold Logic
- **Area has ≥10 profiles:** Proximity query is **skipped** (performance optimization)
- **Area has <10 profiles:** Proximity query **runs** with configured radius

### Important Notes
- The threshold is now a **minimum guarantee** (with automatic radius expansion)
- If Bethesda has 6 area-tagged profiles and proximity finds 2 more within 10 miles (total: 8 < threshold of 10):
  - System automatically expands radius by +5, +10, +15, +20 miles
  - Stops when threshold is reached or max expansion (30 miles total) is hit
- If even the expanded radius doesn't reach threshold, you'll see fewer than the minimum
- To guarantee more profiles in sparse areas:
  - Tag more profiles with the area term, OR
  - Manually set higher custom_radius for that area

### Sorting Order
Results are sorted:
1. **Area-tagged profiles** (sorted by `city_rank`, then `distance`)
2. **Proximity-only profiles** (sorted by `city_rank`, then `distance`)

---

## Production Deployment Checklist

### Before Migrating
- [ ] Test on staging with real data
- [ ] Run `analyze-radius --dry-run` to preview results
- [ ] Note any custom radius values you've set manually

### On Production
- [ ] Deploy plugin files
- [ ] Go to **Directory Helpers** settings
- [ ] Set **Minimum Profiles Threshold** (same as staging)
- [ ] Click **Save Settings**
- [ ] Run WP-CLI command:
  ```bash
  wp directory-helpers analyze-radius --update-meta
  ```
- [ ] Verify Bricks queries are using `get_nearby_profiles_query_args()`
- [ ] Test a few city listing pages to confirm results

### Optional: Manual Overrides
For specific areas needing custom radius:
1. Go to **Taxonomies → Area → Edit Term**
2. Set **Custom Radius** field
3. Save

---

## Troubleshooting

### "Still showing fewer than threshold"
**This is expected behavior.** The threshold triggers proximity search, but doesn't guarantee that many results exist within the radius.

**Solutions:**
- Increase radius for that area (manually or re-run analysis with higher `--max-radius`)
- Tag more profiles with that area term
- Lower your threshold setting

### "No results showing"
Check:
1. Area term has `latitude` and `longitude` set
2. Profiles have `latitude` and `longitude` postmeta
3. Profiles are tagged with correct `niche` taxonomy
4. Profiles are published

### "Proximity not working"
Verify:
1. Settings saved in Directory Helpers admin
2. Bricks query uses `get_nearby_profiles_query_args()`
3. No PHP errors in debug.log

---

## Performance Notes

### Optimization Features
- **Threshold check:** Skips expensive proximity SQL when not needed
- **Single query:** Area-tagged and proximity results merged efficiently
- **Caching ready:** Architecture supports Redis/transient caching (not yet implemented)

### Expected Query Time
- **With threshold met:** ~50ms (simple taxonomy query)
- **With proximity:** ~200-500ms (depends on database size)

---

## Database Requirements

### Required Meta Keys
**Term Meta (area taxonomy):**
- `latitude` (required for proximity)
- `longitude` (required for proximity)
- `custom_radius` (optional, manual override)
- `recommended_radius` (optional, set by WP-CLI)

**Post Meta (profile post type):**
- `latitude` (required for proximity)
- `longitude` (required for proximity)
- `city_rank` (optional, for sorting)

All meta keys are automatically created by ACF when you set values in the admin.

---

## Support

For issues or questions:
1. Check debug.log for PHP errors
2. Run `wp directory-helpers analyze-radius --dry-run` to diagnose
3. Verify ACF fields are present on area taxonomy
4. Test with a single area term first before deploying site-wide
