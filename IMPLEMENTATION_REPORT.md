# Implementation report: e-commerce management system

Scope: the full transformation brief (payments, inventory, purchasing, delivery, order lifecycle,
security, database integrity, dashboard, tests), built on top of the earlier audit implementation
(P0–P2 findings, summarised in section 2 and kept verbatim in Appendix A).

Nothing was committed, pushed or deployed. No production data was touched. Tests ran on in-memory
SQLite and on a separate, throwaway local MySQL 8 database (`pharmacy_impl_check`). The local
development database `Pharmacy-Management` was not migrated or modified.

**Status: functionally complete and tested, not production-ready yet.** Before going live:
Paymob credentials and a live sandbox test (section 8), a staging run of the migrations on a copy
of production (section 5), and the business decisions in section 28.

Two Claude sessions worked in this working tree. Session `-45` did the ZainCash retirement details
(the migration that drops `settings.zaincash`, `ZainCashRetirementTest`, the retired-methods table
on the payment methods page). This session integrated that work. Both are described here.

---

## 1. Implementation summary

| Domain | Before | Now |
|---|---|---|
| Payments | `orders.payment_status` set by hand; ZainCash/Qi Card receipts checked by an admin | `payments` ledger (one row per attempt), `payment_events`, `payment_status_histories`, `refunds`, `cod_settlements`; gateway abstraction; Paymob (intention + legacy iframe flows, HMAC webhooks, server-side verification, refunds, expiry); COD collection separate from settlement; admins can't mark a payment paid by hand |
| Inventory | One `stock` number per size; checkout subtracted it | Warehouses + locations; per-warehouse balances (on hand, reserved, damaged, quarantined, in transit); reservations at checkout, fulfilment at dispatch; immutable idempotent ledger; damage, write-off, quarantine inspection; transfers; stock counts; valuation; integrity checker |
| Purchasing | None | Suppliers, purchase orders, partial/complete receiving (good + damaged), weighted average cost, supplier invoices and payments, supplier balance, supplier returns |
| Delivery | Zones, rates, providers, shipments, webhooks (earlier phase) | Provider interface completed (`cancelShipment`, `fetchStatus`), polling through the same event path, estimated delivery date, COD collection on delivery, returned shipments → quarantine |
| Orders | 5 statuses | `picking` and `packed` warehouse steps; separate order / payment / shipment / refund status; online orders can't be prepared before verified payment; stock, payment and shipment move together through one path each |
| Security | Roles and permissions (earlier phase) | `inventory` and `purchasing` permissions, `warehouse_manager` role, every stock/payment/refund/settlement action audited, provider secrets only in env |
| Dashboard | Stock overview + ledger | 20 new pages: inventory overview with filters, SKU history, reports, warehouses, transfers, counts, suppliers, purchase orders, payments, payment detail, provider events, payment reports, refunds, COD reconciliation and settlements |

## 2. Original audit findings and their final status

The earlier phase implemented the audit's P0–P2 findings (details in Appendix A). They were
re-verified against the current code during the second audit (section 31).

| Area (audit) | Status | Evidence |
|---|---|---|
| Health: DB vaccine schedule + CRUD, country schedules, pregnancy content, reminders, device tokens, StoreClock, history paging, validation, BP urgent care, permissions, translations, dead code | IMPLEMENTED | `HealthContentTest`, `HealthRemindersTest`, `HealthCalculationsTest` (all passing) |
| Coupons: one evaluator for preview + checkout, caps, per-user, delivery applicability | IMPLEMENTED | `CouponsGiftsAndPromotionsTest` |
| Promotions: flash sale end time, precedence, no stacking | IMPLEMENTED | `FlashSalePricingTest`, `CouponsGiftsAndPromotionsTest` |
| Gifts as real order lines with stock | IMPLEMENTED (now reserved + "gift" movement at dispatch) | `CouponsGiftsAndPromotionsTest`, `InventoryDomainTest::test_a_gift_line…` |
| Loyalty: one points definition, ledger, validated settings, StoreClock expiry | IMPLEMENTED | `LoyaltyLedgerTest` |
| Market: Egypt only, EGP (multi-country support removed, see section 32) | IMPLEMENTED | `OrderPoliciesTest`, `EgyptOnlyMigrationTest`, `DeliveryPricingTest` |
| Admin roles, gates, audit log, customer activation, OTP flow | IMPLEMENTED | `AdminPermissionsTest`, `AccountActivationTest` |
| Private files (receipts, return photos, medication photos) | IMPLEMENTED | `PaymentsAndFilesTest` |
| Database integrity (restrict FKs, snapshots, decimals, idempotency) | IMPLEMENTED | `MigrationBackfillTest` |
| API limits, pagination, debug endpoint removed | IMPLEMENTED | `RouteProtectionTest`, `CatalogQueryCountTest` |

## 3. Architecture changes

- **One writer per state.** `InventoryService` is the only code that changes stock.
  `PaymentService` is the only code that changes a payment and writes `orders.payment_status`.
  `RefundService` handles refunds, `OrderWorkflow` the order status, `OrderFulfilment` shipments.
  Dashboard actions, webhooks, scheduled jobs and seeders all go through them.
- **Projections, not duplicate truths.** `product_sizes.stock` / `quarantined_stock` are
  projections of `inventory_balances`, and `orders.payment_status` / `refund_status` /
  `refunded_amount` are projections of `payments` / `refunds`. Each is written in the same
  transaction as its source, by that source's service only. `inventory:verify` checks the
  inventory ones daily. The catalogue, cart and older apps keep reading the same columns.
- **Gateway and provider abstractions.** `PaymentGateway` (`CodGateway`, `PaymobGateway`, chosen
  by `GatewayRegistry`) and `DeliveryProviderAdapter` (`ManualAdapter`, `SignedWebhookAdapter`,
  chosen by `ProviderRegistry`). Adding a provider means one class and one registry line; the
  order module doesn't change.
- **Lock order.** Every stock change locks the SKU row (`product_sizes`) first. Checkout locks
  SKUs in id order. All changes to one SKU are serialized, whichever warehouse they touch, so they
  can't deadlock against each other.
- **Idempotency everywhere.** Ledger rows have a unique `idempotency_key` per business event and
  bucket. Payment events have a unique key per provider transaction and outcome. Shipment events
  have unique keys too. COD collection and refunds are guarded by status.

## 4. Database changes

New tables (this phase): `warehouses`, `warehouse_locations`, `inventory_balances`,
`stock_reservations`, `suppliers`, `purchase_orders`, `purchase_order_items`, `purchase_receipts`,
`purchase_receipt_items`, `supplier_invoices`, `supplier_payments`, `supplier_returns`,
`supplier_return_items`, `stock_transfers`, `stock_transfer_items`, `stock_transfer_receipts`,
`stock_counts`, `stock_count_items`, `payments`, `payment_events`, `payment_status_histories`,
`refunds`, `cod_settlements`.

Changed tables:
- `product_sizes`: + `sku` (unique), `barcode` (unique), `color`, `cost_price`, `min_stock`,
  `max_stock`, `reorder_point`, `reorder_quantity`.
- `stock_movements`: + `warehouse_id`, `unit_cost`, `notes`, `idempotency_key` (unique; replaces
  the old `(type, reference_type, reference_id)` unique key, which couldn't express partial
  receipts or multi-bucket events).
- `order_items`: + `stock_state` (reserved / fulfilled / released / restocked / returned).
- `payment_methods`: + `provider` (cod / paymob / manual_transfer), `is_legacy`.
- `orders`: + `refund_status`, `refunded_amount`; `status` ENUM → string(30) (MySQL had the five original values as an ENUM, which would have rejected `picking`/`packed`; found by the MySQL test run).
- `shipments`: + `estimated_delivery_at`.
- `settings`: − `zaincash` (session `-45`; the value is kept on the legacy payment method row).

Money is `DECIMAL(12,2)` in all new tables. Arithmetic uses integer minor units (`Money`).
Foreign keys on financial and stock history are `restrict`.

## 5. All migrations

| Migration | Purpose | Special handling |
|---|---|---|
| `2026_11_03_000001` … `000006` | Earlier phase (Appendix A §2) | — |
| `2026_11_04_000001_create_inventory_domain` | Inventory, purchasing, transfers, counts; SKU fields | Creates warehouse `MAIN`. For each size: `on_hand = stock + units of open (pending/confirmed) orders`, `reserved =` those units, `quarantined = quarantined_stock`. Open orders' lines become active reservations, so the sellable stock the app shows is unchanged. One `opening_balance` ledger row per non-zero bucket. Line states set from the order status. |
| `2026_11_04_000002_create_payments_domain` | Payments, events, history, refunds, COD settlements; Paymob method (inactive); ZainCash/Qi Card → legacy | One payment per existing order, mapped from its `payment_status` (COD paid → collected; settled shipments → settled). Accepted returns become `succeeded` refunds marked `backfilled`, because the old code treated an accepted return as refunded. No order amount changes. |
| `2026_11_04_000003_add_estimated_delivery_to_shipments` | ETA date | Existing shipments stay null |
| `2026_11_04_000004_widen_order_status_column` | `orders.status` ENUM → string | No row changes. `down()` moves picking/packed orders back to `confirmed` before restoring the ENUM |
| `2026_11_05_000001_remove_zaincash_settings` (session `-45`) | Drops `settings.zaincash` | Copies the number to the legacy method row first; `down()` restores it |

Reversibility: all five have `down()`. On MySQL 8 (with the full demo data) all five were rolled
back and migrated again successfully, after fixing one MySQL-only foreign key ordering
issue found by that test. **Rollback of the inventory migration is lossy by design.** It deletes
the warehouse ledger rows (they belong to the new model) and keeps the legacy rows. Don't roll it
back after real stock activity.

## 6. Payment architecture

```
Checkout ─► PaymentService::createForCheckout ─► payments (pending)
               │ COD: collection_status = pending
               │ Paymob: expires_at = now + TTL ─► PaymentService::initiate ─► PaymobGateway (HTTP, outside the DB transaction)
Provider ─► POST /api/webhooks/payments/paymob ─► PaymentService::handleNotification
               1. verify HMAC (bad → 400, stored as rejected, never applied)
               2. store payment_events with a unique key (repeat → "duplicate", nothing re-applied)
               3. find the payment by our reference (special_reference / merchant_order_id), else provider order id
               4. optional server-side check with Paymob's transaction API
               5. check amount, currency, reference (mismatch → rejected + needs_review)
               6. PaymentService::transition (locked, validated, history row, order projection)
Courier "delivered" ─► OrderFulfilment ─► PaymentService::recordCodCollection
Admin ─► CodSettlementService (cash handed over) · RefundService (refunds)
```

- `PaymentStatus`: unpaid (order-level name for a COD payment not collected yet), pending,
  awaiting_verification (legacy receipts), paid, partially_paid, failed, canceled, expired,
  partially_refunded, refunded. The transition graph is in the enum. A paid payment can only
  become (partially) refunded. A failed/expired/canceled one can only become paid on verified
  provider evidence: a late payment is recorded, and refunded automatically if the order is gone.
- Order cancellation: unpaid payments are canceled; money already received becomes a pending
  refund.
- An online order can't move past `pending` until its payment is paid (`OrderWorkflow`).

## 7. Paymob integration readiness

Implemented from Paymob's developer documentation:
- [HMAC for the transaction callback](https://developers.paymob.com/paymob-docs/developers/webhook-callbacks-and-hmac/hmac/hmac-transaction-callback):
  SHA-512 with the HMAC secret, over 20 fields in their documented order, compared with the `hmac`
  query parameter. Both callback shapes are supported: POST (`obj.id`, `order.id`) and the GET
  redirect (`id`, `order`), including PHP's dot-to-underscore renaming of `source_data.*`.
- [Create Intention](https://developers.paymob.com/paymob-docs/developers/intention-apis/create-intention)
  with `Authorization: Token <secret key>`, `special_reference`, `notification_url`,
  `redirection_url`, `expiration`; then
  [Unified Checkout](https://developers.paymob.com/paymob-docs/developers/checkout-experiences/unified-checkout-redirection)
  `?publicKey=…&clientSecret=…`.
- Legacy flow: `/api/auth/tokens` → `/api/ecommerce/orders` → `/api/acceptance/payment_keys`
  → iframe.
- Inquiry `GET /api/acceptance/transactions/{id}` (bearer token).
- [Refund](https://docs.paymob.com/docs/refund-transaction) `POST /api/acceptance/void_refund/refund`.
- Refund and void callbacks; refunds made in the Paymob dashboard are recorded from their callback.

Ready: configuration validation (`missingConfiguration()`), activation blocked until configured,
the `payments:paymob-check [--ping]` command, separate sandbox and production credential sets,
expiry job, retry endpoint, dashboard status and the provider event log.

**Not verified against a real Paymob account.** Every Paymob test uses faked HTTP. Points to
confirm with the client's account, listed because Paymob's public docs differ between regions or
pages:
1. the region's API host and Unified Checkout URL (`PAYMOB_BASE_URL`, `PAYMOB_CHECKOUT_URL`);
2. the refund call's authentication (we send `Authorization: Token <secret>` in the intention
   flow, and `auth_token` in the legacy flow);
3. the exact refund-callback flags (we treat `is_refund` / `is_refunded` / `has_parent_transaction`
   + `parent_transaction` as a refund);
4. `amount_cents` scaling for 3-decimal currencies (`PAYMOB_AMOUNT_FACTOR`).

## 8. Required Paymob credentials

Set in the server environment only (`.env.example` lists them, empty). They are never stored in
the database, never returned by any API, never printed by the check command.

| Variable (per environment: `PAYMOB_SANDBOX_*` / `PAYMOB_LIVE_*`) | Needed for |
|---|---|
| `SECRET_KEY` | Intention API, refunds (intention flow) |
| `PUBLIC_KEY` | Unified Checkout URL |
| `HMAC_SECRET` | Callback verification (always required) |
| `INTEGRATION_IDS` | Payment methods offered (comma-separated, test/live must match the keys) |
| `API_KEY` | Server-side verification and inquiry; the legacy flow |
| `IFRAME_ID` | Legacy flow only |
| `MERCHANT_ID` | Reference only |

Global: `PAYMOB_ENV` (sandbox / production), `PAYMOB_FLOW` (intention / legacy),
`PAYMOB_BASE_URL`, `PAYMOB_CHECKOUT_URL`, `PAYMOB_CURRENCY`, `PAYMOB_AMOUNT_FACTOR`,
`PAYMOB_PAYMENT_TTL_MINUTES` (60), `PAYMOB_VERIFY_WITH_API` (true), `PAYMOB_RETURN_URL` (app
deep link). In the Paymob dashboard, register the callback URL printed by
`php artisan payments:paymob-check`.

Go-live steps: set the sandbox variables → `payments:paymob-check --ping` → activate Paymob under
Payment methods → test card payment, decline, refund, expiry → switch to `PAYMOB_ENV=production`
with the live variables.

## 9. COD workflow

1. Checkout: payment `pending`, collection `pending`, order `payment_status = unpaid`.
2. Courier picks up: order `on_delivery`, stock fulfilled.
3. Delivered: the courier collected the cash still due → payment `paid`, collection `collected`
   (`collected_via = courier`). **Not settled.**
4. Failed delivery: collection `failed`; the cash stays due and the shipment is flagged.
5. Pickup / manual completion: completing the order records the remaining cash at the counter.
6. Partial collection: the dashboard "Record cash" takes any amount up to what's due →
   `partially_paid`. Over-collection is refused.
7. Settlement (Payments → Cash on delivery): select collected payments and enter the cash
   actually received. `cod_settlements` keeps expected, received and the difference (shortfalls
   stay visible). Payments and shipments are marked settled. Settling twice is refused.
8. Reconciliation page: due from customers, collected-not-settled per courier, failed
   collections, all-time settlement differences.

## 10. ZainCash removal (and Qi Card)

- Both are `is_legacy`, inactive, provider `manual_transfer`. Checkout refuses them even if
  someone re-activates the row in the database (`ACTIVE_PROVIDERS` = cod, paymob). The dashboard
  refuses to re-activate or edit them, or to create another manual-transfer method.
- Settings: `zaincash` dropped (value kept on the legacy row); `keycard` kept as a column but not
  writable. The settings API returns both keys as `null`, so older apps stop offering them.
- Removed from settings UI, validation, the settings ↔ payment-method sync, seeders and demo
  orders.
- Preserved: historical orders (`payment_method`, receipts), their `payments` rows (backfilled as
  `manual_transfer`), events and the legacy method rows. A historical receipt still pending can
  be verified (the only remaining manual verification path).
- `.env.example` / config: there were no ZainCash variables to remove.

## 11. Inventory architecture

- **Variant = `product_sizes`** (not duplicated): size, colour, SKU, barcode, price, cost,
  min/max/reorder levels.
- **`inventory_balances`** per warehouse × SKU: `on_hand`, `reserved` (part of on hand),
  `damaged`, `quarantined`, `in_transit`. Available = on hand − reserved.
- **Sellable warehouses** feed online stock; non-sellable ones (back store) are tracked but not
  offered.
- **Invariants enforced in code**: no bucket below zero, reserved ≤ on hand, a mandatory reason
  for manual changes, an idempotency key per event. Negative stock isn't supported.

## 12. Stock movement lifecycle

| # | Event | Movement type(s) | Buckets |
|---|---|---|---|
| 1 | Initial stock / migration | `initial`, `opening_balance` | on_hand + |
| 2 | Purchase receiving | `purchase_receipt` | on_hand +, damaged + |
| 3–4 | Stock increase / decrease | `adjustment` (signed, reason required) | on_hand ± |
| 5 | Order reservation (checkout) | `reservation` | reserved + |
| 6 | Order confirmation | — (status only; the reservation already holds the units) | — |
| 7–8 | Picking, packing | — (order stages; no quantity changes) | — |
| 9 | Fulfilment (dispatch / hand-over) | `fulfillment` (gifts: `gift`) | on_hand −, reserved − |
| 10–11 | Cancellation / release | `reservation_release`; legacy lines `cancellation`; after dispatch `cancellation_return` | reserved − / on_hand + / quarantined + |
| 12 | Product return | `return_quarantine`; undelivered shipments `shipment_return` | quarantined + |
| — | Inspection | `return_restock`, `return_damaged`, `write_off` | quarantined → on_hand / damaged / out |
| 13 | Damaged | `damage`, `write_off` | on_hand → damaged → out |
| 14 | Warehouse transfer | `transfer_out`, `transfer_dispatch`, `transfer_in`, `transfer_loss` | on_hand → in_transit → on_hand |
| 15–17 | Manual adjustment, count, correction | `adjustment`, `count_correction` | on_hand ± |
| 18 | Gift products | `reservation` then `gift` | reserved +, then on_hand −/reserved − |
| 19 | Supplier returns | `supplier_return` | on_hand / damaged / quarantined − |

Every row records product, SKU, warehouse, type, bucket, before, change, after, reference
(polymorphic), reason, notes, unit cost, admin/customer and timestamp. Rows can't be updated or
deleted (model guards).

## 13. Warehouse management

Warehouses (code, address, country, sellable/active/default) and bin locations. A warehouse
holding any stock can't be deactivated. Changing "sellable" recalculates online stock. Overview
per warehouse with all buckets.

## 14. Supplier and purchase management

- Purchase order `draft → ordered → partially_received → received → closed`, or `canceled`.
  Creating or approving an order never changes stock (tested).
- Receiving: good and damaged units per line, several receipts per order (GRN numbers),
  outstanding quantity tracked, over-receiving refused, weighted average cost updated.
- Supplier invoices (unique number per supplier, linked to an order), supplier payments
  (on account or per invoice, never more than the invoice), supplier returns
  (`draft → dispatched`, taking units from damaged, sellable or quarantine).
- Balance = invoices − payments − return credits.

## 15. Delivery architecture

`DeliveryProviderAdapter`: `createShipment`, `cancelShipment`, `fetchStatus`, `verifyWebhook`,
`parseWebhook`. `ManualAdapter` and `SignedWebhookAdapter` (generic HMAC-SHA256 contract).
`OrderFulfilment` is the single path for dashboard actions, webhooks and the new `poll()`.
Webhook security (earlier phase): HMAC, idempotency, status mapping, unmapped-status quarantine.
The system never models the courier's drivers or vehicles.

## 16. Shipment lifecycle

pending → assigned → picked_up → in_transit → delivered / failed / returned / canceled.
picked_up / in_transit dispatch the order (stock fulfilled). delivered completes it and collects
the cash. failed records an attempt and the collection failure. returned brings the units back to
quarantine. A canceled order withdraws the shipment at the provider; a provider refusal flags it.
Estimated delivery date from the rate's maximum ETA, shown to the customer.

## 17. Order lifecycle

`pending → confirmed → picking → packed → on_delivery → completed`, `canceled` from any non-final
state; steps may be skipped, never reversed. The four statuses are separate:

| Status | Field | Writer |
|---|---|---|
| Order | `orders.status` + `order_status_histories` | `OrderWorkflow` |
| Payment | `payments.status` + `payment_status_histories` (projected to `orders.payment_status`) | `PaymentService` |
| Shipment | `shipments.status` + `shipment_events` | `OrderFulfilment` |
| Refund | `refunds.status` (projected to `orders.refund_status`, `refunded_amount`) | `RefundService` |

Snapshots (earlier phase): address, item names, prices, size labels, delivery fee, discount,
subtotal, country, currency. There are no taxes in the system, so there are no tax snapshots.
Double execution is prevented by row locks, status guards and idempotency keys.

## 18. Refund workflow

Sources: an accepted return (amount approved by the admin), a canceled paid order, a late
payment on a canceled order, a manual request on a payment, or a refund made in the Paymob
dashboard (from its callback).

`pending → processing → succeeded / failed`, or `canceled`. Paymob refunds go through the API
(processing until confirmed if Paymob says pending). Cash and manual refunds are recorded with a
reference. Open plus succeeded refunds never exceed what was received. Only succeeded refunds
change the payment and the order. Processing needs the `refunds` permission, and every step is
audited.

## 19. Health improvements

Implemented in the earlier phase and unchanged here (Appendix A §1 "Health"). Medical formulas
and thresholds stay in code, with boundary tests.

## 20. Dashboard improvements

New: Inventory (overview with 8 stock filters, warehouse/category/size/colour/SKU/barcode
search, per-warehouse summary, KPIs including valuation and overstock), SKU detail (lifecycle
totals, ledger reconciliation, balances per warehouse, active reservations, timeline, stock
operations, SKU master data), Inventory reports (integrity check, valuation by warehouse and
category, reorder list, movement summary by period), Warehouses, Transfers, Stock counts,
Suppliers (balance, invoices, payments, returns), Purchase orders (receiving form), Payments
(filters by method, status, date and reference; Paymob configuration status), Payment detail
(events with payloads, status history, COD collection, verify with provider, refund), Provider
events, Payment reports (success rate, failure reasons, COD position, refunds), Refunds, COD
reconciliation and settlements. Order page: payment attempts, COD collection, legacy receipt
verification, refunds, warehouse steps. The sidebar has Payments and Purchasing sections and
shows only what the role allows.

## 21. API changes

New:
| Endpoint | Purpose |
|---|---|
| `POST /api/user/orders/{id}/pay` | Start or retry the online payment (owner only, max 5 attempts) → `payment.checkout_url` |
| `GET /api/user/orders/{id}/payment` | Current payment state (poll after returning from checkout) |
| `POST /api/webhooks/payments/paymob` | Paymob callback (HMAC) |
| `GET /payments/paymob/return` | Customer redirect → app deep link `?status=success/pending/failed&reference=&order_id=` |

Changed (additive unless noted in section 30): checkout response + `payment` for Paymob;
`fulfillment_stage` in track, details and grouped lists; `refund_status` in details;
`delivery.estimated_delivery_at`; payment methods list + `type` (online / cash_on_delivery).

## 22. Security improvements

Provider credentials only in env (never in the DB, API responses, logs or the check command).
HMAC with `hash_equals`. Forged, replayed and tampered callbacks are rejected and stored for
investigation. Amount, currency and reference are verified, with optional server-side
confirmation. No manual "paid" for online payments. New `inventory` / `purchasing` permissions
and `warehouse_manager` role; orders agents and content editors can't change stock. Every stock
operation, settlement, refund, payment action, purchase and transfer is audited. The webhook is
throttled.

## 23. Performance improvements

Overview totals and valuation computed in SQL aggregates, not in PHP. Lists eager-load and
paginate. Inventory filters run on indexed columns. New indexes on `stock_movements`
(warehouse/SKU/date, reference, type/date), `payments` (provider/order, order/status,
status/expiry, collection) and `payment_events`. The sidebar counts stay cached (60 s).

## 24. Tests added (this phase)

| File | Tests | Covers |
|---|---|---|
| `Payments/PaymobTest` | 19 | missing credentials, sandbox vs production keys, intention initiation (no secrets in responses), legacy iframe flow, unreachable provider + retry, verified success + duplicate webhook, invalid/empty/forged HMAC, amount and currency mismatch, declined payment + confirmation guard, pending → success and stale-failure replay, server-side verification override, unknown payment, signed redirect, gateway refund + permission, refund callback from the Paymob dashboard, expiry releasing stock, late payment on a canceled order, no manual paid, dashboard pages |
| `Payments/CodAndOrderLifecycleTest` | 8 | full COD + order + stock + shipment lifecycle with settlement, failed delivery and returned shipment, partial collection + shortfall, invalid transitions, customer status mapping, permissions + reconciliation pages, immutable payment history, illegal payment transitions |
| `Inventory/InventoryDomainTest` | 17 | PO not changing stock, partial/complete receiving with damage, average cost, draft PO refusal, supplier returns + balance, transfers conserving units with loss, reserved units not transferable, multi-warehouse allocation and non-sellable warehouses, release vs quarantine on cancellation, overselling refusal, gift movements, damage/write-off/inspection, counts vs reservations, idempotent and never-negative ledger, integrity checker, permissions + reason + audit, all pages render, dashboard document creation. Every test also asserts the integrity check afterwards. |
| `Delivery/ProviderAbstractionTest` | 4 | ETA, adapter create + polling through the event path, cancellation at the provider + refusal flag, push-only providers |
| `Orders/ZainCashRetirementTest` (session `-45`) | 5 | not offered or accepted, setting gone, no re-activation, history visible, legacy receipt verification |

Updated existing tests (each change commented in the test): `StockLedgerTest`,
`ReturnQuarantineAndPointsTest` (reservation / fulfilment semantics; quarantine bucket name),
`CouponsGiftsAndPromotionsTest` (gift reserved), `SeederTest` (integrity checker and line states),
`MigrationBackfillTest` (generalised to all new migrations; open orders → reservations),
`LoyaltyLedgerTest` (refund must be paid back before `refunded`), `OrderPoliciesTest`,
`PaymentsAndFilesTest`, `CheckoutTest`, `DeliveryPricingTest` (retired methods),
`RouteProtectionTest` (payment webhook public by design).

## 25. Full test results

- Baseline at the start of this phase: 406 passed, 2 skipped.
- SQLite before the Egypt-only change: 459 passed, 2 skipped.
- **Final run, after the Egypt-only change (section 32): SQLite 461 passed, 2 skipped; MySQL 8 (throwaway `pharmacy_impl_check`) 463 passed, 0 failed**. The MySQL run includes the 2 row-locking tests that SQLite skips. Earlier MySQL runs found two real problems, both fixed: the `orders.status` ENUM (migration `000004`), and an API type regression (`total_price` coming back as the string `"90.00"` after a model refresh in checkout).
- Migrations on MySQL 8 with full demo data: `migrate:fresh --seed`, `inventory:verify`
  (consistent), rollback of the 5 new migrations, migrate again. All succeeded after the `down()` fix.
- `view:cache` compiles every Blade template. `route:list`, `schedule:list` and `php -l` on
  changed files are clean.
- Pint (installed, no project config): the existing codebase doesn't follow the Laravel preset
  (246 files flagged across `app/`, mostly `! $x` spacing). New code follows the project's
  existing style instead; the genuine findings in new files (an unused import, indentation) were
  fixed. No static analyser is configured.

## 26. Remaining issues

1. Paymob is implemented but unverified against a real account (section 7).
2. Warehouse locations exist (CRUD) but balances are tracked per warehouse, not per bin.
3. No batch/lot or expiry-date tracking for medicines. Valuation is weighted average cost, not
   FIFO. It's an important addition for a pharmacy and needs a decision (section 28).
4. Supplier invoices aren't three-way matched with receipts. Supplier payments don't go through
   an approval step.
5. Picking and packing are order stages; there are no pick lists or per-bin picking.
6. Concurrency is proven by row-lock tests on MySQL and by the lock-order design; there's no
   multi-process load test.
7. The dashboard is English-only (unchanged). Customer messages are ar/en.
8. Earlier-phase limitations still apply (Appendix A §10): production address/zone check, FCM
   untested against Firebase, the report date-filter edge, and so on.

## 27. External dependencies

Paymob account and credentials (sandbox, then production); Firebase credentials for push
(earlier phase); a scheduler (`schedule:run` every minute: payment expiry every 5 min, inventory
check daily, reminders) and a queue worker. No new Composer or npm packages were added.

## 28. Business decisions still required

| # | Decision | Current behaviour |
|---|---|---|
| B1 | Online payment window before an unpaid order is canceled | 60 min (`PAYMOB_PAYMENT_TTL_MINUTES`) |
| B2 | Should a Paymob order be auto-confirmed when paid? | No: paid orders wait for an admin to confirm |
| B3 | Units canceled after dispatch | Back to quarantine for inspection, not on sale |
| B4 | Batch/expiry tracking and FIFO/FEFO picking | Not implemented |
| B5 | ~~Default store market~~ **Done:** Egypt only, EGP (section 32) | Egypt / EGP |
| B6 | Which warehouses are sellable online | `MAIN` sellable; new warehouses as configured |
| B7 | Earlier decisions D4–D13 | Appendix A §11 |

## 29. Files created and modified (this phase)

**Created**
- Migrations: `2026_11_04_000001_create_inventory_domain`, `2026_11_04_000002_create_payments_domain`, `2026_11_04_000003_add_estimated_delivery_to_shipments`, `2026_11_04_000004_widen_order_status_column`; `2026_11_05_000001_remove_zaincash_settings` (session `-45`)
- Models: `Warehouse`, `WarehouseLocation`, `InventoryBalance`, `StockReservation`, `Supplier`, `SupplierInvoice`, `SupplierPayment`, `SupplierReturn`, `SupplierReturnItem`, `PurchaseOrder`, `PurchaseOrderItem`, `PurchaseReceipt`, `PurchaseReceiptItem`, `StockTransfer`, `StockTransferItem`, `StockTransferReceipt`, `StockCount`, `StockCountItem`, `Payment`, `PaymentEvent`, `PaymentStatusHistory`, `Refund`, `CodSettlement`
- Inventory: `Services/{InventoryService, InsufficientStock, InventoryIntegrity, PurchasingService, TransferService, StockCountService}`, controllers `WarehouseController`, `TransferController`, `StockCountController`, `SupplierController`, `PurchaseOrderController`
- Payments: `Gateways/{PaymentGateway, PaymentInitiation, GatewayNotification, GatewayRefundResult, PaymentGatewayException, CodGateway, PaymobGateway, GatewayRegistry}`, `Services/{PaymentService, RefundService, CodSettlementService}`, `Api/{PaymentWebhookController, ApiOrderPaymentController}`, `Admin/{PaymentController, RefundController, CodController}`
- Support / commands: `app/Support/DocumentNumber.php`, `VerifyInventory`, `ExpireStalePayments`, `CheckPaymob`
- Views: `dashboard/{inventory/show, inventory/reports, warehouses/index, transfers/index, transfers/show, counts/index, counts/show, suppliers/index, suppliers/form, suppliers/show, purchase-orders/index, purchase-orders/show, payments/index, payments/show, payments/events, payments/reports, refunds/index, refunds/show, cod/index, cod/settlement, partials/line-items}`
- Seeders: `Demo/InventorySeeder`, `Demo/PaymentsSeeder`
- Tests: `Payments/PaymobTest`, `Payments/CodAndOrderLifecycleTest`, `Inventory/InventoryDomainTest`, `Delivery/ProviderAbstractionTest`; `Orders/ZainCashRetirementTest` (session `-45`)

**Modified**
- Models: `ProductSize` (delegates to `InventoryService`, SKU fields, opening stock through the ledger), `StockMovement`, `OrderItem`, `Order`, `PaymentMethod`, `Setting`, `Shipment`
- Enums: `OrderStatus` (picking, packed, `customerStatus()`), `PaymentStatus` (full graph), `Permission` (+inventory, purchasing), `AdminRole` (+warehouse_manager)
- Services: `CheckoutService` (reserve, payment record, Paymob initiation), `OrderWorkflow` (fulfil / release, payment guard, COD on completion, provider cancellation), `OrderFulfilment` (COD collection, returned shipments, ETA, `poll`, settlement through `CodSettlementService`), `ReturnDecisionService` (refund records, warehouse-aware quarantine), `SettingService`
- Controllers / requests: `OrderController` (customer status mapping, legacy-only manual verification, eager loads), `InventoryController` (rewritten), `PaymentMethodController`, `ApiPaymentMethodController`, `ShipmentController`, `CheckoutRequest`, `UpdateSettingRequest`
- Delivery providers: `DeliveryProviderAdapter`, `ManualAdapter`, `SignedWebhookAdapter`
- Routes, config: `routes/{web,api,console}.php`, `config/services.php` (paymob), `.env.example`, `AppServiceProvider` (refund badge)
- Views: `inventory/index`, `inventory/movements`, `orders/show` (payment panel), `orders/index`, `payment-methods/index`, `Settings/index`, `components/admin/status`, `admin/partials/sidebar`
- Lang: `lang/{ar,en}/{orders,payments}.php`
- Seeders: `DemoSeeder`, `Demo/OrderSeeder`, `Demo/ReturnSeeder`, `SettingSeeder`
- Tests: listed in section 24

## 30. Breaking changes and migration instructions

| # | Change | Why | Impact on the apps / admins | What to do |
|---|---|---|---|---|
| 1 | ZainCash and Qi Card are no longer accepted (422 `payment_method`) and aren't in `/payment-methods`; the settings keys are `null` | Business requirement | Apps that hard-code them must hide them | Read the methods from `/api/v1/user/payment-methods` |
| 2 | New method `paymob` (once configured): checkout returns `payment.checkout_url`; open it in a browser or web view; the result returns via the deep link `PAYMOB_RETURN_URL` | New capability | Additive for apps that don't offer it | Implement the open-URL + deep-link + `GET /orders/{id}/payment` poll; retry with `POST /orders/{id}/pay` |
| 3 | `payment_status` can now be `pending`, `failed`, `partially_paid`, `partially_refunded`, `expired`, `canceled` as well as the old four | A real payment lifecycle | Apps that switch on it need a default branch | Treat unknown values as "show the label" |
| 4 | An accepted return no longer makes `payment_status = refunded` immediately; that happens when the refund is paid back | The money hasn't moved yet | Admins must process refunds (Refunds page) | Train admins; `refund_status` shows progress |
| 5 | Order statuses `picking`/`packed` exist; customer endpoints report them as `confirmed` with `fulfillment_stage` | Warehouse workflow | None for current apps | Optionally show `fulfillment_stage` |
| 6 | Checkout reserves stock instead of subtracting it; stock leaves at dispatch | Correct inventory | The sellable number customers see behaves the same | Admins must move orders to `on_delivery` / `completed` (or via shipment events) so stock leaves the warehouse |
| 7 | Stock pages need the new `inventory` permission (content editors lose access); purchasing needs `purchasing` | Least privilege | Admin menus change | Assign `warehouse_manager` / `manager` roles as needed |
| 8 | The order page's payment control only verifies legacy receipts | No manual "paid" | Admins can't mark COD or online payments paid | COD: "Record cash" or delivery; Paymob: provider-verified only |

Deployment order: back up → run migrations on a staging copy → `php artisan inventory:verify`
→ compare `SELECT SUM(stock) FROM product_sizes` before and after (must be equal) → deploy →
`inventory:verify` → configure Paymob (section 8) → ensure the scheduler is running.

## 31. Second full audit

| Requirement | Status | Evidence / reason |
|---|---|---|
| Only COD + Paymob active; extensible gateway architecture | IMPLEMENTED | `ACTIVE_PROVIDERS`, `GatewayRegistry`; `PaymentsAndFilesTest`, `ZainCashRetirementTest` |
| Paymob config, security, workflow, statuses, webhooks, refunds | IMPLEMENTED (code + faked-HTTP tests) / BLOCKED for live verification | `PaymobTest` (19); needs credentials |
| Payment DB (methods, payments, events) + history | IMPLEMENTED | migration `000002`, `MigrationBackfillTest` |
| COD collection, partial, failure, settlement, courier settlement, reconciliation | IMPLEMENTED | `CodAndOrderLifecycleTest` |
| Payment dashboard (filters, details, events, failures, manual legacy verification, methods, COD, refunds, reports) | IMPLEMENTED | `PaymobTest::test_dashboard…`, `CodAndOrderLifecycleTest` |
| No manual "paid" for Paymob | IMPLEMENTED | `test_admins_cannot_mark_an_online_payment_paid` |
| ZainCash removed, history preserved; Qi Card retired | IMPLEMENTED | `ZainCashRetirementTest`, `CheckoutTest` |
| Warehouses, variants, SKUs, barcodes, colours, buckets, min/max/reorder | IMPLEMENTED | `InventoryDomainTest` |
| Warehouse locations | PARTIALLY IMPLEMENTED | CRUD only; balances per warehouse (section 26.2) |
| Stock movement lifecycle (19 events) | IMPLEMENTED (picking/packing intentionally not quantity movements) | section 12; `InventoryDomainTest`, `StockLedgerTest` |
| Immutable, auditable movements with all fields | IMPLEMENTED | model guards; `ReturnQuarantineAndPointsTest` |
| Product stock history with totals and timeline | IMPLEMENTED | SKU detail page; rendered in tests |
| Inventory dashboard (18 items, all filters) | IMPLEMENTED | `test_inventory_and_purchasing_pages_render_with_data` |
| Purchasing and suppliers (all listed) | IMPLEMENTED (no three-way matching) | `InventoryDomainTest` |
| Warehouse transfers (request → receive, partial, discrepancies, history) | IMPLEMENTED | `test_transfers_conserve_units…` |
| Inventory rules (no negative, no overselling, no duplicates, transactions, locks, inspection before resale, permissions, reasons) | IMPLEMENTED | `InventoryDomainTest`; row locks verified on MySQL (`RowLockingTest`) |
| Delivery: zones, rates, providers, shipments, tracking, events, attempts, failures, returns, ETA, COD | IMPLEMENTED | Delivery tests (25) |
| Provider interface (create/cancel/fetch/parse), ManualProvider, webhook security | IMPLEMENTED | `ProviderAbstractionTest`, `DeliveryWebhookTest` |
| Order lifecycle (13 steps), separate statuses, histories, snapshots, guards, idempotency | IMPLEMENTED (no taxes in the system → no tax snapshot) | `CodAndOrderLifecycleTest`, `OrderStatusTest` |
| Health module findings | IMPLEMENTED (earlier phase) | Health tests |
| Coupons, promotions, gifts, loyalty | IMPLEMENTED (earlier phase; gifts extended) | Pricing and loyalty tests |
| Countries, currencies, typed settings | IMPLEMENTED (earlier phase) | `OrderPoliciesTest` |
| Egypt-only market (requested of session `-45`) | INTENTIONALLY NOT IMPLEMENTED here | Session `-45`'s scope, after this work |
| Admin security, roles, permissions, audit, private files | IMPLEMENTED | Security tests |
| API: validation, pagination, limits, consistency | IMPLEMENTED (earlier phase + new endpoints) | `RouteProtectionTest`, API tests |
| DB integrity: FKs, uniques, indexes, decimals, idempotency, reversible migrations | IMPLEMENTED (inventory rollback lossy by design) | MySQL migrate/rollback run |
| Dashboard as a business platform | IMPLEMENTED (English-only UI) | page-render tests |
| Batch/expiry tracking | INTENTIONALLY NOT IMPLEMENTED | Not in the brief; needs a decision (B4) |
| Production readiness | NOT CLAIMED | Needs Paymob credentials, staging migration, decisions B1–B6 |

---

## 32. Egypt-only store

**The store currently operates in Egypt only and uses EGP.**

| Area | Change |
|---|---|
| Config | `config/pharmacy.php`: `country` = `EG` and `currency` = `EGP` are fixed (no env variables); `PHARMACY_COUNTRY` and `PHARMACY_CURRENCY` removed; timezone default `Africa/Cairo` (was `Asia/Baghdad`). `services.paymob.currency` defaults to `EGP`. `.env.example` updated. |
| Seed data | `2026_11_03_000001` creates Egypt only (Iraq/IQD removed). Every later seed (cities, zones, providers, payment methods, vaccines, warehouse) therefore attaches to Egypt, and backfilled orders get EGP. |
| Existing databases | `2026_11_06_000001_make_store_egypt_only`: Egypt becomes the only active market; configuration rows attached to another country (cities, delivery zones, providers, payment methods, vaccine schedule, warehouses) move to Egypt; another country row is deleted only if no order references it, otherwise it stays inactive. Orders, payments, refunds, shipments and addresses are not touched. |
| Code | `Country::default()` always resolves Egypt; `PaymentMethod::offered()` (no country parameter); one vaccine schedule (`VaccineScheduleService::template()`); city lookups, address `city_id` and zone areas restricted to Egyptian governorates. |
| Removed | Customer `GET /api/v1/user/locations/countries` (404 now); the cities list no longer takes `country_id`; the admin Countries page, its controller, routes and sidebar entry; the country dropdowns and columns in delivery zones, providers, payment methods, vaccine schedule, cities and warehouses forms. Purchasing falls back to EGP. |
| Kept on purpose | The `countries` table (one Egypt row) and the nullable `country_id` columns: orders record their country at checkout, and the columns cost nothing. Orders' recorded `country_id`/`currency_code` are history and are never rewritten (an order recorded as IQD in a database migrated earlier stays IQD). |
| Tests | Iraqi sample cities, phone numbers and IQD replaced with Egyptian ones across the suite; multi-country tests replaced (`OrderPoliciesTest::test_orders_are_recorded_in_egypt_and_egp`, countries endpoint 404); new `Database/EgyptOnlyMigrationTest`. |

# Appendix A: earlier phase report (audit P0–P2 implementation)

> Historical record. Its multi-market statements (Iraq/IQD default, countries page and API) are superseded by section 32: the store operates in Egypt only and uses EGP.


Scope: the audit findings P0, P1 and P2. Nothing was committed, pushed or deployed, and no
production data was touched. Every new migration was run only against in-memory SQLite in tests.

**Status: not production-ready yet.** The code is implemented and tested. Before deploying, do the
pre-deployment steps in section 10 (a production data check, the migrations on a staging copy, and
the business decisions in section 11).

---

### A.1. What was implemented

### Architecture used for each kind of rule

| Kind of rule | Where it lives now | Examples |
|---|---|---|
| Business entities | Tables + dashboard CRUD | countries, cities/areas, delivery zones/rates/providers, status maps, payment methods, vaccine schedule, pregnancy week content |
| Business policies | Typed, validated columns on the `settings` singleton | low-stock threshold, return window, customer cancellation, coupon-covers-delivery, points rules, reminder switches |
| Display text | `lang/ar/*.php`, `lang/en/*.php` | order, cart, return, health, medication and points messages and labels |
| Financial/inventory invariants | Code | money arithmetic, stock ledger, coupon caps, refund caps, one promotion per price |
| Medical calculations | Code (`HealthService`) with boundary tests and a `guideline` field | BMI, blood sugar, blood pressure, cycle, pregnancy |
| Security limits | Code/env | OTP length/attempts/TTL, rate limits, token lifetime, signed-link TTL |
| Workflows | Enums + one service | `OrderStatus` + `OrderWorkflow`, `ShipmentStatus` + `OrderFulfilment` |
| History | Immutable rows/snapshots | order address snapshot, order country and currency, status history, shipment events, loyalty ledger, audit log, vaccine snapshots |

#### By area

**Health**
- The vaccination schedule is now the `vaccine_schedule_items` table (ar/en names and descriptions, country, code, offset value + day/week/month/year unit, reminder days, order, active flag, version, soft delete). It is seeded with exactly the schedule the code used to generate.
- Each child's vaccines are a snapshot (`vaccine_schedule_item_id`, `schedule_version`, en name/description, reminder days). Editing the template bumps the version and only affects children added afterwards. Removed items are soft-deleted, so existing children keep their copy. A country-specific entry replaces a global one with the same code. If the template is empty, the built-in default schedule is used.
- Pregnancy content (size, weight, length, image, tips, ar/en) now comes from `pregnancy_week_contents`. Naegele's rule, the 42-week limit, month and trimester logic stay in code.
- Blood pressure: a crisis reading (>180/>120) now returns `requires_urgent_care: true` and `advice`. The legacy `evaluation` text is unchanged.
- Validation (Form Requests): BMI `unit` must be `metric` or `imperial`; measurement dates can't be in the future; the ovulation start must be within a year; the pregnancy date must be within two years and not in the future; a child's birth date must be within 18 years and not in the future. Gender accepts `male`/`female` or the Arabic words; `gender_code` was added.
- Blood sugar recognises the condition codes and the legacy Arabic wording and returns `condition_code`. Other values are still accepted and evaluated as `unknown`, for compatibility (pinned by an existing test).
- Medication: "today" and the week start now use the store clock and the country's `week_start`, not the server's UTC day.
- Health history: page through it with `page`/`per_page` (max 100). Without paging, the response is capped at 500 rows with `meta.truncated`.
- Labels: the legacy fields keep their exact Arabic. New `*_label` fields follow `Accept-Language` (`ar`/`en`), and `*_code` fields are stable identifiers.
- Dead code removed: `VaccinationSchedule` (it had no table) and the commented-out blocks in the health controller.

**Health notifications**
- `device_tokens` table and API; a `HealthReminder` notification sent through the database channel (in-app history) and a push channel.
- `PushGateway` has two implementations: `log` (the default) and `fcm` (Firebase HTTP v1 with a service-account JWT; no SDK added).
- `HealthReminderService` claims each reminder by inserting a row into `notification_deliveries`, whose unique key is (type, reference, occurrence). That makes duplicate reminders impossible, even with concurrent schedulers. One queued job sends each reminder; the job is idempotent and re-checks that the vaccine still needs reminding.
- Schedule: vaccine reminders daily at 09:00 store time; medication reminders every 5 minutes (off by default); a daily loyalty expiry ledger run.
- Dashboard: an on/off switch for each reminder type in Settings, and a "Reminders sent" log under Health data.

**Delivery**
- Tables: `countries`, `cities` (+country, ar/en names, active), `areas` (+ar/en, active), `addresses.city_id/area_id`, `delivery_zones`, `delivery_zone_locations`, `delivery_rates` (fee, free-over, minimum order, ETA, priority, active, effective dates, optional provider), `delivery_providers` (internal / external_manual / external_api, adapter, encrypted credentials and webhook secret, tracking URL template, COD), `provider_status_maps`, `shipments`, `shipment_events`.
- `DeliveryQuoteService` replaces the `LIKE '%city%'` lookup. It resolves the address to a city (by id, or by an exact name match on governorate then city, and remembers the link), then the zone (an area zone beats its city's zone), then the effective rate with the highest priority.
- Every `delivery_prices` row was migrated into a zone + rate. The old table is kept, untouched, for rollback.
- Shipments: created at checkout for address orders, with the address and recipient snapshot, fee, COD amount, timestamps, attempts, failure reason, metadata and COD collected/settled dates.
- Shipment events are immutable and idempotent, and record the internal status, raw status, source, actor, timestamp and payload.
- `OrderFulfilment` is the single path from an event to the order, and order transitions go through `OrderWorkflow`. A delivered shipment completes the order; a failed or returned one only flags it for a person.
- Provider abstraction: a `DeliveryProviderAdapter` interface, with `ManualAdapter` (dashboard updates) and `SignedWebhookAdapter` (a generic HMAC-signed webhook contract). Adding a provider means writing one class and registering it in `ProviderRegistry`. We never model the provider's drivers or vehicles.
- Webhook `POST /api/webhooks/delivery/{code}`:
  - verifies the HMAC signature (401 if it's wrong; nothing is stored);
  - ignores a repeated event id;
  - maps the provider's statuses through `provider_status_maps`;
  - stores an unmapped status with a null status, flags the shipment and leaves the order untouched;
  - reports unknown shipments without failing the request;
  - returns 500 for internal errors only, so the provider retries.

**Orders**
- `OrderStatus` enum (the protected graph), `OrderWorkflow` (the only code that changes status), and `order_status_histories` (from, to, actor type, actor id, note).
- New timestamps: `confirmed_at`, `completed_at`, `canceled_at`.
- Immutable snapshots: `shipping_address`, `country_id`, `currency_code`, `subtotal`, `discount_amount`.
- Customer cancellation of pending orders behind a setting (off by default).
- Optional return window (only completed orders within N days). Unset means no limit, as before.
- Revenue is now completed orders minus approved refunds, grouped by local day (decision D3).
- The `Request::is('api/*')` global scope was replaced by an explicit `Order::visibleToCustomer()`. `display_id` no longer contains HTML; the "Refunded" badge is rendered in Blade.
- Checkout moved from the controller into `CheckoutService` (one transaction, same order of steps).

**Payments**
- `payment_methods` table: country, code, ar/en name, active, receipt required, account name/number, instructions, order. Seeded with cash, zaincash and qicard, with account numbers copied from settings. (ZainCash and Qi Card were later retired; see section 13.)
- `orders.payment_method` is now a string code plus `payment_method_id`. New `payment_status` (unpaid, awaiting_verification, paid, refunded), plus `payment_verified_by` and `payment_verified_at`.
- Cash becomes paid when the order is completed; payment becomes refunded when refunds reach the total.
- Dashboard: payment-methods CRUD, and a payment-status control on the order page (payments permission).
- The settings account numbers and the payment methods stay in sync in both directions (older apps read them from settings).

**Coupons and promotions**
- `CouponEvaluator` is the single calculation behind the cart preview and checkout. It supports `type` (percent/fixed), `min_order_amount`, `max_discount_amount`, `per_user_limit` and a per-coupon `applies_to_delivery` (null means use the store setting).
- The preview accepts `address_id`; with it, the preview matches checkout exactly (tested).
- Promotion precedence: the most specific discount rule wins (product, then brand, then category; oldest first). Flash sales still win over discount rules and nothing stacks; those stay in code.
- Flash sales have an optional `ends_at`. Sales saved without one keep the 1-hour duration.

**Gifts**
- A product gift is an order line (`is_gift`, `discount_rule_id`, price 0) with a stock `sale` movement. It is only given when the stock exists after the cart's own quantities (rows locked). Cancelling returns the gift to stock.
- A brand or category gift target keeps the text description only (see decision D12).

**Loyalty**
- `LoyaltyService` is the single definition of points: earned minus reversals, validity, redemption. The customer API, the dashboard, the customer page and redemption all use it. Totals are computed in SQL, and the admin list is paginated.
- `loyalty_ledger` is append-only (earn / reverse / redeem / expire, with a unique key per event). It was backfilled from orders and reversals, and is written at checkout, cancellation, refund and redemption, plus a daily expiry run.
- Points settings are validated: numeric, ranges, integer points.

**Settings**
- A strict singleton: `Setting::current()`. Both store and update save into the one row, and the create/show/edit/destroy routes are gone. A partial save never clears other fields. Every save is audited (before/after).
- New typed policies: `low_stock_threshold`, `return_window_days`, `customer_can_cancel_pending`, `coupon_applies_to_delivery`, `vaccine_reminders_enabled`, `medication_reminders_enabled`.

**Multi-market**
- `countries` (ISO code, ar/en name, currency, timezone, phone code, week start, active, default). Seeded with IQ (default, IQD) and EG. The default comes from `PHARMACY_COUNTRY`.
- Orders store their country and currency. Payment methods, vaccine schedules, zones and providers can be per country. Changing a country never alters past orders (tested).

**Admin security**
- `AdminRole` enum: super_admin, manager, orders_agent, health_specialist, content_editor, accountant.
- `Permission` enum: orders, refunds, users, health_data, health_content, settings, delivery, payments, reports, catalog, marketing, loyalty, admins. One Gate per permission and `can:` middleware on every dashboard section.
- Health records are visible only to super admins and health specialists, and every view of them is audited. Existing admins default to `super_admin`. `role` is not mass-assignable. There must always be one active super admin. Inactive admins can't sign in and are signed out.
- `admin_audit_logs` (immutable) records order status and payment changes, refunds, points, settings, delivery, payment methods, health content, health-record views, customer (de)activation, admin changes and receipt views.
- The sidebar only shows what the role allows.

**File security**
- Payment receipts, return photos and medication photos are stored on the private `local` disk.
- Dashboard access goes through permission-checked routes; app access goes through owner-only token routes and short-lived signed links (`/files/{type}/{id}`, 60 minutes).
- `full_url` (return photos) and `image_url` (medications) keep their keys but now contain signed links, so the app can still load them by URL without a token.
- Files uploaded before this change are still found on the public disk until `php artisan files:make-private [--delete-public]` moves them.

**Auth**
- Registration now sends the OTP that `/user/verify` expects (it was never sent before), and there is `POST /user/verify/resend`.
- `users.is_active` exists (it used to be written silently into nothing). Inactive customers can't log in, and their tokens get 403 `account_inactive` on every API request.
- Requiring a verified email at login is behind `REQUIRE_VERIFIED_LOGIN` (off by default), so existing users aren't locked out.
- Dashboard: deactivate/reactivate a customer, which also revokes their tokens.

**API / scalability**
- `limit` is capped at 100. Health history is paginated or capped.
- Sidebar badge counts are cached for 60 seconds.
- The points pages paginate and total in SQL.
- The wildcard `PUT /api/user/{medication}` is now constrained to numbers.
- The debug route `/api/test-json` was removed.
- Best-selling products ignore canceled orders and gift lines.
- Reports gained payment-method and delivery/COD breakdowns.

**Data integrity**
- `orders.user_id`, `order_items.*`, `return_requests.user_id/order_id` and `loyalty_point_reversals.order_id` changed from cascade to **restrict**. Deleting a customer can no longer erase financial history; deactivate them instead.
- The `vaccines` columns that production created outside migrations are guarded with `hasColumn`.

---

### A.2. Migrations created

| File | What it does |
|---|---|
| `2026_11_03_000001_create_countries_and_extend_locations` | `countries` (IQ, EG); `cities.country_id/name_ar/name_en/is_active`; `areas.name_ar/name_en/is_active`; `addresses.city_id/area_id`, linked by exact name |
| `2026_11_03_000002_create_delivery_domain` | zones, zone locations, providers, rates, status maps, shipments, shipment events; migrates `delivery_prices` into zones + rates; creates the default internal provider |
| `2026_11_03_000003_create_payment_methods_and_extend_orders` | `payment_methods` (seeded); orders: `payment_method` enum→string, `payment_method_id`, `payment_status`, verification fields, `shipping_address`, `country_id`, `currency_code`, `subtotal`, `discount_amount`, lifecycle timestamps; `order_status_histories`; backfill |
| `2026_11_03_000004_protect_financial_history_foreign_keys` | changes cascade to restrict on the history tables (no rows touched) |
| `2026_11_03_000005_extend_promotions_settings_and_accounts` | coupon attributes, `flash_sales.ends_at`, `order_items.is_gift/discount_rule_id`, typed settings, `users.is_active` (if missing), `admins.role/is_active`, `admin_audit_logs`, `loyalty_ledger` (backfilled) |
| `2026_11_03_000006_create_health_content_and_notifications` | `vaccine_schedule_items` (seeded), `vaccines` snapshot columns, `pregnancy_week_contents` (seeded), `device_tokens`, `notification_deliveries`, `notifications` |

All six migrations have working `down()` methods (tested by rolling back 6 steps and migrating again). The backfills never change an order's money (tested).

### A.3. New tables

`countries`, `delivery_zones`, `delivery_zone_locations`, `delivery_providers`, `delivery_rates`,
`provider_status_maps`, `shipments`, `shipment_events`, `payment_methods`, `order_status_histories`,
`admin_audit_logs`, `loyalty_ledger`, `vaccine_schedule_items`, `pregnancy_week_contents`,
`device_tokens`, `notification_deliveries`, `notifications`.

### A.4. New dashboard features

| Page | Permission |
|---|---|
| Delivery → Shipments (filters: status, needs attention, COD not settled; COD total) | delivery |
| Delivery → Zones & rates (cities/areas per zone, rates with free-over, minimum, ETA, priority, dates, provider) | delivery |
| Delivery → Providers (type, integration, webhook URL and secret, tracking URL, status mapping, unmapped statuses received) | delivery |
| Delivery → Cities & areas | delivery |
| Order page: delivery panel (hand over to a provider with tracking number, add a status, settle COD, create a shipment for old orders), status history, payment status control, gift badge, address snapshot | orders / delivery / payments |
| Payment methods | payments |
| Countries | settings |
| Settings: store policies + health reminder switches | settings |
| Health content → Vaccination schedule (versioned, usage count, soft delete) | health_content |
| Health content → Pregnancy weeks (with image upload, overlap check) | health_content |
| Health data → Reminders sent | health_data |
| Admins & roles (role matrix), Audit log | admins |
| Customer page: deactivate/reactivate | users |
| Coupons: type, minimum order, maximum discount, uses per customer, discounts delivery? | marketing |
| Flash sales: optional end time | marketing |
| Reports: payment methods, deliveries/COD | reports |

### A.5. New APIs

| Endpoint | Purpose |
|---|---|
| `POST /api/user/orders/{id}/cancel` | Customer cancellation (when allowed by settings) |
| `POST /api/user/delivery/quote` `{address_id}` | Delivery fee, free-delivery threshold, ETA |
| `GET /api/v1/user/locations/countries` · `cities?country_id=` · `areas?city_id=` | Address form lists |
| `GET /api/v1/user/payment-methods` | Methods offered at checkout (code, name, receipt required, account) |
| `POST /api/user/devices` · `DELETE /api/user/devices` | Push token registration |
| `GET /api/user/notifications` | Notification history (paginated) |
| `GET /api/user/files/payment-receipt/{order}` · `return-image/{image}` · `medication/{medication}` | Owner-only private files (token) |
| `GET /files/{type}/{id}?signature=…` | Expiring signed links used in `full_url` / `image_url` |
| `POST /api/user/verify/resend` | Resend the verification code |
| `POST /api/webhooks/delivery/{code}` | Provider webhook (HMAC `X-Signature`) |

Commands: `health:send-reminders [--vaccines] [--medications]`, `loyalty:record-expirations`,
`files:make-private [--delete-public] [--dry-run]`.

### A.6. Changed APIs and backward compatibility

A route comparison against `HEAD` (script: the old route files loaded into a separate router)
shows 295 old routes, of which 281 still answer. The 14 missing are deliberate removals:
- `/api/test-json` and `/api/v1/test-json` (debug endpoint);
- the dashboard-only `delivery_prices` CRUD (replaced by Delivery zones; `/dashboard/delivery_prices` redirects);
- the settings `create`/`show`/`edit`/`destroy`/`PATCH` routes (settings is now one row).

`/api/v1/v1/...` is kept; an existing test pins it as a supported prefix.

**Additive only (old fields unchanged):**
- track and order details: `status_label`, `payment_status`, `delivery{...}`; details also adds `subtotal`, `discount`, `currency`, `can_cancel`, and `products[].is_gift`;
- checkout response: `subtotal`, `delivery_fee`, `discount`, `currency`, `payment_status`;
- coupon preview: `delivery_fee`, `delivery_included`, `coupon_type`, `applies_to_delivery`, `code` on errors;
- health: `*_label`, `*_code`, `guideline`, `requires_urgent_care`, `advice`, pregnancy `tip`/`image_url`/`month_number`, BMI `ideal_min_kg`/`ideal_max_kg`;
- history endpoints: `meta`;
- child: `gender_code`; vaccines: `vaccine_name_en`, `description_en`, `schedule_version`, `completed_at`;
- `delivary_prices` rows: `free_over_amount`, `min_order_amount`, ETA;
- points rows: `points_original`, `points_reversed`;
- `code` fields on most 422 responses.

**Behaviour changes to check in the app:**
1. **Delivery fee resolution is exact.** An address whose governorate (or city) isn't exactly a city name in a zone now gets 422 `city_not_covered`; before, it could match by substring. **Check production addresses before deploying** (section 10).
2. **Points.** `points_earned` in `/user/points/valid|expired|notvalid` is now the net after refund reversals, and a partially refunded order's remaining points are listed. They used to be hidden while the admin could still redeem them.
3. **Image URLs** (`full_url`, medication `image_url`) are now signed links valid for 60 minutes, not permanent public URLs. Apps that cache image URLs for longer than an hour must refetch.
4. **Stricter health validation:** unknown BMI `unit`, future measurement dates, absurd pregnancy/ovulation/child dates. The old code silently accepted these and produced wrong results.
5. **Message language.** Messages follow `Accept-Language` when the app sends exactly `ar` or `en`; without the header they stay Arabic, as before. Legacy comparison fields (`phase`, `evaluation`, `status_meaning`, BMI `status`, `trimester`, `month_info`, `summary`, `baby_*`) are always Arabic and byte-identical to before.
6. **Deactivated accounts.** Their tokens get 403 `account_inactive`. Login answers with real status codes: 200 `{token, user}` on success, 401 for wrong credentials, 403 with `code` (`account_inactive`, `email_not_verified`) for refusals. The old `original` wrapper (always HTTP 200) is gone: the app must read `token`, not `original.token`.
7. **Payment methods.** Checkout accepts the active payment-method codes of the store's country; the same three codes as before are seeded.
8. **Coupon preview.** It now applies the coupon's minimum order and caps. Without `address_id` it still covers the items only. Send `address_id` to get exactly what checkout will charge.

### A.7. Tests added

Baseline: 304 passing, 2 skipped. **Now: 406 passing, 2 skipped (the same 2), 2,629 assertions.**

| File | Covers |
|---|---|
| `Delivery/DeliveryPricingTest` | exact match vs substring, legacy linking, area-over-city, free-over/minimum/priority/effective dates/inactive zone, checkout fee + snapshot + shipment, quote API, legacy endpoint shape, location and payment lists, address location validation |
| `Delivery/ShipmentLifecycleTest` | events drive the order through one path, idempotency, final shipments, failures, cancel → shipment canceled + stock once, dashboard assign/events/COD, tracking API, immutability, status mapping |
| `Delivery/DeliveryWebhookTest` | bad/missing signature, mapped statuses, duplicates, batch, unmapped status quarantine, unknown shipment, inactive provider, manual provider refuses webhooks |
| `Orders/OrderPoliciesTest` | customer cancellation policy, country/currency snapshot and per-market payment methods, restricted customer delete, return window, plain display_id, payment verification + permission + audit, net revenue, listing cap, best-selling excludes canceled |
| `Orders/PaymentsAndFilesTest` | payment methods from the DB, receipt rule, private storage, owner/permission access, signed links (tamper/expiry), legacy public files + move command, private medication photos |
| `Orders/LoyaltyLedgerTest` | ledger for earn/reverse/redeem/cancel/expire, API = dashboard, full refund → refunded, immutability, settings validation and partial saves |
| `Pricing/CouponsGiftsAndPromotionsTest` | preview = checkout (with delivery), items-only/fixed/minimum/cap, store default for delivery, per-user limit, flash sale end time, rule specificity, gift line + stock + cancel, out-of-stock gift |
| `Security/AdminPermissionsTest` | 17 role/page cases, default super_admin + menu, health-view and settings audit, inactive admin, last super admin, role not mass-assignable, settings singleton |
| `Security/AccountActivationTest` | registration OTP → verify, resend, verified-login flag, deactivation (login, token revocation, stray tokens) |
| `Health/HealthContentTest` | seeded schedule = old schedule, versioning and snapshots, removed/inactive items, country override + fallback, child validation + gender codes, permission, pregnancy content from the DB, BP crisis, BMI unit, date validation, localized labels vs legacy Arabic, history paging |
| `Health/HealthRemindersTest` | vaccine reminder once inside lead time, setting off, vaccine given meanwhile + idempotent job, medication window, device API + notification history |
| `Database/MigrationBackfillTest` | runs the new migrations on legacy-shaped data: zones from delivery prices, address linking, order backfill without money changes, payment methods, history, ledger, health seeds, restrict FKs; rollback of all six |
| `Admin/AdminPagesTest` (extended) | 12 new dashboard pages render with data |

Updated existing tests (intentional changes, each commented in the test):
- `BuildsShop::makeDeliveryPrice` now builds a zone and rate;
- `PointsAndOrderReadsApiTest` (points now net);
- `RouteProtectionTest` (webhook, resend and location lists are public by design);
- `SeederTest` (the audit log is written only by admins);
- `MedicationApiTest` (warm-up before counting queries);
- `AdminPagesTest` (the old delivery-prices URL redirects).

### A.8. Test results and checks run

- Full suite: `~/.config/herd/bin/php84/php.exe artisan test` → **406 passed, 2 skipped**.
- Date-, clock- and concurrency-sensitive suites (Delivery, Health, Orders, Pricing, Returns, RowLocking): 5 consecutive runs, all green (151 tests each).
- `php -l` on every PHP file in app, database, routes, tests, config, lang and bootstrap: clean. Two pre-existing PHP 8.4 implicit-nullable deprecations in `ProductService` were fixed.
- `route:list`, `view:cache` (every Blade template compiles), `config:cache` and `schedule:list`: OK.
- Migrations: fresh migrate and full rollback/re-migrate of the six new migrations on SQLite; backfill on legacy-shaped data.
- Route compatibility against `HEAD`: see section 6.
- Re-search for the audit's hardcoded rules:
  - `governorate LIKE`, `Setting::first()`, the PHP vaccine array, the payment enum in validation, `addHours(3)`, the global API scope, `Carbon::today()` in app code: none left;
  - Arabic string literals in controllers: none (the only ones left are the seed content in `DefaultHealthContent`);
  - `FlashSale::DURATION_MINUTES` remains on purpose, as the default for sales without `ends_at`.

### A.9. Intentionally left in code, and why

| Rule | Why it stays in code |
|---|---|
| BMI, blood sugar, blood pressure thresholds; luteal phase/fertile window/chances; Naegele rule, 42 weeks, trimesters | Clinical rules from published guidelines. A database typo would silently mis-classify every reading. They change through code review, with boundary tests; `guideline` is returned with each result. |
| Order and shipment status graphs | Stock restore, points and refunds depend on them. |
| Money rounding, refund caps, coupon never above the base, one promotion per price (flash sale beats a rule) | Financial invariants. |
| OTP (6 digits, 10 min, 5 attempts), rate limits, signed-link TTL, listing max 100 | Security limits must not be weakened from an admin panel. |
| Cycle bounds 21–35 / period 3–10 | Clinical normal range. |
| Legacy Arabic strings in comparison fields (including their typos) | Apps may compare against them; the `*_code` fields are the stable replacement. |
| Flash sale 1-hour default | Behaviour of every sale saved before `ends_at` existed. |
| `delivery_prices` table, `/api/v1/v1` prefix, free-text blood sugar condition | Kept for compatibility; removing any of them needs a coordinated app release. |
| Legacy `Discount` model | Still used as a product `saleable` (the audit wrongly listed it as unused). |

### A.10. Remaining issues and required pre-deployment steps

**Before deploying:**
1. **Check production addresses against delivery zones.** For every distinct `addresses.governorate`/`city`, confirm it exactly matches a city name in a zone; otherwise those customers get 422 at checkout. The migration logs nothing for unmatched addresses. Run on a copy:

   ```sql
   SELECT governorate, city, COUNT(*) FROM addresses WHERE city_id IS NULL GROUP BY governorate, city;
   ```

   Then add the missing names as cities, or as Arabic/English names of existing cities, under Delivery → Cities & areas.
2. Run the migrations on a **staging copy of production** first. Production created `children` and `vaccines` outside migrations; the new migration guards their columns, but verify.
3. After deploying, run `php artisan files:make-private` (copies only), check the files, then run it with `--delete-public`.
4. Assign roles to existing admins: they are all `super_admin` until changed.
5. Configure push notifications (`PUSH_DRIVER=fcm`, `FCM_CREDENTIALS`) and make sure the scheduler and a queue worker are running. Until then, reminders are written to the log.

**Known limitations:**
- Localization covers orders, cart, checkout, returns, delivery, payments, health, medication and points. Catalog, brand, category, address and user modules still use their existing `__()` keys with no language files (unchanged from before) and some English literals.
- The FCM gateway was not exercised against Firebase; that needs real credentials. The log driver and the channel are tested.
- Order status values in Blade views are still string literals for display; the logic uses the enum.
- The report date filter selects by UTC timestamps, while grouping is by local day. Up to 3 hours at each edge of the range can fall on the neighbouring day.
- Orders placed before this change have no shipment; the order page offers "Create shipment".
- Points are still redeemed per order by an admin. The ledger is the complete audit trail and the basis for a future balance-based programme, but balances are computed from orders + reversals (`LoyaltyService`).
- Not every admin CRUD is audited (products, banners and categories aren't); all sensitive actions are.
- The dashboard UI is English-only, as before.

### A.11. Business decisions still required

| # | Decision | Current behaviour |
|---|---|---|
| D4 | May customers cancel pending orders? | Setting, off |
| D5 | Is a coupon usable again after its order is canceled? | Stays used |
| D9 | Do coupons discount delivery? | Setting, on (as before) |
| D10 | Does `points_validity_days = 0` mean "no expiry"? | Expires the same day (as before) |
| D11 | **Which market is the store?** Seeders contain Egyptian governorates, production has Iraqi cities and wallets, and the dashboard currency label was EGP | Default country IQ / IQD (`PHARMACY_COUNTRY`); confirm, and fix the seeded city list if needed |
| D12 | Brand/category gift rules: which product is handed over? | Description only, no order line |
| D13 | Partially refunded orders are hidden from the customer's order list (D3), but their remaining points are shown | As described |
| — | Medication reminders: the app may already remind locally | Off by default |
| — | When to turn on `REQUIRE_VERIFIED_LOGIN` | Off until the app shows the OTP step |
| — | Return window length | Unset (no limit) |

The behaviour behind each decision is also documented in `docs/business-rules.md` (updated).

### A.12. Files changed

**New (main):**
- Enums: `app/Enums/*`
- Services: `app/Modules/Delivery/**` (services, providers, API, admin, requests), `app/Modules/Orders/Services/{CheckoutService,OrderWorkflow,GiftResolver}.php`, `app/Modules/Orders/Requests/CheckoutRequest.php`, `app/Modules/Coupon/Services/CouponEvaluator.php`, `app/Modules/Points/Services/LoyaltyService.php`, `app/Modules/Payments/*`, `app/Modules/Reports/Revenue.php`
- Admin and files: `app/Modules/Admin/*`, `app/Support/PrivateFiles.php`
- Health: `app/Modules/HealthService/{Admin/*,Requests/*,DefaultHealthContent.php,DeviceTokenController.php,Services/VaccineScheduleService.php,Services/HealthReminderService.php}`
- Notifications, jobs, commands, middleware: `app/Notifications/**`, `app/Jobs/SendHealthReminder.php`, `app/Console/Commands/{MakeFilesPrivate,RecordLoyaltyExpirations,SendHealthReminders}.php`, `app/Http/Middleware/EnsureAccountIsActive.php`
- Models: 16 new models
- Translations: `lang/{ar,en}/*`
- Database: 6 migrations
- Views: `resources/views/dashboard/{delivery,countries,locations,payment-methods,health-content,admins}/*` and `health-results/notifications.blade.php`
- Tests: 11 new test files

**Modified:**
- Controllers: Order, ReturnRequest, Cart, HealthService, Medication, Points (API and admin), Setting, Customer, DeliveryPrice, Reports, Home, User, Login (admin), Inventory, Product, ProductReview
- Services and requests: Address, Coupon, FlashSale, Setting, User services and requests; ReturnDecisionService; HealthService
- Models: Order, OrderItem, Address, City, Area, Coupon, FlashSale, Setting, User, Admin, Child, Vaccine, ReturnRequestImage, Product
- Medication resource and service
- Routes, config and bootstrap: `routes/{api,web,console}.php`, `bootstrap/app.php`, `config/{pharmacy,services}.php`, `AppServiceProvider`, `SanitizeListingParameters`, `GeneralHelper`
- Seeders: DeliveryPrice, Setting, Demo/Order, Demo/HealthData
- Views: sidebar; orders, returns, customers, coupons, flash sales, points, settings and reports
- `docs/business-rules.md`

**Deleted (dead code):** `app/Models/VaccinationSchedule.php`; the unused `DeliveryPrice` service, repository, requests and resource; `resources/views/dashboard/DeliveryPrice/index.blade.php`.

**Note on files you were already editing:** `HealthService.php` and `HealthServiceController.php` had uncommitted changes when this work started (store-clock "today", phase codes, pregnancy validation). The rewritten versions keep all of that behaviour, and its existing tests pass.

---

### A.13. ZainCash retirement

**ZainCash has been removed as an active payment provider. Historical ZainCash records are preserved.**

This is integrated into the existing payment architecture (`payment_methods`, `PaymentMethod::ACTIVE_PROVIDERS`, `PaymentService`). There is no second payment architecture.

| What | Change |
|---|---|
| Setting | `settings.zaincash` (the wallet number) dropped by migration `2026_11_05_000001_remove_zaincash_settings`. The number is first copied onto the legacy `zaincash` payment-method row if that row has none. `down()` restores the column from that row. |
| Settings API | The `zaincash` key stays, always `null`, so older apps stop offering ZainCash instead of failing to parse. It isn't fillable; sending it through the dashboard does nothing. |
| Checkout, payment-methods API | Already restricted to COD and configured Paymob. ZainCash is refused even if its active flag is forced in the database (tested). |
| Dashboard | Retired methods moved to a read-only "Retired methods (history only)" table with a link to their past orders. Updating a retired method is refused, and creating a `manual_transfer` method is rejected. The orders list can be filtered by payment method, including retired ones. |
| Env / config | No ZainCash variables existed in `.env.example` or `config/*`; nothing to remove. The real `.env` was not opened or modified. |
| Preserved | Orders keep `payment_method = 'zaincash'`, `payment_method_id`, `payment_image`; `payments` rows (`provider = manual_transfer`) and their events and receipts; the legacy `payment_methods` row (inactive, `is_legacy`, with the old number). Visible in order history (app), the order page, the orders filter and reports. A pending historical receipt can still be verified. |
| Not in scope | Qi Card is retired the same way, but its `settings.keycard` column is kept (only ZainCash was requested). |

Tests: new `Orders/ZainCashRetirementTest` (5 tests), plus updates to `CheckoutTest`, `PaymentsAndFilesTest`, `OrderPoliciesTest`, `DeliveryPricingTest` and `MigrationBackfillTest`, and a `makeHistoricalZainCashOrder()` fixture in `BuildsShop`.
