# Database — AcmePay pix-wallet-api

Amounts are **bigint minor units**. Never float.

## Tables

### `partners`

| Column | Type | Notes |
|--------|------|-------|
| id | uuid PK | |
| name | string | |
| api_key_hash | string unique | `hash('sha256', raw_key)` |
| api_key_prefix | string(8) | Display / lookup hint |
| is_active | boolean | default true |
| created_at / updated_at | timestamps | |

### `payments`

| Column | Type | Notes |
|--------|------|-------|
| id | uuid PK | |
| partner_id | uuid FK → partners | |
| status | string | PaymentStatusEnum |
| amount | bigint | minor units |
| currency | char(3) | e.g. BRL |
| external_id | string nullable | unique(partner_id, external_id) |
| description | string nullable | |
| qr_code | text | |
| copy_paste | text | |
| provider | string | `fake_pix` |
| provider_charge_id | string nullable | Go charge `id` from `POST /v1/charges`; not exposed on partner JSON |
| provider_tx_id | string nullable | Settlement id from webhook `payment.paid` (`pix_tx_*`); null on create |
| expires_at | timestamptz | |
| paid_at | timestamptz nullable | |
| created_at / updated_at | timestamps | |

### `webhook_events`

| Column | Type | Notes |
|--------|------|-------|
| id | uuid PK | |
| provider | string | |
| event_id | string | |
| type | string | e.g. payment.paid |
| payload | jsonb | raw event |
| payment_id | uuid nullable FK | |
| processed_at | timestamptz nullable | |
| created_at / updated_at | timestamps | |

**Unique:** `(provider, event_id)` — webhook idempotency key.

### `partner_balances`

| Column | Type | Notes |
|--------|------|-------|
| id | uuid PK | |
| partner_id | uuid FK | |
| currency | char(3) | |
| available | bigint | minor units |
| pending | bigint | minor units |
| created_at / updated_at | timestamps | |

**Unique:** `(partner_id, currency)`.

### `balance_ledger`

Append-only movements. Columns: `direction` (`credit`/`debit`), `amount`, `balance_after`, `reference_type`, `reference_id`.

### `fx_quotes`

Rate lock rows: `source_*`, `target_*`, `rate` (string), `expires_at`, `consumed_at`.

### `payouts`

Statuses: `QUEUED` | `PROCESSING` | `COMPLETED` | `FAILED`. Reserve `available → pending` on create; ledger debit on confirm.

### `payment_splits`

Unique `(payment_id, party)`. Parties: `platform`, `seller`, `affiliate`.

### `idempotency_keys`

| Column | Type | Notes |
|--------|------|-------|
| id | uuid PK | |
| partner_id | uuid FK → partners | Unique with `key` |
| key | string(255) | Partner `Idempotency-Key` header |
| resource_id | uuid nullable | Stable payment UUID for retry-safe create; **no FK**. Null for payouts |
| request_hash | string(64) | SHA-256 of the raw body |
| response_code / response_body | int / jsonb nullable | Snapshot after a completed create |
| expires_at | timestamptz | now+24h |

**Unique:** `(partner_id, key)`. Payment create retains the row on throw and resumes `execute` with `resource_id`. Payouts still delete the row on throw.
