<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Services\Riders\VehicleService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * The Vehicles view of the Bikes tab (Aug-2026) — registering bikes and the van,
 * and giving them to riders.
 *
 * ACCESS, in two layers, deliberately separate:
 *   READING  — the same three keys that already open Bikes (FleetFuelController::
 *              PERMISSIONS). Anyone who can see running costs can see the fleet
 *              those costs belong to; no new access is created.
 *   WRITING  — `assign_vehicles`, a NEW key (batch 13). Reusing manage_bike_service
 *              would have handed assignment to everyone holding it (Qasim, Adnan,
 *              expense-fund); the owner asked for Shabib + Taimur, with Farooq
 *              addable later by ticking his role.
 *
 * ⚠ THE WRITE GATE IS THE PERMISSION ALONE — never a role-type check. Farooq's
 *   role is type='rider', so any admin|manager|supervisor gate would silently
 *   refuse him even with the box ticked. That exact bug blocked Qasim from filing
 *   bike claims until Jul-29.
 */
class VehicleController extends Controller
{
    /** Same read gate as the Bikes tab — keep in sync with FleetFuelController. */
    const VIEW_PERMISSIONS = ['view_rider_reports', 'web_menu_finance_hub', 'view_bike_costs'];

    /** Photos are the deliverable here, so allow a decent phone photo. */
    const MAX_PHOTO_KB = 8192;

    // =================================================================
    // READ
    // =================================================================

    /** The fleet, plus the roster the assign modal picks from. */
    public function index(Request $request, VehicleService $svc)
    {
        if (!$this->canView()) {
            return response()->json(['success' => false, 'message' => 'Not authorised'], 403);
        }
        try {
            // Not an error — the screen renders a "run batch 13" note instead. An
            // upload landing before the SQL must degrade, never explode.
            if (!$svc->available()) {
                return response()->json([
                    'success'   => true,
                    'available' => false,
                    'vehicles'  => [], 'riders' => [],
                    'can_manage' => false,
                    'message'   => 'Vehicles are not set up yet (SQL batch 13 has not been run).',
                ]);
            }

            return response()->json([
                'success'    => true,
                'available'  => true,
                'vehicles'   => $svc->all($request->boolean('include_retired')),
                'riders'     => $this->roster(),
                'can_manage' => $this->canManage(),
                'transfer_grace_km' => $svc->transferGraceKm(),
                'rules_enabled'     => $svc->rulesEnabled(),
            ]);
        } catch (\Throwable $e) {
            Log::error('VehicleController index failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Failed to load the fleet'], 500);
        }
    }

    /** One vehicle with its assignment history and condition photos. */
    public function show(Request $request, VehicleService $svc, $id)
    {
        if (!$this->canView()) {
            return response()->json(['success' => false, 'message' => 'Not authorised'], 403);
        }
        try {
            $v = $svc->find((int) $id);
            if (!$v) return response()->json(['success' => false, 'message' => 'That vehicle no longer exists.'], 404);

            // ⭐ THE MACHINE'S spend — the SAME `claimsForVehicle` the rider's own
            //    profile reads, so a manager and a rider can never be shown two
            //    different reconstructions of one bike's history. Month-scoped;
            //    `?month=YYYY-MM` for the picker, defaults to this month.
            $month = $request->query('month');
            try {
                $m = $month ? \Carbon\Carbon::parse($month . '-01') : \Carbon\Carbon::today();
            } catch (\Throwable $e) {
                $m = \Carbon\Carbon::today();
            }
            $claims = $svc->claimsForVehicle((int) $id,
                $m->copy()->startOfMonth()->format('Y-m-d'),
                $m->copy()->endOfMonth()->format('Y-m-d'));

            $fuel = 0.0; $maint = 0.0;
            foreach ($claims as $c) {
                if (($c['category'] ?? '') === 'Petrol') $fuel += (float) $c['amount'];
                else $maint += (float) $c['amount'];
            }

            return response()->json([
                'success'    => true,
                'vehicle'    => $v,
                'riders'     => $this->roster(),
                'can_manage' => $this->canManage(),
                'transfer_grace_km' => $svc->transferGraceKm(),
                'month'      => $m->format('Y-m'),
                'claims'     => $claims,
                // Totalled from the rows above, never a second calculation.
                'claims_total' => ['fuel_rs' => round($fuel, 2), 'maint_rs' => round($maint, 2),
                                   'count' => count($claims)],
                // The bike's Rs/km: selected month vs previous 3 pooled — the
                // SAME method the rider's screen reads.
                'averages'   => $svc->fuelAverages((int) $id, $m->format('Y-m')),
                // The current keeper's stint — the rider-vs-machine diagnostic.
                'keeper_stint' => $svc->keeperStintStats((int) $id),
            ]);
        } catch (\Throwable $e) {
            Log::error('VehicleController show failed', ['id' => $id, 'error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Failed to load that vehicle'], 500);
        }
    }

    /**
     * What assigning this vehicle to this rider would do — read-only, so the modal
     * can state the consequences before the manager commits. Deliberately its own
     * endpoint: the sentences are computed from live data (who holds what, whose
     * home pin is missing), not guessed in JavaScript.
     */
    public function previewAssign(Request $request, VehicleService $svc, $id)
    {
        if (!$this->canManage()) {
            return response()->json(['success' => false, 'message' => 'Not authorised to assign vehicles'], 403);
        }
        $userId = (int) $request->query('user_id');
        if ($userId <= 0) {
            return response()->json(['success' => false, 'message' => 'user_id required'], 422);
        }
        $p = $svc->previewAssign((int) $id, $userId, $request->query('date'));
        return response()->json(['success' => true] + $p);
    }

    // =================================================================
    // WRITE
    // =================================================================

    public function save(Request $request, VehicleService $svc, $id = null)
    {
        if (!$this->canManage()) {
            return response()->json(['success' => false, 'message' => 'Not authorised to manage vehicles'], 403);
        }
        $data = $request->validate([
            'vtype'      => 'nullable|in:bike,van',
            'reg_no'     => 'nullable|string|max:32',
            'nickname'   => 'nullable|string|max:64',
            'is_company' => 'nullable|boolean',
            'make_model' => 'nullable|string|max:64',
            'notes'      => 'nullable|string|max:255',
            'is_active'  => 'nullable|boolean',
            'service_interval_km' => 'nullable|integer|min:0|max:200000',
        ]);

        $res = $svc->saveVehicle($data, $id ? (int) $id : null, (int) auth()->id());
        if (!$res['ok']) {
            return response()->json(['success' => false, 'message' => $res['message']], 422);
        }
        return response()->json([
            'success' => true,
            'message' => $id ? 'Vehicle updated.' : 'Vehicle added.',
            'vehicle' => $svc->find((int) $res['id']),
        ]);
    }

    /**
     * Give the vehicle to a rider. Condition photos may ride along in the same
     * request (the manager is standing next to the bike with his phone) — but a
     * photo failure must NEVER undo the assignment, so they are stored
     * best-effort after the fact and reported separately.
     */
    public function assign(Request $request, VehicleService $svc, $id)
    {
        if (!$this->canManage()) {
            return response()->json(['success' => false, 'message' => 'Not authorised to assign vehicles'], 403);
        }
        $data = $request->validate([
            'user_id' => 'required|integer',
            'date'    => 'nullable|date',
            'note'    => 'nullable|string|max:255',
            'photos'   => 'nullable|array|max:8',
            'photos.*' => 'image|max:' . self::MAX_PHOTO_KB,
        ]);

        $res = $svc->assign((int) $id, (int) $data['user_id'], $data['date'] ?? null,
                            (int) auth()->id(), $data['note'] ?? null);
        if (!$res['ok']) {
            return response()->json(['success' => false, 'message' => $res['message']], 422);
        }

        $photoNote = $this->storePhotos($request, $svc, (int) $id, 'handover_in',
                                        $data['date'] ?? null, $res['id'] ?? null);

        return response()->json([
            'success'      => true,
            'message'      => trim($res['message'] . ' ' . $photoNote),
            'changed'      => $res['changed'] ?? true,
            'vehicle'      => $svc->find((int) $id),
        ]);
    }

    public function release(Request $request, VehicleService $svc, $id)
    {
        if (!$this->canManage()) {
            return response()->json(['success' => false, 'message' => 'Not authorised to assign vehicles'], 403);
        }
        $request->validate([
            'date'     => 'nullable|date',
            'photos'   => 'nullable|array|max:8',
            'photos.*' => 'image|max:' . self::MAX_PHOTO_KB,
        ]);

        // The photos are of the state it came BACK in, so they belong to the
        // assignment being closed — grab it before it is released.
        $closing = $svc->keeperOf((int) $id);

        $res = $svc->release((int) $id, $request->input('date'), (int) auth()->id());
        if (!$res['ok']) {
            return response()->json(['success' => false, 'message' => $res['message']], 422);
        }

        $photoNote = $this->storePhotos($request, $svc, (int) $id, 'handover_out',
                                        $request->input('date'), $closing->id ?? null);

        return response()->json([
            'success' => true,
            'message' => trim($res['message'] . ' ' . $photoNote),
            'changed' => $res['changed'] ?? true,
            'vehicle' => $svc->find((int) $id),
        ]);
    }

    /** Set or clear the vehicle's fixed overnight base (the van's parking). */
    public function setBase(Request $request, VehicleService $svc, $id)
    {
        if (!$this->canManage()) {
            return response()->json(['success' => false, 'message' => 'Not authorised to manage vehicles'], 403);
        }
        $data = $request->validate([
            'latitude'  => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'radius_m'  => 'nullable|integer|min:50|max:5000',
            'clear'     => 'nullable|boolean',
        ]);

        $clear = !empty($data['clear']);
        $res = $svc->setBase(
            (int) $id,
            $clear ? null : (isset($data['latitude'])  ? (float) $data['latitude']  : null),
            $clear ? null : (isset($data['longitude']) ? (float) $data['longitude'] : null),
            isset($data['radius_m']) ? (int) $data['radius_m'] : null,
            (int) auth()->id()
        );
        if (!$res['ok']) {
            return response()->json(['success' => false, 'message' => $res['message']], 422);
        }
        return response()->json([
            'success' => true, 'message' => $res['message'], 'vehicle' => $svc->find((int) $id),
        ]);
    }

    /** Add condition photos on their own (not tied to a handover). */
    public function addPhotos(Request $request, VehicleService $svc, $id)
    {
        if (!$this->canManage()) {
            return response()->json(['success' => false, 'message' => 'Not authorised to manage vehicles'], 403);
        }
        $request->validate([
            'photos'   => 'required|array|max:8',
            'photos.*' => 'image|max:' . self::MAX_PHOTO_KB,
            'context'  => 'nullable|string|max:16',
            'note'     => 'nullable|string|max:255',
            'date'     => 'nullable|date',
        ]);

        $ctx  = $request->input('context', 'condition');
        $note = $this->storePhotos($request, $svc, (int) $id, $ctx, $request->input('date'), null,
                                   $request->input('note'));

        return response()->json([
            'success' => true,
            'message' => $note ?: 'Nothing was uploaded.',
            'vehicle' => $svc->find((int) $id),
        ]);
    }

    /**
     * "He was on a different bike that day."
     *
     * The ONE place `t_ops_attendance.vehicle_id` is ever written. Everything else
     * about which machine a day was on is derived from the assignment timeline
     * (VehicleResolver) — this exists for the exception the timeline cannot know:
     * a bike borrowed for an afternoon, a breakdown, a swap nobody recorded at the
     * time. Sending `vehicle_id` empty clears the override and hands the day back
     * to the timeline.
     *
     * Manager-only by ruling (`assign_vehicles`) — riders do not declare their own.
     */
    public function dayOverride(Request $request, \App\Services\Riders\VehicleResolver $res)
    {
        if (!$this->canManage()) {
            return response()->json(['success' => false, 'message' => 'Not authorised to assign vehicles'], 403);
        }
        $data = $request->validate([
            'user_id'    => 'required|integer',
            'date'       => 'required|date',
            'vehicle_id' => 'nullable|integer',
        ]);

        try {
            if (!\Illuminate\Support\Facades\Schema::hasColumn('t_ops_attendance', 'vehicle_id')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Vehicles are not set up on this server yet (SQL batch 13).',
                ], 422);
            }

            $date = \Carbon\Carbon::parse($data['date'])->format('Y-m-d');
            $uid  = (int) $data['user_id'];
            $vid  = !empty($data['vehicle_id']) ? (int) $data['vehicle_id'] : null;

            // Only a day the rider actually has an attendance row for — there is
            // nowhere else to hang the override, and inventing a row would create a
            // phantom working day out of a bookkeeping correction.
            $row = DB::table('t_ops_attendance')
                ->where('user_id', $uid)->whereDate('attendance_date', $date)
                ->first(['id']);
            if (!$row) {
                return response()->json([
                    'success' => false,
                    'message' => 'There is no attendance record for that rider on ' . $date . ', so there is no day to correct.',
                ], 422);
            }

            if ($vid) {
                $exists = DB::table(VehicleService::T_VEHICLE)->where('id', $vid)->exists();
                if (!$exists) {
                    return response()->json(['success' => false, 'message' => 'That vehicle no longer exists.'], 422);
                }
            }

            DB::table('t_ops_attendance')->where('id', $row->id)->update([
                'vehicle_id'     => $vid,
                'vehicle_source' => $vid ? 'manager' : null,
            ]);
            \App\Services\Riders\VehicleResolver::flush();

            $label = $vid ? $res->labelFor($vid) : null;
            Log::info('Vehicle day override set', [
                'user_id' => $uid, 'date' => $date, 'vehicle_id' => $vid, 'by' => auth()->id(),
            ]);

            return response()->json([
                'success' => true,
                'message' => $vid
                    ? 'Recorded: he was on ' . $label . ' on ' . $date . '.'
                    : 'Cleared — that day follows the normal assignment again.',
                'vehicle_id' => $vid,
                'label'      => $label,
            ]);
        } catch (\Throwable $e) {
            Log::error('dayOverride failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Could not save that change.'], 500);
        }
    }

    /** What a rider was on for each day of a month — powers the drill-down chips. */
    public function riderDays(Request $request, \App\Services\Riders\VehicleResolver $res)
    {
        if (!$this->canView()) {
            return response()->json(['success' => false, 'message' => 'Not authorised'], 403);
        }
        $uid   = (int) $request->query('rider_id');
        $month = $request->query('month') ?: date('Y-m');
        if ($uid <= 0) {
            return response()->json(['success' => false, 'message' => 'rider_id required'], 422);
        }

        try {
            $from = \Carbon\Carbon::parse($month . '-01')->startOfMonth()->format('Y-m-d');
            $to   = \Carbon\Carbon::parse($month . '-01')->endOfMonth()->format('Y-m-d');

            $rows = DB::table('t_ops_attendance')
                ->where('user_id', $uid)
                ->whereBetween('attendance_date', [$from, $to])
                ->orderBy('attendance_date')
                ->get(['attendance_date', 'vehicle_id', 'vehicle_source']);

            // ⚠ Resolved from ONE read of this rider's assignment history rather than
            //   per day. Calling vehicleForDay() in the loop re-queried the override
            //   table for all ~31 days — and the override is already in the row we
            //   just fetched. Same for the transfer check, which only needs the set of
            //   dates on which any of his assignments started or ended.
            $windows = DB::table(VehicleService::T_ASSIGN)
                ->where('user_id', $uid)
                ->orderBy('assigned_on')->orderBy('id')
                ->get(['vehicle_id', 'assigned_on', 'released_on']);

            $default = DB::table('t_ops_rider_profile')->where('user_id', $uid)->value('default_vehicle_id');

            $out = [];
            foreach ($rows as $r) {
                $d = substr((string) $r->attendance_date, 0, 10);

                if ($r->vehicle_id) {
                    $vid = (int) $r->vehicle_id;
                } else {
                    // Open row first, then the latest start — the same precedence
                    // VehicleResolver applies on a transfer day.
                    $vid = null; $bestOpen = false; $bestFrom = null;
                    foreach ($windows as $w) {
                        $f = substr((string) $w->assigned_on, 0, 10);
                        $t = $w->released_on ? substr((string) $w->released_on, 0, 10) : null;
                        if ($f > $d || ($t !== null && $t < $d)) continue;
                        $isOpen = $t === null;
                        if ($vid === null || ($isOpen && !$bestOpen) || (($isOpen === $bestOpen) && $f >= $bestFrom)) {
                            $vid = (int) $w->vehicle_id; $bestOpen = $isOpen; $bestFrom = $f;
                        }
                    }
                    if ($vid === null && $default) $vid = (int) $default;
                }

                $out[] = [
                    'date'       => $d,
                    'vehicle_id' => $vid,
                    'label'      => $res->labelFor($vid),   // cached per vehicle
                    'overridden' => $r->vehicle_id !== null,
                    'transfer'   => false,                  // filled in below
                ];
            }

            // Transfer days, keyed on the VEHICLE across ALL its keepers — matching
            // VehicleResolver::isTransferDay exactly. Deriving it from this rider's
            // own windows would disagree whenever a day was overridden onto a machine
            // he was never formally assigned. One query for every vehicle on show.
            $vids = array_values(array_unique(array_filter(array_column($out, 'vehicle_id'))));
            if ($vids) {
                $marks = [];
                foreach (DB::table(VehicleService::T_ASSIGN)->whereIn('vehicle_id', $vids)
                            ->get(['vehicle_id', 'assigned_on', 'released_on']) as $a) {
                    $marks[$a->vehicle_id . '|' . substr((string) $a->assigned_on, 0, 10)] = true;
                    if ($a->released_on) {
                        $marks[$a->vehicle_id . '|' . substr((string) $a->released_on, 0, 10)] = true;
                    }
                }
                foreach ($out as &$row) {
                    if ($row['vehicle_id']) {
                        $row['transfer'] = isset($marks[$row['vehicle_id'] . '|' . $row['date']]);
                    }
                }
                unset($row);
            }

            return response()->json(['success' => true, 'days' => $out, 'can_manage' => $this->canManage()]);
        } catch (\Throwable $e) {
            Log::error('riderDays failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Could not load those days'], 500);
        }
    }

    public function deletePhoto(Request $request, VehicleService $svc, $photoId)
    {
        if (!$this->canManage()) {
            return response()->json(['success' => false, 'message' => 'Not authorised to manage vehicles'], 403);
        }
        $res = $svc->deletePhoto((int) $photoId);
        if (!$res['ok']) {
            return response()->json(['success' => false, 'message' => $res['message']], 422);
        }
        return response()->json(['success' => true, 'message' => $res['message']]);
    }

    // =================================================================
    // helpers
    // =================================================================

    /**
     * Store uploaded photos, best-effort. Returns a sentence for the caller to
     * append to its own message. NEVER throws — an assignment that succeeded must
     * not be reported as failed because a JPEG did not save.
     */
    private function storePhotos(Request $request, VehicleService $svc, int $vehicleId,
                                 string $context, ?string $date, ?int $assignmentId,
                                 ?string $note = null): string
    {
        if (!$request->hasFile('photos')) return '';

        $saved = 0; $failed = 0;
        foreach ((array) $request->file('photos') as $file) {
            try {
                if (!$file || !$file->isValid()) { $failed++; continue; }
                $path = $this->storeOne($file, $vehicleId, $context);
                $res  = $svc->addPhoto($vehicleId, $path, $date, $context, $note,
                                       (int) auth()->id(), $assignmentId);
                $res['ok'] ? $saved++ : $failed++;
            } catch (\Throwable $e) {
                $failed++;
                Log::warning('Vehicle photo upload failed', [
                    'vehicle_id' => $vehicleId, 'error' => $e->getMessage(),
                ]);
            }
        }

        if ($saved && !$failed) return $saved . ' photo' . ($saved === 1 ? '' : 's') . ' saved.';
        if ($saved && $failed)  return $saved . ' saved, ' . $failed . ' could not be uploaded.';
        if ($failed)            return 'The photo could not be uploaded — the rest was saved.';
        return '';
    }

    /**
     * Same folder convention as the attendance meter pictures.
     *
     * ⚠ Streamed via putFileAs, NOT file_get_contents. This endpoint accepts up to
     *   8 photos of up to 8 MB each; reading them all into strings would put ~64 MB
     *   through PHP's memory limit on one request. The single-photo meter path gets
     *   away with it — a batch upload would not.
     *
     * The extension follows the actual file. The meter path hardcodes .jpg, which
     * works only because browsers sniff content; a PNG saved as .jpg is a small lie
     * that costs nothing to avoid here.
     */
    private function storeOne($file, int $vehicleId, string $context): string
    {
        $now  = now();
        $safe = preg_replace('/[^a-z_]/', '', strtolower($context)) ?: 'condition';
        $ext  = strtolower($file->getClientOriginalExtension() ?: 'jpg');
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'heic'], true)) $ext = 'jpg';

        $dir  = 'vehicles/photos/' . $now->format('Y') . '/' . $now->format('m');
        $name = "vehicle_{$vehicleId}_{$now->format('Ymd_His')}_" . uniqid() . "_{$safe}.{$ext}";

        Storage::disk('public')->putFileAs($dir, $file, $name);
        return $dir . '/' . $name;
    }

    /**
     * The rider list the assign modal picks from — the Bikes roster (the "Delivery
     * Rider" tick on the People list), never the company-wide user list, whose
     * names render cut off and whose entries are mostly not riders at all.
     */
    private function roster(): array
    {
        try {
            return DB::table('t_ops_rider_profile as p')
                ->join('t_sys_user as u', 'u.id', '=', 'p.user_id')
                ->where('p.active', 1)
                ->orderBy('u.fullname')
                ->get(['p.user_id', 'u.fullname', 'p.company_bike', 'p.default_vehicle_id',
                       'p.home_latitude'])
                ->map(fn ($r) => [
                    'user_id'      => (int) $r->user_id,
                    'name'         => $r->fullname,
                    'company_bike' => (int) $r->company_bike === 1,
                    'has_vehicle'  => $r->default_vehicle_id !== null,
                    'has_home_pin' => $r->home_latitude !== null,
                ])->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function canView(): bool
    {
        $u = auth()->user();
        if (!$u) return false;
        foreach (self::VIEW_PERMISSIONS as $p) {
            if ($u->hasPermission($p)) return true;
        }
        return false;
    }

    /**
     * May this user change the fleet? `assign_vehicles` and nothing else.
     * View-only accounts are refused regardless of the grant (ReadOnlyGuard).
     */
    private function canManage(): bool
    {
        $u = auth()->user();
        if (!$u) return false;
        if (method_exists($u, 'isReadOnly') && $u->isReadOnly()) return false;
        return (bool) $u->hasPermission('assign_vehicles');
    }
}
