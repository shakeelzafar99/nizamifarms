# 🗑️ Clear Legacy Data Feature

## ✅ **Feature Added!**

You can now **safely clean up and re-import** legacy data for testing!

---

## 📍 **Where to Find It:**

**Operations Page** (`/admin/operations`)

You'll see a new card: **"🗑️ Clear Legacy Data (Testing)"**

---

## 🔄 **What It Does:**

### **Deletes:**
- ✅ All imported ledger transactions (from CSV)
- ✅ All import history logs
- ✅ Resets all account balances to **0**

### **Preserves (Structure):**
- ✅ All accounts (Expense - Petrol, NF Cash, Online, etc.)
- ✅ Expense categories
- ✅ Vendors (empty balances)
- ✅ Employees (empty balances)
- ✅ System configuration

---

## 🎯 **Use Cases:**

### **1. Testing Import Multiple Times** ✅
```
1. Import CSV → Test flows → Find issue
2. Clear legacy data
3. Fix CSV or flows
4. Re-import → Test again
```

### **2. Clean Slate After Testing** ✅
```
Before production:
1. Clear all test imports
2. Import real/final CSV
3. Go live with clean data
```

### **3. Fixing Import Errors** ✅
```
If import has wrong data:
1. Clear legacy data
2. Correct CSV
3. Re-import fixed version
```

---

## 🔐 **How to Use:**

### **Step 1: Navigate**
```
Sidebar → Operations → Scroll to "Clear Legacy Data" card
```

### **Step 2: Type Confirmation**
```
Type exactly: DELETE_ALL_LEGACY_DATA
(Copy-paste safe!)
```

### **Step 3: Click Button**
```
Click "🗑️ Clear All Legacy Data"
```

### **Step 4: Final Confirmation**
```
Popup will ask: "Are you absolutely sure?"
Click OK to proceed
```

### **Step 5: Done!**
```
✅ Success message shows:
- X ledger transactions deleted
- X import logs deleted
- Balances reset to 0
```

---

## 📊 **What Happens Technically:**

### **Backend** (`app/Http/Controllers/FIN/ImportController.php`):

```php
clearLegacyData() method:
1. Counts records before deletion
2. Deletes from t_fin_ledger:
   - WHERE external_source LIKE '%legacy%'
   - OR external_source LIKE '%appsheet%'
3. Resets t_fin_accounts balances:
   - current_balance = 0
   - opening_balance = 0
   - (Except system accounts: REV_SALES, EQUITY_OPENING)
4. Truncates t_fin_import_log
5. Commits transaction
6. Shows success message
```

### **Database Changes:**
```sql
-- Deletes
DELETE FROM t_fin_ledger 
WHERE external_source LIKE '%legacy%' 
   OR external_source LIKE '%appsheet%';

-- Resets
UPDATE t_fin_accounts 
SET current_balance = 0, opening_balance = 0
WHERE is_active = 1 
  AND account_code NOT IN ('REV_SALES', 'REV_OTHER', 'EQUITY_OPENING');

-- Clears
TRUNCATE TABLE t_fin_import_log;
```

---

## ⚠️ **Important Notes:**

### **1. Only Affects Legacy Imports** ✅
- Only deletes transactions with `external_source` containing "legacy" or "appsheet"
- **New transactions** (created via app after import) are **NOT deleted**

### **2. Safe for Testing** ✅
- Accounts structure preserved
- No data loss of configuration
- Can re-import immediately

### **3. Not Reversible** ⚠️
- Once cleared, data cannot be recovered
- Make sure you have the CSV backed up
- Only use during testing phase

### **4. Production Warning** 🚨
- After going live with real data, **DO NOT use this feature**
- This is designed for **testing phase only**
- Consider removing/hiding this feature in production

---

## 🧪 **Testing Workflow:**

### **Recommended Test Cycle:**

```
Cycle 1: Initial Test
├── 1. Import legacy CSV
├── 2. Test expense requests
├── 3. Test approvals with payment source
├── 4. Check Overall Ledger
└── 5. Verify balances

Find issues? Need to fix flows?
    ↓
Cycle 2: Clean & Re-test
├── 1. Clear Legacy Data ⭐
├── 2. Fix issues (CSV, flows, code)
├── 3. Re-import CSV
├── 4. Test again
└── 5. Repeat until perfect

Final: Production Import
├── 1. Clear all test data ⭐
├── 2. Import final/clean CSV
├── 3. Verify everything
├── 4. Go live
└── 5. **DISABLE clear feature** 🚨
```

---

## 📋 **Before/After Example:**

### **Before Clear:**
```
t_fin_ledger:
- 1,245 legacy transactions
- Balances: NF Cash = Rs. 50,000, Online = Rs. 250,000

t_fin_accounts:
- 50 accounts with balances

t_fin_import_log:
- 3 import records
```

### **After Clear:**
```
t_fin_ledger:
- 0 legacy transactions ✅
- (New app transactions preserved)

t_fin_accounts:
- 50 accounts (structure intact) ✅
- All balances = 0 ✅

t_fin_import_log:
- 0 records ✅
```

### **Ready to Re-import:**
```
Upload CSV again
    ↓
Fresh import with clean slate
    ↓
Balances recalculated from scratch
```

---

## 🎨 **UI Features:**

### **1. Visual Warning** ⚠️
- Red danger zone styling
- Clear "This will delete" / "This will NOT delete" lists
- Prominent warnings

### **2. Double Confirmation** 🔐
- Must type: `DELETE_ALL_LEGACY_DATA`
- Then browser confirm popup
- Prevents accidental clicks

### **3. Clear Feedback** ✅
- Shows how many records deleted
- Success message with details
- Error handling if something fails

### **4. Easy Access** 📍
- Same page as import
- Logical grouping
- Clear instructions

---

## 🔄 **Alternative: Per-Batch Delete** (Future)

Currently implemented: **Clear ALL**

**Future enhancement option:**
```
Finance → Import History
    ↓
See list of imports:
├── Import #1 (Jan 1, 2025) - 500 records
├── Import #2 (Jan 2, 2025) - 300 records
└── Import #3 (Jan 3, 2025) - 450 records
    ↓
Delete specific import batch
```

**Current approach is simpler for testing!**

---

## ✅ **Safety Features:**

1. **Transaction Wrapping** ✅
   - All operations in DB transaction
   - If any step fails, nothing is deleted

2. **Logging** ✅
   - Logs deletion count
   - Records who performed action
   - Timestamps everything

3. **Confirmation Required** ✅
   - Must type exact text
   - Then browser confirm
   - Two-step process

4. **Selective Deletion** ✅
   - Only legacy imports deleted
   - New app data preserved
   - System accounts preserved

---

## 🚀 **You're Ready!**

**Testing Workflow:**
1. ✅ Import legacy CSV
2. ✅ Test all flows
3. ✅ Clear legacy data (if needed)
4. ✅ Re-import with fixes
5. ✅ Repeat until perfect
6. ✅ Final import for production

**Features Available:**
- ✅ Import legacy CSV
- ✅ Clear legacy data
- ✅ Re-import safely
- ✅ Test unlimited times

**No more worries about:**
- ❌ Duplicate data
- ❌ Wrong balances
- ❌ Old test data
- ❌ Manual database cleanup

**Everything is now ready for thorough testing!** 🎉

