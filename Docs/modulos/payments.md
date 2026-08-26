# payments

PIX cash-in via `FakePixProvider`.

- `POST /v1/payments` → `PENDING` + QR + copia-e-cola
- `GET /v1/payments/{id}` → status (scoped to partner)
- Amounts: bigint minor units; currency `BRL` in v1
- Service: `PaymentService`; Enum: `PaymentStatusEnum`
- Spec: `Docs/specs/fase-1-payments-auth.md`
