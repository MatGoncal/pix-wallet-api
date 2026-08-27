# payouts

Async payouts. Reserve `available → pending` on **create**; ledger debit on
**confirm**.

## Behavior

1. `POST /v1/payouts` → transaction: insert `QUEUED` + reserve. `202`.
   Insufficient `available` → **1027**, no row.
2. `ProcessPayout` job → `PROCESSING` → `confirmDebit` (pending + ledger) →
   `COMPLETED`. `available` does not change on confirm.
3. Domain failure → `release` (pending back to available) → `FAILED`.
4. Job replay: ledger unique on payout/debit; `pending` is not decremented twice.

## Job resilience

`ProcessPayout`: `$tries = 5`, exponential `backoff()` of `[5, 15, 45, 135]`
seconds, and `failed()` logging the exhausted payout id. The payout only leaves
`QUEUED` inside the transaction that confirms or releases the hold, so an
infrastructure failure rolls back and the next attempt still sees `QUEUED` with
pending reserved. See `Docs/runbooks/incidents.md` if the worker gives up.

## Entry points

- `App\Services\PayoutService`
- `App\Jobs\ProcessPayout`
- `App\Http\Controllers\Api\V1\PayoutController`
- Specs: `Docs/specs/fase-4-payouts-splits.md`, `Docs/specs/fase-7-pending-payout.md`
