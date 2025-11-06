# Mark as Prepared - Troubleshooting Guide
**Date:** November 6, 2025  
**Issue:** Mobile app failing to mark orders as prepared  
**Status:** ✅ RESOLVED - Missing `/rider/` prefix in API route

---

## ✅ Resolution Summary

**Root Cause:** Open Order Quantities screen was calling `/orders/bulk-mark-prepared` but mobile API routes require `/rider/` prefix.

**The Fix:** Changed API endpoint from `/orders/bulk-mark-prepared` to `/rider/orders/bulk-mark-prepared`

**Also Fixed:** Changed all UI text from "Preparing" to "Prepared" for consistency (database still uses `preparing`)

---

## 🐛 Issue Report

**Problem:** When trying to mark an order as prepared from the mobile app's Open Order Quantities screen, the operation fails with error: "The route api/orders/bulk-mark-prepared could not be found" (404)

**Working:** Open Orders page could mark items as prepared successfully

**Affected:** Mobile app - Open Order Quantities page only

---

## 🔍 Root Cause Analysis

**Actual Cause (CONFIRMED):**

The Open Order Quantities screen was calling `/orders/bulk-mark-prepared` while Open Orders was calling `/rider/orders/{orderId}/line-items/bulk-update-status`.

All mobile API routes in `routes/api.php` are prefixed with `/rider/`, so the correct path should have been `/rider/orders/bulk-mark-prepared`.

**Why Open Orders worked:**
- Used correct path: `/rider/orders/{orderId}/line-items/bulk-update-status` ✅

**Why Open Order Quantities failed:**
- Used incorrect path: `/orders/bulk-mark-prepared` ❌
- Should have been: `/rider/orders/bulk-mark-prepared` ✅

---

### **Other Potential Causes (Investigated but not the issue):**

1. **Authentication Issue**
   - Mobile API uses `auth:sanctum` middleware
   - Token might be invalid or expired
   - User authentication might be failing silently

2. **Database Constraint**
   - `updated_by` field might be required but `auth()->id()` returning null
   - Foreign key constraints might be blocking the update

3. **Validation Error**
   - Request format might not match backend expectations
   - Field names or structure mismatch

4. **Permission Issue**
   - User might not have permission to update line items
   - Role-based access control blocking the operation

---

## ✅ Fixes Applied

### **1. Improved Mobile Error Handling**

**File:** `src/screens/StoreOpenQuantitiesScreen.js`

**Change:** Show actual error message instead of generic "Failed" message

**Before:**
```javascript
} catch (error) {
  console.error('Error marking order as prepared:', error);
  Alert.alert('Error', 'Failed to mark order as prepared');
}
```

**After:**
```javascript
} catch (error) {
  console.error('Error marking order as prepared:', error);
  const errorMessage = error.response?.data?.message || error.message || 'Failed to mark order as prepared';
  Alert.alert('Error', errorMessage);
}
```

**Benefit:** Users will now see the actual error message from the server

---

### **2. Added Backend Logging**

**File:** `app/Http/Controllers/CRM/OrderController.php`

**Change:** Added comprehensive logging at the start and end of the request

**Added Logs:**
```php
\Log::info('Bulk mark prepared - Request received', [
    'user_id' => auth()->id(),
    'user' => auth()->user() ? auth()->user()->email : 'not authenticated',
    'order_ids' => $request->input('order_ids'),
    'preparation_status' => $request->input('preparation_status'),
]);

// ... processing ...

\Log::info('Bulk mark prepared - Success', [
    'user_id' => auth()->id(),
    'total_updated' => $totalUpdated,
    'orders_updated' => $ordersUpdated,
]);
```

**Benefit:** Can now trace exactly what's happening in the Laravel logs

---

### **3. Made `updated_by` Optional**

**File:** `app/Http/Controllers/CRM/OrderController.php`

**Change:** Only set `updated_by` if user is authenticated

**Before:**
```php
foreach ($order->lineItems as $lineItem) {
    $lineItem->preparation_status = $preparationStatus;
    $lineItem->updated_by = auth()->id(); // Might be null!
    $lineItem->save();
    $updatedInOrder++;
}
```

**After:**
```php
foreach ($order->lineItems as $lineItem) {
    $lineItem->preparation_status = $preparationStatus;
    // Only set updated_by if user is authenticated
    if (auth()->id()) {
        $lineItem->updated_by = auth()->id();
    }
    $lineItem->save();
    $updatedInOrder++;
}
```

**Benefit:** Prevents database errors if `auth()->id()` returns null

---

### **4. Fixed Mobile API Route (THE ACTUAL FIX)**

**File:** `src/screens/StoreOpenQuantitiesScreen.js`

**Change:** Added `/rider/` prefix to API endpoint

**Before:**
```javascript
const response = await api.post('/orders/bulk-mark-prepared', {
  order_ids: [orderId],
  preparation_status: 'preparing',
});
```

**After:**
```javascript
const response = await api.post('/rider/orders/bulk-mark-prepared', {
  order_ids: [orderId],
  preparation_status: 'preparing',
});
```

**Benefit:** Now matches the correct mobile API route path

---

### **5. Updated UI Text: "Preparing" → "Prepared"**

**Files:**
- `src/screens/StoreOpenOrdersScreen.js`
- `src/screens/OrderDetailsScreen.js`

**Changes:**
- Status badges now show "Prepared" instead of "Preparing"
- Action buttons now say "Mark as Prepared" instead of "Mark as Preparing"
- Summary text now shows "X/Y items prepared" instead of "X/Y items"

**Database:** Still uses `preparing` status (no backend changes needed)

---

## 🧪 Testing Steps

### **Test 1: Check Error Message (Mobile)**

1. Open mobile app
2. Navigate to Open Order Quantities
3. Drill down to orders level
4. Try to mark an order as prepared
5. **Expected:** If it fails, you'll now see a specific error message (not just "Failed to mark order as prepared")

---

### **Test 2: Check Laravel Logs**

After attempting to mark as prepared, check logs:

```bash
# On server
tail -f storage/logs/laravel.log

# Look for:
[INFO] Bulk mark prepared - Request received
[INFO] Bulk mark prepared - Success
# OR
[ERROR] Failed to bulk mark orders as prepared
```

**Key Info to Check:**
- Is `user_id` null or a valid number?
- Is `user` showing "not authenticated"?
- Are `order_ids` being received correctly?
- What's the specific error message (if any)?

---

### **Test 3: Test Web App**

1. Open web browser
2. Navigate to Open Order Quantities
3. Drill down to orders level
4. Select an order and click "Mark as Prepared"
5. **Expected:** Should work without issues (uses web authentication)

**Compare:** Does web app work but mobile doesn't? → Authentication issue

---

### **Test 4: Check API Authentication**

Test if the mobile app is properly authenticated:

```bash
# In mobile app console
# Look for successful API calls like:
HTTP GET http://172.20.10.3:8000/api/rider/store/open-quantities

# If getting 200 responses → authentication is working
# If getting 401 responses → authentication is broken
```

---

## 🔧 Debugging Checklist

Run through this checklist to identify the issue:

- [ ] **Mobile Error Message:** What exact error is shown now?
- [ ] **Laravel Log:** Check `storage/logs/laravel.log` for the bulk mark prepared logs
- [ ] **User Authentication:** Is `auth()->id()` null or valid in logs?
- [ ] **Order IDs:** Are the correct order IDs being sent from mobile?
- [ ] **Web App Test:** Does marking as prepared work from web app?
- [ ] **Other API Calls:** Are other mobile API calls working (like fetching orders)?

---

## 🎯 Common Issues & Solutions

### **Issue 1: "Unauthenticated" or "401 Unauthorized"**

**Cause:** Sanctum token expired or invalid

**Solution:**
```javascript
// Mobile app - Check if token is being sent
// In src/services/api.js
console.log('Authorization header:', api.defaults.headers.common['Authorization']);

// If missing or invalid, user needs to log in again
```

---

### **Issue 2: "Validation failed"**

**Cause:** Request format doesn't match backend expectations

**Solution:** Check request payload matches:
```javascript
{
  order_ids: [123], // Array of integers
  preparation_status: 'preparing' // String or null
}
```

---

### **Issue 3: "Order not found"**

**Cause:** Order ID is invalid or order doesn't exist

**Solution:** Verify order ID is correct:
```javascript
console.log('Order ID being sent:', orderId);
// Make sure it's a number, not null/undefined
```

---

### **Issue 4: "Cannot update closed order"**

**Cause:** Order status is delivered/completed/cancelled/refunded

**Solution:** This is expected behavior - these orders can't be marked as prepared

---

### **Issue 5: Works from Web but Not Mobile**

**Cause:** Mobile using different authentication (Sanctum) vs Web (session)

**Solution:**
1. Ensure mobile API route is in `routes/api.php` under `auth:sanctum` middleware
2. Verify mobile app is sending valid Bearer token
3. Check if token has expired (typically 24 hours)

---

## 📊 Expected Behavior

### **Success Case:**

**Mobile App:**
```
User taps "Mark Prepared"
→ Shows confirmation dialog
→ User taps "Confirm"
→ Shows success message: "Updated X item(s) to Prepared status"
→ List refreshes automatically
→ Order now shows "✓ All Items Prepared" badge
```

**Laravel Log:**
```
[INFO] Bulk mark prepared - Request received
      user_id: 123
      user: taimur@nizamifarms.com
      order_ids: [14575]
      preparation_status: "preparing"

[INFO] Bulk mark prepared - Success
      user_id: 123
      total_updated: 2
      orders_updated: 1
```

---

### **Failure Case (Authentication):**

**Mobile App:**
```
User taps "Mark Prepared"
→ Shows: "Error: Unauthenticated"
```

**Laravel Log:**
```
[INFO] Bulk mark prepared - Request received
      user_id: null
      user: "not authenticated"
      ...
```

**Solution:** User needs to log out and log back in

---

### **Failure Case (Validation):**

**Mobile App:**
```
User taps "Mark Prepared"
→ Shows: "Error: Validation failed"
```

**Laravel Log:**
```
[ERROR] Validation failed
        order_ids: required
```

**Solution:** Fix request payload format in mobile app

---

## 🚀 Web App Verification

To verify if the web app has the same issue:

### **Step 1: Open Web App**
```
Navigate to: http://your-domain/orders/open-quantities
```

### **Step 2: Drill Down to Orders**
1. Select a category
2. Drill down through levels
3. Reach the final "Orders" level

### **Step 3: Mark as Prepared**
1. Check the checkbox next to an order (that's not already fully prepared)
2. Click "✓ Mark as Prepared" button
3. Confirm the action

### **Expected Web App Behavior:**
- ✅ Success message appears: "Updated X item(s) in Y order(s) to Prepared status"
- ✅ Table refreshes automatically
- ✅ Checkboxes are cleared
- ✅ Order shows "✓ All Prepared" badge in Action column

### **If Web App Fails:**
- Check browser console for JavaScript errors
- Check Laravel logs for backend errors
- Issue is in the backend logic (not authentication)

### **If Web App Works but Mobile Doesn't:**
- Issue is specific to mobile authentication
- Check mobile app token validity
- Verify API route is correctly configured with `auth:sanctum`

---

## 📋 Next Steps

1. **Test with improved error messages** - See exact error from server
2. **Check Laravel logs** - Look for authentication/validation issues
3. **Test web app** - Determine if it's mobile-specific or global
4. **Report findings** - Share the exact error message shown on mobile

---

## 🔗 Related Files

**Mobile:**
- `src/screens/StoreOpenQuantitiesScreen.js` (lines 276-338)

**Backend:**
- `app/Http/Controllers/CRM/OrderController.php` (lines 2381-2471)

**Routes:**
- `routes/api.php` (line 145) - Mobile API route
- `routes/web.php` (line 91) - Web route

---

## ✅ Summary

**Improvements Made:**
1. ✅ Better error messages in mobile app
2. ✅ Comprehensive backend logging
3. ✅ Made `updated_by` field optional
4. ✅ Created this troubleshooting guide

**Status:** Ready for testing with improved diagnostics

**Next:** Test again and check:
- Exact error message shown on mobile
- Laravel logs for authentication status
- Compare with web app behavior

---

**Created:** November 6, 2025  
**Last Updated:** November 6, 2025  
**Maintained By:** Development Team

