# Shift Authority — who may change whose shift, and what needs Taimur's approval (PLAN, 6-Sep-2026)

Status: **BUILT 6-Sep-2026, NOT uploaded.** Owner decisions locked (§7); build record in §8.
Both phases shipped together. Tests: `test_shift_authority.php` 69/69 · `test_shift_authority_http.php` 57/57.

Owner ask (Shabib relaying Taimur, 6-Sep):

> Taimur wants to control the *management* shifts (supervisor, supervisor 2, Shabib). Nobody sets
> his own shift by default. Shabib's shift can only be set by Taimur; Shabib can set everyone else's.
> Farooq can set everyone except Shabib and Taimur. Taimur may restrict a person to a few shift
> types ("they only rotate between these"). Taimur can tick a setting that lets managers set their
> own shift. Others should not create new shift *types* freely — Taimur approves first. When
> Farooq's or Shabib's shift is changed, Taimur gets a banner to approve.

---

## 0. What the code does TODAY (verified on the local replica, 6-Sep)

| Fact | Evidence |
|---|---|
| Planners = holders of `manage_shifts` (web **and** mobile since the 6-Sep workshop SQL): roles 10 Management (Shabib, "Nizami Farms"), 14 Taimur, 18 Shabib-role (empty), 20 supervisor 2 (Farooq). | `t_sys_role_mobile_permission`, `t_sys_role_permissions` |
| Role 15 **supervisor** = Waseem, Haider Ali. They do **not** hold `manage_shifts`; their shifts are changed almost daily (Manager Shift / LaCarne / Primary 11). | `t_ops_user_shift_assignment` |
| **Shabib has set his own shift 19 times**, latest 2-Sep "Afternoon 1 PM · until changed", all from the web planner. Farooq twice. This is the trigger for the ask. | `t_ops_shift_assignment_log WHERE actor_user_id = user_id` |
| **The web write endpoints have NO permission check at all** — `/shifts/assign`, `/shifts/bulk-assign`, `/shifts` (create type), `/shifts/{id}` (edit type) are inside the plain `auth` group. The sidebar shows "Shift Planner" to every non-rider. Only the mobile wrappers check `manage_shifts`. | `routes/web.php:634-654`, `ShiftController` (no `hasPermission` anywhere), `sidebar.blade.php:599-618` |
| Mobile `StoreShiftsScreen` lists **delivery riders only**; a manager cannot see or change his own or another manager's shift on the phone. Management shifts are set on the web planner's **All staff** view (and the attendance page's shift-change modal, which posts to the same `/shifts/assign`). | `StoreShiftsScreen.js`, `ShiftPlannerController::weekData` filter |
| Shift *types* can be created from three doors: web `/shifts` page, the planner's "new shift" modal, and mobile "＋ new shift type" (`createShiftTemplateMobile`). No approval anywhere. | `ShiftController::store`, `planner.blade.php:112`, `RiderController:32728` |
| Every assignment write (web or mobile) funnels through **one engine**: `ShiftController::assignShiftToUser / bulkAssignShift / cancelShiftChange / removeShiftAssignment`. Mobile delegates to it. | `RiderController:32716-32723` |
| An approval pattern already exists and shipped yesterday (not yet uploaded): workshop day `proposed` → corner banner `#wsApprovals` for planners → approve / decline → push. Mobile twin `WorkshopApprovalBanner.js` in `TopBannerStack`. | `partials/workshop-alerts.blade.php`, `WorkshopVisitController::approvals/approve/decline` |
| Settings live in `t_fin_config` (key/value, e.g. `SHIFT_TARGET_HOURS`, `WORKSHOP_APPROVAL_LEAD_MIN`). Permissions are seeded by plain SQL and edited on Roles → Permissions. | `t_fin_config`, `workshop_approval_sep2026.sql` |
| Legacy door `POST /riders/shift` (`RiderProfileController::updateShift`) still writes the old `t_ops_rider_profile.shift_start/end` from the attendance page. | `attendance/index.blade.php:4601` |

⭐ Consequence: **one server-side gate in the shared engine covers web + mobile at once**, and it
also closes the "any logged-in non-rider can rewrite any shift" hole that exists today.

---

## 1. The model — three ideas, one page

### 1a. The ladder ("who may change whose shift")
Every planner gets a **rank**. Rule, in one sentence:

> **You may change the shift of anyone ranked below you — and not your own.**

| Rank | Person | Can change |
|---|---|---|
| 3 | Taimur | everyone, **including himself** (top rung — §1b) |
| 2 | Shabib | everyone except Taimur **and himself** |
| 1 | Farooq | everyone except Taimur, Shabib **and himself** |
| 0 (default) | everyone else — Waseem, Haider Ali, riders, the "Nizami Farms" admin login… | nobody (and they don't hold `manage_shifts` anyway) |

⭐ **"and himself" is written into every row on the page**, not left implied — the owner's point is
that this must read as an obvious part of the rule, not a footnote (ruling 6-Sep).

That single number reproduces exactly the three sentences in the ask, and adding a fourth manager
later is "drag him onto the ladder", not a new rule. Two people on the **same** rank cannot change
each other. The **"Nizami Farms" login is admin-only and never used** — it stays off the ladder at
rank 0 (Q1 ruling).

### 1b. Own shift
**ONE global switch, default OFF: "Managers may set their own shift."** No per-person override —
the three-state *Follow default / Allowed / Blocked* column from the first draft is **dropped**
(owner ruling 6-Sep: a knob nobody asked for, and it made the table hard to read).

- **OFF** → nobody on the ladder changes their own shift…
- …**except the top rung.** Taimur may always set his own, applied immediately: he owns these
  rules and there is nobody above him to ask (Q3 ruling).
- **ON** → everyone on the ladder may set their own, and a self-change still obeys §1c — so
  Shabib setting his own still goes to Taimur.

### 1c. Approval ("Taimur gets a banner")
Per-person toggle **"Changes to this person's shift need approval"** — default **ON** for anyone
placed on the ladder (Shabib, Farooq), OFF for riders. Rule for *who* approves, derived from the
ladder with no extra setting:

> **Approver = anyone ranked above BOTH the person being changed and the person making the change.**

- Shabib changes Farooq → above both = **Taimur**. ✔ (the ask)
- Shabib changes his own (when the switch is on) → **Taimur**. ✔
- Farooq changes Haider (if Haider is flagged) → Shabib **or** Taimur — Taimur is not a bottleneck.
- Taimur changes anyone → nobody above him → applied immediately, no banner.

### 1d. Allowed shifts ("only rotate between these")
Per-person list **"Allowed shift types"** — default *All*. When set, every picker (web planner
modal, attendance modal, mobile sheet) shows **only those** for that person, and the server refuses
anything else. **The top of the ladder is NOT bound by it** (Q2 ruling: free — he is the one
editing the list); the allowed ones are starred in his picker so he can see what he set.

Combines naturally with 1b: *"Haider rotates between LaCarne and Manager Shift"* is one row.

### 1e. New shift types
Global choice, default **"Anyone can propose, Taimur approves"**:
- *Only the top of the ladder* — others don't see "＋ new shift type".
- **Propose + approve (default)** — anyone with `manage_shifts` can propose; it shows as
  "⏳ Evening 5 PM — waiting for Taimur" in their own picker (not selectable) and as a card in
  Taimur's banner. Approve → it becomes a normal active type; Decline (with reason) → proposer told.
- *Anyone on the ladder can create* — today's behaviour.

---

## 2. What Taimur sees — ONE page: **Shift rules** (`/shift-rules`)

⚠⚠ **TAIMUR ONLY (owner ruling 6-Sep).** New permission `manage_shift_rules`, seeded to **role 14
(Taimur) alone — deliberately NOT role 10 Management (Shabib) and NOT role 20 (Farooq)**. Shabib,
who uploads the build, will not see this page or its ⚙ button; that is intended, and one row in
Roles → Permissions gives it to him later with no code change. The page and every one of its
endpoints check this key server-side, not just the button.

Reached by a **⚙ Shift rules** button in the Shift Planner header (next to "Shift types"),
rendered only for holders of the key.

```
┌ Shift rules ─────────────────────── only you can see this page ───────────┐
│ ⏳ Waiting for you (2)                                                      │
│  • Farooq → Manager Shift · from Mon 8 Sep · until changed · by Shabib     │
│      [✓ Approve] [✖ Decline]                                               │
│  • New shift type "Evening 5 PM" 17:00 · Mon–Sat · proposed by Farooq      │
│      [✓ Approve] [✖ Decline]                                               │
├ 1. Who may change whose shift ─────────────────────────────────────────────┤
│  3  Taimur      (Taimur)        can change everyone, including himself  ▲▼ │
│  2  Shabib      (Management)    can change everyone except Taimur          │
│                                 and himself                             ▲▼ │
│  1  Farooq      (supervisor 2)  can change everyone except Taimur,         │
│                                 Shabib and himself                      ▲▼ │
│  + add someone to the ladder                                                │
│  In plain words:  Taimur can change everyone, including his own shift ·     │
│  Shabib can change everyone except Taimur and himself · Farooq can change   │
│  everyone except Taimur, Shabib and himself.                                │
│  ☐ Managers may set their own shift                                         │
├ 2. Per-person rules ──────────────────────────────────────────────────────┤
│  Person      Allowed shifts                    Changes need approval        │
│  Shabib      All                          ✎     ● Taimur approves           │
│  Farooq      All                          ✎     ● Shabib or Taimur approves │
│  Haider Ali  LaCarne · Manager Shift      ✎     ○ No                        │
│  + add a person (anyone — riders too)                                       │
├ 3. New shift types ───────────────────────────────────────────────────────┤
│  ○ Only the top of the ladder   ● Anyone proposes, I approve   ○ Anyone    │
└──────────────────────────────────────────────────────────────────────────┘
```

Design notes (why it is this shape):
- **The plain-words line** under the ladder re-renders on every drag and on the own-shift switch,
  the same way the planner's "effect line" does — Taimur reads the rule back in English before
  saving. No rule matrix.
- **Waiting for you** sits at the top of the page AND as the corner banner elsewhere — a question
  waiting on him outranks settings (same ruling as the workshop queue).
- Section 2 rows exist only for people with a non-default rule. Everyone else = "all shifts, no
  self-assign, no approval", so the page stays a few rows long.
- Saving is per control (auto-save with a toast), like the Roles permissions page.

### What the OTHER planners see (the feedback that makes rules feel fair, not broken)
- Planner grid / All-staff rows: a **🔒** on people you cannot change, tooltip *"Only Taimur can
  change Shabib's shift"*. Row still readable.
- Assign modal: template dropdown shows **only the allowed types** for that person, with a grey
  note *"Taimur limited Haider to these"*; the Save button becomes **"Send to Taimur for
  approval"** when §1c applies, and the effect line says *"Farooq is told only after approval."*
- Awaiting chip in the planner header gains a second count: *"⏳ 1 awaiting approval"*.
- Own row: opens read-only with *"You can't set your own shift. Ask Taimur."* (or, when the switch
  is on and approval applies, the Send-for-approval path).
- "＋ new shift type" → **"Propose a shift type"**; after save: *"Sent to Taimur — you can use it
  once he approves."*
- Mobile `StoreShiftsScreen`: same three things (filtered types, "Send for approval" label,
  "Propose" wording). Because riders are rank 0 and Farooq's mobile work is riders, **mobile
  behaviour for the daily rider work does not change at all.** No rules page on mobile.

---

## 3. Where the rule is enforced — ONE gate

`app/Services/Ops/ShiftAuthorityService` (new):

```
decide(actor, targetUserId, templateId, action): Allowed | NeedsApproval(approverIds) | Denied(reason)
allowedTemplatesFor(actor, targetUserId): int[] | null (= all)
canChangeRow(actor, targetUserId): bool          // for the 🔒 in lists
approversFor(targetUserId, requesterId): int[]   // ladder rule §1c
```

Called at the top of `assignShiftToUser`, `bulkAssignShift` (per user), `cancelShiftChange`,
`removeShiftAssignment`, and `store/update` of templates. Step 0 of `decide()` is
**`manage_shifts`** (web half now exists) — this is what closes today's open web door.

**Bulk assign with a mixed selection** (Q7 ruling — the recommendation): apply the ones you may
apply **now**, send the rest **for approval**, refuse the ones you may never touch, and say so in
one message — *"Saved for 4 riders · 1 sent to Taimur for approval · Shabib skipped (only Taimur
can change his shift)."* Never refuse the whole batch over one row.

**The legacy `/riders/shift` door** (attendance page → `RiderProfileController::updateShift`, writes
the pre-shift-system `t_ops_rider_profile` times) gets **the same `decide()` check** rather than
being removed (Q4 ruling: whatever is safer — deleting a live door that the attendance page still
posts to is the riskier move). Retire it in a later round once nothing calls it.

Old APK safety: the server answers `success:false` + message for a denial, and `success:true,
pending:true, message:"Sent to Taimur for approval"` for a request — an old phone shows the
message and refreshes; it never silently "assigns".

---

## 4. Data (1 SQL, all additive, run FIRST; PHP schema-guarded like the workshop round)

1. `t_ops_shift_authority` — `user_id PK, rank TINYINT default 0, needs_approval TINYINT default 0,
   allowed_template_ids JSON NULL, updated_by, updated_at DATETIME`.
   (⭐ no `self_mode` column — §1b is one global switch now.)
2. `t_ops_shift_change_request` — `id, user_id, shift_template_id, mode, effective_from,
   effective_to, location_id, set_default_location, requested_by, requested_at DATETIME,
   status VARCHAR(20) ('proposed','approved','declined','lapsed','withdrawn'), decided_by,
   decided_at, decline_reason, source('web'|'mobile'), note`.
   On approve, the approver's session calls the **same** `assignShiftToUser` engine (actor =
   approver, audit note "approved request #N raised by Shabib") — so history, re-stamp, push and
   WhatsApp all happen exactly as they do today, only later.
3. `t_ops_shift_template` + `approval_status VARCHAR(20) DEFAULT 'approved'`, `proposed_by`,
   `approved_by`, `approved_at`, `declined_by`, `declined_at`, `decline_reason`. `list()` hides
   non-approved types from pickers except as a disabled "⏳ waiting" row for the proposer.
4. `t_fin_config` keys: `SHIFT_SELF_ASSIGN` ('N'), `SHIFT_TYPE_CREATE_POLICY` ('approval').
5. Permission `manage_shift_rules` (web + mobile halves) → **role 14 only**. Guarded
   `INSERT … WHERE NOT EXISTS`. ⚠ Do not seed it to role 10 or 20.
6. Seed the ladder: Taimur 3, Shabib 2, Farooq 1; `needs_approval=1` for Shabib and Farooq.

Nothing existing is touched; without the new PHP the tables are inert. Without the SQL the PHP
falls back to today's behaviour (guarded), except step 0 (`manage_shifts`) which is safe now.

---

## 5. Notifications (reuse, no new machinery)

| Event | Who | How |
|---|---|---|
| Shift change request raised | approvers (§1c) | corner banner card (web, polled 60 s + focus, on planner / attendance / riders-map, same `#nfCornerStack`) + `WorkshopApprovalBanner`-style card on mobile + FCM `notifyUser` per approver, type `shift_change_request` |
| Approved | requester (push "Approved by Taimur"); target gets the **normal** shift push + WhatsApp from the engine | existing seams |
| Declined | requester only, with reason | push |
| Lapsed | requester | push; card disappears |
| New shift type proposed / decided | same as above, card variant | same |

No dead banners: a card is removed by a decision, a withdrawal by the requester, or lapse.
**Lapse rule (Q5 ruling — agreed):** a request still undecided at **23:59 on its start day** is
marked `lapsed` (the day is over, nothing to apply). Approving late but within that day is fine —
the engine back-dates and re-stamps attendance already. No prod scheduler → the sweep runs inside
the `approvals` poll (`app()->terminating`), like the workshop cut-off.

---

## 6. Phasing (each phase ships whole and is useful alone)

**Phase 1 — Rules + gate (no approvals yet).** SQL parts 1, 4, 5, 6 · `ShiftAuthorityService` ·
gate in the engine + the legacy door · **Shift rules** page (ladder, own-shift switch, allowed
shifts) · planner/attendance/mobile feedback (🔒, filtered pickers, denial messages) · templates
`list()` takes `for_user_id`. **Effect on day one:** Shabib can no longer set his own; Farooq
cannot touch Shabib/Taimur; Taimur can restrict anyone to a set of shifts; the open web door is
shut. Rider work unchanged.

**Phase 2 — Approvals.** SQL parts 2, 3 · request table + approve/decline/withdraw endpoints
(`/shifts/requests…`, mobile twins) · corner banner + mobile card · "Send for approval" path in the
modals · shift-type proposals · lapse sweep · pushes.

Deploy order per phase: SQL → web `app/ routes/ resources/` → `/xclean` (routes change) → APK.
`manage_shift_rules` is web-only in Phase 1, so no mobile re-login is needed until Phase 2.

Estimated size: Phase 1 ≈ 1 service, 1 controller (rules), 1 blade, edits to `ShiftController`,
`ShiftPlannerController::weekData`, `RiderProfileController::updateShift`, `planner.blade`,
`shift-change-modal`, `StoreShiftsScreen`; Phase 2 ≈ 1 controller, 1 partial, 1 mobile component,
edits to the two modals.

---

## 7. Owner rulings — LOCKED 6-Sep-2026

| # | Question | Ruling |
|---|---|---|
| 1 | Rank of the "Nizami Farms" login (shares Shabib's Management role) | **Admin-only, never used → stays at rank 0**, off the ladder |
| 2 | Is the top of the ladder bound by a person's allowed-shift list? | **Free** — not bound |
| 3 | Does Taimur need to set his own shift? | **Yes** — top rung may always set his own, applied immediately, switch or no switch |
| 4 | Legacy `/riders/shift` door: gate or retire? | **Whatever is safer → gate it** with the same check; retire in a later round |
| 5 | Lapse an undecided request at the end of its start day? | **Yes, agreed** |
| 6 | Read-only web planner for Waseem / Haider (supervisor)? | **Not needed** — they only use the phone; web doesn't matter to them |
| 7 | Bulk assign with a mixed selection | **Recommendation accepted** — apply what's allowed now, send the rest for approval, report all three outcomes in one message |
| + | Per-person own-shift override (3-state column) | **Dropped** — one global switch only |
| + | Who sees the Shift rules page | **Taimur only** for now (`manage_shift_rules` → role 14); Shabib may get it later by ticking one permission |

**Still open before Phase 1 is built:** none that block. Two worth a sentence from Taimur when
convenient — (a) should Waseem/Haider be *added* to section 2 with an allowed-shift list on day one,
or left at "all shifts"? (b) does he want the 🔒 rows hidden from Farooq entirely rather than shown
locked? (Recommendation: shown locked — hiding people makes a planner think the grid is broken.)

---

## 8. BUILD RECORD — 6-Sep-2026 (both phases, not uploaded)

### New files
| File | What |
|---|---|
| `database/migrations/shift_authority_sep2026.sql` | ⚠ **RUN FIRST.** Ladder + request tables, template approval columns, 2 config keys, `manage_shift_rules` → role 14 only, ladder seed. All additive, re-runnable, pre/post-flight SELECTs. |
| `app/Services/Ops/ShiftAuthorityService.php` | The gate. `decide()` / `rowStateFor()` / `allowedTemplateIdsFor()` / `canEditTemplate()` / `templateCreateState()`. |
| `app/Services/Ops/ShiftChangeRequestService.php` | Queue, read, approve (replays the real engine), decline, withdraw, lapse sweep. |
| `app/Http/Controllers/Ops/ShiftRulesController.php` | The page + settings writes (`manage_shift_rules`) **and** approve/decline (ladder-gated). Two different gates in one file — see its class note. |
| `resources/views/pages/shifts/rules.blade.php` | The Shift rules page. |
| `resources/views/partials/shift-approval-alerts.blade.php` | Corner banner (4th on those pages; do not merge with the other three). |
| `NizamiFarmsMobile/src/components/ShiftApprovalBanner.js` | Phone twin, amber, in `TopBannerStack`. |
| `test_shift_authority.php` · `test_shift_authority_http.php` | 69 + 57 checks. |
| `forge_session.php` | Local-only helper to drive real pages as a real user. ⚠ Never upload. |

### Edited
`ShiftController` (the gate on assign/bulk/cancel/end + template store/update/destroy/setActive/setDefault, `list(for_user_id)`, authority block on `user-summary`) · `ShiftPlannerController::weekData` (row state, filtered templates, `can_manage_rules`) · `ShiftTemplateModel` (fillable) · `API/RiderController` (rider row state, 3 approval wrappers, mobile source stamp on `list`) · `routes/web.php` (12 routes) · `routes/api.php` (3 routes) · `planner.blade` · `shift-change-modal.blade` · `shifts/index.blade` · attendance + riders-map (banner include) · mobile `StoreShiftsScreen` + `navigation/index.js`.

### ⚠ Two back doors this round closed that the plan had not called out
1. **The web write endpoints had no permission check at all.** Step 0 of `decide()` is `manage_shifts`; before this, any logged-in non-rider could rewrite anyone's shift from the desk.
2. **Editing a shift TYPE moves everyone on it.** Re-timing "Manager Shift" from 11:00 to 14:00 changes Shabib's real hours without touching one assignment. `canEditTemplate()` restricts edit / delete / deactivate / set-default to the top of the ladder (a proposer may still tidy his own waiting proposal).

### 🐞 One real bug found by the tests while building
`lapseDue()` read `$r->shift_name` off a row whose query did not select it. Laravel turns that warning into an `ErrorException`, which the method's own catch swallowed — so rows were marked lapsed, the sweep reported **0**, and **the requester was never told his request had died**. Fixed by joining the template and making `notifyRequester()` null-safe throughout.

### Also in this round (owner ask, mid-build)
**The assign popup now opens on "One day only"** on all three surfaces (planner, the reusable attendance popup, mobile) and that option is listed first — it is the most frequent change. ⚠ The old *"did you really mean today only?"* confirm was **removed** with it: as the default it would have interrupted nearly every save, and the effect line states the same thing permanently without a click.

### Verified
- `test_shift_authority.php` **69/69** (service rules on real replica data, all in a rolled-back transaction).
- `test_shift_authority_http.php` **57/57** (real routes, real sessions, real writes, cleans up after itself).
- Regression: workshop-approval 110 · workshop-visits 176 · day-review 70 · fleet-personas 80 · handover-requests 85 · rider-visibility 15 · old-APK 28 · late-engine-drift PASSED.
- Mobile: **jest 198/198**; eslint **0 new errors** (the one error and the `App.test.tsx` suite failure are pre-existing).
- Inline JS in all five edited/new blades parses (`node --check`).
- Driven in a browser as **Taimur, Shabib and Farooq** on the local replica: page 200/403/403; Farooq sees Shabib locked with *"Only Taimur can change Shabib's shift"*; Shabib sees his own row locked with *"You can't set your own shift. Ask Taimur."*; Shabib's save became **Send for approval**, the card reached Taimur's corner banner, and approving it applied the change and cleared the queue. Test rows removed afterwards.

### Deploy order
1. **SQL first** — `shift_authority_sep2026.sql` (check the pre-flight ids in PART 6 against prod).
2. Web upload: `app/` `resources/` `routes/` → **`/api/public/xclean`** (routes changed).
3. APK.
4. ⚠ Nobody needs to re-log for Phase 1 on the phone — `manage_shift_rules` is not read by the app yet. The approval banner works off the ladder, not a permission, so it needs no re-login either.
5. ⚠ **Shabib will not see the ⚙ Shift rules button** — that is the ruling. To change it later, tick `manage_shift_rules` for role 10 on Roles → Permissions.

---

## 9. REVIEW — 7-Sep-2026 (shift rules vs the workshop round)

Owner asked for a careful check that the shift rules hold everywhere, and a review of Qasim's
workshop-approval round. Four real gaps found and closed; four reported for a ruling.

### Closed
| # | Gap | Fix |
|---|---|---|
| 1 | **The workshop pin is a second door onto somebody's day.** `WorkshopVisitService::applyShiftLocation()` writes a one-day `t_ops_user_shift_assignment` row without touching the gated engine. **Farooq holds a bike today**, so a visit can be booked for him and he could approve it himself — writing his own shift row. A planner could also pin someone above him, use a shift outside a person's allowed list, or use a shift TYPE still awaiting approval. | It now calls `ShiftAuthorityService::decide()` first and refuses only on DENY. The visit still gets approved; only the pin is skipped, with the reason in the message. `onHandover` stays ungated on purpose (it keeps an already-approved pin correct). |
| 2 | **`RiderProfileController::updateShift` had no permission check at all** — any logged-in user, rider included, could rewrite anyone's legacy shift times, still read as the attendance fallback. Ruling Q4 said gate it; §3 said so; it was never written. | Same `decide()` gate. |
| 3 | **Double-approve race** in `WorkshopVisitService::approve()` — the affected-row count was discarded, so two planners approving together both ran the pin, the ticket line and every push. | The update is now the claim; the loser is told somebody already answered. |
| 4 | **Deploy landmine** — `approvalEnabled()` only checks PART 1 of the workshop SQL. With PART 2 (the `manage_shifts` web seed) missed, nobody can approve, every booking becomes a proposal, and the sweep would auto-decline them all an hour before each shift. | `escalateProposals()` refuses to run when no role holds the permission — proposals pile up visibly instead of being silently declined. |

Two smaller ones in this round's own code: a purely-historical correction for a needs-approval
person was queued and then killed by the lapse sweep seconds later (now refused at once, naming who
can make it), and `list()`'s proposer check could match an unauthenticated caller (`0 === 0`).

### Reported, not fixed — each needs a ruling
1. **A new proposal silently supersedes an already-approved, already-pinned visit, and the rider is
   never told.** He may already have accepted it; the visit vanishes from his phone and his
   attendance pin with it. **Qasim can do this** — the same back door that was deliberately closed
   for handovers. Needs a decision on what message the rider gets.
2. **A same-day booking made after the cut-off is dead on arrival** — `schedule()` accepts it and
   says "Sent for approval", and the next poll auto-declines it. `approvalCutoffFor()` already
   exists; the booking form should warn or refuse. (The plan's §6 claim that same-day proposals are
   spared is only true before their own cut-off.)
3. **`dueReminders()` re-pushes riders already reminded** — pre-existing, but `approve()` clearing
   `reminded_at` makes it fire more often.
4. **`updateShift` inserts a rider profile with `active = 1`**, minting a delivery rider from a
   shift-time edit — the opposite of the deliberate `active = 0` in the planner's phone writer.

### Verified after the fixes
`test_shift_workshop_seam.php` **36/36** (new) · shift-authority 69 · shift-authority HTTP 57 ·
workshop-approval 116 · workshop-visits 176 · day-review 70 · fleet-personas 80 · handover-requests
85 · old-APK 28 · rider-visibility 15 · late-engine-drift PASSED · mobile eslint 0 errors.

---

## 10. Owner rulings on the review — 7-Sep-2026

| # | Ruling | Built |
|---|---|---|
| A | **Same-day booking after the cut-off** — agreed, don't accept it. | `schedule()` now refuses a PROPOSAL whose approval cut-off has already passed, naming the time it had to be decided by and offering the two ways out (later day, or a planner books it directly). A planner assigning directly is unaffected — he is asking nobody. |
| B | **Already reminded ⇒ don't push again.** | `dueReminders()` returns ONLY the visits it just flagged. It used to claim `reminded_at` for the new ones and then return every live visit for tomorrow, so one new booking dragged everybody else's reminder along with it. |
| C | **One shift endpoint — retire the legacy one.** | `POST /riders/shift` and `RiderProfileController::updateShift` are **deleted**. Its only caller was the "Manage Employee Shifts" modal on the attendance page, which nothing ever opened; that modal's rows are now read-only and its button opens the ordinary "Change shift" popup, which posts to `/shifts/assign`. Pinned by `test_shift_authority_http.php` §8b so the route cannot come back unnoticed. |

⚠ The two legacy COLUMNS (`t_ops_rider_profile.shift_start/shift_end`) stay — they are still the
resolution fallback for anyone never migrated. Nothing writes them any more: they are frozen
history. **Do not add a new writer.**

### Still open — the one the owner asked about
**A new booking silently supersedes an already-approved, already-pinned visit.** One open visit per
MACHINE: booking a bike that already has an open visit marks the old one `rescheduled` and clears
its shift pin. That is right for a genuine reschedule. It is wrong when the old visit was already
approved and the rider had been told (and perhaps confirmed): he hears nothing, his attendance pin
vanishes, and the replacement may be a mere proposal that is never approved. ⚠ Qasim can cause this
without holding `manage_shifts`. Needs a ruling on what the rider is told, and whether superseding a
LIVE visit should need a planner at all.

---

## 11. Changing something already approved — the flow (owner ruling 7-Sep)

> "If an already approved workshop visit or shift is being changed… the person doing this gets
> told what will happen. If he still proceeds it should notify or go for approval again, and then
> the rider has to be told about this too. Make sure the manager, the rider and the approver all
> know what is changing."

### The shape, in one line
**Told before · nothing moves until it is decided · everyone hears what changed.**

### 1. Told BEFORE, not after
Both engines now REFUSE the first attempt and answer with a question, not an error:
`success:false, needs_confirmation:true, replaces:{…}` at **HTTP 409**. Only a caller that sends
`confirm_replace` gets through.
- **Workshop** — `WorkshopVisitService::schedule()`, whenever the bike already has an approved day.
- **Shift** — `ShiftController::assignShiftToUser()`, when the change would overwrite a
  TEMPORARY override or an UPCOMING primary the rider has been **told about or has confirmed**.
  ⭐ Deliberately narrow: a standing shift is not a promise about a particular day, so ordinary
  day-to-day assigning never nags.

⚠ Server-side on purpose — the desk and the phone inherit one rule, and a screen cannot forget to
ask. An OLD client cannot send the flag, so it simply cannot replace an approved day; it reads the
refusal instead. That is the safe direction to fail in.

### 2. Nothing moves until it is decided
⭐⭐ **A proposal no longer kills an approved day.** It used to: raising a request marked the old
visit `rescheduled` and deleted its shift pin at once, even though nobody had answered it — the
rider kept the old date in his head with nothing behind it. Now a proposal supersedes only other
proposals; the approved day stands, still pinned, until `approve()` performs the swap. Declining
leaves it exactly where it was. A planner assigning DIRECTLY still swaps at once, because he is
not asking anyone.

### 3. Everyone is told what changed
| Who | Sees |
|---|---|
| The person making the change | The 409 question: the day/shift that exists, whether the rider **confirmed** it, and that he must be told again |
| The approver | `replaces` on the card, web and phone: "⚠ This REPLACES his approved day: Tue 9 Sep 09:00 · Ali Motors — he has confirmed it" |
| The rider | On approval (or direct assign) the push NAMES the old date first: *"Pehle 12 Sep ka plan tha, ab woh CANCEL hai. Kal Ali Motors par shift hai…"* |

### Copy rule (owner, 7-Sep)
Simple English for the simple part; **Roman Urdu when a detail has to land**. Buttons stay English
(OK / Cancel / Approve / Decline). So a warning reads
*"He already has an approved workshop day: Tue 9 Sep 09:00. Yeh us din ko badal dega — usko dobara
batana parega."* — English says what it is, Roman Urdu says what it means for him.
This extends [[alerts-copy-roman-urdu]] (rider = Roman Urdu, manager = English) rather than
replacing it: manager copy stays English until it has to carry a consequence.

### Verified
`test_shift_workshop_seam.php` §7b (17 checks: refused → confirmed → old day stands → approver sees
what it replaces → swap → old pin gone → new pinned → decline leaves it alone) ·
`test_shift_authority_http.php` §8a (the shift half, over real HTTP) ·
workshop-approval 118 · workshop-visits 179 · fleet-personas 80 · vehicle-tickets 135 ·
shift-authority 69 · day-review 70 · handover-requests 85 · old-APK 28 · rider-visibility 15 ·
mobile jest 198/198, eslint 0 errors. All four changed pages re-rendered (HTTP 200) with every
inline script block parsing.

⚠ **Four regression suites needed fixture updates** — they booked over an approved day, which the
new rule refuses. Two of them now assert the refusal itself instead of working around it. No
assertion was weakened; three were made stricter (they asserted "the rider sees nothing", which was
only true because the proposal used to delete his day — they now assert he does not see *the
proposal*).
