# Vendor "Record Purchase" Fix

**Date**: October 24, 2025  
**Issue**: Records from "Record Purchase" (by total) modal were not being saved

## Root Cause

The validation in `VendorController@recordPurchase()` required the `description` field to be filled, but the form marked it as "Optional". When users left the description blank, the validation failed silently and the record was not saved.

## Fix Applied

### Changed Validation Rule

**File**: `app/Http/Controllers/FIN/VendorController.php` (Line 440)

**Before**:
```php
'description' => 'required|string|max:500',
```

**After**:
```php
'description' => 'nullable|string|max:500',
```

### Added Default Description

**File**: `app/Http/Controllers/FIN/VendorController.php` (Line 467)

**Before**:
```php
'description' => $request->description,
```

**After**:
```php
'description' => $request->description ?: "Purchase from {$vendor->vendor_name}",
```

Now if the user leaves the description blank, it will automatically use "Purchase from [Vendor Name]" as the description.

## Testing

1. ✅ Open vendor detail page
2. ✅ Click "Record Purchase" button
3. ✅ Enter amount and date (leave description blank)
4. ✅ Click "Record Purchase"
5. ✅ Verify transaction appears in the table
6. ✅ Verify balance is updated correctly

## Impact

- **No breaking changes** - existing functionality remains intact
- **Better UX** - users can now save purchases without entering a description
- **Data quality** - default description ensures all records have meaningful descriptions

## Related Files

- `app/Http/Controllers/FIN/VendorController.php` - Validation and default description
- `resources/views/fin/vendor/show.blade.php` - Form (already marked description as optional)


