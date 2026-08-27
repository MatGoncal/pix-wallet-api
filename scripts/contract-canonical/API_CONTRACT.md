# API Contract — AcmePay v1

Canonical HTTP contract shared by:

| Repo | Role |
|------|------|
| `pix-wallet-api` | Laravel 12 implementation |
| `payment-api-nest` | NestJS 11 implementation |
| `partner-dashboard-vue` | Vue 3 consumer → Laravel |
| `checkout-portal-next` | Next 15 consumer → Nest |

Machine-readable twin: [`openapi.yaml`](./openapi.yaml) (OpenAPI **3.1**).

Domain glossary: personal skill `payments-domain` (`~/.cursor/skills/payments-domain/SKILL.md`).

Error codes: [`error-codes.md`](./error-codes.md).

---

## Base URL & versioning

- Prefix: `/v1`
- JSON only (`Content-Type: application/json`)
- Amounts: **integer minor units** (centavos/cents) + ISO 4217 `currency`
- IDs: UUID v4 strings unless noted

## Authentication

| Audience | Mechanism | Header |
|----------|-----------|--------|
| Partner APIs | API key | `Authorization: Bearer <api_key>` **or** `X-Api-Key: <api_key>` |
| Provider webhook | Shared secret HMAC | `X-AcmePay-Signature: t=<unix>,v1=<hex>` |

Webhook signature (Stripe-style): HMAC-SHA256 of `"${t}.${raw_body}"` with
`WEBHOOK_SECRET`, hex digest. Header format `t=<unix>,v1=<hex>`. Timestamps
outside `WEBHOOK_TOLERANCE_SECONDS` (default **300**) → **401** + `1044`
`webhook_timestamp_expired`. Missing or invalid signature → **401**. Replay of
the same `event_id` remains `1042`.

Missing/invalid partner auth → **401**. Invalid webhook signature → **401**.

## Idempotency-Key

Optional header on `POST /v1/payments` and `POST /v1/payouts`.

| Header | Required | Notes |
|--------|----------|-------|
| `Idempotency-Key` | no | Scoped to the authenticated partner; max 255 chars |

- Absent: current behaviour (`external_id` unique per partner when set).
- Present: unique `(partner_id, key)`. The SHA-256 of the **raw body** is stored
  with a snapshot of the JSON response (`expires_at` = now + 24h).
- Same key + same body → replay the original **201** (payments) or **202**
  (payouts), including the same resource `id`.
- Same key + different body → **409** + `1043` `idempotency_conflict`.
- Concurrent requests with the same key: the first INSERT wins; the loser hits
  the unique constraint, `SELECT FOR UPDATE`, and waits for the snapshot.

---

## Endpoints

### `POST /v1/payments`

Cash-in PIX. Creates payment `PENDING` and returns QR + copia-e-cola.

**Auth:** partner API key.

**Request**

```json
{
  "amount": 1500,
  "currency": "BRL",
  "external_id": "order-123",
  "description": "Checkout order 123",
  "expires_in_seconds": 1800
}
```

| Field | Required | Notes |
|-------|----------|-------|
| `amount` | yes | Positive integer, minor units |
| `currency` | yes | `BRL` for PIX cash-in in v1 |
| `external_id` | no | Partner reference; unique per partner when set |
| `description` | no | Max 140 chars |
| `expires_in_seconds` | no | Default 1800 |

**Response `201`**

```json
{
  "id": "550e8400-e29b-41d4-a716-446655440000",
  "status": "PENDING",
  "amount": 1500,
  "currency": "BRL",
  "external_id": "order-123",
  "qr_code": "00020126...synthetic...",
  "copy_paste": "00020126...synthetic...",
  "expires_at": "2026-08-26T15:30:00Z",
  "created_at": "2026-08-26T15:00:00Z"
}
```

---

### `GET /v1/payments`

List payments for the authenticated partner with pagination and optional filters.

**Auth:** partner API key.

**Query**

| Param | Required | Notes |
|-------|----------|-------|
| `status` | no | Filter by status (`PENDING`, `PAID`, …) |
| `external_id` | no | Substring match on partner reference |
| `page` | no | Default `1` |
| `per_page` | no | Default `10`, max `50` |

**Response `200`**

```json
{
  "data": [
    {
      "id": "550e8400-e29b-41d4-a716-446655440000",
      "status": "PENDING",
      "amount": 1500,
      "currency": "BRL",
      "external_id": "order-123",
      "qr_code": "00020126...synthetic...",
      "copy_paste": "00020126...synthetic...",
      "expires_at": "2026-08-26T15:30:00Z",
      "created_at": "2026-08-26T15:00:00Z"
    }
  ],
  "meta": {
    "page": 1,
    "per_page": 10,
    "total": 1,
    "total_pages": 1
  }
}
```

Results are ordered by `created_at` descending. Only payments owned by the
authenticated partner are returned.

---

### `GET /v1/payments/{id}`

**Auth:** partner API key. Partner may only read own payments.

**Response `200`**

```json
{
  "id": "550e8400-e29b-41d4-a716-446655440000",
  "status": "PAID",
  "amount": 1500,
  "currency": "BRL",
  "external_id": "order-123",
  "qr_code": "00020126...synthetic...",
  "copy_paste": "00020126...synthetic...",
  "expires_at": "2026-08-26T15:30:00Z",
  "paid_at": "2026-08-26T15:05:00Z",
  "created_at": "2026-08-26T15:00:00Z"
}
```

Statuses: `PENDING` | `PAID` | `EXPIRED` | `FAILED` | `CANCELLED`.

`404` if unknown or not owned by the authenticated partner.

---

### `POST /v1/webhooks/payment`

Provider → AcmePay. **Idempotent** on `(provider, event_id)`.

**Auth:** webhook signature (not partner API key).

**Request**

```json
{
  "event_id": "evt_abc123",
  "provider": "fake_pix",
  "type": "payment.paid",
  "payment_id": "550e8400-e29b-41d4-a716-446655440000",
  "occurred_at": "2026-08-26T15:05:00Z",
  "data": {
    "provider_tx_id": "pix_tx_999",
    "amount": 1500,
    "currency": "BRL"
  }
}
```

| `type` | Effect |
|--------|--------|
| `payment.paid` | Transition to `PAID` (settlement path) |
| `payment.expired` | Transition to `EXPIRED` |
| `payment.failed` | Transition to `FAILED` |

`data.amount` and `data.currency` are **required** for `payment.paid` (missing →
**422**, the provider should retry with a complete payload). Other event types
may send an empty `data` object.

**Status transitions.** `PENDING` is the only open state; `PAID`, `EXPIRED`,
`FAILED` and `CANCELLED` are terminal. An event that asks for a transition out
of a terminal state is accepted (**200**, the provider must stop retrying) and
ignored — a late `payment.paid` never reopens an expired or failed charge.

**Amount check.** A `payment.paid` whose `data.amount`/`data.currency` differ
from the stored charge is accepted, logged as `1015`, and **not** credited. The
payment stays `PENDING` so a corrected event can still settle it.

**Response `200` (first delivery)**

```json
{
  "accepted": true,
  "duplicate": false
}
```

**Response `200` (replay)**

```json
{
  "accepted": true,
  "duplicate": true,
  "error": {
    "code": 1042,
    "name": "duplicate_event",
    "message": "Event already processed.",
    "details": { "event_id": "evt_abc123" }
  }
}
```

Side effects must not run twice. Prefer async job after claiming the event row.

---

### `POST /v1/fx/quotes`

FX quote with **rate lock** (default **5 minutes**).

**Auth:** partner API key.

**Request**

```json
{
  "source_currency": "BRL",
  "target_currency": "USD",
  "amount": 10000
}
```

`amount` is in **source** currency minor units.

**Response `201`**

```json
{
  "quote_id": "660e8400-e29b-41d4-a716-446655440000",
  "source_currency": "BRL",
  "target_currency": "USD",
  "source_amount": 10000,
  "target_amount": 1850,
  "rate": "0.18500000",
  "expires_at": "2026-08-26T15:05:00Z",
  "created_at": "2026-08-26T15:00:00Z"
}
```

`rate` is a decimal **string**. Consuming after `expires_at` → error **`1031`**.

A rate lock is **single use**: consuming a quote stamps `consumed_at`, and any
later attempt to consume it → error **`1032`**.

---

### `GET /v1/balances`

**Auth:** partner API key.

**Response `200`**

```json
{
  "balances": [
    { "currency": "BRL", "available": 50000, "pending": 1500 },
    { "currency": "USD", "available": 2000, "pending": 0 }
  ]
}
```

Amounts in minor units. `pending` is a real payout hold (reserved on `POST /v1/payouts`,
cleared on confirm or release). PIX settlement credits `available` only.

---

### `POST /v1/payouts`

Async payout. **Reserve on create**, ledger debit on confirm.

`POST` inserts `QUEUED` and moves `available → pending` in the same transaction.
Insufficient `available` → **422** + `1027` (no payout row). The job then
consumes `pending` and writes the ledger debit (`COMPLETED`), or returns the
hold to `available` on domain failure (`FAILED`). Replay of the job does not
move `pending` twice (ledger unique on payout/debit).

**Auth:** partner API key.

**Request**

```json
{
  "amount": 2500,
  "currency": "BRL",
  "destination": {
    "type": "pix_key",
    "value": "synthetic@acme.test"
  },
  "external_id": "payout-77"
}
```

**Response `202`**

```json
{
  "id": "770e8400-e29b-41d4-a716-446655440000",
  "status": "QUEUED",
  "amount": 2500,
  "currency": "BRL",
  "external_id": "payout-77",
  "created_at": "2026-08-26T15:00:00Z"
}
```

Payout statuses: `QUEUED` | `PROCESSING` | `COMPLETED` | `FAILED`. Insufficient funds at **create** → **`1027`**. The same code is exceptional on the confirm job if `pending` cannot cover the amount.

---

### `POST /v1/payments/{id}/splits`

Define split lines applied on settlement (or validate against already-settled payment per phase spec).

**Auth:** partner API key.

**Request**

```json
{
  "splits": [
    { "party": "platform", "amount": 150 },
    { "party": "seller", "amount": 1200 },
    { "party": "affiliate", "amount": 150 }
  ]
}
```

Sum of `amount` must equal payment `amount` (fees rules documented in phase specs). Settlement failure → **`1015`**.

Splits are the allocation rule applied at settlement, so they may only be
defined while the payment is still `PENDING`. A payment in any terminal status
(`PAID`, `EXPIRED`, `FAILED`, `CANCELLED`) → **`1015`** with `details.status`,
and the stored lines are left untouched.

**Response `201`**

```json
{
  "payment_id": "550e8400-e29b-41d4-a716-446655440000",
  "splits": [
    { "party": "platform", "amount": 150 },
    { "party": "seller", "amount": 1200 },
    { "party": "affiliate", "amount": 150 }
  ]
}
```

---

## Consistency rules

1. Both APIs expose the same paths, status enums, and error codes.
2. Frontends must not assume stack-specific fields.
3. Never use floating-point for money in request/response examples or implementations.
4. Spec change → update this file, `openapi.yaml`, `error-codes.md`, Postman collection, and both API repos.
