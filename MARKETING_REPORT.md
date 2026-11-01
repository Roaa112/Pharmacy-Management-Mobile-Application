# Marketing, promotions and smart cart rewards — implementation report

Scope: the marketing and promotions brief (promotion engine, all promotion types, automatic Buy
X / Spend X gifts, conflicts, coupons, flash sales, banners and opening ads, dashboard,
snapshots, returns, tests, second audit). The behaviour reference is
`docs/marketing-promotions.md`; this report covers what was found, what changed, the evidence
and what is left.

Nothing was committed or pushed. No production data was touched. Tests ran on in-memory SQLite
and on the throwaway local MySQL 8 database `pharmacy_impl_check` (the same one the previous
phase used for this purpose). The development database `Pharmacy-Management` was not migrated or
modified. The migration was also run, seeded, rolled back and re-applied on a scratch SQLite file.

**Status:** implemented and tested. Before going live: run the migration on a staging copy of
production, and confirm the business decisions in section 12. The defaults chosen there are the
safest ones that keep current customers' promises.

---

## 1. First audit: what existed and what was wrong

| # | Finding | Severity |
|---|---|---|
| A1 | Gifts were decided **only at checkout** (`GiftResolver`): the cart never showed them, the customer could not see a gift appear or disappear, and only the **first** qualifying rule gave a gift (one gift per order). | High |
| A2 | Promotions had no active switch, priority, exclusivity, stacking flag, usage limits, customer or country scope, names, or campaigns. Ties between rules were broken by row id. | High |
| A3 | Pricing lived in `Product::priceForSize` (item price), `CouponEvaluator` (coupon), `GiftResolver` (gifts) and `ProductService` ("on sale" lists queried `discount_rules` directly). The cart and checkout repeated the subtotal calculation. | Medium |
| A4 | Flash sales were linked to **one product each** through `products.saleable`, were percent-only, had no variant selection, no quantity or per-customer limits, and a **hard-coded 60-minute** fallback duration. | Medium |
| A5 | A coupon applied on top of flash-sale and discounted prices (no way to prevent it). Coupons had no product/category/brand scope, no exclusions, no first-order or specific-customer rules. | High |
| A6 | Coupon usage was a counter incremented at checkout; **a canceled order kept its use** and nothing recorded when a use was consumed. | Medium |
| A7 | **Bug:** editing a coupon in the dashboard reset `used_count` to 0 (`constructCouponModel` wrote `used_count ?? 0`), re-opening used-up coupons. | High |
| A8 | **Bug:** updating a coupon without the "usage limit" field crashed with a 500 (undefined array key). | Low |
| A9 | Discount rules could only have their dates edited; deleting one **hard-deleted** it (order lines lost the link); a gift could target a brand or category (no product handed over, decision D12); reward products were never validated. | Medium |
| A10 | **Bug:** the customer banner and opening-ad APIs did not filter on the active flag: inactive banners and ads were returned to the app. No scheduling, country, device or position targeting existed. | High |
| A11 | Banner/ad uploads used the client's file extension and allowed GIF; opening ads were stored in two different places depending on create/update. | Medium |
| A12 | Order lines had a gift flag and rule id but no promotion snapshot; the order had no breakdown of item, automatic, coupon and delivery discounts. | Medium |
| A13 | Refunds were capped by the order total only: a customer could return the qualifying items, keep the gift, and be refunded the full line prices. | Medium |
| A14 | The dashboard had one modal per type, no view page, no preview, no usage history, no analytics, no duplicate/toggle. | Medium |

## 2. What was implemented

| Area | Now |
|---|---|
| Engine | `App\Modules\Promotions\Engine\PromotionEngine` prices listings, product page, cart, coupon preview, delivery quote, checkout, the customer promotions API and the dashboard preview. Structured result: original/final prices, applied and rejected promotions with reasons, gifts, delivery discounts, savings, total, unlockable rewards. Integer minor units throughout. |
| Strategies | Separate classes: `PercentageDiscountStrategy`, `FixedAmountDiscountStrategy`, `FlashSaleStrategy`, `BuyXGetYStrategy`, `SpendXGetYStrategy`, `FreeDeliveryStrategy`, `CouponStrategy`; shared `EligibilityChecker`, `TargetMatcher`, `ConflictResolver`, `PromotionCatalog` (per-second cache, flushed on any promotion change). |
| Smart cart | `CartPromotionSync` adds, resizes and removes gift lines after every cart change, keyed per reward so recalculation never duplicates; gift lines are price 0, not editable, and carry the promotion; responses list `gift_changes` with reasons. |
| Checkout | Two-pass locking (find gift sizes, then lock every size in id order), engine under locks with live usage counts, limited promotions/flash sales/coupon rows locked, `expected_total` → 409, gift fallback "block" → 422, gift lines reserved like any line, snapshots, redemptions. |
| Coupons | Scope (product/brand/category, exclusions), specific customers, first order only, combines-with-promotions flag, country/currency check, reserve → consume → release lifecycle (`coupon_redemptions`), `used_count` never edited by hand, archive instead of delete. |
| Flash sales | Products or variants (`flash_sale_items`), percent or fixed, required end, priority, overall and per-customer unit limits (counted from live orders), overlap prevention, units sold in the list. The product form's link is mirrored automatically. |
| Campaigns | `promotion_campaigns`: dates, active, customer scope; promotions apply only while their campaign runs. |
| Returns | `PromotionRefundPolicy` caps refunds of engine-priced orders at what was paid for the returned units, minus gifts and discounts no longer earned, plus delivery once everything is back; returned gifts are quarantined and marked. |
| Banners / ads | Bilingual content, scheduling, country, device, position (banners), priority and display frequency (ads); customer APIs filter all of it; secure uploads. |
| Dashboard | Marketing overview with analytics; promotions list (tabs per type, search, filters), create/edit form for every type, view page (configuration check, covered products, rewards and stock, preview, usage, analytics, audit), toggle, duplicate, archive; campaigns; coupons (scope, customers, redemptions page, toggle); flash sales (items, limits); banners and ads (new fields). Every action audited. Activation blocked on configuration errors. |
| Orders | Breakdown columns, `pricing_snapshot`, immutable `order_promotions`, per-line promotion snapshots; shown on the admin order page and in the customer order-details API. |
| On-sale lists | `products/on/sale` and `top-discount-products` use only active, non-archived rules, skip gift/excluded targets, and list only products the engine actually discounts. |

## 3. Database changes

One migration: `2026_11_07_000001_create_marketing_engine` (additive; working `down()`, verified on
SQLite and MySQL).

| Table | Change |
|---|---|
| `discount_rules` (promotions) | `name_en/ar`, `description_en/ar`, `campaign_id`, `is_active` (default **true**, as existing rules were live), `priority`, `is_exclusive`, `is_stackable`, `repeatable`, `max_applications_per_order`, `usage_limit`, `per_customer_limit`, `customer_scope`, `country_id`, `min_order_amount`, `max_order_amount`, `max_discount_amount`, `qualifying_basis`, `count_delivery_fee`, `coupon_reduces_qualifying`, `tier_mode`, `gift_fallback`, `created_by`, `updated_by`, soft deletes; `discount_type` enum → string (adds `free_delivery`); `applies_to_*` nullable |
| `discount_rule_targets` | `is_excluded`; target type `variant` |
| `promotion_rewards` (new) | threshold, reward type, product, variant, quantity, value, cap, order |
| `promotion_campaigns`, `promotion_customers`, `promotion_redemptions` (new) | campaigns; specific customers; usage per order (reserved/consumed/released) |
| `flash_sales` | `name_en/ar`, `discount_type`, `priority`, `quantity_limit`, `per_customer_limit`, `country_id` |
| `flash_sale_items` (new) | product or variant per sale, unit limit, product-form link flag |
| `coupons` | `name`, `first_order_only`, `country_id`, `combines_with_promotions`, `customer_scope`, soft deletes |
| `coupon_targets`, `coupon_customers`, `coupon_redemptions` (new) | scope, customers, lifecycle |
| `carts` | `is_gift`, `discount_rule_id`, `promotion_reward_id`, `gift_key` (unique per user) |
| `order_items` | `promotion_reward_id`, `flash_sale_id`, `discount_amount`, `gift_status`, `promotion_snapshot` |
| `orders` | `items_discount_amount`, `coupon_discount_amount`, `promotion_discount_amount`, `delivery_discount_amount`, `total_savings`, `coupon_code`, `pricing_snapshot` |
| `order_promotions` (new) | immutable snapshot per applied promotion/flash sale/coupon |
| `banners` | `description_en/ar`, `position`, `starts_at`, `ends_at`, `country_id`, `device` |
| `opening_ads` | `title_en/ar`, `content_en/ar`, `link`, `display_frequency`, `priority`, `starts_at`, `ends_at`, `country_id`, `device` |
| `settings` | `coupon_release_on_cancel` (default true) |

**Backfills** (additive only): existing coupons get `combines_with_promotions = true` (they keep
applying as customers were promised); flash sales without an end get the one hour they always had,
written down; product-form flash links become flash-sale items; product gift targets become
explicit free-product rewards; orders that used a coupon get a **consumed** redemption (canceled
ones too, matching the old behaviour, so counts are unchanged) and `coupon_discount_amount`.

## 4. APIs changed or added

| Endpoint | Change (existing keys unchanged) |
|---|---|
| `GET /api/user/cart` | optional `coupon`, `address_id`; lines gain `product_size_id`, `is_gift`, `editable`, `promotions`; gift lines appear in `data` (price 0, `gift` object); new `pricing` and `gift_changes` |
| `POST /api/user/cart`, `update-quantity`, `DELETE /api/user/cart/{id}` | re-price and sync gifts; new `pricing`, `gift_changes`; editing or deleting a gift line → `422 gift_not_editable` |
| `POST /api/user/cart/apply-coupon` | same keys; computed by the engine (automatic discounts and free delivery included in `total_after_discount`); new rejection codes (`no_eligible_items`, `first_order_only`, `customer_not_eligible`, `conflict_better_offer`, …); new `pricing` |
| `POST /api/user/orders` | optional `expected_total` (409 `pricing_changed`); 422 `gift_unavailable`; new keys `items_discount`, `coupon_discount`, `promotion_discount`, `delivery_discount`, `total_savings`, `gifts`, `applied_promotions` |
| `GET /api/user/order-details/{id}` | products gain `discount_amount`, `promotion`; order gains the discount breakdown, `coupon_code`, `promotions` |
| `GET /api/v1/user/promotions` (new, public) | live promotions and flash sales for an offers screen |
| `GET /api/v1/user/banners`, `/openingAd` | only visible content; optional `device`, `position` |
| `POST /api/user/delivery/quote` | subtotal from the engine |

Older apps keep working; they will show gift lines as items at price 0 (they cannot edit them).

## 5. Dashboard changes

Marketing → Overview (new), Promotions (rebuilt; route names `dashboard.discounts.*` kept),
Campaigns (new), Coupons (extended + detail page), Flash sales, Banners, Opening ads (extended).
Settings gained "A canceled order gives its coupon use back". The admin order page shows a
"Promotions applied" panel and the discount breakdown. Pages: see `docs/marketing-promotions.md` §12.

## 6. Promotion precedence, conflicts and stacking

Item price: flash sale → priority → specificity (variant, product, brand, category) → older rule;
stacking only between stackable, non-exclusive discount rules; flash sales never stack. Buy X
ignores discounted units unless stackable. Order promotions by priority then id; exclusive blocks
the rest; the same gift from two promotions is given once. Automatic order discounts: first wins
unless both stackable. Non-combinable coupon vs automatic discount: the larger applies (coupon on a
tie). One free-delivery offer. Dashboard refuses two active, overlapping, same-priority discounts on
the same exact target, and overlapping active flash sales on the same product. Full rules:
`docs/marketing-promotions.md` §4.

## 7. Buy X get Y and Spend X get Y behaviour

Buy X: gifts appear at X qualifying units, follow the quantity when repeatable (4 → 2 gifts, 3 → 1,
1 → none), follow variant changes, and vanish with a reason. Spend X: qualifying spend defined
exactly (§6 of the reference), tiers in "highest" or "every tier" mode, gift kept/removed on every
change (removal, quantity, price, coupon, expiry, eligibility, variant, checkout), rewards: free
product, fixed or percentage discount, free delivery. Gifts never count toward their own threshold.

## 8. Inventory integration

Gift stock is checked against sellable stock minus the customer's own units of that size and
gifts already given; at checkout the sizes are locked; gift lines are reserved through
`InventoryService::reserve` (same warehouses and ledger, idempotent per line), fulfilled as `gift`
movements at dispatch, released on cancel, quarantined on return. Out-of-stock behaviour per
promotion: partial (default), skip, block.

## 9. Order snapshots, refunds and returns

See `docs/marketing-promotions.md` §9–§10. Editing or archiving a promotion never changes an
order (tested; `OrderPromotion` refuses updates). Refunds of engine-priced orders never exceed what
was paid for the returned units, minus gifts/discounts no longer earned, plus delivery once
everything is returned; each deduction is taken once over several returns.

## 10. Tests

New: 83 tests (577 assertions).

| File | Tests | Covers |
|---|---|---|
| `Marketing/BuyXGetYTest` | 16 | insertion, separate zero-priced line, labels, repeat and quantity recalculation, removal (item removed, quantity down, variant changed), same-product reward, out of stock, partial/skip/block fallbacks, duplicate prevention over 5 recalculations, no customer editing, checkout line + reservation + snapshot + redemption, cancel releases stock and redemption, tampered gift lines ignored, expiry before checkout, discounted units vs stackable |
| `Marketing/SpendXGetYTest` | 12 | threshold reached/lost, 5,200 − 300 = 4,900 example, cumulative tier downgrade (B removed, A kept), highest-tier mode, gifts excluded from qualifying spend, targets and exclusions, coupon lowering the spend (and the opposite setting), price change, expiry, out of stock, fixed/percent/free-delivery rewards and their conflict |
| `Marketing/PromotionConflictsTest` | 16 | flash vs percent vs fixed, priority/specificity/age, explicit stacking and zero floor, exclusive winner, coupon vs flash/discounted items, coupon vs automatic discount, Buy X vs item discount, two campaigns same reward, exclusive order promotion, campaign and country, specific/new customers and limits, conditional discounts in cart vs listing, flash quantity/customer limits, on-sale lists, no double application |
| `Marketing/CouponLifecycleTest` | 10 | reserve/consume/release, release setting off, refunds keep the use, limit rechecked at checkout (two customers, last use), `expected_total` 409, per-customer, expiry, inactive, first order, specific customers, scope and exclusions with minimum, fixed never above base, archive |
| `Marketing/CartCheckoutParityTest` | 4 | every type at once (flash, percent, Buy X gift, free delivery, coupon with delivery) — preview equals checkout on 8 amounts after 3 recalculations; stale/changed prices; stock change between cart and checkout; snapshot immutability |
| `Marketing/PromotionReturnsTest` | 5 | gift kept after a qualifying return (deduction), gift returned too (quarantine, status), gift-only return, coupon share per unit and cumulative cap, Spend X gift deducted once |
| `Marketing/BannersAndAdsTest` | 5 | scheduling and expiry (time travel), status, country, device, position, ad priority and frequency, SVG/PHP/oversized uploads refused, random stored name, display-only |
| `Marketing/MarketingDashboardTest` | 11 | every page renders, create Buy X with reward + audit, form validation, activation blocking and draft saving, same-priority conflict refused, duplicate/deactivate/archive + audit, preview on a draft (no writes), flash end required and overlap refused, coupon edit keeps usage, permissions, customer promotions API |
| `Unit/PromotionEngineUnitTest` | 4 | resolver total order independent of input order, stacking, order/delivery resolution, target specificity and exclusions |

Existing tests changed: `FlashSalePricingTest` (a coupon on a flash-sale price must now be marked
"combines with promotions"; the default rejection is asserted next to it) and
`RouteProtectionTest` (the new public read-only promotions endpoint is whitelisted).

**Results (final runs):**

- SQLite, full suite: **544 passed, 2 skipped** (3,615 assertions). The 2 skipped need MySQL row locks.
- MySQL 8 (`pharmacy_impl_check`), full suite: **546 passed** (3,619 assertions), including the row-locking tests and the migration rollback test.
- Baseline before this work: 461 passed, 2 skipped.
- Scratch SQLite file: `migrate:fresh --seed`, rollback of the new migration, migrate again, `inventory:verify` → consistent; backfills recreated 2 flash items, 2 rewards, 3 coupon redemptions from the demo data.
- `view:cache` compiles every template; `php -l` clean on all changed files.

Bugs the new tests caught during this work (all fixed): gift lines deleted on every second
recalculation (Eloquent `except()` uses primary keys); an Eloquent `intersect` on an empty
collection crashing the promotion page; the coupon 500 (A8); the MySQL rollback failing because
the new composite cart index backed the `carts.user_id` foreign key.

## 11. Second audit: requirement status

| Requirement | Status | Evidence / reason |
|---|---|---|
| 1 Audit of the existing system | IMPLEMENTED | Section 1 |
| 2 Centralized engine used everywhere, structured result, decimal-safe, backend-authoritative, parity | IMPLEMENTED | `PromotionEngine`; callers in §1 of the reference; `CartCheckoutParityTest`, `FlashSalePricingTest` |
| 3 Distinct strategies for 8 types | IMPLEMENTED | `Engine/Strategies/*`; campaigns as eligibility groups (`PromotionCampaign`) |
| 4 Buy X get Y automatic gifts, removal, recalculation, configurable repetition | IMPLEMENTED | `BuyXGetYTest` |
| 5 Spend X get Y automatic gifts, all removal triggers, tiers | IMPLEMENTED | `SpendXGetYTest` |
| 6 Gift cart line architecture | IMPLEMENTED | `Cart` gift columns, `CartPromotionSync`; `BuyXGetYTest` |
| 7 Gift inventory integration | IMPLEMENTED | reserve/fulfil/release/quarantine through `InventoryService`; `BuyXGetYTest`, `PromotionReturnsTest`, `CartCheckoutParityTest` |
| 8 Conflict resolver, priority, exclusivity, stacking, eligibility levels, reasons | IMPLEMENTED | `ConflictResolver`; `PromotionConflictsTest`, unit tests |
| 9 Coupons (all listed rules, concurrency, lifecycle) | IMPLEMENTED | `CouponLifecycleTest`. Concurrency: coupon row locked at checkout and the limit counted under the lock; tested sequentially (two customers, last use) and the locking path ran on MySQL; no two-connection coupon race test (the existing two-connection tests cover the size locks) |
| 10 Discounts (all listed rules, conflicts, never negative) | IMPLEMENTED | `PromotionConflictsTest`, `MarketingDashboardTest` |
| 11 Flash sales | IMPLEMENTED (one part partial) | Store clock, no fixed duration, products/variants, percent/fixed, limits, overlap; `PromotionConflictsTest`, `MarketingDashboardTest`. **Per-item** unit limits exist in the engine (`flash_sale_items.quantity_limit`) but the dashboard form sets sale-level limits only |
| 12 Spend X reward types and qualifying configuration | IMPLEMENTED | `SpendXGetYStrategy`, `OrderContext::qualifyingAmount`; `SpendXGetYTest` |
| 13 Banners and opening ads | IMPLEMENTED (two notes) | `BannersAndAdsTest`. Device targeting needs the app to send `?device=`; display frequency is returned to the app, which enforces it (the server cannot see app opens) |
| 14 Marketing dashboard incl. preview, history, audit, analytics, activation blocking | IMPLEMENTED | `MarketingDashboardTest`; views in `resources/views/dashboard/{Discounts,Coupons,campaigns,marketing,FlashSales,Banners,OpeningAds}` |
| 15 Snapshots and financial accuracy, refunds/returns | IMPLEMENTED | `CartCheckoutParityTest`, `PromotionReturnsTest`. The stricter refund cap applies to orders placed after this change (they carry `pricing_snapshot`); older orders keep the order-total cap |
| 16 Validation at five levels | IMPLEMENTED | `docs/marketing-promotions.md` §13 |
| 17 Automated tests | IMPLEMENTED | Section 10 |
| 18 Second audit and report | IMPLEMENTED | This section |
| Country restrictions | PARTIALLY IMPLEMENTED | Enforced by the engine for promotions, campaigns, flash sales, coupons, banners and ads (tested); not exposed in the dashboard forms because the store is Egypt-only (decision D11) |

## 12. Remaining limitations and business decisions

| # | Decision / limitation | Default chosen (why) |
|---|---|---|
| M1 | Existing coupons were set to "combines with promotions" so live codes keep giving what customers were told; new coupons do not stack. Review existing coupons. | Keep current behaviour for existing, safe default for new |
| M2 | A canceled order gives its coupon use back (decision D5 changed from "stays used"). Setting in Settings. | On (the customer received no discount) |
| M3 | Spend X tiers: "highest tier only" unless set to "every tier". | Highest (fewer giveaways) |
| M4 | Gift out of stock: "give what is in stock". | Partial, with a message |
| M5 | A cart line over a flash-sale unit limit gets the regular price for the whole line (no split pricing); the message says how many units are left. | No mixed prices on one line |
| M6 | A combining coupon applies after automatic order discounts (sequential). | Documented order |
| M7 | Coupon vs Spend X qualifying spend uses the coupon's discount on items only (the delivery part is decided later), avoiding a circular calculation. | Documented |
| M8 | Brand/category gift rules from before (D12) cannot be activated until an exact product is chosen. Existing such rules keep their state but give nothing; the promotion page flags them. | Explicit products only |
| M9 | Per-item flash limits and country selection are not in the dashboard forms (engine supports both). | Store is Egypt-only; sale-level limits cover current use |
| M10 | Older app versions list gift lines as items at price 0 and cannot edit them (422 with a message). | Backward compatible |
| M11 | Opening-ad display frequency is enforced by the app. | Server cannot observe app opens |
| M12 | The legacy `discounts` table/model and `DiscountService` remain (the product form still injects the service); not used for pricing. | Removing them is a separate clean-up |

## 13. Files

New: `app/Modules/Promotions/**` (engine, strategies, services, API controller),
`app/Providers/PromotionsServiceProvider.php`, `app/Modules/Discount/{CampaignController,MarketingDashboardController}.php`,
`app/Modules/Discount/Requests/SavePromotionRequest.php`, models `PromotionReward`, `PromotionCampaign`,
`PromotionRedemption`, `CouponRedemption`, `CouponTarget`, `FlashSaleItem`, `OrderPromotion`,
`app/Support/MarketingImage.php`, `config/promotions.php`, `lang/{en,ar}/promotions.php`, the migration,
`database/seeders/Demo/MarketingSeeder.php`, views (promotions form/show/partials, coupons show, campaigns,
marketing overview), `docs/marketing-promotions.md`, the tests in section 10 and `tests/Concerns/BuildsPromotions.php`.

Changed: `Product` (pricing via the engine, flash link mirroring), `DiscountRule`, `DiscountRuleTarget`,
`FlashSale`, `Coupon`, `Cart`, `Order`, `OrderItem`, `Banner`, `OpeningAd`, `Setting`; `CartController`,
`CartRepository`, `CheckoutService`, `OrderWorkflow`, `ReturnDecisionService`, `OrderController`,
`CouponEvaluator` (formula only), coupon/flash/banner/ad controllers, services and requests,
`DiscountController`, `ProductService` (on-sale lists), `ApiDeliveryController`, routes, sidebar,
settings, the admin order page, `docs/business-rules.md`, `bootstrap/providers.php`.
Removed: `app/Modules/Orders/Services/GiftResolver.php` (replaced by the engine).
