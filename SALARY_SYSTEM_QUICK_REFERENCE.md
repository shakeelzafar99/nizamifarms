# 💰 Salary System - Quick Reference Guide

## 🎯 Key Integration Points

### **Where Salary Advances Show Up**

```
┌─────────────────────────────────────────────────────┐
│ EMPLOYEE VIEW (/riders/{id})                        │
├─────────────────────────────────────────────────────┤
│                                                      │
│  Tab: Advances & Loans                              │
│  ┌────────────────────────────────────────────┐    │
│  │ 💸 Current Month Advances                  │    │
│  │                                             │    │
│  │ Request #123 - Oct 15, 2025                │    │
│  │ Amount: 5,000 PKR                           │    │
│  │ Status: Approved ✅                         │    │
│  │ Will be deducted from next salary slip     │    │
│  │                                             │    │
│  │ [Request New Advance]                       │    │
│  └────────────────────────────────────────────┘    │
│                                                      │
└─────────────────────────────────────────────────────┘
```

```
┌─────────────────────────────────────────────────────┐
│ MANAGER VIEW - Approvals (/approvals)               │
├─────────────────────────────────────────────────────┤
│                                                      │
│  Pending Salary Advance Requests                    │
│  ┌────────────────────────────────────────────┐    │
│  │ Request #124                                │    │
│  │ Employee: Ali Khan                          │    │
│  │ Category: Salary Advance                    │    │
│  │ Amount: 10,000 PKR                          │    │
│  │ Reason: "Medical emergency"                 │    │
│  │ Requested: Oct 14, 2025                     │    │
│  │                                             │    │
│  │ [Approve L1] [Reject]                       │    │
│  └────────────────────────────────────────────┘    │
│                                                      │
└─────────────────────────────────────────────────────┘
```

```
┌─────────────────────────────────────────────────────┐
│ MANAGER VIEW - Generate Salary Slip                 │
├─────────────────────────────────────────────────────┤
│                                                      │
│  DEDUCTIONS:                                         │
│  ┌────────────────────────────────────────────┐    │
│  │ Salary Advance (Req #123):  -5,000 PKR    │    │
│  │ ↳ Approved: Oct 15, 2025                   │    │
│  │ ↳ Auto-deducted from this month's salary   │    │
│  └────────────────────────────────────────────┘    │
│                                                      │
└─────────────────────────────────────────────────────┘
```

---

### **Where Loans Show Up**

```
┌─────────────────────────────────────────────────────┐
│ EMPLOYEE VIEW (/riders/{id})                        │
├─────────────────────────────────────────────────────┤
│                                                      │
│  Tab: Advances & Loans                              │
│  ┌────────────────────────────────────────────┐    │
│  │ 📋 Active Loans                             │    │
│  │                                             │    │
│  │ Loan #1 - Personal Loan                     │    │
│  │ ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━   │    │
│  │ Original Amount:    100,000 PKR             │    │
│  │ Outstanding:         75,000 PKR (75%)       │    │
│  │ Monthly Payment:      5,000 PKR             │    │
│  │ Next Deduction:  November 2025              │    │
│  │                                             │    │
│  │ Payment History:                            │    │
│  │ Oct 2025: 5,000 PKR ✅                      │    │
│  │ Sep 2025: 5,000 PKR ✅                      │    │
│  │ Aug 2025: 5,000 PKR ✅                      │    │
│  │                                             │    │
│  │ [View Full Details]                         │    │
│  └────────────────────────────────────────────┘    │
│                                                      │
└─────────────────────────────────────────────────────┘
```

```
┌─────────────────────────────────────────────────────┐
│ MANAGER VIEW - Loans List (/hr/loans)               │
├─────────────────────────────────────────────────────┤
│                                                      │
│  Employee Loans Management                           │
│  ┌────────────────────────────────────────────┐    │
│  │ Filter: [All Employees ▼] [Active ▼]      │    │
│  │                                             │    │
│  │ Employee    Loan #  Principal Outstanding  │    │
│  │ ───────────────────────────────────────────│    │
│  │ Ali Khan    1       100,000   75,000 🟢   │    │
│  │ Bilal       2        50,000   10,000 🟢   │    │
│  │ Ahmed       3        80,000        0 ✅   │    │
│  │                                             │    │
│  │ [Create New Loan]                           │    │
│  └────────────────────────────────────────────┘    │
│                                                      │
└─────────────────────────────────────────────────────┘
```

```
┌─────────────────────────────────────────────────────┐
│ MANAGER VIEW - Generate Salary Slip                 │
├─────────────────────────────────────────────────────┤
│                                                      │
│  DEDUCTIONS:                                         │
│  ┌────────────────────────────────────────────┐    │
│  │ Loan Installment (Loan #1): -5,000 PKR    │    │
│  │ ↳ Outstanding: 75,000 PKR                  │    │
│  │ ↳ [☑] Skip this month                      │    │
│  │                                             │    │
│  │ ⚠️ If skipped, next month will still be   │    │
│  │    5,000 PKR (not doubled)                 │    │
│  └────────────────────────────────────────────┘    │
│                                                      │
└─────────────────────────────────────────────────────┘
```

---

## 📊 Complete Data Flow

### **Salary Advance Flow**

```
STEP 1: Employee Requests Advance
   ↓
┌─────────────────────────────────────┐
│ Request Form (/requests/create)     │
│ Category: Salary Advance            │
│ Amount: 10,000 PKR                  │
│ Reason: Medical emergency           │
└─────────────────────────────────────┘
   ↓
   ↓ Saves to t_req_master
   ↓ category_code = 'salary_advance'
   ↓

STEP 2: Appears in Approvals Dashboard
   ↓
┌─────────────────────────────────────┐
│ /approvals                           │
│ L1 Approver sees pending request    │
│ [Approve] → L1 approved             │
└─────────────────────────────────────┘
   ↓
┌─────────────────────────────────────┐
│ /approvals                           │
│ L2 Approver sees pending request    │
│ [Approve] → L2 approved             │
└─────────────────────────────────────┘
   ↓
   ↓ BOTH L1 & L2 Approved
   ↓

STEP 3: System Auto-Actions (Backend)
   ↓
┌─────────────────────────────────────┐
│ RequestModel::processApproval()     │
│                                      │
│ 1. Update request status='approved' │
│ 2. Call postSalaryAdvanceToLedger() │
│    ↓                                 │
│    Creates ledger entry:             │
│    - Type: salary_advance            │
│    - From: NF_CASH                   │
│    - To: CASH_EMP_XXX                │
│    - Amount: 10,000                  │
│                                      │
│ 3. Update employee cash balance      │
│    (Employee receives money)         │
└─────────────────────────────────────┘
   ↓

STEP 4: Shows in Next Salary Slip
   ↓
┌─────────────────────────────────────┐
│ When generating salary slip for     │
│ the current month:                  │
│                                      │
│ SalaryCalculationService queries:   │
│ - All approved advances this month  │
│ - Automatically adds to deductions  │
│                                      │
│ DEDUCTIONS:                          │
│ Salary Advance: -10,000 PKR         │
└─────────────────────────────────────┘
   ↓

STEP 5: After Salary Slip Approved
   ↓
┌─────────────────────────────────────┐
│ Advance is "cleared"                 │
│ (Marked as deducted)                 │
│                                      │
│ Employee net salary reduced by       │
│ advance amount                       │
└─────────────────────────────────────┘
```

---

### **Loan Flow**

```
STEP 1: Manager Creates Loan
   ↓
┌─────────────────────────────────────┐
│ Form (/hr/loans/create)             │
│ Employee: Ali Khan                  │
│ Principal: 100,000 PKR              │
│ Monthly Installment: 5,000 PKR      │
│ Reason: Personal loan               │
└─────────────────────────────────────┘
   ↓
   ↓ Saves to t_hr_employee_loans
   ↓ outstanding_balance = 100,000
   ↓ status = 'active'
   ↓

STEP 2: Optional Ledger Entry (if disbursed)
   ↓
┌─────────────────────────────────────┐
│ If manager checks                    │
│ "Disburse from company cash"         │
│                                      │
│ Creates ledger entry:                │
│ - Type: loan_disbursement            │
│ - From: NF_CASH                      │
│ - To: CASH_EMP_XXX                   │
│ - Amount: 100,000                    │
│                                      │
│ Links to loan:                       │
│ loan.ledger_transaction_id = xxx     │
└─────────────────────────────────────┘
   ↓

STEP 3: Shows in Employee Profile
   ↓
┌─────────────────────────────────────┐
│ /riders/{id} → Loans tab            │
│                                      │
│ Employee sees:                       │
│ - Outstanding balance                │
│ - Monthly payment                    │
│ - Payment history                    │
└─────────────────────────────────────┘
   ↓

STEP 4: Monthly Salary Slip Generation
   ↓
┌─────────────────────────────────────┐
│ SalaryCalculationService queries:   │
│ - All active loans for employee     │
│ - Sum monthly installments          │
│                                      │
│ DEDUCTIONS:                          │
│ Loan Installment: -5,000 PKR        │
│                                      │
│ Manager can:                         │
│ [☑] Skip this month                 │
└─────────────────────────────────────┘
   ↓

STEP 5: After Salary Slip Approved
   ↓
┌─────────────────────────────────────┐
│ If NOT skipped:                      │
│                                      │
│ UPDATE t_hr_employee_loans           │
│ SET outstanding_balance =            │
│     outstanding_balance - 5,000      │
│                                      │
│ New balance: 95,000 PKR              │
│                                      │
│ If balance reaches 0:                │
│ SET status = 'completed'             │
│ SET completed_at = NOW()             │
└─────────────────────────────────────┘
```

---

### **Salary Slip Generation Flow**

```
STEP 1: Manager Initiates
   ↓
┌─────────────────────────────────────┐
│ /riders → Click employee            │
│ [Generate Salary Slip] button       │
│    OR                                │
│ /hr/salary-slips/create/{userId}    │
└─────────────────────────────────────┘
   ↓

STEP 2: Select Month & Calculate
   ↓
┌─────────────────────────────────────┐
│ Form:                                │
│ Month: [October 2025 ▼]            │
│ [Calculate]                          │
└─────────────────────────────────────┘
   ↓
   ↓ AJAX Call to /hr/salary-slips/calculate
   ↓

STEP 3: Backend Calculation
   ↓
┌─────────────────────────────────────┐
│ SalaryCalculationService             │
│ ::calculateSalary(userId, month)    │
│                                      │
│ 1. Get employee salary config        │
│    FROM t_ops_rider_profile          │
│    - base_salary                     │
│    - overtime_rate                   │
│    - late_deduction_rate             │
│                                      │
│ 2. Get attendance data               │
│    FROM AttendanceController         │
│    ::employeeDetails(userId, month)  │
│    - present_days                    │
│    - late_minutes                    │
│    - overtime_hours                  │
│    - absent_days                     │
│                                      │
│ 3. Get salary advances               │
│    FROM t_req_master                 │
│    WHERE category='salary_advance'   │
│      AND status='approved'           │
│      AND month=current_month         │
│                                      │
│ 4. Get active loans                  │
│    FROM t_hr_employee_loans          │
│    WHERE status='active'             │
│      AND user_id=userId              │
│    SUM(monthly_installment)          │
│                                      │
│ 5. Calculate everything              │
│    Earnings:                         │
│    - base_salary                     │
│    - overtime_hours × overtime_rate  │
│                                      │
│    Deductions:                       │
│    - late_minutes × late_rate        │
│    - absent_days × (salary/workdays) │
│    - salary_advances                 │
│    - loan_installments               │
│                                      │
│    Net = Gross - Deductions          │
└─────────────────────────────────────┘
   ↓
   ↓ Returns JSON with full breakdown
   ↓

STEP 4: Display for Review & Override
   ↓
┌─────────────────────────────────────┐
│ EARNINGS:                            │
│ Base Salary:        50,000 PKR      │
│ Overtime (15h):      3,000 PKR [✏️] │
│ ─────────────────────────────────   │
│ Gross:              53,000 PKR      │
│                                      │
│ DEDUCTIONS:                          │
│ Late (120m):        -1,000 PKR [⛔]  │
│ Absent (2d):        -3,846 PKR      │
│ Advance:            -5,000 PKR      │
│ Loan:               -2,000 PKR [⏭️]  │
│ ─────────────────────────────────   │
│ Deductions:        -11,846 PKR      │
│                                      │
│ NET SALARY:         41,154 PKR      │
│                                      │
│ Override Notes:                      │
│ [____________________________]       │
│                                      │
│ [Save as Draft] [Approve & Post]    │
└─────────────────────────────────────┘
│                                      │
│ [✏️] = Edit button                  │
│ [⛔] = Waive button                  │
│ [⏭️] = Skip button                  │
└─────────────────────────────────────┘
   ↓

STEP 5: Manager Makes Overrides (Optional)
   ↓
┌─────────────────────────────────────┐
│ Example overrides:                   │
│                                      │
│ 1. Click [⛔] on Late deduction     │
│    → Late: 0 PKR (waived)           │
│                                      │
│ 2. Click [⏭️] on Loan               │
│    → Loan: 0 PKR (skipped)          │
│                                      │
│ 3. Click [✏️] on Overtime           │
│    → Manual input: 20 hours         │
│    → Overtime: 4,000 PKR (edited)   │
│                                      │
│ 4. Add override notes:               │
│    "Good performance, waived late.   │
│     Emergency expense, skipped loan."│
│                                      │
│ System tracks:                       │
│ - late_deduction_overridden = true  │
│ - overtime_overridden = true        │
│ - loan_installment_skipped = true   │
└─────────────────────────────────────┘
   ↓

STEP 6: Save or Approve
   ↓
Option A: [Save as Draft]
┌─────────────────────────────────────┐
│ Saves to t_hr_salary_slips          │
│ status = 'draft'                    │
│ Can edit later                       │
└─────────────────────────────────────┘

Option B: [Approve & Post]
┌─────────────────────────────────────┐
│ 1. Save to t_hr_salary_slips        │
│    status = 'approved'              │
│    approved_by = manager_id         │
│    approved_at = NOW()              │
│                                      │
│ 2. Create ledger entry              │
│    Type: salary_payment             │
│    From: EXPENSE_SALARY             │
│    To: CASH_EMP_XXX / Bank          │
│    Amount: net_salary               │
│                                      │
│ 3. Update loan balance (if not skip)│
│    outstanding_balance -= installmt │
│                                      │
│ 4. Mark advances as deducted        │
│                                      │
│ 5. Update slip:                      │
│    ledger_transaction_id = xxx      │
│    slip_status = 'paid'             │
│    paid_at = NOW()                  │
└─────────────────────────────────────┘
   ↓

STEP 7: Confirmation & PDF
┌─────────────────────────────────────┐
│ ✅ Salary slip approved!            │
│                                      │
│ [View Slip] [Download PDF] [Email]  │
└─────────────────────────────────────┘
```

---

## 🎨 UI Mockups

### **Rider Profile Page - Salary Tab**

```
┌──────────────────────────────────────────────────────────────┐
│ Ali Khan - Rider Profile                                      │
├──────────────────────────────────────────────────────────────┤
│ [Profile] [Salary] [Advances & Loans] [Salary Slips]        │
├──────────────────────────────────────────────────────────────┤
│                                                               │
│  💰 Salary Configuration                                     │
│  ┌─────────────────────────────────────────────────────┐    │
│  │ Base Salary:          [50,000] PKR / month          │    │
│  │ Overtime Rate:        [200] PKR / hour              │    │
│  │ Late Deduction Rate:  [100] PKR / hour              │    │
│  │ Currency:             [PKR ▼]                       │    │
│  │ Effective Date:       [2025-01-01]                  │    │
│  │                                                       │    │
│  │ [Save Changes] [View History]                        │    │
│  └─────────────────────────────────────────────────────┘    │
│                                                               │
│  📊 Current Month Summary (October 2025)                     │
│  ┌─────────────────────────────────────────────────────┐    │
│  │ Expected Gross Salary:        ~50,000 PKR           │    │
│  │ Overtime Earned (so far):     +3,200 PKR (16h)     │    │
│  │ Pending Deductions:           -8,000 PKR            │    │
│  │ ─────────────────────────────────────────────────   │    │
│  │ Expected Net Salary:          ~45,200 PKR           │    │
│  │                                                       │    │
│  │ ℹ️ This is an estimate. Final amount determined     │    │
│  │   when salary slip is generated.                     │    │
│  └─────────────────────────────────────────────────────┘    │
│                                                               │
│  [Generate Salary Slip for October] →                        │
│                                                               │
└──────────────────────────────────────────────────────────────┘
```

---

### **Rider Profile Page - Advances & Loans Tab**

```
┌──────────────────────────────────────────────────────────────┐
│ Ali Khan - Rider Profile                                      │
├──────────────────────────────────────────────────────────────┤
│ [Profile] [Salary] [Advances & Loans] [Salary Slips]        │
├──────────────────────────────────────────────────────────────┤
│                                                               │
│  💸 Salary Advances                                          │
│  ┌─────────────────────────────────────────────────────┐    │
│  │ Current Month (October 2025):                        │    │
│  │                                                       │    │
│  │ Request #123 - Oct 15, 2025                          │    │
│  │ Amount: 5,000 PKR                                    │    │
│  │ Status: Approved ✅ (L1 & L2)                       │    │
│  │ Note: Will be deducted from October salary slip     │    │
│  │                                                       │    │
│  │ ───────────────────────────────────────────────────  │    │
│  │                                                       │    │
│  │ Recent History:                                       │    │
│  │ Sep 2025: 3,000 PKR (Deducted ✅)                   │    │
│  │ Aug 2025: 2,000 PKR (Deducted ✅)                   │    │
│  │                                                       │    │
│  │ [Request New Advance] [View All History]             │    │
│  └─────────────────────────────────────────────────────┘    │
│                                                               │
│  📋 Employee Loans                                           │
│  ┌─────────────────────────────────────────────────────┐    │
│  │ Loan #1 - Personal Loan                              │    │
│  │ ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━   │    │
│  │ Original Amount:       100,000 PKR                   │    │
│  │ Outstanding Balance:    75,000 PKR (75%)            │    │
│  │ Monthly Installment:     5,000 PKR                   │    │
│  │ Next Deduction:      November 2025                   │    │
│  │                                                       │    │
│  │ Payment History:                                      │    │
│  │ Oct 2025:  5,000 PKR ✅ Deducted                    │    │
│  │ Sep 2025:  5,000 PKR ✅ Deducted                    │    │
│  │ Aug 2025:  5,000 PKR ✅ Deducted                    │    │
│  │ Jul 2025:  5,000 PKR ✅ Deducted                    │    │
│  │ Jun 2025:  5,000 PKR ✅ Deducted                    │    │
│  │                                                       │    │
│  │ [View Full Details] [View Payment Schedule]          │    │
│  └─────────────────────────────────────────────────────┘    │
│                                                               │
│  ℹ️ Manager Note: Loans and advances are automatically      │
│     deducted from monthly salary. Contact HR if you have     │
│     any questions.                                            │
│                                                               │
└──────────────────────────────────────────────────────────────┘
```

---

### **Salary Slip Generation Screen**

```
┌──────────────────────────────────────────────────────────────┐
│ Generate Salary Slip                                          │
├──────────────────────────────────────────────────────────────┤
│                                                               │
│  Employee: Ali Khan (#15)                                    │
│  Month: [October 2025 ▼]  [Calculate Salary] →             │
│                                                               │
├──────────────────────────────────────────────────────────────┤
│                                                               │
│  📊 EARNINGS                                                 │
│  ┌─────────────────────────────────────────────────────┐    │
│  │ Base Salary:                            50,000 PKR   │    │
│  │ Overtime (15.5 hours × 200):             3,100 PKR   │    │
│  │   ↳ [✏️ Edit Hours]                                 │    │
│  │                                                       │    │
│  │ Bonuses:                                     0 PKR   │    │
│  │   ↳ [+ Add Bonus]                                   │    │
│  │                                                       │    │
│  │ Other Earnings:                              0 PKR   │    │
│  │   ↳ [+ Add Other]                                   │    │
│  │ ─────────────────────────────────────────────────   │    │
│  │ GROSS SALARY:                           53,100 PKR   │    │
│  └─────────────────────────────────────────────────────┘    │
│                                                               │
│  📉 DEDUCTIONS                                               │
│  ┌─────────────────────────────────────────────────────┐    │
│  │ Late (120 minutes × 100):              -2,000 PKR   │    │
│  │   ↳ [⛔ Waive Late Deduction]                       │    │
│  │                                                       │    │
│  │ Absent (2 days × 1,923):               -3,846 PKR   │    │
│  │   ↳ Present: 24/26 days                             │    │
│  │                                                       │    │
│  │ Salary Advance (Req #123):             -5,000 PKR   │    │
│  │   ↳ Approved: Oct 15, 2025                          │    │
│  │   ↳ Cannot be modified                               │    │
│  │                                                       │    │
│  │ Loan Installment (Loan #1):            -5,000 PKR   │    │
│  │   ↳ Outstanding: 75,000 PKR                         │    │
│  │   ↳ [⏭️ Skip This Month]                            │    │
│  │                                                       │    │
│  │ Other Deductions:                           0 PKR   │    │
│  │   ↳ [+ Add Deduction]                               │    │
│  │ ─────────────────────────────────────────────────   │    │
│  │ TOTAL DEDUCTIONS:                      -15,846 PKR   │    │
│  └─────────────────────────────────────────────────────┘    │
│                                                               │
│  💰 NET SALARY:                             37,254 PKR       │
│                                                               │
├──────────────────────────────────────────────────────────────┤
│                                                               │
│  📋 Attendance Summary                                       │
│  Working Days: 26 | Present: 24 | Absent: 2 | Leave: 0     │
│  Late Days: 3 (Total: 120 minutes)                          │
│  Overtime: 15.5 hours                                        │
│                                                               │
├──────────────────────────────────────────────────────────────┤
│                                                               │
│  📝 Override Notes (Optional)                                │
│  ┌─────────────────────────────────────────────────────┐    │
│  │ [Employee showed good performance this month.        │    │
│  │  Waiving late deduction as a one-time courtesy.]    │    │
│  └─────────────────────────────────────────────────────┘    │
│                                                               │
│  [← Back] [Save as Draft] [Approve & Generate Slip] →       │
│                                                               │
└──────────────────────────────────────────────────────────────┘
```

---

## 🔑 Key Features Summary

### ✅ **For Employees**
- 👁️ **Transparent** - Can see salary breakdown, advances, loans
- 📱 **Self-service** - Can request advances via existing system
- 📄 **Historical records** - View past salary slips anytime
- 📊 **Real-time tracking** - See current month estimates

### ✅ **For Managers**
- 🎛️ **Full control** - Override any component at runtime
- ⚡ **Fast** - Generate slip in seconds using attendance data
- 🔄 **Flexible** - Waive deductions, skip loan payments as needed
- 📈 **Insightful** - See attendance summary while generating
- 💾 **Draft mode** - Save and review before approving

### ✅ **System Benefits**
- 🔗 **Integrated** - Uses existing users, attendance, ledger, approvals
- 📝 **Audited** - All overrides tracked with notes
- 🎯 **Accurate** - Automatic calculations from attendance
- 💰 **Financial tracking** - All payments go through ledger
- 🔒 **Secure** - Permission-based access

---

## 📞 Decision Points

Before starting implementation, please confirm:

### 1. **Scope of Employees**
- ❓ Only riders get salary tracking?
- ❓ Or all users (admins, managers, office staff)?
- **Recommendation:** Start with riders, expand later if needed

### 2. **Salary Advance Approval**
- ❓ Current plan: L1 + L2 approval (follows expense pattern)
- ❓ Alternative: Only L1 approval for advances?
- **Recommendation:** Keep L1+L2 for consistency

### 3. **Loan Approval**
- ❓ Should loan creation require approval workflow?
- ❓ Or only managers can create (no approval needed)?
- **Recommendation:** Manager creates directly (no approval), since it's typically pre-discussed

### 4. **Mid-Month Scenarios**
- ❓ How to handle employee joining mid-month?
- ❓ Pro-rate salary based on days worked?
- **Recommendation:** Yes, pro-rate: (base_salary ÷ working_days) × present_days

### 5. **Multiple Advances**
- ❓ Can employee have multiple advances in same month?
- **Recommendation:** Yes, sum all approved advances for deduction

### 6. **Negative Salary**
- ❓ What if deductions > earnings?
- ❓ Show negative or cap at zero?
- **Recommendation:** Allow negative, but show warning to manager

---

## 🚀 Ready to Build?

Once you confirm the above decisions, we can proceed with:

**Phase 1:** Database setup (30 mins)
**Phase 2:** Models (2 hours)  
**Phase 3:** Services (3 hours)
**Phase 4:** Controllers (4 hours)
**Phase 5:** Routes (30 mins)
**Phase 6:** Views (6 hours)
**Phase 7:** Testing (4 hours)

**Total Estimated Time:** ~3 days of focused work

Let me know when you're ready! 🎉

