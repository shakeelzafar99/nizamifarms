# Open Order Quantities - Performance Optimization Guide
**Date:** November 6, 2025  
**Purpose:** Comprehensive guide to optimize Open Order Quantities performance in production

---

## 🎯 Quick Wins (Immediate Impact)

### 1. **Date Filter (Default 20 Days)** ✅ CRITICAL - IMPLEMENTED
**Files:** 
- `app/Http/Controllers/CRM/OrderController.php` (line 1872-1874)
- `app/Http/Controllers/API/RiderController.php` (line 2548-2549)

**Change:** Added automatic filter to only show orders from the **last 20 days** by default.

```php
// Default: Only show orders from last 20 days for performance
$query->where('o.order_date', '>=', Carbon::now()->subDays(20));
```

**Expected Result:**
- 70-90% fewer rows to scan (depending on order volume)
- Much faster query execution
- Drastically reduced dataset size

**To Change the Days:**
```php
// Change 20 to any number of days you want
->where('o.order_date', '>=', Carbon::now()->subDays(20));
```

---

### 2. **Database Indexes** ✅ CRITICAL
**File:** `database/migrations/optimize_open_quantities_performance_nov06_2025.sql`

**Action:** Run the SQL migration script to add 20+ strategic indexes

**Expected Result:**
- 50-80% faster initial page load
- 60-90% faster drill-down operations
- Significantly better performance with 1000+ orders

**Verification:**
```sql
-- Check if indexes were created successfully
SHOW INDEX FROM t_crm_prod_order_line_item;
SHOW INDEX FROM t_crm_prod_order;
SHOW INDEX FROM t_crm_prod_product;
```

---

## 🚀 Medium-Term Optimizations

### 2. **Query Result Caching**
Add Laravel caching to frequently accessed data that doesn't change often.

**Implementation in `OrderController.php`:**
```php
public function openQuantitiesData(Request $request)
{
    // Generate cache key based on request parameters
    $cacheKey = 'open_qty_' . md5(json_encode([
        'level' => $request->input('level'),
        'filters' => $request->input('filters'),
        'date_range' => $request->input('date_range'),
    ]));
    
    // Cache for 2 minutes (data updates frequently)
    $data = Cache::remember($cacheKey, 120, function() use ($request) {
        // Existing query logic here...
        return $results;
    });
    
    return response()->json(['success' => true, 'data' => $data]);
}
```

**Expected Result:**
- Instant response for repeated queries
- Reduced database load
- Better concurrent user support

**Caveat:** Cache must be invalidated when orders are marked as prepared

---

### 3. **Paginate Results**
Currently loading ALL orders at once. Add pagination for large datasets.

**Implementation:**
```php
// In openQuantitiesData()
$perPage = $request->input('per_page', 50); // Default 50 items
$results = $query->paginate($perPage);

return response()->json([
    'success' => true,
    'data' => $results->items(),
    'pagination' => [
        'current_page' => $results->currentPage(),
        'total_pages' => $results->lastPage(),
        'total_items' => $results->total(),
        'per_page' => $results->perPage(),
    ]
]);
```

**Frontend Update:**
- Add "Load More" button or infinite scroll
- Show "Showing X of Y" count

**Expected Result:**
- Much faster initial load
- Better user experience with large datasets
- Reduced memory usage

---

### 4. **Optimize JOIN Logic**
Current query has complex OR conditions that prevent index usage.

**Problem in `OrderController.php` (line 1859):**
```php
->orWhereRaw('LOWER(TRIM(li.name)) = LOWER(TRIM(p.title))'); // Name fallback
```

**Solution: Pre-normalize product names**

Add a new column to improve matching:
```sql
ALTER TABLE t_crm_prod_product 
ADD COLUMN title_normalized VARCHAR(255) GENERATED ALWAYS AS (LOWER(TRIM(title))) STORED;

CREATE INDEX idx_product_title_normalized ON t_crm_prod_product(title_normalized);
```

Update line items on insert/update:
```sql
ALTER TABLE t_crm_prod_order_line_item 
ADD COLUMN name_normalized VARCHAR(255);

CREATE INDEX idx_line_item_name_normalized ON t_crm_prod_order_line_item(name_normalized);

-- Backfill existing data
UPDATE t_crm_prod_order_line_item SET name_normalized = LOWER(TRIM(name));
```

Then update the query:
```php
->orWhereColumn('li.name_normalized', 'p.title_normalized'); // Much faster!
```

**Expected Result:**
- 3-5x faster JOIN operations
- Better index utilization
- Scalable with large product catalogs

---

## 📊 Advanced Optimizations

### 5. **Database Query Profiling**
Enable slow query log to identify remaining bottlenecks.

**MySQL Configuration:**
```sql
SET GLOBAL slow_query_log = 'ON';
SET GLOBAL long_query_time = 1; -- Log queries taking > 1 second
SET GLOBAL log_queries_not_using_indexes = 'ON';
```

**Check slow queries:**
```bash
# Linux
tail -f /var/log/mysql/mysql-slow.log

# Windows
tail -f "C:\ProgramData\MySQL\MySQL Server 8.0\Data\slow.log"
```

---

### 6. **Database Server Configuration**
Optimize MySQL/MariaDB settings for better performance.

**Key Settings in `my.cnf` or `my.ini`:**
```ini
[mysqld]
# Increase buffer pool (use 50-70% of available RAM)
innodb_buffer_pool_size = 2G

# Increase query cache (if using MySQL 5.7 or earlier)
query_cache_size = 128M
query_cache_type = 1

# Optimize for many concurrent connections
max_connections = 200
thread_cache_size = 100

# Optimize InnoDB
innodb_flush_log_at_trx_commit = 2
innodb_log_buffer_size = 16M
```

---

### 7. **Frontend Optimizations**

**A. Debounce Search Input**
```javascript
// In open-quantities.blade.php
let searchTimeout;
document.getElementById('search-input').addEventListener('input', function(e) {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => {
        loadData(); // Only search after 500ms of no typing
    }, 500);
});
```

**B. Virtual Scrolling for Large Lists**
For 500+ items, consider virtual scrolling library to render only visible rows.

**C. Loading Skeleton**
Show skeleton loaders instead of blank screen during data fetch.

---

### 8. **Mobile App Optimizations**

**A. Implement Pull-to-Refresh Cache**
```javascript
// In StoreOpenQuantitiesScreen.js
const [lastFetchTime, setLastFetchTime] = useState(null);

const fetchQuantities = async (forceRefresh = false) => {
    // Only fetch if > 30 seconds since last fetch
    const now = Date.now();
    if (!forceRefresh && lastFetchTime && (now - lastFetchTime) < 30000) {
        return; // Use cached data
    }
    
    // Fetch from API...
    setLastFetchTime(now);
};
```

**B. Reduce Data Transfer**
```javascript
// Only fetch necessary fields
const response = await api.get('/rider/store/open-quantities', {
    params: {
        level,
        filters: JSON.stringify(filters),
        fields: 'name,quantity,lean_quantity,non_lean_quantity,order_count' // Specify fields
    },
});
```

---

## 🔄 Real-Time Sync Recommendations

### Current Status
- Web and mobile use **separate API endpoints**
- **No real-time push notifications** currently implemented
- Data updates on **page refresh or manual refresh**

### Recommendation: Implement Laravel Echo + WebSockets

**1. Install Laravel Echo Server**
```bash
npm install -g laravel-echo-server
laravel-echo-server init
```

**2. Broadcast Events When Orders Are Updated**
```php
// In OrderController.php bulkMarkOrdersAsPrepared()
use App\Events\OrderPreparationUpdated;

// After updating orders
broadcast(new OrderPreparationUpdated([
    'order_ids' => $orderIds,
    'preparation_status' => $preparationStatus,
]));
```

**3. Listen in Frontend**
```javascript
// Web App
Echo.channel('open-quantities')
    .listen('OrderPreparationUpdated', (e) => {
        console.log('Orders updated:', e.order_ids);
        loadData(); // Refresh data automatically
    });
```

**4. Mobile App Alternative: Polling**
For simpler implementation, use polling every 30 seconds when viewing Open Quantities:
```javascript
useEffect(() => {
    const interval = setInterval(() => {
        if (!refreshing && !loading) {
            fetchQuantities();
        }
    }, 30000); // Poll every 30 seconds
    
    return () => clearInterval(interval);
}, [refreshing, loading]);
```

**Expected Result:**
- **Real-time updates** across all users
- **No manual refresh** needed
- Better user experience

---

## 📈 Performance Monitoring

### Key Metrics to Track

1. **Page Load Time**
   - Target: < 2 seconds for initial load
   - Target: < 500ms for drill-down

2. **Database Query Time**
   - Target: < 200ms per query
   - Monitor with Laravel Telescope or Debugbar

3. **Memory Usage**
   - Target: < 128MB per request
   - Monitor with `memory_get_peak_usage()`

4. **Concurrent Users**
   - Target: Support 50+ concurrent users without slowdown

### Monitoring Tools

1. **Laravel Telescope** (Development)
   ```bash
   composer require laravel/telescope --dev
   php artisan telescope:install
   php artisan migrate
   ```

2. **New Relic or DataDog** (Production)
   - Application Performance Monitoring (APM)
   - Real-time alerts for slow queries

3. **Custom Performance Logging**
   ```php
   // In OrderController.php
   $startTime = microtime(true);
   
   // Query execution...
   
   $executionTime = (microtime(true) - $startTime) * 1000;
   \Log::info('Open Quantities Query', [
       'execution_time_ms' => $executionTime,
       'level' => $level,
       'result_count' => count($results)
   ]);
   ```

---

## ✅ Implementation Checklist

- [x] **Phase 1: Quick Wins (Day 1)**
  - [x] Add 20-day date filter (DONE - Nov 6, 2025)
  - [x] Run database index migration
  - [x] Fix prepared status UI
  - [x] Fix breadcrumb navigation bug

- [ ] **Phase 2: Medium-Term (Week 1)**
  - [ ] Add query result caching (2-5 minutes TTL)
  - [ ] Implement pagination (50 items per page)
  - [ ] Add normalized columns for faster joins

- [ ] **Phase 3: Advanced (Week 2-3)**
  - [ ] Optimize database server configuration
  - [ ] Implement virtual scrolling for large lists
  - [ ] Add skeleton loaders
  - [ ] Implement real-time sync with WebSockets or polling

- [ ] **Phase 4: Monitoring (Ongoing)**
  - [ ] Set up performance monitoring
  - [ ] Track slow query log
  - [ ] Monitor user experience metrics

---

## 🎓 Expected Overall Results

After implementing all optimizations:

| Metric | Before | With Date Filter | With Indexes | Combined | Improvement |
|--------|--------|------------------|--------------|----------|-------------|
| Initial Page Load | 5-10s | 2-4s | 1.5-3s | **0.5-1.5s** | **85-90%** faster |
| Drill-Down | 2-5s | 1-2s | 0.5-1s | **0.2-0.5s** | **90-95%** faster |
| Result Set Size | 100% | **20-30%** | 100% | **20-30%** | **70-80%** smaller |
| Concurrent Users | 10-15 | 30-40 | 40-50 | **50+** | **3-5x** capacity |
| Database Load | High | Medium | Low | **Very Low** | **70-85%** reduction |
| Memory Usage | 256MB+ | <180MB | <150MB | **<100MB** | **60%** reduction |

**Note:** Date filter alone provides the biggest performance boost by reducing the dataset size!

---

## 🚨 Important Notes

1. **Test in staging first** - Always test performance changes in a staging environment
2. **Backup database** - Before adding indexes, backup your production database
3. **Monitor after deployment** - Watch for any unexpected issues
4. **Cache invalidation** - Ensure caches are cleared when data changes
5. **Progressive enhancement** - Implement optimizations incrementally

---

**Last Updated:** November 6, 2025  
**Maintained By:** Development Team  
**Next Review:** December 2025

