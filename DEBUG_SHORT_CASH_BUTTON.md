# Debug Short Cash Button Not Working

## Quick Debug Steps

### Step 1: Open Browser Console
Press **F12** → Click "Console" tab

### Step 2: Check Button State
Paste this in console and press Enter:
```javascript
const btn = document.getElementById('submit-shortcash-btn');
console.log('Button disabled:', btn.disabled);
console.log('Button dataset:', btn.dataset);
console.log('Selected invoices:', selectedShortCashInvoiceIds);
console.log('Category value:', document.getElementById('shortcash-expense-category')?.value);
console.log('Amount:', document.getElementById('shortcash-amount')?.value);
```

### Step 3: Force Enable Button
If button is disabled, try this:
```javascript
const btn = document.getElementById('submit-shortcash-btn');
btn.disabled = false;
btn.style.opacity = '1';
btn.style.cursor = 'pointer';
console.log('Button force-enabled');
```

### Step 4: Check for Errors
Look for any red errors in the console.

### Step 5: Try Selecting Category Again
After pasting the force-enable code, try:
1. Select a category from dropdown
2. Check if button enables
3. Try clicking submit

## Common Issues

### Issue 1: Category Not Triggering
**Symptom**: Selecting category doesn't enable button  
**Fix**: The `calculateShortage()` function might not be firing

### Issue 2: Button Stuck Disabled
**Symptom**: Button stays grayed out  
**Fix**: Use the force-enable code above

### Issue 3: JavaScript Error
**Symptom**: Red error in console  
**Fix**: Share the error message

## What to Share
If still not working, share:
1. Any errors from console (red text)
2. Output from Step 2 (button state check)
3. Whether force-enable worked

