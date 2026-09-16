<?php
// TEMPORARY browser-verification fixture. Committed rows, removed by sup_browser_teardown.php.
use App\Models\FIN\{SupplyProductModel, SupplyBatchModel, SupplyPacketModel};
use App\Services\FIN\SupplyStockService;
use Illuminate\Support\Facades\DB;
$cfg = DB::table('t_fin_config')->where('config_key','LIKE','EXPENSE_CATEGORY_%')->orderBy('id')->first();
$online = DB::table('t_fin_accounts')->where('account_code','ONLINE')->value('id');
$bank = DB::table('t_fin_online_receiving_accounts')->orderBy('id')->first();

$withStock = SupplyProductModel::create(['name'=>'ZZ Browser Bags (stock)','mode'=>'weight','plu'=>987654,
  'expense_config_id'=>$cfg->id,'expense_category_name'=>$cfg->config_value,'business_unit_id'=>1,'is_active'=>1,'created_by'=>68]);
$empty = SupplyProductModel::create(['name'=>'ZZ Browser Bags (empty)','mode'=>'weight','plu'=>987655,
  'expense_config_id'=>$cfg->id,'expense_category_name'=>$cfg->config_value,'business_unit_id'=>1,'is_active'=>1,'created_by'=>68]);

app(SupplyStockService::class)->bookBatch([
  'product_id'=>$withStock->id,'purchase_date'=>now()->toDateString(),'total_cost'=>4000,
  'payment_source_account_id'=>$online,'receiving_account_id'=>$bank->id,'client_uuid'=>'zz-browser-1',
  'packets'=>[['qty'=>1.25,'barcode'=>'2987654012509','source'=>'scan'],['qty'=>1.25,'barcode'=>'2987654012509','source'=>'scan']],
], 68);
echo "seeded: with_stock={$withStock->id} empty={$empty->id}\n";
