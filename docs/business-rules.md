# Orders, stock and money — how the system behaves

Reference for developers and the business owner. "Decision needed" marks behaviour that is
implemented as it was originally written but that the business has not confirmed.

**The store currently operates in Egypt only and uses EGP.** There is no country selection for
customers or admins and no multi-country behaviour: addresses, delivery zones and rates use Egyptian
governorates/areas, prices and payments are in EGP, and the store clock is Egyptian local time
(`Africa/Cairo`, UTC+2, UTC+3 in summer time). Orders record `country_id` (Egypt) and `currency_code`
(EGP) at checkout; recorded values are never rewritten.

## 1. Stock

Stock is kept per SKU (`product_sizes`: size, colour, SKU, barcode, cost) **and per warehouse**
(`inventory_balances`), in five buckets:

| Bucket | Meaning | Sellable? |
|---|---|---|
| `on_hand` | Physically in the warehouse, in sellable condition | yes, minus reserved |
| `reserved` | Part of `on_hand` held for customer orders not dispatched yet | no |
| `damaged` | Found or received damaged; awaiting write-off or return to the supplier | no |
| `quarantined` | Returned by customers (or undelivered); awaiting inspection (D1) | no |
| `in_transit` | Dispatched to this warehouse by a transfer, not received yet | no |

`product_sizes.stock` (what the app shows and checkout checks) = Σ (`on_hand` − `reserved`) over
**sellable** warehouses. `quarantined_stock` = Σ `quarantined`. Both are projections written
only by `InventoryService`, in the same transaction as the balances; `php artisan inventory:verify`
checks them daily.

Every change goes through `InventoryService`: it locks the SKU row first (one lock order, so
concurrent orders wait instead of overselling or deadlocking), refuses any bucket below zero and
`reserved` above `on_hand`, and writes one immutable `stock_movements` row per bucket changed,
with before/after, warehouse, reference, actor and reason. Each business event has an
idempotency key (unique in the database): repeating it changes nothing.

| Event | Movement type | Buckets |
|---|---|---|
| Size created with stock / opening balance | `initial` / `opening_balance` | on_hand + |
| Goods received from a supplier | `purchase_receipt` | on_hand + (damaged + for broken units) |
| Customer checks out | `reservation` | reserved + (default sellable warehouse first, then others) |
| Order dispatched (`on_delivery`) or completed | `fulfillment` (`gift` for gift lines) | on_hand −, reserved − |
| Order canceled before dispatch | `reservation_release` | reserved − |
| Order canceled after dispatch | `cancellation_return` | quarantined + |
| Shipment returned undelivered | `shipment_return` | quarantined + |
| Return accepted, units received back | `return_quarantine` | quarantined + |
| Quarantine inspected | `return_restock` / `return_damaged` / `write_off` | quarantined → on_hand / damaged / out |
| Units found damaged; damaged destroyed | `damage`; `write_off` | on_hand → damaged; damaged − |
| Transfer dispatched / received / closed short | `transfer_out` + `transfer_dispatch` / `transfer_in` / `transfer_loss` | on_hand → in_transit → on_hand |
| Stock count completed | `count_correction` | on_hand ± (counted − on hand at completion) |
| Goods returned to a supplier | `supplier_return` | on_hand / damaged / quarantined − |
| Admin adjustment (reason required) | `adjustment` | on_hand ± |

Rows written before warehouses existed keep `warehouse_id = null` and bucket `sellable` /
`quarantine`; the migration added `opening_balance` rows so the ledger sums to the balances.

**Purchasing.** A purchase order never changes stock; only receipts do (partial receipts allowed,
over-receiving refused, weighted average cost updated). Supplier balance = invoices − payments −
dispatched return credits.
**Admin stock edits (product form).** Changes apply to the default warehouse; the warehouse-aware operations are on Inventory → SKU. The edit form remembers the stock it showed (`stock_original`).
If the admin did not touch the field, stock is left alone, so sales made while the form was open
are kept. If the admin changed it (e.g. 10 → 60 after a delivery), that difference (+50) is
applied to the current stock. A change that would make stock negative is rejected with
"reload and try again".

### What the customer sees when stock runs out

| Situation | Behaviour |
|---|---|
| One size sold out | Size stays in `size_options` with `in_stock: false`; it is left out of `available_sizes` (unchanged contract); adding it returns 400 |
| Every size sold out | Product stays visible with `stock_status: "out_of_stock"` / `in_stock: false` (decision D6); deleted products stay hidden |
| Quantity asked > stock | Add-to-cart caps the quantity at the stock |
| Cart line sold out | `GET /cart` removes the line and lists it under `adjustments` (`out_of_stock`) |
| Cart quantity > stock | `GET /cart` lowers it and lists it under `adjustments` (`quantity_reduced`) |
| Edit a sold-out line | 400 "out of stock" (it used to save quantity 0, or crash with a 500) |
| Checkout with sold-out lines | 422 listing each line; nothing is changed |
| Two customers buy the last unit | The size row is locked; the second checkout gets 422 |
| Past orders | Still show the product after it sells out or is deleted |

## 2. Order lifecycle

```
pending ──► confirmed ──► on_delivery ──► completed
   │            │              │
   └────────────┴──────────────┴──► canceled
```

* Status can only move forward; steps may be skipped (e.g. pending → completed for pickup).
* `completed` and `canceled` are final. The graph is protected code (`App\Enums\OrderStatus`).
* Every change goes through `OrderWorkflow::transition()`: dashboard, customer cancellation and
  shipment events alike. Each change writes `order_status_histories` (from, to, actor type, actor id).
* Customers may cancel their own **pending** orders only when `settings.customer_can_cancel_pending`
  is on (`POST /api/user/orders/{id}/cancel`). Off by default (decision D4).
* Cancel returns every line's quantity (gift lines included) to stock exactly once, cancels open
  shipments and writes a ledger "reverse" for the points.
* Orders keep a snapshot of the delivery address (`shipping_address`), the country and the currency.

### Delivery

```
address ─► city (city_id, or exact name of governorate/city) ─► zone (area zone wins) ─► rate
```

* The active rate in effect (store clock) with the highest priority prices the delivery:
  `fee`, free above `free_over_amount` (items subtotal), refused below `min_order_amount`.
* A shipment is created at checkout for address orders. Its status (`ShipmentStatus`) moves the order:
  picked_up / in_transit → on_delivery, delivered → completed (cash then counts as paid).
  failed / returned only flag the shipment for a person.
* Shipment events are immutable and idempotent (unique key). Dashboard actions and provider webhooks
  (`POST /api/webhooks/delivery/{code}`, HMAC signed) both go through `OrderFulfilment`.
* Provider statuses are mapped per provider (`provider_status_maps`); an unmapped status is stored,
  flagged, and changes nothing.

Checkout (one database transaction): lock the customer, the cart and the sizes → check stock →
price everything with the promotion engine (items, gifts, coupon, delivery; limited promotions and
the coupon locked) → create order, lines and gift lines, promotion snapshots, usage reservations,
stock reservations → empty the cart. See `docs/marketing-promotions.md`.

## 3. Money

**Payment methods.** Only cash on delivery and Paymob can be offered. ZainCash and Qi Card are
legacy (history only). Every order has `payments` rows (one per attempt); `orders.payment_status`
is projected from the latest one by `PaymentService`.

- *Paymob:* paid only on verified provider evidence (HMAC, optional API confirmation, matching
  amount/currency/reference). No admin can mark it paid. Unpaid after `PAYMOB_PAYMENT_TTL_MINUTES`
  → expired, order canceled, stock released. An online order cannot be confirmed before it is paid.
- *Cash on delivery:* collected when the courier delivers (or at the counter on completion), partial
  collections add up; **collection is not settlement** — cash counts as received by the pharmacy
  only when a COD settlement records it (with any shortfall).
- *Refunds:* an accepted return or a canceled paid order creates a pending refund; it changes the
  payment and the order (`refund_status`) only once paid back (Paymob API, or cash/manual with a
  reference). Refunds never exceed what was received.

* Amounts are `DECIMAL(10,2)` in the database and calculated in integer minor units
  (`App\Support\Money`), rounding half up to 0.01. No totals are accepted from the app.
* Every price comes from the promotion engine (`App\Modules\Promotions\Engine\PromotionEngine`), used by
  listing, details, cart, coupon preview, checkout and the dashboard preview (decision D7). Item price =
  size price after **one** item promotion unless promotions are explicitly stackable:
  1. a live flash sale (active **and** the store clock in `[start, end)`) wins;
  2. then higher priority, then the more specific target (variant > product > brand > category), then the older rule;
  3. flash sales never stack; discount rules stack only when both are marked stackable.
  Gifts, Spend X rewards, free delivery and coupons follow; the full order and the conflict rules are in
  `docs/marketing-promotions.md`.
* Promotion and coupon dates are local wall-clock times. They are compared with `StoreClock::now()`
  (Egyptian local time: `PHARMACY_TIMEZONE`, default `Africa/Cairo`).
* Order lines keep `price_at_time`, `total_price`, and from now on `product_name_ar/en` and `size_label`.
* Order total = items (after item promotions) + delivery fee − automatic order discounts − coupon − delivery
  discount. The coupon discount formula (`CouponEvaluator`) serves the cart preview and checkout: percent or fixed, optional minimum order and maximum discount, per-customer
  limit. Whether it also discounts delivery is per coupon, else `settings.coupon_applies_to_delivery`
  (default on, as before — D9). The preview includes delivery when the app sends `address_id`.
* Among discount rules at equal priority, the most specific wins (variant, product, brand, category; oldest first).
* A flash sale lasts until its `ends_at` (required in the dashboard; sales saved before carry the one hour
  they always had, written onto the row by the 2026_11_07 migration).
* Promotional gifts are cart lines the backend adds and removes (price 0, not editable) and order lines
  at price 0 that are reserved and leave stock like any line. A gift must name a product (and optionally
  a variant); brand/category gift targets are a configuration error.
* Points = floor(items subtotal ÷ `spend_x`) × `get_y`, on the subtotal before delivery and coupon.
* Payment methods are data (`payment_methods`), all in EGP. Only cash on delivery and Paymob can be
  offered (`PaymentMethod::ACTIVE_PROVIDERS`); Paymob only once it is configured.
* **ZainCash has been removed as an active payment provider. Historical ZainCash records are preserved.**
  * Checkout, the payment-methods API and the dashboard no longer offer or configure it; its
    `payment_methods` row is kept as *retired* (inactive, `is_legacy`), read-only, with the old wallet number.
  * The ZainCash setting (`settings.zaincash`) was removed by migration `2026_11_05_000001`; the settings
    API still returns `zaincash: null` so older apps stop offering it.
  * Past ZainCash orders keep `payment_method = 'zaincash'`, their `payment_method_id`, receipt and
    `manual_transfer` payment; they appear in order history, the dashboard (filterable), reports and
    refunds, and a pending receipt can still be verified by an admin. Historical data is never rewritten.
  * Qi Card is retired the same way (its `settings.keycard` column is still in place).
* `payment_status`: unpaid → paid (cash on completion, Paymob on confirmation); historical transfer
  receipts: awaiting_verification → paid/failed by an admin. Refunds reach the total → refunded.
  The verifying admin and time are recorded.
* Revenue = completed orders − refunds approved on them, grouped by the store's local day (D3).

## 4. Returns and refunds

* A customer can request a return on their own, non-canceled order.
* The sum of all pending + accepted refund amounts for an order cannot exceed the order total.
* The admin accepts or rejects a pending request once (row lock; a repeat is refused); the customer
  can cancel a pending one. Accepted refunds of an order never add up to more than its total.
* Accepting (one transaction, `ReturnDecisionService`):
  1. records the refund the admin approves (`approved_amount`; pay it outside the system);
  2. puts the units received back, per order line, into **quarantine** (`quarantined_stock`). They are
     never sellable; no more units than were ordered can be returned (decision D1). Releasing
     quarantined stock is **not implemented**: it needs its own authorised, audited process;
  3. reverses loyalty points in proportion to the money refunded (decision D2, section 5).
* The order is then hidden from the customer's order list (unchanged). Revenue drops by the approved
  refund, not by the whole order (D3). The customer can still request a further partial return on it.
* Refunds paid through Paymob: a request without a usable answer is **unknown** and is never sent
  again by itself. It becomes **failed** (sendable again) only when Paymob declines that refund, or
  when a second admin records, from the Paymob dashboard, that it was not paid out
  (`refunds.resolve`, audited); it becomes **succeeded** when Paymob shows it paid out.
  `payments:reconcile-refunds` asks Paymob every 15 minutes; a refund left "processing" by a crashed
  worker for 30 minutes is treated as unknown.
* When `settings.return_window_days` is set, only completed orders within that many days of
  completion can be returned. Unset = no limit (original behaviour).

## 5. Loyalty points

The full programme (settings, lots, FIFO, rewards, redemptions) is in [loyalty.md](loyalty.md).

* Points are a ledger (`loyalty_ledger`), never a stored balance. An order earns a **lot** at checkout
  (rules on Loyalty › Settings); the lot is *pending* until the points become usable (default: when the
  order is completed, as before), then valid for `points_validity_days` (or never, if set to never
  expire). Redemptions take the soonest-expiring points first; expired points are refused at the
  moment of use whatever the daily expiry run has done.
* Canceling an order voids its earned points and gives back any points used to pay for it. Every
  movement is keyed by its event, so repeating it (double click, retry, two admins) does nothing.
* **Refunds (decision D2).** `orders.points_earned` is never changed. Each accepted refund writes one
  `loyalty_point_reversals` row (unique per return request, never updated or deleted):
  * point base = what was paid for products = `total_price × subtotal ÷ (subtotal + delivery)`;
  * points reversed so far = round half up(`points_earned × cumulative refunds ÷ base`), capped at
    `points_earned` (everything once the refunds reach the base). Rounding happens once on the
    cumulative amount, so 10 % + 20 % + 70 % adds up exactly;
  * this refund's row = that target − what was already reversed.
  * The reversal is taken from the order's own lot, then from the customer's other usable points
    (oldest first). A balance never goes negative: whatever is still missing (points already spent)
    is recorded on the entry (`metadata.shortfall`) and shown as **owed** on the customer profile;
    the row is marked `redeemed`. Offsetting the debt against future points is not automatic.
  * Points the customer paid part of the order with come back in the same proportion.

## 5b. Supplier payments

* Against an invoice: never more than is left on it.
* On account: never more than the supplier is owed, unless the admin ticks **advance payment**
  (`supplier_payments.is_advance`); the balance then becomes a credit. Advances can be switched off
  (`PHARMACY_SUPPLIER_ADVANCE_PAYMENTS=false`). A resubmitted form records the payment once.

## 6. Decisions needed

| # | Question | Current behaviour |
|---|---|---|
| D1 | ~~Returned stock back on sale?~~ **Decided:** quarantined, never resold automatically | Implemented |
| D2 | ~~Refunded orders lose points?~~ **Decided:** proportional to the refunded amount | Implemented |
| D3 | ~~Partial refund revenue?~~ **Implemented:** revenue drops by the approved refund. The order stays hidden from the customer's list (confirm) | Net revenue |
| D4 | May customers cancel their own pending orders? Now a setting | Off (only admins), as before |
| D5 | When an order with a coupon is canceled, can the customer use the coupon again? Now a setting (`coupon_release_on_cancel`) | **Yes** (use released) by default; refunds after delivery keep the use |
| D6 | ~~Sold-out products visible?~~ **Decided:** visible, marked out of stock, not purchasable | Implemented |
| D7 | ~~Flash sale window?~~ **Decided:** one price everywhere; flash sale only inside its window | Implemented |
| D8 | Admin stock edits apply the difference (correct for deliveries). Is a "physical count" mode needed that sets an exact number? | Difference |
| D9 | Should the coupon discount the delivery fee too? Now a setting and a per-coupon choice | Yes (unchanged default) |
| D10 | ~~`points_validity_days = 0`?~~ **Decided:** 0 still means "lapse the day they become usable"; "never expires" is its own setting (`points_never_expire`) | Implemented |
| D11 | ~~Which market is the store in?~~ **Decided:** Egypt only, EGP. Delivery zones created from old production delivery prices with non-Egyptian names (e.g. Baghdad, Karbala) are kept as data; an admin should deactivate them | Egypt / EGP / Africa/Cairo |
| D12 | ~~Brand/category gift targets?~~ **Decided:** a gift names a product (and optionally a variant); brand/category gift rules are flagged as errors and cannot be activated | Implemented |
| D13 | Partially refunded orders are hidden from the customer's order list, but their remaining points are shown | As described |
