# API reference

The JSON API used by the customer mobile app (and by payment / delivery providers). Every endpoint
listed here exists in `routes/api.php`; the list was checked against `php artisan route:list`. A
ready-to-run Postman collection with every request is in
[`postman/Pharmacy-Mobile-App.postman_collection.json`](../../postman/Pharmacy-Mobile-App.postman_collection.json)
(it has no saved example responses).

The admin dashboard is server-rendered (Blade, session auth) and is **not** a JSON API; its routes
are listed in [permissions-matrix.md §3](../permissions-matrix.md#3-dashboard-routes-and-the-abilities-they-require).

## 1. Conventions

### 1.1 Base URLs and versions

`routes/api.php` is registered **twice** (`bootstrap/app.php`): under `/api` and under `/api/v1`.
Every endpoint therefore answers at both prefixes, e.g. `POST /api/user/login` and
`POST /api/v1/user/login`.

The public catalog group inside `routes/api.php` carries its own `v1` prefix, so catalog endpoints
answer at **`/api/v1/user/...`** (and, as a side effect, `/api/v1/v1/user/...`). They do **not**
exist at `/api/user/...`. Both catalog forms are kept on purpose (apps in the field use either;
pinned by `CatalogApiTest`).

In the tables below, `/api/...` means "either prefix" unless the path starts with `/api/v1/user`.

### 1.2 Headers

| Header | Use |
|---|---|
| `Authorization: Bearer <token>` | Customer endpoints (`auth:sanctum`). Token from `POST /api/user/login`. |
| `Accept: application/json` | Recommended; validation errors then come back as JSON 422. |
| `Accept-Language: ar` or `en` | Language of messages and labels. Applied by `SetLocaleLang` on the catalog, addresses, loyalty, devices, files, returns, medication, delivery-quote and favorites-list routes; other endpoints use the server default (`APP_LOCALE`, `en`) or Arabic where they always did (see each module). |
| `X-Request-Id` | Optional. A value of 8–64 letters, digits or dashes is kept; otherwise a UUID is generated. Always echoed in the response and attached to every log line (`AssignRequestId`). |
| `Idempotency-Key` | Optional on `POST /api/user/loyalty/rewards/{id}/redeem` (the same key returns the first redemption). |

### 1.3 Response shapes

The API grew over time and does not use one envelope everywhere. The shapes in use:

| Shape | Used by |
|---|---|
| `{"status": "success", "message": "...", "data": ..., "count": n}` (`successJsonResponse`) | catalog lists (products, categories, brands, health issues, banners, settings), registration, profile update |
| `{"status": "error", "message": "...", "data": null, "errors": {...}}` (`errorJsonResponse`) | some catalog/profile errors |
| `{"success": true|false, "message": "...", ...}` | cart, orders, tracking, payments, medication, favorites, delivery quote |
| `{"message": "...", "data": ...}` / plain objects | returns, loyalty, health, contact messages, devices, auth |
| Laravel API resources (`{"data": [...], "links": ..., "meta": ...}`) | loyalty history, rewards, redemptions |

Errors that carry a machine-readable reason include `"code"` (e.g. `out_of_stock`, `coupon_rejected`,
`pricing_changed`, `account_inactive`). Apps should branch on `code`, not on `message`.

### 1.4 Status codes

| Code | Meaning |
|---|---|
| 200 / 201 | OK / created |
| 400 | Business refusal on some older endpoints (cart item out of stock, coupon invalid, return cannot be canceled) |
| 401 | Missing / invalid / expired token, or wrong credentials on login |
| 403 | `account_inactive` (deactivated customer), `email_not_verified` (when `REQUIRE_VERIFIED_LOGIN=true`), profile of another user |
| 404 | Not found, or not the caller's (ownership is never revealed) |
| 409 | `pricing_changed` (checkout `expected_total` differs) or `cart_changed` (concurrent cart edit) |
| 422 | Validation error, or a business rule refused the action (with `code`) |
| 429 | Rate limit |
| 502 | Payment provider could not be reached when starting a payment |
| 503 | Payment webhook already being processed by another worker (`Retry-After: 60`) |

### 1.5 Listing parameters

Catalog lists accept `limit` (1–100) + `offset` (≥ 0), `sort` (`asc|desc`) + `sortBy`, `search`,
and `filters[...]`. `SanitizeListingParameters` silently drops invalid values (non-numeric paging,
unknown sort direction, arrays where text is expected) and caps `limit` at 100, so a bad value falls
back to the endpoint's default instead of a 500. Product filters: `filters[name_ar]`,
`filters[name_en]` (contains), `filters[price_from]`, `filters[price_to]` (see
`ListAllProductsRequest`).

Paginated customer lists (loyalty history, notifications, contact messages, health histories) use
`page` and `per_page` (1–100).

### 1.6 Rate limits

| Limiter | Limit | Applies to |
|---|---|---|
| `api` | `API_RATE_LIMIT` per minute (default 60), per user id or IP | every API route |
| `auth` | 5/min per (email + IP) and 20/min per IP | register, verify, verify/resend, login, password forgot / verify-otp / reset, `password/reset/auth` |
| fixed | 20/min | `POST /api/user/orders/{id}/pay` |
| fixed | 10/min | loyalty reward redeem, contact-message reply |
| fixed | 120/min, 240/min | delivery webhooks, payment webhooks |

OTP e-mails are additionally capped at 5 codes per account per hour (silently).

### 1.7 Money and product objects

Amounts are EGP numbers with two decimals (some fields come back as strings, e.g. `"125.00"`, because
they are DECIMAL columns; apps should accept both). Product objects (`App\Models\Product`
serialization) include `id`, `name`, `description`, `image_url`, `images`, `brand_id`,
`category_id`, `sizes`, `available_sizes` (sizes that can be bought), `size_options` (every size with
`in_stock`), `final_price`, `discount_type`, `in_stock`, `stock_status` (`in_stock|out_of_stock`),
`average_rating`, `ratings_count`, `is_favorite`. Prices come from
the promotion engine. Sold-out products stay visible (`stock_status: out_of_stock`); deleted
(soft-deleted) products are hidden.

---

## 2. Authentication and account

| Method | Path | Auth | Purpose |
|---|---|---|---|
| POST | `/api/user/register` | throttle:auth | Create a customer account and e-mail a 6-digit verification code |
| POST | `/api/user/verify` | throttle:auth | Verify the account with the code |
| POST | `/api/user/verify/resend` | throttle:auth | Send a new verification code (same answer whether or not the e-mail exists) |
| POST | `/api/user/login` | throttle:auth | Get a bearer token |
| POST | `/api/logout` | token | Revoke **all** of the customer's tokens |
| GET | `/api/token/check` | token | `{"status":"success","message":"Token is valid","user":{...}}` |
| GET | `/api/user/user/data` | token | Profile (`UserResource`) and the caller's current token |
| PUT | `/api/user/update/{userId}` | token | Update own profile |
| POST | `/api/user/password/forgot` | throttle:auth | E-mail a reset code |
| POST | `/api/user/password/verify-otp` | throttle:auth | Check a reset code without using it |
| POST | `/api/password/reset/auth` | throttle:auth | Reset a forgotten password with the code (no token) |
| POST | `/api/user/password/reset` | token + throttle:auth | Change the password while signed in |

### POST /api/user/register

| Field | Rules |
|---|---|
| `name` | required, string, ≤ 255 |
| `email` | required, e-mail, unique |
| `phone` | required, string, unique |
| `password` | required, string, ≥ 8 |
| `confirm_password` | required, must equal `password` (checked in the controller: `422 {"error": "Password confirmation does not match"}`) |

200 → `{"status":"success","message":"...","data":{"id","name","phone","email","social_id","social_provider","is_verified","created_at","updated_at"}}`.
A verification code (valid 10 minutes, stored hashed) is e-mailed (queued, encrypted mail).
Validation errors: `422 {"status": false, "message": "Validation error", "errors": {...}}`.

### POST /api/user/verify

`email`, `otp_code` (6 digits). 200 `{"message":"Account verified successfully!"}`; 422
`{"message":"Invalid or expired OTP"}`. At most 5 guesses per code (counted atomically); the code is
then destroyed.

### POST /api/user/login

`email`, `password`.

```json
200 {"token": "12|kq3...", "user": {"id": 7, "name": "Sara", "email": "sara@example.com", "phone": "010...", "is_verified": true, "is_active": true, ...}}
401 {"message": "Unauthorized"}
403 {"message": "This account has been deactivated.", "code": "account_inactive"}
403 {"message": "Please verify your email first.", "code": "email_not_verified"}   // only when REQUIRE_VERIFIED_LOGIN=true
```

> **Breaking change (commit `75655e0`, 2026-11-04):** login used to answer HTTP 200 with the result
> wrapped under `original` (`original.token`). It now answers with real status codes and the token at
> the top level; apps must read `token`, not `original.token`.

### PUT /api/user/update/{userId}

`name` (required), `email` (required, unique except self), `phone` (required, unique except self),
`current_password` (checked when sent; **required for an e-mail change** when
`PASSWORD_CHANGE_REQUIRES_CURRENT=true`). `{userId}` must be the caller (else 403). Changing the
e-mail marks the account unverified, signs out every other token and e-mails a code to the new
address. `isActive` / `isVerified` are accepted by validation but **ignored** (server-controlled).

### Password flows

* **Forgot**: `POST /api/user/password/forgot {email}` → always 200 ("If this email is registered…").
  `POST /api/user/password/verify-otp {email, otp_code}` → 200 / 422 (does not consume the code).
  `POST /api/password/reset/auth {email, otp_code, password, password_confirmation}` → 200; every
  token of the account is revoked.
* **Signed-in change**: `POST /api/user/password/reset {password, password_confirmation, current_password?, otp_code?}`.
  With `PASSWORD_CHANGE_REQUIRES_CURRENT=true` one of `current_password` / `otp_code` is required.
  When neither is sent (compatibility mode, the default) the change is accepted but logged as a warning
  and the owner is e-mailed "Your password was changed". Other tokens are revoked; the current one is kept.

---

## 3. Public catalog (`/api/v1/user/...`, no token)

| Method | Path | Notes |
|---|---|---|
| GET | `/api/v1/user/settings` | The settings row: every column (contact links, phone, map URL, points and loyalty settings, store policies) except the raw legal texts, plus `privacy_policy` / `terms_conditions` in the request language and `zaincash: null` (compatibility) |
| GET | `/api/v1/user/banners` | Active banners in their dates; `?position=`, `?device=ios|android|web` |
| GET | `/api/v1/user/openingAd` | Active opening ads by priority, with `display_frequency`; `?device=` |
| GET | `/api/v1/user/promotions` | Live promotions open to everyone / new / returning customers (descriptive: name, description, type, value, buy quantity, minimum spend, ...) |
| GET | `/api/v1/user/health/config` | Health tools set in the dashboard: `{data: {tools: {bmi, blood_sugar, blood_pressure, ovulation, pregnancy, vaccines, medication: bool}, disclaimer, guidelines, ovulation: {cycle_length: {min, max}, period_length: {min, max}}, bmi_ideal_range: {min, max}, reminders: {vaccines, medications}}}` |
| GET | `/api/v1/user/payment-methods` | Methods offered now (active, not legacy, Paymob only when configured): `{data: [{code, type: online|cash_on_delivery, name, requires_receipt, account_name, account_number, instructions}]}` |
| GET | `/api/v1/user/delivary_prices` | **Legacy shape** (`id, governorate, price`): active delivery zones with the fee of their effective rate, plus `free_over_amount`, `min_order_amount`, `eta_min_days`, `eta_max_days`. `limit` ≤ 100, `offset` |
| GET | `/api/v1/user/locations/cities` | Active Egyptian governorates (for address forms) |
| GET | `/api/v1/user/locations/areas?city_id=` | Active areas of a city |
| GET | `/api/v1/user/products` | Product list (listing parameters) |
| GET | `/api/v1/user/show/{id}/product` | Product details |
| GET | `/api/v1/user/products/{product}/related` | Related products |
| GET | `/api/v1/user/products/on/sale` | Products with a live promotion |
| GET | `/api/v1/user/products/search?search=` | Search |
| GET | `/api/v1/user/latest-products` | Newest products |
| GET | `/api/v1/user/top-discount-products` | Largest discounts |
| GET | `/api/v1/user/Best-selling-products` | Best sellers from non-canceled orders, gift lines excluded: `limit` (10), `offset`, `sort` (`ASC|DESC`), `sortBy` (`total_sold|product_id`). Response `{success, data: [{total_sold, product}], meta, count}` |
| GET | `/api/v1/user/categories` | Categories (with sub-categories) |
| GET | `/api/v1/user/categories/{id}/products` | Products of a category and its sub-categories |
| GET | `/api/v1/user/categories/{id}/products/search?search=` | Search inside a category |
| GET | `/api/v1/user/categories/{category}/best-selling-products` | |
| GET | `/api/v1/user/categories/{category_id}/new-arrival-products` | |
| GET | `/api/v1/user/brands` | Brands |
| GET | `/api/v1/user/brands/{id}/products` | |
| GET | `/api/v1/user/brands/{id}/best-selling-products` | |
| GET | `/api/v1/user/brands/{id}/new-arrival-products` | |
| GET | `/api/v1/user/health_issues` | Health issues |
| GET | `/api/v1/user/health_issues/{id}/products` | |
| GET | `/api/v1/user/healthissues/{id}/best-selling-products` | (note the path spelling) |
| GET | `/api/v1/user/healthissues/{id}/new-arrival-products` | |
| GET | `/api/user/products/{product}/reviews` | Reviews of a product (public; note: **not** under `/v1/user`) |

Web route (not API): `GET /product/{id}` redirects to the app deep link `elkawther://product/{id}`.

---

## 4. Favorites and reviews (token)

| Method | Path | Body | Result |
|---|---|---|---|
| GET | `/api/user/favorites` | | `{status, message, data: [products]}` |
| POST | `/api/user/favorites/{productId}` | | Toggles: `{"message": "Added to favorites"}` / `"Removed from favorites"`; 404 unknown product |
| POST | `/api/user/products/{product}/rate` | `rate` 1–5 (required), `review` ≤ 1000 | Creates or replaces the caller's review (one per product per customer) and recomputes `products.rate`. Any signed-in customer may review any product (no purchase check). |

---

## 5. Addresses (token)

| Method | Path |
|---|---|
| GET | `/api/user/addresses` |
| GET | `/api/user/addresses/{id}` |
| POST | `/api/user/addresses` |
| PUT | `/api/user/addresses/{id}` |
| DELETE | `/api/user/addresses/{id}` |
| POST | `/api/user/addresses/{id}/toggle-default` |

Body (create / update): `governorate`*, `city`*, `street`* (≤ 255), `building`, `apartment`,
`landmark`, `address`, `type`* (`home|work|pharmacy`), `is_default` (bool), `city_id` (an active
Egyptian city), `area_id` (an area of `city_id`). Sending `city_id`/`area_id` makes delivery pricing
exact; older apps that send only names are matched by exact governorate/city name once, and the link
is stored on the address.

---

## 6. Cart (token)

Every cart change re-prices the whole cart with the promotion engine and re-syncs promotional gift
lines. Prices always come from the database. Details: [marketing-promotions.md](../marketing-promotions.md).

| Method | Path | Body |
|---|---|---|
| GET | `/api/user/cart` | `?coupon=`, `?address_id=` (optional; `-1` = pickup) |
| POST | `/api/user/cart` | `product_id`* (not deleted), `product_size`* (a size **of that product**), `quantity`* 1–1000 |
| POST | `/api/user/cart/update-quantity` | `product_id`*, `operation` (`increment|decrement`), `old_size_id`, `new_size_id` (change size; merges into an existing line of that size) |
| DELETE | `/api/user/cart/{id}` | |
| POST | `/api/user/cart/apply-coupon` | `coupon`*, `address_id` (include delivery in the preview) |
| POST | `/api/user/delivery/quote` | `address_id`* — fee for the current cart's subtotal |

Behaviour:

* Adding a sold-out size → 400; a quantity above stock is capped at the stock.
* `GET /cart` removes sold-out lines and lowers quantities above stock, listing each under
  `adjustments` (`reason: out_of_stock | quantity_reduced`).
* Gift lines (`is_gift: true`, price 0) cannot be edited or deleted: `422 {code: gift_not_editable}`.
* A concurrent edit that would create a second line of the same size: `409 {code: cart_changed}`.
* Responses keep their original keys and add `pricing` (engine result summary) and `gift_changes`.
* Coupon preview failure: `400 {success: false, message, code: <reason>, pricing}`.
* Delivery quote: `200 {success: true, delivery_fee, free_delivery, free_over_amount, eta_min_days, eta_max_days, zone}` or
  `422 {success: false, code: no_zone | below_minimum, message, minimum_order}`.

---

## 7. Orders (token)

| Method | Path | Purpose |
|---|---|---|
| POST | `/api/user/orders` | **Checkout** (multipart when a receipt is uploaded) |
| GET | `/api/user/orders/grouped-by-status` | Own orders grouped by customer status |
| GET | `/api/user/order-details/{id}` | Order details |
| GET | `/api/user/track-order/{id}` | Status and delivery tracking |
| POST | `/api/user/orders/{id}/cancel` | Cancel a pending order (if the store allows it) |
| GET | `/api/user/points` | Legacy: points per order (`orders: [{order_name, points_earned, points_status, created_at}]`) |

### POST /api/user/orders — checkout

| Field | Rules |
|---|---|
| `address_id` | required integer; one of the caller's addresses, or `-1` for pickup at the pharmacy |
| `payment_method` | required; the `code` of a method offered now (`cod`, `paymob` when configured) |
| `payment_image` | image (jpeg/jpg/png/webp, ≤ 5 MB); required only for methods with `requires_receipt` (none of the active ones) |
| `notes` | ≤ 225 chars |
| `coupon_code` | ≤ 255 |
| `expected_total` | optional number: the total the app showed; if checkout computes a different total nothing is ordered (409) |
| `redeem_points` | optional integer ≥ 1: loyalty points to pay part of the order (Loyalty › Settings rules) |

Success `201`:

```json
{
  "message": "Order placed successfully",
  "order_id": 1042,
  "total_price": "455.00",
  "points_earned": 20,
  "gift_description": null,
  "subtotal": "420.00", "delivery_fee": "50.00", "discount": "0.00",
  "items_discount": "15.00", "coupon_discount": "0.00", "promotion_discount": "0.00",
  "delivery_discount": "0.00", "total_savings": "15.00",
  "gifts": [], "applied_promotions": [ ... ],
  "currency": "EGP",
  "payment_status": "unpaid",
  "points_redeemed": 0, "points_discount": "0.00",
  "payment": { "status": "...", "checkout_url": "https://...", "reference": "PAY-01J...", "expires_at": "...", "error": null }
}
```

`payment` is present only for Paymob orders (the provider is called after the order is committed;
if it cannot be reached, the order stays pending and the app retries with `/pay`).

Refusals (nothing is ordered, the cart is unchanged):

| Status | `code` | When |
|---|---|---|
| 422 | — (`message`: cart empty) | No paid lines in the cart |
| 422 | `out_of_stock` / (errors list) | Any line sold out or above stock (`errors: [{product, size, message}]`) |
| 422 | `no_zone` / `below_minimum` | Address not covered by an active zone/rate, or below the rate's minimum order (`minimum_order`) |
| 422 | `gift_unavailable` | A promotion with gift fallback *block* cannot give its gift |
| 422 | `points_rejected` | `redeem_points` refused (`reason`: disabled, below minimum, not combinable, one at a time, over the cap, must redeem all, worth nothing, online payment needs an amount) |
| 422 | `coupon_rejected` | The entered coupon does not apply (`reason`), unless a better automatic offer won |
| 422 | `payment_unavailable` | The chosen online method is not configured |
| 409 | `pricing_changed` | `expected_total` differs from the computed total (`pricing` included) |

### GET /api/user/order-details/{id}

Original keys: `success, order_id, status, status_meaning (Arabic), payment_method, delivery_fee,
total_price, points_earned, gift_description, address {city, street, building, apartment, landmark},
products [{product, quantity, original_price, price_at_time, total_price, size, product_name, is_gift,
discount_amount, promotion}], created_at, last_update`. Added keys: `status_label, fulfillment_stage,
payment_status, refund_status, subtotal, discount, items_discount, coupon_discount,
promotion_discount, delivery_discount, total_savings, coupon_code, promotions, currency, can_cancel,
delivery`.

`status` is the **customer status**: the warehouse steps `picking` and `packed` are reported as
`confirmed` (apps know five statuses: `pending, confirmed, on_delivery, completed, canceled`); the exact
step is `fulfillment_stage`. The address is the snapshot taken at checkout.

### GET /api/user/track-order/{id}

`{success, order_id, status, status_meaning, last_update, status_label, fulfillment_stage,
payment_status, delivery}` — `delivery` is the latest shipment's customer view (`status`,
`status_label`, `provider`, `tracking_number`, `tracking_url`, `delivered_at`,
`estimated_delivery_at`, `events [{status, status_label, occurred_at, ...}]`) or `null` (pickup).

### POST /api/user/orders/{id}/cancel

Allowed only when `settings.customer_can_cancel_pending` is on (default **off**) and the order is
`pending` → `{success: true, message, status: "canceled"}`; otherwise `422 {code: cancel_not_allowed}`.
Cancellation releases stock, gives back points used, reverses points earned, releases promotion and
coupon uses, cancels unpaid payments (and queues a refund for money already received) and cancels
open shipments.

---

## 8. Online payment (token)

| Method | Path | Purpose |
|---|---|---|
| POST | `/api/user/orders/{id}/pay` | Start or retry the Paymob payment of an unpaid online order. 200 `{success: true, order_id, payment: {status, checkout_url, reference, expires_at, error}}`; 502 when Paymob cannot be reached; 422 `{code: payment_not_possible}` (already paid, canceled, not an online order, window over...) |
| GET | `/api/user/orders/{id}/payment` | Poll after returning from checkout: `{success, order_id, payment_status, refund_status, payment: {reference, method, status, status_label, amount, currency, paid_at, expires_at}}` |
| GET | `/api/user/files/payment-receipt/{order}` | The order's uploaded transfer receipt (legacy manual transfers), owner only |

The customer's browser returns from Paymob to `GET /payments/paymob/return` (web route), which
applies the signed result and redirects to `PAYMOB_RETURN_URL` (default `elkawther://payment-result`)
with `?status=success|pending|failed&reference=PAY-...&order_id=...`. The app should then poll
`GET /orders/{id}/payment`: the server-to-server webhook is the source of truth.

---

## 9. Returns (token)

| Method | Path | Body |
|---|---|---|
| POST | `/api/user/returns` | multipart: `order_id`*, `reason`* ≤ 255, `note` ≤ 2000, `refund_amount` (0–99 999 999.99), `refund_method`, `address`, `images[]` ≤ 10 images (jpeg/jpg/png/webp, ≤ 2 MB each) |
| GET | `/api/user/returns` | Own requests: `[{id, order_id, reason, status, refund_amount, created_at, order, images}]` |
| GET | `/api/user/returns/{id}` | With order and images |
| POST | `/api/user/returns/{id}/cancel` | Only while `pending` (one conditional update) → 200; else 400 |
| GET | `/api/user/files/return-image/{image}` | Own return image |

Refusals on create: 404 not the caller's order; 422 `order_canceled`; 422 `order_not_delivered`
(no unit was dispatched yet); with `settings.return_window_days` set: 422 `order_not_completed`,
422 `return_window_closed`; 422 when pending + accepted refund amounts would exceed the order total.

---

## 10. Loyalty (token)

| Method | Path | Notes |
|---|---|---|
| GET | `/api/user/loyalty` | `{data: {available, pending, expiring_soon, next_expiry_at, total_earned, total_redeemed, total_returned, total_expired, total_reversed, total_adjusted_down, owed}, rules: {...}}` |
| GET | `/api/user/loyalty/history` | `type`, `from`, `to`, `per_page` — paginated ledger entries |
| GET | `/api/user/loyalty/expiring?days=` | `{days, points, data: [lots]}` |
| GET | `/api/user/loyalty/rewards` | Rewards available now, each with `affordable`; `balance` added |
| GET | `/api/user/loyalty/rewards/{reward}` | One reward |
| POST | `/api/user/loyalty/rewards/{reward}/redeem` | `quantity` 1–100 (default 1), `Idempotency-Key` header or `idempotency_key` field. 201 `{data: redemption, message, balance}` (200 when the same key returns the earlier redemption), or 422 `{code: redemption_refused, message}`. throttle 10/min |
| GET | `/api/user/loyalty/redemptions?status=` | `pending|approved|fulfilled|cancelled|expired` |
| GET | `/api/user/loyalty/redemptions/{redemption}` | |
| GET | `/api/user/points/valid` | Legacy shape: `{valid_points_total, orders: [{order_id, display_id, points_earned, created_at, points_original, points_reversed, ..., expires_at}]}` |
| GET | `/api/user/points/expired` | `{expired_points_total, orders: [... expired_at]}` |
| GET | `/api/user/points/notvalid` | `{consumed_points_total, orders: [... redeemed_at]}` |

Programme rules: [loyalty.md](../loyalty.md).

---

## 11. Health services (token)

| Method | Path | Body / query | Notes |
|---|---|---|---|
| POST | `/api/user/health/body-weight` | `height` (>0, ≤ 300), `weight` (>0, ≤ 1000), `unit` (`metric|imperial`) | BMI and the matching **result rule** (dashboard-managed; defaults `underweight` < 18.5 ≤ `normal` < 25 ≤ `overweight` < 30 ≤ `obese_class_1` < 35 ≤ `obese_class_2` < 40 ≤ `obese_class_3`): `status`, `status_code`, `status_label`, `description` / `description_label` (the rule message), `requires_urgent_care`, ideal range, `guideline`, `disclaimer` |
| GET | `/api/user/health/weight` | `page`, `per_page` | History |
| POST | `/api/user/health/blood-sugar` | `value` 0–2000 (mg/dL), `condition_type`, `measured_at` (≤ end of today) | Result rule per condition (defaults: < 70 low; fasting-like < 100 normal; after meal < 140 normal; random < 200 normal; else high): `evaluation`, `evaluation_code`, `evaluation_label`, `advice`, `requires_urgent_care`, `guideline`, `disclaimer` |
| GET | `/api/user/health/blood-sugar` | `page`, `per_page` | |
| POST | `/api/user/health/blood-pressure` | `systolic` 1–400, `diastolic` 1–300, `condition_type`, `measured_at` | Result rule (defaults 2017 ACC/AHA: `normal, elevated, stage_1, stage_2, hypertensive_crisis, low`): `evaluation`, `evaluation_code`, `evaluation_label`, `requires_urgent_care`, `advice`, `guideline`, `disclaimer` |
| GET | `/api/user/health/blood-pressure` | `page`, `per_page` | |
| POST | `/api/user/health/ovulation` | `start_day_of_cycle` (within the last year), `cycle_length`, `period_length` (ranges from Health settings, default 21–35 and 3–10) | Stores the cycle and returns the calculation |
| GET | `/api/user/health/ovulation/current` | | Calendar by month with each day's phase and pregnancy chance, and today; `code: no_cycle_data` when none |
| POST | `/api/user/health/calculatePregnancy` | `last_period_date` (within 2 years, ≤ today) | Due date (280 days), week/day, trimester, baby size/weight/length (pregnancy week content) |
| GET | `/api/user/health/CurrentPregnancyStage` | | Current stage; 404 `no_pregnancy_data` |
| POST | `/api/user/health/child` | `child_name` ≤ 100, `gender`, `birth_date` (≤ today, within 18 years) | Creates the child and its vaccine schedule from the active schedule |
| GET | `/api/user/health/child/vaccines` | | Children with vaccines by date |
| PATCH | `/api/user/health/vaccines/{vaccine}/complete` | `is_completed` (bool, default true) | Own child's vaccine only |
| DELETE | `/api/user/health/child/{id}` | | Own child |

Histories without `page`/`per_page` return up to 500 rows with `meta.truncated` (legacy behaviour);
with them, a normal paginator meta.

**Dashboard control.** Result categories, their labels and messages, the medical wording (pregnancy, result notes), and
the settings referenced above are managed from *Health content* (see
[health-services.md](../functional/health-services.md)). A tool switched off there answers its
`POST` (and medication create/update) with:

```json
403 {"message": "This service is not available at the moment.", "code": "health_tool_disabled", "tool": "bmi"}
```

Tools: `bmi`, `blood_sugar`, `blood_pressure`, `ovulation`, `pregnancy`, `vaccines` (`POST /child`),
`medication`. Histories, reads and deletes keep working.

### Medication reminders

| Method | Path | Body |
|---|---|---|
| GET | `/api/user/medication` | The current week (store calendar, week starts on the country's `week_start`, Saturday) with each dose time and its log status |
| POST | `/api/user/medication` | multipart: `name`*, `notes`, `image` (jpeg/png/jpg/gif ≤ 2 MB, private), `quantity_per_time`* ≥ 1, `repeat_type`* (`every_day|specific_days|once`), `times[]`* (`H:i` or `H:i:s`), `days[]` (`saturday`…`friday`) |
| POST | `/api/user/medications/{medication}/update` | same as create |
| PUT | `/api/user/{medication}` | same as create (kept for existing apps; numeric id only) |
| POST | `/api/user/medications/{medication}/log` | `time`* `H:i:s`, `status`* (`taken|missed`), `shown_date` (`Y-m-d`) |
| DELETE | `/api/user/medications/remove-day` | `medication_id`*, `day`* — removes a day (a one-time medication is deleted; an every-day one becomes specific-days) |
| DELETE | `/api/user/medication/{medication}` | |
| GET | `/api/user/files/medication/{medication}` | Own medication image |

---

## 12. Notifications and devices (token)

| Method | Path | Body |
|---|---|---|
| POST | `/api/user/devices` | `token`* ≤ 512, `platform`* (`android|ios|web`), `locale` (`ar|en`). A token moves to the caller if another account had it. 201 `{message, id}` |
| DELETE | `/api/user/devices` | `token`* |
| GET | `/api/user/notifications` | `per_page` — `{data: [{id, read_at, created_at, ...notification data}], meta: {current_page, last_page, total}}` |

There is no endpoint to mark notifications as read.

## 13. Contact messages (token)

| Method | Path | Body |
|---|---|---|
| POST | `/api/user/contact-messages` | `name`* ≤ 255, `email` (e-mail), `message`* ≤ 5000 → 201 |
| GET | `/api/user/contact-messages` | `page`, `per_page` — conversations, newest activity first, each with `replies [{id, from: customer|pharmacy, body, created_at}]` and `status` (`open|replied`) |
| GET | `/api/user/contact-messages/{id}` | One conversation |
| POST | `/api/user/contact-messages/{id}/replies` | `body`* ≤ 5000 (throttle 10/min) → 201; reopens the conversation |

Staff names are never shown; pharmacy replies also arrive as a push notification.

---

## 14. Webhooks (server to server)

### POST /api/webhooks/payments/paymob

Paymob transaction callback. Verified by HMAC-SHA512 over Paymob's documented transaction fields
(`hmac` query parameter) with the environment's HMAC secret, then (when `PAYMOB_VERIFY_WITH_API=true`)
confirmed with Paymob's transaction API. Responses: `200 {status: processed|ignored|rejected|duplicate}`,
`400 {status: invalid_signature}` (nothing applied), `503 {status: in_progress}` + `Retry-After: 60`,
`500` only when our own processing failed (so Paymob retries). Idempotent per statement.
Register the URL printed by `php artisan payments:paymob-check` in the Paymob dashboard.

### POST /api/webhooks/delivery/{provider code}

For providers using the `signed_webhook` adapter.

```http
POST /api/webhooks/delivery/aramex
X-Signature: 5f1c...            (hex HMAC-SHA256 of the raw body with the provider's webhook secret)
Content-Type: application/json

{"event_id": "evt-981", "reference": "SHP-55", "tracking_number": "AX123",
 "status": "OUT_FOR_DELIVERY", "occurred_at": "2026-11-04T10:15:00+02:00", "note": "..."}
```

A body may also be `{"events": [ ... ]}`. External statuses are translated through the provider's
status map; an unmapped status is stored and flags the shipment for a person. 401 bad signature
(nothing stored); otherwise 200 with what happened to each update (unknown shipments are reported,
not failed). A repeated `event_id` is a no-op.
