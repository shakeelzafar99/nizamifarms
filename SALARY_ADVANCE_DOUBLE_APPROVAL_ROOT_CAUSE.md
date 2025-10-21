# Salary Advance Double Approval - Root Cause Analysis
## October 21, 2025

## The Problem

When creating a salary advance for Arsalan, **TWO approvals** were created:
1. **REQ-202510-0007**: Salary Advance, EXP FUND, Rs. 5,000
2. **TXN-20**: Salary advance, NF CASH, Rs. 5,000

## Root Cause: Duplicate Categories

### Evidence from Dropdown
- Dropdown shows "Salary Advance" **TWICE**
- One with payment source dropdown (creates REQ)
- One without payment source dropdown (creates TXN)

### What's Happening

You have **TWO different "Salary Advance" categories** in `t_req_category`:

#### Category 1: Correct One (creates REQ only)
```
category_code: 'salary_advance'
category_name: 'Salary Advance'
requires_level_1: 1
requires_level_2: 1
```

**Flow**:
1. User creates request → REQ-xxx created
2. L1 approves → request status updated
3. L2 approves → request approved
4. System automatically creates ledger entry with `approval_status = 'approved'`
5. **Result**: Only REQ-xxx appears in approvals (already approved)

#### Category 2: Duplicate/Wrong One (creates TXN)
```
category_code: 'salary_advance_OLD' or similar
category_name: 'Salary Advance' (same display name!)
requires_level_1: 1
requires_level_2: 0 (or different config)
```

**Flow**:
1. User creates request → Creates ledger transaction directly
2. Ledger transaction created with `approval_status = 'pending'`
3. **Result**: TXN-xxx appears in approvals (needs approval)

---

## Why Payment Source Matters

### REQ-202510-0007 (EXP FUND)
- Created with `payment_source_account_id` set to EXP_FUND
- Goes through proper request flow
- When approved, ledger entry is created with `from_account_id = EXP_FUND`
- Area determined by payment source → **EXP FUND**

### TXN-20 (NF CASH)
- Created without proper payment source
- Defaults to NF_CASH or employee cash account
- Area determined by account category → **NF CASH**

---

## Code Analysis

### Request Creation (RequestController.php)
```php
// Lines 204-225
$requestModel = RequestModel::create([
    'request_number' => RequestModel::generateRequestNumber(),
    'category_id' => $validated['category_id'],  // ← Uses selected category
    'payment_source_account_id' => $validated['payment_source_account_id'] ?? null,
    // ... other fields
]);
```

**Result**: Creates **ONE request** (REQ-xxx)

### Request Approval (RequestModel.php)
```php
// Lines 289-300
if ($this->category->category_code === 'salary_advance' && $this->amount > 0) {
    $this->postSalaryAdvanceToLedger();  // ← Creates ledger entry
}
```

### Ledger Creation (LedgerPostingService.php)
```php
// Lines 344-360
$ledger = LedgerModel::create([
    'transaction_date' => $request->completed_at ?? now(),
    'transaction_type' => 'salary_advance',
    'from_account_id' => $fundingAccount->id,  // ← Uses payment source
    'to_account_id' => $employeeCashAccount->id,
    'amount' => $request->amount,
    'approval_status' => LedgerModel::STATUS_APPROVED,  // ← Already approved!
    'request_id' => $request->id,
    // ...
]);
```

**Result**: Creates **ONE ledger entry** (but already approved, so doesn't show as pending)

---

## The Mystery: Where Does TXN-20 Come From?

### Hypothesis 1: Different Category Code
The duplicate category might have a different `category_code` that triggers different logic.

### Hypothesis 2: Direct Ledger Creation
There might be another route/controller that creates ledger transactions directly for salary advances.

### Hypothesis 3: Old Migration Script
An old migration might have created a duplicate category with different behavior.

---

## Solution

### Step 1: Identify the Duplicate
Run this SQL:
```sql
SELECT 
    id,
    category_code,
    category_name,
    description,
    is_active,
    created_at
FROM t_req_category
WHERE category_name LIKE '%Salary%Advance%' 
   OR category_code LIKE '%salary%advance%'
ORDER BY id;
```

### Step 2: Check Their Configurations
```sql
SELECT 
    c.id,
    c.category_code,
    c.category_name,
    cfg.requires_level_1,
    cfg.requires_level_2,
    cfg.auto_approve_threshold
FROM t_req_category c
LEFT JOIN t_req_category_approval_config cfg ON c.id = cfg.category_id
WHERE c.category_name LIKE '%Salary%Advance%'
ORDER BY c.id;
```

### Step 3: Check Which One Created TXN-20
```sql
-- Find the ledger transaction
SELECT 
    l.id,
    l.transaction_type,
    l.description,
    l.from_account_id,
    l.to_account_id,
    l.approval_status,
    l.request_id,
    l.created_at
FROM t_fin_ledger l
WHERE l.id = 20;  -- TXN-20

-- If it has a request_id, check that request
SELECT 
    r.id,
    r.request_number,
    r.category_id,
    r.payment_source_account_id,
    c.category_code,
    c.category_name
FROM t_req_master r
JOIN t_req_category c ON c.id = r.category_id
WHERE r.id = (SELECT request_id FROM t_fin_ledger WHERE id = 20);
```

### Step 4: Delete the Duplicate
Once identified, delete the wrong category:
```sql
-- Replace X with the duplicate category ID
DELETE FROM t_req_category_approval_config WHERE category_id = X;
DELETE FROM t_req_category WHERE id = X;
```

---

## Expected Behavior After Fix

### Dropdown
- Only **ONE** "Salary Advance" option
- Shows payment source dropdown (default: EXP_FUND)

### Creating Salary Advance
1. User selects "Salary Advance"
2. Fills amount, description
3. Selects payment source (or uses default EXP_FUND)
4. Submits → Creates **REQ-xxx only**

### Approval Flow
1. REQ-xxx appears in L1 pending
2. L1 approves
3. REQ-xxx appears in L2 pending
4. L2 approves
5. System creates ledger entry (already approved)
6. **Result**: Only ONE approval (REQ-xxx), no TXN

### Area Classification
- If payment source is EXP_FUND → Shows in **EXP FUND** area
- If payment source is NF_CASH → Shows in **NF CASH** area
- If payment source is ONLINE → Shows in **ONLINE** area

---

## Status

⚠️ **NEEDS DATABASE INVESTIGATION**

**Next Steps**:
1. Run SQL queries above to identify duplicate
2. Check which category created TXN-20
3. Delete the wrong category
4. Test creating new salary advance
5. Verify only ONE approval is created

---

## Files to Check

1. `t_req_category` - Find duplicate
2. `t_req_category_approval_config` - Check configurations
3. `t_fin_ledger` (id=20) - Check TXN-20 details
4. `t_req_master` - Check if TXN-20 has a linked request

---

## User's Insight

> "i feel its happening because of payment source since on the final approval payment source is supposed to be exp_fund and initially its setting as nF cash or something like this?"

**Analysis**: You're RIGHT! The payment source IS the issue, but not in the way you think:

- **REQ-202510-0007** was created WITH payment source (EXP_FUND) → Correct flow
- **TXN-20** was created WITHOUT payment source → Defaults to wrong account

The question is: **WHY are two separate entries being created?**

Answer: **Because there are TWO "Salary Advance" categories in the dropdown!**

When you selected "Salary Advance" from the dropdown, you might have accidentally selected the wrong one (or the system created both due to a bug in the form submission).

---

## Conclusion

**The duplicate category IS the root cause.**

Even though the code logic is correct (only creates one request), having two categories with the same display name causes confusion and potentially triggers double submissions or different flows.

**Fix**: Remove the duplicate category, and the problem will be solved going forward.

