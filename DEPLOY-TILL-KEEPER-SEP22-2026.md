# DEPLOY — Till keeper (Sep-22-2026) ✗live

"Who touched my cash" + the daily count checkpoint. Built after the 20-Sep case: Taimur posted a
Rs 150,000 vendor payment against NF Cash from his phone by mistake. That posting was **correct**
— he is allowed to make cash payments and auto-approval is right — so **nothing was blocked**.
What changed is that the same event now *arrives*, and the balance now has an anchor to be judged
against.

> ⚠⚠ **THE WORKING TREE ALSO HOLDS UNRELATED UNCOMMITTED WORK** (Khaas / frozen recipes:
> `app/Http/Controllers/Khaas/**`, `app/Models/Khaas/**`, `app/Services/Khaas/**`,
> `app/Models/FIN/ReceiptDraftModel.php`, `VendorController`, `VendorProductController`,
> `VendorProductModel`, `VendorPurchaseItemModel`, `WarehouseInventoryModel`,
> `FrozenMonthService`, `resources/views/khaas/**`, `resources/views/fin/vendor/show.blade.php`).
> **None of it belongs to this batch.** Upload only the 19 files listed below.

---

## Order (do not reorder)

1. **SQL FIRST** — `PENDING-PROD-SEP22-2026-TILL-KEEPER.sql`, one statement at a time.
   ⚠ `ALTER TABLE` is not rolled back inside a transaction; check each result before the next.
2. **Web upload** (18 files).
3. **`/api/public/xclean`** — REQUIRED, `routes/web.php` and `routes/api.php` both changed.
4. **APK** — `build-production-apk-auto.bat` (1 mobile file).

Backward-compatible in both directions: an old APK ignores the new `till_count` field; a new APK
against the old server simply never sees it and asks nothing.

---

## Web — 18 files

**Modified (12)**
```
app/Http/Controllers/API/RiderController.php        checkOut() sends till_count; new tillCount()
app/Http/Controllers/FIN/Hub/HubController.php      who-filter, count merge, 4 new endpoints
app/Http/Controllers/HR/EmployeeLoanController.php  2 raw inserts now stamp entered_by
app/Models/FIN/AccountUserModel.php                 is_keeper
app/Models/FIN/LedgerModel.php                      entered_by + the "whose hand" rule
app/Observers/LedgerAuditObserver.php               WATCH now includes transaction_date
resources/views/fin/hub/account-detail.blade.php    chips, checkpoint rows, header chip, keeper tick
resources/views/fin/hub/partials/drawer.blade.php   Posted by / Requested by / Approved by
resources/views/fin/hub/partials/styles.blade.php   .hand-chip .back-chip .txn-hit
resources/views/layouts/app.blade.php               includes partials.cash-pill
routes/api.php                                      POST /rider/till/count
routes/web.php                                      account/{id}/count + watch/{count,list,seen}
```

**New (6)**
```
app/Models/FIN/CashCountModel.php
app/Models/FIN/LedgerWatchModel.php
app/Services/FIN/TillCountService.php
app/Services/FIN/LedgerWatchService.php
resources/views/fin/hub/partials/till-count-modal.blade.php
resources/views/partials/cash-pill.blade.php
```

## Mobile — 1 file
```
src/screens/AttendanceScreen.js   till-count sheet after check-out (queued behind cash-held)
```

---

## What it does

**F0 — whose hand.** New `t_fin_ledger.entered_by`, stamped in `LedgerModel::creating`, backfilled
from the audit log. ⭐ This is the foundation, because `created_by` **is not the actor**: on an
expense row `LedgerPostingService` sets it to `requester_user_id`, so Haider's petrol posted by
Shabib reads "Haider". **628 rows on the replica name the wrong person.** The rule —
`isSomeoneElsesHand()` — is *entered by me OR approved by me*, per the owner's ruling; without the
approver half, the 1,182 rider settlements Shabib waved through would all come back at him as
somebody else's entries.

**F1 — the Hub filter.** `Who: Everyone | Not me | Only me | <person>` on the account page.
Verified on real data: 1,297 rows = 115 "not me" + 1,182 "me", an exact partition.
⚠ Filters the LIST only — the running balance is still accumulated over every row, so the Balance
column keeps agreeing with the figure at the top of the page.

**F2 — the cash pill 💵.** A second right-edge bulb (cloned from the day-review bulb, own position
key, seats itself below it). Unread = applied rows on the tills you are **keeper of** that were not
your hand and are not order deliveries. Opening the drawer marks them read at the server's newest
id. Self-hiding: someone who keeps no till gets 0 and never sees it — which is why this ships with
**no new permission**. Today that is Shabib for NF Cash and Taimur for ONLINE (watched, never counted).

**F3 — the count checkpoint.** `is_keeper` on `t_fin_account_users` (seeded: Shabib on NF_CASH — counted and watched; Taimur on ONLINE — watched only).
At check-out the app asks what is physically in the till; the Hub has a `🏦 Count the till` button
for days he does not check out. ⭐⭐ **The box is never pre-filled** with the system figure — a
number copied off the screen records agreement that was never checked. Stored in
`t_fin_cash_count` with the system balance *and* `last_ledger_id` captured under a row lock: the
seal. The ledger then shows the count as a tinted row in its place in the day, carrying:

> ✓ Shabib counted Rs. 12,345.67 · books said Rs. 12,345.67 · match · at check-out
> ⚠ 4 entries dated on/before this count were added afterwards (net − Rs. 2,175.00) — #22114, #22116, #22121, #22171

That second line is the whole point: walk up the page to the last green count and every row that
rewrote history behind it is already named.

---

## Review pass (owner asked: "are all writers covered? don't assume")

**Every ledger writer, censused:** 20 Eloquent write sites (`LedgerModel::create` — all fire the
`creating` hook) + 2 raw `insertGetId` in `EmployeeLoanController` (patched by hand). Zero raw
`INSERT INTO t_fin_ledger`, zero `createQuietly`/bulk `insert`/`withoutEvents`, zero
mass-assignment from request input, and `content_hash` is never computed from the row's
attributes — so a new column cannot break dedup. On the replica, 14 days of rows on Shabib's
accounts contain **no null-actor ("System") rows at all**: no cron/webhook path writes to a till.

**Rider flows, checked one by one:** cash delivery → invoice on the rider's own float (not a
watched account); online delivery → invoice on ONLINE, L1-approved by Taimur; settlement to Shabib
→ `employee_deposit`, entered by the rider, `approved_by` Shabib ⇒ **his hand**, never in his pill.
All Eloquent; all stamp `entered_by`. Nothing about how they post changed.

**⚠⚠ Found and fixed: the pill would have rung on every online delivery.** 280 of the 420 rows in
Shabib's 14-day "not my hand" set were rider invoices / order payments / credit grants on ONLINE,
L1-approved by Taimur — ~15 bulbs a day about the approvals pipeline. `LedgerWatchService::
ORDER_FLOW_TYPES` now excludes `invoice`, `order_payment`, `customer_credit_grant`,
`tip_collected` from the **pill only**. What remains is 140 rows / 14 days — all Taimur's genuine
vendor payments, expenses, transfers, salaries. The Hub "Not me" filter deliberately stays
complete. Proved: an online delivery Taimur approved does not notify Shabib; his vendor payment
from the till does.

**Audit observer change is safe:** proved that saving a row without touching its date writes NO
`transaction_date` audit entry (Eloquent's date-cast dirty check holds), and that a real re-date
now writes one.

**"Will any endpoint fail or go empty because of the new column?" — rehearsed, not reasoned.**
Nothing copies ledger rows by position (no `INSERT … SELECT`, no archive table, no triggers), the
one view `v_financial_daily_summary` names its columns explicitly and still answers, and no code
inspects the ledger's column list. The only real failure mode is **deploy order** — web files
landing on prod before the SQL, when every ledger INSERT would have carried an unknown column and
every delivery, expense and settlement would have failed. So the schema was stripped from the
replica and the new code run against it: **ledger inserts still succeed** (the `creating` hook asks
`Schema::hasColumn` once per process and stays silent until the column exists), the **Hub account
page and `?who=others` return 200** listing all 1,297 rows (the raw `COALESCE(entered_by…)` in
`accountActors` degrades to `created_by`; the count panels are try/caught and simply absent), the
**pill endpoints answer 200 `{count:0}`** quietly instead of logging a 500 a minute, and the
account-users panel opens. Then the deploy SQL was run exactly as the owner will — top to bottom,
first time, clean — and everything came back (6,280 backfilled, keepers seeded, 2 tables). Either order
is now safe; **SQL first is still the order to use**, because until it runs there is no actor on
new rows and no count.

**⚠⚠ Owner's screenshot review (as Taimur): "a lot of clutter — deposits Shabib has to approve,
and why Qasim's NF Food payments?"** Root cause: the pill watched every account the viewer was
*tagged on* (may spend from). Taimur is tagged on 7, so he saw Qasim running his own NF Food till,
Shabib filing riders' petrol, and settlements Shabib had already approved. **Fixed: the pill now
watches only the tills you are KEEPER of** (`is_keeper` — the same source of truth as the check-out
count). Shabib → NF Cash only: 140 rows/14 days became **38 (~3 a day)**, almost all Taimur's
vendor payments, salaries, transfers and advances from the till, plus 3 deposits into it that
someone *else* approved. Anyone who wants their own till watched ticks "Holds the cash" on it.

**Owner's follow-up: "isn't Taimur holding the online account?"** He is — default on ONLINE, 624
of its rows in 60 days. Keeper had been cash-only because you cannot *count* a bank; but
**watching is not counting**. `TillCountService::keeperAccounts($userId, $cashOnly)` now serves
both: counting stays cash-only, the pill watches cash **and** bank. The SQL seeds **Taimur as
keeper of ONLINE** (step 7); the account-users panel offers the tick on bank accounts too, worded
"Answers for this account (told when others move it)". His pill: **23 rows / 14 days (~1.6 a day)**
— Shabib's transfers out of ONLINE, online salaries, online expenses — and he is never asked to
count it. Order deliveries on ONLINE stay out (pipeline). Proved both ways.

**First-day flood avoided:** with no watermark yet, only the last 7 days count as unread — one open
sets the real mark. Without this the bulb would have opened on "200" and meant nothing.

## ⚠⚠ Traps found while building (do not undo these)

- **`touched` must NOT be `updated_at > counted_at`.** The first version was, and it reported
  **1,665 "changed entries"** against one count on the replica — `updated_at` moves for settlement
  flags, bill images, bulk backfills and `saveQuietly()` stamping `balance_updated`. It now reads
  `t_sys_audit_log`, which records what a *person* changed — and, unlike `updated_at`, **survives a
  DELETE**, the one edit that erases its own row. Both id lists are capped at 8 + "+N more".
- **Blade will not parse a directive glued to the previous word** — `count@if(` is a syntax error
  (500 on the page). Keep the space, or use an inline ternary as the count line now does.
- **`LedgerModel::approvedBy()` already existed** — adding it again is a fatal redeclare.
- **The engine's edit bracket is `reverse() → mutate → apply()`.** Mutating `amount` directly leaves
  the stored balance on the old figure; the proof's §8 reconciliation catches it.
- **`App\Models\User` is the Authenticatable model**, not `SysAdmin\UserModel` (same table) — a
  guard rejects the latter.

---

## Verified

- `PROOF-TILL-KEEPER-SEP22-2026.php` — **79/79**, in one rolled-back transaction against the
  replica. Covers the actor rule on all six cases (including two real prod rows), the sign
  convention proved *against the engine's own balance movement*, backdating (26-day claim = 26,
  forward-dated = 0), keeper gating, the seal, drift in both directions, the next-count re-seal,
  the deleted-row tombstone, the pill's watermark (never walks backwards), and the reconciliation
  identity still holding afterwards.
- Real page driven over HTTP as Shabib: 200, filter partitions exactly, both checkpoint states
  render (green match / red short), chips and modal present, computed styles correct.
- `test_core_flows_regression.php` — 30 pass / 3 fail, **identical to baseline** (vehicle
  assignment, unrelated). `test_old_apk_compat.php` — 28/28.
- Mobile: `npx jest --runInBand` all pass; eslint error count unchanged at 3 (all pre-existing
  `exhaustive-deps`).

## After deploy

Ask Shabib to check out once on the phone — the till sheet should appear, and tomorrow's Hub header
should read "Last counted …". If he is not asked, confirm the SQL's step 6 set `is_keeper = 1`.
