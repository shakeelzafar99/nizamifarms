# Learnings

A growing log of business rules and query corrections that came out of integrating sandbox prototypes. **The dashboard developer's AI agent must read this before writing new queries** — every entry here is a bug it should never reproduce.

> Format: one bullet per correction, max two lines. Newest at top.

---

## 2026-04 — initial seed

- **Charity hissas (product category `qurbani_charity`)** are excluded from "Qurbani customers acquired" metrics by default — they're donations, not customer purchases.
- **"New customer in year Y"** = first **non-cancelled** order's `created_at` in year Y (timezone Asia/Karachi). Cancelled orders never count toward firsts.
- **AOV** is gross of discounts, **excluding** shipping and tax, on non-cancelled orders only.
- **Year boundaries** must shift `created_at` to Asia/Karachi (UTC+5) before extracting year — otherwise late-night Pakistan orders fall into the wrong year.
- **Don't double-count Shopify orders:** `t_crm_shopify_order` rows that have been converted into `t_crm_prod_order` will appear in both. Filter to one source per metric.
- **Region attribution:** delivery address for operations, billing address for finance.
- **Order status:** join `t_crm_prod_order_status_master` by name — never hardcode status IDs (they differ across environments).

---

<!-- Add new learnings ABOVE this line, in reverse-chronological order -->
