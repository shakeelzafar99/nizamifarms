# Legacy Attendance Import Guide

## 📋 Overview
This guide explains how to import your legacy attendance data from Google Sheets into the NF Delivery App database.

---

## ✅ What I Updated

### **1. Enhanced Name Matching**
Your legacy data has names like:
- "Asim Tahir - indrive"
- "Kamran - Indrive"
- "Shabib" (just first name)

**Solution:** The import now:
1. Cleans names by removing "- indrive", "- indriver" suffixes
2. Tries 4 matching strategies:
   - Exact match
   - Case-insensitive match
   - Starts with match
   - Contains match

### **2. Audit Trail Support**
- ✅ Sets `created_by` = logged-in user (or admin)
- ✅ Sets `updated_by` = logged-in user
- ✅ Sets `created_at` = current timestamp (for new records)
- ✅ Sets `updated_at` = current timestamp
- ✅ Preserves existing `created_at` on updates

### **3. Duplicate Prevention**
- Uses `updateOrInsert()` instead of `insert()`
- Key: `user_id` + `attendance_date`
- If record exists → Updates it
- If new → Inserts it

### **4. Better Error Reporting**
- Shows cleaned names in error messages
- Scrollable error/missing employee lists
- Counts: New vs Updated records

---

## 📝 Step-by-Step Import Process

### **Step 1: Prepare Your CSV**

**From Google Sheets:**
1. Open your attendance sheet
2. Select all data (including headers)
3. File → Download → Comma Separated Values (.csv)
4. Save as `attendance_legacy.csv`

**Expected Columns (must match exactly - case insensitive):**
```
Date, employee, login time, login location, log out time, logout location, device id, meter start, meter end, picture start, picture end
```

**Column Mapping:**
- `Date` → attendance_date
- `employee` → Matched to user_id
- `login time` → login_time
- `login location` → login_lat, login_lng (split by comma)
- `log out time` → logout_time  
- `logout location` → logout_lat, logout_lng
- `device id` → device_id
- `meter start` → meter_start
- `meter end` → meter_end
- `picture start` → picture_start
- `picture end` → picture_end

---

### **Step 2: Ensure Users Exist**

**Before importing, make sure all employees are in the system!**

You can bulk import users first:
1. Go to **Users** page
2. Click **Bulk Import**
3. Select "rider" role
4. Paste names (one per line):
   ```
   Farooq
   Asim Tahir
   Shabib
   Haider
   Arsalan
   Waseem
   Taimur
   Mashood
   Kamran
   Arslan Aslam
   ```
5. Click "Import Users"

**Tip:** The bulk user import will handle "Asim Tahir - Indrive" → "Asim Tahir"

---

### **Step 3: Import Attendance**

1. Go to **Operations** (in sidebar under Administration)
2. Find "Import Attendance" card
3. Click "Choose File"
4. Select your `attendance_legacy.csv`
5. Click **Upload CSV**
6. Wait for results

---

### **Step 4: Review Results**

You'll see:
```
Import Complete!
New records: 145
Updated records: 12
Total processed: 157

Employees not found in users (3):
Asim Tahir - indrive (cleaned: Asim Tahir)
Kamran - Indrive (cleaned: Kamran)
Test User (cleaned: Test User)
```

**Action Items:**
- ✅ Green = Success
- ⚠️ Orange = Employees not found
- ❌ Red = Errors

---

## 🔍 Troubleshooting

### **Problem: "Employees not found"**

**Check 1: Name in system**
```sql
SELECT id, fullname FROM t_sys_user WHERE fullname LIKE '%Asim%';
```

**Check 2: Exact name**
- In sheet: "Asim Tahir - indrive"
- Import cleans to: "Asim Tahir"
- In database: "Asim Tahir - Indrive" (different case)

**Solution:** Update database name to match:
```sql
UPDATE t_sys_user SET fullname = 'Asim Tahir' WHERE fullname LIKE '%Asim Tahir%';
```

**Or:** Add user using bulk import

---

### **Problem: Date format errors**

**Expected:** `M/D/YYYY` (e.g., "8/1/2025")

**PHP handles:**
- `8/1/2025`
- `08/01/2025`
- `2025-08-01`

**If errors persist:**
- Check for invalid dates like "0/0/2025"
- Check for text in date column

---

### **Problem: Duplicate records**

**Don't worry!** The import uses `updateOrInsert()`:
- Same user + same date = Updates existing
- New user + date = Inserts new

You can safely run the import multiple times.

---

## 📊 What Gets Imported

### **Fields Automatically Set:**
- `notes` = "Legacy CSV import"
- `created_by` = Your user ID (or 1 if not logged in)
- `updated_by` = Your user ID
- `created_at` = Current timestamp (for new)
- `updated_at` = Current timestamp

### **Fields From CSV:**
- `user_id` = Matched from employee name
- `attendance_date` = Parsed from date
- `login_time` = From "login time" column
- `login_lat`, `login_lng` = Parsed from "login location" (e.g., "33.7, 73.0")
- `logout_time` = From "log out time" column
- `logout_lat`, `logout_lng` = Parsed from "logout location"
- `device_id` = From "device id"
- `meter_start`, `meter_end` = From respective columns (as integers)
- `picture_start`, `picture_end` = From respective columns (as strings)

---

## 💡 Tips for Success

### **1. Clean Your Data First**
- Remove empty rows
- Ensure date column has no blanks
- Ensure employee column has no blanks

### **2. Test with Small Batch**
- Export just first 10 rows
- Import and check results
- If all good, import full dataset

### **3. Handle Missing Users**
- Note down all missing employees from error message
- Bulk import them first
- Re-run attendance import

### **4. Verify After Import**
Go to **Attendance** page and check:
- Dates are correct
- Times are correct
- Employee names match

Or query database:
```sql
SELECT 
    u.fullname,
    a.attendance_date,
    a.login_time,
    a.logout_time
FROM t_ops_attendance a
JOIN t_sys_user u ON u.id = a.user_id
WHERE a.notes = 'Legacy CSV import'
ORDER BY a.attendance_date DESC
LIMIT 20;
```

---

## 🚨 Important Notes

### **1. Time Zones**
- CSV times are imported as-is
- No timezone conversion

### **2. Existing Records**
- If same user + date exists, it will be UPDATED
- Old data will be overwritten
- Consider backing up database first

### **3. Lat/Long Format**
Must be comma-separated in single cell:
```
33.7, 73.0        ✅ Good
33.7              ❌ Bad (missing longitude)
33.7 / 73.0       ❌ Bad (wrong separator)
```

### **4. Picture Paths**
- Stored as-is from CSV
- Example: `attendance_images/08-01-2025Haider.picture.081520.jpg`
- Make sure these files exist in your storage

---

## 📋 Pre-Import Checklist

- [ ] All employees exist in `t_sys_user` table
- [ ] CSV has correct headers (date, employee, login time, etc.)
- [ ] Date column is not empty
- [ ] Employee column is not empty
- [ ] Lat/Long format is correct ("lat, lng")
- [ ] Backed up database (optional but recommended)
- [ ] Tested with small batch first

---

## ✅ Post-Import Checklist

- [ ] Check import results (new vs updated counts)
- [ ] Review missing employees list
- [ ] Verify data in Attendance page
- [ ] Spot-check a few records in database
- [ ] Import any missing users
- [ ] Re-run import if needed

---

## 🎯 Expected Results

**Your Google Sheet has ~20-25 rows visible in the screenshot.**

Expected import:
```
Import Complete!
New records: 23
Updated records: 0
Total processed: 23

Employees not found in users (0):
(None - all users matched!)
```

If you get missing employees, use the bulk user import feature first!

---

**Ready to import? Let's do this!** 🚀

