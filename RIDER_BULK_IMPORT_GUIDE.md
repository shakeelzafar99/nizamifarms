# Rider Bulk Import - Complete Guide

## 📋 Overview

This guide explains how to bulk assign delivery riders to orders using a CSV file upload. The system intelligently matches rider names, cleans data, and provides detailed feedback.

---

## ✅ Your Data Format (From Screenshot)

Your data structure is **fully compatible**! Here's what you have:

| Column A | Column B | Column C | Column D |
|----------|----------|----------|----------|
| Order Number | Delivery_Rider | Payment_method | Date |
| 9145 | Arsalan | Cash | 3/3/2025 |
| 9144 | Arsalan | Cash | 3/3/2025 |
| 9141 | Jazib | Online | 3/3/2025 |
| 9176 | Arsalan | Online | 3/3/2025 |
| 9159 | Asim Tahir - Indri | Online | 3/3/2025 |

**✓ This will work perfectly with the bulk import!**

---

## 📝 CSV Format Requirements

### Required Columns

1. **Order Number** (accepts any format):
   - `Order Number`
   - `order_number`
   - `Order_Number`
   - Case-insensitive

2. **Rider Name** (accepts any format):
   - `Delivery_Rider`
   - `rider_name`
   - `Delivery Rider`
   - `rider name`
   - Case-insensitive

### Optional Columns

3. **Date** (accepts any format):
   - `Date`
   - `assigned_at`
   - `assigned at`
   - Any date format: `3/3/2025`, `2025-03-03`, etc.

4. **Other columns** (will be ignored):
   - `Payment_method`
   - Any other columns are fine, they won't affect the import

---

## 🎯 How to Prepare Your CSV

### Option 1: Use Your Existing Data (Easiest)
Your screenshot shows you already have the perfect format! Just:
1. Copy columns A, B, and D (Order Number, Delivery_Rider, Date)
2. Save as CSV
3. Upload!

### Option 2: Minimal CSV (Order + Rider only)
```csv
Order Number,Delivery_Rider
9145,Arsalan
9144,Arsalan
9141,Jazib
9176,Arsalan
9159,Asim Tahir - Indri
```

### Option 3: Full CSV (with Date)
```csv
Order Number,Delivery_Rider,Date
9145,Arsalan,3/3/2025
9144,Arsalan,3/3/2025
9141,Jazib,3/3/2025
9176,Arsalan,3/3/2025
9159,Asim Tahir - Indri,3/3/2025
```

---

## 🔧 Smart Matching Features

### 1. Automatic Name Cleaning
The system automatically removes common suffixes:

| Your CSV | Cleaned | Matches User |
|----------|---------|--------------|
| `Asim Tahir - Indri` | `Asim Tahir` | ✓ |
| `Arsalan - indrive` | `Arsalan` | ✓ |
| `Jazib - indriver` | `Jazib` | ✓ |

### 2. Four Matching Strategies

**Strategy 1: Exact Match**
- CSV: `Arsalan` → Matches user: `Arsalan` ✓

**Strategy 2: Case-Insensitive**
- CSV: `arsalan` → Matches user: `Arsalan` ✓
- CSV: `JAZIB` → Matches user: `Jazib` ✓

**Strategy 3: Starts With**
- CSV: `Arsalan` → Matches user: `Arsalan Khan` ✓
- CSV: `Asim` → Matches user: `Asim Tahir` ✓

**Strategy 4: Contains**
- CSV: `Tahir` → Matches user: `Asim Tahir` ✓

### 3. Order Validation
- ✅ Only non-Shopify orders are matched
- ✅ Order must exist in `t_crm_prod_order`
- ✅ Exact order number match

---

## 📤 How to Upload

1. **Navigate to Operations**
   - Sidebar → Administration → Operations

2. **Find "Import Rider Assignments" Card**

3. **Choose Your CSV File**
   - Click "Choose File"
   - Select your CSV file
   - Format: `.csv` or `.txt`
   - Max size: 4 MB

4. **Click "📤 Upload & Assign Riders"**

5. **Review Results**
   - Success message shows how many riders assigned
   - Warning messages show missing orders/riders
   - Error messages show any processing issues

---

## ✅ Success Response Example

```
✓ Imported: 25 row(s).
```

If all orders and riders are found, you'll see this simple success message.

---

## ⚠️ Warning Messages

### Missing Orders
```
⚠ Orders not found (non-Shopify):
9999, 8888, 7777
```

**Reasons**:
- Order doesn't exist in database
- Order is a Shopify order (not supported for bulk import)
- Order number typo

**Solution**:
- Verify order exists in Orders page
- Check if order is non-Shopify
- Fix typos in CSV

### Missing Riders
```
⚠ Riders not found in users:
Unknown Rider (cleaned: Unknown Rider)
NewGuy (cleaned: NewGuy)
```

**Reasons**:
- Rider not in `t_sys_user` table
- Name doesn't match any user (even with partial matching)
- Inactive rider (the system searches all users, active or inactive)

**Solution**:
- Add rider to Users page first
- Check spelling in CSV
- Use exact name from Users table

---

## 🐛 Error Messages

### Row-Level Errors
```
❌ Processing errors:
Row 5: Missing order_number or rider info
Row 12: Failed to assign rider (order: 9145)
```

**Common causes**:
- Empty cells in required columns
- Invalid date format
- Database constraint violation

**Solution**:
- Ensure all rows have Order Number and Rider Name
- Fix date format
- Check database logs for detailed error

---

## 📊 What Happens Behind the Scenes

For each successful row:

1. **Order Lookup**
   - Finds order in `t_crm_prod_order`
   - Validates it's non-Shopify

2. **Rider Name Cleaning**
   - Removes suffixes: `"- indrive"`, `"- Indri"`, etc.
   - Trims extra spaces

3. **Rider Matching**
   - Uses 4 strategies (exact, case-insensitive, starts-with, contains)
   - Finds rider in `t_sys_user`

4. **Assignment**
   - Calls `OrderModel::assignRider()`
   - Updates `t_crm_prod_order.assigned_rider_user_id`
   - Creates history record in `t_ops_order_rider_history`
   - Sets `is_current = 1` for new assignment
   - Sets `is_current = 0` for previous assignments
   - Logs: `source = 'api'`, `notes = 'CSV import'`

---

## 🔍 Verification

After upload, verify the assignments:

### Method 1: Orders Page
1. Go to Orders page
2. Enable "Rider" column in Column Customizer
3. Search for your order numbers
4. Check "Rider" column shows correct name

### Method 2: View Order Modal
1. Go to Orders page
2. Click "👁️ View" on any order
3. Scroll to "Rider Assignment" section
4. Check assigned rider and history timeline

### Method 3: Database Query
```sql
SELECT 
    o.order_number,
    u.fullname AS rider_name,
    h.assigned_at,
    h.notes
FROM t_crm_prod_order o
LEFT JOIN t_sys_user u ON u.id = o.assigned_rider_user_id
LEFT JOIN t_ops_order_rider_history h ON h.order_id = o.id AND h.is_current = 1
WHERE o.order_number IN (9145, 9144, 9141, 9176, 9159)
ORDER BY o.order_number;
```

---

## 🚫 What This Does NOT Do

- ❌ Does not assign Shopify orders (use Shopify interface)
- ❌ Does not create new riders (add them to Users first)
- ❌ Does not modify order status
- ❌ Does not update order details (product, price, etc.)
- ❌ Only handles rider assignments

---

## 🔄 Re-assigning Riders

**Q: What if an order already has a rider assigned?**

**A:** The system will:
1. ✅ Mark the old assignment as `is_current = 0`
2. ✅ Create a new assignment with `is_current = 1`
3. ✅ Update the order's `assigned_rider_user_id`
4. ✅ Preserve history (you can see past assignments in the timeline)

This is **safe** and **intentional** - you can re-import to correct mistakes!

---

## 🧪 Test Cases

### Test 1: Basic Import (Exact Names)
```csv
Order Number,Delivery_Rider
9145,Arsalan
9144,Jazib
```

**Expected**: Both orders assigned if riders exist.

### Test 2: Names with Suffixes
```csv
Order Number,Delivery_Rider
9159,Asim Tahir - Indri
9146,Arsalan - indrive
```

**Expected**: Suffixes removed, riders matched and assigned.

### Test 3: Case Variations
```csv
Order Number,Delivery_Rider
9145,arsalan
9144,JAZIB
9141,JaZiB
```

**Expected**: All matched case-insensitively.

### Test 4: Partial Names
```csv
Order Number,Delivery_Rider
9145,Ars
9159,Tahir
```

**Expected**: 
- `Ars` matches `Arsalan` (starts with)
- `Tahir` matches `Asim Tahir` (contains)

### Test 5: Missing Data
```csv
Order Number,Delivery_Rider
9999,Arsalan
9145,NonexistentRider
```

**Expected**:
- Order 9999: Warning "Order not found"
- NonexistentRider: Warning "Rider not found"

---

## 📈 Best Practices

1. **Test with Small Batch First**
   - Upload 5-10 rows first
   - Verify results
   - Then upload full file

2. **Keep Original File**
   - Save a backup before uploading
   - In case you need to re-process

3. **Check Users Table First**
   - Ensure all riders exist in Users
   - Use exact names from Users table
   - Check for typos

4. **Review Warnings**
   - Don't ignore "missing" warnings
   - Fix issues and re-upload
   - Only those rows will fail, others will succeed

5. **Verify After Upload**
   - Spot-check a few orders
   - Use Orders page or View modal
   - Confirm rider names are correct

---

## 🆘 Troubleshooting

### Issue 1: "Empty or invalid CSV"
**Solution**: 
- Ensure CSV has at least 2 rows (header + data)
- Check file encoding (UTF-8)
- No empty lines at top

### Issue 2: "Missing order_number or rider info"
**Solution**:
- Check for empty cells in columns A or B
- Remove blank rows
- Ensure header row exists

### Issue 3: All orders showing as "not found"
**Solution**:
- Check if orders are Shopify (not supported)
- Verify order numbers are correct
- Check `t_crm_prod_order.external_source` column

### Issue 4: All riders showing as "not found"
**Solution**:
- Check rider names in Users page
- Copy exact names from Users table
- Remove suffixes or let system clean them

### Issue 5: Some riders assigned, others failed
**Solution**:
- Review warnings section
- Fix missing riders/orders
- Re-upload (existing assignments will be updated safely)

---

## 🔐 Security & Permissions

- ✅ Only admins can access Operations page
- ✅ All assignments logged with source: 'CSV import'
- ✅ History preserved for audit trail
- ✅ Cannot delete or modify past assignments

---

## 🎓 Example Workflow

**Scenario**: You have 50 orders from March 3, 2025 that need rider assignments.

**Step 1**: Export data from your system
- Columns: Order Number, Delivery_Rider, Date
- Save as CSV

**Step 2**: Add missing riders (if any)
- Check Users page
- Add any riders not in system
- Note exact spelling of names

**Step 3**: Clean data (optional)
- Remove extra columns (system ignores them anyway)
- Or keep them, they won't cause issues

**Step 4**: Upload CSV
- Go to Operations → Rider Assignments
- Upload file
- Click "Upload & Assign Riders"

**Step 5**: Review results
- ✓ 48 assigned successfully
- ⚠️ 2 orders not found (9999, 8888)

**Step 6**: Fix issues
- Check why orders 9999, 8888 missing
- Remove those rows or fix order numbers
- Re-upload (will update existing assignments)

**Step 7**: Verify
- Go to Orders page
- Enable "Rider" column
- Spot-check 5-10 orders
- ✓ All correct!

---

## 📞 Support

If you encounter issues:
1. Check this guide first
2. Review warning/error messages
3. Verify order exists and is non-Shopify
4. Verify rider exists in Users table
5. Check Laravel logs: `storage/logs/laravel.log`

---

**Created**: October 2025  
**Last Updated**: October 2025  
**Version**: 2.0 (Enhanced with smart matching)

