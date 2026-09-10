<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Services\Riders\WorkshopVisitService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * 🔧 WORKSHOP VISITS — one door for the desk and the phone.
 *
 * ⭐ Every rule is in WorkshopVisitService; the `api*` wrappers only flip
 *   `$mobileContext` so the mobile permission table is consulted instead of the web one.
 *   Same shape as VehicleTicketController and FleetFuelController::apiMarkServiced.
 *
 * ⚠ NO permission middleware on the routes, deliberately: the ACCEPT and PENDING
 *   endpoints must be reachable by a rider who holds no key at all — he is the person
 *   the instruction is for. Scheduling and completing check `schedule_workshop` inside
 *   the service, and `pending` is self-scoped to the caller so it can never leak.
 */
class WorkshopVisitController extends Controller
{
    private bool $mobileContext = false;

    public function __construct(private WorkshopVisitService $visits)
    {
    }

    // ── mobile entries ───────────────────────────────────────────────────────────
    public function apiIndex(Request $r)         { $this->mobileContext = true; return $this->index($r); }
    public function apiStore(Request $r)         { $this->mobileContext = true; return $this->store($r); }
    public function apiAccept(Request $r, $id)   { $this->mobileContext = true; return $this->accept($r, $id); }
    public function apiApprove(Request $r, $id)  { $this->mobileContext = true; return $this->approve($r, $id); }
    public function apiDecline(Request $r, $id)  { $this->mobileContext = true; return $this->decline($r, $id); }
    public function apiApprovals(Request $r)     { $this->mobileContext = true; return $this->approvals($r); }
    public function apiAddWorkshopLocation(Request $r) { $this->mobileContext = true; return $this->addWorkshopLocation($r); }
    public function apiCancel(Request $r, $id)   { $this->mobileContext = true; return $this->cancel($r, $id); }
    public function apiDone(Request $r, $id)     { $this->mobileContext = true; return $this->done($r, $id); }
    public function apiPending(Request $r)       { $this->mobileContext = true; return $this->pending($r); }
    public function apiAlerts(Request $r)        { $this->mobileContext = true; return $this->alerts($r); }
    public function apiWarnings(Request $r)      { $this->mobileContext = true; return $this->warnings($r); }
    public function apiOutcome(Request $r)       { $this->mobileContext = true; return $this->outcome($r); }

    // ─────────────────────────────────────────────────────────────────────────────

    public function index(Request $request)
    {
        $data = $request->validate([
            'user_id'      => 'nullable|integer',
            'vehicle_id'   => 'nullable|integer',
            'from'         => 'nullable|date_format:Y-m-d',
            'to'           => 'nullable|date_format:Y-m-d',
            'include_done' => 'nullable|boolean',
        ]);
        $user      = $request->user() ?: auth()->user();
        $canManage = $this->visits->canSchedule($user, $this->mobileContext);
        $canApprove = $this->visits->canApprove($user, $this->mobileContext);

        // ⚠ Someone who cannot schedule sees only his OWN visits. Without this a rider
        //   hitting the list endpoint would read the whole fleet's movements.
        // ⚠ A PLANNER is not a rider either (6-Sep review): Farooq holds no booking key, and
        //   forcing his list to his own id turned every vehicle-scoped read empty for him.
        if (!$canManage && !$canApprove) {
            $data['user_id'] = (int) $user->id;
        }
        /**
         * ⚠⚠ ONLY A MANAGER SEES PROPOSALS, and only because he asked. A rider must never
         *    receive a proposed day from any endpoint — that is the ruling, and this is the
         *    one list endpoint he can reach. Planners get them too (a planner who is not a
         *    booker still needs to see what is waiting on the machine's page).
         */
        if ($canManage || $canApprove) {
            $data['include_proposed'] = true;
        }

        return response()->json([
            'success'      => true,
            'available'    => $this->visits->available(),
            'can_schedule' => $canManage,
            // ⭐ The UI asks this to decide between "Assign now / Send for approval" and a
            //   plain Send-for-approval — the same question the server then re-decides.
            'can_approve'  => $canApprove,
            'approval_on'  => $this->visits->approvalEnabled(),
            'visits'       => $this->visits->listVisits($data),
            // 📍 Phase 4 — the registered workshops. Picking one makes it that day's shift
            //   location, so checking in there is on time by itself. Empty until a manager
            //   ticks a location as a workshop, and both schedulers then simply show no picker.
            'workshops'    => $this->visits->workshopLocations(),
            // The Adjust picker: which shift a planner may put the rider on for that one day.
            'shifts'       => $canApprove ? $this->visits->shiftTemplates() : [],
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────────
    //  APPROVAL (6-Sep ruling) — a booked workshop day is a request until a planner
    //  says yes. These three doors are the planners'.
    // ─────────────────────────────────────────────────────────────────────────────

    /** Everything waiting on this planner, with the warnings the booker was shown. */
    public function approvals(Request $request)
    {
        $user = $request->user() ?: auth()->user();
        return response()->json([
            'success'     => true,
            'can_approve' => $this->visits->canApprove($user, $this->mobileContext),
            'approval_on' => $this->visits->approvalEnabled(),
            'pending'     => $this->visits->pendingApprovals($user, $this->mobileContext),
            'workshops'   => $this->visits->workshopLocations(),
            'shifts'      => $this->visits->canApprove($user, $this->mobileContext)
                                ? $this->visits->shiftTemplates() : [],
        ]);
    }

    /**
     * 📍 ADD A WORKSHOP WITHOUT LEAVING THE BOOKING FORM (owner + team ruling, 6-Sep).
     *
     * ⭐ Lives here, next to the form that needs it, but writes through the ONE
     *   `CompanyLocationsService::createWorkshop()` the Locations admin page can also use —
     *   so "what makes a workshop usable" is answered in a single place.
     *
     * ⚠ Gate: either key. Qasim (`schedule_workshop`) is the man standing at the workshop
     *   with no location to pick; a planner (`manage_shifts`) may be fixing it for him while
     *   approving. Nobody else — this writes a row that attendance reads.
     */
    public function addWorkshopLocation(Request $request)
    {
        $data = $request->validate([
            'location_name' => 'required|string|max:100',
            // ⚠ Coordinates are required, but they may arrive as numbers OR as a Maps link,
            //   so neither is `required` here — the service refuses when neither yields any.
            'latitude'      => 'nullable|numeric|between:-90,90',
            'longitude'     => 'nullable|numeric|between:-180,180',
            'maps_url'      => 'nullable|string|max:500',
            'radius_meters' => 'nullable|integer|min:100|max:10000',
        ]);
        $user = $request->user() ?: auth()->user();
        if (!$this->visits->canSchedule($user, $this->mobileContext)
            && !$this->visits->canApprove($user, $this->mobileContext)) {
            return response()->json(['success' => false, 'message' => 'Not authorised'], 403);
        }

        $res = app(\App\Services\Location\CompanyLocationsService::class)
            ->createWorkshop($data, (int) ($user->id ?? 0));
        if (!$res['ok']) {
            return response()->json(['success' => false, 'message' => $res['message']], 422);
        }
        return response()->json([
            'success'   => true,
            'location'  => $res['location'],
            // Returned so the form can re-draw its picker with the new one already ticked,
            // which is the whole "without having to leave the form" requirement.
            'workshops' => $this->visits->workshopLocations(),
            'message'   => $res['message'],
        ]);
    }

    public function approve(Request $request, $id)
    {
        $data = $request->validate([
            // All three are ADJUSTMENTS — absent means "as proposed".
            'location_id'       => 'nullable|integer',
            'visit_time'        => 'nullable|date_format:H:i',
            // ⭐ The planner may also move his START TIME for that one day (Danish's 09:00
            //   visit against a 09:30 shift). Nothing else in the flow could fix that.
            'shift_template_id' => 'nullable|integer',
            /**
             * 📍 "Will he mark attendance at his regular place, or at the workshop?"
             *    (owner ask, 10-Sep-2026). ⚠ ABSENT still means "as proposed" — an older
             *    APK sends nothing and gets exactly the pre-10-Sep inference, so approving
             *    from a phone that has not been updated behaves as it always did.
             */
            'attendance_at'     => 'nullable|in:regular,workshop',
        ]);
        $user = $request->user() ?: auth()->user();
        $res  = $this->visits->approve($user, (int) $id, $data, $this->mobileContext);
        if (!$res['ok']) {
            return response()->json(['success' => false, 'message' => $res['message']], 422);
        }
        // ⭐ THIS is where the rider finally hears about it — and the booker hears it went through.
        $this->notify('approved', (int) $id, $user);
        return response()->json(['success' => true, 'pinned' => (bool) ($res['pinned'] ?? false),
                                 'message' => $res['message']]);
    }

    public function decline(Request $request, $id)
    {
        $request->validate(['reason' => 'nullable|string|max:255']);
        $user = $request->user() ?: auth()->user();
        $res  = $this->visits->decline($user, (int) $id, $request->input('reason'), $this->mobileContext);
        if (!$res['ok']) {
            return response()->json(['success' => false, 'message' => $res['message']], 422);
        }
        // ⚠ Only the booker is told. The rider was never part of this.
        $this->notify('declined', (int) $id, $user);
        return response()->json(['success' => true, 'message' => $res['message']]);
    }

    /** Advisory checks before a manager commits to a date — never blocking. */
    public function warnings(Request $request)
    {
        $data = $request->validate([
            'vehicle_id' => 'required|integer',
            'user_id'    => 'required|integer',
            'visit_date' => 'required|date_format:Y-m-d',
        ]);
        // ⚠ Advice for a SCHEDULER only — it names another rider's approved leave, off day and
        //   who holds the bike, so it must not answer just anyone who is logged in (Sep-4 review).
        $user = $request->user() ?: auth()->user();
        if (!$this->visits->canSchedule($user, $this->mobileContext)) {
            return response()->json(['success' => false, 'message' => 'Not authorised'], 403);
        }
        return response()->json([
            'success'  => true,
            'warnings' => $this->visits->warningsFor(
                (int) $data['vehicle_id'], (int) $data['user_id'], $data['visit_date']),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            // ⚠ Either is enough: a vehicle-first surface sends the bike, a rider-first
            //   one sends the man and the registry resolves his machine. The service
            //   refuses only when neither yields a bike.
            'vehicle_id'          => 'nullable|integer|required_without:user_id',
            'user_id'             => 'nullable|integer|required_without:vehicle_id',
            'visit_date'          => 'required|date_format:Y-m-d',
            'visit_time'          => 'nullable|date_format:H:i',
            'workshop'            => 'nullable|string|max:120',
            'location_id'         => 'nullable|integer',
            'purpose'             => 'nullable|in:service,repair,inspection,other',
            'maintenance_type_id' => 'nullable|integer',
            'ticket_id'           => 'nullable|integer',
            'note'                => 'nullable|string|max:255',
            /**
             * ⭐ 6-Sep: a PLANNER booking his own workshop day is asked "assign now, or send
             *   for approval?" and answers here. Everyone else is sent for approval whatever
             *   they send — the service decides from the caller's rights, never from this
             *   flag, so a crafted payload cannot buy an assignment.
             */
            'send_for_approval'   => 'nullable|boolean',
            /**
             * ⚠ The booker's explicit "yes, change the day he already has" (owner ruling
             *   7-Sep). Without it the service REFUSES a booking that would replace an
             *   already-approved visit and hands back `needs_confirmation` plus exactly what
             *   would change, so the screen can ask before anything happens.
             */
            'confirm_replace'     => 'nullable|boolean',
        ]);

        $user = $request->user() ?: auth()->user();
        $res  = $this->visits->schedule($user, $data, $this->mobileContext);
        if (!$res['ok']) {
            /**
             * ⏳ NOT AN ERROR — a question. The booking would change a workshop day the rider
             * has already been told about, so the screen must ask first and re-send with
             * `confirm_replace`. 409 (Conflict), not 422: a client that does not understand
             * this still shows the message, and shows a real reason rather than "invalid".
             */
            if (!empty($res['needs_confirmation'])) {
                return response()->json([
                    'success'            => false,
                    'needs_confirmation' => true,
                    'replaces'           => $res['replaces'] ?? null,
                    'message'            => $res['message'],
                ], 409);
            }
            return response()->json(['success' => false, 'message' => $res['message']], 422);
        }
        // ⚠⚠ A proposal notifies the PLANNERS. It must NOT notify the rider — that push is
        //    the "instant confirmation" the team ruled out, and sending it here would defeat
        //    the whole flow while everything else about it looked correct.
        $this->notify(!empty($res['proposed']) ? 'proposed' : 'scheduled', (int) $res['visit_id'], $user);

        return response()->json([
            'success'          => true,
            'visit_id'         => (int) $res['visit_id'],
            'rescheduled_from' => $res['rescheduled_from'] ?? null,
            'warnings'         => $res['warnings'] ?? [],
            'proposed'         => (bool) ($res['proposed'] ?? false),
            'message'          => $res['message'],
        ]);
    }

    public function accept(Request $request, $id)
    {
        $user = $request->user() ?: auth()->user();
        $res  = $this->visits->accept($user, (int) $id, $this->mobileContext);
        if (!$res['ok']) {
            return response()->json(['success' => false, 'message' => $res['message']], 422);
        }
        $this->notify('accepted', (int) $id, $user);
        return response()->json(['success' => true, 'message' => $res['message']]);
    }

    /**
     * ⭐⭐ "WORKSHOP JAA RAHA HOON" — the rider sets off (owner ask, 10-Sep-2026).
     *
     * The ONE manual step of the trip. Arrival is the geofence's job (no button, by ruling)
     * and the end is the outcome prompt that already exists — so this endpoint is the whole
     * of the new rider-facing surface.
     *
     * ⚠ 409, not 422, when open orders block it: it is a QUESTION with a remedy attached, not
     *   a validation failure, and both clients already know that shape from `confirm_replace`.
     *   `force` is honoured for a MANAGER only (the service enforces that, not this method).
     */
    public function depart(Request $request, $id)
    {
        $request->validate(['force' => 'nullable|boolean']);
        $user = $request->user() ?: auth()->user();
        $res  = $this->visits->depart($user, (int) $id,
                                      ['force' => $request->boolean('force')], $this->mobileContext);
        if (!$res['ok']) {
            return response()->json([
                'success' => false,
                'message' => $res['message'],
                'blocked_by_orders' => (bool) ($res['blocked_by_orders'] ?? false),
                'orders'  => $res['orders'] ?? null,
            ], !empty($res['blocked_by_orders']) ? 409 : 422);
        }
        return response()->json([
            'success' => true,
            'message' => $res['message'],
            'trip'    => $res['trip'] ?? null,
            'assigned_warning' => $res['assigned_warning'] ?? null,
        ]);
    }

    /**
     * ⭐⭐ EVERY ERRAND IN PROGRESS — the ONE source the store side reads.
     *
     * The phone banner, the web corner notice and the pushes all come from here, so "who is
     * away at a workshop right now" cannot be answered two different ways on two screens.
     *
     * ⚠ AUDIENCE: whoever assigns work (`assign_riders`), the shift planners, and the workshop
     *   alert holders. A rider gets an EMPTY list rather than a 403 — the banner then simply
     *   never renders for him, which is how every other notice on these screens behaves.
     */
    public function live(Request $request)
    {
        $user = $request->user() ?: auth()->user();
        if (!$user) return response()->json(['success' => true, 'trips' => []]);

        $may = false;
        try {
            $may = $this->visits->canSchedule($user, $this->mobileContext)
                || $this->visits->canApprove($user, $this->mobileContext)
                || (method_exists($user, 'hasMobilePermission') && $user->hasMobilePermission('assign_riders'))
                || (method_exists($user, 'hasPermission') && $user->hasPermission('assign_riders'))
                || (method_exists($user, 'hasMobilePermission')
                    && $user->hasMobilePermission(\App\Services\Riders\WorkshopVisitService::ALERT_PERMISSION));
        } catch (\Throwable $e) {
            $may = false;
        }
        if (!$may) return response()->json(['success' => true, 'trips' => []]);

        return response()->json(['success' => true, 'trips' => $this->visits->liveTrips()]);
    }

    /** Mobile twins — same methods, so the phone and the desk cannot drift. */
    public function apiDepart(Request $r, $id) { $this->mobileContext = true; return $this->depart($r, $id); }
    public function apiLive(Request $r)        { $this->mobileContext = true; return $this->live($r); }

    public function cancel(Request $request, $id)
    {
        $request->validate(['reason' => 'nullable|string|max:500']);
        $user = $request->user() ?: auth()->user();
        $res  = $this->visits->cancel($user, (int) $id, $request->input('reason'), $this->mobileContext);
        if (!$res['ok']) {
            return response()->json(['success' => false, 'message' => $res['message']], 422);
        }
        $this->notify('cancelled', (int) $id, $user);
        return response()->json(['success' => true, 'message' => $res['message']]);
    }

    /**
     * Mark done. When a service was performed the caller records it FIRST through
     * `markServiced` (the one door for service records) and passes the resulting
     * `service_log_id` here — so nothing about which clock moves is decided twice.
     */
    /**
     * 💰 File the bill the workshop handed over, against the service just recorded.
     *
     * ⭐⭐ Goes through Request\RequestController::store like every other bill — so it inherits
     *    the request number, the L1/L2 auto-approval rule (which for a RIDER means it queues
     *    for a manager, exactly as his own claims do), the ledger posting and the vehicle
     *    stamping. `service_log_id` ties it to the reading, so the pair is one job and one row.
     *
     * ⚠ Non-fatal by design. The service is already recorded; if the money cannot be filed the
     *   rider is told, and a manager can attach the bill later from the vehicle page.
     */
    private function fileVisitBill(Request $request, array $visit, ?int $logId, float $amount): string
    {
        try {
            if (!$logId) return '';
            $category = \App\Models\Request\RequestCategoryModel::where('category_code', 'expense')
                ->where('is_active', 1)->first();
            if (!$category) return ' The bill was not filed: the expense category is not set up.';

            $files = [];
            if ($request->hasFile('bill_image')) $files['attachment_image'] = $request->file('bill_image');

            /**
             * ⚠⚠ A RIDER FILING HIS OWN BILL MUST NOT LOOK LIKE FILING FOR SOMEONE ELSE.
             *    `RequestController::store` treats the presence of `requester_user_id` as
             *    "on behalf of", which needs a permission no rider holds — so sending it here
             *    refused the rider his own workshop bill outright ("You do not have permission
             *    to create requests for other users"). Omitted when he IS the requester; store()
             *    then defaults to the signed-in user, which is the same person.
             * ⚠ Still sent when a MANAGER completes the visit for him, which is genuinely on
             *   behalf of, and which he does hold the right for.
             */
            /**
             * ⚠⚠ READ THE SAME USER `store()` READS. It resolves the filer with `auth()->user()`,
             *    NOT the request's user resolver — so deciding "is this on behalf of someone
             *    else?" from a different source lets the two disagree: this half would omit
             *    `requester_user_id` while store() then files the claim for a different person,
             *    and the link would be refused as belonging to another rider. One source.
             */
            $actorId  = (int) (auth()->id() ?: (($request->user())->id ?? 0));
            $onBehalf = (int) $visit['user_id'] !== $actorId;

            $sub = Request::create('/api/requests/store', 'POST', array_filter([
                'category_id'        => $category->id,
                'requester_user_id'  => $onBehalf ? (int) $visit['user_id'] : null,
                // ⚠ The visit row carries the type ID, not its name — resolve it so the claim
                //   reads "Oil Change" rather than a generic label on every workshop bill.
                'title'              => (function () use ($visit) {
                    $t = app(\App\Services\Riders\MaintenanceTypeService::class)
                        ->find($visit['maintenance_type_id'] ?? null);
                    return $t->type_name ?? 'Workshop visit';
                })(),
                'description'        => 'Filed with the workshop visit on '
                                        . substr((string) $visit['visit_date'], 0, 10) . '.',
                'amount'             => $amount,
                'expense_category'   => 'Maintenance',
                // ⚠ The reading, job and date are INHERITED from the service — not resent here.
                'service_log_id'     => $logId,
                'payment_source_account_id' => $request->input('payment_source_account_id'),
                // Bikes is Nizami Farms operations — never the other books.
                'business_unit_id'   => 1,
            // ⚠ array_filter drops the NULL requester_user_id (and any null pay source) so the
            //   on-behalf check never sees a key that is not really there.
            ], fn ($v) => $v !== null), [], $files);
            $sub->setUserResolver($request->getUserResolver());

            $res  = app(\App\Http\Controllers\Request\RequestController::class)->store($sub);
            $body = json_decode($res->getContent(), true);
            if ($res->getStatusCode() < 200 || $res->getStatusCode() >= 300 || empty($body['success'])) {
                return ' Bill NOT filed: ' . ($body['message'] ?? 'it was refused.');
            }
            return ' Rs ' . number_format($amount) . ' bill '
                 . (!empty($body['auto_approved']) ? 'added and approved.' : 'sent for approval.')
                 . (!empty($files) ? ' Photo attached.' : ' No photo attached.');
        } catch (\Throwable $e) {
            \Log::error('fileVisitBill failed', ['visit' => $visit['id'] ?? null, 'error' => $e->getMessage()]);
            return ' The bill could not be filed — a manager can add it from the vehicle page.';
        }
    }

    public function done(Request $request, $id)
    {
        $data = $request->validate([
            'outcome_note'        => 'nullable|string|max:500',
            // ⚠⚠ happened = 0 is the "Nahi hua" answer: the trip did NOT take place. It is
            //    handled below as its own path and NEVER reaches markDone — the Sep-4 review
            //    found that button recording the visit as done.
            'happened'            => 'nullable|boolean',
            'request_id'          => 'nullable|integer',
            // ⭐ PHASE 3 — the loop closes here. Giving a meter records the service as a
            //   TYPED record through the shared recorder, so a workshop visit ends up in
            //   the same place (and under the same rules) as any other service.
            'meter'               => 'nullable|integer|min:0',
            'maintenance_type_id' => 'nullable|integer',
            'service_log_id'      => 'nullable|integer',
            /**
             * 💰 THE BILL, OPTIONAL (owner ruling Q5, 3-Sep). The workshop hands the receipt
             *    over on the day, so the rider can file it with the reading instead of leaving
             *    an un-billed service for a manager to chase.
             * ⚠ Blank is the common case and behaves exactly as before: the work is recorded,
             *   no money moves. Only a figure here spends anything.
             */
            'amount'                    => 'nullable|numeric|min:1|max:9999999',
            'payment_source_account_id' => 'nullable|integer',
            'bill_image'                => 'nullable|image|max:5120',
        ]);

        $user  = $request->user() ?: auth()->user();
        $visit = $this->visits->find((int) $id);
        if (!$visit) {
            return response()->json(['success' => false, 'message' => 'That visit no longer exists.'], 404);
        }

        // "Nahi hua" — the distinct answer. Nothing is closed, no service is recorded; the
        // note reaches the manager and the visit stays due (see reportNotDone).
        if ($request->has('happened') && !$request->boolean('happened')) {
            $res = $this->visits->reportNotDone($user, (int) $id, $data['outcome_note'] ?? null, $this->mobileContext);
            return response()->json([
                'success'   => (bool) $res['ok'],
                'kept_open' => !empty($res['kept_open']),
                'message'   => $res['message'],
            ], $res['ok'] ? 200 : 422);
        }

        // ⚠⚠ GATE FIRST. The service record and the bill below are real writes; markDone()
        //    only runs after them, so its own refusal came too late (Sep-4 review: anyone could
        //    post a meter on someone else's visit, and a re-post on a done visit inserted a
        //    fresh log each time). Same gate markDone applies, asked before anything is written.
        if ($err = $this->visits->completionGate($user, $visit, $this->mobileContext)) {
            return response()->json(['success' => false, 'message' => $err], 422);
        }

        $recorded = null;
        $billMsg  = null;
        if ($request->filled('meter')) {
            /**
             * ⚠⚠ ORDER MATTERS. The service is recorded FIRST and the visit is closed only
             *    if that succeeded — otherwise a refused type (or an "as conditions" job)
             *    would leave a visit marked done with no service behind it, which is the
             *    silent-hole shape this whole round has been removing.
             *
             * ⚠ The visit's OWN date is used, not today: the work happened when he went.
             * ⚠ The type falls back to the one the visit was booked for, so completing a
             *   scheduled service needs no re-picking.
             */
            $rec  = app(\App\Services\Riders\ServiceRecordService::class);
            /**
             * ⭐ CLASS-AWARE (Sep-2026): the visit names the machine, so the job is
             *   judged against that machine's own schedule — a van completion resolves
             *   against van figures, and a bike-only job on the van is refused with a
             *   sentence instead of recording a service nothing counts down.
             */
            $vKlass = !empty($visit['vehicle_id'])
                ? (new \App\Services\Riders\VehicleService())->classOf((int) $visit['vehicle_id'])
                : null;
            $type = $rec->resolveType($data['maintenance_type_id'] ?? $visit['maintenance_type_id'], $vKlass);
            if (!$type['ok']) {
                return response()->json(['success' => false, 'message' => $type['message']], 422);
            }
            $recorded = $rec->record([
                'rider_id'   => (int) $visit['user_id'],
                /**
                 * ⭐⭐ THE VISIT NAMES THE MACHINE — so the record is stamped with it rather
                 *    than re-derived from "what was this rider on that day" (owner ask,
                 *    10-Sep-2026).
                 *
                 * ⚠⚠ THIS IS THE CASE THE DERIVATION GETS WRONG, and it is the ordinary one:
                 *    the bike goes IN, the manager puts him on a spare for the day, and the
                 *    registry then answers "the spare". The oil change was credited to a bike
                 *    that never had one, the visit read done with a `service_log_id`, and the
                 *    real machine's countdown kept running with nothing saying why. The bike
                 *    that went to the workshop is right here on the visit; use it.
                 */
                'vehicle_id' => !empty($visit['vehicle_id']) ? (int) $visit['vehicle_id'] : null,
                'meter'      => (int) $data['meter'],
                'date'       => substr((string) $visit['visit_date'], 0, 10),
                'type'       => $type['type'],
                'actor_id'   => (int) $user->id,
                'note'       => 'Workshop visit #' . (int) $id
                    . ((int) $visit['user_id'] === (int) $user->id ? ' — confirmed by the rider' : ''),
            ]);
            if (!$recorded['ok']) {
                return response()->json(['success' => false, 'message' => $recorded['message']], 422);
            }
            $data['service_log_id'] = $recorded['service_log_id'];

            /**
             * 💰 …AND ITS BILL, if the workshop handed one over (owner ruling Q5).
             *
             * ⭐ Filed through the SAME door every other bill goes through — the claim carries
             *   the service's own reading, is linked to it, and inherits the L1/L2 rule, the
             *   ledger posting and the vehicle stamping. No second copy of any of that.
             * ⚠ ORDER: the service is already recorded above. A bill that fails to file must
             *   NOT lose the reading — the work happened either way — so this only decorates
             *   the receipt message and never changes the outcome of the visit.
             */
            if (!empty($data['amount']) && (float) $data['amount'] > 0) {
                $billMsg = $this->fileVisitBill($request, $visit, $recorded['service_log_id'] ?? null,
                                                (float) $data['amount']);
            }
        }

        $res = $this->visits->markDone($user, (int) $id, $data, $this->mobileContext);
        if (!$res['ok']) {
            return response()->json(['success' => false, 'message' => $res['message']], 422);
        }
        // ⭐ 6-Sep: nobody used to be told a visit had closed — not the rider who took the
        //   bike in, not the manager who booked it. A loop that closes silently is one people
        //   stop trusting is closed.
        $this->notify('done', (int) $id, $user);
        return response()->json([
            'success'        => true,
            'service_log_id' => $recorded['service_log_id'] ?? null,
            'message'        => trim($res['message'] . ' ' . ($recorded['message'] ?? '')
                                     . ' ' . ($billMsg ?? '')),
        ]);
    }

    /**
     * ⭐ THE RIDER'S SIDE OF THE LOOP (owner ruling, 2-Sep): "did it get done?" — asked on
     *   the day or after, never before. Self-scoped: it resolves the caller and returns
     *   only a visit addressed to him, so this route needs no permission and can leak
     *   nothing.
     *
     * ⚠ Deliberately no end-of-day auto-complete. Midnight turns an unanswered visit into
     *   MISSED — a question — and this is how it gets answered. Auto-marking it done would
     *   make "he went" and "he never went" look identical.
     */
    public function outcome(Request $request)
    {
        $user = $request->user() ?: auth()->user();
        if (!$user) return response()->json(['success' => true, 'visit' => null]);

        $v = $this->visits->awaitingOutcomeFor((int) $user->id);
        return response()->json([
            'success' => true,
            'visit'   => $v,
            /**
             * What the prompt needs to ask for: the job it was booked for, and every
             * scheduled type in case it turned out to be a different one.
             * ⭐ Narrowed to the VISIT'S OWN MACHINE (Sep-2026) — a van driver is not
             *   offered a bike's jobs, and the figures shown are the ones that machine
             *   actually follows.
             */
            'types'   => $v
                ? app(\App\Services\Riders\ServiceRecordService::class)->scheduledTypes(
                    !empty($v['vehicle_id'])
                        ? (new \App\Services\Riders\VehicleService())->classOf((int) $v['vehicle_id'])
                        : null)
                : [],
        ]);
    }

    /**
     * The RIDER's own next visit, and whether he still has to accept it.
     * ⭐ Self-scoped to `Auth::id()` — no parameter, so no rider can ask about another.
     */
    public function pending(Request $request)
    {
        $user = $request->user() ?: auth()->user();
        $next = $user ? $this->visits->nextForUser((int) $user->id) : null;
        return response()->json([
            'success'        => true,
            'visit'          => $next,
            'needs_accept'   => $next ? ($next['status'] === 'scheduled') : false,
        ]);
    }

    /** Drives the management banners (web corner card, mobile floating bar). */
    public function alerts(Request $request)
    {
        $user = $request->user() ?: auth()->user();
        $out  = $this->visits->summaryFor($user, $this->mobileContext);

        /**
         * ⭐ The planners' banner rides the same poll rather than a second one — one request
         *   answers "what is happening" and "what is waiting on me".
         * ⚠ Empty for anyone who is not a planner (the service refuses, not the caller).
         */
        $out['pending_approvals'] = $this->visits->pendingApprovals($user, $this->mobileContext);
        $out['can_approve']       = $this->visits->canApprove($user, $this->mobileContext);

        /**
         * ⏰ ONE implementation (10-Sep-2026): the day-before reminder, the 17:00 planner
         *    nudge and the auto-decline live in `FleetSweepService::workshop()`, which is
         *    also what the `fleet:sweep` cron runs. This poll keeps calling it — the cron
         *    covers a day nobody opens the screen, the poll covers a day the cron is off —
         *    and every push is once-only by construction, so the two cannot double-send.
         * ⚠ Deferred so a slow push never delays the banner.
         */
        try {
            app()->terminating(function () {
                try {
                    app(\App\Services\Riders\FleetSweepService::class)->workshop();
                } catch (\Throwable $e) {
                    \Log::warning('workshop sweep (poll) failed', ['error' => $e->getMessage()]);
                }
            });
        } catch (\Throwable $e) {
        }

        return response()->json(['success' => true] + $out);
    }

    private function notify(string $event, int $visitId, $actor): void
    {
        try {
            app(\App\Services\FirebaseService::class)
                ->notifyWorkshopVisit($event, $visitId, (int) ($actor->id ?? 0));
        } catch (\Throwable $e) {
            Log::warning('Workshop push failed', ['visit' => $visitId, 'error' => $e->getMessage()]);
        }
    }
}
