# Technical reference

Services, background processing, integrations, configuration and security of the backend. For the
schema see [database.md](database.md); for endpoints see [api-reference.md](api-reference.md); for the
big picture see [architecture.md](../architecture.md).

## 1. Code layout

```
app/
  Console/Commands/     artisan commands (scheduler tasks, checks, tools)
  Enums/                OrderStatus, PaymentStatus, ShipmentStatus, AdminRole, Permission, Gender, BloodSugarCondition
  Http/Controllers/     HomeController (dashboard home), Auth/* (admin login, Laravel UI)
  Http/Middleware/      AssignRequestId, EnsureAccountIsActive, SanitizeListingParameters, SetLocaleLang
  Jobs/                 queued jobs (imports, exports, reminders, contact replies)
  Mail/                 SendOtp, PasswordChanged (queued, encrypted)
  Models/               Eloquent models (one per table, ~100)
  Modules/<Module>/     feature modules: controllers (Api* = customer API, others = dashboard),
                        Services/, Requests/, Resources/, Repositories/
  Notifications/        HealthReminder, ContactMessageReplied, Push/* (push channel + gateways)
  Providers/            AppServiceProvider (gates, push binding, sidebar counts), PromotionsServiceProvider
  Support/              Money, StoreClock, DocumentNumber, PrivateFiles, MarketingImage, TableColumns
  Traits/               Translatable
config/pharmacy.php     store-specific settings (see §6)
config/promotions.php   promotion-engine invariants
routes/api.php          customer API + webhooks (mounted at /api and /api/v1)
routes/web.php          dashboard (session, permissions), payment return, signed files
routes/console.php      scheduler
resources/views/        Blade dashboard (layout in admin/, Tabler assets in public/assets/libs), e-mails
lang/{ar,en}/           customer-facing messages (cart, contact, delivery, health, orders, payments, points, promotions, returns)
```

Older modules follow a Controller → Service → Repository pattern (`app/Modules/Shared/Repositories/BaseRepository`,
`BaseRequest` for listing criteria). Newer domains (orders, payments, inventory, loyalty, promotions,
delivery, data transfer) put the business rules in services and call Eloquent directly. A scaffolding
command exists: `php artisan make:module {name}`.

## 2. Core services

| Service | Responsibility |
|---|---|
| `Orders\Services\CheckoutService` | Cart → order in one transaction (locks customer, cart, sizes in id order; prices with the engine; creates order, lines, gift lines, promotion snapshots, usage reservations, stock reservations, shipment, payment, earned-points lot; empties the cart). Calls Paymob **after** commit. Deadlock retry ×3. |
| `Orders\Services\OrderWorkflow` | The only code that changes `orders.status`. Validates the transition graph, refuses preparing an unpaid online order, fulfils stock on dispatch, restores stock / cancels shipments / reverses points / cancels payments / releases promotions on cancel, records COD collection on completion, writes `order_status_histories`. |
| `Orders\Services\ReturnDecisionService` | Accept/reject return requests under row locks: approved amount caps, per-line received quantities, promotion refund policy, quarantine, loyalty reversal (D2), refund request. |
| `Promotions\Engine\PromotionEngine` | Every price, discount, gift and coupon calculation (catalog price, cart, coupon preview, delivery-quote subtotal, checkout, dashboard simulation). Helpers: `EligibilityChecker`, `PromotionCatalog`, `TargetMatcher`, strategies per type, `PricingResult`. |
| `Promotions\Services\*` | `CartPricingService` (price a cart + delivery), `CartPromotionSync` (gift lines), `PromotionLifecycle` (reserve / consume / release uses), `PromotionRefundPolicy`, `PromotionValidator`, `PromotionAdminService`, `PromotionSimulator`, `CouponEvaluator`. |
| `Payments\Services\PaymentService` | Payment rows per attempt, provider initiation, notification handling (store → claim → verify → apply), status transitions + history, order projection, COD collection, manual-receipt verification, expiry of stale online payments, event recovery. |
| `Payments\Services\RefundService` | Refund requests (return, payment, provider-side), processing (gateway / cash / manual), reconciliation of unknown refunds, manual resolution, cancellation, order refund projection. Dual control. |
| `Payments\Services\CodSettlementService` | Settles courier cash for several payments with the amount actually received (difference recorded). |
| `Payments\Gateways\PaymobGateway`, `CodGateway`, `GatewayRegistry` | Provider adapters (`PaymentGateway` interface). |
| `Delivery\Services\DeliveryQuoteService` | Address → city/area → zone → effective rate (highest priority) → fee / free-over / minimum. |
| `Delivery\Services\OrderFulfilment` | Shipments: create at checkout, assign provider, record events (dashboard, webhook, poll) idempotently, map provider statuses, move the order through `OrderWorkflow`, COD on delivery, returned goods to quarantine, settle shipment cash. |
| `Delivery\Providers\*` | `ManualAdapter`, `SignedWebhookAdapter`, `ProviderRegistry` (adapter keys `manual`, `signed_webhook`). |
| `Inventory\Services\InventoryService` | The only code that changes stock: buckets per warehouse and batch, immutable movements with idempotency keys, FEFO allocation, reservations, fulfilment, cancellation, returns, adjustments, damage, write-off, quarantine inspection, batch expiry, projections. |
| `Inventory\Services\PurchasingService` | Purchase orders, receipts (lot/expiry, damaged units, weighted average cost), supplier returns, supplier payments (invoice / on account / advance). |
| `Inventory\Services\TransferService`, `StockCountService`, `InventoryIntegrity` | Transfers between warehouses; stock counts; invariant checks. |
| `Points\Services\LoyaltyLedger`, `LoyaltyService`, `RewardRedemptionService`, `LoyaltySettings` | Points ledger with lots and allocations, earning/activation/expiry/reversal, checkout redemption, rewards. |
| `HealthService\Services\HealthService`, `HealthReminderService`, `VaccineScheduleService` | Calculations, reminder queueing and recovery, vaccine schedule generation. |
| `DataTransfer\ImportManager`, `ExportManager`, `TemplateBuilder`, `EntityRegistry`, `Entities\*`, `Support\Spreadsheet`, `Support\ImageFetcher` | Import & export centre. |
| `Admin\Authorization\PermissionRegistry`, `RbacSynchronizer`; `Admin\Services\RoleService`, `AdminAccountService` | Permissions and roles. |
| `ContactMessage\Services\ContactMessageService` | Conversations and replies. |
| `Reports\Revenue` | Shared revenue rules and local-day SQL. |

## 3. Cross-cutting patterns

### 3.1 Transactions and lock order

Every money/stock path takes row locks in the same order, so concurrent work waits instead of
deadlocking: **order → product sizes (id order) → stock rows → shipments → payments → refunds**;
checkout locks **customer → cart rows → sizes (id order)**; loyalty locks the **customer** row, then the
reward. Outer transactions use Laravel's deadlock retry (`DB::transaction(fn, 3)`). HTTP calls to
providers (Paymob, FCM, delivery) are never made inside a transaction.

Concurrency is verified by real multi-process race tests on MySQL (`tests/Feature/Concurrency`,
worker script `tests/Concurrency/worker.php`); they are skipped on SQLite.

### 3.2 Idempotency

| Event | Key |
|---|---|
| Stock movement | `"{business key}:{bucket}"` unique in `stock_movements.idempotency_key` |
| Loyalty entry | `earned:order:{id}`, `reversed:order:{id}`, `refunded:loyalty_point_reversal:{id}`, ... unique in `loyalty_ledger.idempotency_key` |
| Reward redemption | `Idempotency-Key` header → `loyalty_redemptions.idempotency_key` |
| Payment provider statement | `payment_events.idempotency_key` (claim with `claim()`; stale after 5 minutes; max 10 attempts) |
| Shipment event | `shipment_events.idempotency_key` (provider `event_id`, or a system key) |
| Supplier payment form | `supplier_payments.request_key` (one-time form key) |
| Loyalty adjustment form | `request_key` in the entry metadata |
| Reminder | `notification_deliveries (type, reference_type, reference_id, occurrence)` + claim |
| Contact reply delivery | `contact_message_replies.sent_at` claim |

### 3.3 Money, time and numbers

`App\Support\Money` (integer minor units, half-up rounding), `App\Support\StoreClock` (store wall
clock for promotion/coupon/flash-sale/rate dates and "today" in health and medication features),
`App\Support\DocumentNumber` (readable references). See [database.md §1](database.md#1-conventions).

### 3.4 Snapshots

Orders keep what was bought and how it was priced even if the catalog changes: `shipping_address`,
`pricing_snapshot`, the discount columns, `order_items.product_name_ar/en`, `size_label`,
`price_at_time`, `original_price`, `promotion_snapshot`, and `order_promotions` rows.

### 3.5 Localization

Customer messages use `api_trans()` / `api_locale()` helpers (`GeneralHelper.php`) and
`lang/{ar,en}/*.php`; the locale comes from `Accept-Language` where `SetLocaleLang` is applied. Some
legacy fields are always Arabic for compatibility (`status_meaning`, blood-pressure `evaluation`,
`display_id`). The dashboard is English only.

## 4. Background processing

### 4.1 Scheduler (`routes/console.php`)

Run `php artisan schedule:run` every minute (cron) on every server. Each task runs on one server only
(`onOneServer`, needs a shared cache store: `CACHE_STORE=database` or `redis`), never overlaps itself
(`withoutOverlapping(n minutes)`), and is safe to run twice.

| Command | Schedule (store time where noted) | What it does |
|---|---|---|
| `sanctum:prune-expired --hours=24` | daily | Deletes expired API tokens |
| `health:send-reminders --vaccines` | every 15 min | From the Health settings send time (default 09:00 store time): queues vaccine reminders (window = reminder lead days) |
| `health:send-reminders --medications` | every 5 min | Queues medication dose reminders; recovers stale ones |
| `loyalty:record-expirations` | daily 02:00 | Activates due lots, writes `expired` entries for lapsed lots, expires uncollected reward redemptions |
| `payments:recover-events` | every 5 min | Re-applies failed / stuck provider events; reports stale ones |
| `payments:reconcile-refunds` | every 15 min | Flags gateway refunds stuck in `processing` for 30 min as `unknown`; reconciles unknown/processing refunds with Paymob |
| `payments:expire-stale` | every 5 min | Online payments past their window: checked with Paymob, then expired; an order left without a payment is canceled (stock released). Without an API key a grace period waits for a late webhook. |
| `inventory:expire-batches` | daily 00:15 | Unreserved units of expired batches leave sellable stock (to damaged, `expiry` movement) |
| `inventory:verify` | daily 03:00 | Read-only invariant check (balances = ledger = reservations = catalog stock); problems are logged |

### 4.2 Queue

`QUEUE_CONNECTION=database` by default (`jobs`, `failed_jobs` tables). Run a worker
(`php artisan queue:work`). Without a worker: OTP and password-changed e-mails, health reminders and
large imports/exports never run.

| Job / queued class | Trigger | Notes |
|---|---|---|
| `App\Mail\SendOtp`, `App\Mail\PasswordChanged` | register, resend, forgot, e-mail change, unproven password change | `ShouldQueue` + `ShouldBeEncrypted` (codes are not stored in clear in `jobs`) |
| `SendHealthReminder` | `health:send-reminders` | Claims the delivery, re-checks it is still due, sends database + push notification; `tries = MAX_ATTEMPTS`, backoff 60 s / 300 s |
| `ValidateImport`, `ProcessImport`, `RunExport` | dashboard import/export above the sync thresholds | `tries = 1`, timeout 3600 s (not retried automatically) |
| `DeliverContactReply` | admin replies to a contact message | Dispatched **after the response** (runs without a worker); writes database notification + push; result on the reply row |
| `TestQueueJob` | none | Empty placeholder job; not referenced anywhere |

### 4.3 Artisan commands (manual)

| Command | Purpose |
|---|---|
| `permissions:sync` | Copy `PermissionRegistry` into `permissions` and ensure system roles exist (run after each deploy) |
| `payments:paymob-check [--ping]` | Validate Paymob configuration without printing secrets; `--ping` authenticates with the API key; prints the callback URL to register |
| `files:make-private [--delete-public] [--dry-run]` | Move sensitive uploads (receipts, return images, medication images) from the public disk to the private disk |
| `make:module {name}` | Scaffold a module folder (Services, Repositories, Enums, Requests, Resources) |
| all scheduled commands above | can be run by hand |

## 5. Integrations

### 5.1 Paymob (online payments)

* **Flows** (`PAYMOB_FLOW`): `intention` (default: `POST {base}/v1/intention/` with `Authorization: Token <secret key>`, customer pays on Unified Checkout `{checkout_url}?publicKey=..&clientSecret=..`) or `legacy` (auth token → order → payment key → iframe).
* **Callbacks**: HMAC-SHA512 with the HMAC secret over Paymob's documented, ordered transaction fields, compared with the `hmac` query parameter. Both the POST webhook and the GET browser return are verified; the webhook is the source of truth.
* **Server-side confirmation** (`PAYMOB_VERIFY_WITH_API=true`): `GET /api/acceptance/transactions/{id}` before acting; a statement the API does not confirm is `rejected`. Inquiry by Paymob order (`/api/ecommerce/orders/transaction_inquiry`) when no transaction id reached us.
* **Refunds**: `POST /api/acceptance/void_refund/refund {transaction_id, amount_cents}`; refund/void callbacks and refunds made in Paymob's own dashboard are recorded.
* **Amounts**: `amount_cents = minor units × PAYMOB_AMOUNT_FACTOR` (1 for EGP). The amount, currency and our reference in every statement must match the payment (`mismatch` → rejected + `metadata.needs_review`).
* **Duplicate captures**: a second successful payment for an already-paid order is recorded, flagged and refunded in full automatically. A payment that succeeds after its order was canceled is recorded and refunded.
* **Environments**: `PAYMOB_ENV=sandbox|production` picks `PAYMOB_SANDBOX_*` or `PAYMOB_LIVE_*`. Credentials live only in the environment (never in the database or API). Paymob is not offered at checkout until `missingConfiguration()` is empty.
* **Status: implemented and tested with faked HTTP only; not yet verified against a real Paymob account** (see [known-gaps.md](../known-gaps.md)).

### 5.2 Push notifications (Firebase Cloud Messaging)

`PUSH_DRIVER=log` (default: messages are written to the log) or `fcm`
(`FcmPushGateway`: FCM HTTP v1, service-account JSON at `FCM_CREDENTIALS`, signed JWT → OAuth token
cached 55 min; tokens Firebase reports unregistered are deleted). `PushChannel` sends a
notification's `toPush()` to every device of the user. Used by `HealthReminder` and
`ContactMessageReplied`. **FCM has not been verified against a real Firebase project.**

### 5.3 E-mail

Laravel Mail (`MAIL_*`). Messages: OTP code (`User/auth/emails/otp.blade.php`) and password-changed
notice. Queued and encrypted.

### 5.4 Delivery companies

Provider records (`delivery_providers`) choose an adapter: `manual` (staff update the shipment) or
`signed_webhook` (the company POSTs signed status updates; statuses mapped by
`provider_status_maps`). A company with an ordering/status API needs its own adapter class
implementing `DeliveryProviderAdapter` (`createShipment`, `cancelShipment`, `fetchStatus`,
`verifyWebhook`, `parseWebhook`) and one line in `ProviderRegistry::ADAPTERS`. No such adapter
exists yet.

### 5.5 Spreadsheets and images

OpenSpout (`openspout/openspout`) streams CSV/XLSX for imports and exports. Product image URLs in
imports are fetched by `ImageFetcher` (http/https only, public addresses only, no redirects, pinned
connection, ≤ 5 MB, JPG/PNG/WebP by content).

## 6. Configuration reference

### 6.1 `config/pharmacy.php`

| Key | Env | Default | Meaning |
|---|---|---|---|
| `name` | `PHARMACY_NAME` | `Our Pharmacy` | Dashboard name |
| `country`, `currency` | — (fixed) | `EG`, `EGP` | Egypt-only store |
| `low_stock_threshold` | `PHARMACY_LOW_STOCK_THRESHOLD` | 5 | Fallback for `settings.low_stock_threshold` |
| `timezone` | `PHARMACY_TIMEZONE` | `Africa/Cairo` | Store clock |
| `require_verified_login` | `REQUIRE_VERIFIED_LOGIN` | false | Refuse login of unverified customers |
| `refund_dual_control` | `PHARMACY_REFUND_DUAL_CONTROL` | true | Requester cannot pay out their own refund |
| `points_redeem_requires_completion` | `PHARMACY_POINTS_REDEEM_REQUIRES_COMPLETION` | true | Initial value of loyalty activation when the loyalty migration runs |
| `supplier_advance_payments` | `PHARMACY_SUPPLIER_ADVANCE_PAYMENTS` | true | Allow ticked advance payments to suppliers |
| `min_shelf_life_days` | `PHARMACY_MIN_SHELF_LIFE_DAYS` | 0 | Units of a batch expiring within N days are not sold |
| `near_expiry_days` | `PHARMACY_NEAR_EXPIRY_DAYS` | 90 | Near-expiry reporting window |
| `require_expiry_on_receipt` | `PHARMACY_REQUIRE_EXPIRY_ON_RECEIPT` | false | Refuse receipts without an expiry date |
| `data_transfer.sync_max_bytes` | `PHARMACY_IMPORT_SYNC_MAX_BYTES` | 524 288 | Larger imports run on the queue |
| `data_transfer.sync_max_rows` | `PHARMACY_EXPORT_SYNC_MAX_ROWS` | 5 000 | Larger exports run on the queue |

### 6.2 Other application variables

| Env | Default | Meaning |
|---|---|---|
| `API_RATE_LIMIT` | 60 | Requests per minute per user/IP on the API |
| `SANCTUM_EXPIRATION` | 43 200 | Token lifetime in minutes (30 days) |
| `PASSWORD_CHANGE_REQUIRES_CURRENT` | false | Require `current_password` (or OTP) for signed-in password and e-mail changes |
| `PUSH_DRIVER`, `FCM_CREDENTIALS` | `log`, — | Push notifications |
| `PAYMOB_*` | see `.env.example` | Paymob (`PAYMOB_ENV`, `PAYMOB_FLOW`, `PAYMOB_BASE_URL`, `PAYMOB_CHECKOUT_URL`, `PAYMOB_CURRENCY`, `PAYMOB_AMOUNT_FACTOR`, `PAYMOB_PAYMENT_TTL_MINUTES` (60), `PAYMOB_VERIFY_WITH_API` (true), `PAYMOB_RETURN_URL`, `PAYMOB_HTTP_TIMEOUT` (20), and per environment `API_KEY`, `SECRET_KEY`, `PUBLIC_KEY`, `HMAC_SECRET`, `INTEGRATION_IDS`, `IFRAME_ID`, `MERCHANT_ID`) |
| `Admin_Email`, `Admin_Password` | — | Used once by `AdminSeeder` to create the first super admin |

### 6.3 Store settings in the database (`settings` row, dashboard *Settings* and *Loyalty › Settings*)

Contact and social links, phone, map URL, privacy policy and terms (ar/en), `low_stock_threshold`,
`return_window_days`, `customer_can_cancel_pending` (off), `coupon_applies_to_delivery` (on),
`coupon_release_on_cancel` (on), `vaccine_reminders_enabled` (on), `medication_reminders_enabled`
(off), the loyalty settings (`loyalty_*`, `spend_x`, `get_y`, `points_validity_days`,
`points_never_expire`) and `health_settings` (JSON, read through `HealthSettings`: tools on/off,
disclaimer, guideline names, BMI ideal range, ovulation ranges / luteal phase / phase chances,
vaccine reminder time and default lead days, medication reminder window — edited on *Health content ›
Health settings*). Every save is audited with before/after values.

Also data-driven: `health_result_rules` (BMI / blood sugar / blood pressure categories, thresholds
and messages, `HealthEvaluator`). All other health texts come from `lang/{ar,en}/health.php`.

### 6.4 `config/promotions.php`

`flash_sale_default_minutes` (60), `specificity` (variant 4 > product 3 > brand 2 > category 1),
`max_gift_units_per_reward` (100).

## 7. Security measures

| Area | Measure |
|---|---|
| Customer auth | Sanctum tokens with expiry; daily pruning; logout revokes all tokens; password reset revokes all tokens; e-mail change and signed-in password change revoke other tokens. |
| OTP | 6 digits from `random_int`, stored **hashed**, valid 10 minutes, ≤ 5 guesses (atomic conditional UPDATE, then destroyed), ≤ 5 codes per account per hour, responses never reveal whether an e-mail exists. |
| Brute force | `throttle:auth` (per e-mail+IP and per IP) on every credential endpoint; `throttle:api` everywhere. |
| Deactivation | Customers: `EnsureAccountIsActive` → 403 on every token request, tokens deleted when deactivated. Admins: cannot sign in; signed out on next request. |
| Admin authorization | Database RBAC, one Gate per permission, every dashboard route behind `can:`, escalation protections in services, audit log. |
| Object authorization | Every customer query scoped by `user_id`; cart sizes must belong to the product; checkout address must be the caller's (IDOR sweep test). |
| Mass assignment | `Admin::role` is not fillable; profile update writes only name/e-mail/phone; verification and OTP state are server-controlled. |
| Prices | Never accepted from the app; computed by the engine at checkout under locks; optional `expected_total` guard. |
| Private files | Payment receipts, return images and medication images are stored on the private `local` disk (`storage/app/private`) and served only through owner-scoped API routes, permission-checked dashboard routes, or signed links that expire after 60 minutes. Laravel's `/storage/{path}` route for the private disk also requires a valid signature. |
| Uploads | Image MIME/size validation; banner and opening-ad images stored under a random name with an extension taken from the detected content type, never from the client (`MarketingImage`); JPEG/PNG/WebP only, no SVG. |
| Webhooks | HMAC verification (Paymob SHA-512, delivery SHA-256), optional API confirmation, amount/currency/reference matching, idempotent processing, rate limits. |
| Import safety | Formula-injection prefixing (`'`) on export of values starting with `= + - @`; image fetcher SSRF protections; per-entity permissions; sensitive exports flagged in the audit log and limited to approved columns. |
| Health data | Separate permission (`health_data.view`), every view audited, never part of exports. |
| Logging | `X-Request-Id` on every response and log line; payment errors logged without secrets; `payments:paymob-check` never prints credentials. |
| Debug | The former `/test-json` debug endpoint was removed. `.env.example` ships with `APP_DEBUG=false`. |
