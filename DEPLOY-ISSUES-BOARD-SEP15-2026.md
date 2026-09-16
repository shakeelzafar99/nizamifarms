# DEPLOY — 🛠 Bikes "Issues" board + company-only tickets (15-Sep-2026)

**NO SQL. No new permission. Nobody logs out or back in.**
Web and mobile can ship together, or web first and the APK later — the old APK is unaffected
because nothing existing changed shape (every new field is additive).

⚠ This round shares the two repos with the **Supplies Round 2** work
(`DEPLOY-SUPPLIES-ROUND2-SEP15-2026.md`). The file lists below are **this round only** — check
`git status` and upload deliberately if you are shipping both.

---

## 1. Web files to upload (7)

| File | New? | Why |
|---|---|---|
| `app/Services/Riders/VehicleIssueBoard.php` | **NEW** | the board: every judgement, one place |
| `app/Services/Riders/VehicleTicketService.php` | changed | company-only raising; `listFleetForReader` |
| `app/Http/Controllers/CRM/VehicleTicketController.php` | changed | `board` / `apiBoard` / `boardPage` |
| `routes/web.php` | changed | `/fleet/issues`, `/fleet/issues-board` |
| `routes/api.php` | changed | `/rider/vehicle-issues` |
| `resources/views/pages/riders-map/partials/fleet-issues.blade.php` | **NEW** | the board UI (shared partial) |
| `resources/views/pages/riders-map/partials/fleet.blade.php` | changed | 3rd mode button, include, close tail |
| `resources/views/pages/fleet/issues-board.blade.php` | **NEW** | the planners' standalone page |

### ⚠⚠ `/api/public/xclean` IS REQUIRED
Both `routes/web.php` and `routes/api.php` changed, and three Blade views changed or were added.
Without the cache clear the new routes 404 and the Bikes tab renders the old two-button bar.

---

## 2. Mobile files (3) — build the APK after the web is up

| File | New? |
|---|---|
| `src/components/FleetIssuesBoard.js` | **NEW** |
| `src/utils/ticketClose.js` | **NEW** |
| `src/screens/FleetScreen.js` | changed |
| `src/screens/VehicleTicketsScreen.js` | changed |
| `src/components/FleetVehicles.js` | changed |

⚠ **Web first if you ship them apart.** The phone's new tab calls `/rider/vehicle-issues`; against
an un-updated server it 404s and the board shows "could not load" (which it says honestly — it
never claims "nothing is open"). Everything else on the phone works either way.

---

## 3. What changed, in order of who notices

### 3.1 A ticket can only be raised on a COMPANY machine (owner ruling)
- Refused server-side in `VehicleTicketService::open()` for **everyone**, riders and managers
  alike — the rule is about the machine, not the person.
- The phone's bike picker and the web's "🎫 Open an issue" button no longer offer an own bike.
- ⚠ **Reading is deliberately NOT narrowed.** `ownMachineIds()` / `visibilityScope()` are
  untouched, so any ticket that already exists on an own bike stays readable and repliable by
  whoever holds that machine. On the replica there are none — but a rule that hid a live
  conversation from the man in it would be the wrong fix.
- A rider whose only machine is his own bike gets a sentence that says so, not the old and untrue
  "No bike is assigned to you right now".

### 3.2 The flow now names the bike
- The phone's report sheet always shows **"This issue is about &lt;plate&gt;"** — with one machine,
  with two (after he picks), opened from a machine's page, and when a manager raises it for a
  rider (`subject_machine` in the list payload answers "which bike would this land on").
- The web form is titled "Open an issue on &lt;plate&gt;" and says where it will be recorded.
- The success message names it: *"Reported on DCR-799. A manager will look at it."* Composed on
  the server, so both surfaces confirm the same machine.

### 3.3 The new third tab — 🛠 Issues
`👤 Riders · 🏍️ Vehicles · 🛠 Issues` on both surfaces. One card per machine, worst first:
urgent → missed workshop → unanswered → waiting on us → **workshop done but still open** →
proposal awaiting a planner → waiting on the rider → booked → quiet machines collapsed and counted.
Each card carries a one-line attention sentence **composed on the server**, the ticket rows with
who spoke last and whose turn it is, the machine's workshop line, and a greyed history footer.

### 3.4 Close from the board, one engine
Web reuses the existing `flCloseTicket()` dialog; its tail now refreshes the rider drawer, the
vehicle panel **and** the board. The phone's close flow moved to `utils/ticketClose.js` and both
the thread screen and the board call it. New: the dialog names unread messages you have not read
before you close. It warns, never blocks.

### 3.5 The planners' read-only door
`/orders/riders-map/fleet/issues-board` — its own page rendering the **same partial**.
Gated on holding a key, never on a role type. Farooq sees every machine's card, workshop line and
ticket titles; `threads:false` means no thread, no Close, no Schedule.
⚠ Verified: Farooq 200 with the board, a plain rider 302 away from it.

### 3.6 One quiet correctness fix that came with it
`submitWorkshopForm` on the phone now sends `vehicle_id` when a **card** opened the sheet. It used
to send only the rider, and `schedule()` resolves the machine from him — so booking from a
machine's card for a keeper who holds two (a van driver still owns his bike) could book the wrong
one. Rider-first bookings are unchanged.

---

## 4. Proof

| Gate | Result |
|---|---|
| `php test_issue_board.php` | **69 / 0** — every scenario STAGED in a rolled-back transaction |
| `php test_ticket_company_only.php` | **35 / 0** |
| `php test_vehicle_tickets.php` | **129 / 0** |
| `php test_fleet_personas.php` | **80 / 0** |
| `php test_workshop_approval.php` | **137 / 0** |
| `node scratchpad/issboard_harness.cjs` | **42 / 0** — drives the REAL page JS |
| `node scratchpad/jscheck.cjs <the 3 blades>` | all parse |
| `php scratchpad/blade_compile_issues.php` | all compile |
| `php scratchpad/iss_http_smoke.php <uid>` | real HTTP kernel, 3 personas |
| `npx jest --runInBand` (mobile) | **440 / 440** |

⚠ **Run the mobile suite with `--runInBand`.** In parallel, six unrelated suites fail on load
timing; serially all 440 pass. `__tests__/App.test.tsx` fails either way and did before this round.

### ⚠ One PRE-EXISTING red, not from this round
`test_workshop_visits.php` is **177 / 2**. Both failures are fixture drift against live replica
data: there is now a real ACCEPTED visit dated 16-Sep, so "a visit dated tomorrow … and counted"
sees 2 instead of its own 1, and that real visit legitimately outranks the suite's staged
unconfirmed one in the banner priority. Verified identical with this round's code reverted. The
fix is to assert deltas rather than fleet-wide absolutes (as `test_issue_board.php` does) — left
alone deliberately rather than weakening a guard I did not need to touch.

---

## 5. After uploading — a 60-second check
1. `/api/public/xclean`.
2. Open Bikes → the bar shows three buttons; press **🛠 Issues**.
3. Cards appear worst-first, with the chips across the top. Press a ticket row → the thread opens
   **under that row**, not at the bottom of the page.
4. Press **Close issue** on a row → the same dialog the vehicle panel uses, and after closing the
   board, the drawer and the panel all refresh.
5. Open a machine that is **not** a company bike → "Tickets & chat" is still there with its
   history, and there is **no** "Open an issue" button.
6. Visit `/orders/riders-map/fleet/issues-board` as a planner → the board, with no Close and no
   Schedule anywhere.

---
---

# PART B — a workshop day answers NAMED issues (15-Sep-2026, same round)

**Still NO SQL.** `t_ops_vehicle_ticket.workshop_visit_id` already existed and is the many-side:
N tickets → 1 visit. The visit's own `ticket_id` keeps meaning "the ticket this was raised from",
so every existing reader is untouched.

## B1. Additional files (on top of the list above)

| File | New? | Why |
|---|---|---|
| `app/Services/Riders/WorkshopVisitService.php` | changed | multi-link, release/move, `open_tickets`, `tickets` on the payload |
| `app/Http/Controllers/CRM/WorkshopVisitController.php` | changed | `ticket_ids` in, `open_tickets` out, warnings works rider-first |
| `resources/views/pages/riders-map/partials/fleet.blade.php` | changed | new `checklist` field type + the tick-boxes |
| `resources/views/pages/riders-map/partials/fleet-issues.blade.php` | changed | "covers: …" on the workshop line |
| `src/screens/FleetScreen.js` (mobile) | changed | `openWorkshopSheet()` + the tick-boxes |
| `src/components/FleetIssuesBoard.js` (mobile) | changed | "covers: …" |
| `test_visit_ticket_links.php` | **NEW** | the gate, 67 checks |

⚠ `/xclean` is already required by the main round; nothing new is needed.

## B2. What a manager now sees

Booking a workshop day lists **that machine's open issues, all ticked by default**, because a
bike going in for everything reported on it is the normal case. Untick one and it simply stays
in the queue. Each ticked issue is told about the date **in its own thread** and reads as
"Workshop set" until the visit is done. The Issues board's workshop line now says
`covers: tyres, seat` instead of leaving a manager to guess what the trip was for.

## B3. The rules that took the real thought

| Event | What happens to the linked issues |
|---|---|
| Booked directly (a planner) | linked, moved to `scheduled`, one line written into each thread |
| Booked as a **proposal** | ⭐⭐ the choice is **staked as pointers only** — no status moves and **not one line** reaches any thread. That is the 6-Sep ruling: the rider reads those threads and a planner may yet decline |
| **Approved** | the link completes — now they move to `scheduled` and now the threads are written to |
| **Declined** / auto-declined / withdrawn | pointers released, **silently** — a plan nobody was told about is not announced by its death |
| **Superseded** (booked again, or approved over a standing day) | the issues **MOVE** to the new visit. The bike is still going in, just on another day |
| **Cancelled** | released back to `acknowledged` (never `open` — a manager HAD answered them) and each thread is told |
| **Marked done** | back to `acknowledged`, and the pointer is **KEPT** — the board's "done but still open" line reads it |
| **"It did not happen"** | nothing changes. The errand still stands, so the issues are still at the workshop |

⚠⚠ **The client's list is never trusted.** Every id is re-checked in `resolveTicketIds()` against
the machine actually going in and against being open. Without that, a crafted payload could drag
another bike's complaint onto a visit and write into a thread the booker cannot even see.

## B4. Proof

| Gate | Result |
|---|---|
| `php test_visit_ticket_links.php` | **67 / 0** — all staged, all rolled back |
| `php test_workshop_approval.php` | 137 / 0 |
| `php test_workshop_contract.php` | 74 / 0 |
| `php test_workshop_selfpin.php` | 47 / 0 |
| `php test_workshop_snooze.php` | 18 / 0 |
| `php test_vehicle_tickets.php` | 129 / 0 |
| `php test_issue_board.php` | 69 / 0 |
| `php test_fleet_personas.php` | 80 / 0 |
| `node scratchpad/wsformcheck.cjs` | **43 / 0** — drives the real booking form |
| `node scratchpad/issboard_harness.cjs` | 44 / 0 |
| `node scratchpad/wsbannercheck.cjs` | 34 / 0 |
| `npx jest --runInBand` (mobile) | **441 / 441** |
| `php scratchpad/partb_http_smoke.php <uid>` | both warnings paths 200; a rider 403 |

### ⚠ Two PRE-EXISTING reds, neither from this round (both verified identical with it reverted)
- `test_workshop_visits.php` **177 / 2** — a real 16-Sep visit makes "tomorrow" count 2 and
  outrank the suite's staged unconfirmed one.
- `test_workshop_trip.php` **143 / 1** — a service-due assertion against live replica figures.

Both are the documented fixture-drift class: they assert fleet-wide absolutes instead of deltas.

## B5. Two bugs this work found in its own harnesses
- ⚠⚠ `wsformcheck.cjs` answered stubbed fetches **by call order**, so adding one request to the
  form silently mis-answered every other one and six assertions failed on a working build. It
  now routes by URL. A stub that depends on call order breaks every time a page gains a request.
- ⚠ The warnings endpoint 500'd on the rider-first path because one line still read
  `$data['vehicle_id']` — a key Laravel's `validate()` does not return when it was not sent.
  Caught by the HTTP smoke, not by any unit test.

## B6. After uploading — the 30-second check
1. Bikes → a machine with an open issue → **🔧 Schedule workshop**.
2. The form asks *"Which reported issues is this trip for?"* with every box ticked.
3. Untick one, book it. Open the ticked issues: each thread has the date; the unticked one does not.
4. The Issues board's workshop line reads `covers: …`.
5. Cancel the visit → the issues come back as "In progress", each thread saying it was cancelled.

---
---

# RULING 6 — "and is the complaint fixed?" on the done form (15-Sep-2026, same round)

**Still NO SQL.** Builds directly on Part B: the visit now knows which issues it went in for, so
those arrive **pre-ticked**.

## C1. Additional files

| File | Why |
|---|---|
| `app/Http/Controllers/CRM/WorkshopVisitController.php` | `closeable_tickets` out of `/types`, `close_ticket_ids` into `/done` |
| `resources/views/pages/riders-map/partials/fleet.blade.php` | the tick-boxes on the done dialog |
| `src/screens/FleetScreen.js` (mobile) | the same, on the done sheet |
| `test_done_closes_issues.php` | **NEW** gate, 35 checks |

## C2. What it does

Marking a workshop visit done now asks **"Which of these are now fixed?"**. The issues the trip
was actually for arrive ticked; anything else still open on that machine is listed unticked and
labelled *"not part of this trip"*. Ticking closes them through the same close engine as
everywhere else, and **the outcome note becomes the close note** — he has already typed what
happened, and asking twice is how close notes end up empty.

## C3. The ruling, enforced by the server and not by the form

⚠⚠ **A rider is asked nothing, and can close nothing.** `completionGate` deliberately lets the
RIDER mark his own visit done — that is the 2-Sep ruling — so `/types` returns him an empty list
and `/done` re-checks him through `VehicleTicketService::close()`. A rider posting ticket ids by
hand closes **zero**, which the gate proves rather than assumes.

Other rules worth not re-deriving:
- Only ids genuinely **open on that machine** are eligible; a foreign, closed or invented id is ignored.
- The closes run **after** `markDone`, never before — `markDone` puts linked tickets back to
  `acknowledged`, so closing first would have that write undo the close a second later.
- A failed close does **not** fail the visit. The visit is done; turning it into an error would
  make a manager mark it done twice, and the second attempt is refused.

## C4. Proof

| Gate | Result |
|---|---|
| `php test_done_closes_issues.php` | **35 / 0** — drives the CONTROLLER as both personas in one process |
| `node scratchpad/wsformcheck.cjs` | **59 / 0** — drives the real done dialog |
| every other suite listed above | unchanged and green |
| `npx jest --runInBand` (mobile) | **441 / 441** |

⚠ The gate uses `setUserResolver` rather than `loginUsingId`, because only ONE user may be
authenticated per process — a manager check written after a rider check silently runs as the rider.

## C5. Two more harness-fidelity bugs this found
- ⚠⚠ The fake DOM only ever **appended** parsed nodes, so re-opening the done dialog for another
  visit left the previous visit's tick-boxes in the registry — an assertion could pass or fail
  depending on what had been rendered minutes earlier. Replacing `innerHTML` now drops what that
  host produced, as a browser does.
- ⚠ The done dialog announces its result with a browser `alert()`. That is **pre-existing** and
  outside the 5-Sep round that removed dialogs from the *booking* flow, so the harness captures it
  for those sections instead of failing, and asserts the manager is actually told.

## C6. After uploading — the 20-second check
1. Bikes → a machine with open issues → book a workshop day covering some of them.
2. Mark that visit done. The form asks **"Which of these are now fixed?"** with the covered ones
   ticked and the rest listed but not.
3. Type an outcome, save. The ticked issues are closed with that text as the close note; the
   unticked ones are still open on the Issues board.

---
---

# DEVICE VERIFICATION + the two pre-existing reds (15-Sep-2026)

## D1. The two red suites are now green — both were fixture drift, not product bugs

| Suite | Was | Now | What was actually wrong |
|---|---|---|---|
| `test_workshop_trip.php` | 143 / 1 | **147 / 0** | It forced a job "overdue" by putting a 100 km override on whichever active type had the smallest interval — which on the current replica has **never been recorded on that bike**, so the result was `state: unknown` / "never recorded", not overdue, and `due()` correctly listed nothing. It now discovers a job with a real `last_meter` behind the odometer. |
| `test_workshop_visits.php` | 177 / 2 | **180 / 0** | Two fleet-wide absolutes. `latest.id === $wx` was a hidden claim about every other live visit, and broke when a real ACCEPTED visit dated tomorrow appeared (rank 2 legitimately outranks "not yet accepted", rank 3). It now asserts the ORDER of the suite's own two rows. `tomorrow === 1` is now a delta. |

⭐ Neither assertion was weakened: each still tests its rule, just scoped to the fixtures it staged.

## D2. Verified on a real phone (Galaxy S21, USB debugging, Taimur logged in)

⚠⚠ **Pushes were confirmed inert FIRST.** `FIREBASE_CREDENTIALS_PATH` points at a missing file, and
both doors were timed returning in milliseconds rather than the ~45 s a real send takes. The local
DB holds 52 real staff device tokens, so this is checked before anything that can notify. The
close during the test logged `Firebase: Skipping user push (not configured)` — exactly right.
⚠ **Pushes are left OFF**, which is the safe default for handing the session back.

Test data was tagged `DEVCHECK`, and `scratchpad/devcheck_issues_teardown.php` removed every row
afterwards (back to the original 13 tickets / 6 visits).

**What the device proved works:** the three-tab bar · the red count on the tab · every filter chip ·
the per-machine cards with stripe, chips and the server's attention sentence · whose-turn on each
row · the workshop line · Schedule workshop on a done-but-open machine · the quiet row · and a full
close: the shared dialog, the unread warning, the write, the thread's system line, and the board
re-deriving itself (badge 4→1, chips and stripe updated, the closed row moved into history).

## D3. ⚠⚠ TWO REAL BUGS THE DEVICE FOUND THAT NO TEST DID

1. **The Issues board rendered ON TOP of the Riders content.** `FleetScreen`'s render chain was
   written for two modes, so its final `else` meant "riders" only by accident — it actually meant
   "anything that is not vehicles". Adding a third mode drew the board AND the whole month's cost
   summary on one screen. The jest test renders the board in isolation and passed throughout.
   **The web had the identical bug and was fixed the same way earlier in this round** (`isCosts`,
   not `!isVeh`) — the mobile half was missed.
2. **An infinite reload loop.** `onTotals={t => setIssueCount(…)}` is a new function identity on
   every render, so `load` was too, so `useFocusEffect` re-subscribed and re-fired forever. The
   board sat on its spinner while requests went out continuously (proved by the token's
   `last_used_at` advancing every second). `load` is now dependency-free with the callback and the
   mode in refs. ⚠ Keep it that way.

## D4. One judgement the device also corrected

A machine with a completed visit AND a new day booked read *"Workshop done 11 Sep, 1 issue still
open"* — true, but the least useful thing to say about a bike going back in tomorrow. The
done-but-open rule now fires only when **nothing is booked**, and the totals and both clients'
filters match it, so the chip and the cards can never disagree.

## D5. Final proof

All twelve web suites and all three page harnesses green (`test_issue_board.php` 70/0,
`test_workshop_visits.php` 180/0, `test_workshop_trip.php` 147/0), mobile **441/441 `--runInBand`**.

---

# ROUND E (15-Sep-2026, later) — review fixes + the cards open collapsed

**Still NO SQL.** Nothing to run on the database, before or after.

Owner, after reading the review: *"go ahead with all three fixes and the collapsed cards"*, and on
the flow itself: *"i dont want to overcomplicate it so users dont understand what to do when… a
workshop assigned without a ticket should still work. it's not important to have a ticket — the
ticket is just to document that there was an issue."*

## E1. Files (no new files — all already in this manifest's upload list)

| File | What changed in this round |
|---|---|
| `app/Services/Riders/WorkshopVisitService.php` | an untick now MEANS something on a re-booking |
| `app/Services/Riders/VehicleIssueBoard.php` | one fleet-read rule instead of two |
| `app/Http/Controllers/CRM/VehicleTicketController.php` | the planners' page gate matches the board |
| `resources/views/pages/riders-map/partials/fleet-issues.blade.php` | the cards open collapsed |
| `resources/views/pages/riders-map/partials/fleet.blade.php` | the booking form sends an empty answer too |

⚠ `/api/public/xclean` is still REQUIRED — the same Blade views changed again.
⚠ Mobile: `src/components/FleetIssuesBoard.js` and `src/screens/FleetScreen.js` changed. Web first.

## E2. An UNTICK now means something (the bug the review found)

Booking a new day for a machine SUPERSEDES the old one, and the old day's complaints follow it —
which is right, because the bike is still going in, just on another date. But they followed
**unconditionally**, so a manager who deliberately unticked an issue still had it dragged onto the
new day, reading "Workshop set" against a trip it was never for. The form promises *"untick
anything this trip is not for — it stays in the queue"*; that sentence was false.

Now the ticked set is the whole truth, and there is no second way in:
- what he unticked is released from the new visit — pointer cleared, and `scheduled` → `acknowledged`,
  never back to `open` (a manager HAD answered it, and `first_response_at` still says so);
- **silently.** A complaint quietly dropped from a trip is a change of plan, not news for a rider,
  and "no longer being looked at" with no date attached is worse than nothing. The board is where a
  manager sees it;
- the thread he pressed "Schedule workshop" FROM is not exempt: the singular `ticket_id` is no
  longer folded in when the question was asked, and it is no longer written onto the visit row
  either — `linkedTicketIds()` reads that column, so it used to resurrect the very issue he excluded
  and the thread would later be told the visit was completed for it.

⚠⚠ **AN OLD CLIENT IS NOT AN EMPTY ANSWER.** A phone built before Part B sends no `ticket_ids` key
at all — it was never ASKED, so it cannot have unticked anything. Reading its silence as "cover
nothing" would unhook every complaint on every re-booking made from a stale APK. The presence of
the KEY is the signal, so **both clients now send it even when empty**, whenever the question was
actually on screen. `test_old_apk_compat.php` stays 28/0.

### …and a workshop day with NO ticket is still a completely ordinary booking
The owner's condition, and it is covered by its own section now (`§9`): the question is never
required, an empty answer books the visit, links nothing and writes into nobody's thread. Most
bookings are a routine service nobody complained about, and that path is untouched.

## E3. One fleet-read rule, not two

The board decided "does he see every machine" from canManage OR canSchedule OR the read grant, but
picked the TICKET reader from the read grant alone. Anyone who could schedule a workshop while
holding neither the ticket key nor a read grant would have got a card for every machine while his
tickets came from `listFor()` — which correctly hands a non-manager only the machines he HOLDS. Every
other machine would have rendered as **quiet**: a board under-reporting exactly what it exists to
show. Nobody holds that combination today, so this is a guard, not a live fix — the new test builds
the persona synthetically and fails without the fix (proved by reverting it).

## E4. The planners' page gate matches the board

`boardPage` admitted `view_bike_costs` and `view_rider_reports` as well. Adnan, the Manager role and
the expense-fund role hold those, and none of them grants a fleet read inside `VehicleIssueBoard` —
so they came through the door and landed on a board with **nothing on it**. The gate is now exactly
the four keys the board itself honours. Confirmed end to end through the real HTTP kernel: Taimur
200, Farooq 200 (`read_only`, `threads:false`), **Adnan 302 → /orders**.

## E5. The cards open collapsed (both surfaces)

A card used to print every ticket row, the workshop line, the Schedule button and the closed footer
at once, so on a bad week the sixth machine in trouble sat several screens down — on a view whose
whole job is "glance and know". Collapsed, a card is its **header, its tags and the ONE sentence the
server composed**; a tap opens the rest. Identical rules on the phone and the desk:

- ⚠ a **RED** machine opens itself — "not rideable", a missed day or an unanswered complaint must
  never sit behind a tap, or the stripe shouts while the card hides why;
- ⚠ so does every card while a **filter** is on — tapping "⏱ On us" IS the drill-in;
- ⚠ **unread counts moved onto the header**, because they are the one thing collapsing would
  otherwise hide, and a badge nobody can see is a badge that has stopped working;
- ⚠ the header is now the collapse control, so the machine's own panel became an explicit
  **"Open this bike"** link in the body (the old duplicate link in the workshop row is gone);
- ⚠ the web toggles the DOM directly rather than re-rendering, so an inline thread open in another
  card survives — the same reason the poll only refreshes the strip.

## E6. Proof

| Suite | Result |
|-------|--------|
| `test_visit_ticket_links.php` | **87 / 0** (was 63/4 — §6b, §6c, §6d and §9 are new) |
| `test_issue_board.php` | **76 / 0** (§7b new, and it fails without E3) |
| `test_fleet_personas.php` | **85 / 0** (§5b new — the page gate, incl. Adnan refused) |
| `test_workshop_visits.php` | 180 / 0 |
| `test_vehicle_tickets.php` | 129 / 0 |
| `test_workshop_trip.php` | 147 / 0 |
| `test_done_closes_issues.php` | 35 / 0 |
| `test_ticket_company_only.php` | 35 / 0 |
| `test_old_apk_compat.php` | 28 / 0 |
| `scratchpad/issboard_harness.cjs` | **58 / 0** (§5c new — the collapse markup) |
| `scratchpad/wsformcheck.cjs` | **61 / 0** (J2 new — the empty answer is still sent) |
| mobile jest `--runInBand` | **446 / 446** (5 new collapse tests) |

⚠ Two suites are red **at baseline too** and were not touched by this round — confirmed by
stashing: `test_fleet_regression.php` (36/7) and `test_core_flows_regression.php` (30/3). Mobile
`__tests__/App.test.tsx` also fails to load at baseline (a react-native-gesture-handler native
import); it contributes 0 tests either way.

⚠ The browser pane could not be used this round — the auto-mode classifier blocked setting the
forged local session cookie (see the `local-login-allowed-for-verification` note, which says to
report this rather than work around it). The real HTTP kernel smoke (`scratchpad/iss_http_smoke.php`)
and the two page harnesses were used instead; both drive the page's own JS.

---

# ROUND F (15-Sep-2026, later) — the three plan items that had been missed

**Still NO SQL.** Owner: *"go ahead with 2, 3 and 5"* from the plan-vs-built check. No new files;
the same upload list. `/xclean` still required (Blade views changed again). APK: the same two
mobile files (`FleetIssuesBoard.js`, `FleetScreen.js`).

| # | Plan item | What was true | Now |
|---|---|---|---|
| 2 | red count fetched once when Bikes mounts (§4.1/§4.2) | badge stayed 0 until the tab had been opened — the one moment it could not help | web: `flInit()` calls `flIssLoadStripOnly()` once, which now also feeds the badge; mobile: one GET on `FleetScreen` mount, same `issueCountFrom()` formula as the board |
| 3 | board refreshes after a booking / reply / close (§4.2) | only close did; Schedule from the board left "Nothing booked", a reply left "waiting on us", and the poll skips while a thread is open | web: `flIssRefresh()` on the booking and reply tails — redraws, forces the thread's card open, re-opens the same thread in ITS box (the reply tail's `flOpenTicket(id)` was redrawing into the rider DRAWER's box, so the board's thread never showed the reply); mobile: the board listens to `workshop:changed`, which FleetScreen already emits |
| 5 | identical chip strips (§2.3) | web had ⏳ Awaiting approval + 🔧 Booked, the phone did not | phone has both, with the web's exact filter rules |

Also: the web poll now skips while the tab is **hidden** — the browser pane showed a hidden tab's
throttled 60-s interval replayed as **15 identical requests 6 ms apart** the moment it was shown
again. A fresh load makes exactly one request in 8 s (checked in the pane).

**Verified in the logged-in browser pane:** the planners' page renders collapsed, a header click
opens it (chevron, workshop line, "Open this bike", closed footer); the Bikes tab fires the badge
request on first render. **Proof:** `issboard_harness.cjs` **65/0** (§13 new), `wsformcheck.cjs`
61/0, blades compile, mobile jest **448/448** (2 new). PHP untouched this round.

---

# ROUND G (15-Sep-2026, later) — 💬 CHAT: the history people can actually find

**Still NO SQL.** Same upload list plus **`app/Services/Riders/VehicleTicketService.php`** (already in
it). `/xclean` required. APK: `FleetIssuesBoard.js`, `FleetScreen.js`, **`VehicleTicketsScreen.js`**.

Owner: *"where is the chat history… a button called chat… subtly link the ticket details so the user
knows this history was for this date and whether closed or still open… without more baggage."*

## G1. What a user sees now
- **Board card, both surfaces:** the grey "✓ 5 closed · Show ›" footer is gone. In its place one
  button, **💬 Chat (N)**, N = every conversation on the machine, open and closed.
  - Web: expands, inside the card, "Earlier conversations, closed" — each row struck through with
    the server's context line and a message count, and clicking it opens the thread in place.
  - Phone: opens the existing conversations screen filtered to that machine with **closed shown by
    default** (owner's call). The inline closed list on the phone card was **removed** — one list,
    one thread renderer.
- **Quiet machines** with a history show **💬 n** after their name; tapping it goes to the same place.
- **Web history mode** (the ✓ Closed chip) rows now open the thread — that was the one dead end.
- **Inside every thread and on every history row, ONE context line**, composed by the server:
  *"Opened 6 Sep by Arslan · Closed 11 Sep by Qasim — “chain replaced”"* or
  *"Opened 6 Sep by Arslan · still open · workshop set for 16 Sep"*.
- No Chat button for a read-only planner (`threads:false`) — counts yes, way in no.

## G2. Server (`VehicleTicketService`)
`runList` joins the closer's name and the linked visit's date; one grouped query counts HUMAN
messages per ticket (system lines would inflate a silent thread). `shape()` gains `closed_by_name`,
`workshop_date`, `message_count`, `context_line` — all additive; older apps ignore them. The thread
endpoint shapes through the same path, so the list row and the thread header cannot disagree.

## G3. Also fixed, found in the pane
The Bikes month payload can land AFTER the user has switched to Vehicles or Issues; the late render
painted the costs verdict + notes over the other view. `flLoad` now re-applies the mode's hiding.

## G4. Proof
Driven in the logged-in pane: Issues tab → card → **💬 Chat (5) ▸** → five dated closed rows with
counts → "Delivery box issue" opens inline with *"Opened 5 Sep by Arslan Aslam · Closed 8 Sep by
Qasim"* above the messages; quiet row shows 💬1 · 💬1 · 💬2 · 💬4.
`issboard_harness.cjs` **73/0** (§14 new) · `wsformcheck.cjs` 61/0 · `test_vehicle_tickets.php`
129/0 · `test_issue_board.php` 76/0 · `test_visit_ticket_links.php` 87/0 · `test_fleet_personas.php`
85/0 · `test_workshop_visits.php` 180/0 · `test_done_closes_issues.php` 35/0 ·
`test_ticket_company_only.php` 35/0 · `test_old_apk_compat.php` 28/0 · mobile **450/450**.

## G5. Two bugs found by the owner's "will there be race conditions?" question — fixed
1. **Pressing Chat on one card re-drew every card**, which wiped a thread a manager had open in
   ANOTHER card and left `flIssOpenThreadId` pointing at a box that no longer existed — the poll
   then believed a thread was open forever and only refreshed the strip. The Chat box is now always
   in the DOM and the toggle flips its display, exactly like the card collapse.
2. **Closed rows were cached per machine for the page's life**, so a conversation closed a moment
   ago never appeared under Chat until a reload. The cache is dropped on every board load and any
   open Chat box is re-read.
Harness `§14` rewritten to prove both (`issboard_harness.cjs` **78/0**); driven in the pane after a
hard reload. Mobile has neither problem: its board never renders a closed list, and the thread is a
separate screen.
