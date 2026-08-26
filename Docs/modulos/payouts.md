# payouts

Async payouts. Debit on **confirm** (job), not on create.

## Behavior

1. `POST /v1/payouts` → `202` + status `QUEUED` (no balance change).
2. `ProcessPayout` job → `PROCESSING` → debit → `COMPLETED`.
3. Insufficient available → `FAILED` with failure_code `1027`.

## Job resilience

`ProcessPayout`: `$tries = 5`, exponential `backoff()` of `[5, 15, 45, 135]`
seconds, and `failed()` logging the exhausted payout id. A failed attempt moved
no money — the payout only leaves `QUEUED` inside the transaction that debits
it — so the next attempt starts from scratch, and a payout that is already
`COMPLETED` is skipped.

## Entry points

- `App\Services\PayoutService`
- `App\Jobs\ProcessPayout`
- `App\Http\Controllers\Api\V1\PayoutController`
- Spec: `Docs/specs/fase-4-payouts-splits.md`
