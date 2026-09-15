<?php
/** Arslan collects his two boxes at the meet-up — the REAL handover scan, persisted. */
use Illuminate\Http\Request; use Illuminate\Support\Facades\DB; use Laravel\Sanctum\Sanctum;
$RIDER = 77;
$u = \App\Models\User::find($RIDER) ?? \App\Models\CRM\UserModel::find($RIDER);
Sanctum::actingAs($u, ['*']); auth()->setUser($u);
$ids = DB::table('t_crm_prod_order')->where('van_user_id',95)->where('assigned_rider_user_id',$RIDER)
    ->where('order_status','on_van')->orderBy('delivery_priority')->pluck('id')->all();
foreach ($ids as $id) {
    $no = DB::table('t_crm_prod_order')->where('id',$id)->value('order_number');
    $req = Request::create("/api/rider/van/orders/{$id}/handover-scan",'POST',['scan_code'=>$no],[],[],['HTTP_ACCEPT'=>'application/json']);
    $res = app()->handle($req); $j = json_decode($res->getContent(), true);
    echo "  {$no}: ", $res->getStatusCode(), " ", ($j['message'] ?? ''), "\n";
}
foreach (DB::table('t_crm_prod_order')->whereIn('id',$ids)->orderBy('delivery_priority')
    ->get(['order_number','order_status','delivery_priority','handover_at','estimated_delivery_at']) as $r)
  echo "  → #{$r->delivery_priority} {$r->order_number} {$r->order_status} handover={$r->handover_at} eta=".($r->estimated_delivery_at ?? 'none')."\n";
