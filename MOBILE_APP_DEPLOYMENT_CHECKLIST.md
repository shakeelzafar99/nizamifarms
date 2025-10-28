# 📱 Mobile App Deployment Checklist

## Part 1: Build APK (Local - Your Laptop)

### ✅ Step 1: Build the APK
```powershell
cd "C:\NF App\NizamiFarmsMobile"
.\build-production-apk.bat
```

**What the BAT file does automatically:**
1. ✅ Sets production environment (`APP_ENV=production`)
2. ✅ Cleans previous builds
3. ✅ Builds signed release APK (3-5 minutes)
4. ✅ Renames to `NizamiFarms-Rider.apk`
5. ✅ Copies to `C:\NF App\nizamifarms\public\downloads\`

**Result:** APK ready at:
```
C:\NF App\nizamifarms\public\downloads\NizamiFarms-Rider.apk
```

**That's it! Just run the BAT file - it handles everything!** ✅

---

## Part 2: Upload Backend Files to Production (StackCP)

### 📁 Files Changed for Mobile App

#### ✅ **1. app/** folder
**Modified:**
- `app/Http/Controllers/API/RiderController.php` (NEW FILE - all mobile APIs)

**Upload to StackCP:**
```
/public_html/app/Http/Controllers/API/RiderController.php
```

---

#### ✅ **2. routes/** folder
**Modified:**
- `routes/api.php` (added rider API routes)

**Upload to StackCP:**
```
/public_html/routes/api.php
```

---

#### ✅ **3. resources/** folder
**Modified:**
- `resources/views/pages/auth/login.blade.php` (added APK download button)

**Upload to StackCP:**
```
/public_html/resources/views/pages/auth/login.blade.php
```

---

#### ✅ **4. public/** folder (NEW)
**Created:**
- `public/downloads/` folder
- `public/downloads/NizamiFarms-Rider.apk` (the APK file)

**Upload to StackCP:**
```
/public_html/public/downloads/NizamiFarms-Rider.apk
```

---

#### ✅ **5. Database Changes**
**SQL to run on production:**
- `add_gps_tracking_to_order_status_history.sql`

**What it does:**
Adds GPS columns to `t_crm_order_status_history` table:
- `delivery_latitude` (DECIMAL 10,8)
- `delivery_longitude` (DECIMAL 11,8)

**Run on production database:**
```sql
ALTER TABLE t_crm_order_status_history 
ADD COLUMN delivery_latitude DECIMAL(10, 8) NULL AFTER status_code,
ADD COLUMN delivery_longitude DECIMAL(11, 8) NULL AFTER delivery_latitude;
```

---

## 📋 Summary: What to Upload to StackCP

### Your 3 Main Folders (✅ Correct!)
1. **app/** → Upload `app/Http/Controllers/API/RiderController.php`
2. **routes/** → Upload `routes/api.php`
3. **resources/** → Upload `resources/views/pages/auth/login.blade.php`

### Additional:
4. **public/** → Upload `public/downloads/NizamiFarms-Rider.apk`
5. **Database** → Run SQL migration (GPS columns)

---

## 🚀 Complete Deployment Steps

### Step 1: Build APK (Local)
```powershell
cd "C:\NF App\NizamiFarmsMobile"
.\build-production-apk.bat
```
⏱️ Time: 3-5 minutes

---

### Step 2: Upload to StackCP
Using your FTP/File Manager:

1. **Upload RiderController.php:**
   ```
   Local:  C:\NF App\nizamifarms\app\Http\Controllers\API\RiderController.php
   Server: /public_html/app/Http/Controllers/API/RiderController.php
   ```

2. **Upload api.php:**
   ```
   Local:  C:\NF App\nizamifarms\routes\api.php
   Server: /public_html/routes/api.php
   ```

3. **Upload login.blade.php:**
   ```
   Local:  C:\NF App\nizamifarms\resources\views\pages\auth\login.blade.php
   Server: /public_html/resources/views/pages/auth/login.blade.php
   ```

4. **Create downloads folder and upload APK:**
   ```
   Server: Create folder /public_html/public/downloads/
   
   Local:  C:\NF App\nizamifarms\public\downloads\NizamiFarms-Rider.apk
   Server: /public_html/public/downloads/NizamiFarms-Rider.apk
   ```

5. **Set APK permissions:**
   ```bash
   chmod 644 /public_html/public/downloads/NizamiFarms-Rider.apk
   ```

---

### Step 3: Run Database Migration
In StackCP → phpMyAdmin or MySQL:

```sql
USE your_production_database;

ALTER TABLE t_crm_order_status_history 
ADD COLUMN delivery_latitude DECIMAL(10, 8) NULL AFTER status_code,
ADD COLUMN delivery_longitude DECIMAL(11, 8) NULL AFTER delivery_latitude;
```

---

### Step 4: Clear Laravel Cache (Production)
Via SSH or StackCP terminal:

```bash
cd /public_html
php artisan config:clear
php artisan cache:clear
php artisan route:clear
```

---

### Step 5: Test Everything

#### Test 1: Download Button
1. Visit: https://app.nizamifarms.com/
2. ✅ Should see "Download Android App (v1.1.0)" button
3. Click download
4. ✅ APK should download (~25-30 MB)

#### Test 2: Install APK
1. Transfer APK to Android device
2. Install (enable "Unknown Sources" if prompted)
3. ✅ App should install successfully

#### Test 3: Login
1. Open app
2. Enter production credentials
3. ✅ Should login successfully

#### Test 4: Core Features
- ✅ Orders list loads
- ✅ Order details display
- ✅ Mark delivered works (with GPS)
- ✅ Ledger shows correct balance
- ✅ Settlements work (full, partial, short cash)
- ✅ Attendance check-in/out works
- ✅ Requests list loads

---

## 📝 Quick Reference

### Files to Upload (5 files total):
```
✅ app/Http/Controllers/API/RiderController.php
✅ routes/api.php
✅ resources/views/pages/auth/login.blade.php
✅ public/downloads/NizamiFarms-Rider.apk
✅ Database: Run GPS migration SQL
```

### Commands to Run:
```powershell
# Local: Build APK
cd "C:\NF App\NizamiFarmsMobile"
.\build-production-apk.bat

# Production: Clear cache
php artisan config:clear
php artisan cache:clear
php artisan route:clear
```

---

## ✅ You're Right!

**Your assumption is correct:** Most changes are in your 3 main folders:
- ✅ **app/** (RiderController.php)
- ✅ **routes/** (api.php)
- ✅ **resources/** (login.blade.php)

**Plus:**
- ✅ **public/downloads/** (APK file - new folder)
- ✅ **Database** (GPS columns - one SQL script)

**That's it!** 🎉

---

## 🎯 TL;DR

### Build APK:
```powershell
.\build-production-apk.bat
```
**Done!** ✅

### Upload to StackCP:
1. `app/Http/Controllers/API/RiderController.php`
2. `routes/api.php`
3. `resources/views/pages/auth/login.blade.php`
4. `public/downloads/NizamiFarms-Rider.apk`
5. Run GPS SQL migration

**That's all you need!** 🚀

