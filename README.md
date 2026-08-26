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
    Provider["FakePixProvider"]
  end

  Vue -->|"API key /v1/*"| API["Laravel API"]
  Provider -->|"HMAC webhook"| API

  API --> PG[(PostgreSQL)]
  API --> Redis[(Redis queue)]

  Redis --> WH["ProcessPaymentWebhook"]
  Redis --> PO["ProcessPayout"]

  WH --> Bal["BalanceService + ledger"]
  PO --> Bal
```

| Module | Endpoints |
|--------|-----------|
| payments | `POST/GET /v1/payments` |
| webhooks | `POST /v1/webhooks/payment` (idempotent) |
| balances | `GET /v1/balances` |
| fx | `POST /v1/fx/quotes` (5 min rate lock) |
| payouts | `POST /v1/payouts` (debit on confirm) |
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

Queue worker (webhooks + payouts):

```bash
./vendor/bin/sail artisan queue:work redis
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
