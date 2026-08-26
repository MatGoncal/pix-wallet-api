# Shared error codes — AcmePay

Used by `pix-wallet-api`, `payment-api-nest`, `partner-dashboard-vue`, and
`checkout-portal-next`. Keep this file identical across repos.

## Envelope

```json
{
  "error": {
    "code": 1027,
    "name": "insufficient_balance",
    "message": "Partner balance is insufficient for this debit.",
    "details": {}
  }
}
```

| Field | Type | Notes |
|-------|------|-------|
| `code` | integer | Stable machine code |
| `name` | string | Snake_case identifier |
| `message` | string | Human-readable, English |
| `details` | object | Optional context (ids, currency, amounts in minor units) |

HTTP status is orthogonal: use 4xx/5xx appropriately; clients key off `error.code`.

## Codes

| Code | Name | HTTP (typical) | When |
|------|------|----------------|------|
| `1015` | `settlement_failed` | 422 / 502 | Settlement cannot complete (provider/ledger failure) |
| `1027` | `insufficient_balance` | 422 | Debit exceeds available balance for partner+currency |
| `1031` | `quote_expired` | 422 | FX quote past `expires_at` (rate lock window) |
| `1032` | `quote_consumed` | 422 | FX quote already consumed — a rate lock is single use |
| `1042` | `duplicate_event` | 200 | Webhook event already processed (idempotent replay) |

### Notes

- **`1015`** also covers a settlement the API refuses to apply: a `payment.paid` whose `data.amount`/`data.currency` diverge from the stored charge, and any attempt to rewrite splits of a payment that is no longer `PENDING`.
- **`1032`**: consuming a quote stamps `consumed_at`. Expiry is checked first, so an expired quote that was never consumed still reports `1031`.
- **`1042`**: Prefer HTTP **200** to the provider so retries stop. Log/metrics may still record `duplicate_event`. Response body may omit a hard error or include the code for observability — both APIs must document the chosen shape in `API_CONTRACT.md` and stay aligned.
- Amounts inside `details` are always **integer minor units**, never floats.
- Auth failures (missing/invalid API key) use standard HTTP 401 without a domain code unless noted in a phase spec.

## Extending

New codes require: update this file in **all four** repos, OpenAPI `components.responses`, and acceptance tests. Do not invent ad-hoc string errors for money paths.
