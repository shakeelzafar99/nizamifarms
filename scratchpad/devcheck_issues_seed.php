<?php
/**
 * Seed DEVCHECK issues + a workshop day so the Issues board has something real to draw on the
 * device. ⚠ Every row is titled "DEVCHECK …" and removed by devcheck_issues_teardown.php.
 * ⚠ Touches NO existing row — it only inserts.
 */
require __DIR__.'/../vendor/autoload.php';
$a = require __DIR__.'/../bootstrap/app.php';
$a->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
use App\Services\Riders\{VehicleTicketService as VT, WorkshopVisitService as WV, VehicleResolver};

$now = now();
$MGR = 91;           // Qasim — a ticket manager, so his replies read as "waiting on the rider"
$res = new VehicleResolver();

// Two company machines that currently have keepers.
$rows = DB::table('t_ops_vehicle as v')
    ->join('t_ops_vehicle_assignment as a', function ($j) { $j->on('a.vehicle_id','=','v.id')->whereNull('a.released_on'); })
    ->where('v.is_company', 1)->where('v.is_active', 1)
    ->orderBy('v.id')->limit(2)->get(['v.id','v.reg_no','a.user_id']);
if (count($rows) < 2) { fwrite(STDERR, "need two held company machines\n"); exit(1); }
[$A, $B] = [$rows[0], $rows[1]];

$mkTicket = function ($vid, $uid, $title, $urgent, $status, $openedDaysAgo, $firstRespDaysAgo) use ($now) {
    return (int) DB::table(VT::T_TICKET)->insertGetId([
        'vehicle_id' => $vid, 'opened_by' => $uid, 'opened_for_user_id' => $uid,
        'category' => 'problem', 'urgent' => $urgent ? 1 : 0, 'title' => $title,
        'status' => $status,
        'first_response_at' => $firstRespDaysAgo === null ? null : $now->copy()->subDays($firstRespDaysAgo),
        'opened_at' => $now->copy()->subDays($openedDaysAgo),
        'last_message_at' => $now->copy()->subDays($openedDaysAgo),
        'created_at' => $now, 'updated_at' => $now,
    ]);
};
$msg = function ($tid, $uid, $body, $daysAgo, $kind = 'text') use ($now) {
    DB::table(VT::T_MESSAGE)->insert(['ticket_id' => $tid, 'user_id' => $uid, 'kind' => $kind,
        'body' => $body, 'created_at' => $now->copy()->subDays($daysAgo)]);
    DB::table(VT::T_TICKET)->where('id', $tid)->update(['last_message_at' => $now->copy()->subDays($daysAgo)]);
};

// Machine A — the worst card: urgent + unanswered, plus two waiting on us.
$t1 = $mkTicket($A->id, $A->user_id, 'DEVCHECK tyre burst — bike not rideable', true, 'open', 3, null);
$msg($t1, $A->user_id, 'Tyre phat gaya hai, chal nahi sakti', 3);
$t2 = $mkTicket($A->id, $A->user_id, 'DEVCHECK seat torn', false, 'acknowledged', 9, 8);
$msg($t2, $A->user_id, 'Seat phat gayi hai', 9);
$msg($t2, $MGR, 'Next week dekh lenge', 8);
$msg($t2, $A->user_id, 'Ok', 4);
$t3 = $mkTicket($A->id, $A->user_id, 'DEVCHECK head cylinder noise', false, 'acknowledged', 6, 5);
$msg($t3, $A->user_id, 'Awaaz aa rahi hai', 6);
$msg($t3, $MGR, 'Mechanic ko dikhate hain', 2);   // manager last ⇒ waiting on the RIDER

// Machine B — "workshop done but the complaint is still open": the detector.
$t4 = $mkTicket($B->id, $B->user_id, 'DEVCHECK brake shoe worn', false, 'acknowledged', 10, 9);
$msg($t4, $B->user_id, 'Brake kaam nahi kar rahi', 10);
$msg($t4, $MGR, 'Next Week', 9);
$v = (int) DB::table(WV::T_VISIT)->insertGetId([
    'vehicle_id' => $B->id, 'user_id' => $B->user_id,
    'visit_date' => $now->copy()->subDays(4)->format('Y-m-d'), 'visit_time' => '09:00',
    'workshop' => 'DEVCHECK Tahir Autos', 'purpose' => 'repair', 'status' => 'done',
    'done_at' => $now->copy()->subDays(4), 'done_by' => $MGR,
    'created_by' => $MGR, 'created_at' => $now->copy()->subDays(6), 'updated_at' => $now,
]);

echo "seeded:\n";
echo "  A = {$A->reg_no} (v{$A->id}, keeper {$A->user_id}) tickets $t1 (urgent/unanswered), $t2 (waiting on us), $t3 (waiting on rider)\n";
echo "  B = {$B->reg_no} (v{$B->id}, keeper {$B->user_id}) ticket $t4 + DONE visit $v 4 days ago\n";
