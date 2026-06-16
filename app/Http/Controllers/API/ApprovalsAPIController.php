<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Controllers\ApprovalController;
use Illuminate\Http\Request;
use App\Models\SysAdmin\RoleApprovalLevelModel;
use Illuminate\Support\Facades\Log;

class ApprovalsAPIController extends Controller
{
    protected $approvalController;

    public function __construct()
    {
        $this->approvalController = new ApprovalController();
    }

    /**
     * Get approvals data for mobile app
     * Reuses the exact same logic as web ApprovalController
     * 
     * GET /api/mobile/approvals
     * 
     * Query params:
     * - level: 'l1', 'l2' (optional)
     * - area: 'exp_fund', 'nf_cash', 'online', 'others' (optional)
     * - assignee_id: user id (optional)
     * - last_synced: ISO timestamp for incremental sync (optional)
     */
    public function index(Request $request)
    {
        try {
            $user = auth()->user();
            
            // Check if user has L1 or L2 approval rights
            $hasLevel1Rights = RoleApprovalLevelModel::userHasApprovalLevel($user->id, 1);
            $hasLevel2Rights = RoleApprovalLevelModel::userHasApprovalLevel($user->id, 2);
            
            if (!$hasLevel1Rights && !$hasLevel2Rights) {
                return response()->json([
                    'success' => false,
                    'message' => 'You do not have approval rights'
                ], 403);
            }

            // Call the web controller's index method with AJAX flag
            $request->headers->set('X-Requested-With', 'XMLHttpRequest');
            $request->headers->set('Accept', 'application/json');
            
            // Get the response from web controller
            $response = $this->approvalController->index($request);
            $data = $response->getData(true);
            
            if (!$data['success']) {
                return response()->json([
                    'success' => false,
                    'message' => 'Error loading approvals'
                ], 500);
            }

            // Get approver users list for dropdown
            $level1Users = RoleApprovalLevelModel::getUsersWithApprovalLevel(1);
            $level2Users = RoleApprovalLevelModel::getUsersWithApprovalLevel(2);
            $approverUsers = $level1Users
                ->merge($level2Users)
                ->unique('id')
                ->map(function($user) {
                    return [
                        'id' => $user->id,
                        'name' => $user->fullname ?? $user->name ?? $user->email
                    ];
                })
                ->values()
                ->sortBy('name')
                ->values();

            // Format response for mobile
            return response()->json([
                'success' => true,
                'data' => [
                    'items' => $data['items'] ?? [],
                    'count' => $data['count'] ?? 0,
                    'total_amount' => $data['total_amount'] ?? 0,
                    'summaries' => $data['summaries'], // Will be null if no assignee filter
                    'users' => $approverUsers,
                    'current_user_id' => $user->id, // Current logged-in user ID
                    'current_user_name' => $user->fullname ?? $user->name ?? $user->email,
                    'has_level_1_rights' => $hasLevel1Rights,
                    'has_level_2_rights' => $hasLevel2Rights,
                    'last_synced' => now()->toIso8601String()
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Mobile approvals error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'An error occurred while loading approvals'
            ], 500);
        }
    }

    /**
     * ⭐ FAST: Get online approvals only (dedicated endpoint for Online Approvals screen)
     * Much faster because it only queries online area and skips user list fetching
     * 
     * GET /api/mobile/approvals/online
     */
    public function onlineOnly(Request $request)
    {
        $startTime = microtime(true);
        $timings = [];
        
        try {
            $user = auth()->user();
            $timings['auth'] = round((microtime(true) - $startTime) * 1000, 2);
            
            // Check if user has L1 or L2 approval rights
            $checkStart = microtime(true);
            $hasLevel1Rights = RoleApprovalLevelModel::userHasApprovalLevel($user->id, 1);
            $hasLevel2Rights = RoleApprovalLevelModel::userHasApprovalLevel($user->id, 2);
            $timings['permission_check'] = round((microtime(true) - $checkStart) * 1000, 2);
            
            if (!$hasLevel1Rights && !$hasLevel2Rights) {
                return response()->json([
                    'success' => false,
                    'message' => 'You do not have approval rights'
                ], 403);
            }

            // ⭐ Check if we want approved items (for "Approved" tab)
            $includeApproved = $request->boolean('include_approved', false);

            // ⭐ Direct query for online area only - much faster than full approvals
            $items = [];
            $approvedItems = [];
            
            // Get pending ledger entries for online area
            // ⭐ OPTIMIZED: Use select() to limit columns and constrained eager loading
            $queryStart = microtime(true);
            $pendingLedger = \App\Models\FIN\LedgerModel::select([
                    'id', 'order_id', 'created_by', 'approval_status', 
                    'amount', 'description', 'transaction_date', 'mode',
                    'receiving_account_id' // ⭐ Which bank received this payment
                ])
                ->whereIn('approval_status', ['pending', 'pending_l1', 'pending_l2'])
                ->whereNull('request_id') // Exclude ledger entries linked to requests
                ->where('mode', 'online') // ⭐ Simplified: Only online mode (most common case)
                ->with([
                    'order:id,order_number,order_date,customer_id,order_status', // ⭐ Added order_status for delivery_date accessor
                    'order.customer:id,first_name,last_name,company,phone,phone_original', // ⭐ Added phone_original for WhatsApp
                    'createdBy:id,fullname',
                    'receivingAccount:id,name,short_code,color_hex' // ⭐ Which bank received payment
                ])
                ->orderBy('transaction_date', 'desc')
                ->get();
            $timings['db_query'] = round((microtime(true) - $queryStart) * 1000, 2);
            
            $processStart = microtime(true);
            foreach ($pendingLedger as $ledger) {
                // Determine level: pending_l2 = level 2, otherwise level 1
                $level = $ledger->approval_status === 'pending_l2' ? 2 : 1;
                
                // Use order number if available
                $displayNumber = $ledger->order ? $ledger->order->order_number : ('TXN-' . $ledger->id);
                
                // ⭐ Use delivery_date (actual delivered date) instead of order_date
                $deliveryDate = $ledger->order ? $ledger->order->delivery_date : null;
                $displayDate = $deliveryDate 
                    ?? ($ledger->order && $ledger->order->order_date 
                        ? $ledger->order->order_date->format('Y-m-d') 
                        : ($ledger->transaction_date ? $ledger->transaction_date->format('Y-m-d') : null));
                
                // ⭐ For invoices, requester = CUSTOMER NAME (for grouping by customer)
                $requester = 'Unknown';
                $customerPhone = null;
                if ($ledger->order && $ledger->order->customer) {
                    $customer = $ledger->order->customer;
                    $fullName = trim(($customer->first_name ?? '') . ' ' . ($customer->last_name ?? ''));
                    $requester = $fullName ?: $customer->company ?: $customer->phone ?: 'Unknown';
                    // ⭐ Get customer phone for WhatsApp
                    $customerPhone = $customer->phone_original ?? $customer->phone ?? null;
                } elseif ($ledger->createdBy) {
                    $requester = $ledger->createdBy->fullname ?? 'System';
                }
                
                $items[] = [
                    'id' => $ledger->id,
                    'type' => 'ledger',
                    'number' => $displayNumber,
                    'title' => 'Online Invoice',
                    'description' => $ledger->description,
                    'amount' => (float) $ledger->amount,
                    'date' => $displayDate,
                    'level' => $level,
                    'area' => 'online',
                    'requester' => $requester,
                    'customer_phone' => $customerPhone, // ⭐ For WhatsApp messaging
                    'category' => 'Invoice',
                    'category_color' => '#DBEAFE',
                    'order_id' => $ledger->order_id, // ⭐ For direct order view link
                    // ⭐ Receiving bank account info
                    'receiving_account_id' => $ledger->receiving_account_id,
                    'receiving_account_name' => $ledger->receivingAccount?->name,
                    'receiving_account_short' => $ledger->receivingAccount?->short_code,
                    'receiving_account_color' => $ledger->receivingAccount?->color_hex,
                ];
            }
            $timings['process_loop'] = round((microtime(true) - $processStart) * 1000, 2);

            // ⭐ NEW: Fetch approved items from last 30 days if requested
            if ($includeApproved) {
                $approvedStart = microtime(true);
                $thirtyDaysAgo = now()->subDays(30)->startOfDay();
                
                $approvedLedger = \App\Models\FIN\LedgerModel::select([
                        'id', 'order_id', 'created_by', 'approved_by', 'approval_status', 
                        'amount', 'description', 'transaction_date', 'approval_date', 
                        'mode', 'comments', 'receiving_account_id'
                    ])
                    ->where('approval_status', 'approved')
                    ->whereNull('request_id')
                    ->where('mode', 'online')
                    ->where('approval_date', '>=', $thirtyDaysAgo)
                    ->with([
                        'order:id,order_number,order_date,customer_id,order_status', // ⭐ Added order_status for delivery_date accessor
                        'order.customer:id,first_name,last_name,company,phone,phone_original',
                        'createdBy:id,fullname',
                        'approvedBy:id,fullname',
                        'receivingAccount:id,name,short_code,color_hex' // ⭐ Which bank received payment
                    ])
                    ->orderBy('approval_date', 'desc')
                    ->get();
                
                foreach ($approvedLedger as $ledger) {
                    $displayNumber = $ledger->order ? $ledger->order->order_number : ('TXN-' . $ledger->id);
                    // ⭐ Use delivery_date (actual delivered date) instead of order_date
                    $deliveryDateApproved = $ledger->order ? $ledger->order->delivery_date : null;
                    $displayDate = $deliveryDateApproved 
                        ?? ($ledger->order && $ledger->order->order_date 
                            ? $ledger->order->order_date->format('Y-m-d') 
                            : ($ledger->transaction_date ? $ledger->transaction_date->format('Y-m-d') : null));
                    
                    $requester = 'Unknown';
                    $customerPhone = null;
                    if ($ledger->order && $ledger->order->customer) {
                        $customer = $ledger->order->customer;
                        $fullName = trim(($customer->first_name ?? '') . ' ' . ($customer->last_name ?? ''));
                        $requester = $fullName ?: $customer->company ?: $customer->phone ?: 'Unknown';
                        $customerPhone = $customer->phone_original ?? $customer->phone ?? null;
                    } elseif ($ledger->createdBy) {
                        $requester = $ledger->createdBy->fullname ?? 'System';
                    }
                    
                    // ⭐ Parse L1 approval info from comments
                    $l1ApprovedBy = null;
                    $l1ApprovedAt = null;
                    if ($ledger->comments) {
                        // Look for pattern: "L1 approved by User ID X"
                        if (preg_match('/L1 approved by User ID (\d+)/i', $ledger->comments, $matches)) {
                            $l1UserId = $matches[1];
                            $l1User = \App\Models\SysAdmin\UserModel::select('id', 'fullname')->find($l1UserId);
                            $l1ApprovedBy = $l1User ? $l1User->fullname : "User #{$l1UserId}";
                        }
                        // Look for "Fully approved (L1+L2) by User ID X"
                        if (preg_match('/Fully approved \(L1\+L2\) by User ID (\d+)/i', $ledger->comments, $matches)) {
                            $l1UserId = $matches[1];
                            $l1User = \App\Models\SysAdmin\UserModel::select('id', 'fullname')->find($l1UserId);
                            $l1ApprovedBy = $l1User ? $l1User->fullname : "User #{$l1UserId}";
                            // For full approval, L1 and L2 are same person and time
                        }
                    }
                    
                    $approvedItems[] = [
                        'id' => $ledger->id,
                        'type' => 'ledger',
                        'number' => $displayNumber,
                        'title' => 'Online Invoice',
                        'description' => $ledger->description,
                        'amount' => (float) $ledger->amount,
                        'date' => $displayDate,
                        'approval_date' => $ledger->approval_date ? $ledger->approval_date->format('Y-m-d') : null,
                        'level' => 0, // 0 = approved (no longer pending)
                        'area' => 'online',
                        'requester' => $requester,
                        'customer_phone' => $customerPhone,
                        'category' => 'Invoice',
                        'category_color' => '#D1FAE5', // Green tint for approved
                        'order_id' => $ledger->order_id, // ⭐ For direct order view link
                        // ⭐ Approval details
                        'l1_approved_by' => $l1ApprovedBy,
                        'l1_approved_at' => $l1ApprovedBy ? $ledger->approval_date?->format('Y-m-d') : null, // Use approval_date as proxy
                        'l2_approved_by' => $ledger->approvedBy?->fullname ?? null,
                        'l2_approved_at' => $ledger->approval_date?->format('Y-m-d'),
                        'approved_by' => $ledger->approvedBy?->fullname ?? 'System',
                        // ⭐ Receiving bank account info
                        'receiving_account_id' => $ledger->receiving_account_id,
                        'receiving_account_name' => $ledger->receivingAccount?->name,
                        'receiving_account_short' => $ledger->receivingAccount?->short_code,
                        'receiving_account_color' => $ledger->receivingAccount?->color_hex,
                    ];
                }
                $timings['approved_query'] = round((microtime(true) - $approvedStart) * 1000, 2);
            }

            // Jun-2026 — Attach payment-proof status (WhatsApp screenshot / bank
            // email) to each item via one bulk lookup. No-op when feature is off.
            if (config('payment_signals.enabled')) {
                $proofOrderIds = [];
                foreach ($items as $it) { if (!empty($it['order_id'])) { $proofOrderIds[] = $it['order_id']; } }
                foreach ($approvedItems as $it) { if (!empty($it['order_id'])) { $proofOrderIds[] = $it['order_id']; } }
                $proofOrderIds = array_values(array_unique($proofOrderIds));
                if (!empty($proofOrderIds)) {
                    $proofMap = app(\App\Services\Payments\Signals\PaymentProofStatusService::class)
                        ->forOrders($proofOrderIds);
                    foreach ($items as &$it) {
                        $it['payment_proof'] = (!empty($it['order_id']) && isset($proofMap[$it['order_id']]))
                            ? $proofMap[$it['order_id']] : null;
                    }
                    unset($it);
                    foreach ($approvedItems as &$it) {
                        $it['payment_proof'] = (!empty($it['order_id']) && isset($proofMap[$it['order_id']]))
                            ? $proofMap[$it['order_id']] : null;
                    }
                    unset($it);
                }
            }

            // Sort by date descending
            $sortStart = microtime(true);
            usort($items, function($a, $b) {
                return strtotime($b['date'] ?? '1970-01-01') - strtotime($a['date'] ?? '1970-01-01');
            });
            $timings['sort'] = round((microtime(true) - $sortStart) * 1000, 2);

            // Calculate summary
            $l1Items = array_filter($items, fn($i) => $i['level'] === 1);
            $l2Items = array_filter($items, fn($i) => $i['level'] === 2);
            
            $timings['total_ms'] = round((microtime(true) - $startTime) * 1000, 2);
            $timings['item_count'] = count($items);
            $timings['approved_count'] = count($approvedItems);
            
            // Log timings for debugging
            Log::info('Online approvals timing', $timings);
            
            // ⭐ Fetch receiving accounts for bank selection dropdown
            $receivingAccounts = \App\Models\FIN\OnlineReceivingAccountModel::active()->ordered()
                ->get(['id', 'name', 'short_code', 'color_hex']);

            // Fetch current Online Bank balance
            $onlineAccount = \App\Models\FIN\AccountModel::where('account_code', 'ONLINE')->first();
            $onlineBalance = $onlineAccount ? $onlineAccount->current_balance : 0;

            $responseData = [
                'items' => $items,
                'count' => count($items),
                'summary' => [
                    'total' => count($items),
                    'total_amount' => array_sum(array_column($items, 'amount')),
                    'l1' => [
                        'count' => count($l1Items),
                        'amount' => array_sum(array_column($l1Items, 'amount'))
                    ],
                    'l2' => [
                        'count' => count($l2Items),
                        'amount' => array_sum(array_column($l2Items, 'amount'))
                    ]
                ],
                'online_balance' => (float) $onlineBalance,
                'has_level_1_rights' => $hasLevel1Rights,
                'has_level_2_rights' => $hasLevel2Rights,
                'receiving_accounts' => $receivingAccounts,
                'last_synced' => now()->toIso8601String(),
                '_debug_timings' => $timings
            ];
            
            // ⭐ Include approved items if requested
            if ($includeApproved) {
                $responseData['approved_items'] = $approvedItems;
                $responseData['approved_count'] = count($approvedItems);
                $responseData['approved_amount'] = array_sum(array_column($approvedItems, 'amount'));
            }
            
            return response()->json([
                'success' => true,
                'data' => $responseData
            ]);

        } catch (\Exception $e) {
            Log::error('Mobile online approvals error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'An error occurred while loading online approvals'
            ], 500);
        }
    }

    /**
     * Get summary statistics only (lightweight endpoint for sync)
     * 
     * GET /api/mobile/approvals/summaries
     */
    public function summaries(Request $request)
    {
        try {
            $user = auth()->user();
            
            $hasLevel1Rights = RoleApprovalLevelModel::userHasApprovalLevel($user->id, 1);
            $hasLevel2Rights = RoleApprovalLevelModel::userHasApprovalLevel($user->id, 2);
            
            if (!$hasLevel1Rights && !$hasLevel2Rights) {
                return response()->json([
                    'success' => false,
                    'message' => 'You do not have approval rights'
                ], 403);
            }

            // Get summaries from web controller
            $request->headers->set('X-Requested-With', 'XMLHttpRequest');
            $request->headers->set('Accept', 'application/json');
            
            $response = $this->approvalController->index($request);
            $data = $response->getData(true);

            return response()->json([
                'success' => true,
                'data' => [
                    'summaries' => $data['summaries'],
                    'last_synced' => now()->toIso8601String()
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Mobile approvals summaries error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'An error occurred'
            ], 500);
        }
    }
}

