# 📱 Mobile App Deployment Checklist - UPDATED

## ✅ What's New (Auto-Update System)

### **New Features:**
1. ✅ Auto-update check on app launch
2. ✅ Update notification dialog
3. ✅ Direct download link in app
4. ✅ Version management system
5. ✅ Improved BAT file with version reminders

---

## Part 1: Files to Upload to Production

### **Backend Files (Laravel):**

```
✅ app/Http/Controllers/API/RiderController.php
✅ app/Http/Controllers/API/AppController.php (NEW - for auto-update)
✅ routes/api.php (updated with /app/version endpoint)
✅ resources/views/pages/auth/login.blade.php
```

### **Mobile App Files (Build Locally):**

```
✅ NizamiFarmsMobile/src/services/versionService.js (NEW)
✅ NizamiFarmsMobile/src/navigation/index.js (updated)
✅ All other mobile changes from this session
```

### **APK:**

```
✅ public/downloads/NizamiFarms-Rider.apk
```

### **Database:**

```
✅ Run: add_gps_tracking_to_order_status_history.sql
```

---

## Part 2: Version Management

### **Before Building APK:**

#### 1. Update package.json
```json
{
  "version": "1.1.0"  // Current version
}
```

#### 2. Update android/app/build.gradle
```gradle
versionCode 2        // Current code
versionName "1.1.0"  // Current name
```

#### 3. Update AppController.php
```php
'code' => 2,
'name' => '1.1.0',
'release_notes' => 'Initial production release...',
```

#### 4. Update login page (optional)
```html
<span>Download Android App (v1.1.0)</span>
```

---

## Part 3: Build APK

### **Option 1: Original BAT (Simple)**
```powershell
cd "C:\NF App\NizamiFarmsMobile"
.\build-production-apk.bat
```

### **Option 2: New BAT (With Version Reminders)**
```powershell
cd "C:\NF App\NizamiFarmsMobile"
.\build-production-apk-v2.bat
```

**New BAT features:**
- Prompts you to confirm version updates
- Shows version checklist
- Names APK with version number
- Better error handling

---

## Part 4: Upload to StackCP

### **1. Backend Files**

Upload to `/public_html/app/`:

```
app/Http/Controllers/API/RiderController.php
app/Http/Controllers/API/AppController.php (NEW)
routes/api.php
resources/views/pages/auth/login.blade.php
```

### **2. APK File**

Upload to `/public_html/app/public/downloads/`:

```
NizamiFarms-Rider.apk
```

Set permissions: `644`

### **3. Database Migration**

Run SQL:
```sql
ALTER TABLE t_crm_order_status_history 
ADD COLUMN delivery_latitude DECIMAL(10, 8) NULL AFTER status_code,
ADD COLUMN delivery_longitude DECIMAL(11, 8) NULL AFTER delivery_latitude;
```

### **4. Clear Laravel Cache**

```bash
cd /public_html/app
php artisan config:clear
php artisan cache:clear
php artisan route:clear
```

---

## Part 5: Testing

### **Test 1: Download from Website**
1. Visit: https://app.nizamifarms.com/
2. ✅ See "Download Android App (v1.1.0)" button
3. Click download
4. ✅ APK downloads (~25-30 MB)

### **Test 2: Install APK**
1. Transfer to Android device
2. Install (enable "Unknown Sources" if needed)
3. ✅ Installs without permission prompts
4. Open app
5. ✅ Login screen appears

### **Test 3: Auto-Update Check**
1. Open app
2. Wait 2 seconds
3. ✅ Should check for updates (see console logs)
4. If no update available: No dialog (correct)
5. If update available: Shows "Update Available" dialog

### **Test 4: Core Features**
- ✅ Login works
- ✅ Orders list loads
- ✅ Mark delivered works (with GPS)
- ✅ Ledger displays correctly
- ✅ Settlements work
- ✅ Attendance check-in/out works
- ✅ Requests list loads

### **Test 5: Permissions**
1. Mark first order as delivered
2. ✅ Location permission dialog appears
3. Tap "Allow"
4. ✅ GPS captured
5. ✅ Success message shows "with GPS location"

---

## Part 6: Future Updates (v1.2.0)

### **When Releasing Next Version:**

#### 1. Update Version Numbers
```
package.json → "1.2.0"
build.gradle → versionCode: 3, versionName: "1.2.0"
AppController.php → code: 3, name: "1.2.0"
login.blade.php → "v1.2.0"
```

#### 2. Build APK
```powershell
.\build-production-apk-v2.bat
```

#### 3. Upload APK
Upload to production `/public_html/app/public/downloads/`

#### 4. Clear Cache
```bash
php artisan config:clear
php artisan cache:clear
```

#### 5. Test Auto-Update
1. Open app with v1.1.0 installed
2. Wait 2 seconds
3. ✅ "Update Available" dialog appears
4. Shows: "v1.2.0 is available"
5. Tap "Update Now"
6. ✅ Downloads new APK
7. User installs update
8. ✅ Done!

---

## 📋 Quick Reference

### **Files Changed (Upload to Production):**
```
Backend:
✅ app/Http/Controllers/API/RiderController.php
✅ app/Http/Controllers/API/AppController.php (NEW)
✅ routes/api.php
✅ resources/views/pages/auth/login.blade.php

APK:
✅ public/downloads/NizamiFarms-Rider.apk

Database:
✅ add_gps_tracking_to_order_status_history.sql
```

### **Commands:**
```powershell
# Build APK
cd "C:\NF App\NizamiFarmsMobile"
.\build-production-apk-v2.bat

# Clear Laravel cache (production)
php artisan config:clear
php artisan cache:clear
php artisan route:clear
```

### **URLs:**
```
Download: https://app.nizamifarms.com/
API Check: https://app.nizamifarms.com/api/app/version
```

---

## 🎉 New Features Summary

### **Auto-Update System:**
- ✅ Checks for updates on app launch
- ✅ Shows dialog if update available
- ✅ Direct download link
- ✅ Optional or forced updates
- ✅ Release notes display

### **Version Management:**
- ✅ Centralized version info
- ✅ Easy to update
- ✅ Automatic version checking
- ✅ Clear update checklist

### **Permissions:**
- ✅ Runtime permissions (not install-time)
- ✅ Location requested only when needed
- ✅ App works even if denied
- ✅ Clear permission dialogs

---

## 📚 Documentation Created

1. **VERSION_MANAGEMENT_GUIDE.md** - Complete version management guide
2. **UPDATE_VERSION_CHECKLIST.md** - Quick checklist for updates
3. **PERMISSIONS_GUIDE.md** - Complete permissions documentation
4. **PERMISSIONS_AND_REQUIREMENTS.md** - User-facing permissions info
5. **build-production-apk-v2.bat** - Improved build script

---

## ✅ Ready to Deploy!

Everything is set up for:
- ✅ v1.1.0 initial release
- ✅ Future v1.2.0+ updates with auto-notification
- ✅ Easy version management
- ✅ Professional update experience

**You can now build and deploy!** 🚀
