# Plan — Freezer columns on the Category Report + Manual payment entry with balance handoff

**Date: 2026-08-27 (v3). Status: PART A ✅ BUILT (not deployed) · PART B = PLAN ONLY.**
Owner decisions applied: FREEZER only · current period shows what is still in the freezer, older
periods show what was taken out · quiet-day markers ON · manual payments (Part B) restricted to
**Shabib and Taimur only** · A before B.
v1 wrongly assumed the overnight tracker was not deployed. It IS live and in real use —
v2 re-plans Part A from the actual data and tightens Part B (one double-count rule added,
plus manager-usability additions).

---

# PART A — "What's in the freezer" per category  ✅ BUILT 2026-08-27 (not deployed)

## A0. The data this was built on (replica probe, prod mirror)

The overnight chiller/freezer tracker is **live and in real use since 2026-08-01**, all
entries by Farooq: 46 packets, 89 log events (`in` 46 / `out` 29 / `move` 2 / `verify` 12).
Every item has `product_id` (0 nulls) so the category comes free from `attribute_1`, and
every `out` event carries kg. History already existed at two levels — `getHistory`
(per-packet feed with who/what/kg) and `getDailySummary` (per-day section balances by log
replay, with a `reconciled` flag). The only thing missing was a CATEGORY dimension.

**Answer to "do we have history for old days?"** Yes, from 2026-08-01 onward, per packet.
Before that: genuinely nothing and unrecoverable — no table ever recorded store-freezer
contents (`deductInventory()` writes to laravel.log only).

⚠ Expect the flow column to look THIN: freezer-section take-outs are sparse (2 events /
14.94 kg) because most take-outs are from the **chiller** (27 events). The owner chose
freezer-only deliberately. The stock column is rich (91.8 kg).

## A1. What was built

**Category Report — whole-range table: new `❄️ In freezer` column.**
Live stock per category (kg + packet count) from `t_crm_overnight_item` where
`status='stored' AND section='freezer'`, grouped by the same `attribute_1` vocabulary the
sales side uses. It is a live level, so it ignores the selected date range (the tooltip
says so).

**Category Report — period tables: new `❄️ Freezer` column.**
The period containing TODAY shows what is **still in the freezer**; every earlier period
shows what was **taken out** of it.
- ⭐ The current-period row ALSO shows that period's take-outs. Without this, a weekly or
  monthly bucket containing today silently swallows its own take-out history — caught in
  testing when Aug-16's 13.75 kg vanished from the monthly view.
- ⭐⭐ Periods are collected from SALES/PURCHASES, so on a day with no deliveries yet today
  has **no row at all** and live stock had nowhere to render. The service now injects the
  current-period row when there is stock to report.
- Categories with freezer activity but no sale/purchase that period are UNIONed in, so
  stock can never go invisible (the Open-Quantities failure mode). Those rows show "—" for
  the difference, never "Rs 0".
- A freezer→chiller **move is not usage** — it is shown separately as "→ chiller" so
  consumption is never overstated.
- Quiet days carry a **"no freezer activity recorded"** chip (day granularity only): the
  data cannot tell "nothing moved" from "nobody scanned", so the UI says only what it knows.
- Footnote states the freezer-only scope and the 01-Aug tracking start date.

**Overnight page (history tab) — per-day category breakdown.**
`getDailySummary` now returns a `categories` map per day (section → category → in/out kg +
packets) from ONE grouped query, rendered as a sub-row under each day.
- ⚠⚠ TRAP HIT: the alias `section` collides with a **real column** on
  `t_crm_overnight_log`, so `GROUP BY section` bound to the column and threw MariaDB 1055
  even though it looked like the known "GROUP BY the alias" fix. Renamed `sec_key`.
  Alias-shadowing is a second flavour of that trap.
- The new key is additive, so existing APKs ignore it — no APK needed.

## A2. Verification (13 checks, all green)
Money totals and the margin identity are byte-identical (freezer never enters
`blankCell()`); the report's freezer kg equals the overnight page's own live figure
(91.805) to the paisa; the new category breakdown ties exactly to the existing section
totals (267.71 kg in / 144.90 kg out); live stock is never painted on a past range;
the chiller's 31 kg is correctly excluded; both pages render; both blades compile and
their JS passes `node --check`.

## A3. Files + deploy
`app/Services/CategorySalesPurchaseService.php` ·
`resources/views/pages/products/category-report.blade.php` ·
`app/Http/Controllers/CRM/OvernightStorageController.php` ·
`resources/views/pages/overnight/index.blade.php`.
**NO SQL, NO APK.** Upload the 4 files + `/xclean`.

## A4. Caveat accepted
The 'Khaas' row stays one-sided (purchases carry no Khaas tag — frozen is made from meat
bought as Beef/Mutton), and its sales include 2 BU-1 products tagged Khaas (ghee id 359,
potato id 1340) that have no freezer stock. Tooltip note, no code change.

---

# PART B — Manual payment entry, prepayment, validation, and extra → balance

## B0. Verified foundations (why this design is safe)

1. **Money vs evidence are separate rails.** `t_fin_payment_signal` rows are EVIDENCE only —
   they never touch `total_paid`, ledger, or order money. The invoice (created at delivery,
   approved in Online Approvals) is the money. Manual entries below are all signal-side, so
   nothing about invoicing/approval mechanics changes.
2. **The prepayment trap is already avoided.** `OrderModel::hasPreReceivedPayments()` reads
   `t_crm_order_payments` ONLY. A signal on an undelivered order can NOT reroute a regular
   order into the shop/qurbani incremental regime. And the matcher explicitly supports
   attaching to undelivered orders (order_date window, payment_status unpaid/partial —
   delivery status never consulted; the forward-grace day exists for pay-before-delivery).
3. **"Validated" already exists as a tier.** The proof ladder is
   `verified > amount_mismatch > proof_received > bank_confirmed > none`. `verified` = a
   customer-side signal bidirectionally paired (`paired_signal_id`) with a bank-side signal
   (email/bank_sms), pairing found by amount (±Rs 1), directional time window, shared
   reference decisive, same receiving bank when known. **A manual claim entered as a
   customer-side signal gets validated automatically when the bank SMS lands.** No new
   validation machinery is needed.
4. **The bucket handoff seam was pre-built.** `t_crm_customer_credit` already has
   `signal_id`, `receiving_account_id`, `order_id`; `CustomerCreditService::requestGrant()`
   already accepts `signal_id` and creates a PENDING grant that needs L2 approval;
   `SOURCE_OVERPAYMENT` is declared and deliberately unused — this feature is its caller.
5. **The assistant precedent.** `AssistantDraftService::replayPaymentProof` already writes
   manual signals (source='whatsapp', extractor_version='assistant_typed@v1',
   matched_customer_id set, matcher scoped to the customer's approvals queue) and the proof
   panel already shows a purple "Recorded from … by X — not sent by the customer" banner.

## B1. ONE new SQL (small): attribution

`t_fin_payment_signal` has **no `created_by`** (assistant attribution is an indirect join
through `t_ai_drafts`). The owner explicitly wants "manual proof by whoever entered it":

```sql
ALTER TABLE t_fin_payment_signal ADD COLUMN created_by INT NULL AFTER extractor_version;
```

Manual rows set it; existing rows stay NULL (assistant rows keep their t_ai_drafts join).
Do NOT extend the `source` ENUM — a manual claim IS a customer-side claim; keeping
`source='whatsapp'` is what makes the pairing/validation machinery work unchanged.
Provenance = `extractor_version` (`manual_web@v1`) + `created_by`.

## B2. "Record payment received" — new endpoint + two UI doors

**Endpoint:** `POST /admin/payments/order/{orderId}/manual-proof` on `PaymentSignalsController`,
gated `ensureApprover()` (L1-or-L2, the same gate as balance-discount actions).
Fields: `amount` (required), `receiving_account_id` (optional, from
`t_fin_online_receiving_accounts` — sets `extracted_to_account_short/last4` so bank-gated
pairing works), `reference` (optional — decisive for pairing), `paid_at` (optional, defaults
now — becomes `extracted_txn_datetime`), `note`.

Writes ONE signal row: `source='whatsapp'`, `extractor_version='manual_web@v1'`,
`created_by=auth`, `matched_customer_id` (resolved from the order, merge-chain aware),
`matched_order_id=order`, `status='matched'`, `match_reason='manual_confirmed'`,
`match_confidence=1.00` — mirroring the existing human-attach path
(`AssistantSmsController::attach`). Idempotency guard: refuse a second manual signal with the
same order + amount within 8 seconds.

**Door 1 — Online Approvals proof panel** (`openProofPanel`): a "✍️ Record payment" button.
Covers "order delivered, sitting in approvals, customer already paid but no proof arrived".

**Door 2 — Order edit modal (prepayment)**: same endpoint from a small "Payments claimed"
strip in the edit modal (web) which also LISTS the order's existing signals — amount, status
badge, entered-by. Covers "someone has prepaid — enter it on the order". When the order later
delivers, the approvals row picks the badge up automatically (`attachProofStatus` keys purely
on order_id — verified).

**Display / who-did-what:** badge variant on approvals rows and the order strip: same yellow
tier as `proof_received` but labelled **"✍️ Manual — by <name>"** (from `created_by`;
`PaymentProofStatusService` payload gains `manual_by`). The proof panel shows the full
banner; when the bank SMS validates it, the tier flips to green `verified` — the owner's
"validated" moment. (A later WhatsApp screenshot does NOT validate — two customer-side
claims never pair; only bank-side evidence validates. The bank is the truth anchor.)

## B3. Extra amount → balance (three triggers, one grant path)

All three call the SAME thing: `CustomerCreditService::requestGrant()` with
`source=SOURCE_OVERPAYMENT`, `signal_id`, `order_id`, `receiving_account_id` → a PENDING
grant in the existing L2-approval flow. Guards: refuse when a non-voided grant already
exists for the same `signal_id`; regular customers only; `MIN_GRANT` Rs 10 floor
(deliberately equal to the matcher's amount tolerance — "extra" begins where "matches" ends).

⚠⚠ **The count-once rule (added in v2 — a real double-count trap):** the proof panel's
`difference` is computed **PER SIGNAL** (`PaymentSignalsController` ~:107). A manual claim
and its paired bank SMS on the same order EACH show the same "+Rs X over". Every overpay
computation in this feature must therefore collapse `paired_signal_id` pairs to ONE proof
(prefer the bank-side amount) and never sum independent signals blindly. The grant-per-
signal_id dedupe must treat a pair as one unit too: granting against either mate blocks the
other (check `signal_id IN (id, paired_id)`).

1. **At manual entry:** if entered `amount` exceeds the order's remaining balance by
   ≥ Rs 10, the dialog asks: **"Rs X extra — add to customer balance, or ignore?"**
   - *Balance* → pending grant (and if the clicking user has L2, offer "approve now").
   - *Ignore* → nothing; the panel still shows "Rs X over" so it stays recoverable.
2. **In the proof panel (signal-detected):** an "➕ Add Rs X to balance" button whenever the
   pair-collapsed difference ≥ Rs 10 and no grant exists for the pair. (Today an overpay is
   text-only — `balanceAdjustmentInfo` is short-only three ways. This gives it a home.)
3. **At invoice approval:** hook in `LedgerController::approve` at the existing
   post-approval seam (~:914, the alias-learning block — try/catch, never blocks approval):
   compute the pair-collapsed overpay, return `overpaid_amount`/`signal_id`/`customer_id` in
   the success JSON; `doApprove`'s success branch shows the same "add to balance or ignore?"
   prompt (pattern: the advisory payer-check dialog — never blocks).

**Permission shape:** REQUESTING a grant = any approver (L1 or L2) — it creates pending only.
APPROVING the grant stays L2 (existing `CustomerCreditController`). The prompt shows to L1
users too (their click yields "sent for L2 approval") — no 403 surprises; the blade already
carries `hasLevel2Rights`.

## B4. Manager usability — where everything lives (v2 additions)

The owner's test: "my managers can use them, fix balances, see who did what."

1. **Credit history must NAME the actors.** The already-built customer-panel history returns
   type/amount/order/date but NOT who entered/approved. Add `entered_by_name` /
   `approved_by_name` / `voided_by_name` to `CustomerCreditService::historyFor()` and render
   them in the panel rows ("Added Rs 500 · by Ali · approved by Taimur · 12-Aug"). Small
   change to already-built code; include in Part B phase 1.
2. **A central place for pending grants.** Today approve/reject only lives inside each
   customer's panel. Add a **"💰 Balance requests (N)" section on the Online Approvals
   page** (collapsed when N=0), fed by the existing `GET /customer-credit/pending` endpoint
   (already returns customer names, amounts, sources, order numbers): rows with
   Approve / Reject for L2 users, read-only list for L1. Managers live in approvals —
   grants must not hide per-customer.
3. **"Fix balances"** = the existing L2 zero-out with mandatory reason (owner ruling:
   zero-out only, no arbitrary set-to-amount). Every fix is a history row with actor + reason.
4. **Proof audit**: manual proof badge names the enterer; any later re-attachment is logged
   in `t_fin_payment_signal_moves` with `moved_by` (existing).

## B5. What deliberately does NOT change
- No `t_crm_order_payments` rows for regular customers, ever (the reroute trap).
- Qurbani/shop payment paths untouched (they already have manual entry with overpay 422s).
- The matcher's automatic behaviour untouched — manual signals are born `matched` with a
  TERMINAL reason (`manual_confirmed`), which every re-matcher/resweeper already skips.
- `payment_signals.enabled` master switch continues to gate the whole feature.
- No cron anywhere (prod has none); everything is request-driven.

## B6. Part B build shape
- **SQL:** the one ALTER above (run FIRST).
- **Web (Phase 1):** `PaymentSignalsController` (+manual-proof endpoint, +grant-from-signal
  endpoint, pair-collapsed difference logic extracted to a shared method),
  `PaymentProofStatusService` (+manual_by), `CustomerCreditService` (+signal-pair dedupe
  guard, +actor names in history), `LedgerController::approve` (+overpay in response JSON),
  `approvals/online.blade.php` (record button, dialog, badge variant, post-approval prompt,
  Balance-requests section), `orders/index.blade.php` (payments-claimed strip in edit modal),
  `CRM/CustomerCreditController` (pending list already exists; add names to summary/history).
  No APK.
- **Mobile (Phase 2, later APK):** mirror the proof-panel button + badge on the mobile
  approvals screen (the proof filter is already duplicated there).
- Verify with rolled-back tinker suites: manual signal → badge payload with `manual_by`;
  pairing with a synthetic bank SMS → verified + count-once collapse; prepay on undelivered
  order → invoice posts normally at delivery (NO reroute); overpay prompts all three
  triggers; grant dedupe across a pair; pending-grants section data.

---

# Owner decisions — ANSWERED 2026-08-27

1. **Freezer column scope:** FREEZER section only. The chiller is never counted, even
   though it holds 31 kg and 27 of the 29 take-outs so far — so the flow column will read
   thin by design.
2. **Current vs past periods:** the latest period shows what is **still in the freezer**;
   all earlier periods show what was **taken out**. (Built with the current row also
   showing its own take-outs, so no history is swallowed.)
3. **Quiet-day markers:** YES — shown on day granularity.
4. **Who may record manual payments (Part B):** **Shabib and Taimur only.** This is
   narrower than the `ensureApprover()` L1-or-L2 default the plan originally assumed —
   Part B's endpoint gate must be built to this, and the UI must hide the action for
   everyone else rather than letting them hit a 403.
5. **Order:** Part A first (done), then Part B.
