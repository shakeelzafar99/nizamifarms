# NF -> Customer App Integration

**Phase 1 — Order status webhooks (outbound, LIVE)**
**Phase 2 — ETA window + live rider tracking (BUILT, opt-in)**
**Phase 3 — Full order snapshot + order history by mobile number (BUILT, section 15)**
**Phase 4 — Customer profile/existence check, verified pin (read+write), invoice image (BUILT, section 16)**
**Last updated:** June 21, 2026

This is the only document the customer-app (Vercel) developer needs.
NF pushes order status changes to a single endpoint on your side, and
exposes a small set of authenticated server-to-server **pull** endpoints
for live tracking, full order detail, and history.

**Data-access model in one line:** everything is either a signed webhook
(push) or an authenticated NF API call (pull) — there is no direct
database access. See [section 15.1](#151-architecture-why-apis-not-direct-database-access)
for why (it matters for your serverless + our shared-hosting setup).

---

## 1. What you build on your side

A single HTTP endpoint:

```
POST <your-public-url>/webhooks/nf/order-status
Content-Type: application/json
```

It must:

1. Verify the `X-NF-Signature` header (see [section 3](#3-auth--hmac-signature)).
2. Persist the event (see [section 5](#5-idempotency-and-ordering-rules-must-implement)).
3. Return `2xx` within **10 seconds**.

Any non-2xx response, or any timeout, will be retried by NF with
exponential backoff: 1m, 5m, 30m, 2h, 12h, 24h. After that the event
is marked dead on NF's side and surfaced to ops.

If your handler does heavy work (DB writes, push notifications, fan-out
to mobile clients), do it asynchronously: queue the work and return
`200` immediately.

---

## 2. When NF fires events

NF sends one event per status change for every order whose NF
`order_number` starts with `SH-` (i.e. Shopify orders accepted into
NF's operational system).

The lifecycle from your customer's perspective:

```
1. Customer places order on Shopify              -> Shopify webhook to YOU (already wired)
2. NF operator accepts the Shopify order in NF   -> NF webhook to YOU: status="accepted"  <-- first event you'll receive from NF
3. Operator/rider moves it through the workflow  -> NF webhook to YOU: status="<NF status>"
4. Order is delivered                            -> NF webhook to YOU: status="delivered"
```

Manually-created NF orders (`NF-` prefix) and Qurbani orders (`QUR`
prefix) are **NOT** sent in Phase 1.

---

## 3. Auth — HMAC signature

Every request from NF carries this header:

```
X-NF-Signature: t=<unix_ts>,v1=<hex_hmac_sha256>
```

`v1` is computed as:

```
v1 = HMAC_SHA256(secret, "<unix_ts>." + raw_request_body)
```

Important: the HMAC is over the **raw body string**, before any JSON
parsing. Capture it in a middleware that runs before your JSON parser.

### Verification (Node / Vercel)

```ts
import crypto from "node:crypto";

export function verifyNfSignature(
  rawBody: string,
  header: string | null,
  secret: string,
): boolean {
  if (!header) return false;

  const parts = Object.fromEntries(
    header.split(",").map((p) => p.trim().split("="))
  );
  const t = parts.t;
  const v1 = parts.v1;
  if (!t || !v1) return false;

  // Reject anything older than 5 minutes — protects against replay.
  if (Math.abs(Math.floor(Date.now() / 1000) - Number(t)) > 300) {
    return false;
  }

  const expected = crypto
    .createHmac("sha256", secret)
    .update(`${t}.${rawBody}`)
    .digest("hex");

  // Constant-time compare.
  const a = Buffer.from(v1, "hex");
  const b = Buffer.from(expected, "hex");
  return a.length === b.length && crypto.timingSafeEqual(a, b);
}
```

If verification fails, return `401` and do not process the body.

There is also a convenience header that you may use for logging only
(do not trust it for auth):

```
X-NF-Event-UUID: <same uuid that appears in payload.event_uuid>
```

---

## 4. Payload

Always JSON. Phase 1 has exactly one event type: `order.status_changed`.

```json
{
  "event_uuid": "0d4f12c0-2b34-4a52-9d77-7c1f0a40a3c1",
  "event_type": "order.status_changed",
  "occurred_at": "2026-05-20T07:42:11Z",
  "data": {
    "order_number": "1234",
    "nf_order_number": "SH-1234",
    "status": "accepted",
    "previous_status": null,
    "changed_at": "2026-05-20T07:42:10Z"
  }
}
```

### Field reference

| Field | Type | Meaning |
|---|---|---|
| `event_uuid` | UUID v4 | **Use this for dedupe.** Retries reuse the same UUID. |
| `event_type` | string | Always `order.status_changed` in Phase 1. |
| `occurred_at` | ISO-8601 UTC | When NF queued the event. |
| `data.order_number` | string | The bare Shopify order number (e.g. `"1234"`). **This is the key you match against your existing Shopify-sourced order.** |
| `data.nf_order_number` | string | NF's internal id (e.g. `"SH-1234"`). Store it for support correlation; you don't need to key on it. |
| `data.status` | string | The customer-facing status. **Code your UI against this field.** See [section 4.1](#41-the-status-field). |
| `data.previous_status` | string \| null | Raw NF status the order was in before this change, or `null` on the very first event. |
| `data.changed_at` | ISO-8601 UTC | When NF actually updated the order. **Use this for ordering** (see [section 5](#5-idempotency-and-ordering-rules-must-implement)). |

### 4.1 The `status` field — exact values and the mapping you should use

The first event NF ever sends for a given order will have
`status: "accepted"` and `previous_status: null`. That's the
"customer's order has been accepted by NF" moment (internally NF sets
the order to `new` on acceptance, but we send you `accepted`).

After that, `status` is NF's raw internal `order_status` verbatim. NF
owns the order; you own the NF-raw -> your-canonical mapping. Here is
the **complete, authoritative list** of values we can send and the
canonical stage we recommend each maps to (your enum:
`placed | accepted | preparing | out_for_delivery | delivered | cancelled | refunded | in_progress`):

| NF sends (`status`) | Map to your stage | Note |
|---|---|---|
| `accepted` (first event only) | `accepted` | The acceptance moment. |
| `new` | `accepted` | Same meaning if ever seen post-first-event. |
| `priority` | `preparing` | Internal "work this first" flag. |
| `processing` | `preparing` | **Important:** maps to your `preparing`, not a literal "processing". |
| `pending` | `in_progress` | Rare for converted orders. |
| `on_hold` / `on-hold` | `in_progress` | No hold stage in your enum. |
| `dispatch` | `out_for_delivery` | Dispatched to a rider. |
| `out_for_delivery` | `out_for_delivery` | Enables the live map (section 12). |
| `delivered` | `delivered` | Include the timeline `at`. |
| `completed` | `delivered` | Treated as delivered. |
| `cancelled` | `cancelled` | Also set `cancelled:true` + `cancelledAt`. |
| `refunded` | `refunded` | Terminal. |

The realistic lifecycle of an `SH-` order is just
`accepted -> processing -> out_for_delivery -> delivered`
(or `cancelled` / `refunded`). The other rows are listed so nothing
ever surprises your mapping.

**Keep your `in_progress` catch-all** for any value not in this table —
if NF ever adds a new internal status you'll receive it as a new string
and must not break. But for the values above, please use the explicit
mapping (especially `processing -> preparing`), otherwise the customer
sees a vague "In Progress" instead of "Preparing".

### 4.2 Matching our order number to yours (read this — easy to get wrong)

- We send `order_number` as the **bare Shopify order number** (e.g.
  `"1234"`) — this is the value to join on.
- We also send `nf_order_number` (e.g. `"SH-1234"`) for support/logging
  only; you do not need to match on it.

NF stores Shopify-converted orders internally as `SH-1234`, where `1234`
is **Shopify's numeric `order_number`** (not the display name, not the
GID). We strip the `SH-` prefix before sending. So you must match our
`order_number` against **Shopify's numeric order number** on your side —
not the formatted display name (e.g. not `#NF-2001`). Please confirm
your join key is the numeric Shopify order number.

### 4.3 Fields NF reads from the Shopify order (populate these when you create it)

Your app creates the order on Shopify; NF ingests it via Shopify's order
webhook and maps the fields below (`OrderModel::mapShopifyOrder`). Make
sure these are populated when you create the order — anything missing is
blank in NF (and therefore blank in what we send back), and **missing
SKUs cause line items to be dropped and conversion to fail.**

**The correlation key (most important):**

| Shopify field | Used for |
|---|---|
| `order_number` (bare numeric, e.g. `20627`; falls back to `name`) | Becomes `SH-20627` in NF; sent back stripped on every webhook. **This is your join key.** |
| `id` | Shopify order id. **Required** — NF rejects a payload with no `id`. |
| `customer.id` | Stored as NF's external customer id (useful, but **not** the history key — see below). |

> **Order history is keyed on the customer's MOBILE NUMBER, not the
> Shopify customer id** (section 15.3). What matters for history is that
> the order carries a **phone** (`shipping_address.phone` or
> `billing_address.phone`) — NF tags the customer by the normalized last
> 10 digits. Always populate the address phone, or the order won't link
> to the customer's history.

**Order-level fields NF reads:**
- `financial_status` (→ status), `created_at` (→ order date), `currency`, `email`, `note`
- Money: `subtotal_price`, `total_discounts`, `total_shipping_price_set.shop_money.amount`, `total_tax`, `total_price`, `total_weight`
- Tip: `current_total_tip_set.shop_money.amount` (preferred) or `total_tip_received`
- Payment: `gateway` → `payment_gateway_names[0]` → `transactions[0].gateway`

**Address** (NF uses `shipping_address` when it has `address1`, else `billing_address`):
- `first_name`, `last_name`, `company`, `phone`, `address1`, `address2`, `city`, `province`, `zip`, `country`

**Line items** (`line_items[]`) — two hard rules:
1. **Every line item MUST have a `sku`** that exists as a product in NF.
   NF drops any item without a SKU, and conversion fails if a SKU isn't
   found in NF. *This is the #1 cause of orders not converting.*
2. Items literally named `tip` / `tips` / `gratuity` are dropped (tip is
   read from the order-level field instead).
   Per item NF reads: `id`, `sku`, `name`, `product_id`, `variant_id`,
   `vendor`, `quantity`, `price`.

> For the live-map **destination** to resolve, accurate `address1` /
> `city` matter — NF geocodes the address (or uses a verified pin) to
> get the customer's drop coordinates.

---

## 5. Idempotency and ordering rules (must implement)

NF retries on any non-2xx, and the network can deliver retries out of
order. Your handler must be safe against both.

1. **Dedupe by `event_uuid`.** When you process an event, store its
   `event_uuid`. If you receive the same `event_uuid` again, return
   `200` and do nothing else.

2. **Order-tolerance.** Track the latest `data.changed_at` you have
   applied for each order. When a new event arrives, compare its
   `changed_at` to the latest applied value:
   - If the new `changed_at` is **older or equal**, return `200` and
     do not overwrite. (Still record the `event_uuid` as processed.)
   - If the new `changed_at` is **newer**, apply it and update the
     "latest applied" timestamp.

3. **Unknown orders.** If you receive an event for an `order_number`
   that you don't have yet (e.g. customer placed via Shopify but your
   own Shopify sync hasn't run yet), still accept it — store the row
   and reconcile when the Shopify data arrives. Do NOT 4xx; that would
   trigger NF retries that won't help.

A reasonable schema on your side:

```sql
nf_status_events (
  event_uuid        TEXT PRIMARY KEY,
  order_number      TEXT NOT NULL,
  status            TEXT NOT NULL,
  previous_status   TEXT,
  changed_at        TIMESTAMPTZ NOT NULL,
  raw_payload       JSONB NOT NULL,
  received_at       TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX ON nf_status_events (order_number, changed_at DESC);
```

And your `orders` table gains:

```
nf_order_number   TEXT
nf_status         TEXT
nf_status_at      TIMESTAMPTZ   -- the changed_at of the latest applied event
```

The "apply" step is then:

```sql
UPDATE orders
   SET nf_status      = $1,
       nf_status_at   = $2,
       nf_order_number= $3
 WHERE order_number   = $4
   AND (nf_status_at IS NULL OR nf_status_at < $2);
```

---

## 6. Secret hand-off and rotation

### Initial setup

1. You generate a random 32+ char secret on your side
   (`openssl rand -hex 32` is fine).
2. Send it to me out-of-band (Signal / WhatsApp).
3. I'll set it as `CUSTOMER_APP_WEBHOOK_SECRET` in NF's `.env` and
   reload config.
4. You set the same value as `NF_WEBHOOK_SECRET` (or whatever you call
   it) on your Vercel project.

### Rotation later

1. Tell me a new value in advance.
2. Configure your verifier on Vercel to accept BOTH old and new
   secrets temporarily.
3. I'll roll the secret on NF's side.
4. Once NF has been signing with the new secret for 24h, drop the old
   secret on Vercel.

---

## 7. Local testing without NF

You can test your verifier and handler entirely locally with a curl
command. Pseudo-script:

```bash
SECRET="test_secret_replace_me"
T=$(date +%s)
BODY='{"event_uuid":"test-uuid-0001","event_type":"order.status_changed","occurred_at":"2026-05-20T07:42:11Z","data":{"order_number":"1234","nf_order_number":"SH-1234","status":"accepted","previous_status":null,"changed_at":"2026-05-20T07:42:10Z"}}'
SIG=$(printf "%s.%s" "$T" "$BODY" | openssl dgst -sha256 -hmac "$SECRET" | awk '{print $2}')

curl -i -X POST http://localhost:3000/webhooks/nf/order-status \
  -H "Content-Type: application/json" \
  -H "X-NF-Signature: t=${T},v1=${SIG}" \
  -H "X-NF-Event-UUID: test-uuid-0001" \
  -d "$BODY"
```

Things to verify:

- `200` returned within 10s when signature is valid.
- `401` (or any non-2xx) when you tamper with the body or sig.
- Sending the same body twice produces the same final state (dedupe).
- Sending the same `event_uuid` with an OLDER `changed_at` does NOT
  overwrite a newer one (order-tolerance).

---

## 8. What to send back to me when you're ready

1. The exact public URL of your endpoint.
2. The webhook secret (out-of-band).
3. Confirmation that you've implemented:
   - HMAC + timestamp-skew verification,
   - dedupe on `event_uuid`,
   - ordering check on `data.changed_at`.

I'll then flip `CUSTOMER_APP_WEBHOOKS_ENABLED=true` on NF, fire one
test conversion, and we'll watch the event arrive together.

---

## 9. Phase 2 — ETA window + live tracking (BUILT, ready when you are)

Everything in Phase 2 is **additive and already implemented on NF's
side**. None of it changes your status webhook from Phase 1 — you can
finish Phase 1 status testing first and adopt these whenever you're
ready.

There are two independent pieces:

- **The delivery window** (`expectedDeliveryWindow`) — arrives via the
  webhook (push). Section 10.
- **The live rider map** (`GET …/tracking`) — you pull from NF on
  demand. Section 12.

---

## 10. `expectedDeliveryWindow` — the coarse ETA string

### 10a. It rides on the status webhook as an optional field

The `order.status_changed` payload now always includes an `eta_window`
field inside `data`:

```jsonc
"data": {
  "order_number": "1234",
  "status": "out_for_delivery",
  "eta_window": "Today, 4:10-4:40 PM",   // <- new optional field; may be null
  "...": "..."
}
```

- It's a short, **already-formatted display string** — do not parse it.
  Drop it straight into your status pill / `expectedDeliveryWindow`.
- Format examples: `"Today, 4:10-4:40 PM"`, `"Tomorrow, 11:50 AM-12:20 PM"`,
  `"Jun 17, 4:10-4:40 PM"`. Plain hyphen, no em dash, short.
- It is `null` until NF has computed a route ETA. In practice it's
  usually `null` on the `out_for_delivery` transition itself, because
  our dispatcher computes the route ETA a moment later — which is why
  the refresh in section 11 exists.
- Adding this field does not require any change on your side; ignore it
  until you wire up `expectedDeliveryWindow`.

### 10b. The window is a 30-minute band around our route ETA

NF derives the window from the rider's Google-route ETA. The start is floored
to the nearest 10 minutes, then a 30-minute band is added — so an ETA of
11:57 AM becomes `"11:50 AM-12:20 PM"`, and 4:12 PM becomes `"4:10-4:40 PM"`.
It is a *promise window*, not the precise live ETA (that's on the tracking
feed, section 12).

> **Assumption flag:** the 30-minute band width
> (`CUSTOMER_APP_ETA_WINDOW_BAND_MINUTES`) and the 10-minute rounding
> (`CUSTOMER_APP_ETA_WINDOW_ROUND_TO_MINUTES`) are business-rule defaults. If
> you'd prefer different values, tell me and I'll change one config value each.

---

## 11. `order.eta_updated` — window refresh (OFF until you opt in)

Because the route ETA is computed shortly *after* an order goes
out-for-delivery, NF can send a follow-up event when the ETA is
(re)calculated, so your `expectedDeliveryWindow` stays fresh:

```jsonc
{
  "event_uuid": "…",
  "event_type": "order.eta_updated",
  "occurred_at": "2026-06-15T16:05:00Z",
  "data": {
    "order_number": "1234",
    "nf_order_number": "SH-1234",
    "status": "out_for_delivery",
    "eta": "2026-06-15T16:32:00Z",         // precise ISO
    "eta_window": "Today, 4:30-5:00 PM",   // coarse string (ETA 4:32 -> floor 4:30 +30m)
    "changed_at": "2026-06-15T16:05:00Z"
  }
}
```

**This event must NOT touch your status timeline.** It carries no new
stage — only an ETA refresh. Handle it by branching on
`event_type === "order.eta_updated"` and updating only the order's
`expectedDeliveryWindow` (and optionally a cached precise `eta`).

> This event is **disabled on NF's side by default.** It will not be
> sent until (a) you confirm you branch on `event_type` for it, and
> (b) we flip the `CUSTOMER_APP_EMIT_ETA_UPDATES` switch on. Until then
> you'll never receive it, so it can't create duplicate timeline rows.
> Your Phase 1 handler is unaffected.

---

## 12. Live rider tracking — `GET …/tracking` (you pull from NF)

This backs your `DeliveryMapCard`. Your backend calls **NF** on demand
(server-to-server) while an order is `out_for_delivery`, then serves the
result to your app at your own `GET /api/customer/orders/{orderNumber}/tracking`.

### 12a. Endpoint on NF

```
GET https://app.nizamifarms.com/api/customer-app/orders/{orderNumber}/tracking
Authorization: Bearer <CUSTOMER_APP_INBOUND_TOKEN>
```

- `{orderNumber}` may be the bare Shopify number (`1234`) or `SH-1234` —
  both resolve.
- **Auth is a separate bearer token** (`CUSTOMER_APP_INBOUND_TOKEN`),
  NOT the webhook signing secret. We'll exchange it out-of-band like the
  webhook secret. (Kept separate so the signing secret is never sent in
  a header.)
- Enforce customer ownership on **your** side (resolve the customer from
  your mobile JWT before calling us). NF authenticates only that the
  caller is your backend.

### 12b. Response

```jsonc
// 200 — a live fix
{
  "success": true,
  "tracking": {
    "order_number": "1234",
    "nf_order_number": "SH-1234",
    "rider":       { "lat": 33.58, "lng": 73.16 },
    "destination": { "lat": 33.55, "lng": 73.18 },
    "stops_away":  2,
    "eta":         "2026-06-15T16:32:00Z",
    "updated_at":  "2026-06-15T16:05:11Z"
  }
}

// 200 — no live fix yet (not out-for-delivery, no fresh GPS, or no
// customer pin). Your app falls back to the timeline.
{ "success": true, "tracking": null }

// 404 — unknown order.
{ "success": false, "tracking": null }
```

Field mapping to your `LiveDelivery` shape (section 5 of your doc) is
1:1 in snake_case — map to camelCase on your side:

| NF field | Your field |
|---|---|
| `rider {lat,lng}` | `rider {lat,lng}` |
| `destination {lat,lng}` | `destination {lat,lng}` |
| `stops_away` | `stopsAway` |
| `eta` | `eta` |
| `updated_at` | `updatedAt` |

Notes:
- `rider` is the **server-provided rider GPS** (heartbeat ~every 5
  minutes), not the user's device GPS.
- `destination` is the customer's verified pin (geocoded fallback).
- We return `tracking: null` (not an error) when there's no fresh rider
  fix, so your "Reconnecting…/unavailable" fallback behaves correctly.
- `route` (polyline) is not sent yet; your field is optional so the map
  just draws without it.
- Poll us at your existing adaptive cadence (~10-30s while
  out-for-delivery and foregrounded). That's well within our limits.

#### GPS freshness — `updated_at` (read this for the "live" indicator)

`updated_at` is **the exact timestamp NF captured that rider GPS fix** (ISO
8601, UTC). It is your freshness signal — derive age as `now − updated_at` and
drive your "live / X min ago / reconnecting" indicator from it.

- The rider app reports a GPS heartbeat **roughly every 5 minutes**, so on a
  healthy delivery `updated_at` advances every few minutes. Don't expect
  second-by-second movement — interpolate/smooth on your side if you want.
- **NF enforces a staleness cutoff before it ever sends you a fix.** Any fix
  older than `CUSTOMER_APP_TRACKING_STALENESS` minutes (**default 30**) is
  suppressed and you get `tracking: null` instead. So **every `rider` fix you
  receive is already guaranteed to be ≤ 30 min old**, and `updated_at` tells
  you precisely how old within that window.
- Practical UI rule of thumb: `updated_at` within ~6 min → "Live"; older but
  still returned → "Updated N min ago"; `tracking: null` → fall back to the
  timeline / "locating rider".
- `eta` and `updated_at` are independent: `eta` is the predicted arrival time;
  `updated_at` is when we last *saw* the rider. A fix can be fresh while the
  ETA is still minutes out.

---

## 13. Answers to your open questions (from your handoff doc)

1. **Live rider GPS for the map?** Yes — NF collects rider GPS
   heartbeats and the `…/tracking` endpoint (section 12) serves it. Your
   map can go live as soon as you wire that pull + token. Until then,
   keep the map hidden (as it already does).
2. **Where does the delivery window come from — accept-time slot or
   dispatch route plan?** Currently the **dispatch route plan** (our
   Google route ETA when the rider is sequenced). So `expectedDeliveryWindow`
   first appears around `out_for_delivery`, via the `eta_window` field /
   `order.eta_updated` refresh — not at accept-time. If you want an
   earlier coarse window at accept-time, tell me; it's a future add.
3. **Are `delivered`/terminal timestamps in the timeline?** Yes — every
   status event (including `delivered`, `cancelled`, `refunded`) carries
   `data.changed_at`, which is the real transition time. Use it as the
   timeline `at`.
4. **Pagination semantics / max limit?** That's on **your** orders list
   endpoint (NF doesn't serve your customer list); decide your own
   cursor/limit. NF only pushes per-order status events.
5. **Rate limits on `…/tracking`?** Your adaptive ~10-30s cadence is
   fine. If we ever need to throttle we'll return `429` with
   `Retry-After`; until told otherwise, assume no extra limit.

---

## 14. Coming next phase — invoice image (heads up, plan your UI)

Not built yet, but it's the next thing after tracking, so build your
order-detail screen with a slot for it.

**What it is:** in NF, each order gets a rendered **invoice image** (a
PNG). It already exists today for the WhatsApp "Send Invoice" flow — NF
generates/captures the invoice and stores a copy on its own storage,
then has it available as a URL. We'll expose that same image to the
customer app so the customer can view their invoice in-app.

**How we'll deliver it (planned):** a pull endpoint mirroring the
tracking one, secured with the same `CUSTOMER_APP_INBOUND_TOKEN`:

```
GET https://app.nizamifarms.com/api/customer-app/orders/{orderNumber}/invoice
Authorization: Bearer <CUSTOMER_APP_INBOUND_TOKEN>
```

Planned response:

```jsonc
// 200 — invoice image available
{
  "success": true,
  "invoice": {
    "order_number": "1234",
    "nf_order_number": "SH-1234",
    "image_url": "https://app.nizamifarms.com/storage/.../SH-1234.png",
    "generated_at": "2026-06-15T16:40:00Z"
  }
}

// 200 — not generated yet (NF hasn't produced the invoice image)
{ "success": true, "invoice": null }
```

**What to prepare on your side now:**
- A field like `invoiceImageUrl` on your order object and an "View
  Invoice" affordance on the order-detail screen, shown only when
  present.
- Treat the amounts on it as **estimated** (weight-variable items), same
  as your existing `estimated` tagging — the invoice image is the
  current snapshot, not a final reconciled total.

**Open question for you:** do you want NF to **push** an
`order.invoice_ready` webhook (so you can pre-store the URL / notify the
customer "your invoice is ready"), or is an on-demand **pull** when the
customer opens the order enough? Tell me and I'll build accordingly.

> Caveat: the invoice image is generated when the team
> sends/previews it (today that's the WhatsApp flow). So it may not
> exist for every order, or may appear only around delivery time. Your
> UI should treat it as optional.

---

## 15. Phase 3 — full order snapshot + order history (pull APIs, BUILT)

**Status: implemented on NF's side and live behind the
`CUSTOMER_APP_INBOUND_TOKEN` bearer (same token as live tracking).**
Adopt whenever your UI is ready — it doesn't affect Phase 1/2.

Goal: once NF accepts the Shopify order and creates `SH-####`, your app
stops showing the bare Shopify order and instead shows **NF's
authoritative order** — the `SH-` number, line items, totals, payment,
address, current status and ETA window. Plus the customer's recent
**order history** (last ~20).

### 15.1 Architecture: why APIs, not direct database access

These are served over the **same authenticated server-to-server pull
API** as live tracking — **not** by giving your app direct MySQL access
or DB views. This is deliberate, and specific to our setup:

- **Connection limits.** Our DB is on shared hosting with a low
  `max_connections`. Your Vercel functions scale horizontally with no
  connection pooling — direct DB access could exhaust connections and
  take down NF's own operational app. The API keeps DB load bounded
  inside NF.
- **Exposure.** Direct access means opening the DB to the internet for
  rotating serverless IPs, and a leaked credential would expose *every*
  customer's data. A bearer-token API scopes and revokes cleanly.
- **Schema decoupling.** The API is a stable contract; NF can refactor
  tables without breaking your app.
- **Correct data.** NF applies the `SH-` stripping, status mapping,
  payment normalization and ETA formatting in app code — the API returns
  already-correct, customer-shaped data so you don't re-implement (and
  drift from) our business rules.

Same auth as tracking: `Authorization: Bearer <CUSTOMER_APP_INBOUND_TOKEN>`,
server-to-server, and **you** enforce customer ownership (resolve the
customer from your mobile JWT before calling us).

> **You do not need to poll the snapshot endpoint.** Call it once when
> you receive the `accepted` webhook (to hydrate the full `SH-` order),
> and on pull-to-refresh. The status webhooks you already handle keep it
> live after that.

### 15.2 Current order snapshot

```
GET https://app.nizamifarms.com/api/customer-app/orders/{orderNumber}
Authorization: Bearer <CUSTOMER_APP_INBOUND_TOKEN>
```

`{orderNumber}` accepts either the bare Shopify number (`1234`) **or any
full NF order number** — `SH-1234`, `NF-2001`, `QUR26-045`. (History in
15.3 returns full NF numbers across all prefixes, so the detail endpoint
must accept them all.) For a bare number with no prefix, NF assumes the
`SH-` Shopify order.

```jsonc
// 200
{
  "success": true,
  "order": {
    "order_number": "1234",
    "nf_order_number": "SH-1234",
    "status": "out_for_delivery",       // same vocabulary as the webhook
    "eta_window": "Today, 4:10-4:40 PM", // may be null
    "placed_at": "2026-06-15T07:42:10Z",
    "currency": "PKR",
    "payment_method": "cash",            // normalized
    "totals": {
      "subtotal": 4200, "discount": 0, "shipping": 150,
      "tax": 0, "tip": 0, "total": 4350
    },
    "address": {
      "name": "…", "phone": "…", "line1": "…", "line2": "…",
      "city": "…", "province": "…", "postal_code": "…", "country": "Pakistan"
    },
    "items": [
      { "sku": "…", "name": "…", "quantity": 2,
        "unit_price": 2100, "line_total": 4200 }
    ]
  }
}

// 404 — unknown order
{ "success": false, "order": null }
```

> Amounts are NF's current snapshot. Weight-variable items can change at
> fulfilment — tag them `estimated` in your UI, same as elsewhere.

### 15.3 Order history — keyed on the customer's MOBILE NUMBER

History is correlated on the **customer's mobile number**, because that
is the identity NF tags every customer with. A single customer row in NF
collects **all** of that person's orders regardless of channel — Shopify-
converted (`SH-`), manual operations orders (`NF-`), and (optionally)
Qurbani (`QUR`) — as long as they share the same number. So one call by
phone returns the customer's whole NF order history.

```
GET https://app.nizamifarms.com/api/customer-app/customers/{mobile}/orders?limit=20
Authorization: Bearer <CUSTOMER_APP_INBOUND_TOKEN>
```

```jsonc
// 200
{
  "success": true,
  "matched_phone": "3001234567",     // the normalized key NF resolved
  "orders": [
    {
      "order_number": "1234", "nf_order_number": "SH-1234",
      "source": "shopify",            // shopify | manual | qurbani
      "status": "delivered", "placed_at": "2026-06-15T07:42:10Z",
      "total": 4350, "currency": "PKR", "item_count": 2
    },
    {
      "order_number": "NF-2001", "nf_order_number": "NF-2001",
      "source": "manual",
      "status": "out_for_delivery", "placed_at": "2026-06-10T09:00:00Z",
      "total": 1800, "currency": "PKR", "item_count": 1
    }
    // … up to `limit` (default 20, capped server-side), newest first
  ]
}
```

#### Phone normalization — send any format, NF canonicalizes it

You can pass the number in **any** format; NF normalizes it the exact
same way it tags customers, then matches:

1. Strip every non-digit (`+`, spaces, dashes, parentheses removed).
2. Take the **last 10 digits**.
3. (If fewer than 10, left-pad with `0` — only matters for malformed input.)

So all of these resolve to the same customer: `+92 300 1234567`,
`0300-1234567`, `923001234567`, `03001234567` → **`3001234567`**.

- URL-encode the value if you send it with a `+` (or just send digits).
- NF echoes the resolved key back as `matched_phone` so you can confirm.
- If no customer matches, NF returns `200` with `"orders": []` (not a 404).

#### Authorization — you MUST scope to the logged-in user

This endpoint returns **every order for whatever number you pass**. NF
only authenticates that the caller is your backend (bearer token); it
does **not** know which customer is logged in. So your backend must pass
**only the authenticated user's own verified mobile number**. Never let a
client choose an arbitrary number.

#### Scope notes

- Returns **all** production orders linked to that customer — `SH-`
  (Shopify), `NF-` (manual), and `QUR` (Qurbani). Each row carries a
  `source` field (`shopify` / `manual` / `qurbani` / `other`) so you can
  badge or filter them in the UI.
- Rows are **compact** (header + totals + status + `item_count`). Fetch
  full line items on tap via the snapshot endpoint (15.2).
- Excludes unconverted Shopify staging orders (those still live on your
  Shopify side pre-acceptance — by design).
- Orders saved without a phone (no `customer_id`) won't appear.
- NF follows merged-customer chains so a merged duplicate still resolves
  to the primary customer's full history.

### 15.4 The switchover — when your app stops showing the Shopify order

```
1. Customer places order on Shopify        -> your app shows the SHOPIFY order
                                               (driven by your own Shopify data)
2. NF operator accepts it -> creates SH-1234 -> NF webhook: status="accepted"
3. On "accepted", your app:                  -> hides the Shopify order and
                                               GET /orders/1234 once to show
                                               the full NF SH-1234 order
4. Subsequent status changes                 -> NF webhooks keep it live
   (incl. "cancelled" if cancelled in NF)
5. Customer opens "My Orders"                -> GET /customers/{mobile}/orders
                                               (all SH-/NF-/QUR for that number)
```

**What to render after the switch (read this — answers the SKU question):**
Once you've switched, **NF is the source of truth — render the snapshot
directly, do NOT reconcile against your Shopify line items by SKU.** An
operator may have edited quantities, added/removed items, or changed prices
during acceptance, so your Shopify copy can be stale. Specifically:

- Display **`nf_order_number`** (`SH-1234`) as the order number shown to the
  user — that's our operational number. (`order_number` is the bare `1234`,
  kept only so you can correlate back to the Shopify order you already have.)
- Take **quantity, name, unit_price, line_total, and `totals`** straight from
  the snapshot's `items[]` — these are authoritative.
- The `sku` on each item is provided **only** so you can *optionally* enrich
  the row from your own catalog (e.g. product image / description). Never let
  your catalog override NF's quantity/price/name.

That's also why qty + payment changes already "reflected" for you: your app
re-pulls this snapshot, which always returns NF's **current** full order — it
was never SKU-matching, it was pulling the whole authoritative order.

**Cancellation rule (important):**
- **Before** `accepted`: the order is still Shopify's — if it's cancelled
  pre-acceptance (or NF's operator declines it in the approval queue), NF
  sends **no** webhook. Your app should reflect cancellation from its
  existing **Shopify** sync during this window.
- **After** `accepted`: NF is the source of truth and will send
  `status="cancelled"` (and other statuses) for the `SH-` order.

So the trigger to switch *away* from the Shopify view is the `accepted`
event; the trigger to show *cancelled* depends on whether it happened
before (Shopify) or after (NF) acceptance.

---

## 16. Phase 4 — customer profile, verified pin, invoice image (BUILT)

Three new endpoints. **All use the SAME `CUSTOMER_APP_INBOUND_TOKEN` and the
same base URL** as the Phase 2/3 pull endpoints — no new credentials. As
before, NF authenticates *your backend*, not the end user; you MUST scope
every call to the **authenticated user's own verified mobile number**.

### 16.1 Customer existence + profile — `GET /customers/{mobile}`

Use this at **login/registration** to decide "does NF already know this
number?". Returns `exists:false` with `200` (not 404) for an unknown number,
so you can route a new user straight to registration; if `exists:true`, pull
their history with `GET /customers/{mobile}/orders` (section 15.3) as you
already do.

```
GET https://app.nizamifarms.com/api/customer-app/customers/{mobile}
Authorization: Bearer <CUSTOMER_APP_INBOUND_TOKEN>
```

```jsonc
// 200 — known customer
{
  "success": true,
  "matched_phone": "3001234567",
  "exists": true,
  "customer": {
    "name": "Ali Raza", "first_name": "Ali", "last_name": "Raza",
    "email": "…", "phone": "+92 300 1234567",
    "total_orders": 7, "total_spent": 28400,
    "is_active": true,
    "customer_since": "2025-11-02T10:14:00Z",
    "first_order_date": "2025-11-02T10:14:00Z",
    "last_order_date": "2026-06-15T07:42:10Z",
    "address": {
      "line1": "House 12, Street 4", "line2": "DHA Phase 5",
      "city": "Karachi", "province": "Sindh",
      "postal_code": "75500", "country": "Pakistan"
    },
    "verified_pin": {
      "lat": 24.8607, "lng": 67.0011,
      "is_verified": true,
      "google_maps_url": "https://www.google.com/maps?q=24.8607,67.0011",
      "saved_by": "Ali Raza staff name | \"Customer\"",
      "saved_by_customer": false,
      "saved_at": "2026-05-10T12:00:00Z"
    }
  }
}

// 200 — unknown number → send them to registration
{ "success": true, "matched_phone": "3001234567", "exists": false, "customer": null }
```

- `customer` carries everything you need to pre-fill the app on login:
  name, email, phone, `address` (postal), and `verified_pin` (map location).
  Any field may be `null` if NF doesn't have it.
- `verified_pin` is `null` if we have no pin for them yet.
- `saved_by_customer:true` (and `saved_by:"Customer"`) means the pin was set
  by the customer via your app; otherwise it's an NF staff name.
- Phone is normalized to the last 10 digits exactly like section 15.3.

### 16.2 Save / update the verified pin — `POST /customers/{mobile}/location`

The customer sets or moves their own pin (e.g. a map picker at registration
or in profile). If the number is **brand-new** (no NF customer yet), NF
**creates a minimal customer row** so the pin has a home — that's the
"new customer drops a pin during registration" case. The pin **overwrites**
any existing one.

```
POST https://app.nizamifarms.com/api/customer-app/customers/{mobile}/location
Authorization: Bearer <CUSTOMER_APP_INBOUND_TOKEN>
Content-Type: application/json

{
  "latitude": 24.8607,         // required
  "longitude": 67.0011,        // required
  "url": "https://maps.app.goo.gl/…",  // optional Google Maps URL
  "first_name": "Ali",         // optional — only used if we create a new row
  "last_name": "Raza"          // optional
}
```

```jsonc
// 200
{
  "success": true,
  "matched_phone": "3001234567",
  "created_customer": false,            // true if we created the row just now
  "verified_pin": { "lat": 24.8607, "lng": 67.0011, "is_verified": true,
    "saved_by": "Customer", "saved_by_customer": true, "saved_at": "…",
    "google_maps_url": "…" }
}

// 422 — bad/missing coordinates
{ "success": false, "message": "Validation failed.", "errors": { … } }
```

- Attribution: NF stamps the pin as **saved by the customer** (internally a
  reserved sentinel id), which is why it reads back as `"saved_by":"Customer"`
  / `"saved_by_customer":true`. Your ops team can see at a glance that the
  customer set it.
- Send `latitude`+`longitude` from your map picker. `url` is optional extra.

### 16.3 Invoice image — `GET /orders/{orderNumber}/invoice`

Returns a URL to the rendered invoice PNG (the exact image NF sends over
WhatsApp). You decide the optics — e.g. show an "Invoice ready" chip that
opens the image.

```
GET https://app.nizamifarms.com/api/customer-app/orders/{orderNumber}/invoice
Authorization: Bearer <CUSTOMER_APP_INBOUND_TOKEN>
```

`{orderNumber}` accepts a bare Shopify number (`1234` → `SH-1234`) or any full
NF number, same as the snapshot.

```jsonc
// 200 — image exists
{
  "success": true, "available": true,
  "order_number": "1234", "nf_order_number": "SH-1234",
  "image_url": "https://app.nizamifarms.com/public-storage/whatsapp-invoices/Invoice-SH-1234.png?v=1718972400"
}

// 200 — not generated yet
{ "success": true, "available": false,
  "order_number": "1234", "nf_order_number": "SH-1234", "image_url": null }
```

- **`available:false` until an NF operator has previewed/sent the invoice at
  least once** — that's the moment the PNG is captured. Poll again later, or
  re-check when the order reaches a later status.
- The `?v=…` suffix is a cache-buster keyed to the file's last-modified time:
  if the invoice is edited and re-rendered, the URL changes, so always use the
  latest `image_url` rather than caching the path.
- The image is served through NF (no CDN). Fine for normal app traffic; just
  don't hot-loop it.

### 16.4 Linking multiple numbers (one account → several numbers)

You want a user to log in with their own number and also see the orders of, say,
2 family members' numbers they've added. **The cleanest design is for the
customer app to own this relationship — NF needs no new endpoint or table.**
You already verify each added number over WhatsApp; NF cannot verify that
consent, so ownership/verification stays on your side.

**How to do it with the existing APIs:**

1. **Verify** each child number on your side (WhatsApp OTP) before linking it —
   only ever query numbers the logged-in user has proven they own.
2. **Existence/profile:** call `GET /customers/{mobile}` (16.1) per number.
3. **History:** call `GET /customers/{mobile}/orders` (15.3) once per number
   (primary + children), then **merge and sort by `placed_at` desc** in your
   backend. Each order row already carries `nf_order_number` + `source`, so you
   can also badge which number an order belongs to.
4. **Detail / tracking / invoice:** unchanged — `GET /orders/{n}` (15.2),
   `/orders/{n}/tracking` (12), `/orders/{n}/invoice` (16.3) work the same
   regardless of which linked number the order came from.

**Important — these are NOT merged in NF.** Each number is its own NF customer
with its own history; you're simply *grouping* them for one viewer. NF has a
separate "merge customers" concept that collapses duplicates — that's **not**
what this is, and you should not assume two linked numbers share a record.

**UI guidance (please build it this way):**

- **Mark one number as PRIMARY.** Let the user pick a primary/default number;
  use it as the default profile, the default verified pin, and the default
  "place order as" identity.
- **On a new order with multiple linked numbers, ASK which number it's for.**
  Prompt "Which number is this order for?" and create the Shopify order under
  that number. This matters because NF files each order in history **by the
  number on the order** — so attributing it correctly at creation time is what
  makes it show under the right person later.
- Optionally show a small chip on each history row indicating which linked
  number it belongs to, so a combined list stays readable.

**When to graduate to an NF-owned table (later, optional):** only if you want NF
to be the system of record for these links (e.g. server-side enforcement of a
max, shared across multiple client surfaces, or a single combined-history
call). Until then, Option above needs zero NF changes. Ask Shabib if you want
this.

---

## 17. Still out of scope (later phases)

- Customer-driven payment-method change (inbound write API).
- **Status webhooks** for `NF-`/`QUR` orders. NF only *pushes* live status
  events for `SH-` orders (Phase 1). `NF-` and `QUR` orders still appear in
  history (15.3) and are fetchable via the snapshot endpoint (15.2) — they
  just don't stream real-time status pushes.
- Route polyline on the tracking feed.

Tell me if any of these block your UX and I'll prioritise them.
