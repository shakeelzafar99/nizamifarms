<?php
// TEMPORARY browser fixture for round 3. Removed by sup_browser_teardown.php (ZZ prefix).
use App\Models\FIN\SupplyProductModel;
use App\Services\FIN\SupplyStockService;
use Illuminate\Support\Facades\DB;
$cfg = DB::table('t_fin_config')->where('config_key','LIKE','EXPENSE_CATEGORY_%')->orderBy('id')->first();
$online = DB::table('t_fin_accounts')->where('account_code','ONLINE')->value('id');
$bank = DB::table('t_fin_online_receiving_accounts')->orderBy('id')->first();
$svc = app(SupplyStockService::class);

$p = SupplyProductModel::create(['name'=>'ZZ Browser Pool Bags','mode'=>'weight','plu'=>987660,
  'expense_config_id'=>$cfg->id,'expense_category_name'=>$cfg->config_value,
  'business_unit_id'=>1,'is_active'=>1,'created_by'=>68]);

// two bales, booked days apart — exactly the owner's situation
$b1 = $svc->bookBatch(['product_id'=>$p->id,'purchase_date'=>now()->subDays(3)->toDateString(),
  'total_cost'=>24750,'payment_source_account_id'=>$online,'receiving_account_id'=>$bank->id,
  'client_uuid'=>'zz-pool-1','packets'=>[['qty'=>26.97,'source'=>'manual']]], 68);
$b2 = $svc->bookBatch(['product_id'=>$p->id,'purchase_date'=>now()->subDays(1)->toDateString(),
  'total_cost'=>25590,'payment_source_account_id'=>$online,'receiving_account_id'=>$bank->id,
  'client_uuid'=>'zz-pool-2','packets'=>[['qty'=>27.89,'source'=>'manual']]], 68);

// one take-out already on the books, so Edit/Delete have something to work on
$t = $svc->takeOut(['product_id'=>$p->id,'qty'=>1.5,'source'=>'scan','scanned_barcode'=>'2987660015005'], 68);
echo "product={$p->id} batches={$b1->id},{$b2->id} takeout={$t->id} cost={$t->cost}\n";
