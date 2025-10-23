<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Request\RequestModel;
use App\Models\FIN\LedgerModel;
use App\Models\FIN\LedgerAdjustmentModel;
use App\Models\FIN\AccountModel;
use App\Models\SysAdmin\RoleApprovalLevelModel;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class ApprovalController extends Controller
{
    // Area constants
    const AREA_EXP_FUND = 'exp_fund';
    const AREA_NF_CASH = 'nf_cash';
    const AREA_ONLINE = 'online';
    const AREA_OTHERS = 'others';

    /**
     * Unified Approvals Dashboard with Two-Layer Card System
     * Layer 1: L1 Pending, L2 Pending, Approved, Rejected
     * Layer 2: EXP_FUND, NF_CASH, ONLINE, OTHERS
     */
    public function index(Request $request)
    {
        $user = auth()->user();
        
        // Check if user has L1 or L2 approval rights
        $hasLevel1Rights = RoleApprovalLevelModel::userHasApprovalLevel($user->id, 1);
        $hasLevel2Rights = RoleApprovalLevelModel::userHasApprovalLevel($user->id, 2);
        
        // Redirect if user has no approval rights
        if (!$hasLevel1Rights && !$hasLevel2Rights) {
            return redirect()->route('requests.index')
                ->with('info', 'You do not have approval rights. Showing your requests.');
        }
        
        // Get key account IDs for area mapping
        $expFundAccount = AccountModel::getByCode('EXP_FUND');
        $nfCashAccount = AccountModel::getByCode('NF_CASH');
        $onlineAccount = AccountModel::getByCode('ONLINE');
        
        // Get all items and categorize them
        $l1Items = $this->getL1PendingItems($user, $hasLevel1Rights, $expFundAccount, $nfCashAccount, $onlineAccount);
        $l2Items = $hasLevel2Rights ? $this->getL2PendingItems($user, $expFundAccount, $nfCashAccount, $onlineAccount) : [];
        $approvedItems = $this->getApprovedItems($request->input('approved_from'), $request->input('approved_to'));
        $rejectedItems = $this->getRejectedItems($request->input('rejected_from'), $request->input('rejected_to'));
        
        // Calculate Layer 1 summaries
        $summaries = [
            'l1' => [
                'count' => count($l1Items),
                'amount' => $this->sumAmounts($l1Items),
                'by_area' => $this->groupByArea($l1Items)
            ],
            'l2' => [
                'count' => count($l2Items),
                'amount' => $this->sumAmounts($l2Items),
                'by_area' => $this->groupByArea($l2Items)
            ],
            'approved' => [
                'count' => count($approvedItems),
                'amount' => $this->sumAmounts($approvedItems)
            ],
            'rejected' => [
                'count' => count($rejectedItems),
                'amount' => $this->sumAmounts($rejectedItems)
            ]
        ];
        
        // If AJAX request, return filtered data
        if ($request->ajax()) {
            return $this->getFilteredData($request, $l1Items, $l2Items, $approvedItems, $rejectedItems);
        }
        
        return view('approvals.unified', compact(
            'summaries',
            'hasLevel1Rights',
            'hasLevel2Rights'
        ));
    }

    /**
     * Get L1 pending items
     */
    private function getL1PendingItems($user, $hasLevel1Rights, $expFundAccount, $nfCashAccount, $onlineAccount)
    {
        if (!$hasLevel1Rights) {
            return [];
        }

        $items = [];
        
        // Get all pending requests
        $pendingRequests = RequestModel::where('status', 'pending')
            ->with(['category', 'requester', 'paymentSourceAccount', 'createdBy'])
            ->orderBy('submitted_at', 'desc') // Newest first
            ->get();
            
        // Filter for L1 pending
        foreach ($pendingRequests as $req) {
            if ($req->requires_level_1 && $req->level_1_status === 'pending') {
                $items[] = $this->formatRequestItem($req, 1, $expFundAccount, $nfCashAccount, $onlineAccount);
            }
        }
        
        // Get pending ledger transactions (no L1/L2 - just pending)
        // IMPORTANT: Exclude ledger entries that are linked to requests (to avoid duplicates)
        $pendingLedger = LedgerModel::where('approval_status', LedgerModel::STATUS_PENDING)
            ->whereNull('request_id')  // Only show ledger entries NOT linked to requests
            ->with(['fromAccount', 'toAccount', 'createdBy', 'request', 'order.customer'])
            ->orderBy('transaction_date', 'desc') // Newest first
            ->get();
        
        foreach ($pendingLedger as $ledger) {
            $items[] = $this->formatLedgerItem($ledger, $expFundAccount, $nfCashAccount, $onlineAccount);
        }
        
        // Get pending ledger adjustments (L1)
        $pendingAdjustments = LedgerAdjustmentModel::where('adjustment_status', LedgerAdjustmentModel::STATUS_PENDING)
            ->with(['ledger', 'order', 'requestedBy'])
            ->orderBy('requested_at', 'desc') // Newest first
            ->get();
        
        foreach ($pendingAdjustments as $adj) {
            if ($adj->requires_level_1 && $adj->level_1_status === LedgerAdjustmentModel::APPROVAL_STATUS_PENDING) {
                $items[] = $this->formatAdjustmentItem($adj, 1, $expFundAccount, $nfCashAccount, $onlineAccount);
            }
        }
        
        return $items;
    }

    /**
     * Get L2 pending items
     */
    private function getL2PendingItems($user, $expFundAccount, $nfCashAccount, $onlineAccount)
    {
        $items = [];
        
        // Get requests that passed L1 and need L2
        $pendingRequests = RequestModel::where('status', 'pending')
            ->where('requires_level_2', 1)
            ->where('level_1_status', 'approved')
            ->where('level_2_status', 'pending')
            ->with(['category', 'requester', 'paymentSourceAccount', 'createdBy'])
            ->orderBy('submitted_at', 'desc') // Newest first
            ->get();
        
        foreach ($pendingRequests as $req) {
            $items[] = $this->formatRequestItem($req, 2, $expFundAccount, $nfCashAccount, $onlineAccount);
        }
        
        // Get adjustments that passed L1 and need L2
        $pendingAdjustments = LedgerAdjustmentModel::where('adjustment_status', LedgerAdjustmentModel::STATUS_PENDING)
            ->where('requires_level_2', 1)
            ->where('level_1_status', LedgerAdjustmentModel::APPROVAL_STATUS_APPROVED)
            ->where('level_2_status', LedgerAdjustmentModel::APPROVAL_STATUS_PENDING)
            ->with(['ledger', 'order', 'requestedBy'])
            ->orderBy('requested_at', 'desc') // Newest first
                ->get();
            
        foreach ($pendingAdjustments as $adj) {
            $items[] = $this->formatAdjustmentItem($adj, 2, $expFundAccount, $nfCashAccount, $onlineAccount);
        }
        
        return $items;
    }

    /**
     * Get approved items
     */
    private function getApprovedItems($dateFrom = null, $dateTo = null)
    {
        $items = [];
        
        // Default to last 30 days if no date provided
        if (!$dateFrom) {
            $dateFrom = Carbon::now()->subDays(30)->startOfDay();
        } else {
            $dateFrom = Carbon::parse($dateFrom)->startOfDay();
        }
        if (!$dateTo) {
            $dateTo = Carbon::now()->endOfDay();
        } else {
            $dateTo = Carbon::parse($dateTo)->endOfDay();
        }
        
        // Approved requests
        // Use COALESCE to handle cases where completed_at might be NULL
        $approvedRequests = RequestModel::where('status', 'approved')
            ->where(function($query) use ($dateFrom, $dateTo) {
                $query->whereBetween('completed_at', [$dateFrom, $dateTo])
                      ->orWhere(function($q) use ($dateFrom, $dateTo) {
                          $q->whereNull('completed_at')
                            ->whereBetween('updated_at', [$dateFrom, $dateTo]);
                      });
            })
            ->with(['category', 'requester', 'paymentSourceAccount', 'createdBy'])
            ->orderBy('completed_at', 'desc')
            ->orderBy('updated_at', 'desc')
            ->get();
        
        foreach ($approvedRequests as $req) {
            $items[] = $this->formatRequestItem($req, null, null, null, null, 'approved');
        }
        
        // Approved ledger transactions
        // IMPORTANT: Exclude ledger entries that are linked to requests (to avoid duplicates)
        // Those will already show up via the request itself
        $approvedLedger = LedgerModel::where('approval_status', LedgerModel::STATUS_APPROVED)
            ->whereBetween('approval_date', [$dateFrom, $dateTo])
            ->whereNull('request_id')  // Only show ledger entries NOT linked to requests
            ->with(['fromAccount', 'toAccount', 'createdBy', 'request', 'order.customer'])
            ->orderBy('approval_date', 'desc')
            ->get();
        
        foreach ($approvedLedger as $ledger) {
            $items[] = $this->formatLedgerItem($ledger, null, null, null, 'approved');
        }
        
        // Sort all items together by date (newest first)
        // This ensures requests and ledger transactions are mixed and sorted by actual date
        usort($items, function($a, $b) {
            $dateA = strtotime($a['date'] ?? '1970-01-01 00:00:00');
            $dateB = strtotime($b['date'] ?? '1970-01-01 00:00:00');
            return $dateB - $dateA;  // Descending order (newest first)
        });
        
        return $items;
    }

    /**
     * Get rejected items
     */
    private function getRejectedItems($dateFrom = null, $dateTo = null)
    {
        $items = [];
        
        // Default to last 30 days
        if (!$dateFrom) {
            $dateFrom = Carbon::now()->subDays(30)->format('Y-m-d');
        }
        if (!$dateTo) {
            $dateTo = Carbon::now()->format('Y-m-d');
        }
        
        // Rejected requests
        $rejectedRequests = RequestModel::where('status', 'rejected')
            ->whereBetween('completed_at', [$dateFrom, $dateTo])
            ->with(['category', 'requester', 'paymentSourceAccount', 'createdBy'])
            ->orderBy('completed_at', 'desc')
            ->get();
        
        foreach ($rejectedRequests as $req) {
            $items[] = $this->formatRequestItem($req, null, null, null, null, 'rejected');
        }
        
        // Rejected ledger transactions
        // IMPORTANT: Exclude ledger entries that are linked to requests (to avoid duplicates)
        $rejectedLedger = LedgerModel::where('approval_status', LedgerModel::STATUS_REJECTED)
            ->whereBetween('updated_at', [$dateFrom, $dateTo])
            ->whereNull('request_id')  // Only show ledger entries NOT linked to requests
            ->with(['fromAccount', 'toAccount', 'createdBy', 'request', 'order.customer'])
            ->orderBy('updated_at', 'desc')
            ->get();
        
        foreach ($rejectedLedger as $ledger) {
            $items[] = $this->formatLedgerItem($ledger, null, null, null, 'rejected');
        }
        
        // Sort all items together by date (newest first)
        usort($items, function($a, $b) {
            $dateA = strtotime($a['date'] ?? '1970-01-01 00:00:00');
            $dateB = strtotime($b['date'] ?? '1970-01-01 00:00:00');
            return $dateB - $dateA;  // Descending order (newest first)
        });
        
        return $items;
    }

    /**
     * Format request item for display
     */
    private function formatRequestItem($request, $level = null, $expFundAccount = null, $nfCashAccount = null, $onlineAccount = null, $overrideStatus = null)
    {
        $area = $this->determineRequestArea($request, $expFundAccount, $nfCashAccount, $onlineAccount);
        
        return [
            'type' => 'request',
            'id' => $request->id,
            'number' => $request->request_number,
            'category' => $request->category ? $request->category->category_name : 'N/A',
            'category_code' => $request->category ? $request->category->category_code : null,
            'requester' => $request->requester ? $request->requester->fullname : ($request->createdBy ? $request->createdBy->fullname : 'System'),
            'title' => $request->title,
            'description' => $request->description,
            'amount' => $request->amount ?? 0,
            'leave_days' => $request->leave_days ?? 0,
            'date' => $request->submitted_at ? $request->submitted_at->format('Y-m-d H:i:s') : null,
            'level' => $level,
            'area' => $area,
            'status' => $overrideStatus ?? 'pending',
            'view_url' => route('requests.show', $request->id)
        ];
    }

    /**
     * Format ledger item for display
     */
    private function formatLedgerItem($ledger, $expFundAccount = null, $nfCashAccount = null, $onlineAccount = null, $overrideStatus = null)
    {
        $area = $this->determineLedgerArea($ledger, $expFundAccount, $nfCashAccount, $onlineAccount);
        
        $title = $ledger->description;
        $description = $ledger->description;
        
        // For invoices, create a detailed title and description
        if ($ledger->order) {
            $title = "Invoice #{$ledger->order->order_number}";
            
            // Add customer name and order date to description for better context
            $customerName = 'Unknown Customer';
            if ($ledger->order->customer) {
                // Use the full_name accessor (getFullNameAttribute)
                $fullName = trim($ledger->order->customer->full_name ?? '');
                $customerName = $fullName ?: 
                               $ledger->order->customer->company ?: 
                               $ledger->order->customer->phone ?: 
                               'Unknown Customer';
            }
            $orderDate = $ledger->order->order_date ? $ledger->order->order_date->format('M d, Y') : 'Unknown Date';
            $description = "Invoice #{$ledger->order->order_number} - {$customerName} ({$orderDate}) - " . $ledger->description;
        }
        
        // Determine requester name based on transaction type
        $requester = 'System';
        if ($ledger->transaction_type === \App\Models\FIN\LedgerModel::TYPE_EMPLOYEE_DEPOSIT) {
            // For employee deposits, show the employee name (from_account)
            $requester = $ledger->fromAccount ? $ledger->fromAccount->account_name : 'Unknown';
        } elseif ($ledger->transaction_type === \App\Models\FIN\LedgerModel::TYPE_INVOICE && $ledger->order) {
            // For invoices, show the customer name (use full_name accessor, company, or phone as fallback)
            if ($ledger->order->customer) {
                $fullName = trim($ledger->order->customer->full_name ?? '');
                $requester = $fullName ?: 
                            $ledger->order->customer->company ?: 
                            $ledger->order->customer->phone ?: 
                            'Unknown';
            } else {
                $requester = 'Unknown';
            }
        } else {
            // For other types, show the person who created it
            $requester = $ledger->createdBy ? $ledger->createdBy->fullname : 'System';
        }
        
        return [
            'type' => 'ledger',
            'id' => $ledger->id,
            'number' => "TXN-{$ledger->id}",
            'category' => ucfirst(str_replace('_', ' ', $ledger->transaction_type)),
            'category_code' => $ledger->transaction_type,
            'requester' => $requester,
            'title' => $title,
            'description' => $description,
            'amount' => $ledger->amount ?? 0,
            'leave_days' => 0,
            'date' => $ledger->created_at ? $ledger->created_at->format('Y-m-d H:i:s') : $ledger->transaction_date,
            'level' => null, // Ledger transactions don't have L1/L2
            'area' => $area,
            'status' => $overrideStatus ?? $ledger->approval_status,
            'view_url' => route('fin.ledger.show', $ledger->id)
        ];
    }

    /**
     * Format adjustment item for display
     */
    private function formatAdjustmentItem($adjustment, $level = null, $expFundAccount = null, $nfCashAccount = null, $onlineAccount = null)
    {
        return [
            'type' => 'adjustment',
            'id' => $adjustment->id,
            'number' => "ADJ-{$adjustment->id}",
            'category' => 'Ledger Adjustment',
            'category_code' => 'adjustment',
            'requester' => $adjustment->requestedBy ? $adjustment->requestedBy->fullname : 'N/A',
            'title' => $adjustment->adjustment_reason ?? 'Ledger Adjustment',
            'description' => $adjustment->adjustment_reason,
            'amount' => abs($adjustment->adjustment_amount ?? 0),
            'leave_days' => 0,
            'date' => $adjustment->requested_at ? $adjustment->requested_at->format('Y-m-d H:i:s') : null,
            'level' => $level,
            'area' => self::AREA_OTHERS,
            'status' => 'pending',
            'view_url' => route('fin.ledger.adjustments.show', $adjustment->id)
        ];
    }

    /**
     * Determine area for request
     */
    private function determineRequestArea($request, $expFundAccount, $nfCashAccount, $onlineAccount)
    {
        // Check payment source account FIRST (most accurate)
        if ($request->payment_source_account_id) {
            if ($expFundAccount && $request->payment_source_account_id == $expFundAccount->id) {
                return self::AREA_EXP_FUND;
            }
            if ($nfCashAccount && $request->payment_source_account_id == $nfCashAccount->id) {
                return self::AREA_NF_CASH;
            }
            if ($onlineAccount && $request->payment_source_account_id == $onlineAccount->id) {
                return self::AREA_ONLINE;
            }
            
            // Check if payment source is an employee cash account (e.g., Waseem's account)
            // These should be categorized as NF_CASH area
            if ($request->paymentSourceAccount && 
                $request->paymentSourceAccount->account_category === 'employee_cash') {
                return self::AREA_NF_CASH;
            }
        }
        
        // Check category code if no payment source
        if ($request->category) {
            $categoryCode = $request->category->category_code;
            
            // Expense reimbursements typically from EXP_FUND
            if ($categoryCode === 'expense') {
                return self::AREA_EXP_FUND;
            }
            
            // Salary advances: Use payment source if available, otherwise default to EXP_FUND
            // (Salary advances should be paid from EXP_FUND by default)
            if ($categoryCode === 'salary_advance') {
                return self::AREA_EXP_FUND;
            }
            
            // Leave requests are OTHERS
            if ($categoryCode === 'leave') {
                return self::AREA_OTHERS;
            }
        }
        
        // Default to others (equipment, etc.)
        return self::AREA_OTHERS;
    }

    /**
     * Determine area for ledger transaction
     */
    private function determineLedgerArea($ledger, $expFundAccount, $nfCashAccount, $onlineAccount)
    {
        // Check from/to accounts
        $fromAccountId = $ledger->from_account_id;
        $toAccountId = $ledger->to_account_id;
        
        // Check if EXP_FUND is involved
        if ($expFundAccount && ($fromAccountId == $expFundAccount->id || $toAccountId == $expFundAccount->id)) {
            return self::AREA_EXP_FUND;
        }
        
        // Check if NF_CASH is involved
        if ($nfCashAccount && ($fromAccountId == $nfCashAccount->id || $toAccountId == $nfCashAccount->id)) {
            return self::AREA_NF_CASH;
        }
        
        // Check if ONLINE is involved
        if ($onlineAccount && ($fromAccountId == $onlineAccount->id || $toAccountId == $onlineAccount->id)) {
            return self::AREA_ONLINE;
        }
        
        // Check account categories
        if ($ledger->fromAccount && $ledger->fromAccount->account_category === AccountModel::CATEGORY_BANK) {
            return self::AREA_ONLINE;
        }
        if ($ledger->toAccount && $ledger->toAccount->account_category === AccountModel::CATEGORY_BANK) {
            return self::AREA_ONLINE;
        }
        
        if ($ledger->fromAccount && $ledger->fromAccount->account_category === AccountModel::CATEGORY_CASH) {
            return self::AREA_NF_CASH;
        }
        if ($ledger->toAccount && $ledger->toAccount->account_category === AccountModel::CATEGORY_CASH) {
            return self::AREA_NF_CASH;
        }
        
        return self::AREA_OTHERS;
    }

    /**
     * Group items by area
     */
    private function groupByArea($items)
    {
        $grouped = [
            self::AREA_EXP_FUND => ['count' => 0, 'amount' => 0],
            self::AREA_NF_CASH => ['count' => 0, 'amount' => 0],
            self::AREA_ONLINE => ['count' => 0, 'amount' => 0],
            self::AREA_OTHERS => ['count' => 0, 'amount' => 0]
        ];
        
        foreach ($items as $item) {
            $area = $item['area'] ?? self::AREA_OTHERS;
            $grouped[$area]['count']++;
            $grouped[$area]['amount'] += $item['amount'] ?? 0;
        }
        
        return $grouped;
    }

    /**
     * Sum amounts from items
     */
    private function sumAmounts($items)
    {
        return array_sum(array_column($items, 'amount'));
    }

    /**
     * Get filtered data for AJAX requests
     */
    private function getFilteredData($request, $l1Items, $l2Items, $approvedItems, $rejectedItems)
    {
        $level = $request->input('level'); // 'l1', 'l2', 'approved', 'rejected'
        $area = $request->input('area'); // 'exp_fund', 'nf_cash', 'online', 'others'
        $search = $request->input('search');
        
        // Select items based on level
        $items = [];
        switch ($level) {
            case 'l1':
                $items = $l1Items;
                break;
            case 'l2':
                $items = $l2Items;
                break;
            case 'approved':
                $items = $approvedItems;
                break;
            case 'rejected':
                $items = $rejectedItems;
                break;
            default:
                // All pending (L1 + L2 + ledger transactions)
                $items = array_merge($l1Items, $l2Items);
        }
        
        // Filter by area if specified
        if ($area) {
            $items = array_filter($items, function($item) use ($area) {
                return $item['area'] === $area;
            });
        }
        
        // Filter by search if specified
        if ($search) {
            $items = array_filter($items, function($item) use ($search) {
                $searchLower = strtolower($search);
                return strpos(strtolower($item['number']), $searchLower) !== false ||
                       strpos(strtolower($item['title']), $searchLower) !== false ||
                       strpos(strtolower($item['requester']), $searchLower) !== false;
            });
        }
        
        return response()->json([
            'success' => true,
            'items' => array_values($items),
            'count' => count($items),
            'total_amount' => $this->sumAmounts($items)
        ]);
    }
}
