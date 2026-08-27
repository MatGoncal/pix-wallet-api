# ADR 002 — Idempotency as a constraint

## Status

Accepted

## Context

Check-then-insert races let two workers credit the same `payment.paid` or
replay the same payout debit. Application-level "SELECT then INSERT" is not
safe under concurrency.

## Decision

Idempotency lives in unique indexes, not in a prior existence check:

- `balance_ledger`: `UNIQUE (reference_type, reference_id, direction)`. Insert
  first; `23505` / `ON CONFLICT DO NOTHING` means the money already moved.
- `webhook_events`: `UNIQUE (provider, event_id)`.
- `idempotency_keys`: `UNIQUE (partner_id, key)` for optional
  `Idempotency-Key` on partner POSTs.
- Payout jobs: deterministic queue id plus the ledger unique on confirm.

## Consequences

- Duplicate work is a no-op, not a second movement.
- Direction is part of the ledger key so a refund of a payment is a distinct
  entry.
- Replay of a payout confirm does not decrement `pending` twice.
