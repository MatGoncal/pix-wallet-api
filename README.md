# pix-wallet-api

AcmePay PIX wallet API — Laravel implementation of the shared AcmePay v1 payments
contract: cash-in PIX, idempotent webhooks, multi-currency balances, FX rate lock,
async payouts, and settlement splits.

Fictional portfolio project. Synthetic data only. No real PSP.

## Architecture

```mermaid
flowchart TB
  subgraph clients [Clients]
    Vue["partner-dashboard-vue"]
    Go["fake-pix-provider"]
  end

  Vue -->|"API key /v1/*"| API["Laravel API"]
  API -->|"POST /v1/charges"| Go
  Go -->|"HMAC webhook simulate"| API

  API --> PG[(PostgreSQL)]
  API --> Redis[(Redis queue)]

  Redis --> WH["ProcessPaymentWebhook"]
  Redis --> PO["ProcessPayout"]

  WH --> Bal["BalanceService + ledger"]
  PO --> Bal
```

`POST /v1/payments` calls Go for the QR. The signed webhook is Go `simulate`
(HMAC `t,v1`) posting to `/v1/webhooks/payment` — the Next
`POST /api/simulator/fire` path is a parallel demo, not the PSP flow.

| Module | Endpoints |
|--------|-----------|
| payments | `POST/GET /v1/payments` |
| webhooks | `POST /v1/webhooks/payment` (idempotent) |
| balances | `GET /v1/balances` |
| fx | `POST /v1/fx/quotes` (5 min rate lock) |
| payouts | `POST /v1/payouts` (reserve on create, debit on confirm) |
| splits | `POST /v1/payments/{id}/splits` |

Contract: [`Docs/specs/API_CONTRACT.md`](Docs/specs/API_CONTRACT.md) ·
OpenAPI: [`Docs/specs/openapi.yaml`](Docs/specs/openapi.yaml)

## Quickstart

Host needs **Docker** only (no local PHP).

```bash
cp .env.example .env
./vendor/bin/sail up -d
./vendor/bin/sail artisan key:generate
./vendor/bin/sail artisan migrate --seed
```

Demo API key (seeded): `DEMO_PARTNER_API_KEY` in `.env` (default
`acmepay_demo_key_change_me`).

```bash
curl -s -X POST http://localhost/v1/payments \
  -H "Authorization: Bearer acmepay_demo_key_change_me" \
  -H "Content-Type: application/json" \
  -d '{"amount":1500,"currency":"BRL","external_id":"demo-1"}'
```

### Demo PIX with fake-pix-provider

QR comes from Go on the **host** (`go run`, `:8080`). Sail reaches it via
`http://host.docker.internal:8080`. Keep `sail artisan queue:work` running so
the signed webhook from `simulate` can mark the payment `PAID`.

```bash
# In portfolio/fake-pix-provider
export WEBHOOK_SECRET=dev-webhook-secret
export FAKE_PIX_API_KEY=fake-pix-demo
export PORT=8080
go run ./cmd/provider
```

```bash
# Create (copy `id` from the 201)
curl -s -X POST http://localhost/v1/payments \
  -H "Authorization: Bearer acmepay_demo_key_change_me" \
  -H "Content-Type: application/json" \
  -d '{"amount":1500,"currency":"BRL","external_id":"demo-1"}'

# Lookup charge id without scraping logs
curl -s http://localhost:8080/v1/charges/by-payment/<payment_id> \
  -H "Authorization: Bearer fake-pix-demo"

# Simulate paid — Go POSTs HMAC t,v1 to FAKE_PIX_CALLBACK_URL
curl -s -X POST http://localhost:8080/v1/charges/<charge_id>/simulate \
  -H "Authorization: Bearer fake-pix-demo" \
  -H "Content-Type: application/json" \
  -d '{"type":"payment.paid"}'
```

If Go is down, `POST /v1/payments` returns **502**. The Next webhook simulator
remains a parallel path and is not required to prove Go → API.

Queue worker (webhooks + payouts):

```bash
./vendor/bin/sail artisan queue:work redis
```

Read-only wallet vs ledger check:

```bash
./vendor/bin/sail artisan acmepay:reconcile
```

## Quality gates

```bash
./vendor/bin/sail pest
./vendor/bin/sail pint --test
./vendor/bin/sail php vendor/bin/phpstan analyse
```

## Docs for agents

Start at [`AGENTS.md`](AGENTS.md). Domain glossary: personal skill
`payments-domain`.
