# Workshop day — propose → planner approves → rider told (6-Sep-2026)

> ## ✅ BUILT 6-Sep-2026 — not yet uploaded. See §7 at the bottom for what shipped, what
> changed from this plan while building it, and the deploy order.



> Owner + team ruling, 6-Sep: Qasim books a workshop day, but it must **not** reach the rider
> automatically. It goes to the shift planners (Shabib · Farooq · Taimur) as a banner; **one of
> them approves**; only then is the rider told — clearly, that it is a **location change for
> attendance, not a time change**. If a planner raises it himself, ask *"assign now, or send for
> approval?"*. The booking form must show the registered workshops and let a manager **add one
> inline**, on web and on the phone, without leaving the form; the Locations page stays the
> other door.

Everything below reuses what exists. Verified facts it rests on (5-Sep rounds):
- A booking today = a visit row **+ a one-day row in `t_ops_user_shift_assignment`** (rider's own
  template, workshop as `location_id`, `workshop_visit_id`) written by
  `WorkshopVisitService::applyShiftLocation()`. That pin is what `processCheckinLocation` →
  `ShiftResolutionService::getUserShift()` → `calculateDistanceFromBase()` reads — proven: at the
  workshop's own coordinates `is_remote=false` with the pin, `true` without.
- **There is no separate "workshop shift" and there must not be one.** The rider's shift is kept;
  approval only fixes *where* (and, optionally, *when*) for that one day.
- Planners = holders of **`manage_shifts`** (today: Shabib, Farooq, Taimur — mobile permission).
  Bookers = `schedule_workshop` (Qasim, Shabib, Taimur). Shabib and Taimur are BOTH, which is
  exactly the "ask him" case.
- A web location **requires latitude/longitude** (`CompanyLocationsController::store`). The phone
  has **no** add-location endpoint today (`/rider/locations/create` is a rider-phone form).
- On the replica **zero** locations are ticked as workshop → the picker offers nothing → nothing
  is pinned. Danish's 6-Sep booking on prod shows the same symptom (visit 09:00, shift still
  09:30 LaCarne). This plan makes that state impossible to reach silently.

---

## 1 · The visit's life (one state machine, one table)

| status | meaning | who moves it |
|---|---|---|
| **`proposed`** *(new)* | booked, **rider NOT told**, no pin | booker without `manage_shifts`, or a planner who chose "send for approval" |
| `scheduled` | approved (or assigned directly), **pin written, rider told** | planner approves · planner books "assign now" |
| `accepted` | rider confirmed | rider (or a manager on his behalf) |
| **`declined`** *(new)* | planner said no, reason kept, **booker told** | planner |
| `rescheduled` / `cancelled` / `done` / `not_done` | as today | as today |

Columns to add (**1 SQL**): `proposed_by`, `approved_by`, `approved_at`, `declined_by`,
`declined_at`, `decline_reason` (varchar 255). `status` is already varchar(20) — no ALTER.

⚠ **`proposed` is NOT a LIVE status.** `LIVE_STATUSES` stays `['scheduled','accepted']`, so every
rider-facing reader — `nextForUser`, the My Vehicle brief, the Attendance banner, `dueReminders`,
the day-of *"Workshop aaj — ho gaya?"* prompt, `mapForRange` for the planner's *pin* — ignores a
proposal by construction. **That is also what keeps old APKs safe**: a phone that has never heard
of `proposed` cannot show it, because it is never sent a proposed visit as "his".

## 2 · Booking (Qasim, or a planner)

`WorkshopVisitService::schedule()` gains one decision, made server-side from the caller's rights:
- caller lacks `manage_shifts` → **always `proposed`**; no pin; no rider push.
- caller has `manage_shifts` → the form (web modal + phone sheet) shows a two-way choice:
  **"Assign now"** (today's behaviour: `scheduled`, pin, rider told) or **"Send for approval"**
  (`proposed`). Server validates: a non-planner sending `assign_now=1` is refused, not trusted.
- `warningsFor()` (off day · holiday · leave) is returned on the proposal and **shown again to the
  approver** — the planner is the person who should see "that is his day off".
- Supersede rule unchanged: a second booking on the same bike replaces the first, whatever its
  state; if the first was `scheduled`, its pin is cleared as today.

**Locations in the form (both surfaces):** radio list of `is_workshop=1` locations, plus
**"➕ Add a workshop"** inline — name, radius, and **coordinates**, because a workshop without
them cannot be a check-in point. Web: the modal grows a sub-panel (name · Google-Maps link *or*
lat/lng; ⚠ a Maps *place* URL carries NO coordinates — see the Sep-1 trap; accept only URLs with
`@lat,lng` / `q=lat,lng` or explicit numbers). Phone: name + **"Use my current location"** (Qasim is
usually standing at the workshop) + radius. Both post to ONE new
`CompanyLocationsService::createWorkshop()` that the web Locations page also calls — one writer.
New API route `POST /rider/locations` (gate: `schedule_workshop` OR `manage_shifts`). The new
location is `is_workshop=1`, `is_active=1`, `is_primary=0`, and is selected in the form on return.
⚠ If no workshop is chosen and none typed, the form says so; a typed name is still allowed but
the form states plainly: *"typed names cannot be pinned — he will check in at his normal place"*.

## 3 · Approval (Shabib · Farooq · Taimur)

**Where they see it:**
- **Web banner** — extend `partials/workshop-alerts` (already on the planner page and Bikes) with a
  *"⏳ awaiting your approval"* row per proposal: bike · rider · day · time · workshop · booked by ·
  warnings. Buttons **Approve · Adjust · Decline**, inline forms via `flForm()`.
- **Shift planner cell** — the proposal draws as a distinct chip `⏳ proposed 09:00` (today's chip is
  for scheduled/accepted only) **and a badge under the rider's name** (the phone's Store→Shifts
  screen already does the name badge — copy it, it is the better placement). Approve/Adjust/Decline
  from the cell too.
- **Phone** — a `RoleAlertBanners` entry for `manage_shifts` holders: *"1 workshop day awaiting
  approval"* → the visit, with the same three actions.
- **Push** — `notifyWorkshopVisit('proposed')` → `manage_shifts` group minus the proposer. New
  event; the group sender already excludes the actor.

**What approving does** (`WorkshopVisitService::approve($user, $visitId, $in)`):
1. gate `manage_shifts`; status must be `proposed`;
2. **Adjust** may change: workshop location (from the ticked list), visit time, and — the case
   Danish's 09:00-vs-09:30 exposed — **the rider's start time that day** by choosing a shift
   template for the one-day row (the planner's own temporary-change semantics; verify against
   `ShiftController::assignShiftToUser` and reuse its writer rather than a second one);
3. `status = scheduled`, `approved_by/at`, then **`applyShiftLocation()`** — the same pin as today,
   now simply gated behind approval;
4. push the **rider** (below), and push the **booker**: *"Shabib approved DCR-799's workshop day"*;
5. writes the ticket system line if the visit is linked to a ticket (as today).

**Declining** sets `declined`, keeps the reason, pushes the booker with it; the proposal leaves the
planners' banner; Qasim can re-propose (a new visit). Nothing is pinned, the rider never knew.

**Escalation without a cron** (prod has none): `dueReminders()`-style, from the alerts poll —
a proposal for **tomorrow still unapproved at 17:00** re-pushes the planners once; a proposal
still unapproved **on the morning of the day** is auto-declined with reason *"not approved in
time"* and the booker told — so a rider is never sent nowhere by silence.

## 4 · What the rider is told (and when)

Only on **`scheduled`** (approval or assign-now). Copy is Roman Urdu and says exactly what changed:

> *🔧 Kal aap ki shift **LaCarne Workshop** par hai — time wahi **09:30**. Jagah badal gayi hai,
> wahan check-in karein. Bike: DCR-799.*

(with a time change: *"time 09:00 aur jagah LaCarne Workshop"*). The Attendance banner text changes
to the same wording for the day before and the day itself; the ⏳/✓ acknowledgement stays (it drives
the day-of prompt and the reminder), but nothing waits on it.

**Push map (final):**

| event | rider | booker | planners (`manage_shifts`) | assigned mechanic |
|---|---|---|---|---|
| proposed | — | — | all, minus proposer | — |
| approved / assigned now | ✓ (Roman Urdu) | ✓ ("approved by …") | — | — |
| declined | — | ✓ with reason | — | — |
| rider accepted | — | ✓ | approver | — |
| moved | ✓ | ✓ | — | — |
| cancelled | ✓ if he had been told | ✓ | if it was still proposed | — |
| reminder (day before, `scheduled`/`accepted` only) | ✓ | — | group (as today) | — |
| done | ✓ *(new — today nobody is told)* | ✓ | — | — |

## 5 · Handover, tickets, and the machine's page

- `onHandover()` (round 20/21): a **proposed** visit follows the bike like a live one — re-pointed
  to the new holder, still `proposed`; a `scheduled` one moves with its pin as today.
- Vehicle panel (web + phone) Workshop block shows the state plainly: *⏳ awaiting Shabib/Farooq/
  Taimur* · *✓ scheduled, rider told* · *✓✓ accepted* — and offers Approve/Adjust/Decline to a
  planner, Cancel to the booker.
- Ticket row "Schedule workshop" keeps the vehicle-first call; the result now reads
  *"sent for approval"* when it went that way.

## 6 · Order of work + proof

| step | files | proof |
|---|---|---|
| 1 SQL: 6 columns | `workshop_approval_sep2026.sql` | idempotent, replica rehearsal |
| 2 states + `approve/decline/escalate` + booking decision | `WorkshopVisitService`, `WorkshopVisitController` | `test_workshop_visits.php` §new: non-planner → proposed & no pin & no rider push; planner assign-now → scheduled+pin+push; planner send-for-approval → proposed; approve → pin + rider push + booker push; adjust time → one-day template row; decline → reason + booker push; old-APK reader never sees proposed; 17:00 nudge once; morning auto-decline |
| 3 push events | `FirebaseService::notifyWorkshopVisit` | recipients asserted with creds aside |
| 4 add-a-workshop, one writer | `CompanyLocationsService`, `CompanyLocationsController`, new `POST /rider/locations` | coords required; Maps place-URL refused with a clear message; created location returned selected |
| 5 web forms + banner + planner chip/badge | `fleet.blade.php`, `partials/workshop-alerts`, `shifts/planner.blade.php` | `formcheck.js`/`vehpanel2.js` extended; no browser dialog |
| 6 phone: sheet (locations + add + assign-now/approval), planner banner, rider copy | `FleetScreen`, `FleetVehicles`, `RoleAlertBanners`, `AttendanceScreen` | eslint 0; old-APK compat run |

**Deliberately not doing:** a separate workshop shift template; approval by the rider's *manager*
rather than a planner (the ruling names the planners); auto-approval after a timeout (silence must
never send a rider somewhere — it declines instead).

---

# 7 · WHAT WAS ACTUALLY BUILT (6-Sep-2026)

Everything in §1–§6 shipped. What follows is the delta — decisions taken while building that
are worth not re-deriving, and the deploy order.

## 7.1 Files changed

**Web / API**
| File | What |
|---|---|
| `database/migrations/workshop_approval_sep2026.sql` | **NEW.** 6 columns + `idx_wv_status_date` + `manage_shifts` as a WEB permission key |
| `app/Services/Riders/WorkshopVisitService.php` | `approvalEnabled()`, `canApprove()`, `OPEN_STATUSES`, the booking decision, `approve/decline/declineRow`, `pendingApprovals`, `escalateProposals`, `isWorkshopLocation`, `shiftTemplates`, `$lastPinNote`, batched name resolution |
| `app/Services/Location/CompanyLocationsService.php` | **NEW.** The one `createWorkshop()` writer |
| `app/Http/Controllers/CRM/WorkshopVisitController.php` | `approvals/approve/decline/addWorkshopLocation` (+ `api*`), the escalation sweep on the alerts poll, the `done` push |
| `app/Services/FirebaseService.php` | `proposed · approved · declined · auto_declined · approval_reminder · done`; group-skip log now names the audience |
| `app/Http/Controllers/Ops/ShiftPlannerController.php` | proposals in the planner grid, for planners only |
| `routes/web.php`, `routes/api.php` | 4 new doors each ⚠ **`/xclean` after upload** |
| `resources/views/partials/workshop-alerts.blade.php` | the ⏳ approval card (Approve · Adjust · Decline) |
| `resources/views/pages/riders-map/partials/fleet.blade.php` | `showIf` fields, inline ➕ Add a workshop, the assign-now choice, `flWorkshopApprove/Decline`, proposal + declined states |
| `resources/views/pages/shifts/planner.blade.php` | the dashed `⏳ workshop?` cell chip + legend |

**Phone**
`src/screens/FleetScreen.js` · `src/components/FleetVehicles.js` ·
`src/components/WorkshopApprovalBanner.js` (**NEW**) · `src/navigation/index.js`

## 7.2 Decisions taken while building

- ⚠⚠ **The planner's DEFAULT is the old behaviour.** A planner who sends no flag gets
  `scheduled`, exactly as today; `send_for_approval` is opt-IN. Defaulting a planner to
  "proposed" would have meant an old APK (or a cached web page) in Shabib's hand silently
  turning his own booking into a request he never sees the banner for — which the morning
  sweep then auto-declines. Qasim's client being old changes nothing, because it is the
  PLANNERS' screens that show a proposal and those ship with this server.
- ⚠⚠ **A proposal writes NOTHING into the ticket thread.** The rider reads that thread, so
  `linkTicket()` (and the ticket's move to `scheduled`) now happens on **approval**, and a
  cancelled proposal writes no line either — announcing a plan by cancelling it is worse than
  saying nothing.
- ⚠⚠ **A handover keeps a proposal proposed.** `onHandover` promoting it to `scheduled` would
  have made a bike change a back door around the whole ruling.
- **`manage_shifts` needed a WEB half.** It existed only as a mobile permission, so
  `hasPermission()` was false for everyone on the desk — the same trap the 3-Sep persona audit
  found in the Phase-2 file. Part 2 of the SQL seeds it for roles 10, 14, 18, 20.
- **`reminded_at` is shared by two sweeps** (the 17:00 planner nudge and the rider's day-before
  reminder), so `approve()` **clears** it — otherwise a nudged proposal would cost the rider his
  own reminder for the very visit that most needs one. Proven in the suite.
- **Auto-decline spares a same-day booking.** A proposal made TODAY for TODAY is left alone;
  only one that has been waiting since before today dies in the morning.
- **`$lastPinNote`** — "the visit exists and nothing was pinned" now always says WHY (no
  workshop chosen · a human's own shift row for that day wins · he has no shift that day).
  Silence there is what produced Danish's 6-Sep booking.
- **Inline add-a-workshop, in one press.** The web form grew `showIf` fields and creates the
  location then books against it without leaving the modal; the phone offers "use my current
  location". A Maps **place** URL is refused by name (the Sep-1 trap). Typing a name that
  already exists **ticks that row as a workshop** instead of making a second one — which is
  also the one-press fix for the live "0 workshops ticked" state.
- **Declined requests stay visible to managers** and needed their own branch in both
  renderers; without it they drew as *"scheduled — the rider has been told"*.
- **N+1 avoided:** the three new user-id columns resolve their names in ONE query per list,
  not one per row per column.

## 7.3 Proof

| Suite | Result |
|---|---|
| `test_workshop_approval.php` **(NEW)** | **104 / 104** — the booking decision, invisibility to the rider, the pin gate, Adjust incl. his start time, decline, escalation, handover, add-a-workshop |
| `test_workshop_approval_pushes.php` **(NEW)** | **23 / 23** — who is told what, with the Firebase credentials moved aside. ⚠ It refuses to run while they are in place |
| `test_workshop_visits.php` | 176 / 176 |
| `test_vehicle_tickets.php` | 131 / 131 |
| `test_fleet_personas.php` | **80 / 80** (3 cases correctly flipped to the new rule) |
| `test_old_apk_compat.php` | 28 / 28 |
| `test_record_service_typed.php` | 255 / 255 |
| `scratchpad/wsformcheck.cjs` **(NEW)** | 30 / 30 — drives the REAL booking form JS |
| `scratchpad/wsbannercheck.cjs` **(NEW)** | 19 / 19 — drives the REAL approval card JS |
| eslint / jest | 0 errors · 198 tests pass (`App.test.tsx` fails on a native module, pre-existing) |

## 7.4 Deploy order

1. **Run `database/migrations/workshop_approval_sep2026.sql`.** Safe before the PHP: the code
   is schema-guarded and falls back to the old flow while the columns are missing.
2. Upload the web files. **`/xclean` is mandatory — routes changed.**
3. ⚠⚠ **Tick at least one location as a workshop** (Locations page: name + lat/long + radius +
   the 🔧 box). Today **zero** are ticked, which is why Danish's 6-Sep booking pinned nothing.
   From this build a manager can also add one from inside the booking form.
4. Build and install the APK.
5. ⚠ Mobile permissions are a login-time snapshot — planners must **log out and back in**.

## 7.5 Review round (same day) — cut-off moved to "before his shift", and five fixes

Owner ruling on review: auto-decline should give the planners until **just before the rider's
shift**, not until midnight. Built as a LEAD before it, because he has to hear before he leaves
home, and an approval after he has checked in at LaCarne cannot move that check-in:

    cut-off = min(shift start that day, appointment time) − WORKSHOP_APPROVAL_LEAD_MIN

`WORKSHOP_APPROVAL_LEAD_MIN` is a `t_fin_config` key (default **60**, no row needed). No shift
and no appointment ⇒ 08:00 stands in. **`approve()` refuses on the same clock** ("Too late —
the cut-off was 08:30"), and the planners' cards on web and phone show *decide by HH:MM*.

Fixed on review: a planner who is not a booker (Farooq) had his `/workshop-visits` list forced to
his own id (vehicle-scoped reads came back empty); the phone approval card now gates on
`manage_shifts` client-side instead of polling for every rider; approving from the web corner
card now redraws an open vehicle panel; same-day rider copy reads *Aaj* not *Us din*.
Suite: `test_workshop_approval.php` **110**.

## 7.6 Integration sweep (7-Sep) — every button → handler → endpoint, every push → tap → screen

Prompted by the `Alert.prompt` bug (a button that looked wired and did nothing). Traced rather
than re-read. Found and fixed:

- ⚠⚠ **A manager's push tap landed on the RIDER's screen.** `routeFromPush` sent every
  `workshop_visit` push to My Vehicle. Farooq tapping *"needs your approval"* was shown a bike he
  does not hold. Manager-bound pushes (all 8: proposed · approval_reminder · declined ·
  auto_declined · approved-to-booker · done-to-booker · scheduled/accepted/reminder group) now
  carry `audience=manager`; the phone routes them to **Fleet with the machine open**, or to
  **StoreShifts** for a planner with no fleet key — the approval card sits in the root banner
  stack on both. Permissions come from the login snapshot (`getPermissions()`), since the router
  runs outside React. Rider pushes are untouched. Asserted at the source on both sides.
- ⚠ **"Use my current location" never asked for the permission.** `getCurrentPosition` does not
  request it; on a phone that has never granted location it failed straight into the error path.
  Now calls the shared `requestPermissions()` first, with a plain message if refused.
- **The planner grid did not redraw after Approve/Decline** from the corner card — the ⏳ chip
  stayed. The card now calls the page's own `loadWeek(WEEK)` when present (`typeof` guard: those
  are top-level `let`s, not on `window`).

Checked and fine: CSRF meta is in the shared `head` partial (all three host pages); web routes
inside the auth group; `StoreShifts`/`Fleet`/`VehicleProfile` all root-registered; rider's Accept
after approval (`/pending` → `needs_accept`); `DeviceEventEmitter` refresh on the Vehicles tab;
graceful degradation when the SQL is not yet run; old APK planners keep the old flow.
Suite: `test_workshop_approval.php` **116**.
