# ADR 003 — Webhook signed timestamp

## Status

Accepted

## Context

An HMAC of the raw body alone can be replayed forever if the secret leaks into
logs or a captured request. Providers (and Stripe-style APIs) bind the
signature to a timestamp so stale payloads expire.

## Decision

`X-AcmePay-Signature: t=<unix>,v1=<hex>` where `v1` is HMAC-SHA256 of
`"${t}.${raw_body}"` with `WEBHOOK_SECRET`. Reject `|now - t|` outside
`WEBHOOK_TOLERANCE_SECONDS` (default 300) with **401** + `1044`
`webhook_timestamp_expired`. Missing or invalid signatures stay generic 401.
`event_id` uniqueness (`1042`) remains the replay key inside the window.

## Consequences

- The Next simulator and both APIs share the same signer/verifier.
- Clock skew beyond five minutes fails closed.
- Tests freeze time (`Carbon::setTestNow` / injected `now`) rather than
  sleeping.
