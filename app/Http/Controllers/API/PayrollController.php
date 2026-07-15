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
            ]);
        } catch (\Throwable $e) {
            \Log::error('Mobile payroll month failed', ['month' => $month, 'error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Could not build payroll.'], 500);
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
        ]);
        if ($v->fails()) {
            return response()->json(['success' => false, 'message' => $v->errors()->first()], 422);
        }
        $res = (new PayrollService())->giveAdvance(
            (int) $request->user_id, (float) $request->amount, $request->funding,
            $request->bank_id ? (int) $request->bank_id : null, $request->note, (int) $request->user()->id
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
            $p = \Illuminate\Support\Facades\DB::table('t_hr_payroll_payment')
                ->where('user_id', $user->id)->where('pay_month', $month)->first();
            $row['paid'] = $p !== null;
            $row['paid_net'] = $p ? (float) $p->net_salary : null;
            $row['paid_at'] = $p ? (string) $p->paid_at : null;
            $row['paid_funding'] = $p ? $p->funding : null;
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
