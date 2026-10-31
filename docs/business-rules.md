# Orders, stock and money — how the system behaves

Reference for developers and the business owner. "Decision needed" marks behaviour that is
implemented as it was originally written but that the business has not confirmed.

## 1. Stock

Stock is kept per product size (`product_sizes.stock`). There is no purchase/supplier module;
stock only enters the system through the admin product screens.

Every change to stock goes through `ProductSize::moveStock()` / `ProductSize::setStock()`, which
lock the size row, refuse to go below zero and write one row to `stock_movements` in the same
database transaction.

| Event | Movement type | Stock change | Recorded by |
|---|---|---|---|
| Admin creates a product / adds a size | `initial` | 0 → entered value | admin |
| Customer checks out | `sale` | − quantity, one row per order line | customer |
| Admin cancels an order | `cancellation` | + quantity, one row per order line | admin |
| Admin changes the stock field | `adjustment` | + / − the admin's change | admin |
| Admin removes a size that was sold before | `adjustment` | → 0 (row kept for history) | admin |
| Return accepted, units received back | `return_quarantine` (bucket `quarantine`) | `quarantined_stock` + units; sellable `stock` unchanged (D1) | admin |

Ledger rows are append-only: the `StockMovement` model refuses updates and deletes, and no admin
screen offers either. Each row has a `bucket`: `sellable` (describes `stock`) or `quarantine`
(describes `quarantined_stock`).

A unique key on `(type, reference_type, reference_id)` makes it impossible to record the sale
or the cancellation of the same order line twice, even if a request is repeated.

**Admin stock edits.** The edit form remembers the stock it showed (`stock_original`).
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
* `completed` and `canceled` are final.
* Only admins change status (dashboard). Customers cannot cancel (decision D4).
* Cancel returns every line's quantity to stock exactly once.

Checkout (one database transaction): lock the cart and the sizes → check stock → recalculate
every price from the catalogue → gifts → points → coupon → create order and lines → stock
`sale` movements → empty the cart → record coupon use.

## 3. Money

* Amounts are `DECIMAL(10,2)` in the database and calculated in integer minor units
  (`App\Support\Money`), rounding half up to 0.01. No totals are accepted from the app.
* Item price = size price after **one** promotion, from one function (`Product::priceForSize`) used by
  listing, details, cart, coupon preview and checkout (decision D7):
  1. a flash sale, only while it is active **and** the store clock is in `[start, start + 60 min)`
     (live exactly at the start, over exactly at the end);
  2. otherwise the first active discount rule for the product, brand or category;
  3. no stacking of the two. The coupon then applies to the order total at checkout.
* Promotion and coupon dates are local wall-clock times. They are compared with `StoreClock::now()`
  (timezone `PHARMACY_TIMEZONE`, default Asia/Baghdad = the +3 hours checkout used before).
* Order lines keep `price_at_time`, `total_price`, and from now on `product_name_ar/en` and `size_label`.
* Order total = items + delivery fee − coupon. The coupon percentage applies to items + delivery.
* Points = floor(items subtotal ÷ `spend_x`) × `get_y`, on the subtotal before delivery and coupon.
* There is no payment gateway: `payment_method` is recorded, with a receipt image for
  zaincash/qicard. No amounts are paid through the API, so overpayment cannot happen here.

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
* The order is then hidden from the customer's order list and excluded from dashboard revenue
  (unchanged; D3 still open). The customer can still request a further partial return on it.

## 5. Loyalty points

* Earned at checkout, valid for `points_validity_days` days, redeemed by the admin.
* Canceled orders do not count and cannot be redeemed; redeeming twice does nothing.
* **Refunds (decision D2).** `orders.points_earned` is never changed. Each accepted refund writes one
  `loyalty_point_reversals` row (unique per return request, never updated or deleted):
  * point base = what was paid for products = `total_price × subtotal ÷ (subtotal + delivery)`;
  * points reversed so far = round half up(`points_earned × cumulative refunds ÷ base`), capped at
    `points_earned` (everything once the refunds reach the base). Rounding happens once on the
    cumulative amount, so 10 % + 20 % + 70 % adds up exactly;
  * this refund's row = that target − what was already reversed.
  * An order's remaining points = `points_earned` − its reversals; the admin points page and
    redemption use that. If the points were already redeemed, the row is marked `redeemed` and the
    customer profile shows them as **owed**. Offsetting the debt against future points is not
    automatic (a business decision).

## 6. Decisions needed

| # | Question | Current behaviour |
|---|---|---|
| D1 | ~~Returned stock back on sale?~~ **Decided:** quarantined, never resold automatically | Implemented |
| D2 | ~~Refunded orders lose points?~~ **Decided:** proportional to the refunded amount | Implemented |
| D3 | Partial refund: should revenue drop by the refunded amount instead of the whole order? | Whole order is excluded once any return is accepted |
| D4 | May customers cancel their own pending orders? | Only admins can cancel |
| D5 | When an order with a coupon is canceled, can the customer use the coupon again? | Coupon stays used |
| D6 | ~~Sold-out products visible?~~ **Decided:** visible, marked out of stock, not purchasable | Implemented |
| D7 | ~~Flash sale window?~~ **Decided:** one price everywhere; flash sale only inside its window | Implemented |
| D8 | Admin stock edits apply the difference (correct for deliveries). Is a "physical count" mode needed that sets an exact number? | Difference |
| D9 | Should the coupon discount the delivery fee too? | Yes (unchanged) |
| D10 | `points_validity_days = 0`: the code comment says "no expiry", but it means "expires today". | Expires today (unchanged) |
