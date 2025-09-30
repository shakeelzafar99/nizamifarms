# Rider/Employee Workflow & Bulk Upload Guide

## 🔄 **Employee Lifecycle Workflow**

### **Active Employee**
1. **User exists in `t_sys_user`** with `is_active = 1`
2. **Profile in `t_ops_rider_profile`** with `active = 1`
3. Can be assigned to orders
4. Shows in all dropdowns (attendance, order assignment, etc.)
5. Can log attendance

### **Inactive/Left Employee**
When an employee leaves:

#### **Option 1: Soft Delete (Recommended)**
```sql
-- Mark user as inactive
UPDATE t_sys_user SET is_active = 0 WHERE id = <user_id>;

-- Mark rider profile as inactive
UPDATE t_ops_rider_profile SET active = 0 WHERE user_id = <user_id>;
```

**Result:**
- ✅ Historical data preserved (orders, attendance, assignments)
- ✅ Name shows in historical records
- ❌ Won't appear in new assignment dropdowns
- ✅ Reports still accurate

#### **Option 2: Keep Active (Not Recommended)**
- Keep both records active
- Just don't assign them to new orders
- They'll still show in dropdowns (confusing)

#### **Option 3: Hard Delete (Dangerous - NOT Recommended)**
- Delete from `t_sys_user`
- All historical references break
- Order history shows user_id without names
- Reports become inaccurate

---

## 📊 **Database Relationship**

```
t_sys_user (Master user record)
├── is_active (0 = inactive/left, 1 = active)
└── id → user_id in other tables

t_ops_rider_profile (Rider-specific data)
├── user_id (FK to t_sys_user.id)
├── active (0 = inactive, 1 = active)
├── shift_start
├── shift_end
├── vehicle_type
└── vehicle_plate

t_ops_order_rider_history (Assignment history)
├── order_id
├── rider_user_id (FK to t_sys_user.id)
├── assigned_at
└── is_current (0 = past, 1 = current)

t_ops_attendance (Attendance records)
├── user_id (FK to t_sys_user.id)
├── attendance_date
├── login_time
└── logout_time
```

---

## 📦 **Bulk Upload Formats**

### **1. Bulk Rider Assignment CSV**
**File:** `rider_assignments.csv`

```csv
order_number,rider_user_id,assigned_at,notes
NF-12345,5,2025-09-30 10:00:00,Initial assignment
NF-12346,7,2025-09-30 11:30:00,Reassigned to this rider
15234,5,2025-09-29 09:00:00,Historical assignment
```

**Fields:**
- `order_number` - Order number (with or without "NF-" prefix)
- `rider_user_id` - User ID from `t_sys_user` (must exist)
- `assigned_at` - Assignment timestamp (YYYY-MM-DD HH:MM:SS)
- `notes` - Optional notes

**Validation:**
- ❌ Missing orders → skipped (report shows)
- ❌ Invalid rider_user_id → skipped (report shows)
- ✅ Existing assignments → updated
- ✅ New assignments → inserted

**Upload Location:** Operations → Bulk Rider Assignment

---

### **2. Bulk Attendance CSV**
**File:** `attendance_records.csv`

```csv
user_id,attendance_date,login_time,logout_time,device_id,notes
5,2025-09-01,09:00:00,17:00:00,MOBILE_001,On time
7,2025-09-01,09:30:00,17:15:00,MOBILE_002,Late arrival
5,2025-09-02,,,,"Absent - Sick leave"
```

**Fields:**
- `user_id` - User ID from `t_sys_user` (must exist)
- `attendance_date` - Date (YYYY-MM-DD)
- `login_time` - Login time (HH:MM:SS or blank for absent)
- `logout_time` - Logout time (HH:MM:SS or blank)
- `device_id` - Optional device identifier
- `notes` - Optional notes

**Status is Auto-Calculated:**
- **Present** = login_time is set
- **Absent** = login_time is NULL/blank
- **Late** = login_time > shift_start
- **On Time** = login_time <= shift_start

**Validation:**
- ❌ Invalid user_id → skipped (report shows)
- ❌ Invalid date format → skipped
- ✅ Existing attendance → updated (upsert by user_id + date)
- ✅ New attendance → inserted

**Upload Location:** Operations → Bulk Attendance Upload

---

### **3. Bulk Order Status CSV**
**File:** `order_statuses.csv`

```csv
order_number,status,changed_at,notes
NF-12345,delivered,2025-09-30 15:00:00,Delivered successfully
15234,out_for_delivery,2025-09-30 10:00:00,Out for delivery
NF-12346,processing,2025-09-29 09:00:00,Order being processed
```

**Fields:**
- `order_number` - Order number (with or without "NF-" prefix, with or without commas)
- `status` - Status code (new, processing, out_for_delivery, delivered, cancelled, etc.)
- `changed_at` - Status change timestamp (YYYY-MM-DD HH:MM:SS)
- `notes` - Optional notes

**Status Normalization:**
- `on-hold`, `on hold`, `onhold` → `on_hold`
- `out for delivery`, `out-for-delivery` → `out_for_delivery`
- Auto-normalizes common variations

**Upload Location:** Operations → Bulk Delivery Status

---

## 🎯 **Best Practices**

### **For Riders Who Leave:**
1. Set `t_sys_user.is_active = 0`
2. Set `t_ops_rider_profile.active = 0`
3. Keep all records (don't delete)
4. Historical data remains intact
5. Reports show accurate history

### **For Bulk Uploads:**
1. **Always use User IDs** - not names (names can duplicate)
2. **Check user existence first** - run: `SELECT id, fullname FROM t_sys_user WHERE is_active = 1`
3. **Use consistent date formats** - YYYY-MM-DD HH:MM:SS
4. **Review error reports** - system shows which rows failed and why
5. **Test with small file first** - 5-10 rows to verify format

### **For Historical Data:**
1. Upload in chronological order
2. Include `assigned_at` / `changed_at` timestamps
3. System preserves history timeline
4. No duplicate checking on bulk uploads (by design for historical imports)

---

## 📋 **Query Examples**

### Get All Active Riders
```sql
SELECT u.id, u.fullname, rp.shift_start, rp.shift_end, rp.vehicle_type
FROM t_sys_user u
JOIN t_ops_rider_profile rp ON rp.user_id = u.id
WHERE u.is_active = 1 AND rp.active = 1;
```

### Get Inactive Riders (Left Company)
```sql
SELECT u.id, u.fullname, u.updated_at as left_date
FROM t_sys_user u
LEFT JOIN t_ops_rider_profile rp ON rp.user_id = u.id
WHERE u.is_active = 0 OR rp.active = 0;
```

### Get Rider Assignment History
```sql
SELECT o.order_number, u.fullname as rider_name, 
       h.assigned_at, h.is_current, h.notes
FROM t_ops_order_rider_history h
JOIN t_crm_prod_order o ON o.id = h.order_id
JOIN t_sys_user u ON u.id = h.rider_user_id
WHERE h.rider_user_id = <user_id>
ORDER BY h.assigned_at DESC;
```

### Get Attendance Summary
```sql
SELECT u.fullname, 
       COUNT(*) as total_days,
       SUM(CASE WHEN a.login_time IS NOT NULL THEN 1 ELSE 0 END) as present_days,
       SUM(CASE WHEN a.login_time IS NULL THEN 1 ELSE 0 END) as absent_days
FROM t_ops_attendance a
JOIN t_sys_user u ON u.id = a.user_id
WHERE a.attendance_date BETWEEN '2025-09-01' AND '2025-09-30'
GROUP BY u.id, u.fullname;
```

---

## 🚨 **Common Issues**

### Issue: "User not found"
- **Cause:** User ID doesn't exist in `t_sys_user`
- **Fix:** Check user exists: `SELECT id FROM t_sys_user WHERE id = <user_id>`

### Issue: "Order not found"
- **Cause:** Order number doesn't exist
- **Fix:** Verify order: `SELECT id, order_number FROM t_crm_prod_order WHERE order_number = 'NF-12345'`

### Issue: Inactive riders showing in dropdowns
- **Cause:** `is_active = 1` but shouldn't be
- **Fix:** `UPDATE t_sys_user SET is_active = 0 WHERE id = <user_id>`

### Issue: Historical assignments not showing
- **Cause:** Missing records in `t_ops_order_rider_history`
- **Fix:** Use bulk upload to backfill historical data

---

## 📞 **Support**

For issues:
1. Check Laravel logs: `storage/logs/laravel.log`
2. Verify database records
3. Review bulk upload error reports
4. Consult this document for correct formats
