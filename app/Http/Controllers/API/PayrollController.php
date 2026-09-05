<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\HR\PayrollService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Mobile payroll API (Sanctum). Manager endpoints reuse the SAME PayrollService as the web
 * Payroll screen, so every figure matches byte-for-byte. Rider `mySalary` is self-only.
 */
class PayrollController extends Controller
{
    private function denyIfNotManager(Request $request)
    {
        $user = $request->user();
        if (!$user || !$user->hasMobilePermission('manage_payroll')) {
            return response()->json(['success' => false, 'message' => "You don't have access to payroll."], 403);
        }
        return null;
    }

    private function normMonth($m): string
    {
        return (is_string($m) && preg_match('/^\d{4}-\d{2}$/', $m)) ? $m : now()->format('Y-m');
    }

    /** Manager: the month grid + funding accounts. */
    public function managerMonth(Request $request)
    {
        if ($deny = $this->denyIfNotManager($request)) return $deny;
        $month = $this->normMonth($request->input('month'));
        try {
            $svc = new PayrollService();
            return response()->json([
                'success' => true,
                'month' => $month,
                'month_label' => date('F Y', strtotime($month . '-01')),
                'rows' => $svc->computeMonth($month),
                'funding' => $svc->fundingOptions(),
                // Same two feeds the web screen renders its panel and red banner from, so a
                // manager on the phone sees the same work waiting for him as on the desk.
                'leave_actions' => $svc->leaveActionsMonth($month),
                'absence_summary' => $svc->pendingAbsenceSummary($month),
            ]);
        } catch (\Throwable $e) {
            \Log::error('Mobile payroll month failed', ['month' => $month, 'error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Could not build payroll.'], 500);
        }
    }

    /**
     * Manager: decide one leave action — overtime bonus, late penalty, or an absence.
     *
     * ⭐ Straight through to the SAME PayrollService::decideLeaveAction the web panel posts to,
     * so a decision made on a phone and one made at a desk are the same write, with the same
     * refusals (a paid month, a custom-schedule employee, a month before the start month).
     */
    public function decideLeaveAction(Request $request)
    {
        if ($deny = $this->denyIfNotManager($request)) return $deny;
        $v = Validator::make($request->all(), [
            'user_id'  => 'required|integer',
            'month'    => 'required|string',
            'kind'     => 'required|in:overtime,late_penalty,absence',
            'decision' => 'required|in:apply,waive,cut,park,excuse',
        ]);
        if ($v->fails()) {
            return response()->json(['success' => false, 'message' => $v->errors()->first()], 422);
        }
        try {
            $res = (new PayrollService())->decideLeaveAction(
                (int) $request->user_id,
                $this->normMonth($request->month),
                (string) $request->kind,
                (string) $request->decision,
                (int) $request->user()->id
            );
            return response()->json($res, ($res['success'] ?? false) ? 200 : 422);
        } catch (\Throwable $e) {
            \Log::error('Mobile decide leave action failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Could not save that decision.'], 500);
        }
    }

    /**
     * Manager: settle days PARKED in an earlier month — charge them now, cover them from the
     * employee's own leave, or excuse them.
     */
    public function settleAbsence(Request $request)
    {
        if ($deny = $this->denyIfNotManager($request)) return $deny;
        $v = Validator::make($request->all(), [
            'user_id'   => 'required|integer',
            'month'     => 'required|string',              // the month the absences happened in
            'action'    => 'required|in:charge,excuse,use_leave',
            'in_month'  => 'nullable|string',              // whose pay carries a later charge
            'amount'    => 'nullable|numeric|min:0',
            'days'      => 'nullable|numeric|min:0',
            'note'      => 'nullable|string|max:255',
        ]);
        if ($v->fails()) {
            return response()->json(['success' => false, 'message' => $v->errors()->first()], 422);
        }
        try {
            $res = (new PayrollService())->settleParkedAbsence(
                (int) $request->user_id,
                (string) $request->month,
                (string) $request->action,
                $request->in_month ? (string) $request->in_month : null,
                $request->filled('amount') ? (float) $request->amount : null,
                $request->filled('days') ? (float) $request->days : null,
                $request->note ? (string) $request->note : null,
                (int) $request->user()->id
            );
            return response()->json($res, ($res['success'] ?? false) ? 200 : 422);
        } catch (\Throwable $e) {
            \Log::error('Mobile settle absence failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Could not settle those days.'], 500);
        }
    }

    /**
     * Manager: hide the absence banner until next month. Writes the SAME per-user row the web
     * banner uses (`t_ops_alert_dismissal`), so hiding it on the phone hides it on the laptop
     * too, and it comes back next month under a new key.
     */
    public function dismissAbsenceAlert(Request $request)
    {
        if ($deny = $this->denyIfNotManager($request)) return $deny;
        $v = Validator::make($request->all(), ['alert_key' => 'required|string|max:64']);
        if ($v->fails()) {
            return response()->json(['success' => false, 'message' => $v->errors()->first()], 422);
        }
        try {
            if (\Illuminate\Support\Facades\Schema::hasTable('t_ops_alert_dismissal')) {
                \Illuminate\Support\Facades\DB::table('t_ops_alert_dismissal')->updateOrInsert(
                    ['user_id' => $request->user()->id, 'alert_key' => (string) $request->alert_key],
                    ['dismissed_at' => now()]
                );
            }
            return response()->json(['success' => true]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => 'Could not hide that.'], 500);
        }
    }

    /** Manager: set an employee's base salary. */
    public function managerSetSalary(Request $request)
    {
        if ($deny = $this->denyIfNotManager($request)) return $deny;
        $v = Validator::make($request->all(), [
            'user_id' => 'required|integer|exists:t_sys_user,id',
            'base_salary' => 'required|numeric|min:0|max:100000000',
        ]);
        if ($v->fails()) {
            return response()->json(['success' => false, 'message' => $v->errors()->first()], 422);
        }
        try {
            (new PayrollService())->setBaseSalary((int) $request->user_id, (float) $request->base_salary, (int) $request->user()->id);
            return response()->json(['success' => true, 'message' => 'Salary saved.']);
        } catch (\Throwable $e) {
            \Log::error('Mobile setSalary failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Could not save the salary.'], 500);
        }
    }

    /** Manager: give an advance. */
    public function managerGiveAdvance(Request $request)
    {
        if ($deny = $this->denyIfNotManager($request)) return $deny;
        $v = Validator::make($request->all(), [
            'user_id' => 'required|integer|exists:t_sys_user,id',
            'amount'  => 'required|numeric|min:1|max:100000000',
            'funding' => 'required|in:cash,online',
            'bank_id' => 'nullable|integer',
            'note'    => 'nullable|string|max:255',
            // Same optional pair the web modal sends. The installed APK sends neither, so it
            // keeps behaving exactly as before (current month, money moved today) — no APK
            // is needed for any of this; the fields are here for when one is next built.
            // ⚠ ARRAY syntax — a rule STRING splits on '|', which this regex contains. See the
            // web PayrollController for the 500-HTML-page failure that causes.
            'payroll_month' => ['nullable', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'money_date'    => ['nullable', 'date'],
        ]);
        if ($v->fails()) {
            return response()->json(['success' => false, 'message' => $v->errors()->first()], 422);
        }
        $res = (new PayrollService())->giveAdvance(
            (int) $request->user_id, (float) $request->amount, $request->funding,
            $request->bank_id ? (int) $request->bank_id : null, $request->note, (int) $request->user()->id,
            $request->payroll_month, $request->money_date
        );
        return response()->json($res, !empty($res['success']) ? 200 : 422);
    }

    /** Manager: pay the selected rows. */
    public function managerPay(Request $request)
    {
        if ($deny = $this->denyIfNotManager($request)) return $deny;
        $v = Validator::make($request->all(), [
            'month'   => ['required', 'regex:/^\d{4}-\d{2}$/'],
            'funding' => 'required|in:cash,online',
            'bank_id' => 'nullable|integer',
            'items'   => 'required|array|min:1',
            'items.*.user_id' => 'required|integer',
            'items.*.late_deduction' => 'nullable|numeric|min:0',
            'items.*.skip_overtime' => 'nullable|boolean',
            'items.*.skip_late_leave' => 'nullable|boolean',
        ]);
        if ($v->fails()) {
            return response()->json(['success' => false, 'message' => $v->errors()->first()], 422);
        }
        if ($request->funding === 'online' && !$request->bank_id) {
            return response()->json(['success' => false, 'message' => 'Choose the bank you are paying from.'], 422);
        }

        $r = (new PayrollService())->payMany(
            $request->items, $request->month, $request->funding,
            $request->bank_id ? (int) $request->bank_id : null, (int) $request->user()->id
        );
        $msg = $r['paid'] . ' paid (Rs ' . number_format($r['total']) . ')'
            . ($r['skipped'] ? ', ' . $r['skipped'] . ' skipped' : '')
            . ($r['failed'] ? ', ' . $r['failed'] . ' failed' : '') . '.';

        return response()->json([
            'success' => $r['paid'] > 0 || ($r['failed'] === 0),
            'message' => $msg,
            'paid' => $r['paid'], 'skipped' => $r['skipped'], 'failed' => $r['failed'], 'total' => $r['total'],
        ]);
    }

    /**
     * Leave balance + dated/attributed adjustment history for a user.
     * Self is always allowed (no manager permission); viewing SOMEONE ELSE requires manage_payroll.
     * Shared by the rider self-view and the manager grid so the numbers can't disagree.
     */
    public function leaveHistory(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Not authenticated.'], 401);
        }
        $targetId = (int) ($request->input('user_id') ?: $user->id);
        if ($targetId !== (int) $user->id && !$user->hasMobilePermission('manage_payroll')) {
            return response()->json(['success' => false, 'message' => "You don't have access to that."], 403);
        }
        try {
            $svc = new \App\Services\HR\LeavePolicyService();
            return response()->json([
                'success' => true,
                'balance' => $svc->balance($targetId),
                'adjustments' => $svc->adjustments($targetId),
            ]);
        } catch (\Throwable $e) {
            \Log::error('Mobile leaveHistory failed', ['user_id' => $targetId, 'error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Could not load leave history.'], 500);
        }
    }

    /** Rider: my own salary for a month (self — no manager permission needed). */
    public function mySalary(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Not authenticated.'], 401);
        }
        $month = $this->normMonth($request->input('month'));
        try {
            $svc = new PayrollService();
            $row = $svc->computeRow((int) $user->id, $month);
            // A custom-schedule employee can have SEVERAL payment rows in one pay_month
            // (one per date range) — aggregate instead of showing an arbitrary row.
            $pays = \Illuminate\Support\Facades\DB::table('t_hr_payroll_payment')
                ->where('user_id', $user->id)->where('pay_month', $month)
                ->where('status', 'paid')->orderBy('paid_at')->get();
            $last = $pays->last();
            $row['paid'] = $last !== null;
            $row['paid_net'] = $last ? (float) $pays->sum('net_salary') : null;
            $row['paid_at'] = $last ? (string) $last->paid_at : null;
            $row['paid_funding'] = $last ? $last->funding : null;
            return response()->json([
                'success' => true,
                'month' => $month,
                'month_label' => date('F Y', strtotime($month . '-01')),
                'row' => $row,
            ]);
        } catch (\Throwable $e) {
            \Log::error('Mobile mySalary failed', ['user_id' => $user->id, 'month' => $month, 'error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Could not load your salary.'], 500);
        }
    }
}
