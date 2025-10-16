# 📋 Attendance Re-Import Guide

## ❓ Your Questions Answered

### 1. Which tables do I have to delete in SQL?

**Answer:** Only **ONE** table needs to be cleared: `t_ops_attendance`

```sql
-- Just this one table:
DELETE FROM t_ops_attendance;
```

### 2. Will calculations work after fresh import?

**Answer:** ✅ **YES!** All calculations will work perfectly because they are **calculated on-the-fly**, not stored.

| Calculation | How it Works | Stored? |
|------------|--------------|---------|
| **Lateness** | `login_time` compared to user's `shift_start` | ❌ No - calculated when viewing |
| **Overtime** | `logout_time` compared to user's `shift_end` | ❌ No - calculated when viewing |
| **Hours worked** | `logout_time` minus `login_time` | ❌ No - calculated when viewing |
| **Present/Absent** | Based on whether `login_time` exists | ❌ No - calculated when viewing |
| **Late days count** | Count of days where late > 0 | ❌ No - calculated in reports |
| **Overtime days** | Count of days where overtime > 0 | ❌ No - calculated in reports |

### 3. Are calculations saved somewhere that needs deletion?

**Answer:** ⚠️ **Partially YES** - in salary slips only!

**Two places to consider:**

#### A. Attendance Table (t_ops_attendance) ✅
- Stores **raw data only**: login_time, logout_time, location, etc.
- **No calculations stored** - everything is computed when you view reports
- ✅ **Safe to delete and re-import**

#### B. Salary Slips (t_hr_salary_slips) ⚠️
- Stores **calculated totals** when salary slip is generated:
  - `late_minutes` - total late minutes for that month
  - `late_deduction` - amount deducted for lateness
  - `overtime_hours` - total overtime hours
  - `overtime_amount` - amount paid for overtime
  - `absent_days` - count of absent days
  - etc.

- These are **snapshots** taken at the time of salary slip generation
- If you re-import attendance with corrected data, **salary slips won't update automatically**

**What to do about salary slips:**
- If no salary slips generated yet: ✅ **No problem!**
- If salary slips exist but not paid: ⚠️ **Consider deleting and regenerating**
- If salary slips are paid: ⚠️ **Don't delete** - keep as historical record

---

## 🗂️ Tables Reference

### Tables You NEED to Delete:

| Table | What it Contains | Delete? |
|-------|-----------------|---------|
| `t_ops_attendance` | Raw attendance records | ✅ **YES** |

### Tables You MIGHT Want to Delete:

| Table | What it Contains | Delete? |
|-------|-----------------|---------|
| `t_hr_salary_slips` | Generated salary slips with calculated totals | ⚠️ **MAYBE** (if unpaid and based on wrong data) |

### Tables You SHOULD NOT Touch:

| Table | What it Contains | Delete? |
|-------|-----------------|---------|
| `t_sys_user` | Employee/user accounts | ❌ **NO** |
| `t_ops_rider_profile` | User shifts (shift_start, shift_end) | ❌ **NO** |
| `t_req_master` | Leave requests | ❌ **NO** |
| `t_ops_attendance_visibility` | Which users show in attendance | ❌ **NO** |
| `t_crm_prod_order` | Orders | ❌ **NO** |
| `t_ops_order_rider_history` | Rider assignments | ❌ **NO** |

---

## 📝 Step-by-Step Process

### Option 1: With Backup (Recommended) ✅

```sql
-- 1. Create backup
CREATE TABLE t_ops_attendance_backup_20251015 LIKE t_ops_attendance;
INSERT INTO t_ops_attendance_backup_20251015 SELECT * FROM t_ops_attendance;

-- 2. Delete current data
DELETE FROM t_ops_attendance;

-- 3. Upload CSV via web interface
-- Go to: Operations → Bulk Attendance Upload

-- 4. If something goes wrong, restore:
-- DROP TABLE IF EXISTS t_ops_attendance;
-- RENAME TABLE t_ops_attendance_backup_20251015 TO t_ops_attendance;
```

### Option 2: Using the SQL Script (Easiest) ✅

```sql
-- Run the provided script:
clear_and_reimport_attendance.sql

-- It will:
-- 1. Show you preview of what will be deleted (STEP 1)
-- 2. Check for salary slips (STEP 2)
-- 3. Create backup (STEP 3 - uncomment to use)
-- 4. Delete data (STEP 4 - uncomment when ready)
-- 5. Verify deletion (STEP 5 - uncomment after deletion)
```

### Option 3: Quick & Dirty (Not Recommended) ⚠️

```sql
-- Just delete everything - no backup!
DELETE FROM t_ops_attendance;

-- Then upload CSV via web interface
```

---

## 🧪 After Re-Import - Verify Everything Works

### 1. Check Attendance Page

Go to: **Attendance** → Select today's date

✅ Verify:
- Users are listed
- Login/logout times show correctly
- Hours worked is calculated
- Lateness shows for late users
- Overtime shows for users who worked late

### 2. Check Monthly Report

Go to: **Attendance** → Monthly Report → Select a month

✅ Verify:
- Present days count is correct
- Late days count is correct
- Overtime days are counted
- Total hours are calculated
- Absent days = working_days - present_days - leave_days

### 3. Check Employee Details

Go to: **Attendance** → Click on an employee → View 30-day details

✅ Verify:
- Daily attendance records show
- Late minutes are calculated for each late day
- Overtime minutes are calculated
- Hours worked per day
- Leave days show correctly

### 4. Test Salary Calculation (If Applicable)

If you use the salary system:

Go to: **HR** → **Salary Slips** → Generate a test slip

✅ Verify:
- Late minutes are pulled from attendance
- Overtime hours are calculated correctly
- Absent days count matches
- Deductions/payments are computed properly

---

## ⚠️ Important Considerations

### 1. Salary Slips Issue

**If you have existing salary slips:**

```sql
-- Check if any exist:
SELECT 
    COUNT(*) as total_slips,
    MIN(salary_month) as earliest_month,
    MAX(salary_month) as latest_month,
    SUM(CASE WHEN slip_status = 'paid' THEN 1 ELSE 0 END) as paid_slips,
    SUM(CASE WHEN slip_status = 'draft' THEN 1 ELSE 0 END) as draft_slips
FROM t_hr_salary_slips;
```

**Decision Matrix:**

| Situation | Action |
|-----------|--------|
| No salary slips exist | ✅ No problem - proceed |
| Only draft slips | ⚠️ Delete them, regenerate after re-import |
| Paid slips exist | ⚠️ Keep them as historical record, regenerate future ones |
| Mix of paid and draft | ⚠️ Keep paid, delete draft, regenerate |

**To delete draft salary slips:**
```sql
DELETE FROM t_hr_salary_slips 
WHERE slip_status = 'draft';
```

### 2. Date Ranges

Make sure your legacy CSV covers the dates you need:
- ✅ Check earliest date in your CSV
- ✅ Check latest date in your CSV
- ✅ Make sure it covers all the months you need

### 3. Employee Matching

The import matches employees by **name** from CSV to `t_sys_user.fullname`:
- ✅ Names must match exactly (or use smart matching)
- ⚠️ Case-insensitive matching is used
- ⚠️ Common suffixes like "- Indrive" are auto-removed

If import shows "Employee not found" errors:
- Check spelling in CSV
- Check if user exists in database
- Check if name has extra spaces or characters

---

## 📊 What the Import Does

When you upload the CSV via **Operations → Bulk Attendance Upload**:

### Imports These Fields:
- ✅ attendance_date
- ✅ login_time
- ✅ logout_time
- ✅ login_location (lat, lng)
- ✅ logout_location (lat, lng)
- ✅ device_id
- ✅ meter_start, meter_end
- ✅ picture_start, picture_end
- ✅ notes

### Does NOT Import (Calculated Later):
- ❌ Lateness (calculated from login_time vs shift)
- ❌ Overtime (calculated from logout_time vs shift)
- ❌ Hours worked (calculated from times)
- ❌ Status (present/absent/late)

### Handles Duplicates:
- Uses `updateOrInsert` by `(user_id, attendance_date)`
- If record exists → **updates it**
- If record doesn't exist → **creates new**

---

## 🎯 Quick Reference

### Delete Only Attendance:
```sql
DELETE FROM t_ops_attendance;
```

### Delete Attendance + Draft Salary Slips:
```sql
DELETE FROM t_ops_attendance;
DELETE FROM t_hr_salary_slips WHERE slip_status = 'draft';
```

### Check What Will Be Deleted:
```sql
-- Preview attendance records
SELECT COUNT(*) FROM t_ops_attendance;

-- Preview salary slips
SELECT slip_status, COUNT(*) 
FROM t_hr_salary_slips 
GROUP BY slip_status;
```

---

## ✅ Final Answer to Your Questions

1. **Which tables to delete?** 
   - `t_ops_attendance` (required)
   - `t_hr_salary_slips` where `slip_status = 'draft'` (optional, if they exist)

2. **Will calculations work?** 
   - ✅ **YES!** All calculations are done on-the-fly when viewing reports

3. **Are calculations saved somewhere?** 
   - ❌ **NO** in attendance table (raw data only)
   - ✅ **YES** in salary slips (snapshots when generated)
   - So you might need to regenerate salary slips after re-import

---

## 📞 Summary

**Simplest approach:**

```sql
-- 1. Run this:
DELETE FROM t_ops_attendance;

-- 2. Upload your CSV via web interface

-- 3. Everything will work automatically!
```

**Calculations will work** because they're computed from the raw data you're importing. Nothing is "pre-calculated" and stored except in salary slips, which you can regenerate if needed.

✅ **Ready to proceed!**

