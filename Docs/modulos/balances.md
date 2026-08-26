# balances

Multi-currency partner balances with immutable ledger.

## Behavior

- One `partner_balances` row per `(partner_id, currency)`.
- Credits/debits always write `balance_ledger`.
- Cash-in settlement (`payment.paid`) credits `available` once (idempotent on ledger reference).
- `GET /v1/balances` returns `available` + `pending` in minor units.

## Invariants

- **Idempotency is a constraint, not a check.** `balance_ledger` carries
  `UNIQUE (reference_type, reference_id, direction)`. `BalanceService` inserts
  and treats `23505` on that constraint as a no-op instead of looking for an
  existing row first, which was a race. Direction is part of the key, so a
  refund of a payment is a distinct entry rather than a duplicate.
- **Money moves in one statement.** A debit is
  `UPDATE partner_balances SET available = available - :amount WHERE ... AND available >= :amount`.
  Zero affected rows means error `1027`. The guard travels with the write, so a
  balance can never go negative through a read-compare-write window.
- **`apply()` owns a transaction.** Nested inside a caller's transaction it
  becomes a savepoint, which is what lets a duplicate roll back without
  poisoning the webhook job's transaction.

## Entry points

- `App\Services\BalanceService`
- `App\Http\Controllers\Api\V1\BalanceController`
- Spec: `Docs/specs/fase-3-balances-fx.md`
- Tests: `tests/Feature/Money/LedgerIdempotencyTest.php`,
  `tests/Concurrency/BalanceConcurrencyTest.php`
