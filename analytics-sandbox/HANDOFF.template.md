# HANDOFF — <Page name> (<date>)

> Copy this file to `analytics-sandbox/handoffs/HANDOFF-<page>.md` for each dashboard you ship into the sandbox. Fill in honestly. Empty checkboxes are not failures — they are **questions** the repo owner will answer when integrating.

---

## What this dashboard answers

A single sentence describing the question this view answers for the business.

> _Example: "How many of 2025's Qurbani buyers were brand-new Nizami customers, and what % of them came back within a year?"_

---

## Visual layout

A 4-line description of the cards / charts on this page. Reference the Vercel prototype if you copied from it.

> _Example:_
> - 3 KPI cards (new customers, share of all new, new-to-Qurbani ratio)
> - Stacked bar of new vs returning per year
> - Conversion funnel (30 / 90 / 180 / 365 day)
> - Cohort comparison table

---

## Tables I queried

List each table you touched.

- `t_crm_prod_order` — for ...
- `t_crm_prod_customer` — for ...
- `t_crm_prod_order_line_item` — for ...

## Files I added / changed

- `analytics-sandbox/views/<page>.blade.php`
- `analytics-sandbox/Controllers/<Page>SandboxController.php`
- `analytics-sandbox/queries/<page>-<metric>.sql` × N
- `analytics-sandbox/handoffs/HANDOFF-<page>.md`

(Pre-commit hook will reject anything outside `analytics-sandbox/`.)

---

## Numbers it produced (against production data, on <date>)

| Metric | Value |
|--------|-------|
| <metric 1> | <value> |
| <metric 2> | <value> |

These are the numbers the repo owner will sanity-check before integration.

---

## Business rules I had to guess (please confirm)

For every assumption you made because you didn't know the rule, add a checkbox. Don't be shy — every one of these is a query bug fix waiting to happen.

- [ ] **Charity hissas:** I included / excluded them from `<metric>`. Correct?
- [ ] **Cancelled orders:** I included / excluded them when defining "new customer". Correct?
- [ ] **AOV definition:** I used gross / net of discounts. Correct?
- [ ] **Region attribution:** I used delivery / billing address. Correct?
- [ ] **Year boundary:** I used `YEAR(created_at)` in UTC / Asia/Karachi. Correct?
- [ ] **Product identification:** I identified Qurbani products by `<column>` = `<value>`. Correct column?

(Add or remove rows as needed.)

---

## Performance

- Heaviest query: `queries/<file>.sql` — runtime against prod data: ~<X> ms
- Total controller round-trips per page load: <N>
- Suspected slow joins or scans: <table / column>

The repo owner will move this into a service with caching when integrating; you don't need to optimise further.

---

## Open questions for the repo owner

Free-form list of anything else you're unsure about — naming, edge cases, weird data you saw.

1. ...
2. ...
