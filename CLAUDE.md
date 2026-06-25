# nizamifarms — Web App & Backend API (Laravel)

The web admin/operations app **and** the REST API that the mobile app and customer app consume.
Live at `app.nizamifarms.com`. Read the workspace-root `CLAUDE.md` first.

> Also read `AGENTS.md` in this folder — it is a binding rule file for analytics/dashboard work
> (the `analytics-sandbox/` edit boundary). It is not optional.

---

## Stack & environment

- **Laravel 11**, **PHP 8.2**, **MySQL** (production), Blade server-rendered views.
- Auth: session for web; **Laravel Sanctum** tokens for the API.
- Notable packages: `barryvdh/laravel-dompdf` (PDF invoices), `guzzlehttp/guzzle` (Google Maps,
  Shopify, WhatsApp, bank-email IMAP), `laravel/tinker`.
- Frontend in views: Blade + heavy **inline vanilla JS** + Tailwind-style classes. There is no SPA.
- **No CI/CD.** Deploy = manual file upload to stackcp. Git is dev-only. (See root `CLAUDE.md`.)

### Useful commands (local dev)
- Syntax-check a file: `php -l path/to/File.php`
- Clear caches after editing config/routes/views: `php artisan config:clear`, `route:clear`, `view:clear`
- Inspect data / models: `php artisan tinker`
- Tests exist (`phpunit`) but coverage is thin — don't assume tests guard your change.
- PowerShell note (owner's machine): chain commands with `;`, not `&&`.

---

## Directory map

```
app/Http/Controllers/
  API/            <- endpoints the MOBILE app + customer app call (RiderController is huge & central)
  CRM/            <- orders, customers, riders, qurbani, operations
  FIN/            <- ledger, expenses, employee cash, payment signals, assets
  HR/             <- salary, loans, attendance, profiles
  PDM/            <- product data (products, parts, brands, sizes)
  Ops/            <- shifts, holidays
  SysAdmin/       <- users, roles/permissions, config, menus
  Request/        <- internal "request" (purchase/expense request) workflow
  Web/, Webhook/  <- WhatsApp web UI, Woo/WhatsApp webhooks
  ApprovalController.php         <- WEB online approvals (regular/shop tabs, L1/L2, proof filters)
app/Models/         <- grouped by domain (CRM/, FIN/, ...). Eloquent models map to t_* tables.
app/Services/       <- business logic. Key ones below.
routes/web.php      <- web routes
routes/api.php      <- mobile/customer-app API routes
resources/views/pages/  <- the actual screens (some are VERY large, e.g. orders/index.blade.php)
resources/views/approvals/online.blade.php  <- online approvals UI
database/migrations/    <- mix of Laravel migrations and raw .sql files applied by hand
config/             <- incl. payment_signals.php (matching window etc.)
analytics-sandbox/  <- SANDBOX ONLY for analytics work (see AGENTS.md)
```

### Key services (`app/Services/`)
- `FIN/LedgerPostingService.php` — posts invoice / order_payment entries to the ledger. **Online
  "shop" customers are intentionally NOT posted a full invoice here** (see Shop flow below).
- `Payments/Signals/PaymentSignalMatcher.php` — matches incoming payment proofs (WhatsApp images /
  bank emails) to candidate orders. Lookback window comes from `config/payment_signals.php`.
- `Payments/Signals/PaymentProofStatusService.php` — derives proof status
  (`proof_received` / `bank_confirmed` / `verified` / `amount_mismatch` / `none`).
- `Payments/Email/*` — IMAP bank-email fetching + per-bank parsers; OCR via Gemini for screenshots.
- `CustomerAppWebhookEmitter.php` / `CustomerAppWebhookDispatcher.php` — outbound webhooks to the
  separate **customer-facing app** (order status updates).
- `ShopifyService.php`, `WooCommerceService.php`, `WhatsAppService.php`, `FirebaseService.php`,
  `GeocodingService.php` / `Location/*` (Google Maps).
- `DashboardAnalyticsService.php`, `API/ReportsController.php` — **revenue is ORDER-based**
  (delivered orders' `total_price`), **not** ledger-based. Ledger-flow changes don't move revenue.

---

## Database table naming convention

Tables are prefixed by domain (MySQL, snake_case):

| Prefix | Domain | Examples |
|--------|--------|----------|
| `t_crm_` | CRM / operations | `t_crm_prod_order` (live orders), `t_crm_prod_customer`, `t_crm_order_payments`, `t_crm_order_status_history`, `t_crm_shopify_order` (staging) |
| `t_fin_` | Finance | `t_fin_ledger`, `t_fin_payment_signal` |
| `t_ops_` | Operations | `t_ops_rider_location`, `t_ops_attendance` |
| `t_sys_` | System | users, roles, permissions, config |
| `t_app_` | Customer app | `t_app_webhook_events` (outbox) |
| `t_wa_`  | WhatsApp | conversations / messages |
| `t_pdm_` | Product data | products, parts |
| `t_hr_`  | HR | salary, loans |

Some columns are **Eloquent accessors, not real columns** (e.g. `delivery_date` on the order model
is derived). Don't `orderBy`/`where` on accessors at the SQL level — check the model first.

---

## Core domains & important rules

### Orders + the Shopify ID collision (critical)
- **Two tables, overlapping ids:** `t_crm_shopify_order` (the Shopify approval queue / staging) and
  `t_crm_prod_order` (accepted, live orders) each auto-increment independently. The same numeric id
  exists in both as unrelated orders.
- `OrderController::findOrder()` is **source-authoritative**: it picks the table from a `source`
  request param (`?source=shopify` → staging model; otherwise the live `OrderModel`). The orders
  view (`resources/views/pages/orders/index.blade.php`) builds detail/save URLs via an
  `ordersDetailUrl()` helper that appends `?source=shopify` when in Shopify mode.
- **Rule going forward:** never look up an order by raw id without the source context. A regression
  here previously caused a Shopify order to overwrite an unrelated live order's status.
- **Frontend invariant (Jun 2026 fix):** every id-based order URL on the orders page MUST carry the
  source. Use the helpers near the top of `orders/index.blade.php`: `ordersDetailUrl()` (AJAX
  detail/save), `editOrderTabUrl()` (pop-out / "open in tab"), `ordersInvoiceUrl()` (print/PDF/PNG).
  All read `currentOrderSource()` (runtime `window.currentSource` → URL `?source` fallback).
  - `window.currentSource` / `window.currentTab` are initialized on **every** page load by the
    `initOrdersSourceContext()` IIFE from the server `$source`/`$tab`. Do NOT rely on the older
    assignment lower in the file (~line 9626) — it sits inside a commented-out `/* ... */` block and
    never runs; it was the root cause of two wrong-order bugs (a popped-out Shopify approval order
    resolving against production because `window.currentSource` was `undefined`).
  - Bug symptom to recognize: opening a Shopify approval order's detail/print shows a different
    customer (a production order sharing the same id). Root cause is always a dropped `source` param.
- **Not affected:** the mobile app and customer app — they keep Shopify and production in separate
  screens/endpoints and never resolve a Shopify id against the production order table.

### Approvals (web: `ApprovalController` + `approvals/online.blade.php`; mobile: `API/ApprovalsAPIController`)
- **Online approvals** has **Regular** and **Shop** tabs (driven by `customer_type`).
- Two-level approval: **L1 then L2**. Business rule: a ledger invoice entry is posted **after L1**
  (`pending_l2` counts as effectively approved for "already in ledger" purposes).
- Proof filters: `received` / `verified` / `mismatch` for bulk review of WhatsApp-matched payments.

### Customers + the "shop" flow
- `t_crm_prod_customer.customer_type` ∈ `regular` | `shop` (default `regular`). Model:
  `CustomerModel` (`TYPE_REGULAR`, `TYPE_SHOP`, `isShop()`).
- **Regular**: online invoice sits in approvals; on approval the invoice amount posts to the ledger.
- **Shop**: invoice does NOT post a full invoice to the ledger (`LedgerPostingService` skips it for
  *online* shop orders). Instead payments are added **incrementally** (like Qurbani) as
  `order_payment` ledger entries — supports partial/full payments. Shop orders live in the **Shop**
  approvals tab and are excluded from the regular tabs. Orders that already have an
  `approved` or `pending_l2` invoice ledger entry are excluded from the Shop tab (already settled).
- New invoices/orders for shop customers default `payment_method` to `online` (web + mobile).
- Converting a customer to `shop` only re-flows `pending`/`pending_l1` invoices — never
  `pending_l2`/`approved` ones (those are kept as settled).

### Payment signals (WhatsApp images + bank emails → orders)
- `PaymentSignalMatcher` matches a proof to **candidate open/unpaid orders** within a time window.
- Window is configurable: `config/payment_signals.php` → `match_window_days`
  (env `PAYMENT_SIGNALS_MATCH_WINDOW_DAYS`, currently **200** days).

### Riders / dispatch / GPS (`API/RiderController.php` — very large)
- Powers the mobile rider experience **and** the web "Riders — Live" board on the orders page.
- `getRidersLiveStatus` returns checked-in riders, GPS freshness, and dispatch/return-to-office ETA.
  Each of those three is computed in its **own** `try/catch` so a slow Google Maps call can't blank
  the whole board. Google ETAs are reused from cache; fresh Google calls are warmed
  **out-of-band** via `app()->terminating()` rather than blocking the response.
- Web board visibility is gated client-side by `riderBoardShouldShow()` (desktop + Open Orders tab),
  which trusts `window.currentSource`/`window.currentTab`, not just the URL.

### Customer-facing app webhooks
- Status changes are pushed to the separate customer app via `CustomerAppWebhookEmitter` into the
  `t_app_webhook_events` outbox. It keys on `order_number` (only emits for `SH-` prefixed numbers),
  **never** internal ids — which is why it's immune to the id-collision issue above.

---

## Conventions & gotchas
- Eloquent + query builder both appear; raw SQL exists in some controllers — match local style.
- Migrations: a mix of Laravel migration classes and hand-written `.sql` files in
  `database/migrations/` applied manually in production. Keep raw SQL portable (plain
  `ALTER TABLE` / `CREATE INDEX`, no fancy syntax).
- Some Blade views (e.g. `orders/index.blade.php`) are thousands of lines with critical inline JS —
  search precisely and change minimally.
- Logs: `storage/logs/laravel.log`. The owner sometimes shares prod logs as separate files.
- The **local dev DB is a replica of prod** but may lag (e.g. the very latest Shopify orders may be
  missing locally). Don't conclude "data doesn't exist" from local alone.
