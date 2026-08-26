# Fase 2 — Idempotent payment webhook + job

## Contexto / Objetivo

Provider notifies payment status changes. Events are idempotent on
`(provider, event_id)`. Side effects run via `ProcessPaymentWebhook` job.

## Endpoints

| Método | Rota | Auth | Descrição |
|--------|------|------|-----------|
| POST | `/v1/webhooks/payment` | HMAC signature | Provider event |

## Fluxo

1. Verify `X-AcmePay-Signature` = `sha256=` + HMAC-SHA256(raw body, `WEBHOOK_SECRET`).
2. Insert `webhook_events` with unique `(provider, event_id)`.
3. On unique violation → `200` `{ accepted: true, duplicate: true, error: { code: 1042, ... } }`.
4. On first insert → dispatch `ProcessPaymentWebhook`.
5. Job transitions payment status (`payment.paid` → `PAID`, etc.) once.

## Critérios de aceite

- [x] Valid signed `payment.paid` moves payment to `PAID` and sets `paid_at`
- [x] Replay of same `event_id` does not double-apply; returns duplicate + 1042
- [x] Invalid signature → 401
- [x] Unique DB constraint on `(provider, event_id)`
- [x] Pest covers paid + duplicate delivery

## Códigos de erro

| Código | Situação |
|--------|----------|
| 1042 | duplicate_event |

## Env

| Var | Default | Descrição |
|-----|---------|-----------|
| `WEBHOOK_SECRET` | `dev-webhook-secret` | HMAC secret |
| `QUEUE_CONNECTION` | `redis` | Job backend |
