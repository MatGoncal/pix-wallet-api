# AGENTS.md — pix-wallet-api (AcmePay)

> Master index for humans and AI agents. Read this **before** any implementation.

## Project summary

**AcmePay** PIX wallet API — fictional portfolio backend for cash-in, webhooks,
FX rate lock, multi-currency balances, payouts, and splits. Domain: personal
skill `payments-domain`. Contract: `Docs/specs/API_CONTRACT.md` +
`Docs/specs/openapi.yaml`.

## Stack

| Layer | Choice |
|-------|--------|
| Runtime | PHP 8.3+ **inside Sail only** (host has no PHP/Composer) |
| Framework | Laravel 13 |
| DB | PostgreSQL (Sail `pgsql`) |
| Cache / queue | Redis (Sail `redis`) |
| Tests | Pest |
| Lint | Pint |
| Static analysis | PHPStan (from Fase 5) |

### Environment rule (hard)

**Never run PHP, Composer, Artisan, Pest, or Pint on the host.** Always:

```bash
./vendor/bin/sail up -d
./vendor/bin/sail artisan …
./vendor/bin/sail pest
./vendor/bin/sail pint
```

See `Docs/runbooks/testes.md`.

## Module map

| Module | Responsibility | Doc |
|--------|----------------|-----|
| `partners` | Partner + API key auth | `Docs/modulos/partners.md` |
| `payments` | Cash-in PIX, QR, status | `Docs/modulos/payments.md` |
| `webhooks` | Idempotent provider events | `Docs/modulos/webhooks.md` |
| `fx` | Quotes + rate lock (Fase 3) | `Docs/modulos/fx.md` |
| `balances` | Multi-currency wallet + payout holds (Fase 3, 7) | `Docs/modulos/balances.md` |
| `payouts` | Async payouts with pending reserve (Fase 4, 7) | `Docs/modulos/payouts.md` |
| `splits` | Settlement splits (Fase 4) | `Docs/modulos/splits.md` |

## Entrypoints

| Path | Notes |
|------|-------|
| `routes/api.php` | `/v1/*` JSON API |
| `app/Http/Middleware/AuthenticateApiKey.php` | Partner auth |
| `app/Services/PaymentService.php` | Payment orchestration |
| `app/Services/BalanceService.php` | Balances + ledger |
| `app/Services/FxService.php` | FX quotes |
| `app/Services/PayoutService.php` | Async payouts |
| `app/Services/SplitService.php` | Payment splits |
| `app/Services/FakePixProvider.php` | HTTP client of `fake-pix-provider` (not a real PSP) |
| `app/Jobs/ProcessPaymentWebhook.php` | Webhook side effects |
| `app/Jobs/ProcessPayout.php` | Payout confirm + pending debit |
| `app/Console/Commands/ReconcileBalancesCommand.php` | Read-only `acmepay:reconcile` |

## Quick lookup

| Want to understand… | See |
|---------------------|-----|
| Product overview | `Docs/Product/OVERVIEW.md` |
| HTTP contract | `Docs/specs/API_CONTRACT.md` |
| OpenAPI 3.1 | `Docs/specs/openapi.yaml` |
| Error codes | `Docs/specs/error-codes.md` |
| Schema | `Docs/Database/DB.md` |
| New feature template | `Docs/specs/TEMPLATE.md` |
| Module folder contract | `Docs/specs/MODULE_SKELETON.md` |
| Fase 0 bootstrap | `Docs/specs/fase-0-bootstrap.md` |
| Fase 1 payments + auth | `Docs/specs/fase-1-payments-auth.md` |
| Fase 2 webhooks | `Docs/specs/fase-2-webhooks.md` |
| Fase 3 balances + FX | `Docs/specs/fase-3-balances-fx.md` |
| Fase 4 payouts + splits | `Docs/specs/fase-4-payouts-splits.md` |
| Fase 5 hardening | `Docs/specs/fase-5-hardening.md` |
| Fase 6 HMAC + Idempotency-Key | `Docs/specs/fase-6-idempotency-hmac.md` |
| Fase 7 pending payout hold | `Docs/specs/fase-7-pending-payout.md` |
| Fase 8 reconcile | `Docs/specs/fase-8-reconcile.md` |
| Fase 9 FakePix HTTP | `Docs/specs/fase-9-fake-pix-http.md` |
| ADRs | `Docs/adrs/` |
| Incidents | `Docs/runbooks/incidents.md` |
| How to test | `Docs/runbooks/testes.md` |
| Domain glossary | `~/.cursor/skills/payments-domain/SKILL.md` |
| New Laravel module skill | `.cursor/skills/laravel-payment-module/SKILL.md` |

## Agent workflow (mandatory)

```
1. Read AGENTS.md
2. Read Docs/modulos/<module>.md and/or Docs/specs/<feature>.md
   (+ Product / Database / Postman as needed)
3. Implement following MODULE_SKELETON.md + laravel-payment-module skill
4. Run tests via Sail (never host PHP)
5. Run Pint via Sail
6. If behavior changed → update spec / module doc / Postman
7. PR with checklist below
```

**Spec without test does not close. Code without updating the spec does not close.**

## Build phases

| Fase | Scope | Doc |
|------|-------|-----|
| 0 | Spec-driven bootstrap + Sail | `Docs/specs/fase-0-bootstrap.md` |
| 1 | API key auth + POST/GET payments + FakePixProvider | `Docs/specs/fase-1-payments-auth.md` |
| 2 | Idempotent webhook + job | `Docs/specs/fase-2-webhooks.md` |
| 3 | Balances + FX quotes | `Docs/specs/fase-3-balances-fx.md` |
| 4 | Payouts + splits | `Docs/specs/fase-4-payouts-splits.md` |
| 5 | Hardening (Pest, PHPStan, Pint, CI) | `Docs/specs/fase-5-hardening.md` |
| 6 | HMAC timestamp + Idempotency-Key | `Docs/specs/fase-6-idempotency-hmac.md` |
| 7 | Payout pending hold | `Docs/specs/fase-7-pending-payout.md` |
| 8 | Read-only reconcile | `Docs/specs/fase-8-reconcile.md` |
| 9 | FakePixProvider HTTP client of `fake-pix-provider` | `Docs/specs/fase-9-fake-pix-http.md` |

## Do NOT

- Use `float`/`double` for money — integer minor units only
- Call a real PSP — `FakePixProvider` is an HTTP client of `fake-pix-provider` only
- Copy StarsPay production code or secrets
- Run tests or Artisan on host PHP
- Write code before the phase spec exists
- Invent error codes outside `error-codes.md`

## Naming

- Controllers: `App\Http\Controllers\Api\V1\*`
- Form requests: `App\Http\Requests\*`
- Enums: `App\Enums\*`
- Services / clients: `App\Services\*`
- Jobs: `App\Jobs\*`
- Amounts in DB: `bigint` minor units; currency `char(3)`

## PR checklist

- [ ] Spec in `Docs/specs/` updated (acceptance criteria checked)
- [ ] Module doc updated if behavior changed
- [ ] Pest tests cover happy path + money/idempotency edge cases
- [ ] `./vendor/bin/sail pest` green
- [ ] `./vendor/bin/sail pint --test` green
- [ ] No floats for money
- [ ] Postman collection updated for new/changed endpoints
- [ ] Commits small and English
