# Fase 7 — Reconciliação (superseded)

This document described a read-only reconcile that compared only
`partner_balances.available` to the ledger, with `pending` always 0.

**Superseded by:**

- [`fase-7-pending-payout.md`](fase-7-pending-payout.md) — payout hold writes `pending`
- [`fase-8-reconcile.md`](fase-8-reconcile.md) — invariant is `available + pending == ledger net`
