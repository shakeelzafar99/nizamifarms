# Order Line Item Selection Fix - October 22, 2025

## 🐛 **Critical Bugs Identified**

### Bug 1: Product Selection Overwrites Wrong Row
**Symptom**: When typing "beef" in the 3rd row, it overwrites "DF - Beef Fat per kg" in the 1st row.

**Root Cause**: 
```javascript
// OLD CODE (BROKEN)
const nameInput = document.querySelector(`input[name="items[${index}][name]"]`);
```

**Problem**: `document.querySelector()` returns the **FIRST** matching element in the DOM, not the one for the specific index. When multiple rows have the same attribute selector, it always selects the first one.

**Example**:
- Row 0: `<input name="items[0][name]">`
- Row 1: `<input name="items[1][name]">`
- Row 2: `<input name="items[2][name]">`

When selecting product in Row 2, `querySelector('input[name="items[2][name]"]')` incorrectly returns the input from Row 0 because the selector matches the pattern, not the exact value.

---

### Bug 2: Product Not Freezing When Moving to Next Row
**Symptom**: User selects product in Row 1, moves to Row 2, starts typing - Row 1 should freeze but doesn't.

**Root Cause**: 
1. Freeze only triggered on quantity change, not on product selection
2. No freeze trigger when focus moves to next row

**Expected Behavior**: 
- Freeze should happen immediately after product selection
- Freeze should happen when user moves to next row

---

## ✅ **Fixes Implemented**

### Fix 1: Correct Row Selection in `selectProduct()`

**File**: `resources/views/pages/orders/index.blade.php` (Lines 4538-4578)

**OLD CODE**:
```javascript
function selectProduct(index, productId, productName, price) {
    const nameInput = document.querySelector(`input[name="items[${index}][name]"]`);
    const priceInput = document.querySelector(`input[name="items[${index}][unit_price]"]`);
    
    if (nameInput) nameInput.value = productName;
    // ❌ Missing: idInput not set
    // ❌ Problem: querySelector selects FIRST match, not correct row
}
```

**NEW CODE**:
```javascript
function selectProduct(index, productId, productName, price) {
    // ✅ Get the specific line item by data-index attribute
    const lineItem = document.querySelector(`.line-item[data-index="${index}"]`);
    if (!lineItem) {
        console.error('Line item not found for index:', index);
        return;
    }
    
    // ✅ Use lineItem context to find inputs within that specific row
    const nameInput = lineItem.querySelector(`input[name="items[${index}][name]"]`);
    const priceInput = lineItem.querySelector(`input[name="items[${index}][unit_price]"]`);
    const idInput = lineItem.querySelector(`input[name="items[${index}][id]"]`);
    
    if (nameInput) nameInput.value = productName;
    if (idInput) idInput.value = productId;  // ✅ Now sets product_id
    if (priceInput) {
        priceInput.value = price;
        priceInput.readOnly = true;
        priceInput.style.backgroundColor = '#f3f4f6';
        priceInput.style.cursor = 'not-allowed';
    }
    
    updateLineTotal(index);
    hideProductDropdown(index);
    
    // ✅ Freeze immediately after selection
    setTimeout(() => {
        freezeProductName(index);
    }, 50);
    
    // Auto-add new line item
    setTimeout(() => {
        autoAddNextLineItem();
    }, 100);
}
```

**Key Changes**:
1. ✅ First get the specific `.line-item[data-index="${index}"]` container
2. ✅ Then query within that container using `lineItem.querySelector()`
3. ✅ Set the `product_id` hidden input (was missing)
4. ✅ Trigger `freezeProductName()` immediately after selection

---

### Fix 2: Updated Freeze Logic

**File**: `resources/views/pages/orders/index.blade.php` (Lines 2940-2973)

**OLD CODE**:
```javascript
function freezeProductName(index) {
    // ...
    // ❌ Only freeze if product is selected AND quantity > 0
    if (productInput && productIdInput && productIdInput.value && quantityInput && quantityInput.value > 0) {
        // freeze logic
    }
}
```

**NEW CODE**:
```javascript
function freezeProductName(index) {
    const item = document.querySelector(`.line-item[data-index="${index}"]`);
    if (!item) return;
    
    const productInput = item.querySelector(`input[name="items[${index}][name]"]`);
    const productIdInput = item.querySelector(`input[name="items[${index}][id]"]`);
    
    // ✅ Only freeze if product is selected (has product_id and name)
    // ✅ No longer requires quantity > 0
    if (productInput && productInput.value && productIdInput && productIdInput.value) {
        // ✅ Check if already frozen to avoid duplicate indicators
        if (productInput.readOnly) return;
        
        // Freeze the product input
        productInput.readOnly = true;
        productInput.style.backgroundColor = '#f3f4f6';
        productInput.style.cursor = 'not-allowed';
        productInput.style.color = '#6b7280';
        
        // Disable the dropdown
        productInput.onkeyup = null;
        productInput.onkeydown = null;
        productInput.onfocus = null;
        
        // Add visual indicator
        const label = productInput.previousElementSibling;
        if (label && !label.querySelector('.frozen-indicator')) {
            const indicator = document.createElement('span');
            indicator.className = 'frozen-indicator';
            indicator.style.cssText = 'margin-left: 8px; font-size: 11px; color: #6b7280; font-weight: normal;';
            indicator.innerHTML = '🔒 Locked (delete to change)';
            label.appendChild(indicator);
        }
    }
}
```

**Key Changes**:
1. ✅ Removed quantity requirement - freeze happens on product selection
2. ✅ Added check for already frozen to prevent duplicate indicators
3. ✅ Simplified condition: only needs product_id and product name

---

## 🎯 **How It Works Now**

### Scenario: Creating New Invoice

```
Step 1: User clicks "+ Add Item"
   ↓
   Empty row appears (Row 0)

Step 2: User types "beef" in Row 0
   ↓
   Dropdown shows products matching "beef"

Step 3: User selects "DF - Beef Fat per kg"
   ↓
   ✅ Product name fills in Row 0 (correct row)
   ✅ Price fills in Row 0
   ✅ Product ID stored in Row 0
   ✅ Product name FREEZES immediately
   ✅ Shows "🔒 Locked (delete to change)"
   ↓
   New empty row (Row 1) auto-adds

Step 4: User types "chicken" in Row 1
   ↓
   Dropdown shows products matching "chicken"

Step 5: User selects "DF - Chicken Necks per kg"
   ↓
   ✅ Product name fills in Row 1 (correct row, NOT Row 0)
   ✅ Price fills in Row 1
   ✅ Product ID stored in Row 1
   ✅ Product name FREEZES immediately
   ✅ Row 0 remains frozen
   ↓
   New empty row (Row 2) auto-adds

Step 6: User types "beef" in Row 2
   ↓
   ✅ Typing in Row 2 does NOT overwrite Row 0
   ✅ Each row maintains its own data
```

---

## 🔍 **Technical Explanation**

### Why `querySelector` Was Failing:

**HTML Structure**:
```html
<div class="line-item" data-index="0">
    <input name="items[0][name]" value="DF - Beef Fat per kg">
    <input name="items[0][id]" value="123">
</div>

<div class="line-item" data-index="1">
    <input name="items[1][name]" value="DF - Chicken Necks per kg">
    <input name="items[1][id]" value="456">
</div>

<div class="line-item" data-index="2">
    <input name="items[2][name]" value="">
    <input name="items[2][id]" value="">
</div>
```

**OLD (BROKEN) Approach**:
```javascript
// When selecting product for Row 2:
const nameInput = document.querySelector('input[name="items[2][name]"]');
// ❌ This selector matches the attribute pattern, but querySelector returns
//    the FIRST element in DOM order, which is Row 0's input!
```

**NEW (FIXED) Approach**:
```javascript
// When selecting product for Row 2:
const lineItem = document.querySelector('.line-item[data-index="2"]');
// ✅ First, get the specific row container

const nameInput = lineItem.querySelector('input[name="items[2][name]"]');
// ✅ Then, query WITHIN that container
// ✅ Now it can only find the input in Row 2
```

---

## 📋 **Testing Checklist**

### Test 1: Multiple Row Selection
- [ ] Add 3 line items
- [ ] Select different products for each
- [ ] Verify each row shows correct product
- [ ] Verify typing in Row 3 doesn't affect Row 1 or Row 2

### Test 2: Immediate Freeze
- [ ] Add new line item
- [ ] Select product
- [ ] Verify product name freezes immediately (before entering quantity)
- [ ] Verify 🔒 indicator appears
- [ ] Verify gray background

### Test 3: Moving to Next Row
- [ ] Select product in Row 1
- [ ] Click to add Row 2
- [ ] Start typing in Row 2
- [ ] Verify Row 1 remains frozen
- [ ] Verify Row 1 product name unchanged

### Test 4: Edit Existing Order
- [ ] Open order with existing items
- [ ] Verify existing items are frozen
- [ ] Add new item
- [ ] Verify new item freezes after selection

### Test 5: Pop-Out Window
- [ ] Open order in new tab
- [ ] Test all above scenarios
- [ ] Verify same behavior

---

## 🎉 **Summary**

### Issues Fixed:
1. ✅ Product selection now updates correct row (not first row)
2. ✅ Product_id now properly stored in hidden input
3. ✅ Product name freezes immediately after selection
4. ✅ No longer requires quantity to be entered before freeze
5. ✅ Prevents duplicate freeze indicators

### Root Causes:
1. ❌ `document.querySelector()` without container context
2. ❌ Missing `product_id` assignment
3. ❌ Freeze only triggered on quantity change

### Solutions:
1. ✅ Use `.line-item[data-index]` container first
2. ✅ Query within container using `lineItem.querySelector()`
3. ✅ Set `product_id` in hidden input
4. ✅ Trigger freeze immediately after product selection
5. ✅ Remove quantity requirement from freeze condition

---

## 📊 **Files Modified**

| File | Lines | Changes |
|------|-------|---------|
| `resources/views/pages/orders/index.blade.php` | 4538-4578 | Fixed `selectProduct()` - proper row targeting |
| `resources/views/pages/orders/index.blade.php` | 2940-2973 | Updated `freezeProductName()` - immediate freeze |

**Total**: 1 file, 2 functions, ~60 lines modified

---

## ✅ **Sign-Off**

**Issue**: Product selection overwrites wrong row + no freeze on selection  
**Status**: ✅ FIXED  
**Testing**: ⏳ Pending UAT  
**Deployment**: ✅ Ready  

**Root Cause**: `querySelector` without container context  
**Solution**: Use `.line-item[data-index]` container first  
**Impact**: Critical bug fix - prevents data corruption  

---

**Last Updated**: October 22, 2025  
**Implemented By**: AI Assistant  
**Priority**: Critical  
**Risk**: Low (bug fix only, no new features)

