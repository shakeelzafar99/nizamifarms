<?php

namespace App\Services\FIN;

use App\Models\FIN\AccountModel;
use App\Models\FIN\ConfigModel;
use App\Models\FIN\LedgerModel;
use App\Models\FIN\SupplyBatchModel;
use App\Models\FIN\SupplyLogModel;
use App\Models\FIN\SupplyPacketModel;
use App\Models\FIN\SupplyProductModel;
use App\Models\FIN\SupplyTakeoutLegModel;
use App\Models\FIN\SupplyTakeoutModel;
use App\Models\Request\RequestCategoryModel;
use App\Models\Request\RequestModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * STORAGE (SUPPLIES STOCK) — the one engine.
 *
 * ⭐⭐ THE RULE THE WHOLE FEATURE RESTS ON
 * The paying account moves EXACTLY ONCE, when the stock is bought:
 *
 *      book in   paying account  ->  SUPPLIES_STOCK   type 'supply_purchase'
 *      take out  SUPPLIES_STOCK  ->  EXP_<category>   type 'expense' (via a request)
 *
 * A take-out therefore NEVER names the paying account as its payment source — doing
 * so would make BalancePostingService take the cash a second time. The paying account
 * is carried in the take-out's DESCRIPTION instead, so every screen that prints a
 * description still shows where the money originally came from, and no screen has to
 * change. The same reason `receiving_account_id` stays NULL on a take-out: the
 * per-bank outflow was already counted at purchase.
 *
 * ⭐ COST ALLOCATION — the remainder rule
 * Packets 1..N-1 are rounded to the paisa and the LAST one takes whatever is left, so
 * SUM(packet.cost) == batch.total_cost to the paisa. For pieces, the take-out that
 * empties a batch takes `cost_remaining` rather than n x unit_cost. Never n x rounded:
 * that is how a batch ends up a few rupees short of the money that actually left.
 *
 * ⭐ FIFO
 * Oldest batch first, always — so a cheaper old batch is consumed before a dearer new
 * one, and the rupee figure on a packet is the price actually paid for it.
 */
class SupplyStockService
{
    /** The asset account unused packaging sits in. Created by supplies_storage_sep2026.sql. */
    public const STOCK_ACCOUNT_CODE = 'SUPPLIES_STOCK';

    /** Deliberately NOT 'expense' — invisible to every P&L query by construction. */
    public const LEDGER_TYPE_PURCHASE = 'supply_purchase';

    /** t_fin_config switch: '1' = take-outs queue for approval, '0' = post immediately. */
    public const APPROVAL_SWITCH_KEY = 'supply_takeout_requires_approval';

    /** Take-outs are ordinary expense requests, so they use the ordinary expense category. */
    public const EXPENSE_CATEGORY_CODE = 'expense';

    /** Below this a packet is not worth billing — the request would be refused anyway. */
    private const MIN_BILLABLE = 0.01;

    // -----------------------------------------------------------------------------
    // BOOK IN
    // -----------------------------------------------------------------------------

    /**
     * Record a purchase: N packets (or a piece count) + one total price + which
     * account paid. All-or-nothing.
     *
     * @param array $in product_id, purchase_date, total_cost, stock_only,
     *                  payment_source_account_id, receiving_account_id, note,
     *                  bill_image, client_uuid, packets[] {qty, barcode, plu, source},
     *                  pieces_qty
     */
    public function bookBatch(array $in, int $userId): SupplyBatchModel
    {
        // A retried Save (phone lost the reply) must return the SAME batch, never book
        // a second one. Checked before the transaction so the common case is one read.
        if (!empty($in['client_uuid'])) {
            $existing = SupplyBatchModel::where('client_uuid', $in['client_uuid'])->first();
            if ($existing) {
                return $existing;
            }
        }

        return DB::transaction(function () use ($in, $userId) {
            /** @var SupplyProductModel $product */
            $product = SupplyProductModel::findOrFail($in['product_id']);

            $stockOnly = !empty($in['stock_only']);
            $totalCost = $stockOnly ? 0.0 : round((float) ($in['total_cost'] ?? 0), 2);

            $packets = $product->usesPackets() ? array_values($in['packets'] ?? []) : [];
            $qtyTotal = $product->usesPackets()
                ? round(array_sum(array_map(fn ($p) => (float) $p['qty'], $packets)), 3)
                : round((float) ($in['pieces_qty'] ?? 0), 3);

            if ($qtyTotal <= 0) {
                throw new \RuntimeException('Nothing to book in.');
            }

            $batch = SupplyBatchModel::create([
                'product_id' => $product->id,
                'mode' => $product->mode,
                'packet_count' => $product->usesPackets() ? count($packets) : null,
                'qty_total' => $qtyTotal,
                'qty_remaining' => $qtyTotal,
                'total_cost' => $totalCost,
                'cost_remaining' => $totalCost,
                'unit_cost' => $qtyTotal > 0 ? round($totalCost / $qtyTotal, 4) : null,
                'purchase_date' => $in['purchase_date'] ?? now()->toDateString(),
                'payment_source_account_id' => $stockOnly ? null : ($in['payment_source_account_id'] ?? null),
                'receiving_account_id' => $stockOnly ? null : ($in['receiving_account_id'] ?? null),
                'status' => $stockOnly ? SupplyBatchModel::STATUS_STOCK_ONLY : SupplyBatchModel::STATUS_CONFIRMED,
                'note' => $in['note'] ?? null,
                'bill_image' => $in['bill_image'] ?? null,
                'booked_by' => $userId,
                'client_uuid' => $in['client_uuid'] ?? null,
            ]);

            if ($product->usesPackets()) {
                $this->createPackets($batch, $product, $packets, $totalCost, $qtyTotal, $userId);
            } else {
                // Pieces have no packet rows — the batch counters ARE the stock.
                $this->log(SupplyLogModel::ACTION_IN, $product, $batch, null, null,
                    $qtyTotal, $product->takeoutUnit(), $totalCost, 'manual', $userId);
            }

            if (!$stockOnly && $totalCost > 0) {
                $this->postPurchase($batch, $product, $userId);
            }

            return $batch->fresh();
        });
    }

    /**
     * Allocate the batch price across the packets and write them.
     * The last packet takes the remainder so the packets sum to the price exactly.
     */
    private function createPackets(
        SupplyBatchModel $batch,
        SupplyProductModel $product,
        array $packets,
        float $totalCost,
        float $qtyTotal,
        int $userId
    ): void {
        $count = count($packets);
        $allocated = 0.0;

        foreach ($packets as $i => $p) {
            // A SCAN product's packets are all the same fixed code, and each is one
            // unit. Normalised here rather than only in the controller so the rule
            // holds for every caller — otherwise a packet saved with a null barcode
            // can never be found again by scanning it.
            if ($product->mode === SupplyProductModel::MODE_SCAN) {
                $p['qty'] = 1;
                $p['barcode'] = $p['barcode'] ?? $product->barcode;
            }

            $qty = round((float) $p['qty'], 3);

            if ($totalCost <= 0) {
                $cost = 0.0;
            } elseif ($i === $count - 1) {
                // ⭐ The remainder — never round the last one independently.
                $cost = round($totalCost - $allocated, 2);
            } else {
                $cost = round($totalCost * $qty / $qtyTotal, 2);
                $allocated += $cost;
            }

            $packet = SupplyPacketModel::create([
                'batch_id' => $batch->id,
                'product_id' => $product->id,
                'seq' => $i + 1,
                'barcode' => $p['barcode'] ?? null,
                'plu' => $p['plu'] ?? null,
                'qty' => $qty,
                'cost' => $cost,
                'status' => SupplyPacketModel::STATUS_IN_STOCK,
            ]);

            $this->log(SupplyLogModel::ACTION_IN, $product, $batch, $packet, null,
                $qty, $product->takeoutUnit(), $cost, $p['source'] ?? 'scan', $userId);
        }
    }

    /**
     * The money leg of a purchase: paying account -> SUPPLIES_STOCK.
     *
     * Booked approved outright, like an asset purchase — only `manage_supplies_storage`
     * holders can reach this, and both of them hold approval level 1 anyway.
     */
    private function postPurchase(SupplyBatchModel $batch, SupplyProductModel $product, int $userId): void
    {
        $source = AccountModel::find($batch->payment_source_account_id);
        if (!$source) {
            throw new \RuntimeException('Choose which account paid for this stock.');
        }

        $stock = $this->stockAccount();
        if (!$stock) {
            throw new \RuntimeException('The Storage stock account is missing — run supplies_storage_sep2026.sql.');
        }

        $isBank = $source->account_category === AccountModel::CATEGORY_BANK;
        $bankId = $isBank ? $batch->receiving_account_id : null;

        $description = 'Storage stock: ' . $product->name
            . ' — ' . $this->quantityPhrase($batch->qty_total, $product)
            . ' · batch #' . $batch->id;

        if ($bankId) {
            $short = \App\Models\FIN\OnlineReceivingAccountModel::find($bankId)?->short_code;
            if ($short) {
                $description .= ' · via ' . $short;
            }
        }

        $ledger = LedgerModel::create([
            'transaction_date' => $batch->purchase_date,
            'transaction_type' => self::LEDGER_TYPE_PURCHASE,
            'description' => $description,
            'from_account_id' => $source->id,
            'to_account_id' => $stock->id,
            'amount' => $batch->total_cost,
            'mode' => $isBank ? LedgerModel::MODE_ONLINE : null,
            'receiving_account_id' => $bankId,
            'approval_status' => LedgerModel::STATUS_APPROVED,
            'approved_by' => $userId,
            'approval_date' => now(),
            'business_unit_id' => $product->business_unit_id ?? 1,
            'created_by' => $userId,
            'comments' => 'Paid from: ' . $source->account_name
                . '. Not an expense — it becomes one packet at a time, as the stock is used.',
        ]);

        (new BalancePostingService())->apply($ledger);

        $batch->ledger_id = $ledger->id;
        $batch->save();
    }

    // -----------------------------------------------------------------------------
    // TAKE OUT
    // -----------------------------------------------------------------------------

    /**
     * Take stock out for use and raise the expense request for it.
     *
     * @param array $in product_id, packet_id (weight/scan) | qty (pieces), source, note
     */
    public function takeOut(array $in, int $userId): SupplyTakeoutModel
    {
        return DB::transaction(function () use ($in, $userId) {
            /** @var SupplyProductModel $product */
            $product = SupplyProductModel::findOrFail($in['product_id']);

            if (!$product->is_active) {
                throw new \RuntimeException($product->name . ' is switched off — ask Taimur or Shabib.');
            }

            $legs = $product->usesPackets()
                ? $this->consumePacket($product, (int) ($in['packet_id'] ?? 0), $userId)
                : $this->consumePieces($product, round((float) ($in['qty'] ?? 0), 3));

            $qty  = round(array_sum(array_column($legs, 'qty')), 3);
            $cost = round(array_sum(array_column($legs, 'cost')), 2);

            // A stock_only batch (opening stock, already expensed) bills nothing, and
            // neither does a packet whose share rounds below a paisa.
            $billable = $cost >= self::MIN_BILLABLE;

            $takeout = SupplyTakeoutModel::create([
                'product_id' => $product->id,
                'qty' => $qty,
                'unit' => $product->takeoutUnit(),
                'cost' => $cost,
                'source' => $in['source'] ?? 'scan',
                'status' => $billable ? SupplyTakeoutModel::STATUS_PENDING : SupplyTakeoutModel::STATUS_NO_CHARGE,
                'note' => $in['note'] ?? null,
                'taken_by' => $userId,
                'taken_at' => now(),
                'settled_at' => $billable ? null : now(),
            ]);

            foreach ($legs as $leg) {
                SupplyTakeoutLegModel::create([
                    'takeout_id' => $takeout->id,
                    'batch_id' => $leg['batch_id'],
                    'packet_id' => $leg['packet_id'] ?? null,
                    'qty' => $leg['qty'],
                    'cost' => $leg['cost'],
                    'created_at' => now(),
                ]);

                if (!empty($leg['packet_id'])) {
                    SupplyPacketModel::where('id', $leg['packet_id'])->update([
                        'takeout_id' => $takeout->id,
                        'consumed_by' => $userId,
                    ]);
                }

                $this->log(SupplyLogModel::ACTION_OUT, $product,
                    SupplyBatchModel::find($leg['batch_id']),
                    !empty($leg['packet_id']) ? SupplyPacketModel::find($leg['packet_id']) : null,
                    $takeout, $leg['qty'], $product->takeoutUnit(), $leg['cost'],
                    $in['source'] ?? 'scan', $userId);
            }

            if ($billable) {
                $this->raiseExpenseRequest($takeout, $product, $legs, $userId);
            }

            return $takeout->fresh();
        });
    }

    /**
     * Consume one identified packet. Row-locked: two phones scanning the same label at
     * the same moment get two different packets, or the second is told there are none
     * left — never the same packet twice.
     */
    private function consumePacket(SupplyProductModel $product, int $packetId, int $userId): array
    {
        /** @var SupplyPacketModel|null $packet */
        $packet = SupplyPacketModel::where('id', $packetId)
            ->where('product_id', $product->id)
            ->lockForUpdate()
            ->first();

        if (!$packet) {
            throw new \RuntimeException('That packet is not in Storage.');
        }
        if ($packet->status !== SupplyPacketModel::STATUS_IN_STOCK) {
            throw new \RuntimeException('That packet has already been taken out.');
        }

        $batch = SupplyBatchModel::where('id', $packet->batch_id)->lockForUpdate()->first();

        $packet->status = SupplyPacketModel::STATUS_CONSUMED;
        $packet->consumed_at = now();
        $packet->consumed_by = $userId;
        $packet->save();

        $batch->qty_remaining = round(max(0, (float) $batch->qty_remaining - (float) $packet->qty), 3);
        $batch->cost_remaining = round(max(0, (float) $batch->cost_remaining - (float) $packet->cost), 2);
        $batch->save();

        return [[
            'batch_id' => $batch->id,
            'packet_id' => $packet->id,
            'qty' => (float) $packet->qty,
            'cost' => (float) $packet->cost,
        ]];
    }

    /**
     * Consume a piece count, oldest batch first. May cross a batch boundary (owner
     * ruling: allowed) — 30 cups when the oldest batch has 20 left produces two legs
     * at two different unit costs, still one request.
     */
    private function consumePieces(SupplyProductModel $product, float $want): array
    {
        if ($want <= 0) {
            throw new \RuntimeException('How many are you taking out?');
        }

        $batches = SupplyBatchModel::where('product_id', $product->id)
            ->whereIn('status', [SupplyBatchModel::STATUS_CONFIRMED, SupplyBatchModel::STATUS_STOCK_ONLY])
            ->where('qty_remaining', '>', 0)
            ->orderBy('purchase_date')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $available = round($batches->sum(fn ($b) => (float) $b->qty_remaining), 3);
        if ($available < $want) {
            throw new \RuntimeException($available > 0
                ? 'Only ' . rtrim(rtrim(number_format($available, 3, '.', ''), '0'), '.') . ' left in Storage.'
                : 'No ' . $product->name . ' left in Storage.');
        }

        $legs = [];
        $left = $want;

        foreach ($batches as $batch) {
            if ($left <= 0) {
                break;
            }

            $take = min($left, (float) $batch->qty_remaining);
            $emptiesBatch = abs($take - (float) $batch->qty_remaining) < 0.0005;

            // ⭐ The take-out that empties a batch takes whatever cost is LEFT, so the
            //    batch lands on exactly zero instead of a few paisa short or over.
            $cost = $emptiesBatch
                ? round((float) $batch->cost_remaining, 2)
                : round(((float) $batch->total_cost) * $take / ((float) $batch->qty_total), 2);

            $batch->qty_remaining = round((float) $batch->qty_remaining - $take, 3);
            $batch->cost_remaining = round(max(0, (float) $batch->cost_remaining - $cost), 2);
            $batch->save();

            $legs[] = [
                'batch_id' => $batch->id,
                'packet_id' => null,
                'qty' => $take,
                'cost' => $cost,
            ];

            $left = round($left - $take, 3);
        }

        return $legs;
    }

    /**
     * The expense request a take-out raises.
     *
     * Built here rather than posted through RequestController::store on purpose: that
     * endpoint runs the payment-source allow-list, which would refuse a store user
     * paying from the stock account. The rules that matter are applied explicitly
     * below instead.
     */
    private function raiseExpenseRequest(
        SupplyTakeoutModel $takeout,
        SupplyProductModel $product,
        array $legs,
        int $userId
    ): void {
        $category = RequestCategoryModel::where('category_code', self::EXPENSE_CATEGORY_CODE)->first();
        if (!$category) {
            throw new \RuntimeException('The Expense request category is missing.');
        }

        $stock = $this->stockAccount();
        if (!$stock) {
            throw new \RuntimeException('The Storage stock account is missing — run supplies_storage_sep2026.sql.');
        }

        $requiresL1 = (bool) $category->requiresLevel1();
        $requiresL2 = (bool) $category->requiresLevel2();

        // Approval, in this order:
        //   1. the owner's switch is off  -> post immediately, for everyone
        //   2. the scanner holds every level this category needs (Taimur, Shabib)
        //      -> post immediately, exactly as their own expenses behave today
        //   3. otherwise queue it
        $switchOff = !$this->takeoutsNeedApproval();
        $selfApproves = !$switchOff
            && app(SelfApprovalPolicy::class)->canSelfApprove(LedgerModel::TYPE_EXPENSE, $userId);
        $autoApprove = $switchOff || $selfApproves;

        $qtyPhrase = $this->quantityPhrase($takeout->qty, $product);
        $batch = SupplyBatchModel::find($legs[0]['batch_id']);

        $request = RequestModel::create([
            'request_number' => RequestModel::generateRequestNumber(),
            'category_id' => $category->id,
            'requester_user_id' => $userId,          // the SCANNER is the spender (owner ruling)
            'title' => 'Storage: ' . $product->name . ' ' . $qtyPhrase,
            'description' => $this->takeoutDescription($product, $qtyPhrase, $legs),
            'amount' => $takeout->cost,
            'expense_category' => $product->expense_category_name,
            'expense_date' => now()->toDateString(),  // the day it was USED — the whole point
            // ⭐⭐ The money leg. NEVER the batch's paying account: that cash already
            //    left when the stock was bought, and naming it here would take it twice.
            'payment_source_account_id' => $stock->id,
            'receiving_account_id' => null,           // the bank was tagged at purchase
            'business_unit_id' => $product->business_unit_id ?? 1,
            'status' => $autoApprove ? RequestModel::STATUS_APPROVED : RequestModel::STATUS_PENDING,
            'priority' => 'normal',
            'requires_level_1' => $requiresL1,
            'requires_level_2' => $requiresL2,
            'level_1_status' => $requiresL1
                ? ($autoApprove ? RequestModel::APPROVAL_STATUS_APPROVED : RequestModel::APPROVAL_STATUS_PENDING)
                : null,
            'level_2_status' => $requiresL2
                ? ($autoApprove ? RequestModel::APPROVAL_STATUS_APPROVED : RequestModel::APPROVAL_STATUS_PENDING)
                : null,
            // Nothing to settle: no one is out of pocket, the money left at purchase.
            'settlement_status' => 'not_required',
            'submitted_at' => now(),
            'created_by' => $userId,
            'supply_takeout_id' => $takeout->id,
        ]);

        if ($autoApprove) {
            // ⚠ There are NO level_1_approved_by / level_1_approved_at columns on
            // t_req_master — who approved lives in t_req_approval, which is also where
            // every screen reads it from. (RequestController::store passes those four
            // keys to create() and Eloquent silently drops them, so nothing has ever
            // been written by that path either.) Writing the real audit row instead
            // means an auto-approved take-out reads exactly like any other approval.
            $note = $switchOff
                ? 'Auto-approved — Storage take-out approval is switched off.'
                : app(SelfApprovalPolicy::class)->auditNote(LedgerModel::TYPE_EXPENSE, $userId);

            foreach (array_filter([$requiresL1 ? 1 : null, $requiresL2 ? 2 : null]) as $level) {
                \App\Models\Request\RequestApprovalModel::create([
                    'request_id' => $request->id,
                    'approval_level' => $level,
                    'approver_user_id' => $userId,
                    'status' => RequestModel::APPROVAL_STATUS_APPROVED,
                    'comments' => $note,
                    'action_date' => now(),
                    'created_by' => $userId,
                ]);
            }

            $request->completed_at = now();
            $request->remarks = $note;
            $request->save();
        }

        $takeout->request_id = $request->id;
        if ($autoApprove) {
            $takeout->status = SupplyTakeoutModel::STATUS_APPROVED;
            $takeout->settled_at = now();
        }
        $takeout->save();

        if ($autoApprove) {
            try {
                app(LedgerPostingService::class)->postExpenseFromRequest($request);
            } catch (\Throwable $e) {
                // Same posture as RequestController::store — a ledger failure is logged,
                // never allowed to lose the take-out itself.
                Log::error('Storage take-out: expense post failed', [
                    'request_id' => $request->id,
                    'takeout_id' => $takeout->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    // -----------------------------------------------------------------------------
    // RESTORE / VOID
    // -----------------------------------------------------------------------------

    /**
     * Reconcile a take-out with its request's CURRENT status. Idempotent, and safe to
     * call from every door that can decide a request — approve, reject, cancel, and
     * the two delete paths — so no door can be forgotten and none can double-restore.
     */
    public function syncWithRequest(?RequestModel $request): void
    {
        if (!$request || !$request->supply_takeout_id) {
            return;
        }

        $takeout = SupplyTakeoutModel::find($request->supply_takeout_id);
        if (!$takeout || !$takeout->isPending()) {
            return; // already settled — nothing to do, and nothing to double-restore
        }

        if ($request->status === RequestModel::STATUS_APPROVED) {
            $takeout->status = SupplyTakeoutModel::STATUS_APPROVED;
            $takeout->settled_at = now();
            $takeout->save();
            return;
        }

        if (in_array($request->status, [RequestModel::STATUS_REJECTED, RequestModel::STATUS_CANCELLED], true)) {
            $this->restore(
                $takeout,
                $request->status === RequestModel::STATUS_REJECTED
                    ? SupplyTakeoutModel::STATUS_REJECTED
                    : SupplyTakeoutModel::STATUS_UNDONE,
                $request->rejection_reason
            );
        }
    }

    /** Put a take-out's stock back on the shelf. */
    public function restore(SupplyTakeoutModel $takeout, string $status, ?string $reason = null): void
    {
        DB::transaction(function () use ($takeout, $status, $reason) {
            $fresh = SupplyTakeoutModel::where('id', $takeout->id)->lockForUpdate()->first();
            if (!$fresh || !$fresh->isPending()) {
                return;
            }

            $product = SupplyProductModel::find($fresh->product_id);

            foreach ($fresh->legs()->get() as $leg) {
                $batch = SupplyBatchModel::where('id', $leg->batch_id)->lockForUpdate()->first();
                if ($batch) {
                    $batch->qty_remaining = round((float) $batch->qty_remaining + (float) $leg->qty, 3);
                    $batch->cost_remaining = round((float) $batch->cost_remaining + (float) $leg->cost, 2);
                    $batch->save();
                }

                if ($leg->packet_id) {
                    SupplyPacketModel::where('id', $leg->packet_id)->update([
                        'status' => SupplyPacketModel::STATUS_IN_STOCK,
                        'consumed_at' => null,
                        'consumed_by' => null,
                        'takeout_id' => null,
                    ]);
                }

                $this->log(SupplyLogModel::ACTION_UNDO, $product, $batch,
                    $leg->packet_id ? SupplyPacketModel::find($leg->packet_id) : null,
                    $fresh, (float) $leg->qty, $fresh->unit, (float) $leg->cost, null,
                    auth()->id(), $reason);
            }

            $fresh->status = $status;
            $fresh->settled_at = now();
            $fresh->save();
        });
    }

    /**
     * ⭐ Correct the price of a batch that was entered wrong — even after some of it has
     * been used. (Voiding only works while a batch is untouched, and a typo is usually
     * spotted after a packet or two has gone out.)
     *
     * WHAT MOVES, and why:
     *  1. The PURCHASE row is amended in place at its ORIGINAL date. The cash really did
     *     leave that day — only the amount was wrong — so the bank/cash account is
     *     corrected by the difference on the day it actually happened.
     *  2. Every packet is RE-COSTED at the new total, by weight, last-takes-remainder.
     *  3. A packet already taken out and APPROVED had an expense posted at the old share.
     *     That row is reversed, amended and re-applied on its ORIGINAL expense date — the
     *     packet was genuinely used that day, only the rupee figure was wrong. Correcting
     *     it in place is what keeps each month's packaging cost true; posting the whole
     *     difference into today's month instead would misstate BOTH months.
     *  4. A take-out still PENDING has no ledger yet — only its request amount changes.
     *
     * ⚠ This DOES move a figure in a month that may already have been read. It is the
     *   honest answer (the month's real packaging cost was always the corrected one), but
     *   the caller must tell the user which months are affected before they confirm —
     *   see previewPriceCorrection().
     */
    public function correctBatchPrice(SupplyBatchModel $batch, float $newTotal, int $userId, ?string $reason = null): array
    {
        return DB::transaction(function () use ($batch, $newTotal, $userId, $reason) {
            /** @var SupplyBatchModel $b */
            $b = SupplyBatchModel::where('id', $batch->id)->lockForUpdate()->first();
            if (!$b) {
                throw new \RuntimeException('That purchase no longer exists.');
            }
            if ($b->status !== SupplyBatchModel::STATUS_CONFIRMED) {
                throw new \RuntimeException($b->status === SupplyBatchModel::STATUS_STOCK_ONLY
                    ? 'This was booked as opening stock, so it has no price to correct.'
                    : 'This purchase was voided — nothing to correct.');
            }
            $newTotal = round($newTotal, 2);
            if ($newTotal <= 0) {
                throw new \RuntimeException('The corrected amount must be more than zero.');
            }

            $product = SupplyProductModel::find($b->product_id);
            $oldTotal = (float) $b->total_cost;
            $changed = [];

            // ── 1. the purchase row, amended in place at its original date ──────────
            if ($b->ledger_id) {
                $ledger = LedgerModel::find($b->ledger_id);
                if ($ledger && $ledger->approval_status === LedgerModel::STATUS_APPROVED) {
                    // The documented caller pattern for editing an applied row.
                    $poster = new BalancePostingService();
                    $poster->reverse($ledger);
                    $ledger->amount = $newTotal;
                    $ledger->comments = trim(($ledger->comments ? $ledger->comments . "\n" : '')
                        . 'Price corrected ' . number_format($oldTotal, 2) . ' → ' . number_format($newTotal, 2)
                        . ' by user ' . $userId . ' on ' . now()->toDateTimeString()
                        . ($reason ? ' — ' . $reason : ''));
                    $ledger->save();
                    $poster->apply($ledger);
                }
            }

            // ── 2. re-cost every packet / leg at the new total ──────────────────────
            if ($product && $product->usesPackets()) {
                $packets = SupplyPacketModel::where('batch_id', $b->id)
                    ->where('status', '!=', SupplyPacketModel::STATUS_VOIDED)
                    ->orderBy('seq')->lockForUpdate()->get();

                // ⭐ The SAME allocation the preview showed — so the figure the manager
                //    confirmed is the figure that lands, to the paisa.
                $newCosts = $this->allocateByWeight($packets, $newTotal);

                foreach ($packets as $pk) {
                    $cost = $newCosts[$pk->id];
                    $wasCost = (float) $pk->cost;
                    $pk->cost = $cost;
                    $pk->save();

                    SupplyTakeoutLegModel::where('packet_id', $pk->id)->update(['cost' => $cost]);

                    if ($pk->status === SupplyPacketModel::STATUS_CONSUMED && abs($wasCost - $cost) >= 0.005) {
                        $moved = $this->recostTakeout($pk->takeout_id, $cost, $userId, $reason);
                        if ($moved) { $changed[] = $moved; }
                    }
                }
                $b->cost_remaining = round($packets->where('status', SupplyPacketModel::STATUS_IN_STOCK)
                    ->sum(fn ($p) => (float) $p->cost), 2);
            } else {
                // Pieces: re-cost each leg at the new rate; the shelf absorbs the rounding.
                $unit = $newTotal / max((float) $b->qty_total, 0.0001);
                $legTotal = 0.0;
                $legs = SupplyTakeoutLegModel::where('batch_id', $b->id)->orderBy('id')->lockForUpdate()->get();

                foreach ($legs as $leg) {
                    $takeout = SupplyTakeoutModel::find($leg->takeout_id);
                    // A rejected / undone take-out gave its quantity back, so it costs nothing.
                    if ($takeout && in_array($takeout->status, SupplyTakeoutModel::RESTORED_STATUSES, true)) {
                        continue;
                    }
                    $wasCost = (float) $leg->cost;
                    $cost = round($unit * (float) $leg->qty, 2);
                    $leg->cost = $cost;
                    $leg->save();
                    $legTotal += $cost;

                    if (abs($wasCost - $cost) >= 0.005) {
                        $sum = round(SupplyTakeoutLegModel::where('takeout_id', $leg->takeout_id)->sum('cost'), 2);
                        $moved = $this->recostTakeout($leg->takeout_id, $sum, $userId, $reason);
                        if ($moved) { $changed[] = $moved; }
                    }
                }
                $b->cost_remaining = round($newTotal - $legTotal, 2);
            }

            // ── 3. the batch itself ─────────────────────────────────────────────────
            $b->total_cost = $newTotal;
            $b->unit_cost = (float) $b->qty_total > 0 ? round($newTotal / (float) $b->qty_total, 4) : null;
            $b->save();

            $this->log(SupplyLogModel::ACTION_CORRECT, $product, $b, null, null,
                (float) $b->qty_total, $product ? $product->takeoutUnit() : 'kg', $newTotal, null, $userId,
                'Price corrected ' . number_format($oldTotal, 2) . ' → ' . number_format($newTotal, 2)
                . ($reason ? ' — ' . $reason : ''));

            return ['old_total' => $oldTotal, 'new_total' => $newTotal, 'expenses_changed' => $changed];
        });
    }

    /**
     * Split a total across packets by weight, LAST packet takes the remainder so the
     * shares sum to the total exactly. The ONE allocation rule — used by the intake, the
     * correction and the correction PREVIEW, so a previewed figure is always the one
     * that lands. Returns [packet_id => cost] in seq order.
     */
    private function allocateByWeight($packets, float $total): array
    {
        $qtyTotal = round($packets->sum(fn ($p) => (float) $p->qty), 3);
        $out = [];
        $allocated = 0.0;
        $last = $packets->count() - 1;

        foreach ($packets->values() as $i => $pk) {
            if ($i === $last) {
                $cost = round($total - $allocated, 2);
            } else {
                $cost = $qtyTotal > 0 ? round($total * (float) $pk->qty / $qtyTotal, 2) : 0.0;
                $allocated += $cost;
            }
            $out[$pk->id] = $cost;
        }

        return $out;
    }

    /**
     * Re-cost ONE take-out after its packet's share changed. A pending one has no ledger
     * yet, so only the request amount moves; an approved one has its expense reversed,
     * amended and re-applied on its ORIGINAL date.
     *
     * @return array|null what moved, for the confirmation message
     */
    private function recostTakeout(?int $takeoutId, float $newCost, int $userId, ?string $reason): ?array
    {
        if (!$takeoutId) {
            return null;
        }
        $takeout = SupplyTakeoutModel::find($takeoutId);
        if (!$takeout || in_array($takeout->status, SupplyTakeoutModel::RESTORED_STATUSES, true)) {
            return null;
        }

        $was = (float) $takeout->cost;
        $takeout->cost = $newCost;
        $takeout->save();

        $request = $takeout->request_id ? RequestModel::find($takeout->request_id) : null;
        if (!$request) {
            return null;
        }
        $request->amount = $newCost;
        $request->save();

        $month = null;
        if ($request->ledger_transaction_id) {
            $ledger = LedgerModel::find($request->ledger_transaction_id);
            if ($ledger && $ledger->approval_status === LedgerModel::STATUS_APPROVED) {
                $poster = new BalancePostingService();
                $poster->reverse($ledger);
                $ledger->amount = $newCost;
                $ledger->comments = trim(($ledger->comments ? $ledger->comments . "\n" : '')
                    . 'Amount corrected ' . number_format($was, 2) . ' → ' . number_format($newCost, 2)
                    . ' (Storage purchase price corrected)' . ($reason ? ' — ' . $reason : ''));
                $ledger->save();
                $poster->apply($ledger);
                $month = substr((string) $ledger->transaction_date, 0, 7);
            }
        }

        return [
            'request_number' => $request->request_number,
            'was' => $was,
            'now' => $newCost,
            'month' => $month,
        ];
    }

    /**
     * What WOULD change if the price were corrected — shown before anything moves, so a
     * month that has already been read is never quietly restated.
     */
    public function previewPriceCorrection(SupplyBatchModel $batch, float $newTotal): array
    {
        $newTotal = round($newTotal, 2);
        $product = SupplyProductModel::find($batch->product_id);
        $old = (float) $batch->total_cost;
        $ratio = $old > 0 ? $newTotal / $old : 0;

        $rows = [];
        $months = [];
        $takeouts = SupplyTakeoutModel::whereIn('id',
            SupplyTakeoutLegModel::where('batch_id', $batch->id)->pluck('takeout_id'))
            ->whereNotIn('status', SupplyTakeoutModel::RESTORED_STATUSES)
            ->with('request')->get();

        // Packet products: use the EXACT allocation the correction will apply (last takes
        // the remainder), not a ratio — a ratio was one paisa off on the remainder packet,
        // so the figure previewed was not the figure that landed.
        $newByPacket = null;
        if ($product && $product->usesPackets()) {
            $packets = SupplyPacketModel::where('batch_id', $batch->id)
                ->where('status', '!=', SupplyPacketModel::STATUS_VOIDED)->orderBy('seq')->get();
            $newByPacket = $this->allocateByWeight($packets, $newTotal);
        }

        foreach ($takeouts as $t) {
            $legs = SupplyTakeoutLegModel::where('takeout_id', $t->id)->where('batch_id', $batch->id)->get();
            $legCost = (float) $legs->sum('cost');
            $newLegCost = $newByPacket !== null
                ? (float) $legs->sum(fn ($l) => $newByPacket[$l->packet_id] ?? (float) $l->cost)
                : round($legCost * $ratio, 2);
            $now = round((float) $t->cost - $legCost + $newLegCost, 2);
            if (abs($now - (float) $t->cost) < 0.005) {
                continue;
            }
            $m = $t->request && $t->request->expense_date
                ? substr((string) $t->request->expense_date, 0, 7) : null;
            if ($m) { $months[$m] = true; }
            $rows[] = [
                'request_number' => $t->request?->request_number,
                'status' => $t->status,
                'was' => (float) $t->cost,
                'now' => $now,
                'month' => $m,
            ];
        }

        return [
            'old_total' => $old,
            'new_total' => $newTotal,
            'difference' => round($newTotal - $old, 2),
            'paid_from' => AccountModel::find($batch->payment_source_account_id)?->account_name,
            'purchase_month' => substr((string) $batch->purchase_date, 0, 7),
            'expenses' => $rows,
            'months_affected' => array_keys($months),
            'unit_label' => $product ? $product->takeoutUnit() : '',
        ];
    }

    /**
     * Reverse a whole batch booked in error. Only while NOTHING has been consumed —
     * once a packet is out, the money is partly in an approved expense and unwinding
     * it silently would leave the two disagreeing.
     */
    public function voidBatch(SupplyBatchModel $batch, int $userId): void
    {
        DB::transaction(function () use ($batch, $userId) {
            $fresh = SupplyBatchModel::where('id', $batch->id)->lockForUpdate()->first();
            if (!$fresh || $fresh->status === SupplyBatchModel::STATUS_VOIDED) {
                return;
            }

            $consumed = SupplyPacketModel::where('batch_id', $fresh->id)
                ->where('status', SupplyPacketModel::STATUS_CONSUMED)->count();
            $piecesUsed = $fresh->mode === SupplyProductModel::MODE_PIECES
                && (float) $fresh->qty_remaining < (float) $fresh->qty_total;

            if ($consumed > 0 || $piecesUsed) {
                throw new \RuntimeException('Some of this batch has already been used — it can no longer be voided.');
            }

            if ($fresh->ledger_id) {
                $ledger = LedgerModel::find($fresh->ledger_id);
                if ($ledger && $ledger->approval_status === LedgerModel::STATUS_APPROVED) {
                    (new BalancePostingService())->reverse($ledger);
                    $ledger->approval_status = LedgerModel::STATUS_REVERSED;
                    $ledger->comments = trim(($ledger->comments ? $ledger->comments . "\n" : '')
                        . 'Batch voided by user ' . $userId . ' on ' . now()->toDateTimeString());
                    $ledger->save();
                }
            }

            SupplyPacketModel::where('batch_id', $fresh->id)
                ->update(['status' => SupplyPacketModel::STATUS_VOIDED]);

            $fresh->status = SupplyBatchModel::STATUS_VOIDED;
            $fresh->qty_remaining = 0;
            $fresh->cost_remaining = 0;
            $fresh->voided_by = $userId;
            $fresh->voided_at = now();
            $fresh->save();

            $this->log(SupplyLogModel::ACTION_VOID, SupplyProductModel::find($fresh->product_id),
                $fresh, null, null, (float) $fresh->qty_total, 'kg', (float) $fresh->total_cost,
                null, $userId, 'Batch voided');
        });
    }

    // -----------------------------------------------------------------------------
    // STOCK COUNT
    // -----------------------------------------------------------------------------

    /**
     * ⭐ A physical shelf count — the only thing that can catch a packet taken WITHOUT
     * scanning it out.
     *
     * Nothing else detects that: the stock figure stays high and the expense never lands,
     * so the shelf and the books drift apart quietly and permanently. Counting compares
     * what is physically there against what the system believes.
     *
     * A SHORTFALL means those packets were used — someone just didn't scan them — so
     * `write_off` expenses them exactly like a normal take-out, attributed to whoever
     * counted, with the reason on the record. Refusing to write off would leave stock
     * permanently overstated, which is the worse of the two errors.
     *
     * A SURPLUS is never written up: stock cannot appear without a price, so the count is
     * recorded and the manager is told to book the purchase that was missed.
     */
    public function recordCount(SupplyProductModel $product, float $counted, bool $writeOff, int $userId, ?string $note = null): array
    {
        return DB::transaction(function () use ($product, $counted, $writeOff, $userId, $note) {
            $expected = $this->onHand($product);
            $counted = $product->usesPackets() ? round($counted) : round($counted, 3);
            $gap = round($counted - $expected, 3);

            $writtenOff = [];
            $cost = 0.0;

            if ($gap < 0 && $writeOff) {
                $short = abs($gap);
                $reason = 'Missing at stock count' . ($note ? ' — ' . $note : '');

                if ($product->usesPackets()) {
                    // Oldest first, exactly like a scan, so the cost is what was paid.
                    $packets = $this->availablePackets($product)->take((int) $short);
                    foreach ($packets as $pk) {
                        $t = $this->takeOut([
                            'product_id' => $product->id, 'packet_id' => $pk->id,
                            'source' => 'manual', 'note' => $reason,
                        ], $userId);
                        $writtenOff[] = ['takeout_id' => $t->id, 'qty' => (float) $t->qty, 'cost' => (float) $t->cost];
                        $cost += (float) $t->cost;
                    }
                } else {
                    $t = $this->takeOut([
                        'product_id' => $product->id, 'qty' => $short,
                        'source' => 'manual', 'note' => $reason,
                    ], $userId);
                    $writtenOff[] = ['takeout_id' => $t->id, 'qty' => (float) $t->qty, 'cost' => (float) $t->cost];
                    $cost += (float) $t->cost;
                }
            }

            $this->log(SupplyLogModel::ACTION_COUNT, $product, null, null, null,
                $counted, $product->takeoutUnit(), $cost, 'manual', $userId,
                trim('Counted ' . $this->quantityPhrase($counted, $product)
                    . ', system had ' . $this->quantityPhrase($expected, $product)
                    . ($gap == 0 ? ' — matched' : ($gap < 0 ? ' — SHORT by ' . abs($gap) : ' — OVER by ' . $gap))
                    . ($gap < 0 && $writeOff ? ' — written off' : '')
                    . ($note ? '. ' . $note : '')));

            return [
                'expected' => $expected,
                'counted' => $counted,
                'gap' => $gap,
                'written_off' => $writtenOff,
                'written_off_cost' => round($cost, 2),
            ];
        });
    }

    /** What the system believes is on the shelf right now. */
    public function onHand(SupplyProductModel $product): float
    {
        if ($product->usesPackets()) {
            return (float) SupplyPacketModel::where('product_id', $product->id)
                ->where('status', SupplyPacketModel::STATUS_IN_STOCK)->count();
        }

        return round((float) SupplyBatchModel::where('product_id', $product->id)
            ->where('status', '!=', SupplyBatchModel::STATUS_VOIDED)
            ->sum('qty_remaining'), 3);
    }

    /** The most recent count per product, for the "last counted" line. */
    public function lastCount(int $productId): ?SupplyLogModel
    {
        return SupplyLogModel::where('product_id', $productId)
            ->where('action', SupplyLogModel::ACTION_COUNT)
            ->orderByDesc('id')->first();
    }

    // -----------------------------------------------------------------------------
    // READS
    // -----------------------------------------------------------------------------

    /** In-stock packets of a product, oldest batch first — the take-out picker list. */
    public function availablePackets(SupplyProductModel $product)
    {
        return SupplyPacketModel::query()
            ->select('t_fin_supply_packet.*')
            ->join('t_fin_supply_batch as b', 'b.id', '=', 't_fin_supply_packet.batch_id')
            ->where('t_fin_supply_packet.product_id', $product->id)
            ->where('t_fin_supply_packet.status', SupplyPacketModel::STATUS_IN_STOCK)
            ->where('b.status', '!=', SupplyBatchModel::STATUS_VOIDED)
            ->orderBy('b.purchase_date')
            ->orderBy('b.id')
            ->orderBy('t_fin_supply_packet.seq')
            ->get();
    }

    /**
     * The packet a scanned label refers to: exact barcode, oldest batch first.
     * Two identical bags print the same label, so this is a FIFO pick, not a lookup —
     * and because their costs come from the same batch order, the choice is also the
     * financially correct one.
     */
    public function findPacketForBarcode(SupplyProductModel $product, string $raw): ?SupplyPacketModel
    {
        return SupplyPacketModel::query()
            ->select('t_fin_supply_packet.*')
            ->join('t_fin_supply_batch as b', 'b.id', '=', 't_fin_supply_packet.batch_id')
            ->where('t_fin_supply_packet.product_id', $product->id)
            ->where('t_fin_supply_packet.status', SupplyPacketModel::STATUS_IN_STOCK)
            ->where('t_fin_supply_packet.barcode', $raw)
            ->where('b.status', '!=', SupplyBatchModel::STATUS_VOIDED)
            ->orderBy('b.purchase_date')
            ->orderBy('b.id')
            ->orderBy('t_fin_supply_packet.seq')
            ->first();
    }

    /** Per-product stock: packets, quantity and the rupees still sitting on the shelf. */
    public function stockSummary(?int $businessUnitId = null): array
    {
        $products = SupplyProductModel::where('is_active', 1)
            ->when($businessUnitId, fn ($q) => $q->where('business_unit_id', $businessUnitId))
            ->orderBy('name')
            ->get();

        $rows = [];
        foreach ($products as $product) {
            $batches = SupplyBatchModel::where('product_id', $product->id)
                ->where('status', '!=', SupplyBatchModel::STATUS_VOIDED)
                ->get();

            $qty = round($batches->sum(fn ($b) => (float) $b->qty_remaining), 3);
            $value = round($batches->sum(fn ($b) => (float) $b->cost_remaining), 2);
            $packets = $product->usesPackets()
                ? SupplyPacketModel::where('product_id', $product->id)
                    ->where('status', SupplyPacketModel::STATUS_IN_STOCK)->count()
                : null;

            $rows[] = [
                'product' => $product,
                'qty_remaining' => $qty,
                'value_remaining' => $value,
                'packets_in_stock' => $packets,
                'low_stock' => $product->low_stock_qty !== null
                    && $qty <= (float) $product->low_stock_qty,
            ];
        }

        return $rows;
    }

    /** Does a take-out queue for approval? Owner-facing switch, default ON. */
    public function takeoutsNeedApproval(): bool
    {
        return (string) ConfigModel::get(self::APPROVAL_SWITCH_KEY, '1') !== '0';
    }

    public function stockAccount(): ?AccountModel
    {
        return AccountModel::where('account_code', self::STOCK_ACCOUNT_CODE)->first();
    }

    // -----------------------------------------------------------------------------
    // HELPERS
    // -----------------------------------------------------------------------------

    /** "1.25 kg" / "4 packets" / "30 pcs" — the phrase a store user recognises. */
    public function quantityPhrase(float $qty, SupplyProductModel $product): string
    {
        if ($product->mode === SupplyProductModel::MODE_WEIGHT) {
            return rtrim(rtrim(number_format($qty, 3, '.', ''), '0'), '.') . ' kg';
        }

        $n = (int) round($qty);
        if ($product->mode === SupplyProductModel::MODE_SCAN) {
            return $n . ' packet' . ($n === 1 ? '' : 's');
        }

        return $n . ' pcs';
    }

    /**
     * ⭐ The description is where the ORIGINAL paying account lives.
     *
     * Every screen that shows an expense prints a description — Expenses, the Ledger,
     * the Reports drill, HQ, the approval card — so putting the parent account here
     * makes it readable everywhere without changing a single one of those screens.
     * Their "Paid from" column keeps saying Storage stock, which is the truth of THIS
     * leg: no cash moves when a packet is used.
     */
    private function takeoutDescription(SupplyProductModel $product, string $qtyPhrase, array $legs): string
    {
        $parts = [];
        foreach ($legs as $leg) {
            $batch = SupplyBatchModel::find($leg['batch_id']);
            if (!$batch) {
                continue;
            }
            $parts[] = 'batch #' . $batch->id
                . ' (' . optional($batch->purchase_date)->format('d-M') . ') · '
                . $batch->parentAccountPhrase();
        }

        return 'Storage take-out: ' . $product->name . ' ' . $qtyPhrase
            . ' — charged to ' . $product->expense_category_name
            . '. ' . implode(' + ', $parts)
            . '. The money left that account when the stock was bought; this only books the cost to the month it was used.';
    }

    private function log(
        string $action,
        ?SupplyProductModel $product,
        ?SupplyBatchModel $batch,
        ?SupplyPacketModel $packet,
        ?SupplyTakeoutModel $takeout,
        float $qty,
        string $unit,
        float $cost,
        ?string $source,
        ?int $userId,
        ?string $note = null
    ): void {
        SupplyLogModel::create([
            'action' => $action,
            'product_id' => $product?->id,
            'product_name' => $product?->name,
            'batch_id' => $batch?->id,
            'packet_id' => $packet?->id,
            'takeout_id' => $takeout?->id,
            'qty' => $qty,
            'unit' => $unit,
            'cost' => $cost,
            'source' => $source,
            'note' => $note,
            'created_by' => $userId,
            'created_at' => now(),
        ]);
    }
}
