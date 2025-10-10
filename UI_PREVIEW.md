# UI Preview - What You'll See

## 📸 **Visual Guide to New Features**

---

## 🎨 **1. Cash Accountability Alert**

When you open an employee's cash page and they have undeposited cash:

```
┌─────────────────────────────────────────────────────────────────┐
│  ⚠️ Cash Accountability Alert                             [3]   │
│                                                                  │
│  The following days have undeposited or overspent cash:         │
│                                                                  │
│  ┌──────────────────────────────────────────────────────────┐  │
│  │ Feb 9, 2025                      🔴 +Rs. 1,350.00 held  │  │
│  ├──────────────────────────────────────────────────────────┤  │
│  │ Feb 7, 2025                      ⚠️ Rs. 500.00 short    │  │
│  ├──────────────────────────────────────────────────────────┤  │
│  │ Feb 4, 2025                      🔴 +Rs. 2,100.00 held  │  │
│  └──────────────────────────────────────────────────────────┘  │
└─────────────────────────────────────────────────────────────────┘
```

**Colors:**
- **Yellow/Orange gradient** background
- **Red text** for held cash (🔴)
- **Yellow text** for short amounts (⚠️)
- **White boxes** for each day
- **Bold badge** showing count

---

## 🗂️ **2. Transaction History Header**

```
┌─────────────────────────────────────────────────────────────────┐
│  Transaction History                      Total: 45 transaction(s)│
│                                                                  │
│  Group by:  [📅 Date]  [📆 Month]  [📋 List]                  │
│  ☐ Show only non-zero days             [Expand All]            │
└─────────────────────────────────────────────────────────────────┘
```

**Interactive Elements:**
- **Blue button** = Active grouping mode
- **Gray buttons** = Inactive modes
- **Checkbox** = Filter toggle
- **Blue bordered button** = Expand/Collapse all

---

## 📅 **3. Date Group (Collapsed)**

```
┌─────────────────────────────────────────────────────────────────┐
│  ▶ Monday, February 9, 2025                 [🔴 +Rs. 1,350 held]│
│     In: Rs. 3,350.00 • Out: Rs. 2,000.00 • 2 transaction(s)    │
└─────────────────────────────────────────────────────────────────┘
```

**Visual:**
- **Gray background** with hover effect
- **Right-pointing chevron** (▶) when collapsed
- **Date badge** on right (green✅, red🔴, or yellow⚠️)
- **Summary stats** below date
- **Cursor changes** to pointer on hover

---

## 📅 **4. Date Group (Expanded)**

```
┌─────────────────────────────────────────────────────────────────┐
│  ▼ Monday, February 9, 2025                 [🔴 +Rs. 1,350 held]│
│     In: Rs. 3,350.00 • Out: Rs. 2,000.00 • 2 transaction(s)    │
│  ┌─────────────────────────────────────────────────────────────┐│
│  │ TIME    │ TYPE    │ DESCRIPTION      │ IN       │ OUT       ││
│  ├─────────────────────────────────────────────────────────────┤│
│  │ 10:30 AM│ Invoice │ Invoice #7651    │ Rs. 3,350│ -         ││
│  │ 04:15 PM│ Deposit │ Deposit to NF... │ -        │ Rs. 2,000 ││
│  └─────────────────────────────────────────────────────────────┘│
└─────────────────────────────────────────────────────────────────┘
```

**Visual:**
- **Down-pointing chevron** (▼) when expanded
- **White background** for transaction table
- **Compact table** with smaller padding
- **Colored badges** for transaction types
- **Time column** shows HH:MM AM/PM

---

## 🏷️ **5. Transaction Type Badges**

```
Invoice:  [Invoice]     ← Green badge, green text
Expense:  [Expense]     ← Red badge, red text
Deposit:  [Employee deposit] ← Blue badge, blue text
Transfer: [Transfer]    ← Purple badge, purple text
```

**Style:**
- **Rounded pill shape**
- **Light background** with matching text color
- **Bold text**
- **Small size** (fits inline)

---

## ✅ **6. Net Balance Badges**

```
Balanced:  [✅ Balanced]         ← Green background, green text
Held:      [🔴 +Rs. 1,350 held] ← Red background, red text
Short:     [⚠️ Rs. 500 short]   ← Yellow background, yellow text
```

**Placement:**
- Right side of date header
- Prominent size
- Easy to scan

---

## 📊 **7. List View (Non-Grouped)**

When you click **[📋 List]**:

```
┌─────────────────────────────────────────────────────────────────┐
│  DATE        │ TYPE    │ DESCRIPTION   │ IN    │ OUT   │ BALANCE│
├─────────────────────────────────────────────────────────────────┤
│ Feb 9, 2025  │ Invoice │ Invoice #7651 │ 3,350 │ -     │ 3,350  │
│ Feb 9, 2025  │ Deposit │ Deposit to NF │ -     │ 2,000 │ 1,350  │
│ Feb 8, 2025  │ Invoice │ Invoice #7652 │ 8,200 │ -     │ 9,550  │
│ Feb 8, 2025  │ Deposit │ Deposit to NF │ -     │ 8,200 │ 1,350  │
└─────────────────────────────────────────────────────────────────┘
```

**Visual:**
- **Traditional table layout**
- **Full dates** in first column
- **No grouping**
- **Easier to export/print**

---

## 🔍 **8. Non-Zero Filter (Active)**

When you check **☐ Show only non-zero days**:

```
┌─────────────────────────────────────────────────────────────────┐
│  ▶ Monday, February 9, 2025                 [🔴 +Rs. 1,350 held]│
│     In: Rs. 3,350.00 • Out: Rs. 2,000.00 • 2 transaction(s)    │
├─────────────────────────────────────────────────────────────────┤
│  ▶ Sunday, February 7, 2025                 [⚠️ Rs. 500 short] │
│     In: Rs. 2,000.00 • Out: Rs. 2,500.00 • 3 transaction(s)    │
└─────────────────────────────────────────────────────────────────┘

(Balanced days are hidden)
```

**Effect:**
- Days with ✅ Balanced badge disappear
- Only problem days remain
- Easier to focus on issues

---

## 📱 **9. Responsive Design**

### **Desktop (Wide Screen):**
```
[ Date | Month | List ]  ☐ Non-zero     [Expand All]
←──── All in one line ────────────────────────────→
```

### **Mobile (Narrow Screen):**
```
[ Date | Month | List ]
☐ Non-zero
[Expand All]
↓
Wraps to multiple lines
```

---

## 🎭 **10. Interactive States**

### **Hover Effects:**
- **Date header**: Gray → Lighter gray background
- **Transaction row**: White → Light gray background
- **Buttons**: Border → Slight shadow
- **Cursor**: Default → Pointer (on clickable elements)

### **Active States:**
- **Grouping button**: Blue background, white text
- **Checkbox**: Blue checkmark when checked
- **Chevron**: Rotates 90° on expand

### **Animations:**
- **Chevron rotation**: Smooth 0.2s transition
- **Row hover**: Instant
- **Expand/collapse**: CSS transition (automatic)

---

## 🌈 **Color Palette**

### **Alerts:**
- Accountability Alert: `bg-gradient-to-r from-yellow-50 to-orange-50`
- Border: `border-yellow-300`

### **Badges (Status):**
- Balanced: `bg-green-100 text-green-800`
- Held (Positive): `bg-red-100 text-red-800`
- Short (Negative): `bg-yellow-100 text-yellow-800`

### **Badges (Type):**
- Invoice: `bg-green-100 text-green-800`
- Expense: `bg-red-100 text-red-800`
- Deposit: `bg-blue-100 text-blue-800`
- Transfer: `bg-purple-100 text-purple-800`
- Adjustment: `bg-orange-100 text-orange-800`

### **Buttons:**
- Active: `bg-blue-600 text-white`
- Inactive: `border-gray-300 text-gray-700`
- Hover: `hover:bg-gray-50` or `hover:bg-blue-700`

---

## 📐 **Spacing & Layout**

### **Date Header:**
- Padding: `py-3 px-6` (12px top/bottom, 24px left/right)
- Gap between elements: `gap-3` (12px)

### **Transaction Table:**
- Row padding: `py-3 px-6` (compact)
- Column alignment: Left for text, Right for amounts
- Border: `divide-y divide-gray-100` (subtle)

### **Cards:**
- Rounded corners: `rounded-lg` (8px)
- Shadow: `shadow-sm` (subtle)
- Border: `border border-gray-200` (1px solid)

---

## ✨ **Special Details**

### **Chevron Icon:**
```html
<svg class="w-5 h-5 transition-transform">
  <path stroke-linecap="round" d="M9 5l7 7-7 7"></path>
</svg>
```
- **Size**: 20x20px
- **Color**: Gray-400
- **Transforms**: Rotates 90° on expand

### **Emoji Icons:**
- ⚠️ Warning (accountability)
- ✅ Success (balanced)
- 🔴 Alert (held cash)
- ⚠️ Caution (short)
- 📅 Calendar (date grouping)
- 📆 Month (month grouping)
- 📋 List (flat view)

---

## 🎯 **Visual Hierarchy**

**Priority 1 (Most Prominent):**
1. Accountability Alert (if present)
2. Net balance badges on date headers

**Priority 2 (Secondary):**
1. Date headers
2. Group by controls

**Priority 3 (Tertiary):**
1. Transaction details
2. Filter options

---

## 💡 **Pro Tips for UI Testing**

1. **Look for smooth transitions** - Chevron rotation should be fluid
2. **Check color contrast** - All text should be readable
3. **Test hover states** - All interactive elements should show feedback
4. **Verify badge colors** - Green = good, Red/Yellow = attention needed
5. **Try rapid clicking** - No visual glitches
6. **Test with long names** - Text should wrap or truncate gracefully

---

**This is what you'll see when everything is working! 🎨**

