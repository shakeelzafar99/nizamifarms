# NF → Customer App: Live Rider Tracking + Order History

**Audience:** the customer-app (Vercel) backend developer.
**Status:** BUILT. Companion to `CUSTOMER_APP_INTEGRATION.md` — this file zooms
in on the **live rider GPS tracking** endpoint (with freshness) and gives a
**brief** on the **order history** endpoint.
**Last updated:** June 23, 2026

Both are authenticated, server-to-server **pull** endpoints. Both use the same
`CUSTOMER_APP_INBOUND_TOKEN` and base URL as every other pull endpoint — **no
new credentials**. NF authenticates *your backend*, not the end user, so you
MUST enforce that the caller owns the order / number on your side.

---

## PART 1 — Live rider GPS tracking

### 1.1 What it is

While an order is **out for delivery**, this endpoint gives you the rider's
latest GPS position, the customer's drop point, an ETA, how many stops are
ahead, and **how fresh the GPS fix is**. You poll it to drive the live map.

### 1.2 Endpoint & auth

```
GET https://app.nizamifarms.com/api/customer-app/orders/{orderNumber}/tracking
Authorization: Bearer <CUSTOMER_APP_INBOUND_TOKEN>
```

- `{orderNumber}` may be the bare Shopify number (`1234`) or the NF number
  (`SH-1234`) — both resolve to the same order.
- Enforce customer ownership on **your** side (resolve the customer from your
  mobile JWT before calling us).

### 1.3 Response shapes

```jsonc
// 200 — a live fix (render the map)
{
  "success": true,
  "tracking": {
    "order_number":  "1234",
    "nf_order_number": "SH-1234",
    "rider":       { "lat": 33.58, "lng": 73.16 },   // server-side rider GPS
    "destination": { "lat": 33.55, "lng": 73.18 },   // customer drop point
    "stops_away":  2,
    "eta":         "2026-06-15T16:32:00Z",           // predicted arrival (may be null)
    "updated_at":  "2026-06-15T16:05:11Z"            // when this GPS fix was captured
  }
}

// 200 — no live fix right now (NOT an error). Fall back to the timeline.
{ "success": true, "tracking": null }

// 404 — unknown order
{ "success": false, "tracking": null }
```

### 1.4 Field reference

| Field | Meaning |
|---|---|
| `order_number` | Bare Shopify number |
| `nf_order_number` | NF's full number (`SH-1234`) |
| `rider.lat/lng` | Rider's latest **server-side** GPS position (not the device GPS) |
| `destination.lat/lng` | Customer's verified pin (geocoded fallback) |
| `stops_away` | Count of the rider's other out-for-delivery stops sequenced **ahead** of this one. `0` = this drop is next |
| `eta` | Predicted arrival time, ISO 8601 UTC. May be `null` until NF computes the route ETA |
| `updated_at` | **Exact capture time of the GPS fix**, ISO 8601 UTC — your freshness signal |

Map to your `LiveDelivery` shape 1:1 (snake_case → camelCase): `stops_away →
stopsAway`, `updated_at → updatedAt`, etc.

### 1.5 GPS freshness — how `updated_at` works (important)

`updated_at` is **the exact moment NF captured that rider GPS fix**. Compute age
as `now − updated_at` and drive your "live / X min ago / reconnecting"
indicator from it.

- The rider app reports a GPS heartbeat **roughly every 5 minutes**, so
  `updated_at` advances every few minutes on a healthy delivery. Don't expect
  second-by-second movement — smooth/interpolate on your side if desired.
- **NF enforces a staleness cutoff before it ever sends a fix.** Any reading
  older than `CUSTOMER_APP_TRACKING_STALENESS` minutes (**default 30**) is
  suppressed and you get `tracking: null` instead of a stale dot. So **every
  `rider` fix you receive is guaranteed ≤ 30 min old**, and `updated_at` tells
  you exactly how old within that window.
- `eta` and `updated_at` are independent: `eta` = predicted arrival; `updated_at`
  = when we last *saw* the rider. A fix can be fresh while the ETA is minutes out.

**Suggested UI rule of thumb:**

| `now − updated_at` | Show |
|---|---|
| ≤ ~6 min | "Live" (green) |
| > ~6 min but still returned | "Updated N min ago" (amber) |
| `tracking: null` | "Locating rider…" / fall back to timeline |

### 1.6 When `tracking` is `null`

You'll get `tracking: null` (a normal `200`) whenever any of these is true:

1. the order is **not** `out_for_delivery`,
2. no rider is assigned to it yet,
3. the latest rider GPS fix is **older than the staleness cutoff** (stale),
4. we have **no usable customer drop point** (no verified pin / geocode).

Treat `null` as "no live tracking right now" — keep showing the status
timeline. It is **not** an error.

### 1.7 How to use it (recommended flow)

1. When your order screen shows a status of `out_for_delivery`, start polling
   this endpoint at an adaptive cadence — **~10–30s while foregrounded**, back
   off when backgrounded. That's well within our limits.
2. On `tracking != null`: draw/refresh the rider marker (`rider`), the
   destination (`destination`), show `stops_away` and `eta`, and render the
   freshness badge from `updated_at` (§1.5).
3. On `tracking == null`: hide/grey the map, show "locating rider" or the
   timeline.
4. Stop polling once the order is `delivered`.

> `route` (a road polyline) is **not** sent yet — draw a straight line or your
> own route. We can add it later if you need it.

---

## PART 2 — Order history (brief)

### 2.1 What it is

All of a customer's NF orders, keyed on their **mobile number**, newest first.
Use it to populate "My Orders".

### 2.2 Endpoint & auth

```
GET https://app.nizamifarms.com/api/customer-app/customers/{mobile}/orders?limit=20
Authorization: Bearer <CUSTOMER_APP_INBOUND_TOKEN>
```

- `{mobile}`: any format — NF normalizes to the **last 10 digits** before
  matching (`+92 300 1234567`, `0300-1234567` → `3001234567`).
- `limit`: optional, default **20**, capped at 50.
- Pass **only the authenticated user's own verified number**.

### 2.3 What it returns

Compact rows (header + totals + status), newest first:

```jsonc
{
  "success": true,
  "matched_phone": "3001234567",      // the normalized key NF resolved
  "orders": [
    {
      "order_number": "1234",          // bare Shopify number
      "nf_order_number": "SH-1234",    // NF's full number
      "source": "shopify",             // shopify | manual | qurbani | other
      "status": "delivered",
      "placed_at": "2026-06-15T07:42:10Z",
      "total": 4350,
      "currency": "PKR",
      "item_count": 2
    }
    // … up to `limit`, newest first
  ]
}
```

| Field | Meaning |
|---|---|
| `order_number` / `nf_order_number` | Bare number / NF's prefixed number |
| `source` | Channel: `shopify` (`SH-`), `manual` (`NF-`), `qurbani` (`QUR`), `other` |
| `status` | Current order status |
| `placed_at` | Order date (ISO 8601) |
| `total` / `currency` | Order total |
| `item_count` | Number of line items |

### 2.4 Detail level & related calls

- Rows are **compact** — they do **not** include line items. To show a single
  order's **full detail** (line items, quantities, prices, totals, address,
  ETA window), call the **snapshot** endpoint:
  `GET /api/customer-app/orders/{orderNumber}` (see `CUSTOMER_APP_INTEGRATION.md`).
- Returns **all** order types for that number — `SH-`, `NF-`, and `QUR`.
- Excludes pre-acceptance Shopify staging orders, and orders saved without a
  phone (no customer link).
- NF follows merged-customer chains, so a merged duplicate still returns the
  surviving customer's full history.

### 2.5 Empty results & logging

- If no customer matches the number, you get a normal **`200`** with
  `"orders": []` (**not** a 404). Check the echoed `matched_phone` to confirm
  NF normalized the number to what you expected.
- An empty result is **not** logged as an error on NF's side — it usually means
  a number-matching issue (the number doesn't normalize to the same last-10
  digits as the order, the order is under a different customer record, or the
  order has no phone). Only genuine server exceptions are logged
  (`laravel.log`, tagged `CustomerAppController::customerOrders failed`).
- If `matched_phone` is correct but the list is still empty when you expect
  orders, send Shabib the mobile number (or an example order number) and he can
  trace the customer-record link on NF's side.
