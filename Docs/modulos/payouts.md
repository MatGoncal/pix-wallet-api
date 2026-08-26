# payouts

Async payouts. Debit on **confirm** (job), not on create.

## Behavior

1. `POST /v1/payouts` → `202` + status `QUEUED` (no balance change).
2. `ProcessPayout` job → `PROCESSING` → debit → `COMPLETED`.
3. Insufficient available → `FAILED` with failure_code `1027`.

## Entry points

- `App\Services\PayoutService`
- `App\Jobs\ProcessPayout`
- `App\Http\Controllers\Api\V1\PayoutController`
- Spec: `Docs/specs/fase-4-payouts-splits.md`
