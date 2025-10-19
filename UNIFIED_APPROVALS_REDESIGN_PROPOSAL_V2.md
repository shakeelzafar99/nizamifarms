# Unified Approvals System - Redesign Proposal V2 (FINAL)

## 📋 Updates Based on Feedback

### ✅ Confirmed Decisions:
1. **Keep quick approval flows** in Online Bank Ledger, Expense Management, and other places
2. **Rejected items get separate card** (not mixed with approved)
3. **Two-layer card system**: Level cards + Area cards
4. **Area-based filtering** for 3 managers + "Others" category
5. **Compact design** to give table breathing room

---

## 🎨 New Two-Layer Card Design

### **Layer 1: Approval Level Cards (Top Row)**
Compact, horizontal layout - shows high-level overview

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                         🎯 Approvals Dashboard                               │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                               │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐   │
│  │📋 L1 PENDING │  │📋 L2 PENDING │  │✅ APPROVED   │  │❌ REJECTED   │   │
│  │  12 items    │  │   5 items    │  │  45 items    │  │   3 items    │   │
│  │ Rs. 15,450   │  │  Rs. 8,200   │  │ Rs. 125,000  │  │  Rs. 2,500   │   │
│  └──────────────┘  └──────────────┘  └──────────────┘  └──────────────┘   │
│                                                                               │
└─────────────────────────────────────────────────────────────────────────────┘
```

### **Layer 2: Area/Account Cards (Second Row)**
Only visible when L1/L2 Pending is selected - shows breakdown by responsible area

```
┌─────────────────────────────────────────────────────────────────────────────┐
│  Filter by Area:                                                             │
│                                                                               │
│  ┌────────────┐  ┌────────────┐  ┌────────────┐  ┌────────────┐           │
│  │💰 EXP FUND │  │💵 NF CASH  │  │🏦 ONLINE   │  │📦 OTHERS   │           │
│  │  3 items   │  │  2 items   │  │  1 item    │  │  6 items   │           │
│  │ Rs. 2,450  │  │ Rs. 5,500  │  │ Rs. 4,875  │  │ Rs. 2,625  │           │
│  └────────────┘  └────────────┘  └────────────┘  └────────────┘           │
│                                                                               │
└─────────────────────────────────────────────────────────────────────────────┘
```

---

## 🎯 Complete User Flow

### **Step 1: Landing Page (Default View)**
User sees Layer 1 cards only + full table below

```
┌─────────────────────────────────────────────────────────────────────────────┐
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐   │
│  │📋 L1 PENDING │  │📋 L2 PENDING │  │✅ APPROVED   │  │❌ REJECTED   │   │
│  │  12 items    │  │   5 items    │  │  45 items    │  │   3 items    │   │
│  │ Rs. 15,450   │  │  Rs. 8,200   │  │ Rs. 125,000  │  │  Rs. 2,500   │   │
│  └──────────────┘  └──────────────┘  └──────────────┘  └──────────────┘   │
└─────────────────────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────────────────────┐
│  📋 All Pending Approvals (17 items)                                        │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                               │
│  REQUEST #      REQUESTER   CATEGORY        AREA      AMOUNT    LEVEL  DATE │
│  ────────────────────────────────────────────────────────────────────────── │
│  REQ-202510-11  Waseem      Expense         EXP_FUND  Rs. 500   L1    Oct12│
│  REQ-202510-12  Kanan       Expense         EXP_FUND  Rs. 245   L1    Oct12│
│  REQ-202510-13  Arslan      Leave           -         3 days    L1    Oct15│
│  Invoice #0002  -           Online Invoice  ONLINE    Rs. 4,875 -     Oct15│
│  REQ-202510-08  Waseem      Salary Advance  NF_CASH   Rs.10,000 L2    Oct10│
│  ...                                                                         │
│                                                                               │
└─────────────────────────────────────────────────────────────────────────────┘
```

### **Step 2: User Clicks "L1 PENDING"**
Layer 2 (Area cards) appears, table filters to L1 only

```
┌─────────────────────────────────────────────────────────────────────────────┐
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐   │
│  │📋 L1 PENDING │  │📋 L2 PENDING │  │✅ APPROVED   │  │❌ REJECTED   │   │
│  │  ⭐ACTIVE⭐  │  │   5 items    │  │  45 items    │  │   3 items    │   │
│  │  12 items    │  │  Rs. 8,200   │  │ Rs. 125,000  │  │  Rs. 2,500   │   │
│  │ Rs. 15,450   │  └──────────────┘  └──────────────┘  └──────────────┘   │
│  └──────────────┘                                                           │
└─────────────────────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────────────────────┐
│  Filter by Area:                                                             │
│  ┌────────────┐  ┌────────────┐  ┌────────────┐  ┌────────────┐           │
│  │💰 EXP FUND │  │💵 NF CASH  │  │🏦 ONLINE   │  │📦 OTHERS   │           │
│  │  3 items   │  │  2 items   │  │  1 item    │  │  6 items   │           │
│  │ Rs. 2,450  │  │ Rs. 5,500  │  │ Rs. 4,875  │  │ Rs. 2,625  │           │
│  └────────────┘  └────────────┘  └────────────┘  └────────────┘           │
└─────────────────────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────────────────────┐
│  📋 Level 1 Pending (12 items)                          [Clear Filters]     │
├─────────────────────────────────────────────────────────────────────────────┤
│  REQUEST #      REQUESTER   CATEGORY        AREA      AMOUNT    LEVEL  DATE │
│  ────────────────────────────────────────────────────────────────────────── │
│  REQ-202510-11  Waseem      Expense         EXP_FUND  Rs. 500   L1    Oct12│
│  REQ-202510-12  Kanan       Expense         EXP_FUND  Rs. 245   L1    Oct12│
│  REQ-202510-13  Arslan      Leave           OTHERS    3 days    L1    Oct15│
│  Invoice #0002  -           Online Invoice  ONLINE    Rs. 4,875 -     Oct15│
│  ...                                                                         │
└─────────────────────────────────────────────────────────────────────────────┘
```

### **Step 3: User Further Clicks "EXP FUND"**
Double filter: L1 + EXP_FUND area

```
┌─────────────────────────────────────────────────────────────────────────────┐
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐   │
│  │📋 L1 PENDING │  │📋 L2 PENDING │  │✅ APPROVED   │  │❌ REJECTED   │   │
│  │  ⭐ACTIVE⭐  │  │   5 items    │  │  45 items    │  │   3 items    │   │
│  └──────────────┘  └──────────────┘  └──────────────┘  └──────────────┘   │
└─────────────────────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────────────────────┐
│  Filter by Area:                                                             │
│  ┌────────────┐  ┌────────────┐  ┌────────────┐  ┌────────────┐           │
│  │💰 EXP FUND │  │💵 NF CASH  │  │🏦 ONLINE   │  │📦 OTHERS   │           │
│  │ ⭐ACTIVE⭐ │  │  2 items   │  │  1 item    │  │  6 items   │           │
│  │  3 items   │  │ Rs. 5,500  │  │ Rs. 4,875  │  │ Rs. 2,625  │           │
│  │ Rs. 2,450  │  └────────────┘  └────────────┘  └────────────┘           │
│  └────────────┘                                                             │
└─────────────────────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────────────────────┐
│  📋 L1 Pending > EXP FUND (3 items)                     [Clear Filters]     │
├─────────────────────────────────────────────────────────────────────────────┤
│  REQUEST #      REQUESTER   CATEGORY        AREA      AMOUNT    LEVEL  DATE │
│  ────────────────────────────────────────────────────────────────────────── │
│  REQ-202510-11  Waseem      Expense         EXP_FUND  Rs. 500   L1    Oct12│
│  REQ-202510-12  Kanan       Expense         EXP_FUND  Rs. 245   L1    Oct12│
│  REQ-202510-17  Ali         Expense         EXP_FUND  Rs. 1,705 L1    Oct16│
│                                                                               │
└─────────────────────────────────────────────────────────────────────────────┘
```

---

## 🗂️ Area Mapping Logic

### **EXP_FUND Area:**
- Expense requests with `payment_source_account_id` = EXP_FUND account
- Expense-related ledger transactions from/to EXP_FUND
- Managed by: User 1 (Expense Manager)

### **NF_CASH Area:**
- Cash deposits (rider to main till)
- Cash transactions from/to NF_CASH account
- Salary advances
- Vendor payments (cash)
- Managed by: User 2 (Cash Manager)

### **ONLINE Area:**
- Online/Bank invoice approvals
- Online payment transactions
- Bank transfers
- Managed by: User 3 (Online/Bank Manager)

### **OTHERS Area:**
- Leave requests (not tied to financial account)
- Equipment requests
- Ledger adjustments
- Any request without specific account mapping
- General requests
- Managed by: All approvers / Admin

---

## 📐 Compact Layout Design

### **Spacing Strategy:**

```
┌─────────────────────────────────────────────────────────────────────────────┐
│  HEADER (60px)                                                               │
├─────────────────────────────────────────────────────────────────────────────┤
│  LAYER 1 CARDS (80px) - Compact, 4 cards in a row                          │
├─────────────────────────────────────────────────────────────────────────────┤
│  LAYER 2 CARDS (70px) - Only shows when L1/L2 selected, 4 cards in a row   │
│  (Collapsed by default, slides down when needed)                            │
├─────────────────────────────────────────────────────────────────────────────┤
│  TABLE HEADER (40px) - Title + Clear Filters button                         │
├─────────────────────────────────────────────────────────────────────────────┤
│  TABLE CONTENT (Remaining height - auto scroll)                             │
│  - Compact rows (40px each)                                                 │
│  - Expandable for details                                                   │
│  - Pagination at bottom                                                     │
│                                                                               │
│                                                                               │
│                                                                               │
└─────────────────────────────────────────────────────────────────────────────┘

Total Cards Height: 80px (Layer 1) + 70px (Layer 2 when visible) = 150px max
Table gets: 100vh - 250px (header + cards + margins) = Plenty of space!
```

### **Card Size:**
- **Layer 1 Cards**: 
  - Width: 24% each (4 cards fit in row with gaps)
  - Height: 80px
  - Padding: Compact (12px)
  - Font: Title 11px, Count 18px bold, Amount 14px

- **Layer 2 Cards**:
  - Width: 24% each (4 cards fit in row)
  - Height: 70px
  - Padding: Compact (10px)
  - Font: Title 10px, Count 16px bold, Amount 12px

### **Responsive Behavior:**
- **Desktop (>1200px)**: 4 cards per row (both layers)
- **Tablet (768-1200px)**: 2 cards per row (both layers)
- **Mobile (<768px)**: 1 card per row, Layer 2 collapses into dropdown

---

## 🎨 Visual Design Specs

### **Layer 1 Cards:**
```css
.level-card {
  background: white;
  border: 2px solid #e5e7eb;
  border-radius: 8px;
  padding: 12px;
  cursor: pointer;
  transition: all 0.2s;
  height: 80px;
}

.level-card:hover {
  border-color: #3b82f6;
  box-shadow: 0 4px 6px rgba(0,0,0,0.1);
  transform: translateY(-2px);
}

.level-card.active {
  border-color: #3b82f6;
  background: #eff6ff;
  border-width: 3px;
}

.level-card .title {
  font-size: 11px;
  font-weight: 600;
  color: #6b7280;
  text-transform: uppercase;
}

.level-card .count {
  font-size: 18px;
  font-weight: 700;
  color: #111827;
  margin: 4px 0;
}

.level-card .amount {
  font-size: 14px;
  font-weight: 600;
  color: #059669;
}
```

### **Layer 2 Cards:**
```css
.area-card {
  background: #f9fafb;
  border: 1px solid #d1d5db;
  border-radius: 6px;
  padding: 10px;
  cursor: pointer;
  transition: all 0.2s;
  height: 70px;
}

.area-card:hover {
  background: white;
  border-color: #9ca3af;
  box-shadow: 0 2px 4px rgba(0,0,0,0.05);
}

.area-card.active {
  background: white;
  border-color: #3b82f6;
  border-width: 2px;
}

.area-card .icon {
  font-size: 20px;
  margin-bottom: 4px;
}

.area-card .title {
  font-size: 10px;
  font-weight: 600;
  color: #6b7280;
}

.area-card .stats {
  font-size: 12px;
  font-weight: 600;
  color: #111827;
}
```

### **Color Scheme:**
- **L1 Pending**: Yellow accent (#f59e0b)
- **L2 Pending**: Blue accent (#3b82f6)
- **Approved**: Green accent (#10b981)
- **Rejected**: Red accent (#ef4444)
- **EXP_FUND**: Orange (#f97316)
- **NF_CASH**: Green (#22c55e)
- **ONLINE**: Blue (#3b82f6)
- **OTHERS**: Gray (#6b7280)

---

## 🔄 Filter State Management

### **JavaScript State Object:**
```javascript
window.approvalFilters = {
  level: null,        // 'l1', 'l2', 'approved', 'rejected', or null
  area: null,         // 'exp_fund', 'nf_cash', 'online', 'others', or null
  dateFrom: null,
  dateTo: null,
  search: ''
};

function applyFilters() {
  // Show/hide Layer 2 based on level selection
  if (filters.level === 'l1' || filters.level === 'l2') {
    showLayer2Cards();
  } else {
    hideLayer2Cards();
  }
  
  // Update URL params
  const params = new URLSearchParams(filters);
  window.history.pushState({}, '', `?${params}`);
  
  // Reload table data via AJAX
  loadTableData(filters);
  
  // Update active states on cards
  updateCardStates();
}

function clearFilters() {
  window.approvalFilters = {
    level: null,
    area: null,
    dateFrom: null,
    dateTo: null,
    search: ''
  };
  hideLayer2Cards();
  applyFilters();
}
```

---

## 📊 Backend Data Structure

### **Controller Method:**
```php
public function index(Request $request)
{
    $user = auth()->user();
    $hasL1 = RoleApprovalLevelModel::userHasApprovalLevel($user->id, 1);
    $hasL2 = RoleApprovalLevelModel::userHasApprovalLevel($user->id, 2);
    
    // Get account IDs for area mapping
    $expFundAccount = AccountModel::getByCode('EXP_FUND');
    $nfCashAccount = AccountModel::getByCode('NF_CASH');
    $onlineAccount = AccountModel::getByCode('ONLINE');
    
    // Build base queries
    $l1Items = $this->getL1PendingItems($user, $expFundAccount, $nfCashAccount, $onlineAccount);
    $l2Items = $hasL2 ? $this->getL2PendingItems($user, $expFundAccount, $nfCashAccount, $onlineAccount) : [];
    $approvedItems = $this->getApprovedItems($request->date_from, $request->date_to);
    $rejectedItems = $this->getRejectedItems($request->date_from, $request->date_to);
    
    // Calculate summaries for Layer 1
    $summaries = [
        'l1' => [
            'count' => count($l1Items),
            'amount' => array_sum(array_column($l1Items, 'amount')),
            'by_area' => $this->groupByArea($l1Items)
        ],
        'l2' => [
            'count' => count($l2Items),
            'amount' => array_sum(array_column($l2Items, 'amount')),
            'by_area' => $this->groupByArea($l2Items)
        ],
        'approved' => [
            'count' => count($approvedItems),
            'amount' => array_sum(array_column($approvedItems, 'amount'))
        ],
        'rejected' => [
            'count' => count($rejectedItems),
            'amount' => array_sum(array_column($rejectedItems, 'amount'))
        ]
    ];
    
    return view('approvals.unified', compact(
        'summaries',
        'hasL1',
        'hasL2'
    ));
}

private function groupByArea($items)
{
    $grouped = [
        'exp_fund' => ['count' => 0, 'amount' => 0],
        'nf_cash' => ['count' => 0, 'amount' => 0],
        'online' => ['count' => 0, 'amount' => 0],
        'others' => ['count' => 0, 'amount' => 0]
    ];
    
    foreach ($items as $item) {
        $area = $item['area'] ?? 'others';
        $grouped[$area]['count']++;
        $grouped[$area]['amount'] += $item['amount'] ?? 0;
    }
    
    return $grouped;
}
```

### **Area Determination Logic:**
```php
private function determineArea($item)
{
    // For requests with payment source
    if (isset($item->payment_source_account_id)) {
        $account = AccountModel::find($item->payment_source_account_id);
        if ($account) {
            if ($account->account_code === 'EXP_FUND') return 'exp_fund';
            if ($account->account_code === 'NF_CASH') return 'nf_cash';
            if ($account->account_code === 'ONLINE') return 'online';
        }
    }
    
    // For ledger transactions
    if (isset($item->from_account_id) || isset($item->to_account_id)) {
        $fromAccount = AccountModel::find($item->from_account_id);
        $toAccount = AccountModel::find($item->to_account_id);
        
        // Check if either account is in our key areas
        if ($fromAccount && $fromAccount->account_code === 'EXP_FUND') return 'exp_fund';
        if ($toAccount && $toAccount->account_code === 'EXP_FUND') return 'exp_fund';
        
        if ($fromAccount && $fromAccount->account_code === 'NF_CASH') return 'nf_cash';
        if ($toAccount && $toAccount->account_code === 'NF_CASH') return 'nf_cash';
        
        if ($fromAccount && $fromAccount->account_code === 'ONLINE') return 'online';
        if ($toAccount && $toAccount->account_code === 'ONLINE') return 'online';
    }
    
    // For salary advances - typically NF_CASH area
    if (isset($item->category) && $item->category->category_code === 'salary_advance') {
        return 'nf_cash';
    }
    
    // Default to others
    return 'others';
}
```

---

## 📱 Mobile Optimization

### **Mobile Layout (<768px):**

```
┌─────────────────┐
│ 📋 L1 PENDING   │
│   12 items      │
│  Rs. 15,450     │
└─────────────────┘
┌─────────────────┐
│ 📋 L2 PENDING   │
│    5 items      │
│   Rs. 8,200     │
└─────────────────┘
┌─────────────────┐
│ ✅ APPROVED     │
│   45 items      │
│  Rs. 125,000    │
└─────────────────┘
┌─────────────────┐
│ ❌ REJECTED     │
│    3 items      │
│   Rs. 2,500     │
└─────────────────┘

[Filter by Area ▼]  <-- Dropdown instead of cards
```

When area dropdown is selected, it filters the table below.

---

## ✅ Implementation Checklist

### **Phase 1: Backend (3-4 hours)**
- [ ] Refactor `ApprovalController` with area mapping logic
- [ ] Add `determineArea()` helper method
- [ ] Update queries to include area information
- [ ] Add `groupByArea()` method for summaries
- [ ] Create AJAX endpoint for filtered table data
- [ ] Add date range filtering for approved/rejected

### **Phase 2: Frontend - Layer 1 Cards (2 hours)**
- [ ] Create 4 compact cards (L1, L2, Approved, Rejected)
- [ ] Add click handlers for filtering
- [ ] Add active state styling
- [ ] Add count and amount display
- [ ] Make responsive (stack on mobile)

### **Phase 3: Frontend - Layer 2 Cards (2 hours)**
- [ ] Create 4 area cards (EXP_FUND, NF_CASH, ONLINE, OTHERS)
- [ ] Add show/hide logic (only when L1/L2 selected)
- [ ] Add click handlers for area filtering
- [ ] Add active state styling
- [ ] Convert to dropdown on mobile

### **Phase 4: Table Integration (2 hours)**
- [ ] Update table to respect filters
- [ ] Add AJAX loading for filtered data
- [ ] Add "Clear Filters" button
- [ ] Add filter breadcrumb (e.g., "L1 Pending > EXP FUND")
- [ ] Add loading states

### **Phase 5: State Management (1 hour)**
- [ ] Implement filter state object
- [ ] Add URL parameter sync
- [ ] Add browser back/forward support
- [ ] Persist filter state in session

### **Phase 6: Polish & Testing (2 hours)**
- [ ] Test all filter combinations
- [ ] Test with different user roles (L1 only, L2 only, both)
- [ ] Test mobile responsiveness
- [ ] Test quick approval flows (keep existing)
- [ ] Performance optimization

**Total: 12-13 hours**

---

## 🚀 Ready to Implement?

This design:
✅ Keeps quick approval flows in existing places
✅ Has separate rejected card
✅ Two-layer card system (Level + Area)
✅ Area-based filtering for 3 managers + others
✅ Compact design - table gets plenty of space
✅ Both count and amount on all cards
✅ Fully filterable and interactive

**Shall I proceed with implementation?** 🎯

