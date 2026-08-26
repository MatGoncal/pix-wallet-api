# Fase 0 — Bootstrap spec-driven + Sail

## Contexto / Objetivo

Scaffold `pix-wallet-api` with AGENTS.md, Docs/, Cursor rules/skills, shared
API contract, and Sail (Postgres + Redis). No business endpoints yet beyond
health.

## Critérios de aceite

- [x] `AGENTS.md` with stack, module map, Sail-only rule, PR checklist
- [x] `Docs/` tree: Product, specs, modulos, Database, Postman, runbooks
- [x] `API_CONTRACT.md`, `error-codes.md`, `openapi.yaml` present
- [x] `.cursor/rules/projeto.mdc` + `laravel-payment-module` skill
- [x] Sail `compose.yaml` with `pgsql` + `redis`
- [x] `.env.example` documents Postgres, Redis, webhook secret
- [x] README quickstart via Sail
- [x] CI workflow stub (lint/test via Sail-compatible PHP)

## Testes

- [x] `./vendor/bin/sail up -d` brings stack (validated in Fase 1 runbook)

## Variáveis de ambiente

| Var | Default | Descrição |
|-----|---------|-----------|
| `DB_*` | Sail pgsql | PostgreSQL |
| `REDIS_*` | Sail redis | Cache/queue |
| `WEBHOOK_SECRET` | `dev-webhook-secret` | HMAC for provider webhooks |
| `QUEUE_CONNECTION` | `redis` | Async jobs |
