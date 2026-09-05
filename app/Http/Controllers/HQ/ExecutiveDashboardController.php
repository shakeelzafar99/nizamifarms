<?php

namespace App\Http\Controllers\HQ;

use App\Http\Controllers\Controller;
use App\Services\HQ\ExecutiveClosingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

/**
 * HQ Executive Dashboard (Phase 1) — the CEO "Monthly Closing" view.
 *
 * Purely a READ-ONLY reporting surface. Every method returns either the Blade
 * shell or cached JSON from ExecutiveClosingService (SELECT-only). No
 * operational route/controller is touched by this class.
 *
 * Access: same boundary as the existing /dashboard — any authenticated user
 * EXCEPT the invoices-only supervisor logins. (The legacy dashboard already
 * shows profit/expense/vendor cards to this same audience, so this is parity,
 * not a new disclosure.) Tighten to owner-only later if desired.
 */
class ExecutiveDashboardController extends Controller
{
    public function __construct(private ExecutiveClosingService $svc)
    {
    }

    /** Render the dashboard shell. */
    public function index(Request $request)
    {
        $this->guard();
        $user = Auth::user();
        $now = Carbon::now();
        return view('pages.hq.executive', [
            'user'        => $user,
            'currentYear' => (int) $now->year,
            'currentMonth' => (int) $now->month,
        ]);
    }

    // ---- JSON: headline blocks -----------------------------------------

    public function closing(Request $request)
    {
        $this->guard();
        [$unit, $year, $month] = $this->params($request);
        return $this->ok($this->svc->closing($unit, $year, $month, $request->boolean('fresh')));
    }

    public function trend(Request $request)
    {
        $this->guard();
        [$unit, $year, $month] = $this->params($request);
        return $this->ok($this->svc->trend($unit, $year, $month, 6, $request->boolean('fresh')));
    }

    /** Order-source split (App / Web / Manual) for the period. Unit-independent. */
    public function orderSplit(Request $request)
    {
        $this->guard();
        [$unit, $year, $month] = $this->params($request);
        return $this->ok($this->svc->orderSourceSplit($year, $month, $request->boolean('fresh')));
    }

    public function workingCapital(Request $request)
    {
        $this->guard();
        $unit = strtolower((string) $request->get('unit', 'nf'));
        return $this->ok($this->svc->workingCapital($unit, $request->boolean('fresh')));
    }

    public function growth(Request $request)
    {
        $this->guard();
        [$unit, $year, $month] = $this->params($request);
        return $this->ok($this->svc->growth($unit, $year, $month));
    }

    public function recency(Request $request)
    {
        $this->guard();
        $band = (string) $request->get('band', '61-90');
        return $this->ok($this->svc->recencyList($band));
    }

    // ---- JSON: drill-downs ---------------------------------------------

    public function revenueDaily(Request $request)
    {
        $this->guard();
        [$unit, $year, $month] = $this->params($request);
        return $this->ok($this->svc->revenueDaily($unit, $year, $month));
    }

    public function revenueOrders(Request $request)
    {
        $this->guard();
        $unit = $request->get('unit', 'all');
        $date = $request->get('date', Carbon::now()->toDateString());
        // Basic date sanity — fall back to today on anything unparsable.
        try { Carbon::parse($date); } catch (\Throwable $e) { $date = Carbon::now()->toDateString(); }
        return $this->ok($this->svc->revenueOrdersForDay($unit, $date));
    }

    public function vendors(Request $request)
    {
        $this->guard();
        [$unit, $year, $month] = $this->params($request);
        return $this->ok($this->svc->vendorBreakdown($unit, $year, $month));
    }

    public function vendorDetail(Request $request)
    {
        $this->guard();
        [$unit, $year, $month] = $this->params($request);
        $accountId = (int) $request->get('account_id', 0);
        return $this->ok($this->svc->vendorDetail($accountId, $year, $month));
    }

    public function expenses(Request $request)
    {
        $this->guard();
        [$unit, $year, $month] = $this->params($request);
        return $this->ok($this->svc->expenseByCategory($unit, $year, $month));
    }

    public function expenseDetail(Request $request)
    {
        $this->guard();
        [$unit, $year, $month] = $this->params($request);
        $category = (string) $request->get('category', '');
        return $this->ok($this->svc->expenseDetail($unit, $year, $month, $category));
    }

    public function salaries(Request $request)
    {
        $this->guard();
        [$unit, $year, $month] = $this->params($request);
        return $this->ok($this->svc->salaryByEmployee($unit, $year, $month));
    }

    /** Salaries Level 2 — the individual payments behind one employee's grouped row. */
    public function salaryDetail(Request $request)
    {
        $this->guard();
        [$unit, $year, $month] = $this->params($request);
        // user_id identifies the employee; `employee` is the fallback for a legacy
        // salary slip that carries no user (Level 1 keys those by name).
        $userId = $request->filled('user_id') ? (int) $request->get('user_id') : null;
        return $this->ok($this->svc->salaryEmployeeDetail(
            $unit, $year, $month, $userId, (string) $request->get('employee', '')
        ));
    }

    public function customers(Request $request)
    {
        $this->guard();
        [$unit, $year, $month] = $this->params($request);
        return $this->ok($this->svc->customersList($unit, $year, $month));
    }

    public function receivables(Request $request)
    {
        $this->guard();
        $unit = strtolower((string) $request->get('unit', 'nf'));
        $type = trim((string) $request->get('type', ''));
        // No type → Level 1 summary (by receivable type + aging); type → the
        // Level 2 customer list behind that type.
        return $this->ok($type !== ''
            ? $this->svc->receivablesByType($unit, $type)
            : $this->svc->receivablesSummary($unit));
    }

    public function payables(Request $request)
    {
        $this->guard();
        return $this->ok($this->svc->payablesByVendor());
    }

    public function assets(Request $request)
    {
        $this->guard();
        $unit = strtolower((string) $request->get('unit', 'all'));
        return $this->ok($this->svc->assetsList($unit));
    }

    /** What was bought this month (the P&L "Assets bought" box) — not the register. */
    public function assetPurchases(Request $request)
    {
        $this->guard();
        [$unit, $year, $month] = $this->params($request);
        return $this->ok($this->svc->assetPurchasesList($unit, $year, $month));
    }

    // ---- Products tab (Phase 3) -----------------------------------------

    public function products(Request $request)
    {
        $this->guard();
        [$unit, $year, $month] = $this->params($request);
        return $this->ok($this->svc->products($unit, $year, $month, $request->boolean('fresh')));
    }

    public function productsCategory(Request $request)
    {
        $this->guard();
        [$unit, $year, $month] = $this->params($request);
        $category = (string) $request->get('category', '');
        return $this->ok($this->svc->productsCategoryList($unit, $year, $month, $category));
    }

    public function productDaily(Request $request)
    {
        $this->guard();
        [$unit, $year, $month] = $this->params($request);
        $pid = (int) $request->get('product_id', 0);
        return $this->ok($this->svc->productDaily($unit, $year, $month, $pid));
    }

    public function missingInvoices(Request $request)
    {
        $this->guard();
        return $this->ok($this->svc->missingInvoices());
    }

    // ---- helpers --------------------------------------------------------

    /** Parse & clamp unit/year/month request params. */
    private function params(Request $request): array
    {
        $unit  = strtolower((string) $request->get('unit', 'all'));
        $year  = (int) $request->get('year', Carbon::now()->year);
        $month = (int) $request->get('month', Carbon::now()->month);
        if ($year < 2020 || $year > 2100) $year = Carbon::now()->year;
        if ($month < 1 || $month > 12)   $month = Carbon::now()->month;
        return [$unit, $year, $month];
    }

    private function ok($data)
    {
        return response()->json(['success' => true, 'data' => $data]);
    }

    /**
     * Block the invoices-only supervisor logins (same gate as the sidebar
     * Dashboards link). Everyone else authenticated may view.
     */
    private function guard(): void
    {
        $uid = Auth::id();
        if (!$uid) {
            abort(403);
        }
        $invoicesOnly = DB::table('t_sys_user_role as ur')
            ->join('t_sys_role as r', 'r.id', '=', 'ur.role_id')
            ->where('ur.user_id', $uid)
            ->whereRaw('LOWER(r.urole_name) IN (?, ?)', ['supervisor', 'supervisor 2'])
            ->exists();
        if ($invoicesOnly) {
            abort(403, 'Not authorised for the executive dashboard.');
        }
    }
}
