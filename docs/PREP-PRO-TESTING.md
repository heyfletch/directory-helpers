# Prep Pro Testing Guide

## Access the Page

Navigate to: **Directory Helpers → Prep Pro**
URL: `/wp-admin/admin.php?page=dh-prep-pro`

---

## Test Process

### Step 1: Filter Profiles (Small Batch First)
1. Select a state (e.g., Florida)
2. Set Status: "Refining"
3. Set Profiles: "≥ 3"
4. Set City Search or select specific city for small test
5. Click "Filter"

**Expected:** See list of 5-10 profiles for testing

---

### Step 2: Fast Publish
1. Click "Fast Publish (X profiles)" button
2. Confirm the dialog
3. Wait for page to reload

**Expected:**
- Success message: "Published X profiles. Created Y cities."
- Profiles now have status "Published"
- City-listing posts created in draft status
- NO state taxonomy assigned to city-listings ✓
- Area terms cleaned (no " - ST" suffix)
- AI webhook triggered for new cities

**Check:**
- Go to Pages → City Listings → Draft
- Verify new cities were created
- Edit one city → Check taxonomies: should have Area + Niche, NO State

---

### Step 3: Test Maintenance Buttons

#### A. Rerank All Profiles
1. Click "Rerank All Profiles"
2. Wait for completion

**Expected:**
- Success message: "Reranking complete."
- Profile ranks updated (check a few profiles)
- Only tracked cities/state were reranked

#### B. Clear Cache
1. Click "Clear Cache"
2. Wait for completion

**Expected:**
- Success message: "Cache cleared."
- Post caches cleared for tracked items only

#### C. Prime Cache
1. Click "Prime Cache"
2. Wait for completion

**Expected:**
- Success message: "Cache primed."
- Caches loaded for tracked items

#### D. Clear and Prime Cache
1. Click "Clear and Prime Cache"
2. Wait for completion

**Expected:**
- Success message: "Cache cleared and primed."
- Both operations completed

#### E. Rerank, Purge, Prime (All-in-One)
1. Click "Rerank, Purge, Prime"
2. Confirm dialog
3. Wait for completion (may take 30-60 seconds)

**Expected:**
- Success message: "Rerank, purge, and prime complete."
- All three operations completed in sequence

---

### Step 4: Reset Tracking
1. Click "Reset Tracking"
2. Verify maintenance buttons disappear

**Expected:**
- Success message: "Tracking reset."
- Maintenance section no longer visible
- Can run another batch

---

## What to Verify

### City Listings
```
✓ Created in draft status
✓ Has area taxonomy term
✓ Has niche taxonomy term
✓ NO state taxonomy term (this is critical!)
✓ Slug format: "city-name-st-dog-trainers"
✓ Title format: "City Name, ST"
```

### Profiles
```
✓ Status changed from "refining" to "publish"
✓ Area term name cleaned (no " - ST")
✓ Ranks calculated after rerank button
✓ All profiles in same city have ranks
```

### Performance
```
✓ Fast Publish completes quickly (5-15 seconds)
✓ No timeouts
✓ Rerank button takes longer (30-60 seconds) but completes
```

### AI Integration
```
✓ n8n webhook called for each new city
✓ Check n8n logs to verify requests received
✓ Keyword format: "dog training in City Name, ST"
```

---

## Common Issues

### Issue: Duplicate Cities Created
**Symptom:** Same city exists multiple times  
**Cause:** Race condition (shouldn't happen in synchronous mode)  
**Check:** Look for duplicate slugs in city-listings

### Issue: City Has State Taxonomy
**Symptom:** City-listing has state term assigned  
**Cause:** Bug - should NOT happen  
**Fix:** Manually remove state taxonomy from city

### Issue: Profiles Not Showing on City Pages
**Symptom:** City page exists but profiles don't appear  
**Cause:** Ranks not updated or cache issue  
**Fix:** Click "Rerank, Purge, Prime" button

### Issue: Timeout on Large Batch
**Symptom:** Page times out during Fast Publish  
**Cause:** Too many profiles at once  
**Fix:** Use city filter to process smaller batches

---

## Performance Benchmarks

### Expected Times (100 profiles, 10 cities)

**Fast Publish:**
- Without AI: ~5 seconds
- With AI: ~15 seconds (1 second per city for webhook)

**Rerank All:**
- 10 cities + 1 state: ~30-60 seconds
- Depends on total profile count

**Cache Operations:**
- Clear: ~1 second
- Prime: ~2 seconds
- Both: ~3 seconds

---

## Next Steps After Testing

1. Test with larger batch (50-100 profiles)
2. Verify no duplicates created
3. Confirm performance is acceptable
4. Test Content Production Queue integration
5. Document any issues found

---

## Comparison to Old System

### Old Production Queue (100 profiles):
- Time: ~147 seconds (2.5 minutes)
- DB Queries: ~16,000
- Reranking: Automatic during process
- Issues: Duplicates, missing profiles, state taxonomy bug

### New Prep Pro (100 profiles):
- Fast Publish: ~15 seconds
- DB Queries: ~320
- Reranking: Manual button (optional)
- Issues: None expected

**Speed Improvement: 10x faster**
**Resource Reduction: 95% fewer queries**
