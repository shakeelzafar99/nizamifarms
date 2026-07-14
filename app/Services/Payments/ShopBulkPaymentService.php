<?php

namespace App\Services\Payments;

use App\Exceptions\ShopBulkPaymentException;
use App\Models\CRM\CustomerModel;
use App\Models\CRM\OrderModel;
use App\Models\CRM\OrderPaymentModel;
use App\Models\FIN\ConfigModel;
use App\Models\FIN\LedgerModel;
use App\Models\FIN\OnlineReceivingAccountModel;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Splits ONE online payment (a single bank transfer) across several selected
 * shop invoices, oldest-first (FIFO), leaving any shortfall on the NEWEST
 * selected invoice.
 *
 * This is the SINGLE SOURCE OF TRUTH for the bulk-payment rule. It is shared by
 * the web endpoint (CRM\OrderController::bulkShopPayment) and the mobile API
 * endpoint (API\ShopBulkPaymentController::store) so both channels behave
 * identically — the validation lives on the server, not in either frontend.
 *
 * Each per-invoice slice is posted exactly the same way a single shop payment
 * is (CRM\OrderController::recordImmediateOrderPayment, 'shop' context): a new
 * active order_payment row + a paired auto-approved order_payment ledger entry
 * moving money Sales Revenue -> ONLINE bank, tagged with the receiving bank.
 * The whole batch runs in one DB transaction — if any slice fails, nothing is
 * posted.
 *
 * The rule (balances b1..bn in FIFO / oldest-first order, T = payment amount):
 *   - valid range:  sum(b1..b_{n-1}) < T <= sum(b1..bn)
 *   - T > sum(all)              -> overpayment, rejected.
 *   - T <= sum(all but newest)  -> the money can't clear everything except the
 *                                   newest, so two or more invoices would be
 *                                   left short ("not enough for the last two").
 *   - otherwise: b1..b_{n-1} are paid in full and the newest absorbs the
 *     remainder (T - sum(b1..b_{n-1})), which may be a partial payment.
 * (For a single selected invoice this collapses to 0 < T <= its balance, i.e.
 * identical to the existing single "Add payment".)
 */
class ShopBulkPaymentService
{
    /**
     * @param  array  $params  order_ids (int[]), amount, receiving_account_id,
     *                         payment_date?, reference?, notes?, sending_bank?,
     *                         stamp_date?, stamp_ref_mode?
     * @param  \App\Models\SysAdmin\UserModel|mixed  $user  authenticated user
     * @return array  breakdown of what was posted (per-invoice allocation)
     *
     * @throws ShopBulkPaymentException  on any business-rule failure (map to 422)
     */
    public function execute(array $params, $user): array
    {
        $orderIds = array_values(array_unique(array_map('intval', $params['order_ids'] ?? [])));
        $amount = round((float) ($params['amount'] ?? 0), 2);
        $receivingAccountId = $params['receiving_account_id'] ?? null;

        if (count($orderIds) < 1) {
            throw new ShopBulkPaymentException('Select at least one invoice.');
        }
        if ($amount <= 0) {
            throw new ShopBulkPaymentException('Enter a valid payment amount.');
        }
        // Shop payments are online-only, and online payments MUST name the
        // receiving bank so per-bank balances can reconcile against ONLINE.
        if (!$receivingAccountId) {
            throw new ShopBulkPaymentException('Select which bank received this payment.');
        }

        return DB::transaction(function () use ($orderIds, $amount, $receivingAccountId, $params, $user) {
            // Lock the selected orders for the life of the transaction so a
            // concurrent bulk/single payment can't double-allocate the balance.
            $orders = OrderModel::whereIn('id', $orderIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            // Every requested id must still exist.
            $missing = array_diff($orderIds, $orders->keys()->all());
            if (!empty($missing)) {
                throw new ShopBulkPaymentException('One or more selected invoices no longer exist. Refresh and try again.');
            }

            // All invoices must belong to the SAME customer — one transfer has
            // one payer. (This also stops cross-shop mis-selection.)
            $customerIds = $orders->pluck('customer_id')->unique()->values();
            if ($customerIds->count() !== 1) {
                throw new ShopBulkPaymentException('All selected invoices must belong to the same shop.');
            }

            $customer = CustomerModel::find($customerIds->first());
            if (!$customer || !$customer->isShop()) {
                throw new ShopBulkPaymentException('Bulk payment is only available for shop customers.');
            }

            // FIFO fill order: oldest first, so the shortfall lands on the
            // NEWEST selected invoice. Sort by the SAME date the shop list shows
            // (delivery_date, falling back to order_date) so this order is the
            // exact reverse of what the user sees on screen. Tie-break by id.
            $fifoKey = function (OrderModel $o): int {
                $d = $o->delivery_date ?: $o->order_date;
                if (!$d) {
                    return 0;
                }
                try {
                    return Carbon::parse($d)->timestamp;
                } catch (\Throwable $e) {
                    return optional($o->order_date)->timestamp ?? 0;
                }
            };
            $ordered = $orders->values()->all();
            usort($ordered, function ($a, $b) use ($fifoKey) {
                $ka = $fifoKey($a);
                $kb = $fifoKey($b);
                return $ka !== $kb ? ($ka <=> $kb) : ($a->id <=> $b->id);
            });

            // Per-invoice remaining balance, in integer paisa for exact money math.
            $balCents = [];
            foreach ($ordered as $o) {
                $rem = round((float) $o->total_price - (float) ($o->total_paid ?? 0), 2);
                $cents = (int) round($rem * 100);
                if ($cents <= 0) {
                    throw new ShopBulkPaymentException("Invoice {$o->order_number} is already fully paid — deselect it.");
                }
                $balCents[] = $cents;
            }

            $n = count($balCents);
            $amountCents = (int) round($amount * 100);
            $sumAllCents = array_sum($balCents);
            $sumAllButLastCents = $sumAllCents - $balCents[$n - 1];

            // ---- The rule (see class docblock) --------------------------------
            if ($amountCents > $sumAllCents) {
                throw new ShopBulkPaymentException(
                    'Amount (Rs. ' . number_format($amount, 2) . ') is more than the selected invoices\' total balance (Rs. ' .
                    number_format($sumAllCents / 100, 2) . '). Reduce the amount or select more invoices.'
                );
            }
            if ($n > 1 && $amountCents <= $sumAllButLastCents) {
                // How many can it fully cover oldest-first, for a helpful hint.
                $covered = 0;
                $run = 0;
                foreach ($balCents as $c) {
                    if ($run + $c <= $amountCents) {
                        $run += $c;
                        $covered++;
                    } else {
                        break;
                    }
                }
                throw new ShopBulkPaymentException(
                    'This amount only fully covers ' . $covered . ' of ' . $n . ' selected invoices. ' .
                    'A bulk payment must clear every invoice except the newest — that needs more than Rs. ' .
                    number_format($sumAllButLastCents / 100, 2) . '. Deselect the older extras or increase the amount.'
                );
            }

            // ---- Allocation ---------------------------------------------------
            // Older invoices paid in full; the newest absorbs the remainder.
            $allocCents = [];
            for ($i = 0; $i < $n - 1; $i++) {
                $allocCents[$i] = $balCents[$i];
            }
            $allocCents[$n - 1] = $amountCents - $sumAllButLastCents;

            // ---- Post each slice (mirrors recordImmediateOrderPayment 'shop') --
            $salesAccount = ConfigModel::getSalesRevenueAccount();
            if (!$salesAccount) {
                throw new \RuntimeException('Sales revenue account not configured');
            }
            $toAccount = ConfigModel::getOnlineBankAccount();
            if (!$toAccount) {
                throw new \RuntimeException('Online bank account not configured');
            }

            $paymentDate = !empty($params['payment_date'])
                ? Carbon::parse($params['payment_date'])->toDateString()
                : now()->toDateString();

            $bankShort = OnlineReceivingAccountModel::find($receivingAccountId)?->short_code;
            $bankSuffix = $bankShort ? " · via {$bankShort}" : '';

            $allocations = [];
            $appliedTotalCents = 0;

            foreach ($ordered as $idx => $order) {
                $sliceCents = $allocCents[$idx];
                if ($sliceCents <= 0) {
                    continue; // defensive — never happens given the validated range
                }
                $slice = $sliceCents / 100;
                $balanceBefore = $balCents[$idx] / 100;

                $payment = OrderPaymentModel::create([
                    'order_id' => $order->id,
                    'amount' => $slice,
                    'payment_method' => 'online',
                    'receiving_account_id' => $receivingAccountId,
                    'payment_date' => $paymentDate,
                    'reference' => $params['reference'] ?? null,
                    'notes' => $params['notes'] ?? null,
                    'status' => 'active',
                    'created_by' => $user->id,
                ]);

                // PAID-stamp metadata — identical semantics to the single add flow.
                $stampUpdates = [];
                if (array_key_exists('sending_bank', $params)) {
                    $stampUpdates['paid_stamp_sending_bank'] = $params['sending_bank'];
                }
                $stampUpdates['paid_stamp_date'] = !empty($params['stamp_date'])
                    ? $params['stamp_date']
                    : $paymentDate;
                if (array_key_exists('stamp_ref_mode', $params)) {
                    $stampUpdates['paid_stamp_ref_mode'] = $params['stamp_ref_mode'];
                }
                $order->fill($stampUpdates)->save();

                $ledger = LedgerModel::create([
                    'transaction_date' => $paymentDate,
                    'transaction_type' => LedgerModel::TYPE_ORDER_PAYMENT,
                    'description' => "Shop payment (bulk) for order #{$order->order_number} - Rs. " . number_format($slice, 2) . " (online) by {$user->fullname}{$bankSuffix}",
                    'from_account_id' => $salesAccount->id,
                    'to_account_id' => $toAccount->id,
                    'amount' => $slice,
                    'mode' => 'online',
                    // Tag which of OUR banks received this online payment so
                    // per-bank balances reconcile against the ONLINE account.
                    'receiving_account_id' => $receivingAccountId,
                    'approval_status' => LedgerModel::STATUS_APPROVED,
                    'balance_updated' => 0, // engine applies this row's slice below
                    'settlement_status' => 'settled',
                    'settled_amount' => $slice,
                    'settled_at' => now(),
                    'approval_date' => now(),
                    'approved_by' => $user->id,
                    'order_id' => $order->id,
                    'created_by' => $user->id,
                ]);

                $payment->ledger_transaction_id = $ledger->id;
                $payment->save();

                // Apply THIS slice via the canonical engine (revenue −, holder +), row-locked; sets
                // balance_updated. Replaces the old single batched apply below — the sum of the
                // per-slice moves is identical, and each row's flag is now genuinely true.
                (new \App\Services\FIN\BalancePostingService())->apply($ledger);

                $order->recalculatePaymentStatus();

                $appliedTotalCents += $sliceCents;

                $allocations[] = [
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'allocated' => $slice,
                    'balance_before' => $balanceBefore,
                    'balance_after' => max(0, round($balanceBefore - $slice, 2)),
                    'fully_paid' => $sliceCents >= $balCents[$idx],
                ];
            }

            // Balances were already applied per-slice above via the engine (sum = the whole batch).
            $applied = $appliedTotalCents / 100;

            $customerName = trim(($customer->first_name ?? '') . ' ' . ($customer->last_name ?? ''))
                ?: ($customer->company ?: 'Shop');

            return [
                'customer' => $customerName,
                'total_amount' => $applied,
                'bank' => $bankShort,
                'count' => count($allocations),
                'allocations' => $allocations,
            ];
        });
    }
}
