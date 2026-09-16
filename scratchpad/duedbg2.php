<?php
require __DIR__.'/../vendor/autoload.php';
$a = require __DIR__.'/../bootstrap/app.php';
$a->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
use App\Services\Riders\{VehicleService, VehicleScheduleService, ServiceIntervalResolver, BikeServiceAlerts};
$vid=3;
echo "vehicle row: ".json_encode(DB::table('t_ops_vehicle')->where('id',$vid)->first(['id','reg_no','last_service_meter','last_service_at','service_interval_km','is_active','is_company']))."\n";
DB::beginTransaction();
$job=(int) DB::table('t_fleet_maintenance_types')->where('is_active',1)->where('interval_km','>',0)->orderBy('interval_km')->value('id');
echo "job=$job\n";
DB::table('t_ops_vehicle_service_schedule')->updateOrInsert(
  ['vehicle_id'=>$vid,'maintenance_type_id'=>$job],
  ['interval_km'=>100,'updated_at'=>now(),'created_at'=>now()]);
VehicleService::flushServiceMemo(); VehicleScheduleService::flush(); ServiceIntervalResolver::flush(); \Cache::flush();
$alerts=(new BikeServiceAlerts())->due();
echo "due total=".count($alerts)."\n";
foreach($alerts as $x){ if((int)$x['vehicle_id']===$vid) echo "  MINE ".json_encode($x)."\n"; }
echo "serviceScheduleFor: ".json_encode((new VehicleService())->serviceScheduleFor($vid, (new VehicleService())->currentMeterFor($vid)))."\n";
DB::rollBack();
