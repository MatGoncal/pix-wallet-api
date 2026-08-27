# Product overview — AcmePay PIX Wallet API

AcmePay is a **fictional** portfolio payments platform. This repository
(`pix-wallet-api`) is the Laravel implementation of the shared AcmePay v1
contract consumed by `partner-dashboard-vue`.

## Goals

- Demonstrate production-shaped payment engineering: idempotent webhooks,
  integer money, partner API keys, async jobs.
- Stay demoable locally with Sail (Postgres + Redis) and `FakePixProvider`
  (HTTP client of `fake-pix-provider`).
- Keep specs in git (`Docs/`) so the method is visible in interviews.

## Non-goals

- Real PIX / PSP connectivity
- Production KYC, ledger reconciliation at bank grade
- Sharing any StarsPay proprietary code

## Personas

| Persona | Need |
|---------|------|
| Partner developer | Create PIX charges, poll status, read balances |
| Dashboard operator | See transactions and QR (via Vue app) |
| Interviewer | Read specs + run `sail up` + hit Postman |

## Success for Fase 1–2

Partner can authenticate with an API key, create a PENDING payment with QR /
copia-e-cola, and a signed webhook can move it to PAID exactly once even if
replayed.
