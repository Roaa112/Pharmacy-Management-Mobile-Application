# Marketing and promotions — how the system behaves

Reference for developers and the business owner. It describes the promotion engine, every
promotion type, how conflicts are resolved, how gifts enter and leave the cart, and what
happens to promotions when orders are canceled or returned. Amounts are EGP; all dates are the
store's wall-clock time (`Africa/Cairo`, see `App\Support\StoreClock`).

## 1. One engine for every price

`App\Modules\Promotions\Engine\PromotionEngine` is the only code that calculates a promotion.

| Caller | What it asks the engine |
|---|---|
| Product listings, product page (`Product::priceForSize`, `final_price`, `size_options`) | catalogue price of one unit (item promotions without cart conditions) |
| Cart (`GET/POST /api/user/cart`, update, remove) | the whole cart; then gift lines are synced (`CartPromotionSync`) |
| Coupon preview (`POST /api/user/cart/apply-coupon`) | the whole cart with the coupon (and delivery when `address_id` is sent) |
| Delivery quote (`/api/user/delivery/quote`) | the items subtotal after item promotions |
| Checkout (`CheckoutService`) | the whole cart, under row locks, with live usage counts |
| Dashboard preview (`/dashboard/discounts/{id}/simulate`) | a sample cart with one promotion treated as live |
| Customer offers screen (`GET /api/v1/user/promotions`) | the live promotions (descriptive only) |

Prices always come from the database (`product_sizes.price`). Prices stored on cart rows are a
cache that is overwritten on every recalculation; nothing the app sends is used as a price.
Arithmetic is in integer minor units (piastres), rounding half up to 0.01.

The result (`PricingResult`) contains: each line's original and final unit price and the
promotions applied to it; gift lines; applied promotions with eligible amount, discount and
rewards; rejected promotions with a reason code and a translated message; rewards the customer
can still unlock ("spend 300 more"); and the totals: original subtotal, subtotal, item discounts,
automatic order discounts, coupon discount, delivery fee and delivery discount, gift value,
total savings and the payable total.

**Parity.** For the same lines, customer, coupon, delivery fee, stock and promotion state, the
cart preview and checkout return the same result (tested in `CartCheckoutParityTest`). The app
may send `expected_total` with the checkout; if the total calculated at checkout differs (a
promotion ended, a price changed, the last coupon use was taken), nothing is ordered and the
API answers `409 pricing_changed` with the new pricing.

## 2. Calculation order

1. Each customer line starts at its size's price.
2. **Item promotions** per line: live flash sales, percentage and fixed discounts. One winner per
   line unless explicitly stackable (section 4).
3. **Coupon, items part (tentative)**: its eligible items and the discount on them.
4. **Order promotions** in precedence order (priority desc, then id asc): Buy X get Y, Spend X
   get Y, free delivery. Each is checked for eligibility, exclusivity, duplicate rewards and
   gift stock.
5. **Order discounts**: automatic order discounts (Spend X fixed or percentage rewards) against
   each other and against a coupon that does not combine (larger wins).
6. **Delivery**: the best free-delivery offer; then the coupon's delivery part, if the coupon
   discounts delivery, on the fee that is left.
7. **Totals**: `total = subtotal − automatic order discounts − coupon + delivery fee − delivery
   discount`, never below zero. A combining coupon applies after the automatic order discount.

Circularity is avoided by design: gift lines are an output, never an input; Spend X discount
rewards are decided on the qualifying amount *without* the coupon (they compete with a
non-combinable coupon, so only one of the two can apply); gift and delivery rewards use the
amount *after* the coupon's items discount when `coupon_reduces_qualifying` is on; the delivery
fee that may count toward spend is the fee before any delivery discount.

## 3. Promotion types

Promotions are rows of `discount_rules` (model `DiscountRule`) with targets
(`discount_rule_targets`: product, variant, brand or category, optionally *excluded*) and
rewards (`promotion_rewards`). Every promotion has a name (EN/AR), description, dates, active
switch, priority, exclusive and stackable flags, optional campaign, customer scope (everyone,
new customers, returning customers, specific customers), country, minimum/maximum cart amount,
total and per-customer usage limits.

| Type | Configuration | Behaviour |
|---|---|---|
| **Percentage discount** (`percent`) | value 0 < v ≤ 100, optional per-unit cap, minimum line quantity | `unit × (1 − v%)`, rounded half up |
| **Fixed discount** (`fixed`) | amount > 0 per unit, optional cap | `max(0, unit − amount)` |
| **Buy X get Y** (`buy_x_get_y`) | buy quantity X, qualifying targets, free-product rewards (product + optional variant + quantity), repeatable, max times per order, gift fallback | see section 5 |
| **Spend X get Y** (`amount_gift`) | rewards with thresholds (tiers): free product, fixed discount, percentage discount, free delivery; tier mode; qualifying basis; delivery counts?; coupon reduces spend?; repeatable (one tier only); max times per order; eligible/excluded targets | see section 6 |
| **Free delivery** (`free_delivery`) | minimum qualifying spend, optional cap on the fee waived, targets | waives the delivery fee |
| **Flash sale** (`flash_sales` + `flash_sale_items`) | products (every size) or single variants, percent or fixed, start and end (required), priority, units overall, units per customer | live only inside `[start, end)` |
| **Coupon** (`coupons`) | percent or fixed, min order (on eligible items), max discount, dates, total uses, uses per customer, first order only, specific customers, product/brand/category scope and exclusions, discounts delivery?, combines with promotions? | section 7 |
| **Campaign** (`promotion_campaigns`) | name, dates, active, customer scope | its promotions apply only while it runs |

Item price conditions (minimum line quantity, cart bounds) are cart conditions: listings show the
price without conditional discounts; the cart applies them.

## 4. Conflicts, precedence and stacking

Stacking is off by default. Decisions never depend on database order.

**Item price (per line).** Candidates are sorted by: flash sale first (decision D7), then
priority (higher first), then target specificity (variant 4 > product 3 > brand 2 > category 1),
then the older promotion. The first applies. Others apply on top only when the winner **and**
they are stackable and not exclusive (each on the running price, in the same order). Flash
sales never stack. Every loser is reported (`conflict_lower_precedence` or `not_stackable`).

**Buy X get Y and item discounts.** Units already reduced by an item promotion do not count
toward Buy X unless the Buy X promotion is stackable (`qualifying_items_discounted`).

**Order promotions.** Processed by precedence. An applied exclusive promotion blocks the later
ones (`blocked_by_exclusive`); an exclusive promotion is refused when another one already
applies (`exclusive_conflict`). Two promotions giving the same gift product: only the first
gives it unless both are stackable (`duplicate_reward`).

**Automatic order discounts.** The first applies; later ones only when both are stackable.

**Coupon.** A coupon that does not combine with promotions (the default for new coupons) applies
only to items without an item promotion (`no_eligible_items` if none) and competes with automatic
order discounts: the larger discount applies, the coupon on a tie (`conflict_better_offer` for
the loser). A combining coupon applies on promoted items too, after the automatic discount.

**Delivery.** One free-delivery offer applies (the one waiving most); others are reported as
`delivery_already_free`.

**Dashboard.** Activating a promotion that targets exactly the same product/variant/brand/
category as another active, overlapping, non-stackable item discount with the **same priority**
is refused (ambiguous). Different priorities are allowed with a warning naming the winner.
Flash sales covering the same product at the same time cannot both be active.

## 5. Buy X get Y — automatic gifts

* X qualifying units (targets, not discounted unless stackable) earn each reward's quantity.
  Repeatable: every full X earns it again (4 units of "buy 2 get 1" → 2 gifts), capped by "max
  times per order". Not repeatable: once.
* After **every** cart change (add, quantity, variant change, remove, view, coupon preview) the
  engine recalculates and `CartPromotionSync` makes the gift lines match: added, resized
  (4 → 3 units: 2 gifts → 1) or removed (below X). The response lists the changes
  (`gift_changes`) with the reason (`buy_quantity_not_reached`, `promotion_ended`, ...).
* A gift line is a real cart row: `is_gift = true`, price 0, the promotion and reward ids, and a
  stable key `p{promotion}:r{reward}` (unique per customer), so recalculation never duplicates it.
  The customer cannot edit or delete it (`422 gift_not_editable`); adding the same product
  creates a separate paid line.
* Same-product rewards ("buy 2 get 1 of the same") are a separate gift line; stock must cover
  the paid units plus the gift.
* Checkout never trusts the stored gift lines: it re-decides the gifts with the stock locked.

## 6. Spend X get Y — thresholds and tiers

* **Qualifying spend** = Σ eligible lines (targets; no target = every product; minus excluded)
  at their price after item promotions (or original price if configured) − the coupon's
  discount on those lines (if "coupon reduces spend", default on) + the delivery fee (if "delivery
  counts", default off). Gift lines never count.
* Each reward has a threshold; rewards with the same threshold form a tier.
  * **Highest tier** (default): only the highest tier reached gives its rewards.
  * **Every tier**: all tiers reached give their rewards. From 10,500 down to 7,000 with tiers
    5,000 (gift A) and 10,000 (gift B): B is removed, A stays.
* Gifts are added and removed exactly like Buy X gifts, after every change that moves the
  qualifying spend: item removed, quantity down, price changed, coupon applied, promotion
  expired, item no longer eligible, variant changed, and at checkout.
* Removed gifts carry the reason (`minimum_spend_not_reached`, `reward_replaced` when a higher
  tier replaced it, `promotion_ended`).

## 7. Coupons — reservation lifecycle

| Event | Redemption (`coupon_redemptions`) | `coupons.used_count` |
|---|---|---|
| Preview | nothing | unchanged |
| Checkout | **reserved** (counts toward the limits at once, under the coupon row lock) | +1 |
| Order completed | **consumed** | unchanged |
| Order canceled | **released** when `settings.coupon_release_on_cancel` (default on) | −1 |
| Return / refund after delivery | stays consumed (the order happened) | unchanged |

The total limit is checked on `used_count` (uses recorded before redemptions existed still
count); the per-customer limit on the customer's counting redemptions (or older `coupon_user`
history, whichever is larger). Two customers who previewed the last use: the first checkout
takes it; the second order is placed without the discount (or refused with `409` when the app
sends `expected_total`). Archiving a coupon (delete) is a soft delete; its code is never reused.

## 8. Gifts and inventory

* A gift needs: the reward product (not deleted), the variant (belongs to the product; empty =
  first size in id order with enough stock), the promotion live and eligible, and stock:
  sellable units − units of that size the customer is buying − gift units already given.
* **Out of stock** (per promotion, `gift_fallback`): *partial* (default) gives what is left and
  says so; *skip* gives nothing; *block* refuses checkout (`422 gift_unavailable`) until the
  customer reviews the cart.
* At checkout a gift is an `order_items` row (`is_gift`, price 0, `original_price`,
  `discount_amount` = its value, promotion and reward ids, `gift_status = granted`, snapshot). It
  is **reserved** like any line (`InventoryService::reserve`, same warehouses, same ledger), leaves
  stock as a `gift` movement at dispatch, and is released when the order is canceled.
* A returned gift goes to quarantine like any returned unit (never sellable automatically) and is
  marked `gift_status = returned`.

## 9. Order snapshots

Written once at checkout and never changed:

* `orders`: `subtotal`, `items_discount_amount`, `promotion_discount_amount`,
  `coupon_discount_amount`, `delivery_discount_amount`, `discount_amount` (sum of the order-level
  ones, as before), `total_savings` (all discounts + gift value), `coupon_code`,
  `pricing_snapshot` (the engine result).
* `order_items`: `price_at_time`, `original_price`, `discount_amount`, `discount_rule_id`,
  `flash_sale_id`, `promotion_reward_id`, `promotion_snapshot` (names, types, unit discounts).
* `order_promotions` (immutable model): one row per applied promotion, flash sale and coupon:
  name, type, code, eligible amount, discount, delivery discount, gift units, details (qualifying
  lines, thresholds, gifts with their value).

Promotions and coupons are archived (soft deleted), not deleted, so the links stay.

## 10. Cancellations, returns and refunds

* **Cancel**: every line's stock (gifts included) is released; promotion redemptions are
  released; the coupon use is released (setting); flash-sale units count only from orders that
  are not canceled, so they become available again.
* **Refunds on orders priced by the engine** (`PromotionRefundPolicy`), cumulative over the
  order's accepted returns:
  * a returned unit refunds what was paid for it: its line price minus its share of the
    order-level item discounts (automatic + the coupon's items part);
  * gift lines refund nothing;
  * a return that makes a gift no longer earned (fewer qualifying units kept, or kept spend
    below the gift's threshold) deducts the gift's value, unless the gift is returned too; a
    Spend X discount no longer earned is deducted too; each deduction is taken once;
  * the delivery actually paid becomes refundable when every paid unit is back;
  * never more than the order total minus earlier refunds.
  The admin's approved amount above that maximum is refused with the breakdown.
* Orders placed before the engine keep the previous rule (refunds up to the order total).

## 11. Banners and opening ads

Display content only: they never change a price. Customers (`GET /api/v1/user/banners`,
`/openingAd`) see only rows that are active, inside their dates (empty = always), for the store's
country (or no country), and for their device when the app sends `?device=ios|android|web`.
Banners also filter by `?position=`. Opening ads come by priority with their
`display_frequency` (every open, once per session, once a day, once); the app enforces the
frequency. Images: JPEG/PNG/WebP only (no SVG), ≤ 2 MB and 4000 px, stored on the public disk
under a random name with an extension from the detected content type.

## 12. Dashboard (Marketing)

* **Overview**: live, scheduled and ending promotions, live flash sales, coupons, shown banners
  and ads, orders with an offer, customer savings, and a performance table per offer (orders,
  eligible sales, discount, delivery waived, gift units), from order snapshots.
* **Promotions** (tabs: Discounts, Buy X get Y, Spend X get Y, Free delivery): search, filter
  by status and campaign, create, edit, view, activate/deactivate, duplicate (inactive draft),
  archive. The view page has the configuration check (blocking errors and warnings, conflicts),
  covered products with price before/after, rewards with stock, the preview, usage history,
  analytics and audit history.
* **Campaigns**, **Coupons** (scope, customers, redemptions page, toggle, archive), **Flash sales**
  (products/variants, limits, overlap check, units sold), **Banners**, **Opening ads**.
* Every create/update/activate/deactivate/duplicate/archive is written to the admin audit log.
* Activation is refused while the configuration has blocking errors (e.g. no reward, a reward
  variant of another product, a repeatable Spend X with several tiers, a brand/category gift, an
  ambiguous conflict).

## 13. Validation levels

1. Dashboard form rules (`SavePromotionRequest`, coupon/flash/banner requests).
2. Domain rules (`PromotionValidator`): activation blocked on errors.
3. Cart: the engine's eligibility (dates, campaign, country, customer, limits, stock).
4. Checkout: the same, under row locks, with live counts; gift fallback "block".
5. Order creation: `expected_total`, stock reservation (refuses overselling).
