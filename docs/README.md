# Documentation

Reference documentation for the Our Pharmacy backend (customer API + admin dashboard). It
describes the code as it is; incomplete, unverified, legacy and undecided items are marked where they
appear and collected in [known-gaps.md](known-gaps.md).

## Start here

| If you want to… | Read |
|---|---|
| Understand the system in 10 minutes | [architecture.md](architecture.md) |
| Set it up, test it, deploy it | [testing-and-deployment.md](testing-and-deployment.md) |
| Know what a feature does | [functional/README.md](functional/README.md) |
| Call the API from the app | [technical/api-reference.md](technical/api-reference.md) |
| Find a table or column | [technical/database.md](technical/database.md) |
| Know which service owns what, jobs, config, integrations | [technical/technical-reference.md](technical/technical-reference.md) |
| Check a business rule | [business-rules.md](business-rules.md) |
| Know who may do what | [permissions-matrix.md](permissions-matrix.md) |
| See how modules affect each other | [module-dependencies.md](module-dependencies.md) |
| Know what is missing or unverified | [known-gaps.md](known-gaps.md) |

## All documents

```
docs/
├── README.md                         this index
├── architecture.md                   system context, stack, request pipeline, module map, design decisions
├── module-dependencies.md            module dependency graph and cross-module side effects
├── business-rules.md                 business rules reference (stock, orders, money, returns, loyalty, suppliers, accounts, health, ...)
├── permissions-matrix.md             role × permission defaults, route → ability table, code-level checks
├── roles-and-permissions.md          RBAC model and protections
├── marketing-promotions.md           promotion engine, promotion types, conflicts, coupons, gifts
├── loyalty.md                        loyalty ledger, settings, redemption, rewards
├── import-export.md                  import & export centre
├── testing-and-deployment.md         setup, tests, deployment, operations, regenerating docs
├── known-gaps.md                     incomplete / unverified / legacy / open decisions
├── functional/
│   ├── README.md                     functional documentation index and module summaries
│   ├── authentication-and-accounts.md
│   ├── catalog.md
│   ├── cart-and-checkout.md
│   ├── orders.md
│   ├── payments-and-paymob.md
│   ├── returns-and-refunds.md
│   ├── delivery.md
│   ├── inventory.md
│   ├── suppliers-and-purchasing.md
│   ├── health-services.md
│   ├── notifications-and-contact.md
│   ├── reports-and-dashboard.md
│   ├── audit-log.md
│   └── settings.md
├── technical/
│   ├── technical-reference.md        services, patterns, scheduler, queue, commands, integrations, configuration, security
│   ├── database.md                   conventions, ER diagrams, full table catalogue (114 tables)
│   └── api-reference.md              every customer API endpoint and webhook
└── tools/                            scripts that regenerate the generated sections
    ├── db-catalogue.php
    ├── role-matrix.php
    └── route-permissions.php
```

Also in the repository root: [`postman/Pharmacy-Mobile-App.postman_collection.json`](../postman/Pharmacy-Mobile-App.postman_collection.json)
(every API request), and two historical implementation reports,
[`IMPLEMENTATION_REPORT.md`](../IMPLEMENTATION_REPORT.md) and
[`MARKETING_REPORT.md`](../MARKETING_REPORT.md). The reports describe how features were built; some of
their "remaining issues" have since been implemented (e.g. batch/expiry tracking). Where they differ
from these documents, the code and these documents are current.

## Keeping the docs in sync

* Code comments point at `docs/business-rules.md`, `docs/loyalty.md`, `docs/marketing-promotions.md`
  and `docs/import-export.md`; keep those file names.
* After adding migrations, permissions or dashboard routes, regenerate the generated sections
  ([testing-and-deployment.md §5](testing-and-deployment.md#5-regenerating-generated-reference-sections)).
* When a behaviour changes, update the module's functional page, the business rules and, for API
  changes, the API reference and the Postman collection.
* Docs last checked against the code on 2026-11-04 (latest migration
  `2026_11_14_000002_drop_emailed_to_from_contact_message_replies`; test run: 706 passed, 31 skipped).
