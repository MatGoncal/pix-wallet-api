# Runbook — testes (Sail only)

Host PHP/Composer **must not** be used.

## Up

```bash
cp .env.example .env   # if needed
./vendor/bin/sail up -d
./vendor/bin/sail artisan key:generate   # once
./vendor/bin/sail artisan migrate
./vendor/bin/sail artisan db:seed
```

## Tests

```bash
./vendor/bin/sail pest
./vendor/bin/sail pint --test
./vendor/bin/sail php vendor/bin/phpstan analyse
```

### Suites

| Suite | Base case | Database | Queue |
|-------|-----------|----------|-------|
| `Feature` | `Tests\TestCase` | `RefreshDatabase` (transaction per test) | `sync` |
| `Concurrency` | `Tests\ConcurrencyTestCase` | `DatabaseTruncation` | `redis`, flushed per test on `REDIS_DB=15` |

`Feature` wraps every test in a transaction that is rolled back, so row locks,
commit ordering and anything a queue worker would observe are invisible to it.
Tests about those live in `Concurrency`, which truncates instead and runs its
workers in forked processes via `runConcurrently()`:

```bash
./vendor/bin/sail pest --testsuite=Concurrency
```

That suite needs `pcntl` and `posix`; it skips itself when they are missing.

## Queue worker (webhooks)

```bash
./vendor/bin/sail artisan queue:work redis --tries=3
```

## Tear down

```bash
./vendor/bin/sail down
```
