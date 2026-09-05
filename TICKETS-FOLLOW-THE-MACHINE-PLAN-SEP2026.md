# Bike tickets follow the MACHINE — plan (5-Sep-2026)

> Owner rulings, 5-Sep: **(1)** "since the bike moved to someone else, the new owner should be
> the one seeing this ticket until it's moved back to Waseem." **(2)** "this issue box should come
> in vehicles too… currently it's coming in the riders part only."
>
> Both are the same principle: **a ticket belongs to the machine, and the machine's holder is
> whoever the registry says today.** Nothing new is invented — the registry already answers
> "who holds it", and the ticket list already accepts a `vehicle_id`.

---

## 0 · What the code does today (verified, not assumed)

| Question | Today | Where |
|---|---|---|
| Who may SEE a ticket | manager **or** `opened_by` **or** `opened_for_user_id` **or** holds the machine now | `VehicleTicketService::mayRead`, `listFor` |
| Who may REPLY | anyone who may see it (plus the 7-day re-open rule) | `reply()` → `mayRead` |
| Who holds the machine now | `VehicleResolver::currentVehicleFor` ∪ his own bikes | `ownMachineIds()` |
| Where the box appears | **rider** side-panel only (`flTickets`, fetched with `user_id=`) | `fleet.blade.php` ~1702 |
| Vehicle panel (web + mobile) | **no tickets at all** | `flvRenderDetail`, `FleetVehicles.js` |
| On handover | **nothing happens to tickets** — `VehicleService` has zero ticket references | `assign()`, `release()` |

So Waseem still sees "Chain lose hogai hai" purely because he is `opened_by`. Rajab, who now
holds DCR-799, sees it too (holds-now) — but was **never told** it exists.

---

## 1 · ONE visibility predicate (the ruling)

Replace the four-way OR with one question, asked in one place:

```
visible(user, ticket) = canManage(user)
                     OR ticket.vehicle_id ∈ ownMachineIds(user)     // holds it NOW
```

`opened_by` / `opened_for_user_id` **stop being standalone grants.** While the raiser still holds
the bike, holds-now already covers him; the moment it moves, it moves with the bike.

**`VehicleTicketService::visibilityScope($user)`** — a single method that returns either
`'all'` (manager) or the list of machine ids — and **every reader calls it**:

| Reader | Today | After |
|---|---|---|
| `mayRead` (thread, reply, close, reopen) | 4-way OR | `visibilityScope` |
| `listFor` (both list boxes) | 4-way OR | `visibilityScope` |
| `alerts()` / badge counts (mobile `ticketCounts`) | own query | `visibilityScope` |

⚠ **Three readers, one rule.** The bug we are closing exists because "may see" was written more
than once; writing the new rule three times would re-create it.

### Consequences the ruling implies (stated, not hidden)
- A rider who holds **nothing** sees **no** tickets — including ones he raised. That is the ruling.
- While a bike is **unassigned** (e.g. sitting at the workshop after a "Take back"), **only
  managers** see its tickets. When it is assigned again, the new holder sees them.
- The **history never moves and never hides**: `opened_by` stays on the row, the thread keeps
  every message. Managers see all of it. Waseem's name stays on the ticket he raised.
- `assigned_to` (the manager/mechanic working it) is untouched.

---

## 2 · The handover HOOK (one engine)

`VehicleService::assign()` and `release()` gain one call, after the registry row is written:

```
VehicleTicketService::onHandover(vehicleId, fromUserId|null, toUserId|null, actorId)
```

which, for every ticket on that machine in an OPEN status:
1. appends a **system message** to the thread — *"Bike moved from Waseem to Rajab"* / *"Bike
   taken back — with nobody"* — so anyone reading later knows why the voices changed
   (`kind = 'system'`, `user_id = null`, exactly like the existing status-change messages);
2. **pushes to the new holder** (Firebase, the same `notify()` path): *"DCR-799 ab aap ke paas hai —
   1 open masla: Chain lose hogai hai"* — he inherits a problem, he should hear about it;
3. bumps `last_message_at` so the ticket surfaces at the top of his list.

Non-fatal (`try/catch`, logged), idempotent per ticket, **no re-open, no status change** — moving
a bike is not an opinion about the fault. Gated on `VEHICLE_RULES` like every registry side-effect.

⚠ No SQL: `kind = 'system'` messages and Firebase pushes already exist.

---

## 3 · The box on the VEHICLE — web

**Reuse, not rebuild.** `listFor()` already honours `opts['vehicle_id']`; the rider box already
renders rows and opens the thread (`flOpenTicket`). The vehicle panel gets:

- **Sticky-header chip** — `🎫 1 open ticket` (red when any is `urgent`), next to the due chip.
  This is the §8.3 "open tickets" chip from the vehicle-page plan, now with a data source.
- **A "Tickets" block** under *Service* — same renderer as the rider box, fetched with
  `/orders/riders-map/fleet/tickets?vehicle_id=<id>&status=open&limit=10`, plus **➕ Report a
  problem** (opens the existing new-ticket form pre-set to this machine and its keeper — same as
  "New maintenance" was wired) and a *"Show closed"* toggle (`status=all`).
- Rows show **who raised it and when**, so a manager reading the machine's page sees the history
  across holders — *Chain lose · raised by Waseem 5 Sep · bike now with Rajab*.

Implementation: extract the row renderer out of the rider box into `flTicketRowsHtml(list)` and
call it from both places. **One renderer, two callers.**

---

## 4 · The box on the VEHICLE — mobile

`FleetVehicles.js` vehicle detail gains the same block, fetched from
`/rider/vehicle-tickets?vehicle_id=<id>&status=open` (`apiIndex` → `listFor`, same `vehicle_id`
support), rendered with the ticket card already used in `VehicleTicketsScreen`, tapping through to
that screen's thread. The header gets the same `🎫 N open` chip beside the due chip. **Manager
screen only** (riders do not have the Vehicles tab), so `visibilityScope` = `'all'` here — but it
is still called, never bypassed.

---

## 5 · Order of work + verification

| Step | Files | Proof |
|---|---|---|
| 1 `visibilityScope` + swap all three readers | `VehicleTicketService.php` | `test_vehicle_tickets.php` §new: raiser loses the ticket on handover, new holder gains it, manager unaffected, unassigned bike = managers only, own-bike rider keeps his own |
| 2 `onHandover` hook | `VehicleService.php`, `VehicleTicketService.php` | system message appears, push recipient = new holder, no status change, idempotent, `VEHICLE_RULES=N` ⇒ no-op |
| 3 web vehicle box + chip | `fleet.blade.php` | `drive_panel.js` harness: chip count, block present, `vehicle_id` in the fetch URL, one renderer |
| 4 mobile vehicle box + chip | `FleetVehicles.js`, `FleetScreen.js` | eslint 0 errors; device check when the phone is back |

No SQL. Web upload + APK. Existing suites (128 · 176 · 75) must stay green.

---

## 6 · Deliberately NOT doing
- No "raiser keeps a read-only copy" — the owner ruled it; if it ever comes back it is one line in
  `visibilityScope`, nothing else changes.
- No re-open or re-assign of the ticket on handover — the fault's status is a mechanic's call.
- No separate "vehicle tickets" table or endpoint — the machine key is already on every row.

---

## 7 · FOUND AFTER THE PLAN — the handover does NOT reach the rider's phone until midnight

**Evidence (5-Sep).** Prod log: `Vehicle assigned {vehicle_id:3, to_user:95 (Rajab), from_user:73
(Waseem), on:2026-09-05, by:79}` at **13:42:14**. Waseem's screenshots at **14:42**: *"MY VEHICLE
DCR-799 · since 5 Sep · 0 km"* and a full My Vehicle page with **Bike badlein** and **Is bike ka masla
batayein** — an hour after the bike was Rajab's. Replayed on the replica through the real endpoint:
after `assign(3, 95, today)` **both riders get `has_vehicle=true · DCR-799` for the rest of the day.**

**Cause — two resolvers, and the wrong one asked.**

| Question | Right resolver | Rule |
|---|---|---|
| *Whose was it on date D?* (km, fuel, service attribution) | `vehicleForDay($uid, D)` | assignment covering D — a bike released ON D still counts for D, so the morning's km stay with the man who rode them |
| *What is mine NOW?* (My Vehicle card + screen, meter demand, ticket picker, fuel door) | `currentVehicleFor($uid)` | OPEN assignment only |

`API\MyVehicleController::show()` line 58 asks **`vehicleForDay($uid, $today)`** for a NOW question.
Its `assignmentFor()` then looks for an OPEN row, finds none, and falls back to "today" — hence
*since 5 Sep · 0 km*. Every other `vehicleForDay` call in rider-facing code passes a real date
(24 sites audited — all attribution). This is the only one. (`VanService:262` takes
`$date ?: today` — a date question with a default; listed to verify, not to change.)

⭐ `VehicleResolver::773` already states the right idea for the live case:
`currentVehicleFor($u) ?: vehicleForDay($u, $date)`. Apply it here.

### The fix (small, and it goes FIRST — it is live today)
1. **`MyVehicleController::show`**: `$vehicleId = $res->currentVehicleFor($uid)`. No machine ⇒
   `has_vehicle=false` — the card disappears, the handover-request button offers a bike, the
   ticket button loses its default machine. **Keep** `vehicleForDay` for the month km/stint
   summary further down — that IS a date question and must still credit his morning ride.
2. **`assignmentFor()`** stops falling back to "today"; with no open row it returns null.
3. **The handover tells both phones** (extends §2's hook): push to the NEW holder as planned,
   and to the OLD holder — *"DCR-799 ab Rajab ke paas hai. Aap ke paas abhi koi bike nahi."* —
   so the phone does not have to be reopened to learn it. The Attendance screen already
   refetches `/rider/my-vehicle` on focus, so the next open is correct regardless.
4. **Test** (`test_fleet_personas.php`, new §): replay `assign(3, 95, today)` → old holder
   `has_vehicle=false`, new holder `true`, old holder's `vehicleForDay(today)` **still** the bike
   (attribution unchanged), month summary still credits his km.

⚠ No SQL. Web upload only — this is server-side; **no APK needed for the fix itself**.

### Why my first answer was wrong, so it is not repeated
I probed `currentVehicleFor` and `myMachines` — the NOW-rule paths — and never the endpoint the
phone actually calls. **Test the endpoint the screen calls, not the resolver you assume it calls.**

### Final order of work
**§7 fix → §1 visibility → §2 hook (both pushes) → §3 web box → §4 mobile box.**
