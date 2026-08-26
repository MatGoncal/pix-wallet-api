# Fase 5 — Hardening (Pest, PHPStan, Pint, CI, README)

## Contexto / Objetivo

Production-ready quality gates for the portfolio demo: static analysis,
formatter, CI on GitHub Actions, and an English README with architecture diagram.

## Critérios de aceite

- [x] PHPStan level 5 green via Larastan
- [x] Pint green (`vendor/bin/pint --test`)
- [x] Pest covers money paths (balances, FX, payout, split) + prior phases
- [x] GitHub Actions CI runs Pint + PHPStan + Pest
- [x] README in English with architecture diagram + one-command quickstart
- [x] AGENTS.md phase table updated

## Commands (Sail only on local host)

```bash
./vendor/bin/sail pest
./vendor/bin/sail pint --test
./vendor/bin/sail php vendor/bin/phpstan analyse
```
