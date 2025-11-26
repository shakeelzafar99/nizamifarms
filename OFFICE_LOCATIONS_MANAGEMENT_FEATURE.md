# Office Locations Management Feature

## Overview
This feature allows administrators to manage multiple office locations and assign users to specific locations for attendance tracking. Each user's attendance check-in distance is calculated from their assigned office location (or the primary location if no assignment exists).

## Features

### 1. Multiple Office Locations
- Create and manage multiple office locations (Main Office, Warehouse, Branch Office, etc.)
- Each location has:
  - **Location Name**: Descriptive name for easy identification
  - **GPS Coordinates**: Latitude and longitude
  - **Allowed Radius**: Distance within which check-ins are considered on-site (100-10,000 meters)
  - **Primary Flag**: One location can be marked as primary (default for unassigned users)
  - **Active Status**: Enable/disable locations

### 2. User Assignment
- Assign specific users to specific office locations
- Users can be assigned to their nearest office
- Multiple users can be assigned to the same location
- View all users assigned to each location
- Remove user assignments when needed

### 3. Attendance Tracking
- User check-ins are automatically calculated against their assigned location
- Falls back to primary location if user has no specific assignment
- Remote check-ins are flagged if distance exceeds the location's radius
- Location information is displayed in attendance records

## Database Schema

### Tables

#### `t_ops_company_locations`
Stores office location information.

| Column | Type | Description |
|--------|------|-------------|
| id | INT | Primary key |
| location_name | VARCHAR(100) | Office name (e.g., "Main Office") |
| latitude | DECIMAL(10,8) | GPS latitude |
| longitude | DECIMAL(11,8) | GPS longitude |
| radius_meters | INT | Allowed radius in meters (default 2000) |
| is_primary | BOOLEAN | Primary location flag |
| is_active | BOOLEAN | Active status |
| created_at | TIMESTAMP | Creation timestamp |
| updated_at | TIMESTAMP | Last update timestamp |

#### `t_ops_user_location_assignment`
Assigns users to specific locations.

| Column | Type | Description |
|--------|------|-------------|
| id | INT | Primary key |
| user_id | INT | User ID (FK to t_sys_user) |
| location_id | INT | Location ID (FK to t_ops_company_locations) |
| assigned_at | TIMESTAMP | Assignment timestamp |
| assigned_by | INT | Who assigned this user (FK to t_sys_user) |
| is_active | BOOLEAN | Active status |
| created_at | TIMESTAMP | Creation timestamp |
| updated_at | TIMESTAMP | Last update timestamp |

**Constraints:**
- Unique constraint on (user_id, location_id) - prevents duplicate assignments
- Cascade delete on user_id and location_id
- Set NULL on assigned_by deletion

## Backend Implementation

### Controller: `CompanyLocationsController`

**Location:** `app/Http/Controllers/CRM/CompanyLocationsController.php`

**Methods:**
- `index()` - Display locations management page
- `getLocations()` - Get all locations with user counts (API)
- `store(Request $request)` - Create new location
- `update(Request $request, $id)` - Update existing location
- `destroy($id)` - Delete location
- `getLocationUsers($locationId)` - Get users assigned to a location
- `getAvailableUsers()` - Get all active users for assignment
- `assignUsers(Request $request, $locationId)` - Assign users to location
- `removeUserAssignment($assignmentId)` - Remove user assignment

### Service: `LocationService`

**Location:** `app/Services/LocationService.php`

**Updated Methods:**
- `getUserAssignedLocation(int $userId)` - Get user's assigned location (falls back to primary)
- `calculateDistanceFromBase($latitude, $longitude, $userId = null)` - Calculate distance with user context

**Existing Methods:**
- `calculateDistance($lat1, $lon1, $lat2, $lon2)` - Haversine formula for GPS distance
- `getPrimaryBaseLocation()` - Get primary location
- `formatDistance(int $meters)` - Format distance for display
- `isValidCoordinates($latitude, $longitude)` - Validate GPS coordinates
- `getLocationDisplay($latitude, $longitude, $distance, $isRemote)` - Format location info

## Frontend Implementation

### Page: Office Locations Management

**Location:** `resources/views/pages/attendance/locations.blade.php`

**Features:**
- **Locations Table**: View all office locations with:
  - Location name and primary badge
  - GPS coordinates with Google Maps link
  - Allowed radius
  - Number of assigned users (clickable)
  - Active/Inactive status
  - Edit and Delete actions

- **Add/Edit Location Modal**:
  - Location name input
  - Latitude and longitude inputs
  - Radius slider/input (100-10,000 meters)
  - Primary location checkbox
  - Active status checkbox
  - Google Maps tip for getting coordinates

- **Manage Users Modal**:
  - Multi-select dropdown of all active users
  - Assign selected users to location
  - Table of currently assigned users
  - Remove assignment button for each user
  - Shows user role and assignment date

### Navigation

Access from: **Attendance Management → Settings → 📍 Office Locations**

## Routes

**Web Routes** (`routes/web.php`):

```php
// Company Locations Management
Route::get('/attendance/locations', [CompanyLocationsController::class, 'index']);
Route::get('/attendance/locations/data', [CompanyLocationsController::class, 'getLocations']);
Route::post('/attendance/locations', [CompanyLocationsController::class, 'store']);
Route::put('/attendance/locations/{id}', [CompanyLocationsController::class, 'update']);
Route::delete('/attendance/locations/{id}', [CompanyLocationsController::class, 'destroy']);
Route::get('/attendance/locations/{id}/users', [CompanyLocationsController::class, 'getLocationUsers']);
Route::get('/attendance/locations/available-users', [CompanyLocationsController::class, 'getAvailableUsers']);
Route::post('/attendance/locations/{id}/assign-users', [CompanyLocationsController::class, 'assignUsers']);
Route::delete('/attendance/locations/assignments/{id}', [CompanyLocationsController::class, 'removeUserAssignment']);
```

## Usage Guide

### For Administrators

#### 1. Add a New Office Location

1. Go to **Attendance Management**
2. Click **Settings** → **📍 Office Locations**
3. Click **➕ Add Location**
4. Fill in the form:
   - **Location Name**: e.g., "Warehouse - Rawalpindi"
   - **Latitude/Longitude**: Get from Google Maps (right-click → "What's here?")
   - **Allowed Radius**: Set appropriate distance (e.g., 2000 meters = 2 km)
   - **Primary Location**: Check if this should be the default location
   - **Active**: Check to enable
5. Click **Save Location**

#### 2. Assign Users to a Location

1. In the locations table, click the **"X user(s) →"** link for the desired location
2. In the "Assign Users" section:
   - Hold Ctrl/Cmd and click to select multiple users
   - Click **Assign →**
3. Users will appear in the "Currently Assigned Users" table
4. To remove a user, click **Remove** next to their name

#### 3. Edit a Location

1. Click **Edit** next to the location
2. Update the desired fields
3. Click **Save Location**

#### 4. Delete a Location

1. Click **Delete** next to the location
2. Confirm the deletion
3. **Note**: All user assignments will also be removed

### For Users (Mobile App)

- When checking in, the app automatically uses the user's assigned office location
- If no assignment exists, the primary location is used
- Distance is calculated and displayed in the attendance record
- Remote check-ins are flagged if beyond the allowed radius

## Business Rules

1. **Primary Location**:
   - Only one location can be marked as primary at a time
   - Setting a new primary location automatically unsets the previous one
   - Primary location is used for users without specific assignments

2. **Location Deletion**:
   - Cannot delete the only active location
   - Deleting a location removes all user assignments (cascade delete)
   - Confirmation required before deletion

3. **User Assignment**:
   - Users can only be assigned to one location at a time (enforced by unique constraint)
   - Duplicate assignments are prevented
   - Assignments can be removed at any time

4. **Distance Calculation**:
   - Uses Haversine formula for accurate GPS distance
   - Calculated in meters, displayed as meters or kilometers
   - Remote flag set if distance > location's radius

## Migration Files

1. **`add_location_tracking_to_attendance_nov20_2025.sql`**
   - Creates `t_ops_company_locations` table
   - Adds location columns to `t_ops_attendance`
   - Inserts default Nizami Farms Office location

2. **`create_user_location_assignment_table_nov25_2025.sql`**
   - Creates `t_ops_user_location_assignment` table
   - Sets up foreign keys and constraints

## Testing Checklist

- [ ] Create a new office location
- [ ] Edit an existing location
- [ ] Set a location as primary
- [ ] Assign users to a location
- [ ] Remove user assignments
- [ ] Delete a location
- [ ] Verify user check-in uses assigned location
- [ ] Verify fallback to primary location for unassigned users
- [ ] Test remote check-in flagging
- [ ] Verify Google Maps links work correctly

## Future Enhancements

- [ ] Bulk user assignment (CSV import)
- [ ] Location history tracking
- [ ] Geofencing alerts
- [ ] Location-based reports
- [ ] Map view of all locations
- [ ] Auto-assignment based on user's home address
- [ ] Multiple location assignments per user (for traveling employees)

## Support

For issues or questions, contact the development team.

---

**Created**: November 25, 2025  
**Last Updated**: November 25, 2025  
**Version**: 1.0

