# Salary-Advance VOID — ✅ BUILT 2026-08-02 (NOT deployed)

> **Status: built and tested locally, awaiting manual deploy.** Owner approved all four §8
> questions on 2026-08-02 — the rulings are locked in below and the code is written.
>
> **Owner's rulings:**
> 1. Grant to **role 14 (Taimur)** — verified as a single-member role (user 68). It is a ROLE
>    grant on purpose: anyone later assigned that role inherits the access.
> 2. Voided advances **vanish** from the drill (still visible as cancelled/REVERSED for audit).
> 3. Reason is **mandatory** (min 3 chars, enforced client + server).
> 4. Store-Mode-settled advances are **un-voidable**, same as payroll-settled — "settled"
>    always means the money story is closed.
>
> **⚠ The landmine was CONFIRMED WITH LIVE DATA, not theory.** On the dev DB, advance
> REQ-202607-0323 (Rs 20,000, Waseem) carries `settlement_transaction_id = 17816`, which is the
> **Jul-2026 salary_payment ledger row of Rs 16,796.30**. Deleting that advance through the old
> generic expense-delete would have un-posted Waseem's entire salary. Also found: **role 14 is
> currently the ONLY role holding L2**, so today only Taimur can reach that path — but it widens
> the moment any other role is granted L2. Both delete endpoints are now blocked for advances.
>
> **Verification run (all inside rolled-back transactions, dev DB untouched):** void restores the
> funding account by exactly the advance amount ✅; employee-cash never moves ✅; ledger →
> `reversed` + `balance_updated=0` ✅; request → `cancelled` ✅; re-void refused ✅;
> payroll-settled advance refused **and the salary ledger stayed approved** ✅; store-settled
> refused ✅; blank reason refused ✅; non-advance request refused ✅. Settle-guard: open
> advance → 1 affected row (normal pay proceeds) ✅, voided → 0 (pay aborts) ✅, already-settled
> → 0 ✅.
>
> **Files changed:** `HR/PayrollService.php` (voidAdvance + enriched openAdvances + settle guards
> in payRow & payCustomPeriod), `HR/PayrollController.php` (endpoint + `can_void_advance` on both
> grids), `routes/web.php`, `pages/payroll/index.blade.php`, `FIN/ExpenseManagementController.php`
> + `API/RiderController.php` (the category block), and
> `database/migrations/void_advance_permission_aug2026.sql`.

---

# Original plan (design rationale, kept as written)

Owner question: *"If I give a salary advance and at payroll time see it was wrong, is there a
safe way to view it and delete it, restoring balances through the same BalancePostingService —
Taimur only?"*

Answer: yes — and the codebase already contains 90% of the machinery (engine `reverse()`,
the expense-delete convention). But the investigation found a **live landmine** that must be
closed as part of this work (§3). Plan covers web + mobile angles.

---

## 1. Anatomy of an advance (what exists today)

**Three creation doors, one shape:**

| Door | Code | Notes |
|---|---|---|
| Web payroll "+ advance" | `PayrollController::giveAdvance` → `PayrollService::giveAdvance` | auto-approved, posts immediately |
| Mobile payroll (Phase G) | `API/PayrollController::managerGiveAdvance` → same service | same |
| Mobile Store Mode | `API/RiderController::createSalaryAdvance` | older parallel code, same category + posting |

Each creates: **(a)** `t_req_master` row — category `salary_advance`, `status='approved'`,
`settlement_status='pending'`, `ledger_transaction_id` linked; **(b)** `t_fin_ledger` row —
`transaction_type='salary_advance'`, from = funding account (NF Cash / ONLINE + bank tag in
`receiving_account_id`), to = employee-cash, applied via `BalancePostingService::apply`.

**Balance effect: funding account − only.** `salary_advance` is in
`EXCLUDED_EMPLOYEE_CASH_TYPES`, so the employee-cash leg is skipped by charter (both in the
stored balance and `getCalculatedBalance`). A reversal therefore restores **only** the funding
account — no employee-cash side effects, ever. This makes the void unusually clean.

**Three settlement doors (this is where danger lives):**

1. **Payroll monthly pay** (`PayrollService::payRow`): sets `settlement_status='settled'` and
   — critically — `settlement_transaction_id` = **the salary_payment ledger id** (the whole
   month's net salary row, shared by every advance settled in that pay).
2. **Custom-tab pay** (`payCustomPeriod`): identical pattern.
3. **Store-Mode manual settle** (`RiderController::settleSalaryAdvance`): flags only, writes
   NO ledger row and no settlement_transaction_id.

---

## 2. Reversal machinery already present (reuse, don't reinvent)

- `BalancePostingService::reverse($ledger)` — the canonical, **idempotent** un-apply
  (guarded by `balance_updated`, locks accounts `FOR UPDATE`). Exactly "the same balance
  posting service" the owner asked for.
- The **expense-delete convention** (web `ExpenseManagementController::destroy`, mobile
  `RiderController::deleteExpense`): reverse main ledger → mark `STATUS_REVERSED` + audit
  comment → request `status='cancelled'` + reason. Ledger row stays visible as an audit trail.
  We mirror this shape for advances.

---

## 3. ⚠ LANDMINE FOUND — the generic delete endpoints already accept advances

`DELETE /…/expenses/{id}` (web) and `/rider/expenses/{id}/delete` (mobile) are **L2-gated but
category-blind** (`RequestModel::findOrFail($id)` — any category). Two consequences:

1. **Any L2 approver can already delete a salary advance** — not Taimur-only.
2. **The salary-reversal bomb:** both endpoints blindly reverse `settlement_transaction_id`
   if present. For a payroll-settled advance that column points at the **entire month's
   `salary_payment` ledger row**. Deleting one settled Rs 5,000 advance would silently
   reverse e.g. a Rs 32,407 salary posting from the books (and mark it REVERSED) while
   `t_hr_payroll_payment` still says paid. With two advances settled by the same salary,
   the engine's idempotency prevents double-reversal, but the salary row is still wrongly
   reversed once.

**Timing:** prod today has no payroll-settled advances (the new payroll isn't deployed), so
the bomb arms itself **the day the payroll module ships**. → The guard in §5 must be in the
**same upload** as the payroll deploy.

---

## 4. Proposed design — "Void advance" (Taimur-only, web-only)

### 4.1 Service (single choke point): `PayrollService::voidAdvance(int $requestId, string $reason, int $actorId)`

All inside **one DB transaction**, request row `lockForUpdate()`:

**Guards (refuse with a clear message if any fails):**
1. request exists, category = `salary_advance`;
2. `status === 'approved'`;
3. `settlement_status !== 'settled'` — settled advances are **never voidable** (payroll- or
   store-settled: the money story already consumed them; correcting those is a different,
   manual flow — see §8 Q4);
4. linked ledger row exists and `approval_status === STATUS_APPROVED`.

**Actions (mirror of the expense-delete convention):**
1. `BalancePostingService->reverse($ledger)` — restores NF Cash / the online account
   (per-bank tag view corrects with it); employee-cash untouched by charter;
2. ledger `approval_status = STATUS_REVERSED`, comment appended:
   `"VOIDED by {name} on {ts} — Reason: {reason}"`;
3. request `status='cancelled'`, `rejection_reason` same text, `updated_by` = actor;
4. `Log::info` with request id/number, amount, ledger id, actor.

Return: `{success, message: "Advance Rs X voided — Rs X returned to {account}.", restored_account, request_number}`.

### 4.2 Permission — "Taimur only"

Permissions are **role-based** (`hasPermission` → `RolePermissionModel`). New key
`void_salary_advance` inserted into the permissions table and granted **only to Taimur's
role**. ⚠ VERIFY FIRST that no one else holds that role; if it's shared, create a dedicated
`owner` role (or grant on a new single-member role) rather than widening. Enforced
server-side in the controller (`hasPermission('void_salary_advance')`), NOT just hidden in UI.

### 4.3 Web UI — view before deciding

The payroll grid's advance drill (click the amount → `showAdvances`) becomes the review
surface. Additions:
- each open advance row shows **funding source + given-by + note** (all already on the
  request: `payment_source_account_id`, `created_by`, `description`) so the wrong one is
  identifiable before acting;
- a **🗑 Void** button per row, rendered only when the session user has
  `void_salary_advance` (server passes a flag with the grid payload);
- click → typed **reason (mandatory)** → confirm dialog that repeats the story:
  *"Void advance REQ-1234 of Rs 5,000 to Farooq? Rs 5,000 returns to NF Cash. The ledger
  entry is kept, marked REVERSED."* → on success refresh the row/grid.
- Same drill is reused on the **Custom tab** (`data-cadv`) — one render function, both tabs.

### 4.4 Mobile — deliberately NO void

- No void UI or endpoint on mobile: this is an owner-only correction done on web.
- Mobile stays correct automatically: every reader (Phase-G grid via `computeMonth`/
  `openAdvances`, Store-Mode advance lists) filters `status='approved'`, so a voided advance
  simply disappears. **No APK needed** — all changes are server-side (the API controllers
  live in the web repo).

---

## 5. Close the landmine (ships with the payroll upload, non-negotiable)

In **both** `ExpenseManagementController::destroy` (web) and `RiderController::deleteExpense`
(mobile API), before anything else:

```
if request.category.category_code === 'salary_advance'
    → 403 "Salary advances can only be voided from Payroll (owner action)."
```

One door in, one door out: advances are created via payroll/store flows and voided only via
`voidAdvance`. (Optional hardening, low priority: also refuse settlement-reversal in those
endpoints whenever `settlement_transaction_id` points at a `salary_payment`-type row —
defense in depth in case a future category repeats this pattern.)

---

## 6. Race guards (void vs Pay running at the same moment)

`payRow` (and `payCustomPeriod`) currently settle advances **unconditionally by id**, using an
advance list computed *before* the transaction. If Taimur voids an advance in the window
between the manager loading/computing and the settle update, today's code would: deduct the
voided advance from net anyway AND stamp a cancelled request `settled`. Fix (part of this
plan, also fixes the pre-existing stale-grid race):

- settle update gains `WHERE status='approved' AND (settlement_status IS NULL OR
  settlement_status != 'settled')` and the loop **compares affected-rows to expected**;
  any mismatch → `throw` inside the pay transaction → whole pay rolls back →
  *"Advances changed since this page loaded — refresh and pay again."*
- `voidAdvance` re-checks `settlement_status` under its row lock (§4.1 guard 3).
- Either commit order is then safe: void-first → pay aborts and recomputes without it;
  pay-first → void refuses ("already settled — recovered from {Month} salary").

---

## 7. What the void restores / touches — full checklist

| Surface | Effect | Why |
|---|---|---|
| Funding account (NF Cash / ONLINE) | ✅ restored | engine `reverse()` |
| Per-bank tag view | ✅ corrects | row leaves 'approved' status (VERIFY V1 below) |
| Employee-cash balance (stored + calculated) | ⛔ untouched | charter exclusion, both directions |
| Payroll grid advance total + net preview | ✅ drops out | `openAdvances` filters approved |
| Expenses page totals / BU split | ✅ drops out | cancelled excluded (same as expense delete) |
| Ledger Hub listing | stays visible, **REVERSED** | audit convention |
| HQ working capital | ✅ corrects | reads account balances |
| Paid payroll months | ⛔ never touched | settled advances un-voidable |
| Mobile screens | ✅ auto-correct | server-side filters, no APK |

**V1 (verify during build):** per-bank balance math and any ledger SUMs that use the
`receiving_account_id` tag must exclude `approval_status='reversed'` rows — expense deletes
already rely on this convention, but confirm for the bank views before shipping.

---

## 8. Open questions for the owner

1. **Is Taimur's role exclusive to him?** Determines where `void_salary_advance` is granted.
2. Voided advances in the drill: vanish (recommended — they remain visible on the Expenses
   page as cancelled and in the ledger as REVERSED), or stay greyed with a "voided" tag?
3. Reason mandatory (recommended) or optional?
4. Store-Mode-settled advances (flag-only settle, no ledger): keep them un-voidable like
   payroll-settled ones (recommended — "settled" always means "money story closed"), or allow?

---

## 9. Build/deploy checklist (when approved)

- **SQL (1, portable):** INSERT permission key `void_salary_advance` + role grant (after Q1).
- **Web files:** `HR/PayrollService.php` (voidAdvance + settle-guards in payRow/payCustomPeriod),
  `HR/PayrollController.php` + `routes/web.php` (POST void route, permission-gated),
  `pages/payroll/index.blade.php` (drill columns + Void button + confirm),
  `FIN/ExpenseManagementController.php` + `API/RiderController.php` (§5 category block).
- **Order:** §5 guards MUST be in the same upload as the payroll module deploy; the rest can
  ride along. `/xclean` after upload. No APK.
