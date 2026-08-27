<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Models\CRM\CustomerCreditModel;
use App\Models\CRM\OrderModel;
use App\Models\FIN\LedgerModel;
use App\Models\SysAdmin\RoleApprovalLevelModel;
use App\Services\CustomerCreditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Customer credit ("bucket") — the web endpoints.
 *
 * All the money rules live in CustomerCreditService; this class only checks who
 * is allowed to ask and turns the answers into JSON.
 *
 * ⚠ Adding credit is creating spendable money, so every grant path is gated on
 * LEVEL 2 approval rights — the same gate the ledger uses — never on a name.
 */
class CustomerCreditController extends Controller
{
    public function __construct(private CustomerCreditService $credit)
    {
    }

    // =====================================================================
    // READS — what the prompts are built from
    // =====================================================================

    /** GET /customer-credit/{customerId}/summary */
    public function summary(Request $request, int $customerId)
    {
        $data = $this->credit->summaryFor($customerId, (int) $request->input('limit', 10));
        // Lets the panel show Approve/Reject only to someone who can actually
        // use them, instead of offering a button that always 403s.
        $data['can_approve'] = $this->hasLevel2();

        return response()->json(['success' => true, 'data' => $data]);
    }

    /**
     * GET /orders/{orderId}/credit-offer
     *
     * The one call every prompt uses: "does this order's customer have money
     * sitting with us, and may it be used here?" Feeds the banner on the order
     * edit modal (including a Shopify order the manager just converted) and the
     * new-order form.
     */
    public function offerForOrder(Request $request, int $orderId)
    {
        $order = OrderModel::find($orderId);
        if (!$order) {
            return response()->json(['success' => false, 'message' => 'Order not found.'], 404);
        }

        $summary = $this->credit->summaryFor($order->customer_id);
        $applied = $this->credit->liveConsumeForOrder($orderId);

        // Is the order's invoice still open (pre-L1 / reversed / absent)? Both
        // applying and removing credit are only allowed while it is — past L1
        // the posted amount is in the balances and must not drift.
        $invoiceOpen = true;
        if ($order->ledger_transaction_id) {
            $status = \DB::table('t_fin_ledger')->where('id', $order->ledger_transaction_id)->value('approval_status');
            $invoiceOpen = $status === null || in_array($status, [
                LedgerModel::STATUS_PENDING,
                LedgerModel::STATUS_PENDING_L1,
                LedgerModel::STATUS_REVERSED,
                LedgerModel::STATUS_REJECTED,
            ], true);
        }

        // Why the offer cannot be taken right now (already used, invoice locked,
        // shop customer). The banner shows this instead of a dead button.
        $blocked = null;
        if (!$summary['eligible']) {
            $blocked = 'not_eligible';
        } elseif ($applied) {
            $blocked = 'already_applied';
        } elseif ($order->order_status === 'cancelled') {
            $blocked = 'cancelled';
        } elseif (!$invoiceOpen) {
            $blocked = 'invoice_approved';
        }

        $balance   = (float) $summary['balance'];
        $orderLeft = round((float) $order->total_price, 2);

        return response()->json([
            'success' => true,
            'data'    => [
                'order_id'      => $order->id,
                'order_number'  => $order->order_number,
                'order_total'   => $orderLeft,
                'eligible'      => $summary['eligible'],
                'balance'       => $balance,
                'balance_display' => $summary['balance_display'],
                'has_balance'   => $summary['has_balance'],
                'history'       => $summary['history'],
                // What would actually come off if the manager says yes.
                'suggested'     => round(min($balance, $orderLeft), 2),
                'applied'       => $applied ? round(abs((float) $applied->amount), 2) : 0.0,
                'applied_state' => $applied?->status,
                // Whether applied credit may still be taken off (invoice still open).
                'removable'     => $applied !== null && $invoiceOpen && $order->order_status !== 'cancelled',
                'blocked'       => $blocked,
                // Only offer the prompt when there is something to offer, it can be
                // taken, AND the order actually has something left to pay.
                'should_prompt' => $summary['eligible'] && $summary['has_balance']
                    && $blocked === null && $orderLeft >= 0.01,
            ],
        ]);
    }

    // =====================================================================
    // CONSUME
    // =====================================================================

    /** POST /orders/{orderId}/credit/apply  { amount } */
    public function apply(Request $request, int $orderId)
    {
        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.01',
        ]);

        try {
            $result = $this->credit->applyToOrder($orderId, (float) $validated['amount'], (int) auth()->id());

            return response()->json([
                'success' => true,
                'message' => 'Rs ' . number_format($result['applied'], 2) . ' applied from the account balance.',
                'data'    => $result,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    /** POST /orders/{orderId}/credit/remove */
    public function remove(Request $request, int $orderId)
    {
        try {
            $released = $this->credit->releaseFromOrder(
                $orderId,
                (int) auth()->id(),
                'Removed from order by ' . (auth()->user()->fullname ?? 'user')
            );

            if ($released === null) {
                return response()->json(['success' => false, 'message' => 'This order is not using the account balance.'], 422);
            }

            return response()->json([
                'success' => true,
                'message' => 'Rs ' . number_format($released, 2) . ' returned to the customer\'s balance.',
                'data'    => ['released' => $released],
            ]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    // =====================================================================
    // GRANTS
    // =====================================================================

    /**
     * POST /customer-credit/{customerId}/grant
     * The manual "I received extra money from this customer" entry. Creates a
     * PENDING grant that shows up in the approvals queue like everything else.
     */
    public function grant(Request $request, int $customerId)
    {
        $validated = $request->validate([
            'amount'               => 'required|numeric|min:1',
            'mode'                 => 'nullable|in:cash,online',
            'receiving_account_id' => 'nullable|integer',
            'order_id'             => 'nullable|integer',
            'reason'               => 'nullable|string|max:255',
        ]);

        try {
            $credit = $this->credit->requestGrant($customerId, (float) $validated['amount'], (int) auth()->id(), [
                'order_id'             => $validated['order_id'] ?? null,
                'receiving_account_id' => $validated['receiving_account_id'] ?? null,
                'mode'                 => $validated['mode'] ?? null,
                'source'               => CustomerCreditModel::SOURCE_MANUAL,
                'reason'               => $validated['reason'] ?? null,
            ]);

            // Shabib/Taimur's own entries come back already ACTIVE (auto-
            // approved in the service); everyone else's are pending. Say which.
            $message = $credit->status === CustomerCreditModel::STATUS_ACTIVE
                ? 'Rs ' . number_format((float) $credit->amount, 2) . ' added to the customer\'s balance.'
                : 'Rs ' . number_format((float) $credit->amount, 2)
                    . ' recorded and sent for approval. It becomes usable balance once approved.';

            return response()->json([
                'success' => true,
                'message' => $message,
                'data'    => ['credit_id' => $credit->id, 'status' => $credit->status],
            ]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    /** POST /customer-credit/{creditId}/approve  — LEVEL 2 only. */
    public function approve(Request $request, int $creditId)
    {
        if ($deny = $this->denyUnlessLevel2()) {
            return $deny;
        }

        $mode = $request->input('mode') === 'cash' ? LedgerModel::MODE_CASH : LedgerModel::MODE_ONLINE;

        try {
            $credit = $this->credit->approveGrant($creditId, (int) auth()->id(), $mode);

            return response()->json([
                'success' => true,
                'message' => 'Rs ' . number_format((float) $credit->amount, 2) . ' added to the customer\'s balance.',
                'data'    => ['credit_id' => $credit->id, 'status' => $credit->status],
            ]);
        } catch (\Throwable $e) {
            Log::warning('Customer credit approve failed', ['credit_id' => $creditId, 'error' => $e->getMessage()]);

            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    /** POST /customer-credit/{creditId}/reject  — LEVEL 2 only. */
    public function reject(Request $request, int $creditId)
    {
        if ($deny = $this->denyUnlessLevel2()) {
            return $deny;
        }

        try {
            $this->credit->rejectGrant($creditId, (int) auth()->id(), $request->input('reason'));

            return response()->json(['success' => true, 'message' => 'Entry rejected — no balance was added.']);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    /**
     * POST /customer-credit/{customerId}/zero-out  — LEVEL 2 only.
     * Writes the balance off to zero. A reason is required because this row is
     * the only record of why a customer's money stopped being theirs.
     */
    public function zeroOut(Request $request, int $customerId)
    {
        if ($deny = $this->denyUnlessLevel2()) {
            return $deny;
        }

        $validated = $request->validate([
            'reason' => 'required|string|min:3|max:255',
        ]);

        try {
            $amount = $this->credit->zeroOut($customerId, (int) auth()->id(), $validated['reason']);

            return response()->json([
                'success' => true,
                'message' => 'Balance of Rs ' . number_format($amount, 2) . ' cleared.',
                'data'    => ['cleared' => $amount],
            ]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    /** GET /customer-credit/pending — the approval queue for credit grants. */
    public function pending(Request $request)
    {
        $rows = CustomerCreditModel::where('status', CustomerCreditModel::STATUS_PENDING)
            ->orderByDesc('id')
            ->limit(200)
            ->get();

        $customerNames = [];
        if ($rows->isNotEmpty()) {
            $customerNames = \DB::table('t_crm_prod_customer')
                ->whereIn('id', $rows->pluck('customer_id')->unique()->all())
                ->get(['id', 'first_name', 'last_name'])
                ->keyBy('id')
                ->map(fn ($c) => trim(($c->first_name ?? '') . ' ' . ($c->last_name ?? '')))
                ->all();
        }

        $orderNumbers = [];
        $orderIds = $rows->pluck('order_id')->filter()->unique()->all();
        if (!empty($orderIds)) {
            $orderNumbers = \DB::table('t_crm_prod_order')->whereIn('id', $orderIds)
                ->pluck('order_number', 'id')->all();
        }

        return response()->json([
            'success' => true,
            'data'    => $rows->map(fn ($r) => [
                'id'            => $r->id,
                'customer_id'   => $r->customer_id,
                'customer_name' => $customerNames[$r->customer_id] ?? ('Customer #' . $r->customer_id),
                'amount'        => round((float) $r->amount, 2),
                'source'        => $r->source,
                'reason'        => $r->reason,
                'order_id'      => $r->order_id,
                'order_number'  => $r->order_id ? ($orderNumbers[$r->order_id] ?? null) : null,
                'date'          => optional($r->created_at)->format('d M Y, g:i A'),
            ])->all(),
            'can_approve' => $this->hasLevel2(),
        ]);
    }

    // =====================================================================

    /**
     * Who may approve/reject others' balance requests and zero a balance out.
     *
     * NOT plain L2: Shabib's login holds the shared "Management" role and no
     * approval level at all, so an L2-only gate would lock the owner out of
     * his own feature. The service's auto-approver rule (L2 OR the
     * Shabib/Taimur pair) is exactly the set of people trusted with balance
     * money, so both gates share it — one rule, one place.
     */
    private function hasLevel2(): bool
    {
        return $this->credit->userCanAutoApproveGrant(auth()->user());
    }

    private function denyUnlessLevel2()
    {
        return $this->hasLevel2()
            ? null
            : response()->json([
                'success' => false,
                'message' => 'Adding or clearing a customer balance needs approval rights (Shabib/Taimur).',
            ], 403);
    }
}
