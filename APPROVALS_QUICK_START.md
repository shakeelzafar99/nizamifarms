# Approvals System - Quick Start Guide

## 🚀 Get Started in 5 Minutes

### Step 1: Run Database Migration (2 minutes)
```bash
# Connect to your database
mysql -u root -p nizamifarms

# Run the migration
source database/migrations/PRODUCTION_approval_system_migration.sql;

# Verify
SHOW COLUMNS FROM t_fin_ledger LIKE 'approval_status';
# Should show: pending, pending_l1, pending_l2, approved, rejected, reversed
```

### Step 2: Configure Routing Rules (2 minutes)
1. Log in to web app
2. Go to **Requests → Request Settings**
3. Expand "Invoice Approval" category
4. Set:
   - **Level 1**: Select a user (e.g., Shabib)
   - **Payment Source**: Select "Online Bank"
   - Click "Save"
5. Repeat for other categories as needed

### Step 3: Test the Flow (1 minute)
1. Create an order with payment method = "Online"
2. Mark order as delivered
3. Go to **Approvals Dashboard** (side menu)
4. You should see the invoice in "L1 Pending"
5. Click "View & Approve" → Approve
6. Invoice moves to "L2 Pending"
7. Approve again → Fully approved!

---

## 📱 Mobile Setup

### Deploy Mobile App
```bash
cd NizamiFarmsMobile
npm install
npm run build:android  # or build:ios
```

### Test Mobile
1. Open app in Store Mode
2. Tap hamburger menu → "Approvals"
3. See pending items
4. Tap "View & Approve" → Opens browser
5. Auto-syncs every 60 seconds

---

## 🔑 Key Concepts

### Approval Statuses
- **`pending_l1`**: Waiting for Level 1 approval
- **`pending_l2`**: Waiting for Level 2 approval
- **`approved`**: Fully approved

### Virtual Assignment
- No physical column in database
- Assignment calculated based on routing rules
- Historical data automatically reflects new rules

### Request vs Ledger Flow
- **Request flow**: Leave, Expense (creates request first)
- **Ledger flow**: Online Invoice, Deposit (creates ledger entry directly)

---

## 📊 Quick Reference

### Web URLs
- **Approvals Dashboard**: `/approvals`
- **Request Settings**: `/requests/settings`
- **Ledger**: `/ledger`

### Mobile API
- **Get Approvals**: `GET /api/approvals`
- **Filters**: `?level=l1&area=online&assignee_id=5`

### Database Tables
- **Routing Rules**: `t_req_approval_rules`
- **Assignees**: `t_req_approval_rule_assignees`
- **Ledger**: `t_fin_ledger` (approval_status column)
- **Requests**: `t_req_master` (approval_status column)

---

## 🐛 Troubleshooting

### Items not showing?
✅ Check routing rules in Request Settings  
✅ Verify user has L1 or L2 permissions  
✅ Refresh the page

### L1 approval fully approves?
✅ Ensure L2 is configured in Request Settings

### Mobile not syncing?
✅ Check network connection  
✅ Pull to refresh manually

---

## 📚 Full Documentation

- **Complete Guide**: `APPROVALS_SYSTEM_COMPLETE_SUMMARY.md`
- **Testing Guide**: `APPROVALS_TESTING_GUIDE.md`
- **Mobile Details**: `APPROVALS_MOBILE_IMPLEMENTATION_COMPLETE.md`

---

## ✅ Checklist

- [ ] Database migration run
- [ ] Routing rules configured
- [ ] Test approval created
- [ ] L1 approval works
- [ ] L2 approval works
- [ ] Mobile app deployed
- [ ] Mobile approvals tested

---

**Need Help?** Check the full documentation or review the testing guide!

