# Final Debug - Approval Dashboard Not Showing Short Cash

## The Request is PERFECT
```
request_number: REQ-202510-0027
status: pending
requires_level_1: 1
level_1_status: pending
category_id: 3 (expense category)
```

Everything is correct! The issue is likely with **area assignment**.

## Debug Steps

### Step 1: Check in Browser
1. Go to Approvals Dashboard
2. **Don't click any filters** - just look at the page
3. Open Console (F12)
4. Paste this:

```javascript
// Check if the data is being loaded
fetch('/approvals?level=l1', {
    headers: {
        'X-Requested-With': 'XMLHttpRequest',
        'Accept': 'application/json'
    }
})
.then(r => r.json())
.then(data => {
    console.log('Total L1 items:', data.count);
    console.log('Looking for REQ-202510-0027...');
    const shortCash = data.items.find(i => i.number === 'REQ-202510-0027');
    if (shortCash) {
        console.log('✅ FOUND!', shortCash);
        console.log('Area assigned:', shortCash.area);
    } else {
        console.log('❌ NOT FOUND in L1 items');
        console.log('All request numbers:', data.items.filter(i => i.type === 'request').map(i => i.number));
    }
});
```

### Step 2: Check EXP_FUND Filter
If the request is found, check the EXP_FUND filter:

```javascript
fetch('/approvals?level=l1&area=exp_fund', {
    headers: {
        'X-Requested-With': 'XMLHttpRequest',
        'Accept': 'application/json'
    }
})
.then(r => r.json())
.then(data => {
    console.log('EXP_FUND items:', data.count);
    const shortCash = data.items.find(i => i.number === 'REQ-202510-0027');
    if (shortCash) {
        console.log('✅ FOUND in EXP_FUND!');
    } else {
        console.log('❌ NOT in EXP_FUND filter');
    }
});
```

## Expected Results

### If Request Shows in "All L1 Items" but NOT in EXP_FUND:
**Problem**: Area is being assigned incorrectly

**Solution**: The `determineRequestArea()` function is assigning it to wrong area because `payment_source_account_id` is the rider's account, not EXP_FUND.

### If Request Does NOT Show in "All L1 Items":
**Problem**: The `getL1PendingItems()` query is excluding it

**Unlikely** since the request has all correct fields.

## Quick Test
Try clicking "All Pending Approvals" (bottom of page) without any filters - does REQ-202510-0027 show there?

If YES → Area filtering issue
If NO → Query issue

Please run the browser console commands above and share the output!

