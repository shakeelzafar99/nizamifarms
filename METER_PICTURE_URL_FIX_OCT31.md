# Meter Picture URL Fix - October 31, 2025

## Issue
Meter pictures were saving correctly but failing to display in production with 404 errors.

**Incorrect Production URL:**
```
https://app.nizamifarms.com/app/storage/app/public/attendance/meters/...
```

**Correct Production URL:**
```
https://app.nizamifarms.com/app/public/storage/attendance/meters/...
```

## Root Cause
The `getMeterPictureUrl()` helper function and webapp JavaScript were using the wrong path for production:
- **Wrong:** `/app/storage/app/public/`
- **Correct:** `/app/public/storage/`

## Files Fixed

### 1. Backend API (Mobile App)
**File:** `app/Http/Controllers/API/RiderController.php`

**Method:** `getMeterPictureUrl()`

**Change:**
```php
// OLD (WRONG)
return url('app/storage/app/public/' . $picturePath);

// NEW (CORRECT)
return url('app/public/storage/' . $picturePath);
```

### 2. Webapp - Attendance Index
**File:** `resources/views/pages/attendance/index.blade.php`

**Change:**
```javascript
// OLD (WRONG)
const storagePrefix = isProduction ? '/app/storage/app/public/' : '/storage/';

// NEW (CORRECT)
const storagePrefix = isProduction ? '/app/public/storage/' : '/storage/';
```

### 3. Webapp - Attendance Reports
**File:** `resources/views/pages/attendance/reports.blade.php`

**Change:**
```javascript
// OLD (WRONG)
const storagePrefix = isProduction ? '/app/storage/app/public/' : '/storage/';

// NEW (CORRECT)
const storagePrefix = isProduction ? '/app/public/storage/' : '/storage/';
```

## How It Works

### File Storage Location
Files are physically stored at:
```
/public_html/app/storage/app/public/attendance/meters/YYYY/MM/filename.jpg
```

### URL Access Path
Files are accessible via:
```
https://app.nizamifarms.com/app/public/storage/attendance/meters/YYYY/MM/filename.jpg
```

### Environment Detection
- **Local Dev:** Uses `/storage/` (Laravel symlink works)
- **Production:** Uses `/app/public/storage/` (direct path, bypasses symlink issues)

## Testing

### After Deployment
1. Upload a meter picture from mobile app
2. View attendance in mobile app - pictures should display
3. View attendance in webapp (index page) - pictures should display
4. View attendance reports in webapp - pictures should display

### Expected Behavior
- ✅ Pictures save successfully
- ✅ Pictures display in mobile app
- ✅ Pictures display in webapp attendance page
- ✅ Pictures display in webapp attendance reports
- ✅ No 404 errors
- ✅ Works in both local dev and production

## Deployment Steps
1. Upload the 3 fixed files to production via WinSCP
2. Clear Laravel cache (if accessible): `php artisan cache:clear`
3. Test by uploading a new meter picture
4. Verify existing pictures also display correctly

## Notes
- No database changes required
- No file permission changes required
- Existing pictures will work automatically with the new URL
- Local development continues to work with symlink approach

