# Approval Routing System - Quick Start Guide

## 🚀 Step-by-Step Implementation

### Step 1: Run SQL Migration ✅ DO THIS FIRST

**File**: `database/migrations/approval_routing_system_migration.sql`

**How to run**:
1. Open MySQL Workbench
2. Connect to your database
3. Open the SQL file
4. Execute the entire script
5. Check for "✅ Migration completed successfully!" message

**What it does**:
- Creates `t_req_approval_rules` table
- Creates `t_req_approval_rule_assignees` table
- Adds `level_1_assigned_to`, `level_2_assigned_to` columns to requests
- Adds `order_id` column to requests
- Adds `invoice_request_id` column to orders
- Creates/updates `invoice_approval` category
- Adds assignee tracking to ledger adjustments

---

### Step 2: Verify Migration

Run these queries to confirm:

```sql
-- Check tables exist
SHOW TABLES LIKE 't_req_approval%';

-- Check new columns in requests
DESCRIBE t_req_master;

-- Check invoice_approval category
SELECT * FROM t_req_category WHERE category_code = 'invoice_approval';
```

---

### Step 3: Configure Initial Routing Rules (Optional)

You can configure rules via UI later, but here are SQL examples:

#### Example 1: Route online invoices to specific user
```sql
-- Get user ID first
SELECT id, fullname FROM t_sys_user WHERE fullname LIKE '%YourName%';

-- Create rule (replace USER_ID_HERE)
INSERT INTO t_req_approval_rules 
(rule_name, area_type, area_identifier, approval_level, payment_mode, assignment_strategy, priority, created_at)
VALUES 
('Online Invoices - L1', 'request_category', 'invoice_approval', 1, 'online', 'single_primary', 10, NOW());

-- Get the rule ID
SET @rule_id = LAST_INSERT_ID();

-- Assign user to rule (replace USER_ID_HERE)
INSERT INTO t_req_approval_rule_assignees (rule_id, user_id, is_primary, sequence_order, created_at)
VALUES (@rule_id, USER_ID_HERE, 1, 0, NOW());
```

#### Example 2: Route expenses from EXP_FUND to specific user
```sql
-- Get account ID
SELECT id FROM t_fin_accounts WHERE account_code = 'EXP_FUND';

-- Create rule (replace USER_ID_HERE)
INSERT INTO t_req_approval_rules 
(rule_name, area_type, area_identifier, approval_level, payment_source_account_id, assignment_strategy, priority, created_at)
VALUES 
('Expenses from EXP_FUND - L1', 'request_category', 'expense', 1, 
 (SELECT id FROM t_fin_accounts WHERE account_code = 'EXP_FUND'), 
 'single_primary', 10, NOW());

SET @rule_id = LAST_INSERT_ID();

INSERT INTO t_req_approval_rule_assignees (rule_id, user_id, is_primary, sequence_order, created_at)
VALUES (@rule_id, USER_ID_HERE, 1, 0, NOW());
```

---

### Step 4: Code Changes

**IMPORTANT**: Review the implementation plan document for detailed code changes.

**Key files to modify**:
1. `app/Services/FIN/LedgerPostingService.php` - Add online invoice → request logic
2. `app/Models/Request/RequestModel.php` - Add invoice posting after approval
3. `app/Models/CRM/OrderModel.php` - Add invoiceRequest relationship
4. `app/Http/Controllers/FIN/LedgerAuditController.php` - Exclude online invoices in request stage

**See**: `APPROVAL_ROUTING_IMPLEMENTATION_PLAN.md` for complete code snippets

---

## 📊 Understanding the New Flow

### Before (Current)
```
Online Invoice:
Order Delivered → Ledger (PENDING) → L2 Approval → Balances Updated
```

### After (New)
```
Online Invoice:
Order Delivered → Request (PENDING) → L1 Approval → Ledger (PENDING) → L2 Approval → Balances Updated
                                           ↓
                                    Can modify amount
                                    Can change to cash (auto-posts)
```

### Cash Invoice (Unchanged)
```
Order Delivered → Ledger (APPROVED) → Balances Updated
```

---

## 🎯 Key Benefits

1. **Edit before ledger**: Manager can modify invoice amount or payment method before it hits the ledger
2. **Two-stage approval**: Request approval (L1) + Ledger approval (L2) for online payments
3. **Flexible routing**: Assign specific users to handle specific types of approvals
4. **Backup approvers**: Any L1/L2 role member can still approve if assigned user is unavailable
5. **Payment method flexibility**: Change from online to cash during approval → auto-posts to ledger

---

## 🔍 Routing Rules Explained

### Rule Matching
Rules are evaluated in **priority order** (lower number = higher priority).

**Example**:
```
Rule 1 (Priority 10): Expenses from EXP_FUND → User A
Rule 2 (Priority 20): All expenses → User B
```
If expense is from EXP_FUND, Rule 1 matches and assigns to User A.

### Assignment Strategies
1. **single_primary**: Assign to one primary user (shows in "My Assignments")
2. **round_robin**: Rotate through assignees (future enhancement)
3. **all_can_act**: Show to all assignees (future enhancement)

### Filters
Rules can filter by:
- **Payment source**: `payment_source_account_id` (e.g., EXP_FUND, NF_CASH)
- **Payment mode**: `payment_mode` (cash or online)
- **Amount range**: `min_amount`, `max_amount`

---

## 🧪 Testing Scenarios

### Test 1: Online Invoice → Request → Ledger
1. Create order with payment method = "online"
2. Mark order as delivered
3. **Expected**: Request created (NOT ledger entry)
4. Check `t_req_master` for new request with `category_code = 'invoice_approval'`
5. L1 user approves request
6. **Expected**: Ledger entry created with `approval_status = 'pending'`
7. L2 user approves ledger
8. **Expected**: Balances updated

### Test 2: Payment Method Change
1. Create online invoice request (as above)
2. Before L1 approval, change order payment method to "cash"
3. L1 user approves request
4. **Expected**: Ledger posted with `approval_status = 'approved'` (not pending)

### Test 3: Amount Modification
1. Create online invoice request
2. L1 user modifies amount before approving
3. Approve request
4. **Expected**: Ledger has modified amount

### Test 4: Routing Assignment
1. Create routing rule for online invoices → User A
2. Create online invoice
3. **Expected**: `level_1_assigned_to = User A's ID`
4. User B (also L1) can still see and approve it

### Test 5: Cash Invoice (Backward Compatibility)
1. Create order with payment method = "cash"
2. Mark as delivered
3. **Expected**: Ledger entry created immediately (approved)
4. **Expected**: NO request created

---

## 🔧 Troubleshooting

### Issue: Online invoices still posting directly to ledger
**Solution**: Check if `invoice_approval` category exists and is active:
```sql
SELECT * FROM t_req_category WHERE category_code = 'invoice_approval';
```

### Issue: Request created but not assigned to anyone
**Solution**: Check if routing rules exist:
```sql
SELECT * FROM t_req_approval_rules WHERE area_identifier = 'invoice_approval';
```
If no rules, it falls back to "anyone with L1 role can approve"

### Issue: Ledger audit flagging online invoices as missing
**Solution**: Ensure audit controller excludes orders with pending invoice requests

### Issue: Can't approve request
**Solution**: Verify user has L1 role approval level:
```sql
SELECT u.fullname, r.urole_name, ral.approval_level
FROM t_sys_user u
JOIN t_sys_user_role ur ON u.id = ur.user_id
JOIN t_sys_role r ON ur.role_id = r.id
JOIN t_sys_role_approval_level ral ON r.id = ral.role_id
WHERE u.id = YOUR_USER_ID;
```

---

## 📝 Quick Reference: SQL Queries

### View all routing rules
```sql
SELECT 
    r.id,
    r.rule_name,
    r.area_type,
    r.area_identifier,
    r.approval_level,
    r.priority,
    GROUP_CONCAT(u.fullname ORDER BY ra.is_primary DESC SEPARATOR ', ') as assignees
FROM t_req_approval_rules r
LEFT JOIN t_req_approval_rule_assignees ra ON r.id = ra.rule_id
LEFT JOIN t_sys_user u ON ra.user_id = u.id
WHERE r.is_active = 1
GROUP BY r.id
ORDER BY r.priority;
```

### View pending invoice approval requests
```sql
SELECT 
    r.id,
    r.request_number,
    r.amount,
    r.status,
    r.level_1_status,
    o.order_number,
    o.payment_method,
    u.fullname as assigned_to
FROM t_req_master r
JOIN t_crm_prod_order o ON r.order_id = o.id
LEFT JOIN t_sys_user u ON r.level_1_assigned_to = u.id
WHERE r.category_id = (SELECT id FROM t_req_category WHERE category_code = 'invoice_approval')
AND r.status = 'pending';
```

### View online invoices in ledger (pending L2)
```sql
SELECT 
    l.id,
    l.description,
    l.amount,
    l.approval_status,
    r.request_number,
    o.order_number
FROM t_fin_ledger l
JOIN t_crm_prod_order o ON l.order_id = o.id
LEFT JOIN t_req_master r ON l.request_id = r.id
WHERE l.transaction_type = 'invoice'
AND l.mode = 'online'
AND l.approval_status = 'pending';
```

---

## 🎓 User Training Points

1. **For Managers (L1)**:
   - Online invoices now appear in "Requests" first
   - You can modify amount or payment method before approving
   - Changing to cash auto-posts to ledger (no L2 needed)
   - "My Assignments" shows items assigned to you
   - You can still approve any L1 item (backup)

2. **For Finance (L2)**:
   - Online invoices appear in Ledger after L1 approval
   - Approve to update account balances
   - Cash invoices skip request stage (direct to ledger)

3. **For Admins**:
   - Configure routing rules in Request Settings
   - Assign specific users to handle specific approval types
   - Monitor assignments and adjust rules as needed

---

## ✅ Post-Implementation Checklist

- [ ] SQL migration executed successfully
- [ ] Invoice_approval category exists and is active
- [ ] Routing tables created (t_req_approval_rules, t_req_approval_rule_assignees)
- [ ] New columns added to requests and orders
- [ ] Code changes deployed
- [ ] Test online invoice flow (order → request → ledger)
- [ ] Test payment method change (online → cash)
- [ ] Test cash invoice (unchanged behavior)
- [ ] Configure initial routing rules
- [ ] Train L1 and L2 users
- [ ] Monitor for first week

---

## 📞 Support

If you encounter issues:
1. Check troubleshooting section above
2. Review implementation plan document
3. Check logs: `storage/logs/laravel.log`
4. Verify database state with SQL queries above

**Remember**: The system is backward compatible. If issues arise, you can disable online invoice routing by deactivating the `invoice_approval` category.

