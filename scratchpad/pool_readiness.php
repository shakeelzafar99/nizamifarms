<?php
// Does the EXISTING Sep-17 take-out unwind cleanly once weight stock is read as a POOL?
use App\Models\FIN\{SupplyBatchModel, SupplyPacketModel, SupplyTakeoutModel, SupplyTakeoutLegModel, AccountModel};
use Illuminate\Support\Facades\DB;

echo "── legs on the real take-out ──\n";
foreach (SupplyTakeoutLegModel::where('takeout_id', 1)->get() as $l) {
    echo sprintf("  leg#%d takeout=%s batch=%s packet=%s qty=%s cost=%s\n",
        $l->id, $l->takeout_id, $l->batch_id, $l->packet_id ?? 'NULL', $l->qty, $l->cost);
}
echo "\n── the POOL as round 3 would read it (Σ qty_remaining / cost_remaining, non-voided) ──\n";
foreach ([1, 2] as $pid) {
    $rows = SupplyBatchModel::where('product_id', $pid)->where('status', '!=', 'voided')->get();
    printf("  product %d: %s kg / Rs %s across %d purchase(s)\n", $pid,
        round($rows->sum(fn($b) => (float)$b->qty_remaining), 3),
        number_format($rows->sum(fn($b) => (float)$b->cost_remaining), 2), $rows->count());
}
echo "\n── after Taimur deletes take-out #1 (simulated, rolled back) ──\n";
DB::beginTransaction();
try {
    $req = \App\Models\Request\RequestModel::where('supply_takeout_id', 1)->firstOrFail();
    $l = \App\Models\FIN\LedgerModel::find($req->ledger_transaction_id);
    (new \App\Services\FIN\BalancePostingService())->reverse($l);
    $l->approval_status = \App\Models\FIN\LedgerModel::STATUS_REVERSED; $l->save();
    $req->setAttribute('status', 'cancelled'); $req->save();
    app(\App\Services\FIN\SupplyStockService::class)->syncWithRequest($req->fresh());

    $rows = SupplyBatchModel::where('product_id', 1)->where('status', '!=', 'voided')->get();
    printf("  pool for product 1: %s kg / Rs %s\n",
        round($rows->sum(fn($b) => (float)$b->qty_remaining), 3),
        number_format($rows->sum(fn($b) => (float)$b->cost_remaining), 2));
    printf("  SUPPLIES_STOCK bal: %s   (shelf total: %s)\n",
        AccountModel::where('account_code','SUPPLIES_STOCK')->value('current_balance'),
        number_format(SupplyBatchModel::where('status','!=','voided')->sum('cost_remaining'), 2));
    printf("  packet #1: %s   take-out #1: %s\n",
        SupplyPacketModel::find(1)->status, SupplyTakeoutModel::find(1)->status);
    printf("  legs left on take-out #1: %d  (restore deletes none; they are the audit)\n",
        SupplyTakeoutLegModel::where('takeout_id', 1)->count());

    echo "\n  then a 1.5 kg packet scanned out of the restored pool:\n";
    $unit = 24750 / 26.97;
    printf("    FIFO takes 1.5 kg from batch #1 @ Rs %.4f/kg = Rs %s\n", $unit, number_format(round($unit * 1.5, 2), 2));
} finally { DB::rollBack(); echo "\n(rolled back)\n"; }
