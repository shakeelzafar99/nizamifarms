# Expense Management Fixes - Implementation Plan

## Issues Identified

### 1. **Total Expenses Mismatch**
**NF Ledger**: Rs. 126,479 (Ledger expenses + Salary expenses)
**Expense Management**: Rs. 141,479 (Higher - might be including unapproved or duplicates)

**Root Cause**: 
- NF Ledger: Only counts `approval_status = 'approved'` from ledger
- Expense Management: Might be counting all expenses with `ledger_transaction_id`

**Fix**: Ensure both use the same logic - only approved expenses

### 2. **Category Filter Not Working**
**Problem**: Filtering by "Petrol" doesn't filter the table properly

**Root Cause**: 
- Filter uses `expense_category` field
- Might be case-sensitive or partial match issue
- Need to check if field name is correct

**Fix**: Use case-insensitive matching and ensure correct field

### 3. **Add Top 10 Categories Card**
**Requirements**:
- Show top 10 expense categories with totals
- Group rest as "Other Expenses"
- Clickable to filter table
- Smart layout (biggest card on right, 4 cards in 2 rows on left)

**Implementation**:
- Query expenses grouped by category
- Sort by amount descending
- Take top 10
- Make each category clickable
- Update frontend layout

## Implementation Steps

1. Fix expense calculation in Expense Management
2. Fix category filter
3. Add top 10 categories calculation
4. Update frontend layout
5. Add JavaScript for clickable filtering

