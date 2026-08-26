# partners

Partner tenants authenticate with an API key.

- Table: `partners` (`api_key_hash`, `api_key_prefix`, `is_active`)
- Middleware: `AuthenticateApiKey` — accepts `Authorization: Bearer` or `X-Api-Key`
- Raw key shown once at seed/create time; only hash stored
- Spec: `Docs/specs/fase-1-payments-auth.md`
