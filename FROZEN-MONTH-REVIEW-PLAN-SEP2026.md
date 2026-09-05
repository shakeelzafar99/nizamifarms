# Frozen · Month Review — Implementation Plan (Sep-2026)

Status: **BUILT Sep-04-2026, NOT deployed.** Owner approved all five rulings (see §6 for what he
decided, including dropping Phase 3). Deploy steps and the build record are in §7 at the end.
Owner ask (Sep-4-2026): one screen, inside Frozen (web sidebar + mobile Frozen mode), that shows for a month
(1) how many packs were made, expandable per product, with shelf value; (2) spend split into **Product cost /
Fixed cost / One-time cost**, fully re-classifiable at any time; (3) meat bought vs meat used; and every number
**identical to what Qasim already sees** on the Frozen screens.

---

## 0. The alignment contract (most important rule)

Qasim reads production from the mobile **Inventory Report** (`WarehouseController::inventoryReport`,
`GET /api/warehouse/inventory-report`) and sales from the **Sales Report** (`KhaasController::salesReport`).
Today those screens compute their own numbers inline. The new page must not compute a third version.

**Rule: ONE service produces the month figures, and BOTH the Inventory Report and the new page read it.**

- New `app/Services/Khaas/FrozenMonthService.php`. `inventoryReport()` is refactored to call it for
  produced / stock_in / adjustments / transferred_to_shop / sold / current stock (a behaviour-preserving
  extraction, verified by diffing the JSON before and after for Jun/Jul/Aug).
- The new page and its API endpoint call the same service. Same code path = same numbers, by construction.
- Verification script asserts, per product and in total, `month_review == inventory_report` for 3 months.

### Definitions the service freezes (these are the CURRENT Inventory Report definitions, unchanged)

| Figure | Source | Aug-2026 |
|---|---|---|
| Produced via batch | `t_crm_product_batch` completed, `ended_at` in month | 338 |
| Warehouse in | `t_crm_warehouse_inventory_log` `stock_in` in month (batch + manual) | 449 |
| Adjustments | log `adjustment` + `count` rows in month | +26 (= +83 rejected transfers returned, −57 counts) |
| Sent to store | `t_crm_warehouse_transfer` approved, `to_location=store`, `approved_at` in month | 454 |
| Sold | line items of BU-2 products, `order_date` in month, order not cancelled (free included) | 450 (93 free) |
| Warehouse / shop stock | live quantities | 145 / 47 |

Known trap the refactor must NOT hide: "Adjustments" today mixes **rejected transfers returned** with
**physical counts**. The service returns them as two fields; the Inventory Report keeps showing the combined
number unless the owner approves the split (see ruling 1).

### Ruling 1 — what the headline "Made" is

Recommendation: **Made = Warehouse in (449)**, with two sub-lines: "recorded through a batch 338 · entered by
hand 111" and "counts and corrections −57 (drill to the notes)". Reasons: it is the physical figure Qasim
typed; the batch figure is provably incomplete (3 batches closed at 0 in August); and it is already on his
report as "Warehouse In". The earlier "413" was my reading of free-text notes ("correcting wrong entry").
The system cannot tell a correction from a photoshoot take-out, so the page will not invent a net figure.
If approved, the mobile Inventory Report relabels "Produced" to "Made (warehouse in)" with "via batch" as
the secondary line, so both screens use the same word for the same number (APK, Phase 2).

---

## 1. Cost types — customizable, retroactive

### Data: ONE new table (1 SQL, no ALTER on existing tables)

```
t_fin_cost_type_map
  id, business_unit_id, source_kind ENUM('vendor','expense_category','salary','asset_purchase'),
  source_key VARCHAR(150), cost_type ENUM('product','fixed','one_time'),
  updated_by, updated_at
  UNIQUE (business_unit_id, source_kind, source_key)
```

Resolution happens at **read time**. Nothing is stamped on a bill, so changing Imtiaz from product to
fixed re-files every past and future Imtiaz bill instantly (same principle as the Category Report tag).

| Ledger row | Key looked up | If no row |
|---|---|---|
| `vendor_purchase` | vendor id | **Unclassified** bucket (shown, never hidden) |
| `expense` (khaas_expense request) | `t_req_master.expense_category` | Unclassified |
| Salaries (`SalaryCostService`, BU 2) | `salary` / `*` | **fixed** (built-in default, overridable) |
| `asset_purchase` | `asset_purchase` / `*` | **one_time** (built-in default, overridable) |

Money sources are exactly the HQ ones (`t_fin_ledger` posted rows with `business_unit_id = 2`,
`SalaryCostService::costForWindow`) so **Product + Fixed + One-time + Unclassified == HQ Frozen
vendor purchases + expenses + salaries + asset purchases** for the same month. Asserted in tests.

### Editing (three doors, one table)

1. **On the page itself**: every vendor / category row carries a dropdown (product · fixed · one-time);
   change shows a sticky "numbers out of date — Refresh" bar (Category Report pattern).
2. **Frozen → Vendors page**: same dropdown per vendor.
3. Mobile: read-only in Phase 2 (classification is a manager job; web is fine).

### Seed (applied by the SQL so day one is useful; owner can flip anything)

product: Nizami Farms (meat), Imtiaz Store, AR Packages, Gas, B.B.Q, Faizoo, Vegetable Supplies, Grocery,
Tazo cheese · fixed: Sabir Bhai, NF Shop Food, Other Supplies, Warehouse Live-In Expenses, expense
category "Utility Bills - IESCO", salaries · one_time: Kitchen Extension - Warehouse, asset purchases.
Ideal Foods / grocery nankari / punjab cnc / save mart / Abu Bakar: product. Anything new = Unclassified.

---

## 2. Meat bought vs used (inside the Product cost section)

- **Bought**: the auto-posted meat `vendor_purchase` rows (storage vendor = `KHAAS_STORAGE_VENDOR_ID` config
  or "Nizami Farms"), by `transaction_date`, identical to the HQ vendor drill row. Kg = storage log
  `received` rows in month.
- **Used**: storage log `used` rows in month (what `acceptDemand` deducted by recipe), valued at that
  month's average KS price per kg for that meat (fallback: last KS price before the month).
- **On hand** at month end: last `quantity_after` per storage row at or before month end. Identity check
  `opening + received − used ± adjustments == closing` per meat, asserted.
- Toggle **"Product cost: as bought (default) / as used"**. Default = as bought so the section ties to
  the HQ drill; "as used" swaps the meat line and recomputes per-pack. Per-meat rows on drill.

---

## 3. The page

Route `/khaas/month-review` (web) · `GET /api/khaas/month-review?month=YYYY-MM` (mobile, Phase 2).
Layout (as in the agreed mockup): month stepper → tiles: Made · Shelf value · Total spend → three cost
tiles with per-pack lines (one-time excluded from per-pack) → share bar → expandable sections:
**Made by product** (price, packs, shelf value, batch/manual split, counts drill) · **Product cost** (rows
with dropdowns; meat sub-panel with the toggle) · **Fixed cost** · **One-time cost** · **Unclassified**
(only when non-empty, amber). Footer line: "Sent to store 454 · Sold 450 (93 free) · Stock now 145
warehouse / 47 shop", all from the shared service, so they match the Inventory Report to the pack.
Break-even line: fixed ÷ (avg shelf price − product cost per pack), shown as "packs needed this month",
hidden with an explanation when product cost per pack ≥ price.

Every money tile gets the ⓘ formula popover pattern from HQ (formula + substituted values + window).

### Placement and access

- Web sidebar: Frozen section, new "Month Review" entry after "Sales Report" (`layouts/partials/sidebar.blade.php`).
- Mobile: SideMenu → Khaas mode block, next to "Inventory Report"; new `KhaasMonthReviewScreen` registered in
  `KhaasStack` (`src/navigation/index.js`), reading the API.
- **Ruling 2 — who sees the money.** Production section: anyone with `access_khaas_mode`. Cost sections
  include salaries. Recommendation: new mobile permission `view_khaas_month_review` (1 INSERT, granted to
  Management + Taimur; Qasim's role 17 only if the owner wants him to see costs). Fallback: reuse
  `view_khaas_sales_report`.

---

## 4. Files

**SQL (1 file, run FIRST, local + prod):** `database/migrations/frozen_month_review_sep2026.sql` — CREATE
`t_fin_cost_type_map` + seed rows + `t_sys_mobile_permission` row + role grants.

**Web (Phase 1):**
- `app/Services/Khaas/FrozenMonthService.php` — NEW (production/stock/sales figures, cost classification,
  meat bought-vs-used, per-pack maths).
- `app/Http/Controllers/CRM/WarehouseController.php` — `inventoryReport()` reads the service (extraction,
  no behaviour change). 4,500-line file; touch only that method.
- `app/Http/Controllers/KhaasController.php` — `monthReview()`, `monthReviewApi()`, `setCostType()`.
- `routes/web.php` (khaas group) + `routes/api.php`.
- `resources/views/khaas/month-review.blade.php` — NEW, self-contained CSS under `@push('custom_css')`
  (Tailwind purge / styles-stack traps), JSON into `<script>` via `@json` only (blade-json-in-script trap).
- `resources/views/khaas/vendors.blade.php` — cost-type dropdown per vendor.
- `resources/views/layouts/partials/sidebar.blade.php` — menu entry.
- `app/Models/FIN/CostTypeMapModel.php` — NEW (fillable incl. `updated_by`).

**Mobile (Phase 2, one APK):** `src/screens/KhaasMonthReviewScreen.js` NEW, `src/components/SideMenu.js`,
`src/navigation/index.js`; `KhaasInventoryReportScreen.js` relabel per ruling 1.

**Phase 3 (small, separate approval):** reason picker on warehouse count adjustments
(Correction / Promo-photoshoot / Wastage / Physical count) stored in `reference_type` (varchar, no ENUM
change) on web `updateStock` + mobile; "end batch" refuses 0 without a reason. Turns "−57 counts" into
named lines and closes the manual-stock-in gap.

---

## 5. Verification before hand-off (replica)

1. `inventory-report` JSON identical before/after the refactor for Jun, Jul, Aug (per product).
2. Month Review totals == inventory-report totals for the same three months.
3. Product + Fixed + One-time + Unclassified == HQ `closing('kh')` vendor + expenses + salaries (+ asset
   purchases) for Jun–Aug, to the rupee.
4. Flip Imtiaz product→fixed: buckets move, total unchanged; flip back.
5. Meat identity per storage row; "as used" ≤ "as bought" sanity; on-hand equals live storage screen.
6. Rendered blade JS parsed with node (`new Function`); both drills return HTTP 200 as Qasim's role
   and as Management; permission denial returns 403, not 500.
7. `php -l` all files; existing suites still green.

Deploy: SQL → upload `app/ resources/ routes/` → `/xclean` → sidebar shows the entry. APK only for Phase 2.

---

## 6. Owner's rulings (Sep-04-2026) — all agreed, one changed

1. ✅ Headline **Made = warehouse in (449)**, batch/manual as sub-lines. Mobile relabel stays Phase 2.
2. ✅ Costs visible to **Shabib, Taimur and Qasim**. Adnan **later**, and the owner's steer is that he
   should get the Frozen *reporting* views without the operational ones — a separate piece of work,
   so role 16 is deliberately NOT granted `view_khaas_month_review` by the SQL.
3. ✅ Default **as bought**, with the "as used" toggle.
4. ✅ Seed classification as listed in §1.
5. ❌ **Phase 3 dropped.** Owner: *"if the staff lie, Qasim says this is the actual number and edits
   it — I will trust him, so whatever number he wants us to see is what was produced."* No reason
   picker on count adjustments, and no guard on closing a batch at zero. The screen therefore
   presents Qasim's entries as the truth and never second-guesses a correction; counts are shown on
   their own line so the movement is visible, not so it can be challenged.

---

## 7. Build record (Sep-04-2026) — BUILT, NOT DEPLOYED

### Deploy order
1. **SQL first** (local + prod): `database/migrations/frozen_month_review_sep2026.sql`.
   Safe to re-run — verified twice against the replica, no duplicate rows or grants.
   ⚠ The table must be `COLLATE=utf8mb4_unicode_ci`; without it every seed comparison fails with
   MySQL error 1267 (the other tables are unicode_ci while the server default is general_ci).
2. Upload `app/`, `resources/`, `routes/`.
3. `/api/public/xclean` (routes and views changed).
4. No APK. The mobile Inventory Report keeps working unchanged; the new mobile screen is Phase 2.

### Files
| File | Change |
|---|---|
| `database/migrations/frozen_month_review_sep2026.sql` | NEW — table, seed, permission, 4 role grants |
| `app/Models/FIN/CostTypeMapModel.php` | NEW |
| `app/Services/Khaas/FrozenMonthService.php` | NEW — the one month engine |
| `app/Http/Controllers/CRM/WarehouseController.php` | `inventoryReport()` now reads the service |
| `app/Http/Controllers/KhaasController.php` | `monthReview`, `monthReviewApi`, `setCostType`, `canSeeMonthCosts`, `buildMonthReview`; `vendors()` passes the map |
| `resources/views/khaas/month-review.blade.php` | NEW |
| `resources/views/khaas/vendors.blade.php` | Cost Type column + save script |
| `resources/views/layouts/partials/sidebar.blade.php` | Frozen → Month Review entry |
| `routes/web.php`, `routes/api.php` | 3 routes |

### ⚠ The one deliberate behaviour change — read before deploying
The sales window was comparing a DATETIME column against bare date strings, so the upper bound
resolved to midnight and **every order placed during the last day of the month was dropped**. Fixed
inside the shared service, which means the mobile Inventory Report's `sold` figure changes the
moment the web files go up, with no APK. Measured on the replica:

| Month | Sold was | Sold now | Rs was | Rs now |
|---|---|---|---|---|
| Jun 2026 | 248.5 | 251.5 | 36,885 | 37,845 |
| Jul 2026 | 368 | 401 | 108,310 | 121,890 |
| Aug 2026 | 441 | 457 | 150,670 | 155,430 |

Each delta equals that month's last-day sales exactly. Nothing else in the response moved.
**Tell Qasim before the upload** — his Sales Report is order-date based and unchanged, so his
"sold" number going up is expected, not a new discrepancy.

### Verification run (all on the replica)
- **Refactor diff**: `inventoryReport` + `dailyInventoryReport` JSON compared field by field for
  Jun/Jul/Aug. Only the sales-window fields differ; produced, stock, transfers, values, planned
  weight and every daily row are byte-identical.
- **Service suite — 38/38**: Month Review == Inventory Report per product for 3 months; made ==
  raw `stock_in` from the ledger; batch + manual == made; four buckets == HQ Frozen
  (vendor + expense + salary + assets) to the rupee for 3 months; a re-tag moves money without
  changing the total and reverts cleanly; meat kg matches the storage log and on-hand matches the
  live screen; every used kg is valued; "as used" moves only the meat line; empty month, bad month
  string and the unclassified bucket all handled.
- **HTTP suite — 35/35** through the real kernel: page loads for Qasim, Taimur, Shabib and Sabir;
  4 months + the basis toggle + a rubbish month; every existing Frozen screen still 200s; save
  works and invalid input is refused 422; both mobile endpoints answer and agree.
- **Per-persona permission checks** in fresh processes (⚠ the api guard caches the first user it
  resolves, so one process per persona is the only honest way to test this): rider 403 on both the
  page and the API, Adnan 403, Qasim/saad/Taimur 200, rider refused on save.
- **Render checks**: 4 page states + the vendors page rendered and every inline script parsed with
  node; no HTML entity leaked into a script block; the restricted view ships neither the money nor
  the classification code.
- `php -l` clean on all touched PHP. PHPUnit: 2 tests, 1 failure — `ExampleTest` asserts `/`
  returns 200 while the app redirects guests to login. Pre-existing and unrelated.

### Round 2 (Sep-04, after the owner opened the page) — two CSS bugs, both fixed

**⚠⚠ The Fixed-cost tile was pinned to the viewport and overlapped the content below it.**
Cause: the tile was written `class="mr-tile fixed"` and `assets/css/styles.css` (~line 303) defines
the Tailwind utility **`.fixed{position:fixed}`**. Nothing in the page's own `<style>` declared
`position`, so that utility applied unopposed — which both pinned the tile to the viewport (it
"scrolled with the page") and took it out of the grid, so the share bar and the paragraph rendered
underneath it. One cause, both symptoms.
Fix: **every** class in the page is now `mr-` prefixed — modifiers (`mr-t-product`, `mr-t-fixed`,
`mr-t-onetime`, `mr-t-unknown`) and descendants (`mr-lab`, `mr-big`, `mr-tile-sub`, `mr-num`,
`mr-name`, `mr-amt`, `mr-on`, `mr-chip-grey`) — plus `position: relative` on `.mr-tile` as a
belt-and-braces guard. Audited all 36 class tokens against the global sheet: **0 collisions**.
⚠ Never use a bare, generic class name in a page style block in this app.

**⚠ The page also stretched the document sideways on a phone** (714px wide at a 375px viewport).
The Metronic shell nests content in three flex items (`main.grow`, `.kt-wrapper`, `.flex.grow`) that
all have the default `min-width: auto`, so a wide table's min-content bubbles up and stretches
everything. `min-width:0` on my own elements and `max-width:min(1180px,100vw)` both failed — the
offenders are app chrome, which must not be edited. Fixed with **`contain: inline-size`** on the
table's scroll box: 714 → 375, desktop unchanged, and the table scrolls inside its own box.
⚠ This overflow **pre-dated** the scroll box (measured identical with the box removed), so it was
not introduced by round 1.

Also tidied: the dead `$meta[1]` CSS-class slot removed from `$bucketMeta`, and a `<details>`
ternary whose two branches were both `open`.

**Verified after the fix:** layout suite (tag balance, tiles on one row, share bar after all three
tiles, both tables in scroll boxes, no bare utility token, empty month free of NaN) · render + JS
parse suite · 38 service checks · 35 HTTP checks · and a real browser at 1440px and 375px.
**Plus the CSRF round trip** the earlier smoke test had bypassed: GET the page for a session and
token, POST the save with them, HTTP 200 and the map really changes — so the dropdown will not 419
in the owner's browser.

### Phase 2 (Sep-04) — the mobile screen, BUILT ✗live (⚠ needs an APK)

Frozen mode → side menu → **📊 Month Review**, sitting directly under Inventory Report.

| File | Change |
|---|---|
| `src/screens/KhaasMonthReviewScreen.js` | NEW — the screen |
| `src/navigation/index.js` | import + `KhaasMonthReviewWrapper` + `KhaasMonthReview` in `KhaasStack` |
| `src/components/SideMenu.js` | menu entry in the Khaas block |
| `src/screens/KhaasInventoryReportScreen.js` | ruling 1 relabel (below) |
| `__tests__/khaasMonthReview.test.js` | NEW — 15 render tests |

- Reads `GET /warehouse/month-review` — **the same FrozenMonthService** as the Inventory Report, so
  the phone and the web page cannot quote different production numbers. The screen contains no
  arithmetic beyond formatting; it prints what the server sends.
- **Ruling 1 relabel done.** The Inventory Report's Month Summary now leads with
  **"🏭 Made (warehouse in)"** (= `stock_in`) and carries the batch figure underneath as
  "of which via a batch". All three surfaces now use one word for one number.
- **Menu is NOT permission-gated, the cost half is.** Everyone in Frozen mode gets the entry,
  because the production half is what the Inventory Report already shows them. Without
  `view_khaas_month_review` the server strips the money and the screen renders production plus a
  line saying why. A second gate on the menu would have hidden production from the people who
  actually make the packs.
- Read-only: re-classifying a cost stays a web job.
- Refreshes on open and on pull-to-refresh, **deliberately not on a 30s timer** like the live
  operational screens — a month review is read, not watched, and the query behind it is heavy.

**Verification** (the screen cannot be driven on a device from here, so it is verified by rendering):
- **15 render tests, all passing**, against a payload copied from the live replica response. They
  assert the alignment contract directly — the rendered headline IS `production.totals.made`, not a
  recomputation — plus the flow line, product rows and chips, expansion, the three cost tiles, the
  break-even sentence, cost sections with the salary detail line, the meat panel, the basis toggle
  reaching the API, an empty month, a 403, the missing-table warning, and that
  `can_see_costs: false` leaks **no** money figure.
- **Contract check against the LIVE API**: all 43 field reads in the screen exist in a real
  `/warehouse/month-review` response. This catches a typo like `made_batches`, which would render a
  silent blank rather than crash.
- All four changed RN files compile under the project's Babel preset; eslint clean on the new screen.
- ⚠ **A bug the tests caught:** the screen fired the API even with no business unit (the "not in
  Frozen mode" guard was in the render only, not in the fetch). Fixed, and covered by a test.
- ⚠ Pre-existing and unrelated: `App.test.tsx`, `paymentProof.test.js` and `vanBoards.test.js` fail
  on `main` — the first on a missing native gesture-handler module, the other two on files this work
  never touched. The other 8 suites pass.
- ⚠ Test-helper note for next time: `TouchableOpacity` appears as a **composite** node in the test
  tree, so the older tests' `typeof n.type === 'string'` filter finds nothing on this screen. Match
  on `props.onPress` instead.

**Deploy for Phase 2:** build the APK with `build-production-apk-auto.bat` (it rolls the version
itself — do not hand-edit `versionCode`). The web files and SQL from Phase 1 must go up first, or
the endpoint will not exist.

### Known, deliberate, and worth telling the owner
- **August salaries read Rs 70,000, not ~120,000.** That is the system being honest: Frozen payroll
  for August has not been pressed, so only Sabir's open advances have posted. Press Pay for the
  month and the figure fills in by itself. Same engine as HQ, so the two always agree.
- **Until the Phase 2 APK**, the mobile Inventory Report still says "Produced 338" (batches) beside
  "Warehouse In 449". The web page says "449 made · 338 through a batch · 111 entered by hand", so
  the two reconcile on screen, but the WORD differs until the relabel ships.
- August break-even comes out at ~3,920 packs because the margin over product cost is only ~Rs 32 a
  pack. That is a real signal about the month, not a bug in the arithmetic.
