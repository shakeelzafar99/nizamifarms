# 📱 Mobile App UI Mockups - Rider App

This document shows what the rider mobile app will look like.

---

## 🎨 **Design Principles**

1. **Large, Touch-Friendly Buttons** - Easy for riders to tap while on the move
2. **Minimal Text Input** - Most actions are taps/selections
3. **Clear Status Indicators** - Colors and icons show status at a glance
4. **Card-Based Layout** - Each order/request is a card
5. **Bottom Navigation** - Quick access to main sections

---

## 📱 **Screen Mockups**

### **1. Login Screen**

```
╔═══════════════════════════════════╗
║                                   ║
║           🚚                      ║
║      Nizami Farms                 ║
║      Rider Portal                 ║
║                                   ║
║   ┌─────────────────────────────┐ ║
║   │ 📧 Email                    │ ║
║   │ rider@example.com           │ ║
║   └─────────────────────────────┘ ║
║                                   ║
║   ┌─────────────────────────────┐ ║
║   │ 🔒 Password                 │ ║
║   │ ••••••••                    │ ║
║   └─────────────────────────────┘ ║
║                                   ║
║   ┌───────────────────────────┐   ║
║   │     LOGIN  →              │   ║  Large green button
║   └───────────────────────────┘   ║
║                                   ║
║   Forgot Password?                ║
║                                   ║
╚═══════════════════════════════════╝
```

---

### **2. Home Dashboard**

```
╔═══════════════════════════════════╗
║ ☰  Nizami Farms       🔔      ⚙️  ║  Header
║                                   ║
║   👋 Hi, Ali (Rider)              ║
║   📅 Thursday, Oct 16, 2025       ║
║                                   ║
║  ┌─────────────────────────────┐  ║
║  │  📦 MY ORDERS                │  ║
║  │  ───────────────             │  ║
║  │  5 Orders Pending            │  ║
║  │  2 Ready for Delivery  →     │  ║
║  └─────────────────────────────┘  ║
║                                   ║
║  ┌─────────────────────────────┐  ║
║  │  💰 MY LEDGER                │  ║
║  │  ───────────────             │  ║
║  │  Balance: Rs. 15,000 Dr      │  ║
║  │  View Details  →             │  ║
║  └─────────────────────────────┘  ║
║                                   ║
║  ┌─────────────────────────────┐  ║
║  │  📝 MY REQUESTS              │  ║
║  │  ───────────────             │  ║
║  │  3 Pending Requests          │  ║
║  │  View & Create  →            │  ║
║  └─────────────────────────────┘  ║
║                                   ║
║  ┌─────────────────────────────┐  ║
║  │  ⏰ ATTENDANCE               │  ║
║  │  ───────────────             │  ║
║  │  Not Clocked In Today        │  ║
║  │  Clock In  →                 │  ║
║  └─────────────────────────────┘  ║
║                                   ║
╠═══════════════════════════════════╣
║  📦       💰       📝       👤    ║  Bottom Navigation
║ Orders  Ledger  Requests  Profile ║
╚═══════════════════════════════════╝
```

---

### **3. Orders List Screen**

```
╔═══════════════════════════════════╗
║ ←  My Orders           🔍         ║
║                                   ║
║ Filters: [All] [Ready] [Delivered]║
║                                   ║
║ ┌───────────────────────────────┐ ║
║ │ Order #12345       🟢 Ready   │ ║  Green badge
║ │ ─────────────────────────────│ ║
║ │ Ali Khan                      │ ║
║ │ 📍 Model Town, Lahore         │ ║
║ │ 💰 Rs. 2,500                  │ ║
║ │ 🕐 Assigned 2 hours ago       │ ║
║ │                               │ ║
║ │ [View Details →]              │ ║
║ └───────────────────────────────┘ ║
║                                   ║
║ ┌───────────────────────────────┐ ║
║ │ Order #12346    🟡 In Transit │ ║  Yellow badge
║ │ ─────────────────────────────│ ║
║ │ Sara Ahmad                    │ ║
║ │ 📍 Gulberg, Lahore            │ ║
║ │ 💰 Rs. 3,200                  │ ║
║ │ 🕐 Out for 30 mins            │ ║
║ │                               │ ║
║ │ [Mark Delivered]              │ ║  Green button
║ └───────────────────────────────┘ ║
║                                   ║
║ ┌───────────────────────────────┐ ║
║ │ Order #12344   ✅ Delivered   │ ║  Gray
║ │ ─────────────────────────────│ ║
║ │ Ahmed Ali                     │ ║
║ │ 💰 Rs. 1,800                  │ ║
║ │ ✓ Delivered today at 2:30 PM  │ ║
║ └───────────────────────────────┘ ║
║                                   ║
╠═══════════════════════════════════╣
║  📦       💰       📝       👤    ║
║ Orders  Ledger  Requests  Profile ║
╚═══════════════════════════════════╝
```

---

### **4. Order Details Screen**

```
╔═══════════════════════════════════╗
║ ←  Order #12345                   ║
║                                   ║
║ Status: 🟢 Ready for Delivery     ║
║                                   ║
║ ┌───────────────────────────────┐ ║
║ │ 👤 CUSTOMER DETAILS           │ ║
║ │ ─────────────────────────────│ ║
║ │ Name: Ali Khan                │ ║
║ │ Phone: 0300-1234567           │ ║
║ │ Address: House 23, Street 5   │ ║
║ │          Model Town, Lahore   │ ║
║ │                               │ ║
║ │ [📞 Call]  [🗺️ Navigate]     │ ║
║ └───────────────────────────────┘ ║
║                                   ║
║ ┌───────────────────────────────┐ ║
║ │ 📦 ORDER ITEMS                │ ║
║ │ ─────────────────────────────│ ║
║ │ • 2kg Chicken     Rs. 1,200   │ ║
║ │ • 1kg Beef        Rs. 1,000   │ ║
║ │ • 500g Mutton     Rs. 300     │ ║
║ │                   ─────────   │ ║
║ │ Subtotal:         Rs. 2,500   │ ║
║ │ Delivery:         Free        │ ║
║ │ ═════════════     ═════════   │ ║
║ │ TOTAL:            Rs. 2,500   │ ║
║ └───────────────────────────────┘ ║
║                                   ║
║ ┌───────────────────────────────┐ ║
║ │ 📝 DELIVERY NOTES             │ ║
║ │ ─────────────────────────────│ ║
║ │ Please call before arriving.  │ ║
║ │ Gate code: 1234               │ ║
║ └───────────────────────────────┘ ║
║                                   ║
║ ┌─────────────────────────────┐   ║
║ │   ✓ MARK AS DELIVERED       │   ║  Big green button
║ └─────────────────────────────┘   ║
║                                   ║
╚═══════════════════════════════════╝
```

---

### **5. Ledger Screen**

```
╔═══════════════════════════════════╗
║ ←  My Ledger           📊         ║
║                                   ║
║ ┌───────────────────────────────┐ ║
║ │   CURRENT BALANCE             │ ║
║ │   Rs. 15,000 (Debit)          │ ║  Large, prominent
║ │   Amount you owe company      │ ║
║ └───────────────────────────────┘ ║
║                                   ║
║ ┌─────────────────────────────┐   ║
║ │   SETTLE INVOICES  →        │   ║  Action button
║ └─────────────────────────────┘   ║
║                                   ║
║ Recent Transactions               ║
║ ─────────────────                 ║
║                                   ║
║ Oct 16, 2025                      ║
║ ┌───────────────────────────────┐ ║
║ │ 📦 Order #12345 Collected     │ ║
║ │ + Rs. 2,500                   │ ║  Green
║ └───────────────────────────────┘ ║
║                                   ║
║ Oct 15, 2025                      ║
║ ┌───────────────────────────────┐ ║
║ │ 💵 Cash Deposit               │ ║
║ │ - Rs. 10,000                  │ ║  Red
║ └───────────────────────────────┘ ║
║                                   ║
║ Oct 14, 2025                      ║
║ ┌───────────────────────────────┐ ║
║ │ 📦 Order #12340 Collected     │ ║
║ │ + Rs. 3,200                   │ ║
║ └───────────────────────────────┘ ║
║                                   ║
║ [View All Transactions]           ║
║                                   ║
╠═══════════════════════════════════╣
║  📦       💰       📝       👤    ║
║ Orders  Ledger  Requests  Profile ║
╚═══════════════════════════════════╝
```

---

### **6. Settle Invoices Screen**

```
╔═══════════════════════════════════╗
║ ←  Settle Invoices                ║
║                                   ║
║ Outstanding Invoices              ║
║ Total: Rs. 15,000                 ║
║                                   ║
║ ☑️ Order #12345  Rs. 2,500        ║  Checkboxes
║ ☑️ Order #12346  Rs. 3,200        ║
║ ☑️ Order #12340  Rs. 1,800        ║
║ ☑️ Order #12338  Rs. 4,500        ║
║ ☑️ Order #12335  Rs. 3,000        ║
║                                   ║
║ ┌───────────────────────────────┐ ║
║ │ Selected: 5 invoices          │ ║
║ │ Total Amount: Rs. 15,000      │ ║
║ └───────────────────────────────┘ ║
║                                   ║
║ ┌─────────────────────────────┐   ║
║ │   CONFIRM SETTLEMENT        │   ║  Large button
║ └─────────────────────────────┘   ║
║                                   ║
╚═══════════════════════════════════╝
```

---

### **7. Requests List Screen**

```
╔═══════════════════════════════════╗
║ ←  My Requests                    ║
║                                   ║
║ ┌─────────────────────────────┐   ║
║ │   + CREATE NEW REQUEST      │   ║  Prominent button
║ └─────────────────────────────┘   ║
║                                   ║
║ Tabs: [Pending] [Approved] [All]  ║
║                                   ║
║ ┌───────────────────────────────┐ ║
║ │ ⛽ Petrol Expense             │ ║
║ │ ─────────────────────────────│ ║
║ │ Amount: Rs. 500               │ ║
║ │ Status: 🟡 Pending            │ ║
║ │ Submitted: Oct 16, 2025       │ ║
║ │                               │ ║
║ │ [View Details →]              │ ║
║ └───────────────────────────────┘ ║
║                                   ║
║ ┌───────────────────────────────┐ ║
║ │ 💰 Salary Advance             │ ║
║ │ ─────────────────────────────│ ║
║ │ Amount: Rs. 5,000             │ ║
║ │ Status: ✅ Approved            │ ║
║ │ Approved: Oct 15, 2025        │ ║
║ │                               │ ║
║ │ [View Details →]              │ ║
║ └───────────────────────────────┘ ║
║                                   ║
║ ┌───────────────────────────────┐ ║
║ │ 🏖️ Leave Request              │ ║
║ │ ─────────────────────────────│ ║
║ │ Duration: Oct 20-22 (3 days)  │ ║
║ │ Status: 🟡 Pending            │ ║
║ │ Submitted: Oct 14, 2025       │ ║
║ └───────────────────────────────┘ ║
║                                   ║
╠═══════════════════════════════════╣
║  📦       💰       📝       👤    ║
║ Orders  Ledger  Requests  Profile ║
╚═══════════════════════════════════╝
```

---

### **8. Create Request Screen**

```
╔═══════════════════════════════════╗
║ ←  Create New Request             ║
║                                   ║
║ Request Type:                     ║
║ ┌───────────────────────────────┐ ║
║ │ ⛽ Petrol Expense             │ ║  Tap to select
║ └───────────────────────────────┘ ║
║ ┌───────────────────────────────┐ ║
║ │ 💰 Salary Advance             │ ║
║ └───────────────────────────────┘ ║
║ ┌───────────────────────────────┐ ║
║ │ 🏖️ Leave Request              │ ║
║ └───────────────────────────────┘ ║
║                                   ║
╚═══════════════════════════════════╝

(After selecting Petrol):

╔═══════════════════════════════════╗
║ ←  Petrol Expense Request         ║
║                                   ║
║ Amount (Rs.)                      ║
║ ┌─────────────────────────────┐   ║
║ │ 500                         │   ║  Number input
║ └─────────────────────────────┘   ║
║                                   ║
║ Description                       ║
║ ┌─────────────────────────────┐   ║
║ │ Fuel for delivery route     │   ║  Text area
║ │                             │   ║
║ └─────────────────────────────┘   ║
║                                   ║
║ Receipt (Optional)                ║
║ ┌─────────────────────────────┐   ║
║ │  📷  Take Photo             │   ║
║ └─────────────────────────────┘   ║
║                                   ║
║                                   ║
║ ┌─────────────────────────────┐   ║
║ │   SUBMIT REQUEST  →         │   ║  Big button
║ └─────────────────────────────┘   ║
║                                   ║
╚═══════════════════════════════════╝
```

---

### **9. Attendance Screen**

```
╔═══════════════════════════════════╗
║ ←  My Attendance                  ║
║                                   ║
║ Today: Thursday, Oct 16, 2025     ║
║                                   ║
║ ┌───────────────────────────────┐ ║
║ │                               │ ║
║ │        ⏰ 09:30 AM            │ ║  Large time
║ │                               │ ║
║ │    Status: Not Clocked In     │ ║
║ │                               │ ║
║ └───────────────────────────────┘ ║
║                                   ║
║ ┌─────────────────────────────┐   ║
║ │     🟢 CLOCK IN             │   ║  Huge green button
║ └─────────────────────────────┘   ║
║                                   ║
║ This Month Summary                ║
║ ┌───────────────────────────────┐ ║
║ │ Days Present:     15          │ ║
║ │ Days Absent:      2           │ ║
║ │ Total Hours:      120         │ ║
║ │ This Month:       Oct 2025    │ ║
║ └───────────────────────────────┘ ║
║                                   ║
║ Recent Attendance                 ║
║ ─────────────────                 ║
║ Oct 15  ✓ 09:00 AM - 06:00 PM    ║
║ Oct 14  ✓ 09:15 AM - 06:30 PM    ║
║ Oct 13  ✗ Absent                  ║
║                                   ║
║ [View Full History]               ║
║                                   ║
╠═══════════════════════════════════╣
║  📦       💰       📝       👤    ║
║ Orders  Ledger  Requests  Profile ║
╚═══════════════════════════════════╝
```

(After clocking in):

```
╔═══════════════════════════════════╗
║ ←  My Attendance                  ║
║                                   ║
║ Today: Thursday, Oct 16, 2025     ║
║                                   ║
║ ┌───────────────────────────────┐ ║
║ │                               │ ║
║ │      🟢 CLOCKED IN            │ ║
║ │                               │ ║
║ │      Clock In: 09:30 AM       │ ║
║ │      Duration: 2h 15m         │ ║
║ │                               │ ║
║ └───────────────────────────────┘ ║
║                                   ║
║ ┌─────────────────────────────┐   ║
║ │     🔴 CLOCK OUT            │   ║  Red button
║ └─────────────────────────────┘   ║
║                                   ║
║ [Rest of screen same as above]    ║
╚═══════════════════════════════════╝
```

---

### **10. Profile Screen**

```
╔═══════════════════════════════════╗
║ ←  My Profile                     ║
║                                   ║
║        👤                         ║
║     Ali Khan                      ║
║     Rider                         ║
║                                   ║
║ ┌───────────────────────────────┐ ║
║ │ 📧 Email                      │ ║
║ │ ali@example.com               │ ║
║ └───────────────────────────────┘ ║
║                                   ║
║ ┌───────────────────────────────┐ ║
║ │ 📞 Phone                      │ ║
║ │ 0300-1234567                  │ ║
║ └───────────────────────────────┘ ║
║                                   ║
║ ┌───────────────────────────────┐ ║
║ │ 🚗 Vehicle                    │ ║
║ │ Bike - ABC 123                │ ║
║ └───────────────────────────────┘ ║
║                                   ║
║ ┌───────────────────────────────┐ ║
║ │ ⏰ Shift                      │ ║
║ │ 09:00 AM - 06:00 PM           │ ║
║ └───────────────────────────────┘ ║
║                                   ║
║ ┌─────────────────────────────┐   ║
║ │ 🔒 Change Password          │   ║
║ └─────────────────────────────┘   ║
║                                   ║
║ ┌─────────────────────────────┐   ║
║ │ 🚪 Logout                   │   ║  Red
║ └─────────────────────────────┘   ║
║                                   ║
║                                   ║
║ App Version: 1.0.0                ║
║                                   ║
╠═══════════════════════════════════╣
║  📦       💰       📝       👤    ║
║ Orders  Ledger  Requests  Profile ║
╚═══════════════════════════════════╝
```

---

## 🎨 **Color Scheme**

| Element | Color | Purpose |
|---------|-------|---------|
| Primary | Green (#10B981) | Actions, success |
| Secondary | Blue (#3B82F6) | Links, info |
| Warning | Yellow (#F59E0B) | Pending status |
| Danger | Red (#EF4444) | Clock out, logout |
| Success | Green (#10B981) | Completed |
| Gray | (#6B7280) | Disabled, past |

---

## 📏 **Size Guidelines**

- **Headers:** 24px, bold
- **Body text:** 16px
- **Button text:** 18px, bold
- **Cards:** 16px padding, 8px radius
- **Buttons:** 56px height (easy to tap)
- **Icons:** 24px standard, 48px for main actions

---

## ✨ **Interactions**

1. **Tap Card** → View details
2. **Swipe Card** → Quick actions (future)
3. **Pull Down** → Refresh data
4. **Bottom Nav** → Switch sections
5. **Back Button** → Android back button works

---

## 📱 **Status Colors**

### Orders:
- 🟢 Green = Ready for delivery
- 🟡 Yellow = Out for delivery
- 🔵 Blue = Preparing
- ✅ Gray = Delivered

### Requests:
- 🟡 Yellow = Pending
- ✅ Green = Approved
- 🔴 Red = Rejected

### Attendance:
- 🟢 Green = Clocked in
- ⚪ Gray = Not clocked in
- ✅ Green checkmark = Present
- ❌ Red X = Absent

---

**This design prioritizes:**
1. ✅ Large tap targets
2. ✅ Clear status indicators
3. ✅ Minimal typing
4. ✅ Visual hierarchy
5. ✅ Easy one-handed use

---

**Next step:** After you install the prerequisites, Cursor will build this exact interface! 🎨

