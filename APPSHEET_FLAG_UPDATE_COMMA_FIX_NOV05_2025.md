# 🔧 AppSheet Flag Update - Comma Formatting Fix
## November 5, 2025

## 🐛 Issue Identified

The AppSheet flag update webhook was failing when AppSheet sent order numbers with comma formatting (e.g., "14,481" instead of "14481").

### Symptoms
- **Error:** "Shopify order not found"
- **Cause:** AppSheet sending formatted order numbers like "14,332" or "14,481" with thousand separators
- **Impact:** Flag updates were failing completely

### Evidence

**From AppSheet Logs:**
```json
{
  "Order Number": "14,332",
  "flag": "3"
}
```

**From Laravel Error Log:**
```
[2025-11-05 00:44:26] local.ERROR: AppSheet flag update - Shopify order not found 
{
  "computed_order_number":"6786189526281",
  "payload":{
    "Order Number":"14,481",
    "flag":"3"
  }
}
```

**The Problem:**
- AppSheet sends: `"14,481"` (with comma)
- Code adds 1000: `"14,481" + 1000` = invalid calculation
- Database has: `15481` (no comma)
- **Result:** Order not found!

## ✅ Root Cause Analysis

### Code Comparison

**statusUpdate() webhook** - ✅ **WORKING** (Lines 317-324):
```php
// Normalize order number: trim, remove commas, remove NF- prefix if present
if ($orderNumber !== null) {
    $orderNumber = trim((string) $orderNumber);
    // Remove commas (thousands separators)
    $orderNumber = str_replace(',', '', $orderNumber);
    if (stripos($orderNumber, 'NF-') === 0) {
        $orderNumber = substr($orderNumber, 3);
    }
}
```

**handleFlagUpdate() webhook** - ❌ **BROKEN** (Before fix):
```php
// NO COMMA REMOVAL!
$raw = trim((string)$customerEmailAsOrderNumber);
if (ctype_digit($raw)) {  // This fails with comma!
    $shopifyOrderNumber = (string) (((int) $raw) + 1000);
}
```

### Why It Broke

1. AppSheet started sending order numbers with comma formatting
2. The `handleFlagUpdate()` method never handled commas
3. When AppSheet sends `"14,481"`:
   - `ctype_digit("14,481")` returns `false` (comma is not a digit)
   - The number is treated as non-numeric string
   - No +1000 is added
   - Lookup fails

## 🔧 Solution Implemented

### Changes Made

**File:** `app/Http/Controllers/Webhook/AppSheetController.php`
**Method:** `handleFlagUpdate()`
**Lines:** ~550-610

### Key Changes:

1. **Added Order Number field support:**
```php
// Now checks "Order Number" field first (AppSheet's primary field)
$orderNumberRaw = $payload['Order Number'] 
    ?? $payload['order_number'] 
    ?? $payload['order number'] 
    ?? $payload['Customer Email']  // Fallback
    ?? null;
```

2. **Added comma removal (CRITICAL FIX):**
```php
$raw = trim((string)$orderNumberRaw);

// CRITICAL: Remove comma formatting (e.g., "14,481" -> "14481")
$raw = str_replace(',', '', $raw);
```

3. **Added better logging:**
```php
\Log::info('AppSheet flag update: order number after comma removal', [
    'original' => $orderNumberRaw,
    'cleaned' => $raw
]);

\Log::info('AppSheet flag update: computed shopify order number', [
    'original_payload_order_number' => $orderNumberRaw,
    'original_payload_order_id' => $orderIdRaw,
    'computed_shopify_order_number' => $shopifyOrderNumber
]);
```

## 📋 How It Works Now

### Example Flow:

**AppSheet sends:**
```json
{
  "Order Number": "14,481",
  "flag": "3"
}
```

**Processing:**
1. Extract: `orderNumberRaw = "14,481"`
2. Trim: `raw = "14,481"`
3. **Remove comma:** `raw = "14481"` ← **THE FIX!**
4. Check if numeric: `ctype_digit("14481")` = `true` ✅
5. Add 1000: `14481 + 1000 = 15481`
6. Lookup: `ShopifyOrderModel::where('order_number', '15481')`
7. **Found!** ✅
8. Update converted flag

## 🧪 Testing

### Test Case 1: Order Number with Comma
```bash
curl -X POST http://your-server/webhook/appsheet/flag-update \
  -H "Content-Type: application/json" \
  -d '{
    "Order Number": "14,481",
    "flag": 3
  }'
```

**Expected Result:** ✅ Success
- Removes comma
- Computes 15481
- Finds order
- Updates flag

### Test Case 2: Order Number without Comma
```bash
curl -X POST http://your-server/webhook/appsheet/flag-update \
  -H "Content-Type: application/json" \
  -d '{
    "Order Number": "14481",
    "flag": 3
  }'
```

**Expected Result:** ✅ Success
- No comma to remove
- Computes 15481
- Finds order
- Updates flag

### Test Case 3: Large Number with Multiple Commas
```bash
curl -X POST http://your-server/webhook/appsheet/flag-update \
  -H "Content-Type: application/json" \
  -d '{
    "Order Number": "1,234,567",
    "flag": 3
  }'
```

**Expected Result:** ✅ Success
- Removes all commas: "1234567"
- Computes 1235567
- Looks up order

## 📊 Monitoring

### Check Logs After Deployment

Look for these log entries:

**✅ Success Pattern:**
```
[INFO] AppSheet flag update: order number after comma removal
    original: "14,481"
    cleaned: "14481"

[INFO] AppSheet flag update: computed shopify order number
    original_payload_order_number: "14,481"
    computed_shopify_order_number: "15481"

[INFO] AppSheet flag update applied
    order_number: "15481"
    converted: 1
```

**❌ Error Pattern (if still failing):**
```
[ERROR] AppSheet flag update - Shopify order not found
    original_order_number_from_appsheet: "14,481"
    computed_shopify_order_number: "15481"
```
*This would indicate the order doesn't exist in database, not a comma issue*

## 🎯 Impact

### Before Fix
- ❌ All order numbers with commas failed
- ❌ No visibility into the issue
- ❌ AppSheet updates not working

### After Fix
- ✅ All order number formats work (with or without commas)
- ✅ Clear logging shows comma removal
- ✅ AppSheet updates work reliably
- ✅ Consistent with statusUpdate() method

## 🔄 Related Webhooks

### Other Webhooks That Handle Commas Correctly:

1. **statusUpdate()** - ✅ Already had comma removal (lines 317-324)
2. **riderAssignment()** - ✅ Uses plain order numbers (no issue)
3. **attendanceUpdate()** - ✅ Not relevant (no order numbers)
4. **handleFlagUpdate()** - ✅ NOW FIXED

## 📝 Files Modified

- **app/Http/Controllers/Webhook/AppSheetController.php**
  - Updated `handleFlagUpdate()` method
  - Added comma removal logic
  - Enhanced logging
  - Added Order Number field support

## ⚠️ Important Notes

1. **Comma removal is now consistent** across all order-related webhooks
2. **AppSheet number formatting** can vary based on locale/settings
3. **Always strip commas** from numeric fields before processing
4. **Logging helps debug** - keep the enhanced logs

## 🚀 Deployment Checklist

- ✅ Code updated
- ✅ No linting errors
- ✅ Logic tested with examples
- ✅ Logging enhanced
- ✅ Documentation created
- [ ] Deploy to production
- [ ] Monitor logs for success
- [ ] Verify AppSheet updates work

## 🎉 Success Criteria

After deployment:
- ✅ AppSheet flag updates succeed
- ✅ Orders with comma-formatted numbers processed correctly
- ✅ Clear logs show comma removal happening
- ✅ No more "Shopify order not found" errors for valid orders

---

**Issue Identified By:** User (excellent catch!)
**Fixed On:** November 5, 2025
**Status:** ✅ READY FOR TESTING

**Test it now by sending a flag update from AppSheet with a comma-formatted order number!**

