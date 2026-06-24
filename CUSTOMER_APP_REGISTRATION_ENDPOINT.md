# NF → Customer App: Registration / Profile Lookup Endpoint

**Audience:** the customer-app (Vercel) backend developer.
**Status:** BUILT. Part of the same integration as `CUSTOMER_APP_INTEGRATION.md`
(this file zooms in on just the registration/profile lookup).
**Last updated:** June 23, 2026

---

## 1. What this endpoint is for

When a user logs in or registers in the customer app **by mobile number**, call
this once to ask NF: *"do we already know this number, and if so, who are they?"*

- If NF **knows** the number → you get back the customer's full profile (name,
  email, phone, postal address, and their verified map pin) so you can
  **pre-fill the app** and skip re-asking for details.
- If NF **doesn't** know it → you get `exists: false` and route the user into
  registration.

Order **history** is a separate endpoint (see §7) — this one is the identity +
details load only.

---

## 2. Endpoint & auth

```
GET https://app.nizamifarms.com/api/customer-app/customers/{mobile}
Authorization: Bearer <CUSTOMER_APP_INBOUND_TOKEN>
```

- Same `CUSTOMER_APP_INBOUND_TOKEN` and base URL as every other pull endpoint
  (tracking, snapshot, history, invoice). **No new credentials.**
- NF authenticates **your backend**, not the end user. You MUST only ever call
  this with the **authenticated user's own verified mobile number**.

### `{mobile}` — send any format
NF normalizes it the same way it tags every customer:
1. strip all non-digits, 2. take the **last 10 digits**.
So `+92 300 1234567`, `0300-1234567`, `923001234567` all resolve to
`3001234567`. URL-encode the value if it contains a `+`.

---

## 3. Where the data comes from

All fields are read **live from NF's customer table (`t_crm_prod_customer`)** —
the same record your operations team sees and edits in the web app. So whatever
ops has on file (name, address, pin) is exactly what this endpoint returns at
call time. Nothing is cached.

---

## 4. Response — unknown number

`200 OK` (not 404), so you can branch straight to registration:

```jsonc
{
  "success": true,
  "matched_phone": "3001234567",   // the normalized key NF tried
  "exists": false,
  "customer": null
}
```

---

## 5. Response — known customer

```jsonc
{
  "success": true,
  "matched_phone": "3001234567",
  "exists": true,
  "customer": {
    "name": "Ali Raza",
    "first_name": "Ali",
    "last_name": "Raza",
    "email": "ali@example.com",
    "phone": "+92 300 1234567",          // original formatting NF has on file
    "total_orders": 7,
    "total_spent": 28400,
    "is_active": true,
    "customer_since": "2025-11-02T10:14:00Z",
    "first_order_date": "2025-11-02T10:14:00Z",
    "last_order_date": "2026-06-15T07:42:10Z",

    "address": {
      "line1": "House 12, Street 4",
      "line2": "DHA Phase 5",
      "city": "Karachi",
      "province": "Sindh",
      "postal_code": "75500",
      "country": "Pakistan"
    },

    "verified_pin": {
      "lat": 24.8607,
      "lng": 67.0011,
      "is_verified": true,
      "google_maps_url": "https://www.google.com/maps?q=24.8607,67.0011",
      "saved_by": "Customer",            // staff name, or "Customer" (see §6)
      "saved_by_customer": true,
      "saved_at": "2026-05-10T12:00:00Z"
    }
  }
}
```

### Field reference

| Field | Meaning |
|---|---|
| `name` / `first_name` / `last_name` | Customer name on file |
| `email` | Email on file (may be null) |
| `phone` | Phone in NF's original formatting |
| `total_orders` / `total_spent` | Lifetime stats |
| `is_active` | Account active flag |
| `customer_since` | When the NF customer record was created |
| `first_order_date` / `last_order_date` | Order date bookends |
| `address.*` | **Postal address** (line1, line2, city, province, postal_code, country) |
| `verified_pin` | **Map location** — see §6. `null` if no pin on file |

**Any field can be `null`** if NF doesn't have it. Treat `address` and
`verified_pin` defensively (a brand-new or partial record may have nulls).

NF follows its **merged-customer** chain, so if a number's record was merged
into another, you still get the surviving customer's details and history.

---

## 6. The verified pin (map location)

**Yes — if a verified location pin exists for the customer, it's returned in
this same response** as the `verified_pin` object. You do **not** need a
separate call to read it.

- `lat` / `lng` — the pin coordinates (preferred = explicit verified pin;
  falls back to a geocoded estimate if that's all NF has).
- `is_verified` — `true` when it's an actual verified pin / map URL (not just a
  geocoded guess).
- `google_maps_url` — convenience link.
- `saved_by` / `saved_by_customer` — **who set the pin.** A staff member's name,
  or the literal `"Customer"` (`saved_by_customer: true`) when the **customer
  themselves** set it from your app.
- `saved_at` — when it was last set.
- The whole object is `null` if NF has no pin for them yet.

### The customer can change the pin
The customer can set or move their own pin from your app via the companion
write endpoint:

```
POST https://app.nizamifarms.com/api/customer-app/customers/{mobile}/location
Authorization: Bearer <CUSTOMER_APP_INBOUND_TOKEN>
Content-Type: application/json

{ "latitude": 24.8607, "longitude": 67.0011, "url": "https://maps.app.goo.gl/…" }
```

- `latitude` + `longitude` are required (`url` optional).
- If the number is **brand-new**, NF creates a minimal customer row so the pin
  has a home (the "new customer drops a pin at registration" case). You may
  also pass optional `first_name` / `last_name` for that case.
- A customer-set pin is stamped as **saved by the customer**, which is why it
  reads back here as `"saved_by": "Customer"` / `"saved_by_customer": true`.
  Your ops team can see at a glance that the customer set it.
- The pin **overwrites** any existing one.

So the round-trip is: read the pin via this profile endpoint → let the customer
adjust it on a map → write it back via `POST …/location` → next profile read
reflects the new pin.

---

## 7. Related endpoints (for context)

| Endpoint | Purpose |
|---|---|
| `GET /customers/{mobile}/orders` | Order **history** for the number (separate call) |
| `GET /orders/{orderNumber}` | Full snapshot of one order |
| `POST /customers/{mobile}/location` | Write/update the verified pin (§6) |

Full contracts for these live in `CUSTOMER_APP_INTEGRATION.md`.

---

## 8. Errors & what gets logged where

| Situation | HTTP | Body | Logged on NF side? |
|---|---|---|---|
| Known customer | 200 | `exists:true` + profile | no |
| Unknown number | 200 | `exists:false`, `customer:null` | no |
| Server/DB exception | 500 | `success:false` | **yes** — `laravel.log`, tagged `CustomerAppController::customerProfile failed` |

**Important:** an unknown/empty result is a **normal 200**, not an error — so it
is **not** logged. If a profile or history comes back empty when you expected
data, it's almost always a **matching** issue, not a crash:

- the number on the order in NF doesn't normalize to the same last-10 digits, or
- the order is linked to a different customer record, or
- (history only) the order was saved without a phone, so it has no customer link.

Use the `matched_phone` echo in the response to confirm NF normalized the number
to what you expected. If `matched_phone` is right but you still get nothing,
it's an NF-side data link — send Shabib the mobile number (or an example order
number) and he can trace it.
