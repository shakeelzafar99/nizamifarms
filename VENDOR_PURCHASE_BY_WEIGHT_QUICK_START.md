# Vendor Purchase by Weight - Quick Start Guide

## 🚀 Getting Started in 3 Steps

### Step 1: Run SQL Migration (You'll Do This)
1. Open MySQL Workbench
2. Connect to `nizamifarms_db`
3. Open file: `VENDOR_PURCHASE_BY_WEIGHT_SQL_SCHEMA.md`
4. Scroll to bottom and copy the SQL script
5. Run it in MySQL Workbench
6. Verify success messages appear

**What it creates**:
- `t_fin_vendor_products` table (stores vendor product catalog)
- `t_fin_vendor_purchase_items` table (stores line items for purchases)

---

### Step 2: Set Up Vendor Products
1. Go to **Finance → Vendors**
2. Click on any vendor
3. Click **"🛒 Manage Products"** button (blue)
4. Add products for this vendor:
   - **Product Name**: e.g., "Chicken Breast"
   - **Unit**: e.g., "kg"
   - **Rate per Unit**: e.g., "450.00"
   - Click **"✓ Add Product"**
5. Repeat for all products this vendor supplies
6. Click **"← Back to Vendor"**

---

### Step 3: Record a Weighted Purchase
1. On vendor page, click **"⚖️ Purchase by Weight"** button (orange)
2. Select **Date**
3. Click **"➕ Add Item"** for each product:
   - Select **Product** from dropdown
   - Enter **Quantity** (e.g., 25.5)
   - **Rate** fills automatically
   - **Total** calculates automatically
4. Add more items as needed (click "➕ Add Item" again)
5. Review **Grand Total** at bottom
6. Add optional **Description**
7. Click **"✓ Record Purchase"**

**Done!** The purchase is recorded with all line items tracked.

---

## 📱 User Interface Tour

### Vendor Show Page - New Buttons
```
[📦 Record Purchase]  [⚖️ Purchase by Weight]  [💰 Record Payment]  [🛒 Manage Products]
      (red)                  (orange)                (green)              (blue)
```

### Purchase by Weight Modal
```
┌─────────────────────────────────────────────────────┐
│ ⚖️ Purchase by Weight                        [×]    │
├─────────────────────────────────────────────────────┤
│ Date: [2024-10-16]                                  │
│                                                     │
│ Purchase Items                      [➕ Add Item]   │
│ ┌─────────────────────────────────────────────────┐│
│ │ Product  | Qty  | Rate | Total | [×]            ││
│ │ Chicken  | 25   | 450  | 11,250|                ││
│ │ Mutton   | 15   | 850  | 12,750|                ││
│ └─────────────────────────────────────────────────┘│
│                                                     │
│ ┌─────────────────────────────────────────────────┐│
│ │ Total Amount:              Rs. 24,000.00        ││
│ └─────────────────────────────────────────────────┘│
│                                                     │
│ Description: [Optional details...]                 │
│                                                     │
│         [Cancel]  [✓ Record Purchase]              │
└─────────────────────────────────────────────────────┘
```

---

## 💡 Key Features

### ✅ What You Can Do:
1. **Manage Vendor Products**:
   - Add products specific to each vendor
   - Set rates that can be updated anytime
   - Enable/disable products
   - Delete unused products

2. **Record Detailed Purchases**:
   - Add multiple items in one purchase
   - Each item shows quantity, rate, and total
   - Grand total calculated automatically
   - All items tracked separately

3. **View Purchase History**:
   - All purchases appear in transaction history
   - Weighted purchases show item summary in comments
   - Can see exactly what was purchased

---

## 🎯 When to Use What?

### Use "📦 Record Purchase" (Regular) When:
- Recording a flat bill amount
- Don't need item-level details
- Quick entry

### Use "⚖️ Purchase by Weight" When:
- Buying multiple items from vendor
- Need to track quantities and rates
- Want detailed records
- Purchasing by weight/volume

---

## 📊 Example Scenarios

### Scenario 1: Meat Supplier
**Setup** (one-time):
1. Add products: Chicken Breast (kg, Rs. 450), Mutton Leg (kg, Rs. 850)

**Daily Use**:
1. Click "Purchase by Weight"
2. Add: Chicken 25 kg, Mutton 15 kg
3. Total auto-calculates: Rs. 24,000
4. Submit

### Scenario 2: Vegetable Vendor
**Setup** (one-time):
1. Add products: Tomatoes (kg, Rs. 80), Onions (kg, Rs. 60), Potatoes (kg, Rs. 40)

**Daily Use**:
1. Click "Purchase by Weight"
2. Add: Tomatoes 10 kg, Onions 15 kg, Potatoes 20 kg
3. Total auto-calculates: Rs. 2,500
4. Submit

---

## ⚙️ Product Management Tips

### Adding Products:
- Use clear, simple names (e.g., "Chicken Breast" not "CHK-BRS-001")
- Select correct unit (kg for weight, liter for liquids, piece for items)
- Set current market rate (can update anytime)

### Updating Rates:
1. Go to "Manage Products"
2. Click "✏️ Edit" on product
3. Update rate
4. Click "✓ Update Product"

**Note**: Past purchases retain old rates (historical accuracy)

### Disabling Products:
- Use "🔒 Disable" instead of deleting
- Keeps history intact
- Product won't appear in new purchases

---

## 🔍 Troubleshooting

### "No products in dropdown"
→ Go to "Manage Products" and add products first

### "Can't delete product"
→ Product has purchase history, use "Disable" instead

### "Total not updating"
→ Make sure you entered quantity and selected product

### "Submit button disabled"
→ Need at least one valid line item with quantity > 0

---

## 📈 What Happens Behind the Scenes

When you record a weighted purchase:
1. ✅ One ledger entry created with grand total
2. ✅ All line items saved separately
3. ✅ Vendor balance increases (payable goes up)
4. ✅ Purchase expense account increases
5. ✅ Transaction appears in vendor history
6. ✅ Item details preserved (product, qty, rate)

**Accounting Entry**:
```
Dr  Purchase Expense (EXP_PURCHASES)    Rs. 24,000
    Cr  Vendor Payable                           Rs. 24,000
```

---

## ✨ Benefits

1. **Detailed Records**: Know exactly what you bought
2. **Easy Entry**: Add multiple items quickly
3. **Auto-Calculate**: No manual math needed
4. **Flexible**: Add as many items as needed
5. **Simple**: User-friendly for non-technical staff
6. **Accurate**: Historical rates preserved forever

---

## 🎉 You're Ready!

After running the SQL script, everything is set up and ready to use. Start by adding products for your vendors, then record your first weighted purchase!

**Questions?** Check `VENDOR_PURCHASE_BY_WEIGHT_IMPLEMENTATION_OCT16.md` for technical details.

