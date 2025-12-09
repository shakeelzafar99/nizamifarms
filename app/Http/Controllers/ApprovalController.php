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

        // Build list of approver users (for assignee filter dropdown)
        $level1Users = RoleApprovalLevelModel::getUsersWithApprovalLevel(1);
        $level2Users = RoleApprovalLevelModel::getUsersWithApprovalLevel(2);
        $approverUsers = $level1Users
            ->merge($level2Users)
            ->unique('id')
            ->sortBy(fn($u) => strtolower($u->fullname ?? $u->name ?? $u->email ?? ''));
        
        // If AJAX request, return filtered data
        if ($request->ajax()) {
            return $this->getFilteredData($request, $l1Items, $l2Items, $approvedItems, $rejectedItems);
        }
        
        return view('approvals.unified', compact(
            'summaries',
            'hasLevel1Rights',
            'hasLevel2Rights',
            'approverUsers'
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
        
        // Get pending ledger transactions (ledger-level approvals)
        // IMPORTANT: Exclude ledger entries that are linked to requests (to avoid duplicates)
        // We treat legacy 'pending' and explicit 'pending_l1' as Level 1 items.
        $pendingLedger = LedgerModel::whereIn('approval_status', [
                LedgerModel::STATUS_PENDING,
                LedgerModel::STATUS_PENDING_L1,
            ])
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

        // Get L2 pending ledger transactions (explicit Level 2 status)
        $pendingLedgerL2 = LedgerModel::where('approval_status', LedgerModel::STATUS_PENDING_L2)
            ->whereNull('request_id')
            ->with(['fromAccount', 'toAccount', 'createdBy', 'request', 'order.customer'])
            ->orderBy('transaction_date', 'desc')
            ->get();

        foreach ($pendingLedgerL2 as $ledger) {
            $items[] = $this->formatLedgerItem($ledger, $expFundAccount, $nfCashAccount, $onlineAccount);
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
        // Virtual assignment: compute assignee on the fly from routing rules,
        // based on category, payment source and amount, instead of relying
        // on stored level_1_assigned_to / level_2_assigned_to columns.
        $assignedToId = $this->getVirtualAssigneeForRequest($request, $level);
        
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
            // For \"my assignments\" filtering – null means \"unassigned\"
            'assigned_to_id' => $assignedToId,
            'view_url' => route('requests.show', $request->id)
        ];
    }

    /**
     * Format ledger item for display
     */
    private function formatLedgerItem($ledger, $expFundAccount = null, $nfCashAccount = null, $onlineAccount = null, $overrideStatus = null)
    {
        $area = $this->determineLedgerArea($ledger, $expFundAccount, $nfCashAccount, $onlineAccount);
        $level = $this->getLedgerApprovalLevel($ledger);
        $assignedToId = $this->getVirtualAssigneeForLedger($ledger, $expFundAccount, $nfCashAccount, $onlineAccount);
        
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
            'level' => $level, // Ledger transactions now participate in L1/L2 based on status
            'area' => $area,
            'status' => $overrideStatus ?? $ledger->approval_status,
            'assigned_to_id' => $assignedToId,
            'view_url' => route('fin.ledger.show', ['id' => $ledger->id, 'origin' => 'approvals'])
        ];
    }

    /**
     * Format adjustment item for display
     */
    private function formatAdjustmentItem($adjustment, $level = null, $expFundAccount = null, $nfCashAccount = null, $onlineAccount = null)
    {
        $assignedToId = null;
        if ($level === 1) {
            $assignedToId = $adjustment->level_1_assigned_to ?? null;
        } elseif ($level === 2) {
            $assignedToId = $adjustment->level_2_assigned_to ?? null;
        }

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
            'assigned_to_id' => $assignedToId,
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
        $assigneeId = $request->input('assignee_id'); // user id for \"My assignments\" filter
        
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

        // Filter by assignee if specified (only applies to requests/adjustments with assigned_to_id)
        if ($assigneeId) {
            $assigneeIdInt = (int) $assigneeId;
            $items = array_filter($items, function($item) use ($assigneeIdInt) {
                if (!isset($item['assigned_to_id']) || !$item['assigned_to_id']) {
                    return false;
                }
                return (int) $item['assigned_to_id'] === $assigneeIdInt;
            });
        }
        
        // Filter by search if specified (search in number, title, requester, description, category)
        if ($search) {
            $items = array_filter($items, function($item) use ($search) {
                $searchLower = strtolower($search);
                return strpos(strtolower($item['number'] ?? ''), $searchLower) !== false ||
                       strpos(strtolower($item['title'] ?? ''), $searchLower) !== false ||
                       strpos(strtolower($item['requester'] ?? ''), $searchLower) !== false ||
                       strpos(strtolower($item['description'] ?? ''), $searchLower) !== false ||
                       strpos(strtolower($item['category'] ?? ''), $searchLower) !== false;
            });
        }
        
        // Calculate updated summaries if assignee filter is applied
        $updatedSummaries = null;
        if ($assigneeId) {
            // Filter all item sets by assignee
            $filteredL1 = array_filter($l1Items, function($item) use ($assigneeId) {
                return isset($item['assigned_to_id']) && (int)$item['assigned_to_id'] === (int)$assigneeId;
            });
            $filteredL2 = array_filter($l2Items, function($item) use ($assigneeId) {
                return isset($item['assigned_to_id']) && (int)$item['assigned_to_id'] === (int)$assigneeId;
            });
            
            $updatedSummaries = [
                'l1' => [
                    'count' => count($filteredL1),
                    'amount' => $this->sumAmounts($filteredL1),
                    'by_area' => $this->groupByArea($filteredL1)
                ],
                'l2' => [
                    'count' => count($filteredL2),
                    'amount' => $this->sumAmounts($filteredL2),
                    'by_area' => $this->groupByArea($filteredL2)
                ]
            ];
        }
        
        return response()->json([
            'success' => true,
            'items' => array_values($items),
            'count' => count($items),
            'total_amount' => $this->sumAmounts($items),
            'summaries' => $updatedSummaries // Include updated summaries if filtered
        ]);
    }

    /**
     * Compute virtual assignee for a request based on current routing rules.
     * This does NOT rely on stored level_1_assigned_to/level_2_assigned_to,
     * so changing routing rules immediately affects \"who it is on\".
     */
    private function getVirtualAssigneeForRequest($request, ?int $level): ?int
    {
        if (!$level || !in_array($level, [1, 2], true)) {
            return null;
        }

        // We need the category code to match routing rules (area_identifier)
        if (!$request->relationLoaded('category')) {
            $request->load('category');
        }
        if (!$request->category || !$request->category->category_code) {
            return null;
        }

        $context = [];

        // Derive a normalized payment source for routing:
        // - If an explicit employee cash account is selected, treat it as NF_CASH bucket
        // - If no account and category is 'expense', default to EXP_FUND bucket
        // - Otherwise use the explicit payment_source_account_id
        $normalizedSourceId = $request->payment_source_account_id;

        try {
            if ($request->relationLoaded('paymentSourceAccount') || method_exists($request, 'paymentSourceAccount')) {
                $account = $request->paymentSourceAccount;
                if ($account && $account->account_category === AccountModel::CATEGORY_EMPLOYEE_CASH) {
                    // Map employee cash accounts to NF_CASH for routing purposes
                    $nfCashAccount = AccountModel::where('account_code', 'NF_CASH')->first();
                    if ($nfCashAccount) {
                        $normalizedSourceId = $nfCashAccount->id;
                    }
                }
            }

            // For expense reimbursements without explicit payment source,
            // default to EXP_FUND as the logical payment bucket
            if (!$normalizedSourceId && $request->category && $request->category->category_code === 'expense') {
                $expFundAccount = AccountModel::where('account_code', 'EXP_FUND')->first();
                if ($expFundAccount) {
                    $normalizedSourceId = $expFundAccount->id;
                }
            }
        } catch (\Exception $e) {
            // Fail soft; routing will just fall back to "no specific assignee"
            \Log::error('Error normalizing payment source for virtual assignment', [
                'error' => $e->getMessage(),
                'request_id' => $request->id ?? null,
            ]);
        }

        // Always include the (possibly normalized) payment_source_account_id in context
        $context['payment_source_account_id'] = $normalizedSourceId;
        
        if (!empty($request->amount)) {
            $context['amount'] = $request->amount;
        }

        return $this->getAssigneeFromRules(
            'request_category',
            $request->category->category_code,
            $level,
            $context
        );
    }

    /**
     * Determine logical approval level for a ledger transaction from its status.
     * - Legacy 'pending' and explicit 'pending_l1' are treated as Level 1
     * - 'pending_l2' is treated as Level 2
     */
    private function getLedgerApprovalLevel(LedgerModel $ledger): ?int
    {
        if (in_array($ledger->approval_status, [
            LedgerModel::STATUS_PENDING,
            LedgerModel::STATUS_PENDING_L1,
        ], true)) {
            return 1;
        }

        if ($ledger->approval_status === LedgerModel::STATUS_PENDING_L2) {
            return 2;
        }

        return null; // Approved / rejected / reversed
    }

    /**
     * Compute virtual assignee for a ledger transaction based on routing rules.
     * This lets finance categories (Employee Deposit, Vendor Payment, Account Transfer,
     * and L2 Invoice approvals) participate in "My assignments" without changing
     * underlying ledger schemas.
     */
    private function getVirtualAssigneeForLedger($ledger, $expFundAccount = null, $nfCashAccount = null, $onlineAccount = null): ?int
    {
        $level = $this->getLedgerApprovalLevel($ledger);
        if (!$level) {
            return null; // No pending approval
        }

        // Derive payment bucket from area (EXP_FUND, NF_CASH, ONLINE)
        $area = $this->determineLedgerArea($ledger, $expFundAccount, $nfCashAccount, $onlineAccount);
        $paymentSourceId = null;

        if ($area === self::AREA_EXP_FUND && $expFundAccount) {
            $paymentSourceId = $expFundAccount->id;
        } elseif ($area === self::AREA_NF_CASH && $nfCashAccount) {
            $paymentSourceId = $nfCashAccount->id;
        } elseif ($area === self::AREA_ONLINE && $onlineAccount) {
            $paymentSourceId = $onlineAccount->id;
        }

        $context = [
            'payment_source_account_id' => $paymentSourceId,
        ];

        return $this->getAssigneeFromRules(
            'ledger_transaction',
            $ledger->transaction_type,
            $level,
            $context
        );
    }

    /**
     * Shared helper to look up an assignee from routing rule tables.
     * This mirrors the logic used in LedgerPostingService so dashboards
     * and posting behave consistently.
     */
    private function getAssigneeFromRules(
        string $areaType,
        string $areaIdentifier,
        int $level,
        array $context = []
    ): ?int {
        try {
            // Base rule query
            $query = DB::table('t_req_approval_rules')
                ->where('area_type', $areaType)
                ->where('area_identifier', $areaIdentifier)
                ->where('approval_level', $level)
                ->where('is_active', 1);

            // Contextual filters
            if (isset($context['payment_source_account_id'])) {
                $paymentSourceId = $context['payment_source_account_id'];
                if ($paymentSourceId === null) {
                    // Request has no payment source - match rules with NULL payment source only
                    $query->whereNull('payment_source_account_id');
                } else {
                    // Request has a payment source - match specific account OR catch-all (NULL)
                    $query->where(function($q) use ($paymentSourceId) {
                        $q->where('payment_source_account_id', $paymentSourceId)
                          ->orWhereNull('payment_source_account_id');
                    });
                }
            }

            if (!empty($context['payment_mode'])) {
                $query->where(function($q) use ($context) {
                    $q->where('payment_mode', $context['payment_mode'])
                      ->orWhereNull('payment_mode');
                });
            }

            if (isset($context['amount']) && $context['amount'] !== null) {
                $amount = $context['amount'];
                $query->where(function($q) use ($amount) {
                    $q->where(function($subQ) use ($amount) {
                        $subQ->whereNull('min_amount')
                             ->orWhere('min_amount', '<=', $amount);
                    })
                    ->where(function($subQ) use ($amount) {
                        $subQ->whereNull('max_amount')
                             ->orWhere('max_amount', '>=', $amount);
                    });
                });
            }

            // Highest priority rule wins
            $rule = $query->orderBy('priority', 'asc')->first();

            // Backward compatibility: For historical configurations we may still
            // have invoice routing stored as request_category/invoice_approval.
            // If no ledger_transaction/invoice rule is found, fall back to that.
            if (
                !$rule &&
                $areaType === 'ledger_transaction' &&
                $areaIdentifier === \App\Models\FIN\LedgerModel::TYPE_INVOICE
            ) {
                $fallbackQuery = DB::table('t_req_approval_rules')
                    ->where('area_type', 'request_category')
                    ->where('area_identifier', 'invoice_approval')
                    ->where('approval_level', $level)
                    ->where('is_active', 1);

                // Re-apply the same contextual filters to the fallback query
                if (isset($context['payment_source_account_id'])) {
                    $paymentSourceId = $context['payment_source_account_id'];
                    if ($paymentSourceId === null) {
                        $fallbackQuery->whereNull('payment_source_account_id');
                    } else {
                        $fallbackQuery->where(function($q) use ($paymentSourceId) {
                            $q->where('payment_source_account_id', $paymentSourceId)
                              ->orWhereNull('payment_source_account_id');
                        });
                    }
                }

                if (!empty($context['payment_mode'])) {
                    $fallbackQuery->where(function($q) use ($context) {
                        $q->where('payment_mode', $context['payment_mode'])
                          ->orWhereNull('payment_mode');
                    });
                }

                if (isset($context['amount']) && $context['amount'] !== null) {
                    $amount = $context['amount'];
                    $fallbackQuery->where(function($q) use ($amount) {
                        $q->where(function($subQ) use ($amount) {
                            $subQ->whereNull('min_amount')
                                 ->orWhere('min_amount', '<=', $amount);
                        })
                        ->where(function($subQ) use ($amount) {
                            $subQ->whereNull('max_amount')
                                 ->orWhere('max_amount', '>=', $amount);
                        });
                    });
                }

                $rule = $fallbackQuery->orderBy('priority', 'asc')->first();
            }
            
            // Debug logging
            \Log::debug('Virtual Assignment Lookup', [
                'area_type' => $areaType,
                'area_identifier' => $areaIdentifier,
                'level' => $level,
                'context' => $context,
                'rule_found' => $rule ? $rule->id : null,
                'rule_payment_source' => $rule ? $rule->payment_source_account_id : null,
            ]);
            
            if (!$rule) {
                return null;
            }

            // Primary assignee for this rule
            $assignee = DB::table('t_req_approval_rule_assignees')
                ->where('rule_id', $rule->id)
                ->where('is_primary', 1)
                ->orderBy('sequence_order', 'asc')
                ->first();
            
            \Log::debug('Virtual Assignment Result', [
                'rule_id' => $rule->id,
                'assignee_user_id' => $assignee ? $assignee->user_id : null,
            ]);

            return $assignee ? (int) $assignee->user_id : null;
        } catch (\Exception $e) {
            // Fail silently for UI – approvals still work via role-based rights
            \Log::error('Error computing virtual assignee', [
                'error' => $e->getMessage(),
                'area_type' => $areaType,
                'area_identifier' => $areaIdentifier,
                'level' => $level,
            ]);
            return null;
        }
    }
}
