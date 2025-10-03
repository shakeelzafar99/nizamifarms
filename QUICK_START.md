# ⚡ Quick Start - 5 Minute Setup

## Step 1: Run This SQL (Required) ⚠️

Open MySQL Workbench and run the entire file:

```
database/migrations/approval_workflow_schema.sql
```

This creates all tables and seeds initial data.

---

## Step 2: Create This Directory (Required) ⚠️

In Windows File Explorer or terminal:

```
resources/views/pages/requests/
```

Then create 4 blank files in it:
- `index.blade.php`
- `create.blade.php`
- `show.blade.php`
- `settings.blade.php`

Copy templates from `BLADE_VIEWS_TEMPLATES.md` into these files.

---

## Step 3: Assign Approval Roles (Required) ⚠️

Run this to see your roles:

```sql
SELECT id, urole_name FROM t_sys_role WHERE is_active = 1;
```

Then assign approval levels (replace role IDs with your actual IDs):

```sql
-- Level 1 = Managers
INSERT INTO t_sys_role_approval_level (role_id, approval_level, created_by)
VALUES (2, 1, 1);

-- Level 2 = Admins
INSERT INTO t_sys_role_approval_level (role_id, approval_level, created_by)
VALUES (1, 2, 1);
```

---

## Step 4: Test It

1. Go to: `http://your-app/requests`
2. Click "New Request"
3. Create a leave request
4. Login as manager → Approve it
5. Check attendance records were created:

```sql
SELECT * FROM t_ops_attendance WHERE leave_request_id IS NOT NULL;
```

---

## 🎯 That's It!

You now have:
- ✅ Request submission system
- ✅ Two-level approval workflow
- ✅ Leave request → attendance integration
- ✅ Admin settings page

---

## 📋 Quick Commands

### Check who can approve:
```sql
-- Level 1 approvers
SELECT u.fullname, r.urole_name
FROM t_sys_user u
JOIN t_sys_user_role ur ON u.id = ur.user_id
JOIN t_sys_role r ON ur.role_id = r.id
JOIN t_sys_role_approval_level ral ON r.id = ral.role_id
WHERE ral.approval_level = 1 AND u.is_active = 1;

-- Level 2 approvers
SELECT u.fullname, r.urole_name
FROM t_sys_user u
JOIN t_sys_user_role ur ON u.id = ur.user_id
JOIN t_sys_role r ON ur.role_id = r.id
JOIN t_sys_role_approval_level ral ON r.id = ral.role_id
WHERE ral.approval_level = 2 AND u.is_active = 1;
```

### View pending requests:
```sql
SELECT request_number, title, status 
FROM t_req_master 
WHERE status = 'pending' 
ORDER BY submitted_at DESC;
```

### Configure category to need both levels:
```sql
-- Make salary advance need both Level 1 AND Level 2
UPDATE t_req_category_approval_config 
SET requires_level_1 = 1, requires_level_2 = 1
WHERE category_id = (SELECT id FROM t_req_category WHERE category_code = 'advance');
```

---

## 🚨 Troubleshooting

**Problem:** Table doesn't exist
- **Fix:** Run `database/migrations/approval_workflow_schema.sql`

**Problem:** No one can approve requests
- **Fix:** Assign roles to approval levels (Step 3 above)

**Problem:** Request stays pending after approval
- **Fix:** Check if category needs Level 2:
  ```sql
  SELECT * FROM t_req_category_approval_config WHERE category_id = X;
  ```

**Problem:** Views not working
- **Fix:** Create `resources/views/pages/requests/` directory

---

## 📚 Full Docs

- **Complete Guide:** `SETUP_INSTRUCTIONS.md`
- **System Details:** `APPROVAL_WORKFLOW_IMPLEMENTATION_GUIDE.md`
- **View Templates:** `BLADE_VIEWS_TEMPLATES.md`

---

## 🎨 Customization

All behavior is database-driven:

1. **Add new category:**
   ```sql
   INSERT INTO t_req_category (category_code, category_name, icon, color_class, created_by)
   VALUES ('custom', 'Custom Request', 'file-text', 'blue', 1);
   ```

2. **Change approval requirements:**
   ```sql
   -- Use settings page at: /requests/settings
   -- Or update directly:
   UPDATE t_req_category_approval_config 
   SET requires_level_1 = 1, requires_level_2 = 1
   WHERE category_id = X;
   ```

3. **Add more approvers:**
   ```sql
   INSERT INTO t_sys_role_approval_level (role_id, approval_level, created_by)
   VALUES (YOUR_ROLE_ID, 1, 1);
   ```

---

## ✨ Key URLs

- `/requests` - Main requests page
- `/requests/create` - Create new request
- `/requests/{id}` - View request details
- `/requests/settings` - Admin configuration

---

**That's all you need! The system is fully functional after these 4 steps.**

