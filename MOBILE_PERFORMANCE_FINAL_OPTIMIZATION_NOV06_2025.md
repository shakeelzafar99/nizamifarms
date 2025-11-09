# Mobile Performance - Final Optimization Plan
**Date:** November 6, 2025  
**Goal:** Eliminate all loading delays, make tab switches instant, prefetch all categories  
**Status:** 🚀 Implementation Plan

---

## 🎯 Current Issues

### **1. Tab Switch Delay (3-4 seconds)**
- Switching between Quantities ↔ Open Orders ↔ Expenses shows 3-4s load
- Even with cache, first network call blocks UI
- User sees loading spinner every time

### **2. Category Navigation Delay**
- Each category level needs to be opened individually to cache
- 20 categories = 20 individual loads
- Not efficient or natural
- Shows old page headings while syncing

### **3. Network Error**
- Intermittent "Network error - please check your connection"
- Happens during category navigation
- Possibly race condition or concurrent request limit

### **4. No Sync Status**
- User doesn't know when data was last updated
- Need "Last synced: 5s ago" type indicator

---

## 📊 Payload Analysis

### **Open Orders Endpoint** (`/rider/store/open-orders`)

**Current Payload Size:** ~150-300KB for 50 orders

**Fields Returned Per Order:**
```php
[
    'id', 'order_number', 'order_date', 'order_status',
    'total_price', 'payment_method', 'expected_packets',
    'customer_name', 'customer_phone', 'customer_address',
    'customer_address1', 'customer_address2', 'customer_city',
    'customer_province', 'customer_postal_code', 'customer_id',
    'has_verified_location',
    'assigned_rider' => ['id', 'name'],
    'items_count', 'items_summary', // "Product A (x2), Product B (x1)"
    'line_items' => [
        'id', 'product_name', 'variant_name', 'quantity',
        'unit_price', 'unit_price_formatted', 'line_total',
        'total', 'total_formatted', 'preparation_status'
    ],
    'shipping_total', 'tip_amount',
    'discounts' => ['discount_amount', 'discount_type'],
    'preparation_summary' => ['preparing_count', 'total_items'],
    'invoice' => ['image_url', 'pdf_url']
]
```

**Mobile List View Uses:**
- ✅ id, order_number, order_date, order_status
- ✅ total_price, customer_name
- ✅ assigned_rider (id, name)
- ✅ preparation_summary
- ❌ customer_address (not shown in list)
- ❌ customer_address1, address2, city, province, postal_code (not in list)
- ❌ items_summary (not shown, we show count)
- ❌ line_items (only needed when expanded)
- ❌ discounts (not shown in list)
- ❌ invoice URLs (only needed when viewing invoice)

**Optimization:**
- Create a "light" endpoint for list view
- Return full details only when order is expanded
- Save ~60-70% payload

---

### **Open Quantities Endpoint** (`/rider/store/open-quantities`)

**Current Payload:** ~50-100KB per level

**Fields Returned:**
```php
[
    'name', 'quantity', 'lean_quantity', 'non_lean_quantity',
    'processing_quantity', 'prepared_quantity',
    'processing_lean', 'processing_non_lean',
    'prepared_lean', 'prepared_non_lean',
    'order_count', 'product_count',
    // At orders level:
    'order_id', 'order_number', 'status', 'customer_name', 'total_price'
]
```

**Mobile Uses:** ✅ All fields (already optimized)

**No trimming needed** - this is already lean

---

## 🚀 Solution: 3-Phase Approach

### **Phase 1: Aggressive Prefetching (Quantities)**

**Goal:** Load all category levels on first visit, make all navigation instant

**Implementation:**
```javascript
// On first load of Quantities tab:
1. Load Level 0 (root categories)
2. In background, recursively prefetch:
   - For each L0 category → fetch L1
   - For each L1 category → fetch L2
   - For each L2 category → fetch L3
   - Stop at products level (don't prefetch orders yet)
3. Use concurrency limit (6 parallel requests)
4. Cache all results
5. Show small "Prefetching..." indicator
6. Once done, all category navigation is instant
```

**Benefits:**
- First-time user: sees root immediately, background prefetch takes 10-15s
- All subsequent navigation: instant (0ms)
- Covers 95% of use cases (drilling to products)
- Orders level still loads on-demand (too many to prefetch)

---

### **Phase 2: Light Payload for Open Orders**

**Create New Endpoint:** `/rider/store/open-orders-light`

**Returns Only:**
```php
[
    'id', 'order_number', 'order_date', 'order_status',
    'total_price', 'customer_name',
    'assigned_rider_id', 'assigned_rider_name',
    'preparing_count', 'total_items',
    'has_verified_location'
]
```

**Full Details Endpoint:** `/rider/store/open-orders/{id}/details`
- Called when order is expanded
- Returns line_items, customer address, discounts, invoice URLs

**Savings:**
- Light list: ~30-50KB (vs 150-300KB)
- 70-80% reduction
- Load time: 1-1.5s (vs 3-4s)

---

### **Phase 3: Smart Navigation & Sync Status**

**Fix Navigation Delay:**
```javascript
// When user taps a category:
1. Immediately show cached data (if available)
2. Update breadcrumb/header instantly
3. Start background sync
4. When sync completes, silently update list
5. No "waiting on old page" issue
```

**Add Last Synced Indicator:**
```javascript
// Header shows:
"Last synced: 5s ago" (green dot)
"Syncing..." (spinner)
"Synced" (checkmark, fades after 2s)
```

**Fix Network Error:**
- Add request deduplication (cancel in-flight requests when navigating)
- Add retry logic with exponential backoff
- Better error messages

---

## 📋 Implementation Steps

### **Step 1: Backend - Light Endpoint**

**File:** `app/Http/Controllers/API/RiderController.php`

```php
public function getStoreOpenOrdersLight(Request $request)
{
    $query = OrderModel::select([
            'id', 'order_number', 'order_date', 'order_status',
            'total_price', 'name', 'address_first_name', 'address_last_name',
            'customer_id', 'assigned_rider_id'
        ])
        ->with(['customer:id,name,latitude,longitude,verified_location_url'])
        ->with(['assignedRider:id,fullname'])
        ->where(function($q) {
            $q->where('external_source', '!=', 'shopify')
              ->orWhereNull('external_source');
        })
        ->whereNotIn('order_status', ['delivered', 'completed', 'cancelled', 'refunded'])
        ->orderBy('order_date', 'desc')
        ->get();

    $formatted = $query->map(function($order) {
        // Calculate prep summary (lightweight query)
        $prepSummary = DB::table('t_crm_prod_order_line_item')
            ->where('order_id', $order->id)
            ->selectRaw('COUNT(*) as total, SUM(CASE WHEN preparation_status = "preparing" THEN 1 ELSE 0 END) as preparing')
            ->first();

        return [
            'id' => $order->id,
            'order_number' => $order->order_number ?? 'NF-' . str_pad($order->id, 4, '0', STR_PAD_LEFT),
            'order_date' => $order->order_date,
            'order_status' => $order->order_status,
            'total_price' => $order->total_price,
            'customer_name' => $order->name ?? trim(($order->address_first_name ?? '') . ' ' . ($order->address_last_name ?? '')) ?: ($order->customer->name ?? 'N/A'),
            'assigned_rider_id' => $order->assigned_rider_id,
            'assigned_rider_name' => $order->assignedRider->fullname ?? null,
            'preparing_count' => $prepSummary->preparing ?? 0,
            'total_items' => $prepSummary->total ?? 0,
            'has_verified_location' => !empty($order->customer->latitude) || !empty($order->customer->verified_location_url),
        ];
    });

    return response()->json([
        'success' => true,
        'orders' => $formatted,
        'total_count' => $formatted->count()
    ]);
}

public function getStoreOpenOrderDetails($orderId)
{
    $order = OrderModel::with(['customer', 'lineItems', 'assignedRider', 'discounts'])
        ->findOrFail($orderId);

    // Return full details (line items, address, etc)
    // ... existing formatting logic ...
}
```

**Route:** `routes/api.php`
```php
Route::get('/store/open-orders-light', [RiderController::class, 'getStoreOpenOrdersLight']);
Route::get('/store/open-orders/{id}/details', [RiderController::class, 'getStoreOpenOrderDetails']);
```

---

### **Step 2: Mobile - Aggressive Prefetch**

**File:** `src/screens/StoreOpenQuantitiesScreen.js`

```javascript
const [prefetching, setPrefetching] = useState(false);
const [prefetchProgress, setPrefetchProgress] = useState(0);
const prefetchedRef = useRef(false);

// Aggressive prefetch on first load
useEffect(() => {
  if (!prefetchedRef.current && items.length > 0 && level === 0) {
    prefetchedRef.current = true;
    prefetchAllCategories();
  }
}, [items, level]);

const prefetchAllCategories = async () => {
  setPrefetching(true);
  const queue = [];
  let completed = 0;

  // Start with L0 items (already loaded)
  for (const item of items) {
    queue.push({
      level: 0,
      name: item.name,
      filters: {[hierarchy[0]]: item.name},
    });
  }

  const total = queue.length * 3; // Estimate: L1, L2, L3 per L0
  const concurrency = 6;
  const inFlight = [];

  while (queue.length > 0 || inFlight.length > 0) {
    // Fill up to concurrency limit
    while (inFlight.length < concurrency && queue.length > 0) {
      const node = queue.shift();
      const nextLevel = node.level + 1;

      if (nextLevel >= hierarchy.length - 1) continue; // Stop before orders

      const promise = fetchLevelData(nextLevel, node.filters)
        .then(children => {
          completed++;
          setPrefetchProgress(Math.round((completed / total) * 100));

          // Add children to queue
          children.forEach(child => {
            const nextField = hierarchy[nextLevel];
            queue.push({
              level: nextLevel,
              name: child.name,
              filters: {...node.filters, [nextField]: child.name},
            });
          });
        })
        .catch(e => console.log('Prefetch error:', e.message));

      inFlight.push(promise);
    }

    // Wait for at least one to complete
    if (inFlight.length > 0) {
      await Promise.race(inFlight);
      // Remove completed promises
      for (let i = inFlight.length - 1; i >= 0; i--) {
        if (await Promise.race([inFlight[i], Promise.resolve('pending')]) !== 'pending') {
          inFlight.splice(i, 1);
        }
      }
    }
  }

  setPrefetching(false);
  console.log('✅ Prefetch complete! All categories cached.');
};
```

**UI Indicator:**
```javascript
{prefetching && (
  <View style={styles.prefetchBanner}>
    <ActivityIndicator size="small" color="#9333EA" />
    <Text style={styles.prefetchText}>
      Prefetching categories... {prefetchProgress}%
    </Text>
  </View>
)}
```

---

### **Step 3: Fix Navigation Delay**

**File:** `src/screens/StoreOpenQuantitiesScreen.js`

```javascript
const handleItemPress = item => {
  if (level >= hierarchy.length - 1) return;

  const currentField = hierarchy[level];
  const newFilters = {...filters, [currentField]: item.name};

  // 1. Update UI immediately
  setBreadcrumb([...breadcrumb, {name: item.name, level, field: currentField}]);
  setLevel(level + 1);
  setFilters(newFilters);
  setSelectedOrders([]);

  // 2. Check cache
  const cacheKey = JSON.stringify({level: level + 1, filters: newFilters});
  const cached = viewCache.get(cacheKey);

  if (cached) {
    // Show cached data instantly
    setItems(cached);
    setLoading(false);
    // Silent refresh in background
    fetchQuantities(); // Updates cache if changed
  } else {
    // No cache, show loading
    setLoading(true);
    fetchQuantities();
  }
};
```

---

### **Step 4: Last Synced Timestamp**

**File:** `src/screens/StoreOpenQuantitiesScreen.js`

```javascript
const [lastSynced, setLastSynced] = useState(null);
const [syncStatus, setSyncStatus] = useState('synced'); // 'syncing', 'synced', 'error'

const fetchQuantities = async () => {
  setSyncStatus('syncing');
  try {
    // ... existing fetch logic ...
    setLastSynced(Date.now());
    setSyncStatus('synced');
  } catch (error) {
    setSyncStatus('error');
    // ... error handling ...
  }
};

// Format relative time
const getRelativeTime = (timestamp) => {
  if (!timestamp) return '';
  const seconds = Math.floor((Date.now() - timestamp) / 1000);
  if (seconds < 60) return `${seconds}s ago`;
  const minutes = Math.floor(seconds / 60);
  if (minutes < 60) return `${minutes}m ago`;
  const hours = Math.floor(minutes / 60);
  if (hours < 24) return `${hours}h ago`;
  return `${Math.floor(hours / 24)}d ago`;
};

// Update every 5s
useEffect(() => {
  const interval = setInterval(() => {
    setLastSynced(prev => prev); // Trigger re-render
  }, 5000);
  return () => clearInterval(interval);
}, []);
```

**UI:**
```javascript
<View style={styles.syncStatus}>
  {syncStatus === 'syncing' && (
    <>
      <ActivityIndicator size="small" color="#9333EA" />
      <Text style={styles.syncText}>Syncing...</Text>
    </>
  )}
  {syncStatus === 'synced' && lastSynced && (
    <>
      <Text style={styles.syncDot}>●</Text>
      <Text style={styles.syncText}>Synced {getRelativeTime(lastSynced)}</Text>
    </>
  )}
  {syncStatus === 'error' && (
    <Text style={styles.syncTextError}>Sync failed</Text>
  )}
</View>
```

---

### **Step 5: Fix Network Error**

**Add Request Cancellation:**

```javascript
const abortControllerRef = useRef(null);

const fetchQuantities = async () => {
  // Cancel previous request if still in flight
  if (abortControllerRef.current) {
    abortControllerRef.current.abort();
  }

  abortControllerRef.current = new AbortController();

  try {
    const response = await api.get('/rider/store/open-quantities', {
      params: {level, filters: JSON.stringify(filters)},
      signal: abortControllerRef.current.signal,
    });
    // ... rest of logic ...
  } catch (error) {
    if (error.name === 'AbortError') {
      console.log('Request cancelled (navigated away)');
      return; // Silent, expected
    }
    // Real error
    console.error('Failed to fetch quantities:', error);
    Alert.alert('Error', 'Failed to load quantities');
  }
};
```

**Add Retry Logic:**

```javascript
const fetchWithRetry = async (fn, retries = 2) => {
  for (let i = 0; i <= retries; i++) {
    try {
      return await fn();
    } catch (error) {
      if (i === retries || error.name === 'AbortError') throw error;
      await new Promise(r => setTimeout(r, 1000 * Math.pow(2, i))); // Exponential backoff
    }
  }
};

const fetchQuantities = () => fetchWithRetry(async () => {
  // ... existing fetch logic ...
});
```

---

## 📊 Expected Results

### **Before:**
- Tab switch: 3-4s loading spinner
- Category navigation: 1-2s per level
- 20 categories = 20 individual loads
- No sync status
- Occasional network errors

### **After:**
- Tab switch: Instant (cached) + silent refresh
- Category navigation: Instant (prefetched)
- First load: 10-15s background prefetch, then all instant
- Clear sync status: "Synced 5s ago"
- No network errors (cancellation + retry)

### **Payload Savings:**
- Open Orders: 150KB → 40KB (73% reduction)
- Load time: 3-4s → 1-1.5s

---

## 🎯 Implementation Order

1. ✅ **Backend: Light endpoint** (30 min)
2. ✅ **Mobile: Use light endpoint** (15 min)
3. ✅ **Mobile: Aggressive prefetch** (45 min)
4. ✅ **Mobile: Fix navigation delay** (20 min)
5. ✅ **Mobile: Last synced timestamp** (20 min)
6. ✅ **Mobile: Request cancellation** (15 min)

**Total:** ~2.5 hours

---

## ✅ Testing Checklist

- [ ] Open Orders loads in < 1.5s
- [ ] Tab switch shows cached data instantly
- [ ] Quantities prefetches all categories in background
- [ ] All category navigation is instant after prefetch
- [ ] "Last synced" updates every 5s
- [ ] No "old page" shown during navigation
- [ ] No network errors during rapid navigation
- [ ] Web changes reflect within 5s on mobile

---

**Status:** Ready to implement  
**Priority:** HIGH - Critical UX improvement

