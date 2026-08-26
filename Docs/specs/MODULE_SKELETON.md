# MODULE_SKELETON — pix-wallet-api

Canonical layout for new domain modules. See also
`.cursor/skills/laravel-payment-module/SKILL.md`.

```
app/
├── Enums/<Name>Enum.php
├── Http/
│   ├── Controllers/Api/V1/<Name>Controller.php
│   ├── Middleware/…
│   └── Requests/<Action><Name>Request.php
├── Jobs/<Verb><Name>Job.php
├── Models/<Name>.php
└── Services/
    ├── <Name>Service.php
    └── <Provider>Client.php   # e.g. FakePixProvider
database/migrations/xxxx_create_<table>.php
tests/Feature/<Name>/…
Docs/modulos/<module>.md
Docs/specs/fase-N-<name>.md
```

## Rules

1. Controllers stay thin — orchestration in `*Service`.
2. External provider I/O only through a client class (fake in local/demo).
3. Status fields use backed string Enums matching `API_CONTRACT.md`.
4. Unique constraints for idempotency keys live in the database, not only in code.
5. Money columns: `bigInteger` minor units + `string(3)` currency.
