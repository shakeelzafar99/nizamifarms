# 💼 Salary Management System - Complete User Flow Guide

**Last Updated:** October 15, 2025  
**Status:** System is live and ready to use!

---

## 🎯 Complete Workflow Overview

### Phase 1: Setup (One-Time)
1. **Set Base Salaries** for all employees
2. **Configure Rates** (overtime, late deductions)
3. **Set Employment Details** (designation, department, employee code)

### Phase 2: Monthly Operations
4. **Create Employee Loans** (as needed)
5. **Process Salary Advances** via request system
6. **Generate Salary Slips** at end of month
7. **Review & Approve** salary slips

---

## 📍 Step-by-Step Guide

### STEP 1: Set Base Salaries for Employees

**Navigate to:** HR & Salary > **Employee Salaries**  
**URL:** `/hr/employees`

**What you see:**
- List of ALL employees (riders, managers, office staff)
- Shows who has salary profiles and who doesn't
- Statistics cards showing total employees, active profiles, missing profiles, total monthly salary

**What to do:**

1. **Check for Missing Profiles:**
   - Click "Check Missing Profiles" button
   - System shows employees without salary configurations
   - Click "Yes" to create profiles for all missing employees

2. **Set Base Salary for Each Employee:**
   - Click the "Edit" ✏️ button next to an employee
   - Modal opens with salary configuration form
   
3. **Fill in Salary Details:**
   - **Base Salary (PKR):** Monthly fixed salary (e.g., 50,000)
   - **Salary Effective Date:** When this salary started
   - **Overtime Rate (PKR/hr):** How much per hour for overtime (e.g., 200)
   - **Late Deduction (PKR/hr):** How much to deduct per hour late (e.g., 150)
   - **Designation:** Job title (e.g., "Sales Manager")
   - **Department:** Department name (e.g., "Sales")
   - **Employee Code:** Internal employee ID (e.g., "EMP001")
   
4. **Optional Bank Details:**
   - Bank Name, Account Number, Account Title
   - Used if you do direct bank transfers

5. **Click "Save Changes"**

**✅ Result:** Employee now has a salary profile and can receive salary slips!

---

### STEP 2: Create Employee Loans

**Navigate to:** HR & Salary > **Employee Loans**  
**URL:** `/hr/loans`

**When to use this:**
- Employee requests a loan
- Company policy allows loans
- You want to track monthly repayment from salary

**What to do:**

1. **Click "Create Loan"** button

2. **Fill Loan Details:**
   - **Employee:** Select employee from dropdown
   - **Loan Date:** Date loan was given
   - **Principal Amount:** Total loan amount (e.g., 100,000)
   - **Monthly Installment:** How much to deduct each month (e.g., 5,000)
   - **Loan Type:** Personal, Emergency, Housing, etc.
   - **Description:** Purpose of loan
   - **Terms:** Any loan conditions (interest, duration, etc.)

3. **System calculates:**
   - Estimated duration: 100,000 ÷ 5,000 = 20 months
   - Shows "20 months (1 year 8 months)"

4. **Click "Create Loan"**

**✅ Result:**
- Loan is active
- Outstanding balance = principal amount
- System will automatically deduct monthly installment from salary slips
- Each month, outstanding balance decreases by installment amount
- When balance reaches 0, loan status changes to "completed"

**View Loan Details:**
- Click "View" 👁️ button to see full details
- Shows: principal, monthly installment, amount paid, outstanding balance
- Progress bar shows % repaid
- Future: Payment history tracking

---

### STEP 3: Salary Advance via Request System

**Navigate to:** Requests & Approvals > **My Requests**  
**URL:** `/requests`

**Linked to Request System:**
✅ Salary advances use your existing approval workflow!
✅ They automatically post to financial ledger!
✅ They automatically deduct from salary slips!

**How it works:**

1. **Employee (or Manager on behalf) Creates Request:**
   - Click "New Request"
   - **Category:** Select "Salary Advance"
   - **Amount:** PKR amount (e.g., 10,000)
   - **Description:** Reason for advance
   - Submit request

2. **Approval Flow (L1 + L2 required):**
   - **L1 Approver** reviews and approves/rejects
   - **L2 Approver** reviews and approves/rejects
   - (Salary advances require BOTH levels by default)

3. **After Final Approval:**
   - ✅ System automatically posts to ledger:
     - **Debit:** NF_CASH (company cash) - decreases
     - **Credit:** Employee Cash Account - increases
   - ✅ Employee balance updated
   - ✅ Request status = "Approved"
   - ✅ Ledger entry created with link to request

4. **At Month-End (Salary Slip Generation):**
   - System automatically finds all approved salary advances for that month
   - Adds them to "Deductions" section
   - Amount is recovered from employee's salary

**✅ Result:**
- Salary advance tracked in request system
- Posted to financial ledger
- Linked to employee cash account
- Automatically deducted from next salary

**View Salary Advances:**
- Go to Finance > NF Ledger
- Filter by employee
- See all advances with dates and amounts

---

### STEP 4: Generate Salary Slip (End of Month)

**Navigate to:** HR & Salary > **Salary Slips** > **Generate Salary Slip**  
**URL:** `/hr/salary-slips/create`

**When to use:**
- End of month
- Time to process salaries
- All attendance data is recorded

**How it works:**

#### **STEP 4A: Select Employee & Month**

1. **Select Employee** from dropdown
   - Shows only employees with salary profiles
   - Displays employee code if available

2. **Select Salary Month**
   - Pick month/year (e.g., October 2025)
   - Defaults to current month

3. **Click "Calculate Salary"**

#### **STEP 4B: System Auto-Calculates (Behind the Scenes)**

🔍 **System pulls attendance data:**
- Working days in month (e.g., 26 days)
- Present days (e.g., 24 days)
- Leave days (e.g., 2 days approved leave)
- Half days (e.g., 0)
- Absent days (e.g., 0 unauthorized)
- Late minutes (e.g., 120 minutes total)
- Overtime hours (e.g., 10 hours)

🔍 **System calculates earnings:**
- Base Salary: From employee profile (e.g., PKR 50,000)
- Overtime: 10 hours × 200/hr = PKR 2,000
- Bonuses: 0 (manager can add)
- Allowances: 0 (manager can add)
- **Gross Salary:** 50,000 + 2,000 = PKR 52,000

🔍 **System calculates deductions:**
- Late Deduction: 120 min ÷ 60 = 2 hours × 150/hr = PKR 300
- Absent Deduction: 0 days (all absences were approved leave)
- Salary Advance: PKR 10,000 (from approved request)
- Loan Installment: PKR 5,000 (from active loan)
- Tax: 0 (manager can add)
- **Total Deductions:** 300 + 10,000 + 5,000 = PKR 15,300

🔍 **Net Salary:**
- Gross (52,000) - Deductions (15,300) = **PKR 36,700**

#### **STEP 4C: Review & Customize**

**You see 3 columns:**

**LEFT: 💰 Earnings (Green)**
- Base Salary: 50,000 (read-only from profile)
- Overtime: 10 hrs → 2,000 (can override with 🔓 button)
- Bonuses: Add if applicable
- Allowances: Add if applicable
- Other Earnings: Add with description
- **Total Gross:** Auto-updates

**MIDDLE: ➖ Deductions (Red)**
- Late Deduction: 2 hrs → 300 (can override/waive with 🔓 button)
- Absent Days: 0 → 0
- Salary Advance: 10,000 (from requests, read-only)
- Loan Installment: 5,000 (from loan, can skip with 🔓 button)
- Tax: Add if applicable
- Other Deductions: Add with description
- **Total Deductions:** Auto-updates

**RIGHT: 📊 Summary (Blue/Purple)**
- Attendance summary (working days, present, leave, half days)
- **Net Salary:** Big purple display
- Manual adjustments indicator (if you override anything)
- Override notes (explain your changes)

**Manager Customization Options:**

🔓 **Override Lateness:**
- Click "Override" button next to Late Minutes
- Unlock the field
- Change late minutes or deduction amount
- Example: Waive lateness for this month (set to 0)

🔓 **Override Overtime:**
- Click "Override" button
- Adjust overtime hours or amount manually
- Example: Give bonus overtime for extra effort

🔓 **Override Absent Deduction:**
- Click "Override" button
- Adjust absent days or deduction amount
- Example: Don't deduct for illness

🔓 **Skip Loan Installment:**
- Click "Skip This Month" button
- Sets loan installment to 0 for this month
- Example: Employee requested payment holiday

➕ **Add Bonuses/Allowances:**
- Just type amounts in the fields
- Examples:
  - Bonuses: 5,000 (Performance bonus)
  - Allowances: 2,000 (Transport allowance)

➕ **Add Other Earnings/Deductions:**
- Type amount and description
- Examples:
  - Other Earnings: 3,000 (Commission)
  - Other Deductions: 1,000 (Uniform cost)

📝 **Add Override Notes:**
- If you made any adjustments, explain why
- Example: "Waived lateness deduction as employee had medical emergency"

**Net Salary updates in real-time as you make changes!**

#### **STEP 4D: Save or Approve**

Two options:

1. **Save as Draft**
   - Saves slip but keeps it editable
   - Status: Draft
   - You can come back later to approve

2. **Approve & Finalize** (if you have permission)
   - Finalizes the slip
   - Status: Approved
   - Ready for payment
   - Can download PDF

**Click your choice** → System saves slip → Redirects to salary slips list

✅ **Result:**
- Salary slip created
- Employee can view it
- Ready for payment processing
- All components tracked (earnings, deductions, attendance)
- Loan payment recorded (if applicable)
- Advance deducted (if applicable)

---

### STEP 5: View & Manage Salary Slips

**Navigate to:** HR & Salary > **Salary Slips**  
**URL:** `/hr/salary-slips`

**What you see:**
- All salary slips (all employees, all months)
- Filter by: Employee name, Month, Status
- Statistics: Total slips, Draft, Approved, Paid, Total net salary

**Slip Statuses:**
- 🔵 **Draft:** Created but not finalized (can edit)
- 🟡 **Approved:** Finalized, ready for payment
- 🟢 **Paid:** Payment completed
- 🔴 **Cancelled:** Slip was cancelled

**Actions on Slips:**

- **View Details** 👁️: See full salary breakdown
- **Approve** ✅ (if draft): Finalize the slip
- **Download PDF** 📥 (if approved/paid): Get printable salary slip
- **Cancel** 🗑️ (if draft): Delete the slip

**Salary Slip Details View:**
- Shows complete breakdown
- Earnings vs Deductions
- Attendance summary
- Net salary
- Override notes (if any)
- Links to salary advance requests
- Links to loan installments

---

## 🔗 How Everything is Linked

### Salary Advance Request → Ledger → Salary Slip

```
1. Employee submits salary advance request (PKR 10,000)
   ↓
2. L1 approves
   ↓
3. L2 approves
   ↓
4. System auto-posts to ledger:
   - Transaction Type: salary_advance
   - From: NF_CASH (company)
   - To: Employee Cash Account
   - Amount: 10,000
   - Request linked
   ↓
5. At month-end, generate salary slip:
   - System finds all approved salary_advance requests for that month
   - Adds to deductions automatically
   - Links request ID to slip
   ↓
6. Employee receives net salary (after advance deduction)
```

### Employee Loan → Salary Slip → Loan Payment

```
1. Manager creates loan:
   - Principal: 100,000
   - Monthly Installment: 5,000
   - Status: Active
   ↓
2. Each month, generate salary slip:
   - System finds active loans
   - Adds monthly installment to deductions
   - Links loan ID to slip
   ↓
3. On slip approval:
   - Create loan payment record
   - Update outstanding balance: 100,000 - 5,000 = 95,000
   ↓
4. After 20 months:
   - Outstanding balance: 0
   - Loan status: Completed
```

### Attendance → Salary Slip

```
1. Employee clocks in/out daily (existing attendance system)
   ↓
2. System tracks:
   - Check-in time (late if after shift start)
   - Check-out time (overtime if after shift end)
   - Absent days (no attendance record)
   - Leave days (approved leave requests)
   ↓
3. At month-end, generate salary slip:
   - System queries attendance for that month
   - Calculates:
     • Total late minutes → Late deduction
     • Total overtime hours → Overtime pay
     • Absent days → Absent deduction
     • Present days, leave days, half days
   ↓
4. All attendance data appears in salary slip
   - Manager can override if needed
```

---

## 📊 Key Reports & Views

### Employee Salaries Page
- See all employees with salary configurations
- Quick edit salary details
- Check missing profiles
- View total monthly payroll

### Salary Slips Page
- All salary slips across all months
- Filter by employee, month, status
- Bulk view of payroll for a month
- Total amount to be paid

### Employee Loans Page
- All active and completed loans
- Outstanding balances
- Loan repayment progress
- Total amount in employee loans

### Finance > NF Ledger
- All salary advances posted
- Employee cash account balances
- Ledger entries for advances

### Requests > My Requests
- Salary advance requests
- Approval status
- Approved amounts

---

## ❓ Common Questions

**Q: Where do I set the base salary?**  
A: HR & Salary > Employee Salaries > Click Edit button for that employee

**Q: Where can I create a loan for an employee?**  
A: HR & Salary > Employee Loans > Click "Create Loan" button

**Q: How do salary advances work?**  
A: Employee submits via Requests system (category: Salary Advance) → L1/L2 approve → Auto-posts to ledger → Auto-deducts from next salary slip

**Q: Can I waive lateness deduction for a particular month?**  
A: Yes! When generating salary slip, click "Override" button next to Late Deduction and set it to 0

**Q: What if employee took multiple advances in one month?**  
A: All approved advances for that month are summed and added to deductions automatically

**Q: Can I skip loan installment for one month?**  
A: Yes! When generating salary slip, click "Skip This Month" button next to Loan Installment

**Q: Where does attendance data come from?**  
A: Your existing attendance system (clock-in/clock-out records)

**Q: Can I add bonuses or deductions not related to attendance?**  
A: Yes! Add them in "Bonuses", "Allowances", "Other Earnings", or "Other Deductions" fields

**Q: Can employees see their own salary slips?**  
A: Yes, they have `view_own_salary` permission (to be implemented in employee portal)

**Q: Do admins get salary profiles?**  
A: No, the system excludes admin users from salary management

---

## 🎯 Best Practices

1. **Setup Phase:**
   - Create salary profiles for all employees FIRST
   - Set accurate overtime and late deduction rates
   - Use employee codes for better tracking

2. **Monthly Workflow:**
   - Process salary advances via request system (proper approval)
   - Generate salary slips after attendance is finalized
   - Review slips before approving
   - Document any overrides in notes

3. **Loan Management:**
   - Create loans with clear terms
   - Set monthly installment that employee can afford
   - Track repayment progress regularly

4. **Approval Discipline:**
   - Don't skip approvals for salary advances
   - Use L1+L2 approval for accountability
   - Document reasons for overrides

5. **Record Keeping:**
   - Download approved salary slip PDFs
   - Keep records of all salary changes
   - Maintain salary history

---

## 🚨 Troubleshooting

**Issue: Can't find an employee in salary slip generation**  
**Fix:** Create salary profile for that employee first (HR & Salary > Employee Salaries)

**Issue: Salary advance not appearing in deductions**  
**Fix:** Check if advance was approved in the same month as salary slip. Only advances approved in that specific month are deducted.

**Issue: Loan installment not being deducted**  
**Fix:** Check if loan status is "active". Completed or cancelled loans don't deduct.

**Issue: Attendance data not showing correctly**  
**Fix:** Verify attendance records exist for that employee in that month

**Issue: Can't edit salary slip**  
**Fix:** Only "draft" slips can be edited. Approved slips are final.

---

## 📞 Support

If you need help:
1. Check this guide first
2. Check Laravel logs: `storage/logs/laravel.log`
3. Check browser console for JavaScript errors
4. Verify database tables were created (SQL scripts ran successfully)
5. Verify permissions are assigned to your role

---

**System is now fully operational!** 🎉

Use this guide as your reference for daily operations. All features are integrated with your existing app functionality!

