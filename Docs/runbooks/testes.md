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
```

## Queue worker (webhooks)

```bash
./vendor/bin/sail artisan queue:work redis --tries=3
```

## Tear down

```bash
./vendor/bin/sail down
```
