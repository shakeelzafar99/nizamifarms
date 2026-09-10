<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Services\Location\PlusCode;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class RiderProfileController extends Controller
{
    /** company_bike is added by the Phase-A SQL; guard reads/writes so the code
     *  works whether or not the owner has run the ALTER yet (memoized). */
    private static ?bool $hasCompanyBike = null;
    private function companyBikeColumnExists(): bool
    {
        if (self::$hasCompanyBike === null) {
            try { self::$hasCompanyBike = Schema::hasColumn('t_ops_rider_profile', 'company_bike'); }
            catch (\Throwable $e) { self::$hasCompanyBike = false; }
        }
        return self::$hasCompanyBike;
    }

    /** overnight_grace_km is added by the Phase-B SQL; guard the same way. */
    private static ?bool $hasOvernightGrace = null;
    private function overnightGraceColumnExists(): bool
    {
        if (self::$hasOvernightGrace === null) {
            try { self::$hasOvernightGrace = Schema::hasColumn('t_ops_rider_profile', 'overnight_grace_km'); }
            catch (\Throwable $e) { self::$hasOvernightGrace = false; }
        }
        return self::$hasOvernightGrace;
    }

    /**
     * The reference point a SHORT Plus Code is recovered against — the primary company
     * location. Every rider lives well inside the ~55 km radius within which recovery is
     * unambiguous (the furthest legitimate rider fix ever recorded is 35.6 km).
     * Falls back to the office's known coordinates if the table cannot be read, because a
     * missing row must not turn a readable link into an unreadable one.
     */
    private function pinReference(): array
    {
        try {
            $loc = DB::table('t_ops_company_locations')
                ->where('is_primary', 1)->where('is_active', 1)
                ->first(['latitude', 'longitude']);
            if ($loc && $loc->latitude !== null && $loc->longitude !== null) {
                return ['lat' => (float) $loc->latitude, 'lng' => (float) $loc->longitude];
            }
        } catch (\Throwable $e) {
            // fall through to the constant
        }
        return ['lat' => 33.70811597, 'lng' => 73.08868750];   // Nizami Farms Office
    }

    public function index()
    {
        $cols = [
            'u.id as user_id', 'u.id', 'u.fullname', 'u.email',
            'p.phone', 'p.vehicle_type', 'p.vehicle_plate',
            'p.hire_date', 'p.active as profile_active',
            'p.shift_start', 'p.shift_end',
        ];
        if ($this->companyBikeColumnExists()) {
            $cols[] = 'p.company_bike';
        }
        if ($this->overnightGraceColumnExists()) {
            $cols[] = 'p.overnight_grace_km';
        }
        // Home pin + any-office flag (guarded — added by later SQL) for the table indicators.
        if (Schema::hasColumn('t_ops_rider_profile', 'home_latitude')) {
            $cols[] = 'p.home_latitude';
        }
        if (Schema::hasColumn('t_ops_rider_profile', 'checkin_any_office')) {
            $cols[] = 'p.checkin_any_office';
        }
        if (Schema::hasColumn('t_ops_rider_profile', 'meter_required')) {
            $cols[] = 'p.meter_required';
        }
        // WHO IS ON THIS PAGE: a rider by ROLE, **or** anyone ticked "Delivery Rider" in the
        // People & Rider List (`rider_profile.active = 1`) — the same switch that already drives
        // the web/mobile assign lists, the shift planner and the Bikes roster. Without the second
        // arm, ticking a non-rider-role account (e.g. a Management user who also delivers) put
        // them in every assign list but left them unmanageable here, with no way to set their
        // phone / vehicle / home pin / meter rule. Role-only riders are kept so nobody who is
        // listed today can vanish — this widens the page, it never narrows it.
        // LEFT joins + distinct: a multi-role account must appear once, and an account with no
        // role row at all still shows if it is ticked.
        $riders = DB::table('t_sys_user as u')
            ->leftJoin('t_sys_user_role as ur', 'ur.user_id', '=', 'u.id')
            ->leftJoin('t_sys_role as r', 'r.id', '=', 'ur.role_id')
            ->leftJoin('t_ops_rider_profile as p', 'p.user_id', '=', 'u.id')
            ->where(function ($q) {
                $q->where('r.type', 'rider')->orWhere('p.active', 1);
            })
            ->where('u.is_active', 1)
            ->distinct()
            ->select($cols)
            ->get();

        // Enrich each rider with their CURRENT resolved shift + location (from the
        // shift system — the source of truth), so the page shows real assignments
        // instead of the stale legacy rider_profile.shift_start/end columns.
        $shiftSvc = new \App\Services\ShiftResolutionService();
        $today = now()->format('Y-m-d');
        foreach ($riders as $rd) {
            try {
                $s = $shiftSvc->getUserShift((int) $rd->user_id, $today);
                $rd->cur_shift_name = $s['shift_name'] ?? null;
                $rd->cur_shift_start = $s['shift_start'] ?? null;
                $rd->cur_shift_end = $s['shift_end'] ?? null; // null = start-only shift
                $rd->cur_location = $s['location_name'] ?? null;
            } catch (\Throwable $e) {
                $rd->cur_shift_name = null;
                $rd->cur_shift_start = null;
                $rd->cur_shift_end = null;
                $rd->cur_location = null;
            }
        }

        return view('pages.riders.index', compact('riders'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'user_id' => 'required|exists:t_sys_user,id',
            'phone' => 'nullable|string|max:50',
            'emergency_contact' => 'nullable|string|max:100',
            'vehicle_type' => 'nullable|string|max:50',
            'vehicle_plate' => 'nullable|string|max:50',
            'hire_date' => 'nullable|date',
            'company_bike' => 'nullable|boolean',
            'overnight_grace_km' => 'nullable|numeric|min:0|max:1000',
            'home_maps_url' => 'nullable|string|max:500',
            'home_latitude' => 'nullable|numeric|between:-90,90',
            'home_longitude' => 'nullable|numeric|between:-180,180',
            'home_radius_m' => 'nullable|integer|min:30|max:2000',
            'checkin_any_office' => 'nullable|boolean',
            'meter_required' => 'nullable|boolean',
            'active' => 'boolean'
        ]);

        // Home-pin outcome, reported back to the manager. $pinWarning = a link we could not read
        // (the pin was left alone); $pinMoved = the coordinates actually written this save.
        $pinWarning = null;
        $pinMoved = null;

        try {
            $data = [
                'phone' => $request->phone,
                'emergency_contact' => $request->emergency_contact,
                'vehicle_type' => $request->vehicle_type,
                'vehicle_plate' => $request->vehicle_plate,
                // A blank hire_date must become NULL — an empty string '' is rejected by a
                // strict-mode MySQL DATE column (this blocked saving any no-hire-date rider).
                'hire_date' => $request->filled('hire_date') ? $request->hire_date : null,
                'active' => $request->active ?? 1,
                'notes' => $request->notes,
                'updated_at' => now()
            ];
            // Only write the column once the Phase-A ALTER has been applied.
            $isBike = $request->boolean('company_bike');
            if ($this->companyBikeColumnExists()) {
                $data['company_bike'] = $isBike ? 1 : 0;
            }
            // Per-rider overnight grace (Phase-B). Only meaningful for a company bike;
            // clear it when the bike is unticked so a stale override can't linger.
            if ($this->overnightGraceColumnExists()) {
                $grace = $request->input('overnight_grace_km');
                $data['overnight_grace_km'] = ($isBike && $grace !== null && $grace !== '')
                    ? (int) round((float) $grace)
                    : null;
            }
            // 🏠 HOME pin — deliberately NOT part of $data. It is written AFTER the profile row,
            // by RiderHomePinService: the ONE writer, shared with the Bikes tab and the phone.
            //   • The pin no longer depends on `company_bike` (owner ruling, 10-Sep-2026). Riders
            //     swap between a company machine and their own constantly, and the old
            //     `$hasPin = $isBike && …` SILENTLY WIPED the home location when the tick came
            //     off — losing the very thing the overnight meter checks measure against.
            //   • A blank coordinate box now means "leave it alone", never "delete it".
            //   • Removal is its own explicit, confirmed action — see destroyHomePin().
            // R1 — per-rider "may check in at any office" allowance (guarded).
            if (Schema::hasColumn('t_ops_rider_profile', 'checkin_any_office')) {
                $data['checkin_any_office'] = $request->boolean('checkin_any_office') ? 1 : 0;
            }
            // Meter reading compulsory? Default required; unticked = exempt (management users).
            if (Schema::hasColumn('t_ops_rider_profile', 'meter_required')) {
                $data['meter_required'] = $request->boolean('meter_required') ? 1 : 0;
            }
            DB::table('t_ops_rider_profile')->updateOrInsert(
                ['user_id' => $request->user_id],
                $data
            );

            // 🏠 Now the pin, through the shared engine. The profile row is written FIRST so the
            //    rider always exists in the table before the pin lands on it.
            //
            // ⚠ ORDER OF THE THREE OUTCOMES MATTERS:
            //    nothing entered  → leave the stored pin untouched, say nothing;
            //    entered + read   → save, and echo back the coordinates actually stored;
            //    entered + unread → save NOTHING about the pin, warn, and keep the rest of the
            //                       profile (a bad paste must never cost a good pin, and it used
            //                       to throw away the whole form as well).
            $pinSvc  = new \App\Services\Riders\RiderHomePinService();
            $mapsUrl = trim((string) $request->input('home_maps_url', ''));
            $typedLat = $request->input('home_latitude');
            $typedLng = $request->input('home_longitude');
            // "Entered" means ANY of the three boxes has something in it. A half-typed pair
            // (latitude only) must reach the engine so it can refuse with a reason, rather
            // than being treated as an untouched form and silently ignored.
            $anythingEntered = $mapsUrl !== ''
                || ($typedLat !== null && $typedLat !== '')
                || ($typedLng !== null && $typedLng !== '');

            if ($pinSvc->pinColumnsExist() && $anythingEntered) {
                $radius = $request->input('home_radius_m');
                $res = $pinSvc->resolveAndSave(
                    (int) $request->user_id,
                    $mapsUrl,
                    $typedLat,
                    $typedLng,
                    ($radius !== null && $radius !== '') ? (int) $radius : null,
                    (int) auth()->id(),
                    'web-riders'
                );
                if (!$res['ok']) {
                    $pinWarning = $res['error'] . ' Everything else was saved.';
                } elseif ($res['moved']) {
                    $pinMoved = ['lat' => $res['pin']['lat'], 'lng' => $res['pin']['lng']];
                }
            }

            // ⭐ Say what was STORED, not just "saved". A home pin is invisible once the modal
            //   closes, and the 31 Aug mix-up (an office link pasted as a rider's home) would
            //   have been obvious the moment the coordinates were shown back with a map link.
            $message = 'Rider profile updated successfully!';
            if ($pinMoved) {
                $message .= sprintf(' Home pin set to %.7f, %.7f', $pinMoved['lat'], $pinMoved['lng']);
            }

            return redirect()->route('riders.index')
                ->with('success', $message)
                ->with('pin_moved', $pinMoved)
                ->with('warning', $pinWarning);
        } catch (\Exception $e) {
            return redirect()->back()->with('error', 'Error updating rider profile: ' . $e->getMessage());
        }
    }

    public function show($userId)
    {
        $profile = DB::table('t_ops_rider_profile')->where('user_id', $userId)->first();

        // 🏠 The pin, resolved the SAME way every other surface resolves it — including WHO set
        //    it and a map link, so a manager can check where it actually is before he moves it.
        $svc = new \App\Services\Riders\RiderHomePinService();

        return response()->json([
            'success'  => true,
            'profile'  => $profile,
            'home_pin' => $svc->get((int) $userId),
            'home_history' => $svc->history((int) $userId, 5),
        ]);
    }

    /**
     * 🏠 REMOVE a rider's home pin — explicit and deliberate, never a side effect of an empty
     * form box or of unticking "company bike" (owner ruling, 10-Sep-2026).
     *
     * Gated on `assign_vehicles`, the same key that governs every other fleet write, so the
     * three surfaces (this page, the Bikes tab, the phone) all ask the same question.
     */
    public function destroyHomePin(Request $request, $userId)
    {
        $u = auth()->user();
        $allowed = $u
            && !(method_exists($u, 'isReadOnly') && $u->isReadOnly())
            && $u->hasPermission('assign_vehicles');

        if (!$allowed) {
            return redirect()->route('riders.index')
                ->with('error', 'You are not allowed to change rider home locations.');
        }

        $res = (new \App\Services\Riders\RiderHomePinService())
            ->clear((int) $userId, (int) auth()->id(), 'web-riders');

        if (!$res['ok']) {
            return redirect()->route('riders.index')->with('error', $res['error']);
        }

        return redirect()->route('riders.index')->with(
            'success',
            $res['cleared'] ? 'Home location removed.' : 'That rider had no home location to remove.'
        );
    }
    /**
     * ⚰ RETIRED 7-Sep-2026 — `updateShift()` and its route `POST /riders/shift` are gone.
     *
     * It was the LEGACY door: it wrote raw `t_ops_rider_profile.shift_start/shift_end`, the
     * pre-shift-system times, and it had no permission check of any kind — any logged-in
     * user, a rider included, could rewrite anyone's hours. Its only caller was a "Manage
     * Employee Shifts" modal on the attendance page that nothing ever opened.
     *
     * ⭐ ONE ENGINE (owner ruling 7-Sep). Every shift change now goes through
     *   `Ops\ShiftController` — which is where the ladder, the own-shift rule, the
     *   allowed-shift lists and the approval queue live. A second door meant a second set of
     *   rules to keep in step, and this one had none.
     *
     * ⚠ The two COLUMNS stay: they are still read as the resolution fallback for anyone who
     *   was never migrated (`AttendanceController`, `SysAdmin\UserController`). Nothing
     *   writes them any more, so they are frozen history — do not add a new writer.
     */
}
