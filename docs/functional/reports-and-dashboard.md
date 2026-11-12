# Reports and the dashboard home

Code: `app/Http/Controllers/HomeController`, `app/Modules/Reports/ReportController`,
`app/Modules/Reports/Revenue`, `MarketingDashboardController`, `LoyaltyDashboardController`,
`InventoryController::reports`, `PaymentController::reports`. Views in `resources/views/dashboard`.

## 1. Revenue rule (shared)

> **Revenue = totals of completed orders − money actually refunded on them** (succeeded refunds only;
> refunds of duplicate captures are excluded because the order never kept that money).

An approved return whose refund is not paid yet, or was canceled, does not lower revenue. Daily
figures use the store's **local calendar day** (DST-aware SQL in `Revenue`). The same rule feeds the
dashboard home, the orders list total and the reports page (decision D3).

## 2. Dashboard home (`/dashboard`, any signed-in admin)

KPIs: net revenue, orders, pending orders, completed orders, customers, products, low-stock sizes
(1 … threshold), out-of-stock sizes, pending returns, orders with a coupon; daily orders/revenue
trend; 6 most recent orders; top 5 products by units (non-canceled orders); 6 lowest-stock sizes.

The sidebar lists only the sections the admin's role can open and has a **menu search** box
(filters links by page or section name; Enter opens the first match, Escape clears, `/` focuses the
field). It shows "needs attention" badges, cached for 60 seconds: pending orders, pending returns,
out-of-stock sizes, payments awaiting verification, shipments needing attention, pending/failed
refunds, open reward redemptions, open contact messages.

> Note: the home page requires only an admin session (no permission), so every admin sees its KPIs,
> including revenue.

## 3. Reports page (`/dashboard/reports`, `reports.view`)

Date range `from` / `to` (default: the last 30 days, ending today). Sections:

| Section | Content |
|---|---|
| Summary | Net revenue, orders in range, completed, average completed order, canceled, new customers |
| Status counts | Orders per status |
| Daily | Revenue per day in the range |
| Monthly | Revenue and new customers per month for the last 12 months (independent of the range) |
| Top products | Top 10 by units, with sales (non-canceled orders in the range) |
| Slow moving | In-stock sizes that sold nothing in the range |
| Returns | Return requests created in the range, per status, with count and amount (approved amount, else requested) |
| Coupons | Top 10 coupons by orders in the range (non-canceled), with order value |
| Loyalty | Points earned (earned + bonus), redeemed (net of cancelled), reversed by refunds, expired — from the ledger |
| Payments | Non-canceled orders per payment method, with value and receipts awaiting verification |
| Delivery | Shipments per status with fees charged and COD; COD collected but not settled |

## 4. Other report screens

| Screen | Permission | Content |
|---|---|---|
| Marketing overview | `marketing.view` | Live / scheduled promotions, live flash sales, active coupons, banners/ads shown, orders with offers, customer savings; performance per offer from order snapshots |
| Loyalty overview | `loyalty.view` | Active, pending, earned, redeemed, expired, reversed points; rewards; redemptions; top balances |
| Inventory reports | `inventory.view` | Valuation, reorder list, movement summary, integrity problems, near-expiry batches |
| Payment reports | `payments.view` | By provider, failure reasons, COD due / collected / settled, refunds by status and method |

## 5. Exports

Reports themselves have no export button; data exports are on each data page (orders, payments,
refunds, point transactions, ...) — see [import-export.md](../import-export.md).

## 6. Limitations

* The reports date filter compares `created_at` (UTC) with dates parsed in the app timezone (UTC),
  while daily grouping uses the store's local day; orders placed shortly after local midnight can
  fall on the previous day's edge of a range (noted as "the report date-filter edge" in the
  implementation report).
* No scheduled or e-mailed reports.

## 7. Tests

`Admin/AdminPagesTest`, `Orders/FinancialTest`, `Marketing/MarketingDashboardTest`,
`Loyalty/LoyaltyProgrammeTest`.
