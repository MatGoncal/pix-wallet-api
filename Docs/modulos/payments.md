# payments

PIX cash-in via `FakePixProvider` (HTTP client of `fake-pix-provider`).

- `POST /v1/payments` → Go `POST /v1/charges` → `PENDING` + QR + copia-e-cola
- `GET /v1/payments/{id}` → status (scoped to partner)
- Amounts: bigint minor units; currency `BRL` in v1
- Go down → HTTP 502 (no domain code, not `1015`)
- Charge id is not stored; `provider_tx_id` stays null until `payment.paid`
- Service: `PaymentService`; Enum: `PaymentStatusEnum`
- Spec: `Docs/specs/fase-1-payments-auth.md`, `Docs/specs/fase-9-fake-pix-http.md`
