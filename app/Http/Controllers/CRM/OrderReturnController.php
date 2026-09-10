<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Models\CRM\OrderModel;
use App\Models\CRM\OrderReturnModel;
use App\Services\CRM\OrderReturnService;
use App\Services\OrderStatusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * RETURNS — the web door. A delivered order comes back.
 *
 * Deliberately its own controller rather than more branches inside
 * OrderStatusController: a return asks three questions, posts money and starts a
 * store task, none of which a status change does. Keeping it separate also means
 * the status endpoint can simply REFUSE the code, which is the whole gate.
 *
 * Three endpoints, in the order the manager meets them:
 *   preview  — what the ledger says, which answers are legal, what is on the order
 *   store    — take the return (money now, goods promised)
 *   pending  — the amber "not back on the shelf yet" strip
 */
class OrderReturnController extends Controller
{
    public function __construct(
        private OrderReturnService $returns,
        private OrderStatusService $statusService
    ) {
    }

    /**
     * ⭐ ONE gate, asked on every door.
     *
     * Returns move money and write off stock, so this is not a "view" permission.
     * The service holds the rule (the `return_orders` role permission, nothing else)
     * because the mobile door and the status endpoint have to ask exactly the
     * same question.
     */
    private function guard(): ?JsonResponse
    {
        if (!$this->returns->userCanReturn(auth()->user())) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to return an order. Ask Shabib or Taimur to grant "Return a Delivered Order" on your role.',
            ], 403);
        }

        return null;
    }

    /**
     * The read-only lists carry customer names and return reasons, so they are
     * not open to every login: anyone who may take a return, or who may see ALL
     * orders (the office), and never a rider account.
     */
    private function viewGuard(): ?JsonResponse
    {
        $user = auth()->user();
        $ok = $user && (
            $this->returns->userCanReturn($user)
            || (method_exists($user, 'hasPermission') && $user->hasPermission('view_all_orders'))
        );

        if (!$ok) {
            return response()->json(['success' => false, 'message' => 'Not allowed.'], 403);
        }

        return null;
    }

    /**
     * Everything the return form needs.
     *
     * ⚠ Read-only, and the answers it offers are recomputed on the way back in —
     * a form left open while the rider settles his cash must not be able to post
     * a reversal that is no longer legal.
     */
    public function preview(Request $request, $id): JsonResponse
    {
        if ($resp = $this->guard()) {
            return $resp;
        }

        $order = OrderModel::find($id);
        if (!$order) {
            return response()->json(['success' => false, 'message' => 'Order not found'], 404);
        }

        return response()->json([
            'success' => true,
            'preview' => $this->returns->previewFor($order),
        ]);
    }

    /**
     * Take the return.
     *
     * The money and the status move together inside changeStatus's transaction:
     * either both happen or neither does. The goods are only PROMISED here — the
     * store puts them back by scanning, which is the only evidence that they
     * actually came back.
     */
    public function store(Request $request, $id): JsonResponse
    {
        if ($resp = $this->guard()) {
            return $resp;
        }

        $validated = $request->validate([
            'money_action' => 'required|in:reversed,refund,credit,none',
            'goods_action' => 'required|in:restock,wasted',
            'meat_section' => 'nullable|in:freezer,chiller',
            'return_tip'   => 'nullable|boolean',
            'reason'       => 'nullable|string|max:255',
            'notes'        => 'nullable|string|max:500',
        ]);

        $order = OrderModel::find($id);
        if (!$order) {
            return response()->json(['success' => false, 'message' => 'Order not found'], 404);
        }

        if (strtolower((string) $order->order_status) !== 'delivered') {
            return response()->json([
                'success' => false,
                'message' => 'Only a delivered order can be returned. This one is ' . $order->order_status . '.',
            ], 422);
        }

        $result = $this->statusService->changeOrderStatus(
            (int) $order->id,
            OrderReturnService::STATUS_CODE,
            trim('Returned' . ($validated['reason'] ? ' — ' . $validated['reason'] : '')),
            auth()->id(),
            ['return' => [
                'money_action' => $validated['money_action'],
                'goods_action' => $validated['goods_action'],
                'meat_section' => $validated['meat_section'] ?? null,
                'return_tip'   => (bool) ($validated['return_tip'] ?? false),
                'reason'       => $validated['reason'] ?? null,
                'notes'        => $validated['notes'] ?? null,
            ]]
        );

        if (!$result['success']) {
            // The refusal text comes from OrderReturnException via
            // OrderModel::$lastStatusError — safe to show, and the only way the
            // manager learns what to do instead.
            return response()->json($result, 422);
        }

        $result['message'] = $this->confirmationLine($result['return'] ?? null, $order);

        return response()->json($result);
    }

    /**
     * A sentence the manager can read without opening anything else — what
     * happened to the money, the tip, and who has to do what next.
     */
    private function confirmationLine(?array $return, OrderModel $order): string
    {
        if (!$return) {
            return 'Order ' . $order->order_number . ' marked as returned.';
        }

        $money = match ($return['money_action']) {
            OrderReturnModel::MONEY_REVERSED => 'the invoice was reversed',
            OrderReturnModel::MONEY_REFUND   => 'Rs ' . number_format($return['amount'], 2) . ' is being refunded',
            OrderReturnModel::MONEY_CREDIT   => 'Rs ' . number_format($return['amount'], 2) . ' went to the customer\'s account balance',
            default                          => 'no money was involved',
        };

        $parts = ['Order ' . $order->order_number . ' returned — ' . $money];

        if (!empty($return['tip_returned']) && $return['tip_amount'] > 0) {
            $parts[] = 'the Rs ' . number_format($return['tip_amount'], 2) . ' tip went back too';
        }

        $parts[] = $return['goods_action'] === OrderReturnModel::GOODS_WASTED
            ? 'the goods were written off, so there is nothing to put back'
            : 'the store has been asked to scan the items back in';

        return implode('. ', $parts) . '.';
    }

    /**
     * Returns whose goods are still not back on the shelf — the web twin of the
     * store banner, so the office can see what the shop has not done yet.
     *
     * Visible to anyone who can see orders: this is a nag, not a money screen.
     */
    public function pending(Request $request): JsonResponse
    {
        if ($resp = $this->viewGuard()) {
            return $resp;
        }

        return response()->json([
            'success' => true,
            'pending' => $this->returns->pendingPutBacks((int) $request->input('limit', 20)),
        ]);
    }

    /** One return's full detail — used by the web strip's "what is missing?" popover. */
    public function detail(Request $request, $returnId): JsonResponse
    {
        if ($resp = $this->viewGuard()) {
            return $resp;
        }

        try {
            return response()->json([
                'success' => true,
                'detail'  => $this->returns->putBackDetail((int) $returnId),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 404);
        }
    }
}
