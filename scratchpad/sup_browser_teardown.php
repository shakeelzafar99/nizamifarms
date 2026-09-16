<?php
// Remove the browser-verification fixture and put the balances back exactly as they were.
use App\Models\FIN\LedgerModel;
use App\Services\FIN\BalancePostingService;
use Illuminate\Support\Facades\DB;

$ids = DB::table('t_fin_supply_product')->where('name', 'LIKE', 'ZZ Browser %')->pluck('id')->all();
if (!$ids) { echo "nothing to clean\n"; return; }
echo "products: " . implode(',', $ids) . "\n";

$before = [
  'ONLINE' => (float) DB::table('t_fin_accounts')->where('account_code','ONLINE')->value('current_balance'),
  'STOCK'  => (float) DB::table('t_fin_accounts')->where('account_code','SUPPLIES_STOCK')->value('current_balance'),
];

DB::transaction(function () use ($ids) {
    $batches = DB::table('t_fin_supply_batch')->whereIn('product_id', $ids)->get();
    foreach ($batches as $b) {
        if ($b->ledger_id) {
            $l = LedgerModel::find($b->ledger_id);
            if ($l) {
                (new BalancePostingService())->reverse($l);   // self-guards on balance_updated
                $l->delete();
                echo "  reversed + deleted ledger #{$b->ledger_id}\n";
            }
        }
    }
    $batchIds = $batches->pluck('id')->all();
    DB::table('t_fin_supply_takeout_leg')->whereIn('batch_id', $batchIds ?: [0])->delete();
    DB::table('t_fin_supply_takeout')->whereIn('product_id', $ids)->delete();
    DB::table('t_fin_supply_log')->whereIn('product_id', $ids)->delete();
    DB::table('t_fin_supply_packet')->whereIn('product_id', $ids)->delete();
    DB::table('t_fin_supply_batch')->whereIn('product_id', $ids)->delete();
    DB::table('t_fin_supply_product')->whereIn('id', $ids)->delete();
});

$after = [
  'ONLINE' => (float) DB::table('t_fin_accounts')->where('account_code','ONLINE')->value('current_balance'),
  'STOCK'  => (float) DB::table('t_fin_accounts')->where('account_code','SUPPLIES_STOCK')->value('current_balance'),
];
echo "\nbalances  before: " . json_encode($before) . "\n           after: " . json_encode($after) . "\n";
echo "residual — products:" . DB::table('t_fin_supply_product')->count()
   . " batches:" . DB::table('t_fin_supply_batch')->count()
   . " packets:" . DB::table('t_fin_supply_packet')->count()
   . " takeouts:" . DB::table('t_fin_supply_takeout')->count()
   . " logs:" . DB::table('t_fin_supply_log')->count() . "\n";
