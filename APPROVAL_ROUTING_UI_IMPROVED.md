# Approval Routing UI - Improved & Simplified

## 🎉 What Changed

Based on your feedback, I've completely redesigned the approval routing UI to be **much more intuitive and integrated**!

### Before (Complex Modal Approach)
- ❌ Separate "Routing Rules" section with modal
- ❌ JavaScript not loading properly
- ❌ Disconnected from category configuration
- ❌ Missing "Invoice Approval" category
- ❌ Complex multi-step process

### After (Integrated Approach) ✅
- ✅ **Routing integrated directly into each category**
- ✅ Simple, clean JavaScript that works
- ✅ **All categories shown** including Invoice Approval
- ✅ Expandable/collapsible details per category
- ✅ One-click save for both config and routing

---

## 📋 New UI Layout

### 1. Category Cards (Collapsed by default)
Each category shows:
- Category name and description
- Quick checkboxes for "Requires L1" and "Requires L2"
- Expand button (▼) to show detailed configuration

### 2. Expanded View (Click ▼ button)
When expanded, each category shows:

#### **Level 1 Approvers** (Blue section)
- **Assign Specific User**: Dropdown to select a user (or leave as "Any L1 user")
- **Payment Source Filter**: Optional filter by payment account

#### **Level 2 Approvers** (Purple section)
- **Assign Specific User**: Dropdown to select a user (or leave as "Any L2 user")
- **Payment Source Filter**: Optional filter by payment account

#### **Additional Settings**
- **Auto-Approve Threshold**: Amount below which requests auto-approve

#### **Save Button**
- One button saves everything: config + routing rules

---

## 🎯 How It Works

### Example: Configure "Invoice Approval"

1. **Find "Invoice Approval" in the list**
   - It's now visible along with all other categories

2. **Click the ▼ button** to expand details

3. **Configure L1 Approval**:
   - Check "Requires L1" ✓
   - Select a user from "Assign Specific User" dropdown
   - Optionally select "Online" payment source filter

4. **Configure L2 Approval**:
   - Check "Requires L2" ✓
   - Select a user from "Assign Specific User" dropdown

5. **Click "Save Configuration"**
   - Saves both the L1/L2 requirements AND creates routing rules automatically

---

## 💡 Key Features

### 1. **Role-Based by Default**
- If you leave user assignment empty → Uses role-based approval
- Any user with L1 role can approve L1 requests
- Any user with L2 role can approve L2 requests

### 2. **User-Specific Routing**
- Select a specific user → Only that user sees the request assigned to them
- But any L1/L2 user can still approve (backup coverage)

### 3. **Payment Source Filtering**
- Filter by payment account (EXP_FUND, NF_CASH, Online, etc.)
- Example: "Expenses from EXP_FUND go to User A"
- Example: "Expenses from NF_CASH go to User B"

### 4. **Auto-Approve Threshold**
- Set amount limit for automatic approval
- Example: Expenses under Rs. 5,000 auto-approve
- Saves time for small requests

---

## 📊 All Categories Now Visible

The UI now shows **ALL** request categories:

1. ✅ **Employee Deposit** - L1 required
2. ✅ **Vendor Payment** - L1 required  
3. ✅ **Account Transfer** - L1 & L2 required
4. ✅ **Invoice Approval** - L1 & L2 required (NEW!)
5. ✅ **Invoice Adjustment** - L1 & L2 required
6. ✅ **Salary Advance** - L1 & L2 required
7. ✅ **Leave Request** - L1 required
8. ✅ **Expense Reimbursement** - L1 required
9. ✅ **Equipment Request** - L1 required

---

## 🚀 Quick Start Guide

### Step 1: Assign L1 and L2 Roles
1. Go to **Requests → Settings**
2. In "Approval Level Assignments" section:
   - Add roles to Level 1 (e.g., Manager, Supervisor)
   - Add roles to Level 2 (e.g., Admin, Director)

### Step 2: Configure Invoice Approval
1. Scroll to "Category Approval Configuration & Routing"
2. Find **"Invoice Approval"** card
3. Click the **▼** button to expand
4. Configure:
   - ✓ Requires L1
   - ✓ Requires L2
   - L1 User: Select your manager
   - L2 User: Select your director
5. Click **"Save Configuration"**

### Step 3: Test with Online Order
1. Create an order with **online payment**
2. Mark as **"Delivered"**
3. Check **Requests** page
4. Should see "Invoice Approval" request assigned to L1 user
5. Approve at L1 → Creates ledger entry as PENDING
6. Approve ledger at L2 → Updates balances

---

## 🔧 Technical Details

### How Routing Rules Are Created

When you save a category configuration with user assignments, the system automatically:

1. Saves the basic config (L1/L2 requirements, threshold)
2. Creates routing rules in `t_req_approval_rules` table
3. Creates assignee records in `t_req_approval_rule_assignees` table

**Example Rule Created**:
```
Rule Name: Invoice Approval - L1 Auto-Rule
Area Type: request_category
Area Identifier: invoice_approval
Approval Level: 1
Assignee: [Selected User]
Payment Source: [Selected Account or NULL]
```

### JavaScript Functions

**`toggleCategoryDetails(categoryId)`**
- Expands/collapses category details
- Toggles icon between ▼ and ▲

**`saveCategoryConfigAndRouting(categoryId)`**
- Saves L1/L2 requirements
- Saves auto-approve threshold
- Creates routing rules for assigned users
- Handles payment source filters

**`saveRoutingRule(categoryId, level, userId, paymentSourceId)`**
- Helper function to create individual routing rules
- Called automatically when users are assigned

---

## 📝 Configuration Examples

### Example 1: Online Invoices → Manager A
```
Category: Invoice Approval
├─ Requires L1: ✓
├─ Requires L2: ✓
├─ L1 Approvers:
│  ├─ User: Manager A
│  └─ Payment Source: (Any)
└─ L2 Approvers:
   ├─ User: Director B
   └─ Payment Source: (Any)
```

### Example 2: Expenses by Payment Source
```
Category: Expense Reimbursement
├─ Requires L1: ✓
├─ L1 Approvers:
│  ├─ User: Manager A
│  └─ Payment Source: EXP_FUND
└─ (Create another rule for NF_CASH → Manager B)
```

### Example 3: Auto-Approve Small Expenses
```
Category: Expense Reimbursement
├─ Requires L1: ✓
├─ Auto-Approve Threshold: 5000
└─ (Expenses < Rs. 5,000 auto-approve)
```

---

## ⚠️ Important Notes

### 1. **Backup Coverage**
- Even with specific user assignment, any L1/L2 user can approve
- This ensures someone can always handle approvals
- Primary assignee is just for visual organization

### 2. **Payment Source Filters**
- Only applies if payment source is set on the request
- If request has no payment source → rule still matches
- Useful for expenses, vendor payments, transfers

### 3. **Multiple Rules**
- You can create multiple rules for the same category
- System uses priority (lower number = higher priority)
- First matching rule determines the assignee

### 4. **Invoice Approval Category**
- Special category for online invoices
- Created automatically by migration
- Requires both L1 and L2 by default
- L1 = Request approval, L2 = Ledger approval

---

## 🐛 Troubleshooting

### Issue: Categories not showing
**Solution**: Refresh the page, check if migration ran successfully

### Issue: Can't expand category details
**Solution**: Check browser console for JavaScript errors

### Issue: Save button not working
**Solution**: Check network tab, verify routes are registered

### Issue: Routing rules not applying
**Solution**: Check `t_req_approval_rules` table, verify rules were created

### Issue: Invoice Approval not visible
**Solution**: Run migration again, check `t_req_category` table

---

## 📊 Database Tables

### t_req_approval_rules
Stores routing rules with filters:
- `rule_name` - Display name
- `area_type` - request_category, ledger_transaction, etc.
- `area_identifier` - Category code (e.g., invoice_approval)
- `approval_level` - 1 or 2
- `payment_source_account_id` - Optional filter
- `priority` - Rule priority

### t_req_approval_rule_assignees
Stores user assignments:
- `rule_id` - FK to rules table
- `user_id` - FK to users table
- `is_primary` - Primary assignee flag
- `sequence_order` - Display order

---

## ✅ Summary

The new UI is:
- ✅ **Simpler** - No complex modals
- ✅ **Integrated** - Routing with category config
- ✅ **Complete** - All categories visible
- ✅ **Working** - Clean JavaScript that loads properly
- ✅ **Intuitive** - Expand/collapse per category
- ✅ **Flexible** - Role-based or user-specific

**Next Step**: Refresh the page and try configuring Invoice Approval! 🚀

