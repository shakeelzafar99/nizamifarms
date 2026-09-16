# Bikes → third tab "🛠 Issues" — the fleet's open problems, by machine (PLAN, 15-Sep-2026)

**Status: DESIGN ONLY. Nothing built, nothing changed.** Owner asked for a careful design first.

## 0. What the owner asked for, in one line

> "Vehicles have individual chats. I want a third tab under Bikes that consolidates those chats **by
> vehicle**, tells me what is going on, whether issues are still open, whether a workshop day has been
> assigned or not — a summary view so I can see delays and whether anyone is closing tickets. Closed
> tickets still accessible, but clearly different."

So the tab answers one question per machine: **"Is anything stuck on this bike, and whose fault is
it?"** It is a *board*, not a chat screen. The chats already exist (`VehicleTicketsScreen` on the
phone, the inline thread on the web vehicle panel); the board points into them.

---

## 1. What exists today (verified in code + replica, 15-Sep)

| Piece | Where | Note |
|---|---|---|
| Ticket rows | `t_ops_vehicle_ticket` (status `open / acknowledged / scheduled / closed`, `urgent`, `first_response_at`, `last_message_at`, `assigned_to`, `workshop_visit_id`) | Belongs to the MACHINE (`vehicle_id`) — ruling 2-Sep |
| Thread | `t_ops_vehicle_ticket_message` (`text / photo / voice / system`) | `(ticket_id, id)` index → "last message per ticket" is cheap |
| Read marks | `t_ops_vehicle_ticket_read` | per person → `unread` per ticket already in `listFor()` |
| Workshop visits | `t_ops_workshop_visit` (status `proposed / scheduled / accepted / done / cancelled / rescheduled / declined`) | `is_missed / is_today / is_tomorrow / accepted / is_proposed` are DERIVED in `shape()` |
| Ticket list API | `VehicleTicketService::listFor($user, {vehicle_id, user_id, status, limit})` behind `visibilityScope()` | ONE visibility predicate — reuse, never re-derive |
| Visit list API | `WorkshopVisitService::listVisits({vehicle_id, include_done, include_proposed, statuses})` | default LIVE-only; proposals only when a manager asks |
| Vehicles | `VehicleService::all()` → `keeper_user_id / keeper_name / name / vtype / is_company / is_active` | |
| Mobile Bikes | `screens/FleetScreen.js` — `view` = `'riders' | 'vehicles'`, `viewBar` at ~L2058; `FleetVehicles.js` renders a machine; `VehicleTicketsScreen` (ROOT navigator) takes `{ticketId}` / `{vehicleId, vehicleName}` | |
| Web Bikes | `pages/riders-map/partials/fleet.blade.php` — `.fl-modes` (Riders / Vehicles), `flSetMode()`, `flvLoad()`; ticket rows `flTicketRowsHtml()`, thread `flOpenTicket(id)` → writes into **the single `#flTicketThread`**; workshop actions `flScheduleWorkshop / flWorkshopAccept / flWorkshopDone / flWorkshopCancel / flWorkshopApprove / flWorkshopDecline`; deep link `#bikes?vehicle=ID&ticket=ID` via `window.flDeepLink` | 7,815 lines |
| Corner banners | `partials/vehicle-ticket-alerts` (newest open ticket), `partials/workshop-alerts` (approvals + missed/tomorrow + live trips) | "something is waiting" — NOT a list |

### ⭐⭐ Three facts from the replica that shape the design

1. **0 of 6 workshop visits carry a `ticket_id`, and 0 of 13 tickets carry a `workshop_visit_id`.**
   Managers book the machine from the vehicle panel, not from a ticket thread. So *"has a workshop day
   been assigned for this issue?"* **cannot be answered per ticket today — only per MACHINE** (a live
   visit exists on that `vehicle_id`). The board must be grouped by vehicle for this reason alone, not
   just because the owner asked for it. (Part B below proposes closing this gap.)
2. **The real "stuck" states are already in the data** (as of 15-Sep):
   - `BCN-5755` (Arslan Aslam): **4 open tickets**; #13 (tyres, 3rd puncture in 2 days, photo only) has
     had **no manager reply for 3 days**; on the other three the rider's last word is "Ok / Ok Sir" —
     i.e. a manager promised something and the ball is with us. Workshop **accepted for tomorrow**
     (16-Sep 09:00, "Rawalpindi", repair).
   - `AY-4771` (Kanan Anoos): #5 "tyre end hai" open since **6-Sep**; Qasim's last line "Next Week"
     (9 days ago); a workshop visit was **done 11-Sep** (no outcome note) — **ticket still open**.
     This is exactly "nobody is closing tickets".
   - `EGL-682` (Danish Ali): #7 petrol over-consumption, #8 tank cleaning; Qasim replied last
     (9/10-Sep) → **waiting on the rider for 5 days**; nothing booked.
   - The other 7 machines (van `CAD-2958`, `DCR-799`, `EDN-198`, 4 own bikes) are quiet.
3. **Every open ticket is `assigned_to` Qasim (91).** "Who is handling it" is one name today, so the
   board must not waste a column on it — show the assignee only when it is NOT the usual person, or
   when a ticket is unassigned.

### Who can reach it (replica permission rows, 15-Sep)
`view_bike_costs` (opens Bikes) — web row only for role 17 (Qasim); Shabib (10) / Taimur (14) reach
Bikes via mobile grant / admin type. `manage_vehicle_tickets` + `schedule_workshop` — roles 10, 14,
17, 18. `manage_shifts` (approves workshop days) — 10, 14, 18, **20 (Farooq)**. Farooq holds no Bikes
key, so he cannot reach this tab; his approval card already covers his one job. **The board inherits
the Bikes gate and adds no permission.**

---

## 2. The design — one card per machine, sorted by how stuck it is

### 2.1 Name
**"🛠 Issues"** (recommended). The phone already says "Bike issues" / "Open an issue" / "Close
issue"; the web says "Tickets & chat". "Workshop" alone under-describes the chats; "Chats" hides the
workshop question. Alternatives if the owner prefers: "🛠 Workshop" or "💬 Chats".

Mode bar becomes: `👤 Riders · 🏍️ Vehicles · 🛠 Issues` (same segmented control on both surfaces;
the mobile button carries a small red count when something needs us).

### 2.2 The board is a NOW view
No month picker (the costs views own that; `flSetMode('vehicles')` already hides it — same treatment).
Refresh on focus / pull-to-refresh on the phone; 60-second poll on the web while the mode is visible
(⚠ see §4.3 — never wipe an open thread or a half-typed reply).

### 2.3 Top strip — the fleet in one line (tappable filters)
`⏱ Waiting on us 4 · ❓ Unanswered 1 · 🔴 Not rideable 0 · 🔧 Workshop this week 1 · ❗ Missed 0 · ✓ Closed 6`
- Each number is a filter chip; tapping one narrows the cards. "Closed" flips the board to history
  mode (§2.6). "All" resets.
- The strip is the whole answer for a manager glancing at the tab; the cards are the detail.

### 2.4 The vehicle card
```
┌──────────────────────────────────────────────────────────────────────┐
│ BCN-5755 · Arslan Aslam                    🎫 4 open  🔧 Tomorrow ✓✓  │  ← header (tap → vehicle page)
│ ⏱ 1 unanswered for 3 days · 3 waiting on us since Thu               │  ← ATTENTION line (server-composed)
│ ────────────────────────────────────────────────────────────────────  │
│ ● Tayre bilkul farg ho gay h… 2 din m tesra punchar   [Open]  3d ⏱   │  ← ticket rows (tap → thread)
│   Arslan · 📷 photo · Sat 20:38                                  ❓   │
│ ● Hed salndr ka Kam h…                          [In progress]  4d ⏱  │
│   Arslan: "Ok" · Fri 13:01                            waiting on us  │
│ ● Tayre chang honay Wala h or rim b             [In progress]  3d ⏱  │
│ ● Set kharab h…                                 [In progress]  5d ⏱  │
│ ────────────────────────────────────────────────────────────────────  │
│ 🔧 Wed 16 Sep 09:00 · Rawalpindi · repair · accepted by Arslan       │  ← workshop line (tap → vehicle workshop block)
│ ✓ 1 closed · last 8 Sep                                    Show ›    │  ← muted history footer
└──────────────────────────────────────────────────────────────────────┘
```
Every element is either existing data or a derived field from §3. Nothing is typed twice: the ticket
row is the existing `flTicketRowsHtml` / mobile card shape **plus** one new line (last message
preview + whose turn).

**Header chips** (max 3, in this order): `🔴 Not rideable` (any urgent open) · `🎫 N open` ·
workshop state — one of `🔧 Today ✓✓` / `🔧 Tomorrow ✓` / `🔧 Sat 20 Sep ⏳ not accepted` /
`❗ Missed 12 Sep` / `⏳ Awaiting approval — rider NOT told` / `🔧 Nothing booked` (only when there
are open tickets; a quiet machine has no chip).

**Attention line** — ONE sentence, composed on the server (house rule: `checkin_line` precedent —
two apps and a Blade composing the same sentence is three chances to drift). Level drives the
card's left stripe colour (red / amber / blue / green / grey).

**Ticket row** — title (urgent 🔴 prefix), status pill (existing colours: open amber, in progress
blue, workshop set purple, closed grey), age, unread badge, and the new second line:
`{author}: "{snippet}" · {when}` + turn marker `waiting on us` / `waiting on Arslan` /
`workshop set`. Photo/voice-only last message prints `📷 photo` / `🎤 voice`.

**Workshop line** — the machine's live visit, in the vehicle panel's own vocabulary (`✓✓ accepted`,
`✓ scheduled, rider told`, `⏳ awaiting a shift planner — rider NOT told`, `✖ declined`). Also
prints the most recent DONE visit when it post-dates an open ticket: `🔧 Done 11 Sep — 1 ticket still
open` (amber). That line is the "nobody is closing tickets" detector.

### 2.5 Sort order (fixed, not user-configurable)
1. 🔴 any urgent open ticket
2. ❗ workshop missed
3. ❓ unanswered ticket (no manager reply yet) — oldest first
4. ⏱ waiting on us — longest wait first
5. 🔧 workshop done but tickets still open
6. ⏳ proposal awaiting approval
7. waiting on the rider (stale ones first)
8. 🔧 booked and everything answered
9. quiet machines — collapsed into one muted row: `7 machines with nothing open ›` (expands to plain
   names; tapping a name opens the vehicle page). Quiet machines are counted, not hidden — "10 bikes,
   3 with issues" is itself the summary.

### 2.6 Closed — accessible, clearly different
- Per card: muted footer `✓ 3 closed · last 11 Sep › Show`. Expanding lists closed tickets **greyed,
  no stripe, no attention text, no turn marker**, with `closed by Qasim · 8 Sep · "{close_note}"`.
  Tap still opens the thread (read-only after the 7-day reopen window — `can_reply` already governs
  the composer).
- Board-level: the `✓ Closed` chip switches to **history mode** — every machine that has ever had a
  ticket, closed tickets listed newest first, done/cancelled visits under them, all in the grey
  treatment. History mode has no attention lines and no stripes at all, so the eye cannot mistake it
  for the live board.
- Done visits show `outcome_note` (real data: "Chain repair", "Nahi", or nothing — an empty outcome
  prints `no note`, which is itself a nudge).

### 2.7 Buttons — deliberately few
The board is for *seeing*; the thread and the vehicle page are for *doing*. Every action below already
exists; the board only links to it.

| Where | Button | Goes to | Why here |
|---|---|---|---|
| Card header | (tap) | Vehicle page (`openVehicleDeep` / `flvOpen`) | the machine's full panel |
| Ticket row | (tap) | Thread — phone `VehicleTickets {ticketId}`; web inline under the row | reply / 📷 / 🔧 Schedule / Close all live there |
| Card, when open tickets and NO live visit, and `can_schedule` | `🔧 Schedule workshop` | existing scheduler (`onVehicleAction('workshop', keeper…)` / `flScheduleWorkshop(null,null,vehicleId)`) | the commonest answer to a fault |
| Workshop line | (tap) | vehicle panel's workshop block (Accept-for-him / Done / Cancel / Approve / Decline live there) | one place decides visits |
| Proposal line, `can_approve` | `✓ Approve · ✖ Decline` | existing `approve/decline` | a planner should not have to hunt |
| Strip chips | filter | — | |

**Not offered on the board, on purpose:**
- **Close** from the list. A close without reading the thread is how a rider's "still not fixed" gets
  buried; the thread is one tap away and the close note matters. (Open ruling §6.5.)
- **Auto-close when the workshop is done.** Ruling 2-Sep: only a manager closes. The board surfaces
  "done but open" instead of deciding it.
- A second thread renderer. Web reuses `flOpenTicket()`; phone reuses `VehicleTicketsScreen`.

### 2.8 Empty / degraded states
- Nothing open anywhere: `Nothing open on any machine. 6 closed → Show history`.
- Tables missing (SQL not run): the same `fl-vwarn` sentence pattern the Vehicles tab uses.
- Non-manager who somehow lands here (a rider with the Bikes key would be a permission mistake):
  sees only his own machines — `visibilityScope()` guarantees it.

---

## 3. Backend — ONE new read endpoint, no SQL, nothing existing changes shape

### 3.1 Why a new endpoint rather than stitching three existing ones on the client
The client would need `/fleet/vehicles` + `/fleet/tickets?status=all` + `/fleet/workshop?include_done`
and then join, classify and compose sentences — **in two languages (Blade JS and RN)**. Three gates,
three sort orders, two copies of "whose turn is it". The `checkin_line` lesson (10-Sep) says compose
once on the server. Cost is 8 fixed queries for 10 machines.

### 3.2 Routes
- web: `GET /orders/riders-map/fleet/issues` → `VehicleTicketController::board` (name
  `fleet.issues.board`) — inside the existing Bikes-gated group.
- api: `GET /rider/vehicle-issues` → `VehicleTicketController::apiBoard` (sets `mobileContext`).
- Both call `Services/Riders/VehicleIssueBoard::forUser($user, $opts, $mobile)`. New file; the
  controller stays a thin door, as tickets and visits already are.
- Query params: `mode=live|history` (default live), `vehicle_id` (optional, for the per-card
  "Show closed" expansion in live mode).

### 3.3 Payload
```
{ success, available, can_manage, can_schedule, can_approve, generated_at,
  totals: { machines: 10, with_issues: 3, quiet: 7,
            open_tickets: 7, urgent: 0, unanswered: 1, waiting_on_us: 3, waiting_on_rider: 2,
            stale: 1, workshop_booked: 1, workshop_missed: 0, proposed: 0, closed: 6 },
  vehicles: [ {
      id, name, vtype, is_company, keeper_user_id, keeper_name,
      attention: { level: 'red'|'amber'|'blue'|'green'|'grey', line: '1 unanswered for 3 days · 3 waiting on us since Thu' },
      open_tickets: [ …ticket shape (unchanged)…,
                      last_message: { id, user_id, author_name, kind, snippet, created_at },
                      waiting_on: 'us'|'rider'|'workshop'|null, waiting_hours, unanswered: bool,
                      age_hours, is_stale, assigned_to_name ],
      workshop: visit shape | null,            // the LIVE (or proposed, managers only) visit
      last_done_visit: visit shape | null,     // most recent done, only if it post-dates an open ticket
      closed_count, last_closed_at
  } ],
  quiet: [ {id, name, keeper_name} ],
  history: [ … ]                                // only when mode=history
}
```

### 3.4 Derivations (all in `VehicleIssueBoard`, all Carbon — never `CURDATE()`/`NOW()`, see the
2h DB-clock trap in `workshop-visits-phase2`)
- **Last message per ticket** — one query: `messages WHERE id IN (SELECT MAX(id) … GROUP BY
  ticket_id)` joined to `t_sys_user` for the author. System lines are skipped for the *turn*
  question (take the newest human message) but kept for the preview when they are the newest thing
  ("Qasim scheduled a workshop for 16 Sep").
- **Manager set** — one query: user ids whose role holds `manage_vehicle_tickets` (web or mobile
  table — the push group already resolves against the mobile table; the board takes the union).
- **`waiting_on`** — newest human message author ∈ manager set → `rider`; else → `us`; no message
  beyond the opener → `us`; ticket `status = scheduled` with a live visit → `workshop`.
- **`unanswered`** — `first_response_at IS NULL` (existing column, exactly this meaning).
- **`waiting_hours`** — now − that message's `created_at`.
- **`is_stale`** — no human message for ≥ `TICKET_STALE_DAYS` (default 3) whoever's turn it is.
- **Thresholds** — `TICKET_UNANSWERED_HOURS` 24, `TICKET_WAITING_HOURS` 24, `TICKET_STALE_DAYS` 3.
  Constants in the service, overridable from `t_fin_config` if the key exists (the
  `WORKSHOP_APPROVAL_LEAD_MIN` mechanism) — **no SQL required to ship**.
- **Workshop per machine** — `listVisits({vehicle_id…})` is per-vehicle; the board asks ONCE for the
  fleet (`statuses` = OPEN + done, `limit` 200) and buckets by `vehicle_id`, picking the live/proposed
  one and the newest done one. Proposals only when `can_schedule || can_approve` — the same gate
  `WorkshopVisitController::index` applies.
- **`last_done_visit`** is attached only when `done_at > MIN(open_tickets.opened_at)`; otherwise
  it is noise.
- **Attention level + line** — first matching rule from §2.5 writes both. English (manager-facing,
  `alerts-copy-roman-urdu` ruling). Names, not ids. Relative time in days once past 48h.
- **Visibility** — `visibilityScope($user)`: managers = fleet; others = own machines only. Retired
  machines excluded unless they have an open ticket (a retired bike with an open ticket is a stuck
  ticket, not a retired problem).

### 3.5 Tests (`test_issue_board.php`, transaction-scoped like `test_vehicle_tickets.php`)
- turn classification: rider-last → us · manager-last → rider · photo-only opener → us · system line
  newest → falls through to the last human · scheduled + live visit → workshop.
- unanswered ≥ 24h flagged, 23h not (Carbon `setTestNow`).
- done visit after the ticket → `last_done_visit` set; done visit before → null.
- a proposal appears for a manager, is invisible to the rider on the same board.
- non-manager sees own machines only; a handed-over machine leaves his board (reuses the Phase-1 §3
  handover staging).
- quiet machines are counted and listed in `quiet`, never in `vehicles`.
- closed tickets excluded from `open_tickets`, counted in `closed_count`, present in history mode.
- sort order for a fixture of five machines matches §2.5.
- `available()` false → `{available:false}` and nothing thrown.

---

## 4. Frontend

### 4.1 Mobile (`NizamiFarmsMobile`)
- `FleetScreen.js`: third `viewBtn` `🛠 Issues` in `viewBar`; `view === 'issues'` hides the month
  bar and mounts `<FleetIssuesBoard …/>`. A red count on the button from `totals.unanswered +
  waiting_on_us + workshop_missed`, fetched once when Bikes mounts (cheap) and refreshed when the
  board reloads.
- New `components/FleetIssuesBoard.js` (sibling of `FleetVehicles.js`): strip → chips → cards →
  quiet row. Props: `onOpenVehicle(id)` → existing `openVehicleDeep`; `onOpenTicket(id)` →
  `navigation.navigate('VehicleTickets', {ticketId})` (ROOT screen, already registered);
  `onVehicleAction('workshop', keeper_user_id, current_meter, keeper_name)` → existing scheduler
  sheet; `onVehicleAction('workshop_approve' | 'workshop_decline', …)` → existing handlers.
- Reload on `useFocusEffect` (coming back from a thread), pull-to-refresh, and the existing
  `DeviceEventEmitter 'workshop:changed'` event.
- Copy: English (manager surface). Roman Urdu only inside rider-facing instructions, which this
  screen has none of.
- ⚠ `Alert.prompt` is truthy on Android (memory) — not used here; nothing prompts.
- ⚠ Old APK safe: a new endpoint only; no existing payload changes.

### 4.2 Web (`fleet.blade.php`)
- `.fl-modes`: third `.fl-mode` `🛠 Issues` → `flSetMode('issues')`; the mode function hides the
  costs group + `flVehWrap` and shows a new `#flIssuesWrap`. Own state prefix `flIss*` (⚠ the
  Vehicles-tab harness trap: assigning `flv*` bindings from outside the eval creates a different
  binding — keep the board's state separate).
- Renderers: `flIssRenderStrip(totals)`, `flIssRenderCards(vehicles)`, `flIssRenderQuiet(quiet)`,
  `flIssRenderHistory(history)`. Ticket rows call `flTicketRowsHtml(list, {showWho:true,
  preview:true})` — one small extension of the existing renderer (new option prints the second line;
  both existing callers pass nothing and render unchanged).
- Inline thread: `flOpenTicket(id)` writes into `#flTicketThread` **by id** — a board with many cards
  needs a per-card target. Change to `flOpenTicket(id, wrapEl)` with `wrapEl` defaulting to the
  existing `#flTicketThread` lookup. Two existing callers unchanged.
- After `flPostWorkshop` / a ticket reply / close succeeds, the board refreshes (the same hook points
  that already call `flvLoadTickets` when a vehicle panel is open).
- Deep link: `#bikes?mode=issues` parked by `index.blade.php` alongside `vehicle`/`ticket`, so the
  corner banners can later say "Open Issues →". Optional in this round.
- New CSS: `.fl-iss-card`, `.fl-iss-stripe-{red,amber,blue,green,grey}`, `.fl-iss-chip`,
  `.fl-iss-closed`. Widths: cards stack in one column ≤ 1100px, two columns above.

### 4.3 The refresh rule (the Daily Closing lesson, 15-Sep)
The web poll must not wipe what the manager is doing. Rule: **if a thread is expanded or the reply
box has text, refresh only the strip numbers; re-render cards only when nothing is open.** Same
on the phone: `useFocusEffect` reload, no timer while the board itself is visible.

---

## 5. Deploy shape (when built)
**No SQL. No new permission. Nobody re-logs in.** Web first (`routes/web.php` + Blade + 1 new
service + 1 controller method → `/xclean`), then the APK. The old APK is unaffected. Gate scripts:
`test_issue_board.php` + a `scratchpad/jscheck.cjs` pass over the new inline script + a
`formcheck`-style harness driving `flSetMode('issues')` with fetch stubbed.

---

## 6. OWNER RULINGS — answered 15-Sep-2026

### 6.1 ✅ Tab name = **"🛠 Issues"** (recommended taken)
### 6.2 ✅ Thresholds as recommended — unanswered > 24h · waiting-on-us > 24h · stale ≥ 3 days
Constants in `VehicleIssueBoard`, optional `t_fin_config` override. No SQL to ship.

### 6.3 ✅ A READ-ONLY DOOR for the planners — but NOT by granting the Bikes tab

⚠⚠ **The blocker is Farooq's role TYPE, not a bike permission.** His only role is 20, whose `type`
is literally `rider`, and `OrderController::ridersMap()` redirects *any* role-typed rider off the
whole page before a single bike key is consulted. (He does hold web `view_rider_reports`, which is
in `FleetFuelController::PERMISSIONS` — so the Bikes DATA gate would let him through. The page gate
is what stops him.) Changing his role type to let him in would also hand him the live board, Day
Review and the rider money views, since he holds `view_orders` + `view_all_riders` + `view_rider_reports`.
**Verified on the replica 15-Sep** — `hasPermission()` has no admin bypass, it is pure role rows.

**So the door is its own page, and the board partial is shared:**
- `GET /orders/riders-map/fleet/issues-board` → `pages/fleet/issues-board.blade.php`, which
  `@include`s **the same partial** the Bikes tab mounts. One board, two doors, one renderer.
- **Gate = holding a key, never a role type** — a plain rider holds none of
  `manage_vehicle_tickets` / `view_bike_costs` / `view_rider_reports` / `receive_workshop_alerts` /
  `manage_shifts`, so the key check alone is the whole gate. No rider-type test, which is what keeps
  Farooq in.
- Page shows **only** the board: no costs, no month picker, no rider money, no map.

**What "read-only" means, and the one real decision inside it.** ⚠ `visibilityScope()` returns a
non-manager only the machines he HOLDS — Farooq holds EDN-198, so a naive read-only board would show
him one bike and call itself a fleet board. A read grant is therefore needed, and there are two grades:

| | Grade 1 — **recommended**, no SQL | Grade 2 — 1 SQL |
|---|---|---|
| Grant | `receive_workshop_alerts \|\| manage_shifts` (Farooq already holds both, W+M) | new key `view_vehicle_issues` |
| Sees | every machine's card: attention line, workshop line, ticket **counts, titles, ages, whose turn** | the same **plus the threads** |
| Cannot | open a thread, reply, close, schedule | reply, close, schedule |
| Why | honours the Phase-2 split — Farooq was deliberately given workshop alerts and **not** ticket alerts, so his complaint-thread exclusion is a ruling, not an oversight | if he later needs the conversations |

Grade 1 gives a planner exactly what he plans against ("AY-4771, tyre, 9 days, workshop done 11 Sep,
still open") without handing him rider complaint conversations he was deliberately kept out of.
Grade 2 is one flag away if that turns out to be too little.

⚠ **Server-side, not a hidden button.** The board endpoint returns `can_manage:false`, `can_schedule:false`,
`can_approve` as usual (Farooq DOES approve workshop days), and `threads:false`; the renderer draws
what it is told. A read-only viewer can never be offered a control the server would refuse.

**Mobile is the bigger half, and it is the half Farooq actually uses** (role type rider; he drives
the van and holds EDN-198). He has no mobile `view_bike_costs`, so `FleetScreen` is closed to him
and `routeFromPush` sends him to StoreShifts. The mobile door = `FleetScreen` gains an **issues-only
mode**: when the user lacks `view_bike_costs` but holds `receive_workshop_alerts`, the view bar
renders one button, the month bar and both cost views never mount, and the navigator + `routeFromPush`
gate widens to match. ⚠ Touches the push-routing seam that the 7-Sep sweep already had to fix once —
check it for every new entry point. **Recommended order: web door in round 1, mobile door in round 2**,
so the mobile navigation change ships on its own and can be reverted alone.

### 6.4 ✅ Own bikes stay in — the replica says they are unused, the code says they are reachable
**Replica 15-Sep: 0 tickets and 0 workshop visits on all four own bikes, ever.** All 13 tickets are on
company machines (11 bikes + 2 on the van). So the owner's instinct is right about what has *happened*.
⚠ **But nothing prevents it.** Three own bikes carry a LIVE assignment — Asim Tahir (v6), Haider Ali
(v7), Rajab Masood (v9) — and `ownMachineIds()` applies **no `is_company` filter**. Those three phones
offer "＋ Open an issue" on their own bike today, and the ticket would be perfectly valid.
→ **Leave them unfiltered.** They cost nothing (they fall into the quiet row), and excluding them would
make a ticket raised on an own bike invisible on the board — a silent hole, which is the shape this
whole round exists to remove. Card labels them `own bike` so it is visible at a glance that the company
may not be paying for that repair.
⚠ Also found: **the van CAD-2958 has 2 tickets and NO live assignment.** Its card shows no keeper, and
by `visibilityScope()` those tickets are manager-only until the van is given out again. Expected, not a bug.

### 6.5 ✅ Close FROM the board — and it is already a single engine
Owner's condition is already met server-side and nearly met client-side.
- **Server:** `VehicleTicketService::close()` behind `POST …/fleet/tickets/{id}/close` (web) and
  `POST /rider/vehicle-tickets/{id}/close` (api). The board adds **no endpoint and no rule**.
- **Web:** the board calls the **existing `flCloseTicket(id)`** — same `flForm` dialog, same optional
  note, same copy ("The rider holding the bike sees your note."). Its success tail already refreshes
  the rider drawer and the vehicle panel; add **one line** for the board. So a close from the drawer,
  the vehicle panel or the board updates all three identically.
- **Mobile:** lift the existing `closeTicket()` + `doClose()` out of `VehicleTicketsScreen` into
  `src/utils/ticketClose.js` (`closeTicketFlow({ticketId, say, onDone})`), used by the screen AND the
  board — so the flow exists once on the phone too. ⚠ Keep the platform branch exactly as it is:
  `Alert.prompt` is iOS-only and **returns truthy on Android**, which is why Android already takes the
  confirm path ([[alert-prompt-truthy-on-android]]).
- **`can_close` on the board = `can_manage && is_open`** — the identical rule `show()` computes. No
  second definition.
- ⭐ **One guard added because the button moved off the thread:** if the ticket has `unread > 0` for
  you, the close dialog prints *"2 messages on this issue you have not read"* above the note field.
  Warns, never blocks. That was the entire objection to closing from a list, and this removes it.

### 6.6 ✅ Workshop done → **do NOT auto-close. Close it in the same breath, by a manager.**
Two hard reasons, both read out of the code rather than argued:

1. ⚠⚠ **`WorkshopVisitService::completionGate()` lets the RIDER mark his own visit done** — that is
   the normal path (his outcome prompt after the day arrives), ruled on 2-Sep: *"when either Qasim
   enters the values or the riders enter it after the service."* Auto-closing on `done` would therefore
   let a **rider close his own ticket**, breaking the 2-Sep ruling that only a manager closes — and he
   is precisely the person who may be wrong about whether the fault is gone.
2. ⚠⚠ **A visit does not know which ticket it fixed** (0 of 6 carry `ticket_id`). Auto-close would have
   to close *every* open ticket on the machine. BCN-5755 has four unrelated ones — tyres, seat, head
   cylinder. Closing "seat is broken" because a tyre was changed is a guaranteed wrong close, and the
   rider who reported it learns not to bother reporting.

**What to build instead:**
- **Manager marks done** → the done form lists that machine's open tickets as tick-boxes, **unticked
  by default**, with the outcome note pre-filled as the close note. One tap each, at the moment he
  actually knows what happened. Each tick calls `close()` — the same engine as §6.5.
- **Rider marks done** → no tick-boxes at all. Existing behaviour stands (a linked ticket returns to
  `acknowledged`). The board then shows the machine amber — *"workshop done 11 Sep, 1 ticket still
  open"* — and §6.5's Close is one tap from there.
- **After Part B** (tickets ticked at booking) the boxes arrive **pre-ticked**, because the visit then
  knows which tickets it was for. That is the point at which auto-close could be revisited honestly —
  not before. This is why Part B is now recommended as the very next round rather than "someday".

## 7. Part B (recommended follow-up, separate round): tie visits to tickets at booking time
The replica shows the link is never made. When a workshop is scheduled from a machine that has open
tickets, the scheduler (phone sheet + web `flForm`) offers those tickets as tick-boxes, **all ticked
by default**; `schedule()` writes `workshop_visit_id` on each, moves them to `scheduled`, and writes
the existing system line into each thread. `markDone()` already returns linked tickets to
`acknowledged`. Then "workshop assigned?" is answerable **per ticket**, the board's `waiting_on:
'workshop'` becomes exact, and the "done but open" line names the tickets. Touches `schedule()` —
the most-guarded function in the fleet — so it is its own round with its own gate.
