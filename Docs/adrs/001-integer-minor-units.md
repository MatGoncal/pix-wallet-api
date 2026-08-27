# ADR 001 — Integer minor units

## Status

Accepted

## Context

Payment amounts cannot be represented safely as IEEE-754 floats. Rounding
errors on BRL/USD cents would silently drift the ledger.

## Decision

Every money field in the database, HTTP contract, and logs is an **integer
minor unit** (`bigint` / Prisma `BigInt`). Currency is a separate `char(3)`.
FX `rate` is a decimal **string**, never a float. JSON responses serialize
amounts as integers.

## Consequences

- No `float`/`double`/`number` decimals on money paths.
- Callers format for display; the API never rounds.
- Tests assert integer types on quoted and settled amounts.
