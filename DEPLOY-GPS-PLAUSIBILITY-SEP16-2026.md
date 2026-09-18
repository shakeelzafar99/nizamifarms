# DEPLOY — 🛰️ GPS: a fix outside Pakistan is not a fix (16-Sep-2026)

**NO SQL REQUIRED.** One OPTIONAL config row turns on Wi-Fi check-in (§4). Web first, then APK.

## 1. Web files (2) — upload, then `/api/public/xclean`
```
app/Services/LocationService.php                         isPlausibleFix · phantomFixMessage · officeForWifi
app/Http/Controllers/API/RiderController.php             heartbeat / check-in / delivery gates + Wi-Fi
```
No routes or views changed, but `/xclean` anyway (opcode cache on shared hosting).
**These two files alone stop the damage for every phone already in the field** — the old APK
keeps sending Beijing, the server now refuses it and tells the rider why (it already renders
`gps_warning`).

## 2. Mobile files (7) — `build-production-apk-auto.bat`
```
android/app/src/main/java/com/nizamifarmsmobile/LocationTrackingService.kt   GPS on · gate · metadata
src/utils/locationService.js       the box · quick/precise/attendance gates · phantom hand-back
src/services/locationHeartbeat.js  fix_age_s · device_model · phantom_fix report · no re-send
src/utils/locationHelper.js        wifi_bssid/ssid on check-in · isPhantom pass-through
src/screens/AttendanceScreen.js    the phantom dialog (retry / Wi-Fi se check in)
src/screens/OrderDetailsScreen.js  delivery fallback gated
__tests__/gpsPlausibility.test.js  (test only)
```

## 3. What changed, and why each piece exists

The incident (prod access log + `t_ops_rider_location`, 16-Sep): three riders' phones reported
**Tiananmen Square, Beijing** at 4–32 m "accuracy". A mislocated office Wi-Fi point started it;
the background tracker — `BALANCED_POWER_ACCURACY`, which **never switches the GPS on** — then
repeated the fix for 3½ h and carried it 10 km onto a delivery run, inflating accuracy 600 → 35,000 m
without ever asking a satellite. Farooq's check-in was stored as REMOTE 3,878 km away; two of
Rajab's deliveries were stamped in China. ⚠ The Riyadh rows on Sep-13 were the owner testing from
Saudi — not part of it.

| # | Item | Where | Effect |
|---|---|---|---|
| 1 | **GPS on while moving** | Kotlin | `PRIORITY_HIGH_ACCURACY`, 20 s / 10 m; after 10 min without 25 m of movement the *interval* stretches to 90 s — the priority never drops. Sends only on ≥30 m moved or ≥2 min elapsed, so the callback's higher rate does not multiply rows. |
| 2 | **The Pakistan box** (lat 23–37.5, lng 60.5–78.5) | Kotlin · JS · PHP — one rule, three copies | A fix outside it is NOT a fix: refused before it is sent, refused again if an old APK sends it. Phantom fixes never become `lastLocation`/`lastFix`, so nothing re-sends them. |
| 3 | **Fix metadata** | Kotlin · JS | `fix_age_s`, `provider`, `mocked`, `device_model` on every heartbeat; a refused fix is filed to `t_ops_location_failures` as `phantom_fix` with coordinates + handset. An unchanged fix is not re-sent (JS: 5 min; native: 30 m / 2 min). |
| 4 | **Server gates** | PHP | Heartbeat: phantom ⇒ failure row + `200 {stored:false, code:phantom_fix, gps_warning}` (a 4xx would make old APKs retry the lie); `fix_age_s > 900` ⇒ `stale_fix`, not stored as "now". Check-in: phantom is *no location* (mandatory ON ⇒ 422 `phantom_fix` in Roman Urdu; OFF ⇒ recorded without coordinates + `gps_warning`). Delivery: phantom coordinates dropped, delivery never blocked, customer never offered Beijing as a pin. |
| 5 | **Office presence by Wi-Fi** | JS · PHP | Check-in sends the connected `wifi_bssid`. If it matches a configured office router, a phantom, coarse or remote fix is settled as *present at that office* on the office's own coordinates. A **sharp** (≤50 m) fix elsewhere is never overridden. Dormant until configured. |

⚠ **Attendance strategy semantics:** every return passes through `finalizeAttendanceFix`, which
refuses a phantom and lets the next attempt run. Only if *nothing* plausible ever arrives is the
phantom handed back **flagged** (`isPhantom`, `isCoarse`, source `phantom`) — so the screen can say
what is wrong ("GPS ghalat jagah bata raha hai") and offer *Dobara koshish* / *Wi-Fi se check in*.
A 3,878 km distance is never shown and never confirmed.

## 4. OPTIONAL — turn on Wi-Fi check-in (one config row, no schema change)
Find each office router's **BSSID** (its MAC, `aa:bb:cc:dd:ee:ff`): router admin page → wireless
status, or a Wi-Fi analyser app on any phone standing next to it. Then:
```sql
INSERT INTO t_fin_config (config_key, config_value, created_at)
VALUES ('OFFICE_WIFI_BSSIDS', '{"9": ["aa:bb:cc:dd:ee:ff"], "10": ["11:22:33:44:55:66"]}', NOW());
```
Keys are `t_ops_company_locations.id` (9 = Abpara office, 10 = Ghori town …), values a list of
BSSIDs (a router with 2.4 + 5 GHz has two). Absent ⇒ feature dormant. To change: UPDATE the row.

## 5. Still yours, outside the code
- Rename the office Wi-Fi with a **`_nomap`** suffix so Google drops the router from its database.
- Send me the **phone models** of Rajab, Danish, Farooq, Haider vs two clean riders; from this build
  onward `device_model` arrives automatically with every phantom, so the next episode answers itself.
- Fix today's data: Farooq's 12:03 check-in row (remote, Beijing) and orders **SH-22883 / SH-22890**
  (`t_crm_order_status_history.delivery_latitude/longitude` = Beijing).

## 6. Proof
- `test_gps_plausibility.php` **41/0** (rolled back): box · heartbeat phantom/stale/real/old-APK ·
  check-in phantom, Wi-Fi vouch, coarse+Wi-Fi, remote-without-Wi-Fi stays remote, sharp-far not
  overridden · dormant when unconfigured · nothing left behind.
- Regression: `test_attendance_sync.php` 32/0 · `test_rider_visibility.php` 14/0 ·
  `test_workshop_visits.php` 180/0 · `test_handover_mobile.php` green.
- Mobile: `gpsPlausibility.test.js` **10/10** (box · quick · precise · the incident replayed at
  32 m · phantom handed back flagged · never "best seen" · heartbeat reports phantom · no re-send).
  Full suite **474/474** `--runInBand`. `:app:compilePrimaryDebugKotlin` **BUILD SUCCESSFUL**.
- Eslint: 0 new errors (the 5 reported are pre-existing hook-deps / dupe-key lines far from the edits).
⚠ Not driven on a device this round — no phone attached. Item 1 (battery, cadence) should be watched
on one rider's phone for a day after the APK goes out.

## 7. Second pass — every door that consumes a position (owner: "check check-ins, meter arming, delivery, Chinese devices")
Audited each flow after the build above; two more gaps closed, same files.

| Flow | Verdict | Change |
|---|---|---|
| **Check-in indoors** | OK. Attempt 1–3 (GPS) time out indoors, the sharpen loop refuses a phantom, attempt 4 (network) gives the real ~40–100 m office fix, which is within the 300 m radius ⇒ onsite. With `OFFICE_WIFI_BSSIDS` set, the Wi-Fi vouches even when the network fix lies. | none needed |
| **Meter arming at home (company bike)** | Phone side already goes through the attendance strategy ⇒ gated. Server `processHomeMeterSubmission` (both the check-in meter path and `submitHomeMeter`) judged the home fence against whatever arrived. | phantom ⇒ ignored, "no fix" ⇒ existing fallback to the home pin; failure row `home_meter` |
| **Check-out** (checkout rule "within 150 m of last delivery", home-journey arming, stored checkout position) | Three sub-steps read the request separately. | `dropPhantomFromRequest()` strips a phantom once at the top; failure row `checkout` |
| **Delivery button → "delivered at verified location?"** | Was the weakest: took the cached quick fix of ANY age, spent a precise attempt only if it was worse than 100 m. A stale-but-"accurate" cached read is precisely how the two China stamps happened. | new `locationService.getDeliveryFix()`: cached read used only if ≤30 s old AND ≤50 m; otherwise one bounded precise attempt (≤15 s old) first, fresher wins over tighter-but-stale; never blocks; phantoms refused. The 500 m verified-location check now reads a position taken AT the door. |
| **Chinese OEM battery killers** (MIUI/ColorOS/EMUI/Transsion) vs Samsung | Already handled and adequate: `backgroundReliability.js` asks for the battery-optimisation exemption + OEM autostart at check-in (capped nagging, manual ⚙️ retry), native foreground service with wake lock, AlarmManager ticks, WorkManager watchdog, FCM "defibrillator", boot restart. What was missing was not survival but *what the survivor asked for* — BALANCED never used GPS. Fixed by item 1. | none beyond item 1 |

Tests added: `getDeliveryFix` 5 cases (recent+sharp cached ⇒ used; stale ⇒ precise preferred; vague ⇒ precise; precise timeout ⇒ vague cached, never blocks; Beijing ⇒ null). Server §4b: check-out strip (phantom stripped, real untouched, none ⇒ nothing).
Totals now: `test_gps_plausibility.php` **47/0** · mobile `gpsPlausibility` **15/15**, full suite green · Kotlin compiled.

## 8. Third pass — crash surface + OEM snooze/kill (owner: "theoretically check from the code")
Read every path, not from memory. Two small hardenings added to `LocationTrackingService.kt`
(recompiled, BUILD OK); nothing else needed.

**Crash surface — verdict: no open crash paths found.**
- GPS *warm-up* (`warmUpGpsForAttendance`): in-flight + 30 s cooldown guards, permission check,
  both phases wrapped, phase 2 fire-and-forget with catch. `setRNConfiguration` (the historic cold-
  start crash) is deferred past the first frame with 3 backoff retries and never throws to the caller.
- Native service: `onStartCommand` catches the Android-14 background-start `SecurityException` and
  stops cleanly; `onTaskRemoved` self-restart wrapped; `onDestroy` releases wake lock/alarms/receiver.
- **Added:** the companion `startService()` — the ONE choke point behind JS, watchdog, boot and the
  FCM restart — now swallows `ForegroundServiceStartNotAllowedException`/`SecurityException` once,
  so no caller that forgot its own try/catch can crash the app.
- My new Kotlin: re-registering the request from inside the callback is legal on FLP;
  `location.isMock` is API-31 gated; `isFromMockProvider` suppressed-deprecation below it; phantom
  never assigned to `lastLocation`, so the 5-min timer cannot re-send it.
- JS: `Platform.constants` reads, `NetInfo.fetch('wifi')` (1.5 s raced), the phantom Alert and
  every new call are inside try/catch or optional-chained; nothing new can throw into a screen.

**OEM snooze/kill — verdict: as covered as a sideloaded app can be.** Layers already in place:
`backgroundReliability.js` (battery-optimisation exemption dialog + exact-alarm + OEM autostart deep
link for Xiaomi/Redmi/POCO/Oppo/Realme/OnePlus/Vivo/iQOO/Huawei/Honor/Meizu/Asus and Transsion
Infinix/Tecno/Itel; auto at check-in, ≤1/day, 5 lifetime, manual ⚙️ *Fix settings* always) ·
foreground service `foregroundServiceType="location"` + `stopWithTask=false` + `START_STICKY` +
partial wake lock · AlarmManager 5-min tick + retry alarms · WorkManager watchdog every 15 min
restarting when the heartbeat is >12 min old · `BootReceiver` · FCM "defibrillator" push. The gap
was never survival; it was that the survivor asked for `BALANCED` (no GPS) — fixed by item 1.
- **Added:** the stationary demotion (20 s → 90 s interval) is now also evaluated on the 5-min alarm
  tick. With `setMinUpdateDistanceMeters(10)` a phone lying still gets no callbacks at all, so the
  callback-only check would never have fired and the 20 s cadence would have held forever.
- ⚠ Two things the code cannot do, for the managers' settings round: on MIUI set *Battery saver →
  No restrictions* AND *Autostart ON* (the dialog we open); on Huawei/Honor *App launch → Manage
  manually* (all three toggles); on Samsung remove the app from *Sleeping apps* / add to *Never
  sleeping apps* (One UI's own list — our exemption dialog does not cover it). Also keep Android
  *Location → Wi-Fi scanning* OFF on riders' phones until the office SSID carries `_nomap`.
