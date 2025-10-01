# Attendance Webhook Testing Guide

## 🧪 Test Scenario: Two-Step Login/Logout

This simulates the real AppSheet flow where login and logout come as separate webhook calls.

### Test 1: Login (Morning)
```bash
curl -X POST http://127.0.0.1:8000/webhook/appsheet/attendance-update \
  -H "Content-Type: application/json" \
  -d '{
    "Date": "2025-10-01",
    "employee": "John Doe",
    "login time": "09:15:00",
    "login location": "33.7123, 73.0456",
    "device id": "PHONE-ABC123",
    "picture start": "https://example.com/login.jpg",
    "meter start": 1234,
    "log out time": null,
    "logout location": null,
    "meter end": null,
    "picture end": null
  }'
```

**Expected Response**:
```json
{
  "success": true,
  "message": "Attendance record created",
  "user_id": 73,
  "fullname": "John Doe",
  "attendance_date": "2025-10-01",
  "login_time": "09:15:00",
  "logout_time": null
}
```

### Test 2: Logout (Evening - same day)
```bash
curl -X POST http://127.0.0.1:8000/webhook/appsheet/attendance-update \
  -H "Content-Type: application/json" \
  -d '{
    "Date": "2025-10-01",
    "employee": "John Doe",
    "login time": null,
    "login location": null,
    "device id": "PHONE-ABC123",
    "picture start": null,
    "meter start": null,
    "log out time": "17:30:00",
    "logout location": "33.7150, 73.0480",
    "meter end": 5678,
    "picture end": "https://example.com/logout.jpg"
  }'
```

**Expected Response**:
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

### Verify in Database
```sql
SELECT 
  user_id,
  attendance_date,
  login_time,
  logout_time,
  login_lat,
  login_lng,
  logout_lat,
  logout_lng,
  device_id,
  meter_start,
  meter_end,
  picture_start,
  picture_end,
  notes,
  created_by,
  updated_by
FROM t_ops_attendance
WHERE attendance_date = '2025-10-01'
AND user_id = (SELECT id FROM t_sys_user WHERE fullname = 'John Doe');
```

**Expected Result**:
- `login_time`: `09:15:00` (from first webhook)
- `logout_time`: `17:30:00` (from second webhook)
- `login_lat`: `33.7123` (from first webhook)
- `logout_lat`: `33.7150` (from second webhook)
- `meter_start`: `1234` (from first webhook)
- `meter_end`: `5678` (from second webhook)
- `notes`: Contains both `"Created by: John Doe via AppSheet"` and `"Updated by: John Doe via AppSheet"`
- `created_by`: `1` (system admin)
- `updated_by`: `1` (system admin)

---

## 🚨 Edge Case Tests

### Test 3: Logout Before Login (out of order)
```bash
# First: Logout comes first (unusual but possible)
curl -X POST http://127.0.0.1:8000/webhook/appsheet/attendance-update \
  -H "Content-Type: application/json" \
  -d '{
    "Date": "2025-10-02",
    "employee": "John Doe",
    "log out time": "17:30:00",
    "logout location": "33.7150, 73.0480"
  }'

# Then: Login comes later
curl -X POST http://127.0.0.1:8000/webhook/appsheet/attendance-update \
  -H "Content-Type: application/json" \
  -d '{
    "Date": "2025-10-02",
    "employee": "John Doe",
    "login time": "09:15:00",
    "login location": "33.7123, 73.0456"
  }'
```

**Result**: Both should work fine! The webhook handles any order.

### Test 4: Login Only (No logout)
```bash
curl -X POST http://127.0.0.1:8000/webhook/appsheet/attendance-update \
  -H "Content-Type: application/json" \
  -d '{
    "Date": "2025-10-03",
    "employee": "John Doe",
    "login time": "09:15:00",
    "login location": "33.7123, 73.0456"
  }'
```

**Expected**: Record created with only login data, `logout_time` remains NULL.

### Test 5: Employee Name with Suffix
```bash
curl -X POST http://127.0.0.1:8000/webhook/appsheet/attendance-update \
  -H "Content-Type: application/json" \
  -d '{
    "Date": "2025-10-04",
    "employee": "John Doe - indrive",
    "login time": "09:15:00"
  }'
```

**Expected**: Automatically cleans to `"John Doe"` and matches the user.

### Test 6: Case Insensitive Match
```bash
curl -X POST http://127.0.0.1:8000/webhook/appsheet/attendance-update \
  -H "Content-Type: application/json" \
  -d '{
    "Date": "2025-10-05",
    "employee": "john doe",
    "login time": "09:15:00"
  }'
```

**Expected**: Matches `"John Doe"` in database (case-insensitive).

### Test 7: Employee Not Found
```bash
curl -X POST http://127.0.0.1:8000/webhook/appsheet/attendance-update \
  -H "Content-Type: application/json" \
  -d '{
    "Date": "2025-10-06",
    "employee": "Nonexistent User",
    "login time": "09:15:00"
  }'
```

**Expected Response** (404):
```json
{
  "success": false,
  "message": "Employee not found in system: Nonexistent User (cleaned: Nonexistent User)"
}
```

### Test 8: Missing Required Fields
```bash
curl -X POST http://127.0.0.1:8000/webhook/appsheet/attendance-update \
  -H "Content-Type: application/json" \
  -d '{
    "employee": "John Doe"
  }'
```

**Expected Response** (422):
```json
{
  "success": false,
  "message": "Missing required fields: date and employee"
}
```

---

## 📊 Check Logs

### Watch Live Logs
```bash
tail -f storage/logs/laravel.log | grep "attendance-update"
```

### Search for Specific Employee
```bash
grep "John Doe" storage/logs/laravel.log | grep "attendance-update"
```

### Count Today's Webhook Calls
```bash
grep "$(date +%Y-%m-%d)" storage/logs/laravel.log | grep "attendance-update webhook received" | wc -l
```

---

## 🔍 Frontend Verification

After testing, verify in the webapp:

1. Go to **Attendance** page
2. Select the test date (e.g., `2025-10-01`)
3. Find "John Doe" in the table
4. Verify:
   - Login time shows `09:15`
   - Logout time shows `17:30`
   - Click **✏️** (edit) to see full details including GPS coordinates
   - Notes field should show audit trail

5. Go to **Attendance Reports**
6. Select October 2025
7. Find "John Doe"
8. Click **View Details**
9. Verify `Oct 1` row shows:
   - Login: `09:15:00`
   - Logout: `17:30:00`
   - Hours: Calculated correctly
   - Status: "On Time" or "Late" based on shift

---

## ✅ Success Checklist

- [ ] Login webhook creates new record
- [ ] Logout webhook updates same record (no duplicate)
- [ ] Login data preserved after logout webhook
- [ ] Logout data preserved after login webhook (if out of order)
- [ ] Employee name matching works (exact, case-insensitive, partial)
- [ ] Name cleaning removes "- indrive" suffix
- [ ] GPS coordinates parsed correctly
- [ ] Meter readings stored as integers
- [ ] Picture URLs stored correctly
- [ ] Notes show audit trail with employee name
- [ ] Database has single record per employee per day
- [ ] `created_by` and `updated_by` = 1 (system admin)
- [ ] `created_at` preserved on updates
- [ ] `updated_at` changes on updates
- [ ] Logs show detailed step-by-step processing
- [ ] 404 error for non-existent employee
- [ ] 422 error for missing date or employee

---

**Pro Tip**: Use Postman to save these requests as a collection for easy re-testing!

