<?php
/**
 * Remove everything devcheck_issues_seed.php created. ⚠ Matches ONLY on the DEVCHECK tag and the
 * ids it owns, so a real ticket or visit can never be caught by it.
 */
require __DIR__.'/../vendor/autoload.php';
$a = require __DIR__.'/../bootstrap/app.php';
$a->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
use App\Services\Riders\{VehicleTicketService as VT, WorkshopVisitService as WV};

$ids = DB::table(VT::T_TICKET)->where('title', 'like', 'DEVCHECK%')->pluck('id')->all();
echo "tickets to remove: ".json_encode($ids)."\n";
$msgs = $ids ? DB::table(VT::T_MESSAGE)->whereIn('ticket_id', $ids)->count() : 0;
if ($ids) {
    DB::table(VT::T_MESSAGE)->whereIn('ticket_id', $ids)->delete();
    DB::table('t_ops_vehicle_ticket_read')->whereIn('ticket_id', $ids)->delete();
    DB::table(VT::T_TICKET)->whereIn('id', $ids)->delete();
}
$visits = DB::table(WV::T_VISIT)->where('workshop', 'like', 'DEVCHECK%')->pluck('id')->all();
echo "visits to remove: ".json_encode($visits)."\n";
if ($visits) DB::table(WV::T_VISIT)->whereIn('id', $visits)->delete();

echo "removed {$msgs} messages, ".count($ids)." tickets, ".count($visits)." visits\n";
echo "left behind — tickets: ".DB::table(VT::T_TICKET)->where('title','like','DEVCHECK%')->count()
   ." visits: ".DB::table(WV::T_VISIT)->where('workshop','like','DEVCHECK%')->count()."\n";
echo "ticket total now: ".DB::table(VT::T_TICKET)->count()
   ." · visit total now: ".DB::table(WV::T_VISIT)->count()."\n";
