# Fase 3 — Multi-currency balances + FX rate lock

## Contexto / Objetivo

Partner wallets hold balances per currency with an immutable ledger.
FX quotes lock a synthetic rate for 5 minutes (`quote_id` + `expires_at`).

## Endpoints

| Método | Rota | Auth | Descrição |
|--------|------|------|-----------|
| GET | `/v1/balances` | API key | Available + pending by currency |
| POST | `/v1/fx/quotes` | API key | Create quote with rate lock |

## Fluxo — balances

1. On `payment.paid` settlement, credit `partner_balances.available` by payment amount.
2. Write a `balance_ledger` row (`credit`, reference payment).
3. `GET /v1/balances` returns all currency rows for the authenticated partner.

## Fluxo — FX

1. Validate source/target currencies and positive `amount` (source minor units).
2. Resolve rate from `FakeFxProvider` (fixed synthetic table).
3. Compute `target_amount` with half-up rounding via `bcmath` (no float).
4. Persist `fx_quotes` with `expires_at = now + 5 min`.
5. Consuming after expiry → **1031** (used by later payout/FX consume; quote create itself always fresh).

## Critérios de aceite

- [x] Paid webhook credits partner balance + ledger once
- [x] `GET /v1/balances` returns integer minor units
- [x] `POST /v1/fx/quotes` returns `quote_id`, string `rate`, `expires_at`
- [x] Rate lock default is 300 seconds
- [x] No float used for money math
- [x] Pest covers credit-on-paid, balances list, FX quote happy path

## Códigos de erro

| Código | Situação |
|--------|----------|
| 1031 | quote_expired (consumption) |

## Migrations

- `partner_balances` unique `(partner_id, currency)`
- `balance_ledger` append-only movements
- `fx_quotes`

## Env

| Var | Default | Descrição |
|-----|---------|-----------|
| `FX_RATE_LOCK_SECONDS` | `300` | Rate lock window |
