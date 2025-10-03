# 🔧 SQL Scripts - Run in This Order

## ⚠️ IMPORTANT - Read This First

I've created **SAFE** versions of the SQL scripts that:
- Use `INT` instead of `BIGINT UNSIGNED` to match your existing tables
- Create tables WITHOUT foreign keys first
- Add foreign keys step-by-step so you can see exactly what fails
- Check if columns already exist before adding them
- Won't break your existing system

---

## 📋 Run These Scripts in Order

### Step 1: Check Your Current Structure (Optional but Recommended)

**File:** `database/migrations/00_check_existing_structure.sql`

This will show you:
- Current structure of `t_sys_role` and `t_sys_user`
- Existing foreign keys
- Sample data

**Just run it and review the output to understand your database.**

---

### Step 2: Install the Approval Workflow System

**File:** `database/migrations/01_approval_workflow_schema_SAFE.sql`

This is the **SAFE VERSION** that:
1. ✅ Creates tables without foreign keys
2. ✅ Inserts seed data
3. ✅ Adds foreign keys one by one (so you can see which one fails if any)
4. ✅ Updates `t_ops_attendance` table safely
5. ✅ Checks if columns already exist before adding

**Run this entire file in MySQL Workbench.**

---

## 🎯 What Changed from the Original Script

### Original Issue:
- Used `BIGINT UNSIGNED` for foreign keys
- Your existing tables likely use `INT`
- Type mismatch = foreign key error

### Fixed Version:
- ✅ All foreign key columns now use `INT` (matching your existing tables)
- ✅ Tables created first, then foreign keys added separately
- ✅ Better error messages - you'll see exactly which FK fails if any
- ✅ Safe updates to `t_ops_attendance` - checks if columns exist first

---

## 🔍 What to Watch For

When you run the script, you should see messages like:

```
Created t_sys_role_approval_level (without FKs)
Created t_req_category (without FKs)
Created t_req_master (without FKs)
...
Added FK: t_sys_role_approval_level -> t_sys_role
Added FK: t_sys_role_approval_level.created_by -> t_sys_user
...
INSTALLATION COMPLETE!
```

### If You Get an Error:

**Example:** "Error adding FK: t_sys_role_approval_level -> t_sys_role"

This means:
- The table `t_sys_role` might have a different structure
- Or the `id` column type doesn't match

**Solution:** 
1. Run the diagnostic script first: `00_check_existing_structure.sql`
2. Look at the output and tell me what the `id` column type is for `t_sys_role` and `t_sys_user`
3. I'll adjust the script accordingly

---

## ✅ After Running Successfully

You should have:
1. ✅ 5 new tables created
2. ✅ `t_ops_attendance` updated with leave request columns
3. ✅ 5 request categories seeded
4. ✅ Default approval configurations

---

## 🚀 Next Steps After SQL

Once the SQL runs successfully:

1. **Assign Approval Levels to Roles:**
   ```sql
   -- Check your roles first
   SELECT id, urole_name FROM t_sys_role WHERE is_active = 1;
   
   -- Assign Level 1 to Manager role (replace 2 with your actual manager role ID)
   INSERT INTO t_sys_role_approval_level (role_id, approval_level, created_by)
   VALUES (2, 1, 1);
   
   -- Assign Level 2 to Admin role (replace 1 with your actual admin role ID)
   INSERT INTO t_sys_role_approval_level (role_id, approval_level, created_by)
   VALUES (1, 2, 1);
   ```

2. **Test the System:**
   - Go to `/requests` in your browser
   - Create a test leave request
   - Approve it as a manager
   - Check attendance records

---

## 🆘 Troubleshooting

### Error: "Can't create table... foreign key"
- **Run:** `00_check_existing_structure.sql` first
- **Check:** The output for `id` column types in `t_sys_role` and `t_sys_user`
- **Tell me:** What data type it shows (INT, BIGINT, VARCHAR, etc.)

### Error: "Column already exists"
- **This is OK!** The script checks for this and will skip safely
- Look for "already exists" messages - they're just warnings

### Error: "Table doesn't exist"
- **Check:** Database name is `nizamifarms_db`
- **Run:** `USE nizamifarms_db;` first

---

## 📚 Documentation

After SQL runs successfully, refer to:
- **QUICK_START.md** - 5-minute setup guide
- **SETUP_INSTRUCTIONS.md** - Detailed configuration
- **APPROVAL_WORKFLOW_IMPLEMENTATION_GUIDE.md** - Full system docs

---

## 💡 Key Differences from Original

| Original Script | Safe Version |
|----------------|--------------|
| `BIGINT UNSIGNED` for FKs | `INT` for FKs (matches existing) |
| All FKs in CREATE TABLE | Tables first, then FKs separately |
| Direct ALTER TABLE | Checks if columns exist first |
| Would fail on first error | Shows exactly which FK fails |

---

## ✨ Safe for Your System

This script:
- ✅ Won't delete any existing data
- ✅ Won't modify existing tables (except adding 2 columns to `t_ops_attendance`)
- ✅ Uses `IF NOT EXISTS` where appropriate
- ✅ Checks before adding columns
- ✅ Uses proper foreign key constraints with `ON DELETE SET NULL` or `CASCADE`

**Your existing system will continue to work normally!**

