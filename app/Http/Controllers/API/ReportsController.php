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
     * Get Monthly Summary for the last 12 months
     * Returns profit, invoices, expenses, vendor_purchases for each month
     */
    public function getMonthlySummary(Request $request)
    {
        try {
            $months = [];
            $date = Carbon::now()->startOfMonth();
            
            for ($i = 0; $i < 12; $i++) {
                $startDate = $date->copy()->format('Y-m-d');
                $endDate = $date->copy()->endOfMonth()->format('Y-m-d');
                $monthKey = $date->format('Y-m');
                $monthName = $date->format('F Y');
                
            // Get delivered invoices for this month (using MIN delivery date like dashboard)
            $invoiceData = DB::selectOne("
                SELECT 
                    COALESCE(SUM(o.total_price), 0) as total,
                    COUNT(DISTINCT o.id) as count
                FROM t_crm_prod_order o
                INNER JOIN (
                    SELECT order_id, MIN(changed_at) as delivered_at 
                    FROM t_crm_order_status_history 
                    WHERE status_code = 'delivered' 
                    GROUP BY order_id
                ) h ON o.id = h.order_id
                WHERE h.delivered_at >= ? AND h.delivered_at <= ?
                AND (o.external_source IS NULL OR o.external_source != 'shopify')
            ", [$startDate . ' 00:00:00', $endDate . ' 23:59:59']);
                
                // Get approved expenses for this month
                $expenseData = DB::selectOne("
                    SELECT 
                        COALESCE(SUM(amount), 0) as total,
                        COUNT(*) as count
                    FROM t_fin_ledger
                    WHERE transaction_date >= ? AND transaction_date <= ?
                    AND transaction_type = ?
                    AND approval_status = ?
                ", [$startDate, $endDate, LedgerModel::TYPE_EXPENSE, LedgerModel::STATUS_APPROVED]);
                
                // Get approved vendor purchases for this month
                $purchaseData = DB::selectOne("
                    SELECT 
                        COALESCE(SUM(amount), 0) as total,
                        COUNT(*) as count
                    FROM t_fin_ledger
                    WHERE transaction_date >= ? AND transaction_date <= ?
                    AND transaction_type = ?
                    AND approval_status = ?
                ", [$startDate, $endDate, LedgerModel::TYPE_VENDOR_PURCHASE, LedgerModel::STATUS_APPROVED]);
                
                $invoices = round($invoiceData->total ?? 0, 2);
                $expenses = round($expenseData->total ?? 0, 2);
                $vendorPurchases = round($purchaseData->total ?? 0, 2);
                $profit = round($invoices - $expenses - $vendorPurchases, 2);
                
                $months[] = [
                    'month_key' => $monthKey,
                    'month_name' => $monthName,
                    'invoices' => $invoices,
                    'invoice_count' => (int) ($invoiceData->count ?? 0),
                    'expenses' => $expenses,
                    'expense_count' => (int) ($expenseData->count ?? 0),
                    'vendor_purchases' => $vendorPurchases,
                    'vendor_purchase_count' => (int) ($purchaseData->count ?? 0),
                    'profit' => $profit,
                ];
                
                $date->subMonth();
            }
            
            return response()->json([
                'success' => true,
                'data' => $months,
            ]);
        } catch (\Exception $e) {
            Log::error('Monthly summary error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
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
            
            // Get invoices grouped by delivery date (using MIN delivery date like dashboard)
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
            
            // Get expenses grouped by transaction date with user and category
            $expensesRaw = DB::select("
                SELECT 
                    l.transaction_date,
                    l.description,
                    l.amount,
                    u.fullname as created_by,
                    a.account_name as category,
                    DATE(l.created_at) as entry_date
                FROM t_fin_ledger l
                LEFT JOIN t_sys_user u ON l.created_by = u.id
                LEFT JOIN t_fin_accounts a ON l.to_account_id = a.id
                WHERE l.transaction_date >= ? AND l.transaction_date <= ?
                AND l.transaction_type = ?
                AND l.approval_status = ?
                ORDER BY l.transaction_date DESC
            ", [$startDate->format('Y-m-d'), $endDate->format('Y-m-d'), LedgerModel::TYPE_EXPENSE, LedgerModel::STATUS_APPROVED]);
            
            // Group expenses by date
            $expensesByDate = [];
            $expenseTotal = 0;
            $expenseCount = 0;
            foreach ($expensesRaw as $exp) {
                $date = $exp->transaction_date;
                if (!isset($expensesByDate[$date])) {
                    $expensesByDate[$date] = ['date' => $date, 'items' => [], 'total' => 0];
                }
                // Extract category from account name (e.g., "Expense - Fuel" -> "Fuel")
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
                ];
                $expensesByDate[$date]['total'] += $exp->amount;
                $expenseTotal += $exp->amount;
                $expenseCount++;
            }
            
            // Get vendor purchases grouped by transaction date with vendor name
            $purchasesRaw = DB::select("
                SELECT 
                    l.transaction_date,
                    l.description,
                    l.amount,
                    u.fullname as created_by,
                    a.account_name as vendor_name,
                    DATE(l.created_at) as entry_date
                FROM t_fin_ledger l
                LEFT JOIN t_sys_user u ON l.created_by = u.id
                LEFT JOIN t_fin_accounts a ON l.to_account_id = a.id
                WHERE l.transaction_date >= ? AND l.transaction_date <= ?
                AND l.transaction_type = ?
                AND l.approval_status = ?
                ORDER BY l.transaction_date DESC
            ", [$startDate->format('Y-m-d'), $endDate->format('Y-m-d'), LedgerModel::TYPE_VENDOR_PURCHASE, LedgerModel::STATUS_APPROVED]);
            
            // Group vendor purchases by date
            $purchasesByDate = [];
            $purchaseTotal = 0;
            $purchaseCount = 0;
            foreach ($purchasesRaw as $vp) {
                $date = $vp->transaction_date;
                if (!isset($purchasesByDate[$date])) {
                    $purchasesByDate[$date] = ['date' => $date, 'items' => [], 'total' => 0];
                }
                // Extract vendor name (e.g., "Vendor - ABC Farms" -> "ABC Farms")
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
                ];
                $purchasesByDate[$date]['total'] += $vp->amount;
                $purchaseTotal += $vp->amount;
                $purchaseCount++;
            }
            
            return response()->json([
                'success' => true,
                'data' => [
                    'month_name' => $startDate->format('F Y'),
                    'profit' => round($invoiceTotal - $expenseTotal - $purchaseTotal, 2),
                    'invoices' => [
                        'total' => round($invoiceTotal, 2),
                        'count' => $invoiceCount,
                        'by_date' => array_values($invoicesByDate),
                    ],
                    'expenses' => [
                        'total' => round($expenseTotal, 2),
                        'count' => $expenseCount,
                        'by_date' => array_values($expensesByDate),
                    ],
                    'vendor_purchases' => [
                        'total' => round($purchaseTotal, 2),
                        'count' => $purchaseCount,
                        'by_date' => array_values($purchasesByDate),
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
            
            // Build result array for each day (only include days with activity)
            $dailySummaries = [];
            $allDates = array_unique(array_merge(
                array_keys($expenseMap), 
                array_keys($purchaseMap), 
                array_keys($paymentMap)
            ));
            rsort($allDates); // Sort descending (newest first)
            
            foreach ($allDates as $date) {
                $expense = $expenseMap[$date] ?? ['count' => 0, 'total' => 0];
                $purchase = $purchaseMap[$date] ?? ['count' => 0, 'total' => 0];
                $payment = $paymentMap[$date] ?? ['count' => 0, 'total' => 0];
                
                $dayTotal = $expense['total'] + $purchase['total'] + $payment['total'];
                
                $dailySummaries[] = [
                    'date' => $date,
                    'day_name' => Carbon::parse($date)->format('D'),
                    'formatted_date' => Carbon::parse($date)->format('M j, Y'),
                    'is_today' => Carbon::parse($date)->isToday(),
                    'expenses' => $expense,
                    'purchases' => $purchase,
                    'payments' => $payment,
                    'total_amount' => round($dayTotal, 2),
                    'total_count' => $expense['count'] + $purchase['count'] + $payment['count'],
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
            
            $expenseTotal = array_sum(array_column($expenseItems, 'amount'));
            $purchaseTotal = array_sum(array_column($purchaseItems, 'amount'));
            $paymentTotal = array_sum(array_column($paymentItems, 'amount'));
            
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
