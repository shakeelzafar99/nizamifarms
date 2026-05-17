# Qurbani Smart-Box Calculation — Design Plan (May 2026)

**Status:** Plan only. No code in this iteration.
**Owner:** Shabib (please review and edit assumptions)
**Related work:** `add_qurbani_item_status_and_rider_may2026.sql`, mobile `QurbaniOpenOrdersScreen.js` (per-line-item status + rider).

---

## 1. Problem statement

A qurbani customer order can have multiple products (Hissa, Bakra, Lamb Dumba, etc.) and each product can have a `quantity > 1`. Today these line items are treated as independent units in the region view, but operationally they form **physical bundles** ("boxes") that one rider carries on one trip.

Two operations problems we want to address:

1. **Mistakenly assigning the same rider to items across slots.** If a customer orders 2 Hissa for the Afternoon slot and 2 Bakra for the Evening slot, those go on different trips. Bulk-assigning a single rider to "all of order #SH-1234" is wrong — Afternoon and Evening should be treated as separate jobs.
2. **No visibility on bundle progress.** When a rider is loading the van, they need to know "how many packets does this customer get on this trip" (e.g. 4 total) and which packet they're handling right now (e.g. packet 2/4). Today a rider has to mentally piece this together from the line item list.

---

## 2. Definitions

| Term | Meaning |
|---|---|
| **Bundle / Box** | The set of physical packets one rider carries to one customer on one trip. |
| **Bundle key** | `(order_id, qurbani_day, qurbani_slot)` — items sharing this triple form one bundle. |
| **Packet** | One billable unit of `quantity` from a line item. A line item with `quantity=2` becomes two packets. |
| **Bundle size (N)** | Total packet count in the bundle = Σ `quantity` over all line items sharing the bundle key. |
| **Position (i/N)** | Stable 1-indexed position of a packet inside its bundle, used for the printed sticker label. |

---

## 3. Bundle key — what `(order_id, qurbani_day, qurbani_slot)` means in practice

For an order `SH-1234` with these line items:

| LI | Product | Qty | Day | Slot |
|---|---|---|---|---|
| 1 | Hissa | 2 | Day 1 | Afternoon |
| 2 | Bakra | 1 | Day 1 | Afternoon |
| 3 | Hissa | 2 | Day 1 | Evening |

We get **two bundles**:

- **Bundle A** = `(1234, Day 1, Afternoon)` → size 3 (2 Hissa + 1 Bakra).
- **Bundle B** = `(1234, Day 1, Evening)` → size 2 (2 Hissa).

A rider who takes Bundle A is carrying 3 packets to one customer. The labels would be `1/3`, `2/3`, `3/3`. Bundle B is a separate trip with labels `1/2`, `2/2`.

### Why include `qurbani_day` in the key?

Even within a single order, day-level splits (Day 1 vs Day 2) absolutely cannot be in the same bundle — those are different ritual events. Including day in the key makes this impossible by construction.

### What about `qurbani_region` / `qurbani_sub_region`?

We **deliberately do not** include region/sub-region in the bundle key. Region is the customer's, not the slot's; an order's items always go to the same address regardless of region splits. If two items in the same slot somehow had different regions (data error), our bundle logic would still group them — which is the correct fail-safe behaviour because the rider physically can't deliver them separately.

### What about `qurbani_delivery_type`?

Edge case to think about: if `Delivery` and `Self Collection` items somehow exist in the same order/day/slot, they're **not** the same bundle. Recommendation: include `qurbani_delivery_type` in the bundle key too. So the canonical key is:

```
(order_id, qurbani_day, qurbani_slot, qurbani_delivery_type)
```

We can revisit this if Shabib confirms `Self Collection` items shouldn't be packetized at all.

---

## 4. Position calculation

Stable ordering rule (so `1/N` doesn't shuffle between page reloads):

```
ORDER BY
  category_priority ASC,   -- Hissa(1) → Bakra(2) → Dumba(3) → Other(99)
  product_id ASC,           -- groups same SKU together
  line_item_id ASC,         -- final tie-breaker
  packet_index ASC          -- 1..quantity within a line item
```

For a line item with `quantity=N`, we expand into N "virtual packets" labelled
`1..N` of that line item, then assign global positions `1..bundleSize` across
the merged sequence.

**Worked example** (Bundle A from §3, qty 2 Hissa + qty 1 Bakra):

```
Sorted packets:
  Hissa (LI #1, packet 1) → position 1/3
  Hissa (LI #1, packet 2) → position 2/3
  Bakra (LI #2, packet 1) → position 3/3
```

So the rider's box stickers read:
- `SH-1234 · Day 1 · Afternoon · 1/3 · Hissa`
- `SH-1234 · Day 1 · Afternoon · 2/3 · Hissa`
- `SH-1234 · Day 1 · Afternoon · 3/3 · Bakra`

---

## 5. Storage — computed not stored

Bundle key and position are **derived** from the line-item rows. We do **not**
need new DB columns:

- `bundle_key` is just a deterministic concatenation of existing columns.
- `bundle_size` is `SUM(quantity)` over the bundle.
- `position` is computed once per render from a stable sort.

This keeps the schema clean and the truth single-sourced. The mobile client can
compute these locally; the printed-sticker generator on the server can compute
them with one extra `SELECT … GROUP BY (order_id, qurbani_day, qurbani_slot, qurbani_delivery_type)`.

If we later decide we need to *persist* the position (e.g. for audit / printed
stickers must reproduce after a re-print), add `bundle_position` and
`bundle_size` as nullable computed columns and a small backfill script. Skip
that until there's a concrete reason.

---

## 6. Soft-warn enforcement (chosen behaviour, per Pass 2 sign-off)

When the user triggers a bulk action on the mobile region view:

| Action | Detection | Behaviour |
|---|---|---|
| Bulk assign rider | Selection contains items from one `order_id` whose bundle keys differ | **Warn**: "Order SH-1234 has items in 2 different bundles (Day 1 Afternoon, Day 1 Evening). The same rider will be assigned to both — proceed?" Confirm / Cancel. |
| Bulk change status to `out_for_delivery` | Same detection | **Warn**: "Order SH-1234 spans multiple bundles. Marking all as Out for Delivery may not match physical reality — proceed?" Confirm / Cancel. |
| Bulk change status to other values (`open`, `slaughtered`, `delivered`) | — | No warning. These are pre-trip / post-trip states where bundle splits don't matter. |

**Rule**: the warning fires only when a single `order_id` in the selection has >1 distinct bundle keys. Selections that span many orders but each with a single bundle don't trigger it (that's normal multi-order loading).

**UI wording**: keep it as a single-line dialog with a clear primary action ("Assign anyway") and a secondary "Cancel". Do not block — operations sometimes legitimately want to override.

---

## 7. UI surfaces

### 7.1 Region view row

Add a small `1/3` chip next to the day/slot chips when the row's bundle size is >1. Hidden when bundle size = 1. Greyed slightly so it doesn't dominate.

```
[order#] [Customer]                    [✓]
2x Hissa
[Day 1] [Afternoon] [Delivery] [Bundle 1/3]
```

### 7.2 Order detail modal

Add a "Bundles" section above the line items list:

```
Bundles for this order:
  • Day 1 / Afternoon / Delivery   — 3 packets   [Assign rider ▾]
  • Day 1 / Evening   / Delivery   — 2 packets   [Assign rider ▾]
```

Each bundle row exposes a quick rider-assignment shortcut that hits the
existing `/qurbani/line-items/bulk-assign-rider` endpoint with all of that
bundle's line item IDs. This is the clean path to "assign all packets in this
bundle to one rider" without the user having to multi-select manually.

### 7.3 Sub-region header (region view)

Show a `bundle count` next to `item count`:

```
DHA Phase 1   12 items · 7 bundles    ▼
```

Helps operations gauge how many physical trips a sub-region needs.

### 7.4 Printed packet sticker (web side)

Once the position is computed, the existing invoice/sticker template can show:

```
NF QURBANI · SH-1234
DHA Phase 1 · Day 1 / Afternoon · Delivery
Packet 2 of 3 · Hissa
```

Stickers are out of scope for the mobile-first pass; flag them for the web sync that follows.

---

## 8. Edge cases to handle in the implementation

1. **Quantity 0 or NULL line items.** Skip — not a packet.
2. **Free items (`is_free=1`).** Include in bundle position numbering. Sticker
   should still print so the rider hands them over. Optionally tag with `(Free)`.
3. **Items missing `qurbani_day` or `qurbani_slot`.** Group into a synthetic
   `(order_id, "—", "—")` bundle so they still show a position. The mobile
   warning fires for these too because they can't actually be loaded yet.
4. **Cancelled / deleted line items.** Excluded from the bundle SELECT.
5. **Order partially delivered.** Bundle size stays the same; positions are
   stable. The rider sees `1/3 ✓ delivered`, `2/3 ✓ delivered`, `3/3 open` —
   useful at-a-glance.

---

## 9. Implementation order (when we're ready to build it)

1. **Server-side helper** `BundleHelper::computeBundles($lineItems)` returning
   a per-line-item map of `{bundleKey, bundleSize, position}`.
2. **Wire into `getQurbaniOpenOrders`** so each line item in the API response
   carries `bundle_key`, `bundle_size`, `bundle_position`.
3. **Mobile region view** — render the `i/N` chip and the bundle count in
   sub-region headers. Pure presentation, no new endpoints needed.
4. **Soft-warn dialogs** for bulk rider assignment and bulk OFD status
   change. Pure client-side detection using the new fields.
5. **Order detail modal** — Bundles section with per-bundle rider shortcut.
6. **Web sync** — once mobile has been used in the field for a Qurbani
   weekend and the rules feel right, port the bundle helper to the web order
   list and update sticker templates.

---

## 10. Questions still open for Shabib

- Should `Self Collection` items appear in bundles at all, or are they
  ignored (since the rider doesn't carry them)?
- For the printed sticker: do we want one sticker per packet, or one sticker
  per line item (with `2x` printed on it)?
- Bundle stability across re-prints: do we need to **persist** position once
  generated, so a re-printed sticker after a line-item edit doesn't shuffle
  positions? Recommendation: don't persist; positions are deterministic from
  the existing data so re-prints will match unless the line items themselves
  change.
- Does the "soft warn" threshold also include items with no day/slot set yet
  (treated as their own bundle)? My recommendation: yes, surfacing missing
  metadata is exactly the kind of friction we want.

---

## 11. What is **not** in scope for this plan

- Hard blocking of cross-bundle assignments (rejected in Pass 2 — soft warn was
  chosen).
- Auto-splitting a bulk assignment into per-bundle assignments (rejected in
  Pass 2).
- Creating physical pre-printed packet labels.
- Multi-stop route optimization for a rider's bundles.
