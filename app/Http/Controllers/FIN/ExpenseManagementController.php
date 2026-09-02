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
        // ⭐ Use expense_date for filtering (falls back to created_at for old records).
        //
        // Sep-2026 — salary advances are the one exception: they belong to a PAYROLL MONTH,
        // which is not always the month the money moved (an advance given forward is paid now
        // but recovered from next month's salary). They are listed under that payroll month,
        // using the SAME month expression HQ and the Reports page cost them by, so a manager
        // filtering August sees exactly the advances August's wage bill was charged for.
        // Every other request type is unchanged.
        if ($dateFrom && $dateTo) {
            $monthExpr = \App\Services\HR\SalaryCostService::monthExpr('t_req_master');
            $fromMonth = date('Y-m', strtotime($dateFrom));
            $toMonth   = date('Y-m', strtotime($dateTo));
            $expensesQuery->where(function ($q) use ($dateFrom, $dateTo, $monthExpr, $fromMonth, $toMonth) {
                // Salary advances → by payroll month.
                $q->where(function ($adv) use ($monthExpr, $fromMonth, $toMonth) {
                    $adv->whereHas('category', fn ($c) => $c->where('category_code', 'salary_advance'))
                        ->whereRaw("$monthExpr >= ?", [$fromMonth])
                        ->whereRaw("$monthExpr <= ?", [$toMonth]);
                })
                // Everything else → by the date the money moved, exactly as before.
                ->orWhere(function ($oth) use ($dateFrom, $dateTo) {
                    $oth->whereHas('category', fn ($c) => $c->where('category_code', '!=', 'salary_advance'))
                        ->whereRaw('DATE(COALESCE(expense_date, created_at)) >= ?', [$dateFrom])
                        ->whereRaw('DATE(COALESCE(expense_date, created_at)) <= ?', [$dateTo]);
                });
            });
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
        
        // Salaries are in scope when no category filter (or the "Salary" filter) is set,
        // and never on the Qurbani tab (qurbani staff are paid via request sub-categories).
        $salaryInScope = (!$category || strtolower($category) === 'salary') && !$qurbaniMode;

        // Salary SLIPS carry no business_unit_id → they are NF-only; hide them in a KHAAS
        // drill so the Khaas total isn't inflated by all-staff salaries.
        $includeSalarySlips = $salaryInScope && $buFilter !== 'KHAAS';

        $salarySlips = $includeSalarySlips ? $salarySlipsQuery->orderBy('created_at', 'desc')->get() : collect([]);

        // ── New Payroll screen payments (Phase G) ──────────────────
        // t_hr_payroll_payment (the Payroll screen) replaced salary slips. Surface each
        // paid salary as a "Salary" expense row — cash-basis (net actually paid, by paid_at).
        // Unlike slips, payroll rows CAN carry a business_unit_id (Khaas employees are tagged
        // on the Payroll screen), so they appear in the matching BU drill instead of always
        // counting as NF.
        $payrollHasBu = false;
        try {
            $payrollHasBu = \Illuminate\Support\Facades\Schema::hasColumn('t_hr_payroll_payment', 'business_unit_id');
        } catch (\Throwable $e) { $payrollHasBu = false; }

        // Before the BU column exists, every payroll row is effectively NF, so a KHAAS drill
        // shows none (matches the pre-tagging behaviour exactly).
        $includePayroll = $salaryInScope && ($payrollHasBu || $buFilter !== 'KHAAS');

        $payrollPayments = collect([]);
        if ($includePayroll) {
            $selectCols = ['p.id', 'p.user_id', 'p.net_salary', 'p.pay_month', 'p.paid_at', 'p.created_at', 'u.fullname'];
            if ($payrollHasBu) { $selectCols[] = 'p.business_unit_id'; }
            $payrollQuery = DB::table('t_hr_payroll_payment as p')
                ->leftJoin('t_sys_user as u', 'u.id', '=', 'p.user_id')
                ->where('p.status', 'paid');
            if ($dateFrom && $dateTo) {
                $payrollQuery->whereRaw('DATE(p.paid_at) >= ?', [$dateFrom])
                             ->whereRaw('DATE(p.paid_at) <= ?', [$dateTo]);
            }
            // BU scope — mirror the regular-expense rules (NULL = NF).
            if ($payrollHasBu) {
                if ($buFilter === 'NF' && $nfBuId) {
                    $payrollQuery->where(function ($q) use ($nfBuId) {
                        $q->where('p.business_unit_id', $nfBuId)->orWhereNull('p.business_unit_id');
                    });
                } elseif ($buFilter === 'KHAAS' && $khaasBuId) {
                    $payrollQuery->where('p.business_unit_id', $khaasBuId);
                }
                if (!$canViewKhaas && $khaasBuId) {
                    $payrollQuery->where(function ($q) use ($khaasBuId) {
                        $q->where('p.business_unit_id', '!=', $khaasBuId)->orWhereNull('p.business_unit_id');
                    });
                }
            }
            $payrollPayments = $payrollQuery->orderBy('p.paid_at', 'desc')->get($selectCols);
        }

        // Salary total = slips (NF-only) + payroll (BU-scoped above). Every downstream
        // "Salary" aggregation (totals, category card, BU split) reads these.
        $totalPayrollExpenses = $payrollPayments->sum('net_salary');
        $totalSalaryExpenses = $salarySlips->sum('net_salary') + $totalPayrollExpenses;

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
        
        // Transform payroll payments to the same expense shape for unified display.
        $payrollForDisplay = $payrollPayments->map(function ($p) {
            $when = $p->paid_at ?? $p->created_at;
            return (object) [
                'id' => 'PAYROLL-' . $p->id,
                'payroll_id' => $p->id,
                'type' => 'salary',
                'request_number' => 'PAY-' . ($p->pay_month ?? '') . '-' . $p->user_id,
                'created_at' => $when ? \Carbon\Carbon::parse($when) : now(),
                'requester' => (object) ['fullname' => $p->fullname ?? 'Unknown'],
                'requester_user_id' => $p->user_id,
                'category' => (object) ['category_name' => 'Salary Payment', 'category_code' => 'salary'],
                'expense_category' => 'Salary',
                'amount' => $p->net_salary,
                'paymentSourceAccount' => (object) ['account_name' => 'Payroll'],
                'payment_source_account_id' => null,
                'settlement_status' => 'not_applicable',
                'status' => 'paid',
                'ledger_transaction_id' => null,
            ];
        });

        // Merge expenses, salary slips and payroll payments for unified display
        $allExpensesForDisplay = $allExpenses->concat($salarySlipsForDisplay)->concat($payrollForDisplay)->sortByDesc('created_at');
        
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
            foreach ($payrollPayments as $pp) {
                $empName = $pp->fullname ?? 'Unknown';
                if (!isset($expensesByCategoryUser['Salary'][$empName])) {
                    $expensesByCategoryUser['Salary'][$empName] = 0;
                }
                $expensesByCategoryUser['Salary'][$empName] += $pp->net_salary;
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
        // Salary slips → always count under NF (they predate the BU concept).
        if ($salarySlips->count() > 0) {
            $slipTotal = (float) $salarySlips->sum('net_salary');
            $buBreakdown['NF']['total'] += $slipTotal;
            $buBreakdown['NF']['categories']['Salary'] = ($buBreakdown['NF']['categories']['Salary'] ?? 0.0) + $slipTotal;
            if (!isset($buUserMap['NF']['Salary'])) $buUserMap['NF']['Salary'] = [];
            foreach ($salarySlips as $slip) {
                $empName = $slip->employee ? $slip->employee->fullname : 'Unknown';
                $buUserMap['NF']['Salary'][$empName] = ($buUserMap['NF']['Salary'][$empName] ?? 0.0) + (float) $slip->net_salary;
            }
        }
        // Payroll payments → bucket each by its STAMPED business unit (Khaas employees'
        // salaries land on the Khaas side; NULL / NF id count as NF).
        foreach ($payrollPayments as $pp) {
            $ppBuId = $payrollHasBu ? (int) ($pp->business_unit_id ?? 0) : 0;
            $ppBu = ($khaasBuId && $ppBuId === $khaasBuId) ? 'KHAAS' : 'NF';
            if ($ppBu === 'KHAAS' && !$canViewKhaas) continue; // safety (already filtered in the query)
            $empName = $pp->fullname ?? 'Unknown';
            $buBreakdown[$ppBu]['total'] += (float) $pp->net_salary;
            $buBreakdown[$ppBu]['categories']['Salary'] = ($buBreakdown[$ppBu]['categories']['Salary'] ?? 0.0) + (float) $pp->net_salary;
            if (!isset($buUserMap[$ppBu]['Salary'])) $buUserMap[$ppBu]['Salary'] = [];
            $buUserMap[$ppBu]['Salary'][$empName] = ($buUserMap[$ppBu]['Salary'][$empName] ?? 0.0) + (float) $pp->net_salary;
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
            'total_salary_expenses' => $totalSalaryExpenses, // For debugging/display (slips + payroll)
            'salary_slips_count' => $salarySlips->count() + $payrollPayments->count(),
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

            // ⛔ SALARY ADVANCES ARE NOT DELETABLE HERE. This endpoint is category-blind and
            // blindly reverses `settlement_transaction_id` — which on a payroll-settled advance
            // points at the WHOLE MONTH'S salary_payment ledger row (shared by every advance
            // that pay recovered). Deleting one advance would un-post an entire salary while
            // t_hr_payroll_payment still reads "paid". Advances are voided from Payroll only
            // (owner-gated PayrollService::voidAdvance, which refuses settled ones outright).
            if ($expenseRequest->category && $expenseRequest->category->category_code === 'salary_advance') {
                return response()->json([
                    'success' => false,
                    'message' => 'Salary advances cannot be deleted here — void it from the Payroll page instead.'
                ], 403);
            }

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
                    // Reverse balances via the canonical engine (funding +, expense −); self-guards on
                    // balance_updated and clears it — fixing the old bug where a reversed row kept
                    // flag=1. REQUIRES the expense flag backfill for legacy rows applied with flag=0.
                    (new \App\Services\FIN\BalancePostingService())->reverse($ledger);

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
                    // Reverse settlement balances via the canonical engine (EXP_FUND +, till −);
                    // self-guards on balance_updated and clears it.
                    (new \App\Services\FIN\BalancePostingService())->reverse($settlementLedger);

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

