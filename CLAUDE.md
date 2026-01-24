# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

**Directory Helpers** is a modular WordPress plugin for directory websites. It provides functionality for managing professional profiles (dog trainers, service providers), location-based searches, rankings, AI content generation, and production workflows.

**Dependencies:**
- WordPress 5.0+
- Bricks Builder (theme framework)
- Advanced Custom Fields (ACF) Pro
- Redis Object Cache (recommended for performance)

## Post Types & Taxonomies

### Custom Post Types
- `profile` - Individual professional profiles
- `city-listing` - City landing pages
- `state-listing` - State landing pages

### Taxonomies
- `area` - City/location taxonomy (has latitude/longitude term meta)
- `state` - State taxonomy
- `niche` - Professional category/service type (e.g., dog-trainer)

## Architecture

### Plugin Entry Point
`directory-helpers.php` - Main plugin file, registers hooks, loads modules and WP-CLI commands

### Shared Helper Classes
Located in `/includes/`:

- **`class-dh-taxonomy-helpers.php`** - Taxonomy utilities (CRITICAL - see Primary Area Term Policy below)
- **`class-dh-bricks-query-helpers.php`** - Proximity search queries with Redis caching
- **`class-dh-acf-fields.php`** - ACF field helpers

### Modular System
All modules in `/modules/` are auto-loaded. Each module:
- Has its own directory with a main PHP file
- Registers a class extending functionality
- Can provide shortcodes, admin pages, REST endpoints, etc.

Key modules:
- `profile-rankings/` - City and state ranking calculations
- `instant-search/` - Client-side search with typeahead
- `ai-content-generator/` - n8n webhook integration for AI content
- `*-production-queue/` - AJAX-based batch processing for profiles/content/video
- `profile-badges/` - Dynamic SVG badge generation
- `proximity search` - Handled by `class-dh-bricks-query-helpers.php` (not a module)

### WP-CLI Commands
Located in `/includes/cli/`:
- `deduplicate_area_terms` - Clean up duplicate area terms
- `analyze-radius` - Calculate optimal proximity search radii
- `update-rankings` - Recalculate profile rankings
- `update-state-rankings` - Recalculate state-level rankings
- `prime-cache` - Pre-warm query caches
- `migrate-main-image` - Migrate featured images
- `migrate-years-experience` - Migrate experience data
- `pre-warm-object-cache` - Pre-warm Redis object cache
- `pre-warm-rankings` - Pre-warm ranking queries

## Key Concepts & Policies

### Primary Area Term Policy (CRITICAL)
Profiles may have multiple area terms. **Always use the helper to get the primary term:**

```php
// ✅ CORRECT
$primary_area = DH_Taxonomy_Helpers::get_primary_area_term($post_id);

// ❌ WRONG - Never use directly for profiles
$area_terms = get_the_terms($post_id, 'area');
$area = $area_terms[0]; // Could be wrong city!
```

**Logic:** Matches against ACF `city` field to determine which area is primary when multiple exist.

**When to use:**
- Always for `profile` post type
- Not needed for `city-listing` (they have only one area term)

See `/PRIMARY-AREA-TERM-POLICY.md` for full details.

### Proximity Search & Caching
- Uses Haversine formula for distance calculations
- Radius priority: Custom Radius > Recommended Radius > Default (5mi)
- 30-day Redis cache with event-driven invalidation
- Cache key: `dh_nearby_profiles_{area_id}_{niche_ids}_{radius}`
- Automatically invalidates on profile save or term meta update

See `/PROXIMITY-SEARCH-README.md` for full details.

### Rankings System
- Profiles ranked within city (`city_rank`) and state (`state_rank`)
- Based on ratings and review counts
- Stored as post meta on profile posts
- Must be recalculated after publishing new profiles in a city

## Common Commands

### Run WP-CLI Commands
All commands are under the `directory-helpers` namespace:

```bash
# Analyze and set optimal search radius for a niche
wp directory-helpers analyze-radius dog-trainer --update-meta

# Update city rankings for a specific area
wp directory-helpers update-rankings --niche=dog-trainer --area=milwaukee-wi

# Update state rankings for all niches in a state
wp directory-helpers update-state-rankings wi

# Pre-warm object cache with ranking queries
wp directory-helpers pre-warm-object-cache --post-type=city-listing --limit=50

# Pre-warm ranking queries specifically
wp directory-helpers pre-warm-rankings --niche=dog-trainer

# Migrate main images to new ACF field
wp directory-helpers migrate-main-image --dry-run
wp directory-helpers migrate-main-image --post-id=12345
```

### Cache Management
```bash
# Flush all Redis cache
wp cache flush

# Prime query caches (for Bricks queries)
wp directory-helpers prime-cache
```

### View WP-CLI Help
```bash
wp directory-helpers <command> --help
```

## Development Workflow

### Adding a New Module
1. Create directory in `/modules/your-module/`
2. Create main file `your-module.php` with class `DH_Your_Module`
3. Register in `directory-helpers.php` `$this->modules` array
4. Class will be auto-instantiated on plugin init

### Helper Function Usage
Always use the taxonomy helper methods for consistency:

```php
// City name (without state suffix)
$city = DH_Taxonomy_Helpers::get_city_name($post_id);

// State name or abbreviation
$state_full = DH_Taxonomy_Helpers::get_state_name($post_id);
$state_abbr = DH_Taxonomy_Helpers::get_state_name($post_id, 'abbr');

// Niche name
$niche = DH_Taxonomy_Helpers::get_niche_name($post_id);
$niches_plural = DH_Taxonomy_Helpers::get_niche_name($post_id, true);
```

### Working with Proximity Queries
```php
// Get query args for nearby profiles (used in Bricks)
$args = DH_Bricks_Query_Helpers::get_nearby_profiles_query_args();

// Clear proximity cache for an area
DH_Bricks_Query_Helpers::clear_proximity_cache($area_term_id, $niche_ids);
```

### ACF Fields (Common)
**Profile fields:**
- `city` (text) - Primary city name
- `state` (text) - State name
- `latitude` (number) - Profile location
- `longitude` (number) - Profile location
- `main_profile_image` - Main profile image
- `body_image_1`, `body_image_2` - Additional images
- `video_overview` (URL) - YouTube video

**Area term meta:**
- `latitude`, `longitude` - City center coordinates
- `custom_radius` - Manual override radius (miles)
- `recommended_radius` - CLI-calculated radius (miles)

**Profile meta (calculated):**
- `city_rank` - Ranking within primary city
- `state_rank` - Ranking within state

## Testing & Debugging

### Common Test Files
Root directory contains test/investigation scripts:
- `test-*.php` - Various feature tests
- `investigate-*.php` - Debugging scripts
- `fix-*.php` - One-time fix scripts

These are utilities, not part of the production plugin.

### Debug Proximity Queries
Check Redis cache:
```bash
# Connect to Redis CLI
redis-cli
# Search for proximity cache keys
KEYS dh_nearby_profiles_*
# Get specific cache value
GET dh_nearby_profiles_123_45_5
```

### Verify Rankings
```bash
# Check profile meta
wp post meta get <post_id> city_rank
wp post meta get <post_id> state_rank
```

## Important Documentation
- `/PRIMARY-AREA-TERM-POLICY.md` - Critical: how to handle multiple area terms
- `/PROXIMITY-SEARCH-README.md` - Proximity search implementation details
- `/MAIN-IMAGE-MIGRATION-README.md` - Featured image migration process
- `/BADGE-ACTIVE-PARAMETER.md` - Badge system documentation

## Production Queues
The plugin includes AJAX-based queues for batch processing:
- Keep browser tab open during processing
- Process items sequentially with status updates
- Located in admin under Directory Helpers menu

## Code Standards
- Follow WordPress coding standards
- Use `DIRECTORY_HELPERS_PATH` and `DIRECTORY_HELPERS_URL` constants
- All modules should check `if (!defined('ABSPATH')) exit;`
- Use `wp_cache_*` functions for caching (automatically uses Redis when available)
- Always sanitize user input, escape output
- Use nonces for form submissions
