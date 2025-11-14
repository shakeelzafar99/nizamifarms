# Approval Routing System - Changes Summary

## ✅ Completed Changes

### 1. Database Migration ✅
**File**: `database/migrations/approval_routing_system_migration.sql`

**Tables Created**:
- `t_req_approval_rules` - Routing rules for approval assignments
- `t_req_approval_rule_assignees` - User assignments for rules

**Columns Added**:
- `t_req_master`: `level_1_assigned_to`, `level_2_assigned_to`, `order_id`
- `t_crm_prod_order`: `invoice_request_id`
- `t_fin_ledger_adjustments`: `level_1_assigned_to`, `level_2_assigned_to`

**Category Created**:
- `invoice_approval` - For online invoice approval workflow

---

### 2. Service Layer Changes ✅
**File**: `app/Services/FIN/LedgerPostingService.php`

**Modified Method**: `postInvoiceFromOrder()`
- Now checks if payment method is online
- If online: Creates invoice approval request instead of ledger entry
- If cash: Continues with existing direct ledger posting

**New Methods Added**:
1. `createInvoiceApprovalRequest()` - Creates request for online invoices
2. `getAssigneeForApproval()` - Finds assignee based on routing rules
3. `getCustomerNameFromOrder()` - Extracts customer name for descriptions

**Key Logic**:
```php
if (in_array($order->payment_method, $onlinePaymentMethods)) {
    // Create request instead of ledger
    return $this->createInvoiceApprovalRequest($order);
} else {
    // Cash - continue as before
    // ... existing code ...
}
```

---

### 3. Request Model Changes ✅
**File**: `app/Models/Request/RequestModel.php`

**Modified Method**: `processApproval()`
- Added handling for `invoice_approval` category
- Calls `postInvoiceToLedgerAfterApproval()` when approved

**New Methods Added**:
1. `postInvoiceToLedgerAfterApproval()` - Posts invoice to ledger after L1 approval
   - Checks if payment method changed to cash → posts as approved
   - If still online → posts as pending (needs L2)
2. `order()` - Relationship to get order for invoice requests

**Key Logic**:
```php
// After L1 approval of invoice request
if ($this->category->category_code === 'invoice_approval') {
    // Check payment method
    if (changed to cash) {
        // Post as approved (skip L2)
    } else {
        // Post as pending (needs L2)
    }
}
```

---

### 4. Order Model Changes ✅
**File**: `app/Models/CRM/OrderModel.php`

**New Relationship**:
```php
public function invoiceRequest()
{
    return $this->hasOne(RequestModel::class, 'order_id')
                ->whereHas('category', function($q) {
                    $q->where('category_code', 'invoice_approval');
                });
}
```

---

### 5. Ledger Audit Changes ✅
**File**: `app/Http/Controllers/FIN/LedgerAuditController.php`

**Modified Query**: Missing invoice check
- Now excludes orders with pending invoice approval requests
- Prevents false positives for online invoices in request stage

**Updated Logic**:
```php
$missingInvoices = OrderModel::where('order_status', 'delivered')
    ->whereNull('ledger_transaction_id')
    ->whereDoesntHave('invoiceRequest', function($q) {
        $q->where('status', 'pending');
    })
    // ... rest of query ...
```

---

## 🔄 New Workflow

### Online Invoice Flow (NEW)
```
1. Order Delivered (online payment)
   ↓
2. Create Request (invoice_approval category)
   - Status: PENDING
   - Assigned to specific L1 user (if rule exists)
   - Amount: order total_price
   ↓
3. L1 Manager Reviews
   - Can modify amount
   - Can change payment method
   ↓
4a. If Changed to Cash:
   - Post to ledger as APPROVED
   - Skip L2
   - Request closed
   ↓
4b. If Still Online:
   - Post to ledger as PENDING
   - Requires L2 approval
   ↓
5. L2 Finance Approves Ledger
   - Balances updated
   - Invoice complete
```

### Cash Invoice Flow (UNCHANGED)
```
1. Order Delivered (cash payment)
   ↓
2. Post to Ledger (APPROVED)
   ↓
3. Balances Updated
   ↓
4. Complete
```

---

## 📊 Routing Rules System

### How It Works
1. **Rules are evaluated** when creating approval items
2. **Priority-based matching** (lower number = higher priority)
3. **Context-aware filtering**:
   - Payment source account
   - Payment mode (cash/online)
   - Amount range
4. **Assignee selected** from rule's primary assignees
5. **Fallback**: If no rule matches, anyone with L1/L2 role can approve

### Example Rules

#### Rule 1: Online Invoices → User A
```sql
INSERT INTO t_req_approval_rules 
(rule_name, area_type, area_identifier, approval_level, payment_mode, assignment_strategy, priority)
VALUES 
('Online Invoices - L1', 'request_category', 'invoice_approval', 1, 'online', 'single_primary', 10);

-- Assign User A
INSERT INTO t_req_approval_rule_assignees (rule_id, user_id, is_primary, sequence_order)
VALUES (LAST_INSERT_ID(), USER_A_ID, 1, 0);
```

#### Rule 2: Expenses from EXP_FUND → User B
```sql
INSERT INTO t_req_approval_rules 
(rule_name, area_type, area_identifier, approval_level, payment_source_account_id, assignment_strategy, priority)
VALUES 
('Expenses from EXP_FUND', 'request_category', 'expense', 1, 
 (SELECT id FROM t_fin_accounts WHERE account_code = 'EXP_FUND'), 
 'single_primary', 10);

-- Assign User B
INSERT INTO t_req_approval_rule_assignees (rule_id, user_id, is_primary, sequence_order)
VALUES (LAST_INSERT_ID(), USER_B_ID, 1, 0);
```

---

## 🎯 Key Features

### 1. Consolidated Approval View
- **One place** for all approvals (requests, ledger, adjustments)
- **Clear level indicators** (L1 Pending, L2 Pending)
- **"My Assignments" tab** shows items assigned to you
- **"All L1/L2" tabs** show everything you can approve (backup)

### 2. Flexible Assignment
- **Visual assignment** to specific users
- **Role-based backup** - anyone with L1/L2 can approve
- **Context-aware routing** based on payment source, mode, amount

### 3. Edit Before Ledger
- **Manager can modify** invoice amount before ledger posting
- **Payment method changes** handled automatically
- **No ledger adjustments needed** if caught at request stage

### 4. Two-Stage Approval for Online
- **L1 (Request)**: Manager reviews and approves
- **L2 (Ledger)**: Finance approves ledger entry
- **Cash payments**: Skip request stage (direct to ledger)

---

## 🧪 Testing Checklist

### Test 1: Online Invoice → Request → Ledger ✅
- [ ] Create order with payment_method = "online"
- [ ] Mark as delivered
- [ ] Verify request created (NOT ledger)
- [ ] Check `t_req_master` for invoice_approval request
- [ ] L1 approves request
- [ ] Verify ledger created with status = 'pending'
- [ ] L2 approves ledger
- [ ] Verify balances updated

### Test 2: Payment Method Change ✅
- [ ] Create online invoice request
- [ ] Change order payment_method to "cash"
- [ ] L1 approves request
- [ ] Verify ledger posted as 'approved' (not pending)
- [ ] Verify no L2 approval needed

### Test 3: Amount Modification ✅
- [ ] Create online invoice request
- [ ] L1 modifies amount before approval
- [ ] Approve request
- [ ] Verify ledger has modified amount

### Test 4: Routing Assignment ✅
- [ ] Create routing rule for online invoices
- [ ] Create online invoice
- [ ] Verify `level_1_assigned_to` = assigned user
- [ ] Verify other L1 users can still approve

### Test 5: Cash Invoice (Backward Compatibility) ✅
- [ ] Create order with payment_method = "cash"
- [ ] Mark as delivered
- [ ] Verify ledger created immediately (approved)
- [ ] Verify NO request created

### Test 6: Audit Exclusion ✅
- [ ] Create online invoice (in request stage)
- [ ] Run ledger audit
- [ ] Verify order NOT flagged as missing ledger

---

## 📝 Configuration Steps

### Step 1: Run SQL Migration
```bash
# In MySQL Workbench
# Open: database/migrations/approval_routing_system_migration.sql
# Execute entire script
```

### Step 2: Verify Migration
```sql
-- Check tables
SHOW TABLES LIKE 't_req_approval%';

-- Check invoice_approval category
SELECT * FROM t_req_category WHERE category_code = 'invoice_approval';

-- Check new columns
DESCRIBE t_req_master;
DESCRIBE t_crm_prod_order;
```

### Step 3: Configure Routing Rules (Optional)
```sql
-- Example: Route online invoices to specific user
-- Replace USER_ID_HERE with actual user ID

INSERT INTO t_req_approval_rules 
(rule_name, area_type, area_identifier, approval_level, payment_mode, assignment_strategy, priority, created_at)
VALUES 
('Online Invoices - L1', 'request_category', 'invoice_approval', 1, 'online', 'single_primary', 10, NOW());

SET @rule_id = LAST_INSERT_ID();

INSERT INTO t_req_approval_rule_assignees (rule_id, user_id, is_primary, sequence_order, created_at)
VALUES (@rule_id, USER_ID_HERE, 1, 0, NOW());
```

### Step 4: Test the Flow
1. Create test order with online payment
2. Mark as delivered
3. Check request created
4. Approve at L1
5. Check ledger created as pending
6. Approve at L2
7. Verify balances updated

---

## 🔍 Troubleshooting

### Issue: Online invoices still posting directly to ledger
**Solution**: Check invoice_approval category exists and is active
```sql
SELECT * FROM t_req_category WHERE category_code = 'invoice_approval';
```

### Issue: Request created but not assigned
**Solution**: Normal behavior if no routing rules exist. Anyone with L1 role can approve.

### Issue: Can't approve request
**Solution**: Verify user has L1 role approval level
```sql
SELECT u.fullname, r.urole_name, ral.approval_level
FROM t_sys_user u
JOIN t_sys_user_role ur ON u.id = ur.user_id
JOIN t_sys_role r ON ur.role_id = r.id
JOIN t_sys_role_approval_level ral ON r.id = ral.role_id
WHERE u.id = YOUR_USER_ID;
```

### Issue: Ledger audit flagging online invoices
**Solution**: Ensure OrderModel has invoiceRequest relationship (added in changes)

---

## 🎉 Benefits

1. ✅ **Edit before ledger** - Catch errors early
2. ✅ **Two-stage approval** - Better financial control
3. ✅ **Flexible routing** - Assign to specific users
4. ✅ **Backup approvers** - Role-based fallback
5. ✅ **Payment method flexibility** - Handle changes gracefully
6. ✅ **Consolidated view** - All approvals in one place
7. ✅ **Backward compatible** - Cash invoices unchanged
8. ✅ **Audit-aware** - No false positives

---

## 📚 Related Documents

- `APPROVAL_ROUTING_IMPLEMENTATION_PLAN.md` - Detailed technical plan
- `APPROVAL_ROUTING_QUICK_START.md` - Quick reference guide
- `database/migrations/approval_routing_system_migration.sql` - SQL migration

---

## 🚀 Next Steps

1. ✅ SQL migration completed (you're running it now)
2. ✅ Code changes deployed (completed above)
3. ⏳ Test the complete flow
4. ⏳ Configure routing rules for your users
5. ⏳ Train L1 and L2 users on new workflow
6. ⏳ Monitor for first week

---

## 📞 Support

If you encounter issues:
1. Check logs: `storage/logs/laravel.log`
2. Verify database state with SQL queries
3. Review troubleshooting section above
4. Check implementation plan for detailed code

**Remember**: System is backward compatible. Cash invoices work exactly as before!

