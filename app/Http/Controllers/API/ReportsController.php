<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\FIN\LedgerModel;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ReportsController extends Controller
{
    /**
     * Returns the SQL fragment that identifies a Qurbani order for
     * use inside raw queries against `t_crm_prod_order`. Mirrors
     * `QurbaniFinanceFilter::applyToOrderQuery` exactly so the
     * Reports page reconciles with the Qurbani Expenses tab.
     *
     * @param string $alias Alias of the orders table, e.g. 'o'.
     */
    private function qurbaniOrderSqlClause(string $alias = 'o'): string
    {
        return "(
            ($alias.qurbani_day IS NOT NULL OR $alias.qurbani_slot IS NOT NULL OR $alias.qurbani_region IS NOT NULL OR $alias.qurbani_delivery_type IS NOT NULL)
            OR EXISTS (
                SELECT 1 FROM t_crm_prod_order_line_item li
                INNER JOIN t_crm_prod_product p ON p.id = li.product_id
                WHERE li.order_id = $alias.id
                  AND LOWER(COALESCE(p.attribute_1, '')) = 'qurbani'
            )
        )";
    }

    /**
     * SQL fragment for Qurbani-tagged ledger rows. A row is Qurbani
     * if its `request_id` resolves to a request whose category is
     * 'qurbani' OR its `order_id` resolves to a Qurbani order.
     */
    private function qurbaniLedgerSqlClause(string $alias = ''): string
    {
        $a = $alias === '' ? '' : ($alias . '.');
        $orderClause = $this->qurbaniOrderSqlClause('qord');
        return "(
            EXISTS (
                SELECT 1 FROM t_req_master qr
                INNER JOIN t_req_category qc ON qc.id = qr.category_id
                WHERE qr.id = {$a}request_id AND qc.category_code = 'qurbani'
            )
            OR EXISTS (
                SELECT 1 FROM t_crm_prod_order qord
                WHERE qord.id = {$a}order_id AND $orderClause
            )
        )";
    }

    /**
     * True if the current user can see Khaas figures in the report.
     */
    private function canViewKhaas(): bool
    {
        try {
            $u = \Auth::user();
            if (!$u) return false;
            $u->load(['roles.mobilePermissions']);
            $perms = method_exists($u, 'getMobilePermissions') ? $u->getMobilePermissions() : [];
            return in_array('access_khaas_mode', $perms, true);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Resolve NF / Khaas business unit ids once per request. Returns
     * `[nfBuId, khaasBuId]`. NULL business_unit_id is treated as NF
     * downstream (legacy ledger rows pre-date the Khaas split).
     *
     * @return array{0:int,1:int}
     */
    private function buIds(): array
    {
        static $cached = null;
        if ($cached !== null) return $cached;
        $nf    = (int) (\App\Models\FIN\BusinessUnitModel::where('code', 'NF')->value('id') ?? 0);
        $khaas = (int) (\App\Models\FIN\BusinessUnitModel::where('code', 'KHAAS')->value('id') ?? 0);
        $cached = [$nf, $khaas];
        return $cached;
    }

    /**
     * Get Monthly Summary for the last N months.
     *
     * May-2026 (Phase 5) — the Monthly Reports payload now segregates
     * Qurbani activity from the rest of the business and splits NF
     * vs Khaas on both expenses and vendor purchases:
     *
     *   - `invoices` excludes Qurbani delivered orders (Qurbani is
     *     an annual event with its own dedicated screen and rolling
     *     it into a single month would distort the operating P&L).
     *   - `expenses_nf` / `expenses_khaas` and
     *     `vendor_purchases_nf` / `vendor_purchases_khaas` split by
     *     `t_fin_ledger.business_unit_id` (NULL = NF).
     *   - Khaas figures are zeroed out for users without
     *     `access_khaas_mode`.
     *   - `profit` = invoices − (NF+Khaas expenses) − (NF+Khaas
     *     vendor purchases). Qurbani is intentionally NOT included.
     *   - Per-year Qurbani totals are returned in `qurbani_yearly`
     *     for the view to render a "Qurbani YYYY" header above
     *     each year's months.
     *
     * Query param `months` controls the window length (default 12,
     * max 60). Used by the "Load more years" button.
     */
    public function getMonthlySummary(Request $request)
    {
        try {
            $months  = [];
            $date    = Carbon::now()->startOfMonth();
            $window  = (int) $request->input('months', 12);
            if ($window < 1) $window = 12;
            if ($window > 60) $window = 60;

            $canViewKhaas         = $this->canViewKhaas();
            [$nfBuId, $khaasBuId] = $this->buIds();
            $qurbaniOrder         = $this->qurbaniOrderSqlClause('o');
            $qurbaniLedger        = $this->qurbaniLedgerSqlClause();

            // Track years that appear in the window — used after
            // the loop to build the per-year Qurbani section. We
            // do NOT compute Qurbani revenue/expense per-month here
            // because Qurbani revenue is "booked" (based on order
            // creation date) and Qurbani expenses are reported
            // year-wide, not month-wide. Building from per-month
            // buckets would also undercount if the user's window
            // doesn't span a full year.
            $yearsInWindow = [];

            for ($i = 0; $i < $window; $i++) {
                $startDate = $date->copy()->format('Y-m-d');
                $endDate   = $date->copy()->endOfMonth()->format('Y-m-d');
                $monthKey  = $date->format('Y-m');
                $monthName = $date->format('F Y');
                $year      = (int) $date->format('Y');

                // Invoices — non-Qurbani delivered orders this month.
                // Qurbani delivered orders are aggregated separately
                // into the "Qurbani YYYY" yearly section.
                $invoiceData = DB::selectOne("
                    SELECT
                        COALESCE(SUM(o.total_price), 0) as total,
                        COUNT(DISTINCT o.id) as count,
                        COALESCE(SUM(CASE WHEN o.tip_amount > 0 THEN o.tip_amount ELSE 0 END), 0) as tips_total,
                        COUNT(DISTINCT CASE WHEN o.tip_amount > 0 THEN o.id END) as tips_count
                    FROM t_crm_prod_order o
                    INNER JOIN (
                        SELECT order_id, MIN(changed_at) as delivered_at
                        FROM t_crm_order_status_history
                        WHERE status_code = 'delivered'
                        GROUP BY order_id
                    ) h ON o.id = h.order_id
                    WHERE h.delivered_at >= ? AND h.delivered_at <= ?
                      AND (o.external_source IS NULL OR o.external_source != 'shopify')
                      AND NOT $qurbaniOrder
                ", [$startDate . ' 00:00:00', $endDate . ' 23:59:59']);

                // Approved expenses, segregated into NF and Khaas
                // (Qurbani is excluded entirely from the per-month
                // bucket; it's computed separately per year after
                // the loop). NULL business_unit_id counts as NF.
                $expenseData = DB::selectOne("
                    SELECT
                        COALESCE(SUM(CASE WHEN NOT $qurbaniLedger AND (business_unit_id IS NULL OR business_unit_id = ?) THEN amount ELSE 0 END), 0) as nf_total,
                        COALESCE(SUM(CASE WHEN NOT $qurbaniLedger AND business_unit_id = ? THEN amount ELSE 0 END), 0) as khaas_total,
                        SUM(CASE WHEN NOT $qurbaniLedger AND (business_unit_id IS NULL OR business_unit_id = ?) THEN 1 ELSE 0 END) as nf_count,
                        SUM(CASE WHEN NOT $qurbaniLedger AND business_unit_id = ? THEN 1 ELSE 0 END) as khaas_count
                    FROM t_fin_ledger
                    WHERE transaction_date >= ? AND transaction_date <= ?
                      AND transaction_type = ?
                      AND approval_status = ?
                ", [$nfBuId, $khaasBuId, $nfBuId, $khaasBuId, $startDate, $endDate, LedgerModel::TYPE_EXPENSE, LedgerModel::STATUS_APPROVED]);

                $purchaseData = DB::selectOne("
                    SELECT
                        COALESCE(SUM(CASE WHEN NOT $qurbaniLedger AND (business_unit_id IS NULL OR business_unit_id = ?) THEN amount ELSE 0 END), 0) as nf_total,
                        COALESCE(SUM(CASE WHEN NOT $qurbaniLedger AND business_unit_id = ? THEN amount ELSE 0 END), 0) as khaas_total,
                        SUM(CASE WHEN NOT $qurbaniLedger AND (business_unit_id IS NULL OR business_unit_id = ?) THEN 1 ELSE 0 END) as nf_count,
                        SUM(CASE WHEN NOT $qurbaniLedger AND business_unit_id = ? THEN 1 ELSE 0 END) as khaas_count
                    FROM t_fin_ledger
                    WHERE transaction_date >= ? AND transaction_date <= ?
                      AND transaction_type = ?
                      AND approval_status = ?
                ", [$nfBuId, $khaasBuId, $nfBuId, $khaasBuId, $startDate, $endDate, LedgerModel::TYPE_VENDOR_PURCHASE, LedgerModel::STATUS_APPROVED]);

                $assetData = DB::selectOne("
                    SELECT
                        COALESCE(SUM(amount), 0) as total,
                        COUNT(*) as count
                    FROM t_fin_ledger
                    WHERE transaction_date >= ? AND transaction_date <= ?
                      AND transaction_type = 'asset_purchase'
                      AND approval_status = ?
                ", [$startDate, $endDate, LedgerModel::STATUS_APPROVED]);

                $invoices       = round($invoiceData->total ?? 0, 2);
                $expensesNf     = round($expenseData->nf_total ?? 0, 2);
                $expensesKhaas  = $canViewKhaas ? round($expenseData->khaas_total ?? 0, 2) : 0.0;
                $vendorNf       = round($purchaseData->nf_total ?? 0, 2);
                $vendorKhaas    = $canViewKhaas ? round($purchaseData->khaas_total ?? 0, 2) : 0.0;
                $assetPurchases = round($assetData->total ?? 0, 2);

                $expensesTotal = $expensesNf + $expensesKhaas;
                $vendorTotal   = $vendorNf + $vendorKhaas;
                // Profit excludes Qurbani entirely on both legs so a
                // single big Qurbani delivery month doesn't distort
                // the operating-business P&L curve.
                $profit = round($invoices - $expensesTotal - $vendorTotal, 2);

                // Track which years appear in the window so we can
                // build the Qurbani per-year section after the loop.
                $yearsInWindow[$year] = true;

                $months[] = [
                    'month_key'              => $monthKey,
                    'month_name'             => $monthName,
                    'year'                   => $year,
                    'invoices'               => $invoices,
                    'invoice_count'          => (int) ($invoiceData->count ?? 0),
                    'tips'                   => round($invoiceData->tips_total ?? 0, 2),
                    'tips_count'             => (int) ($invoiceData->tips_count ?? 0),
                    // Phase 5 — split fields. `expenses` / `vendor_purchases` kept
                    // for backward compatibility with old mobile clients.
                    'expenses'               => round($expensesTotal, 2),
                    'expense_count'          => (int) ($expenseData->nf_count ?? 0) + (int) ($expenseData->khaas_count ?? 0),
                    'expenses_nf'            => $expensesNf,
                    'expenses_khaas'         => $expensesKhaas,
                    'expense_count_nf'       => (int) ($expenseData->nf_count ?? 0),
                    'expense_count_khaas'    => (int) ($expenseData->khaas_count ?? 0),
                    'vendor_purchases'       => round($vendorTotal, 2),
                    'vendor_purchase_count'  => (int) ($purchaseData->nf_count ?? 0) + (int) ($purchaseData->khaas_count ?? 0),
                    'vendor_purchases_nf'    => $vendorNf,
                    'vendor_purchases_khaas' => $vendorKhaas,
                    'asset_purchases'        => $assetPurchases,
                    'asset_purchase_count'   => (int) ($assetData->count ?? 0),
                    'profit'                 => $profit,
                ];

                $date->subMonth();
            }

            // Build the Qurbani per-year section using direct
            // year-scoped queries so the numbers reconcile 1-to-1
            // with the dedicated Qurbani Expenses tab:
            //   - Revenue = "booked" (every non-cancelled qurbani
            //     order placed in the year), with a Paid / Pending
            //     split from `t_crm_prod_order.total_paid`.
            //   - Expenses & Vendor Purchases = full-year ledger
            //     totals (regardless of whether the months window
            //     covers the whole year).
            $qurbaniYearly = [];
            foreach (array_keys($yearsInWindow) as $year) {
                $year      = (int) $year;
                $yearStart = sprintf('%04d-01-01', $year);
                $yearEnd   = sprintf('%04d-12-31', $year);

                // Booked revenue + collection split.
                $bookedQuery = DB::table('t_crm_prod_order as o')
                    ->whereRaw('YEAR(o.created_at) = ?', [$year])
                    ->whereRaw("LOWER(COALESCE(o.order_status, '')) <> 'cancelled'")
                    ->where(function ($q) {
                        $q->whereNull('o.external_source')
                          ->orWhere('o.external_source', '!=', 'shopify');
                    });
                \App\Services\QurbaniFinanceFilter::applyToOrderQuery(
                    $bookedQuery, 'o', \App\Services\QurbaniFinanceFilter::MODE_INCLUDE
                );
                $totals = $bookedQuery->selectRaw('COALESCE(SUM(o.total_price), 0) as booked, COALESCE(SUM(o.total_paid), 0) as paid')->first();
                $booked  = (float) ($totals->booked ?? 0);
                $paid    = (float) ($totals->paid ?? 0);
                $pending = max(0.0, $booked - $paid);

                // Full-year Qurbani expenses (ledger).
                $expQuery = DB::table('t_fin_ledger as l')
                    ->where('l.transaction_type', LedgerModel::TYPE_EXPENSE)
                    ->where('l.approval_status', LedgerModel::STATUS_APPROVED)
                    ->whereBetween('l.transaction_date', [$yearStart, $yearEnd]);
                \App\Services\QurbaniFinanceFilter::applyToLedgerQuery(
                    $expQuery, 'l', \App\Services\QurbaniFinanceFilter::MODE_INCLUDE
                );
                $qurbaniExp = (float) $expQuery->sum('l.amount');

                // Full-year Qurbani vendor purchases (ledger).
                $vQuery = DB::table('t_fin_ledger as l')
                    ->where('l.transaction_type', LedgerModel::TYPE_VENDOR_PURCHASE)
                    ->where('l.approval_status', LedgerModel::STATUS_APPROVED)
                    ->whereBetween('l.transaction_date', [$yearStart, $yearEnd]);
                \App\Services\QurbaniFinanceFilter::applyToLedgerQuery(
                    $vQuery, 'l', \App\Services\QurbaniFinanceFilter::MODE_INCLUDE
                );
                $qurbaniVendor = (float) $vQuery->sum('l.amount');

                $qurbaniYearly[] = [
                    'year'             => $year,
                    // `revenue` kept as alias for any consumer still
                    // reading the legacy field; mirrors `booked`.
                    'revenue'          => round($booked, 2),
                    'booked'           => round($booked, 2),
                    'paid'             => round($paid, 2),
                    'pending'          => round($pending, 2),
                    'paid_pct'         => $booked > 0 ? (int) round(($paid / $booked) * 100) : 0,
                    'expenses'         => round($qurbaniExp, 2),
                    'vendor_purchases' => round($qurbaniVendor, 2),
                    // Net is computed off Booked (committed revenue
                    // for the season) so the headline matches the
                    // Qurbani Expenses tab Net.
                    'net'              => round($booked - $qurbaniExp - $qurbaniVendor, 2),
                ];
            }
            // Newest year first.
            usort($qurbaniYearly, fn ($a, $b) => $b['year'] <=> $a['year']);

            return response()->json([
                'success'         => true,
                'data'            => $months,
                'months_returned' => $window,
                'can_view_khaas'  => $canViewKhaas,
                'qurbani_yearly'  => $qurbaniYearly,
            ]);
        } catch (\Exception $e) {
            Log::error('Monthly summary error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'error'   => $e->getMessage(),
            ], 500);
        }
    }
    
    /**
     * Get Month Details (drill-down)
     * Returns transactions grouped by date for invoices, expenses, and vendor_purchases
     */
    public function getMonthDetails(Request $request)
    {
        try {
            $monthKey = $request->get('month', Carbon::now()->format('Y-m'));
            $startDate = Carbon::parse($monthKey . '-01')->startOfMonth();
            $endDate = $startDate->copy()->endOfMonth();

            // Phase 5 — drill-down also excludes Qurbani so it
            // reconciles 1-to-1 with the monthly summary card.
            $qurbaniOrder  = $this->qurbaniOrderSqlClause('o');
            $qurbaniLedger = $this->qurbaniLedgerSqlClause('l');
            $canViewKhaas         = $this->canViewKhaas();
            [$nfBuId, $khaasBuId] = $this->buIds();

            // Get invoices grouped by delivery date (excluding Qurbani).
            $invoicesRaw = DB::select("
                SELECT
                    DATE(h.delivered_at) as delivery_date,
                    o.order_number,
                    CONCAT(c.first_name, ' ', c.last_name) as customer_name,
                    o.total_price as amount
                FROM t_crm_prod_order o
                INNER JOIN (
                    SELECT order_id, MIN(changed_at) as delivered_at
                    FROM t_crm_order_status_history
                    WHERE status_code = 'delivered'
                    GROUP BY order_id
                ) h ON o.id = h.order_id
                LEFT JOIN t_crm_prod_customer c ON o.customer_id = c.id
                WHERE h.delivered_at >= ? AND h.delivered_at <= ?
                  AND (o.external_source IS NULL OR o.external_source != 'shopify')
                  AND NOT $qurbaniOrder
                ORDER BY h.delivered_at DESC
            ", [$startDate->format('Y-m-d 00:00:00'), $endDate->format('Y-m-d 23:59:59')]);
            
            // Group invoices by date
            $invoicesByDate = [];
            $invoiceTotal = 0;
            $invoiceCount = 0;
            foreach ($invoicesRaw as $inv) {
                $date = $inv->delivery_date;
                if (!isset($invoicesByDate[$date])) {
                    $invoicesByDate[$date] = ['date' => $date, 'items' => [], 'total' => 0];
                }
                $invoicesByDate[$date]['items'][] = [
                    'order_number' => $inv->order_number,
                    'customer_name' => $inv->customer_name,
                    'amount' => round($inv->amount, 2),
                ];
                $invoicesByDate[$date]['total'] += $inv->amount;
                $invoiceTotal += $inv->amount;
                $invoiceCount++;
            }
            
            // Get tips from delivered invoices (excluding Qurbani).
            $tipsRaw = DB::select("
                SELECT
                    DATE(h.delivered_at) as delivery_date,
                    o.order_number,
                    o.tip_amount,
                    o.total_price,
                    COALESCE(NULLIF(TRIM(o.name), ''), CONCAT(c.first_name, ' ', c.last_name)) as customer_name,
                    r.fullname as rider_name
                FROM t_crm_prod_order o
                INNER JOIN (
                    SELECT order_id, MIN(changed_at) as delivered_at
                    FROM t_crm_order_status_history
                    WHERE status_code = 'delivered'
                    GROUP BY order_id
                ) h ON o.id = h.order_id
                LEFT JOIN t_crm_prod_customer c ON o.customer_id = c.id
                LEFT JOIN t_sys_user r ON o.assigned_rider_user_id = r.id
                WHERE h.delivered_at >= ? AND h.delivered_at <= ?
                  AND (o.external_source IS NULL OR o.external_source != 'shopify')
                  AND o.tip_amount > 0
                  AND NOT $qurbaniOrder
                ORDER BY h.delivered_at DESC
            ", [$startDate->format('Y-m-d 00:00:00'), $endDate->format('Y-m-d 23:59:59')]);
            
            $tipsTotal = 0;
            $tipsCount = 0;
            $tipsItems = [];
            foreach ($tipsRaw as $tip) {
                $tipsItems[] = [
                    'order_number' => $tip->order_number,
                    'customer_name' => $tip->customer_name ?? 'Unknown',
                    'tip_amount' => round($tip->tip_amount, 2),
                    'order_total' => round($tip->total_price, 2),
                    'rider_name' => $tip->rider_name ?? 'Unassigned',
                    'delivery_date' => $tip->delivery_date,
                ];
                $tipsTotal += $tip->tip_amount;
                $tipsCount++;
            }
            
            // Get expenses grouped by transaction date with user, category and BU.
            // Phase 5 — excludes Qurbani; tags each row with `bu_code`
            // (NF / KHAAS) so the drill-down can show the split too.
            $expensesRaw = DB::select("
                SELECT
                    l.transaction_date,
                    l.description,
                    l.amount,
                    u.fullname as created_by,
                    a.account_name as category,
                    DATE(l.created_at) as entry_date,
                    COALESCE(bu.code, 'NF') as bu_code,
                    l.business_unit_id
                FROM t_fin_ledger l
                LEFT JOIN t_sys_user u ON l.created_by = u.id
                LEFT JOIN t_fin_accounts a ON l.to_account_id = a.id
                LEFT JOIN t_fin_business_units bu ON l.business_unit_id = bu.id
                WHERE l.transaction_date >= ? AND l.transaction_date <= ?
                  AND l.transaction_type = ?
                  AND l.approval_status = ?
                  AND NOT $qurbaniLedger
                ORDER BY l.transaction_date DESC
            ", [$startDate->format('Y-m-d'), $endDate->format('Y-m-d'), LedgerModel::TYPE_EXPENSE, LedgerModel::STATUS_APPROVED]);

            // Group expenses by date with NF/Khaas split per day.
            $expensesByDate = [];
            $expensesByCategory = []; // ALSO group by expense sub-category (Salaries, Food, ...)
            $expenseTotal = 0;
            $expenseCount = 0;
            $expensesNfTotal    = 0;
            $expensesKhaasTotal = 0;
            foreach ($expensesRaw as $exp) {
                $isKhaas = ((int) ($exp->business_unit_id ?? 0)) === $khaasBuId;
                if ($isKhaas && !$canViewKhaas) {
                    continue; // hide Khaas rows for users without access
                }
                $date = $exp->transaction_date;
                if (!isset($expensesByDate[$date])) {
                    $expensesByDate[$date] = ['date' => $date, 'items' => [], 'total' => 0, 'total_nf' => 0, 'total_khaas' => 0];
                }
                $category = $exp->category;
                if ($category && strpos($category, 'Expense - ') === 0) {
                    $category = substr($category, 10);
                }
                $expensesByDate[$date]['items'][] = [
                    'description' => $exp->description,
                    'amount' => round($exp->amount, 2),
                    'user' => $exp->created_by ?? 'Unknown',
                    'category' => $category ?? 'Uncategorized',
                    'entry_date' => $exp->entry_date,
                    'bu_code' => $exp->bu_code,
                ];
                $expensesByDate[$date]['total'] += $exp->amount;

                // ALSO accumulate by expense sub-category (Salaries / Food / Maintenance …).
                $catKey = $category ?? 'Uncategorized';
                if (!isset($expensesByCategory[$catKey])) {
                    $expensesByCategory[$catKey] = ['category' => $catKey, 'total' => 0, 'total_nf' => 0, 'total_khaas' => 0, 'count' => 0, 'items' => []];
                }
                $expensesByCategory[$catKey]['items'][] = [
                    'description' => $exp->description,
                    'amount' => round($exp->amount, 2),
                    'user' => $exp->created_by ?? 'Unknown',
                    'date' => $date,
                    'entry_date' => $exp->entry_date,
                    'bu_code' => $exp->bu_code,
                ];
                $expensesByCategory[$catKey]['total'] += $exp->amount;
                $expensesByCategory[$catKey]['count']++;

                if ($isKhaas) {
                    $expensesByDate[$date]['total_khaas'] += $exp->amount;
                    $expensesKhaasTotal += $exp->amount;
                    $expensesByCategory[$catKey]['total_khaas'] += $exp->amount;
                } else {
                    $expensesByDate[$date]['total_nf'] += $exp->amount;
                    $expensesNfTotal += $exp->amount;
                    $expensesByCategory[$catKey]['total_nf'] += $exp->amount;
                }
                $expenseTotal += $exp->amount;
                $expenseCount++;
            }
            // Sort expense categories by total desc for the breakdown view.
            $expensesByCategory = array_values($expensesByCategory);
            usort($expensesByCategory, fn($a, $b) => $b['total'] <=> $a['total']);
            
            // Get vendor purchases grouped by date (excluding Qurbani),
            // tagged with NF/KHAAS bu_code for the drill-down split.
            $purchasesRaw = DB::select("
                SELECT
                    l.transaction_date,
                    l.description,
                    l.amount,
                    u.fullname as created_by,
                    a.account_name as vendor_name,
                    DATE(l.created_at) as entry_date,
                    COALESCE(bu.code, 'NF') as bu_code,
                    l.business_unit_id
                FROM t_fin_ledger l
                LEFT JOIN t_sys_user u ON l.created_by = u.id
                LEFT JOIN t_fin_accounts a ON l.to_account_id = a.id
                LEFT JOIN t_fin_business_units bu ON l.business_unit_id = bu.id
                WHERE l.transaction_date >= ? AND l.transaction_date <= ?
                  AND l.transaction_type = ?
                  AND l.approval_status = ?
                  AND NOT $qurbaniLedger
                ORDER BY l.transaction_date DESC
            ", [$startDate->format('Y-m-d'), $endDate->format('Y-m-d'), LedgerModel::TYPE_VENDOR_PURCHASE, LedgerModel::STATUS_APPROVED]);

            $purchasesByDate = [];
            $purchasesByVendor = []; // ALSO group by vendor → date (daily within each vendor)
            $purchaseTotal = 0;
            $purchaseCount = 0;
            $vendorNfTotal    = 0;
            $vendorKhaasTotal = 0;
            foreach ($purchasesRaw as $vp) {
                $isKhaas = ((int) ($vp->business_unit_id ?? 0)) === $khaasBuId;
                if ($isKhaas && !$canViewKhaas) {
                    continue;
                }
                $date = $vp->transaction_date;
                if (!isset($purchasesByDate[$date])) {
                    $purchasesByDate[$date] = ['date' => $date, 'items' => [], 'total' => 0, 'total_nf' => 0, 'total_khaas' => 0];
                }
                $vendorName = $vp->vendor_name;
                if ($vendorName && strpos($vendorName, 'Vendor - ') === 0) {
                    $vendorName = substr($vendorName, 9);
                }
                $purchasesByDate[$date]['items'][] = [
                    'description' => $vp->description,
                    'amount' => round($vp->amount, 2),
                    'user' => $vp->created_by ?? 'Unknown',
                    'vendor_name' => $vendorName ?? 'Unknown Vendor',
                    'entry_date' => $vp->entry_date,
                    'bu_code' => $vp->bu_code,
                ];
                $purchasesByDate[$date]['total'] += $vp->amount;

                // ALSO group by vendor, then by date within the vendor.
                $vkey = $vendorName ?? 'Unknown Vendor';
                if (!isset($purchasesByVendor[$vkey])) {
                    $purchasesByVendor[$vkey] = ['vendor_name' => $vkey, 'total' => 0, 'total_nf' => 0, 'total_khaas' => 0, 'count' => 0, 'by_date' => []];
                }
                if (!isset($purchasesByVendor[$vkey]['by_date'][$date])) {
                    $purchasesByVendor[$vkey]['by_date'][$date] = ['date' => $date, 'total' => 0, 'items' => []];
                }
                $purchasesByVendor[$vkey]['by_date'][$date]['items'][] = [
                    'description' => $vp->description,
                    'amount' => round($vp->amount, 2),
                    'user' => $vp->created_by ?? 'Unknown',
                    'entry_date' => $vp->entry_date,
                    'bu_code' => $vp->bu_code,
                ];
                $purchasesByVendor[$vkey]['by_date'][$date]['total'] += $vp->amount;
                $purchasesByVendor[$vkey]['total'] += $vp->amount;
                $purchasesByVendor[$vkey]['count']++;

                if ($isKhaas) {
                    $purchasesByDate[$date]['total_khaas'] += $vp->amount;
                    $vendorKhaasTotal += $vp->amount;
                    $purchasesByVendor[$vkey]['total_khaas'] += $vp->amount;
                } else {
                    $purchasesByDate[$date]['total_nf'] += $vp->amount;
                    $vendorNfTotal += $vp->amount;
                    $purchasesByVendor[$vkey]['total_nf'] += $vp->amount;
                }
                $purchaseTotal += $vp->amount;
                $purchaseCount++;
            }
            // Flatten each vendor's date map and sort vendors by total desc.
            $purchasesByVendor = array_values($purchasesByVendor);
            foreach ($purchasesByVendor as &$pv) {
                $pv['by_date'] = array_values($pv['by_date']);
            }
            unset($pv);
            usort($purchasesByVendor, fn($a, $b) => $b['total'] <=> $a['total']);
            
            // Get asset purchases grouped by transaction date
            $assetsRaw = DB::select("
                SELECT 
                    l.transaction_date,
                    l.description,
                    l.amount,
                    u.fullname as created_by,
                    DATE(l.created_at) as entry_date,
                    a.asset_name,
                    a.asset_code,
                    bu.name as business_unit
                FROM t_fin_ledger l
                LEFT JOIN t_sys_user u ON l.created_by = u.id
                LEFT JOIN t_fin_assets a ON a.ledger_transaction_id = l.id
                LEFT JOIN t_fin_business_units bu ON a.business_unit_id = bu.id
                WHERE l.transaction_date >= ? AND l.transaction_date <= ?
                AND l.transaction_type = 'asset_purchase'
                AND l.approval_status = ?
                ORDER BY l.transaction_date DESC
            ", [$startDate->format('Y-m-d'), $endDate->format('Y-m-d'), LedgerModel::STATUS_APPROVED]);
            
            // Group asset purchases by date
            $assetsByDate = [];
            $assetTotal = 0;
            $assetCount = 0;
            foreach ($assetsRaw as $asset) {
                $date = $asset->transaction_date;
                if (!isset($assetsByDate[$date])) {
                    $assetsByDate[$date] = ['date' => $date, 'items' => [], 'total' => 0];
                }
                $assetsByDate[$date]['items'][] = [
                    'description' => $asset->description,
                    'asset_name' => $asset->asset_name ?? 'Asset',
                    'asset_code' => $asset->asset_code ?? '',
                    'business_unit' => $asset->business_unit ?? 'Unknown',
                    'amount' => round($asset->amount, 2),
                    'user' => $asset->created_by ?? 'Unknown',
                    'entry_date' => $asset->entry_date,
                ];
                $assetsByDate[$date]['total'] += $asset->amount;
                $assetTotal += $asset->amount;
                $assetCount++;
            }
            
            return response()->json([
                'success' => true,
                'data' => [
                    'month_name' => $startDate->format('F Y'),
                    'profit' => round($invoiceTotal - $expenseTotal - $purchaseTotal, 2),
                    'can_view_khaas' => $canViewKhaas,
                    'invoices' => [
                        'total' => round($invoiceTotal, 2),
                        'count' => $invoiceCount,
                        'by_date' => array_values($invoicesByDate),
                    ],
                    'tips' => [
                        'total' => round($tipsTotal, 2),
                        'count' => $tipsCount,
                        'items' => $tipsItems,
                    ],
                    'expenses' => [
                        'total' => round($expenseTotal, 2),
                        'total_nf' => round($expensesNfTotal, 2),
                        'total_khaas' => round($expensesKhaasTotal, 2),
                        'count' => $expenseCount,
                        'by_date' => array_values($expensesByDate),
                        'by_category' => $expensesByCategory, // Salaries / Food / Maintenance …
                    ],
                    'vendor_purchases' => [
                        'total' => round($purchaseTotal, 2),
                        'total_nf' => round($vendorNfTotal, 2),
                        'total_khaas' => round($vendorKhaasTotal, 2),
                        'count' => $purchaseCount,
                        'by_date' => array_values($purchasesByDate),
                        'by_vendor' => $purchasesByVendor, // vendor → daily breakdown
                    ],
                    'asset_purchases' => [
                        'total' => round($assetTotal, 2),
                        'count' => $assetCount,
                        'by_date' => array_values($assetsByDate),
                    ],
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Month details error: ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }
    
    /**
     * Get Vendor Daily Report
     * Shows vendor purchases and payments recorded on a specific date (by updated_at/created_at)
     * This catches entries that were recorded on that date regardless of transaction_date
     */
    public function getVendorDailyReport(Request $request)
    {
        try {
            $date = $request->get('date');
            if (!$date) {
                $date = Carbon::now()->format('Y-m-d');
            }
            
            // Validate date format
            try {
                $parsedDate = Carbon::parse($date);
                $date = $parsedDate->format('Y-m-d');
            } catch (\Exception $e) {
                $date = Carbon::now()->format('Y-m-d');
            }
            
            $startDateTime = $date . ' 00:00:00';
            $endDateTime = $date . ' 23:59:59';
            
            // Get vendor purchases recorded on this date (by created_at)
            $purchases = DB::select("
                SELECT 
                    l.id,
                    l.description,
                    l.amount,
                    l.mode,
                    l.transaction_date,
                    l.created_at,
                    u.fullname as created_by,
                    a.account_name as vendor_name
                FROM t_fin_ledger l
                LEFT JOIN t_sys_user u ON l.created_by = u.id
                LEFT JOIN t_fin_accounts a ON l.to_account_id = a.id
                WHERE l.transaction_type = ?
                AND l.approval_status = ?
                AND DATE(l.created_at) = ?
                ORDER BY l.created_at DESC
            ", [LedgerModel::TYPE_VENDOR_PURCHASE, LedgerModel::STATUS_APPROVED, $date]);
            
            // Get vendor payments recorded on this date (by created_at)
            $payments = DB::select("
                SELECT 
                    l.id,
                    l.description,
                    l.amount,
                    l.mode,
                    l.transaction_date,
                    l.created_at,
                    u.fullname as created_by,
                    a.account_name as vendor_name
                FROM t_fin_ledger l
                LEFT JOIN t_sys_user u ON l.created_by = u.id
                LEFT JOIN t_fin_accounts a ON l.to_account_id = a.id
                WHERE l.transaction_type = ?
                AND l.approval_status = ?
                AND DATE(l.created_at) = ?
                ORDER BY l.created_at DESC
            ", [LedgerModel::TYPE_VENDOR_PAYMENT, LedgerModel::STATUS_APPROVED, $date]);
            
            $purchasesTotal = 0;
            foreach ($purchases as $p) {
                $purchasesTotal += $p->amount;
            }
            
            $paymentsTotal = 0;
            foreach ($payments as $p) {
                $paymentsTotal += $p->amount;
            }
            
            return response()->json([
                'success' => true,
                'data' => [
                    'date' => $date,
                    'purchases' => array_map(function ($item) {
                        $vendorName = $item->vendor_name;
                        if ($vendorName && strpos($vendorName, 'Vendor - ') === 0) {
                            $vendorName = substr($vendorName, 9);
                        }
                        return [
                            'id' => $item->id,
                            'vendor_name' => $vendorName ?? 'Unknown Vendor',
                            'description' => $item->description,
                            'amount' => round($item->amount, 2),
                            'mode' => $item->mode,
                            'transaction_date' => $item->transaction_date,
                            'entry_date' => Carbon::parse($item->created_at)->format('Y-m-d'),
                            'created_by' => $item->created_by ?? 'Unknown',
                        ];
                    }, $purchases),
                    'payments' => array_map(function ($item) {
                        $vendorName = $item->vendor_name;
                        if ($vendorName && strpos($vendorName, 'Vendor - ') === 0) {
                            $vendorName = substr($vendorName, 9);
                        }
                        return [
                            'id' => $item->id,
                            'vendor_name' => $vendorName ?? 'Unknown Vendor',
                            'description' => $item->description,
                            'amount' => round($item->amount, 2),
                            'mode' => $item->mode,
                            'transaction_date' => $item->transaction_date,
                            'entry_date' => Carbon::parse($item->created_at)->format('Y-m-d'),
                            'created_by' => $item->created_by ?? 'Unknown',
                        ];
                    }, $payments),
                    'purchases_total' => round($purchasesTotal, 2),
                    'payments_total' => round($paymentsTotal, 2),
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Vendor daily report error: ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }
    
    /**
     * Get Expense Daily Report
     * Shows expenses recorded on a specific date (by created_at)
     * This catches entries that were recorded on that date regardless of transaction_date
     */
    public function getExpenseDailyReport(Request $request)
    {
        try {
            $date = $request->get('date');
            if (!$date) {
                $date = Carbon::now()->format('Y-m-d');
            }
            
            // Validate date format
            try {
                $parsedDate = Carbon::parse($date);
                $date = $parsedDate->format('Y-m-d');
            } catch (\Exception $e) {
                $date = Carbon::now()->format('Y-m-d');
            }
            
            // Get expenses recorded on this date (by created_at)
            $expenses = DB::select("
                SELECT 
                    l.id,
                    l.description,
                    l.amount,
                    l.mode,
                    l.transaction_date,
                    l.created_at,
                    u.fullname as created_by,
                    a.account_name as category
                FROM t_fin_ledger l
                LEFT JOIN t_sys_user u ON l.created_by = u.id
                LEFT JOIN t_fin_accounts a ON l.to_account_id = a.id
                WHERE l.transaction_type = ?
                AND l.approval_status = ?
                AND DATE(l.created_at) = ?
                ORDER BY l.created_at DESC
            ", [LedgerModel::TYPE_EXPENSE, LedgerModel::STATUS_APPROVED, $date]);
            
            $total = 0;
            foreach ($expenses as $e) {
                $total += $e->amount;
            }
            
            return response()->json([
                'success' => true,
                'data' => [
                    'date' => $date,
                    'items' => array_map(function ($item) {
                        $category = $item->category;
                        if ($category && strpos($category, 'Expense - ') === 0) {
                            $category = substr($category, 10);
                        }
                        return [
                            'id' => $item->id,
                            'description' => $item->description,
                            'amount' => round($item->amount, 2),
                            'mode' => $item->mode,
                            'transaction_date' => $item->transaction_date,
                            'entry_date' => Carbon::parse($item->created_at)->format('Y-m-d'),
                            'created_by' => $item->created_by ?? 'Unknown',
                            'category' => $category ?? 'Uncategorized',
                        ];
                    }, $expenses),
                    'total' => round($total, 2),
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Expense daily report error: ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }
    
    /**
     * Get Daily Summary for the last 30 days
     * Returns summary totals for expenses and vendor transactions per day
     * Each day can be expanded to get full details
     */
    public function getDailySummary(Request $request)
    {
        try {
            $days = min(max((int) $request->get('days', 30), 7), 365);
            $endDate = Carbon::now();
            $startDate = $endDate->copy()->subDays($days - 1);
            
            // Get expense totals grouped by entry date (created_at)
            $expenseTotals = DB::select("
                SELECT 
                    DATE(l.created_at) as entry_date,
                    COUNT(*) as count,
                    SUM(l.amount) as total
                FROM t_fin_ledger l
                WHERE l.transaction_type = ?
                AND l.approval_status = ?
                AND DATE(l.created_at) >= ?
                AND DATE(l.created_at) <= ?
                GROUP BY DATE(l.created_at)
                ORDER BY entry_date DESC
            ", [LedgerModel::TYPE_EXPENSE, LedgerModel::STATUS_APPROVED, $startDate->format('Y-m-d'), $endDate->format('Y-m-d')]);
            
            // Get vendor purchase totals grouped by entry date (created_at)
            $purchaseTotals = DB::select("
                SELECT 
                    DATE(l.created_at) as entry_date,
                    COUNT(*) as count,
                    SUM(l.amount) as total
                FROM t_fin_ledger l
                WHERE l.transaction_type = ?
                AND l.approval_status = ?
                AND DATE(l.created_at) >= ?
                AND DATE(l.created_at) <= ?
                GROUP BY DATE(l.created_at)
                ORDER BY entry_date DESC
            ", [LedgerModel::TYPE_VENDOR_PURCHASE, LedgerModel::STATUS_APPROVED, $startDate->format('Y-m-d'), $endDate->format('Y-m-d')]);
            
            // Get vendor payment totals grouped by entry date (created_at)
            $paymentTotals = DB::select("
                SELECT 
                    DATE(l.created_at) as entry_date,
                    COUNT(*) as count,
                    SUM(l.amount) as total
                FROM t_fin_ledger l
                WHERE l.transaction_type = ?
                AND l.approval_status = ?
                AND DATE(l.created_at) >= ?
                AND DATE(l.created_at) <= ?
                GROUP BY DATE(l.created_at)
                ORDER BY entry_date DESC
            ", [LedgerModel::TYPE_VENDOR_PAYMENT, LedgerModel::STATUS_APPROVED, $startDate->format('Y-m-d'), $endDate->format('Y-m-d')]);
            
            // Get asset purchase totals grouped by entry date (created_at)
            $assetTotals = DB::select("
                SELECT 
                    DATE(l.created_at) as entry_date,
                    COUNT(*) as count,
                    SUM(l.amount) as total
                FROM t_fin_ledger l
                WHERE l.transaction_type = 'asset_purchase'
                AND l.approval_status = ?
                AND DATE(l.created_at) >= ?
                AND DATE(l.created_at) <= ?
                GROUP BY DATE(l.created_at)
                ORDER BY entry_date DESC
            ", [LedgerModel::STATUS_APPROVED, $startDate->format('Y-m-d'), $endDate->format('Y-m-d')]);
            
            // Build lookup maps
            $expenseMap = [];
            foreach ($expenseTotals as $row) {
                $expenseMap[$row->entry_date] = ['count' => (int) $row->count, 'total' => round($row->total, 2)];
            }
            
            $purchaseMap = [];
            foreach ($purchaseTotals as $row) {
                $purchaseMap[$row->entry_date] = ['count' => (int) $row->count, 'total' => round($row->total, 2)];
            }
            
            $paymentMap = [];
            foreach ($paymentTotals as $row) {
                $paymentMap[$row->entry_date] = ['count' => (int) $row->count, 'total' => round($row->total, 2)];
            }
            
            $assetMap = [];
            foreach ($assetTotals as $row) {
                $assetMap[$row->entry_date] = ['count' => (int) $row->count, 'total' => round($row->total, 2)];
            }
            
            // Build result array for each day (only include days with activity)
            $dailySummaries = [];
            $allDates = array_unique(array_merge(
                array_keys($expenseMap), 
                array_keys($purchaseMap), 
                array_keys($paymentMap),
                array_keys($assetMap)
            ));
            rsort($allDates); // Sort descending (newest first)
            
            foreach ($allDates as $date) {
                $expense = $expenseMap[$date] ?? ['count' => 0, 'total' => 0];
                $purchase = $purchaseMap[$date] ?? ['count' => 0, 'total' => 0];
                $payment = $paymentMap[$date] ?? ['count' => 0, 'total' => 0];
                $asset = $assetMap[$date] ?? ['count' => 0, 'total' => 0];
                
                $dayTotal = $expense['total'] + $purchase['total'] + $payment['total'] + $asset['total'];
                
                $dailySummaries[] = [
                    'date' => $date,
                    'day_name' => Carbon::parse($date)->format('D'),
                    'formatted_date' => Carbon::parse($date)->format('M j, Y'),
                    'is_today' => Carbon::parse($date)->isToday(),
                    'expenses' => $expense,
                    'purchases' => $purchase,
                    'payments' => $payment,
                    'assets' => $asset,
                    'total_amount' => round($dayTotal, 2),
                    'total_count' => $expense['count'] + $purchase['count'] + $payment['count'] + $asset['count'],
                ];
            }
            
            return response()->json([
                'success' => true,
                'data' => [
                    'start_date' => $startDate->format('Y-m-d'),
                    'end_date' => $endDate->format('Y-m-d'),
                    'days' => $dailySummaries,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Daily summary error: ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }
    
    /**
     * Get Daily Details for a specific date
     * Returns full details for expenses and vendor transactions on a date
     */
    public function getDailyDetails(Request $request)
    {
        try {
            $date = $request->get('date');
            if (!$date) {
                $date = Carbon::now()->format('Y-m-d');
            }
            
            // Get expenses for this date
            $expenses = DB::select("
                SELECT 
                    l.id, l.description, l.amount, l.mode, l.transaction_date, l.created_at,
                    u.fullname as created_by,
                    a.account_name as category
                FROM t_fin_ledger l
                LEFT JOIN t_sys_user u ON l.created_by = u.id
                LEFT JOIN t_fin_accounts a ON l.to_account_id = a.id
                WHERE l.transaction_type = ?
                AND l.approval_status = ?
                AND DATE(l.created_at) = ?
                ORDER BY l.created_at DESC
            ", [LedgerModel::TYPE_EXPENSE, LedgerModel::STATUS_APPROVED, $date]);
            
            // Get vendor purchases for this date
            $purchases = DB::select("
                SELECT 
                    l.id, l.description, l.amount, l.mode, l.transaction_date, l.created_at,
                    u.fullname as created_by,
                    a.account_name as vendor_name
                FROM t_fin_ledger l
                LEFT JOIN t_sys_user u ON l.created_by = u.id
                LEFT JOIN t_fin_accounts a ON l.to_account_id = a.id
                WHERE l.transaction_type = ?
                AND l.approval_status = ?
                AND DATE(l.created_at) = ?
                ORDER BY l.created_at DESC
            ", [LedgerModel::TYPE_VENDOR_PURCHASE, LedgerModel::STATUS_APPROVED, $date]);
            
            // Get vendor payments for this date
            $payments = DB::select("
                SELECT 
                    l.id, l.description, l.amount, l.mode, l.transaction_date, l.created_at,
                    u.fullname as created_by,
                    a.account_name as vendor_name
                FROM t_fin_ledger l
                LEFT JOIN t_sys_user u ON l.created_by = u.id
                LEFT JOIN t_fin_accounts a ON l.to_account_id = a.id
                WHERE l.transaction_type = ?
                AND l.approval_status = ?
                AND DATE(l.created_at) = ?
                ORDER BY l.created_at DESC
            ", [LedgerModel::TYPE_VENDOR_PAYMENT, LedgerModel::STATUS_APPROVED, $date]);
            
            // Format expense items
            $expenseItems = array_map(function ($item) {
                $category = $item->category;
                if ($category && strpos($category, 'Expense - ') === 0) {
                    $category = substr($category, 10);
                }
                return [
                    'id' => $item->id,
                    'description' => $item->description,
                    'amount' => round($item->amount, 2),
                    'transaction_date' => $item->transaction_date,
                    'created_by' => $item->created_by ?? 'Unknown',
                    'category' => $category ?? 'Uncategorized',
                ];
            }, $expenses);
            
            // Format vendor purchase items
            $purchaseItems = array_map(function ($item) {
                $vendorName = $item->vendor_name;
                if ($vendorName && strpos($vendorName, 'Vendor - ') === 0) {
                    $vendorName = substr($vendorName, 9);
                }
                return [
                    'id' => $item->id,
                    'description' => $item->description,
                    'amount' => round($item->amount, 2),
                    'transaction_date' => $item->transaction_date,
                    'created_by' => $item->created_by ?? 'Unknown',
                    'vendor_name' => $vendorName ?? 'Unknown Vendor',
                ];
            }, $purchases);
            
            // Format vendor payment items
            $paymentItems = array_map(function ($item) {
                $vendorName = $item->vendor_name;
                if ($vendorName && strpos($vendorName, 'Vendor - ') === 0) {
                    $vendorName = substr($vendorName, 9);
                }
                return [
                    'id' => $item->id,
                    'description' => $item->description,
                    'amount' => round($item->amount, 2),
                    'transaction_date' => $item->transaction_date,
                    'created_by' => $item->created_by ?? 'Unknown',
                    'vendor_name' => $vendorName ?? 'Unknown Vendor',
                ];
            }, $payments);
            
            // Get asset purchases for this date
            $assets = DB::select("
                SELECT 
                    l.id, l.description, l.amount, l.mode, l.transaction_date, l.created_at,
                    u.fullname as created_by,
                    a.asset_name,
                    a.asset_code,
                    bu.name as business_unit
                FROM t_fin_ledger l
                LEFT JOIN t_sys_user u ON l.created_by = u.id
                LEFT JOIN t_fin_assets a ON a.ledger_transaction_id = l.id
                LEFT JOIN t_fin_business_units bu ON a.business_unit_id = bu.id
                WHERE l.transaction_type = 'asset_purchase'
                AND l.approval_status = ?
                AND DATE(l.created_at) = ?
                ORDER BY l.created_at DESC
            ", [LedgerModel::STATUS_APPROVED, $date]);
            
            // Format asset items
            $assetItems = array_map(function ($item) {
                return [
                    'id' => $item->id,
                    'description' => $item->description,
                    'asset_name' => $item->asset_name ?? 'Asset',
                    'asset_code' => $item->asset_code ?? '',
                    'business_unit' => $item->business_unit ?? 'Unknown',
                    'amount' => round($item->amount, 2),
                    'transaction_date' => $item->transaction_date,
                    'created_by' => $item->created_by ?? 'Unknown',
                ];
            }, $assets);
            
            $expenseTotal = array_sum(array_column($expenseItems, 'amount'));
            $purchaseTotal = array_sum(array_column($purchaseItems, 'amount'));
            $paymentTotal = array_sum(array_column($paymentItems, 'amount'));
            $assetTotal = array_sum(array_column($assetItems, 'amount'));
            
            return response()->json([
                'success' => true,
                'data' => [
                    'date' => $date,
                    'formatted_date' => Carbon::parse($date)->format('l, M j, Y'),
                    'expenses' => [
                        'items' => $expenseItems,
                        'total' => round($expenseTotal, 2),
                        'count' => count($expenseItems),
                    ],
                    'purchases' => [
                        'items' => $purchaseItems,
                        'total' => round($purchaseTotal, 2),
                        'count' => count($purchaseItems),
                    ],
                    'payments' => [
                        'items' => $paymentItems,
                        'total' => round($paymentTotal, 2),
                        'count' => count($paymentItems),
                    ],
                    'assets' => [
                        'items' => $assetItems,
                        'total' => round($assetTotal, 2),
                        'count' => count($assetItems),
                    ],
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Daily details error: ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
