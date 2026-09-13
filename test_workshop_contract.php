<?php
/**
 * 🤝 THE CLIENT ↔ SERVER CONTRACT (11-Sep-2026).
 *
 * ⚠⚠ THIS SUITE EXISTS BECAUSE OF THE 11-Sep INCIDENT. The Sep-10 round shipped a server that
 *    asked a question (`409 needs_confirmation` + a `confirm` flag) that NO client had been
 *    taught to answer — and the only test of it asserted the dead end as correct. The store
 *    tablet read "Partial Update — Failed to assign rider" and the order could not be given to
 *    the man at all.
 *
 *    The lesson, written as a test: every field one side WRITES, the other side must READ, and
 *    under the same name. This walks the actual source files and checks exactly that. It is
 *    deliberately crude — string matching, no framework — because its whole value is that it
 *    fails when someone renames a key on one side only.
 *
 * Run:  php test_workshop_contract.php
 */
$pass = 0; $fail = 0;
function ok(string $what, $got, $want = null, bool $raw = false) {
    global $pass, $fail;
    $good = $raw ? (bool) $got : $got === $want;
    if ($good) { $pass++; echo "  ✓ $what\n"; }
    else {
        $fail++; echo "  ✗ $what\n";
        if (!$raw) { echo "      got:  " . var_export($got, true) . "\n";
                     echo "      want: " . var_export($want, true) . "\n"; }
    }
}
function section(string $t) { echo "\n== $t ==\n"; }

$WEB = __DIR__;
$APP = dirname(__DIR__) . '/NizamiFarmsMobile';

$read = function (string $p) {
    $s = @file_get_contents($p);
    if ($s === false) { echo "      ⚠ missing file: $p\n"; return ''; }
    return $s;
};

$riderCtrl   = $read($WEB . '/app/Http/Controllers/API/RiderController.php');
$orderRider  = $read($WEB . '/app/Http/Controllers/CRM/OrderRiderController.php');
$wsCtrl      = $read($WEB . '/app/Http/Controllers/CRM/WorkshopVisitController.php');
$wsSvc       = $read($WEB . '/app/Services/Riders/WorkshopVisitService.php');
$apiRoutes   = $read($WEB . '/routes/api.php');
$webRoutes   = $read($WEB . '/routes/web.php');
$ordersBlade = $read($WEB . '/resources/views/pages/orders/index.blade.php');
$fleetBlade  = $read($WEB . '/resources/views/pages/riders-map/partials/fleet.blade.php');

$storeOrders = $read($APP . '/src/screens/StoreOpenOrdersScreen.js');
$ordersScr   = $read($APP . '/src/screens/OrdersScreen.js');
$riderHeader = $read($APP . '/src/components/RiderDashboardHeader.js');
$arrivalSheet= $read($APP . '/src/components/WorkshopArrivalSheet.js');
$outcome     = $read($APP . '/src/components/WorkshopOutcomePrompt.js');
$approval    = $read($APP . '/src/components/WorkshopApprovalBanner.js');
$tracker     = $read($APP . '/src/screens/DispatchTrackerScreen.js');
$nav         = $read($APP . '/src/navigation/index.js');

// ─────────────────────────────────────────────────
section('§1 ⚠⚠ the assign guard no longer REFUSES — on either door');

ok('mobile assign does not return needs_confirmation any more',
   str_contains($riderCtrl, "'needs_confirmation' => true,\n                        'workshop_trip'"), false);
ok('web assign does not either',
   str_contains($orderRider, "'needs_confirmation' => true"), false);
ok('⭐ mobile assign emits a `warning` instead',
   str_contains($riderCtrl, "'warning' => \$wsWarning"), true);
ok('⭐ web assign emits a `warning` instead',
   str_contains($orderRider, "'warning' => \$wsWarning"), true);
ok('both read ONE engine (warningFor)',
   str_contains($riderCtrl, 'workshopWarningFor') && str_contains($orderRider, '->warningFor('), true);
ok('…which exists on the service', str_contains($wsSvc, 'public function warningFor('), true);

// ─────────────────────────────────────────────────
section('§2 ⭐ …and every client READS that warning');

ok('the store phone reads response.data.warning',
   str_contains($storeOrders, 'response.data.warning'), true);
ok('  …on the Save-Order-Details path too (the reported failure)',
   str_contains($storeOrders, 'pendingWorkshopWarning'), true);
ok('  …and on the bulk path', str_contains($storeOrders, "w.kind === 'workshop'"), true);
ok('  …through ONE renderer', str_contains($storeOrders, 'const showWorkshopWarning'), true);
ok('the web orders page reads it too',
   str_contains($ordersBlade, "result.warning.kind === 'workshop'")
   && str_contains($ordersBlade, "aJson.warning.kind === 'workshop'"), true);
ok('⚠ the phone prints the server\'s Roman-Urdu sentence, not its own',
   str_contains($storeOrders, 'label_ur'), true);
ok('  …and the server sends that key', str_contains($wsSvc, "'label_ur'"), true);

// ─────────────────────────────────────────────────
section('§3 ⭐⭐ DISPATCH is the stop — and the override is REACHABLE');

ok('the server guards calculateDeliveryEtas',
   str_contains($riderCtrl, 'Dispatch held: rider is on a workshop trip'), true);
ok('  …and honours `confirm`', str_contains($riderCtrl, "!\$request->boolean('confirm')"), true);
ok('  …recording an override when used',
   str_contains($riderCtrl, 'Dispatch OVERRIDDEN during a workshop trip'), true);
ok('⭐ the rider screen SENDS confirm', str_contains($ordersScr, 'confirmWorkshop'), true);
ok('  …under the name the server reads',
   str_contains($ordersScr, 'confirm: 1'), true);
ok('⭐ the pinned-rider header sends it too', str_contains($riderHeader, 'confirmWorkshop'), true);
ok('  …under the same name', str_contains($riderHeader, 'confirm: 1'), true);
ok('⚠ the header answers a THROWN 409 (axios rejects 4xx)',
   str_contains($riderHeader, 'error.response?.status === 409'), true);

// ─────────────────────────────────────────────────
section('§4 📍 the self-pinning workshop: routes, fields, both ends');

foreach ([['arrived-here', 'apiArrivedHere'], ['not-here', 'apiNotHere'], ['snooze', 'apiSnooze']] as [$path, $fn]) {
    ok("route /workshop-visits/{id}/$path exists", str_contains($apiRoutes, $path), true);
    ok("  …wired to $fn", str_contains($wsCtrl, "function $fn("), true);
}
ok('⭐ the server sends `arrival_prompt` on the polled endpoint',
   str_contains($wsCtrl, "'arrival_prompt' =>"), true);
ok('⭐ …and the sheet READS it', str_contains($arrivalSheet, 'arrival_prompt'), true);
ok('  …from that same endpoint', str_contains($arrivalSheet, '/rider/workshop-visits/outcome'), true);
ok('  …posting to arrived-here', str_contains($arrivalSheet, '/arrived-here'), true);
ok('  …and to not-here on a NO', str_contains($arrivalSheet, '/not-here'), true);
ok('⚠ dismissing takes the same path as "no" (silence is never yes)',
   str_contains($arrivalSheet, 'onRequestClose={decline}'), true);
/**
 * ⚠⚠ THE ANTI-STUCK PROPERTY, stated as a test. The sheet must keep NO durable memory of its
 *    own about whether to ask — no AsyncStorage "seen" flag, no persisted dismissal. Every
 *    such flag is a second source of truth that drifts from the server's, and drift is how
 *    these boxes end up either nagging forever or never appearing again. The server owns the
 *    cap and the cooldown; this file only draws what it is sent.
 */
ok('⚠⚠ the sheet stores NO local "seen" state (the anti-stuck rule)',
   str_contains($arrivalSheet, 'AsyncStorage'), false);
ok('  …and decides nothing itself — no local ask counter',
   str_contains($arrivalSheet, 'askCount') || str_contains($arrivalSheet, 'setAsked'), false);
ok('⭐ the sheet is actually MOUNTED', str_contains($nav, '<WorkshopArrivalSheet />'), true);
ok('  …and imported', str_contains($nav, "from '../components/WorkshopArrivalSheet'"), true);

// ─────────────────────────────────────────────────
section('§5 🔧 the close dialog: every job, and a photo with no amount');

ok('the server offers EVERY active job to the close dialog',
   str_contains($wsCtrl, '->typesForClose('), true);
ok('  …via a per-visit endpoint the desk can call',
   str_contains($wsCtrl, 'function visitTypes(') && str_contains($webRoutes, 'workshop/{id}/types'), true);
ok('  …and the web dialog fetches it', str_contains($fleetBlade, "'/types'") || str_contains($fleetBlade, "/types'"), true);
ok('⭐ the web dialog can now send a photo', str_contains($fleetBlade, "fd.append('photo'"), true);
ok('  …and an amount + source', str_contains($fleetBlade, "fd.append('amount'"), true);
ok('⭐ the rider prompt can send a photo', str_contains($outcome, "fd.append('photo'"), true);
ok('  ⚠ …with NO amount required (multipart when a photo exists)',
   str_contains($outcome, "didHappen && photo?.uri"), true);
ok('the server accepts `photo` on done()', str_contains($wsCtrl, "'photo'                     => 'nullable|image"), true);
ok('  …and on the Bikes record path', str_contains($read($WEB . '/app/Http/Controllers/CRM/FleetFuelController.php'), "'photo'      => 'nullable|image"), true);
ok('⭐ the type rows carry counts_down for the pickers',
   str_contains($read($WEB . '/app/Services/Riders/ServiceRecordService.php'), "'counts_down' => \$counts"), true);
ok('  …and the phone reads it', str_contains($outcome, 'counts_down'), true);

// ─────────────────────────────────────────────────
section('§6 ⏰ the snooze, and the tracker rungs');

ok('the server exposes snooze', str_contains($wsSvc, 'public function snoozeProposal('), true);
ok('  …filters the queue by it', str_contains($wsSvc, 'minusSnoozed'), true);
ok('⭐ the banner calls it', str_contains($approval, "/snooze"), true);
ok('  …with a visible button', str_contains($approval, 'Baad mein'), true);
ok('⚠⚠ the dispatch tracker knows the two workshop rungs',
   str_contains($tracker, 'workshop_en_route') && str_contains($tracker, 'at_workshop'), true);
ok('  …and prints the server\'s sentence', str_contains($tracker, 'r.workshop_trip'), true);

// ─────────────────────────────────────────────────
section('§7 ⚠ the rider picker tags him but never hides him');

ok('the server tags the picker', str_contains($riderCtrl, "\$r->workshop_trip = (\$t && !empty(\$t['is_active']))"), true);
ok('⚠ …and does NOT filter him out of the list',
   str_contains($riderCtrl, 'workshop') && str_contains($riderCtrl, '$riders = $riders->map('), true);

// ─────────────────────────────────────────────────
section('§8 ⚠⚠ the nine items that were PLANNED and nearly shipped unbuilt');

/**
 * ⚠⚠ EVERY ONE OF THESE WAS WRITTEN INTO THE ROUND-2 PLAN AND WAS STILL NOT BUILT WHEN THE
 *    WORK WAS FIRST CALLED FINISHED. That is the same failure mode as the Sep-10 round, one
 *    layer up: a plan is not an implementation. They are asserted here so "it is in the plan"
 *    can never again be mistaken for "it is in the app".
 */
$fleetScr = $read($APP . '/src/screens/FleetScreen.js');
$fleetVeh = $read($APP . '/src/components/FleetVehicles.js');
$myVeh    = $read($APP . '/src/screens/VehicleProfileScreen.js');
$roleBan  = $read($APP . '/src/components/RoleAlertBanners.js');
$wsAlerts = $read($WEB . '/resources/views/partials/workshop-alerts.blade.php');
$fireSvc  = $read($WEB . '/app/Services/FirebaseService.php');

ok('1. web history shows the photo', str_contains($fleetBlade, 's.photo_url'), true);
ok('2. mobile history shows the photo', str_contains($fleetVeh, 's.photo_url'), true);
ok('3. the mobile manager close sheet takes a photo', str_contains($fleetScr, 'pickWsPhoto'), true);
ok('   …and can post multipart', str_contains($fleetScr, 'multipart = false'), true);
ok('   ⚠ …and its job picker no longer filters on a countdown',
   str_contains($fleetScr, 'has_schedule || Number(t.interval_km)'), false);
ok('4. the web planner has a Later button', str_contains($fleetBlade, 'flWorkshopSnooze'), true);
ok('5. the web has the manager "He has gone" button', str_contains($fleetBlade, 'flWorkshopDepart'), true);
ok('6. …and "He is there" for an unpinned workshop', str_contains($fleetBlade, 'flWorkshopArrivedHere'), true);
ok('   …served by a manager path that pins NOTHING', str_contains($wsSvc, 'confirmArrivalByManager'), true);
ok('   ⚠ …which refuses a workshop that IS pinned (a geofence must stay the proof)',
   str_contains($wsSvc, 'is pinned, so his arrival is detected automatically'), true);
ok('7. the web corner lists who is away right now', str_contains($wsAlerts, 'renderLive'), true);
ok('   …fed by the server', str_contains($wsCtrl, "'live_trips'"), true);
ok('8. the trip banner sends store staff to Open Orders, not Bikes',
   str_contains($roleBan, "navigate('OpenOrders')"), true);
ok('   ⚠ …with useAppMode actually imported (it would throw on tap)',
   str_contains($roleBan, 'import {useAppMode}'), true);
ok('9. My Vehicle shows his own trip line', str_contains($myVeh, 'workshop.trip_label'), true);
ok('   …and the server sends it', str_contains($wsSvc, "\$r['trip_label']"), true);
ok('the approval banner refetches on resume', str_contains($approval, 'AppState.addEventListener'), true);
ok('  ⚠⚠ …and NO blank-notification push was shipped to do it',
   str_contains($fireSvc, "'event' => 'decided'"), false);

echo "\n────────────────────────────────────────────────────────────\n";
echo $fail === 0 ? "ALL GREEN — passed $pass, failed 0\n" : "passed $pass, FAILED $fail\n";
exit($fail === 0 ? 0 : 1);
