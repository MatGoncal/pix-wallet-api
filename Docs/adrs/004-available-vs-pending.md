# ADR 004 — Available vs pending

## Status

Accepted

## Context

Queuing a payout without moving money left `available` spendable. A second
payout could over-commit the same funds before the job ran. Writing a ledger
debit on create would lie: the money has not left the platform yet.

## Decision

- **PIX `payment.paid`** credits `available` and writes a ledger credit.
  PIX does **not** use `pending`.
- **Payout create** atomically moves `available → pending` (hold). No ledger
  row. Insufficient `available` → `1027` and no payout row.
- **Payout confirm** (job) decrements `pending` and writes the ledger debit.
- **Payout FAILED** returns `pending → available`. No ledger row.
- Invariant: `available + pending == SUM(credit) - SUM(debit)`.
- Reconcile compares that wallet sum to the ledger net. It never auto-corrects.

## Consequences

- `GET /v1/balances.pending` is a real hold, not a placeholder zero.
- Comparing only `available` during a hold is a false mismatch.
- Split credit-per-party and PIX unpaid QR pending remain out of scope.
