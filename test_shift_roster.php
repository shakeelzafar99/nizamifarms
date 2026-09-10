<?php
/**
 * 👥 ONE COMMON LIST — the shift-planner roster (Sep-09 2026).
 *
 * Owner ruling: *"we should have one common list which is the attendance. taimur doesnt
 * need shifts he is exempt from it but others should follow."*
 *
 * Proves `User::shiftPlannerRoster()` — the ONE definition now shared by all three
 * shift-assign surfaces (web grid, mobile list, Shift Types bulk-assign) — and, above
 * all, proves the SAFETY NET is narrow enough not to undo the ruling.
 *
 * ⚠ LOCAL ONLY. It writes temporary rows and deletes them in §7 (and on any throw).
 * Run:  php test_shift_roster.php
 */

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use App\Models\User;

$pass = 0; $fail = 0;
function ok(string $what, $got, $want = null, bool $raw = false) {
    global $pass, $fail;
    $good = $raw ? (bool) $got : $got === $want;
    if ($good) { $pass++; echo "  ✓ $what\n"; }
    else { $fail++; echo "  ✗ $what\n";
           if (!$raw) echo "      got:  " . var_export($got, true) . "\n      want: " . var_export($want, true) . "\n"; }
}
function head(string $t) { echo "\n== $t ==\n"; }

/** Roster ids right now. */
function roster(): array { return User::shiftPlannerRoster()['ids']; }
function rosterOff(): array { return User::shiftPlannerRoster()['off_roster']; }
function uid(string $name): ?int {
    $id = DB::table('t_sys_user')->where('fullname', $name)->value('id');
    return $id ? (int) $id : null;
}

$today = now()->format('Y-m-d');
$tempUserIds = [];
$tempAssignIds = [];
$tempVisIds = [];

try {

// ─────────────────────────────────────────────────────────────────────────────
head('1. The ruling itself — attendance decides, the rider tick does not');

$shabib = uid('Shabib');
$taimur = uid('Taimur');

if ($shabib) {
    $tick = (int) DB::table('t_ops_rider_profile')->where('user_id', $shabib)->value('active');
    ok('Shabib is NOT a ticked delivery rider (so the old list excluded him)', $tick, 0);
    ok('Shabib IS on the roster (attendance-visible)', in_array($shabib, roster(), true), true);
} else { echo "  – Shabib not in this DB, skipped\n"; }

if ($taimur) {
    $vis = DB::table('t_ops_attendance_visibility')->where('user_id', $taimur)->value('is_visible');
    ok('Taimur is explicitly hidden from attendance', (int) $vis, 0);
    ok('Taimur is OFF the roster — exempt, as the owner asked', in_array($taimur, roster(), true), false);
}

// A ticked delivery rider who is HIDDEN from attendance must NOT be pulled in by the tick.
$tickedButHidden = DB::table('t_ops_rider_profile as p')
    ->join('t_sys_user as u', 'u.id', '=', 'p.user_id')
    ->join('t_ops_attendance_visibility as av', 'av.user_id', '=', 'u.id')
    ->where('p.active', 1)->where('u.is_active', 1)->where('av.is_visible', 0)
    ->pluck('u.id')->map(fn ($i) => (int) $i)->all();
foreach ($tickedButHidden as $id) {
    $n = DB::table('t_sys_user')->where('id', $id)->value('fullname');
    ok("ticked rider '$n' is hidden from attendance ⇒ off the roster", in_array($id, roster(), true), false);
}

// ─────────────────────────────────────────────────────────────────────────────
head('2. The roster IS the attendance list (no more, no less, safety net aside)');

$attendance = DB::table('t_sys_user as u')
    ->leftJoin('t_ops_attendance_visibility as av', 'av.user_id', '=', 'u.id')
    ->where('u.is_active', 1)
    ->where(fn ($q) => $q->whereNull('av.is_visible')->orWhere('av.is_visible', 1))
    ->pluck('u.id')->map(fn ($i) => (int) $i)->all();

sort($attendance);
$r = roster(); sort($r);
$extra = array_values(array_diff($r, $attendance));
$missing = array_values(array_diff($attendance, $r));

ok('nobody on the attendance list is missing from the roster', $missing, []);
ok('every extra name is explained by the safety net', array_values(array_diff($extra, rosterOff())), []);

// ─────────────────────────────────────────────────────────────────────────────
head('3. ⚠⚠ A stale open-ended PRIMARY row must NOT keep a hidden person on');
/**
 * This is the bug the first cut had. `effective_to IS NULL` describes EVERY person's
 * standing shift, so treating it as "live" made anyone ever scheduled immune to being
 * hidden — the ruling undone. Only a FUTURE change or a RUNNING cover counts.
 */
$staleOpen = DB::table('t_ops_user_shift_assignment as a')
    ->join('t_sys_user as u', 'u.id', '=', 'a.user_id')
    ->leftJoin('t_ops_attendance_visibility as av', 'av.user_id', '=', 'u.id')
    ->where('u.is_active', 1)->where('av.is_visible', 0)
    ->whereNull('a.effective_to')
    ->where(fn ($q) => $q->whereNull('a.effective_from')->orWhereDate('a.effective_from', '<=', $today))
    ->distinct()->pluck('u.id')->map(fn ($i) => (int) $i)->all();

if (!$staleOpen) { echo "  – no hidden person has a stale open row here; covered synthetically in §5\n"; }
foreach ($staleOpen as $id) {
    $n = DB::table('t_sys_user')->where('id', $id)->value('fullname');
    ok("stale open row does NOT resurrect hidden '$n'", in_array($id, roster(), true), false);
}

// ─────────────────────────────────────────────────────────────────────────────
head('4. Fixtures: a hidden user, to drive the safety net');

$mkUser = function (string $name) use (&$tempUserIds, &$tempVisIds) {
    $id = DB::table('t_sys_user')->insertGetId([
        'fullname' => $name, 'email' => strtolower(str_replace(' ', '', $name)) . '@roster.test',
        'password' => bcrypt('x'), 'is_active' => 1, 'created_by' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $tempUserIds[] = $id;
    DB::table('t_ops_attendance_visibility')->insert([
        'user_id' => $id, 'is_visible' => 0, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $tempVisIds[] = $id;
    return (int) $id;
};

$hidden = $mkUser('ZZ Roster Hidden');
ok('a hidden user with no shift state is off the roster', in_array($hidden, roster(), true), false);

$tplId = (int) DB::table('t_ops_shift_template')->value('id');
$mkAssign = function (int $userId, $from, $to) use (&$tempAssignIds, $tplId) {
    $id = DB::table('t_ops_user_shift_assignment')->insertGetId([
        'user_id' => $userId, 'shift_template_id' => $tplId,
        'effective_from' => $from, 'effective_to' => $to,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $tempAssignIds[] = $id;
    return (int) $id;
};

// ─────────────────────────────────────────────────────────────────────────────
head('5. The safety net keeps ONLY what a planner must be able to reach');

$a1 = $mkAssign($hidden, null, null);                                   // stale open primary
ok('stale open-ended primary row alone ⇒ still OFF', in_array($hidden, roster(), true), false);
DB::table('t_ops_user_shift_assignment')->where('id', $a1)->delete();

$a2 = $mkAssign($hidden, now()->subDays(30)->format('Y-m-d'), now()->subDay()->format('Y-m-d'));
ok('a cover that ENDED yesterday ⇒ still OFF', in_array($hidden, roster(), true), false);
DB::table('t_ops_user_shift_assignment')->where('id', $a2)->delete();

$a3 = $mkAssign($hidden, now()->subDays(2)->format('Y-m-d'), $today);
ok('a cover still running TODAY ⇒ ON (safety net)', in_array($hidden, roster(), true), true);
ok('  …and it is tagged off_roster so the UI can explain it', in_array($hidden, rosterOff(), true), true);
DB::table('t_ops_user_shift_assignment')->where('id', $a3)->delete();

$a4 = $mkAssign($hidden, now()->addDays(3)->format('Y-m-d'), null);
ok('a FUTURE primary change ⇒ ON (safety net)', in_array($hidden, roster(), true), true);
DB::table('t_ops_user_shift_assignment')->where('id', $a4)->delete();

ok('once the row is gone the person drops off by themselves', in_array($hidden, roster(), true), false);

// An INACTIVE account is never schedulable, safety net or not.
$a5 = $mkAssign($hidden, now()->addDays(3)->format('Y-m-d'), null);
DB::table('t_sys_user')->where('id', $hidden)->update(['is_active' => 0]);
ok('a DISABLED login with a future change is still OFF', in_array($hidden, roster(), true), false);
DB::table('t_sys_user')->where('id', $hidden)->update(['is_active' => 1]);
DB::table('t_ops_user_shift_assignment')->where('id', $a5)->delete();

// ─────────────────────────────────────────────────────────────────────────────
head('6. A new hire needs no setup — no visibility row means visible');

$fresh = DB::table('t_sys_user')->insertGetId([
    'fullname' => 'ZZ Roster Newhire', 'email' => 'zzrosternewhire@roster.test',
    'password' => bcrypt('x'), 'is_active' => 1, 'created_by' => 1, 'created_at' => now(), 'updated_at' => now(),
]);
$tempUserIds[] = $fresh;
ok('brand-new account with NO visibility row is on the roster', in_array((int) $fresh, roster(), true), true);
ok('  …and is not flagged as a safety-net row', in_array((int) $fresh, rosterOff(), true), false);

// ─────────────────────────────────────────────────────────────────────────────
head('7. Deploy-order safety + one shared definition');

ok('helper survives being called before the request table exists (guarded)', is_array(User::shiftPlannerRoster()), true);
ok('returns both keys the callers read', array_keys(User::shiftPlannerRoster()), ['ids', 'off_roster']);

$src = file_get_contents(__DIR__ . '/app/Http/Controllers/API/RiderController.php');
/**
 * ⚠ Scope these to the METHOD BODY, not the whole 30k-line file — an unscoped
 *   `getStoreShiftRiders.*?…` regex silently reaches into unrelated methods.
 */
$body = '';
if (preg_match('/public function getStoreShiftRiders.*?\n    \}\n/s', $src, $m)) $body = $m[0];
ok('found the getStoreShiftRiders body to assert on', $body !== '', true);
ok('mobile list draws the shared roster', str_contains($body, 'shiftPlannerRoster'), true);
ok('mobile list no longer INNER JOINs the rider profile', str_contains($body, "->join('t_ops_rider_profile"), false);
ok('mobile list no longer filters on the Delivery Rider tick', str_contains($body, "where('p.active', 1)"), false);
ok('mobile list still exposes is_rider (shown as a label, no longer a filter)', str_contains($body, 'is_rider'), true);

$web = file_get_contents(__DIR__ . '/app/Http/Controllers/Ops/ShiftPlannerController.php');
ok('web grid draws the same roster', str_contains($web, 'shiftPlannerRoster'), true);
// 9-Sep: the filter is GONE, not defaulted — the page always shows the one list.
ok('web grid has no rider/everyone filter left', str_contains($web, "input('filter'"), false);
ok('  …and ignores a stale ?filter from an old bookmark', str_contains($web, 'accepted and'), true);

$types = file_get_contents(__DIR__ . '/app/Http/Controllers/Ops/ShiftController.php');
ok('Shift Types bulk-assign draws the same roster', str_contains($types, 'shiftPlannerRoster'), true);

$blade = file_get_contents(__DIR__ . '/resources/views/pages/shifts/planner.blade.php');
ok('planner blade has no filter state at all', str_contains($blade, 'FILTER'), false);

$mob = file_get_contents(dirname(__DIR__) . '/NizamiFarmsMobile/src/screens/StoreShiftsScreen.js');
ok('mobile empty state no longer says "No delivery riders"', str_contains($mob, 'No delivery riders'), false);

// ─────────────────────────────────────────────────────────────────────────────
head('8. 👥 USERS LIST — the chips are gone and the list is editable by TWO people');
/**
 * Owner ruling 9-Sep: *"no need to differentiate between riders only and everyone …
 * instead we can show the customizable list here as well … this list is only for shabib
 * abd taimur to modify"*.
 */
ok('web: the Riders/Everyone chips are gone', str_contains($blade, "setFilter("), false);
ok('web: no FILTER state left behind', str_contains($blade, 'let FILTER'), false);
ok('web: the Users list button exists', str_contains($blade, 'rosterBtn'), true);
ok('⚠ web: #rosterModal is registered in the SCOPED css (else it renders unstyled)',
    str_contains($blade, '#rosterModal {') || str_contains($blade, ',#rosterModal)'), true);
ok('web: the modal writes through the ONE door', str_contains($blade, '/attendance/update-visibility'), true);
ok('mobile: the scope chips are gone', str_contains($mob, "scope === 'riders'"), false);
ok('mobile: the Users list button exists', str_contains($mob, 'canManageRoster'), true);
ok('mobile: its sheet is height-bounded (Android footer trap)', str_contains($mob, 'maxHeight: 380'), true);

$svc = app(\App\Services\Ops\ShiftAuthorityService::class);
$roster = app(\App\Services\Ops\UserRosterService::class);

$seeded = DB::table('t_sys_role_permissions')->where('permission_key', 'manage_user_roster')->exists();
if (!$seeded) {
    echo "  – manage_user_roster not seeded here; run user_roster_permission_sep2026.sql to cover §8b\n";
} else {
    head('8b. the gate itself');
    foreach (['Shabib' => true, 'Taimur' => true, 'Farooq' => false, 'Waseem' => false] as $who => $may) {
        $id = uid($who);
        if (!$id) { continue; }
        $u = \App\Models\User::find($id);
        ok(($may ? 'MAY' : 'may NOT') . " edit the users list: $who", $svc->canManageRoster($u), $may);
        ok('  …and the same answer on mobile: ' . $who, $svc->canManageRoster($u, true), $may);
    }
    // ⭐ The separation that matters: Farooq IS a planner (ladder rank 1) and still cannot
    //    change WHO EXISTS on the list. Moving shifts ≠ deciding who is schedulable.
    $farooq = uid('Farooq');
    if ($farooq) {
        ok('⭐ a planner on the ladder still cannot edit the list', $svc->canManageRoster(\App\Models\User::find($farooq)), false);
        ok('  …though he is on the ladder', $svc->rankOf($farooq) > 0, true);
    }
}

head('9. the list writes once, and moves BOTH screens');

$before = count(roster());
$qasim = DB::table('t_sys_user as u')->leftJoin('t_ops_attendance_visibility as av', 'av.user_id', '=', 'u.id')
    ->where('u.is_active', 1)->where('av.is_visible', 0)
    ->whereNotIn('u.id', roster())->value('u.id');

if (!$qasim) { echo "  – nobody is currently off the list; §9 skipped\n"; }
else {
    $qasim = (int) $qasim;
    $name = DB::table('t_sys_user')->where('id', $qasim)->value('fullname');
    $roster->set($qasim, true, 1);
    ok("adding '$name' puts him on the planner roster", in_array($qasim, roster(), true), true);
    ok('  …and the roster grew by exactly one', count(roster()), $before + 1);

    // The attendance screen reads the SAME flag — that is the owner's whole point.
    $inAttendance = DB::table('t_sys_user as u')
        ->leftJoin('t_ops_attendance_visibility as av', 'av.user_id', '=', 'u.id')
        ->where('u.id', $qasim)
        ->where(fn ($q) => $q->whereNull('av.is_visible')->orWhere('av.is_visible', 1))
        ->exists();
    ok('  …and onto ATTENDANCE, from the one same toggle', $inAttendance, true);

    $roster->set($qasim, false, 1);
    ok('removing him puts the roster back', count(roster()), $before);
    ok("  …and '$name' is off the planner again", in_array($qasim, roster(), true), false);
}

head('10. ⚠ taking someone off who still has a shift change pending');

$tplId2 = (int) DB::table('t_ops_shift_template')->value('id');
$onList = roster()[0] ?? null;
if ($onList && $tplId2) {
    ok('no warning for a clean person', $roster->removalWarning((int) $onList), null);
    $aid = DB::table('t_ops_user_shift_assignment')->insertGetId([
        'user_id' => (int) $onList, 'shift_template_id' => $tplId2,
        'effective_from' => now()->addDays(4)->format('Y-m-d'), 'effective_to' => null,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $warn = $roster->removalWarning((int) $onList);
    ok('a future change warns before removal', is_string($warn) && $warn !== '', true);
    ok('  …and says they stay on the planner meanwhile', str_contains((string) $warn, 'not in attendance'), true);
    DB::table('t_ops_user_shift_assignment')->where('id', $aid)->delete();
    ok('warning clears once the change is gone', $roster->removalWarning((int) $onList), null);
}

} finally {
    // ── cleanup, always ──────────────────────────────────────────────────────
    if ($tempAssignIds) DB::table('t_ops_user_shift_assignment')->whereIn('id', $tempAssignIds)->delete();
    if ($tempUserIds) {
        DB::table('t_ops_user_shift_assignment')->whereIn('user_id', $tempUserIds)->delete();
        DB::table('t_ops_attendance_visibility')->whereIn('user_id', $tempUserIds)->delete();
        DB::table('t_sys_user')->whereIn('id', $tempUserIds)->delete();
    }
    echo "\n  (cleaned up " . count($tempUserIds) . " temp users)\n";
}

echo "\n────────────────────────────\n";
echo "PASS $pass   FAIL $fail\n";
exit($fail ? 1 : 0);
