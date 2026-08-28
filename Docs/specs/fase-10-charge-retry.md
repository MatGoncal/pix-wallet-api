# Fase 10 — charge id + create retry-safe

## Contexto / Objetivo

The partner contract **does not change**. `POST /v1/payments` still returns
`201 PENDING` + QR. `provider_tx_id` stays **null on create**; only webhook
`payment.paid` fills it. No outbox, no Docker of Go, no in-process EMV
fallback, no unified compose.

Today create mints a new UUID → POST Go → `save`. A timeout after Go 201 (or a
failed `save`) deletes the `idempotency_keys` row, so a retry mints **another**
UUID and a second charge. This phase keeps one local payment and one Go charge
on retry of the same `Idempotency-Key`.

`provider_tx_id` is the settlement id from the webhook (`pix_tx_*`). The Go
charge id is a different concept and is stored as `provider_charge_id`.

## Endpoints (se aplicável)

Partner contract **does not change**. OpenAPI / `CurrencyCode` / 201 body
unchanged. `transform` does **not** expose `provider_charge_id`.

| Método | Rota | Auth | Descrição |
|--------|------|------|-----------|
| POST | `/v1/payments` | API key + optional `Idempotency-Key` | Unchanged 201. Internally POSTs Go `/v1/charges`. |

Outbound (this API → Go):

| Método | Rota | Auth | Descrição |
|--------|------|------|-----------|
| POST | `{FAKE_PIX_BASE_URL}/v1/charges` | Bearer / `X-Api-Key` | Create or replay charge; QR comes from **201** (first) or **200** (replay) |

Demo lookup stays on Go (`GET /v1/charges/by-payment/{payment_id}`).

Without `Idempotency-Key`, create remains non-idempotent (UUID generated
inside `PaymentService::create`, as today). Documented; not in scope to fix.

## Request / Response

Outbound body (integer minor units) unchanged:

```json
{
  "amount": 1500,
  "currency": "BRL",
  "payment_id": "<stable wallet UUID>",
  "callback_url": "http://localhost/v1/webhooks/payment"
}
```

`FakePixProvider` returns `{ id, qr_code, copy_paste, provider: "fake_pix" }`
to `PaymentService`. Partner 201 shape is unchanged — no `provider_charge_id`.

Success if Go status is **200 or 201** with a non-empty `id` and QR. Go down /
timeout / neither 200 nor 201 → HTTP **502** (same envelope as fase 9; not
`1015`). Partner `currency` other than `BRL` is still HTTP **422** before any
Go call.

## Fluxo (passo a passo)

1. `StorePaymentRequest` rejects `currency ≠ BRL` with HTTP 422. No payments
   row; Go is not called.
2. With `Idempotency-Key`: INSERT the key **already carrying** `resource_id`
   UUID (nullable column, no FK). Flag **only** on payment create — payouts
   still delete the key on throw.
3. `execute(resourceId)` → `PaymentService::create(..., $resourceId)`.
4. `FakePixProvider` POSTs `/v1/charges` with that stable `payment_id`.
5. On 200/201, persist `PENDING` with QR from Go and `provider_charge_id` =
   Go JSON `id`. Do **not** copy Go `provider_tx_id` into `payments.provider_tx_id`.
6. Throw after Go create (timeout, `save` fail): **do not** delete the
   payment idempotency key. Unique hit, no snapshot, with `resource_id`:
   **resume** `execute` (do not wait 10s or return 1043). Two parallel POSTs
   with the same key both call create with the same UUID — Go CreateOrGet +
   insert payment handles PK duplicate (load existing row, same 201).
7. Without the header: generate UUID inside create, as today.
8. Partner receives 201. Demo: `GET` Go `by-payment` → `POST .../simulate`.
9. Go POSTs HMAC `t,v1` to `callback_url`. Existing job marks `PAID` and
   fills `provider_tx_id`.

## Códigos de erro

| Código | Situação |
|--------|----------|
| HTTP 422 (FormRequest) | `currency` is not `BRL` (rejected on the API; Go is not called; no 10xx) |
| HTTP 502 (`bad_gateway`) | Go unreachable, timeout, or neither 200 nor 201 (safety net includes Go 400; no new domain code; not `1015`) |

Idempotency `1043` / snapshot replay unchanged for **completed** keys and for
payouts. Payment create in-flight resume is new (no 1043 when `resource_id` is
set and snapshot is empty).

## Critérios de aceite

- [x] One local payment, one Go charge; `provider_charge_id` filled; `provider_tx_id` null until `paid`
- [x] Partner contract unchanged (`PENDING` + QR); `provider_charge_id` not in JSON
- [x] `FakePixProvider` treats HTTP 200 **and** 201 as success; reads Go `id`
- [x] `PaymentService::create` persists `provider_charge_id`
- [x] Without `Idempotency-Key`, create stays non-idempotent (new UUID)
- [x] Payment create: INSERT key with `resource_id` UUID; throw does **not** delete the key
- [x] Unique hit, no snapshot, with `resource_id`: resume `execute` with that UUID
- [x] Payouts unchanged (delete key on throw)
- [x] USD 422 unchanged (fase 9); CI does not start Go (`Http::fake()`)
- [x] Demo README still uses `GET` Go `by-payment`

## Testes obrigatórios

- [x] Pest — create stores `provider_charge_id`; `provider_tx_id` null
- [x] Pest — `createCharge` accepts 200 (replay) as well as 201
- [x] Pest — same `Idempotency-Key` after throw post-`createCharge` → one payment; second POST to Go uses the **same** `payment_id` (Http fake 201 then 200)
- [x] Pest — payouts still delete the key on throw
- [x] Existing fase 6 payment-create concurrency stays green
- [x] `Http::fake()` in `TestCase` so create + idempotency stay green without a live Go process

## Migrations

1. `payments.provider_charge_id` string nullable (do **not** reuse `provider_tx_id`)
2. `idempotency_keys.resource_id` uuid nullable, **no FK**

## Variáveis de ambiente novas

None. Reuse fase 9 `FAKE_PIX_*`.

## Dependências / Rollback

- Dependências: `fake-pix-provider` fase 2 (`CreateOrGet` by `payment_id`; 200 replay / 201 create). Demo/Docker: `fake-pix-provider` fase 4 (Postgres + outbox; restart does not drop charges). Pest uses `Http::fake()`.
- Rollback: drop `provider_charge_id` / `resource_id`; restore FakePix 201-only and key-delete-on-throw.
- Out of scope: outbox, Docker of Go, unified compose, EMV fallback, exposing charge id on the partner JSON, making create without header idempotent.
