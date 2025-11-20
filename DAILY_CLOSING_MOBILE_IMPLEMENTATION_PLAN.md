# Daily Closing Mobile Implementation Plan

## Overview
Implement the Daily Closing feature in the mobile app, mirroring the web app's implementation at `/finance/employee/outstanding-invoices`.

## Business Rules (From Web App Analysis)

### 1. Invoice States
- **OPEN**: Invoices with no settlement (`settled_amount = 0`)
- **PARTIAL**: Invoices with partial settlement (`settled_amount > 0` but less than full amount)
- **PENDING**: Settlement deposits awaiting approval
- **SETTLED**: Fully settled invoices

### 2. Data Displayed

#### KPI Cards (Top Row)
1. **🔴 OPEN**
   - Count: Open invoices
   - Amount: Total outstanding amount (unsettled)
   - Clickable filter

2. **🟡 PARTIAL**
   - Count: Partially settled invoices
   - Amount: Remaining outstanding amount
   - Clickable filter

3. **⏳ PENDING**
   - Count: Settlement deposits awaiting approval
   - Amount: Total deposit amount pending
   - Clickable to expand/collapse pending settlements section

4. **📊 TOTAL**
   - Count: OPEN + PARTIAL count
   - Amount: Total outstanding (OPEN + PARTIAL)
   - Clickable filter (shows both open and partial)

#### Expense Management Cards (Second Row)
1. **⏳ PENDING (Approvals)**
   - Subtitle: "Awaiting approval"
   - Amount: Sum of pending expense requests
   - Logic: Pending expenses paid from NF Cash OR any rider balance

2. **💸 SHORT CASH**
   - Subtitle: "Unsettled"
   - Amount: Approved expenses from rider balance not yet settled
   - Logic: Approved expenses with `settlement_status = 'pending'` from employee_cash accounts

### 3. Invoice Listing
- **Grouped by Rider**: Each rider has their own card/section
- **Rider Header Shows**:
  - Avatar (first letter of name)
  - Account name
  - Account code
  - Invoice count
  - Total outstanding amount
- **Invoice Table Columns**:
  - Order #
  - Customer Name
  - Invoice Date
  - Description
  - Amount
  - Settled Amount
  - Outstanding
  - Settlement Status
  - Actions (View Details)

### 4. Pending Settlements
- Shows deposits awaiting approval
- Displays which invoices will be settled
- Shows total outstanding amount
- **Approve/Reject Actions** (inline in table)

### 5. Filtering
- **By Rider**: Dropdown to filter specific rider
- **By Status**: Open, Partial, Settled, All
- **By Date Range**: From/To date filters

### 6. Settlement Approvals
When a pending settlement is approved:
1. The deposit transaction status changes to `approved`
2. Related invoice(s) `settled_amount` is updated
3. Related invoice(s) `settlement_status` changes to 'settled' or 'partial'
4. If short cash settlement: expense transaction is also created
5. Account balances are updated

## Technical Implementation

### Backend API Endpoint
**Route**: `GET /api/rider/daily-closing`

**Controller**: `RiderController::getDailyClosing()`

**Request Parameters**:
```php
- status: 'all' | 'open' | 'partial' | 'settled' (default: 'all')
- rider: account_id | 'all' (default: 'all')
- date_from: Y-m-d (optional)
- date_to: Y-m-d (optional)
```

**Response Structure**:
```json
{
  "success": true,
  "stats": {
    "open_count": 10,
    "open_total": 100000.00,
    "partial_count": 5,
    "partial_total": 50000.00,
    "pending_settlement_count": 3,
    "pending_settlement_total": 30000.00,
    "total_outstanding": 150000.00,
    "pending_approvals_count": 2,
    "pending_approvals_amount": 5000.00,
    "short_cash_count": 1,
    "short_cash_amount": 2000.00
  },
  "invoices_by_rider": [
    {
      "account": {
        "id": 1,
        "account_name": "Arslan Aslam",
        "account_code": "CASH_EMP_ARSLAN_ASLAM"
      },
      "pending_settlements": [...],
      "total_outstanding": 50000.00,
      "invoice_count": 5,
      "invoices": [
        {
          "id": 123,
          "order_number": "NF-15209",
          "customer_name": "Ali Nizami",
          "transaction_date": "2025-11-19",
          "description": "Invoice #NF-15209 - Delivered (Ali nizami)",
          "amount": 41991.50,
          "settled_amount": 0,
          "outstanding_amount": 41991.50,
          "settlement_status": "open",
          "is_pending_approval": false,
          "pending_settlement_id": null
        }
      ]
    }
  ],
  "pending_settlements": [
    {
      "id": 456,
      "from_account": "Arslan Aslam",
      "amount": 30000.00,
      "created_at": "2025-11-19 10:30:00",
      "description": "Settlement - Partial Payment",
      "approval_status": "pending",
      "invoice_ids": [123, 124],
      "invoices": [...]
    }
  ],
  "all_riders": [
    {
      "id": 1,
      "account_name": "Arslan Aslam"
    }
  ],
  "filters": {
    "status": "all",
    "rider": "all",
    "date_from": null,
    "date_to": null
  }
}
```

### Backend: Approval Routes
**Approve Settlement**: `POST /api/rider/daily-closing/approve/{id}`
**Reject Settlement**: `POST /api/rider/daily-closing/reject/{id}`

These should reuse the existing `LedgerController::approveTransaction()` and `LedgerController::rejectTransaction()` methods.

### Mobile App Screen
**File**: `src/screens/DailyClosingScreen.js`

**Features**:
1. **Header with Filters**
   - Rider dropdown
   - Date range picker
   - Apply button

2. **KPI Cards Row 1** (Horizontal Scroll)
   - Open (Red)
   - Partial (Yellow)
   - Pending (Blue)
   - Total (Purple)
   - Cards are clickable to filter

3. **KPI Cards Row 2** (Horizontal Scroll)
   - Pending Approvals (Yellow)
   - Short Cash (Green)

4. **Invoice Sections** (Vertical Scroll)
   - Grouped by rider
   - Expandable/collapsible sections
   - Each section shows rider info and invoice list
   - Invoices shown in a compact card format (not table)

5. **Pending Settlements Section** (if any)
   - Collapsible section
   - Each pending settlement shows:
     - Rider name
     - Amount
     - Date
     - Invoice list
     - Approve/Reject buttons

6. **Pull to Refresh**

7. **Navigation**
   - Add to sidebar menu (finance section)

### Reusable Logic
The implementation should reuse:
1. `EmployeeCashController::allOutstandingInvoices()` logic for calculations
2. `LedgerController::approveTransaction()` for approvals
3. `LedgerController::rejectTransaction()` for rejections
4. Same filtering and grouping logic
5. Same settlement metadata structure

### Permission Check
```php
if (!$user->hasMobilePermission('view_daily_closing')) {
    return response()->json([
        'success' => false,
        'message' => 'You do not have permission to view Daily Closing'
    ], 403);
}
```

## Implementation Steps

1. ✅ Create SQL migration for permissions
2. ⬜ Create API endpoint in `RiderController::getDailyClosing()`
3. ⬜ Create approval/reject endpoints
4. ⬜ Add routes to `routes/api.php`
5. ⬜ Create `DailyClosingScreen.js` in mobile app
6. ⬜ Add navigation item in `SideMenu.js`
7. ⬜ Add route in mobile navigation stack
8. ⬜ Test filtering
9. ⬜ Test approvals/rejections
10. ⬜ Test pull-to-refresh
11. ⬜ Test date filtering

## Notes
- The web app uses the route name `fin.employee.all-outstanding-invoices`
- The web app view is at `resources/views/fin/employee/outstanding-invoices.blade.php`
- Settlement metadata is stored in `settlement_metadata` JSON column
- Approvals redirect back to the daily closing page when `_origin` is `outstanding-invoices`
- The feature is also called "Invoice Tracker" in the UI

