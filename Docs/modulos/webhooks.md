# webhooks

Idempotent provider webhooks.

- `POST /v1/webhooks/payment` with `X-AcmePay-Signature`
- Unique `(provider, event_id)` on `webhook_events`
- First delivery claims row and dispatches `ProcessPaymentWebhook`
- Replay → `200` + `duplicate: true` + error code `1042`
- Spec: `Docs/specs/fase-2-webhooks.md`
