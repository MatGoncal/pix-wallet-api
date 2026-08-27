# Fase 6 — HMAC com timestamp + Idempotency-Key

## Contexto / Objetivo

Tighten the provider webhook signature (Stripe-style timestamped HMAC) and add
an optional `Idempotency-Key` header on partner POSTs so Vue/Next keep working
without the header while retried creates replay the original response.

## Endpoints (se aplicável)

| Método | Rota | Auth | Descrição |
|--------|------|------|-----------|
| POST | `/v1/webhooks/payment` | HMAC `t=,v1=` | Provider event (unchanged path) |
| POST | `/v1/payments` | API key + optional `Idempotency-Key` | Cash-in create |
| POST | `/v1/payouts` | API key + optional `Idempotency-Key` | Async payout create |

## Request / Response

Webhook header:

```
X-AcmePay-Signature: t=<unix>,v1=<hex>
```

`v1` is `HMAC-SHA256("${t}.${raw_body}", WEBHOOK_SECRET)` (hex). Window:
`WEBHOOK_TOLERANCE_SECONDS` (default 300). Outside the window → **401** +
`1044` `webhook_timestamp_expired`. Missing or invalid signature → **401**.
Replay of the same `event_id` remains **200** + `1042`.

`Idempotency-Key` is optional. Without it, behaviour is unchanged
(`external_id` unique per partner). With it: unique `(partner_id, key)`,
SHA-256 of the **raw body**, snapshot of the JSON response.

Same body → replay original `201` / `202`. Different body → **409** + `1043`
`idempotency_conflict`. Race: INSERT first; the loser hits the unique
constraint, `SELECT FOR UPDATE`, and waits for the snapshot.

## Fluxo (passo a passo)

1. Verify webhook `t`/`v1` and the timestamp window before parsing JSON.
2. On `POST /v1/payments` or `POST /v1/payouts`, if `Idempotency-Key` is absent, run the existing create path.
3. If present, INSERT `idempotency_keys` then execute create; persist `response_code` + `response_body`.
4. On unique `(partner_id, key)`, lock the row and either replay or return `1043`.

## Códigos de erro

| Código | Situação |
|--------|----------|
| 1043 | `idempotency_conflict` — same key, different raw body |
| 1044 | `webhook_timestamp_expired` — `t` outside the tolerance window |
| 1042 | `duplicate_event` — unchanged webhook `event_id` replay |
| 401 | Missing/invalid webhook signature (no new domain code) |

## Critérios de aceite

- [x] Header format `t=<unix>,v1=<hex>`; HMAC over `"${t}.${raw_body}"`
- [x] Timestamp older than the window → 401 / `1044`
- [x] Timestamp in the future beyond the window → 401
- [x] Valid `t` + wrong `v1` → 401
- [x] Happy path with frozen clock (`Carbon::setTestNow` / injected now)
- [x] No `Idempotency-Key`: creating a payment still works (regression)
- [x] Same key + same body, serial: one row; second POST returns the same `id` and HTTP status
- [x] Same key + different body → `1043`
- [x] Two parallel POSTs with the same key → a single payment (or payout)
- [x] Header remains optional so existing Vue/Next clients keep working
- [x] Next simulator signer emits `t,v1` (`checkout-portal-next`)

## Testes obrigatórios

- [x] Unitário — parser + janela HMAC (Laravel helper / Nest `webhook-signature.ts`)
- [x] Integração — webhook HTTP 401/1044 + happy path
- [x] Pest feature + Nest integration — Idempotency-Key serial, conflito, regressão sem header
- [x] Concorrência — duas POSTs paralelas com a mesma key (`tests/Concurrency/` / `test/integration/`)

## Migrations

1. Create `idempotency_keys`
2. Unique `(partner_id, key)`
3. Columns: `method`, `path`, `request_hash`, `response_code`, `response_body` jsonb, `expires_at` (now+24h)

## Variáveis de ambiente novas

| Var | Default | Descrição |
|-----|---------|-----------|
| `WEBHOOK_TOLERANCE_SECONDS` | `300` | Max `|now - t|` for webhook signatures |

## Dependências / Rollback

- Dependências: shared contract (`1043`, `1044`, HMAC format, `Idempotency-Key` parameter)
- Rollback: drop `idempotency_keys`; revert signature header to the previous `sha256=` format (consumers must match)
