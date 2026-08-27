<?php
/**
 * Manager-side door, in its OWN process — the suite's standing rule is that only one
 * user may be logged in per process (guard leakage), so switching users mid-script
 * silently leaves the first one authenticated.
 *
 * Proves: a manager can SEE and FILE Rajab's own-bike per-km claim for 2026-08-23,
 * the day his attendance meters were the VAN's.
 *
 * The filing runs inside a transaction that is ALWAYS rolled back.
 * Usage:  php probe_manager_files.php [user_id]
 */
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\Riders\FuelClaimRules;
use App\Services\Riders\VehicleResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

const RAJAB = 95;
const VAN = 4;
const OWNBIKE = 9;
const CAT_EXPENSE = 3;
const DAY = '2026-08-23';

$who = (int) ($argv[1] ?? 79);

$pass = 0; $fail = 0;
function ok(string $what, $got, $want) {
    global $pass, $fail;
    $good = $got === $want; $good ? $pass++ : $fail++;
    echo ($good ? "  [PASS] " : "  [FAIL] ") . $what . "\n";
    if (!$good) { echo "         got:  " . var_export($got, true) . "\n         want: " . var_export($want, true) . "\n"; }
}

$u = \App\Models\SysAdmin\UserModel::find($who);
Auth::guard('web')->loginUsingId($who);
echo "Acting as: #{$who} {$u->fullname} <{$u->email}>\n";
echo "auth()->id() actually resolves to: " . var_export(auth()->id(), true) . "\n";
$roles = DB::table('t_sys_user_role as ur')->join('t_sys_role as r','r.id','=','ur.role_id')
    ->where('ur.user_id',$who)->pluck('r.urole_name','r.type')->toArray();
echo "roles: " . json_encode($roles) . "\n";

$att = (int) DB::table('t_ops_attendance')->where('user_id',RAJAB)->where('attendance_date',DAY)->value('id');

echo "\n== SEE: the New-petrol modal ==\n";
$vc  = new \App\Http\Controllers\CRM\VehicleController();
$req = Request::create('/x','GET',['user_id'=>RAJAB,'date'=>DAY]);
$req->setUserResolver(fn () => $u);
$ctx = $vc->petrolContext($req, app(VehicleResolver::class))->getData(true);
ok('the modal answers', (bool)($ctx['success'] ?? false), true);
ok('  …offering both machines', count($ctx['vehicles'] ?? []), 2);
foreach ($ctx['vehicles'] ?? [] as $v) {
    printf("     %-14s %5s km  per-km claimable: %-5s  suggested: %-6s  existing claim: %s\n",
        $v['label'], $v['km'], $v['can_meter_claim'] ? 'YES' : 'no',
        $v['suggested_amount'] ?? '-',
        $v['claim'] ? ('#'.$v['claim']['id'].' Rs '.$v['claim']['amount'].($v['claim']['metered']?' metered':' CASH')) : 'none');
}

echo "\n== FILE: the manager raises it on Rajab's behalf (rolled back) ==\n";
DB::beginTransaction();
try {
    $before = DB::table('t_req_master')->where('requester_user_id',RAJAB)->where('expense_category','Petrol')->count();
    $rc  = new \App\Http\Controllers\Request\RequestController();
    $req = Request::create('/requests','POST',[
        'category_id'       => CAT_EXPENSE,
        'title'             => 'Expense Reimbursement',
        'expense_category'  => 'Petrol',
        'requester_user_id' => RAJAB,
        'expense_date'      => DAY,
        'amount'            => 133,
        'attendance_id'     => $att,
        'meter_distance'    => 14,
        'petrol_rate'       => 9.5,
        'vehicle_id'        => OWNBIKE,
        'description'       => 'PROBE own bike on a van day',
    ]);
    $req->setUserResolver(fn () => $u);
    $code = 0; $body = null;
    try {
        $resp = $rc->store($req);
        $code = method_exists($resp,'getStatusCode') ? $resp->getStatusCode() : 0;
        $body = json_decode(method_exists($resp,'getContent') ? $resp->getContent() : '{}', true);
    } catch (\Throwable $e) { $body = ['exception'=>get_class($e),'message'=>$e->getMessage()]; }

    $after = DB::table('t_req_master')->where('requester_user_id',RAJAB)->where('expense_category','Petrol')->count();
    ok('the filing is accepted', $after === $before + 1, true);
    if ($after === $before + 1) {
        $new = DB::table('t_req_master')->where('requester_user_id',RAJAB)
            ->where('expense_category','Petrol')->orderByDesc('id')->first();
        ok('  …a METERED row, not an untethered cash one',
            [(float)$new->meter_distance, (int)$new->attendance_id], [14.0, $att]);
        ok('  …stamped to HIS bike, never the van', (int)$new->vehicle_id, OWNBIKE);
        ok('  …priced Rs 133', (float)$new->amount, 133.0);
        printf("     filed %s  vehicle=%s  Rs %s  attendance=%s  created_by=%s  status=%s\n",
            $new->request_number, $new->vehicle_id, $new->amount, $new->attendance_id, $new->created_by, $new->status);
        $c = app(FuelClaimRules::class)->checkMeteredPetrol(RAJAB, DAY, 14.0, $att, OWNBIKE, null);
        ok('  …a second claim on that machine+day is now refused', (bool)($c['ok'] ?? true), false);
        // And the rider's own phone must now show it as claimed.
        $rowNow = DB::table('t_req_master')->where('id',$new->id)->first();
        ok('  …and it names the attendance day so the rider screen can see it',
            (int)$rowNow->attendance_id, $att);
    } else {
        echo "     http=$code body=" . json_encode($body) . "\n";
    }
} finally { DB::rollBack(); }

ok('rolled back — replica clean',
   DB::table('t_req_master')->where('description','PROBE own bike on a van day')->count(), 0);

echo "\n" . ($fail === 0 ? "ALL GREEN — passed $pass, failed 0\n" : "FAILURES — passed $pass, failed $fail\n");
