# CLAUDE.md - Directory Helpers WordPress Plugin

## Project Overview

Directory Helpers is a modular WordPress plugin for managing directory websites with city/state business directories, professional profile listings, and automated content generation. The plugin integrates with external services (n8n, DataForSEO) for workflow automation and SEO optimization.

**Version:** 1.0.0
**License:** GPL v2 or later
**Text Domain:** directory-helpers

## Architecture

### Core Structure

```
directory-helpers/
├── directory-helpers.php      # Main plugin file (singleton pattern)
├── includes/                  # Shared utilities and CLI commands
│   ├── class-dh-taxonomy-helpers.php
│   └── cli/                   # WP-CLI commands
├── modules/                   # 21 feature modules (self-contained)
├── views/                     # Admin page templates
├── assets/                    # CSS and JS files
│   ├── css/
│   └── js/
└── docs/                      # Technical documentation
```

### Module System

Modules are self-contained feature classes in `/modules/{module-name}/{module-name}.php`. Each module:
- Is a single PHP class with static initialization
- Registers its own hooks, shortcodes, and REST endpoints
- Has no inter-module dependencies (loose coupling)
- Can be enabled/disabled via admin settings

**Module loading flow:**
1. `Directory_Helpers::load_modules()` - scans module directory
2. `Directory_Helpers::init_active_modules()` - initializes enabled modules on `init` hook

### Key Modules

| Module | Purpose |
|--------|---------|
| `instant-search` | Client-side search with REST API backend |
| `profile-badges` | SVG badge generation with HTTP caching |
| `ai-content-generator` | n8n webhook triggers for content |
| `profile-production-queue` | AJAX batch profile processing |
| `content-production-queue` | AJAX batch content publishing |
| `video-production-queue` | Video creation via Zero Work webhook |
| `profile-structured-data` | Schema.org markup |
| `cache-integration` | LiteSpeed Cache purging |

## Development Conventions

### PHP Patterns

- **Singleton pattern** for main `Directory_Helpers` class
- **Static methods** for module classes (no instantiation)
- **Hook-based architecture** using WordPress actions/filters
- **Nonce verification** for all form submissions
- **Capability checks** (`manage_options`, `edit_posts`)
- **Sanitization/escaping** for all inputs/outputs

### Naming Conventions

- **Classes:** `Directory_Helpers`, `DH_Instant_Search`, `DH_Profile_Badges`
- **Module files:** `{module-name}.php` in `/modules/{module-name}/`
- **Functions:** `dh_` prefix for global functions
- **Options:** `directory_helpers_options` (single option array)
- **Transients:** `dh_search_index`, `dh_badge_rate_limit_{ip}`

### Constants

```php
DIRECTORY_HELPERS_VERSION   // Plugin version
DIRECTORY_HELPERS_PATH      // Plugin directory path
DIRECTORY_HELPERS_URL       // Plugin URL
DIRECTORY_HELPERS_BASENAME  // Plugin basename
```

### Asset Enqueuing

- Version with file modification time for cache busting
- Admin assets: `admin_enqueue_scripts` hook
- Frontend assets: `wp_enqueue_scripts` hook

```php
wp_enqueue_script('handle', $url, $deps, filemtime($path), true);
```

## Key Files

### Main Plugin File
- **`directory-helpers.php`** (1,692 lines) - Bootstrap, settings, prompt system, module loading

### Admin Interface
- **`views/admin-page.php`** - Settings page with module list, webhooks, prompts

### Important Modules
- **`modules/profile-badges/profile-badges.php`** (1,292 lines) - Badge generation
- **`modules/profile-production-queue/profile-production-queue.php`** (956 lines) - Batch processing
- **`modules/instant-search/instant-search.php`** (417 lines) - Search system

### Configuration
- **`docs/acf.json`** - ACF field definitions (97KB)

## Database & Options

### Main Options Array

Settings stored in `directory_helpers_options`:

```php
[
    'active_modules' => [],           // Array of enabled module IDs
    'prompts' => [],                  // AI prompt templates
    'prompt_targets' => [],           // Post-type targeting for prompts
    'n8n_webhook_url' => '',          // Content generation webhook
    'notebook_webhook_url' => '',     // Notebook creation webhook
    'featured_image_webhook_url' => '', // Image generation webhook
    'shared_secret_key' => '',        // API authentication
    'dataforseo_login' => '',         // SEO API credentials
    'dataforseo_password' => '',
    'instant_search_*' => '',         // Search configuration
]
```

### Custom Post Types

- `city-listing` - City directory entries
- `state-listing` - State directory entries
- `profile` - Professional profiles

### Taxonomies

- `area-term` - Geographic area terms (city/state relationships)

## WP-CLI Commands

```bash
# Deduplicate area terms
wp directory-helpers deduplicate_area_terms [--dry-run]

# Update area term format
wp directory-helpers update_area_term_format

# Update state listing titles
wp directory-helpers update_state_listing_titles

# Rebuild search cache
wp dh search rebuild-cache
```

## External Integrations

### Webhooks (n8n)

- **Content generation:** `n8n_webhook_url`
- **Notebook creation:** `notebook_webhook_url`
- **Featured image:** `featured_image_webhook_url`
- **Video production:** Zero Work webhook

### APIs

- **DataForSEO:** SEO keyword research (Basic Auth)
- Credentials: `dataforseo_login`, `dataforseo_password`

## Caching Strategy

### Transient Caching

- **Search index:** Cached with auto-invalidation on post changes
- **Badge data:** 30-day TTL
- **Rate limiting:** Per-IP transients for badge requests

### HTTP Caching

- Badge embeds: 24-hour Cache-Control headers
- File assets: Versioned with `filemtime()`

### Cache Invalidation

- Automatic on `save_post`, `delete_post`, `set_object_terms`
- Manual via admin action: `dh_rebuild_search_cache`

## Testing

No formal testing framework. Test files are standalone PHP scripts in root directory:

- `test-area-term-validation.php`
- `test-featured-media-webhook.php`
- `test-token-cleanup.php`
- `investigate-area-term-assignment.php`

Run tests manually via browser or WP-CLI for validation.

## Common Tasks

### Adding a New Module

1. Create directory: `/modules/my-module/`
2. Create class file: `/modules/my-module/my-module.php`
3. Follow pattern:

```php
<?php
if (!defined('ABSPATH')) exit;

class DH_My_Module {
    public static function init() {
        // Register hooks, shortcodes, REST endpoints
        add_action('init', [__CLASS__, 'register_shortcodes']);
    }

    public static function register_shortcodes() {
        add_shortcode('my_shortcode', [__CLASS__, 'render_shortcode']);
    }

    public static function render_shortcode($atts) {
        // Shortcode logic
    }
}
```

4. Module auto-discovered on next load
5. Enable via Settings > Directory Helpers

### Adding REST Endpoints

```php
add_action('rest_api_init', function() {
    register_rest_route('directory-helpers/v1', '/endpoint', [
        'methods' => 'GET',
        'callback' => [__CLASS__, 'handle_request'],
        'permission_callback' => '__return_true', // or capability check
    ]);
});
```

### Token Replacement in Prompts

Available tokens in prompt templates:
- `{title}` - Post title
- `{city}` - City name
- `{state}` - State name
- `{taxonomy_name}` - Taxonomy display name

### Triggering Cache Invalidation

```php
// Automatic via hooks
do_action('save_post', $post_id);

// Manual rebuild
do_action('dh_rebuild_search_cache');
```

## Security Considerations

### Always Implement

- Nonce verification for forms: `wp_verify_nonce()`
- Capability checks: `current_user_can('manage_options')`
- Data sanitization: `sanitize_text_field()`, `esc_url()`, etc.
- Output escaping: `esc_html()`, `esc_attr()`, `wp_kses_post()`
- SQL preparation: `$wpdb->prepare()`

### Sensitive Data

Stored credentials (handle carefully):
- `n8n_webhook_url`
- `dataforseo_login` / `dataforseo_password`
- `shared_secret_key`

## Performance Notes

### Optimization Patterns Used

- Transient caching for expensive queries
- HTTP cache headers for static content (badges)
- Lazy module loading
- Batch processing for production queues
- Rate limiting (120 req/min for badges)

### Potential Bottlenecks

- Large profile databases (use batch processing)
- ACF field queries for primary area terms
- Search index regeneration for thousands of posts

## Git Workflow

### Commit Message Conventions

Based on recent commits:
- `feat:` - New features
- `refactor:` - Code improvements
- `fix:` - Bug fixes

Examples:
```
feat: add manual cache rebuild controls for instant search
refactor: optimize search index cache invalidation strategy
fix: missing nonce verification and error handling
```

### Branch Naming

Feature branches: `claude/{feature-name}-{session-id}`

## Documentation

### Root-Level Docs

- `AREA-TERM-FIX-*.md` - Area taxonomy fixes
- `PRIMARY-AREA-TERM-*.md` - Term selection policies
- `BADGE-ACTIVE-PARAMETER.md` - Badge status tracking

### Technical Docs in `/docs`

- `instant-search.md` - Search system documentation
- `acf.json` - ACF field configuration export
- `PREP-PRO-SUMMARY.md` - Prep Pro module overview
- `PROFILE-PRODUCTION-*.md` - Workflow documentation

## Important Considerations for AI Assistants

### Do

- Follow WordPress coding standards
- Use existing module patterns for new features
- Implement proper security (nonces, capability checks, escaping)
- Add cache invalidation for data changes
- Use transients for expensive operations
- Test with WP-CLI commands when available

### Avoid

- Breaking the modular architecture (no cross-module dependencies)
- Storing sensitive data without encryption consideration
- Direct database queries without `$wpdb->prepare()`
- Skipping nonce verification on form submissions
- Adding features without admin UI controls

### Module Independence

Each module must be:
- Self-contained (no dependencies on other modules)
- Loadable independently
- Removable without breaking other functionality

### ACF Integration

Many features rely on ACF fields. Check `docs/acf.json` for:
- Custom field definitions
- Field group assignments
- Location rules for post types

### Webhook Patterns

When adding webhook triggers:
1. Store webhook URL in settings
2. Validate URL before use
3. Use `wp_remote_post()` with proper args
4. Handle errors gracefully
5. Consider HMAC validation for security

## Quick Reference

### Option Retrieval

```php
$options = get_option('directory_helpers_options', []);
$webhook_url = $options['n8n_webhook_url'] ?? '';
```

### Module Check

```php
$active_modules = $options['active_modules'] ?? [];
if (in_array('instant-search', $active_modules)) {
    // Module is active
}
```

### Prompt Retrieval

```php
$prompts = $options['prompts'] ?? [];
$intro_prompt = $prompts['intro'] ?? '';
```

### Common Hooks

- `directory_helpers_activated` - Plugin activation
- `directory_helpers_deactivated` - Plugin deactivation
- `dh_rebuild_search_cache` - Trigger search cache rebuild
