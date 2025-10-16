# Profile Production - Proposed Streamlined System

## Design Goals

1. **Process by CITY** - Ensure all profiles for a city are handled together
2. **Defer reranking** - Avoid expensive operations during batch processing
3. **Minimal cache operations** - Clear cache only at final step
4. **Prevent duplicates** - Lock mechanism for city creation
5. **No frontend polling** - Use more efficient approach
6. **Fix taxonomy bug** - Remove state taxonomy from city-listings

---

## Proposed Flow Options

### Option A: City-Based Batch Processing (RECOMMENDED)

**High Level:**
```
1. Filter profiles by state/niche/status/etc
2. Group profiles by city (area term)
3. For each city:
   a. Create city-listing if doesn't exist
   b. Publish all profiles in that city
   c. Clean area terms for that city
   d. Send city to AI (if newly created)
4. After ALL cities processed:
   a. OPTIONAL: Rerank all cities + state (single button)
   b. OPTIONAL: Clear/prime cache (single button)
```

**Benefits:**
- Natural unit of work = one city
- Prevents split cities across batches
- No duplicate city creation
- All profiles for city updated atomically
- Reranking completely optional/manual

**Drawbacks:**
- If one city has 100 profiles, processes all 100 at once
- Might timeout on very large cities

---

### Option B: Profile Batch with City Locking

**High Level:**
```
1. Filter profiles by state/niche/status/etc
2. Process in batches of N profiles:
   a. Extract unique cities from batch
   b. For each city, CHECK and CREATE with lock
   c. Publish profiles in batch
   d. Clean area terms
   e. Track created cities for AI
3. After ALL batches:
   a. Send all new cities to AI (bulk)
   b. OPTIONAL: Rerank (button)
   c. OPTIONAL: Cache operations (button)
```

**Benefits:**
- Prevents timeout on huge cities
- Controlled batch size
- Still defers expensive operations

**Drawbacks:**
- More complex locking logic
- City might span multiple batches
- Need to track state across batches

---

### Option C: Two-Phase Process (SIMPLEST)

**Phase 1: Prepare (Fast)**
```
1. Filter profiles
2. Create all missing city-listings
3. Send all new cities to AI queue
4. Publish all filtered profiles
5. Clean all area terms
→ DONE in seconds, no reranking
```

**Phase 2: Optimize (Manual, When Needed)**
```
Separate manual buttons:
1. "Rerank All Profiles" - runs reranking for state
2. "Clear Cache" - clears city/state listing cache
3. "Prime Cache" - pre-generates cache if needed
```

**Benefits:**
- Simplest to implement
- Fastest for user
- Reranking completely decoupled
- Can rerank once per day instead of per batch
- Manual control over expensive operations

**Drawbacks:**
- Profiles live without ranks temporarily
- Need to educate user on when to rerank

---

## Recommended Approach: Option C (Two-Phase)

### Why This is Best:

1. **Speed:** Publishing 1000 profiles takes seconds, not minutes
2. **Resource Efficiency:** Rerank once daily instead of thousands of times
3. **Clarity:** User knows exactly what's happening
4. **Flexibility:** Can publish now, rerank later
5. **Safety:** Can't accidentally DOS your own server

### Implementation Plan:

#### Phase 1: Fast Publishing Button
```php
Function: dh_fast_publish_profiles()

Steps:
1. Query filtered profiles
2. Group by city (area term)
3. For each city:
   - Check if city-listing exists (area + niche)
   - If not, create city-listing (NO state taxonomy)
   - Track new city IDs
4. Publish all profiles (wp_update_post with post_status = publish)
5. Clean all area terms (remove " - ST")
6. Send new city IDs to AI webhook (one request per city)
7. Return summary: X profiles published, Y cities created, Z sent to AI

Time Estimate: 5-15 seconds for 100 profiles
```

#### Phase 2: Manual Maintenance Buttons

**Button 1: Rerank Profiles**
```php
Function: dh_rerank_state_profiles($state_slug)

Steps:
1. Query all published profiles in state
2. Group by city (area term)
3. For each city:
   - Call update_ranks_for_term($city_term, 'area', 'city_rank')
4. Call update_ranks_for_term($state_term, 'state', 'state_rank')
5. Return summary: X cities reranked, Y state profiles reranked

Time Estimate: 30-120 seconds for 500 profiles
Run Frequency: Once per day or after major updates
```

**Button 2: Clear Cache**
```php
Function: dh_clear_listing_cache($state_slug)

Steps:
1. Clear cache for all city-listings in state
2. Clear cache for state-listing
3. Clear any query result caches

Time Estimate: 1-2 seconds
Run Frequency: After reranking or major updates
```

---

## Detailed Technical Specs

### Fast Publishing Function

```php
/**
 * Fast publish profiles without reranking
 * 
 * @param array $filters {
 *     @type string $state_slug     Required
 *     @type string $post_status    Default 'refining'  
 *     @type string $niche_slug     Default 'dog-trainer'
 *     @type int    $min_count      Default 3
 *     @type string $city_slug      Optional
 *     @type string $city_search    Optional
 * }
 * @return array {
 *     @type int   $profiles_published
 *     @type int   $cities_created
 *     @type int   $ai_triggered
 *     @type array $city_ids Created city listing IDs
 *     @type array $errors
 * }
 */
function dh_fast_publish_profiles($filters) {
    // 1. Query profiles
    $profiles = query_profiles_by_filters($filters);
    
    // 2. Group by city
    $cities = array(); // area_slug => [profile_ids]
    foreach ($profiles as $p) {
        $cities[$p->area_slug][] = $p->ID;
    }
    
    // 3. Create missing city-listings
    $created_city_ids = array();
    $niche_term = get_term_by('slug', $filters['niche_slug'], 'niche');
    
    foreach ($cities as $area_slug => $profile_ids) {
        $area_term = get_term_by('slug', $area_slug, 'area');
        if (!$area_term) continue;
        
        // Check existence
        $exists = city_listing_exists($area_term->term_id, $niche_term->term_id);
        if (!$exists) {
            // Create WITHOUT state taxonomy
            $city_id = create_city_listing(
                $area_term, 
                $filters['state_slug'], 
                $niche_term,
                false // assign_state_taxonomy = false
            );
            if ($city_id) {
                $created_city_ids[] = $city_id;
            }
        }
    }
    
    // 4. Publish all profiles at once
    $published_count = 0;
    $all_profile_ids = array();
    foreach ($cities as $profile_ids) {
        $all_profile_ids = array_merge($all_profile_ids, $profile_ids);
    }
    
    foreach ($all_profile_ids as $pid) {
        if (get_post_status($pid) !== 'publish') {
            wp_update_post(array(
                'ID' => $pid,
                'post_status' => 'publish'
            ));
            $published_count++;
        }
    }
    
    // 5. Clean area terms
    cleanup_area_terms($all_profile_ids);
    
    // 6. Trigger AI for new cities
    $ai_count = trigger_ai_for_cities($created_city_ids);
    
    return array(
        'profiles_published' => $published_count,
        'cities_created' => count($created_city_ids),
        'ai_triggered' => $ai_count,
        'city_ids' => $created_city_ids,
        'errors' => array()
    );
}
```

### Reranking Function

```php
/**
 * Rerank all profiles in a state
 * Call this manually, not during publishing
 * 
 * @param string $state_slug
 * @return array Stats
 */
function dh_rerank_state_profiles($state_slug) {
    // Get all published profiles in state
    $profiles = get_posts(array(
        'post_type' => 'profile',
        'post_status' => 'publish',
        'posts_per_page' => -1,
        'tax_query' => array(
            array(
                'taxonomy' => 'state',
                'field' => 'slug',
                'terms' => $state_slug
            )
        ),
        'fields' => 'ids'
    ));
    
    // Group by city
    $cities = array();
    foreach ($profiles as $pid) {
        $area_terms = get_the_terms($pid, 'area');
        if ($area_terms && !is_wp_error($area_terms)) {
            $cities[$area_terms[0]->term_id][] = $pid;
        }
    }
    
    // Rerank each city
    $cities_reranked = 0;
    foreach ($cities as $area_term_id => $city_profile_ids) {
        $rep_profile = $city_profile_ids[0];
        do_action('acf/save_post', $rep_profile);
        $cities_reranked++;
    }
    
    // Rerank state
    if (!empty($profiles)) {
        do_action('acf/save_post', $profiles[0]);
    }
    
    return array(
        'cities_reranked' => $cities_reranked,
        'total_profiles' => count($profiles),
        'state' => $state_slug
    );
}
```

---

## UI Design

### Production Queue Page Layout

```
┌─────────────────────────────────────────────────────┐
│  Profile Production Queue                           │
├─────────────────────────────────────────────────────┤
│                                                      │
│  [Filters: State | City Search | Status | Niche]    │
│  [Filter Button]                                     │
│                                                      │
├─────────────────────────────────────────────────────┤
│  FAST PUBLISHING                                     │
│  ┌──────────────────────────────────────────────┐  │
│  │ 🚀 Fast Publish (125 profiles)               │  │
│  │                                               │  │
│  │ This will:                                    │  │
│  │ • Create missing city pages                   │  │
│  │ • Publish all filtered profiles               │  │
│  │ • Send cities for AI content                  │  │
│  │ • Skip reranking (do separately)              │  │
│  │                                               │  │
│  │ Time: ~10 seconds                             │  │
│  └──────────────────────────────────────────────┘  │
│                                                      │
├─────────────────────────────────────────────────────┤
│  MAINTENANCE (Run After Publishing)                  │
│  ┌──────────────────────────────────────────────┐  │
│  │ ⚙️  Rerank All Profiles                      │  │
│  │ Time: ~60 seconds | Last run: 2 hours ago    │  │
│  └──────────────────────────────────────────────┘  │
│                                                      │
│  ┌──────────────────────────────────────────────┐  │
│  │ 🗑️  Clear Cache                              │  │
│  │ Time: ~1 second                               │  │
│  └──────────────────────────────────────────────┘  │
│                                                      │
├─────────────────────────────────────────────────────┤
│  RESULTS (125 profiles)                             │
│  [Table of profiles]                                │
└─────────────────────────────────────────────────────┘
```

---

## Migration Plan

### Step 1: Create New Functions (No Breaking Changes)
- Add new fast publishing function
- Add reranking button handler
- Add cache clearing button handler
- Keep old code intact

### Step 2: Add UI to Profile Production Page
- Add new buttons above existing queue
- Keep old queue visible but with deprecation notice

### Step 3: Test with Small Batch
- Test with 5 profiles
- Verify city creation
- Verify AI triggering
- Test reranking button
- Test cache clearing

### Step 4: Deprecate Old Queue
- Add big warning banner
- Update to point to new approach
- Keep code for 30 days as backup

### Step 5: Remove Polling System
- Remove JavaScript polling
- Remove batch processing logic
- Clean up database options

---

## Performance Comparison

### Current System (100 profiles, 10 cities, Florida)
```
Operation                          Time       DB Queries
─────────────────────────────────────────────────────
Batch 1 (5 profiles)              2s         50
Batch 2 (5 profiles)              2s         50
... (20 batches total)            40s        1000
AI Triggers (10 cities)           10s        20
Rerank 10 cities                  30s        5000
Rerank Florida (500 profiles)     60s        10000
Cache flush (x10)                 5s         0
─────────────────────────────────────────────────────
TOTAL                             147s       ~16000
```

### Proposed System (100 profiles, 10 cities, Florida)
```
Operation                          Time       DB Queries
─────────────────────────────────────────────────────
Fast Publish (all profiles)       5s         300
AI Triggers (10 cities)           10s        20
─────────────────────────────────────────────────────
SUBTOTAL (Publishing)             15s        320

MANUAL (run once daily):
Rerank 10 cities                  30s        5000
Rerank Florida (500 profiles)     60s        10000
Cache clear                       1s         10
─────────────────────────────────────────────────────
SUBTOTAL (Maintenance)            91s        15010

TOTAL (if run together)           106s       15330
REAL TOTAL (separate)             15s        320
```

**Speed Improvement: 10x faster (147s → 15s)**
**Resource Reduction: 95% fewer queries during normal operations**

---

## Questions for Discussion

1. **Do you want Option C (Two-Phase)?** 
   - Fast publishing without reranking
   - Manual reranking button
   
2. **When should reranking happen?**
   - Once per day on schedule?
   - Manual button only?
   - After publishing automatically (slower)?

3. **Should we keep Prep Profiles page working?**
   - Yes, leave it as-is for small batches
   - Yes, but update to use new approach
   - No, deprecate completely

4. **Cache strategy?**
   - Clear cache after reranking (manual)
   - Clear cache after publishing (automatic)
   - Don't clear cache at all (let it expire)

5. **UI preference?**
   - Add to existing Profile Production page
   - Replace existing queue system
   - New separate page
