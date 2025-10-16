# 🚨 Production Attendance Showing Old Data - Troubleshooting Guide

## 🐛 Issue Description

**Problem:** Production shows old attendance calculations even after deleting tables
- Shows 24/27 days when it's only Oct 16
- Duplicate records visible (Oct 1 appears twice, Oct 2 twice, etc.)
- Dev is working fine after deletion
- Production still shows old data

## 🔍 Possible Causes

1. **Database duplicates not actually deleted**
2. **Laravel application cache**
3. **Browser caching old API responses**
4. **Database permissions issue (DELETE didn't work)**
5. **Multiple database connections**

---

## ✅ Step-by-Step Fix

### STEP 1: Check What's Actually in Production Database

**Run this SQL file:** `troubleshoot_production_attendance.sql`

It will show you:
- ✅ How many records are actually there
- ✅ If duplicates exist
- ✅ Which records are causing the issue

**Expected Results:**
- If table is empty: **0 records** (but you're still seeing data = cache issue)
- If table has data: **You'll see the duplicates listed**

---

### STEP 2: Delete Duplicates (If They Exist)

**Option A: Delete ONLY Duplicates (Safer)**

```sql
-- This keeps the first record and deletes duplicates
DELETE a1 FROM t_ops_attendance a1
INNER JOIN t_ops_attendance a2 
WHERE 
    a1.user_id = a2.user_id
    AND a1.attendance_date = a2.attendance_date
    AND a1.id > a2.id;

-- Verify
SELECT 
    user_id,
    attendance_date,
    COUNT(*) as count
FROM t_ops_attendance
GROUP BY user_id, attendance_date
HAVING COUNT(*) > 1;
-- Should return 0 rows
```

**Option B: Delete EVERYTHING (Nuclear)**

```sql
DELETE FROM t_ops_attendance;

-- Verify
SELECT COUNT(*) FROM t_ops_attendance;
-- Should return 0
```

---

### STEP 3: Clear Laravel Cache (CRITICAL!)

Laravel caches various things that could be showing old data:

**On Production Server, Run:**

```bash
cd /path/to/your/app

# Clear all caches
php artisan cache:clear
php artisan config:clear
php artisan route:clear
php artisan view:clear

# If you have Redis/Memcached:
php artisan cache:flush

# Restart PHP-FPM (if applicable)
sudo systemctl restart php8.1-fpm  # or your PHP version
```

**Why this matters:**
- `cache:clear` - Clears application cache (might have cached query results)
- `config:clear` - Clears config cache
- `route:clear` - Clears route cache
- `view:clear` - Clears compiled views

---

### STEP 4: Clear Browser Cache

The browser might be caching the API response for the attendance report.

**Method 1: Hard Refresh**
- Windows/Linux: `Ctrl + Shift + R` or `Ctrl + F5`
- Mac: `Cmd + Shift + R`

**Method 2: Clear Site Data**
1. Open DevTools (F12)
2. Right-click on refresh button
3. Select "Empty Cache and Hard Reload"

**Method 3: Incognito/Private Window**
- Open production in incognito mode
- If it works there, it's definitely browser cache

---

### STEP 5: Check Database Connection

Make sure you're actually connected to production database:

```sql
-- Check which database you're connected to
SELECT DATABASE();

-- Check if it's the right server
SHOW VARIABLES LIKE 'hostname';

-- Show current attendance count
SELECT COUNT(*) FROM t_ops_attendance;
```

**Common mistake:** Accidentally running queries on dev database thinking it's production!

---

## 🎯 Most Likely Solution

Based on your description, the issue is probably:

### **90% Chance: Laravel Cache + Browser Cache**

**Why:** You deleted the database tables successfully, but:
1. Laravel cached the attendance report API response
2. Browser cached the API response
3. Both are serving old data

**Fix:**
```bash
# On production server
php artisan cache:clear
php artisan config:clear

# Then in browser
Ctrl + Shift + R  (hard refresh)
```

### **10% Chance: Duplicates Still in Database**

**Why:** DELETE didn't work due to permissions or wrong database connection

**Fix:**
```sql
-- Check if data still exists
SELECT COUNT(*) FROM t_ops_attendance 
WHERE attendance_date >= '2025-10-01';

-- If yes, delete properly
DELETE FROM t_ops_attendance;
```

---

## 🧪 How to Verify It's Fixed

### 1. Check Database Directly

```sql
SELECT COUNT(*) FROM t_ops_attendance;
-- Should be 0 if you want to re-import

-- Or check for duplicates
SELECT 
    user_id,
    attendance_date,
    COUNT(*) 
FROM t_ops_attendance 
GROUP BY user_id, attendance_date 
HAVING COUNT(*) > 1;
-- Should return 0 rows
```

### 2. Check the API Response

In browser DevTools (F12):
1. Go to **Network** tab
2. Load the attendance reports page
3. Find the API call (probably `/attendance/monthly-report`)
4. Check the response data
5. Should show empty or correct data (not old cached data)

### 3. Check the UI

Go to: **Attendance Reports** → Select October 2025

**Should show:**
- Correct employee counts
- Correct present days (not 24 when it's Oct 16)
- No duplicate entries in details
- Correct totals

---

## 📋 Checklist

Run through this checklist:

- [ ] **Check database has data:** `SELECT COUNT(*) FROM t_ops_attendance;`
- [ ] **Check for duplicates:** Run STEP 2 from troubleshoot script
- [ ] **Delete attendance data:** `DELETE FROM t_ops_attendance;`
- [ ] **Clear Laravel cache:** `php artisan cache:clear`
- [ ] **Clear config cache:** `php artisan config:clear`
- [ ] **Hard refresh browser:** `Ctrl + Shift + R`
- [ ] **Check in incognito:** Open production in private window
- [ ] **Verify it's fixed:** Check attendance reports page
- [ ] **Re-import if needed:** Upload CSV via Bulk Upload

---

## 🔧 Quick Commands Summary

### On Production Server:
```bash
# Clear everything
php artisan cache:clear && php artisan config:clear && php artisan route:clear && php artisan view:clear

# Restart PHP (optional)
sudo systemctl restart php8.1-fpm
```

### In MySQL/Database:
```sql
-- Nuclear option: delete everything
DELETE FROM t_ops_attendance;
DELETE FROM t_hr_salary_slips WHERE slip_status = 'draft';

-- Verify
SELECT COUNT(*) FROM t_ops_attendance;
```

### In Browser:
```
Ctrl + Shift + R  (Windows/Linux)
Cmd + Shift + R   (Mac)
```

---

## ❓ Still Not Working?

If after all this you're still seeing old data:

### Check These:

1. **Are you sure you're on production?**
   - Check URL carefully
   - Check database name: `SELECT DATABASE();`

2. **Check Laravel `.env` file**
   ```bash
   cat .env | grep DB_
   ```
   - Make sure `DB_DATABASE` is correct
   - Make sure `DB_HOST` is correct

3. **Check if there's a CDN/Proxy**
   - CloudFlare or similar might be caching
   - Need to purge CDN cache

4. **Check for read replicas**
   - Some setups have separate read/write databases
   - Write went to master, read is from old replica

5. **Check session storage**
   - Session might have cached user-specific data
   - Try different browser or logout/login

---

## 📞 Summary

**Most likely issue:** Cache (Laravel + Browser)

**Quick fix:**
```bash
# Server
php artisan cache:clear

# Browser  
Ctrl + Shift + R
```

**If that doesn't work:** Delete database records again and clear cache again.

**To verify it worked:** Check in incognito window or different browser.

✅ **Once fixed, re-import your clean attendance data!**

