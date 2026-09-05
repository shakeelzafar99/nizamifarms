# One job, one truth — linking every service reading to its bill (3-Sep-2026)

> **DESIGN CHANGED BY OWNER RULING, and the plan below reflects it:** an earlier draft matched a
> bill to a reading on *"meter within 100 km, date within 7 days"*. **Rejected — nothing is
> guessed.** The filer PICKS the service from his own un-billed readings, or says it is a new one.
> Guessing is what misfiled service log #8, and a tolerance is not auditable: nobody can later say
> *why* two rows were treated as one job.
>
> **STATUS — P0 BUILT (server), 3-Sep. ✗ not live.** Picker list, chosen-service validation with
> reading inheritance, the double-bill refusal, status-aware links, and both bill doors wired.
> No SQL. Guarded by `test_record_service_typed` §14 (158 green).
> **P1 (the pickers in the UI) and P2 (bill on the reading doors) are next.**

Owner's ruling: *"if meter entered it should give option to enter bill, and if no bill, both
riders and management can select which service they are entering the bill for, so they don't
re-enter the meter."* Plus Q1–Q5 from the vehicle-page plan, all answered **yes** (fuel stays its
own section for the machine averages).

This plan was built from an inventory of **every** door that creates a reading or a bill — not
from memory. The rider form taught us why.

---

## 0 · The inventory (verified in code)

### Doors that create a READING (a service log)
| # | Door | Persona | Sends | Bill? |
|---|---|---|---|---|
| R1 | Record service — web Bikes | manager | meter · type · date | optional, **linked** (built 3-Sep) |
| R2 | Record service — mobile Bikes | manager | meter · type · date | optional, **linked** |
| R3 | Mark visit done — mobile Bikes | manager | meter · type | **no** |
| R4 | Mark visit done — web (`flPostWorkshop /done`) | manager | meter (type = visit's) | **no** |
| R5 | *"Workshop aaj — ho gaya?"* prompt | **rider** | meter · type | **no** |

### Doors that create a BILL (a maintenance claim)
| # | Door | Persona | Endpoint | Knows about logs? |
|---|---|---|---|---|
| B1 | Create Request — rider app | **rider** | `/rider/requests` | **no** |
| B2 | Create Request — store mode (`StoreRequestScreen`) | manager for a rider | `/requests/store` | **no** |
| B3 | Requests page — web (`requests/create.blade.php`) | manager for a rider | `/requests` | **no** |
| B4 | New bike expense — web Bikes modal | manager for a rider | `/requests` | **no** |
| B5 | New maintenance — mobile Bikes | manager for a rider | `/requests/store` | **no** |
| B6 | Record service **with amount** (R1/R2) | manager | → `recordServiceBill` | **yes — linked** |

Every one of B1–B5 files a maintenance claim carrying a meter and a type **with no idea a log for
the same job may already exist**, and R3/R4/R5 write a log **with no idea a bill may already have
been filed**. `ServiceRecordService::record()` never looks at `t_req_master`. `done()` accepts a
`request_id` that **no client sends** — a dead field.

### ⚠⚠ Two more faults found while checking
- **A rejected bill keeps the service looking paid.** The history/evidence dedup hides *any*
  linked claim regardless of status, and nothing on the reject path touches the log. So after a
  rejection the log still reads "billed", and a re-filed bill has nothing to attach to.
- **Correcting a claim's reading does not reach its linked log.** `amend()` on a log mirrors to the
  claim (built 3-Sep); `correctClaim()` on the claim does not mirror back. Half a mirror.

### The two failure shapes this plan removes
1. **Two rows for one job** — reading first, bill later (or bill first, reading later): the
   engine keeps the higher meter and nothing says they are the same job.
2. **Two bills for one job — money out twice.** Manager records the service *with* the receipt;
   the rider files the same receipt from his phone. Nothing stops it today.

---

## 1 · The rule: the service is CHOSEN — ✅ BUILT (P0, server)

Four methods on `ServiceRecordService`, and every bill door calls them. **Nothing is inferred.**

| Method | What it answers |
|---|---|
| `unbilledServicesFor($riderId, $vehicleId?)` | *"Which of my services has no bill yet?"* — the picker's list, newest first, labelled **"30 Aug · Oil + Tuning · 27,906 km"** |
| `validateBillTarget($logId, $requesterId)` | *"May this bill attach to that service?"* — and hands back the reading to **inherit** |
| `attachBillToService($logId, $requestId)` | ties the pair |
| `liveBillLinks()` | the ONE reader of *"which claims are already spoken for"* |

### What happens when a bill is filed
- **A service was chosen** → the claim **inherits its odometer, its job and its date**, so the
  filer never retypes a meter he already entered. That was the owner's whole reason for asking.
  After creation, `log.request_id` is set.
- **The chosen service already has a live bill** → **REFUSED**, naming it:
  *"That service already has a bill — Rs 3,500 filed by Shabib. If that bill is wrong, reverse it
  first."* **This is the double-money guard.**
- **It belongs to another rider, or does not exist** → refused.
- **Nothing chosen** → "a new service": exactly today's behaviour, unchanged.

### ⭐ A link is only live while its claim is
`pending` / `approved` = live. **`rejected` / `cancelled` release the service**: it returns to the
picker, a new bill may attach over the dead link, and the rejected claim stops being hidden from
the history — so money that never cleared can no longer read as paid. Verified end to end.

### ⚠ Old APKs
An APK that never sends `service_log_id` behaves exactly as it does today — nothing is guessed on
its behalf. It is already prevented from filing an *untyped* maintenance claim with a meter
(`requireTypeForClaim`, built earlier today), which is the case that would otherwise produce a
stray unlinked reading. The double-bill guard protects every client that DOES send a choice.

### The mirror is now whole
`amend()` on a log mirrors the reading to its claim; `correctClaim()` mirrors back to its log;
removing a log keeps the money and says so. All three built and guarded.

---

## 2 · What each persona sees

### Rider (B1, R5)
- **Create Request → Maintenance:** a chip row above the fields — *"Yeh bill kis service ka
  hai?"* — listing his bill-less services from the last 30 days
  (*30 Aug · Oil + Tuning · 27,906 km*) and **"Nayi service"**. Picking one **locks** type, meter
  and date (shown, not typed) and sends `service_log_id`. The **RECENT ENTRIES** panel he already
  sees gains those bill-less logs with an *"Bill lagayein"* tap that preselects the same thing.
- **"Ho gaya?" prompt:** after the meter, an optional *Bill (Rs) · Kis account se · Photo* block
  — the same optional bill Record service has, filed through `recordServiceBill` (reading first,
  money second, linked), with the same *"Bill attached / No bill photo"* line.
- If he files a bill for a service the manager already billed → refused in plain Roman Urdu.

### Management (B2–B5, R1–R4)
- Every bill form (store-mode request, web Requests page, web Bikes modal, mobile New
  maintenance) gets the same **"This bill is for: [recorded service ▾] / a new service"** picker,
  scoped to the rider chosen (and the machine, on the vehicle page).
- Every bill-less log row in Past services (web + mobile) gets **"Add the bill"** → opens the bill
  form with the service preselected and locked.
- **Mark done** (mobile + web) gets the optional bill block. The web one becomes the same small
  modal Record service now uses — a `window.prompt` cannot carry a photo.
- The history row shows the truth of the link: *+ bill · waiting · bill rejected — file again*.

### Old APKs
The double-bill guard is **server-side**, so it protects any client that sends a choice. An APK that has never
heard of `service_log_id` is protected from both failure shapes from the day the server ships.

---

## 3 · Rulings applied (Q1–Q5)

| | Ruling | Where it lands |
|---|---|---|
| Q1 | legacy `oil_change` → **Regular**, `repair`/`general` → **Repairs**, none → **Unclassified** (shown, never hidden) | cost strip buckets |
| Q2 | lifetime **includes** pre-registry claims attributed by keeper-on-date, badged *from history* | cost strip |
| Q3 | **fuel is its own section** with Rs/km — the machine averages depend on it | vehicle page |
| Q4 | a service log's meter **feeds the odometer** — one more term in `computeCurrentMeter`'s MAX, same plausibility floor, attributed by `vehicleForDay` | `VehicleService` |
| Q5 | optional bill on the service-day prompt and on Mark done | R3–R5 |

Q4 note: once linked, the claim's `meter_at_fill` already counts; adding the log's meter changes
nothing for linked pairs and fixes the unbilled-service case. A typo is correctable via Edit, and
the mirror keeps the pair equal.

---

## 4 · Edge cases checked (so neither persona hits a gap)

| Case | Handling |
|---|---|
| Rider holds **two machines**, same job type on both within a week | machine is part of the match key when both sides know it; otherwise the picker asks |
| Manager billed it; rider files the same receipt | **refused** — the double-money guard |
| Rider billed first; manager records the service a day later | `record()` auto-links to the claim |
| Bill **rejected** | link goes dead: log reads *"bill rejected — file again"*, re-filing attaches over it, cost tiles exclude it |
| Bill still **pending** | log already counts for the countdown; ledger posts on approval; tiles show it as *waiting* |
| Amend the log | mirrors to claim (built) · **Correct the claim** → mirrors to log (this plan) |
| Remove the log | money stays, manager told (built) |
| Meter typed slightly differently on the bill (27,906 vs 27,900) | within tolerance → attaches; outside → treated as different, picker offered |
| Untyped legacy bill (old APK, no type) | `requireTypeForClaim` already refuses an untyped meter; a no-meter repair bill still files and can still be attached later by type-less match on date + rider |
| Manager files for rider X and picks a log | log must belong to X — otherwise refused |
| Workshop visit's dead `request_id` | retired — the matcher does what it was meant to |

---

## 5 · Files and phases (no SQL — `request_id` and its index already exist)

| Phase | What | Layer | Ships to |
|---|---|---|---|
| **P0 — close the money risk** ✅ BUILT | un-billed list · chosen-service validation with reading inheritance · double-bill refusal · status-aware links · correctClaim→log mirror | `ServiceRecordService`, `VehicleService` (dedup), `RiderController::createRequest`, `RequestController::store` | **web upload only — protects every APK in the field immediately** |
| **P1 — the pickers** | `/rider/services/unbilled` (self, or any rider with `manage_bike_service`); picker on B1–B5; *Add the bill* on history rows; RECENT ENTRIES shows bill-less logs | `RequestsScreen`, `StoreRequestScreen`, `requests/create.blade`, Bikes modal, `FleetScreen`, `FleetVehicles`, `fleet.blade` | APK + web |
| **P2 — bill on the reading doors** | optional bill block on the "ho gaya?" prompt and Mark done (mobile + web modal) → `recordServiceBill` | `WorkshopOutcomePrompt`, `FleetScreen`, `fleet.blade`, `WorkshopVisitController::done` | APK + web |
| **P3 — odometer** | service-log meters in `computeCurrentMeter` | `VehicleService` | web |
| **P4 — the vehicle page** | cost strip (buckets per Q1/Q2), fuel section (Q3), merged regular+repairs history with All-time, header actions | per `VEHICLE-PAGE-REDESIGN-PLAN-SEP2026.md` | APK + web |

**P0 first, alone.** It is server-only, changes no screen, and removes both failure shapes for
every client that exists today. Everything after it is convenience layered on a safe base.

**Tests:** `test_record_service_typed` §14 — attach in both directions, double-bill refusal,
rider isolation, rejected re-link, correctClaim mirror, and the ABSENCE of any tolerance in the linker code;
`test_old_apk_compat` gains a section for the untagged-client path.
