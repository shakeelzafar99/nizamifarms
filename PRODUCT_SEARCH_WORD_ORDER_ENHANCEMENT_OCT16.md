# Product Search Word Order Enhancement - Oct 16, 2025

## Summary
Enhanced the product search functionality to support flexible word-order matching. Users can now search for products with multiple words in any order, making the search more intuitive and user-friendly.

## Problem
Previously, the search required exact word sequence matching:
- Searching "chicken boneless" would only match titles containing that exact phrase in that exact order
- Would NOT match "Boneless Chicken" or "Boneless LEAN Chicken"
- Limited search effectiveness and user experience

## Solution
Implemented word-by-word matching that searches for each word independently:
- Searching "chicken boneless" now matches:
  - "Chicken Boneless"
  - "Boneless Chicken"
  - "Boneless LEAN Chicken"
  - "Chicken (B1) Breast Butterfly per kg - Boneless Chicken"
  - Any title containing both "chicken" AND "boneless" in any position

## Changes Made

### File: `app/Http/Controllers/CRM/ProductController.php`

#### 1. Main Products Page Search (index method) - Lines 556-583

**Before:**
```php
if ($request->has('search') && $request->search) {
    $search = $request->search;
    $query->where(function($q) use ($search) {
        $q->where('title', 'LIKE', "%{$search}%")
          ->orWhere('vendor', 'LIKE', "%{$search}%")
          // ... etc
    });
}
```

**After:**
```php
if ($request->has('search') && $request->search) {
    $search = $request->search;
    
    // Split search query into individual words for flexible matching
    $searchWords = array_filter(explode(' ', $search));
    
    $query->where(function($q) use ($search, $searchWords) {
        // If multiple words, match each word independently in title
        if (count($searchWords) > 1) {
            $q->where(function($titleQuery) use ($searchWords) {
                foreach ($searchWords as $word) {
                    $titleQuery->where('title', 'LIKE', "%{$word}%");
                }
            });
        } else {
            // Single word search - keep original behavior
            $q->where('title', 'LIKE', "%{$search}%");
        }
        
        // Also search in vendor, product_type, and variants
        $q->orWhere('vendor', 'LIKE', "%{$search}%")
          ->orWhere('product_type', 'LIKE', "%{$search}%")
          ->orWhereHas('variants', function($vq) use ($search) {
              $vq->where('sku', 'LIKE', "%{$search}%")
                ->orWhere('title', 'LIKE', "%{$search}%");
          });
    });
}
```

#### 2. API Search Endpoint (search method) - Lines 736-782

**Before:**
```php
$products = \App\Models\CRM\ProductModel::with('variants')
    ->where('is_active', true)
    ->where(function($q) use ($query) {
        $q->where('title', 'LIKE', "%{$query}%")
          ->orWhereHas('variants', function($vq) use ($query, $queryNoHyphens) {
              $vq->where('sku', 'LIKE', "%{$query}%")
                ->orWhere('title', 'LIKE', "%{$query}%")
                ->orWhereRaw('REPLACE(REPLACE(sku, "-", ""), " ", "") LIKE ?', ["%{$queryNoHyphens}%"]);
          });
    })
    ->limit($limit)
    ->get();
```

**After:**
```php
// Split search query into individual words for flexible matching
$searchWords = array_filter(explode(' ', $query));

$products = \App\Models\CRM\ProductModel::with('variants')
    ->where('is_active', true)
    ->where(function($q) use ($query, $searchWords) {
        $queryNoHyphens = str_replace(['-', ' '], '', $query);
        
        // If multiple words, match each word independently in title (in any order)
        if (count($searchWords) > 1) {
            $q->where(function($titleQuery) use ($searchWords) {
                foreach ($searchWords as $word) {
                    $titleQuery->where('title', 'LIKE', "%{$word}%");
                }
            });
        } else {
            // Single word search - keep original behavior
            $q->where('title', 'LIKE', "%{$query}%");
        }
        
        // Also search in variants
        $q->orWhereHas('variants', function($vq) use ($query, $queryNoHyphens, $searchWords) {
            if (count($searchWords) > 1) {
                // Multi-word search in variant title
                $vq->where(function($titleQuery) use ($searchWords) {
                    foreach ($searchWords as $word) {
                        $titleQuery->where('title', 'LIKE', "%{$word}%");
                    }
                });
            } else {
                $vq->where('title', 'LIKE', "%{$query}%");
            }
            
            // SKU search (always use full query)
            $vq->orWhere('sku', 'LIKE', "%{$query}%")
              ->orWhereRaw('REPLACE(REPLACE(sku, "-", ""), " ", "") LIKE ?', ["%{$queryNoHyphens}%"]);
        });
    })
    ->limit($limit)
    ->get();
```

## How It Works

### Logic Flow:
1. **Split Query**: The search query is split into individual words using `explode(' ', $query)`
2. **Filter Empty**: Empty strings are removed using `array_filter()`
3. **Word Count Check**: 
   - If **multiple words**: Each word must appear in the title (using AND logic)
   - If **single word**: Uses original LIKE search behavior
4. **Title Matching**: For multi-word searches, uses nested WHERE clauses to require ALL words
5. **Variant Matching**: Same logic applied to product variants
6. **Other Fields**: Vendor, product type, and SKU still use full phrase matching

### Example Queries:

**Query: "chicken boneless"**
- Split into: ["chicken", "boneless"]
- SQL: `title LIKE '%chicken%' AND title LIKE '%boneless%'`
- Matches: "Boneless Chicken", "Chicken Boneless Cubes", "Boneless LEAN Chicken"

**Query: "lean breast butterfly"**
- Split into: ["lean", "breast", "butterfly"]
- SQL: `title LIKE '%lean%' AND title LIKE '%breast%' AND title LIKE '%butterfly%'`
- Matches: "Chicken (B1) LEAN Breast Butterfly per kg"

**Query: "boneless"** (single word)
- SQL: `title LIKE '%boneless%'` (original behavior preserved)
- Matches any title containing "boneless"

## Benefits

### User Experience:
- ✅ More intuitive search behavior
- ✅ Finds products regardless of word order in title
- ✅ Reduces "no results" frustration
- ✅ Matches how users naturally think about products

### Technical:
- ✅ Case-insensitive matching (SQL LIKE is case-insensitive)
- ✅ Works with product titles and variant titles
- ✅ Maintains SKU exact-match behavior
- ✅ Maintains vendor/product type full-phrase matching
- ✅ No breaking changes - single word searches work as before

### Performance:
- ✅ Uses existing LIKE queries (already indexed if title is indexed)
- ✅ Minimal overhead - only splits string on spaces
- ✅ No additional database queries
- ✅ Maintains existing pagination limits

## Search Behavior Breakdown

| Search Query | Behavior | Example Matches |
|--------------|----------|-----------------|
| "chicken" | Single word - exact phrase | "Chicken Breast", "Boneless Chicken" |
| "chicken boneless" | Multi-word - any order | "Boneless Chicken", "Chicken Boneless Cubes" |
| "boneless lean chicken" | Three words - all must exist | "Chicken (B2) LEAN Boneless Cubes per kg" |
| "P12-DM" | SKU - hyphen flexible | Variant with SKU "P12-DM" or "P12 DM" |

## What Still Uses Full Phrase Matching

These fields intentionally still use full-phrase matching (not word-by-word):
1. **Vendor** - Users typically know vendor names exactly
2. **Product Type** - Categories are specific phrases
3. **SKU** - Product codes are exact identifiers (with hyphen flexibility)

## Testing Recommendations

### Test Cases:
- [ ] Search "chicken boneless" → should find "Boneless Chicken" products
- [ ] Search "boneless chicken" → should find same results as above
- [ ] Search "lean breast" → should find all LEAN breast products
- [ ] Search "chicken" (single word) → should find all chicken products
- [ ] Search "P12-DM" (SKU) → should find specific product variant
- [ ] Search with 3+ words → all words must be present in any order

### Expected Results:
- Multi-word searches return products containing ALL words in ANY order
- Single word searches work as before
- Results are sorted by title (alphabetically)
- Pagination shows 20 products per page

## Areas Affected

### Frontend:
- `/products` page search bar
- Order creation product search dropdowns
- Any component using `/api/products/search` endpoint

### API Endpoints:
- `GET /products?search=...` (main products page)
- `GET /api/products/search?q=...` (API search for dropdowns)

## No Database Changes Required
All changes are in application logic only - no migrations needed.

## Backward Compatibility
✅ **Fully backward compatible**
- Single word searches work exactly as before
- Existing integrations unaffected
- API response format unchanged
- Only enhancement is multi-word search flexibility

---

## Future Enhancement Ideas (Optional)

1. **Relevance Scoring**: Rank results by word proximity (words closer together = higher score)
2. **Typo Tolerance**: Use Levenshtein distance for fuzzy matching
3. **Synonym Support**: "breast" also searches "brest" or "breasts"
4. **Search History**: Save recent searches per user
5. **Search Suggestions**: Auto-complete based on popular searches
6. **Highlighted Results**: Bold matched words in search results

