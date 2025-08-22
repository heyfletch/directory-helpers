# Instant Search Module

A lightweight, client-side instant search for selected post types. It builds a compact index via a REST endpoint and renders grouped results (Cities, Profiles, States) as you type.

## Shortcode

Place the shortcode where you want the search UI:

```
[dh_instant_search]
```

Per-instance parameters (all optional):
- post_types: CSV of post type slugs to include. Default: city-listing,state-listing,profile
- min_chars: Minimum characters before searching. Default: 2
- debounce: Input debounce in milliseconds. Default: 120
- limit: Max number of results to display. Default: 12

Example:
```
[dh_instant_search post_types="city-listing,profile" min_chars="1" debounce="250" limit="20"]
```

These map to data attributes on the input: data-post-types (letters), data-min-chars, data-debounce, data-limit.

## Where to tweak defaults (code)

- PHP shortcode defaults: modules/instant-search/instant-search.php → DH_Instant_Search::render_shortcode()
- Type labels and REST config: modules/instant-search/instant-search.php → DH_Instant_Search::register_assets() (via wp_localize_script)
- Global post types for indexing: filter "dh_instant_search_post_types" used in build_index_items() and maybe_invalidate_index()
- Client caps per group (result mix): modules/instant-search/assets/js/instant-search.js → caps in groupAndLimit()

## Global post types (site-wide)

To change which post types are indexed globally (affects the REST index and all shortcode instances), use:

```php
add_filter('dh_instant_search_post_types', function($types){
  return array('city-listing', 'profile');
});
```

## REST endpoint

- URL: /wp-json/dh/v1/instant-index
- Optional filter by type letters: ?pt=c,p
- Response: { version: "<n>", items: [{ i, t, u, y, n } ...] }
  - y is type letter: c (city-listing), p (profile), s (state-listing)

## Caching: how it works

- Server cache: WordPress transient dh_instant_search_index_json, TTL 12 hours.
- Client cache: localStorage
  - Keys: dhIS_v (version), dhIS_data (index payload)
  - The PHP option dh_instant_search_index_version is injected to JS and stored as version. When it changes, the browser refreshes its cache.

### When cache is invalidated

- On publish/trashed of a target post type (save_post hook → maybe_invalidate_index()).
- On deleted_post, trashed_post, untrashed_post hooks.
- Invalidation deletes the transient and bumps the version option to invalidate browser cache.

### Forcing a rebuild

- Edit and update a relevant post (e.g., city-listing) or trash/restore one.
- Programmatically call DH_Instant_Search::invalidate_index() (e.g., via WP-CLI or a snippet).
- Client-side only: clear localStorage keys dhIS_v and dhIS_data in the browser, then reload.

```js
localStorage.removeItem('dhIS_v');
localStorage.removeItem('dhIS_data');
```

## Troubleshooting

- No results:
  - Confirm your CPT slugs match those in defaults or filter.
  - Verify REST data: open /wp-json/dh/v1/instant-index and check items array.
  - If empty, ensure posts are published and the filter returns expected types.
- Stale results:
  - Save a relevant post to bump the version (or call invalidate_index()).
  - Clear browser cache keys dhIS_v and dhIS_data and reload.
- Filtering by types:
  - Ensure the shortcode post_types maps to valid letters; the server mapping is in $type_map in instant-search.php.
- Labels:
  - Adjust labels via wp_localize_script in register_assets().

## Files of interest

- modules/instant-search/instant-search.php
  - render_shortcode(): shortcode parameters → data-* attributes
  - register_assets(): localized config and labels
  - register_rest_routes()/rest_get_index(): REST API for index
  - maybe_invalidate_index()/invalidate_index(): cache invalidation
  - get_index_data()/build_index_items(): server cache and index build
- modules/instant-search/assets/js/instant-search.js
  - Reads data-* attributes, loads and caches index (localStorage), renders results
