<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Services\CRM\CustomerCreditReportService;
use App\Services\CustomerCreditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Customer Balances — the audit screen (Sep-2026).
 *
 * READ ONLY. Every correction offered on this page (approve / reject / remove
 * one entry / clear to zero / record extra) posts to the EXISTING
 * CustomerCreditController endpoints, which is why nothing here writes: there
 * must be exactly one implementation of "change a balance" in the system, and
 * it is the one the customer panel already used.
 *
 * The page is deliberately visible to anyone who can see customers — a manager
 * has to be able to LOOK at the money — while the buttons follow the same
 * approver rule as everywhere else (userCanAutoApproveGrant: L2, or the
 * Shabib/Taimur pair). The server re-checks on every action, so showing a
 * button is never what grants the right.
 */
class CustomerCreditReportController extends Controller
{
    public function __construct(
        private CustomerCreditReportService $report,
        private CustomerCreditService $credit
    ) {
    }

    /** GET /customers/balances */
    public function index(Request $request)
    {
        $overview = $this->report->overview(
            $request->input('from'),
            $request->input('to')
        );

        return view('pages.customers.balances', [
            'overview'   => $overview,
            'canManage'  => $this->credit->userCanAutoApproveGrant(auth()->user()),
            'actors'     => $this->actors(),
            'tableReady' => $this->credit->tableReady(),
        ]);
    }

    /** GET /customers/balances/data — Tab A */
    public function balances(Request $request)
    {
        $filters = [
            'min'          => $this->nullableFloat($request->input('min')),
            'max'          => $this->nullableFloat($request->input('max')),
            'added_by'     => $request->input('added_by') ?: null,
            'days'         => $request->input('days') ?: null,
            'flagged'      => $request->boolean('flagged'),
            'search'       => $request->input('search'),
            'sort'         => $request->input('sort', 'balance_desc'),
            'include_zero' => $request->boolean('include_zero'),
        ];

        return response()->json([
            'success' => true,
            'data'    => $this->report->balances(
                $filters,
                max(1, (int) $request->input('page', 1)),
                $this->perPage($request, 25, 100)
            ),
        ]);
    }

    /** GET /customers/balances/activity — Tab B */
    public function activity(Request $request)
    {
        return response()->json([
            'success' => true,
            'data'    => $this->report->activity(
                $this->activityFilters($request),
                max(1, (int) $request->input('page', 1)),
                $this->perPage($request, 50, 200)
            ),
        ]);
    }

    /** GET /customers/balances/daily — Tab C */
    public function daily(Request $request)
    {
        return response()->json([
            'success' => true,
            'data'    => $this->report->daily($request->input('from'), $request->input('to')),
        ]);
    }

    /** GET /customers/balances/overview — the totals strip, refreshed after a correction */
    public function overview(Request $request)
    {
        return response()->json([
            'success' => true,
            'data'    => $this->report->overview($request->input('from'), $request->input('to')),
        ]);
    }

    /**
     * GET /customers/balances/export — the filtered activity log as CSV.
     *
     * Streamed, and built through the same activity() the screen uses, so the
     * sheet a manager mails around can never disagree with what they saw.
     */
    public function export(Request $request)
    {
        $rows     = $this->report->activityCsv($this->activityFilters($request));
        $filename = 'customer-balances-' . now()->format('Y-m-d-Hi') . '.csv';

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            // BOM so Excel opens the customer names in UTF-8 rather than mojibake.
            fwrite($out, "\xEF\xBB\xBF");
            foreach ($rows as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, $filename, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    // =====================================================================

    private function activityFilters(Request $request): array
    {
        return [
            'from'        => $request->input('from'),
            'to'          => $request->input('to'),
            'type'        => $request->input('type'),
            'source'      => $request->input('source'),
            'status'      => $request->input('status'),
            'actor'       => $request->input('actor') ?: null,
            'customer_id' => $request->input('customer_id') ?: null,
            'order_id'    => $request->input('order_id') ?: null,
            'flagged'     => $request->boolean('flagged'),
        ];
    }

    /** Everyone who has ever touched a balance — for the "by whom" dropdowns. */
    private function actors(): array
    {
        if (!$this->credit->tableReady()) {
            return [];
        }

        $ids = DB::table('t_crm_customer_credit')
            ->select('created_by')->distinct()->pluck('created_by')
            ->merge(DB::table('t_crm_customer_credit')->select('approved_by')->distinct()->pluck('approved_by'))
            ->merge(DB::table('t_crm_customer_credit')->select('voided_by')->distinct()->pluck('voided_by'))
            ->filter()->unique()->values()->all();

        if (empty($ids)) {
            return [];
        }

        return DB::table('t_sys_user')->whereIn('id', $ids)
            ->orderBy('fullname')
            ->pluck('fullname', 'id')
            ->all();
    }

    private function nullableFloat($value): ?float
    {
        return ($value === null || $value === '') ? null : (float) $value;
    }

    private function perPage(Request $request, int $default, int $max): int
    {
        $n = (int) $request->input('per_page', $default);

        return max(1, min($n ?: $default, $max));
    }
}
