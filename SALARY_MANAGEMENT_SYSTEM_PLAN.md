# 💰 Salary Management System - Implementation Plan

## 📋 Executive Summary

This document outlines a comprehensive salary management system that integrates seamlessly with your existing:
- **User Management** (`t_sys_user`)
- **Rider Profiles** (`t_ops_rider_profile`) 
- **Attendance Tracking** (`t_ops_attendance`)
- **Financial Ledger** (`t_fin_ledger`, `t_fin_accounts`)
- **Request/Approval System** (`t_req_master`, L1/L2 approval workflow)

---

## 🎯 Core Requirements

### 1. **Salary Configuration**
- Store monthly base salary for each employee
- Configure overtime rate (per hour)
- Configure late deduction rate (per hour/minute)
- Store salary in rider profile (avoid duplication)

### 2. **Salary Advances**
- Employees can request salary advances
- Uses existing request/approval system (L1→L2)
- Tracks advance balance per employee
- Automatically deducts from salary slip

### 3. **Loan Management**
- Track employee loans (principal amount, monthly installment)
- Store loan balance
- Automatically deduct installments from salary
- View loan details in employee profile

### 4. **Salary Slip Generation**
- **Inputs:**
  - Attendance data (present days, late minutes, overtime hours)
  - Salary advances taken (current month)
  - Loan installments due
- **Runtime Overrides:**
  - Manager can override any component before finalizing
  - Option to waive late deductions
  - Option to adjust overtime hours
  - Option to skip loan installment for the month
- **Output:**
  - Detailed salary slip (PDF/printable)
  - Posted to ledger as salary payment

---

## 🗄️ Database Design

### Table 1: **Extended Rider Profile** (Modify existing table)

```sql
-- Add salary fields to existing t_ops_rider_profile
ALTER TABLE t_ops_rider_profile 
ADD COLUMN base_salary DECIMAL(10,2) DEFAULT 0.00 COMMENT 'Monthly base salary',
ADD COLUMN overtime_rate DECIMAL(10,2) DEFAULT 0.00 COMMENT 'Rate per hour for overtime',
ADD COLUMN late_deduction_rate DECIMAL(10,2) DEFAULT 0.00 COMMENT 'Deduction per hour for lateness',
ADD COLUMN salary_currency VARCHAR(10) DEFAULT 'PKR' COMMENT 'Salary currency',
ADD COLUMN salary_effective_date DATE NULL COMMENT 'When current salary became effective';
```

**Why extend rider profile?**
- ✅ Riders are your main employees (delivery staff)
- ✅ Already has user_id FK to t_sys_user
- ✅ Already tracks hire_date, shift times
- ✅ Avoids creating duplicate "employee" table
- ✅ Other users (admins, managers) typically don't need salary tracking

---

### Table 2: **Employee Loans** (New table)

```sql
CREATE TABLE t_hr_employee_loans (
    id INT AUTO_INCREMENT PRIMARY KEY,
    
    -- Employee reference
    user_id INT NOT NULL COMMENT 'FK to t_sys_user.id',
    
    -- Loan details
    loan_date DATE NOT NULL COMMENT 'Date loan was given',
    principal_amount DECIMAL(15,2) NOT NULL COMMENT 'Original loan amount',
    monthly_installment DECIMAL(15,2) NOT NULL COMMENT 'Monthly deduction amount',
    outstanding_balance DECIMAL(15,2) NOT NULL COMMENT 'Remaining balance',
    
    -- Status
    loan_status ENUM('active', 'completed', 'cancelled') DEFAULT 'active',
    
    -- Notes
    description TEXT NULL COMMENT 'Purpose of loan',
    notes TEXT NULL COMMENT 'Additional notes',
    
    -- Ledger integration (optional - if loan was given via cash)
    ledger_transaction_id INT NULL COMMENT 'FK to t_fin_ledger.id if loan was disbursed via ledger',
    
    -- Audit fields
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_by INT NULL COMMENT 'FK to t_sys_user.id',
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    updated_by INT NULL COMMENT 'FK to t_sys_user.id',
    completed_at TIMESTAMP NULL COMMENT 'When loan was fully paid',
    
    -- Indexes
    INDEX idx_user_id (user_id),
    INDEX idx_loan_status (loan_status),
    INDEX idx_loan_date (loan_date),
    
    -- Foreign Keys
    CONSTRAINT fk_loan_user_id FOREIGN KEY (user_id) REFERENCES t_sys_user(id) ON DELETE CASCADE,
    CONSTRAINT fk_loan_ledger FOREIGN KEY (ledger_transaction_id) REFERENCES t_fin_ledger(id) ON DELETE SET NULL
    
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Employee loan tracking with monthly installments';
```

---

### Table 3: **Salary Slips** (New table - Historical record)

```sql
CREATE TABLE t_hr_salary_slips (
    id INT AUTO_INCREMENT PRIMARY KEY,
    
    -- Employee & Period
    user_id INT NOT NULL COMMENT 'FK to t_sys_user.id',
    salary_month DATE NOT NULL COMMENT 'Month for which salary is calculated (YYYY-MM-01)',
    
    -- Earnings (Credits)
    base_salary DECIMAL(15,2) NOT NULL COMMENT 'Base monthly salary',
    overtime_hours DECIMAL(10,2) DEFAULT 0.00 COMMENT 'Total overtime hours',
    overtime_amount DECIMAL(15,2) DEFAULT 0.00 COMMENT 'Overtime payment',
    bonuses DECIMAL(15,2) DEFAULT 0.00 COMMENT 'Any bonuses',
    other_earnings DECIMAL(15,2) DEFAULT 0.00 COMMENT 'Other earnings',
    gross_salary DECIMAL(15,2) NOT NULL COMMENT 'Total earnings before deductions',
    
    -- Deductions (Debits)
    late_minutes DECIMAL(10,2) DEFAULT 0.00 COMMENT 'Total late minutes',
    late_deduction DECIMAL(15,2) DEFAULT 0.00 COMMENT 'Deduction for lateness',
    absent_days INT DEFAULT 0 COMMENT 'Number of absent days',
    absent_deduction DECIMAL(15,2) DEFAULT 0.00 COMMENT 'Deduction for absences',
    salary_advance DECIMAL(15,2) DEFAULT 0.00 COMMENT 'Salary advance taken this month',
    loan_installment DECIMAL(15,2) DEFAULT 0.00 COMMENT 'Loan installment deducted',
    other_deductions DECIMAL(15,2) DEFAULT 0.00 COMMENT 'Other deductions',
    total_deductions DECIMAL(15,2) NOT NULL COMMENT 'Sum of all deductions',
    
    -- Final Amount
    net_salary DECIMAL(15,2) NOT NULL COMMENT 'Amount to be paid (gross - deductions)',
    
    -- Attendance Summary
    working_days INT NOT NULL COMMENT 'Expected working days in month',
    present_days INT NOT NULL COMMENT 'Days employee was present',
    leave_days INT DEFAULT 0 COMMENT 'Approved leave days',
    
    -- Override Flags (to track manager adjustments)
    late_deduction_overridden BOOLEAN DEFAULT FALSE,
    overtime_overridden BOOLEAN DEFAULT FALSE,
    loan_installment_skipped BOOLEAN DEFAULT FALSE,
    override_notes TEXT NULL COMMENT 'Reason for any overrides',
    
    -- Status & Approval
    slip_status ENUM('draft', 'approved', 'paid', 'cancelled') DEFAULT 'draft',
    approved_by INT NULL COMMENT 'FK to t_sys_user.id - Manager who approved',
    approved_at TIMESTAMP NULL,
    paid_at TIMESTAMP NULL,
    
    -- Ledger Integration
    ledger_transaction_id INT NULL COMMENT 'FK to t_fin_ledger.id when salary is paid',
    
    -- Audit fields
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_by INT NOT NULL COMMENT 'FK to t_sys_user.id - Who generated this slip',
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    updated_by INT NULL,
    
    -- Indexes
    INDEX idx_user_id (user_id),
    INDEX idx_salary_month (salary_month),
    INDEX idx_slip_status (slip_status),
    INDEX idx_user_month (user_id, salary_month),
    
    -- Foreign Keys
    CONSTRAINT fk_slip_user FOREIGN KEY (user_id) REFERENCES t_sys_user(id) ON DELETE CASCADE,
    CONSTRAINT fk_slip_approved_by FOREIGN KEY (approved_by) REFERENCES t_sys_user(id) ON DELETE SET NULL,
    CONSTRAINT fk_slip_ledger FOREIGN KEY (ledger_transaction_id) REFERENCES t_fin_ledger(id) ON DELETE SET NULL,
    
    -- Prevent duplicate slips for same user+month
    UNIQUE KEY unique_user_month (user_id, salary_month, slip_status)
    
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Monthly salary slips with attendance-based calculations';
```

---

### Table 4: **Use Existing Request System for Advances**

**Add new category:**
```sql
INSERT INTO t_req_category (category_code, category_name, description, is_active)
VALUES ('salary_advance', 'Salary Advance', 'Request for advance salary payment', 1);

-- Configure L1+L2 approval
INSERT INTO t_req_category_approval_config (category_id, requires_level_1, requires_level_2)
SELECT id, 1, 1 FROM t_req_category WHERE category_code = 'salary_advance';
```

**Track advances in existing `t_req_master`:**
- category_code = 'salary_advance'
- amount = advance amount requested
- When approved → create ledger entry
- Deduct from next salary slip

---

## 🔗 Integration with Existing System

### 1. **User Interface Integration**

#### **Option A: Extend Riders Page** ⭐ **RECOMMENDED**
- **Route:** `/riders` (existing)
- **Controller:** `RiderProfileController` 
- Add tabs:
  - 📋 Profile (existing)
  - 💰 Salary & Compensation (new)
  - 💸 Advances & Loans (new)
  - 📄 Salary Slips (new)

**Why this is best:**
- ✅ Riders are your main salary-earning employees
- ✅ Already has shift, attendance integration
- ✅ No duplication with users page
- ✅ Keeps HR functions together

#### **Option B: Separate HR Menu**
- Create new menu item: "HR Management"
- Routes: `/hr/employees`, `/hr/salary-slips`, `/hr/loans`
- **Downside:** Creates duplication, more navigation clicks

**Recommendation:** Use Option A - extend riders page

---

### 2. **Attendance Integration**

**Data Flow:**
```
t_ops_attendance → AttendanceController::monthlyReport() 
                → Salary Slip Calculation
                → Manual Adjustments
                → Final Salary Slip
```

**Already Available:**
- Present days count ✅
- Late minutes (per day and total) ✅
- Overtime hours (per day and total) ✅
- Working days calculation ✅

**Use existing method:** `AttendanceController::employeeDetails()`
- Returns: present_days, late_days, total_late_minutes, overtime_days, total_overtime_minutes

---

### 3. **Financial Ledger Integration**

#### **Transaction Types (already defined in LedgerModel):**

**Salary Advance:**
```php
// When advance request is approved
TYPE_SALARY_ADVANCE = 'salary_advance'
From: Company Cash (NF_CASH)
To: Employee Cash (CASH_EMP_XXX)
Amount: Advance amount
```

**Salary Payment:**
```php
// When salary slip is paid
TYPE_SALARY_PAYMENT = 'salary_payment' // NEW TYPE NEEDED
From: Salary Expense Account (EXPENSE_SALARY) // NEW ACCOUNT
To: Employee Cash (CASH_EMP_XXX) OR Bank Account
Amount: Net salary
```

**Loan Disbursement:**
```php
// When loan is given
TYPE_LOAN_DISBURSEMENT = 'loan_disbursement' // NEW TYPE NEEDED
From: Company Cash (NF_CASH) or Loan Account
To: Employee Cash (CASH_EMP_XXX)
Amount: Loan principal
```

#### **Account Types Needed:**
```sql
-- Add to t_fin_accounts
INSERT INTO t_fin_accounts (account_code, account_name, account_type, account_category, is_active)
VALUES 
('EXPENSE_SALARY', 'Salary Expense', 'expense', 'salary', 1),
('LOAN_RECEIVABLE', 'Employee Loans Receivable', 'asset', 'loan', 1);
```

---

### 4. **Request/Approval Integration**

**For Salary Advances:**

```
Employee submits advance request
    ↓
Appears in L1 approver's dashboard (/approvals)
    ↓
L1 approves
    ↓
L2 approves
    ↓
System creates ledger entry (TYPE_SALARY_ADVANCE)
    ↓
Amount tracked for next salary deduction
```

**Existing code that handles this:**
- `RequestController::store()` - Create request
- `RequestApprovalController::approve()` - Approve at levels
- `RequestModel::processApproval()` - Handle approval workflow
- **Need to add:** Hook in `processApproval()` to create ledger entry for salary_advance category

---

## 📱 User Experience Flow

### **Manager View - Generate Salary Slip**

**Page:** `/riders/{id}/salary-slip/generate`

**Step 1: Select Month**
```
┌─────────────────────────────────────┐
│  Generate Salary Slip for Ali Khan │
├─────────────────────────────────────┤
│  Select Month: [October 2025 ▼]    │
│  [Calculate] button                 │
└─────────────────────────────────────┘
```

**Step 2: Review & Override**
```
┌────────────────────────────────────────────────┐
│  Salary Slip - Ali Khan - October 2025        │
├────────────────────────────────────────────────┤
│  EARNINGS:                                     │
│  Base Salary:              50,000 PKR         │
│  Overtime (15 hrs):         3,000 PKR [Edit]  │
│  ────────────────────────────────────────────  │
│  Gross Salary:             53,000 PKR         │
│                                                │
│  DEDUCTIONS:                                   │
│  Late (120 mins):          -1,000 PKR [Waive] │
│  Absent (2 days):          -3,333 PKR         │
│  Salary Advance:           -5,000 PKR         │
│  Loan Installment:         -2,000 PKR [Skip]  │
│  ────────────────────────────────────────────  │
│  Total Deductions:        -11,333 PKR         │
│                                                │
│  NET SALARY:               41,667 PKR         │
│                                                │
│  Attendance Summary:                           │
│  Working Days: 26 | Present: 24 | Leave: 0    │
│                                                │
│  [Save as Draft] [Approve & Generate]         │
└────────────────────────────────────────────────┘
```

**Step 3: Approve**
- Saves to `t_hr_salary_slips` with status='approved'
- Creates ledger entry (when paid)
- Updates loan balance
- Clears advance amount

---

### **Employee View - My Salary Info**

**Page:** `/riders/{id}` (Salary Tab)

```
┌────────────────────────────────────────────┐
│  My Salary Information                     │
├────────────────────────────────────────────┤
│  Base Salary: 50,000 PKR/month            │
│  Effective Date: Jan 1, 2025              │
│                                            │
│  💸 Advances:                              │
│  Current Month: 5,000 PKR                 │
│  (Will be deducted from next salary)      │
│                                            │
│  📋 Active Loans:                          │
│  Loan #1: 50,000 PKR (25,000 remaining)  │
│  Monthly Installment: 2,000 PKR           │
│                                            │
│  📄 Recent Salary Slips:                   │
│  October 2025 - 41,667 PKR [View] [PDF]  │
│  September 2025 - 48,500 PKR [View]       │
└────────────────────────────────────────────┘
```

---

## 🛠️ Implementation Steps

### **Phase 1: Database Setup** (Day 1)

**Files to create:**
1. `database/migrations/salary_management_system.sql`
   - Alter t_ops_rider_profile (add salary fields)
   - Create t_hr_employee_loans
   - Create t_hr_salary_slips
   - Add salary_advance category
   - Add new ledger accounts

**Run migration:**
```bash
mysql -u root -p nizamifarms_db < database/migrations/salary_management_system.sql
```

---

### **Phase 2: Models** (Day 1-2)

**Files to create:**

1. `app/Models/HR/EmployeeLoanModel.php`
```php
<?php
namespace App\Models\HR;

class EmployeeLoanModel extends BaseModel
{
    protected $table = 't_hr_employee_loans';
    // relationships, scopes, helpers
}
```

2. `app/Models/HR/SalarySlipModel.php`
```php
<?php
namespace App\Models\HR;

class SalarySlipModel extends BaseModel
{
    protected $table = 't_hr_salary_slips';
    // relationships, calculation methods
}
```

3. **Update:** `app/Models/CRM/RiderProfileModel.php` (create if doesn't exist)
   - Add salary fields to fillable
   - Add relationships to loans, salary slips

---

### **Phase 3: Services** (Day 2-3)

**File to create:**

`app/Services/HR/SalaryCalculationService.php`
```php
<?php
namespace App\Services\HR;

class SalaryCalculationService
{
    /**
     * Calculate salary for given user and month
     * Returns array with all earnings, deductions, net salary
     */
    public function calculateSalary($userId, $month)
    {
        // 1. Get employee salary config
        // 2. Get attendance data for month
        // 3. Calculate earnings (base + overtime)
        // 4. Calculate deductions (late, absent, advances, loans)
        // 5. Return detailed breakdown
    }
    
    /**
     * Generate and save salary slip
     */
    public function generateSlip($userId, $month, $overrides = [])
    {
        // Uses calculateSalary() 
        // Applies manual overrides
        // Saves to t_hr_salary_slips
    }
    
    /**
     * Approve slip and create ledger entry
     */
    public function approveSlip($slipId, $approverId)
    {
        // Updates slip status
        // Creates ledger entry
        // Updates loan balances
        // Clears advances
    }
}
```

---

### **Phase 4: Controllers** (Day 3-4)

**Files to create/update:**

1. `app/Http/Controllers/HR/SalarySlipController.php`
   - `index()` - List all salary slips
   - `create($userId)` - Show calculation form
   - `calculate()` - API: Calculate salary for review
   - `store()` - Save salary slip
   - `show($id)` - View salary slip details
   - `approve($id)` - Approve and post to ledger
   - `downloadPdf($id)` - Generate PDF

2. `app/Http/Controllers/HR/EmployeeLoanController.php`
   - `index()` - List all loans
   - `create()` - Loan creation form
   - `store()` - Create new loan
   - `show($id)` - Loan details & payment history
   - `update($id)` - Update loan details
   - `cancel($id)` - Cancel loan

3. **Update:** `app/Http/Controllers/CRM/RiderProfileController.php`
   - Add `updateSalary()` method
   - Add `salaryInfo($id)` method

4. **Update:** `app/Http/Controllers/Request/RequestController.php`
   - Handle salary_advance category
   - Hook into approval process

5. **Update:** `app/Models/Request/RequestModel.php`
   - Add method `postSalaryAdvanceToLedger()`
   - Call in `processApproval()` when fully approved

---

### **Phase 5: Routes** (Day 4)

**Add to `routes/web.php`:**

```php
// HR & Salary Management
Route::prefix('hr')->name('hr.')->group(function () {
    
    // Salary Slips
    Route::get('/salary-slips', [\App\Http\Controllers\HR\SalarySlipController::class, 'index'])
        ->name('salary-slips.index');
    Route::get('/salary-slips/create/{userId}', [\App\Http\Controllers\HR\SalarySlipController::class, 'create'])
        ->name('salary-slips.create');
    Route::post('/salary-slips/calculate', [\App\Http\Controllers\HR\SalarySlipController::class, 'calculate'])
        ->name('salary-slips.calculate');
    Route::post('/salary-slips', [\App\Http\Controllers\HR\SalarySlipController::class, 'store'])
        ->name('salary-slips.store');
    Route::get('/salary-slips/{id}', [\App\Http\Controllers\HR\SalarySlipController::class, 'show'])
        ->name('salary-slips.show');
    Route::post('/salary-slips/{id}/approve', [\App\Http\Controllers\HR\SalarySlipController::class, 'approve'])
        ->name('salary-slips.approve');
    Route::get('/salary-slips/{id}/pdf', [\App\Http\Controllers\HR\SalarySlipController::class, 'downloadPdf'])
        ->name('salary-slips.pdf');
    
    // Employee Loans
    Route::get('/loans', [\App\Http\Controllers\HR\EmployeeLoanController::class, 'index'])
        ->name('loans.index');
    Route::get('/loans/create', [\App\Http\Controllers\HR\EmployeeLoanController::class, 'create'])
        ->name('loans.create');
    Route::post('/loans', [\App\Http\Controllers\HR\EmployeeLoanController::class, 'store'])
        ->name('loans.store');
    Route::get('/loans/{id}', [\App\Http\Controllers\HR\EmployeeLoanController::class, 'show'])
        ->name('loans.show');
    Route::put('/loans/{id}', [\App\Http\Controllers\HR\EmployeeLoanController::class, 'update'])
        ->name('loans.update');
    Route::post('/loans/{id}/cancel', [\App\Http\Controllers\HR\EmployeeLoanController::class, 'cancel'])
        ->name('loans.cancel');
});

// Add to riders routes
Route::post('/riders/{id}/salary', [\App\Http\Controllers\CRM\RiderProfileController::class, 'updateSalary'])
    ->name('riders.salary.update');
Route::get('/riders/{id}/salary-info', [\App\Http\Controllers\CRM\RiderProfileController::class, 'salaryInfo'])
    ->name('riders.salary-info');
```

---

### **Phase 6: Views** (Day 5-6)

**Files to create:**

1. `resources/views/pages/hr/salary-slips/index.blade.php`
   - List all salary slips (filterable by user, month)
   - Bulk generation option

2. `resources/views/pages/hr/salary-slips/create.blade.php`
   - Calculation form with overrides
   - Real-time calculation preview

3. `resources/views/pages/hr/salary-slips/show.blade.php`
   - Detailed salary slip view
   - Approve/Print buttons

4. `resources/views/pages/hr/loans/index.blade.php`
   - List all loans
   - Filter by employee, status

5. `resources/views/pages/hr/loans/create.blade.php`
   - Loan creation form

6. `resources/views/pages/hr/loans/show.blade.php`
   - Loan details
   - Payment history

7. **Update:** `resources/views/pages/riders/index.blade.php`
   - Add salary column (optional)
   - Add "Generate Salary Slip" button per rider

8. **Update:** `resources/views/pages/riders/show.blade.php` (or create)
   - Add tabs for Profile, Salary, Advances, Loans, Slips

---

### **Phase 7: Permissions** (Day 6)

**Add to `t_sys_permission`:**
```sql
INSERT INTO t_sys_permission (perm_key, perm_name, perm_desc) VALUES
('view_salaries', 'View Salaries', 'Can view employee salary information'),
('manage_salaries', 'Manage Salaries', 'Can edit employee salary configurations'),
('generate_salary_slips', 'Generate Salary Slips', 'Can generate and approve salary slips'),
('view_employee_loans', 'View Employee Loans', 'Can view employee loan information'),
('manage_employee_loans', 'Manage Employee Loans', 'Can create and manage employee loans');
```

**Assign to roles:**
- Admin: All permissions
- Manager: All permissions  
- HR: All permissions
- Employee: Only view their own

---

## 📊 Where Everything Shows Up

### **1. Salary Advances**

**Visibility Locations:**

**Employee View:**
- `/riders/{id}` → "Salary & Compensation" tab
- Shows: Pending advances, approved advances waiting for deduction

**Manager View:**
- `/approvals` → Existing approvals page (already shows all pending requests)
- `/riders/{id}` → Employee detail page
- Salary slip generation screen → Shows advances to be deducted

**Request Flow:**
```
1. Employee creates request (category='salary_advance')
   → Goes to /requests
   
2. Shows up in /approvals for L1/L2 approvers
   
3. When approved:
   → Creates ledger entry (employee receives cash)
   → Tracked for next salary deduction
   
4. Shows in salary slip generation:
   → "Salary Advance: 5,000 PKR (Request #123)"
   → Automatically deducted from net salary
```

---

### **2. Loans**

**Visibility Locations:**

**Employee View:**
- `/riders/{id}` → "Advances & Loans" tab
```
┌──────────────────────────────────────────┐
│  Active Loans                            │
├──────────────────────────────────────────┤
│  📋 Loan #1                              │
│  Principal: 100,000 PKR                  │
│  Outstanding: 75,000 PKR (75%)           │
│  Monthly Installment: 5,000 PKR          │
│  Next Deduction: November 2025           │
│  [View Details]                          │
└──────────────────────────────────────────┘
```

**Manager View:**
- `/hr/loans` → All loans list page
- `/hr/loans/{id}` → Individual loan details with payment history
- `/riders/{id}` → Shows loans in employee detail
- Salary slip generation → Shows loan installment to be deducted

**Loan Flow:**
```
1. Manager creates loan via /hr/loans/create
   
2. System creates:
   → t_hr_employee_loans record (tracks balance, installment)
   → Optional: Ledger entry if disbursed via cash account
   
3. Shows in employee profile:
   → Outstanding balance
   → Next installment amount
   
4. Shows in salary slip generation:
   → "Loan Installment: 5,000 PKR (Loan #1)"
   → Manager can skip this month if needed
   
5. After salary slip approved:
   → Loan balance decreased by installment amount
   → When balance = 0, loan status → 'completed'
```

---

### **3. Salary Information**

**Visibility Locations:**

**Employee View:**
- `/riders/{id}` → "Salary & Compensation" tab
```
┌──────────────────────────────────────────┐
│  Salary Configuration                    │
├──────────────────────────────────────────┤
│  Base Salary: 50,000 PKR/month          │
│  Overtime Rate: 200 PKR/hour            │
│  Effective Date: January 1, 2025        │
│                                          │
│  📊 This Month Summary:                  │
│  Expected Gross: ~50,000 PKR            │
│  Overtime Earned: +3,000 PKR            │
│  Deductions: -8,000 PKR                 │
│  Expected Net: ~45,000 PKR              │
└──────────────────────────────────────────┘
```

**Manager View:**
- `/riders` → Riders list (add salary column - optional)
- `/riders/{id}` → Edit salary settings
```
┌──────────────────────────────────────────┐
│  Edit Salary Configuration               │
├──────────────────────────────────────────┤
│  Base Salary: [50000] PKR/month         │
│  Overtime Rate: [200] PKR/hour          │
│  Late Deduction: [100] PKR/hour         │
│  Currency: [PKR ▼]                      │
│  Effective Date: [2025-01-01]           │
│  [Save Changes]                          │
└──────────────────────────────────────────┘
```

---

### **4. Salary Slips**

**Visibility Locations:**

**Employee View:**
- `/riders/{id}` → "Salary Slips" tab
- Shows all historical salary slips
- Can view and download PDF

**Manager View:**
- `/hr/salary-slips` → Master list of all salary slips
```
┌────────────────────────────────────────────────────────┐
│  Salary Slips Management                               │
├────────────────────────────────────────────────────────┤
│  Filter: [Month ▼] [Employee ▼] [Status ▼] [Search]  │
│                                                         │
│  Employee        Month        Gross     Net    Status  │
│  ─────────────────────────────────────────────────────│
│  Ali Khan    Oct 2025   53,000   41,667   Approved    │
│  Bilal       Oct 2025   45,000   42,000   Draft       │
│  ...                                                    │
│                                                         │
│  [Generate Bulk Slips for October]                     │
└────────────────────────────────────────────────────────┘
```

- `/hr/salary-slips/create/{userId}` → Generate new slip
- `/hr/salary-slips/{id}` → View/Approve/Download specific slip
- `/riders/{id}` → Quick link "Generate Salary Slip"

---

## 🎨 UI Integration - Recommended Approach

### **Navigation Menu Structure**

```
📊 Dashboard
📦 Orders
👥 Customers
🏷️  Products
🚴 Riders & HR  ← Rename existing "Riders" menu
   ├─ 👤 Rider Profiles (existing)
   ├─ 📅 Attendance (existing)  
   ├─ 🕐 Shift Management (existing)
   ├─ 💰 Salary Slips (NEW)
   └─ 📋 Employee Loans (NEW)
💼 Requests & Approvals
💵 Finance
⚙️  Admin
```

**Why this structure?**
- ✅ Groups all employee-related functions together
- ✅ Minimal navigation changes
- ✅ Clear hierarchy
- ✅ No duplication

---

## 🔒 Security & Permissions

### **Permission Matrix**

| Feature | Admin | Manager | HR | Employee |
|---------|-------|---------|-----|----------|
| View All Salaries | ✅ | ✅ | ✅ | ❌ (Own only) |
| Edit Salary Config | ✅ | ✅ | ✅ | ❌ |
| Generate Salary Slips | ✅ | ✅ | ✅ | ❌ |
| Approve Salary Slips | ✅ | ✅ | ✅ | ❌ |
| View Own Salary Info | ✅ | ✅ | ✅ | ✅ |
| Request Advance | ✅ | ✅ | ✅ | ✅ |
| Approve Advance (L1/L2) | ✅ | ✅ (if L1/L2) | ✅ (if L1/L2) | ❌ |
| Create Loans | ✅ | ✅ | ✅ | ❌ |
| View Own Loans | ✅ | ✅ | ✅ | ✅ |

---

## 📈 Calculation Examples

### **Example 1: Standard Salary Slip**

**Employee:** Ali Khan  
**Month:** October 2025  
**Base Salary:** 50,000 PKR  
**Overtime Rate:** 200 PKR/hour  
**Late Deduction:** 100 PKR/hour  

**Attendance Summary:**
- Working Days: 26
- Present: 24 days
- Absent: 2 days  
- Late: 120 minutes (2 hours)
- Overtime: 15 hours

**Calculations:**

**EARNINGS:**
```
Base Salary:              50,000 PKR
Overtime (15 hrs × 200):   3,000 PKR
──────────────────────────────────
Gross Salary:             53,000 PKR
```

**DEDUCTIONS:**
```
Late (2 hrs × 100):       -200 PKR
Absent (2 days × 1,923):  -3,846 PKR  (50,000 ÷ 26 working days)
Salary Advance:           -5,000 PKR
Loan Installment:         -2,000 PKR
──────────────────────────────────
Total Deductions:        -11,046 PKR
```

**NET SALARY:** 53,000 - 11,046 = **41,954 PKR**

---

### **Example 2: With Manager Override**

**Same as Example 1, but manager decides to:**
- ✅ Waive late deduction (good performance this month)
- ✅ Skip loan installment (employee had emergency expense)

**DEDUCTIONS (Overridden):**
```
Late (waived):                  0 PKR ✅
Absent (2 days × 1,923):   -3,846 PKR
Salary Advance:            -5,000 PKR
Loan Installment (skipped):     0 PKR ✅
──────────────────────────────────
Total Deductions:          -8,846 PKR
```

**NET SALARY:** 53,000 - 8,846 = **44,154 PKR**

**System records:**
- `late_deduction_overridden = true`
- `loan_installment_skipped = true`
- `override_notes = "Good performance, waived late. Emergency expense, skipped loan."`

---

## 🚀 Quick Start Guide (For Implementation)

### **Minimum Viable Product (MVP) - Week 1**

**Day 1-2: Database**
1. Run migration (add salary fields, create tables)
2. Add salary_advance category
3. Create ledger accounts

**Day 3-4: Basic Salary Entry**
1. Create form to enter base salary in rider profile
2. Allow viewing salary info

**Day 5-6: Salary Calculation**
1. Create SalaryCalculationService
2. Build calculation API endpoint
3. Test calculations with real attendance data

**Day 7: Salary Slip Generation**
1. Create salary slip storage
2. Build basic approval flow
3. Test end-to-end for one employee

---

### **Full Implementation - Week 2-3**

**Week 2:**
- Salary advance requests (integrate with existing approval)
- Loan management (CRUD)
- Manager override interface

**Week 3:**
- PDF generation
- Bulk salary slip generation
- Ledger integration for payments
- UI polish & testing

---

## ✅ Testing Checklist

### **Functional Testing**

- [ ] Can set salary for an employee
- [ ] Attendance data correctly fetched
- [ ] Late minutes calculated correctly
- [ ] Overtime hours calculated correctly
- [ ] Absent days deduction calculated correctly
- [ ] Salary advance request created & approved
- [ ] Advance shows up in salary slip
- [ ] Loan created successfully
- [ ] Loan installment deducted from salary
- [ ] Manager can override late deduction
- [ ] Manager can skip loan installment
- [ ] Salary slip saved as draft
- [ ] Salary slip approved successfully
- [ ] Ledger entry created on approval
- [ ] Loan balance updated after payment
- [ ] Advance cleared after deduction
- [ ] PDF generated correctly
- [ ] Employee can view own salary info
- [ ] Employee can view own salary slips
- [ ] Permissions work correctly

### **Edge Cases**

- [ ] Employee with no attendance (how to handle?)
- [ ] Employee on full month leave (no salary?)
- [ ] Mid-month salary change (pro-rata calculation?)
- [ ] Negative salary (deductions > earnings)
- [ ] Multiple advances in same month
- [ ] Loan completed mid-month
- [ ] Duplicate salary slip prevention

---

## 📝 Summary

### **What You Get:**

✅ **Complete salary configuration** per employee  
✅ **Automatic calculations** based on attendance  
✅ **Salary advance tracking** via existing request system  
✅ **Loan management** with automatic installments  
✅ **Manager override capability** for all components  
✅ **Full integration** with existing ledger system  
✅ **No duplication** - extends riders area  
✅ **Historical records** - all salary slips stored  
✅ **Audit trail** - all overrides tracked  
✅ **PDF generation** - professional salary slips  

### **Key Advantages:**

1. **Uses existing infrastructure:**
   - Riders table for employee data
   - Request system for advances
   - Ledger system for payments
   - Approval workflow (L1/L2)

2. **User-friendly:**
   - All employee info in one place (riders page)
   - Manager has full control with overrides
   - Employee can see all info transparently

3. **Flexible:**
   - Can waive deductions
   - Can skip loan installments
   - Can adjust any component at runtime

4. **Scalable:**
   - Works for 5 employees or 500
   - Bulk slip generation supported
   - Historical data retained

---

## 🎯 Next Steps

1. **Review this plan** - confirm it matches your needs
2. **Approve approach** - especially UI integration (extend riders vs separate HR)
3. **Start Phase 1** - database setup
4. **Proceed sequentially** through phases

**Estimated Timeline:** 2-3 weeks for full implementation

**Questions to decide:**
1. Should non-rider employees (admins, managers) also have salary tracking?
   - If yes, we'll need to check role types and include them
2. Do you want automatic salary slip generation (e.g., on 1st of each month)?
3. What's the business rule for mid-month joiners/leavers?
4. Should loan disbursement require approval like advances?

---

**Ready to proceed?** Let me know and I'll start with Phase 1! 🚀

