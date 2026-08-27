# Fase 1 — API key auth + payments + FakePixProvider

## Contexto / Objetivo

Partner authenticates with an API key and creates a PIX cash-in that returns
QR + copia-e-cola in `PENDING` status.

> Fase 9: QR is fetched from `fake-pix-provider` over HTTP. See
> `Docs/specs/fase-9-fake-pix-http.md`.

## Endpoints

| Método | Rota | Auth | Descrição |
|--------|------|------|-----------|
| POST | `/v1/payments` | API key | Create payment |
| GET | `/v1/payments/{id}` | API key | Get payment |

## Fluxo

1. Middleware resolves partner from API key hash.
2. `StorePaymentRequest` validates amount (int > 0), currency, optional fields.
3. `PaymentService` generates the payment UUID, then `FakePixProvider` POSTs
   Go `/v1/charges` (Fase 9). The UUID is assigned on the model (not mass-assigned)
   so it matches the webhook `payment_id`.
4. Persist `payments` row as `PENDING`.
5. Return `201` per `API_CONTRACT.md`.

## Critérios de aceite

- [x] Invalid/missing API key → 401
- [x] `POST /v1/payments` creates PENDING with qr_code and copy_paste
- [x] Amount stored/returned as integer minor units (no float)
- [x] `GET /v1/payments/{id}` returns own payment; foreign id → 404
- [x] Pest feature tests cover create + auth failure
- [x] Demo partner seeded with documented API key

## Testes obrigatórios

- [x] Create payment happy path
- [x] Unauthorized without key
- [x] Show payment scoped to partner

## Env

| Var | Default | Descrição |
|-----|---------|-----------|
| `DEMO_PARTNER_API_KEY` | `acmepay_demo_key_change_me` | Seeded partner raw key |
