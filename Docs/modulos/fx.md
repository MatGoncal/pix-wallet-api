# fx

FX quotes with a **5 minute** rate lock (configurable via `FX_RATE_LOCK_SECONDS`).

## Behavior

- `POST /v1/fx/quotes` stores locked `rate` (decimal string) + `target_amount`.
- Conversion via `FakeFxProvider` + `bcmath` (no float).
- Consuming after `expires_at` → error **1031** (`FxService::assertUsable`).

## Entry points

- `App\Services\FxService`, `App\Services\FakeFxProvider`
- `App\Http\Controllers\Api\V1\FxController`
- Spec: `Docs/specs/fase-3-balances-fx.md`
