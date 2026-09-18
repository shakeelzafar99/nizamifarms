<?php

namespace App\Http\Controllers\FIN;

use App\Http\Controllers\Controller;
use App\Models\FIN\AccountModel;
use App\Models\FIN\ConfigModel;
use App\Models\FIN\SupplyBatchModel;
use App\Models\FIN\SupplyLogModel;
use App\Models\FIN\SupplyPacketModel;
use App\Models\FIN\SupplyProductModel;
use App\Models\FIN\SupplyTakeoutLegModel;
use App\Models\FIN\SupplyTakeoutModel;
use App\Services\CRM\WeightBarcodeDecoder;
use App\Services\FIN\PaymentSourceService;
use App\Services\FIN\SupplyStockService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * STORAGE (SUPPLIES) — one controller, mounted on BOTH /supplies/* (web) and
 * /api/supplies/* (mobile), exactly like OvernightStorageController. The two surfaces
 * therefore can never drift apart in what they allow or what they show.
 *
 * Access is gated by MOBILE permissions even on the web page (the Overnight pattern):
 *   access_supplies_storage — open Storage, take packets out
 *   manage_supplies_storage — book stock in, define products, void a batch
 * Using the mobile permission system for both avoids the web-permission registry trap
 * (a key missing from RolePermissionController::getAvailablePermissions() can never be
 * granted or revoked by anyone).
 */
class SupplyStorageController extends Controller
{
    public function __construct(private SupplyStockService $stock, private WeightBarcodeDecoder $decoder)
    {
    }

    // -----------------------------------------------------------------------------
    // ACCESS
    // -----------------------------------------------------------------------------

    private function hasAccess(): bool
    {
        return $this->holds('access_supplies_storage');
    }

    private function canManage(): bool
    {
        return $this->holds('manage_supplies_storage');
    }

    /** Eager-loads the roles once — without it every check is an N+1. */
    private function holds(string $code): bool
    {
        $user = auth()->user();
        if (!$user) {
            return false;
        }
        if (!$user->relationLoaded('roles')) {
            $user->load(['roles.mobilePermissions']);
        }

        return $user->hasMobilePermission($code);
    }

    /**
     * A refusal a person can act on. These are defence in depth — the UI already hides
     * every control the caller cannot use — but "You do not have access" tells someone
     * who somehow reaches one nothing about what to do next, so each names the way out.
     */
    private function deny(string $what = 'Storage')
    {
        return response()->json([
            'success' => false,
            'message' => $what === 'Storage'
                ? 'Storage is not switched on for you. Ask Taimur or Shabib if you need it.'
                : 'Only Taimur or Shabib can do that. You can still take packets out.',
        ], 403);
    }

    // -----------------------------------------------------------------------------
    // READS
    // -----------------------------------------------------------------------------

    /** The web page. */
    public function index()
    {
        if (!$this->hasAccess()) {
            abort(403, 'You do not have access to Storage.');
        }

        return view('pages.supplies.index', [
            'stock' => $this->stock->stockSummary(),
            'canManage' => $this->canManage(),
            'needsApproval' => $this->stock->takeoutsNeedApproval(),
            'stockAccount' => $this->stock->stockAccount(),
            'pendingCount' => SupplyTakeoutModel::where('status', SupplyTakeoutModel::STATUS_PENDING)->count(),
        ]);
    }

    /** Everything the mobile Storage screen needs in one call. */
    public function getStock(Request $request)
    {
        if (!$this->hasAccess()) {
            return $this->deny();
        }

        $rows = [];
        foreach ($this->stock->stockSummary($request->input('business_unit_id')) as $row) {
            /** @var SupplyProductModel $p */
            $p = $row['product'];
            $rows[] = [
                'id' => $p->id,
                'name' => $p->name,
                'mode' => $p->mode,
                'plu' => $p->plu,
                'barcode' => $p->barcode,
                'unit' => $p->takeoutUnit(),
                'expense_category' => $p->expense_category_name,
                'qty_remaining' => (float) $row['qty_remaining'],
                'value_remaining' => (float) $row['value_remaining'],
                // ⭐ Null for a weighed product from round 3 on: its stock is kilograms in a
                // pool, not a number of bags, so a packet count would be a lie.
                'packets_in_stock' => $row['packets_in_stock'],
                'low_stock' => $row['low_stock'],
                'qty_label' => $this->stock->quantityPhrase((float) $row['qty_remaining'], $p),
                // Drives the take-out UI: pooled products ask for a QUANTITY (scanned or
                // typed); a scan product still picks an identified packet.
                'pooled' => $p->isPooled(),
                'packet_barcode' => $p->packet_barcode,
                'packet_kg' => $p->packet_kg !== null ? (float) $p->packet_kg : null,
                'open_purchases' => $row['open_purchases'] ?? null,
            ];
        }

        return response()->json([
            'success' => true,
            'products' => $rows,
            'can_manage' => $this->canManage(),
            'needs_approval' => $this->stock->takeoutsNeedApproval(),
            'my_pending' => SupplyTakeoutModel::where('taken_by', auth()->id())
                ->where('status', SupplyTakeoutModel::STATUS_PENDING)->count(),
        ]);
    }

    /** Batches of one product — where the money came from, and what is left. */
    public function getBatches(Request $request, $productId)
    {
        if (!$this->hasAccess()) {
            return $this->deny();
        }

        $product = SupplyProductModel::findOrFail($productId);
        $batches = SupplyBatchModel::where('product_id', $product->id)
            ->with(['paymentSourceAccount'])
            ->orderByDesc('purchase_date')->orderByDesc('id')
            ->limit(100)->get();

        return response()->json([
            'success' => true,
            'product' => ['id' => $product->id, 'name' => $product->name, 'mode' => $product->mode],
            'batches' => $batches->map(function (SupplyBatchModel $b) use ($product) {
                $consumed = SupplyPacketModel::where('batch_id', $b->id)
                    ->where('status', SupplyPacketModel::STATUS_CONSUMED)->count();

                // ⭐ Round 3: a POOLED purchase is drawn down in kilograms, so its packet
                // rows stay `in_stock` for ever. Whether anything has come out of it can
                // only be answered by the legs — and the void guard depends on this.
                $liveLegs = SupplyTakeoutLegModel::where('batch_id', $b->id)
                    ->whereHas('takeout', fn ($q) => $q->whereNotIn('status', SupplyTakeoutModel::RESTORED_STATUSES))
                    ->count();

                return [
                    'id' => $b->id,
                    'purchase_date' => optional($b->purchase_date)->toDateString(),
                    'status' => $b->status,
                    'packet_count' => $b->packet_count,
                    'qty_total' => (float) $b->qty_total,
                    'qty_remaining' => (float) $b->qty_remaining,
                    'qty_label' => $this->stock->quantityPhrase((float) $b->qty_remaining, $product),
                    'total_cost' => (float) $b->total_cost,
                    'cost_remaining' => (float) $b->cost_remaining,
                    // "How much of this purchase has been used so far" — asked for directly by
                    // the owner. Derived, never stored, so it can never disagree with the two above.
                    'cost_used' => round((float) $b->total_cost - (float) $b->cost_remaining, 2),
                    'qty_used' => round((float) $b->qty_total - (float) $b->qty_remaining, 3),
                    'unit_cost' => $b->unit_cost !== null ? (float) $b->unit_cost : null,
                    // ⭐ What the owner asked to see: which account paid for this stock.
                    'paid_from' => $b->paymentSourceAccount?->account_name,
                    'paid_from_phrase' => $b->parentAccountPhrase(),
                    'ledger_id' => $b->ledger_id,
                    'note' => $b->note,
                    'consumed_packets' => $consumed,
                    'takeouts_from_it' => $liveLegs,
                    // ⭐ The owner keeps buying before the pool runs out, so purchases pile
                    // up. The sheet leads with the ones that still hold stock and hides the
                    // rest behind "show earlier purchases".
                    'is_active' => $b->status !== SupplyBatchModel::STATUS_VOIDED
                        && (float) $b->qty_remaining > 0.0005,
                    // A price can be corrected even after some of it has been used — that
                    // is the whole point, since a typo is usually spotted after a packet
                    // or two has gone out. Voiding stays restricted to untouched purchases.
                    'can_correct' => $this->canManage() && $b->status === SupplyBatchModel::STATUS_CONFIRMED,
                    'can_void' => $this->canManage()
                        && $b->status !== SupplyBatchModel::STATUS_VOIDED
                        && $consumed === 0
                        && $liveLegs === 0
                        && abs((float) $b->qty_remaining - (float) $b->qty_total) < 0.0005,
                ];
            }),
        ]);
    }

    /** In-stock packets — the picker when a scan does not resolve to exactly one. */
    public function getPackets(Request $request, $productId)
    {
        if (!$this->hasAccess()) {
            return $this->deny();
        }

        $product = SupplyProductModel::findOrFail($productId);

        return response()->json([
            'success' => true,
            'packets' => $this->stock->availablePackets($product)->map(fn ($p) => [
                'id' => $p->id,
                'qty' => (float) $p->qty,
                'qty_label' => $this->stock->quantityPhrase((float) $p->qty, $product),
                'cost' => (float) $p->cost,
                'barcode' => $p->barcode,
                'batch_id' => $p->batch_id,
                'batch_date' => optional(SupplyBatchModel::find($p->batch_id))->purchase_date?->format('d-M'),
            ]),
        ]);
    }

    /** Movement history. */
    public function getHistory(Request $request)
    {
        if (!$this->hasAccess()) {
            return $this->deny();
        }

        $rows = SupplyLogModel::query()
            ->when($request->input('product_id'), fn ($q, $id) => $q->where('product_id', $id))
            ->orderByDesc('id')->limit((int) $request->input('limit', 100))->get();

        $names = DB::table('t_sys_user')->whereIn('id', $rows->pluck('created_by')->filter()->unique())
            ->pluck('fullname', 'id');

        return response()->json([
            'success' => true,
            'history' => $rows->map(fn ($r) => [
                'id' => $r->id,
                'action' => $r->action,
                'product_name' => $r->product_name,
                'qty' => (float) $r->qty,
                'unit' => $r->unit,
                'cost' => (float) $r->cost,
                'batch_id' => $r->batch_id,
                'takeout_id' => $r->takeout_id,
                // ⭐ The code actually read, in or out. Recorded from round 3 onward: on
                // Sep-17 nobody could tell whether a bale's own label or a small packet's
                // had been scanned, because it was stored nowhere.
                'barcode' => $r->barcode,
                'source' => $r->source,
                'typed' => $r->source === 'manual',
                'by' => $names[$r->created_by] ?? null,
                'at' => optional($r->created_at)->format('d-M h:i A'),
                'note' => $r->note,
            ]),
        ]);
    }

    /** My own take-outs, so a scanner can see what is still waiting and why. */
    public function getMyTakeouts(Request $request)
    {
        if (!$this->hasAccess()) {
            return $this->deny();
        }

        // ⭐ A manager sees EVERYONE's take-outs, because a manager is the one who fixes a
        // mistake and cannot fix what he cannot see. A store user still sees only his own.
        $canManage = $this->canManage();
        $rows = SupplyTakeoutModel::with(['product', 'request'])
            ->when(!$canManage, fn ($q) => $q->where('taken_by', auth()->id()))
            ->orderByDesc('id')->limit(50)->get();

        $userNames = $canManage
            ? DB::table('t_sys_user')->whereIn('id', $rows->pluck('taken_by')->filter()->unique())
                ->pluck('fullname', 'id')
            : collect();

        return response()->json([
            'success' => true,
            'shows_everyone' => $canManage,
            'takeouts' => $rows->map(fn (SupplyTakeoutModel $t) => [
                'id' => $t->id,
                'product_name' => $t->product?->name,
                'qty' => (float) $t->qty,
                'unit' => $t->unit,
                'qty_label' => $t->product ? $this->stock->quantityPhrase((float) $t->qty, $t->product) : null,
                'cost' => (float) $t->cost,
                'status' => $t->status,
                'request_number' => $t->request?->request_number,
                'rejection_reason' => $t->request?->rejection_reason,
                'source' => $t->source,
                // Typed, not scanned — visible on every row, because with the approval
                // switch off this is the only thing that marks a hand-entered quantity.
                'typed' => $t->source === 'manual',
                'scanned_barcode' => $t->scanned_barcode,
                'taken_by_name' => $userNames[$t->taken_by] ?? null,
                'mine' => (int) $t->taken_by === (int) auth()->id(),
                // no_charge is undoable too — see SupplyTakeoutModel::UNDOABLE_STATUSES.
                'can_undo' => $t->isUndoable() && (int) $t->taken_by === (int) auth()->id(),
                // Managers can delete any take-out that has not already been reversed, and
                // edit the weight of a pooled one.
                'can_delete' => $canManage
                    && !in_array($t->status, SupplyTakeoutModel::RESTORED_STATUSES, true),
                'can_edit' => $canManage
                    && !in_array($t->status, SupplyTakeoutModel::RESTORED_STATUSES, true)
                    && (bool) $t->product?->isPooled(),
                'at' => optional($t->taken_at)->format('d-M h:i A'),
            ]),
        ]);
    }

    /** The banner: how many take-outs are waiting for an approver. */
    public function alerts()
    {
        if (!auth()->check()) {
            return response()->json(['success' => false], 401);
        }

        $pending = SupplyTakeoutModel::where('status', SupplyTakeoutModel::STATUS_PENDING)->count();
        $latest = SupplyTakeoutModel::where('status', SupplyTakeoutModel::STATUS_PENDING)->max('id');

        return response()->json([
            'success' => true,
            'takeouts_pending' => $pending,
            'latest_id' => $latest,
        ]);
    }

    /** Products list for the manager's form + the expense categories they can link to. */
    public function getProducts(Request $request)
    {
        if (!$this->hasAccess()) {
            return $this->deny();
        }

        $categories = ConfigModel::where('config_key', 'LIKE', 'EXPENSE_CATEGORY_%')
            ->where(function ($q) {
                $q->whereNull('request_category_code')->orWhere('request_category_code', 'expense');
            })
            ->orderBy('config_value')
            ->get(['id', 'config_value', 'business_unit_id']);

        // ⭐ has_stock / stock_value ride along so the product form can FREEZE the fields
        // the server refuses to change (mode, PLU, barcode) and explain why, instead of
        // letting a manager fill the form and then bounce off a 422. One grouped query,
        // not one per product.
        $stockByProduct = SupplyBatchModel::where('status', '!=', SupplyBatchModel::STATUS_VOIDED)
            ->groupBy('product_id')
            ->selectRaw('product_id, COUNT(*) AS batches, COALESCE(SUM(cost_remaining), 0) AS value_left')
            ->get()->keyBy('product_id');

        $products = SupplyProductModel::orderBy('name')->get()->map(function (SupplyProductModel $p) use ($stockByProduct) {
            $row = $stockByProduct->get($p->id);

            return array_merge($p->toArray(), [
                'has_stock' => (bool) $row,
                'stock_value' => round((float) ($row->value_left ?? 0), 2),
            ]);
        });

        return response()->json([
            'success' => true,
            'can_manage' => $this->canManage(),
            'products' => $products,
            'expense_categories' => $categories->map(fn ($c) => [
                'id' => $c->id, 'name' => $c->config_value, 'business_unit_id' => $c->business_unit_id,
            ]),
            'payment_sources' => $this->canManage()
                ? app(PaymentSourceService::class)->sourcesFor(auth()->user(), $request->input('business_unit_id'))
                : [],
            'banks' => $this->canManage() ? app(PaymentSourceService::class)->banks() : [],
        ]);
    }

    // -----------------------------------------------------------------------------
    // WRITES — products
    // -----------------------------------------------------------------------------

    public function saveProduct(Request $request, $id = null)
    {
        if (!$this->canManage()) {
            return $this->deny('Storage stock');
        }

        $validated = $request->validate([
            'name' => 'required|string|max:150',
            'mode' => 'required|in:weight,scan,pieces',
            'plu' => 'nullable|integer|min:1|max:999999',
            'barcode' => 'nullable|string|max:40',
            // ⭐ Round 3 — the INNER packet, when it carries a vendor code with no weight.
            'packet_barcode' => 'nullable|string|max:40',
            'packet_kg' => 'nullable|numeric|min:0.001|max:9999',
            'pieces_per_packet' => 'nullable|integer|min:1',
            'expense_config_id' => 'required|integer|exists:t_fin_config,id',
            'business_unit_id' => 'nullable|integer',
            'low_stock_qty' => 'nullable|numeric|min:0',
            'is_active' => 'nullable|boolean',
        ]);

        $mode = $validated['mode'];
        $plu = $mode === 'weight' ? ($validated['plu'] ?? null) : null;
        $barcode = $mode === 'scan' ? trim((string) ($validated['barcode'] ?? '')) : null;

        // The inner packet's own code only means anything on a weighed product, and only
        // together with what one of them weighs — a code with no weight could not be
        // turned into a quantity, and a weight with no code could never be triggered.
        $packetBarcode = $mode === 'weight' ? (trim((string) ($validated['packet_barcode'] ?? '')) ?: null) : null;
        $packetKg = $mode === 'weight' ? ($validated['packet_kg'] ?? null) : null;

        if ($mode === 'weight' && !$plu) {
            return response()->json(['success' => false, 'message' => 'A weighed product needs its scale PLU.'], 422);
        }
        if ($mode === 'scan' && $barcode === '') {
            return response()->json(['success' => false, 'message' => 'Scan the product barcode, or type it in.'], 422);
        }
        if (($packetBarcode === null) !== ($packetKg === null)) {
            return response()->json([
                'success' => false,
                'message' => 'An inner-packet barcode needs the weight of one packet, and the weight needs the barcode. '
                    . 'Leave both blank if the packets carry their own scale labels.',
            ], 422);
        }

        // One code = one product, so a scan can never be ambiguous.
        $clash = SupplyProductModel::when($id, fn ($q) => $q->where('id', '!=', $id))
            ->where(function ($q) use ($plu, $barcode, $packetBarcode) {
                if ($plu) { $q->orWhere('plu', $plu); }
                if ($barcode) { $q->orWhere('barcode', $barcode); }
                // The inner-packet code has to be unique too, and against BOTH columns:
                // a scan that matched two products could take stock out of the wrong one.
                if ($packetBarcode) {
                    $q->orWhere('packet_barcode', $packetBarcode)->orWhere('barcode', $packetBarcode);
                }
            })->first();
        if (($plu || $barcode || $packetBarcode) && $clash) {
            return response()->json([
                'success' => false,
                'message' => 'That code is already used by "' . $clash->name . '".',
            ], 422);
        }

        // ─────────────────────────────────────────────────────────────────────────
        // ⭐⭐ ONCE A PRODUCT HAS STOCK, HOW IT IS COUNTED IS FROZEN.
        //
        // Changing `mode` re-reads every existing row in a different unit: a weight
        // batch's qty_remaining is KILOGRAMS, and after a flip to `pieces` the take-out
        // path (consumePieces) walks those same numbers as a PIECE COUNT while the
        // packet rows are orphaned — the shelf figure and the money silently diverge.
        // Changing `plu` or `barcode` is just as bad and quieter: resolveScan() finds
        // the product by its code, so every packet already on the shelf becomes
        // unscannable and only the "pick the packet you have" fallback still works.
        //
        // Neither client had a guard (the Blade even carried the comment and then wrote
        // `disabled = false`), so this is enforced HERE — the one place both surfaces
        // and any future caller must pass through. Everything else about the product
        // stays editable: name, expense category, low-stock level, active.
        // ─────────────────────────────────────────────────────────────────────────
        if ($id) {
            $existing = SupplyProductModel::findOrFail($id);

            if ($this->productHasStock($existing)) {
                $frozen = [];
                if ($existing->mode !== $mode) {
                    $frozen[] = 'how it is counted';
                }
                if ((int) $existing->plu !== (int) $plu) {
                    $frozen[] = 'the scale PLU';
                }
                if ((string) $existing->barcode !== (string) $barcode) {
                    $frozen[] = 'the barcode';
                }

                if ($frozen) {
                    return response()->json([
                        'success' => false,
                        'message' => $existing->name . ' already has stock booked in, so ' . implode(' and ', $frozen)
                            . ' cannot change — the packets on the shelf were recorded that way. '
                            . 'Use the new packaging as a NEW product, or void/use up this one first.',
                    ], 422);
                }
            }

            // Switching a product off hides it from every Storage screen (stockSummary
            // filters is_active) AND blocks take-out — while its rupees stay sitting in
            // the Storage stock account. That is stranded money nobody can see.
            if (!$request->boolean('is_active', true) && $existing->is_active) {
                $value = $this->stockValueOf($existing);
                if ($value > 0.009) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Rs ' . number_format($value, 0) . ' of ' . $existing->name
                            . ' is still on the shelf. Take it out, or void the purchase, before switching it off — '
                            . 'otherwise the stock disappears from Storage but the money stays in the books.',
                    ], 422);
                }
            }
        }

        $config = ConfigModel::find($validated['expense_config_id']);

        $payload = [
            'name' => $validated['name'],
            'mode' => $mode,
            'plu' => $plu,
            'barcode' => $barcode ?: null,
            'packet_barcode' => $packetBarcode,
            'packet_kg' => $packetKg,
            'pieces_per_packet' => $validated['pieces_per_packet'] ?? null,
            'expense_config_id' => $config->id,
            // Snapshot, so a renamed or deleted config row can never break a take-out.
            'expense_category_name' => $config->config_value,
            'business_unit_id' => $validated['business_unit_id'] ?? 1,
            'low_stock_qty' => $validated['low_stock_qty'] ?? null,
            'is_active' => $request->boolean('is_active', true),
        ];

        if ($id) {
            $product = $existing;          // already loaded and checked above
            $product->update($payload);
        } else {
            $payload['created_by'] = auth()->id();
            $product = SupplyProductModel::create($payload);
        }

        // A warning, not a block: the two scanners look at different tables, so a shared
        // PLU cannot mis-resolve — but it will confuse whoever programmed the scale.
        $warning = null;
        if ($plu && DB::table('t_crm_prod_product')->where('czerlop_product_id', $plu)->exists()) {
            $warning = 'Note: PLU ' . $plu . ' is also used by a shop product on the scale. '
                . 'Storage still reads it correctly, but pick a free PLU if you can.';
        }

        return response()->json(['success' => true, 'product' => $product, 'warning' => $warning]);
    }

    /**
     * Has anything ever been booked in against this product? Any non-voided batch counts,
     * including one already used up: its packets and log rows still carry the old mode and
     * code, and re-reading those in a different unit is exactly what must not happen.
     */
    private function productHasStock(SupplyProductModel $product): bool
    {
        return SupplyBatchModel::where('product_id', $product->id)
            ->where('status', '!=', SupplyBatchModel::STATUS_VOIDED)
            ->exists();
    }

    /** Rupees of this product still sitting on the shelf. */
    private function stockValueOf(SupplyProductModel $product): float
    {
        return (float) SupplyBatchModel::where('product_id', $product->id)
            ->where('status', '!=', SupplyBatchModel::STATUS_VOIDED)
            ->sum('cost_remaining');
    }

    // -----------------------------------------------------------------------------
    // WRITES — stock in
    // -----------------------------------------------------------------------------

    public function bookBatch(Request $request)
    {
        if (!$this->canManage()) {
            return $this->deny('Storage stock');
        }

        $validated = $request->validate([
            'product_id' => 'required|integer|exists:t_fin_supply_product,id',
            'purchase_date' => 'nullable|date',
            'total_cost' => 'nullable|numeric|min:0',
            'stock_only' => 'nullable|boolean',
            'payment_source_account_id' => 'nullable|integer|exists:t_fin_accounts,id',
            'receiving_account_id' => 'nullable|integer|exists:t_fin_online_receiving_accounts,id',
            'note' => 'nullable|string|max:255',
            'client_uuid' => 'nullable|string|max:36',
            'packets' => 'nullable|array|max:200',
            'packets.*.qty' => 'required_with:packets|numeric|min:0.001|max:9999',
            'packets.*.barcode' => 'nullable|string|max:20',
            'packets.*.source' => 'nullable|in:scan,manual',
            'pieces_qty' => 'nullable|numeric|min:1|max:999999',
            'attachment_image' => 'nullable|image|max:5120',
        ]);

        $product = SupplyProductModel::findOrFail($validated['product_id']);
        $stockOnly = $request->boolean('stock_only');

        // ── Everything that must be true BEFORE anything is written ────────────
        if (!$stockOnly) {
            if (!isset($validated['total_cost']) || (float) $validated['total_cost'] <= 0) {
                return response()->json(['success' => false, 'message' => 'Enter what was paid for this stock.'], 422);
            }
            if (empty($validated['payment_source_account_id'])) {
                return response()->json(['success' => false, 'message' => 'Choose which account paid.'], 422);
            }

            $bu = $product->business_unit_id ?: 1;
            if (!app(PaymentSourceService::class)->allows(
                auth()->user(), $validated['payment_source_account_id'], $bu, PaymentSourceService::PURPOSE_EXPENSE
            )) {
                return response()->json([
                    'success' => false,
                    'message' => 'You are not set up to pay from that account. Ask Taimur or Shabib to add you to it '
                        . '(Ledger Hub → the account → "Who uses this account").',
                ], 403);
            }

            $account = AccountModel::find($validated['payment_source_account_id']);
            if ($account && $account->account_category === AccountModel::CATEGORY_BANK
                && empty($validated['receiving_account_id'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Select which bank this payment came from.',
                ], 422);
            }
        }

        // Backdating follows exactly the same rule as an expense: the role's window.
        $error = $this->checkBackdate($validated['purchase_date'] ?? null);
        if ($error) {
            return response()->json(['success' => false, 'message' => $error], 422);
        }

        // ── Barcodes: decode and match BEFORE the transaction, so a bad scan fails
        //    the whole batch fast instead of half-writing it. ────────────────────
        $packets = [];
        // ⚠ booksPackets(), not usesPackets(). Round 3 changed what a weighed product's
        // packet rows MEAN (intake audit, not stock) but not that intake records them.
        if ($product->booksPackets()) {
            $incoming = $validated['packets'] ?? [];
            if (empty($incoming)) {
                return response()->json(['success' => false, 'message' => 'Scan at least one packet.'], 422);
            }

            foreach ($incoming as $i => $p) {
                $row = [
                    'qty' => (float) $p['qty'],
                    'barcode' => $p['barcode'] ?? null,
                    'plu' => null,
                    'source' => $p['source'] ?? 'scan',
                ];

                if (!empty($p['barcode']) && $product->mode === SupplyProductModel::MODE_WEIGHT) {
                    $decoded = $this->decoder->decode($p['barcode']);
                    if (!$decoded) {
                        return response()->json([
                            'success' => false,
                            'message' => 'Packet ' . ($i + 1) . ': that is not a valid scale label.',
                        ], 422);
                    }
                    if ((int) $product->plu !== (int) $decoded['plu']) {
                        return response()->json([
                            'success' => false,
                            'message' => 'Packet ' . ($i + 1) . ': that label is PLU ' . $decoded['plu']
                                . ', not ' . $product->name . '.',
                        ], 422);
                    }
                    // ⭐ The server re-decode wins over whatever the client computed, so a
                    //    client bug can never store a weight the label does not carry.
                    $row['qty'] = $decoded['weight_kg'];
                    $row['plu'] = $decoded['plu'];
                    $row['barcode'] = $decoded['raw'];
                } elseif ($product->mode === SupplyProductModel::MODE_SCAN) {
                    $row['qty'] = 1;
                    $row['barcode'] = $product->barcode;
                }

                $packets[] = $row;
            }
        } elseif (empty($validated['pieces_qty'])) {
            return response()->json(['success' => false, 'message' => 'How many pieces are you adding?'], 422);
        }

        // Bill photo, same storage shape as a request attachment.
        $billImage = null;
        if ($request->hasFile('attachment_image')) {
            $date = now();
            $path = "supplies/bills/{$date->format('Y')}/{$date->format('m')}/"
                . 'supply_' . auth()->id() . '_' . $date->format('Ymd_His') . '.jpg';
            \Storage::disk('public')->put($path, file_get_contents($request->file('attachment_image')));
            $billImage = $path;
        }

        try {
            $batch = $this->stock->bookBatch([
                'product_id' => $product->id,
                'purchase_date' => $validated['purchase_date'] ?? now()->toDateString(),
                'total_cost' => $validated['total_cost'] ?? 0,
                'stock_only' => $stockOnly,
                'payment_source_account_id' => $validated['payment_source_account_id'] ?? null,
                'receiving_account_id' => $validated['receiving_account_id'] ?? null,
                'note' => $validated['note'] ?? null,
                'bill_image' => $billImage,
                'client_uuid' => $validated['client_uuid'] ?? null,
                'packets' => $packets,
                'pieces_qty' => $validated['pieces_qty'] ?? null,
            ], auth()->id());
        } catch (\Throwable $e) {
            \Log::error('Storage book-in failed', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => $e->getMessage() ?: 'Could not save. Nothing was recorded.',
            ], 422);
        }

        $account = $batch->payment_source_account_id ? AccountModel::find($batch->payment_source_account_id) : null;

        return response()->json([
            'success' => true,
            'batch_id' => $batch->id,
            'message' => $stockOnly
                ? 'Added to Storage — nothing charged (already expensed).'
                : 'Booked — Rs ' . number_format((float) $batch->total_cost, 0)
                  . ($account ? ' from ' . $account->account_name : ''),
        ]);
    }

    /**
     * What a price correction WOULD change — always shown before anything moves, so a
     * month that has already been read is never quietly restated behind the owner's back.
     */
    public function previewCorrection(Request $request, $batchId)
    {
        if (!$this->canManage()) {
            return $this->deny('Storage stock');
        }

        $validated = $request->validate(['total_cost' => 'required|numeric|min:0.01|max:99999999']);
        $batch = SupplyBatchModel::findOrFail($batchId);

        if ($batch->status !== SupplyBatchModel::STATUS_CONFIRMED) {
            return response()->json([
                'success' => false,
                'message' => $batch->status === SupplyBatchModel::STATUS_STOCK_ONLY
                    ? 'This was booked as opening stock, so it has no price to correct.'
                    : 'This purchase was voided — nothing to correct.',
            ], 422);
        }

        return response()->json([
            'success' => true,
            'preview' => $this->stock->previewPriceCorrection($batch, (float) $validated['total_cost']),
        ]);
    }

    /** Apply the correction the preview above described. */
    public function correctPrice(Request $request, $batchId)
    {
        if (!$this->canManage()) {
            return $this->deny('Storage stock');
        }

        $validated = $request->validate([
            'total_cost' => 'required|numeric|min:0.01|max:99999999',
            'reason' => 'nullable|string|max:255',
        ]);

        $batch = SupplyBatchModel::findOrFail($batchId);

        try {
            $result = $this->stock->correctBatchPrice(
                $batch, (float) $validated['total_cost'], auth()->id(), $validated['reason'] ?? null
            );
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        $n = count($result['expenses_changed']);

        return response()->json([
            'success' => true,
            'result' => $result,
            'message' => 'Corrected to Rs ' . number_format($result['new_total'], 0)
                . ($n ? ' — ' . $n . ' expense entr' . ($n === 1 ? 'y' : 'ies') . ' updated to match.' : '.'),
        ]);
    }

    /**
     * A physical shelf count. Managers only — a shortfall books real money.
     */
    public function recordCount(Request $request, $productId)
    {
        if (!$this->canManage()) {
            return $this->deny('Storage stock');
        }

        $validated = $request->validate([
            'counted' => 'required|numeric|min:0|max:999999',
            'write_off' => 'nullable|boolean',
            'note' => 'nullable|string|max:255',
        ]);

        $product = SupplyProductModel::findOrFail($productId);

        try {
            $result = $this->stock->recordCount(
                $product, (float) $validated['counted'], $request->boolean('write_off'),
                auth()->id(), $validated['note'] ?? null
            );
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        $gap = (float) $result['gap'];
        if (abs($gap) < 0.0005) {
            $msg = 'Counted ' . $this->stock->quantityPhrase($result['counted'], $product) . ' — matches the system.';
        } elseif ($gap < 0) {
            $msg = 'Short by ' . $this->stock->quantityPhrase(abs($gap), $product) . '. '
                . (count($result['written_off'])
                    ? 'Booked as used — Rs ' . number_format($result['written_off_cost'], 0) . ' charged to expenses.'
                    : 'Nothing was charged; the shelf figure is unchanged.');
        } else {
            $msg = 'There is MORE on the shelf than the system knows ('
                . $this->stock->quantityPhrase($gap, $product) . ' extra). '
                . 'Stock cannot be added without a price — book the purchase that was missed.';
        }

        return response()->json(['success' => true, 'result' => $result, 'message' => $msg]);
    }

    public function voidBatch(Request $request, $batchId)
    {
        if (!$this->canManage()) {
            return $this->deny('Storage stock');
        }

        $batch = SupplyBatchModel::findOrFail($batchId);

        try {
            $this->stock->voidBatch($batch, auth()->id());
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true, 'message' => 'Batch voided and the payment reversed.']);
    }

    // -----------------------------------------------------------------------------
    // WRITES — take out
    // -----------------------------------------------------------------------------

    /**
     * Resolve a scanned code to the ONE packet it refers to.
     * 0 matches -> a message that says what to do; 2+ identical labels -> FIFO picks
     * the oldest, which is also the right cost, so the user is never asked.
     */
    public function resolveScan(Request $request)
    {
        if (!$this->hasAccess()) {
            return $this->deny();
        }

        $raw = trim((string) $request->input('barcode'));
        if ($raw === '') {
            return response()->json(['success' => false, 'message' => 'Nothing scanned.'], 422);
        }

        $decoded = $this->decoder->decode($raw);

        // ⚠ FIXED CODE FIRST, then the PLU. A scan-mode product's barcode is a printed
        // manufacturer EAN, and one that happens to start with the in-store flag '2' and
        // carry a valid check digit DECODES as a scale label — sending the lookup down the
        // PLU path, where it finds nothing, and telling the user their own product is "not
        // a Storage item". An exact barcode match is unambiguous by construction (the
        // column is unique), so it can safely be tried first for every read.
        $product = SupplyProductModel::where('barcode', $raw)->where('is_active', 1)->first();

        // ⭐ Round 3: the INNER packet of a weighed product may carry a vendor barcode that
        //    holds no weight at all (unlike a Czerlop scale label). The product then names
        //    that code and what one packet of it weighs.
        $nominal = false;
        if (!$product) {
            $byPacketCode = SupplyProductModel::where('packet_barcode', $raw)->where('is_active', 1)->first();
            if ($byPacketCode && $byPacketCode->hasNominalPacket()) {
                $product = $byPacketCode;
                $nominal = true;
            }
        }

        if (!$product && $decoded) {
            $product = SupplyProductModel::where('plu', $decoded['plu'])->where('is_active', 1)->first();
        }

        if (!$product) {
            return response()->json([
                'success' => false,
                'code' => 'unknown_product',
                'message' => $decoded
                    ? 'PLU ' . $decoded['plu'] . ' is not a Storage item.'
                    : 'That barcode is not a Storage item.',
            ], 404);
        }

        // ─────────────────────────────────────────────────────────────────────────────
        // ⭐⭐ POOLED PRODUCTS (weight): the scan says HOW MUCH, not WHICH PACKET.
        //
        // This is the whole of round 3. A bale is weighed once on a tray and booked under
        // one label; the small packets inside are then scanned out one at a time. Looking
        // for "the packet with this barcode" was what turned a 1.5 kg scan into a 26.97 kg
        // take-out on Sep-17 — the only row that matched was the bale itself.
        // ─────────────────────────────────────────────────────────────────────────────
        if ($product->isPooled()) {
            return $this->resolvePooledScan($product, $raw, $decoded, $nominal);
        }

        // Look the packet up by the code its rows actually carry: a scan product's packets
        // store the fixed barcode exactly as it was typed or scanned into the product form.
        $lookup = $raw;

        $packet = $product->usesPackets() ? $this->stock->findPacketForBarcode($product, $lookup) : null;

        // No exact label match — offer what IS on the shelf (covers typed-in packets
        // and re-printed labels) instead of a dead end.
        if ($product->usesPackets() && !$packet) {
            $available = $this->stock->availablePackets($product);

            return response()->json([
                'success' => false,
                'code' => $available->isEmpty() ? 'empty' : 'pick_one',
                'message' => $available->isEmpty()
                    ? 'No ' . $product->name . ' left in Storage — ask Taimur or Shabib to book the new stock.'
                    : 'That exact label is not in Storage. Pick the packet you have.',
                'product' => ['id' => $product->id, 'name' => $product->name, 'mode' => $product->mode],
                'packets' => $available->map(fn ($p) => [
                    'id' => $p->id,
                    'qty' => (float) $p->qty,
                    'qty_label' => $this->stock->quantityPhrase((float) $p->qty, $product),
                    'cost' => (float) $p->cost,
                ]),
            ], 409);
        }

        $batch = $packet ? SupplyBatchModel::find($packet->batch_id) : null;

        return response()->json([
            'success' => true,
            'product' => [
                'id' => $product->id,
                'name' => $product->name,
                'mode' => $product->mode,
                'expense_category' => $product->expense_category_name,
            ],
            'packet' => $packet ? [
                'id' => $packet->id,
                'qty' => (float) $packet->qty,
                'qty_label' => $this->stock->quantityPhrase((float) $packet->qty, $product),
                'cost' => (float) $packet->cost,
                'batch_date' => optional($batch?->purchase_date)->format('d-M'),
                'free' => $batch && $batch->status === SupplyBatchModel::STATUS_STOCK_ONLY,
            ] : null,
        ]);
    }

    /**
     * A scan against a POOLED product: how much is leaving, what it costs, and anything
     * the person should look at twice before it does.
     *
     * Returns the same shape whether the weight came from a Czerlop label or from a vendor
     * barcode with a known packet weight, so the client has one path.
     */
    private function resolvePooledScan(SupplyProductModel $product, string $raw, ?array $decoded, bool $nominal)
    {
        if ($nominal) {
            $qty = (float) $product->packet_kg;
        } elseif ($decoded) {
            if ((int) $product->plu !== (int) $decoded['plu']) {
                return response()->json([
                    'success' => false,
                    'code' => 'wrong_product',
                    'message' => 'That label is PLU ' . $decoded['plu'] . ', not ' . $product->name . '.',
                ], 404);
            }
            $qty = (float) $decoded['weight_kg'];
        } else {
            return response()->json([
                'success' => false,
                'code' => 'no_weight',
                'message' => 'That code does not carry a weight. Type the weight instead.',
            ], 422);
        }

        \Log::info('Storage scan resolved', [
            'product_id' => $product->id,
            'barcode' => $raw,
            'qty' => $qty,
            'nominal' => $nominal,
            'user_id' => auth()->id(),
        ]);

        return $this->poolQuote($product, $qty, $raw);
    }

    /**
     * Price a quantity against the pool and collect the warnings, for a scan OR a typed
     * weight. The client shows the figure and any warning; `takeOut` re-checks everything
     * server-side, so a client that ignores a warning cannot get past it silently.
     */
    private function poolQuote(SupplyProductModel $product, float $qty, ?string $barcode)
    {
        $preview = $this->stock->previewPoolConsumption(
            $product, $qty, [], $this->stock->toleranceFor($product)
        );

        if (!$preview['ok']) {
            return response()->json([
                'success' => false,
                'code' => $preview['available'] <= 0 ? 'empty' : 'not_enough',
                'message' => $preview['available'] <= 0
                    ? 'No ' . $product->name . ' left in Storage — ask Taimur or Shabib to book the new stock.'
                    : 'Only ' . $this->stock->quantityPhrase($preview['available'], $product)
                        . ' of ' . $product->name . ' is left in Storage.',
                'pool_remaining' => $preview['available'],
            ], 409);
        }

        return response()->json([
            'success' => true,
            'product' => [
                'id' => $product->id,
                'name' => $product->name,
                'mode' => $product->mode,
                'expense_category' => $product->expense_category_name,
            ],
            'pooled' => true,
            'qty' => $preview['qty'],
            'qty_label' => $this->stock->quantityPhrase($preview['qty'], $product),
            'cost' => $preview['cost'],
            'free' => $preview['cost'] < 0.01,
            'capped' => $preview['capped'] ?? false,
            'pool_remaining' => $preview['available'],
            'pool_label' => $this->stock->quantityPhrase($preview['available'], $product),
            'legs' => $preview['legs'],
            'scanned_barcode' => $barcode,
            'warnings' => $this->poolWarnings($product, $preview['qty'], $preview['available'], $barcode),
        ]);
    }

    /**
     * ⚠⚠ THE GUARDS. A valid check digit is not proof of a correct read, and a typed
     * number is not proof of anything at all — and with the approval switch off a take-out
     * posts to expenses immediately, so this is where a wrong quantity is caught.
     *
     * Each is a WARNING, not a refusal: the person at the shelf can see the packet and we
     * cannot. Confirming one is a deliberate act, and `takeOut` demands the confirmation
     * back before it will write.
     */
    private function poolWarnings(SupplyProductModel $product, float $qty, float $available, ?string $barcode): array
    {
        $out = [];

        // 1. Wildly unlike what this product's packets normally weigh. Median, not mean —
        //    one wild reading must not drag the baseline far enough to accept the next.
        $median = $this->stock->recentTakeoutMedian($product);
        if ($median > 0 && abs($qty - $median) >= 1 && ($qty >= $median * 4 || $qty <= $median / 4)) {
            $out[] = [
                'code' => 'unusual_qty',
                'title' => 'Unusual weight',
                'message' => $this->stock->quantityPhrase($qty, $product) . ' — the packets taken out recently '
                    . 'were around ' . $this->stock->quantityPhrase($median, $product)
                    . '. A label can misread and still look valid.',
            ];
        }

        // 2. Most of the shelf in one go — the tray label scanned by mistake, the exact
        //    Sep-17 shape where one scan took a whole 26.97 kg bale.
        //
        //    ⚠ A FRACTION ALONE IS A BAD SIGNAL, and a test caught it: with two packets
        //    left, taking one is exactly 50 % and perfectly normal, and the last packet of
        //    a pool is 100 %. So the quantity must ALSO be bale-sized in absolute terms
        //    (the owner's bales are 10-28 kg, his packets about 1.5) and unlike what this
        //    product normally gives up. Otherwise the guard cries wolf every time a pool
        //    runs low, and a guard people learn to click through protects nothing.
        $baleLike = $qty >= 5.0;
        $unlikeUsual = $median <= 0 || $qty > $median * 2;

        if ($available > 0 && $qty >= $available * 0.5 && $baleLike && $unlikeUsual) {
            $out[] = [
                'code' => 'most_of_shelf',
                'title' => 'That is most of the shelf',
                'message' => $this->stock->quantityPhrase($qty, $product) . ' out of '
                    . $this->stock->quantityPhrase($available, $product)
                    . '. Is this one packet, or did you scan the whole bale by mistake?',
            ];
        }

        // 3. The same label, a moment ago. Two packets of the same weight print IDENTICAL
        //    labels, so this is legitimate as often as it is a double read — ask, never block.
        $twin = $this->stock->sameLabelRecently($product, $barcode);
        if ($twin) {
            $mins = max(1, (int) round($twin->taken_at->diffInMinutes(now())));
            $out[] = [
                'code' => 'same_label',
                'title' => 'This label went out already',
                'message' => 'The same label was taken out ' . $mins . ' minute(s) ago. Another packet of the '
                    . 'same weight, or the same one again?',
            ];
        }

        return $out;
    }

    /**
     * Decode a scale label for the INTAKE form, so the web page can show the weight of
     * each packet as it is scanned without re-implementing the EAN maths in JavaScript.
     * (The mobile app decodes locally with its own unit-tested `barcodeDecode.js`; both
     * are re-decoded server-side on save, which stays the authority either way.)
     */
    public function decodeBarcode(Request $request)
    {
        if (!$this->hasAccess()) {
            return $this->deny();
        }

        $raw = trim((string) $request->input('barcode'));
        $decoded = $this->decoder->decode($raw);

        if (!$decoded) {
            return response()->json([
                'success' => false,
                'reason' => $this->decoder->rejectionReason($raw),
                'message' => 'That is not a valid scale label.',
            ], 422);
        }

        return response()->json([
            'success' => true,
            'plu' => $decoded['plu'],
            'weight_kg' => $decoded['weight_kg'],
            'barcode' => $decoded['raw'],
        ]);
    }

    public function takeOut(Request $request)
    {
        if (!$this->hasAccess()) {
            return $this->deny();
        }

        $validated = $request->validate([
            'product_id' => 'required|integer|exists:t_fin_supply_product,id',
            'packet_id' => 'nullable|integer',
            'qty' => 'nullable|numeric|min:0.001',
            'source' => 'nullable|in:scan,manual',
            'scanned_barcode' => 'nullable|string|max:40',
            'confirmed_warnings' => 'nullable|array|max:10',
            'confirmed_warnings.*' => 'string|max:40',
            'note' => 'nullable|string|max:255',
        ]);

        // ⚠⚠ THE GUARDS ARE RE-RUN HERE, not trusted from the client.
        // The client shows them and asks; the server decides. A stale APK, a page with the
        // JavaScript disabled, or a hand-made request must not be able to post a quantity
        // that nobody looked at — and with the approval switch off this lands in expenses
        // immediately, so this is the last check there is.
        $product = SupplyProductModel::findOrFail($validated['product_id']);
        if ($product->isPooled()) {
            $qty = round((float) ($validated['qty'] ?? 0), 3);
            $confirmed = $validated['confirmed_warnings'] ?? [];
            $warnings = $this->poolWarnings(
                $product, $qty,
                $this->stock->onHand($product),
                $validated['scanned_barcode'] ?? null
            );
            $unconfirmed = array_values(array_filter(
                $warnings,
                fn ($w) => !in_array($w['code'], $confirmed, true)
            ));

            if ($unconfirmed) {
                return response()->json([
                    'success' => false,
                    'code' => 'needs_confirmation',
                    'message' => $unconfirmed[0]['message'],
                    'warnings' => $unconfirmed,
                ], 409);
            }
        }

        try {
            $takeout = $this->stock->takeOut($validated, auth()->id());
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        $product = SupplyProductModel::find($takeout->product_id);
        $qtyLabel = $this->stock->quantityPhrase((float) $takeout->qty, $product);

        if ($takeout->status === SupplyTakeoutModel::STATUS_PENDING) {
            $this->notifyApprovers($takeout, $product, $qtyLabel);
        }

        return response()->json([
            'success' => true,
            'takeout_id' => $takeout->id,
            'status' => $takeout->status,
            'qty_label' => $qtyLabel,
            'cost' => (float) $takeout->cost,
            'message' => match ($takeout->status) {
                SupplyTakeoutModel::STATUS_NO_CHARGE => 'Taken out — nothing charged (already expensed).',
                SupplyTakeoutModel::STATUS_APPROVED => 'Taken out — Rs ' . number_format((float) $takeout->cost, 0)
                    . ' booked to ' . $product->expense_category_name . '.',
                default => 'Taken out — sent for approval.',
            },
        ]);
    }

    /** The scanner's own undo, while the take-out is still pending. */
    public function undoTakeout(Request $request, $takeoutId)
    {
        if (!$this->hasAccess()) {
            return $this->deny();
        }

        $takeout = SupplyTakeoutModel::findOrFail($takeoutId);

        if ((int) $takeout->taken_by !== (int) auth()->id()) {
            return response()->json(['success' => false, 'message' => 'You can only undo your own take-outs.'], 403);
        }
        // ⚠ isUndoable(), not isPending(): a no_charge take-out (opening stock, already
        // expensed in an earlier month) raises no request, so it is never "pending" and
        // the old check made a mis-scan on free stock impossible to reverse.
        if (!$takeout->isUndoable()) {
            return response()->json([
                'success' => false,
                'message' => $takeout->status === SupplyTakeoutModel::STATUS_APPROVED
                    ? 'That take-out is already approved and booked to expenses — a manager can delete '
                        . 'the expense from the Expenses page, which puts the packet back.'
                    : 'That take-out has already been decided.',
            ], 422);
        }

        DB::transaction(function () use ($takeout) {
            if ($takeout->request_id) {
                $req = \App\Models\Request\RequestModel::find($takeout->request_id);
                if ($req && $req->status === \App\Models\Request\RequestModel::STATUS_PENDING) {
                    $req->status = \App\Models\Request\RequestModel::STATUS_CANCELLED;
                    $req->rejection_reason = 'Undone by the person who took it out.';
                    $req->updated_by = auth()->id();
                    $req->save();
                }
            }
            $this->stock->restore($takeout, SupplyTakeoutModel::STATUS_UNDONE, 'Undone by the scanner');
        });

        return response()->json(['success' => true, 'message' => 'Undone — it is back in Storage.']);
    }

    /**
     * Price a TYPED quantity before it is taken out — the manual twin of resolveScan.
     *
     * A label tears, smudges, or the vendor's packet never carried one. Without this the
     * store simply stops, so typing is open to everyone who can take out; the guards and
     * the "(typed)" marking on every row are what carry the risk (see the round-3 plan).
     */
    public function quoteTakeout(Request $request)
    {
        if (!$this->hasAccess()) {
            return $this->deny();
        }

        $validated = $request->validate([
            'product_id' => 'required|integer|exists:t_fin_supply_product,id',
            'qty' => 'required|numeric|min:0.001',
        ]);

        $product = SupplyProductModel::findOrFail($validated['product_id']);
        if (!$product->isPooled()) {
            return response()->json([
                'success' => false,
                'message' => 'That product is taken out by scanning a packet, not by typing a quantity.',
            ], 422);
        }

        return $this->poolQuote($product, round((float) $validated['qty'], 3), null);
    }

    /**
     * ⭐ Delete a take-out from the Storage screen itself.
     *
     * `manage_supplies_storage` — Taimur and Shabib — which is deliberately WIDER than the
     * general expense-delete rule (L2, Taimur alone). Owner ruling: this money is the stock
     * account, not a till, and a mis-scan at the shelf should not wait for one person.
     */
    public function deleteTakeout(Request $request, $takeoutId)
    {
        if (!$this->canManage()) {
            return $this->deny('Storage stock');
        }

        $takeout = SupplyTakeoutModel::findOrFail($takeoutId);
        $reason = trim((string) $request->input('reason')) ?: null;

        try {
            $result = $this->stock->deleteTakeout($takeout, auth()->id(), $reason);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        $product = SupplyProductModel::find($takeout->product_id);
        $qtyLabel = $product ? $this->stock->quantityPhrase((float) $result['qty'], $product) : $result['qty'];

        return response()->json([
            'success' => true,
            'message' => 'Deleted — ' . $qtyLabel . ' is back in Storage'
                . ($result['cost'] > 0 ? ' and Rs ' . number_format($result['cost'], 0) . ' came off expenses' : '')
                . '.',
            'month_touched' => $result['month_touched'],
        ]);
    }

    /** What changing a take-out's weight would do. Writes nothing. */
    public function previewTakeoutEdit(Request $request, $takeoutId)
    {
        if (!$this->canManage()) {
            return $this->deny('Storage stock');
        }

        $validated = $request->validate(['qty' => 'required|numeric|min:0.001']);
        $takeout = SupplyTakeoutModel::findOrFail($takeoutId);
        $product = SupplyProductModel::findOrFail($takeout->product_id);

        $preview = $this->stock->previewTakeoutEdit($takeout, round((float) $validated['qty'], 3));

        if (!$preview['ok']) {
            return response()->json([
                'success' => false,
                'message' => 'Only ' . $this->stock->quantityPhrase($preview['available'], $product)
                    . ' would be available for this take-out.',
            ], 409);
        }

        return response()->json([
            'success' => true,
            'was_qty_label' => $this->stock->quantityPhrase($preview['was_qty'], $product),
            'qty_label' => $this->stock->quantityPhrase($preview['qty'], $product),
            'was_cost' => $preview['was_cost'],
            'cost' => $preview['cost'],
            'legs' => $preview['legs'],
            'restates_earlier_month' => $preview['restates_earlier_month'],
            'expense_month' => $preview['expense_month'],
        ]);
    }

    /** Change a take-out's weight — see SupplyStockService::editTakeoutWeight. */
    public function editTakeout(Request $request, $takeoutId)
    {
        if (!$this->canManage()) {
            return $this->deny('Storage stock');
        }

        $validated = $request->validate([
            'qty' => 'required|numeric|min:0.001',
            'reason' => 'nullable|string|max:255',
        ]);

        $takeout = SupplyTakeoutModel::findOrFail($takeoutId);
        $product = SupplyProductModel::findOrFail($takeout->product_id);

        try {
            $result = $this->stock->editTakeoutWeight(
                $takeout,
                round((float) $validated['qty'], 3),
                auth()->id(),
                $validated['reason'] ?? null
            );
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Corrected to ' . $this->stock->quantityPhrase($result['qty'], $product)
                . ' — Rs ' . number_format($result['cost'], 0)
                . match ($result['crossed']) {
                    'now_billable' => '. It now comes out of bought stock, so an expense was raised.',
                    'now_free'     => '. It now comes out of opening stock, so the expense was removed.',
                    default        => '.',
                },
            'qty' => $result['qty'],
            'cost' => $result['cost'],
            'month_touched' => $result['month_touched'],
        ]);
    }

    /** The owner's switch: do take-outs queue for approval, or post immediately? */
    public function setApprovalSwitch(Request $request)
    {
        if (!$this->canManage()) {
            return $this->deny('Storage stock');
        }

        $on = $request->boolean('required');
        ConfigModel::set(
            SupplyStockService::APPROVAL_SWITCH_KEY,
            $on ? '1' : '0',
            'Storage take-outs: 1 = queue for approval, 0 = post immediately'
        );

        return response()->json([
            'success' => true,
            'required' => $on,
            'message' => $on
                ? 'Take-outs now wait for approval.'
                : 'Take-outs now post straight to expenses, with no approval.',
        ]);
    }

    // -----------------------------------------------------------------------------
    // HELPERS
    // -----------------------------------------------------------------------------

    /** Same backdating window an expense uses — the role's expense_backdate_days. */
    private function checkBackdate(?string $date): ?string
    {
        if (!$date) {
            return null;
        }

        $days = (int) DB::table('t_sys_user_role as ur')
            ->join('t_sys_role as r', 'r.id', '=', 'ur.role_id')
            ->where('ur.user_id', auth()->id())
            ->max('r.expense_backdate_days');

        $purchase = \Carbon\Carbon::parse($date)->startOfDay();
        if ($purchase->gt(now()->endOfDay())) {
            return 'The purchase date cannot be in the future.';
        }
        if ($purchase->lt(now()->startOfDay()->subDays($days))) {
            return $days === 0
                ? 'You can only book stock bought today.'
                : "You can only book stock bought in the last {$days} day(s).";
        }

        return null;
    }

    private function notifyApprovers(SupplyTakeoutModel $takeout, ?SupplyProductModel $product, string $qtyLabel): void
    {
        try {
            app(\App\Services\FirebaseService::class)->notifySupplyTakeoutPending(
                $product?->name ?? 'Storage item',
                $qtyLabel,
                (float) $takeout->cost,
                auth()->user()->fullname ?? '',
                $takeout->id,
                auth()->id()
            );
        } catch (\Throwable $e) {
            // Best-effort, exactly like every other push in this codebase.
            \Log::warning('Storage take-out push failed', ['error' => $e->getMessage()]);
        }
    }
}
