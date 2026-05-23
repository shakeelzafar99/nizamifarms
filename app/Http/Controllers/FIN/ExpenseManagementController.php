<?php

namespace App\Http\Controllers\FIN;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Request\RequestModel;
use App\Models\FIN\AccountModel;
use App\Models\FIN\LedgerModel;
use App\Models\FIN\ConfigModel;
use App\Models\FIN\BusinessUnitModel;
use App\Models\HR\SalarySlipModel;
use App\Services\FIN\ExpenseSettlementService;
use App\Services\QurbaniFinanceFilter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ExpenseManagementController extends Controller
{
    protected $settlementService;
    
    public function __construct(ExpenseSettlementService $settlementService)
    {
        $this->settlementService = $settlementService;
    }
    
    /**
     * Display expense management dashboard
     */
    public function index(Request $request)
    {
        // May-2026 (Phase 3) — `qurbani=1` flips this controller into
        // "Qurbani Expenses" mode. Same view, same code path, but:
        //   - Qurbani-only filter on all three request queries
        //     (instead of the regular Qurbani-EXCLUDE filter).
        //   - Date range defaults to the *current calendar year*
        //     (instead of the current month). User can switch years
        //     via the year picker rendered in the view.
        //   - View renders an extra "Qurbani Revenue" card pulling
        //     from t_crm_prod_order with the canonical OR-rule.
        //   - Salary slips are skipped — Qurbani staff costs are
        //     entered as request-based qurbani sub-categories.
        $qurbaniMode = filter_var($request->input('qurbani', false), FILTER_VALIDATE_BOOLEAN);

        $hasDateFilter = $request->has('date_from') || $request->has('date_to');
        if ($qurbaniMode) {
            // Year-accumulating window. The view's year picker posts
            // a `year` query param; default to the current calendar
            // year if none provided.
            $year = (int) ($request->input('year', date('Y')));
            if ($year < 2024 || $year > 2099) { $year = (int) date('Y'); }
            $dateFrom = $year . '-01-01';
            $dateTo   = $year . '-12-31';
        } elseif (!$hasDateFilter) {
            // Default to "This Month"
            $dateFrom = date('Y-m-01');
            $dateTo = date('Y-m-t');
        } else {
            $dateFrom = $request->input('date_from');
            $dateTo = $request->input('date_to');
        }

        $category = $request->input('category');
        $paymentSource = $request->input('payment_source');
        $settlementStatus = $request->input('settlement_status');
        $employeeFilter = $request->input('employee');
        $requestType = $request->input('request_type');
        // May-2026 (Phase 4) — `bu` drill: NF or KHAAS code on the
        // business unit table. Filters the entire page (KPIs, list,
        // pending approvals) when set. Empty = both BUs combined.
        $buFilter = $request->input('bu');
        if ($buFilter !== null && $buFilter !== '' && !in_array($buFilter, ['NF', 'KHAAS'], true)) {
            $buFilter = null;
        }
        // Whoever lacks `access_khaas_mode` shouldn't see any Khaas
        // numbers (per the user's permissions rule). Force-strip the
        // Khaas filter and the Khaas split row in that case.
        $canViewKhaas = false;
        try {
            if (\Auth::check()) {
                $u = \Auth::user();
                $u->load(['roles.mobilePermissions']);
                $perms = method_exists($u, 'getMobilePermissions')
                    ? $u->getMobilePermissions()
                    : [];
                $canViewKhaas = in_array('access_khaas_mode', $perms, true);
            }
        } catch (\Throwable $e) {
            $canViewKhaas = false;
        }
        if (!$canViewKhaas && $buFilter === 'KHAAS') {
            $buFilter = null;
        }
        // Resolve BU ids once per request so the BU-split block doesn't
        // re-query the BU table per row.
        $nfBuId    = (int) (BusinessUnitModel::where('code', 'NF')->value('id') ?? 0);
        $khaasBuId = (int) (BusinessUnitModel::where('code', 'KHAAS')->value('id') ?? 0);
        
        // Dynamically get all category codes that are enabled for expense management
        try {
            $expenseCategoryCodes = \App\Models\Request\RequestCategoryModel::where('show_in_expenses', 1)
                ->where('is_active', 1)
                ->pluck('category_code')
                ->toArray();
        } catch (\Exception $e) {
            $expenseCategoryCodes = ['expense', 'salary_advance', 'khaas_expense'];
        }
        if (empty($expenseCategoryCodes)) {
            $expenseCategoryCodes = ['expense', 'salary_advance', 'khaas_expense'];
        }
        
        // Build base query for all expense-type requests.
        // May-2026 (Phase 2/3) — Qurbani requests are silo'd into a
        // dedicated tab. The same controller serves both pages; the
        // `qurbaniMode` flag toggles which side of the silo we
        // return. Qurbani requests are still entered from the same
        // quick-add modal regardless of which tab the user is on.
        $expensesQuery = RequestModel::whereHas('category', function($q) use ($expenseCategoryCodes, $requestType, $qurbaniMode) {
                if ($requestType) {
                    $q->where('category_code', $requestType);
                } else {
                    $q->whereIn('category_code', $expenseCategoryCodes);
                }
                if ($qurbaniMode) {
                    $q->where('category_code', 'qurbani');
                } else {
                    $q->where('category_code', '!=', 'qurbani');
                }
            })
            ->whereNotNull('ledger_transaction_id')
            ->where('status', RequestModel::STATUS_APPROVED) // Only approved expenses
            ->with(['requester', 'paymentSourceAccount', 'category', 'settledBy', 'settlementDestinationAccount']);
        // BU drill — restrict the whole page when user clicks NF/Khaas.
        if ($buFilter === 'NF' && $nfBuId) {
            $expensesQuery->where(function ($q) use ($nfBuId) {
                $q->where('business_unit_id', $nfBuId)
                  ->orWhereNull('business_unit_id');
            });
        } elseif ($buFilter === 'KHAAS' && $khaasBuId) {
            $expensesQuery->where('business_unit_id', $khaasBuId);
        }
        // If the user can't view Khaas, force-hide Khaas rows even
        // without an explicit filter.
        if (!$canViewKhaas && $khaasBuId) {
            $expensesQuery->where(function ($q) use ($khaasBuId) {
                $q->where('business_unit_id', '!=', $khaasBuId)
                  ->orWhereNull('business_unit_id');
            });
        }
        
        // Apply filters
        // ⭐ Use expense_date for filtering (falls back to created_at for old records)
        if ($dateFrom && $dateTo) {
            $expensesQuery->whereRaw('DATE(COALESCE(expense_date, created_at)) >= ?', [$dateFrom])
                          ->whereRaw('DATE(COALESCE(expense_date, created_at)) <= ?', [$dateTo]);
        }
        
        if ($category) {
            // Case-insensitive category filter
            // Handle special cases: Salary (from slips) and Salary Advance (might not have expense_category)
            if (strtolower($category) === 'salary') {
                // For "Salary" filter, we'll handle this by including salary slips later
                // For now, exclude all regular expenses (since Salary only comes from slips)
                $expensesQuery->whereRaw('1 = 0'); // This will return no results from expenses
            } else {
                $expensesQuery->where(function($q) use ($category) {
                    $q->whereRaw('LOWER(expense_category) = ?', [strtolower($category)])
                      ->orWhere(function($q2) use ($category) {
                          // For salary advances without expense_category
                          if (strtolower($category) === 'salary advance') {
                              $q2->whereNull('expense_category')
                                 ->orWhere('expense_category', '')
                                 ->whereHas('category', function($q3) {
                                     $q3->where('category_code', 'salary_advance');
                                 });
                          }
                      });
                });
            }
        }
        
        if ($paymentSource) {
            $expensesQuery->where('payment_source_account_id', $paymentSource);
        }
        
        if ($settlementStatus) {
            $expensesQuery->where('settlement_status', $settlementStatus);
        }
        
        if ($employeeFilter) {
            $expensesQuery->whereHas('requester', function($q) use ($employeeFilter) {
                $q->where('fullname', $employeeFilter);
            });
        }
        
        $allExpenses = $expensesQuery->orderBy('created_at', 'desc')->get();
        
        // Get salary slips (approved/paid) for total expenses calculation AND display
        $salarySlipsQuery = SalarySlipModel::with(['employee'])
            ->whereIn('slip_status', ['approved', 'paid'])
            ->whereNotNull('ledger_transaction_id');
        
        // Apply date filter to salary slips if provided
        // ⭐ FIX: Use DATE() to ensure full day is included
        if ($dateFrom && $dateTo) {
            $salarySlipsQuery->whereRaw('DATE(created_at) >= ?', [$dateFrom])
                             ->whereRaw('DATE(created_at) <= ?', [$dateTo]);
        }
        
        // If category filter is "Salary", include salary slips; otherwise exclude them
        $includeSalarySlips = !$category || strtolower($category) === 'salary';
        // Qurbani Expenses tab never wants salary — qurbani staff
        // are paid via the request-based qurbani sub-categories.
        if ($qurbaniMode) {
            $includeSalarySlips = false;
        }
        // Salary slips don't carry a business_unit_id — when the
        // page is drilled into KHAAS, hide them so the Khaas total
        // isn't inflated by all-staff salaries.
        if ($buFilter === 'KHAAS') {
            $includeSalarySlips = false;
        }

        $salarySlips = $includeSalarySlips ? $salarySlipsQuery->orderBy('created_at', 'desc')->get() : collect([]);
        $totalSalaryExpenses = $salarySlips->sum('net_salary');
        
        // Transform salary slips to match expense format for unified display
        $salarySlipsForDisplay = $salarySlips->map(function($slip) {
            return (object) [
                'id' => 'SALARY-' . $slip->id, // Prefix to distinguish from regular expenses
                'slip_id' => $slip->id,
                'type' => 'salary',
                'request_number' => $slip->slip_number ?? ('SLIP-' . $slip->id),
                'created_at' => $slip->created_at,
                'requester' => $slip->employee,
                'requester_user_id' => $slip->user_id,
                'category' => (object) ['category_name' => 'Salary Payment', 'category_code' => 'salary'],
                'expense_category' => 'Salary',
                'amount' => $slip->net_salary,
                'paymentSourceAccount' => (object) ['account_name' => 'Expense Fund'],
                'payment_source_account_id' => null, // Could be enhanced to get actual account
                'settlement_status' => 'not_applicable', // Salaries don't need settlement
                'status' => $slip->slip_status,
                'ledger_transaction_id' => $slip->ledger_transaction_id
            ];
        });
        
        // Merge expenses and salary slips for unified display
        $allExpensesForDisplay = $allExpenses->concat($salarySlipsForDisplay)->sortByDesc('created_at');
        
        // Calculate KPIs
        $totalExpenses = $allExpenses->sum('amount') + $totalSalaryExpenses;
        
        // Calculate "from expense fund" - expenses with no settlement needed + all salary payments
        $fromExpenseFund = $allExpenses->filter(function($exp) {
            return $exp->settlement_status === 'not_required';
        })->sum('amount') + $totalSalaryExpenses; // Salaries always from EXP_FUND
        
        $needsSettlement = $allExpenses->filter(function($exp) {
            return $exp->settlement_status === 'pending';
        })->sum('amount');
        
        $settled = $allExpenses->filter(function($exp) {
            return $exp->settlement_status === 'settled';
        })->sum('amount');
        
        // Get expenses needing settlement (for priority view)
        $pendingSettlement = $allExpenses->filter(function($exp) {
            return $exp->settlement_status === 'pending';
        })->sortBy('created_at');
        
        // Get settlement history (most recent)
        $settlementHistory = $allExpenses->filter(function($exp) {
            return $exp->settlement_status === 'settled';
        })->sortByDesc('settled_at')->take(20);
        
        // Get expense categories for filter - dynamically from actual expenses
        // (Qurbani sub-categories appear ONLY on the Qurbani tab.)
        $categoriesFromExpenses = RequestModel::whereHas('category', function($q) use ($expenseCategoryCodes, $qurbaniMode) {
                $q->whereIn('category_code', $expenseCategoryCodes);
                if ($qurbaniMode) {
                    $q->where('category_code', 'qurbani');
                } else {
                    $q->where('category_code', '!=', 'qurbani');
                }
            })
            ->whereNotNull('ledger_transaction_id')
            ->where('status', RequestModel::STATUS_APPROVED)
            ->whereNotNull('expense_category')
            ->where('expense_category', '!=', '')
            ->distinct()
            ->pluck('expense_category');
        
        // Add "Salary" from salary slips
        $categoriesFromSalary = collect(['Salary']);
        
        // Add "Salary Advance" for salary advances that might not have expense_category set
        $categoriesFromSalaryAdvance = collect(['Salary Advance']);
        
        // Merge all categories and sort
        $categories = $categoriesFromExpenses
            ->merge($categoriesFromSalary)
            ->merge($categoriesFromSalaryAdvance)
            ->unique()
            ->sort()
            ->values();
        
        // Get payment sources for filter — hide private for non-Taimur
        $paymentSources = AccountModel::whereIn('account_type', ['asset'])
            ->where('is_active', 1)
            ->visibleTo(auth()->user())
            ->orderBy('account_name')
            ->get();
        
        // Get settlement sources (for settlement modal)
        $settlementSources = AccountModel::whereIn('account_code', ['EXP_FUND', 'NF_CASH', 'ONLINE'])
            ->where('is_active', 1)
            ->get();
        
        // Get pending approvals (real-time, not filtered by date).
        // Pending approvals follow the same Qurbani silo as the rest
        // of the page — Qurbani-only on the Qurbani tab, none on the
        // regular tab.
        $pendingApprovalsQuery = RequestModel::whereHas('category', function($q) use ($expenseCategoryCodes, $qurbaniMode) {
                $q->whereIn('category_code', $expenseCategoryCodes);
                if ($qurbaniMode) {
                    $q->where('category_code', 'qurbani');
                } else {
                    $q->where('category_code', '!=', 'qurbani');
                }
            })
            ->where('status', RequestModel::STATUS_PENDING)
            ->with(['requester', 'paymentSourceAccount', 'category'])
            ->orderBy('created_at', 'asc');
        if ($buFilter === 'NF' && $nfBuId) {
            $pendingApprovalsQuery->where(function ($q) use ($nfBuId) {
                $q->where('business_unit_id', $nfBuId)
                  ->orWhereNull('business_unit_id');
            });
        } elseif ($buFilter === 'KHAAS' && $khaasBuId) {
            $pendingApprovalsQuery->where('business_unit_id', $khaasBuId);
        }
        if (!$canViewKhaas && $khaasBuId) {
            $pendingApprovalsQuery->where(function ($q) use ($khaasBuId) {
                $q->where('business_unit_id', '!=', $khaasBuId)
                  ->orWhereNull('business_unit_id');
            });
        }
        $pendingApprovals = $pendingApprovalsQuery->get();
        
        // Calculate expense categories with user breakdown (matches mobile format)
        $expensesByCategory = [];
        $expensesByCategoryUser = [];
        
        foreach ($allExpenses as $expense) {
            $category = $expense->expense_category;
            if (empty($category) && $expense->category && $expense->category->category_code === 'salary_advance') {
                $category = 'Salary Advance';
            } elseif (empty($category)) {
                $category = 'Uncategorized';
            }
            
            if (!isset($expensesByCategory[$category])) {
                $expensesByCategory[$category] = 0;
            }
            $expensesByCategory[$category] += $expense->amount;
            
            $userName = $expense->requester ? $expense->requester->fullname : 'Unknown';
            if (!isset($expensesByCategoryUser[$category])) {
                $expensesByCategoryUser[$category] = [];
            }
            if (!isset($expensesByCategoryUser[$category][$userName])) {
                $expensesByCategoryUser[$category][$userName] = 0;
            }
            $expensesByCategoryUser[$category][$userName] += $expense->amount;
        }
        
        if ($totalSalaryExpenses > 0) {
            if (!isset($expensesByCategory['Salary'])) {
                $expensesByCategory['Salary'] = 0;
            }
            $expensesByCategory['Salary'] += $totalSalaryExpenses;
            
            if (!isset($expensesByCategoryUser['Salary'])) {
                $expensesByCategoryUser['Salary'] = [];
            }
            foreach ($salarySlips as $slip) {
                $empName = $slip->employee ? $slip->employee->fullname : 'Unknown';
                if (!isset($expensesByCategoryUser['Salary'][$empName])) {
                    $expensesByCategoryUser['Salary'][$empName] = 0;
                }
                $expensesByCategoryUser['Salary'][$empName] += $slip->net_salary;
            }
        }
        
        arsort($expensesByCategory);
        
        $topCategories = [];
        $othersTotal = 0;
        $othersUsers = [];
        $count = 0;
        
        foreach ($expensesByCategory as $cat => $amount) {
            if ($count < 15 && $cat !== 'Uncategorized') {
                $usersInCat = $expensesByCategoryUser[$cat] ?? [];
                arsort($usersInCat);
                $topCategories[$cat] = [
                    'total' => $amount,
                    'users' => $usersInCat
                ];
                $count++;
            } else {
                $othersTotal += $amount;
                foreach (($expensesByCategoryUser[$cat] ?? []) as $uName => $uAmount) {
                    if (!isset($othersUsers[$uName])) $othersUsers[$uName] = 0;
                    $othersUsers[$uName] += $uAmount;
                }
            }
        }
        
        if ($othersTotal > 0) {
            arsort($othersUsers);
            $topCategories['Other Expenses'] = [
                'total' => $othersTotal,
                'users' => $othersUsers
            ];
        }
        
        // ── Phase 4: NF vs Khaas split for the Top Categories card ─
        // Bucket every expense by business unit, and within each
        // bucket re-build the same category → user drilldown
        // structure so the existing card UI can render BU-first
        // without any per-row server logic.
        $buBreakdown = [
            'NF'    => ['total' => 0.0, 'categories' => []],
            'KHAAS' => ['total' => 0.0, 'categories' => []],
        ];
        $buUserMap = [
            'NF'    => [],
            'KHAAS' => [],
        ];
        foreach ($allExpenses as $expense) {
            $expenseBuId = (int) ($expense->business_unit_id ?? 0);
            $bu = ($khaasBuId && $expenseBuId === $khaasBuId) ? 'KHAAS' : 'NF';
            // If user can't see Khaas, fold those rows away entirely
            // (they were already filtered out of the listing query
            // above, but we double-check here so the right-hand panel
            // never leaks a Khaas row through aggregation).
            if ($bu === 'KHAAS' && !$canViewKhaas) continue;

            $catName = $expense->expense_category;
            if (empty($catName) && $expense->category && $expense->category->category_code === 'salary_advance') {
                $catName = 'Salary Advance';
            } elseif (empty($catName)) {
                $catName = 'Uncategorized';
            }
            $buBreakdown[$bu]['total'] += (float) $expense->amount;
            if (!isset($buBreakdown[$bu]['categories'][$catName])) {
                $buBreakdown[$bu]['categories'][$catName] = 0.0;
            }
            $buBreakdown[$bu]['categories'][$catName] += (float) $expense->amount;

            $userName = $expense->requester ? $expense->requester->fullname : 'Unknown';
            if (!isset($buUserMap[$bu][$catName])) $buUserMap[$bu][$catName] = [];
            if (!isset($buUserMap[$bu][$catName][$userName])) $buUserMap[$bu][$catName][$userName] = 0.0;
            $buUserMap[$bu][$catName][$userName] += (float) $expense->amount;
        }
        // Salary slips → always count under NF (no BU concept).
        if ($totalSalaryExpenses > 0) {
            $buBreakdown['NF']['total'] += (float) $totalSalaryExpenses;
            if (!isset($buBreakdown['NF']['categories']['Salary'])) {
                $buBreakdown['NF']['categories']['Salary'] = 0.0;
            }
            $buBreakdown['NF']['categories']['Salary'] += (float) $totalSalaryExpenses;
            if (!isset($buUserMap['NF']['Salary'])) $buUserMap['NF']['Salary'] = [];
            foreach ($salarySlips as $slip) {
                $empName = $slip->employee ? $slip->employee->fullname : 'Unknown';
                if (!isset($buUserMap['NF']['Salary'][$empName])) $buUserMap['NF']['Salary'][$empName] = 0.0;
                $buUserMap['NF']['Salary'][$empName] += (float) $slip->net_salary;
            }
        }
        // Sort each BU's categories desc + flatten to a [cat → ['total'=>x, 'users'=>...]]
        // structure that the Blade template can render with the
        // existing nested drill-down UI.
        foreach ($buBreakdown as $bu => &$payload) {
            arsort($payload['categories']);
            $shaped = [];
            foreach ($payload['categories'] as $catName => $catTotal) {
                $usersInCat = $buUserMap[$bu][$catName] ?? [];
                arsort($usersInCat);
                $shaped[$catName] = [
                    'total' => $catTotal,
                    'users' => $usersInCat,
                ];
            }
            $payload['categories'] = $shaped;
        }
        unset($payload);
        if (!$canViewKhaas) {
            unset($buBreakdown['KHAAS']);
        }

        $kpis = [
            'total_expenses' => $totalExpenses,
            'from_expense_fund' => $fromExpenseFund,
            'needs_settlement' => $needsSettlement,
            'settled' => $settled,
            'pending_count' => $pendingSettlement->count(),
            'settled_count' => $settlementHistory->count(),
            'pending_approvals' => $pendingApprovals->sum('amount'),
            'pending_approvals_count' => $pendingApprovals->count(),
            'total_salary_expenses' => $totalSalaryExpenses, // For debugging/display
            'salary_slips_count' => $salarySlips->count(),
            'top_categories' => $topCategories, // Top 10 categories + others
            'bu_breakdown' => $buBreakdown,     // Phase 4 — NF vs Khaas
        ];
        
        // ⭐ Get business units for expense form dropdown - filtered by user's access
        $businessUnits = AccountModel::getUserAccessibleBusinessUnits();
        
        // ⭐ Get user's accessible company accounts for "Pay From" dropdown
        $accessibleCompanyAccounts = AccountModel::getAccessibleCompanyAccounts();
        
        // ⭐ Get user's default business unit ID
        $userDefaultBuId = AccountModel::getUserDefaultBusinessUnitId();
        
        $isTaimurRole = false;
        if (\Auth::check()) {
            $isTaimurRole = \DB::table('t_sys_user_role as ur')
                ->join('t_sys_role as r', 'r.id', '=', 'ur.role_id')
                ->where('ur.user_id', \Auth::id())
                ->whereRaw('LOWER(r.urole_name) = ?', ['taimur'])
                ->exists();
        }
        
        // Filter out private accounts for non-Taimur
        if (!$isTaimurRole) {
            $accessibleCompanyAccounts = $accessibleCompanyAccounts->filter(fn($a) => !$a->is_private);
        }
        
        // Get request types for filter dropdown
        try {
            $requestTypes = \App\Models\Request\RequestCategoryModel::where('show_in_expenses', 1)
                ->where('is_active', 1)
                ->whereIn('form_type', ['expense', 'salary'])
                ->orderBy('sequence_order')
                ->get(['category_code', 'category_name']);
        } catch (\Exception $e) {
            $requestTypes = collect([]);
        }

        // ── Qurbani-tab-only payload ───────────────────────────────
        // Computed only when on the Qurbani tab so the regular tab
        // pays no extra DB cost.
        //
        // May-2026 — switched from "delivered revenue" (status
        // history dependent) to "booked revenue" (every non-cancelled
        // qurbani order placed this year). Booked is then split into
        // Paid / Pending using the order-level `total_paid` column
        // that QurbaniWebController already trusts on the invoices
        // page. This way the operator can plan around what the season
        // is *committing* to bring in, not just what's already been
        // delivered.
        $qurbaniBooked = null;
        $qurbaniPaid = null;
        $qurbaniPending = null;
        $qurbaniVendorPurchases = null;
        $availableYears = null;
        $currentYear = null;
        if ($qurbaniMode) {
            $currentYear = (int) date('Y', strtotime($dateFrom));

            // Booked revenue base query — every non-cancelled qurbani
            // order created this year, excluding shopify mirrors.
            $bookedQuery = \DB::table('t_crm_prod_order as o')
                ->whereRaw('YEAR(o.created_at) = ?', [$currentYear])
                ->whereRaw("LOWER(COALESCE(o.order_status, '')) <> 'cancelled'")
                ->where(function ($q) {
                    $q->whereNull('o.external_source')
                      ->orWhere('o.external_source', '!=', 'shopify');
                });
            QurbaniFinanceFilter::applyToOrderQuery($bookedQuery, 'o', QurbaniFinanceFilter::MODE_INCLUDE);
            // Pull totals + paid in a single trip so the two numbers
            // are guaranteed to come from the same row set.
            $bookedTotals = $bookedQuery
                ->selectRaw('COALESCE(SUM(o.total_price), 0) as booked, COALESCE(SUM(o.total_paid), 0) as paid')
                ->first();
            $qurbaniBooked  = (float) ($bookedTotals->booked ?? 0);
            $qurbaniPaid    = (float) ($bookedTotals->paid ?? 0);
            $qurbaniPending = max(0.0, $qurbaniBooked - $qurbaniPaid);

            // Qurbani vendor purchases (per user's q1 — qurbani
            // vendors are separated). Tied to a Qurbani request OR a
            // Qurbani order via the canonical ledger filter.
            $vendorQuery = \DB::table('t_fin_ledger as l')
                ->where('l.transaction_type', 'vendor_purchase')
                ->where('l.approval_status', 'approved')
                ->whereRaw('DATE(l.transaction_date) >= ?', [$dateFrom])
                ->whereRaw('DATE(l.transaction_date) <= ?', [$dateTo]);
            QurbaniFinanceFilter::applyToLedgerQuery($vendorQuery, 'l', QurbaniFinanceFilter::MODE_INCLUDE);
            $qurbaniVendorPurchases = (float) $vendorQuery->sum('l.amount');

            // Year picker — list every year that has at least one
            // qurbani-tagged order or request, plus the current
            // year as a baseline.
            $orderYears = \DB::table('t_crm_prod_order as o')
                ->where(function ($q) {
                    $q->whereNull('o.external_source')
                      ->orWhere('o.external_source', '!=', 'shopify');
                })
                ->whereRaw("LOWER(COALESCE(o.order_status, '')) <> 'cancelled'");
            QurbaniFinanceFilter::applyToOrderQuery($orderYears, 'o', QurbaniFinanceFilter::MODE_INCLUDE);
            $orderYears = $orderYears
                ->selectRaw('DISTINCT YEAR(o.created_at) as y')
                ->pluck('y')
                ->all();
            $reqYears = \DB::table('t_req_master as r')
                ->join('t_req_category as c', 'c.id', '=', 'r.category_id')
                ->where('c.category_code', 'qurbani')
                ->whereNotNull('r.ledger_transaction_id')
                ->selectRaw('DISTINCT YEAR(COALESCE(r.expense_date, r.created_at)) as y')
                ->pluck('y')
                ->all();
            $availableYears = collect(array_merge([(int) date('Y')], $orderYears, $reqYears))
                ->filter(fn ($y) => is_numeric($y) && $y >= 2024 && $y <= 2099)
                ->map(fn ($y) => (int) $y)
                ->unique()
                ->sortDesc()
                ->values()
                ->all();
        }

        return view('fin.expense.index', compact(
            'allExpensesForDisplay',
            'allExpenses',
            'pendingSettlement',
            'settlementHistory',
            'kpis',
            'categories',
            'paymentSources',
            'settlementSources',
            'pendingApprovals',
            'dateFrom',
            'dateTo',
            'category',
            'paymentSource',
            'settlementStatus',
            'employeeFilter',
            'businessUnits',
            'accessibleCompanyAccounts',
            'userDefaultBuId',
            'isTaimurRole',
            'requestTypes',
            'requestType',
            'qurbaniMode',
            'qurbaniBooked',
            'qurbaniPaid',
            'qurbaniPending',
            'qurbaniVendorPurchases',
            'availableYears',
            'currentYear',
            'canViewKhaas',
            'buFilter'
        ));
    }
    
    /**
     * Settle a single expense
     */
    public function settle(Request $request, $id)
    {
        try {
            \Log::info("Settlement request received", [
                'expense_id' => $id,
                'request_data' => $request->all()
            ]);
            
            $validated = $request->validate([
                'settlement_source_account_id' => 'nullable|exists:t_fin_accounts,id',
                'notes' => 'nullable|string|max:1000'
            ]);
            
            \Log::info("Validation passed", ['validated' => $validated]);
            
            $expenseRequest = RequestModel::findOrFail($id);
            
            \Log::info("Expense request found", [
                'request_number' => $expenseRequest->request_number,
                'settlement_status' => $expenseRequest->settlement_status
            ]);
            
            $result = $this->settlementService->settleExpense(
                $expenseRequest,
                $validated['settlement_source_account_id'] ?? null,
                $validated['notes'] ?? null
            );
            
            \Log::info("Settlement service completed", ['result' => $result]);
            
            if ($result['success']) {
                return response()->json([
                    'success' => true,
                    'message' => $result['message']
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => $result['message']
                ], 400);
            }
        } catch (\Exception $e) {
            \Log::error("Settlement failed with exception", [
                'expense_id' => $id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Settlement failed: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Bulk settle multiple expenses
     */
    public function bulkSettle(Request $request)
    {
        $validated = $request->validate([
            'expense_ids' => 'required|array',
            'expense_ids.*' => 'exists:t_req_master,id',
            'settlement_source_account_id' => 'nullable|exists:t_fin_accounts,id',
            'notes' => 'nullable|string|max:1000'
        ]);
        
        $result = $this->settlementService->bulkSettle(
            $validated['expense_ids'],
            $validated['settlement_source_account_id'] ?? null,
            $validated['notes'] ?? null
        );
        
        if ($result['success']) {
            return response()->json([
                'success' => true,
                'message' => $result['message'],
                'details' => [
                    'success_count' => $result['success_count'],
                    'fail_count' => $result['fail_count'],
                    'errors' => $result['errors']
                ]
            ]);
        } else {
            return response()->json([
                'success' => false,
                'message' => $result['message'],
                'errors' => $result['errors']
            ], 400);
        }
    }
    
    /**
     * Get settlement details for modal
     */
    public function getSettlementDetails($id)
    {
        $expenseRequest = RequestModel::with([
            'requester', 
            'paymentSourceAccount', 
            'category',
            'settlementDestinationAccount'
        ])->findOrFail($id);
        
        // Determine destination account
        $destinationAccount = null;
        if ($expenseRequest->requester_user_id) {
            // Find rider's cash account
            $riderCashAccount = AccountModel::where('user_id', $expenseRequest->requester_user_id)
                ->where('account_category', 'employee_cash')
                ->first();
            
            if ($riderCashAccount) {
                $recentDeposit = LedgerModel::where('from_account_id', $riderCashAccount->id)
                    ->where('transaction_type', LedgerModel::TYPE_EMPLOYEE_DEPOSIT)
                    ->where('transaction_date', '<=', $expenseRequest->created_at)
                    ->orderBy('transaction_date', 'desc')
                    ->first();
                
                if ($recentDeposit) {
                    $destinationAccount = AccountModel::find($recentDeposit->to_account_id);
                }
            }
        }
        
        if (!$destinationAccount) {
            $destinationAccount = ConfigModel::getNFCashAccount();
        }
        
        return response()->json([
            'success' => true,
            'data' => [
                'request_number' => $expenseRequest->request_number,
                'employee' => $expenseRequest->requester ? ($expenseRequest->requester->fullname ?? $expenseRequest->requester->name) : 'Unknown',
                'category' => $expenseRequest->expense_category ?? ($expenseRequest->category ? $expenseRequest->category->category_name : 'Unknown'),
                'amount' => $expenseRequest->amount,
                'paid_from' => $expenseRequest->paymentSourceAccount ? $expenseRequest->paymentSourceAccount->account_name : 'Unknown',
                'destination' => $destinationAccount ? $destinationAccount->account_name : 'NF Cash',
                'date' => ($expenseRequest->expense_date ?? $expenseRequest->created_at)->format('Y-m-d')
            ]
        ]);
    }
    
    /**
     * Delete an expense request and reverse ledger entries
     * Only users with L2 approval rights can delete
     */
    public function destroy(Request $request, $id)
    {
        try {
            $user = auth()->user();
            
            Log::info('Delete expense attempt (web)', [
                'expense_id' => $id,
                'user_id' => $user->id,
                'user_name' => $user->fullname ?? $user->name
            ]);
            
            // Check if user has L2 approval rights (required for delete)
            $hasL2Rights = \App\Models\SysAdmin\RoleApprovalLevelModel::userHasApprovalLevel($user->id, 2);
            
            if (!$hasL2Rights) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only Level 2 approvers can delete expenses'
                ], 403);
            }
            
            $expenseRequest = RequestModel::with(['paymentSourceAccount', 'category'])
                ->findOrFail($id);
            
            // Check if expense is approved
            if ($expenseRequest->status !== RequestModel::STATUS_APPROVED) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only approved expenses can be deleted'
                ], 400);
            }
            
            $notes = $request->input('notes', '');
            
            DB::beginTransaction();
            
            // If expense has ledger entry, reverse it
            if ($expenseRequest->ledger_transaction_id) {
                $ledger = LedgerModel::find($expenseRequest->ledger_transaction_id);
                
                if ($ledger && $ledger->approval_status === LedgerModel::STATUS_APPROVED) {
                    // Reverse account balances
                    $fromAccount = $ledger->fromAccount;
                    $toAccount = $ledger->toAccount;
                    
                    if ($fromAccount) {
                        // Add amount back to from_account (was deducted)
                        $fromAccount->current_balance += $ledger->amount;
                        $fromAccount->save();
                        
                        Log::info("Reversed from_account balance", [
                            'account_id' => $fromAccount->id,
                            'account_name' => $fromAccount->account_name,
                            'amount_added' => $ledger->amount,
                            'new_balance' => $fromAccount->current_balance
                        ]);
                    }
                    
                    if ($toAccount) {
                        // Subtract amount from to_account (was added)
                        $toAccount->current_balance -= $ledger->amount;
                        $toAccount->save();
                        
                        Log::info("Reversed to_account balance", [
                            'account_id' => $toAccount->id,
                            'account_name' => $toAccount->account_name,
                            'amount_subtracted' => $ledger->amount,
                            'new_balance' => $toAccount->current_balance
                        ]);
                    }
                    
                    // Mark ledger entry as reversed
                    $ledger->approval_status = LedgerModel::STATUS_REVERSED;
                    $ledger->comments = ($ledger->comments ? $ledger->comments . "\n" : '') . 
                        "DELETED by {$user->fullname} on " . now()->format('Y-m-d H:i:s') . 
                        ($notes ? " - Reason: {$notes}" : '');
                    $ledger->save();
                }
            }
            
            // If expense has settlement transaction, reverse it too
            if ($expenseRequest->settlement_transaction_id) {
                $settlementLedger = LedgerModel::find($expenseRequest->settlement_transaction_id);
                
                if ($settlementLedger && $settlementLedger->approval_status === LedgerModel::STATUS_APPROVED) {
                    // Reverse settlement balances
                    $fromAccount = $settlementLedger->fromAccount;
                    $toAccount = $settlementLedger->toAccount;
                    
                    if ($fromAccount) {
                        $fromAccount->current_balance += $settlementLedger->amount;
                        $fromAccount->save();
                    }
                    
                    if ($toAccount) {
                        $toAccount->current_balance -= $settlementLedger->amount;
                        $toAccount->save();
                    }
                    
                    $settlementLedger->approval_status = LedgerModel::STATUS_REVERSED;
                    $settlementLedger->comments = ($settlementLedger->comments ? $settlementLedger->comments . "\n" : '') . 
                        "DELETED (settlement reversal) by {$user->fullname} on " . now()->format('Y-m-d H:i:s');
                    $settlementLedger->save();
                    
                    Log::info("Reversed settlement transaction", [
                        'settlement_ledger_id' => $settlementLedger->id,
                        'amount' => $settlementLedger->amount
                    ]);
                }
            }
            
            // Mark request as deleted/cancelled
            $expenseRequest->status = 'cancelled';
            $expenseRequest->rejection_reason = "DELETED by {$user->fullname} on " . now()->format('Y-m-d H:i:s') . 
                ($notes ? " - Reason: {$notes}" : '');
            $expenseRequest->updated_by = $user->id;
            $expenseRequest->save();
            
            DB::commit();
            
            Log::info('Expense deleted successfully (web)', [
                'expense_id' => $id,
                'request_number' => $expenseRequest->request_number,
                'amount' => $expenseRequest->amount,
                'deleted_by' => $user->id,
                'ledger_reversed' => $expenseRequest->ledger_transaction_id ? true : false,
                'settlement_reversed' => $expenseRequest->settlement_transaction_id ? true : false
            ]);
            
            return response()->json([
                'success' => true,
                'message' => "Expense #{$expenseRequest->request_number} deleted successfully. Ledger entries reversed."
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Expense delete failed (web)', [
                'expense_id' => $id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete expense: ' . $e->getMessage()
            ], 500);
        }
    }
}

