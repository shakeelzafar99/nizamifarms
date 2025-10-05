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
        $validator = Validator::make($request->all(), [
            'holiday_date' => 'required|date|after_or_equal:today|unique:t_ops_public_holidays,holiday_date',
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
            $holiday = PublicHolidayModel::create([
                'holiday_date' => $request->holiday_date,
                'holiday_name' => $request->holiday_name,
                'description' => $request->description,
                'is_active' => true,
                'created_by' => auth()->id(),
                'updated_by' => auth()->id()
            ]);

            // Clear shift cache since holidays affect working days calculation
            $this->shiftService->clearAllShiftCaches();

            return response()->json([
                'success' => true,
                'message' => 'Holiday added successfully',
                'data' => $holiday
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



