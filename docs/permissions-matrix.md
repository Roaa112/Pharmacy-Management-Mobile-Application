# Permissions matrix

Who may do what, in the dashboard and in the customer API. The model (one role per admin, database
roles, server-side protections) is explained in [roles-and-permissions.md](roles-and-permissions.md);
this file is the reference table.

> **Generated sections.** The role × permission table (section 2) is the state created by the
> migrations on a fresh database (`RbacSynchronizer` + `PermissionRegistry::defaultsFor`). In a running
> system these are **starting defaults only**: admins edit role permissions from
> *Operations › Roles & permissions*, so the live database may differ. The route table (section 3) was
> exported from the router (`php artisan route:list --json`). See
> [testing-and-deployment.md](testing-and-deployment.md#5-regenerating-generated-reference-sections).

## 1. How authorization works

| Actor | Authentication | Authorization |
|---|---|---|
| **Customer** (mobile app) | Sanctum bearer token (`auth:sanctum`), issued by `POST /api/user/login`. Tokens expire after `SANCTUM_EXPIRATION` minutes (default 43 200 = 30 days). | Owner-only: every customer endpoint scopes its query to `user_id = auth user` (orders, addresses, returns, payments, loyalty, health records, contact messages, files, devices). Another customer's id answers 404. A deactivated account gets `403 {code: account_inactive}` on every token request (`EnsureAccountIsActive`). |
| **Admin** (dashboard) | Session guard `admin` (`/login`, Laravel UI). Inactive admins cannot sign in and are signed out on their next request. | Each permission slug is a Gate ability (`AppServiceProvider`). Routes declare `can:<slug>`; a few checks depend on the data and are made in the controller or service (section 4). The admin's role and its active permissions are read from the database on every request. |
| **Payment provider** | HMAC signature (Paymob) on `POST /api/webhooks/payments/paymob` and `GET /payments/paymob/return`. | Unsigned or mismatching statements are stored as `rejected` and change nothing. |
| **Delivery provider** | `X-Signature: hex(HMAC-SHA256(raw body, webhook secret))` on `POST /api/webhooks/delivery/{code}` (adapter `signed_webhook`). | Manual-adapter providers do not accept webhooks. |
| **Anyone** | none | Public catalog endpoints under `/api/v1/user/...`, product reviews list, `/up` health check, `/product/{id}` deep-link redirect, signed private-file links (`/files/{type}/{id}`, valid 60 minutes). |

Module abilities: the module names (`orders`, `users`, `refunds`, `payments`, `delivery`, `catalog`,
`inventory`, `purchasing`, `marketing`, `loyalty`, `reports`, `health_data`, `health_content`,
`settings`, `admins`) are also Gate abilities meaning "holds the module's view permission"
(`PermissionRegistry::MODULES`). `data_transfer` means "holds at least one `*.import` / `*.export`
permission". The sidebar hides items the admin cannot open; hiding is never the protection.

## 2. Default role × permission matrix

✓ = granted on a fresh install. The super admin role (`is_super_admin = 1`) holds every permission by
definition (it is not stored per permission). Totals: super_admin 86, manager 73, orders_agent 23,
health_specialist 4, content_editor 23, accountant 28, warehouse_manager 28.

| Permission | Module | super_admin | manager | orders_agent | health_specialist | content_editor | accountant | warehouse_manager |
|---|---|---|---|---|---|---|---|---|
| `orders.view` | orders | ✓ | ✓ | ✓ | · | · | ✓ | ✓ |
| `orders.update_status` | orders | ✓ | ✓ | ✓ | · | · | ✓ | ✓ |
| `orders.cancel` | orders | ✓ | ✓ | ✓ | · | · | ✓ | ✓ |
| `orders.export` | orders | ✓ | ✓ | · | · | · | ✓ | · |
| `customers.view` | users | ✓ | ✓ | ✓ | · | · | · | · |
| `customers.activate` | users | ✓ | ✓ | ✓ | · | · | · | · |
| `customers.export` | users | ✓ | · | · | · | · | · | · |
| `contact_messages.view` | users | ✓ | ✓ | ✓ | · | · | · | · |
| `contact_messages.reply` | users | ✓ | ✓ | ✓ | · | · | · | · |
| `contact_messages.delete` | users | ✓ | ✓ | ✓ | · | · | · | · |
| `refunds.view` | refunds | ✓ | ✓ | · | · | · | ✓ | · |
| `refunds.approve` | refunds | ✓ | ✓ | · | · | · | ✓ | · |
| `refunds.create` | refunds | ✓ | ✓ | · | · | · | ✓ | · |
| `refunds.process` | refunds | ✓ | ✓ | · | · | · | ✓ | · |
| `refunds.export` | refunds | ✓ | · | · | · | · | ✓ | · |
| `payments.view` | payments | ✓ | ✓ | · | · | · | ✓ | · |
| `payments.verify` | payments | ✓ | ✓ | · | · | · | ✓ | · |
| `payments.collect` | payments | ✓ | ✓ | · | · | · | ✓ | · |
| `payments.settle_cod` | payments | ✓ | ✓ | · | · | · | ✓ | · |
| `payment_methods.manage` | payments | ✓ | ✓ | · | · | · | ✓ | · |
| `payments.export` | payments | ✓ | · | · | · | · | ✓ | · |
| `delivery.view` | delivery | ✓ | ✓ | ✓ | · | · | · | · |
| `delivery.manage_zones` | delivery | ✓ | ✓ | ✓ | · | · | · | · |
| `delivery.manage_providers` | delivery | ✓ | ✓ | ✓ | · | · | · | · |
| `delivery.manage_shipments` | delivery | ✓ | ✓ | ✓ | · | · | · | · |
| `locations.manage` | delivery | ✓ | ✓ | ✓ | · | · | · | · |
| `locations.import` | delivery | ✓ | ✓ | · | · | · | · | · |
| `locations.export` | delivery | ✓ | ✓ | · | · | · | · | · |
| `products.view` | catalog | ✓ | ✓ | ✓ | · | ✓ | · | ✓ |
| `products.create` | catalog | ✓ | ✓ | ✓ | · | ✓ | · | ✓ |
| `products.update` | catalog | ✓ | ✓ | ✓ | · | ✓ | · | ✓ |
| `products.delete` | catalog | ✓ | ✓ | ✓ | · | ✓ | · | ✓ |
| `products.import` | catalog | ✓ | ✓ | · | · | · | · | · |
| `products.export` | catalog | ✓ | ✓ | · | · | ✓ | · | ✓ |
| `categories.view` | catalog | ✓ | ✓ | ✓ | · | ✓ | · | ✓ |
| `categories.manage` | catalog | ✓ | ✓ | ✓ | · | ✓ | · | ✓ |
| `categories.import` | catalog | ✓ | ✓ | · | · | ✓ | · | · |
| `categories.export` | catalog | ✓ | ✓ | · | · | ✓ | · | · |
| `brands.view` | catalog | ✓ | ✓ | ✓ | · | ✓ | · | ✓ |
| `brands.manage` | catalog | ✓ | ✓ | ✓ | · | ✓ | · | ✓ |
| `brands.import` | catalog | ✓ | ✓ | · | · | ✓ | · | · |
| `brands.export` | catalog | ✓ | ✓ | · | · | ✓ | · | · |
| `health_issues.view` | catalog | ✓ | ✓ | ✓ | · | ✓ | · | ✓ |
| `health_issues.manage` | catalog | ✓ | ✓ | ✓ | · | ✓ | · | ✓ |
| `inventory.view` | inventory | ✓ | ✓ | · | · | · | · | ✓ |
| `inventory.adjust` | inventory | ✓ | ✓ | · | · | · | · | ✓ |
| `inventory.transfer` | inventory | ✓ | ✓ | · | · | · | · | ✓ |
| `inventory.count` | inventory | ✓ | ✓ | · | · | · | · | ✓ |
| `warehouses.manage` | inventory | ✓ | ✓ | · | · | · | · | ✓ |
| `inventory.import` | inventory | ✓ | ✓ | · | · | · | · | ✓ |
| `inventory.export` | inventory | ✓ | ✓ | · | · | · | · | ✓ |
| `purchasing.view` | purchasing | ✓ | ✓ | · | · | · | ✓ | ✓ |
| `suppliers.manage` | purchasing | ✓ | ✓ | · | · | · | ✓ | ✓ |
| `supplier_finance.manage` | purchasing | ✓ | ✓ | · | · | · | ✓ | ✓ |
| `purchase_orders.manage` | purchasing | ✓ | ✓ | · | · | · | ✓ | ✓ |
| `purchase_orders.receive` | purchasing | ✓ | ✓ | · | · | · | ✓ | ✓ |
| `suppliers.import` | purchasing | ✓ | ✓ | · | · | · | · | ✓ |
| `suppliers.export` | purchasing | ✓ | ✓ | · | · | · | ✓ | ✓ |
| `marketing.view` | marketing | ✓ | ✓ | · | · | ✓ | · | · |
| `promotions.manage` | marketing | ✓ | ✓ | · | · | ✓ | · | · |
| `campaigns.manage` | marketing | ✓ | ✓ | · | · | ✓ | · | · |
| `coupons.manage` | marketing | ✓ | ✓ | · | · | ✓ | · | · |
| `flash_sales.manage` | marketing | ✓ | ✓ | · | · | ✓ | · | · |
| `banners.manage` | marketing | ✓ | ✓ | · | · | ✓ | · | · |
| `loyalty.view` | loyalty | ✓ | ✓ | · | · | · | ✓ | · |
| `loyalty.manage` | loyalty | ✓ | ✓ | · | · | · | ✓ | · |
| `loyalty.adjust_points` | loyalty | ✓ | ✓ | · | · | · | ✓ | · |
| `loyalty.manage_rewards` | loyalty | ✓ | ✓ | · | · | · | ✓ | · |
| `loyalty.settings` | loyalty | ✓ | ✓ | · | · | · | ✓ | · |
| `loyalty_rewards.import` | loyalty | ✓ | ✓ | · | · | · | · | · |
| `loyalty_rewards.export` | loyalty | ✓ | ✓ | · | · | · | · | · |
| `loyalty_transactions.export` | loyalty | ✓ | · | · | · | · | ✓ | · |
| `reports.view` | reports | ✓ | ✓ | · | · | · | ✓ | · |
| `health_data.view` | health_data | ✓ | · | · | ✓ | · | · | · |
| `health_content.view` | health_content | ✓ | ✓ | · | ✓ | ✓ | · | · |
| `health_content.manage` | health_content | ✓ | ✓ | · | ✓ | ✓ | · | · |
| `health_rules.manage` | health_content | ✓ | · | · | ✓ | · | · | · |
| `settings.view` | settings | ✓ | ✓ | · | · | · | · | · |
| `settings.update` | settings | ✓ | ✓ | · | · | · | · | · |
| `admins.view` | admins | ✓ | · | · | · | · | · | · |
| `admins.create` | admins | ✓ | · | · | · | · | · | · |
| `admins.update` | admins | ✓ | · | · | · | · | · | · |
| `roles.view` | admins | ✓ | · | · | · | · | · | · |
| `roles.manage` | admins | ✓ | · | · | · | · | · | · |
| `roles.assign` | admins | ✓ | · | · | · | · | · | · |
| `audit_log.view` | admins | ✓ | · | · | · | · | · | · |

### Role intent (from `App\Enums\AdminRole`)

| Role | Purpose |
|---|---|
| Super Admin | Full access, including admins, roles and health records. Cannot be edited (except name/description by a super admin), deactivated or deleted; at least one active super admin always exists. |
| Manager | Runs the store: everything except customers' health records and admin accounts. |
| Orders Agent | Orders, delivery and customers (plus catalog read). |
| Health Specialist | Customers' health records and health content. |
| Content Editor | Catalog, marketing and health content. |
| Accountant | Orders, refunds, payments, reports, loyalty and purchasing. |
| Warehouse Manager | Stock, warehouses and purchasing; sees orders to pick and pack them. |

Import/export permissions are granted only where listed in `PermissionRegistry::IMPORT_EXPORT_DEFAULTS`
(Manager, Warehouse Manager, Content Editor, Accountant); `customers.export` is super-admin only until
granted. Custom roles can be created from the dashboard (they start with the permissions chosen; a
duplicated role starts inactive).

## 3. Dashboard routes and the abilities they require

Every route below also requires an authenticated, active admin session (`auth:admin` +
`EnsureAccountIsActive`). "A + B" means both abilities are required (an outer group and the action).

| Method | URI | Abilities required (all) | Controller action |
|---|---|---|---|
| GET | `/dashboard` | admin session only | `HomeController@home` |
| GET | `/dashboard/OpeningAd` | `marketing.view` | `OpeningAd\OpeningAdController@index` |
| POST | `/dashboard/OpeningAd` | `marketing.view` + `banners.manage` | `OpeningAd\OpeningAdController@store` |
| GET | `/dashboard/OpeningAd/create` | `marketing.view` + `banners.manage` | `OpeningAd\OpeningAdController@create` |
| DELETE | `/dashboard/OpeningAd/{OpeningAd}` | `marketing.view` + `banners.manage` | `OpeningAd\OpeningAdController@destroy` |
| GET | `/dashboard/OpeningAd/{OpeningAd}` | `marketing.view` | `OpeningAd\OpeningAdController@show` |
| PUT/PATCH | `/dashboard/OpeningAd/{OpeningAd}` | `marketing.view` + `banners.manage` | `OpeningAd\OpeningAdController@update` |
| GET | `/dashboard/OpeningAd/{OpeningAd}/edit` | `marketing.view` + `banners.manage` | `OpeningAd\OpeningAdController@edit` |
| GET | `/dashboard/admins` | `admins.view` | `Admin\AdminUserController@index` |
| POST | `/dashboard/admins` | `admins.create` | `Admin\AdminUserController@store` |
| GET | `/dashboard/admins/{admin}` | `admins.view` | `Admin\AdminUserController@show` |
| PUT | `/dashboard/admins/{admin}` | `admins.update` | `Admin\AdminUserController@update` |
| PUT | `/dashboard/areas/{area}` | `delivery.view` + `locations.manage` | `Delivery\Admin\LocationAdminController@updateArea` |
| GET | `/dashboard/audit-log` | `audit_log.view` | `Admin\AuditLogController@index` |
| GET | `/dashboard/banners` | `marketing.view` | `Banner\BannerController@index` |
| POST | `/dashboard/banners` | `marketing.view` + `banners.manage` | `Banner\BannerController@store` |
| GET | `/dashboard/banners/create` | `marketing.view` + `banners.manage` | `Banner\BannerController@create` |
| DELETE | `/dashboard/banners/{banner}` | `marketing.view` + `banners.manage` | `Banner\BannerController@destroy` |
| GET | `/dashboard/banners/{banner}` | `marketing.view` | `Banner\BannerController@show` |
| PUT/PATCH | `/dashboard/banners/{banner}` | `marketing.view` + `banners.manage` | `Banner\BannerController@update` |
| GET | `/dashboard/banners/{banner}/edit` | `marketing.view` + `banners.manage` | `Banner\BannerController@edit` |
| GET | `/dashboard/brands` | `brands.view` | `Brand\BrandController@index` |
| POST | `/dashboard/brands` | `brands.manage` | `Brand\BrandController@store` |
| GET | `/dashboard/brands/create` | `brands.manage` | `Brand\BrandController@create` |
| DELETE | `/dashboard/brands/{brand}` | `brands.manage` | `Brand\BrandController@destroy` |
| GET | `/dashboard/brands/{brand}` | `brands.view` | `Brand\BrandController@show` |
| PUT/PATCH | `/dashboard/brands/{brand}` | `brands.manage` | `Brand\BrandController@update` |
| GET | `/dashboard/brands/{brand}/edit` | `brands.manage` | `Brand\BrandController@edit` |
| GET | `/dashboard/campaigns` | `marketing.view` | `Discount\CampaignController@index` |
| POST | `/dashboard/campaigns` | `marketing.view` + `campaigns.manage` | `Discount\CampaignController@store` |
| GET | `/dashboard/campaigns/create` | `marketing.view` + `campaigns.manage` | `Discount\CampaignController@create` |
| DELETE | `/dashboard/campaigns/{campaign}` | `marketing.view` + `campaigns.manage` | `Discount\CampaignController@destroy` |
| PUT/PATCH | `/dashboard/campaigns/{campaign}` | `marketing.view` + `campaigns.manage` | `Discount\CampaignController@update` |
| GET | `/dashboard/campaigns/{campaign}/edit` | `marketing.view` + `campaigns.manage` | `Discount\CampaignController@edit` |
| GET | `/dashboard/categories` | `categories.view` | `Category\CategoryController@index` |
| POST | `/dashboard/categories` | `categories.view` + `categories.manage` | `Category\CategoryController@store` |
| GET | `/dashboard/categories/create` | `categories.view` + `categories.manage` | `Category\CategoryController@create` |
| DELETE | `/dashboard/categories/{category}` | `categories.view` + `categories.manage` | `Category\CategoryController@destroy` |
| PUT | `/dashboard/categories/{category}` | `categories.view` + `categories.manage` | `Category\CategoryController@update` |
| POST | `/dashboard/categories/{category}/storeSubcategory` | `categories.view` + `categories.manage` | `Category\CategoryController@storeSubcategory` |
| GET | `/dashboard/cities` | `delivery.view` | `Delivery\Admin\LocationAdminController@index` |
| POST | `/dashboard/cities` | `delivery.view` + `locations.manage` | `Delivery\Admin\LocationAdminController@storeCity` |
| PUT | `/dashboard/cities/{city}` | `delivery.view` + `locations.manage` | `Delivery\Admin\LocationAdminController@updateCity` |
| POST | `/dashboard/cities/{city}/areas` | `delivery.view` + `locations.manage` | `Delivery\Admin\LocationAdminController@storeArea` |
| GET | `/dashboard/cod` | `payments.view` | `Payments\Admin\CodController@index` |
| POST | `/dashboard/cod/settlements` | `payments.view` + `payments.settle_cod` | `Payments\Admin\CodController@settle` |
| GET | `/dashboard/cod/settlements/{settlement}` | `payments.view` | `Payments\Admin\CodController@show` |
| DELETE | `/dashboard/contact-messages/{id}` | `contact_messages.delete` | `ContactMessage\ContactMassageController@destroy` |
| POST | `/dashboard/contact-messages/{message}/replies` | `contact_messages.reply` (throttle 30,1) | `ContactMessage\ContactMassageController@reply` |
| POST | `/dashboard/contact-messages/{message}/replies/{reply}/resend` | `contact_messages.reply` (throttle 30,1) | `ContactMessage\ContactMassageController@resend` |
| GET | `/dashboard/contacts` | `contact_messages.view` | `ContactMessage\ContactMassageController@index` |
| GET | `/dashboard/coupons` | `marketing.view` | `Coupon\CouponsController@index` |
| POST | `/dashboard/coupons` | `marketing.view` + `coupons.manage` | `Coupon\CouponsController@store` |
| GET | `/dashboard/coupons/create` | `marketing.view` + `coupons.manage` | `Coupon\CouponsController@create` |
| DELETE | `/dashboard/coupons/{coupon}` | `marketing.view` + `coupons.manage` | `Coupon\CouponsController@destroy` |
| GET | `/dashboard/coupons/{coupon}` | `marketing.view` | `Coupon\CouponsController@show` |
| PUT/PATCH | `/dashboard/coupons/{coupon}` | `marketing.view` + `coupons.manage` | `Coupon\CouponsController@update` |
| GET | `/dashboard/coupons/{coupon}/edit` | `marketing.view` + `coupons.manage` | `Coupon\CouponsController@edit` |
| POST | `/dashboard/coupons/{coupon}/toggle` | `marketing.view` + `coupons.manage` | `Coupon\CouponsController@toggle` |
| GET | `/dashboard/customers` | `customers.view` | `Customers\CustomerController@index` |
| GET | `/dashboard/customers/{customer}` | `customers.view` | `Customers\CustomerController@show` |
| PATCH | `/dashboard/customers/{customer}/active` | `customers.activate` | `Customers\CustomerController@toggleActive` |
| PATCH | `/dashboard/dashboard/points/redeem-multiple` | `loyalty.view` + `loyalty.manage` | `Points\PointController@redeemMultiple` |
| POST | `/dashboard/data/exports` | `data_transfer` (throttle 20,1) | `DataTransfer\DataTransferController@export` |
| GET | `/dashboard/data/exports/{exportRun}/download` | `data_transfer` | `DataTransfer\DataTransferController@download` |
| POST | `/dashboard/data/imports` | `data_transfer` (throttle 20,1) | `DataTransfer\DataTransferController@upload` |
| GET | `/dashboard/data/imports/{importRun}` | `data_transfer` | `DataTransfer\DataTransferController@showImport` |
| POST | `/dashboard/data/imports/{importRun}/cancel` | `data_transfer` | `DataTransfer\DataTransferController@cancelImport` |
| POST | `/dashboard/data/imports/{importRun}/confirm` | `data_transfer` | `DataTransfer\DataTransferController@confirm` |
| GET | `/dashboard/data/imports/{importRun}/files/{kind}` | `data_transfer` | `DataTransfer\DataTransferController@importFile` |
| GET | `/dashboard/data/templates/{entity}` | `data_transfer` | `DataTransfer\DataTransferController@template` |
| GET | `/dashboard/data/{entity}/history` | `data_transfer` | `DataTransfer\DataTransferController@history` |
| POST | `/dashboard/delivery/orders/{order}/shipments` | `delivery.view` + `delivery.manage_shipments` | `Delivery\Admin\ShipmentController@createForOrder` |
| GET | `/dashboard/delivery/providers` | `delivery.view` | `Delivery\Admin\DeliveryProviderController@index` |
| POST | `/dashboard/delivery/providers` | `delivery.view` + `delivery.manage_providers` | `Delivery\Admin\DeliveryProviderController@store` |
| PUT | `/dashboard/delivery/providers/{provider}` | `delivery.view` + `delivery.manage_providers` | `Delivery\Admin\DeliveryProviderController@update` |
| POST | `/dashboard/delivery/providers/{provider}/status-maps` | `delivery.view` + `delivery.manage_providers` | `Delivery\Admin\DeliveryProviderController@storeStatusMap` |
| DELETE | `/dashboard/delivery/rates/{rate}` | `delivery.view` + `delivery.manage_zones` | `Delivery\Admin\DeliveryZoneController@destroyRate` |
| PUT | `/dashboard/delivery/rates/{rate}` | `delivery.view` + `delivery.manage_zones` | `Delivery\Admin\DeliveryZoneController@updateRate` |
| GET | `/dashboard/delivery/shipments` | `delivery.view` | `Delivery\Admin\ShipmentController@index` |
| POST | `/dashboard/delivery/shipments/{shipment}/assign` | `delivery.view` + `delivery.manage_shipments` | `Delivery\Admin\ShipmentController@assign` |
| POST | `/dashboard/delivery/shipments/{shipment}/events` | `delivery.view` + `delivery.manage_shipments` | `Delivery\Admin\ShipmentController@addEvent` |
| POST | `/dashboard/delivery/shipments/{shipment}/settle-cod` | `delivery.view` + `delivery.manage_shipments` | `Delivery\Admin\ShipmentController@settleCod` |
| DELETE | `/dashboard/delivery/status-maps/{map}` | `delivery.view` + `delivery.manage_providers` | `Delivery\Admin\DeliveryProviderController@destroyStatusMap` |
| GET | `/dashboard/delivery/zones` | `delivery.view` | `Delivery\Admin\DeliveryZoneController@index` |
| POST | `/dashboard/delivery/zones` | `delivery.view` + `delivery.manage_zones` | `Delivery\Admin\DeliveryZoneController@store` |
| DELETE | `/dashboard/delivery/zones/{zone}` | `delivery.view` + `delivery.manage_zones` | `Delivery\Admin\DeliveryZoneController@destroy` |
| PUT | `/dashboard/delivery/zones/{zone}` | `delivery.view` + `delivery.manage_zones` | `Delivery\Admin\DeliveryZoneController@update` |
| POST | `/dashboard/delivery/zones/{zone}/rates` | `delivery.view` + `delivery.manage_zones` | `Delivery\Admin\DeliveryZoneController@storeRate` |
| GET | `/dashboard/delivery_prices` | `delivery.view` | `Closure` |
| GET | `/dashboard/discounts` | `marketing.view` | `Discount\DiscountController@index` |
| POST | `/dashboard/discounts` | `marketing.view` + `promotions.manage` | `Discount\DiscountController@store` |
| GET | `/dashboard/discounts/create` | `marketing.view` + `promotions.manage` | `Discount\DiscountController@create` |
| DELETE | `/dashboard/discounts/{discount}` | `marketing.view` + `promotions.manage` | `Discount\DiscountController@destroy` |
| GET | `/dashboard/discounts/{discount}` | `marketing.view` | `Discount\DiscountController@show` |
| PUT/PATCH | `/dashboard/discounts/{discount}` | `marketing.view` + `promotions.manage` | `Discount\DiscountController@update` |
| POST | `/dashboard/discounts/{discount}/duplicate` | `marketing.view` + `promotions.manage` | `Discount\DiscountController@duplicate` |
| GET | `/dashboard/discounts/{discount}/edit` | `marketing.view` + `promotions.manage` | `Discount\DiscountController@edit` |
| POST | `/dashboard/discounts/{discount}/simulate` | `marketing.view` + `promotions.manage` | `Discount\DiscountController@simulate` |
| POST | `/dashboard/discounts/{discount}/toggle` | `marketing.view` + `promotions.manage` | `Discount\DiscountController@toggle` |
| GET | `/dashboard/files/payment-receipt/{order}` | `payments.view` | `Admin\PrivateFileController@paymentReceipt` |
| GET | `/dashboard/files/return-image/{image}` | `refunds.view` | `Admin\PrivateFileController@returnImage` |
| GET | `/dashboard/flash_sales` | `marketing.view` | `FlashSale\FlashSaleController@index` |
| POST | `/dashboard/flash_sales` | `marketing.view` + `flash_sales.manage` | `FlashSale\FlashSaleController@store` |
| GET | `/dashboard/flash_sales/create` | `marketing.view` + `flash_sales.manage` | `FlashSale\FlashSaleController@create` |
| DELETE | `/dashboard/flash_sales/{flash_sale}` | `marketing.view` + `flash_sales.manage` | `FlashSale\FlashSaleController@destroy` |
| GET | `/dashboard/flash_sales/{flash_sale}` | `marketing.view` | `FlashSale\FlashSaleController@show` |
| PUT/PATCH | `/dashboard/flash_sales/{flash_sale}` | `marketing.view` + `flash_sales.manage` | `FlashSale\FlashSaleController@update` |
| GET | `/dashboard/flash_sales/{flash_sale}/edit` | `marketing.view` + `flash_sales.manage` | `FlashSale\FlashSaleController@edit` |
| POST | `/dashboard/flash_sales/{flash_sale}/toggle` | `marketing.view` + `flash_sales.manage` | `FlashSale\FlashSaleController@toggle` |
| GET | `/dashboard/health-content/pregnancy` | `health_content.view` | `HealthService\Admin\PregnancyContentController@index` |
| POST | `/dashboard/health-content/pregnancy` | `health_content.view` + `health_content.manage` | `HealthService\Admin\PregnancyContentController@store` |
| DELETE | `/dashboard/health-content/pregnancy/{content}` | `health_content.view` + `health_content.manage` | `HealthService\Admin\PregnancyContentController@destroy` |
| PUT | `/dashboard/health-content/pregnancy/{content}` | `health_content.view` + `health_content.manage` | `HealthService\Admin\PregnancyContentController@update` |
| GET | `/dashboard/health-content/rules` | `health_content.view` | `HealthService\Admin\HealthRuleController@index` |
| POST | `/dashboard/health-content/rules` | `health_content.view` + `health_rules.manage` | `HealthService\Admin\HealthRuleController@store` |
| POST | `/dashboard/health-content/rules/reset/{metric}` | `health_content.view` + `health_rules.manage` | `HealthService\Admin\HealthRuleController@reset` |
| DELETE | `/dashboard/health-content/rules/{rule}` | `health_content.view` + `health_rules.manage` | `HealthService\Admin\HealthRuleController@destroy` |
| PUT | `/dashboard/health-content/rules/{rule}` | `health_content.view` + `health_rules.manage` | `HealthService\Admin\HealthRuleController@update` |
| GET | `/dashboard/health-content/settings` | `health_content.view` | `HealthService\Admin\HealthSettingsController@edit` |
| PUT | `/dashboard/health-content/settings` | `health_content.view` + `health_content.manage` | `HealthService\Admin\HealthSettingsController@update` |
| GET | `/dashboard/health-content/vaccines` | `health_content.view` | `HealthService\Admin\VaccineScheduleController@index` |
| POST | `/dashboard/health-content/vaccines` | `health_content.view` + `health_content.manage` | `HealthService\Admin\VaccineScheduleController@store` |
| DELETE | `/dashboard/health-content/vaccines/{item}` | `health_content.view` + `health_content.manage` | `HealthService\Admin\VaccineScheduleController@destroy` |
| PUT | `/dashboard/health-content/vaccines/{item}` | `health_content.view` + `health_content.manage` | `HealthService\Admin\VaccineScheduleController@update` |
| GET | `/dashboard/health-results/blood-pressure` | `health_data.view` | `HealthService\HealthServiceController@bloodPressure` |
| GET | `/dashboard/health-results/blood-sugar` | `health_data.view` | `HealthService\HealthServiceController@bloodSugar` |
| GET | `/dashboard/health-results/body-weight` | `health_data.view` | `HealthService\HealthServiceController@bodyWeight` |
| GET | `/dashboard/health-results/children` | `health_data.view` | `HealthService\HealthServiceController@children` |
| GET | `/dashboard/health-results/notifications` | `health_data.view` | `HealthService\Admin\NotificationLogController@index` |
| GET | `/dashboard/health-results/ovulation` | `health_data.view` | `HealthService\HealthServiceController@ovulation` |
| GET | `/dashboard/health-results/pregnancy` | `health_data.view` | `HealthService\HealthServiceController@pregnancy` |
| GET | `/dashboard/health_issues` | `health_issues.view` | `HealthIssue\HealthIssueController@index` |
| POST | `/dashboard/health_issues` | `health_issues.manage` | `HealthIssue\HealthIssueController@store` |
| GET | `/dashboard/health_issues/create` | `health_issues.manage` | `HealthIssue\HealthIssueController@create` |
| DELETE | `/dashboard/health_issues/{health_issue}` | `health_issues.manage` | `HealthIssue\HealthIssueController@destroy` |
| GET | `/dashboard/health_issues/{health_issue}` | `health_issues.view` | `HealthIssue\HealthIssueController@show` |
| PUT/PATCH | `/dashboard/health_issues/{health_issue}` | `health_issues.manage` | `HealthIssue\HealthIssueController@update` |
| GET | `/dashboard/health_issues/{health_issue}/edit` | `health_issues.manage` | `HealthIssue\HealthIssueController@edit` |
| GET | `/dashboard/inventory` | `inventory.view` | `Inventory\InventoryController@index` |
| GET | `/dashboard/inventory/movements` | `inventory.view` | `Inventory\InventoryController@movements` |
| GET | `/dashboard/inventory/reports` | `inventory.view` | `Inventory\InventoryController@reports` |
| GET | `/dashboard/inventory/skus/{size}` | `inventory.view` | `Inventory\InventoryController@show` |
| PUT | `/dashboard/inventory/skus/{size}` | `inventory.view` + `inventory.adjust` | `Inventory\InventoryController@updateSku` |
| POST | `/dashboard/inventory/skus/{size}/operations` | `inventory.view` + `inventory.adjust` | `Inventory\InventoryController@operate` |
| GET | `/dashboard/loyalty` | `loyalty.view` | `Points\LoyaltyDashboardController@overview` |
| GET | `/dashboard/loyalty/customers` | `loyalty.view` | `Points\LoyaltyDashboardController@customers` |
| POST | `/dashboard/loyalty/customers/{customer}/adjust` | `loyalty.view` + `loyalty.adjust_points` | `Points\LoyaltyDashboardController@adjust` |
| POST | `/dashboard/loyalty/customers/{customer}/redeem` | `loyalty.view` + `loyalty.manage` | `Points\RedemptionController@store` |
| GET | `/dashboard/loyalty/expiring` | `loyalty.view` | `Points\LoyaltyDashboardController@expiring` |
| GET | `/dashboard/loyalty/redemptions` | `loyalty.view` | `Points\RedemptionController@index` |
| POST | `/dashboard/loyalty/redemptions/{redemption}/approve` | `loyalty.view` + `loyalty.manage` | `Points\RedemptionController@approve` |
| POST | `/dashboard/loyalty/redemptions/{redemption}/cancel` | `loyalty.view` + `loyalty.manage` | `Points\RedemptionController@cancel` |
| POST | `/dashboard/loyalty/redemptions/{redemption}/fulfil` | `loyalty.view` + `loyalty.manage` | `Points\RedemptionController@fulfil` |
| GET | `/dashboard/loyalty/rewards` | `loyalty.view` | `Points\RewardController@index` |
| POST | `/dashboard/loyalty/rewards` | `loyalty.view` + `loyalty.manage_rewards` | `Points\RewardController@store` |
| GET | `/dashboard/loyalty/rewards/create` | `loyalty.view` + `loyalty.manage_rewards` | `Points\RewardController@create` |
| DELETE | `/dashboard/loyalty/rewards/{reward}` | `loyalty.view` + `loyalty.manage_rewards` | `Points\RewardController@destroy` |
| PUT | `/dashboard/loyalty/rewards/{reward}` | `loyalty.view` + `loyalty.manage_rewards` | `Points\RewardController@update` |
| GET | `/dashboard/loyalty/rewards/{reward}/edit` | `loyalty.view` + `loyalty.manage_rewards` | `Points\RewardController@edit` |
| POST | `/dashboard/loyalty/rewards/{reward}/toggle` | `loyalty.view` + `loyalty.manage_rewards` | `Points\RewardController@toggle` |
| GET | `/dashboard/loyalty/settings` | `loyalty.view` + `loyalty.settings` | `Points\LoyaltySettingsController@edit` |
| PUT | `/dashboard/loyalty/settings` | `loyalty.view` + `loyalty.settings` | `Points\LoyaltySettingsController@update` |
| GET | `/dashboard/loyalty/transactions` | `loyalty.view` | `Points\LoyaltyDashboardController@transactions` |
| GET | `/dashboard/marketing` | `marketing.view` | `Discount\MarketingDashboardController@index` |
| GET | `/dashboard/orders` | `orders.view` | `Orders\OrderController@index` |
| GET | `/dashboard/orders/{order}` | `orders.view` | `Orders\OrderController@show` |
| PATCH | `/dashboard/orders/{order}/payment-status` | `payments.verify` | `Orders\OrderController@updatePaymentStatus` |
| PUT | `/dashboard/orders/{order}/update-status` | `orders.view` | `Orders\OrderController@updateStatus` |
| GET | `/dashboard/payment-methods` | `payments.view` | `Payments\PaymentMethodController@index` |
| POST | `/dashboard/payment-methods` | `payments.view` + `payment_methods.manage` | `Payments\PaymentMethodController@store` |
| PUT | `/dashboard/payment-methods/{paymentMethod}` | `payments.view` + `payment_methods.manage` | `Payments\PaymentMethodController@update` |
| GET | `/dashboard/payments` | `payments.view` | `Payments\Admin\PaymentController@index` |
| GET | `/dashboard/payments/events` | `payments.view` | `Payments\Admin\PaymentController@events` |
| GET | `/dashboard/payments/reports` | `payments.view` | `Payments\Admin\PaymentController@reports` |
| GET | `/dashboard/payments/{payment}` | `payments.view` | `Payments\Admin\PaymentController@show` |
| POST | `/dashboard/payments/{payment}/collect` | `payments.view` + `payments.collect` | `Payments\Admin\PaymentController@collect` |
| POST | `/dashboard/payments/{payment}/refresh` | `payments.view` + `payments.collect` | `Payments\Admin\PaymentController@refresh` |
| POST | `/dashboard/payments/{payment}/refunds` | `refunds.view` + `refunds.create` | `Payments\Admin\RefundController@store` |
| GET | `/dashboard/points` | `loyalty.view` | `Points\PointController@index` |
| PATCH | `/dashboard/points/{order}/redeem` | `loyalty.view` + `loyalty.manage` | `Points\PointController@redeemPoints` |
| GET | `/dashboard/products` | `products.view` | `Product\ProductController@index` |
| POST | `/dashboard/products` | `products.create` | `Product\ProductController@store` |
| GET | `/dashboard/products/create` | `products.create` | `Product\ProductController@create` |
| DELETE | `/dashboard/products/{product}` | `products.delete` | `Product\ProductController@destroy` |
| GET | `/dashboard/products/{product}` | `products.view` | `Product\ProductController@show` |
| PUT/PATCH | `/dashboard/products/{product}` | `products.update` | `Product\ProductController@update` |
| GET | `/dashboard/products/{product}/edit` | `products.update` | `Product\ProductController@edit` |
| GET | `/dashboard/purchase-orders` | `purchasing.view` | `Inventory\PurchaseOrderController@index` |
| POST | `/dashboard/purchase-orders` | `purchasing.view` + `purchase_orders.manage` | `Inventory\PurchaseOrderController@store` |
| GET | `/dashboard/purchase-orders/{purchaseOrder}` | `purchasing.view` | `Inventory\PurchaseOrderController@show` |
| POST | `/dashboard/purchase-orders/{purchaseOrder}/cancel` | `purchasing.view` + `purchase_orders.manage` | `Inventory\PurchaseOrderController@cancel` |
| POST | `/dashboard/purchase-orders/{purchaseOrder}/close` | `purchasing.view` + `purchase_orders.manage` | `Inventory\PurchaseOrderController@close` |
| POST | `/dashboard/purchase-orders/{purchaseOrder}/order` | `purchasing.view` + `purchase_orders.manage` | `Inventory\PurchaseOrderController@markOrdered` |
| POST | `/dashboard/purchase-orders/{purchaseOrder}/receive` | `purchasing.view` + `purchase_orders.receive` | `Inventory\PurchaseOrderController@receive` |
| GET | `/dashboard/refunds` | `refunds.view` | `Payments\Admin\RefundController@index` |
| GET | `/dashboard/refunds/{refund}` | `refunds.view` | `Payments\Admin\RefundController@show` |
| POST | `/dashboard/refunds/{refund}/cancel` | `refunds.view` + `refunds.process` | `Payments\Admin\RefundController@cancel` |
| POST | `/dashboard/refunds/{refund}/process` | `refunds.view` + `refunds.process` | `Payments\Admin\RefundController@process` |
| POST | `/dashboard/refunds/{refund}/reconcile` | `refunds.view` + `refunds.process` | `Payments\Admin\RefundController@reconcile` |
| POST | `/dashboard/refunds/{refund}/resolve` | `refunds.view` + `refunds.process` | `Payments\Admin\RefundController@resolve` |
| GET | `/dashboard/reports` | `reports.view` | `Reports\ReportController@index` |
| GET | `/dashboard/returns` | `refunds.view` | `Orders\ReturnRequestController@adminindex` |
| GET | `/dashboard/returns/{id}` | `refunds.view` | `Orders\ReturnRequestController@adminshow` |
| POST | `/dashboard/returns/{id}/accept` | `refunds.view` + `refunds.approve` | `Orders\ReturnRequestController@accept` |
| POST | `/dashboard/returns/{id}/reject` | `refunds.view` + `refunds.approve` | `Orders\ReturnRequestController@reject` |
| GET | `/dashboard/roles` | `roles.view` | `Admin\RoleController@index` |
| POST | `/dashboard/roles` | `roles.view` + `roles.manage` | `Admin\RoleController@store` |
| GET | `/dashboard/roles/create` | `roles.view` + `roles.manage` | `Admin\RoleController@create` |
| DELETE | `/dashboard/roles/{role}` | `roles.view` + `roles.manage` | `Admin\RoleController@destroy` |
| GET | `/dashboard/roles/{role}` | `roles.view` | `Admin\RoleController@show` |
| PUT | `/dashboard/roles/{role}` | `roles.view` + `roles.manage` | `Admin\RoleController@update` |
| POST | `/dashboard/roles/{role}/duplicate` | `roles.view` + `roles.manage` | `Admin\RoleController@duplicate` |
| GET | `/dashboard/roles/{role}/edit` | `roles.view` + `roles.manage` | `Admin\RoleController@edit` |
| PATCH | `/dashboard/roles/{role}/permissions/{permission}` | `roles.view` + `roles.manage` | `Admin\RoleController@setPermission` |
| POST | `/dashboard/roles/{role}/toggle` | `roles.view` + `roles.manage` | `Admin\RoleController@toggle` |
| GET | `/dashboard/settings` | `settings.view` | `Setting\SettingController@index` |
| POST | `/dashboard/settings` | `settings.update` | `Setting\SettingController@store` |
| PUT | `/dashboard/settings/{setting}` | `settings.update` | `Setting\SettingController@update` |
| GET | `/dashboard/stock-counts` | `inventory.view` | `Inventory\StockCountController@index` |
| POST | `/dashboard/stock-counts` | `inventory.view` + `inventory.count` | `Inventory\StockCountController@store` |
| GET | `/dashboard/stock-counts/{count}` | `inventory.view` | `Inventory\StockCountController@show` |
| POST | `/dashboard/stock-counts/{count}/cancel` | `inventory.view` + `inventory.count` | `Inventory\StockCountController@cancel` |
| POST | `/dashboard/stock-counts/{count}/complete` | `inventory.view` + `inventory.count` | `Inventory\StockCountController@complete` |
| POST | `/dashboard/stock-counts/{count}/record` | `inventory.view` + `inventory.count` | `Inventory\StockCountController@record` |
| POST | `/dashboard/supplier-returns/{return}/cancel` | `purchasing.view` + `supplier_finance.manage` | `Inventory\SupplierController@cancelReturn` |
| POST | `/dashboard/supplier-returns/{return}/dispatch` | `purchasing.view` + `supplier_finance.manage` | `Inventory\SupplierController@dispatchReturn` |
| GET | `/dashboard/suppliers` | `purchasing.view` | `Inventory\SupplierController@index` |
| POST | `/dashboard/suppliers` | `purchasing.view` + `suppliers.manage` | `Inventory\SupplierController@store` |
| GET | `/dashboard/suppliers/{supplier}` | `purchasing.view` | `Inventory\SupplierController@show` |
| PUT | `/dashboard/suppliers/{supplier}` | `purchasing.view` + `suppliers.manage` | `Inventory\SupplierController@update` |
| POST | `/dashboard/suppliers/{supplier}/invoices` | `purchasing.view` + `supplier_finance.manage` | `Inventory\SupplierController@storeInvoice` |
| POST | `/dashboard/suppliers/{supplier}/payments` | `purchasing.view` + `supplier_finance.manage` | `Inventory\SupplierController@storePayment` |
| POST | `/dashboard/suppliers/{supplier}/returns` | `purchasing.view` + `supplier_finance.manage` | `Inventory\SupplierController@storeReturn` |
| GET | `/dashboard/transfers` | `inventory.view` | `Inventory\TransferController@index` |
| POST | `/dashboard/transfers` | `inventory.view` + `inventory.transfer` | `Inventory\TransferController@store` |
| GET | `/dashboard/transfers/{transfer}` | `inventory.view` | `Inventory\TransferController@show` |
| POST | `/dashboard/transfers/{transfer}/approve` | `inventory.view` + `inventory.transfer` | `Inventory\TransferController@approve` |
| POST | `/dashboard/transfers/{transfer}/cancel` | `inventory.view` + `inventory.transfer` | `Inventory\TransferController@cancel` |
| POST | `/dashboard/transfers/{transfer}/close` | `inventory.view` + `inventory.transfer` | `Inventory\TransferController@close` |
| POST | `/dashboard/transfers/{transfer}/dispatch` | `inventory.view` + `inventory.transfer` | `Inventory\TransferController@dispatchTransfer` |
| POST | `/dashboard/transfers/{transfer}/receive` | `inventory.view` + `inventory.transfer` | `Inventory\TransferController@receive` |
| PUT | `/dashboard/warehouse-locations/{location}` | `inventory.view` + `warehouses.manage` | `Inventory\WarehouseController@updateLocation` |
| GET | `/dashboard/warehouses` | `inventory.view` | `Inventory\WarehouseController@index` |
| POST | `/dashboard/warehouses` | `inventory.view` + `warehouses.manage` | `Inventory\WarehouseController@store` |
| PUT | `/dashboard/warehouses/{warehouse}` | `inventory.view` + `warehouses.manage` | `Inventory\WarehouseController@update` |
| POST | `/dashboard/warehouses/{warehouse}/locations` | `inventory.view` + `warehouses.manage` | `Inventory\WarehouseController@storeLocation` |

## 4. Checks made in controllers and services (beyond the route)

| Where | Rule |
|---|---|
| `OrderController::updateStatus` | Route needs `orders.view`; the controller then requires `orders.cancel` when the target status is `canceled`, otherwise `orders.update_status`. |
| `AdminAccountService` | Changing an admin's role needs `roles.assign`; the role cannot carry permissions the actor lacks (unless super admin); only a super admin can create, edit or assign super admins; nobody can change their own role or deactivate their own account; the last active super admin cannot be demoted or deactivated (checked under row locks). |
| `RoleService` | The super admin role cannot be edited (except name/description by a super admin), deactivated or deleted; system roles cannot be deleted; a role with admins cannot be deleted; non-super admins may only edit roles whose permissions they all hold and grant only permissions they hold; nobody edits their own role. |
| `EntityRegistry::authorize` (import/export) | Every template, upload, preview, confirm, cancel, file download and export re-checks the entity's own `<entity>.import` / `<entity>.export` permission. A data's history page opens only for admins who may import or export it. |
| `RefundService::process` / `resolveUnknown` | With `PHARMACY_REFUND_DUAL_CONTROL=true` (default), the admin who requested a refund cannot pay it out or resolve it; another admin with `refunds.process` must. System-queued refunds can be processed by any admin with the permission. |
| `CustomerController::show` | Loyalty data on the customer page is shown only with `loyalty.view`. |
| Health records (`dashboard/health-results/*`) | `health_data.view`; **every page view is written to the audit log** (`health.view`, with the page and search term). |
| Online payments | No permission allows marking a Paymob payment paid by hand; `payments.verify` only verifies legacy manual-transfer receipts. |

## 5. Customer API authorization summary

| Area | Rule |
|---|---|
| Orders, tracking, payment status, pay | Only the caller's own orders (`orders.user_id`); orders with an accepted return are hidden from the lists, details and tracking (`Order::visibleToCustomer`), but stay payable/visible through `GET /api/user/orders/{id}/payment`. |
| Customer cancellation | Only when `settings.customer_can_cancel_pending` is on and the order is still `pending` (re-checked under the order lock). |
| Addresses | Own addresses only; checkout refuses another customer's `address_id` (404). |
| Returns | Own orders only; own return requests only. |
| Profile update | `PUT /api/user/update/{userId}`: `userId` must be the caller (403 otherwise). |
| Files | `/api/user/files/*` serve only the caller's receipt, return image or medication image; dashboard file routes need `payments.view` / `refunds.view`. |
| Loyalty, health, medication, devices, notifications, contact messages | Scoped to the caller. |
| Rate limits | `throttle:api` (`API_RATE_LIMIT`, default 60/min per user or IP) on every API route; `throttle:auth` (5/min per email+IP, 20/min per IP) on register, verify, login and password endpoints; pay 20/min; reward redeem 10/min; contact reply 10/min; delivery webhooks 120/min; payment webhooks 240/min; payment return 60/min; signed files 120/min. |

## 6. Admin capabilities that do **not** exist

So nobody looks for them: there is no dashboard screen to create customers, edit customer profiles,
reset a customer password, read customer push notifications, or delete orders. Products, categories,
brands and health issues can be created/edited/deleted, but those changes are **not** written to the
audit log (see [known-gaps.md](known-gaps.md)).
