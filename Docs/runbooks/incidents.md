# Runbook — incidents (AcmePay)

Operator playbook for money and webhook failures. The CLI never writes; it
only tells you the delta.

## Ledger mismatch (`acmepay:reconcile` / `npm run reconcile` exit 1)

**Symptom:** process exit 1 and a `ledger_mismatch` line with `partner_id`,
`currency`, `available`, `pending`, `wallet_sum`, `ledger_sum`, `delta` in
minor units.

**Meaning:** `available + pending` disagrees with the ledger net. A hold
(`pending > 0`) that still matches the ledger is **not** a mismatch.

**Do**

1. Run the command again to confirm it is not a read of an in-flight
   transaction.
2. Inspect `balance_ledger` and `partner_balances` for that partner+currency.
3. Typical causes: a row edited by hand; a job that wrote the ledger but
   rolled back the wallet (or the reverse); an orphan credit inserted in a
   test or a one-off script.
4. Fix with a **new** compensating ledger entry and matching wallet update in
   one transaction. Do not UPDATE existing ledger rows.

**Do not** re-run payout jobs hoping they will "fix" the numbers. Confirm is
idempotent on the ledger unique; it will not patch a damaged wallet.

## Payout `PROCESSING` with pending stuck

**Symptom:** payout status `PROCESSING` (or `QUEUED` after the worker gave up)
while `pending` still holds the amount.

**Meaning:** create already reserved funds. The confirm transaction should
never commit `PROCESSING` alone (status change and confirm/release share a
transaction). If you see `PROCESSING` in the database, the process crashed
after a code change that split the transaction, or someone updated the row by
hand.

**Do**

1. If a ledger debit for `reference_type=payout` already exists: set status
   `COMPLETED` and ensure `pending` was decremented. Do not insert a second
   debit.
2. If there is no ledger debit: either finish confirm (`pending -=`, ledger
   debit, `COMPLETED`) or release (`pending → available`, `FAILED`) in one
   transaction.
3. If the worker exhausted retries and the payout is still `QUEUED`: the hold
   is intact. Re-dispatch `ProcessPayout` or release and mark `FAILED`.

## `1042` duplicate webhook event

**Symptom:** provider retries `POST /v1/webhooks/payment`; API returns **200**
with `duplicate_event`.

**Meaning:** `(provider, event_id)` already processed. Money must not move
again. This is success as far as the provider is concerned.

**Do:** confirm the original payment is in the expected terminal status. If
the first delivery failed *after* inserting the event but *before* settlement,
that is a separate incident (ledger vs payment status) — do not delete the
event row to "force a replay".

## `1044` webhook timestamp expired

**Symptom:** **401** + `webhook_timestamp_expired`.

**Meaning:** HMAC `v1` matched but `|now - t|` exceeded
`WEBHOOK_TOLERANCE_SECONDS` (default 300).

**Do:** check clock skew on the producer (Next simulator or fake provider) and
the API host. A captured payload older than five minutes is supposed to fail.
Do not widen the window to "make it work" without an ADR.

## `1027` on payout create

**Symptom:** `POST /v1/payouts` returns **422** + `insufficient_balance`. No
payout row.

**Meaning:** `available` could not cover the amount at reserve time. This is
the partner running out of spendable funds, not a job bug.

**Do:** `GET /v1/balances`. If `pending` is large, a queued payout is holding
the money — wait for confirm/fail or inspect stuck payouts. If both buckets
are too small, the partner needs a settlement credit (`payment.paid`) first.

Exceptional `1027` on the **job** means `pending` could not cover confirm
after a reserve. Treat it like a stuck hold: inspect, then release or
complete by hand.
