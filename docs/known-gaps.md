# Known gaps, unverified features, legacy and open decisions

One place for everything that is incomplete, untested against the real world, deprecated, or waiting
for a business decision. Each item was confirmed in the code; nothing here is planned work unless it
says so. Status labels:

* **Unverified** — implemented and covered by automated tests with fakes, never run against the real
  external service.
* **Limitation** — the feature exists but deliberately or currently does less than one might expect.
* **Not implemented** — the capability does not exist (columns or names may suggest otherwise).
* **Legacy** — kept for compatibility or history; do not build on it.
* **Decision** — implemented one way; the business has not confirmed it.

## Payments

| Item | Status | Detail |
|---|---|---|
| Paymob integration | **Unverified** | All Paymob tests use faked HTTP. To confirm with the client's account: the region's API host and Unified Checkout URL (`PAYMOB_BASE_URL`, `PAYMOB_CHECKOUT_URL`); the refund call's authentication (`Authorization: Token <secret>` in the intention flow, `auth_token` in the legacy flow); the exact refund-callback flags (`is_refund` / `is_refunded` / `has_parent_transaction` + `parent_transaction`); `amount_cents` scaling (`PAYMOB_AMOUNT_FACTOR`). Go-live: set sandbox keys → `php artisan payments:paymob-check --ping` → activate Paymob under Payment methods → test pay / decline / refund / expiry → switch `PAYMOB_ENV=production`. |
| ZainCash, Qi Card | **Legacy** | Retired as payment providers; rows kept inactive (`is_legacy`), history readable, legacy receipts can still be verified (`payments.verify`). `settings.zaincash` removed (API returns `null`); `settings.keycard` column kept. |
| Paid Paymob orders are not auto-confirmed | **Decision** (B2) | Staff confirm them. |
| Online payment window | **Decision** (B1) | 60 minutes. |

## Notifications and messaging

| Item | Status | Detail |
|---|---|---|
| Firebase push (FCM) | **Unverified** | Implemented over FCM HTTP v1 without SDK; never sent to a real Firebase project. Default driver is `log`. |
| E-mail delivery | **Unverified here** | Depends on the server's SMTP configuration; tests use the array mailer. |
| Order / payment / shipment / refund notifications | **Not implemented** | Only health reminders and contact replies are pushed; customers poll order endpoints. |
| Mark notifications as read | **Not implemented** | `GET /api/user/notifications` only. |

## Delivery

| Item | Status | Detail |
|---|---|---|
| Courier company APIs | **Not implemented** | Adapters exist for `manual` and `signed_webhook` only. Creating shipments at a company and status polling (`OrderFulfilment::poll`) have no adapter using them. |
| Non-Egyptian zones from old data | **Decision** (D11) | Zones migrated from old delivery prices with non-Egyptian names should be deactivated by an admin. |
| `delivery_prices` table, `GET /api/v1/user/delivary_prices` | **Legacy** | Table kept for reference; the endpoint now returns zones in the old shape. |

## Inventory and purchasing

| Item | Status | Detail |
|---|---|---|
| Bin-level stock | **Limitation** | Warehouse locations can be defined, but balances are per warehouse. |
| Pick lists / per-bin picking | **Not implemented** | `picking` / `packed` are order stages only. |
| Valuation | **Limitation** | Weighted average cost; no FIFO cost layers. (Batch/lot and expiry tracking with FEFO **is** implemented — older parts of `IMPLEMENTATION_REPORT.md` that say otherwise predate migration `2026_11_09_000001_create_inventory_batches`.) |
| Expiry required on receipt | **Limitation** | Off by default (`PHARMACY_REQUIRE_EXPIRY_ON_RECEIPT`), because products are not marked as medicines. |
| Three-way match, supplier payment approval | **Not implemented** | |
| Exact-count stock edit on the product form | **Decision** (D8) | The form applies a difference; exact counts go through stock counts or inventory import. |

## Catalog, customers, loyalty

| Item | Status | Detail |
|---|---|---|
| Social login | **Not implemented** | `users.social_id` / `social_provider` exist and are returned, but no endpoint signs in with a social provider. |
| Verified-purchase reviews | **Not implemented** | Any signed-in customer can review any product. |
| Admin creation/editing of customers | **Not implemented** | Admins can view and (de)activate customers only. |
| Category deletion | **Limitation** | Deleting a category also deletes its sub-categories (FK cascade); products keep existing without a category. |
| `free_delivery` / `voucher` / `custom` loyalty rewards | **Limitation** | Fulfilled by staff by hand; nothing is applied automatically at checkout. |
| Points owed after a refund | **Limitation** | Shortfall is recorded and shown, not offset against future points. |
| Customer cancellation of pending orders | **Decision** (D4) | Off by default. |
| Coupon use released on cancel | **Decision** (D5) | On by default. |
| Coupon discounts delivery | **Decision** (D9) | On by default. |
| Partially refunded orders hidden from the customer | **Decision** (D13) | |

## Dashboard, reports, audit

| Item | Status | Detail |
|---|---|---|
| Dashboard home permissions | **Limitation** | `/dashboard` requires only an admin session, so every admin sees store KPIs including revenue. |
| Report date filter | **Limitation** | Range boundaries are UTC days while daily grouping uses the store's local day. |
| Dashboard language | **Limitation** | English only (customer messages are ar/en). |
| Audit coverage | **Limitation** | Product, category, brand and health-issue changes, stock-count recording/cancellation and admin sign-in are not audited. |

## Testing

| Item | Status | Detail |
|---|---|---|
| Concurrency | **Verified on MySQL only** | 29 multi-process race tests and 2 row-locking tests are skipped on SQLite (31 skipped in a default run). |
| Load testing | **Not done** | No multi-user load test. |

## Code-level legacy and leftovers (safe to know, no behaviour)

| Item | Note |
|---|---|
| `/api/v1/v1/user/...` | Duplicate catalog prefix (the catalog group's own `v1` inside a file mounted at `/api` and `/api/v1`); kept on purpose for apps in the field. |
| `PUT /api/user/{medication}` | Kept for existing apps; numeric ids only. |
| `/api/user/points`, `/points/valid`, `/expired`, `/notvalid` | Legacy loyalty shapes, kept alongside `/api/user/loyalty`. |
| `coupon_user`, `orders.gift_description`, `orders.points_status`, `products.saleable_*`, `products.rate` | Legacy columns (see [database.md §3.9](technical/database.md#39-legacy-and-compatibility-tables--columns)). |
| `media` table | No code path writes to it. |
| `App\Enums\AdminRole`, `Admin::roleEnum()`, `Order::STATUS_FLOW` | Seed-only / deprecated helpers; authorization reads database roles. |
| `routes/api.php` `use` statements for `Contact\ApiContactController`, `Developer\ProjectsController`, `Developer\ProjectExportController`, `ContactMessage\ApiContactMessageController` | Those classes do not exist; the imports are unused (PHP does not resolve them), so they have no effect. |
| Commented-out health routes and OTP code in `UserController` | Dead code kept in the files. |
| `App\Jobs\TestQueueJob`, `tests/*/ExampleTest.php` | Placeholders. |
| `jeroennoten/laravel-adminlte`, `resources/views/layouts/app.blade.php`, `auth/register`, `auth/passwords/email|reset`, `home.blade.php`, `welcome.blade.php` | Skeleton / Laravel UI views; registration and password-reset routes are disabled, `/home` redirects to `/dashboard`. Only `auth/login` and `auth/passwords/confirm` are reachable. |
| `IMPLEMENTATION_REPORT.md`, `MARKETING_REPORT.md` | Historical implementation reports; where they differ from these docs, the code and these docs win. |

## What could not be verified while writing these docs

* Behaviour of the **mobile app** (not in this repository) and which endpoint variants it calls.
* The **live database**: actual role permissions, settings values, zones, payment-method rows and data
  quality may differ from fresh-install defaults.
* **External services**: Paymob, Firebase, SMTP and any courier company.
* **MySQL-specific behaviour**: the concurrency suite was not re-run for this documentation (SQLite run:
  706 passed, 31 skipped, 0 failed).
