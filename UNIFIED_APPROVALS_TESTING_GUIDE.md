# Unified Approvals System - Quick Testing Guide

## 🚀 Quick Start

1. **Navigate to Approvals Dashboard**
   ```
   URL: http://127.0.0.1:8000/approvals
   ```

2. **What You Should See**
   - 4 cards at the top (L1 Pending, L2 Pending, Approved, Rejected)
   - Each card shows count and total amount
   - Table below with "Select a filter above to view approvals" message

---

## ✅ Test Scenarios

### **Scenario 1: Basic L1 Filtering**
1. Click "L1 PENDING" card
2. **Expected**:
   - Card gets blue border and light blue background
   - Layer 2 cards (EXP_FUND, NF_CASH, ONLINE, OTHERS) slide down
   - Table loads with all L1 pending items
   - Title shows "Level 1 Pending"

### **Scenario 2: Area Filtering**
1. With L1 selected, click "EXP_FUND" area card
2. **Expected**:
   - Area card gets blue border
   - Table filters to show only EXP_FUND items
   - Title shows "Level 1 Pending > EXP FUND"
   - Item count and amount update

### **Scenario 3: Toggle Filters**
1. Click the same L1 card again
2. **Expected**:
   - Filter clears
   - Layer 2 cards hide
   - Table shows "Select a filter" message
   - All active states removed

### **Scenario 4: Approved Items**
1. Click "APPROVED" card
2. **Expected**:
   - Layer 2 cards stay hidden (no area breakdown for approved)
   - Table shows approved items from last 30 days
   - Title shows "Approved"

### **Scenario 5: Clear Filters Button**
1. Apply any filter (e.g., L1 > EXP_FUND)
2. Click "Clear Filters" button
3. **Expected**:
   - All filters reset
   - Layer 2 cards hide
   - All active states removed
   - Table shows default message

---

## 🧪 Data Verification

### **Check L1 Counts**
```sql
-- Verify L1 pending requests
SELECT COUNT(*) as l1_requests
FROM t_req_master
WHERE status = 'pending'
  AND requires_level_1 = 1
  AND level_1_status = 'pending';

-- Verify pending ledger transactions
SELECT COUNT(*) as pending_ledger
FROM t_fin_ledger
WHERE approval_status = 'pending';
```

### **Check Area Mapping**
```sql
-- Check EXP_FUND items
SELECT r.request_number, r.title, a.account_name
FROM t_req_master r
LEFT JOIN t_fin_accounts a ON r.payment_source_account_id = a.id
WHERE r.status = 'pending'
  AND a.account_code = 'EXP_FUND';
```

---

## 🎭 User Role Testing

### **Test as L1-Only User**
1. Login as manager with only L1 rights
2. **Expected**:
   - See: L1 Pending, Approved, Rejected cards
   - NOT see: L2 Pending card
   - Can approve L1 items

### **Test as L2-Only User**
1. Login as admin with only L2 rights
2. **Expected**:
   - See: L2 Pending, Approved, Rejected cards
   - NOT see: L1 Pending card
   - Can approve L2 items (that passed L1)

### **Test as Both L1+L2 User**
1. Login as Taimur/Admin with both rights
2. **Expected**:
   - See all 4 cards
   - Can approve both L1 and L2 items

### **Test as No Approval Rights**
1. Login as regular employee
2. **Expected**:
   - Redirected to "My Requests" page
   - Message: "You do not have approval rights"

---

## 🐛 Common Issues & Solutions

### **Issue: Cards show 0 items**
**Solution**: 
- Check if there are actually pending items in database
- Verify user has correct approval level permissions
- Check Laravel logs for errors

### **Issue: Layer 2 doesn't appear**
**Solution**:
- Ensure you clicked L1 or L2 card (not Approved/Rejected)
- Check browser console for JavaScript errors
- Clear browser cache

### **Issue: Table doesn't load**
**Solution**:
- Check browser console for AJAX errors
- Verify route `/approvals` is accessible
- Check Laravel logs for backend errors

### **Issue: "View & Approve" button 404**
**Solution**:
- Verify routes for requests and ledger exist
- Check if item IDs are correct
- Ensure user has permission to view item

---

## 📊 Performance Testing

### **Load Time**
- Page should load in < 2 seconds
- AJAX requests should complete in < 1 second
- No visible lag when clicking cards

### **Large Datasets**
Test with 100+ pending items:
- Table should render smoothly
- Filtering should be instant
- No browser freezing

---

## ✅ Acceptance Criteria

Before marking as complete, verify:

- [ ] All 4 Level cards display correct counts and amounts
- [ ] Layer 2 cards show/hide correctly
- [ ] Area filtering works for all 4 areas
- [ ] Table displays all item details correctly
- [ ] "View & Approve" buttons work
- [ ] Clear Filters button resets everything
- [ ] No console errors
- [ ] No horizontal overflow
- [ ] Responsive on mobile
- [ ] Quick approval flows in other pages still work
- [ ] Existing approval/rejection logic works

---

## 🎉 Success!

If all tests pass, the unified approvals system is ready for production use!

**Next Steps:**
1. Train managers on new interface
2. Monitor for any issues in first week
3. Gather user feedback for improvements
4. Consider future enhancements (pagination, search, bulk actions)

