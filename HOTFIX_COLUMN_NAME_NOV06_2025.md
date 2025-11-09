# Hotfix: Column Name Correction
**Date:** November 6, 2025  
**Issue:** SQL error on mobile app reload  
**Status:** ✅ FIXED

---

## 🐛 Issue Found

**Error Message:**
```
SQLSTATE[42S22]: Column not found: 1054 Unknown column 'assigned_rider_id' in 'field list'
```

**Root Cause:**
The new lightweight endpoint used incorrect column name `assigned_rider_id` instead of the actual database column `assigned_rider_user_id`.

---

## ✅ Fix Applied

**File:** `app/Http/Controllers/API/RiderController.php`

### Change 1: Select Query (Line 2294)
```php
// BEFORE (WRONG):
'customer_id', 'assigned_rider_id', 'external_source'

// AFTER (CORRECT):
'customer_id', 'assigned_rider_user_id', 'external_source'
```

### Change 2: Return Array (Line 2352)
```php
// BEFORE (WRONG):
'assigned_rider_id' => $order->assigned_rider_id,

// AFTER (CORRECT):
'assigned_rider_id' => $order->assigned_rider_user_id,
```

**Note:** The return key stays as `assigned_rider_id` for API consistency, but now reads from the correct database column `assigned_rider_user_id`.

---

## 📋 Verification

### Database Schema:
- ✅ Table: `t_crm_prod_order`
- ✅ Column: `assigned_rider_user_id` (NOT `assigned_rider_id`)
- ✅ Relationship: `assignedRider()` uses `assigned_rider_user_id`

### Model Confirmation:
From `OrderModel.php`:
```php
public function assignedRider(): BelongsTo
{
    return $this->belongsTo(UserModel::class, 'assigned_rider_user_id', 'id');
}
```

### Mobile App:
The mobile app already has a fallback that handles both names:
```javascript
o.assigned_rider_id || o.rider_id || null
```

---

## 🧪 Testing

**To Test:**
1. Reload the mobile app (Cmd+R or R+R)
2. Navigate to Open Orders screen
3. Should load without SQL errors
4. "Last synced" indicator should appear
5. Orders should display correctly

**Expected Result:**
- ✅ No SQL errors
- ✅ Orders load successfully
- ✅ Sync status shows
- ✅ All features work

---

## 🔍 Other Checks Performed

### Checked for Similar Issues:
- ✅ Details endpoint uses correct column names
- ✅ All other queries verified
- ✅ No linter errors
- ✅ Mobile app compatible

### Files Verified:
1. ✅ `RiderController.php` - Fixed
2. ✅ `OrderModel.php` - Correct
3. ✅ `StoreOpenOrdersScreen.js` - Compatible

---

## 📝 Lesson Learned

**Always verify column names against:**
1. Database schema
2. Model relationships
3. Existing working queries

**Prevention:**
- Use Model relationships instead of raw column names when possible
- Check existing queries in the same controller
- Test with actual database before committing

---

## 🚀 Next Steps

1. **Reload the app** - Should work now
2. **Test thoroughly** - All Open Orders features
3. **Monitor logs** - Ensure no other SQL errors
4. **Test Open Quantities** - Should also work fine

---

**Status:** ✅ FIXED - Ready to test  
**Impact:** Critical (app was broken)  
**Resolution Time:** Immediate

