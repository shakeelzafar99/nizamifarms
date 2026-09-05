# Service records ↔ money — plan (3-Sep-2026)

> **STATUS: A and B BUILT, 3-Sep. ✗ not live** — 8 files + 1 SQL
> (`service_log_request_link_sep2026.sql`, applied to the replica). **APK required.**
> Routes changed ⇒ `/xclean` on deploy. B3 (attach a bill to an existing record) deferred.
> Tests: `test_record_service_typed` §11 + §12 — 111 green.

Two asks from the owner, one root cause between them.

- **A.** A way out when an **approved claim's odometer is wrong** — today nobody can fix it.
- **B.** A manager recording a service can only record the *work*, never the *money*.
  Should the money be linked, and optional?

---

## 0 · What is actually true today (verified, not assumed)

There are **two kinds of record**, and the split is *not* rider-vs-manager — both people can
already produce both:

| Record | Written by | Carries | Approval | Editable |
|---|---|---|---|---|
| **Expense claim** (`t_req_master`) | a rider filing maintenance; a manager via the claims screen | amount **+** meter + type + date | L1/L2 | **only while pending** |
| **Service log** (`t_fleet_service_log`) | manager "Record service"; a rider answering *"ho gaya?"* on a workshop visit; workshop completion | meter + type + date, **no money** | none | yes — Edit/Remove, any category |

Both feed the same countdown. `VehicleService::serviceEvidenceByType()` reads claims **and**
logs, and `beatsEvidence()` keeps the best per type (highest meter, newest date as tiebreak).

### ⚠⚠ The trap that shapes everything below

Because both sources feed one engine, **one job recorded twice appears twice** — once as a log,
once as a claim — and if the two meters differ by even a kilometre, **the higher one silently
wins with nothing on screen saying they are the same job.**

**Adding an amount box that quietly creates a separate claim would manufacture that duplicate at
scale.** So the design rule is:

> ⭐⭐ **One job = one row.** If a service entry carries money, the money must be *attached to
> that entry*, not filed as a second independent record that merely looks like it.

---

## 0b · Who files maintenance today, and how it gets approved (checked, 140 claims)

**Managers already file most of it.** The `by <name>` shown on the Past-services list is the
`requester_user_id` — the rider the cost is *attributed to* — **not** who entered it.

| Entered by | Filed | Approved | Pending |
|---|---|---|---|
| Mashood | 42 | 42 | 0 |
| Shabib | 33 | 32 | 0 |
| Kanan Anoos (rider) | 23 | 19 | 0 |
| Arslan Aslam (rider) | 23 | 23 | 0 |
| Taimur | 11 | 10 | 0 |
| Waseem (rider) | 6 | 5 | 0 |
| Haider Ali | 2 | 1 | 0 |

Managers 86, riders 54; 67 of 140 were self-filed. **Nothing is sitting pending.**

### ⭐⭐ Auto-approval is real — but it keys on the FILER'S OWN L1 RIGHT, not on "being a manager"

`Request\RequestController::store` reads the logged-in user's approval rights and stamps the
approval at the moment of filing: L1 right ⇒ `level_1_status = approved, approved_by = himself`,
and once every required level is satisfied the whole request is **`approved` immediately**.
No L1 right ⇒ pending, routed to an assignee. So the owner's model is correct, with a caveat:

| | L1 | L2 | His own expense lands |
|---|---|---|---|
| Qasim | ✅ | — | **approved instantly** |
| Shabib | ✅ | — | **approved instantly** |
| Taimur | ✅ | ✅ | **approved instantly** |
| Farooq | — | — | pending someone else |
| **Mashood** | — | — | **pending someone else** |
| Riders | — | — | pending someone else |

⚠ **Mashood files the most maintenance of anyone (42) and does NOT auto-approve.** So "manager
entering ⇒ auto-approved" is true for Qasim, Shabib and Taimur, and false for the highest-volume
filer. The new flow must say what will happen *to this user*, not assume.

### ⚠⚠ Two creation endpoints disagree — a pre-existing split-brain

| Endpoint | Auto-approves? | Used by |
|---|---|---|
| `POST /requests/store` → `Request\RequestController@store` | **yes**, by the filer's rights | the **web**, and the mobile **Bikes** screen ("🔧 New maintenance") |
| `POST /rider/requests` → `API\RiderController@createRequest` | **no** — always pending | the mobile **Requests** and **Attendance** screens |

**The same manager filing the same expense gets a different outcome depending on which screen he
used.** Not introduced here, but it decides one thing for us: **the new flow must post through
`/requests/store`**, so a manager's entry behaves the way he expects. Worth fixing the other path
separately — flagged, not bundled.

---

## A · Correcting an approved claim's odometer

**The problem.** `FleetFuelController::editClaim` refuses once approved:
*"An approved claim has money in the ledger — reverse it and file it again instead."*
That guard is right about money and wrong about everything else — **the odometer is not money.**
On AY-4771 the "Oil Change 767 km overdue" figure comes from an approved 17-Aug claim at
48,777 km. If that is a typo, there is no way to fix it, and the only workaround (record a manual
service at the right meter) leaves the wrong number sitting in the history for ever.

**Proposal — a narrow second door, not a loosening of the first.**

Allow, on an **approved** maintenance claim, correction of exactly two fields:

- `meter_at_fill` — the odometer
- `maintenance_type_id` — which job it was

and refuse everything else. Specifically **not** `amount`, **not** `expense_date` (it sets the
P&L period), **not** `vehicle_id` (it moves cost between machines) and **not** the category.

- **Permission:** the same key that lets you record a service (`manage_bike_service`) — if you
  can write the number you can fix the number, which is the rule `amend()` already follows.
- **Audit:** append to the claim's description in the same shape `ServiceRecordService::amend()`
  uses — `corrected 3 Sep 2026 by <name>` — so an audit can tell a chosen figure from a fixed one.
- **Recompute:** nothing to do. Countdowns are derived, so every surface self-corrects. Already
  proven: amending a past record moved its countdown and left the other type untouched.
- **UI:** the same **Edit** link now appears on approved claim rows, opening a *reduced* form —
  meter and service type only — captioned so it is obvious the money is untouched.

**Cost:** no schema change. One controller method, one small JS form, guards in the test suite.

---

## B · Optional money on a service entry

**Recommendation: yes — link it, keep it optional, and make the two one record.**

### B1 · What the manager sees

The existing "Record service" form gains an optional money section:

```
Service type   [Oil Change ▾]     (required, as today)
Odometer       [ 48,900 ]         (required, as today)
Date           [ 3 Sep 2026 ]     (required, as today)
─────────────────────────────────────────────
Bill (optional)
Amount         [        ]  ← leave blank if you are only recording the reading
Paid from      [ ▾ ]       ← only enabled once an amount is entered

⚠ This will add an expense of Rs 2,400 against Kanan Anoos.
  It will be APPROVED IMMEDIATELY because you are an approver.
```

### ⭐⭐ The warning is a requirement, not decoration (owner, 3-Sep)

> *"tell clearly to the manager that this will add expense in the system"*

The warning appears **the moment an amount is typed**, above the save button, and **states the
outcome for this particular user** — because §0b shows that outcome differs by person:

- filer holds L1 (Qasim / Shabib / Taimur) → *"…will be **approved immediately** because you are
  an approver."*
- filer holds no L1 (Mashood, Farooq) → *"…will go for **approval** before it reaches the ledger."*

⚠ Never a generic "this creates a request". A manager who believes it is queued when it in fact
posts to the ledger the same second is the failure this warning exists to prevent. The
confirmation button should read **"Record service + add expense"**, not "Save", whenever an
amount is present.

- **Blank amount → today's behaviour, unchanged**, and **no warning at all.** One log row,
  "no bill". This is the common case (Qasim entering a reading) and it must not get slower,
  noisier, or feel like it might spend money.
- **Amount filled → one job, two linked halves.** The service log is written exactly as now, and
  a maintenance expense request is filed through the **same service the rider's path already
  uses**, then linked back.

### B2 · The link is the whole point

Add **one column**: `t_fleet_service_log.request_id`.

- The history list renders **one row** per job, showing the money when there is money — instead
  of the log row and the claim row sitting on top of each other.
- `serviceEvidenceByType()` skips a claim that is already referenced by a log's `request_id`, so
  the same job can never be counted as two pieces of evidence.
- Deleting the service record asks what to do with the bill rather than orphaning it.

### ⭐⭐ Why the log stays the source of truth for "the work happened"

The tempting simpler design — *when there is an amount, just file a claim and skip the log* —
is wrong, for a reason worth stating plainly:

> **A claim only becomes evidence once it is approved.** So on that design, a service done this
> morning would not reset the countdown until somebody approved the bill — possibly days later,
> and the bike would keep reading "overdue" the whole time.

The service log resets the clock **immediately**, which is correct: the work happened whether or
not the paperwork has cleared. So the log records the work, the request records the money, and
`request_id` ties them together. They are deliberately allowed to be at different stages.

### B3 · Later — attach a bill to an existing record

Once B2 exists this is nearly free, and it matches how the work really happens: the service is
recorded on the day, the bill turns up afterwards. An "Add the bill" link on any bill-less
service row, filing the request and setting `request_id`. **Suggest deferring** until A and B2
are in use.

---

## Phasing

| Phase | What | Schema | Size |
|---|---|---|---|
| **A** | Correct meter + type on an approved claim | none | small |
| **B1** | Optional amount on Record service | 1 column (`request_id`) | medium |
| **B2** | One-row history + evidence dedup | (same column) | medium |
| **B3** | Attach a bill later | none | small — defer |

A is independent and can ship on its own. **Recommend A first**, since it fixes a live problem
with numbers already on screen.

---

## ⚠ Rulings needed before B is built

These are money rules, so they are the owner's call, not mine:

~~1. Approval routing.~~ **Answered by the code** (§0b): follow the existing rule — auto-approve
   on the filer's own L1 right. Nothing new to decide; **do not invent a second rule.**

1. **Payment source.** Should "Paid from" default to something (petty cash / a specific bank), or
   always be an explicit choice? *(Recommend: explicit, no default — it spends real money.)*
2. **Scope of the correction in A.** Meter **and** service type, as proposed — or meter only?
3. **Who may add money.** Any manager who can record a service, or a narrower set? Note this is
   already implicitly answered by L1 rights, but "who may see the Amount box at all" is separate
   from "whose entry auto-approves".
4. **Mashood.** He files the most maintenance of anyone and holds no L1, so his entries queue.
   Is that intended, or should he hold L1? *(A question about him, not about this feature — but
   it surfaced here and affects what his warning will say.)*
5. **The split endpoint** (§0b). Should `/rider/requests` be brought in line with
   `/requests/store` so the same person gets the same result from any screen? Recommend yes, but
   as its own change — it touches every request type, not just maintenance.

---

## Out of scope, deliberately

- Changing anything about how a **rider's** maintenance request works. It already links meter and
  money correctly and goes through approval.
- Loosening the existing pending-only `editClaim` guard. A is a separate, narrower door.
- Editing `amount`, `expense_date`, `vehicle_id` or category on an approved claim. Those move
  money or move it between machines, and the existing answer — reverse it and re-file — is right.
