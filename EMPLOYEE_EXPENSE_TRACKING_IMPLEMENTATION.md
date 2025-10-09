# Employee Expense Tracking - Comprehensive Implementation Plan

## 🎯 Objectives

1. **Fix**: Pop-up modal not showing (cache/JS issue)
2. **Fix**: Request title showing "leave" instead of expense category
3. **Add**: Expense request tracking on employee cash page
4. **Add**: Quick expense request form from employee page
5. **Improve**: Display pending vs approved expenses with proper summaries

---

## 📋 Current Issues

### Issue 1: Modal Not Showing
**Cause**: Likely browser cache holding old version
**Solution**: User needs hard refresh (Ctrl+Shift+R) - code is correct

### Issue 2: Title Shows "leave"
**Cause**: When expense request is created, title field defaults to something wrong
**Location**: `resources/views/pages/requests/create.blade.php` or controller
**Fix**: Set title properly for expense requests

### Issue 3: No Expense Tracking on Employee Page
**Current State**: Employee cash page only shows ledger transactions (invoices, deposits)
**Missing**: Expense reimbursement requests (pending, approved, paid)
**Solution**: Add second section/tab for expense requests

---

## 🏗️ Proposed Solution

### A. Employee Cash Page Structure (NEW)

```
┌─────────────────────────────────────────────────────┐
│  Cash - Employee Name                                │
├─────────────────────────────────────────────────────┤
│  Summary Cards (6 in a row):                        │
│  Opening | Invoices | Expenses | Deposits | Balance | Account │
├─────────────────────────────────────────────────────┤
│  Action Buttons:                                     │
│  [💵 Record Deposit]  [💰 Request Expense]  [⚖️ Adjustment] │
├─────────────────────────────────────────────────────┤
│  TABS:                                               │
│  [ Cash Transactions ]  [ Expense Requests ]        │
├─────────────────────────────────────────────────────┤
│  TAB 1: Cash Transactions (Current ledger view)     │
│  - Shows invoices, deposits, expenses from ledger    │
│  - Running balance                                   │
├─────────────────────────────────────────────────────┤
│  TAB 2: Expense Requests (NEW)                      │
│  - Summary: Pending: Rs. X | Approved: Rs. Y | Paid: Rs. Z │
│  - Table: Date | Category | Amount | Status | Actions │
│  - Statuses: Pending L1, Pending L2, Approved, Paid, Rejected │
└─────────────────────────────────────────────────────┘
```

### B. New "Request Expense" Modal

Similar to deposit modal, but for creating expense requests:
- **Expense Category**: Dropdown (Petrol, Rent, etc.)
- **Amount**: Number input
- **Description**: Textarea
- **Attachments**: File upload (optional)
- **Submit**: Creates request on behalf of employee

### C. Expense Request Summary Display

**Statuses**:
1. **Pending L1**: Yellow badge
2. **Pending L2**: Orange badge
3. **Approved (Not Paid)**: Green badge
4. **Paid**: Blue badge (ledger posted)
5. **Rejected**: Red badge

**Summary Cards**:
- **Total Pending**: Sum of L1 + L2 pending
- **Total Approved (Unpaid)**: Approved but not in ledger yet
- **Total Paid**: Has ledger_transaction_id

---

## 🔧 Implementation Steps

### Step 1: Fix Title Bug
**File**: `app/Http/Controllers/Request/RequestController.php`
- When expense category is selected, set title = expense_category if title is empty

### Step 2: Add Expense Request Button
**File**: `resources/views/fin/employee/show.blade.php`
- Add button: "💰 Request Expense"
- Add modal similar to deposit modal

### Step 3: Add Tabs to Employee Page
**File**: `resources/views/fin/employee/show.blade.php`
- Add tab navigation (Cash Transactions | Expense Requests)
- Show/hide content based on active tab
- Use JavaScript for tab switching

### Step 4: Fetch & Display Expense Requests
**File**: `app/Http/Controllers/FIN/EmployeeCashController.php`
- In `show()` method, fetch requests for this employee
- Filter by user_id matching account->user_id
- Include: requester, category, approval status

### Step 5: Create Expense Request Handler
**File**: Create `app/Http/Controllers/FIN/EmployeeCashController.php::createExpenseRequest()`
- Accept: employee_id, expense_category, amount, description
- Create request on behalf of employee
- Set requester = employee
- Set created_by = current user (manager/admin)

### Step 6: Display Summary Stats
**Calculate**:
```php
$expenseRequests = RequestModel::where('requester_user_id', $account->user_id)
    ->where('category.category_name', 'Expense Reimbursement')
    ->get();

$summary = [
    'pending_requests' => $expenseRequests->where('status', 'pending')->sum('amount'),
    'approved_unpaid' => $expenseRequests->where('status', 'approved')
                                        ->whereNull('ledger_transaction_id')
                                        ->sum('amount'),
    'paid' => $expenseRequests->whereNotNull('ledger_transaction_id')->sum('amount')
];
```

---

## 📁 Files to Modify/Create

### Modified:
1. `resources/views/fin/employee/show.blade.php` - Add tabs, expense request button, modal
2. `app/Http/Controllers/FIN/EmployeeCashController.php` - Fetch requests, create request handler
3. `app/Http/Controllers/Request/RequestController.php` - Fix title bug
4. `routes/web.php` - Add route for employee expense request creation

### New:
1. None (reuse existing request system)

---

## 🎨 UI Components

### Tab Component (Simple)
```html
<div class="border-b border-gray-200 mb-4">
    <nav class="-mb-px flex gap-4">
        <button onclick="switchTab('cash')" id="tab-cash" 
                class="tab-button active px-4 py-2 border-b-2 font-medium text-sm">
            💵 Cash Transactions
        </button>
        <button onclick="switchTab('expenses')" id="tab-expenses" 
                class="tab-button px-4 py-2 border-b-2 font-medium text-sm">
            💰 Expense Requests
        </button>
    </nav>
</div>

<div id="content-cash" class="tab-content">
    <!-- Existing ledger view -->
</div>

<div id="content-expenses" class="tab-content hidden">
    <!-- New expense requests view -->
</div>
```

### Summary Stats for Expenses
```html
<div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4 mb-4">
    <div class="grid grid-cols-3 gap-4">
        <div>
            <p class="text-xs text-yellow-700 uppercase">Pending</p>
            <p class="text-lg font-bold text-yellow-900">Rs. X,XXX</p>
        </div>
        <div>
            <p class="text-xs text-green-700 uppercase">Approved (Unpaid)</p>
            <p class="text-lg font-bold text-green-900">Rs. X,XXX</p>
        </div>
        <div>
            <p class="text-xs text-blue-700 uppercase">Paid</p>
            <p class="text-lg font-bold text-blue-900">Rs. X,XXX</p>
        </div>
    </div>
</div>
```

---

## ✅ Testing Checklist

- [ ] Hard refresh browser to see modal fix
- [ ] Create expense request - verify title is correct
- [ ] View employee page - see two tabs
- [ ] Switch between tabs - content changes
- [ ] Click "Request Expense" - modal opens properly
- [ ] Submit expense request - appears in Expense Requests tab
- [ ] Approve request - status updates
- [ ] Check ledger posting - "Paid" status shows

---

## 🚀 Deployment Order

1. Fix title bug (backend)
2. Add expense request button + modal (frontend)
3. Add tabs structure (frontend)
4. Fetch and display requests (backend + frontend)
5. Add create expense request handler (backend)
6. Test end-to-end flow

---

**Status**: Ready to implement
**Est. Time**: 2-3 hours
**Priority**: High


