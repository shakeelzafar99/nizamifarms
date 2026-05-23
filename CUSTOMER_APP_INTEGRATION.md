# NF -> Customer App Integration

**Phase 1 — Order Status Webhooks (outbound only)**
**Last updated:** May 20, 2026

This is the only document the customer-app (Vercel) developer needs.
NF will push order status changes to a single endpoint on your side.
There is no inbound API yet — that arrives in a later phase.

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

### 4.1 The `status` field

The first event NF ever sends for a given order will have
`status: "accepted"` and `previous_status: null`. That's the
"customer's order has been accepted by NF" moment.

After that, `status` is whatever NF's internal `order_status` is at
that moment. You will see values like:

- `processing`
- `packed`
- `ready_for_delivery`
- `out_for_delivery`
- `delivered`
- `completed`
- `cancelled`
- `refunded`

**Your UI mapping is up to you.** You can map these to any user-facing
labels. NF will not change these values for an existing order without
notifying you.

If we ever introduce a new internal status, you'll receive it as a new
string — design your code to handle unknown statuses gracefully (e.g.
log + display the raw string + treat as a generic in-progress state)
rather than throwing.

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

## 9. Out of scope for Phase 1

These are coming in later phases — please do not build for them yet,
their contracts may change:

- Customer-driven payment-method change (inbound API).
- ETA pushes while `out_for_delivery`.
- Manual NF-prefix orders.
- Order detail / line-item sync.
- Refunds / partial refunds beyond the status flag.

If you have UX needs that depend on any of these, tell me now so I can
prioritise the next phase.
