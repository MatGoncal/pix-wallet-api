# Fase 7 — Pending no payout (reserva no create)

## Contexto / Objetivo

`partner_balances.pending` exists on `GET /v1/balances` but was never written.
A queued payout left `available` spendable, so a second payout could over-commit
the same funds before the job ran.

Reserve on `POST /v1/payouts`: move `available → pending` in the same
transaction as the `QUEUED` insert. The job confirms by debiting `pending` and
writing the ledger, or releases back to `available` on domain failure.

PIX cash-in is unchanged: `payment.paid` credits `available` only.

## Endpoints (se aplicável)

| Método | Rota | Auth | Descrição |
|--------|------|------|-----------|
| POST | `/v1/payouts` | API key | Insert `QUEUED` + reserve; `202`. Insufficient → **1027**, no row |
| GET | `/v1/balances` | API key | `pending` is now a real hold (minor units) |

No new routes. The `ProcessPayout` job remains the confirm path.

## Request / Response

Unchanged `202` body. After a successful create (job not yet run):

- `available` decreased by `amount`
- `pending` increased by `amount`
- **zero** ledger debit

On confirm: `pending` decreased, one ledger debit, `available` unchanged.
On job `FAILED`: `pending → available`, no ledger line.

## Fluxo (passo a passo)

1. `POST /v1/payouts` opens a transaction: insert `QUEUED`, then one atomic
   `UPDATE partner_balances SET available = available - :amt, pending = pending + :amt WHERE available >= :amt`.
2. Zero rows → **1027**, rollback (no payout). Enqueue only after the
   transaction commits.
3. Job: `QUEUED` → `PROCESSING` → `confirmDebit` (`pending -= amt WHERE pending >= amt` + ledger debit) → `COMPLETED`.
4. Ledger unique `(reference_type, reference_id, direction)` is the job
   idempotency key: a replay must not touch `pending` again.
5. Domain failure on the job: `release` (`pending -=, available +=`) and
   `FAILED`. Exceptional `1027` on confirm (pending already gone) still marks
   `FAILED` without inventing a ledger line.
6. `payment.paid` continues to credit `available` and never writes `pending`.

Invariante: `available + pending == SUM(credit) - SUM(debit)` on the ledger.
Reserve/release **do not** write ledger (the money is still the platform's).

## Códigos de erro

| Código | Situação |
|--------|----------|
| 1027 | `insufficient_balance` — create when `available` cannot cover the payout; exceptional confirm if `pending` cannot cover |

## Critérios de aceite

- [x] POST with funds: `202`, `available` down, `pending` up, **zero** ledger debit
- [x] Job completes: `pending` 0, one ledger debit, `available` unchanged on confirm
- [x] POST without funds: `1027`, no payout row
- [x] Two parallel POSTs that only fit once: one `202`, one `1027`, `available+pending` = original credit
- [x] FAILED on the job returns `pending` → `available`
- [x] Job replay (unique ledger) does not move `pending` again
- [x] PIX `paid` does not touch `pending` (regression)
- [x] Idempotency replay of a payout does not reserve a second time

## Testes obrigatórios

- [x] Feature — hold on create, confirm, insufficient create, PIX regression
- [x] Feature — job FAILED releases the hold; job replay leaves pending untouched
- [x] Concorrência — two (and many) creates race on reserve, not on the job debit

## Migrations

None (`pending` already exists).

## Variáveis de ambiente novas

None.

## Dependências / Rollback

- Dependências: `partner_balances.pending`, ledger unique on payout/debit
- Rollback: debit `available` on the job again; `pending` stays 0
