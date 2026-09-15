<?php
use Illuminate\Http\Request; use Illuminate\Support\Facades\DB; use Laravel\Sanctum\Sanctum;
$pass=0;$fail=0;
$ck=function($n,$ok,$d='')use(&$pass,&$fail){$ok?$pass++:$fail++;echo($ok?'OK  ':'BAD ').$n.($d!==''?'  → '.(is_string($d)?$d:json_encode($d)):'')."\n";};
DB::beginTransaction();
try {
  $DRIVER=95; $VAN=4;
  DB::table('t_ops_vehicle_assignment')->insert(['vehicle_id'=>$VAN,'user_id'=>$DRIVER,
    'assigned_on'=>today()->format('Y-m-d'),'released_on'=>null,'created_at'=>now(),'updated_at'=>now()]);
  $van=app(\App\Services\Riders\VanService::class);
  $oid=DB::table('t_crm_prod_order')->orderByDesc('id')->value('id');
  DB::table('t_crm_prod_order')->where('id',$oid)->update(['order_status'=>'on_van',
    'assigned_rider_user_id'=>77,'van_user_id'=>$DRIVER,'van_vehicle_id'=>$VAN,
    'van_loaded_at'=>now()->subMinutes(20),'van_loaded_packets'=>json_encode([1]),
    'expected_packets'=>1,'handover_at'=>null,'eta_calculated_at'=>null]);
  $trip=$van->ensureTrip($DRIVER,null,$DRIVER);
  DB::table('t_ops_van_trip')->where('id',$trip->id)->update(['departed_at'=>now()->subMinutes(15)]);
  // a stop that was force-closed with cargo aboard, then the trip ENDED (the M5 shape)
  DB::table(\App\Services\Riders\VanService::T_HANDOVER)->insert(['van_user_id'=>$DRIVER,
    'trip_id'=>$trip->id,'label'=>'Smoke stop','meet_lat'=>33.6,'meet_lng'=>73.1,'status'=>'forced',
    'set_at'=>now()->subMinutes(12),'reached_at'=>now()->subMinutes(8),'completed_at'=>now()->subMinutes(2),
    'note'=>'left with cargo','created_at'=>now(),'updated_at'=>now()]);

  $u=\App\Models\User::find(74)??\App\Models\CRM\UserModel::find(74);
  Sanctum::actingAs($u,['*']); auth()->setUser($u);
  $call=function($uri){ $r=Request::create($uri,'GET',[],[],[],['HTTP_ACCEPT'=>'application/json']);
    $res=app()->handle($r); return [$res->getStatusCode(), json_decode($res->getContent(),true)]; };

  [$st,$j]=$call('/api/rider/van/store-panel');
  $ck('store-panel 200 while the trip is OPEN',$st===200,$st);
  $v=($j['vans']??[])[0]??null;
  $ck('  the van is on the board',$v!==null);
  $ck('  the abandoned meet-up is reported',count($v['forced_closes']??[])===1,$v['forced_closes']??[]);
  $ck('  the trip timeline is there',count($v['trip_stops']??[])>=1);
  $ck('  the stranded count is present (0 today)',array_key_exists('stranded',$v['totals']??[]),$v['totals']['stranded']??'MISSING');

  // ⭐ THE M5 CASE: finish the trip — the banner must SURVIVE it.
  DB::table('t_ops_van_trip')->where('id',$trip->id)->update(['ended_at'=>now(),'current_leg'=>'done']);
  [$st,$j]=$call('/api/rider/van/store-panel');
  $v=($j['vans']??[])[0]??null;
  $ck('store-panel still 200 after the trip is finished',$st===200,$st);
  $ck('  ⭐ the abandoned-cargo banner SURVIVES the finish',
      $v!==null && count($v['forced_closes']??[])===1, $v['forced_closes']??'van gone');
  $ck('  and so does the timeline',$v!==null && count($v['trip_stops']??[])>=1);
} catch(\Throwable $e){ echo "EXCEPTION: ".$e->getMessage()." @".$e->getFile().':'.$e->getLine()."\n"; $fail++; }
finally { DB::rollBack(); echo "--- rolled back ---\n"; }
echo "\n==== $pass passed / $fail failed ====\n";
