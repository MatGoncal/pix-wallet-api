# Fase 8 — Reconciliação ledger × carteira (read-only)

## Contexto / Objetivo

Detect drift between the append-only ledger and the wallet without
auto-correcting. After fase 7 a hold lives in `pending` with **no** ledger
row, so the invariant is:

`available + pending == SUM(credit) - SUM(debit)`

Comparing only `available` would flag every in-flight payout as a mismatch.

## Endpoints (se aplicável)

No HTTP endpoint. CLI only:

| Runtime | Command |
|---------|---------|
| Laravel | `php artisan acmepay:reconcile` (Sail) |
| Nest | `npm run reconcile` (`src/cli/reconcile.ts`, ts-node, no nest-commander) |

## Request / Response

Not applicable. Process output is structured lines; process exit is the result.

Query: ledger net per `(partner_id, currency)` versus
`partner_balances.available + partner_balances.pending`.

On mismatch, log `ledger_mismatch` with `partner_id`, `currency`, `available`,
`pending`, `wallet_sum`, `ledger_sum`, `delta` (all amounts in integer minor
units) and exit **1**.

## Fluxo (passo a passo)

1. Read ledger sums per partner+currency
   (`SUM(CASE direction WHEN 'credit' THEN amount ELSE -amount END)`).
2. Read `available` and `pending` for the same keys (full outer join so an
   orphan ledger or an empty wallet still appears).
3. Diff `wallet_sum` (`available + pending`) against `ledger_sum`. Do **not**
   write to the database.
4. Exit 0 when every pair matches; exit 1 otherwise.

## Códigos de erro

None (CLI, not HTTP). Domain code `1015` is unrelated.

## Critérios de aceite

- [x] Command is read-only (no INSERT/UPDATE/DELETE)
- [x] Wallet with a hold (`pending > 0`) that matches the ledger net → exit 0
- [x] Orphan ledger credit (or any delta) → exit 1 + `ledger_mismatch` with delta in minor units
- [x] Tampered `available` with `pending` 0 → exit 1
- [x] Pest / Nest integration covers the three cases above

## Testes obrigatórios

- [x] Integração — hold (`pending > 0`) → exit 0
- [x] Integração — ledger órfão → exit ≠ 0 e mismatch no output
- [x] Integração — `available` adulterado, `pending` 0 → exit 1

## Migrations

None.

## Variáveis de ambiente novas

None.

## Dependências / Rollback

- Dependências: existing `balance_ledger` and `partner_balances`
- Rollback: remove the Artisan command / npm script
