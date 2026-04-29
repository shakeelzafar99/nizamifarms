# Schema & Business Context

This is the primer the developer (and his AI agent) must read before writing any query. The goal: avoid having to dig through the entire Laravel codebase to find table names and column meanings.

> **Convention:** all tables are prefixed with their domain — `t_crm_*`, `t_fin_*`, `t_hr_*`, `t_sys_*`, `t_wa_*`. Models live in `app/Models/<Domain>/<Name>Model.php` if you need to peek at relationships. Models are read-only reference for you — never edit them.

---

## Core CRM / orders

| Table | Notes |
|-------|-------|
| `t_crm_prod_customer` | Master customer table. `id`, `name`, `phone`, `created_at`, region info. **A customer is "new in year Y" iff their first non-cancelled order is in year Y.** |
| `t_crm_prod_order` | Master order table. `id`, `customer_id`, `status`, `created_at`, `total`, source (online vs cash etc.). |
| `t_crm_prod_order_line_item` | Line-level rows linked to `t_crm_prod_order` by `order_id`. Contains `product_id`, `quantity`, `price`. |
| `t_crm_prod_product` | Product master. `id`, `name`, `category`, `is_qurbani` flag (verify column name in actual schema). |
| `t_crm_prod_order_status_master` | Lookup table for human-readable status names. |
| `t_crm_order_payments` | Payments against orders. |
| `t_crm_shopify_order` / `t_crm_shopify_order_line_item` | Mirrored Shopify orders before they're converted into CRM orders. **Don't double-count — converted Shopify orders also exist in `t_crm_prod_order`.** |

## Finance

| Table | Notes |
|-------|-------|
| `t_fin_ledger` | All money movements (cash, bank, vendor, customer). |
| `t_fin_config` | Global key/value config. e.g. `qurbani_mode_enabled`. Read via `\App\Models\FIN\ConfigModel::get('key')`. |
| `t_fin_business_units` | Business unit master. |
| `t_fin_vendors`, `t_fin_vendor_products`, `t_fin_vendor_purchase_items` | Procurement side. |
| `t_fin_assets`, `t_fin_asset_categories` | Fixed-asset register. |
| `t_fin_accounts`, `t_fin_online_receiving_accounts` | Bank / online accounts. |

## HR

| Table | Notes |
|-------|-------|
| `t_hr_employee_profile`, `t_hr_employee_loans`, `t_hr_loan_payments`, `t_hr_salary_slips` | HR domain. Out of scope for first analytics passes. |

## System

| Table | Notes |
|-------|-------|
| `t_sys_user` | Logged-in users (App\Models\User). |
| `t_sys_role`, `t_sys_role_mobile_permission`, `t_sys_mobile_permission` | RBAC. |

## WhatsApp / messaging

| Table | Notes |
|-------|-------|
| `t_wa_conversations`, `t_wa_messages`, `t_wa_labels`, `t_wa_conversation_labels`, `t_wa_conversation_reads`, `t_wa_templates` | The WhatsApp inbox (out of scope for analytics dashboards). |

---

## Business glossary (do NOT guess these — ask)

These are the recurring questions that broke the Vercel prototype's numbers. Answers below are **the** answers — if your scenario doesn't fit, ask the repo owner before guessing.

| Term | Definition |
|------|------------|
| **Qurbani order** | An order whose product belongs to the Qurbani SKU set. The exact identifier (column name, flag, category) needs to be confirmed against the schema. Do **not** filter by product name — names change. |
| **Charity hissa** | A donation Qurbani SKU. May or may not count toward customer-acquisition metrics depending on the question — always raise as a HANDOFF checkbox. |
| **"New customer in year Y"** | Their first non-cancelled, non-refunded order has `created_at` in year Y. Cancelled prior orders do not make them "old". |
| **AOV (average order value)** | Default = **gross of discounts**, **excluding shipping and tax**, on **non-cancelled orders only**. Confirm per dashboard. |
| **Order status — what counts as "delivered"** | Statuses are looked up from `t_crm_prod_order_status_master`. There is no single boolean. Don't hardcode IDs — join the master and filter by name. |
| **Region** | Use the **delivery address region**, not billing, for operations dashboards. Use **billing region** for finance dashboards. |
| **Year boundary** | Use the **business year** based on `t_crm_prod_order.created_at` in `Asia/Karachi` timezone (UTC+5). The DB stores in UTC — convert. |
| **Qurbani season** | Defined per-year by config in `t_fin_config` (`qurbani_season_start_<year>`, `qurbani_season_end_<year>`). Do not hardcode dates. |
| **Cohort** | A cohort is identified by the year of a customer's **first** Qurbani order — not their first order overall. |
| **Repeat / retention** | "Returned within 30/90/180/365 days" is measured **from the cohort-defining order's created_at**, not from year start. |

---

## Things you'll be tempted to do — don't

- ❌ Don't `SELECT *`. Always list the columns you need.
- ❌ Don't compute year/month with `YEAR(created_at)` directly without a timezone shift — UTC midnight cutoff is wrong by 5 hours for us.
- ❌ Don't filter products by name like `WHERE name LIKE '%qurbani%'`. Use a flag/category column. If unsure which one, leave a `TODO: confirm column` comment in the SQL and ship — the repo owner will fix.
- ❌ Don't hardcode product IDs. Use category names or flags.
- ❌ Don't run the SQL in `queries/*.sql` against the DB through Laravel multiple times in a request — load once, reuse. The example controller shows the pattern.
- ❌ Don't query the WhatsApp tables for analytics yet — they're a separate workstream.

---

## Where to confirm a column or table

1. `app/Models/<Domain>/<Name>Model.php` — read-only reference for relationships, fillable columns, `$casts`.
2. `database/migrations/` — schema source of truth for any column you're not sure exists.
3. `app/Services/DashboardAnalyticsService.php` — the existing dashboard logic; if you can find the metric there, copy that table+column choice rather than reinventing.

You may **read** any file in the repo. You may not **edit** any file outside `analytics-sandbox/`.
