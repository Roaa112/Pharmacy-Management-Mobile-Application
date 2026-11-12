# Catalog: products, sizes, categories, brands, health issues, reviews, favorites

Code: `app/Modules/Product`, `Category`, `Brand`, `HealthIssue`, `Rate`, `Favorite`; models `Product`,
`ProductSize`, `ProductImage`, `Category`, `Brand`, `HealthIssue`, `ProductReview`, `Favorite`.
Dashboard: **Catalog › Products / Categories / Brands / Health issues**.

## 1. Purpose

The pharmacy's product catalog as customers browse it in the app and as staff maintain it.

## 2. Concepts

| Concept | Meaning |
|---|---|
| Product | Bilingual name and description (`name_ar/en`, `description_ar/en`), main image, extra images, category, optional brand, health issues it treats, stable code `PRD-000123`. Soft-deleted when removed (kept for order history). |
| Size (SKU) | `product_sizes`: the sellable variant — size label, price, optional colour, unique SKU and barcode, cost price, min/max stock, reorder point and quantity. Stock lives per size (and per warehouse/batch in Inventory). |
| Category | Two levels used: categories and sub-categories (`parent_id`). Product lists of a category include its sub-categories. |
| Brand, health issue | Grouping and browsing (a product may belong to several health issues). |
| Review | One per customer per product (1–5 + optional text); the product's `rate` is the average. |
| Favorite | A customer's saved product (one row per product). |

## 3. Customer capabilities (public API, `/api/v1/user/...`)

Browse products, categories (with products, search inside a category, best sellers, new arrivals),
brands and health issues (with products, best sellers, new arrivals), product details and related
products, products on sale, latest products, top discounts, global best sellers, search; read
reviews. Signed-in customers can favorite/unfavorite and rate products. Endpoints:
[api-reference.md §3–4](../technical/api-reference.md#3-public-catalog-apiv1user-no-token).

Display rules:

* Prices shown are the **promotion engine's catalog price** (live flash sale or discount, without cart
  conditions such as minimum quantity). The cart may apply further conditional discounts.
* A sold-out size stays in `size_options` with `in_stock: false` and is left out of
  `available_sizes`. A fully sold-out product stays visible with `stock_status: out_of_stock`
  (decision D6) and cannot be added to the cart. Deleted products are hidden.
* Best-seller lists count non-canceled orders only and ignore free gift lines.
* Messages and names follow `Accept-Language` (`ar` / `en`).

## 4. Admin capabilities

| Screen | Capabilities | Permissions |
|---|---|---|
| Products | List/search, create (name ar/en, descriptions, main image ≤ 2 MB jpg/png/webp, extra images, category, brand, health issues, sizes with price and opening stock), edit, delete (soft). Import/export buttons. | `products.view`, `.create`, `.update`, `.delete`, `.import`, `.export` |
| Categories | List, create, edit, delete, add sub-category; import/export | `categories.view`, `.manage`, `.import`, `.export` |
| Brands | List, create, edit, delete; import/export | `brands.view`, `.manage`, `.import`, `.export` |
| Health issues | List, create, edit, delete | `health_issues.view`, `.manage` |
| Inventory › SKU | SKU settings (SKU, barcode, colour, cost, min/max, reorder) | `inventory.adjust` |

### Stock edits from the product form

The edit form remembers the stock it displayed (`stock_original`). If the field is unchanged, stock is
left alone (sales made meanwhile are kept). If it changed (e.g. 10 → 60), the **difference** (+50) is
applied to the current stock of the default warehouse as a recorded `adjustment` movement. A change
that would make stock negative is refused ("reload and try again"). Warehouse-aware operations are on
Inventory › SKU. Decision D8 (an exact-count mode) is open.

### Removing a size

A size removed from the form is deleted if it was never sold; if it appears on any order it is kept
(for history) and its stock is set to 0 with a recorded adjustment.

## 5. Data

`products`, `product_sizes`, `product_images`, `categories`, `brands`, `health_issues`,
`health_issue_product`, `product_reviews`, `favorites` — [database.md §3.2](../technical/database.md#32-catalog).

## 6. Business rules and edge cases

* SKU and barcode are unique across all products.
* Deleting a **category** cascades to its sub-categories (`categories.parent_id ON DELETE CASCADE`);
  products of a deleted category or brand keep existing with `category_id` / `brand_id` set to null.
  There is no confirmation of affected products beyond the dashboard's delete dialog.
* Reviews: any signed-in customer can rate any product (no "verified purchase" check); a second
  rating replaces the first.
* `products.saleable_*` (an old polymorphic flash-sale link) is still accepted by the product form but
  prices come only from the promotion engine.

## 7. Background processing

None specific. Imports (products, categories, brands) can run on the queue — see
[import-export.md](../import-export.md).

## 8. Security

Public read endpoints are rate-limited (`throttle:api`) and sanitized
(`SanitizeListingParameters`). Admin actions need their permission per route. Catalog changes are
**not audited** (known gap).

## 9. Dependencies

Promotions (prices), Inventory (stock projection), Orders (snapshots keep names and size labels),
Import/Export.

## 10. Tests

`Feature/Api/CatalogApiTest`, `FavoritesAndReviewsApiTest`, `Catalog/CatalogSmokeTest`,
`CatalogQueryCountTest` (N+1 guard), `Inventory/OutOfStockTest`, `Pricing/FlashSalePricingTest`.
