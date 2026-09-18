<?php
// Does deleting the expense of an APPROVED take-out put the packet back? Rolled back.
use App\Models\FIN\{SupplyPacketModel, SupplyBatchModel, SupplyTakeoutModel, AccountModel, LedgerModel};
use App\Models\Request\RequestModel;
use App\Services\FIN\SupplyStockService;
use Illuminate\Support\Facades\DB;
DB::beginTransaction();
try {
    $t = SupplyTakeoutModel::find(1);
    $req = RequestModel::find($t->request_id);
    echo "before: takeout={$t->status} packet=" . SupplyPacketModel::find(1)->status
       . " batch1 qty_rem=" . SupplyBatchModel::find(1)->qty_remaining
       . " stock_bal=" . AccountModel::where('account_code','SUPPLIES_STOCK')->value('current_balance') . "\n";

    // exactly what ExpenseManagementController::destroy does
    $ledger = LedgerModel::find($req->ledger_transaction_id);
    (new \App\Services\FIN\BalancePostingService())->reverse($ledger);
    $ledger->approval_status = LedgerModel::STATUS_REVERSED; $ledger->save();
    $req->setAttribute('status', 'cancelled'); $req->save();
    app(SupplyStockService::class)->syncWithRequest($req->fresh());

    $t2 = SupplyTakeoutModel::find(1);
    echo "after : takeout={$t2->status} packet=" . SupplyPacketModel::find(1)->status
       . " batch1 qty_rem=" . SupplyBatchModel::find(1)->qty_remaining
       . " stock_bal=" . AccountModel::where('account_code','SUPPLIES_STOCK')->value('current_balance') . "\n";
    echo "shelf value (sum cost_remaining) = " . SupplyBatchModel::where('status','!=','voided')->sum('cost_remaining') . "\n";
} finally { DB::rollBack(); echo "(rolled back)\n"; }
