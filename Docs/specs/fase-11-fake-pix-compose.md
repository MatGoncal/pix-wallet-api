# Fase 11 — fake-pix no Sail compose

## Contexto / Objetivo

Demo still needs `go run` on the host and `FAKE_PIX_BASE_URL=http://host.docker.internal:8080`.
`fake-pix-provider` fase 3 adds a Dockerfile. This phase adds a `fake-pix`
service on the Sail `sail` network so `./vendor/bin/sail up -d` starts the PSP.

When Go runs **inside** Docker, `FAKE_PIX_CALLBACK_URL=http://localhost/...`
is wrong: `localhost` is the Go container. Callback must be the Sail service
DNS name `laravel.test`. Sail → Go is container DNS `http://fake-pix:8080`,
not `host.docker.internal`.

Do **not** merge Laravel and Nest into one compose. Partner JSON / OpenAPI
unchanged. Pest keeps `Http::fake()` — CI still does not start Go.

## Endpoints (se aplicável)

Partner contract unchanged.

| Método | Rota | Auth | Descrição |
|--------|------|------|-----------|
| POST | `/v1/payments` | API key + optional `Idempotency-Key` | Unchanged. Internally POSTs `http://fake-pix:8080/v1/charges`. |

Outbound callback the **Go container** POSTs:

| Método | Rota | Auth | Descrição |
|--------|------|------|-----------|
| POST | `http://laravel.test/v1/webhooks/payment` | HMAC `t,v1` | Same webhook as today; URL is reachable from `fake-pix` |

## Request / Response

Outbound body unchanged except `callback_url` now uses the Sail hostname:

```json
{
  "amount": 1500,
  "currency": "BRL",
  "payment_id": "<wallet UUID>",
  "callback_url": "http://laravel.test/v1/webhooks/payment"
}
```

Partner 201 shape unchanged. Go down → HTTP **502** (fase 9/10).

## Fluxo (passo a passo)

1. `docker-compose.yml`: service `fake-pix`, `build.context: ../fake-pix-provider`
   (local portfolio layout), network `sail`, HEALTHCHECK from the image.
2. `laravel.test` `depends_on.fake-pix` with `condition: service_healthy`.
3. `.env.example` + `config/acmepay.php` defaults:
   `FAKE_PIX_BASE_URL=http://fake-pix:8080`,
   `FAKE_PIX_CALLBACK_URL=http://laravel.test/v1/webhooks/payment`.
4. README: `sail up -d` replaces `go run`. Smoke 502 = `sail stop fake-pix` →
   retry same `Idempotency-Key` → `sail start fake-pix` → 201 same `id` →
   by-payment → simulate → `PAID` (`sail artisan queue:work`).
5. phpunit.xml follows the new base URL so `Http::fake` maps match config.
   Tests still never dial a live Go process.

## Códigos de erro

| Código | Situação |
|--------|----------|
| HTTP 422 (FormRequest) | `currency` is not `BRL` (unchanged) |
| HTTP 502 (`bad_gateway`) | `fake-pix` stopped / unhealthy / timeout (unchanged envelope) |

## Critérios de aceite

- [x] `sail up -d` starts Go without a toolchain on the host
- [x] Create → by-payment (published `:8080`) → simulate → `PAID` without `go run`
- [x] `sail stop fake-pix` → POST with `Idempotency-Key` → 502; `sail start fake-pix` → retry same key → 201 same `id`
- [x] Callback URL is `http://laravel.test/v1/webhooks/payment` (Go container can resolve it)
- [x] Pest still uses `Http::fake()`; CI does not start the Go container
- [x] No mega-compose with Nest; no `provider_charge_id` on partner JSON

## Testes obrigatórios

- [x] Existing Pest green (`Http::fake` URL follows `FAKE_PIX_BASE_URL`)
- [x] No new Pest that requires a live `fake-pix` container
- [x] README smoke (manual): 502 → retry → simulate → `PAID`

## Migrations

None.

## Variáveis de ambiente novas

Same names; **defaults change**:

| Var | Default (Sail) | Descrição |
|-----|----------------|-----------|
| `FAKE_PIX_BASE_URL` | `http://fake-pix:8080` | Container DNS (not `host.docker.internal`) |
| `FAKE_PIX_API_KEY` | `fake-pix-demo` | Outbound Bearer / `X-Api-Key` |
| `FAKE_PIX_CALLBACK_URL` | `http://laravel.test/v1/webhooks/payment` | URL the **Go container** can reach |
| `FORWARD_FAKE_PIX_PORT` | `8080` | Host publish for curl `by-payment` / `simulate` |

## Dependências / Rollback

- Dependências: sibling `../fake-pix-provider` with fase 3 `Dockerfile`.
  `WEBHOOK_SECRET` must match between Sail and `fake-pix`.
- Rollback: drop the compose service; restore host `go run` +
  `host.docker.internal` / `http://localhost/v1/webhooks/payment`.
- Out of scope: durable Go store (fase 12), `Idempotency-Key` on Vue/Next
  (fase 13), EMV fallback, unified Laravel+Nest compose.
