# balances

Multi-currency partner balances with immutable ledger.

## Behavior

- One `partner_balances` row per `(partner_id, currency)`.
- Credits/debits always write `balance_ledger`.
- Cash-in settlement (`payment.paid`) credits `available` once (idempotent on ledger reference).
- `GET /v1/balances` returns `available` + `pending` in minor units.

## Entry points

- `App\Services\BalanceService`
- `App\Http\Controllers\Api\V1\BalanceController`
- Spec: `Docs/specs/fase-3-balances-fx.md`
