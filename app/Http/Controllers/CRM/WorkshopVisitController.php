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
        $user = $request->user() ?: auth()->user();

        // ⚠ Someone who cannot schedule sees only his OWN visits. Without this a rider
        //   hitting the list endpoint would read the whole fleet's movements.
        if (!$this->visits->canSchedule($user, $this->mobileContext)) {
            $data['user_id'] = (int) $user->id;
        }

        return response()->json([
            'success'      => true,
            'available'    => $this->visits->available(),
            'can_schedule' => $this->visits->canSchedule($user, $this->mobileContext),
            'visits'       => $this->visits->listVisits($data),
            // 📍 Phase 4 — the registered workshops. Picking one makes it that day's shift
            //   location, so checking in there is on time by itself. Empty until a manager
            //   ticks a location as a workshop, and both schedulers then simply show no picker.
            'workshops'    => $this->visits->workshopLocations(),
        ]);
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
        ]);

        $user = $request->user() ?: auth()->user();
        $res  = $this->visits->schedule($user, $data, $this->mobileContext);
        if (!$res['ok']) {
            return response()->json(['success' => false, 'message' => $res['message']], 422);
        }
        $this->notify('scheduled', (int) $res['visit_id'], $user);

        return response()->json([
            'success'          => true,
            'visit_id'         => (int) $res['visit_id'],
            'rescheduled_from' => $res['rescheduled_from'] ?? null,
            'warnings'         => $res['warnings'] ?? [],
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
            $type = $rec->resolveType($data['maintenance_type_id'] ?? $visit['maintenance_type_id']);
            if (!$type['ok']) {
                return response()->json(['success' => false, 'message' => $type['message']], 422);
            }
            $recorded = $rec->record([
                'rider_id' => (int) $visit['user_id'],
                'meter'    => (int) $data['meter'],
                'date'     => substr((string) $visit['visit_date'], 0, 10),
                'type'     => $type['type'],
                'actor_id' => (int) $user->id,
                'note'     => 'Workshop visit #' . (int) $id
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
            // What the prompt needs to ask for: the job it was booked for, and every
            // scheduled type in case it turned out to be a different one.
            'types'   => $v ? app(\App\Services\Riders\ServiceRecordService::class)->scheduledTypes() : [],
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

        // ⚠ No cron on prod — the day-before reminder rides on this poll and fires once
        //   per visit (`reminded_at`). Deferred so a slow push never delays the banner.
        try {
            app()->terminating(function () {
                foreach ($this->visits->dueReminders() as $v) {
                    try {
                        app(\App\Services\FirebaseService::class)
                            ->notifyWorkshopVisit('reminder', (int) $v['id'], 0);
                    } catch (\Throwable $e) {
                    }
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
