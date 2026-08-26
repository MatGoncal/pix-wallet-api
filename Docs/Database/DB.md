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
| provider_tx_id | string nullable | |
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

Statuses: `QUEUED` | `PROCESSING` | `COMPLETED` | `FAILED`. Debit on confirm.

### `payment_splits`

Unique `(payment_id, party)`. Parties: `platform`, `seller`, `affiliate`.
