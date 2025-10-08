# Financial Ledger System - SQL Scripts

## 📋 Overview

This directory contains SQL scripts to install a simplified double-entry ledger system for Nizami Farms.

**Database:** `nizamifarms_db`

---

## 📂 Files in this Directory

| File | Purpose | When to Use |
|------|---------|-------------|
| `fin_ledger_system.sql` | **MAIN INSTALLATION** | Run this ONCE to install the ledger system |
| `fin_ledger_system_VERIFY.sql` | **VERIFICATION** | Run after installation to check if everything is correct |
| `fin_ledger_system_ROLLBACK.sql` | **ROLLBACK/REMOVE** | ONLY if you need to completely remove the system |

---

## 🚀 Installation Steps

### **Step 1: Backup Your Database**
```bash
# Create a backup BEFORE running any scripts!
mysqldump -u root -p nizamifarms_db > nizamifarms_backup_$(date +%Y%m%d).sql
```

### **Step 2: Run Main Installation Script**

**Option A: MySQL Workbench (Recommended)**
1. Open MySQL Workbench
2. Connect to your database
3. File → Open SQL Script
4. Select `fin_ledger_system.sql`
5. Click ⚡ Execute
6. Review output for any errors

**Option B: Command Line**
```bash
mysql -u root -p nizamifarms_db < database/migrations/fin_ledger_system.sql
```

### **Step 3: Verify Installation**

Run the verification script:
```bash
mysql -u root -p nizamifarms_db < database/migrations/fin_ledger_system_VERIFY.sql
```

**Expected Output:**
- ✓ All 5 tables exist
- ✓ Foreign keys created (13+)
- ✓ Core accounts seeded (20+)
- ✓ Config entries (5)
- ✓ ledger_transaction_id column added to t_req_master

---

## 📊 What Gets Created

### **5 New Tables:**

1. **`t_fin_accounts`** - Account registry (NF Cash, Online, Employee Cash, Vendors, Expenses)
2. **`t_fin_ledger`** - All transactions (From → To pattern)
3. **`t_fin_vendors`** - Vendor master data
4. **`t_fin_import_log`** - CSV import history
5. **`t_fin_config`** - System configuration

### **1 Modified Table:**

- **`t_req_master`** - Added `ledger_transaction_id` column (links requests to ledger)

### **Seeded Data:**

#### **Core System Accounts (20+):**
```
Assets:
  - NF_CASH (Main Till)
  - ONLINE (Bank)
  - EXP_FUND (Expense Funding)

Income:
  - REV_SALES (Sales Revenue)
  - REV_OTHER (Other Income)

Expenses:
  - EXP_PURCHASES (Vendor Purchases)
  - EXP_FUEL (Fuel/Petrol)
  - EXP_FOOD (Food)
  - EXP_RENT (Rent)
  - EXP_UTILITIES (Utility Bills)
  - EXP_SALARIES (Staff Salaries)
  - EXP_PACKAGING (Packaging)
  - EXP_MARKETING (Marketing)
  - EXP_EQUIPMENT (Equipment)
  - EXP_MAINTENANCE (Maintenance)
  - EXP_TELECOM (Telecommunication)
  - EXP_INTERNET (Internet)
  - EXP_BANK_CHARGES (Bank Charges)
  - EXP_INDRIVE (Indrive Expenses)
  - EXP_OTHER (Other Expenses)

Equity:
  - EQUITY_OPENING (Opening Balance Equity)
```

#### **Configuration Settings:**
```
- expense_fund_account: EXP_FUND
- legacy_import_enabled: 1
- last_successful_import: NULL
- high_balance_threshold_employee: 100000
- high_balance_threshold_vendor: 500000
```

---

## 🔄 Foreign Key Relationships

### **Established Relationships:**

```
t_fin_accounts
├── user_id → t_sys_user.id
├── created_by → t_sys_user.id
└── updated_by → t_sys_user.id

t_fin_ledger
├── from_account_id → t_fin_accounts.id
├── to_account_id → t_fin_accounts.id
├── approved_by → t_sys_user.id
├── request_id → t_req_master.id
├── order_id → t_crm_prod_order.id
├── created_by → t_sys_user.id
└── updated_by → t_sys_user.id

t_fin_vendors
├── account_id → t_fin_accounts.id
├── created_by → t_sys_user.id
└── updated_by → t_sys_user.id

t_fin_import_log
└── imported_by → t_sys_user.id

t_req_master
└── ledger_transaction_id → t_fin_ledger.id
```

---

## ✅ Verification Checklist

After running the installation, verify:

- [ ] All 5 tables created (`t_fin_*`)
- [ ] Foreign keys established (13+)
- [ ] Core accounts seeded (check with: `SELECT * FROM t_fin_accounts`)
- [ ] Config entries present (check with: `SELECT * FROM t_fin_config`)
- [ ] `t_req_master.ledger_transaction_id` column added
- [ ] No error messages in output
- [ ] Run `fin_ledger_system_VERIFY.sql` shows all checks PASS

---

## 🔧 Troubleshooting

### **Issue: Foreign Key Error**

**Error:** `Cannot add foreign key constraint`

**Solution:**
1. Check if referenced table exists (e.g., `t_sys_user`, `t_req_master`)
2. Ensure data types match (INT → INT)
3. Verify database name is `nizamifarms_db`

### **Issue: Column Already Exists**

**Error:** `Duplicate column name 'ledger_transaction_id'`

**Solution:**
This is safe to ignore. The script checks if column exists before adding.

### **Issue: Table Already Exists**

**Error:** `Table 't_fin_accounts' already exists`

**Solution:**
The script uses `DROP TABLE IF EXISTS`. If you see this error, someone already ran the script. Run `VERIFY` script to check status.

---

## ⚠️ Rollback Instructions

**ONLY use this if you need to completely remove the ledger system!**

### **Step 1: Backup First!**
```bash
mysqldump -u root -p nizamifarms_db > nizamifarms_backup_before_rollback.sql
```

### **Step 2: Run Rollback Script**
```bash
mysql -u root -p nizamifarms_db < database/migrations/fin_ledger_system_ROLLBACK.sql
```

**This will:**
- ❌ Delete all `t_fin_*` tables
- ❌ Remove `ledger_transaction_id` from `t_req_master`
- ❌ **Delete ALL financial data** (cannot be undone!)

---

## 📞 Support

If you encounter issues:
1. Run verification script first
2. Check error messages carefully
3. Verify database name is correct
4. Ensure you have proper MySQL permissions
5. Check if existing tables have the correct structure

---

## 🎯 Next Steps After Installation

1. ✅ Run verification script
2. ✅ Create Laravel models (`AccountModel`, `LedgerModel`, `VendorModel`)
3. ✅ Build import service for legacy CSV
4. ✅ Create controllers (Employee Cash, Vendor Management)
5. ✅ Build frontend views

---

## 📝 Notes

- **Safe to Re-run:** The main installation script can be re-run safely (it drops tables first)
- **Deduplication:** Legacy imports use `external_source` + `external_txn_id` for deduplication
- **Balances:** Both `opening_balance` and `current_balance` are tracked
- **Audit Trail:** All tables have `created_by`, `updated_by`, `created_at`, `updated_at`
- **Soft Deletes:** FKs use `ON DELETE SET NULL` (not `CASCADE`) for safety

---

## 🔐 Database Conventions Followed

✅ Primary Keys: `INT AUTO_INCREMENT`
✅ Foreign Keys: `INT` (matching your existing tables)
✅ User References: `INT NULL` (allows NULL)
✅ Timestamps: Standard Laravel format
✅ Charset: `utf8mb4_unicode_ci`
✅ Engine: `InnoDB`
✅ Indexes: All FKs and commonly queried fields
✅ Comments: Descriptive comments on all columns

---

**Version:** 1.0
**Database:** nizamifarms_db
**Compatible with:** Your existing approval workflow system
**Created:** February 2025

