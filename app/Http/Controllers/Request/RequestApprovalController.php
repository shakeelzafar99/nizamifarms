<?php

namespace App\Http\Controllers\Request;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Request\RequestModel;
use App\Models\SysAdmin\RoleApprovalLevelModel;
use Illuminate\Support\Facades\Log;

class RequestApprovalController extends Controller
{
    /**
     * Refuse an approval that sets an ONLINE payment source without naming the
     * bank it left from.
     *
     * FALSE until the APK carrying the bank picker on Daily Closing / Fleet
     * approve is out — turning it on first would block those approvals on the day
     * the web files go up. Flip to true after that build ships; the warn-log in
     * approve() shows how often it would have fired.
     */
    private const ENFORCE_APPROVE_BANK = false;

    /**
     * Approve request at a given level
     */
    public function approve(Request $request, $id)
    {
        $validated = $request->validate([
            'level' => 'required|in:1,2',
            'comments' => 'nullable|string',
            'payment_source_account_id' => 'nullable|exists:t_fin_accounts,id',
            // ⭐ Aug-2026: the approve side accepted a payment source but NOT the
            // bank it left from, so an approver switching a claim to an ONLINE
            // source posted an untagged bank outflow and the per-bank balances
            // drifted. The create side has demanded this since Jul-2026; approval
            // is now the MAIN path for choosing the account (owner ruling: the
            // approver's pick outranks the filer's), so it has to demand it too.
            'receiving_account_id' => 'nullable|integer|exists:t_fin_online_receiving_accounts,id',
        ]);

        $requestModel = RequestModel::findOrFail($id);
        $user = auth()->user();
        $level = (int) $validated['level'];

        // Check if user has approval rights for this level
        if (!RoleApprovalLevelModel::userHasApprovalLevel($user->id, $level)) {
            return response()->json([
                'success' => false,
                'message' => "You don't have Level {$level} approval rights"
            ], 403);
        }

        // Check if request can be approved at this level
        if (!$requestModel->canBeApprovedByLevel($level)) {
            return response()->json([
                'success' => false,
                'message' => "This request cannot be approved at Level {$level} at this time"
            ], 400);
        }

        // ── The account this money comes out of ────────────────────────────────
        // Owner ruling (Aug-2026): the APPROVER's pick outranks the filer's — a
        // rider cannot know which account his claim should be deducted from. So
        // this is the authoritative choice, and it is checked against the
        // APPROVER's own allow-list, in the business unit the request was stamped
        // with, through the same PaymentSourceService call that built their picker.
        if (isset($validated['payment_source_account_id'])) {
            $sourceAccount = \App\Models\FIN\AccountModel::find($validated['payment_source_account_id']);
            $buForSource   = $requestModel->business_unit_id ?: 1;
            $purpose       = ($requestModel->category->category_code ?? null) === 'salary_advance'
                ? \App\Services\FIN\PaymentSourceService::PURPOSE_ADVANCE
                : \App\Services\FIN\PaymentSourceService::PURPOSE_EXPENSE;

            if (!app(\App\Services\FIN\PaymentSourceService::class)
                    ->allows($user, $validated['payment_source_account_id'], $buForSource, $purpose)) {
                return response()->json([
                    'success' => false,
                    'message' => 'You are not set up to pay from that account. Ask Taimur or Shabib to add you to it (Ledger Hub → the account → "Who uses this account").'
                ], 403);
            }

            $isBankSource = $sourceAccount
                && $sourceAccount->account_category === \App\Models\FIN\AccountModel::CATEGORY_BANK;
            $bankGiven = $validated['receiving_account_id'] ?? null;

            if ($isBankSource && !$bankGiven) {
                // ⚠ STAGED ROLLOUT. The mobile approve screens do not send a bank
                // until the next APK, and refusing them outright would block Daily
                // Closing approvals the day the web files go up. So: warn now,
                // enforce once the APK is out — flip ENFORCE_APPROVE_BANK to true
                // and delete this branch. Same pattern as meter_required.
                // Until then the row keeps whatever bank was filed with it (below),
                // which is right far more often than null.
                if (self::ENFORCE_APPROVE_BANK) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Select which bank this online payment is made from.'
                    ], 422);
                }
                Log::warning('Approval set an online payment source without naming a bank', [
                    'request_id' => $requestModel->id,
                    'account_id' => $validated['payment_source_account_id'],
                    'approver'   => $user->id,
                    'kept_bank'  => $requestModel->receiving_account_id,
                ]);
            }

            $requestModel->payment_source_account_id = $validated['payment_source_account_id'];
            // A cash-funded row must never carry a bank tag; a bank-funded one keeps
            // the bank just named, else whatever it was filed with.
            $requestModel->receiving_account_id = $isBankSource
                ? ($bankGiven ?: $requestModel->receiving_account_id)
                : null;
            $requestModel->save();
        }

        try {

            $success = $requestModel->processApproval(
                $level,
                $user->id,
                'approved',
                $validated['comments'] ?? null
            );

            if ($success) {
                // ⭐ SMART SYNC: Flag that requester needs to sync
                $requestModel->requester_sync_required = true;
                $requestModel->save();

                // 🔧 The bike service-clock reset (approved oil change → Fleet
                // due/overdue chip) now fires inside RequestModel::processApproval,
                // so every approval path gets it — see BikeServiceClock.

                return response()->json([
                    'success' => true,
                    'message' => "Request approved at Level {$level}",
                    'request_status' => $requestModel->fresh()->status
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to process approval'
                ], 500);
            }

        } catch (\Exception $e) {
            Log::error('Request approval error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to approve request: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Reject request at a given level
     */
    public function reject(Request $request, $id)
    {
        $validated = $request->validate([
            'level' => 'required|in:1,2',
            'comments' => 'required|string'
        ]);

        $requestModel = RequestModel::findOrFail($id);
        $user = auth()->user();
        $level = (int) $validated['level'];

        // Check if user has approval rights for this level
        if (!RoleApprovalLevelModel::userHasApprovalLevel($user->id, $level)) {
            return response()->json([
                'success' => false,
                'message' => "You don't have Level {$level} approval rights"
            ], 403);
        }

        // Check if request can be approved/rejected at this level
        if (!$requestModel->canBeApprovedByLevel($level)) {
            return response()->json([
                'success' => false,
                'message' => "This request cannot be rejected at Level {$level} at this time"
            ], 400);
        }

        try {
            $success = $requestModel->processApproval(
                $level,
                $user->id,
                'rejected',
                $validated['comments']
            );

            if ($success) {
                // ⭐ SMART SYNC: Flag that requester needs to sync
                $requestModel->requester_sync_required = true;
                $requestModel->save();
                
                return response()->json([
                    'success' => true,
                    'message' => "Request rejected at Level {$level}",
                    'request_status' => $requestModel->fresh()->status
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to process rejection'
                ], 500);
            }

        } catch (\Exception $e) {
            Log::error('Request rejection error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to reject request: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get approval statistics (for dashboard/reporting)
     */
    public function statistics()
    {
        $user = auth()->user();
        
        $canApproveLevel1 = RoleApprovalLevelModel::userHasApprovalLevel($user->id, 1);
        $canApproveLevel2 = RoleApprovalLevelModel::userHasApprovalLevel($user->id, 2);

        $stats = [];

        if ($canApproveLevel1) {
            $stats['pending_level_1'] = RequestModel::where('status', RequestModel::STATUS_PENDING)
                ->where('requires_level_1', 1)
                ->where('level_1_status', RequestModel::APPROVAL_STATUS_PENDING)
                ->count();
        }

        if ($canApproveLevel2) {
            $stats['pending_level_2'] = RequestModel::where('status', RequestModel::STATUS_PENDING)
                ->where('requires_level_2', 1)
                ->where('level_2_status', RequestModel::APPROVAL_STATUS_PENDING)
                ->where(function($q) {
                    $q->where('requires_level_1', 0)
                      ->orWhere('level_1_status', RequestModel::APPROVAL_STATUS_APPROVED);
                })
                ->count();
        }

        $stats['my_pending'] = RequestModel::where('requester_user_id', $user->id)
            ->where('status', RequestModel::STATUS_PENDING)
            ->count();

        $stats['my_approved'] = RequestModel::where('requester_user_id', $user->id)
            ->where('status', RequestModel::STATUS_APPROVED)
            ->count();

        $stats['my_rejected'] = RequestModel::where('requester_user_id', $user->id)
            ->where('status', RequestModel::STATUS_REJECTED)
            ->count();

        return response()->json([
            'success' => true,
            'data' => $stats
        ]);
    }
}

