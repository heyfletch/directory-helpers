# IndexNow Integration Plan

## Context
We need to "safe" submit URLs to Bing's IndexNow API for our high-volume directory (11k+ Profiles, 3k+ Cities). We cannot rely on RankMath's automatic hooks because our batch queues use direct `$wpdb->update` calls. We will use RankMath's API key but handle submission manually.

## Task List

- [ ] **1. Create Helper Class**
    - File: `includes/class-dh-indexnow-helper.php`
    - Logic:
        - Fetch API Key from RankMath option: `rank-math-options-instant-indexing` -> `bing_api_key`.
        - Method `submit_urls($urls)`: Accepts string or array.
        - Chunk URLs into batches of 10,000.
        - POST to `https://api.indexnow.org/indexnow`.
        - JSON Payload: host, key, keyLocation, urlList.
        - Log success/errors to `error_log`.

- [ ] **2. Create WP-CLI Backfill Command**
    - File: `includes/cli/class-indexnow-backfill-command.php`
    - Command: `wp directory-helpers indexnow backfill`
    - Logic:
        - Query `profile` and `city-listing` post types (publish status).
        - Support `--limit` and `--offset`.
        - Chunk results and pass to `DH_IndexNow_Helper::submit_urls`.
        - Output progress bar.

- [ ] **3. Register Classes**
    - File: `directory-helpers.php`
    - Action: Require both new files and register the CLI command class.

- [ ] **4. Integrate: Content Production Queue**
    - File: `modules/content-production-queue/content-production-queue.php`
    - Location: Inside `process_next_in_queue`.
    - Action: After `$wpdb->update` success, call `DH_IndexNow_Helper::submit_urls` with the post permalink.
    - Ensure non-blocking

- [ ] **5. Integrate: Prep Pro**
    - File: `modules/prep-pro/prep-pro.php`
    - Location: Inside `handle_fast_publish`.
    - Action: Collect all published IDs into an array, get their permalinks, and pass the batch to `DH_IndexNow_Helper::submit_urls` at the end of the function.
    - Ensure non-blocking

