<?php

namespace App\Http\Controllers\Ops;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Ops\PublicHolidayModel;
use App\Services\ShiftResolutionService;
use Illuminate\Support\Facades\Validator;

class HolidayController extends Controller
{
    protected $shiftService;

    public function __construct()
    {
        $this->shiftService = new ShiftResolutionService();
    }

    /**
     * Display holidays management page
     */
    public function index()
    {
        return view('pages.holidays.index');
    }

    /**
     * Get all holidays
     */
    public function list(Request $request)
    {
        $year = $request->input('year', date('Y'));
        
        $holidays = PublicHolidayModel::active()
            ->whereYear('holiday_date', $year)
            ->orderBy('holiday_date')
            ->get()
            ->map(function($holiday) {
                return [
                    'id' => $holiday->id,
                    'holiday_date' => $holiday->holiday_date->format('Y-m-d'),
                    'holiday_date_formatted' => $holiday->holiday_date->format('d M Y'),
                    'holiday_name' => $holiday->holiday_name,
                    'description' => $holiday->description,
                    'day_of_week' => $holiday->holiday_date->format('l')
                ];
            });

        return response()->json([
            'success' => true,
            'data' => $holidays
        ]);
    }

    /**
     * Store a new holiday
     */
    public function store(Request $request)
    {
        // holiday_end_date is optional — when given, the whole from–end range is added
        // as one action (e.g. an Eid break) instead of one date at a time. Each day
        // that already exists is skipped, not errored, so re-adding an overlapping range
        // is safe.
        // PAST dates are allowed on purpose: the owner backfills historical holidays so
        // past working-day/attendance math is correct (an Eid that was missed shouldn't
        // read as everyone Absent). clearAllShiftCaches() below re-computes affected days.
        $validator = Validator::make($request->all(), [
            'holiday_date' => 'required|date',
            'holiday_end_date' => 'nullable|date|after_or_equal:holiday_date',
            'holiday_name' => 'required|string|max:200',
            'description' => 'nullable|string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first()
            ], 422);
        }

        try {
            $start = \Carbon\Carbon::parse($request->holiday_date)->startOfDay();
            $end = $request->filled('holiday_end_date')
                ? \Carbon\Carbon::parse($request->holiday_end_date)->startOfDay()
                : $start->copy();
            if ($end->lt($start)) {
                $end = $start->copy();
            }
            // Guard a runaway range (fat-fingered year etc.).
            if ($start->diffInDays($end) > 60) {
                return response()->json([
                    'success' => false,
                    'message' => 'That range is more than 60 days — please add it in smaller pieces.'
                ], 422);
            }

            $created = 0; $skipped = 0;
            for ($d = $start->copy(); $d->lte($end); $d->addDay()) {
                $ds = $d->format('Y-m-d');
                if (PublicHolidayModel::whereDate('holiday_date', $ds)->exists()) {
                    $skipped++;
                    continue;
                }
                PublicHolidayModel::create([
                    'holiday_date' => $ds,
                    'holiday_name' => $request->holiday_name,
                    'description' => $request->description,
                    'is_active' => true,
                    'created_by' => auth()->id(),
                    'updated_by' => auth()->id()
                ]);
                $created++;
            }

            // Clear shift cache since holidays affect working days calculation
            $this->shiftService->clearAllShiftCaches();

            $message = $created > 0
                ? ($created . ' holiday' . ($created > 1 ? ' days' : '') . ' added'
                    . ($skipped > 0 ? " ({$skipped} already existed)" : ''))
                : 'Those dates are already marked as holidays';

            return response()->json([
                'success' => true,
                'message' => $message,
                'created' => $created,
                'skipped' => $skipped
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error adding holiday: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete a holiday
     */
    public function destroy($id)
    {
        $holiday = PublicHolidayModel::find($id);
        
        if (!$holiday) {
            return response()->json([
                'success' => false,
                'message' => 'Holiday not found'
            ], 404);
        }

        try {
            $holiday->delete();

            // Clear shift cache
            $this->shiftService->clearAllShiftCaches();

            return response()->json([
                'success' => true,
                'message' => 'Holiday deleted successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error deleting holiday: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get upcoming holidays
     */
    public function upcoming(Request $request)
    {
        $days = $request->input('days', 90);
        
        $holidays = PublicHolidayModel::getUpcomingHolidays($days);

        return response()->json([
            'success' => true,
            'data' => $holidays->map(function($holiday) {
                return [
                    'id' => $holiday->id,
                    'holiday_date' => $holiday->holiday_date->format('Y-m-d'),
                    'holiday_date_formatted' => $holiday->holiday_date->format('d M Y'),
                    'holiday_name' => $holiday->holiday_name,
                    'days_until' => now()->diffInDays($holiday->holiday_date, false)
                ];
            })
        ]);
    }

    /**
     * Get all available years with holidays
     */
    public function getYears()
    {
        $years = PublicHolidayModel::selectRaw('DISTINCT YEAR(holiday_date) as year')
            ->orderBy('year', 'desc')
            ->pluck('year');

        // Always include current year even if no holidays
        $currentYear = (int)date('Y');
        if (!$years->contains($currentYear)) {
            $years->prepend($currentYear);
        }

        return response()->json([
            'success' => true,
            'data' => $years
        ]);
    }
}



