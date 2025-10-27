# GPS Testing Checklist
**Date:** October 27, 2025

---

## ✅ Pre-Test Setup

### Step 1: Prepare Device
- [ ] Device location services enabled (Settings → Location → ON)
- [ ] Location mode set to "High Accuracy"
- [ ] App has location permission granted

### Step 2: Prime GPS Cache (Recommended)
- [ ] Open Google Maps
- [ ] Wait for blue dot to appear (location acquired)
- [ ] Close Google Maps
- [ ] **Test within 1 minute** (while location is cached)

### Step 3: Reload Mobile App
```bash
# In Metro terminal, press:
r
```

---

## 🧪 Testing Steps

### Test 1: Mark Order as Delivered
1. [ ] Open mobile app
2. [ ] Navigate to an order (e.g., order 2615)
3. [ ] Press "Mark as Delivered"
4. [ ] Enter packet count if prompted
5. [ ] Confirm delivery

### Test 2: Watch Console Logs
Look for these messages:
- [ ] `📍 Starting location capture...`
- [ ] `📍 Step 1: Getting last known location...`
- [ ] `✅ Last known location retrieved` OR `⚠️ Could not get last known location`
- [ ] `📍 Step 2: Attempting fresh GPS location...`
- [ ] `✅ Fresh GPS location captured` OR `❌ Fresh GPS failed`
- [ ] `✅ FINAL LOCATION CAPTURED` ← **MOST IMPORTANT**
- [ ] `📤 Sending delivery request to API`
- [ ] `📥 API Response: { success: true }`

### Test 3: Verify Database
```sql
SELECT 
    order_id,
    status_code,
    delivery_latitude,
    delivery_longitude,
    changed_at,
    notes
FROM t_crm_order_status_history
WHERE order_id = 2615  -- Your test order ID
AND status_code = 'delivered'
ORDER BY changed_at DESC
LIMIT 1;
```

**Expected Result**:
- [ ] `delivery_latitude` is NOT NULL
- [ ] `delivery_longitude` is NOT NULL
- [ ] Values look reasonable (e.g., 33.684400, 73.047900)
- [ ] `notes` contains "(GPS: lat, lng)"

### Test 4: Check Laravel Logs
```bash
tail -f storage/logs/laravel.log | grep "GPS"
```

Look for:
- [ ] `[INFO] Received GPS data from mobile app`
- [ ] `latitude_is_null: false`
- [ ] `longitude_is_null: false`
- [ ] `[INFO] GPS location stored`

---

## 📊 Test Results

### Scenario A: ✅ Complete Success
```
Console: ✅ FINAL LOCATION CAPTURED
Database: delivery_latitude = 33.684400, delivery_longitude = 73.047900
Laravel Log: GPS location stored
```
**Result**: PASS ✅

### Scenario B: ⚠️ Using Cached Location
```
Console: ✅ Last known location retrieved
Console: ❌ Fresh GPS failed
Console: ✅ FINAL LOCATION CAPTURED (using cached)
Database: delivery_latitude = 33.684400, delivery_longitude = 73.047900
```
**Result**: PASS ✅ (Cached location is acceptable)

### Scenario C: ❌ No Location Available
```
Console: ⚠️ Could not get last known location
Console: ❌ Fresh GPS failed
Console: ❌ All location attempts failed
Console: ⚠️ NO LOCATION AVAILABLE
Database: delivery_latitude = NULL, delivery_longitude = NULL
```
**Result**: FAIL ❌

**If Scenario C occurs**:
1. Open Google Maps first
2. Wait for location to appear
3. Close Maps
4. Retry test immediately

---

## 🔍 Troubleshooting

### Issue: "NO LOCATION AVAILABLE"

**Solution 1**: Use Google Maps first
```
1. Open Google Maps
2. Wait for blue dot
3. Close Maps
4. Test app within 60 seconds
```

**Solution 2**: Enable Mock Locations
```
1. Settings → Developer Options → Allow mock locations
2. Install "Fake GPS Location" app
3. Set location to: 33.6844, 73.0479
4. Test app
```

**Solution 3**: Check Permissions
```
Settings → Apps → NizamiFarms → Permissions → Location:
- Must be: "Allow all the time" or "Allow only while using the app"
```

### Issue: Console Shows Location but Database is NULL

**Check Laravel Logs**:
```bash
tail -f storage/logs/laravel.log | grep "Received GPS data"
```

**If you see `latitude_is_null: true`**:
- Mobile app is sending null values
- Check console for "willBeSentToAPI: false"

**If you don't see the log at all**:
- API request not reaching backend
- Check network connectivity
- Check API endpoint URL

---

## 📝 Test Report Template

```
Date: October 27, 2025
Tester: [Your Name]
Device: [Device Model]
Location: [Indoor/Outdoor]

Test Order ID: 2615

Console Output:
- Step 1 (Last Known): [✅ Success / ❌ Failed]
- Step 2 (Fresh GPS): [✅ Success / ❌ Failed]
- Final Location: [✅ Captured / ❌ Not Available]

Database Check:
- delivery_latitude: [Value or NULL]
- delivery_longitude: [Value or NULL]

Laravel Logs:
- Received GPS data: [✅ Yes / ❌ No]
- GPS location stored: [✅ Yes / ❌ No]

Overall Result: [✅ PASS / ❌ FAIL]

Notes:
[Any additional observations]
```

---

## ✅ Success Criteria

For the test to PASS, you need:
1. ✅ Console shows "FINAL LOCATION CAPTURED"
2. ✅ Database has non-NULL latitude/longitude
3. ✅ Laravel logs show "GPS location stored"
4. ✅ Coordinates look reasonable (within your country/region)

---

**Ready to test!** 📍✨

If you encounter any issues, refer to the troubleshooting section or check `GPS_RELIABILITY_IMPLEMENTATION_OCT27.md` for detailed implementation details.

