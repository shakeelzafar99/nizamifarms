<?php

namespace App\Http\Controllers\Ops;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Services\ShiftResolutionService;
use App\Models\Ops\ShiftTemplateModel;
use App\Models\Ops\PublicHolidayModel;
use App\Models\Ops\UserShiftAssignmentModel;

/**
 * Shift Planner — the single place to see and change who works which shift.
 * Read-only aggregation; all writes go through ShiftController (assign / cancel-change),
 * which owns the layered primary + temporary-override engine.
 */
class ShiftPlannerController extends Controller
{
    public function index()
    {
        return view('pages.shifts.planner');
    }

    /**
     * Save/update a rider's WhatsApp contact number (unified location =
     * t_ops_rider_profile.phone) from the planner's "add number" prompt. Upserts
     * by user_id; never touches other profile fields.
     */
    public function updatePhone(Request $request)
    {
        $data = $request->validate([
            'user_id' => 'required|integer|exists:t_sys_user,id',
            'phone' => 'required|string|max:50',
        ]);
        // A real number, not a note: at least 10 digits somewhere in the string.
        if (strlen(preg_replace('/\D/', '', $data['phone'])) < 10) {
            return response()->json(['success' => false, 'message' => 'Please enter a valid number (at least 10 digits).'], 422);
        }
        $uid = (int) $data['user_id'];
        if (DB::table('t_ops_rider_profile')->where('user_id', $uid)->exists()) {
            DB::table('t_ops_rider_profile')->where('user_id', $uid)
                ->update(['phone' => trim($data['phone']), 'updated_at' => now()]);
        } else {
            // New row (e.g. All-staff view, non-rider): active=0 EXPLICITLY — the column
            // defaults to 1, and saving a phone must never mint a delivery rider.
            DB::table('t_ops_rider_profile')->insert([
                'user_id' => $uid, 'phone' => trim($data['phone']), 'active' => 0,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        // Fire any WhatsApp that was skipped "no phone" for a pending change.
        try {
            app(ShiftController::class)->dispatchShiftWhatsApp($uid);
        } catch (\Throwable $e) {
            \Log::warning('planner update-phone WA redispatch failed (non-fatal)', ['error' => $e->getMessage()]);
        }
        return response()->json(['success' => true]);
    }

    /**
     * Week grid data: riders (or all staff) × 7 days, each day resolved to the shift
     * actually in effect, plus each rider's active/upcoming changes (for past/now/next
     * and the Cancel button), the assignable templates, and the week's holidays.
     */
    public function weekData(Request $request)
    {
        $svc = new ShiftResolutionService();

        // Week starts on Monday.
        $startInput = $request->input('start');
        $monday = $startInput
            ? \Carbon\Carbon::parse($startInput)->startOfWeek(\Carbon\Carbon::MONDAY)
            : \Carbon\Carbon::now()->startOfWeek(\Carbon\Carbon::MONDAY);

        $days = [];
        for ($i = 0; $i < 7; $i++) {
            $d = $monday->copy()->addDays($i);
            $days[] = [
                'date' => $d->format('Y-m-d'),
                'label' => $d->format('D'),
                'day' => $d->format('j'),
            ];
        }
        $dateList = array_column($days, 'date');
        $today = now()->format('Y-m-d');

        $filter = $request->input('filter', 'riders');
        $search = trim((string) $request->input('search', ''));

        $users = DB::table('t_sys_user as u')
            ->leftJoin('t_sys_user_role as ur', 'ur.user_id', '=', 'u.id')
            ->leftJoin('t_sys_role as r', 'r.id', '=', 'ur.role_id')
            ->leftJoin('t_ops_rider_profile as p', 'p.user_id', '=', 'u.id')
            ->where('u.is_active', 1)
            ->when($search !== '', fn($q) => $q->where('u.fullname', 'like', '%' . $search . '%'))
            ->select(
                'u.id as user_id',
                'u.fullname',
                DB::raw('MAX(r.urole_name) as role_name'),
                // "Delivery rider" = active rider profile — same list as the assign screens.
                DB::raw('MAX(CASE WHEN p.active = 1 THEN 1 ELSE 0 END) as is_rider'),
                // Has a WhatsApp number on file? (drives the "add number" prompt.)
                DB::raw("MAX(CASE WHEN p.phone IS NOT NULL AND TRIM(p.phone) <> '' THEN 1 ELSE 0 END) as has_phone")
            )
            ->groupBy('u.id', 'u.fullname')
            ->orderBy('u.fullname')
            ->get();

        if ($filter === 'riders') {
            $users = $users->filter(fn($u) => (int) $u->is_rider === 1);
        }

        // Holidays in the week (date => name).
        $holidayNames = DB::table('t_ops_public_holidays')
            ->where('is_active', 1)
            ->whereBetween('holiday_date', [$dateList[0], $dateList[6]])
            ->get()
            ->mapWithKeys(fn($h) => [(string) \Carbon\Carbon::parse($h->holiday_date)->format('Y-m-d') => $h->holiday_name])
            ->all();

        $riders = [];
        foreach ($users as $u) {
            $uid = (int) $u->user_id;
            $rows = UserShiftAssignmentModel::with('shiftTemplate')->where('user_id', $uid)->get();

            $cells = [];
            foreach ($dateList as $d) {
                $shift = $svc->getUserShift($uid, $d);
                $dow = (int) date('N', strtotime($d));
                $isHoliday = isset($holidayNames[$d]);
                $isOff = !in_array($dow, $shift['working_days']);
                // A bounded row covering this date means it's a temporary override.
                $isOverride = $rows->first(function ($row) use ($d) {
                    if (is_null($row->effective_to)) {
                        return false; // primary, not an override
                    }
                    $f = $row->effective_from ? $row->effective_from->format('Y-m-d') : null;
                    $t = $row->effective_to->format('Y-m-d');
                    return ($f === null || $f <= $d) && ($t >= $d);
                }) !== null;

                $cells[] = [
                    'date' => $d,
                    'shift_name' => $shift['shift_name'],
                    'start' => $shift['shift_start'],
                    'end' => $shift['shift_end'],
                    'is_off' => $isOff,
                    'is_holiday' => $isHoliday,
                    'is_override' => $isOverride,
                ];
            }

            $primary = $svc->getUserShift($uid, $today);

            // Active / upcoming changes → past/now/next + Cancel.
            $changes = [];
            foreach ($rows as $row) {
                $f = $row->effective_from ? $row->effective_from->format('Y-m-d') : null;
                $t = $row->effective_to ? $row->effective_to->format('Y-m-d') : null;
                if ($t !== null && $t >= $today) {
                    $changes[] = [
                        'assignment_id' => $row->id,
                        'kind' => 'temporary',
                        'shift_name' => optional($row->shiftTemplate)->shift_name,
                        'from' => $f,
                        'to' => $t,
                        'started' => ($f === null || $f <= $today),
                        'acknowledged' => $row->acknowledged_at !== null,
                    ];
                } elseif ($t === null && $f !== null && $f > $today) {
                    $changes[] = [
                        'assignment_id' => $row->id,
                        'kind' => 'upcoming_primary',
                        'shift_name' => optional($row->shiftTemplate)->shift_name,
                        'from' => $f,
                        'to' => null,
                        'started' => false,
                        'acknowledged' => $row->acknowledged_at !== null,
                    ];
                }
            }

            $riders[] = [
                'user_id' => $uid,
                'name' => $u->fullname,
                'role' => $u->role_name,
                'has_phone' => (int) ($u->has_phone ?? 0) === 1,
                'primary' => [
                    'shift_name' => $primary['shift_name'],
                    'start' => $primary['shift_start'],
                    'end' => $primary['shift_end'],
                ],
                'cells' => $cells,
                'changes' => $changes,
            ];
        }

        $templates = ShiftTemplateModel::where('active', 1)
            ->orderBy('shift_name')
            ->get()
            ->map(fn($t) => [
                'id' => $t->id,
                'name' => $t->shift_name,
                'start' => substr($t->shift_start, 0, 5),
                'end' => substr($t->shift_end, 0, 5),
                'off_days' => $t->getOffDaysString(),
            ])
            ->values();

        return response()->json([
            'success' => true,
            'week_start' => $dateList[0],
            'week_end' => $dateList[6],
            'prev_week' => $monday->copy()->subDays(7)->format('Y-m-d'),
            'next_week' => $monday->copy()->addDays(7)->format('Y-m-d'),
            'this_week' => \Carbon\Carbon::now()->startOfWeek(\Carbon\Carbon::MONDAY)->format('Y-m-d'),
            'days' => $days,
            'today' => $today,
            'holiday_names' => $holidayNames,
            'templates' => $templates,
            'riders' => array_values($riders),
        ]);
    }
}
