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
            'funding' => $svc->fundingOptions(),
        ]);
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
            (new PayrollService())->setBaseSalary((int) $request->user_id, (float) $request->base_salary, (int) auth()->id());
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
