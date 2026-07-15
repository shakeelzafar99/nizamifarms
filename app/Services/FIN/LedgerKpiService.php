<?php

namespace App\Services\FIN;

use App\Models\FIN\AccountModel;
use App\Models\FIN\LedgerModel;
use App\Services\QurbaniFinanceFilter;
use Illuminate\Support\Facades\DB;

/**
 * LedgerKpiService — the operational Ledger KPI figures (Invoices / Expenses / Vendor / Riders /
 * Profit) used by the Ledger Hub Overview.
 *
 * IMPORTANT (parallel-run): this is a FAITHFUL MIRROR of
 * FIN\LedgerController::calculateKPIs(). It is intentionally a separate copy so the Ledger Hub
 * does NOT modify LedgerController (which carries the pending ledger-solidity deploy and is shared
 * with other efforts). When the old Overall-Ledger page is retired, point
 * LedgerController::calculateKPIs() at this service and delete its private copy — until then, keep
 * the two in lockstep.
 *
 * Qurbani is stripped from these operational figures (see the block comment below) exactly as the
 * old page does.
 */
class LedgerKpiService
{
    /**
     * @return array<string,mixed> same shape the <x-fin.kpi-cards> component expects.
     */
    public function compute(?string $startDate, ?string $endDate): array
    {
        // Qurbani orders are stripped from the operational Ledger KPIs because the Qurbani delivery
        // flow deliberately skips posting an INVOICE ledger row; Qurbani payments are collected
        // separately against the dedicated Qurbani Cash / Qurbani Online accounts. Keeping them
        // would produce phantom "Cash Pending With Rider".
        $invoicesQuery = DB::table('t_crm_order_status_history as h')
            ->leftJoin('t_crm_prod_order as o', 'o.id', '=', 'h.order_id')
            ->where('h.status_code', 'delivered')
            ->where('h.is_current', 1)
            ->tap(function ($q) {
                QurbaniFinanceFilter::applyToOrderQuery($q, 'o', QurbaniFinanceFilter::MODE_EXCLUDE);
            });

        if ($startDate && $endDate) {
            $invoicesQuery->whereBetween('h.changed_at', [$startDate, $endDate]);
        }

        $deliveredOrderIds = $invoicesQuery->pluck('h.order_id');

        // === KPI 1: INVOICES ===
        $totalInvoices = DB::table('t_crm_prod_order')
            ->whereIn('id', $deliveredOrderIds)
            ->sum('total_price') ?? 0;

        $invoicesCash = DB::table('t_crm_prod_order')
            ->whereIn('id', $deliveredOrderIds)
            ->whereIn('payment_method', ['cash', 'cash_on_delivery', 'Cash', 'COD'])
            ->sum('total_price') ?? 0;

        $nfCashAccount = AccountModel::where('account_code', 'NF_CASH')->first();
        $cashDeposits = 0;
        $shortCashTotal = 0;

        if ($nfCashAccount) {
            $cashDeposits = LedgerModel::where('to_account_id', $nfCashAccount->id)
                ->where('transaction_type', LedgerModel::TYPE_EMPLOYEE_DEPOSIT)
                ->where('approval_status', LedgerModel::STATUS_APPROVED)
                ->whereBetween('transaction_date', [$startDate, $endDate])
                ->sum('amount') ?? 0;

            $shortCashTotal = \App\Models\Request\RequestModel::where('status', 'approved')
                ->whereHas('category', function ($q) {
                    $q->where('category_code', 'expense');
                })
                ->whereHas('paymentSourceAccount', function ($q) {
                    $q->where('account_category', 'employee_cash');
                })
                ->whereBetween('created_at', [$startDate, $endDate])
                ->sum('amount') ?? 0;
        }

        $invoicesOnline = DB::table('t_crm_prod_order')
            ->whereIn('id', $deliveredOrderIds)
            ->whereIn('payment_method', ['online', 'Online', 'bank_transfer', 'card', 'online_payment'])
            ->sum('total_price') ?? 0;

        // === ONLINE INVOICES: L1/L2 split ===
        $onlineAccount = AccountModel::where('account_code', 'ONLINE')->first();
        $onlineInvoiceLedgersQuery = LedgerModel::where('transaction_type', LedgerModel::TYPE_INVOICE)
            ->where('approval_status', '!=', LedgerModel::STATUS_REVERSED)
            ->whereBetween('transaction_date', [$startDate, $endDate]);

        if ($onlineAccount) {
            $onlineInvoiceLedgersQuery->where('to_account_id', $onlineAccount->id);
        }

        $onlineInvoiceLedgers = $onlineInvoiceLedgersQuery->get();

        $onlineApproved = $onlineInvoiceLedgers
            ->where('approval_status', LedgerModel::STATUS_APPROVED)
            ->sum('amount');

        $onlinePendingL1 = $onlineInvoiceLedgers
            ->filter(function ($invoice) {
                return in_array($invoice->approval_status, [
                    LedgerModel::STATUS_PENDING,
                    LedgerModel::STATUS_PENDING_L1,
                ], true);
            })
            ->sum('amount');

        $onlinePendingL2 = $onlineInvoiceLedgers
            ->where('approval_status', LedgerModel::STATUS_PENDING_L2)
            ->sum('amount');

        $onlinePending = $onlinePendingL1 + $onlinePendingL2;

        // === KPI 2: EXPENSES ===
        $ledgerExpenses = LedgerModel::where('transaction_type', LedgerModel::TYPE_EXPENSE)
            ->where('approval_status', LedgerModel::STATUS_APPROVED)
            ->whereBetween('transaction_date', [$startDate, $endDate])
            ->tap(function ($q) {
                QurbaniFinanceFilter::applyToLedgerQuery($q, 't_fin_ledger', QurbaniFinanceFilter::MODE_EXCLUDE);
            })
            ->sum('amount') ?? 0;

        $salaryExpenses = \App\Models\HR\SalarySlipModel::whereIn('slip_status', ['approved', 'paid'])
            ->whereNotNull('ledger_transaction_id')
            ->whereBetween('created_at', [$startDate, $endDate])
            ->sum('net_salary') ?? 0;

        // Salaries paid from the NEW Payroll screen (t_hr_payroll_payment, the Phase-G flow that
        // replaced salary slips). These post a `salary_payment` ledger row that is NOT `expense`-typed,
        // so without this they never reached the Expenses figure — which would make salaries vanish
        // once the legacy "Staff Salaries" expense path is blocked. Cash-basis: the net actually paid,
        // one row per employee/month.
        $payrollNet = DB::table('t_hr_payroll_payment')
            ->where('status', 'paid')
            ->whereBetween('paid_at', [$startDate, $endDate])
            ->sum('net_salary') ?? 0;

        // Salary advances (cash handed out before payday) are real cash-out too and already appear as
        // expenses on the Expense Management page. Count them here for parity + cash-basis correctness
        // (net excludes the advance, so advance + net = the full salary cost, never double-counted).
        $salaryAdvances = LedgerModel::where('transaction_type', LedgerModel::TYPE_SALARY_ADVANCE)
            ->where('approval_status', LedgerModel::STATUS_APPROVED)
            ->whereBetween('transaction_date', [$startDate, $endDate])
            ->tap(function ($q) {
                QurbaniFinanceFilter::applyToLedgerQuery($q, 't_fin_ledger', QurbaniFinanceFilter::MODE_EXCLUDE);
            })
            ->sum('amount') ?? 0;

        $totalExpenses = $ledgerExpenses + $salaryExpenses + $payrollNet + $salaryAdvances;
        $regularExpenses = $ledgerExpenses;
        // Keep total_expenses == regular + salary so the KPI card still balances.
        $salaryExpensesForDisplay = $salaryExpenses + $payrollNet + $salaryAdvances;

        $expensesNeedingSettlement = \App\Models\Request\RequestModel::where('status', 'approved')
            ->where('settlement_status', 'pending')
            ->whereHas('category', function ($q) {
                $q->where('category_code', 'expense');
            })
            ->whereBetween('created_at', [$startDate, $endDate])
            ->sum('amount') ?? 0;

        // === KPI 3: VENDOR BALANCE ===
        $vendorPurchases = LedgerModel::where('transaction_type', LedgerModel::TYPE_VENDOR_PURCHASE)
            ->where('approval_status', LedgerModel::STATUS_APPROVED)
            ->whereBetween('transaction_date', [$startDate, $endDate])
            ->tap(function ($q) {
                QurbaniFinanceFilter::applyToLedgerQuery($q, 't_fin_ledger', QurbaniFinanceFilter::MODE_EXCLUDE);
            })
            ->sum('amount') ?? 0;

        $vendorPayments = LedgerModel::where('transaction_type', LedgerModel::TYPE_VENDOR_PAYMENT)
            ->where('approval_status', LedgerModel::STATUS_APPROVED)
            ->whereBetween('transaction_date', [$startDate, $endDate])
            ->tap(function ($q) {
                QurbaniFinanceFilter::applyToLedgerQuery($q, 't_fin_ledger', QurbaniFinanceFilter::MODE_EXCLUDE);
            })
            ->sum('amount') ?? 0;

        $vendorBalance = $vendorPurchases - $vendorPayments;

        // === KPI 4: RIDERS BALANCE ===
        $ridersBalance = AccountModel::where('account_category', AccountModel::CATEGORY_EMPLOYEE_CASH)
            ->where('is_active', 1)
            ->sum('current_balance') ?? 0;

        $pendingDeposits = LedgerModel::where('transaction_type', LedgerModel::TYPE_EMPLOYEE_DEPOSIT)
            ->where('approval_status', LedgerModel::STATUS_PENDING)
            ->whereBetween('transaction_date', [$startDate, $endDate])
            ->sum('amount') ?? 0;

        $pendingExpenses = \App\Models\Request\RequestModel::where('status', 'pending')
            ->whereHas('category', function ($q) {
                $q->where('category_code', 'expense');
            })
            ->whereBetween('created_at', [$startDate, $endDate])
            ->sum('amount') ?? 0;

        // === KPI 5: NF BALANCE (PROFIT) ===
        $profit = $totalInvoices - $totalExpenses - $vendorPurchases;

        return [
            'total_invoices' => $totalInvoices,
            'invoices_cash' => $invoicesCash,
            'cash_deposits' => $cashDeposits,
            'short_cash_total' => $shortCashTotal,
            'invoices_online' => $invoicesOnline,
            'online_approved' => $onlineApproved,
            'online_pending' => $onlinePending,
            'online_pending_l1' => $onlinePendingL1,
            'online_pending_l2' => $onlinePendingL2,
            'total_expenses' => $totalExpenses,
            'regular_expenses' => $regularExpenses,
            'salary_expenses' => $salaryExpensesForDisplay,
            'expenses_needing_settlement' => $expensesNeedingSettlement,
            'vendor_balance' => $vendorBalance,
            'vendor_purchases' => $vendorPurchases,
            'vendor_payments' => $vendorPayments,
            'riders_balance' => $ridersBalance,
            'pending_deposits' => $pendingDeposits,
            'pending_expenses' => $pendingExpenses,
            'profit' => $profit,
            'profit_invoices' => $totalInvoices,
            'profit_expenses' => $totalExpenses,
            'profit_vendor_purchases' => $vendorPurchases,
        ];
    }
}
