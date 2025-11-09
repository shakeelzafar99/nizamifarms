<?php

namespace App\Http\Controllers\FIN;

use App\Http\Controllers\Controller;
use App\Models\CRM\OrderModel;
use App\Models\FIN\LedgerModel;
use App\Models\FIN\AccountModel;
use App\Services\FIN\LedgerPostingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class LedgerAuditController extends Controller
{
    /**
     * Get audit report for ledger integrity
     * Detects:
     * 1. Delivered orders without ledger entries
     * 2. Approved settlement deposits where invoice settlement failed
     * 3. Other ledger inconsistencies
     */
    public function getAuditReport(Request $request)
    {
        try {
            // Get date filters (default: from Nov 1, 2025 onwards)
            $startDate = $request->input('start_date', '2025-11-01');
            $endDate = $request->input('end_date', now()->format('Y-m-d'));
            
            $issues = [];
            
            // ================================================================
            // ISSUE 1: Delivered Orders WITHOUT Ledger Entries
            // ================================================================
            $missingInvoices = OrderModel::where('order_status', 'delivered')
                ->whereNull('ledger_transaction_id')
                ->whereDate('order_date', '>=', $startDate)
                ->whereDate('order_date', '<=', $endDate)
                ->with(['customer', 'assignedRider'])
                ->orderBy('order_date', 'desc')
                ->get();
            
            $missingInvoicesList = $missingInvoices->map(function($order) {
                return [
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'order_date' => $order->order_date,
                    'customer_name' => $order->customer->name ?? 'N/A',
                    'rider_name' => $order->assignedRider->fullname ?? 'N/A',
                    'rider_id' => $order->assigned_rider_user_id,
                    'amount' => $order->total_price,
                    'payment_method' => $order->payment_method,
                    'external_source' => $order->external_source
                ];
            });
            
            if ($missingInvoices->count() > 0) {
                $issues[] = [
                    'type' => 'missing_invoice_ledger',
                    'severity' => 'high',
                    'title' => 'Delivered Orders Without Ledger Entries',
                    'description' => "Found {$missingInvoices->count()} delivered order(s) that don't have ledger entries. These should have TYPE_INVOICE entries created.",
                    'count' => $missingInvoices->count(),
                    'items' => $missingInvoicesList,
                    'can_auto_fix' => true
                ];
            }
            
            // ================================================================
            // ISSUE 2: Approved Settlement Deposits with Unsettled Invoices
            // ================================================================
            $incompleteSettlements = LedgerModel::where('transaction_type', LedgerModel::TYPE_EMPLOYEE_DEPOSIT)
                ->where('approval_status', LedgerModel::STATUS_APPROVED)
                ->where(function($q) {
                    $q->where('description', 'LIKE', '%Settlement%')
                      ->orWhere('description', 'LIKE', '%Partial Payment%');
                })
                ->whereNotNull('settlement_metadata')
                ->whereDate('transaction_date', '>=', $startDate)
                ->whereDate('transaction_date', '<=', $endDate)
                ->get();
            
            $incompleteSettlementsList = [];
            
            foreach ($incompleteSettlements as $deposit) {
                $metadata = $deposit->settlement_metadata;
                
                if (!isset($metadata['invoice_ids']) || !is_array($metadata['invoice_ids'])) {
                    continue;
                }
                
                // Check if any of these invoices are still not properly settled
                $invoices = LedgerModel::whereIn('id', $metadata['invoice_ids'])->get();
                $expectedTotal = $metadata['deposit_amount'] ?? 0;
                if (isset($metadata['is_short_cash_settlement']) && $metadata['is_short_cash_settlement']) {
                    $expectedTotal += ($metadata['short_cash_amount'] ?? 0);
                }
                
                $unsettledInvoices = [];
                foreach ($invoices as $invoice) {
                    // If invoice is still open or the settlement reference doesn't match
                    if ($invoice->settlement_status === 'open' && ($invoice->settled_amount ?? 0) == 0) {
                        $unsettledInvoices[] = [
                            'invoice_id' => $invoice->id,
                            'order_number' => $invoice->order->order_number ?? 'N/A',
                            'amount' => $invoice->amount,
                            'settlement_status' => $invoice->settlement_status
                        ];
                    }
                }
                
                if (count($unsettledInvoices) > 0) {
                    $incompleteSettlementsList[] = [
                        'deposit_id' => $deposit->id,
                        'deposit_description' => $deposit->description,
                        'deposit_amount' => $deposit->amount,
                        'deposit_date' => $deposit->transaction_date,
                        'approved_date' => $deposit->approval_date,
                        'from_account' => $deposit->fromAccount->account_name ?? 'N/A',
                        'unsettled_invoices' => $unsettledInvoices,
                        'unsettled_count' => count($unsettledInvoices)
                    ];
                }
            }
            
            if (count($incompleteSettlementsList) > 0) {
                $issues[] = [
                    'type' => 'incomplete_settlement',
                    'severity' => 'high',
                    'title' => 'Approved Settlements with Unsettled Invoices',
                    'description' => "Found " . count($incompleteSettlementsList) . " approved settlement deposit(s) where the invoices were not properly marked as settled. The second leg of the transaction may have failed.",
                    'count' => count($incompleteSettlementsList),
                    'items' => $incompleteSettlementsList,
                    'can_auto_fix' => true
                ];
            }
            
            // ================================================================
            // ISSUE 3: Approved Expense Requests WITHOUT Ledger Entries
            // ================================================================
            $missingExpenses = \App\Models\Request\RequestModel::where('status', 'approved')
                ->whereNull('ledger_transaction_id')
                ->whereHas('category', function($q) {
                    $q->where('category_code', 'expense');
                })
                ->whereDate('completed_at', '>=', $startDate)
                ->whereDate('completed_at', '<=', $endDate)
                ->with(['requester', 'category'])
                ->orderBy('completed_at', 'desc')
                ->get();
            
            if ($missingExpenses->count() > 0) {
                $missingExpensesList = $missingExpenses->map(function($req) {
                    return [
                        'request_id' => $req->id,
                        'request_number' => $req->request_number,
                        'requester_name' => $req->requester->fullname ?? $req->requester->name ?? 'N/A',
                        'expense_category' => $req->expense_category ?? $req->category->category_name ?? 'N/A',
                        'amount' => $req->amount,
                        'approved_at' => $req->completed_at ? $req->completed_at->format('Y-m-d') : 'N/A',
                        'payment_source_account_id' => $req->payment_source_account_id
                    ];
                });
                
                $issues[] = [
                    'type' => 'missing_expense_ledger',
                    'severity' => 'high',
                    'title' => 'Approved Expense Requests Without Ledger Entries',
                    'description' => "Found {$missingExpenses->count()} approved expense request(s) that don't have ledger entries. These should have TYPE_EXPENSE entries created.",
                    'count' => $missingExpenses->count(),
                    'items' => $missingExpensesList,
                    'can_auto_fix' => true
                ];
            }
            
            // ================================================================
            // ISSUE 4: Approved Ledger Entries with Unupdated Account Balances
            // ================================================================
            // Check for approved ledger entries where from_account or to_account balances don't match
            // This is a complex check - we'll focus on detecting inconsistencies
            $approvedTransactions = LedgerModel::where('approval_status', LedgerModel::STATUS_APPROVED)
                ->whereDate('approval_date', '>=', $startDate)
                ->whereDate('approval_date', '<=', $endDate)
                ->whereIn('transaction_type', [
                    LedgerModel::TYPE_TRANSFER,
                    LedgerModel::TYPE_VENDOR_PAYMENT,
                    LedgerModel::TYPE_SALARY_PAYMENT,
                    LedgerModel::TYPE_REIMBURSEMENT_PAYMENT
                ])
                ->with(['fromAccount', 'toAccount'])
                ->get();
            
            $balanceMismatches = [];
            foreach ($approvedTransactions as $txn) {
                // Check if accounts exist
                if (!$txn->fromAccount || !$txn->toAccount) {
                    $balanceMismatches[] = [
                        'ledger_id' => $txn->id,
                        'description' => $txn->description,
                        'amount' => $txn->amount,
                        'date' => $txn->transaction_date,
                        'issue' => 'Missing account(s): ' . (!$txn->fromAccount ? 'From Account' : '') . (!$txn->toAccount ? ' To Account' : ''),
                        'transaction_type' => $txn->transaction_type
                    ];
                }
            }
            
            if (count($balanceMismatches) > 0) {
                $issues[] = [
                    'type' => 'balance_mismatch',
                    'severity' => 'medium',
                    'title' => 'Transactions with Missing Accounts',
                    'description' => "Found " . count($balanceMismatches) . " approved transaction(s) where one or both accounts are missing.",
                    'count' => count($balanceMismatches),
                    'items' => $balanceMismatches,
                    'can_auto_fix' => false
                ];
            }
            
            // ================================================================
            // ISSUE 5: Invoices without Orders (Orphaned Ledger Entries)
            // ================================================================
            $orphanedInvoices = LedgerModel::where('transaction_type', LedgerModel::TYPE_INVOICE)
                ->whereNotNull('order_id')
                ->whereDate('transaction_date', '>=', $startDate)
                ->whereDate('transaction_date', '<=', $endDate)
                ->get()
                ->filter(function($ledger) {
                    return !$ledger->order; // Order doesn't exist
                });
            
            if ($orphanedInvoices->count() > 0) {
                $orphanedList = $orphanedInvoices->map(function($ledger) {
                    return [
                        'ledger_id' => $ledger->id,
                        'order_id' => $ledger->order_id,
                        'amount' => $ledger->amount,
                        'date' => $ledger->transaction_date,
                        'description' => $ledger->description
                    ];
                });
                
                $issues[] = [
                    'type' => 'orphaned_invoice',
                    'severity' => 'medium',
                    'title' => 'Invoice Ledger Entries Without Orders',
                    'description' => "Found {$orphanedInvoices->count()} invoice ledger entries that reference orders that no longer exist in the system.",
                    'count' => $orphanedInvoices->count(),
                    'items' => $orphanedList,
                    'can_auto_fix' => false
                ];
            }
            
            // ================================================================
            // Summary
            // ================================================================
            $totalIssues = collect($issues)->sum('count');
            $criticalIssues = collect($issues)->where('severity', 'high')->count();
            
            return response()->json([
                'success' => true,
                'summary' => [
                    'total_issues' => $totalIssues,
                    'critical_issues' => $criticalIssues,
                    'issue_types' => count($issues),
                    'date_range' => [
                        'start' => $startDate,
                        'end' => $endDate
                    ],
                    'last_checked' => now()->toDateTimeString()
                ],
                'issues' => $issues
            ]);
            
        } catch (\Exception $e) {
            Log::error("Audit report failed", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to generate audit report: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Fix missing invoice ledger entries
     * Creates ledger entries for delivered orders that don't have them
     */
    public function fixMissingInvoices(Request $request)
    {
        try {
            $request->validate([
                'order_ids' => 'required|array',
                'order_ids.*' => 'required|integer'
            ]);
            
            DB::beginTransaction();
            
            $results = [
                'success' => [],
                'failed' => [],
                'skipped' => []
            ];
            
            $ledgerService = new LedgerPostingService();
            
            foreach ($request->order_ids as $orderId) {
                $order = OrderModel::with('customer')->find($orderId);
                
                if (!$order) {
                    $results['skipped'][] = [
                        'order_id' => $orderId,
                        'reason' => 'Order not found'
                    ];
                    continue;
                }
                
                // Verify it's delivered and doesn't have a ledger entry
                if ($order->order_status !== 'delivered') {
                    $results['skipped'][] = [
                        'order_id' => $orderId,
                        'order_number' => $order->order_number,
                        'reason' => 'Order is not marked as delivered'
                    ];
                    continue;
                }
                
                if ($order->ledger_transaction_id) {
                    $results['skipped'][] = [
                        'order_id' => $orderId,
                        'order_number' => $order->order_number,
                        'reason' => 'Order already has a ledger entry'
                    ];
                    continue;
                }
                
                // Attempt to create ledger entry
                $result = $ledgerService->postInvoiceFromOrder($order);
                
                if ($result['success']) {
                    $results['success'][] = [
                        'order_id' => $orderId,
                        'order_number' => $order->order_number,
                        'ledger_id' => $result['ledger_id'] ?? null,
                        'amount' => $order->total_price
                    ];
                    
                    Log::info("Audit fix: Created missing invoice ledger", [
                        'order_id' => $orderId,
                        'ledger_id' => $result['ledger_id']
                    ]);
                } else {
                    $results['failed'][] = [
                        'order_id' => $orderId,
                        'order_number' => $order->order_number,
                        'reason' => $result['message'] ?? 'Unknown error'
                    ];
                }
            }
            
            DB::commit();
            
            return response()->json([
                'success' => true,
                'message' => count($results['success']) . ' invoice(s) created, ' . count($results['failed']) . ' failed, ' . count($results['skipped']) . ' skipped',
                'results' => $results
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error("Failed to fix missing invoices", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to fix invoices: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Fix incomplete settlement transactions
     * Re-processes invoice settlement for approved deposits
     */
    public function fixIncompleteSettlements(Request $request)
    {
        try {
            $request->validate([
                'deposit_ids' => 'required|array',
                'deposit_ids.*' => 'required|integer'
            ]);
            
            DB::beginTransaction();
            
            $results = [
                'success' => [],
                'failed' => [],
                'skipped' => []
            ];
            
            foreach ($request->deposit_ids as $depositId) {
                $deposit = LedgerModel::find($depositId);
                
                if (!$deposit) {
                    $results['skipped'][] = [
                        'deposit_id' => $depositId,
                        'reason' => 'Deposit not found'
                    ];
                    continue;
                }
                
                // Verify it's approved and has settlement metadata
                if ($deposit->approval_status !== LedgerModel::STATUS_APPROVED) {
                    $results['skipped'][] = [
                        'deposit_id' => $depositId,
                        'reason' => 'Deposit is not approved'
                    ];
                    continue;
                }
                
                $metadata = $deposit->settlement_metadata;
                if (!$metadata || !isset($metadata['invoice_ids'])) {
                    $results['skipped'][] = [
                        'deposit_id' => $depositId,
                        'reason' => 'No settlement metadata found'
                    ];
                    continue;
                }
                
                try {
                    // Re-process the settlement
                    $this->processInvoiceSettlement($deposit, $metadata);
                    
                    $results['success'][] = [
                        'deposit_id' => $depositId,
                        'invoice_count' => count($metadata['invoice_ids']),
                        'amount' => $deposit->amount
                    ];
                    
                    Log::info("Audit fix: Re-processed incomplete settlement", [
                        'deposit_id' => $depositId
                    ]);
                    
                } catch (\Exception $e) {
                    $results['failed'][] = [
                        'deposit_id' => $depositId,
                        'reason' => $e->getMessage()
                    ];
                }
            }
            
            DB::commit();
            
            return response()->json([
                'success' => true,
                'message' => count($results['success']) . ' settlement(s) fixed, ' . count($results['failed']) . ' failed, ' . count($results['skipped']) . ' skipped',
                'results' => $results
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error("Failed to fix incomplete settlements", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to fix settlements: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Process invoice settlement (copied from LedgerController for reuse)
     */
    private function processInvoiceSettlement(LedgerModel $depositLedger, array $settlementData)
    {
        $invoiceIds = $settlementData['invoice_ids'];
        $depositAmount = $settlementData['deposit_amount'];
        
        $isShortCash = $settlementData['is_short_cash_settlement'] ?? false;
        $shortCashAmount = $settlementData['short_cash_amount'] ?? 0;
        
        if ($isShortCash) {
            $totalSettlementAmount = $depositAmount + $shortCashAmount;
        } else {
            $totalSettlementAmount = $depositAmount;
        }
        
        $invoices = LedgerModel::whereIn('id', $invoiceIds)
            ->whereIn('settlement_status', ['open', 'partial'])
            ->orderBy('transaction_date', 'asc')
            ->get();
        
        $remainingAmount = $totalSettlementAmount;
        
        foreach ($invoices as $invoice) {
            $outstandingForThisInvoice = $invoice->amount - ($invoice->settled_amount ?? 0);
            
            if ($remainingAmount <= 0) {
                break;
            }
            
            $amountToSettle = min($remainingAmount, $outstandingForThisInvoice);
            
            $invoice->settled_amount = ($invoice->settled_amount ?? 0) + $amountToSettle;
            
            if ($invoice->settled_amount >= $invoice->amount) {
                $invoice->settlement_status = 'settled';
                $invoice->settled_at = now();
                $invoice->settled_via_ledger_id = $depositLedger->id;
            }
            $invoice->save();
            
            // Create audit record if it doesn't exist
            $existingAudit = \App\Models\FIN\InvoiceSettlementModel::where('settlement_deposit_id', $depositLedger->id)
                ->where('invoice_ledger_id', $invoice->id)
                ->first();
            
            if (!$existingAudit) {
                \App\Models\FIN\InvoiceSettlementModel::create([
                    'settlement_deposit_id' => $depositLedger->id,
                    'invoice_ledger_id' => $invoice->id,
                    'settled_amount' => $amountToSettle
                ]);
            }
            
            $remainingAmount -= $amountToSettle;
        }
    }
    
    /**
     * Fix missing expense ledger entries
     * Creates ledger entries for approved expense requests that don't have them
     */
    public function fixMissingExpenses(Request $request)
    {
        try {
            $request->validate([
                'request_ids' => 'required|array',
                'request_ids.*' => 'required|integer'
            ]);
            
            DB::beginTransaction();
            
            $results = [
                'success' => [],
                'failed' => [],
                'skipped' => []
            ];
            
            $ledgerService = new LedgerPostingService();
            
            foreach ($request->request_ids as $requestId) {
                $expenseRequest = \App\Models\Request\RequestModel::with('category')->find($requestId);
                
                if (!$expenseRequest) {
                    $results['skipped'][] = [
                        'request_id' => $requestId,
                        'reason' => 'Request not found'
                    ];
                    continue;
                }
                
                // Verify it's approved and doesn't have a ledger entry
                if ($expenseRequest->status !== 'approved') {
                    $results['skipped'][] = [
                        'request_id' => $requestId,
                        'request_number' => $expenseRequest->request_number,
                        'reason' => 'Request is not approved'
                    ];
                    continue;
                }
                
                if ($expenseRequest->ledger_transaction_id) {
                    $results['skipped'][] = [
                        'request_id' => $requestId,
                        'request_number' => $expenseRequest->request_number,
                        'reason' => 'Request already has a ledger entry'
                    ];
                    continue;
                }
                
                // Attempt to create ledger entry
                $result = $ledgerService->postExpenseFromRequest($expenseRequest);
                
                if ($result['success']) {
                    $results['success'][] = [
                        'request_id' => $requestId,
                        'request_number' => $expenseRequest->request_number,
                        'ledger_id' => $result['ledger_id'] ?? null,
                        'amount' => $expenseRequest->amount
                    ];
                    
                    Log::info("Audit fix: Created missing expense ledger", [
                        'request_id' => $requestId,
                        'ledger_id' => $result['ledger_id']
                    ]);
                } else {
                    $results['failed'][] = [
                        'request_id' => $requestId,
                        'request_number' => $expenseRequest->request_number,
                        'reason' => $result['message'] ?? 'Unknown error'
                    ];
                }
            }
            
            DB::commit();
            
            return response()->json([
                'success' => true,
                'message' => count($results['success']) . ' expense ledger(s) created, ' . count($results['failed']) . ' failed, ' . count($results['skipped']) . ' skipped',
                'results' => $results
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error("Failed to fix missing expenses", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to fix expenses: ' . $e->getMessage()
            ], 500);
        }
    }
}

