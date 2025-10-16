# Profile Production Analysis & Issues

## Current Systems Overview

### System 1: Prep Profiles (`dh-prep-profiles`)
**URL:** `/wp-admin/edit.php?post_type=state-listing&page=dh-prep-profiles`

**"One-Click Flow" Process:**
1. Query profiles by filters (state, city, status, niche, min_count)
2. Extract unique cities from filtered profiles
3. **Create city-listing posts** for all cities that don't exist
4. **Publish all filtered profiles** (changes status from refining → publish)
5. **Clean area terms** (removes " - ST" suffix from area term names)
6. **Rerank profiles** (triggers `do_action('acf/save_post')` for each city + state)
7. **Trigger AI content** for newly created cities (sends to n8n webhook)
8. Redirect to draft city listings

**Key Characteristics:**
- Synchronous, single-request operation
- All profiles processed at once
- Reranking happens per-city, then per-state
- Does NOT assign state taxonomy to city-listings
- Slow for large batches

---

### System 2: Profile Production Queue (`dh-profile-production`)
**URL:** `/wp-admin/admin.php?page=dh-profile-production`

**Process:**
1. "Add to Pipeline" button adds profile IDs to queue in `wp_options`
2. Frontend JavaScript polls every 3-5 seconds via AJAX
3. Each poll processes one batch of 5 profiles:
   - Extract unique cities from batch
   - **Create city-listing posts** for cities that don't exist
   - **Trigger AI immediately** for newly created cities
   - **Publish profiles in batch** (refining → publish)
   - **Clean area terms** for newly published profiles
4. After ALL batches complete:
   - **Rerank all profiles** at once (triggers `do_action('acf/save_post')` for each city + state)

**Key Characteristics:**
- Asynchronous, frontend-polling driven
- Processes 5 profiles per batch
- Reranking deferred to final step
- **ASSIGNS state taxonomy to city-listings** (line 754) ⚠️
- City creation per batch (potential race condition)

---

## Critical Issues Identified

### Issue 1: State Taxonomy Inconsistency ⚠️
**Problem:** Production Queue assigns `state` taxonomy to city-listings, but Prep Profiles does NOT.

```php
// Production Queue (line 754)
wp_set_object_terms($post_id, $state_term->term_id, 'state');

// Prep Profiles - NO state assignment
```

**Impact:**
- City-listings created by different systems have different taxonomy structures
- Queries and relationships may break
- Per CITY-STATE-RELATIONSHIPS.md, city-listings DON'T have state taxonomy terms

**Root Cause:** Production Queue was likely copied from Prep Profiles but added state assignment incorrectly

---

### Issue 2: Duplicate City Creation
**Problem:** Race condition when processing batches

**Scenario:**
1. Batch 1 (profiles 1-5): Miami profiles → checks if Miami city exists → creates it
2. Batch 2 (profiles 6-10): Miami profiles → checks if Miami city exists → might create duplicate
3. Between batch processing, `city_listing_exists()` may not find newly created draft posts

**Contributing Factors:**
- `city_listing_exists()` only checks `post_status = draft|publish`
- No locking mechanism between batches
- Frontend polling can trigger overlapping requests

---

### Issue 3: Missing Profiles from City Pages
**Problem:** Profiles mysteriously missing from city listing pages

**Likely Causes:**
1. **Reranking timing:** Production Queue reranks AFTER all batches, but profiles are already published
   - Published profiles without ranks may not show in queries
   - Cache may be stale between publish and rerank
   
2. **Cache clearing:** Ranking module calls `wp_cache_flush()` at END, not per save
   - Profiles published in batch 1 have stale cache until final rerank
   
3. **Query filters:** City listing page queries may filter by rank or cached counts
   - Profiles published without immediate reranking won't appear

---

### Issue 4: Resource Consumption - Reranking
**Problem:** Reranking is extremely resource-intensive

**What Happens on `do_action('acf/save_post', $profile_id)`:**
1. Triggers `DH_Profile_Rankings::update_ranks_on_save()`
2. Queries ALL published profiles in same city (`posts_per_page = -1`)
3. Loops through ALL profiles to calculate scores (reads 3 ACF fields per profile)
4. Sorts all profiles by score
5. Updates `city_rank` ACF field for ALL profiles in city
6. Repeats for state: queries ALL profiles in state, calculates, updates ALL
7. Calls `wp_cache_flush()` to clear entire object cache
8. Each ACF `update_field()` triggers database writes

**Scale Examples:**
- 5 profiles in Miami → 5 DB reads + 5 DB writes for city rank
- 500 profiles in Florida → 500 DB reads + 500 DB writes for state rank
- If you process 100 profiles across 20 cities in Florida:
  - 20 city reranks (20 queries, ~2000 field reads, ~2000 field writes)
  - 1 state rerank (1 query, ~500 field reads, ~500 field writes)
  - 21 full cache flushes

**Why It's Worse in Batches:**
- Prep Profiles: Reranks once per city + once for state = minimal
- Production Queue: Same, but between publish and rerank, profiles are "live" without correct ranks

---

### Issue 5: No Cache Clearing Per Profile
**Problem:** Individual profile saves don't clear city/state listing caches

**What Should Happen:**
- When profile published → clear cache for containing city-listing
- When profile published → clear cache for containing state-listing
- When profile reranked → clear cache for city-listing and state-listing

**What Actually Happens:**
- Only `wp_cache_flush()` in ranking module (nuclear option)
- No targeted cache invalidation
- City/state listing pages may show stale data

---

## Your Notes vs Current Implementation

### Your Understanding:
1. Create city listing page
2. Send city for AI content generation
3. Publish all profiles for that city (based on area term)
4. Rerank at end OR make manual button

### What Actually Happens:
1. ✅ Create city listing page - CORRECT
2. ✅ Send city for AI - CORRECT  
3. ❌ Publish profiles by FILTER, not by city - DIFFERENT
   - Current: publishes ALL filtered profiles at once
   - Your expectation: publish per city
4. ⚠️ Rerank triggers full recalculation per city/state - RESOURCE HEAVY

---

## Root Causes Summary

1. **Architectural Mismatch:**
   - System treats profiles in batches (5 at a time)
   - But reranking works per city/state (potentially hundreds at once)
   - Mismatch between batch granularity and ranking granularity

2. **Missing Transactional Logic:**
   - No "unit of work" concept (should be: one city = one transaction)
   - Publishing and ranking are separated in time
   - No locking or queue deduplication

3. **Cache Strategy:**
   - Nuclear cache flush is inefficient
   - No targeted cache invalidation
   - No cache priming after updates

4. **State Taxonomy Bug:**
   - Inconsistent between two systems
   - Breaks city-state relationship model

---

## Proposed Requirements for New System

### Core Principles:
1. **Process by CITY, not by profile count**
   - One city = one unit of work
   - All profiles in city processed together
   - Ensures consistency

2. **Defer Reranking:**
   - Don't rerank during batch processing
   - Manual "Rerank All" button OR single rerank at end
   - Reduces resource consumption by 95%

3. **Minimal Cache Operations:**
   - Only clear cache at end, not per operation
   - Consider cache priming as separate manual step
   - Avoid `wp_cache_flush()` during processing

4. **Fix State Taxonomy:**
   - Remove state taxonomy from city-listings
   - Follow CITY-STATE-RELATIONSHIPS.md model

5. **No Cron, Use Alternative:**
   - Frontend polling (current) OR
   - Admin AJAX long-running request OR  
   - WP-CLI command (best for large batches)

---

## Next Steps

1. Agree on basic flow:
   - Process by city or by profile batch?
   - Rerank immediately, defer to end, or manual button?
   - Cache strategy?

2. Design new streamlined process

3. Implement without breaking existing system

4. Test with small batch before production use
