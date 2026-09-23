# Till keeper — "who touched my cash" + daily count checkpoint — PLAN (Sep-22-2026)

Status: **PLAN ONLY. No code changed.** Awaiting the owner's rulings (§7) before any build.

## 0. Why (the incident that started it)

On 20-Sep Taimur posted a Rs 150,000 vendor payment from the mobile app against **NF Cash (Main
Till)** by mistake. The balance moved; Shabib could not see *why* by reading the ledger, and
suspected a backdated entry. It took a SQL diagnostic (`DIAG-NF-CASH-BACKDATED-SEP21-2026.sql`)
to find one row. Three asks came out of it:

1. In the Ledger Hub, let Shabib see the entries that were **not his own hand**.
2. A floating **cash pill** (like the day-review bulb) that tells him when someone else moves
   his till — last 10, unread count, read once he looks.
3. At check-out, Shabib **counts the till** and stamps it — the way riders already confirm the
   cash they hold — and that stamp shows in the ledger as a sealed checkpoint, so a later
   backdated entry is caught against it instead of silently rewriting history.

Everything below was planned against the code as it is today (read, not assumed). Facts that
shaped the design are marked ⭐; traps ⚠.

---

## 1. What exists today (verified)

| Thing | Where | What it does |
|---|---|---|
| Account page | `HubController::accountDetail` → `buildAccountLedger()` (~l.883) → `fin/hub/account-detail.blade.php` | Server-rendered. Rows for the account in the window, ordered by `transaction_date` then `created_at`; running balance for asset company accounts; grouped by day. Chips: 30d / 90d / 1yr / All (`?days=`). Row click opens the drawer (`partials/drawer.blade.php`). |
| "Balance right now" | `AccountModel::getEffectiveBalance()` | For NF_CASH = the **stored** `t_fin_accounts.current_balance`, moved only by `BalancePostingService` one applied row at a time. ⭐ Verified exactly rebuildable from `balance_updated=1` rows (gap 0.00). |
| Who really typed a row | `t_sys_audit_log` (`entity_type='ledger'`, `action='created'`, `user_id`, `source` web/mobile/system) | ⚠ `t_fin_ledger.created_by` is **the requester** on expense rows (`LedgerPostingService` ~l.397/554: `'created_by' => $request->requester_user_id`). Haider's petrol posted by Shabib says *Haider*. The audit log says *Shabib, web*. |
| Date edits | `LedgerAuditObserver::WATCH` | ⚠ `transaction_date` is **not watched** — a re-date writes no audit row. (Today no screen edits the date: `LedgerController::updateTransaction` validates only amount / description / bill_image. Backdating happens only at entry time by picking a past date.) |
| Floating bulb pattern | `partials/day-review-pill.blade.php` (included from `layouts/app.blade.php`) | Right-edge draggable pill with count + slide-in drawer; polls `/hr/day-reviews/pending-count` every 60 s; one pulse when the count *rises*; snooze in `sessionStorage`; endpoint answers `0` for anyone without the permission so the pill self-hides. ⚠ Plain `<style>` not `@push` (layout include). Bottom-left = checkout-stuck stack, bottom-right = `#nfCornerStack`; right edge is the bulb's lane. |
| Rider "cash you are holding" | `RiderController::checkOut` → `cash_held` (via `getRiderCashHeld()`), modal in `AttendanceScreen.js` (`showCashModal`), `POST /rider/attendance/confirm-cash` → `confirmCash()` stamps `t_ops_attendance.cash_confirmed_amount / _at / cash_confirm_status` | ⭐ This is the engine to reuse. It is **a record, not money**; idempotent per day; uses `AttendanceDay::currentOrJustClosedRow()` for the past-midnight case; shown on the Invoice Tracker per rider (`outstanding-invoices.blade.php` ~l.667). ⚠ The app *queues* other post-checkout dialogs behind this modal (Android replaces a live Alert). |
| Who may use an account | `t_fin_account_users` (`is_default`, `can_expense/vendor/advance`, `preferred_bank_id`), panel on the account page, `manage_account_users` to edit | NF_CASH today: Shabib ⭐default, Taimur, Nizami Farms (user 67). |
| Shabib on the mobile app | `t_ops_attendance` | 237 attendance days, last 17-Sep → he checks in/out on the app, so a checkout prompt reaches him. |

---

## 2. Foundation — the row must know whose hand posted it (F0)

Without this, both the filter and the pill would tell Shabib that *Haider* moved the till when
Shabib himself approved Haider's petrol. Everything else stands on this.

**F0.1 `t_fin_ledger.entered_by`** (nullable int, indexed). Set in the existing
`LedgerModel::boot()` → `static::creating` (beside the business-unit default): `auth()->id()`
or `null` for console/system. One engine — the audit observer already proved every writer goes
through Eloquent `create()`/`save()`. Two known exceptions: the raw
`DB::table('t_fin_ledger')->insertGetId` in `EmployeeLoanController` (l.374, l.493) — add
`'entered_by' => auth()->id()` there.

**F0.2 Backfill** from the audit log for rows since 4-Jul-2026:
`UPDATE t_fin_ledger l JOIN t_sys_audit_log g ON g.entity_type='ledger' AND g.action='created'
AND g.entity_id=l.id SET l.entered_by=g.user_id WHERE l.entered_by IS NULL`. Older rows stay
null → readers fall back to `created_by`.

**F0.3 The one rule, in one place** — `LedgerModel::isSomeoneElsesHand(int $viewerId): bool`:
```
actor      = entered_by ?? created_by
approver   = approved_by
return actor !== viewer && approver !== viewer
```
so a rider's settlement that **Shabib approved** is *his* hand (excluded), while Taimur's
mobile vendor payment (auto-approved, `approved_by` null) is *not*. Rows with no actor at all
(`null`) read as **System** and count as someone else's hand.

**F0.4 Close the silent-edit hole:** add `transaction_date` to `LedgerAuditObserver::WATCH`.
Three lines; nothing else changes.

**F0.5 The drawer's "Created by"** switches to the actor and gains an "Approved by" line and
the source (web / mobile). `$d['by']` in the blade is the one place.

SQL: 1 ALTER + 1 UPDATE. No new permission.

---

## 3. F1 — Hub filter: "Not my hand"

On the account page, a third chip group beside the period chips:

```
Who:  [Everyone]  [Not me]  [Person ▾]        (?who=others | me | <user_id>)
```

- Applied **after** the running balance is computed over *all* rows, then rows are dropped
  from display — otherwise the Balance column would lie. Day headers recount In/Out from the
  shown rows and say "filtered".
- Every row (filtered or not) that is someone else's hand gets a small chip:
  `👤 Taimur · mobile` — always visible, so the page teaches the eye even with no filter on.
- Backdated rows get `dated Sep 10 · typed Sep 20` in amber when `days_backdated > 0`.
  ⚠ Backdating is routine (10–50 rows/day, usually yesterday's petrol); the chip informs, it
  does not alarm. Only *other hand + backdated + large* is worth a look, and that is exactly
  what "Not me" sorted by amount shows.
- The `Person ▾` list = users tagged on this account in `t_fin_account_users` + "System".

Files: `HubController::buildAccountLedger` (+ `accountDetail` param), `account-detail.blade.php`.
No SQL beyond F0. No routes. `/xclean` for the view.

---

## 4. F2 — The cash pill 💵 (web)

A second right-edge bulb, cloned from the day-review pill, mounted under it in a shared
`#nfEdgeStack` host so the two never fight for the lane (each keeps its own remembered `top`).

**What counts as "unread for me":** applied rows (`balance_updated = 1`) on **accounts I am
tagged on** in `t_fin_account_users`, whose hand is not mine (F0.3), with `id >` my watermark.
For Shabib that is NF_CASH (and any other till he is tagged on); Taimur sees his own tills.
The endpoint answers `0` for a user tagged on no account, so the pill hides itself — no
permission needed, same self-hiding contract as the day-review bulb.

**Endpoints** (`routes/web.php`, finance group, JSON):
- `GET  /finance/hub/watch/count` → `{count, latest_id}` — polled every 60 s.
- `GET  /finance/hub/watch/list?limit=10` → the last 10 matching rows newest first:
  account, type, amount, **effect on the till (±)**, actor + source, typed-at, date shown,
  `days_backdated`, `unread` flag, link to the Hub row.
- `POST /finance/hub/watch/seen` `{last_seen_ledger_id}` → count returns to 0. Sent when the
  drawer opens (the owner's rule: "once he views it, it's read").

**Storage:** `t_fin_ledger_watch (user_id PK, last_seen_ledger_id, last_seen_at)`. One row per
person; upserted.

**Drawer:** each card = `− Rs 150,000 · Vendor payment · Ghousia Mutton` / `Taimur · mobile ·
Sep 20 13:45` / `NF Cash (Main Till)`; unread cards bold with a dot; backdated cards carry the
amber `dated … · typed …` line. Footer link "Open NF Cash → Not me".

**Nudge:** one pulse when the count rises (already the bulb's rule). No sound, no popup.

Mobile parity (a badge on the Ledger tab) is **not** in this round — the ask was the Hub.

Files: new `partials/cash-pill.blade.php`, include in `layouts/app.blade.php`, 3 routes,
`HubController` (or a small `LedgerWatchController`), 1 SQL (table).

---

## 5. F3 — The till keeper's daily count (the checkpoint)

### 5.1 Who is a keeper
`t_fin_account_users.is_keeper` (tinyint, new). Toggle "holds the cash" in the *Who uses this
account* panel, only offered on `account_category = 'cash'` accounts, editable by
`manage_account_users` (Shabib + Taimur). Shabib on NF_CASH = 1. ⚠ Not `is_default` — that
means "his default payment source", which Taimur may well be on his own account.

### 5.2 Where he counts
**Door A — check-out (mobile).** `checkOut()` adds a `till_count` block beside `cash_held`
for a keeper: `{account_id, account_name, system_balance, last_ledger_id, as_of}`. The app
shows a modal built on the rider cash-held modal's skeleton:

```
🏦 NF Cash (Main Till)
System says          Rs 55,156.98
How much are you holding?   [ ________ ]
                          ↳ live: "matches" / "short Rs 2,000" / "over Rs 500"
[ Skip for now ]                    [ Confirm my count ]
```
- ⭐ **No prefill.** An amount typed from memory is the whole point; prefilling anchors him to
  the system figure and the stamp proves nothing.
- Queued behind the rider cash-held modal when both apply (a keeper who is also a rider),
  using the same queue the own-bike follow-up uses. ⚠ Android alert-replacement trap.
- Posts `POST /rider/till/count {account_id, counted_amount, note?}`.
- Skip = nothing stored; the Hub header chip shows "not counted today" (see 5.5).

**Door B — Hub account page (web).** A `Count the till` button in the balance card for a
keeper, same modal, same endpoint via a web route — for a day he does not check out, or a
mid-day recount after a big vendor payment. Same table, `source='hub'`.

### 5.3 What is stored — `t_fin_cash_count`
```
id, account_id, user_id, counted_amount, system_balance, difference,
last_ledger_id,            -- MAX(t_fin_ledger.id) at that instant = the seal
counted_at DATETIME,       -- ⚠ DATETIME not TIMESTAMP (replica renders TIMESTAMP +2h)
source ENUM('checkout','hub'), attendance_id NULL, note VARCHAR(200), created_at
INDEX (account_id, counted_at)
```
`system_balance` and `last_ledger_id` are captured **inside the same transaction** as the
insert, under `lockForUpdate()` on the account row, so the seal is exact. Purely a record —
no money moves, no approval item, no ledger row (same charter as the rider engine).

### 5.4 The information row in the ledger
`buildAccountLedger()` merges the account's counts into the day groups by `counted_at`, as a
`kind: 'count'` item placed by time among that day's rows. Rendered full-width, tinted:

```
✓ Shabib counted Rs 55,157 at 21:05 (check-out) · system said Rs 55,157 · match
```
or `· short Rs 2,000` in red. And — the part that makes a checkpoint a checkpoint —
**drift since this count**, computed live for each count row:

- `late_rows` = rows on this account with `id > last_ledger_id` **and**
  `transaction_date <= DATE(counted_at)` → entries that arrived *after* the count but are
  dated *on or before* it. Shown as
  `⚠ 2 entries dated on/before this count were added later (net − Rs 1,045)`, each
  clickable.
- `touched_rows` = rows with `id <= last_ledger_id` and `updated_at > counted_at` whose
  `balance_updated` or amount changed (reversed/edited after the seal) → `1 earlier entry
  was reversed after this count`.

So when the balance "goes wrong", the reader walks up to the last green count and every row
that rewrote history since then is already named on that line.

### 5.5 Header chip
Beside *Balance right now*: `Last counted Sep 21 21:05 · Shabib · matched` (green) /
`… short Rs 2,000` (red) / `Not counted today` (amber, after 20:00) / `Never counted` (grey).

### 5.6 Rider flow — leave it alone
The rider modal, `confirmCash()` and the attendance columns are untouched. The keeper count
is a *sibling* with its own table because its subject is a company account, not the rider's
own float, and because it needs the seal (`last_ledger_id`) the attendance row has no place for.

Files: `RiderController::checkOut` (+ new `tillCount()`), `routes/api.php`, `routes/web.php`,
new `TillCountService`, `HubController` (merge + chip + button), `account-detail.blade.php`,
account-users panel (keeper toggle), `AttendanceScreen.js` (modal + queue), 1 SQL (table +
`is_keeper`).

---

## 6. Rollout

**SQL FIRST** (one file, `PENDING-PROD-…-TILL-KEEPER.sql`): `entered_by` column + index,
backfill UPDATE, `t_fin_ledger_watch`, `t_fin_cash_count`, `account_users.is_keeper`.
⚠ `ALTER TABLE` is not rolled back inside a transaction — run and verify each statement.
Then **web** (`app/ resources/ routes/` + `/xclean` — routes change). Then **APK**.
Backward-compatible both ways: an old APK ignores `till_count`; a new APK on the old server
sees no block and asks nothing.

Order of building, so each step is usable on its own: F0 → F1 → F3 → F2.

Verification: a PROOF php for F0.3 (six hand/approver cases incl. System), the reconciliation
identity still 0.00 after counts, `buildAccountLedger` merge order with a count at 21:05 between
two rows, drift detection with one late-dated row and one reversal, and the mobile queue when
`cash_held` and `till_count` both arrive.

---

## 7. Rulings needed before code

1. **"My hand" = I entered it OR I approved it?** (recommended yes — otherwise the pill fills
   with rider settlements Shabib himself approved).
2. **Which accounts feed the pill:** every account the viewer is tagged on (recommended), or
   NF_CASH only?
3. **Keeper flag** on *Who uses this account* (recommended) vs hard-wiring Shabib.
4. **Count doors:** check-out **and** a Hub button (recommended), and is *Skip* allowed
   (recommended yes, silently — the header chip tells on it)?
5. **A mismatch does what?** Recommended: record + show only (like the rider engine); no
   approval item, no money moves. Shabib decides what to do with it.
6. **Approval of large mobile postings** — out of scope here, but the incident itself was an
   auto-approved Rs 150,000 vendor payment from a phone. Worth a separate ruling.
