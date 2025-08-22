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
- placeholder: Placeholder text for the input. Default comes from admin setting or filter. Built-in default: “Search by City, State, Zip, or Name …”.
- label_c: Label for City Listings group. Overrides defaults/admin.
- label_p: Label for Profiles group. Overrides defaults/admin.
- label_s: Label for States group. Overrides defaults/admin.
- theme: Visual theme for the input. "light" (default) or "dark". Adds the wrapper class `dhis--dark` when set to `dark`.

Examples:
```
[dh_instant_search post_types="city-listing,profile" min_chars="1" debounce="250" limit="20"]

[dh_instant_search placeholder="Search trainers…" label_p="Trainers" label_c="Cities" label_s="States"]

[dh_instant_search theme="dark"]
```

These map to data attributes on the input: data-post-types (letters), data-min-chars, data-debounce, data-limit.

## Where to tweak defaults (code)

- PHP shortcode defaults: modules/instant-search/instant-search.php → DH_Instant_Search::render_shortcode()
- Type labels and REST config: modules/instant-search/instant-search.php → DH_Instant_Search::register_assets() (via wp_localize_script). Labels default from admin options, then filter.
- Global post types for indexing: filter "dh_instant_search_post_types" used in build_index_items() and maybe_invalidate_index()
- Client caps per group (result mix): modules/instant-search/assets/js/instant-search.js → caps in groupAndLimit()

## Admin settings (site-wide defaults)

Under Directory Helpers → Settings, set:

- Default Placeholder: saves to option `directory_helpers_options[instant_search_placeholder]`.
- Profiles Label, City Listings Label, States Label: save to options `instant_search_label_p`, `instant_search_label_c`, `instant_search_label_s`.

These apply to all instances unless overridden by shortcode attributes. Developers may also use filters below.

## Filters

- `dh_instant_search_labels` (array labels): Override global labels passed to JS. Receives an array like `{ 'c' => 'City Listings', 'p' => 'Profiles', 's' => 'States' }` pre-filled from admin options.

```php
add_filter('dh_instant_search_labels', function($labels){
  $labels['p'] = 'Trainers';
  return $labels;
});
```

- `dh_instant_search_default_placeholder` (string placeholder): Change default placeholder when shortcode does not provide one. Receives the admin option value (or built-in default) as the first arg.

```php
add_filter('dh_instant_search_default_placeholder', function($ph){
  return 'Search…';
});
```

- `dh_instant_search_profile_zip_meta_key` (string meta_key): Customize the ACF/meta key used for ZIP on profile posts. Defaults to `zip`.

```php
add_filter('dh_instant_search_profile_zip_meta_key', function($meta_key, $post_id){
  return 'zip';
}, 10, 2);
```

## Override precedence

- Placeholder: shortcode `placeholder` > filter `dh_instant_search_default_placeholder` (receives admin value) > admin option > built-in “Search by City, State, Zip, or Name …”.
- Labels: shortcode `label_c/label_p/label_s` > filter `dh_instant_search_labels` (receives admin values) > admin options > built-in defaults (City Listings, Profiles, States).

## Styling

The input ships with a modern, pill-shaped design and two themes:

- Light (default): white background, 2px border in `var(--primary)`, soft focus ring.
- Dark (`theme="dark"`): white background, no border (on dark surfaces), subtle white focus ring.

Shortcode switching:

```
[dh_instant_search]               ; light (default)
[dh_instant_search theme="dark"]  ; dark variant
```

### Search icon and placeholder

- A magnifying glass icon is rendered on the left via an SVG mask and inherits `var(--primary)`.
- Placeholder color uses `var(--primary)` and fades to 0.2 opacity on focus.

### CSS variables (easy overrides)

You can fine-tune spacing, radius, icon size/offset, etc. via CSS variables on the wrapper `.dh-instant-search` or a parent:

```css
.dh-instant-search {
  --primary: #0a84ff;         /* brand color */
  --dhis-font-size: 18px;     /* input font size */
  --dhis-radius: 9999px;      /* pill radius */
  --dhis-padding-x: 18px;     /* horizontal padding */
  --dhis-padding-y: 12px;     /* vertical padding */
  --dhis-icon-size: 18px;     /* magnifier size */
  --dhis-icon-left: 16px;     /* icon left inset */
  --dhis-text-color: #111;    /* input text color */
}
```

### Removing theme underlines

Some themes add underlines via box-shadow/appearance on inputs. The module proactively:

- Resets native search appearances and hides WebKit decorations.
- Forces no box-shadow by default and applies a custom focus ring.

If you still see an underline, ensure no higher-specificity selector targets `.dh-instant-search .dhis-input` with a box-shadow/border. Add your own override as needed.

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
- Response: { version: "<n>", items: [{ i, t, u, y, n, z? } ...] }
  - y is type letter: c (city-listing), p (profile), s (state-listing)
  - z is optional ZIP (5-digit) for profiles when available

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
  - Adjust labels via admin settings or `dh_instant_search_labels` filter.

- ZIP field not present:
  - Confirm your profile posts have a `zip` meta value (ACF field) and that it’s 5 digits.
  - Verify the REST data at /wp-json/dh/v1/instant-index: profile items should include a `z` when a valid ZIP exists.

## ZIP search (Option A)

- 5‑digit fast path:
  - If the normalized query is exactly 5 digits, results prioritize profiles whose `z` equals the ZIP.
- Modest ranking boost:
  - For general queries, the base ranking uses the normalized title (`n`). If the query contains numeric tokens that match a profile ZIP (`z`), a small fractional boost is applied so matching profiles surface slightly higher without outranking strong title matches.
- Data shape:
  - Profile items may include `z` (5-digit string). The ZIP meta key is filterable via `dh_instant_search_profile_zip_meta_key`.
- Placeholder:
  - Default placeholder updated to “Search by City, State, Zip, or Name …”. You can still override via shortcode or `dh_instant_search_default_placeholder`.

### Optional enhancements

- In-memory ZIP map:
  - Build a `zip -> [profile items]` map after loading the index to make ZIP queries O(1). Not required for typical datasets; consider at very large scales.

### Future phases

- Separate ZIP group:
  - Introduce a dedicated result group and label for ZIP results if the UX calls for explicit separation.
- Server-assisted ZIP/geo endpoint:
  - For very large datasets or geospatial logic (radius/nearest), add a server endpoint and route ZIP queries to it while keeping the current client-side flow as a fallback.

## Files of interest

- modules/instant-search/instant-search.php
  - render_shortcode(): shortcode parameters → data-* attributes
  - register_assets(): localized config and labels
  - register_rest_routes()/rest_get_index(): REST API for index
  - maybe_invalidate_index()/invalidate_index(): cache invalidation
  - get_index_data()/build_index_items(): server cache and index build
- modules/instant-search/assets/js/instant-search.js
  - Reads data-* attributes, loads and caches index (localStorage), renders results
