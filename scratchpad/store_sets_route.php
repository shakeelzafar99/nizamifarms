<?php
/** Shabib plans Rajab's route through the REAL endpoint — persisted, so the phone sees it. */
use Illuminate\Http\Request; use Illuminate\Support\Facades\DB; use Laravel\Sanctum\Sanctum;
$DRIVER = 95;
$u = \App\Models\User::find(79) ?? \App\Models\CRM\UserModel::find(79);
Sanctum::actingAs($u, ['*']); auth()->setUser($u);
// Reverse the natural id order, so "the store planned this" is unmistakable on the phone.
$ids = DB::table('t_crm_prod_order')->where('van_user_id',$DRIVER)->where('assigned_rider_user_id',$DRIVER)
    ->where('order_status','on_van')->orderByDesc('id')->pluck('id')->all();
$prios = []; foreach ($ids as $i => $id) $prios[] = ['order_id'=>$id,'priority'=>$i+1];
$req = Request::create('/api/rider/store/update-delivery-priorities','POST',
    ['rider_id'=>$DRIVER,'priorities'=>$prios],[],[],['HTTP_ACCEPT'=>'application/json']);
$res = app()->handle($req);
echo "status ", $res->getStatusCode(), " → ", $res->getContent(), "\n";
foreach (DB::table('t_crm_prod_order')->whereIn('id',$ids)->orderBy('delivery_priority')
    ->get(['order_number','delivery_priority','delivery_priority_updated_by']) as $r)
  echo "  #{$r->delivery_priority} {$r->order_number} (set by u{$r->delivery_priority_updated_by})\n";
