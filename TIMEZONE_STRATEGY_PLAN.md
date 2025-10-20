# Timezone Strategy Plan - Pakistan Time (GMT+5)

## Current Situation
- Application is used in Pakistan (GMT+5 / PKT)
- Timezone handling is inconsistent:
  - Some displays use Laravel timezone conversion
  - Others use JavaScript
  - Previous attempts to fix broke the application
- Approval dates showing without proper time display

## Goal
**Consistent timezone handling across the entire application: Pakistan Time (GMT+5)**

---

## Recommended Strategy

### Option 1: Store UTC, Display Local (Recommended)
**Best practice approach:**

1. **Database**: Store all timestamps in UTC
2. **Laravel**: Set timezone to 'Asia/Karachi' in `config/app.php`
3. **Display**: Laravel automatically converts UTC to local time
4. **JavaScript**: Use user's browser timezone or force PKT

**Pros**:
- Industry standard
- Handles DST automatically
- Easy to add multi-timezone support later
- No data conversion needed

**Cons**:
- Requires careful implementation
- All existing timestamps need verification

### Option 2: Store and Display Pakistan Time
**Simpler approach:**

1. **Database**: Store all timestamps in PKT
2. **Laravel**: Set timezone to 'Asia/Karachi'
3. **Display**: No conversion needed
4. **JavaScript**: Force all displays to use PKT

**Pros**:
- Simpler to implement
- No conversion confusion
- Direct display

**Cons**:
- Harder to add multi-timezone support
- Not best practice
- DST issues if Pakistan changes policy

---

## Recommended Implementation (Option 1)

### Phase 1: Configuration
```php
// config/app.php
'timezone' => 'Asia/Karachi',
```

### Phase 2: Model Timestamps
```php
// app/Models/BaseModel.php
protected $dates = [
    'created_at',
    'updated_at',
    'submitted_at',
    'completed_at',
    'approved_at',
    // ... all timestamp fields
];

// Automatically cast to Carbon with app timezone
```

### Phase 3: Display Format
```php
// Create a helper for consistent display
// app/Helpers/DateTimeHelper.php

class DateTimeHelper {
    public static function format($datetime, $format = 'M d, Y g:i A') {
        if (!$datetime) return '-';
        return Carbon::parse($datetime)->format($format);
    }
    
    public static function formatDate($datetime) {
        return self::format($datetime, 'M d, Y');
    }
    
    public static function formatDateTime($datetime) {
        return self::format($datetime, 'M d, Y g:i A');
    }
    
    public static function formatTime($datetime) {
        return self::format($datetime, 'g:i A');
    }
}
```

### Phase 4: Blade Templates
```blade
<!-- Instead of -->
{{ $request->created_at }}

<!-- Use -->
{{ \App\Helpers\DateTimeHelper::formatDateTime($request->created_at) }}

<!-- Or create a Blade directive -->
@datetime($request->created_at)
```

### Phase 5: JavaScript Consistency
```javascript
// Force all JavaScript to use Pakistan timezone
// resources/js/datetime-helper.js

const PKT_OFFSET = 5 * 60; // GMT+5 in minutes

function formatDateTimePKT(dateString) {
    const date = new Date(dateString);
    // Convert to PKT
    const pktDate = new Date(date.getTime() + (PKT_OFFSET * 60 * 1000));
    return pktDate.toLocaleString('en-PK', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        hour12: true
    });
}
```

### Phase 6: API Responses
```php
// Ensure all API responses include timezone info
return response()->json([
    'created_at' => $model->created_at->toIso8601String(), // Includes timezone
    'created_at_formatted' => $model->created_at->format('M d, Y g:i A'),
    'timezone' => 'Asia/Karachi'
]);
```

---

## Current Issues to Fix

### 1. Approval Dashboard Date Display
**Issue**: Shows `2025-10-20T00:00:00.000000Z` instead of readable format

**Fix**:
```blade
<!-- resources/views/approvals/unified.blade.php -->
<!-- Change from -->
{{ $item['date'] }}

<!-- To -->
{{ \Carbon\Carbon::parse($item['date'])->format('M d, Y g:i A') }}
```

### 2. Expense Management Dates
**Issue**: Some dates show without time

**Fix**: Use consistent format helper across all views

### 3. Invoice Settlement Timestamps
**Issue**: Settlement timestamps might not show PKT

**Fix**: Ensure all settlement-related timestamps use proper timezone

---

## Migration Steps

### Step 1: Verify Current Database Timezone
```sql
-- Check what timezone your data is in
SELECT 
    created_at,
    CONVERT_TZ(created_at, 'UTC', 'Asia/Karachi') as pkt_time
FROM t_req_master
LIMIT 5;
```

### Step 2: Update Laravel Config
```php
// config/app.php
'timezone' => 'Asia/Karachi',
```

### Step 3: Test in Staging
- Create test records
- Verify timestamps display correctly
- Check all datetime displays across app

### Step 4: Deploy to Production
- Apply config change
- Monitor for issues
- Have rollback plan ready

---

## Testing Checklist

After implementing timezone fixes:

- [ ] Approvals Dashboard shows correct time
- [ ] Expense Management shows correct time
- [ ] Invoice settlements show correct time
- [ ] Employee cash transactions show correct time
- [ ] Attendance records show correct time
- [ ] Salary slips show correct time
- [ ] Ledger transactions show correct time
- [ ] API responses have correct timezone
- [ ] JavaScript displays match Laravel displays
- [ ] Filters work correctly with date ranges
- [ ] Exports (if any) have correct timestamps

---

## Recommended Next Steps

1. **Fix approval dashboard date display first** (quick win)
2. **Set Laravel timezone to Asia/Karachi** (config change)
3. **Create datetime helper** (for consistent display)
4. **Gradually update views** (one module at a time)
5. **Test thoroughly** (before production deploy)

---

## Notes for Pakistan Time (PKT)

- **Standard Offset**: GMT+5 (no DST)
- **Laravel Timezone**: `'Asia/Karachi'`
- **PHP Timezone**: `Asia/Karachi`
- **Moment.js/Luxon**: `Asia/Karachi`
- **MySQL**: `Asia/Karachi` or `+05:00`

---

## Status

⚠️ **PLANNED - Awaiting Approval**

This is a comprehensive plan. We should:
1. First fix the approval dashboard issue (current priority)
2. Then implement timezone fixes module by module
3. Test each module before moving to the next

**Estimated Time**: 2-3 days for full implementation and testing
**Risk Level**: Medium (requires careful testing)
**Impact**: High (affects all timestamp displays)

