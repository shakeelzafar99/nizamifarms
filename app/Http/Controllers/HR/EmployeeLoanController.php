<?php

namespace App\Http\Controllers\HR;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\HR\EmployeeLoanModel;
use App\Models\HR\EmployeeProfileModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class EmployeeLoanController extends Controller
{
    /**
     * ⚠ STAGED ROLLOUT (Aug-27-2026). A loan paid out of a BANK account must name
     * WHICH bank or the per-bank balances never see it (BankAttributionService).
     * The store-mode loan form does not send one until the next APK, so this warns
     * rather than refuses. Flip to true once that APK is out.
     * Same pattern as RequestApprovalController::ENFORCE_APPROVE_BANK.
     */
    private const ENFORCE_LOAN_BANK = false;

    /**
     * Display list of all loans
     */
    public function index(Request $request)
    {
        // For API requests
        if ($request->wantsJson() || $request->has('api')) {
            return $this->getData($request);
        }

        // For web view
        return view('pages.hr.loans.index');
    }

    /**
     * Get loans data (API)
     */
    public function getData(Request $request)
    {
        $query = EmployeeLoanModel::with(['employee', 'creator']);

        // Filter by user
        if ($request->filled('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        // Filter by status
        if ($request->filled('status')) {
            $query->where('loan_status', $request->status);
        }

        // Search
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('loan_number', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%")
                  ->orWhereHas('employee', function($empQuery) use ($search) {
                      $empQuery->where('fullname', 'like', "%{$search}%");
                  });
            });
        }

        // Order
        $query->orderBy('loan_date', 'desc');

        // Get all loans (not paginated)
        $allLoans = $query->get();

        // Transform data
        $loansData = $allLoans->map(function($loan) {
            return [
                'id' => $loan->id,
                'loan_number' => $loan->loan_number,
                'employee' => [
                    'fullname' => $loan->employee->fullname ?? 'Unknown',
                    'email' => $loan->employee->email ?? ''
                ],
                'user_id' => $loan->user_id,
                'loan_date' => $loan->loan_date,
                'loan_type' => $loan->loan_type,
                'principal_amount' => (float) $loan->principal_amount,
                'monthly_installment' => (float) $loan->monthly_installment,
                'outstanding_balance' => (float) $loan->outstanding_balance,
                'loan_status' => $loan->loan_status,
                'description' => $loan->description,
                'terms' => $loan->terms,
                'notes' => $loan->notes
            ];
        });

        // Calculate statistics
        $statistics = [
            'active' => $allLoans->where('loan_status', 'active')->count(),
            'completed' => $allLoans->where('loan_status', 'completed')->count(),
            'cancelled' => $allLoans->where('loan_status', 'cancelled')->count(),
            'total_outstanding' => $allLoans->where('loan_status', 'active')->sum('outstanding_balance'),
            'total_disbursed' => $allLoans->whereIn('loan_status', ['active', 'completed'])->sum('principal_amount')
        ];

        return response()->json([
            'success' => true,
            'loans' => $loansData,
            'statistics' => $statistics
        ]);
    }

    /**
     * Show form to create new loan
     */
    public function create()
    {
        // Get all employees with salary profiles (excluding admins)
        $employees = EmployeeProfileModel::with('user')
            ->active()
            ->get()
            ->map(function($profile) {
                return [
                    'user_id' => $profile->user_id,
                    'name' => $profile->user->fullname ?? 'Unknown',
                    'email' => $profile->user->email ?? '',
                    'current_loans' => $profile->activeLoans()->count(),
                    'total_outstanding' => $profile->getTotalActiveLoanBalance()
                ];
            });

        return view('pages.hr.loans.create', compact('employees'));
    }

    /**
     * Store new loan
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'user_id' => 'required|integer|exists:t_sys_user,id',
            'loan_date' => 'required|date',
            'principal_amount' => 'required|numeric|min:0',
            'monthly_installment' => 'required|numeric|min:0',
            'loan_type' => 'nullable|string|max:100',
            'description' => 'nullable|string|max:1000',
            'terms' => 'nullable|string|max:2000',
            'notes' => 'nullable|string|max:1000',
            'disburse_via_ledger' => 'nullable|boolean',
            'disbursement_account_id' => 'nullable|integer|exists:t_fin_accounts,id', // ⭐ Source account
            // 🏦 WHICH bank a loan disbursed from an online account left from.
            'receiving_account_id' => 'nullable|integer|exists:t_fin_online_receiving_accounts,id',
            'is_outside_cash' => 'nullable|boolean' // ⭐ If true, skip ledger
        ]);

        try {
            DB::beginTransaction();

            // Generate loan number
            $loanNumber = EmployeeLoanModel::generateLoanNumber();
            
            // ⭐ Determine disbursement source
            $isOutsideCash = $request->input('is_outside_cash', false);
            $disbursementAccountId = null;
            $disbursementBankId = null;   // 🏦 which bank, when the source is one

            if (!$isOutsideCash) {
                // Get disbursement account - use provided or default to NF_CASH
                $disbursementAccountId = $validated['disbursement_account_id'] ?? null;
                if (!$disbursementAccountId) {
                    $nfCash = DB::table('t_fin_accounts')->where('account_code', 'NF_CASH')->first();
                    $disbursementAccountId = $nfCash?->id;
                }

                // 🏦 Aug-27-2026: a loan handed out of an ONLINE account posted a bank
                // outflow that named no bank, so BankBalanceService never counted it and
                // the per-bank split drifted by the principal. The picker behind this
                // (getDisbursementAccounts) offers bank accounts by design, so the bank
                // has to travel with the amount.
                //
                // ⚠ STAGED like RiderController::ENFORCE_ADVANCE_BANK — the APK in the
                // field sends no bank yet, and refusing here would stop loans being
                // issued the day the web files go up.
                $bankSvc = app(\App\Services\FIN\BankAttributionService::class);
                $disbursementBankId = $validated['receiving_account_id'] ?? null;
                $problem = $bankSvc->problemWith($disbursementAccountId, $disbursementBankId);
                if ($problem) {
                    if (self::ENFORCE_LOAN_BANK) {
                        DB::rollBack();
                        return response()->json(['success' => false, 'message' => $problem], 422);
                    }
                    Log::warning('Loan disbursed without naming a bank', [
                        'disbursement_account_id' => $disbursementAccountId,
                        'bank_id' => $disbursementBankId,
                        'problem' => $problem,
                        'created_by' => auth()->id(),
                    ]);
                    $disbursementBankId = null;      // never store a half-valid pairing
                }
                $disbursementBankId = $bankSvc->bankIdToStore($disbursementAccountId, $disbursementBankId);
            }

            // Create loan
            $loan = EmployeeLoanModel::create([
                'user_id' => $validated['user_id'],
                'loan_date' => $validated['loan_date'],
                'loan_number' => $loanNumber,
                'principal_amount' => $validated['principal_amount'],
                'monthly_installment' => $validated['monthly_installment'],
                'outstanding_balance' => $validated['principal_amount'], // Initially equals principal
                'loan_status' => EmployeeLoanModel::STATUS_ACTIVE,
                'loan_type' => $validated['loan_type'] ?? 'Personal Loan',
                'description' => $validated['description'] ?? null,
                'terms' => $validated['terms'] ?? null, // ⭐ Fixed: use null coalescing
                'notes' => $validated['notes'] ?? null, // ⭐ Fixed: use null coalescing
                'disbursement_account_id' => $disbursementAccountId, // ⭐ Track source
                'created_by' => auth()->id()
            ]);

            // ⭐ If NOT outside cash, create ledger entry
            $shouldPostToLedger = !$isOutsideCash && $request->input('disburse_via_ledger', !$isOutsideCash);
            if ($shouldPostToLedger && $disbursementAccountId) {
                $ledgerEntry = $this->createLoanDisbursementLedger($loan, $disbursementAccountId, $disbursementBankId);
                if ($ledgerEntry) {
                    $loan->ledger_transaction_id = $ledgerEntry->id;
                    $loan->save();
                }
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Employee loan created successfully',
                'loan' => $loan->getLoanSummary()
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Error creating employee loan', [
                'error' => $e->getMessage(),
                'user_id' => $validated['user_id']
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to create loan: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Show loan details
     */
    public function show($id)
    {
        $loan = EmployeeLoanModel::with([
            'employee',
            'creator',
            'payments.salarySlip'
        ])->findOrFail($id);

        $loanSummary = $loan->getLoanSummary();
        $payments = $loan->payments()->recent()->get()->map(function($payment) {
            return $payment->getPaymentInfo();
        });

        if (request()->wantsJson()) {
            return response()->json([
                'success' => true,
                'loan' => $loanSummary,
                'payments' => $payments
            ]);
        }

        return view('pages.hr.loans.show', compact('loan', 'loanSummary', 'payments'));
    }

    /**
     * Update loan
     */
    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'monthly_installment' => 'nullable|numeric|min:0',
            'loan_type' => 'nullable|string|max:100',
            'description' => 'nullable|string|max:1000',
            'terms' => 'nullable|string|max:2000',
            'notes' => 'nullable|string|max:1000'
        ]);

        try {
            $loan = EmployeeLoanModel::findOrFail($id);

            // Only allow updating certain fields
            if (isset($validated['monthly_installment'])) {
                $loan->monthly_installment = $validated['monthly_installment'];
            }
            if (isset($validated['loan_type'])) {
                $loan->loan_type = $validated['loan_type'];
            }
            if (isset($validated['description'])) {
                $loan->description = $validated['description'];
            }
            if (isset($validated['terms'])) {
                $loan->terms = $validated['terms'];
            }
            if (isset($validated['notes'])) {
                $loan->notes = $validated['notes'];
            }

            $loan->updated_by = auth()->id();
            $loan->save();

            return response()->json([
                'success' => true,
                'message' => 'Loan updated successfully',
                'loan' => $loan->getLoanSummary()
            ]);

        } catch (\Exception $e) {
            Log::error('Error updating loan', [
                'loan_id' => $id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to update loan: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Cancel loan
     * ⭐ If loan was from company account, refunds remaining balance
     */
    public function cancel(Request $request, $id)
    {
        $validated = $request->validate([
            'cancellation_reason' => 'required|string|max:500'
        ]);

        try {
            DB::beginTransaction();
            
            $loan = EmployeeLoanModel::findOrFail($id);

            if (!$loan->isActive()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only active loans can be cancelled'
                ], 400);
            }
            
            $refundAmount = $loan->outstanding_balance;
            $refundedToAccount = null;
            
            // ⭐ If loan was disbursed from a company account, refund remaining balance
            if ($loan->disbursement_account_id && $refundAmount > 0) {
                $sourceAccount = DB::table('t_fin_accounts')
                    ->where('id', $loan->disbursement_account_id)
                    ->first();
                
                if ($sourceAccount) {
                    // Create refund ledger entry
                    $loansReceivableAccount = DB::table('t_fin_accounts')
                        ->where('account_code', 'ASSET_EMPLOYEE_LOANS')
                        ->first();
                    
                    $employeeCashAccount = DB::table('t_fin_accounts')
                        ->where('user_id', $loan->user_id)
                        ->where('account_category', 'employee_cash')
                        ->first();
                    
                    $refundLedgerId = DB::table('t_fin_ledger')->insertGetId([
                        'transaction_date' => now()->toDateString(),
                        'transaction_type' => 'loan_cancellation_refund',
                        'description' => 'Loan cancelled - Refund remaining balance - ' . $loan->loan_number,
                        'from_account_id' => $employeeCashAccount?->id,
                        'to_account_id' => $sourceAccount->id,
                        'amount' => $refundAmount,
                        'mode' => 'adjustment',
                        'approval_status' => 'approved',
                        'approval_date' => now(),
                        'approved_by' => auth()->id(),
                        'external_source' => 'hr_loan_system',
                        'external_ref_id' => $loan->loan_number,
                        'comments' => 'Loan cancellation refund - ' . $validated['cancellation_reason'],
                        'created_at' => now(),
                        'created_by' => auth()->id()
                    ]);

                    // Apply via the canonical engine (source +; stamps balance_updated). The
                    // employee-cash leg is skipped by the engine's employee-skip list — same net
                    // effect as the old inline code, which never touched it either.
                    $refundRow = \App\Models\FIN\LedgerModel::find($refundLedgerId);
                    if ($refundRow) {
                        (new \App\Services\FIN\BalancePostingService())->apply($refundRow);
                    }

                    // Decrease loans receivable — third account, outside the two-sided row (see
                    // the disbursement note).
                    if ($loansReceivableAccount) {
                        DB::table('t_fin_accounts')
                            ->where('id', $loansReceivableAccount->id)
                            ->decrement('current_balance', $refundAmount);
                    }
                    
                    $refundedToAccount = $sourceAccount->account_name;
                    
                    Log::info('Loan cancellation refund processed', [
                        'loan_id' => $loan->id,
                        'loan_number' => $loan->loan_number,
                        'refund_amount' => $refundAmount,
                        'refunded_to' => $refundedToAccount,
                    ]);
                }
            }

            $loan->cancel($validated['cancellation_reason'], auth()->id());
            
            DB::commit();

            $message = 'Loan cancelled successfully';
            if ($refundedToAccount) {
                $message .= ". Rs. " . number_format($refundAmount) . " refunded to {$refundedToAccount}";
            }

            return response()->json([
                'success' => true,
                'message' => $message,
                'refund_amount' => $refundAmount,
                'refunded_to' => $refundedToAccount,
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Error cancelling loan', [
                'loan_id' => $id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to cancel loan: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Create ledger entry for loan disbursement
     * @param EmployeeLoanModel $loan
     * @param int|null $sourceAccountId - The account to disburse from (defaults to NF_CASH)
     */
    /**
     * @param ?int $bankId 🏦 Which of OUR banks an ONLINE disbursement left from.
     *                     Null for cash. Without it the row is invisible to the
     *                     per-bank balances — see BankAttributionService.
     */
    protected function createLoanDisbursementLedger(EmployeeLoanModel $loan, ?int $sourceAccountId = null, ?int $bankId = null)
    {
        try {
            // Get employee cash account
            $employeeCashAccount = DB::table('t_fin_accounts')
                ->where('user_id', $loan->user_id)
                ->where('account_category', 'employee_cash')
                ->first();

            // ⭐ Get source account (provided or default to NF_CASH)
            $sourceAccount = null;
            if ($sourceAccountId) {
                $sourceAccount = DB::table('t_fin_accounts')->where('id', $sourceAccountId)->first();
            }
            if (!$sourceAccount) {
                $sourceAccount = DB::table('t_fin_accounts')->where('account_code', 'NF_CASH')->first();
            }

            // Get loans receivable account
            $loansReceivableAccount = DB::table('t_fin_accounts')
                ->where('account_code', 'ASSET_EMPLOYEE_LOANS')
                ->first();

            if (!$employeeCashAccount || !$sourceAccount || !$loansReceivableAccount) {
                Log::warning('Missing accounts for loan disbursement', [
                    'employee_cash' => $employeeCashAccount ? 'found' : 'missing',
                    'source_account' => $sourceAccount ? 'found' : 'missing',
                    'loans_receivable' => $loansReceivableAccount ? 'found' : 'missing',
                ]);
                return null;
            }

            // Create ledger transaction
            $ledgerId = DB::table('t_fin_ledger')->insertGetId([
                'transaction_date' => $loan->loan_date,
                'transaction_type' => 'loan_disbursement',
                'description' => 'Loan disbursement - ' . $loan->employee->fullname . ' - ' . $loan->loan_type,
                'from_account_id' => $sourceAccount->id,
                'to_account_id' => $employeeCashAccount->id,
                'amount' => $loan->principal_amount,
                // ⭐ 'cash' was hardcoded even when the money left a bank account, so
                // the row described itself wrongly to every mode-filtered report. Keyed
                // off the ACCOUNT, not off $bankId, so it stays truthful even on the
                // staged path where the bank tag has not arrived yet. The bank math does
                // NOT read mode (it reads the accounts + the tag) — this is a
                // truthfulness fix, not the drift fix.
                'mode' => ($sourceAccount->account_category ?? null) === 'bank' ? 'online' : 'cash',
                // 🏦 Which of OUR banks it left from. Null for cash — a cash row must
                // never carry a bank tag or it credits a bank nothing moved through.
                'receiving_account_id' => $bankId,
                'approval_status' => 'approved',
                'approval_date' => now(),
                'approved_by' => auth()->id(),
                'external_source' => 'hr_loan_system',
                'external_ref_id' => $loan->loan_number,
                'comments' => 'Loan disbursement from: ' . $sourceAccount->account_name,
                'created_at' => now(),
                'created_by' => auth()->id()
            ]);

            // Apply the row via the canonical engine (source −; stamps balance_updated). The
            // employee-cash leg is AUTOMATICALLY skipped — loan_disbursement is on the engine's
            // employee-skip list, enforcing the charter that loans are personal money TO the
            // employee, not company cash they hold (same net effect as the old inline code).
            $ledgerRow = \App\Models\FIN\LedgerModel::find($ledgerId);
            if ($ledgerRow) {
                (new \App\Services\FIN\BalancePostingService())->apply($ledgerRow);
            }

            // Loans receivable increases (asset). This is a THIRD account the two-sided ledger row
            // cannot carry, so it stays a manual bump — receivable is an asset-register figure, not
            // part of the cash/bank reconciliation the balance_updated flag guards.
            DB::table('t_fin_accounts')
                ->where('id', $loansReceivableAccount->id)
                ->increment('current_balance', $loan->principal_amount);

            return DB::table('t_fin_ledger')->find($ledgerId);

        } catch (\Exception $e) {
            Log::error('Error creating loan disbursement ledger', [
                'loan_id' => $loan->id,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Get payment history for a loan
     */
    public function getPaymentHistory($id)
    {
        $loan = EmployeeLoanModel::findOrFail($id);
        
        $payments = $loan->payments()
            ->with('salarySlip')
            ->recent()
            ->get()
            ->map(function($payment) {
                return $payment->getPaymentInfo();
            });

        return response()->json([
            'success' => true,
            'payments' => $payments
        ]);
    }
}

