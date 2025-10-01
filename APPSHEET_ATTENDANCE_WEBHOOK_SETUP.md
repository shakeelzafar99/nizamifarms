# AppSheet Attendance Webhook Setup Guide

## Overview
This webhook allows your AppSheet attendance tracking app to automatically sync attendance data to your Laravel webapp in real-time, using the same robust logic as the CSV import feature.

---

## 🔗 Webhook Endpoint

**URL**: `https://your-domain.com/webhook/appsheet/attendance-update`

**Method**: `POST`

**Content-Type**: `application/json`

**Authentication**: None required (public webhook)

---

## 📋 Required Fields

Your AppSheet webhook must send a JSON payload with these **required** fields:

| Field | Description | Format | Example |
|-------|-------------|--------|---------|
| `date` | Attendance date | `YYYY-MM-DD` or any date format | `"2025-10-01"` or `"10/01/2025"` |
| `employee` | Employee full name | String (will be matched against `t_sys_user.fullname`) | `"John Doe"` |

---

## 📝 Optional Fields

All other fields from your CSV format are optional:

| Field | Description | Format | Example |
|-------|-------------|--------|---------|
| `login_time` | Login/check-in time | `HH:MM:SS` or `HH:MM` | `"09:15:00"` or `"09:15"` |
| `logout_time` | Logout/check-out time | `HH:MM:SS` or `HH:MM` | `"17:30:00"` or `"17:30"` |
| `login_location` | Login GPS coordinates | `"lat, lng"` | `"33.7, 73.0"` |
| `logout_location` | Logout GPS coordinates | `"lat, lng"` | `"33.7, 73.0"` |
| `device_id` | Device identifier | String | `"ABC123"` |
| `meter_start` | Starting odometer reading | Number | `1234` |
| `meter_end` | Ending odometer reading | Number | `5678` |
| `picture_start` | Login photo URL | String/URL | `"https://..."` |
| `picture_end` | Logout photo URL | String/URL | `"https://..."` |
| `notes` | Additional notes | String | `"Late due to traffic"` |

---

## 🔄 Field Name Flexibility

The webhook accepts multiple field name formats (case-insensitive):

- `date` / `Date` / `attendance_date` / `Attendance Date`
- `employee` / `Employee` / `employee_name` / `Employee Name`
- `login_time` / `Login Time` / `login time`
- `logout_time` / `Logout Time` / `logout time` / `log_out_time` / `Log Out Time`
- `login_location` / `Login Location` / `login_lat_lng`
- `logout_location` / `Logout Location` / `logout_lat_lng`
- `device_id` / `Device ID` / `Device Id`
- `meter_start` / `Meter Start`
- `meter_end` / `Meter End`
- `picture_start` / `Picture Start`
- `picture_end` / `Picture End`
- `notes` / `Notes`

---

## 📤 Example Webhook Payload

### Your AppSheet Structure (Recommended):
This is the exact format your AppSheet app will use:

**Login Webhook (9:00 AM)**:
```json
{
  "Date": "<<[Date]>>",
  "employee": "<<[employee]>>",
  "login time": "<<[login time]>>",
  "login location": "<<[login location]>>",
  "log out time": null,
  "logout location": null,
  "device id": "<<[device id]>>",
  "picture start": "<<[picture start]>>",
  "meter start": "<<[meter start]>>",
  "meter end": null,
  "picture end": null
}
```

**Logout Webhook (5:00 PM - same day)**:
```json
{
  "Date": "<<[Date]>>",
  "employee": "<<[employee]>>",
  "login time": null,
  "login location": null,
  "log out time": "<<[log out time]>>",
  "logout location": "<<[logout location]>>",
  "device id": "<<[device id]>>",
  "picture start": null,
  "meter start": null,
  "meter end": "<<[meter end]>>",
  "picture end": "<<[picture end]>>"
}
```

**Result**: Single database record with **both** login and logout data merged intelligently.

### Minimal (required fields only):
```json
{
  "date": "2025-10-01",
  "employee": "John Doe"
}
```

### Complete (all fields in one call):
```json
{
  "date": "2025-10-01",
  "employee": "John Doe",
  "login_time": "09:15:00",
  "logout_time": "17:30:00",
  "login_location": "33.7123, 73.0456",
  "logout_location": "33.7150, 73.0480",
  "device_id": "PHONE-ABC123",
  "meter_start": 1234,
  "meter_end": 5678,
  "picture_start": "https://appsheet.com/photos/login-photo.jpg",
  "picture_end": "https://appsheet.com/photos/logout-photo.jpg",
  "notes": "Regular day"
}
```

---

## ✅ Response Formats

### Success (201):
```json
{
  "success": true,
  "message": "Attendance record created",
  "user_id": 73,
  "fullname": "John Doe",
  "attendance_date": "2025-10-01",
  "login_time": "09:15:00",
  "logout_time": "17:30:00"
}
```

### Success - Updated Existing (200):
```json
{
  "success": true,
  "message": "Attendance record updated",
  "user_id": 73,
  "fullname": "John Doe",
  "attendance_date": "2025-10-01",
  "login_time": "09:15:00",
  "logout_time": "17:30:00"
}
```

### Error - Missing Required Fields (422):
```json
{
  "success": false,
  "message": "Missing required fields: date and employee"
}
```

### Error - Employee Not Found (404):
```json
{
  "success": false,
  "message": "Employee not found in system: John Doe (cleaned: John Doe)"
}
```

### Error - Invalid Date (422):
```json
{
  "success": false,
  "message": "Invalid date format: invalid-date"
}
```

---

## 🔧 Smart Features

### 1. **Partial Updates Support** ⭐ NEW
The webhook intelligently handles **two separate webhook calls** for the same day:
- **First call (Login)**: Creates record with only login data
- **Second call (Logout)**: Updates same record with logout data
- **Only updates fields that are provided** (non-null)
- **Never overwrites existing data** with null values

**Example Flow**:
```json
// 9:00 AM - Login webhook (creates record)
{
  "Date": "2025-10-01",
  "employee": "John Doe",
  "login time": "09:00:00",
  "login location": "33.7, 73.0",
  "device id": "PHONE123",
  "picture start": "url1",
  "meter start": 1234
  // logout fields are null/empty
}

// 5:00 PM - Logout webhook (updates same record)
{
  "Date": "2025-10-01",
  "employee": "John Doe",
  "log out time": "17:00:00",
  "logout location": "33.7, 73.1",
  "picture end": "url2",
  "meter end": 5678
  // login fields can be null/empty - they won't overwrite existing data
}

// Result: Single record with both login AND logout data
```

### 2. **Employee Name Matching**
The webhook uses 4 matching strategies (same as CSV import):
- **Exact match**: `"John Doe"` matches `"John Doe"`
- **Case-insensitive**: `"john doe"` matches `"John Doe"`
- **Starts with**: `"John"` matches `"John Doe"`
- **Contains**: `"Doe"` matches `"John Doe"`

### 3. **Name Cleaning**
Automatically removes common suffixes:
- `"John Doe - indrive"` → `"John Doe"`
- `"John Doe - indriver"` → `"John Doe"`
- Extra spaces are trimmed

### 4. **Duplicate Prevention**
- Uses `user_id` + `attendance_date` as unique key
- Automatically updates existing records instead of creating duplicates
- Preserves original `created_at` and `created_by` when updating

### 5. **Time Format Flexibility**
Accepts multiple time formats:
- `HH:MM:SS` → `"09:15:00"`
- `HH:MM` → automatically converts to `"09:15:00"`
- Any PHP-parseable time string

### 6. **Audit Trail with Employee Names**
- New records: `created_by = 1` (system admin), notes track actual employee
- Updated records: `updated_by = 1` (system admin), notes track actual employee
- Notes field includes: `"(Created by: John Doe via AppSheet)"` or `"(Updated by: John Doe via AppSheet)"`
- This allows you to see WHO created/updated the record even though the system user ID is used

---

## 🛠️ AppSheet Configuration Steps

### Step 1: Create a Bot/Automation
1. In AppSheet, go to **Automation** → **Bots**
2. Click **New Bot**
3. Name it: `Attendance Sync to Webapp`

### Step 2: Configure the Trigger
Choose your trigger event:
- **Option A**: When a new attendance record is added
- **Option B**: When an attendance record is updated
- **Option C**: On a schedule (every 5 minutes, hourly, etc.)

### Step 3: Add a Webhook Task
1. Add a new task to your bot
2. Select **Call a webhook**
3. Configure:
   - **URL**: `https://your-domain.com/webhook/appsheet/attendance-update`
   - **Method**: `POST`
   - **Headers**: 
     ```
     Content-Type: application/json
     ```
   - **Body**: (Map your AppSheet columns)
     ```json
     {
       "date": <<[Date]>>,
       "employee": <<[Employee]>>,
       "login_time": <<[Login Time]>>,
       "logout_time": <<[Log Out Time]>>,
       "login_location": <<[Login Location]>>,
       "logout_location": <<[Logout Location]>>,
       "device_id": <<[Device ID]>>,
       "meter_start": <<[Meter Start]>>,
       "meter_end": <<[Meter End]>>,
       "picture_start": <<[Picture Start]>>,
       "picture_end": <<[Picture End]>>,
       "notes": <<[Notes]>>
     }
     ```

### Step 4: Test the Webhook
1. In AppSheet, use the **Test** button to simulate the webhook
2. Check your Laravel logs: `storage/logs/laravel.log`
3. Look for: `AppSheet attendance-update webhook received`

---

## 🐛 Debugging & Troubleshooting

### Check Laravel Logs
All webhook activity is logged:
```bash
tail -f storage/logs/laravel.log | grep "attendance-update"
```

### Common Issues

#### Issue 1: Employee Not Found
**Error**: `"Employee not found in system: John Doe"`

**Solutions**:
1. Check if the employee exists in the Users table
2. Verify the spelling exactly matches `t_sys_user.fullname`
3. Check if name has extra suffixes (will be auto-cleaned)
4. Use partial match (webhook tries 4 strategies)

#### Issue 2: Date Format Error
**Error**: `"Invalid date format: ..."`

**Solutions**:
1. Use ISO format: `YYYY-MM-DD` (e.g., `"2025-10-01"`)
2. Or any format PHP can parse: `"10/01/2025"`, `"Oct 1, 2025"`
3. Ensure date column is formatted as Date in AppSheet

#### Issue 3: Webhook Not Firing
**Solutions**:
1. Check AppSheet Bot logs
2. Verify the URL is correct (no `/api/` prefix needed)
3. Test with the `/test` endpoint first: `POST /webhook/appsheet/test`
4. Check Laravel routes: `php artisan route:list | grep attendance`

#### Issue 4: Duplicate Records
**Note**: This should NOT happen due to `updateOrInsert` logic, but if it does:
1. Check if `t_ops_attendance` has proper unique constraint
2. Review logs to see if webhook is being called multiple times
3. Consider adding debouncing in AppSheet

---

## 🔒 Security Considerations

### Current State:
- Webhook is **public** (no authentication required)
- Same as existing order status webhook

### Future Enhancements (if needed):
1. **Add API Key Authentication**:
   - Add header check: `X-API-Key: your-secret-key`
   - Validate in middleware

2. **IP Whitelisting**:
   - Restrict to AppSheet's IP ranges
   - Configure in web server (nginx/Apache)

3. **Rate Limiting**:
   - Laravel throttle middleware
   - Limit to X requests per minute per IP

4. **Webhook Signature Verification**:
   - AppSheet signs webhooks with HMAC
   - Verify signature before processing

---

## 📊 Monitoring

### Check Webhook Activity
```sql
-- Count attendance records from webhook today
SELECT COUNT(*) 
FROM t_ops_attendance 
WHERE DATE(updated_at) = CURDATE() 
AND notes = 'AppSheet webhook';

-- Find failed matches
SELECT * 
FROM t_ops_attendance 
WHERE updated_at > NOW() - INTERVAL 1 HOUR
ORDER BY updated_at DESC;
```

### Laravel Log Search
```bash
# Success logs
grep "AppSheet attendance-update: record saved" storage/logs/laravel.log

# Error logs
grep "AppSheet attendance-update error" storage/logs/laravel.log

# Employee not found
grep "employee not found" storage/logs/laravel.log
```

---

## ✨ Comparison with CSV Import

| Feature | CSV Import | AppSheet Webhook |
|---------|-----------|------------------|
| **Real-time** | ❌ Manual | ✅ Automatic |
| **Batch Processing** | ✅ Multiple records | ❌ One at a time |
| **Error Summary** | ✅ Full report | ❌ Per-record only |
| **Employee Matching** | ✅ Same logic | ✅ Same logic |
| **Name Cleaning** | ✅ Yes | ✅ Yes |
| **Duplicate Prevention** | ✅ updateOrInsert | ✅ updateOrInsert |
| **Audit Trail** | ✅ Yes | ✅ Yes |
| **Missing Employee List** | ✅ Shows all | ❌ Returns 404 |

**Recommendation**: Use **both**!
- **Webhook** for real-time daily updates
- **CSV Import** for bulk historical data or corrections

---

## 🧪 Testing the Webhook

### Using cURL:
```bash
curl -X POST https://your-domain.com/webhook/appsheet/attendance-update \
  -H "Content-Type: application/json" \
  -d '{
    "date": "2025-10-01",
    "employee": "John Doe",
    "login_time": "09:15:00",
    "logout_time": "17:30:00",
    "notes": "Test from cURL"
  }'
```

### Using Postman:
1. Method: `POST`
2. URL: `https://your-domain.com/webhook/appsheet/attendance-update`
3. Headers: `Content-Type: application/json`
4. Body (raw JSON):
   ```json
   {
     "date": "2025-10-01",
     "employee": "John Doe",
     "login_time": "09:15:00",
     "logout_time": "17:30:00"
   }
   ```

### Using AppSheet Test Endpoint:
First, test with the generic test endpoint:
```bash
curl -X POST https://your-domain.com/webhook/appsheet/test \
  -H "Content-Type: application/json" \
  -d '{"test": "data"}'
```

Expected response:
```json
{
  "success": true,
  "message": "AppSheet webhook test completed",
  "received_data": {"test": "data"},
  "timestamp": "2025-10-01T12:00:00.000000Z"
}
```

---

## 📞 Support

If you encounter issues:
1. Check Laravel logs: `storage/logs/laravel.log`
2. Test with minimal payload (date + employee only)
3. Verify employee exists in Users table
4. Check database for duplicate records
5. Review AppSheet bot execution logs

---

**Created**: October 2025  
**Last Updated**: October 2025  
**Version**: 1.0

