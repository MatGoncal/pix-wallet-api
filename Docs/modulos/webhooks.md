# webhooks

Idempotent provider webhooks.

- `POST /v1/webhooks/payment` with `X-AcmePay-Signature`
- Unique `(provider, event_id)` on `webhook_events`
- First delivery claims row and dispatches `ProcessPaymentWebhook`
- Replay → `200` + `duplicate: true` + error code `1042`

## Guards on the settlement path

- `data.amount` + `data.currency` are required for `payment.paid` (missing → `422`)
- `PaymentStatusEnum::canTransitionTo()` — only `PENDING` is open, so a late
  `payment.paid` never reopens an `EXPIRED`/`FAILED`/`CANCELLED` charge
- Payload amount/currency must match the stored charge; a divergence is logged
  as `1015`, is not credited, and leaves the payment `PENDING` for a corrected
  event

## Job resilience

`ProcessPaymentWebhook`: `$tries = 5`, exponential `backoff()` of `[5, 15, 45, 135]`
seconds, and `failed()` logging the exhausted event id. Retrying is safe — the
ledger's unique reference makes a replayed credit a no-op.

- Spec: `Docs/specs/fase-2-webhooks.md`
