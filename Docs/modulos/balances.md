# balances

Multi-currency partner balances with immutable ledger.

## Behavior

- One `partner_balances` row per `(partner_id, currency)`.
- Credits/debits that change the wallet **net** always write `balance_ledger`.
- Cash-in settlement (`payment.paid`) credits `available` once (idempotent on
  ledger reference). PIX does not use `pending`.
- Payout create **reserves** `available → pending` with no ledger row.
- Payout confirm **consumes** `pending` and writes a ledger debit.
- Payout failure **releases** `pending → available` with no ledger row.
- `GET /v1/balances` returns `available` + `pending` in minor units.
- Invariant: `available + pending == SUM(credit) - SUM(debit)`.

## Invariants

- **Idempotency is a constraint, not a check.** `balance_ledger` carries
  `UNIQUE (reference_type, reference_id, direction)`. `BalanceService` inserts
  and treats `23505` on that constraint as a no-op instead of looking for an
  existing row first, which was a race. Direction is part of the key, so a
  refund of a payment is a distinct entry rather than a duplicate.
- **Money moves in one statement.** A spendable debit is
  `UPDATE partner_balances SET available = available - :amount WHERE ... AND available >= :amount`.
  A payout hold is `available -=, pending += WHERE available >=`. Zero affected
  rows means error `1027`.
- **`apply()` / `reserve` / `release` / `confirmDebit` own a transaction.**
  Nested inside a caller's transaction they become a savepoint.

## Entry points

- `App\Services\BalanceService`
- `App\Http\Controllers\Api\V1\BalanceController`
- `App\Console\Commands\ReconcileBalancesCommand` (`php artisan acmepay:reconcile`)
- Specs: `Docs/specs/fase-3-balances-fx.md`, `Docs/specs/fase-7-pending-payout.md`,
  `Docs/specs/fase-8-reconcile.md`
- ADR: `Docs/adrs/004-available-vs-pending.md`
- Tests: `tests/Feature/Money/LedgerIdempotencyTest.php`,
  `tests/Feature/Money/ReconcileBalancesTest.php`,
  `tests/Concurrency/BalanceConcurrencyTest.php`
