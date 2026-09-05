<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;

class CompanyLocationsController extends Controller
{
    /**
     * Display the office locations management page
     */
    public function index()
    {
        return view('pages.attendance.locations', [
            'requireLocation' => $this->requireLocationEnabled(),
        ]);
    }

    /** Read the "riders must be at their location to check in" flag (default off). */
    private function requireLocationEnabled(): bool
    {
        try {
            $v = DB::table('t_fin_config')
                ->where('config_key', 'ATTENDANCE_REQUIRE_LOCATION')
                ->value('config_value');
            return $v !== null && (string) $v === '1';
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Toggle the mandatory-location rule. When ON, riders can only check in on the
     * mobile app when GPS confirms they're within their shift location's radius
     * (the manager's web "Mark Attendance" stays exempt). Stored in t_fin_config.
     */
    public function setRequireLocation(Request $request)
    {
        try {
            $enabled = $request->boolean('enabled') ? '1' : '0';
            $exists = DB::table('t_fin_config')
                ->where('config_key', 'ATTENDANCE_REQUIRE_LOCATION')->exists();
            if ($exists) {
                DB::table('t_fin_config')
                    ->where('config_key', 'ATTENDANCE_REQUIRE_LOCATION')
                    ->update(['config_value' => $enabled, 'updated_at' => now()]);
            } else {
                DB::table('t_fin_config')->insert([
                    'config_key' => 'ATTENDANCE_REQUIRE_LOCATION',
                    'config_value' => $enabled,
                    'description' => 'When on, riders can only check in (mobile) while inside their shift location radius.',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            Log::info('Attendance require-location setting changed', [
                'enabled' => $enabled, 'by' => Auth::id(),
            ]);
            return response()->json(['success' => true, 'enabled' => $enabled === '1']);
        } catch (\Throwable $e) {
            Log::error('Failed to set require-location', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Could not save the setting.'], 500);
        }
    }

    /**
     * Get all company locations with user assignment counts
     */
    public function getLocations()
    {
        try {
            $locations = DB::table('t_ops_company_locations as loc')
                ->leftJoin('t_ops_user_location_assignment as ula', function($join) {
                    $join->on('ula.location_id', '=', 'loc.id')
                         ->where('ula.is_active', 1);
                })
                // ⚠ Van MEET-UP POINTS share this table but are not offices — they
                //   have no staff, no attendance and no shifts. Showing them here
                //   would let someone assign a rider to a roadside rendezvous as his
                //   work location. They are managed on their own screen instead.
                //   Schema-guarded, so this is a no-op before batch 14.
                ->when(\App\Services\LocationService::hasHandoverPointColumn(), function ($q) {
                    $q->where(function ($w) {
                        $w->where('loc.is_handover_point', 0)->orWhereNull('loc.is_handover_point');
                    });
                })
                /**
                 * ⚠ WORKSHOPS ARE **NOT** FILTERED OUT HERE, deliberately. This feeds the
                 *   locations ADMIN page itself — the one screen where a workshop is ticked
                 *   as such — so hiding them here would hide the rows being managed.
                 *   The exclusion that matters is on the shift-ASSIGN picker
                 *   (Ops\ShiftPlannerController) and in LocationService::isAssignable.
                 */
                ->select(array_merge([
                    'loc.id',
                    'loc.location_name',
                    'loc.latitude',
                    'loc.longitude',
                    'loc.radius_meters',
                    'loc.is_primary',
                    'loc.is_active',
                    'loc.created_at',
                ],
                    // ⚠ The admin page draws the 🔧 WORKSHOP badge and pre-ticks the edit box from
                    //   this flag. Without it here the box was ALWAYS unticked, so editing a
                    //   workshop's name would have silently un-flagged it (Sep-4 review).
                    \App\Services\LocationService::hasWorkshopColumn() ? ['loc.is_workshop'] : [],
                    [DB::raw('COUNT(DISTINCT ula.user_id) as assigned_users_count')]
                ))
                ->groupBy(array_merge(
                    ['loc.id', 'loc.location_name', 'loc.latitude', 'loc.longitude',
                     'loc.radius_meters', 'loc.is_primary', 'loc.is_active', 'loc.created_at'],
                    \App\Services\LocationService::hasWorkshopColumn() ? ['loc.is_workshop'] : []
                ))
                ->orderBy('loc.is_primary', 'desc')
                ->orderBy('loc.location_name')
                ->get();

            return response()->json([
                'success' => true,
                'locations' => $locations
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to fetch locations', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch locations'
            ], 500);
        }
    }

    /**
     * Create a new company location
     */
    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'location_name' => 'required|string|max:100',
                'latitude' => 'required|numeric|between:-90,90',
                'longitude' => 'required|numeric|between:-180,180',
                'radius_meters' => 'required|integer|min:100|max:10000',
                'is_primary' => 'boolean',
                // ⚠ Phase 4: marks a WORKSHOP. Excluded from the office picker above, and
                //   only ever reaches a rider's day through the one-day override that
                //   scheduling a workshop visit writes.
                'is_workshop' => 'boolean',
                'is_active' => 'boolean'
            ]);

            DB::beginTransaction();

            // If this is set as primary, unset all other primary locations
            if (!empty($validated['is_primary'])) {
                DB::table('t_ops_company_locations')->update(['is_primary' => 0]);
            }

            $locationId = DB::table('t_ops_company_locations')->insertGetId([
                'location_name' => $validated['location_name'],
                'latitude' => $validated['latitude'],
                'longitude' => $validated['longitude'],
                'radius_meters' => $validated['radius_meters'],
                'is_primary' => $validated['is_primary'] ?? 0,
                // Schema-guarded like every read of this column: saving a location must not
                // 500 if the Sep-2026 SQL has not been run yet.
                ...(\App\Services\LocationService::hasWorkshopColumn() ? ['is_workshop' => $validated['is_workshop'] ?? 0] : []),
                'is_active' => $validated['is_active'] ?? 1,
                'created_at' => now(),
                'updated_at' => now()
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Location created successfully',
                'location_id' => $locationId
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to create location', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to create location: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update an existing company location
     */
    public function update(Request $request, $id)
    {
        try {
            $validated = $request->validate([
                'location_name' => 'required|string|max:100',
                'latitude' => 'required|numeric|between:-90,90',
                'longitude' => 'required|numeric|between:-180,180',
                'radius_meters' => 'required|integer|min:100|max:10000',
                'is_primary' => 'boolean',
                // ⚠ Phase 4: marks a WORKSHOP. Excluded from the office picker above, and
                //   only ever reaches a rider's day through the one-day override that
                //   scheduling a workshop visit writes.
                'is_workshop' => 'boolean',
                'is_active' => 'boolean'
            ]);

            DB::beginTransaction();

            // Check if location exists
            $location = DB::table('t_ops_company_locations')->where('id', $id)->first();
            if (!$location) {
                return response()->json([
                    'success' => false,
                    'message' => 'Location not found'
                ], 404);
            }

            // If this is set as primary, unset all other primary locations
            if (!empty($validated['is_primary'])) {
                DB::table('t_ops_company_locations')
                    ->where('id', '!=', $id)
                    ->update(['is_primary' => 0]);
            }

            DB::table('t_ops_company_locations')
                ->where('id', $id)
                ->update([
                    'location_name' => $validated['location_name'],
                    'latitude' => $validated['latitude'],
                    'longitude' => $validated['longitude'],
                    'radius_meters' => $validated['radius_meters'],
                    'is_primary' => $validated['is_primary'] ?? 0,
                    ...(\App\Services\LocationService::hasWorkshopColumn() ? ['is_workshop' => $validated['is_workshop'] ?? 0] : []),
                    'is_active' => $validated['is_active'] ?? 1,
                    'updated_at' => now()
                ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Location updated successfully'
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to update location', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to update location: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete a company location
     */
    public function destroy($id)
    {
        try {
            DB::beginTransaction();

            // Check if location exists
            $location = DB::table('t_ops_company_locations')->where('id', $id)->first();
            if (!$location) {
                return response()->json([
                    'success' => false,
                    'message' => 'Location not found'
                ], 404);
            }

            // Prevent deletion of primary location if it's the only one
            if ($location->is_primary) {
                $otherLocations = DB::table('t_ops_company_locations')
                    ->where('id', '!=', $id)
                    ->where('is_active', 1)
                    ->count();
                
                if ($otherLocations == 0) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Cannot delete the only active location. Please create another location first.'
                    ], 400);
                }
            }

            // Delete location (cascade will handle user assignments)
            DB::table('t_ops_company_locations')->where('id', $id)->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Location deleted successfully'
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to delete location', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete location: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get users assigned to a specific location
     */
    public function getLocationUsers($locationId)
    {
        try {
            $users = DB::table('t_ops_user_location_assignment as ula')
                ->join('t_sys_user as u', 'u.id', '=', 'ula.user_id')
                ->leftJoin('t_sys_user_role as ur', 'ur.user_id', '=', 'u.id')
                ->leftJoin('t_sys_role as r', 'r.id', '=', 'ur.role_id')
                ->where('ula.location_id', $locationId)
                ->where('ula.is_active', 1)
                ->select(
                    'u.id',
                    'u.fullname',
                    'u.email',
                    'r.urole_name as role_name',
                    'ula.assigned_at',
                    'ula.id as assignment_id'
                )
                ->orderBy('u.fullname')
                ->get();

            return response()->json([
                'success' => true,
                'users' => $users
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to fetch location users', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch users'
            ], 500);
        }
    }

    /**
     * Get all active users for assignment
     */
    public function getAvailableUsers()
    {
        try {
            $users = DB::table('t_sys_user as u')
                ->leftJoin('t_sys_user_role as ur', 'ur.user_id', '=', 'u.id')
                ->leftJoin('t_sys_role as r', 'r.id', '=', 'ur.role_id')
                ->select(
                    'u.id',
                    'u.fullname',
                    'u.email',
                    'r.urole_name as role_name'
                )
                ->where('u.is_active', 1)
                ->orderBy('u.fullname')
                ->get();

            return response()->json([
                'success' => true,
                'users' => $users
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to fetch available users', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch users'
            ], 500);
        }
    }

    /**
     * Assign users to a location
     */
    public function assignUsers(Request $request, $locationId)
    {
        try {
            $validated = $request->validate([
                'user_ids' => 'required|array',
                'user_ids.*' => 'integer|exists:t_sys_user,id'
            ]);

            DB::beginTransaction();

            // Check if location exists
            $location = DB::table('t_ops_company_locations')->where('id', $locationId)->first();
            if (!$location) {
                return response()->json([
                    'success' => false,
                    'message' => 'Location not found'
                ], 404);
            }

            $assignedBy = Auth::id();
            $assignments = [];
            $now = now();

            foreach ($validated['user_ids'] as $userId) {
                // Check if assignment already exists
                $existing = DB::table('t_ops_user_location_assignment')
                    ->where('user_id', $userId)
                    ->where('location_id', $locationId)
                    ->first();

                if (!$existing) {
                    $assignments[] = [
                        'user_id' => $userId,
                        'location_id' => $locationId,
                        'assigned_by' => $assignedBy,
                        'assigned_at' => $now,
                        'is_active' => 1,
                        'created_at' => $now,
                        'updated_at' => $now
                    ];
                }
            }

            if (!empty($assignments)) {
                DB::table('t_ops_user_location_assignment')->insert($assignments);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => count($assignments) . ' user(s) assigned successfully'
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to assign users', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to assign users: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove user assignment from a location
     */
    public function removeUserAssignment($assignmentId)
    {
        try {
            DB::beginTransaction();

            $deleted = DB::table('t_ops_user_location_assignment')
                ->where('id', $assignmentId)
                ->delete();

            if (!$deleted) {
                return response()->json([
                    'success' => false,
                    'message' => 'Assignment not found'
                ], 404);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'User assignment removed successfully'
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to remove user assignment', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to remove assignment: ' . $e->getMessage()
            ], 500);
        }
    }
}

