<?php

namespace App\Http\Controllers\HR;

use App\Http\Controllers\Controller;
use App\Services\HR\PayrollService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class PayrollController extends Controller
{
    /** One key gates the whole screen: view, generate, set salaries, advances, pay. */
    private function denyIfNotAllowed()
    {
        $u = auth()->user();
        if (!$u || !$u->hasPermission('manage_payroll')) {
            return response()->json(['success' => false, 'message' => "You don't have access to payroll."], 403);
        }
        return null;
    }

    public function index()
    {
        $u = auth()->user();
        if (!$u || !$u->hasPermission('manage_payroll')) {
            abort(403, "You don't have access to payroll.");
        }
        return view('pages.payroll.index');
    }

    /**
     * Whether this user may VOID a salary advance (owner-level correction).
     *
     * Separate from `manage_payroll` on purpose: every payroll manager gives advances, but
     * un-doing one moves money back out of the books, so it is granted to the owner's role
     * only. Role-based — anyone later assigned that role inherits it automatically.
     */
    private function canVoidAdvance(): bool
    {
        $u = auth()->user();
        return $u && $u->hasPermission('void_salary_advance');
    }

    /**
     * Whether this user may VOID a custom-salary payment.
     *
     * Owner ruling Aug-2026: Taimur AND Shabib (roles 14 + 10), i.e. wider than the
     * advance void but still narrower than `manage_payroll`. Role-based, so it follows
     * the role rather than the person.
     */
    private function canVoidPayment(): bool
    {
        $u = auth()->user();
        return $u && $u->hasPermission('void_salary_payment');
    }

    /** Whether this manager may see/tag the Khaas business unit (gates the BU toggle). */
    private function canViewKhaas(): bool
    {
        try {
            $u = auth()->user();
            if (!$u) { return false; }
            $u->load(['roles.mobilePermissions']);
            $perms = method_exists($u, 'getMobilePermissions') ? $u->getMobilePermissions() : [];
            return in_array('access_khaas_mode', $perms, true);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** The month grid + the accounts the pay modal needs. */
    public function data(Request $request)
    {
        if ($deny = $this->denyIfNotAllowed()) return $deny;
        $month = $request->input('month', now()->format('Y-m'));
        if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
            $month = now()->format('Y-m');
        }
        try {
            $svc = new PayrollService();
            $rows = $svc->computeMonth($month);
        } catch (\Throwable $e) {
            \Log::error('Payroll data failed', ['month' => $month, 'error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Could not build payroll for this month.'], 500);
        }

        return response()->json([
            'success' => true,
            'month' => $month,
            'month_label' => date('F Y', strtotime($month . '-01')),
            'rows' => $rows,
            // Ships with the grid rather than as a second request: the rows above already
            // warmed the per-request compute cache, so this costs almost nothing here,
            // whereas a separate call would recompute the whole month from scratch.
            'leave_actions' => $svc->leaveActionsMonth($month),
            'funding' => $svc->fundingOptions(),
            'schedule_available' => $svc->scheduleTaggingAvailable(),
            'khaas_available' => $svc->khaasTaggingAvailable() && $this->canViewKhaas(),
            'khaas_bu_id' => $svc->khaasBuIdValue(),
            'can_void_advance' => $this->canVoidAdvance(),
            // Drives the "you have something waiting" banner + the strip card. Counts every
            // pending request, including staff who are not on this grid.
            'advance_summary' => $svc->pendingAdvanceSummary(),
        ]);
    }

    /** The Custom tab: date-range / weekly employees + their coverage for the month. */
    public function customData(Request $request)
    {
        if ($deny = $this->denyIfNotAllowed()) { return $deny; }
        $month = $request->input('month', now()->format('Y-m'));
        if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
            $month = now()->format('Y-m');
        }
        try {
            $svc = new PayrollService();
            $rows = $svc->computeMonthCustom($month);
        } catch (\Throwable $e) {
            \Log::error('Payroll custom data failed', ['month' => $month, 'error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Could not build the custom list for this month.'], 500);
        }

        return response()->json([
            'success' => true,
            'month' => $month,
            'month_label' => date('F Y', strtotime($month . '-01')),
            'rows' => $rows,
            'funding' => $svc->fundingOptions(),
            'schedule_available' => $svc->scheduleTaggingAvailable(),
            'khaas_available' => $svc->khaasTaggingAvailable() && $this->canViewKhaas(),
            'khaas_bu_id' => $svc->khaasBuIdValue(),
            'can_void_advance' => $this->canVoidAdvance(),
            'balance_available' => $svc->balanceTrackingAvailable(),
            'can_void_payment' => $this->canVoidPayment(),
            'today' => now()->format('Y-m-d'),
            // Same banner on this tab — a waiting request must not hide behind a tab choice.
            'advance_summary' => $svc->pendingAdvanceSummary(),
        ]);
    }

    // ── Custom running balance ("khata") ─────────────────────────────────────
    //
    // Same `manage_payroll` authority as every other money action on this screen;
    // voiding a payment additionally needs `void_salary_payment`.

    /** One month of an employee's calendar: days, payments and the running balance. */
    public function balanceCalendar(Request $request)
    {
        if ($deny = $this->denyIfNotAllowed()) { return $deny; }
        $v = Validator::make($request->all(), [
            'user_id' => 'required|integer|exists:t_sys_user,id',
            'month'   => ['nullable', 'regex:/^\d{4}-\d{2}$/'],
        ]);
        if ($v->fails()) {
            return response()->json(['success' => false, 'message' => $v->errors()->first()], 422);
        }
        try {
            $data = (new PayrollService())->balanceCalendar(
                (int) $request->user_id,
                (string) ($request->month ?: now()->format('Y-m'))
            );
        } catch (\Throwable $e) {
            \Log::error('Balance calendar failed', ['user_id' => $request->user_id, 'error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Could not load the calendar.'], 500);
        }
        return response()->json($data + ['can_void_payment' => $this->canVoidPayment(), 'today' => now()->format('Y-m-d')],
            !empty($data['success']) ? 200 : 422);
    }

    /** Start a running balance: anchor date + opening balance. */
    public function balanceEnable(Request $request)
    {
        if ($deny = $this->denyIfNotAllowed()) { return $deny; }
        $v = Validator::make($request->all(), [
            'user_id'    => 'required|integer|exists:t_sys_user,id',
            'start_date' => 'required|date',
            'opening'    => 'nullable|numeric|min:-100000000|max:100000000',
        ]);
        if ($v->fails()) {
            return response()->json(['success' => false, 'message' => $v->errors()->first()], 422);
        }
        $res = (new PayrollService())->enableBalanceTracking(
            (int) $request->user_id,
            date('Y-m-d', strtotime($request->start_date)),
            (float) ($request->opening ?? 0),
            (int) auth()->id()
        );
        return response()->json($res, !empty($res['success']) ? 200 : 422);
    }

    /** Cross a day out (earns nothing) or restore it. Toggle. */
    public function balanceAbsence(Request $request)
    {
        if ($deny = $this->denyIfNotAllowed()) { return $deny; }
        $v = Validator::make($request->all(), [
            'user_id' => 'required|integer|exists:t_sys_user,id',
            'date'    => 'required|date',
        ]);
        if ($v->fails()) {
            return response()->json(['success' => false, 'message' => $v->errors()->first()], 422);
        }
        $res = (new PayrollService())->toggleAbsence(
            (int) $request->user_id, date('Y-m-d', strtotime($request->date)), (int) auth()->id()
        );
        return response()->json($res, !empty($res['success']) ? 200 : 422);
    }

    /** Record money handed to a tracked employee (a normal payroll payment + ledger row). */
    public function balancePayment(Request $request)
    {
        if ($deny = $this->denyIfNotAllowed()) { return $deny; }
        $v = Validator::make($request->all(), [
            'user_id' => 'required|integer|exists:t_sys_user,id',
            'amount'  => 'required|numeric|min:1|max:100000000',
            'date'    => 'required|date',
            'funding' => 'required|in:cash,online',
            'bank_id' => 'nullable|integer',
            'note'    => 'nullable|string|max:255',
        ]);
        if ($v->fails()) {
            return response()->json(['success' => false, 'message' => $v->errors()->first()], 422);
        }
        if ($request->funding === 'online' && !$request->bank_id) {
            return response()->json(['success' => false, 'message' => 'Choose the bank you are paying from.'], 422);
        }
        $res = (new PayrollService())->recordBalancePayment(
            (int) $request->user_id,
            (float) $request->amount,
            date('Y-m-d', strtotime($request->date)),
            [
                'funding'  => $request->funding,
                'bank_id'  => $request->bank_id ? (int) $request->bank_id : null,
                'note'     => $request->note,
                'actor_id' => (int) auth()->id(),
            ]
        );
        return response()->json($res, !empty($res['success']) ? 200 : 422);
    }

    /** Change a tracked employee's rate from a date the manager chooses. */
    public function balanceRate(Request $request)
    {
        if ($deny = $this->denyIfNotAllowed()) { return $deny; }
        $v = Validator::make($request->all(), [
            'user_id'        => 'required|integer|exists:t_sys_user,id',
            'rate'           => 'required|numeric|min:1|max:100000000',
            'effective_date' => 'required|date',
        ]);
        if ($v->fails()) {
            return response()->json(['success' => false, 'message' => $v->errors()->first()], 422);
        }
        $res = (new PayrollService())->changeTrackedRate(
            (int) $request->user_id, (float) $request->rate,
            date('Y-m-d', strtotime($request->effective_date)), (int) auth()->id()
        );
        return response()->json($res, !empty($res['success']) ? 200 : 422);
    }

    /** Void a mistaken payment: reverses the ledger and drops it from the balance. */
    public function balanceVoidPayment(Request $request)
    {
        if ($deny = $this->denyIfNotAllowed()) { return $deny; }
        if (!$this->canVoidPayment()) {
            return response()->json(['success' => false, 'message' => "You don't have access to void a salary payment."], 403);
        }
        $v = Validator::make($request->all(), [
            'payment_id' => 'required|integer',
            'reason'     => 'required|string|min:3|max:200',
        ]);
        if ($v->fails()) {
            return response()->json(['success' => false, 'message' => $v->errors()->first()], 422);
        }
        $res = (new PayrollService())->voidBalancePayment(
            (int) $request->payment_id, (string) $request->reason, (int) auth()->id()
        );
        return response()->json($res, !empty($res['success']) ? 200 : 422);
    }

    /** Live preview of a custom period's amount (days × rate, advances, attendance reference). */
    public function customPreview(Request $request)
    {
        if ($deny = $this->denyIfNotAllowed()) { return $deny; }
        $v = Validator::make($request->all(), [
            'user_id' => 'required|integer|exists:t_sys_user,id',
            'start'   => 'required|date',
            'end'     => 'required|date',
        ]);
        if ($v->fails()) {
            return response()->json(['success' => false, 'message' => $v->errors()->first()], 422);
        }
        try {
            $row = (new PayrollService())->computeCustomPeriod(
                (int) $request->user_id,
                date('Y-m-d', strtotime($request->start)),
                date('Y-m-d', strtotime($request->end))
            );
            return response()->json(['success' => true, 'row' => $row]);
        } catch (\Throwable $e) {
            \Log::error('Payroll custom preview failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Could not compute this period.'], 500);
        }
    }

    /** Pay a custom employee for a date range. */
    public function payCustom(Request $request)
    {
        if ($deny = $this->denyIfNotAllowed()) { return $deny; }
        $v = Validator::make($request->all(), [
            'user_id' => 'required|integer|exists:t_sys_user,id',
            'start'   => 'required|date',
            'end'     => 'required|date',
            'funding' => 'required|in:cash,online',
            'bank_id' => 'nullable|integer',
            'amount'  => 'nullable|numeric|min:0',
            'note'    => 'nullable|string|max:255',
        ]);
        if ($v->fails()) {
            return response()->json(['success' => false, 'message' => $v->errors()->first()], 422);
        }
        if ($request->funding === 'online' && !$request->bank_id) {
            return response()->json(['success' => false, 'message' => 'Choose the bank you are paying from.'], 422);
        }
        $res = (new PayrollService())->payCustomPeriod(
            (int) $request->user_id,
            date('Y-m-d', strtotime($request->start)),
            date('Y-m-d', strtotime($request->end)),
            [
                'funding'  => $request->funding,
                'bank_id'  => $request->bank_id ? (int) $request->bank_id : null,
                'amount'   => $request->has('amount') ? $request->amount : null,
                'note'     => $request->note,
                'actor_id' => (int) auth()->id(),
            ]
        );
        return response()->json($res, !empty($res['success']) ? 200 : 422);
    }

    /** The "Staff Salaries" expense records behind a row's double-pay warning. */
    public function staffExpenseDetail(Request $request)
    {
        if ($deny = $this->denyIfNotAllowed()) { return $deny; }
        $v = Validator::make($request->all(), [
            'user_id' => 'required|integer',
            'month'   => ['required', 'regex:/^\d{4}-\d{2}$/'],
        ]);
        if ($v->fails()) {
            return response()->json(['success' => false, 'message' => $v->errors()->first()], 422);
        }
        return response()->json([
            'success' => true,
            'records' => (new PayrollService())->staffExpenseDetail((int) $request->user_id, $request->month),
        ]);
    }

    /** Set an employee's pay schedule (monthly|custom) + rate type. */
    public function setSchedule(Request $request)
    {
        if ($deny = $this->denyIfNotAllowed()) { return $deny; }
        $v = Validator::make($request->all(), [
            'user_id'      => 'required|integer|exists:t_sys_user,id',
            'pay_schedule' => 'required|in:monthly,custom',
            'rate_type'    => 'nullable|in:daily,monthly',
        ]);
        if ($v->fails()) {
            return response()->json(['success' => false, 'message' => $v->errors()->first()], 422);
        }
        $res = (new PayrollService())->setSchedule(
            (int) $request->user_id, $request->pay_schedule, $request->rate_type, (int) auth()->id()
        );
        return response()->json($res, !empty($res['success']) ? 200 : 422);
    }

    /** Tag an employee's business unit (NF or Khaas). */
    public function setBusinessUnit(Request $request)
    {
        if ($deny = $this->denyIfNotAllowed()) { return $deny; }
        // Only Khaas-visible managers may change BU tags at all — otherwise a
        // manager without Khaas access could still CLEAR someone's Khaas tag.
        if (!$this->canViewKhaas()) {
            return response()->json(['success' => false, 'message' => "You don't have access to the Khaas business unit."], 403);
        }
        $v = Validator::make($request->all(), [
            'user_id'          => 'required|integer|exists:t_sys_user,id',
            'business_unit_id' => 'nullable|integer',
        ]);
        if ($v->fails()) {
            return response()->json(['success' => false, 'message' => $v->errors()->first()], 422);
        }
        $res = (new PayrollService())->setBusinessUnit(
            (int) $request->user_id,
            $request->business_unit_id ? (int) $request->business_unit_id : null,
            (int) auth()->id()
        );
        return response()->json($res, !empty($res['success']) ? 200 : 422);
    }

    /** Inline base-salary edit from the grid (audited). */
    public function setSalary(Request $request)
    {
        if ($deny = $this->denyIfNotAllowed()) return $deny;
        $v = Validator::make($request->all(), [
            'user_id' => 'required|integer|exists:t_sys_user,id',
            'base_salary' => 'required|numeric|min:0|max:100000000',
        ]);
        if ($v->fails()) {
            return response()->json(['success' => false, 'message' => $v->errors()->first()], 422);
        }
        try {
            $svc = new PayrollService();
            // A running-balance employee's rate needs the date it applies from, so send a
            // stale page to the right control instead of failing as a server error.
            if ($svc->isBalanceTracked((int) $request->user_id)) {
                return response()->json([
                    'success' => false,
                    'message' => 'This employee is on a running balance — change the rate from their card so you can set the date it applies from.',
                ], 422);
            }
            $svc->setBaseSalary((int) $request->user_id, (float) $request->base_salary, (int) auth()->id());
            return response()->json(['success' => true, 'message' => 'Salary saved.']);
        } catch (\Throwable $e) {
            \Log::error('setSalary failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Could not save the salary.'], 500);
        }
    }

    /** Give a mid-month advance from the payroll page (creates + posts a salary_advance). */
    public function giveAdvance(Request $request)
    {
        if ($deny = $this->denyIfNotAllowed()) return $deny;
        $v = Validator::make($request->all(), [
            'user_id' => 'required|integer|exists:t_sys_user,id',
            'amount'  => 'required|numeric|min:1|max:100000000',
            'funding' => 'required|in:cash,online',
            'bank_id' => 'nullable|integer',
            'note'    => 'nullable|string|max:255',
        ]);
        if ($v->fails()) {
            return response()->json(['success' => false, 'message' => $v->errors()->first()], 422);
        }
        $res = (new PayrollService())->giveAdvance(
            (int) $request->user_id, (float) $request->amount, $request->funding,
            $request->bank_id ? (int) $request->bank_id : null, $request->note, (int) auth()->id()
        );
        return response()->json($res, !empty($res['success']) ? 200 : 422);
    }

    /** Advance requests from employees still awaiting a decision (the top card). */
    public function pendingRequests(Request $request)
    {
        if ($deny = $this->denyIfNotAllowed()) return $deny;
        $svc = new PayrollService();
        $rows = $svc->pendingAdvanceRequests();
        return response()->json([
            'success' => true,
            'requests' => $rows,
            'count' => count($rows),
            'total' => round(array_sum(array_column($rows, 'amount')), 2),
            'funding' => $svc->fundingOptions(),
        ]);
    }

    // ── Leave actions (overtime bonus / late penalty) ────────────────────────
    //
    // Read by BOTH the payroll Leave-actions panel and the Attendance month banner, and
    // gated by the same `manage_payroll` key on every call: a bonus/penalty leave changes
    // what an employee can take off and feeds the salary rules, so the authority to decide
    // it must not be wider on Attendance than it is on Payroll.

    /** The month's leave actions: what's recommended, what's decided, and why. */
    public function leaveActions(Request $request)
    {
        if ($deny = $this->denyIfNotAllowed()) return $deny;
        $month = (string) $request->input('month', now()->format('Y-m'));
        if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
            $month = now()->format('Y-m');
        }
        try {
            $data = (new PayrollService())->leaveActionsMonth($month);
        } catch (\Throwable $e) {
            \Log::error('Leave actions failed', ['month' => $month, 'error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Could not load the leave actions for this month.'], 500);
        }
        return response()->json(['success' => true] + $data);
    }

    /** Give/deduct or waive ONE employee's leave action for a month. */
    public function decideLeaveAction(Request $request)
    {
        if ($deny = $this->denyIfNotAllowed()) return $deny;
        $v = Validator::make($request->all(), [
            'user_id'  => 'required|integer',
            'month'    => ['required', 'regex:/^\d{4}-\d{2}$/'],
            'kind'     => 'required|in:overtime,late_penalty',
            'decision' => 'required|in:apply,waive',
        ]);
        if ($v->fails()) {
            return response()->json(['success' => false, 'message' => $v->errors()->first()], 422);
        }
        $res = (new PayrollService())->decideLeaveAction(
            (int) $request->user_id, (string) $request->month,
            (string) $request->kind, (string) $request->decision, (int) auth()->id()
        );
        return response()->json($res, !empty($res['success']) ? 200 : 422);
    }

    /** Apply every still-pending recommendation for the month (never touches a decided one). */
    public function applyAllLeaveActions(Request $request)
    {
        if ($deny = $this->denyIfNotAllowed()) return $deny;
        $v = Validator::make($request->all(), [
            'month' => ['required', 'regex:/^\d{4}-\d{2}$/'],
        ]);
        if ($v->fails()) {
            return response()->json(['success' => false, 'message' => $v->errors()->first()], 422);
        }
        $res = (new PayrollService())->applyAllPendingLeaveActions((string) $request->month, (int) auth()->id());
        return response()->json($res, !empty($res['success']) ? 200 : 422);
    }

    /**
     * APPROVE an employee's advance request AND pay it now. Same money authority as
     * "+ advance" (both are gated by `manage_payroll`), so the funding account is chosen
     * here at approval time.
     */
    public function approveRequest(Request $request)
    {
        if ($deny = $this->denyIfNotAllowed()) return $deny;
        $v = Validator::make($request->all(), [
            'request_id' => 'required|integer',
            'funding'    => 'required|in:cash,online',
            'bank_id'    => 'nullable|integer',
        ]);
        if ($v->fails()) {
            return response()->json(['success' => false, 'message' => $v->errors()->first()], 422);
        }
        $res = (new PayrollService())->approveAdvanceRequest(
            (int) $request->request_id, $request->funding,
            $request->bank_id ? (int) $request->bank_id : null, (int) auth()->id()
        );
        return response()->json($res, !empty($res['success']) ? 200 : 422);
    }

    /** REJECT an employee's advance request (no money involved). */
    public function rejectRequest(Request $request)
    {
        if ($deny = $this->denyIfNotAllowed()) return $deny;
        $v = Validator::make($request->all(), [
            'request_id' => 'required|integer',
            'reason'     => 'required|string|min:3|max:200',
        ]);
        if ($v->fails()) {
            return response()->json(['success' => false, 'message' => $v->errors()->first()], 422);
        }
        $res = (new PayrollService())->rejectAdvanceRequest(
            (int) $request->request_id, (string) $request->reason, (int) auth()->id()
        );
        return response()->json($res, !empty($res['success']) ? 200 : 422);
    }

    /**
     * VOID a wrongly-given advance: reverses its ledger entry through the balance engine and
     * cancels the request. Owner-only (`void_salary_advance`), on top of payroll access.
     */
    public function voidAdvance(Request $request)
    {
        if ($deny = $this->denyIfNotAllowed()) return $deny;
        if (!$this->canVoidAdvance()) {
            return response()->json(['success' => false, 'message' => 'Only the owner can void a salary advance.'], 403);
        }
        $v = Validator::make($request->all(), [
            'request_id' => 'required|integer',
            'reason'     => 'required|string|min:3|max:200',
        ]);
        if ($v->fails()) {
            return response()->json(['success' => false, 'message' => $v->errors()->first()], 422);
        }
        $res = (new PayrollService())->voidAdvance(
            (int) $request->request_id, (string) $request->reason, (int) auth()->id()
        );
        return response()->json($res, !empty($res['success']) ? 200 : 422);
    }

    /** Pay the selected rows. */
    public function pay(Request $request)
    {
        if ($deny = $this->denyIfNotAllowed()) return $deny;
        $v = Validator::make($request->all(), [
            'month'   => ['required', 'regex:/^\d{4}-\d{2}$/'],
            'funding' => 'required|in:cash,online',
            'bank_id' => 'nullable|integer',
            'items'   => 'required|array|min:1',
            'items.*.user_id' => 'required|integer',
            'items.*.late_deduction' => 'nullable|numeric|min:0',
            'items.*.net_override' => 'nullable|numeric|min:0',
            'items.*.skip_overtime' => 'nullable|boolean',
            'items.*.skip_late_leave' => 'nullable|boolean',
            'items.*.defer_leave_actions' => 'nullable|boolean',
        ]);
        if ($v->fails()) {
            return response()->json(['success' => false, 'message' => $v->errors()->first()], 422);
        }
        if ($request->funding === 'online' && !$request->bank_id) {
            return response()->json(['success' => false, 'message' => 'Choose the bank you are paying from.'], 422);
        }

        $r = (new PayrollService())->payMany(
            $request->items, $request->month, $request->funding,
            $request->bank_id ? (int) $request->bank_id : null, (int) auth()->id()
        );
        $msg = $r['paid'] . ' paid (Rs ' . number_format($r['total']) . ')'
            . ($r['skipped'] ? ', ' . $r['skipped'] . ' skipped' : '')
            . ($r['failed'] ? ', ' . $r['failed'] . ' failed' : '') . '.';

        return response()->json([
            'success' => $r['paid'] > 0 || ($r['failed'] === 0),
            'message' => $msg,
            'paid' => $r['paid'], 'skipped' => $r['skipped'], 'failed' => $r['failed'],
            'total' => $r['total'], 'details' => $r['details'],
        ]);
    }
}
