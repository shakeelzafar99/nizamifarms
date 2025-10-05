# Shift Management System - Setup Instructions

## 📋 Overview

We're implementing a **template-based shift management system** to replace the current per-user shift configuration.

### **Before:**
- ❌ Each user has `shift_start` and `shift_end` in `t_ops_rider_profile`
- ❌ Managed one-by-one in "Manage Shifts" modal
- ❌ Working days hardcoded in PHP (Tuesday off)
- ❌ No holiday management

### **After:**
- ✅ Shift templates: Define once, assign to many
- ✅ User assignments: Bulk assign shifts to users
- ✅ Holiday calendar: Centralized, applies to everyone
- ✅ Smart fallback: Old system continues working during migration

---

## 🎯 Your Task: Run SQL Scripts

I've created 2 SQL files for you to run in **both DEV and PROD**:

### **Location:**
```
database/migrations/shift_management/
├── 01_create_shift_management_tables.sql  ← Run this FIRST
├── 02_seed_default_shift_templates.sql    ← Run this SECOND
└── README_RUN_THESE_SQLS.md               ← Instructions
```

### **What to do:**

1. **Open `01_create_shift_management_tables.sql`**
   - Copy entire contents
   - Run in your MySQL client (DEV)
   - Run in your MySQL client (PROD)
   - ✅ Creates 3 new tables + 1 column

2. **Open `02_seed_default_shift_templates.sql`**
   - Copy entire contents
   - Run in your MySQL client (DEV)
   - Run in your MySQL client (PROD)
   - ✅ Inserts 4 default shift templates

3. **Reply to me:**
   - "SQL scripts run successfully in DEV ✅"
   - "SQL scripts run successfully in PROD ✅"

---

## 📊 Default Shifts Created

Based on your requirements:

| Shift Name | Code | Hours | Working Days | Off Days | Default? |
|------------|------|-------|--------------|----------|----------|
| Rider Shift 1 | `rider_shift_1` | 11:00 AM - 7:00 PM | Mon, Wed-Sun (6 days) | Tuesday | ✅ YES |
| Rider Shift 2 | `rider_shift_2` | 11:00 AM - 7:00 PM | Mon, Thu-Sun (5 days) | Tue, Wed | No |
| Manager Shift | `manager_shift` | 11:00 AM - 8:00 PM | Mon, Wed-Sun (6 days) | Tuesday | No |
| System Default | `system_default` | 9:00 AM - 5:00 PM | Mon-Sat (6 days) | Sunday | No |

**Note:** "Rider Shift 1" is marked as default, so any user without an explicit assignment will use this shift.

---

## 🔄 Migration Strategy

Don't worry about existing data! The system is designed with **zero breaking changes**:

1. **Old system keeps working:** 
   - Users with `shift_start/shift_end` in `t_ops_rider_profile` will continue using those values
   - New tables are empty initially

2. **Gradual migration:**
   - After backend code is deployed, you can assign users to new shift templates via UI
   - Both systems work side-by-side
   - When a user is assigned to a shift template, that takes priority

3. **Fallback logic:**
   ```
   User shift assignment 
   → Old rider_profile times (if not migrated)
   → Default shift template (Rider Shift 1)
   → Hardcoded fallback (9-5)
   ```

---

## 🚀 What Happens Next (After You Run SQLs)

Once you confirm SQLs are run, I'll implement:

### **Backend (Automatic):**
1. ✅ Create 3 Laravel models for new tables
2. ✅ Create `ShiftResolutionService` for smart shift lookup
3. ✅ Update `AttendanceController` to use new system
4. ✅ Update working days calculation to use shift templates
5. ✅ Update all attendance queries to use new service

### **Frontend (New Pages):**
1. ✅ **Shift Templates Page** (`/shifts`)
   - List all shift templates
   - Create/edit/delete shifts
   - Set default shift
   - Define working days (checkboxes)

2. ✅ **Holiday Management Page** (`/holidays`)
   - List all holidays
   - Add new holiday (date + name)
   - Delete holidays

3. ✅ **Enhanced "Manage Shifts" Modal**
   - Dropdown to assign shift template to users
   - Bulk assign multiple users at once
   - Show which shift each user has

4. ✅ **Updated Reports**
   - Use backend shift data (not localStorage)
   - Show which shift was used for calculations

---

## 📝 No More SQL Needed From You

After you run these 2 SQL files, **you're done with database work**!

I'll handle:
- All PHP/Laravel backend code
- All frontend UI pages
- All integration with existing pages
- Testing and verification

---

## ⚠️ Important Notes

1. **Don't delete old columns:** `shift_start` and `shift_end` in `t_ops_rider_profile` will stay for backward compatibility

2. **Tuesday is off by default:** Rider Shift 1 (the default) has Tuesday off, matching your current system

3. **Holidays:** Holiday table is empty initially - you can add holidays later via UI

4. **No impact yet:** Running these SQLs won't change any current behavior - just prepares the database

---

## ❓ Troubleshooting

**If SQL fails:**
- Check that `t_sys_user` table exists
- Check that user ID 1 exists
- Send me the error message

**If JSON column fails:**
- You might have old MySQL version (<5.7.8)
- Let me know and I'll provide alternative

---

## ✅ Ready to Run!

The SQL files are in:
```
database/migrations/shift_management/
```

Run them and let me know when done! 🎉



