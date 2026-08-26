# splits

Payment split lines applied/validated around settlement.

## Behavior

- `POST /v1/payments/{id}/splits` replaces split lines for owning partner.
- Parties: `platform`, `seller`, `affiliate`.
- Sum of amounts must equal payment amount; otherwise **1015**.
- Only editable while the payment is `PENDING`. Any terminal status (`PAID`,
  `EXPIRED`, `FAILED`, `CANCELLED`) → **1015** with `details.status`, stored
  lines untouched: rewriting them after settlement would leave the ledger
  describing a split that never happened.
- On `payment.paid`, invalid stored splits fail settlement (**1015** path → payment `FAILED`).

## Entry points

- `App\Services\SplitService`
- `App\Http\Controllers\Api\V1\SplitController`
- Spec: `Docs/specs/fase-4-payouts-splits.md`
