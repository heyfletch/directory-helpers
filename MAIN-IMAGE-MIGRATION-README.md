# Main Image to Featured Image Migration

## Overview

This WP-CLI command safely migrates images from the ACF `main_image` field to WordPress featured images for profile CPTs. It includes comprehensive safety features, batch processing, and automatic cleanup.

## Why This Migration is Safe

### Automatic Handling by WordPress

1. **Thumbnail Generation**: When you call `set_post_thumbnail()`, WordPress automatically:
   - Associates the attachment with the post
   - Updates post meta `_thumbnail_id`
   - Generates all registered image sizes if they don't exist
   - No need to manually save the post

2. **Metadata Management**: WordPress handles:
   - Attachment metadata (dimensions, file paths, etc.)
   - Image size variations
   - MIME types and file information

3. **Cache Integration**: The command automatically:
   - Purges LiteSpeed cache for updated posts
   - Clears the ACF `main_image` field after successful migration

### Optional Thumbnail Regeneration

While WordPress generates thumbnails automatically when setting a featured image, you can use the `--regenerate-thumbs` flag to:
- Force regeneration of all thumbnail sizes
- Ensure consistency across all image sizes
- Update metadata if needed

This uses `wp_generate_attachment_metadata()` which:
- Reads the original image file
- Generates all registered image sizes
- Updates attachment metadata in the database

## Command Usage

### Test with a Few Profiles (Recommended First Step)

```bash
# Dry run with first 5 profiles (shows what would happen)
wp directory-helpers migrate-main-image --dry-run --limit=5

# Actual migration of first 5 profiles
wp directory-helpers migrate-main-image --limit=5

# Verify the 5 profiles in WordPress admin to ensure everything looks correct
```

### Migrate Small Batches

```bash
# Migrate 10 profiles at a time
wp directory-helpers migrate-main-image --limit=10

# Migrate 25 profiles at a time
wp directory-helpers migrate-main-image --limit=25
```

### Migrate All Profiles

```bash
# Dry run all profiles (see full report without changes)
wp directory-helpers migrate-main-image --dry-run

# Migrate all profiles
wp directory-helpers migrate-main-image

# Migrate all profiles and regenerate thumbnails (slower but thorough)
wp directory-helpers migrate-main-image --regenerate-thumbs
```

## Command Options

| Option | Description |
|--------|-------------|
| `--dry-run` | Shows what would be changed without making any actual changes |
| `--limit=<number>` | Limits the number of profiles to process (useful for testing) |
| `--force` | Shows profiles that already have featured images in the report (doesn't override them) |
| `--regenerate-thumbs` | Regenerates all thumbnail sizes after setting featured image |

## What the Command Does

### For Each Profile:

1. **Checks if profile has a featured image**
   - Skips profiles that already have a featured image (unless `--force` flag)
   - This prevents overwriting existing featured images

2. **Retrieves the main_image ACF field**
   - Handles all ACF return formats (ID, Array, URL)
   - Validates the attachment exists in the media library
   - Skips if field is empty or invalid

3. **Sets the featured image**
   - Uses WordPress's `set_post_thumbnail()` function
   - Automatically updates post metadata
   - No need to manually save the post

4. **Regenerates thumbnails (optional)**
   - If `--regenerate-thumbs` flag is used
   - Generates all registered image sizes
   - Updates attachment metadata

5. **Clears the main_image field**
   - Removes the ACF field value with `delete_field()`
   - Only happens if featured image was successfully set

6. **Purges cache**
   - Automatically purges LiteSpeed cache for the updated profile
   - Ensures fresh content is served

## Migration Report

The command provides a detailed summary:

```
=== Summary ===
Total processed: 150
Migrated: 120
Skipped (has featured image): 15
Skipped (no main_image value): 10
Skipped (invalid attachment): 5
Errors: 0
```

## Recommended Migration Workflow

### Step 1: Test with Small Batch
```bash
# Test with 5 profiles (dry run)
wp directory-helpers migrate-main-image --dry-run --limit=5

# Review the output, then migrate for real
wp directory-helpers migrate-main-image --limit=5
```

### Step 2: Verify in WordPress Admin
- Check 2-3 of the migrated profiles
- Verify featured image displays correctly
- Verify main_image field is empty
- Check that thumbnail sizes exist

### Step 3: Migrate Larger Batch
```bash
# Migrate 50 more profiles
wp directory-helpers migrate-main-image --limit=50
```

### Step 4: Migrate All Remaining
```bash
# Migrate all remaining profiles
wp directory-helpers migrate-main-image
```

### Step 5: Clear and Prime Cache (Optional)
```bash
# If using LiteSpeed Cache, rebuild the cache
wp litespeed-purge all
wp litespeed-crawler run
```

## Troubleshooting

### Issue: "Could not extract valid attachment ID"
**Solution**: The ACF field might contain an invalid value. Run with `--dry-run` to identify which profiles have issues.

### Issue: "Attachment does not exist"
**Solution**: The media file was deleted but the ACF field still references it. You may need to manually clean up these fields or delete them.

### Issue: Thumbnails not displaying correctly
**Solution**: Run the migration again with the `--regenerate-thumbs` flag:
```bash
wp directory-helpers migrate-main-image --regenerate-thumbs --force
```

### Issue: Want to see which profiles already have featured images
**Solution**: Use the `--force` flag with `--dry-run`:
```bash
wp directory-helpers migrate-main-image --dry-run --force
```

## Technical Details

### ACF Field Formats Supported

The command handles all ACF image field return formats:

1. **Array Format** (`'ID' => 123, 'url' => '...'`)
2. **ID Format** (`123`)
3. **URL Format** (`'https://example.com/image.jpg'`)

### Database Changes

The command makes these database changes:

1. **Adds**: `wp_postmeta` entry with meta_key `_thumbnail_id`
2. **Removes**: `wp_postmeta` entry with meta_key `main_image`
3. **Optional**: Updates attachment metadata with new thumbnail sizes

### No Post Save Required

WordPress's `set_post_thumbnail()` function handles all necessary database updates without requiring `wp_update_post()` or manual post saves. This is more efficient and avoids triggering unnecessary post update hooks.

## Safety Features

✅ **Dry-run mode** - Test before making changes  
✅ **Batch processing** - Test with small groups first  
✅ **Skip existing** - Won't overwrite existing featured images  
✅ **Validation** - Verifies attachments exist before migration  
✅ **Error handling** - Continues processing even if individual profiles fail  
✅ **Progress tracking** - Shows real-time progress bar  
✅ **Detailed reporting** - Comprehensive summary of all actions  
✅ **Cache integration** - Automatically purges LiteSpeed cache  

## Post-Migration Verification

After migration, verify these items:

1. **Featured images display** on profile archive pages
2. **Featured images display** on single profile pages
3. **Thumbnail sizes** are generated correctly
4. **main_image ACF field** is empty on migrated profiles
5. **Structured data** still works (uses main_image fallback to featured image)

## Notes

- The command processes posts with ANY status (publish, draft, pending, etc.)
- Migration is idempotent - safe to run multiple times
- LiteSpeed cache is automatically purged for each updated profile
- If a profile already has the same attachment as featured image, it just clears the main_image field
