# Approvals System - Complete Testing Guide

## Overview
This guide covers testing the complete approval system across both web and mobile platforms, ensuring all features work correctly with the new L1/L2 approval statuses.

## Pre-Testing Setup

### 1. Database Migration
Ensure the production migration has been run:
```sql
-- File: database/migrations/PRODUCTION_approval_system_migration.sql
-- This creates:
-- - t_req_approval_rules table
-- - t_req_approval_rule_assignees table
-- - Updates t_fin_ledger.approval_status ENUM
-- - Adds invoice_approval category
```

### 2. Request Settings Configuration
Navigate to: **Requests → Request Settings**

Configure routing rules for testing:
- **Leave Request**: Assign L1 approver (e.g., Manager A)
- **Expense Reimbursement**: 
  - EXP_FUND → L1: User A, L2: User B
  - NF_CASH → L1: User C
- **Invoice Approval** (Ledger flow):
  - ONLINE → L1: User D, L2: User E
- **Employee Deposit** (Ledger flow):
  - NF_CASH → L1: User F

## Web Application Testing

### Test 1: Request Settings UI
**Objective**: Verify routing configuration works correctly

1. Open Request Settings page
2. Expand each category card
3. Verify:
   - ✅ "Request flow" or "Ledger flow" badge is shown
   - ✅ Can select multiple users for L1
   - ✅ Can select multiple users for L2
   - ✅ Can select payment source (where applicable)
   - ✅ Can add multiple rules per category
   - ✅ Save button works
   - ✅ Rules persist after page refresh
   - ✅ Correct users and payment sources are pre-selected

**Expected**: All categories show correct flow type, rules save successfully

---

### Test 2: Online Invoice Flow (Ledger-Based)
**Objective**: Verify online invoices create ledger entries with pending_l1 status

**Steps**:
1. Create an order with payment method = "Online"
2. Mark order as delivered
3. Check Approvals Dashboard
4. Verify:
   - ✅ Invoice appears in L1 Pending
   - ✅ Shows in assigned user's "My assignments"
   - ✅ Description shows customer name and order number
   - ✅ Amount is correct

**Database Check**:
```sql
SELECT id, transaction_type, approval_status, description, amount
FROM t_fin_ledger
WHERE transaction_type = 'invoice'
ORDER BY id DESC LIMIT 5;
```
**Expected**: `approval_status = 'pending_l1'`

---

### Test 3: L1 Approval (Online Invoice)
**Objective**: Verify L1 approval transitions to L2 pending

**Steps**:
1. Log in as L1 approver
2. Open Approvals Dashboard
3. Filter by "My assignments"
4. Click "View & Approve" on an online invoice
5. Verify:
   - ✅ Modal opens (not new tab)
   - ✅ No sidebar/header in modal
   - ✅ Customer name and order number shown
   - ✅ Click "Approve" shows loading spinner
6. Approve the invoice
7. Verify:
   - ✅ Success message appears
   - ✅ Modal closes automatically
   - ✅ Dashboard refreshes
   - ✅ Item moves from L1 to L2 Pending
   - ✅ Shows in L2 assignee's "My assignments"

**Database Check**:
```sql
SELECT id, approval_status, approved_by_level_1, approved_at_level_1
FROM t_fin_ledger
WHERE id = [INVOICE_ID];
```
**Expected**: `approval_status = 'pending_l2'`, `approved_by_level_1` is set

---

### Test 4: L2 Approval (Online Invoice)
**Objective**: Verify L2 approval fully approves and posts to ledger

**Steps**:
1. Log in as L2 approver
2. Open Approvals Dashboard
3. Filter by L2 Pending
4. Approve the invoice
5. Verify:
   - ✅ Item disappears from dashboard
   - ✅ Account balances are updated
   - ✅ Invoice shows in NF Ledger as "Approved"

**Database Check**:
```sql
SELECT id, approval_status, approved_by_level_2, approved_at_level_2
FROM t_fin_ledger
WHERE id = [INVOICE_ID];
```
**Expected**: `approval_status = 'approved'`, `approved_by_level_2` is set

---

### Test 5: Employee Deposit (Ledger-Based)
**Objective**: Verify employee deposits follow approval flow

**Steps**:
1. Navigate to Employee Cash page
2. Create a deposit for an employee
3. Verify:
   - ✅ Deposit appears in Approvals Dashboard (L1 Pending)
   - ✅ Shows in assigned user's "My assignments"
   - ✅ Description is clear
4. Approve at L1
5. If L2 is configured:
   - ✅ Moves to L2 Pending
   - ✅ Shows in L2 assignee's "My assignments"
6. Approve at L2 (if applicable)
7. Verify:
   - ✅ Deposit is fully approved
   - ✅ Employee balance is updated

---

### Test 6: Request Flow (Leave, Expense)
**Objective**: Verify traditional request flow still works

**Steps**:
1. Create a leave request
2. Verify:
   - ✅ Appears in Approvals Dashboard (L1 Pending)
   - ✅ Shows in assigned user's "My assignments"
3. Approve at L1
4. If L2 is configured:
   - ✅ Moves to L2 Pending
5. Approve at L2 (if applicable)
6. Verify:
   - ✅ Request is fully approved
   - ✅ No ledger entry created (for leave)

**Repeat for**:
- Expense Reimbursement (with different payment sources)
- Salary Advance
- Short Cash

---

### Test 7: Assignee Filter
**Objective**: Verify virtual assignment and filtering

**Steps**:
1. Open Approvals Dashboard
2. Select a user from "Assigned To" dropdown
3. Verify:
   - ✅ Only items assigned to that user are shown
   - ✅ Summary cards update to show filtered counts/amounts
   - ✅ Area cards update (if level is selected)
4. Clear filter
5. Verify:
   - ✅ All items are shown again
   - ✅ Summary cards revert to overall totals

---

### Test 8: Historical Data
**Objective**: Verify virtual assignment works for old data

**Steps**:
1. Find an old pending ledger entry (created before routing rules)
2. Configure a routing rule for that category/payment source
3. Refresh Approvals Dashboard
4. Verify:
   - ✅ Old entry now shows assigned user
   - ✅ Appears in assignee's "My assignments"

---

### Test 9: Payment Method Change
**Objective**: Verify payment method change during approval

**Steps**:
1. Create an online invoice
2. During L1 approval, change payment method to "Cash"
3. Verify:
   - ✅ Payment method updates
   - ✅ Routing re-evaluates for L2
   - ✅ Correct L2 assignee is set

---

### Test 10: Ledger Audit
**Objective**: Verify audit doesn't flag pending invoices

**Steps**:
1. Create several online invoices (some pending, some approved)
2. Navigate to Ledger Audit page
3. Verify:
   - ✅ Pending invoices are NOT flagged as missing
   - ✅ Only truly missing invoices are flagged
   - ✅ Approved invoices are correctly matched

---

## Mobile Application Testing

### Test 11: Mobile API Endpoint
**Objective**: Verify mobile API returns correct data

**Using Postman or similar**:
```
GET /api/approvals
Headers:
  Authorization: Bearer [TOKEN]
  Accept: application/json

Query Params:
  level: l1 (optional)
  area: online (optional)
  assignee_id: 5 (optional)
```

**Verify Response**:
```json
{
  "success": true,
  "data": {
    "items": [...],
    "count": 5,
    "total_amount": 15000,
    "summaries": {...},
    "users": [...],
    "has_level_1_rights": true,
    "has_level_2_rights": false,
    "last_synced": "2025-11-15T10:30:00Z"
  }
}
```

---

### Test 12: Mobile Side Menu
**Objective**: Verify Approvals option appears correctly

**Steps**:
1. Open mobile app in Store Mode
2. Tap hamburger menu
3. Verify:
   - ✅ "Approvals" option is visible (if user has permissions)
   - ✅ Shows checkmark emoji and description
   - ✅ Tapping navigates to Approvals screen
   - ✅ Menu closes automatically

**Test with different users**:
- User with L1 only: ✅ Should see Approvals
- User with L2 only: ✅ Should see Approvals
- User with neither: ❌ Should NOT see Approvals

---

### Test 13: Mobile Approvals Screen
**Objective**: Verify UI displays correctly

**Steps**:
1. Navigate to Approvals screen
2. Verify:
   - ✅ Header shows "Approvals" and sync status
   - ✅ Summary cards are scrollable horizontally
   - ✅ L1 card shows correct count and amount (blue)
   - ✅ L2 card shows correct count and amount (yellow)
   - ✅ "Assigned To" dropdown has correct users
   - ✅ Items list shows all pending items
   - ✅ Each item card shows:
     - Level badge (L1/L2)
     - Category badge with color
     - Amount
     - Description
     - Date and area
     - Assigned user name
     - "View & Approve" button

---

### Test 14: Mobile Filtering
**Objective**: Verify filters work correctly

**Steps**:
1. Tap L1 summary card
2. Verify:
   - ✅ Card highlights with border
   - ✅ Area cards appear
   - ✅ Items list filters to L1 only
3. Tap an area card
4. Verify:
   - ✅ Area card highlights
   - ✅ Items list filters to L1 + that area
5. Select a user from "Assigned To" dropdown
6. Verify:
   - ✅ Items list filters to that user
   - ✅ Summary cards update to show filtered counts
7. Tap "Clear All Filters"
8. Verify:
   - ✅ All filters reset
   - ✅ All items shown
   - ✅ Summary cards show overall totals

---

### Test 15: Mobile Approval Action
**Objective**: Verify approval opens in browser

**Steps**:
1. Tap "View & Approve" on an item
2. Verify:
   - ✅ Device browser opens
   - ✅ Approval page loads correctly
   - ✅ Can approve/reject
3. Approve the item
4. Return to mobile app
5. Pull to refresh
6. Verify:
   - ✅ Item disappears or moves to next level
   - ✅ Summary cards update

---

### Test 16: Mobile Auto-Sync
**Objective**: Verify 60-second auto-sync works

**Steps**:
1. Open Approvals screen
2. Note the sync status in header
3. Wait 60 seconds
4. Verify:
   - ✅ Sync status updates
   - ✅ New items appear (if any)
   - ✅ No loading spinner (silent sync)
5. Approve an item on web
6. Wait for next sync on mobile
7. Verify:
   - ✅ Item disappears from mobile list

---

### Test 17: Mobile Pull-to-Refresh
**Objective**: Verify manual refresh works

**Steps**:
1. Pull down on the items list
2. Verify:
   - ✅ Refresh indicator appears
   - ✅ Data reloads
   - ✅ Sync status updates

---

### Test 18: Mobile Empty State
**Objective**: Verify empty state displays correctly

**Steps**:
1. Approve all pending items
2. Return to Approvals screen
3. Verify:
   - ✅ Checkmark emoji appears
   - ✅ "No pending approvals" message
   - ✅ Encouraging subtext

---

## Integration Testing

### Test 19: Web-Mobile Consistency
**Objective**: Verify web and mobile show same data

**Steps**:
1. Open Approvals Dashboard on web
2. Open Approvals screen on mobile
3. Compare:
   - ✅ Same items appear on both
   - ✅ Same counts in summary cards
   - ✅ Same amounts
   - ✅ Same assigned users

---

### Test 20: Multi-Device Sync
**Objective**: Verify changes sync across devices

**Steps**:
1. Open Approvals on Device A (mobile)
2. Open Approvals on Device B (web)
3. Approve an item on Device B
4. Wait for sync on Device A (60 seconds or pull-to-refresh)
5. Verify:
   - ✅ Item disappears from Device A
   - ✅ Summary cards update on Device A

---

## Edge Cases & Error Handling

### Test 21: No Permissions
**Objective**: Verify users without permissions can't access

**Steps**:
1. Log in as user without L1 or L2 permissions
2. Verify:
   - ✅ "Approvals" option NOT in side menu (mobile)
   - ✅ Direct API call returns 403 error
   - ✅ Web dashboard redirects or shows error

---

### Test 22: Network Error
**Objective**: Verify graceful error handling

**Steps**:
1. Turn off network
2. Try to load Approvals on mobile
3. Verify:
   - ✅ Error message appears
   - ✅ Can retry with pull-to-refresh
4. Turn on network
5. Pull to refresh
6. Verify:
   - ✅ Data loads successfully

---

### Test 23: Large Data Set
**Objective**: Verify performance with many items

**Steps**:
1. Create 50+ pending items
2. Open Approvals Dashboard/Screen
3. Verify:
   - ✅ Loads within reasonable time
   - ✅ Scrolling is smooth
   - ✅ Filters work correctly
   - ✅ Summary cards calculate correctly

---

## Performance Testing

### Test 24: Server Load
**Objective**: Verify sync doesn't overwhelm server

**Monitor server logs**:
1. Have 10+ mobile users with Approvals screen open
2. Monitor API calls over 5 minutes
3. Verify:
   - ✅ Requests are staggered (not all at once)
   - ✅ Server response time is acceptable
   - ✅ No 429 (Too Many Requests) errors

---

## Regression Testing

### Test 25: Existing Flows
**Objective**: Verify old functionality still works

**Test these existing features**:
- ✅ Cash invoice posting (no approval)
- ✅ Manual ledger entries
- ✅ Vendor payments
- ✅ Employee salary posting
- ✅ Ledger adjustments
- ✅ Request creation by employees
- ✅ Attendance approval (if separate)

---

## Sign-Off Checklist

### Web Application
- [ ] Request Settings UI works correctly
- [ ] Online invoice flow creates pending_l1 ledger entries
- [ ] L1 approval transitions to pending_l2
- [ ] L2 approval fully approves and posts
- [ ] Employee deposits follow approval flow
- [ ] Traditional requests (leave, expense) still work
- [ ] Assignee filter works with virtual assignment
- [ ] Summary cards update when filtered
- [ ] Historical data shows correct assignees
- [ ] Payment method change re-routes correctly
- [ ] Ledger audit doesn't flag pending invoices
- [ ] Modal approval works (no new tab)
- [ ] Customer names appear correctly

### Mobile Application
- [ ] API endpoint returns correct data
- [ ] Side menu shows Approvals option (with permissions)
- [ ] Approvals screen displays correctly
- [ ] Summary cards are scrollable and interactive
- [ ] Filters work correctly (level, area, assignee)
- [ ] "View & Approve" opens in browser
- [ ] 60-second auto-sync works
- [ ] Pull-to-refresh works
- [ ] Empty state displays correctly
- [ ] Web-mobile consistency verified
- [ ] Multi-device sync works
- [ ] Error handling is graceful

### Integration
- [ ] Web and mobile show same data
- [ ] Routing rules apply to both platforms
- [ ] Virtual assignment works across platforms
- [ ] Approvals sync in real-time
- [ ] No existing functionality broken

---

## Troubleshooting

### Issue: Items not showing in "My assignments"
**Check**:
1. Routing rules are configured in Request Settings
2. User has correct permissions (L1 or L2)
3. Virtual assignment logic is correct (check `ApprovalController`)
4. Payment source normalization is working

### Issue: L1 approval fully approves instead of going to L2
**Check**:
1. L2 is configured in Request Settings for that category
2. `LedgerController::approve()` checks for L2 requirement
3. Database `approval_status` is set to `pending_l2`

### Issue: Mobile not syncing
**Check**:
1. User has network connection
2. Auth token is valid
3. API endpoint is accessible
4. Sync interval is running (check console logs)

### Issue: Summary cards not updating
**Check**:
1. Backend returns `summaries` in response when assignee filter is applied
2. Frontend `updateSummaryCards()` function is called
3. `window.originalSummaries` is stored on page load

---

**Testing Complete**: Date: ___________
**Tested By**: ___________
**Sign-Off**: ___________

