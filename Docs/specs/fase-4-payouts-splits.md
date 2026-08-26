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
2. Dispatch `ProcessPayout` job (`$tries = 5`, backoff `[5, 15, 45, 135]`).
3. Job guards on `available >= amount` in the update itself; a short balance →
   mark `FAILED`, surface **1027**.
4. Else debit available, write ledger `debit`, mark `COMPLETED`.
5. A retry is safe at any point: the payout only leaves `QUEUED` inside the
   transaction that debits it, and a payout already past `QUEUED` is skipped.
   Exhausted retries log a structured failure and leave the payout `QUEUED`.

## Fluxo — splits

1. Partner owns payment; payment still `PENDING`.
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
- [x] Splits on a payment in any terminal status → 1015, stored lines untouched
- [x] Processing the same payout twice debits once
- [x] A payout job that keeps failing lands in `failed_jobs` with a logged reason

## Códigos de erro

| Código | Situação |
|--------|----------|
| 1027 | insufficient_balance |
| 1015 | settlement_failed |

## Migrations

- `payouts`
- `payment_splits`
