# Fase 4 — Async payouts + payment splits

## Contexto / Objetivo

Payouts are created as `QUEUED` and processed asynchronously. Debit happens on
**confirm** (job), not on create. Splits define settlement allocation; sum of
split amounts must equal payment amount. On paid settlement, split lines are
recorded; if splits were defined and settlement ledger fails → **1015**.

## Endpoints

| Método | Rota | Auth | Descrição |
|--------|------|------|-----------|
| POST | `/v1/payouts` | API key | Enqueue payout (`202`) |
| POST | `/v1/payments/{id}/splits` | API key | Define split lines |

## Fluxo — payout

1. Validate amount/currency/destination; create payout `QUEUED` (no debit).
2. Dispatch `ProcessPayout` job.
3. Job locks partner balance; if `available < amount` → mark `FAILED`, surface **1027**.
4. Else debit available, write ledger `debit`, mark `COMPLETED`.

## Fluxo — splits

1. Partner owns payment; payment not cancelled.
2. Sum of split `amount` must equal payment `amount`.
3. Upsert `payment_splits` rows for parties (`platform`, `seller`, `affiliate`).
4. On settlement (`payment.paid`), apply split metadata (already stored); credit
   full amount to partner available (demo simplification: platform fee is
   informational; seller credit = payment amount unless phase extends).
5. If splits sum invalid at settlement time → **1015** path (payment → FAILED).

## Critérios de aceite

- [x] `POST /v1/payouts` returns `202` with status `QUEUED` without debiting
- [x] Job debits on confirm; insufficient → `FAILED` + ledger unchanged + 1027 in response path when sync
- [x] Split sum must equal payment amount
- [x] Splits scoped to owning partner
- [x] Pest covers payout happy path, insufficient balance, split validation

## Códigos de erro

| Código | Situação |
|--------|----------|
| 1027 | insufficient_balance |
| 1015 | settlement_failed |

## Migrations

- `payouts`
- `payment_splits`
