# splits

Payment split lines applied/validated around settlement.

## Behavior

- `POST /v1/payments/{id}/splits` replaces split lines for owning partner.
- Parties: `platform`, `seller`, `affiliate`.
- Sum of amounts must equal payment amount; otherwise **1015**.
- On `payment.paid`, invalid stored splits fail settlement (**1015** path → payment `FAILED`).

## Entry points

- `App\Services\SplitService`
- `App\Http\Controllers\Api\V1\SplitController`
- Spec: `Docs/specs/fase-4-payouts-splits.md`
