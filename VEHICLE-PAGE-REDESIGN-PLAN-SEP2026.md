# Vehicle page — one engine, clear costs (plan, 3-Sep-2026)

> **STATUS after the 3-Sep review.** ✗ not live.
> P1 cost strip ✅ web + mobile · P3 header actions ✅ **web only** · P4 service-day bill ✅ ·
> P5 odometer ✅ · **P2 merged history ✗** — filter chips exist on both surfaces, but *Past
> services* and *This month* are still two lists and the server's `all_time=1` has **no toggle in
> either UI**. Mobile still lacks *New maintenance* / *Record service* on the vehicle header.
> See §8 for the revised next phase.

Owner's three questions, answered from the code (not assumed), then a redesign that keeps every
current function.

---

## 1 · How does the service-day feature link to service records? (verified)

**On service day the rider does NOT use the request form.** He answers the *"Workshop aaj — ho
gaya?"* prompt. That posts `meter` + `maintenance_type_id` (+ note) to
`WorkshopVisitController::done`, which records a **typed service LOG** through the shared
`ServiceRecordService::record()` — dated on the **visit's** date, type defaulting to the one the
visit was booked for, refused if untyped. So the *reading* side is already one engine.

### ⚠⚠ The gap: the BILL
The completion prompt has **no amount and no bill photo**. `done()` accepts a `request_id`, but it
is only stored on the *visit* row — **nothing links a claim to the service log.** So when the rider
later files the workshop bill from his own request form, that is a **second, unlinked record**:

> service-day prompt → LOG at 27,906 km · rider's bill → CLAIM at 27,906 km
> ⇒ **two rows in Past services**, and the evidence engine keeps the higher meter if they differ.

This is exactly the duplicate that `request_id` prevents on the *manager's* Record service — the
service-day path just never got the same treatment.

**Fix (Phase 4 below):** (a) the outcome prompt and the manager's "Mark done" gain the same
optional *Amount · Paid from · Bill photo* block, filed through the existing
`recordServiceBill()` (service first, money second, linked); and (b) **"Add the bill"** on any
bill-less LOG row — the deferred B3 — for the far commoner case where the receipt turns up later.

---

## 2 · When Qasim / Shabib enter readings, is it one engine? (verified)

Every path that records "a service happened" goes through **`ServiceRecordService`** (manager
Record service, workshop completion, rider's outcome prompt), and every path that files a claim
now goes through **`requireTypeForClaim`** — so **the service schedule is one engine.** The
screenshot shows it working: DCR-799's Oil + Tuning at 27,906 km *covers* the Oil Change ("done
with Oil + Tuning") because the covers rule is applied once, in one place.

### ⚠ One thing to decide: a service reading does NOT move the ODOMETER
`computeCurrentMeter()` takes the MAX of attendance meters (by vehicle and by assignment window)
and **claims' `meter_at_fill`** — but **not `t_fleet_service_log.meter`**. So if Qasim records a
service at 28,100 km on a bike whose last attendance/claim reading was 27,986, the schedule counts
from 28,100 but "current km" stays 27,986 until the next check-in. Consistent with how it was
designed (a service log is *evidence for the countdown*, not an odometer reading) — but it is a
second definition of "the bike's km". **Owner ruling needed** (Phase 5): include service-log meters
in the odometer, with the same plausibility guard? Recommend **yes** — it is a real reading at a real
time, it is one more MAX term, and a typo is already correctable via Edit.

---

## 3 · What the vehicle detail shows today (verified, in order)

| Section | What it is | Problem |
|---|---|---|
| Header + buttons | name · keeper · km · Reassign / Take back / Edit / Meter / Profile | no *New maintenance*, no *Record service* here |
| **Condition** | handover photos + upload form | first thing on screen, rarely what you opened it for |
| **Service** | headline · per-type schedule · **Past services** | Past services is a **24-month window, max 24 rows** — no lifetime; regular and repair rows interleaved; no totals |
| **This month** | fuel Rs + maintenance Rs for ONE month · claim list · 💰/📅 toggle | one month at a time; "maintenance" lumps **regular service and repairs together** |
| **Who has had it** | keeper history | fine, but sits in the way |

**Same on mobile** (`FleetVehicles`): Past services → This month → Who has had it, month passed in.

So the two questions a manager actually opens this for — *what has this bike cost me, and is that
scheduled upkeep or things breaking* — cannot be answered without adding it up by hand.

---

## 4 · The redesign — keep everything, restructure and add the missing numbers

### 4.1 Layout (web and mobile, same order)

```
┌ DCR-799 · company · with Waseem since 9 Aug · 27,986 km          [Close] ┐
│ chips: 🛢 Oil Change due in 920 km · 🎫 1 open ticket · 🔧 workshop 5 Sep │
│ actions: Reassign · Take back · Edit · Meter · ➕ New maintenance ·        │
│          🛠 Record service · Profile ›                                     │
├──────────────────────────────────────────────────────────────────────────┤
│ COST  ┌ This month ┐ ┌ Last 3 mo ┐ ┌ This year ┐ ┌ Lifetime ┐            │
│       │ Regular 3,500│ │ …        │ │ …        │ │ Regular 12,050│        │
│       │ Repairs   650│ │          │ │          │ │ Repairs  8,300│        │
│       │ Fuel   14,200│ │          │ │          │ │ Fuel   …      │        │
│       └ Rs 6.76/km ─┘ └──────────┘ └──────────┘ └ + 2 unclassified ┘    │
├──────────────────────────────────────────────────────────────────────────┤
│ SERVICE   Oil Change — due every 1,000 · last 27,906 · due in 920        │
│           (per-type schedule exactly as now)                              │
├──────────────────────────────────────────────────────────────────────────┤
│ HISTORY   [All] [Regular] [Repairs] [Fuel]   ‹ Sep 2026 › · All time     │
│           30 Aug · Oil + Tuning · 27,906 km · Rs 3,500 · Waseem · Edit reading │
│           30 Aug · Brake Shoe   · 27,906 km · Rs   650 · Waseem · Edit reading │
│            6 Aug · Oil Change   · 24,667 km · no bill · Shabib · recorded · Edit │
│           …                          📅 Day by day (kilometre audit) — toggle │
├──────────────────────────────────────────────────────────────────────────┤
│ ▸ Condition (photos)          ▸ Who has had it                            │
└──────────────────────────────────────────────────────────────────────────┘
```

### 4.2 The cost strip (the thing that is missing)
- Four windows: **This month · Last 3 months · This year · Lifetime.**
- Each split **Regular service / Repairs / Fuel**, plus Rs/km for fuel where the km exist.
- Bucket comes from `t_fleet_maintenance_types.bucket` via the claim's `maintenance_type_id`.
  Legacy untyped rows: `service_type = oil_change` → Regular; `repair` / `general` → Repairs;
  neither → **"unclassified"**, shown as its own small count so the totals never silently hide it.
- Approved money in the tiles; **pending shown separately** ("+ Rs 900 waiting") so an unapproved
  bill can never read as spent.
- Lifetime = **all** approved claims attributed to this machine, including pre-registry rows
  attributed by who held the bike on that date (kept with the *from history* badge).

### 4.3 One history instead of two lists
"Past services" (24-month window) and "This month" (claims) become **one timeline** with filter
chips and a month picker that also has **All time**. Every row keeps everything it has today —
badges (*recorded · + bill · waiting · bill rejected · from history*), **Edit / Edit reading /
Remove** — and the linked LOG+claim pair still shows as **one row**. Fuel rows are there under
their own chip so the timeline is not drowned in fills. The 📅 **Day by day** kilometre audit is
kept as the toggle it is now — it answers a different question and nobody should lose it.

### 4.4 New maintenance — and Record service — from the vehicle
The web "New bike expense" modal (`flOpenNew`) and mobile `openNewClaim` already accept a rider and
a vehicle; today they are only reachable from the **Riders** tab. Add **➕ New maintenance** and
**🛠 Record service** to the vehicle header, opening the *same* forms with **requester = current
keeper, vehicle = this machine** locked. No keeper → the form asks who. The claim goes through
`/requests/store` exactly as it does from the Riders tab, so the approval rule, the ledger posting
and the new `requireTypeForClaim` gate all apply unchanged. **Server: no change.**

### 4.5 Move the rarely-used sections down
Condition photos and keeper history become collapsed sections at the bottom. Nothing removed.

---

## 5 · What changes where (all additive — nothing existing is removed or renamed)

| Layer | Change | Risk |
|---|---|---|
| `VehicleService` | `costSummaryFor($vehicleId)` — the four windows × three buckets, approved vs pending | new method only |
| `VehicleService::serviceHistoryFor` | optional `all=1` to lift the 24-month window (capped, newest first); `bucket` on every row | default behaviour unchanged |
| `VehicleController::apiShow` / web show | ship `cost_summary`; `bucket` on claim rows | old APKs ignore new keys |
| Web `fleet.blade.php` | header actions, cost strip, merged history + chips, collapsibles | UI only |
| Mobile `FleetVehicles.js` | same sections, same order | UI only |
| `WorkshopOutcomePrompt` + manager Mark-done | optional Amount / Paid from / Bill → `recordServiceBill` | reuses the built path |
| History row | **"Add the bill"** on a bill-less LOG → files the claim, sets `request_id` | B3, small |
| `computeCurrentMeter` | **(ruling)** include service-log meters | one MAX term |

No SQL. The link column (`request_id`) already exists. APK required for the mobile half.

---

## 6 · Phasing

| Phase | What | Answers |
|---|---|---|
| **P1** | Cost strip + `bucket` on rows (server + both UIs) | "what has it cost me — upkeep vs repairs, this month and lifetime" |
| **P2** | One filterable history with All-time; Condition / Who-has-had-it collapsed | "it's messy" |
| **P3** | ➕ New maintenance · 🛠 Record service from the vehicle header | "vehicle-centric" |
| **P4** | Service-day bill (outcome prompt + Mark done) and **Add the bill later** | the §1 gap |
| **P5** | (ruling) service-log meter feeds the odometer | the §2 second definition |

P1 alone changes how the page reads. P1–P3 are UI + additive payload; P4 reuses today's bill
path; P5 is one line behind a ruling.

---

## 7 · Rulings needed before building

1. **Legacy buckets** — treat old `oil_change` as Regular and `repair`/`general` as Repairs, with
   the rest shown as *unclassified*? (Recommend yes; nothing is guessed, the unknown stays visible.)
2. **Lifetime attribution** — count pre-registry claims attributed by keeper-on-date? (Recommend
   yes, badged *from history*.)
3. **Fuel in the same timeline**, behind its own chip — or a separate section as now?
4. **Should a service LOG's meter move the bike's odometer?** (§2 — recommend yes.)
5. **Service-day bill** — optional on the prompt, as on Record service? (Recommend yes, same
   warning, same *Bill attached / No bill photo* receipt.)

---

## 8 · Revised next phase (after the 3-Sep review — what building it taught)

The plan above still holds; building P1/P3/P4/P5 changed what P2 should be and added three
accuracy items the original plan could not have known about.

### 8.1 One history, not two lists (P2, properly)
Today the detail still shows *Past services* (service records + maintenance claims) **and** *This
month* (that month's claims, fuel included) — the same job can appear in both, one month at a time
in one and 24 rows in the other. Replace both with **one timeline**:
- rows = service records + maintenance claims (linked pairs as one row) + fuel fills;
- chips: **All · 🛢 Regular · 🔧 Repairs · ⛽ Fuel · ❓ Unclassified** (fuel behind its own chip —
  owner Q3, the averages depend on it);
- a **month picker with "All time"**, wired to the `all_time=1` the server already supports and
  **nothing in either UI calls yet**;
- the 📅 *Day by day* kilometre audit kept as a toggle inside the month view — different question,
  keep it.

### 8.2 Mobile parity for the vehicle actions
The web header has **➕ New maintenance** and **🛠 Record service**; the phone has neither. Hand
them down from `FleetScreen` exactly as `onAddBill` is — `openNewClaim('Maintenance')` and
`openMarkServiced` with the keeper preset. Qasim works from the phone; this is where it matters.

### 8.3 Accuracy — things the cost strip exposed
1. **A way OUT of "Unclassified".** 109 legacy rows sit there (AY-4771: Rs 29,760 unclassified vs
   Rs 2,490 regular). The tiles are honest, but they can only become *useful* if a manager can
   classify a row in one tap. `correctClaimReading` already accepts the type on an approved claim —
   add a **"Classify ▾"** on unclassified rows that sends only `maintenance_type_id`. No new door.
2. **Rs/km beside the fuel figure** on each tile, where the km exist — the machine averages
   (`averages`) are already computed; they just are not next to the money.
3. **Header status chips** — *🛢 Oil Change due in 920 km · 🎫 1 open ticket · 🔧 workshop 5 Sep*
   — from the attention map the list already ranks by, so the top of the detail says why it was
   opened.

### 8.4 Rulings that surfaced while building (owner's call, no code yet)
- **Backdate windows:** Shabib and Waseem are **0 days** (today only); Qasim/Mashood/Kanan 2;
  Taimur 30. A bill ATTACHED to a service inherits the service's date and so **bypasses the
  window** — Shabib filed a 20-day-old bill via the service route that he cannot file directly.
  The service date is manager-recorded truth, so this is arguably right; but it is a way round a
  policy. Keep, or enforce the window on attach too?
- **The fuel rules do not see service logs.** The odometer now does (Q4), but
  `FuelClaimRules`' plausibility baseline still measures against attendance + claims only, so an
  ASSERTED meter on a claim is judged against a lower floor than the bike's real km. Correct
  for a typo guard; still two definitions. Extend the baseline to service logs?
- **Is Shabib's 0-day window intended?** He files the most maintenance after Mashood and cannot
  backdate a receipt by a single day from the direct form.

### 8.5 Deliberately NOT changing
- No fuzzy matching anywhere — the owner's ruling stands.
- `t_fleet_service_log` stays rider-keyed; attribution by `vehicleForDay` is the same engine the
  countdowns use, and it works.
- The pending-only `editClaim` guard on money fields stays.

---

## 9 · BUILT, 3-Sep round 2 — the panel now has ONE clock

§8.1's "one history" and §8.2's mobile parity are **done**, plus a cause §8 had not identified.

**The real fault was two time controls, one of them off-screen.** The panel's period chips were
new; everything money-shaped still obeyed the SCREEN-header month picker, which scrolls away the
moment a vehicle is opened. Day-by-day was never broken — August has 25 day rows; the panel was
pointed at September with no visible way to move.

**Shipped**
- **Sticky header** — name · keeper · km · 🛢 due/overdue chip · ➕ New maintenance · 🛠 Record
  service. (The actions already existed; they were above the fold, which for a manager is the
  same as absent.)
- **One time bar** — `‹ August 2026 ›` stepper + *That month · Last 3 months · This year ·
  All time*. Stepper refetches (fuel and day-by-day are per month); ranges filter client-side.
- **Activity** replaces both lists — *Service & repairs* (all-time capable), *Fuel — <month>*,
  *Day by day — <month>*. Month-only views narrow the window rather than show a partial.
- **Opens on a month with activity**, so a fresh month is not three zeros.
- **Mobile matched**: own month + stepper, honest heading, same month-filter fix.
- **⚠⚠ `costSummaryFor` now takes the month.** It was hard-wired to the current calendar month, so
  the relabelled tile read "AUGUST 2026 — Nothing filed" above August rows worth Rs 400. Past
  months are closed at both ends. Aug 34,010 · Jul 27,340 · Jun 11,250 · Sep 0.

**Still open from §8.3–8.4**
- Rs/km beside the fuel figure per tile; open-ticket and next-workshop chips in the header.
- "Classify ▾" on unclassified rows — **already possible today via *Edit reading***, which asks
  which service was done. It is discoverability, not capability.
- The §8.4 rulings still stand: backdate window is now 14 days for roles 10/17 and covers EVERY
  expense category; the 9 negative leave rows are unrepaired.
