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
            // HOME pin (U4 going-home journey; home-journey SQL). Paste a Google Maps link
            // (short share links are resolved) OR type coordinates. Only meaningful for a
            // company-bike rider; clearing both fields (or unticking the bike) clears the pin.
            if (Schema::hasColumn('t_ops_rider_profile', 'home_latitude')) {
                $prior = DB::table('t_ops_rider_profile')->where('user_id', $request->user_id)
                    ->first(['home_latitude', 'home_longitude']);
                $homeLat = $request->input('home_latitude');
                $homeLng = $request->input('home_longitude');
                $mapsUrl = trim((string) $request->input('home_maps_url', ''));
                $skipPin = false;               // leave the stored pin exactly as it is

                if ($mapsUrl !== '') {
                    $coords = null;
                    $resolved = $mapsUrl;
                    // Reuse the app-wide verified-pin parser (7 URL patterns + short links).
                    try {
                        $api = app(\App\Http\Controllers\API\RiderController::class);
                        $resolved = $api->resolveGoogleMapsUrl($mapsUrl);
                        $coords = $api->parseCoordinatesFromGoogleMapsUrl($resolved);
                    } catch (\Throwable $e) {
                        $coords = null;
                    }

                    // ⭐⭐ PLUS CODE FALLBACK (31 Aug 2026 incident). Google's share sheet hands
                    //    out PLACE-ID urls — `/maps/place/Nizami+Farms,+P35Q%2B5FF,…/data=!4m2!3m1!1s0x…`
                    //    — which carry no `@lat,lng` and no `!3d`/`!4d`, so every pattern above
                    //    returns null. Five saves in a row were refused this way. The coordinates
                    //    are still in the URL, as the Plus Code, and that decodes offline.
                    //    Reference = the company office; see PlusCode's ±55 km caveat.
                    if (!$coords) {
                        try {
                            $ref = $this->pinReference();
                            $pc = new PlusCode();
                            $hit = $pc->fromText($resolved, $ref['lat'], $ref['lng'])
                                ?? $pc->fromText($mapsUrl, $ref['lat'], $ref['lng']);
                            if ($hit) {
                                $coords = ['latitude' => $hit['latitude'], 'longitude' => $hit['longitude']];
                                \Log::info('Home pin read from a Plus Code', [
                                    'user_id' => $request->user_id, 'plus_code' => $hit['plus_code'],
                                    'lat' => $hit['latitude'], 'lng' => $hit['longitude'],
                                ]);
                            }
                        } catch (\Throwable $e) {
                            \Log::warning('Plus Code fallback failed (non-fatal)', ['error' => $e->getMessage()]);
                        }
                    }

                    if ($coords) {
                        $homeLat = $coords['latitude'];
                        $homeLng = $coords['longitude'];
                    } else {
                        // ⚠⚠ TWO THINGS THAT USED TO GO WRONG HERE, BOTH FIXED.
                        //  1. This returned `redirect()->back()->with('error', …)`, which threw
                        //     away the WHOLE profile save — phone, vehicle, meter rule, everything
                        //     the manager had typed — over one unreadable link.
                        //  2. Falling through to the typed boxes would re-save the PREFILLED
                        //     (existing) coordinates and stamp a fresh home_set_at, so the page
                        //     then reported "✓ Home pin saved (today)" for a pin that never moved.
                        //     That is precisely how the 31 Aug attempts looked like they worked.
                        // So: save everything else, touch nothing about the pin, and say so.
                        $skipPin = true;
                        $pinWarning = 'That Maps link has no coordinates in it, so the home pin was NOT changed. '
                            . 'Everything else was saved. Open the pin in Google Maps, tap Share → Copy link '
                            . '(or long-press the exact spot to drop a pin first), or type the coordinates.';
                    }
                }

                if (!$skipPin) {
                    $hasPin = $isBike && $homeLat !== null && $homeLat !== '' && $homeLng !== null && $homeLng !== '';
                    $data['home_latitude'] = $hasPin ? (float) $homeLat : null;
                    $data['home_longitude'] = $hasPin ? (float) $homeLng : null;
                    $radius = $request->input('home_radius_m');
                    $data['home_radius_m'] = ($hasPin && $radius !== null && $radius !== '') ? (int) $radius : null;

                    // ⭐ Only stamp WHO/WHEN when the pin actually MOVED. Re-saving the profile
                    //   for an unrelated reason used to refresh home_set_at, which made the
                    //   modal announce "✓ Home pin saved (today)" for an untouched location —
                    //   the manager's only feedback, and it was lying to him.
                    $moved = $hasPin && (
                        $prior === null
                        || $prior->home_latitude === null
                        || abs((float) $prior->home_latitude - (float) $homeLat) > 0.0000005
                        || abs((float) $prior->home_longitude - (float) $homeLng) > 0.0000005
                    );
                    if ($moved) {
                        $data['home_set_by'] = auth()->id();
                        $data['home_set_at'] = now();
                        $pinMoved = ['lat' => (float) $homeLat, 'lng' => (float) $homeLng];
                    }
                }
            }
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
        return response()->json(['success' => true, 'profile' => $profile]);
    }

    public function updateShift(Request $request)
    {
        // Convert 12-hour format to 24-hour if needed
        $shiftStart = $request->shift_start;
        $shiftEnd = $request->shift_end;
        
        // Check if it's in 12-hour format and convert
        if (preg_match('/(\d{1,2}):(\d{2})\s*(AM|PM)/i', $shiftStart, $matches)) {
            $hour = (int)$matches[1];
            $minute = $matches[2];
            $period = strtoupper($matches[3]);
            
            if ($period === 'PM' && $hour !== 12) {
                $hour += 12;
            } elseif ($period === 'AM' && $hour === 12) {
                $hour = 0;
            }
            $shiftStart = sprintf('%02d:%s', $hour, $minute);
        }
        
        if (preg_match('/(\d{1,2}):(\d{2})\s*(AM|PM)/i', $shiftEnd, $matches)) {
            $hour = (int)$matches[1];
            $minute = $matches[2];
            $period = strtoupper($matches[3]);
            
            if ($period === 'PM' && $hour !== 12) {
                $hour += 12;
            } elseif ($period === 'AM' && $hour === 12) {
                $hour = 0;
            }
            $shiftEnd = sprintf('%02d:%s', $hour, $minute);
        }
        
        $request->validate([
            'user_id' => 'required|exists:t_sys_user,id'
        ]);

        try {
            // Check if profile exists
            $exists = DB::table('t_ops_rider_profile')->where('user_id', $request->user_id)->exists();

            if ($exists) {
                // Update existing profile
                DB::table('t_ops_rider_profile')
                    ->where('user_id', $request->user_id)
                    ->update([
                        'shift_start' => $shiftStart,
                        'shift_end' => $shiftEnd,
                        'updated_at' => now()
                    ]);
            } else {
                // Create new profile with shift times
                DB::table('t_ops_rider_profile')->insert([
                    'user_id' => $request->user_id,
                    'shift_start' => $shiftStart,
                    'shift_end' => $shiftEnd,
                    'active' => 1,
                    'created_at' => now(),
                    'updated_at' => now()
                ]);
            }

            return response()->json(['success' => true, 'message' => 'Shift times updated successfully']);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Error: ' . $e->getMessage()], 500);
        }
    }
}
