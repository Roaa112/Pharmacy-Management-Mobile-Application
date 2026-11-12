# Testing and deployment guide

## 1. Requirements

| Component | Version / note |
|---|---|
| PHP | ^8.2 (8.4 used in development) with `pdo_mysql`, `pdo_sqlite` (tests), `openssl`, `mbstring`, `fileinfo`, `curl`, `zip` |
| Composer | 2.x |
| Database | MySQL 8 / MariaDB (production). SQLite for the default test run. |
| Node | Only for the Vite build of the Laravel UI layout (`layouts/app`); the dashboard uses self-hosted assets in `public/assets` and needs no build. |
| Cron | `* * * * * php artisan schedule:run` |
| Queue worker | `php artisan queue:work` (supervised) |
| Shared cache | `CACHE_STORE=database` or `redis` on every server (scheduler `onOneServer` locks) |

## 2. Local setup

```bash
composer install
cp .env.example .env          # then set DB_*, MAIL_*, Admin_Email, Admin_Password
php artisan key:generate
php artisan migrate            # creates schema, permissions, system roles, Egypt, payment methods, MAIN warehouse, health content
php artisan db:seed            # cities, areas, first super admin, settings, delivery zones; demo data outside production
php artisan storage:link       # public disk (marketing images, pregnancy images, some product images)
php artisan serve              # or Laravel Herd
php artisan queue:work         # e-mails, reminders, large imports/exports
php artisan schedule:work      # scheduler in development
```

`composer run dev` starts the server, a queue listener, log tailing (`pail`) and Vite together.

Sign in at `/login` with `Admin_Email` / `Admin_Password` (the seeder creates that account only when
both are set and no admin with that e-mail exists; it never resets a password).

**Demo data** (`DemoSeeder`, never in production, only once per database): catalog, promotions,
customers, orders, marketing, returns, inventory, payments, loyalty, health and medication data.
`php artisan migrate:fresh --seed` (base + demo) or `php artisan db:seed --class=DemoSeeder`.
`migrate:fresh` drops every table — never run it against a real database.

## 3. Running the tests

```bash
php artisan test                         # whole suite (phpunit.xml: SQLite in memory, array cache/mail/session, sync queue)
php artisan test --filter=CheckoutTest   # one class
php artisan test tests/Feature/Payments  # one folder
```

On the development machine used for these docs the default `php` lacks `pdo_sqlite`; run with
Herd's PHP: `~/.config/herd/bin/php84/php.exe artisan test`.

### 3.1 Latest run (for this documentation, SQLite)

```
Tests: 31 skipped, 706 passed (4898 assertions)
Duration: ~78 s
```

The 31 skipped tests are MySQL-only by design: `Concurrency/AdversarialConcurrencyTest` (10),
`ConcurrencyTest` (12), `HardeningConcurrencyTest` (7) and `Database/RowLockingTest` (2).

### 3.2 MySQL and concurrency tests

The race tests start separate PHP processes (`tests/Concurrency/worker.php`) that hit the same rows at
the same instant with real commits. They need MySQL/MariaDB:

```bash
DB_CONNECTION=mysql DB_DATABASE=<throwaway_test_db> php artisan test
CONCURRENCY_EVIDENCE=storage/logs/races.jsonl DB_CONNECTION=mysql DB_DATABASE=<throwaway_test_db> php artisan test tests/Feature/Concurrency
```

Use a **dedicated, disposable database**: the tests use `RefreshDatabase` and wipe it. Each race
re-migrates (~40 s per test; the full MySQL suite takes ~20+ minutes). Do not edit application code
while a race run is in progress (workers boot the app fresh).

### 3.3 Test inventory (76 files)

| Area | Files |
|---|---|
| Admin dashboard | `Admin/AdminPagesTest`, `ContactMessageRepliesTest`, `SchedulerTest` |
| Customer API | `Api/AccountApiTest`, `AddressApiTest`, `CartLineUniquenessTest`, `CatalogApiTest`, `FavoritesAndReviewsApiTest`, `HealthDataApiTest`, `MedicationApiTest`, `PointsAndOrderReadsApiTest`, `RequestIdTest`, `RouteProtectionTest` |
| Catalog | `Catalog/CatalogQueryCountTest`, `CatalogSmokeTest` |
| Concurrency (MySQL) | `Concurrency/*`, `Database/RowLockingTest` |
| Import/export | `DataTransfer/ImportExportTest` |
| Database | `Database/EgyptOnlyMigrationTest`, `MigrationBackfillTest`, `SeederTest` |
| Delivery | `Delivery/DeliveryPricingTest`, `DeliveryWebhookTest`, `ProviderAbstractionTest`, `ShipmentLifecycleTest` |
| Health | `Health/HealthCalculationsTest`, `HealthContentTest`, `HealthRemindersTest`, `ReminderDeliveryTest` |
| Inventory | `Inventory/BatchExpiryTest`, `InventoryAuditTest`, `InventoryDomainTest`, `OutOfStockTest`, `StockLedgerTest`, `SupplierPaymentTest` |
| Loyalty | `Loyalty/LoyaltyProgrammeTest`, `Orders/LoyaltyLedgerTest` |
| Marketing / pricing | `Marketing/*` (banners, Buy X Get Y, parity, coupons, dashboard, conflicts, returns, Spend X Get Y), `Pricing/CouponsGiftsAndPromotionsTest`, `FlashSalePricingTest`, `Unit/PromotionEngineUnitTest` |
| Orders | `Orders/CheckoutTest`, `FinancialTest`, `OrderPoliciesTest`, `OrderStateMachineAuditTest`, `OrderStatusTest`, `PaymentsAndFilesTest`, `PointsAndHistoryTest`, `RemediationRulesTest`, `ZainCashRetirementTest` |
| Payments | `Payments/CodAndOrderLifecycleTest`, `PaymentIntegrityAuditTest`, `PaymentReliabilityTest`, `PaymobTest` |
| Returns | `Returns/ReturnQuarantineAndPointsTest` |
| Security | `Security/AccountActivationTest`, `AdminPermissionsTest`, `AdminRoutesTest`, `AuthHardeningTest`, `AuthenticationTest`, `IdorSweepTest`, `ObjectAuthorizationTest`, `PasswordChangeTest`, `RolesAndPermissionsTest` |
| Unit | `Unit/MoneyTest`, `PromotionEngineUnitTest` |

Shared builders: `tests/Concerns/BuildsShop.php`, `BuildsPromotions.php`, `RunsRaces.php`.
External HTTP (Paymob, FCM) is always faked in tests.

### 3.4 Manual test checklist before a release

* Register → verify (code e-mail arrives) → login → profile update.
* Add to cart (with a promotion and a gift) → coupon preview → checkout COD → dashboard: confirm → picking →
  packed → shipment events → delivered → order completed, COD collected → settle COD.
* Paymob sandbox: pay, decline, abandon (expiry after the window), refund, refund from Paymob's dashboard.
* Return request with images → accept with quantities → quarantine visible → refund processed by a
  second admin → points reversed.
* Import a product file in "update" mode from a fresh export (should change nothing).
* Health reminders with `PUSH_DRIVER=fcm` on a test device.

## 4. Deployment

### 4.1 Environment

Start from `.env.example` and set at least: `APP_KEY`, `APP_URL`, `APP_ENV=production`,
`APP_DEBUG=false`, `DB_*`, `MAIL_*`, `QUEUE_CONNECTION=database` (or redis), `CACHE_STORE=database` (or
redis), `SANCTUM_EXPIRATION`, `API_RATE_LIMIT`, `PHARMACY_*`, `PUSH_DRIVER` / `FCM_CREDENTIALS`,
`PAYMOB_*` (empty keeps Paymob unavailable). Consider `PASSWORD_CHANGE_REQUIRES_CURRENT=true` and
`REQUIRE_VERIFIED_LOGIN=true` once the app supports them. Full list:
[technical-reference.md §6](technical/technical-reference.md#6-configuration-reference).

### 4.2 Release steps

```bash
php artisan down                      # optional maintenance window
git pull                              # or deploy the build artifact
composer install --no-dev --optimize-autoloader
php artisan migrate --force           # back up the database first
php artisan permissions:sync          # new permissions reach the system roles that should have them
php artisan config:cache && php artisan route:cache && php artisan view:cache
php artisan storage:link              # first deploy only
php artisan queue:restart             # workers pick up the new code
php artisan up
```

After the first deploy on an existing installation: `php artisan files:make-private --dry-run`, then
without `--dry-run` (and later `--delete-public`) to move old receipts / return images / medication
images to the private disk.

### 4.3 Processes

* **Cron**: `* * * * * cd /path && php artisan schedule:run >> /dev/null 2>&1` on every server (tasks
  run once thanks to `onOneServer`).
* **Queue worker** under a supervisor (e.g. `php artisan queue:work --tries=3 --timeout=3700`; import /
  export jobs set their own timeout of 3600 s, so the worker timeout must be larger, and
  `DB_QUEUE_RETRY_AFTER` must exceed the longest job).

### 4.4 Files to persist and back up

| Location | Content |
|---|---|
| `storage/app/private` | Payment receipts, return images, medication images, import/export files and reports |
| `storage/app/public` (served via `public/storage`) | Banner and opening-ad images (`marketing/`), pregnancy images (`pregnancy/`), some product images |
| `public/products`, `public/category`, `public/subcategory`, `public/brands`, `public/healthissue` | Catalog images written directly under `public/` by the older upload code |
| `storage/logs` | Logs (with `request_id`) |

### 4.5 Integrations go-live

* **Paymob**: set `PAYMOB_ENV=sandbox` keys → `php artisan payments:paymob-check --ping` → register the
  printed callback URL (`/api/webhooks/payments/paymob`) in Paymob → activate the Paymob method under
  *Payments › Payment methods* → test → switch to `production` keys. See
  [payments-and-paymob.md](functional/payments-and-paymob.md).
* **Push**: `PUSH_DRIVER=fcm`, `FCM_CREDENTIALS=/secure/path/service-account.json`.
* **Delivery company**: create the provider with adapter `signed_webhook`, a webhook secret and status
  maps; give the company `POST /api/webhooks/delivery/{code}` and the signature rule.

### 4.6 Post-deploy checks

* `GET /up` → 200.
* `php artisan inventory:verify` → no problems.
* `php artisan payments:paymob-check` → no missing keys (if Paymob is used).
* Dashboard › Payments › Payment events: no new `rejected` / stale events.
* `php artisan schedule:list` shows the nine tasks.

## 5. Regenerating generated reference sections

Parts of these docs were generated from the running code. The generator scripts are in
[`docs/tools`](tools/). Re-run them after migrations, permission or route changes:

```bash
# 1. Build a scratch SQLite database from the migrations (never point this at a real database).
touch /tmp/schema.sqlite
DB_CONNECTION=sqlite DB_DATABASE=/tmp/schema.sqlite CACHE_STORE=array php artisan migrate --force

# 2. Table catalogue for docs/technical/database.md §4
php docs/tools/db-catalogue.php /tmp/schema.sqlite > /tmp/db_tables.md

# 3. Default role × permission matrix for docs/permissions-matrix.md §2
php docs/tools/role-matrix.php /tmp/schema.sqlite > /tmp/matrix.md

# 4. Dashboard route → ability table for docs/permissions-matrix.md §3
DB_CONNECTION=sqlite DB_DATABASE=/tmp/schema.sqlite php artisan route:list --json > /tmp/routes.json
php docs/tools/route-permissions.php /tmp/routes.json > /tmp/dash_routes.md
```

Paste each output over the corresponding section.
