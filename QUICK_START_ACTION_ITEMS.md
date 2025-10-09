# 🚀 Quick Start Guide - Ledger Action Items

## 📋 Prerequisites
- Run the SQLs for ledger action items system
- System is already integrated into your app

---

## ⚡ Setup (2 Minutes)

### **Step 1: Run SQL Migrations** (Required)

Open your MySQL client and run these **2 SQL files** in order:

#### **1.1 Add ledger_transaction_id to Orders**
```bash
source database/migrations/add_ledger_transaction_id_to_orders_FIXED.sql;
```
✅ This adds the column to link orders to ledger entries

#### **1.2 Create Action Items Table**
```bash
source database/migrations/fin_action_items_system.sql;
```
✅ This creates the action items table and adds the auto-post config

---

### **Step 2: Enable Auto-Posting** (Optional - Test First!)

**Option A: From UI** (Recommended)
1. Go to **Operations** page (`/admin/operations`)
2. Find **"Ledger Settings"** card
3. Toggle **ON**
4. Done! ✅

**Option B: SQL Command**
```sql
UPDATE `t_fin_config` 
SET `config_value` = '1' 
WHERE `config_key` = 'LEDGER_AUTO_POST_ENABLED';
```

---

## 🧪 Quick Test (5 Minutes)

### **Test 1: Missing Rider Creates Action Item**
1. Find an order (or create one)
2. **Do NOT assign a rider**
3. Mark order as "delivered"
4. Go to **Finance → Action Items** in sidebar
5. You should see a new action item with:
   - ❌ Status: **OPEN**
   - 🔴 Severity: **HIGH**
   - 📋 Type: **Missing Rider**

### **Test 2: Retry Posting**
1. Click on the action item you just created
2. Assign a rider to the order (go back to orders page)
3. Return to action item detail page
4. Click **"Retry Posting"** button
5. Verify:
   - ✅ Action item marked as **RESOLVED**
   - ✅ Ledger entry created
   - ✅ Employee cash account updated

### **Test 3: Toggle Auto-Posting**
1. Go to **Operations** page
2. Find **"Ledger Settings"** card
3. Toggle **OFF**
4. Mark an order as delivered (without rider)
5. Verify: No action item created (because posting is disabled)
6. Toggle **ON** again
7. Done! ✅

---

## 📍 Where to Find Things

### **In Sidebar**
- **Finance** section
- **Action Items** menu item (with red badge if issues exist)

### **In Operations**
- **Ledger Settings** card (toggle auto-posting)
- **View Ledger Action Items** link

### **In Finance Section**
- **Finance → Action Items** - Main list page
- Click any item to see details and actions

---

## 🎯 What Each Status Means

| Status | Meaning | Action |
|--------|---------|--------|
| 🔴 **OPEN** | Issue needs attention | Resolve or dismiss it |
| ✅ **RESOLVED** | Issue fixed | No action needed |
| ⚪ **DISMISSED** | Issue ignored | No action needed |

---

## 🔧 Common Scenarios

### **Scenario 1: Order Missing Rider**
**Problem**: Order marked delivered without assigned rider  
**Action Item Created**: Yes (automatically)  
**How to Fix**:
1. Assign rider to order
2. Click "Retry Posting" on action item
3. System posts to ledger automatically

### **Scenario 2: Employee Not Found During Import**
**Problem**: Legacy CSV has employee name not in `t_sys_user`  
**Action Item Created**: Yes (automatically)  
**How to Fix**:
1. Create user in User Management
2. Re-run import OR manually adjust ledger
3. Mark action item as resolved

### **Scenario 3: Auto-Posting Disabled**
**Problem**: Want to test without auto-posting to ledger  
**Action Item Created**: No  
**How to Enable**:
1. Go to Operations → Ledger Settings
2. Toggle ON
3. Future orders will post automatically

---

## 📊 Quick Stats

Once set up, you can monitor:
- **Sidebar Badge**: Real-time count of open issues
- **Action Items Page**: Filterable list (Open/Resolved/Dismissed/All)
- **Stats Cards**: Summary counts at top of page

---

## ❓ FAQ

### **Q: Why is auto-posting disabled by default?**
A: To allow testing. Enable it when you're ready for production use.

### **Q: What happens if I retry posting and it fails again?**
A: The action item remains open, and an error is logged. Check logs for details.

### **Q: Can I delete action items?**
A: No, they're kept for audit. You can dismiss them instead (status = ignored).

### **Q: Will old orders create action items?**
A: No, only orders marked as delivered AFTER you enable auto-posting.

### **Q: How do I know if auto-posting is working?**
A: Check the sidebar badge or Action Items page. If orders are delivering without issues, the badge should stay at 0.

---

## ✅ Checklist

Before going live:
- [ ] Run both SQL migrations
- [ ] Test with one order (missing rider)
- [ ] Test retry posting
- [ ] Verify sidebar badge works
- [ ] Check Operations toggle
- [ ] Enable auto-posting when ready
- [ ] Monitor action items regularly

---

## 🎉 You're All Set!

The system is now fully operational. Action items will be created automatically when issues occur, and you can track/resolve them from the **Finance → Action Items** page.

**Need Help?**  
Check the full documentation: `LEDGER_ACTION_ITEMS_COMPLETE.md`

---

*Last Updated: 2025-10-09*  
*Feature: Ledger Action Items System*

