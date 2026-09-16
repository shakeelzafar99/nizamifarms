<?php
require __DIR__.'/../vendor/autoload.php';
$a = require __DIR__.'/../bootstrap/app.php';
$a->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
use App\Services\Riders\{VehicleResolver, VehicleService, BikeServiceAlerts};
// mimic the suite: find Waseem's machine the same way
$RID = (int) DB::table('t_sys_user')->where('fullname','like','%Waseem%')->value('id');
echo "rider=$RID\n";
$vid = (new VehicleResolver())->currentVehicleFor($RID);
echo "vid=".var_export($vid,true)." label=".(new VehicleResolver())->labelFor($vid)."\n";
echo "meter=".var_export((new VehicleService())->currentMeterFor((int)$vid),true)."\n";
echo "job types: ".json_encode(DB::select("select id,type_name,interval_km,is_active from t_fleet_maintenance_types where is_active=1 and interval_km>0 order by interval_km limit 5"))."\n";
echo "sched rows for vid: ".json_encode(DB::select("select * from t_ops_vehicle_service_schedule where vehicle_id=?", [$vid]))."\n";
echo "service logs: ".json_encode(DB::select("select id, maintenance_type_id, meter, service_date from t_fleet_service_log where vehicle_id=? order by id desc limit 5", [$vid]))."\n";
$alerts = (new BikeServiceAlerts())->due();
echo "due count total=".count($alerts)."\n";
foreach (array_slice($alerts,0,6) as $x) echo "  ".json_encode(array_intersect_key($x, array_flip(['vehicle_id','vehicle_name','type_name','due_text','km_left'])))."\n";
