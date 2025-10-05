# Shift Management System - SQL Scripts

## 🔴 IMPORTANT: Run in Order

Run these SQL scripts **in both DEV and PROD** environments.

---

## Step 1: Create Tables

**File:** `01_create_shift_management_tables.sql`

**What it does:**
- Creates 3 new tables:
  - `t_ops_shift_template` - Shift definitions
  - `t_ops_user_shift_assignment` - User-to-shift mappings
  - `t_ops_public_holidays` - Holiday calendar
- Adds `migrated_to_shift_system` column to `t_ops_rider_profile`

**Impact:** ✅ Zero - just creates empty tables

**Run:**
```sql
-- Copy and paste the entire contents of 01_create_shift_management_tables.sql
```

**Verify:**
```sql
-- Check tables were created
SHOW TABLES LIKE 't_ops_shift%';
SHOW TABLES LIKE 't_ops_public_holidays';

-- Check new column was added
SHOW COLUMNS FROM t_ops_rider_profile LIKE 'migrated_to_shift_system';
```

---

## Step 2: Insert Default Shifts

**File:** `02_seed_default_shift_templates.sql`

**What it does:**
- Inserts 4 shift templates:
  1. **Rider Shift 1** (11:00-19:00, Off: Tue, DEFAULT)
  2. **Rider Shift 2** (11:00-19:00, Off: Tue+Wed)
  3. **Manager Shift** (11:00-20:00, Off: Tue)
  4. **System Default** (09:00-17:00, Off: Sun, Fallback)

**Impact:** ✅ Zero - just inserts data, doesn't change behavior yet

**Run:**
```sql
-- Copy and paste the entire contents of 02_seed_default_shift_templates.sql
```

**Verify:**
```sql
-- You should see 4 rows
SELECT 
    id, shift_name, shift_code, shift_start, shift_end, 
    working_days, is_default 
FROM t_ops_shift_template;
```

**Expected Output:**
```
+----+----------------+----------------+-------------+-----------+-------------------+------------+
| id | shift_name     | shift_code     | shift_start | shift_end | working_days      | is_default |
+----+----------------+----------------+-------------+-----------+-------------------+------------+
|  1 | Rider Shift 1  | rider_shift_1  | 11:00:00    | 19:00:00  | [1,3,4,5,6,7]     |          1 |
|  2 | Rider Shift 2  | rider_shift_2  | 11:00:00    | 19:00:00  | [1,4,5,6,7]       |          0 |
|  3 | Manager Shift  | manager_shift  | 11:00:00    | 20:00:00  | [1,3,4,5,6,7]     |          0 |
|  4 | System Default | system_default | 09:00:00    | 17:00:00  | [1,2,3,4,5,6]     |          0 |
+----+----------------+----------------+-------------+-----------+-------------------+------------+
```

---

## Step 3: Code Deployment

After running the above SQLs, tell me and I'll:
1. Create Laravel models for the new tables
2. Create `ShiftResolutionService` for shift lookup
3. Update `AttendanceController` to use new shift system
4. Create UI pages for managing shifts and holidays

**No more SQL needed from you** - I'll handle the rest! 🚀

---

## Working Days Reference

For understanding the `working_days` JSON format:

```
1 = Monday
2 = Tuesday
3 = Wednesday
4 = Thursday
5 = Friday
6 = Saturday
7 = Sunday
```

**Examples:**
- `[1,3,4,5,6,7]` = Mon, Wed-Sun (Tuesday off)
- `[1,4,5,6,7]` = Mon, Thu-Sun (Tue+Wed off)
- `[1,2,3,4,5,6]` = Mon-Sat (Sunday off)
- `[1,2,3,4,5]` = Mon-Fri (Sat+Sun off)

---

## Troubleshooting

**If you get foreign key errors:**
- Make sure `t_sys_user` table exists and has `id` column
- Check that user ID 1 exists (used as created_by/updated_by)

**If IF NOT EXISTS doesn't work:**
- Your MySQL version might not support it
- Remove `IF NOT EXISTS` and run again (script will fail if table exists, which is fine)

**If JSON column fails:**
- Your MySQL version might not support JSON (need 5.7.8+)
- Let me know and I'll provide TEXT column alternative

---

## Once You're Done

Reply with:
- ✅ "SQL scripts run successfully in DEV"
- ✅ "SQL scripts run successfully in PROD"

And I'll proceed with the backend/frontend implementation! 💪



