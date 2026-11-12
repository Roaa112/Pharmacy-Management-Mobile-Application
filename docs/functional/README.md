# Functional documentation

What the system does, module by module: purpose, customer and admin workflows, data, rules, edge
cases, background processing, security and dependencies. Every statement is based on the current
code; incomplete or unverified features are marked and collected in [known-gaps.md](../known-gaps.md).

## Who uses the system

| Actor | Interface | Typical work |
|---|---|---|
| Customer | Mobile app → JSON API | Browse, cart, checkout (COD or Paymob), track orders, returns, loyalty, health tools, reminders, contact the pharmacy |
| Pharmacy staff | Dashboard (`/dashboard`) | Orders, payments, refunds, delivery, catalog, stock, purchasing, marketing, loyalty, reports, health content, settings |
| Super admin | Dashboard | Everything above plus admins, roles, health records |
| Paymob | Webhook + browser return | Payment and refund results |
| Delivery company | Signed webhook | Shipment status updates |
| Scheduler / queue | `schedule:run`, `queue:work` | Expiry, reconciliation, reminders, imports/exports |

## Modules

| Module | Document | Summary |
|---|---|---|
| Authentication, customers, addresses | [authentication-and-accounts.md](authentication-and-accounts.md) | Registration with e-mail OTP, token login, password flows, profile, addresses; admin login; customer list, profile and activation |
| Admins, roles & permissions | [../roles-and-permissions.md](../roles-and-permissions.md) · [../permissions-matrix.md](../permissions-matrix.md) | Database roles (7 system roles + custom), 86 permissions, escalation protections, audit |
| Catalog | [catalog.md](catalog.md) | Products and sizes (SKUs), categories, brands, health issues, reviews, favorites, search, best sellers |
| Cart & checkout | [cart-and-checkout.md](cart-and-checkout.md) | Engine-priced cart with automatic gifts; one-transaction checkout with locks and snapshots |
| Orders | [orders.md](orders.md) | Status workflow (pending → confirmed → picking → packed → on_delivery → completed / canceled) and its side effects |
| Payments & Paymob | [payments-and-paymob.md](payments-and-paymob.md) | Payment methods, attempts, Paymob online payments, COD collection and settlement |
| Returns & refunds | [returns-and-refunds.md](returns-and-refunds.md) | Customer return requests, admin decision, quarantine, point reversal, refunds with dual control and reconciliation |
| Delivery | [delivery.md](delivery.md) | Cities/areas, zones and rates, providers and status maps, shipments and webhooks |
| Inventory | [inventory.md](inventory.md) | Warehouses, buckets, batches (FEFO), ledger, adjustments, transfers, counts, expiry, integrity |
| Suppliers & purchasing | [suppliers-and-purchasing.md](suppliers-and-purchasing.md) | Suppliers, purchase orders, receipts with lots, invoices, payments, returns |
| Marketing & promotions | [../marketing-promotions.md](../marketing-promotions.md) | Promotion engine, discounts, Buy X Get Y, Spend X Get Y, free delivery, flash sales, coupons, campaigns, banners, opening ads |
| Loyalty & rewards | [../loyalty.md](../loyalty.md) | Points ledger with lots, earning and activation rules, FIFO redemption, checkout redemption, rewards catalog and redemptions, adjustments |
| Import & export | [../import-export.md](../import-export.md) | Per-page import/export for 14 entities, templates, validation preview, per-record transactions, queued runs, history |
| Health services | [health-services.md](health-services.md) | BMI, blood sugar, blood pressure, ovulation, pregnancy, children's vaccines, medication reminders; dashboard-controlled result rules & messages and health settings |
| Notifications & contact | [notifications-and-contact.md](notifications-and-contact.md) | Push devices and history, e-mails, customer ↔ pharmacy conversations |
| Reports & dashboard | [reports-and-dashboard.md](reports-and-dashboard.md) | Revenue rule, home KPIs, reports page, other report screens |
| Audit log | [audit-log.md](audit-log.md) | Immutable record of sensitive admin actions |
| Settings | [settings.md](settings.md) | Store settings and policies |

Cross-module behaviour: [business-rules.md](../business-rules.md) (stock, order lifecycle, money,
returns, loyalty, supplier payments, open decisions) and
[module-dependencies.md](../module-dependencies.md).

## Summaries of modules documented elsewhere

### Loyalty & rewards (see [loyalty.md](../loyalty.md))

* **Purpose:** reward customers with points they can spend at checkout or on rewards.
* **Customer:** balance, history, expiring points, rewards catalog, redeem (idempotent), redemptions;
  pay part of an order with `redeem_points`.
* **Admin:** overview, customers' balances, transactions, expiring, rewards CRUD, redemptions
  (approve → fulfil, cancel), manual adjustments with reason, settings, per-order points page.
* **Rules:** ledger is the source of truth (no balance column); lots with pending → active →
  depleted/expired/void; FIFO by expiry; never negative (shortfall recorded as owed); every movement
  keyed by its event.
* **Background:** `loyalty:record-expirations` daily 02:00.
* **Reward types** are `product`, `free_delivery`, `voucher`, `custom`. Only `product` rewards have
  an automatic effect (stock leaves the default warehouse when fulfilled); the other types are handed
  over by staff — no coupon, voucher code or free-delivery credit is generated or applied at checkout.

### Marketing & promotions (see [marketing-promotions.md](../marketing-promotions.md))

* **Purpose:** one promotion engine for every price in the system.
* **Admin:** promotions (percent, fixed, Buy X Get Y, Spend X Get Y with tiers, free delivery),
  campaigns, coupons, flash sales, banners, opening ads; simulate, duplicate, toggle, archive;
  configuration checks block activation of broken promotions; marketing overview.
* **Customer:** prices everywhere, automatic gift lines, coupon preview, live offers list, banners and
  opening ads.
* **Rules:** precedence (flash sale → priority → specificity → age), stacking only when both stackable,
  coupon vs automatic discount (larger wins), usage reserved at checkout, consumed on completion,
  released on cancel, refund policy on returns.

### Import & export (see [import-export.md](../import-export.md))

* **Entities:** products (+ sizes), categories, brands, inventory (adjustments), suppliers, cities,
  areas, loyalty rewards (import + export); customers, orders, order lines, payments, refunds, point
  transactions (export only).
* **Flow:** template → upload (create / update / create-or-update) → validation preview with error
  report → confirm (each record in its own transaction) → results; large files on the queue.
* **Security:** per-entity permission re-checked on every action, sensitive exports flagged, formula
  injection protection, SSRF-safe image fetching, never deletes.

### Admins, roles & permissions (see [roles-and-permissions.md](../roles-and-permissions.md))

* **Admin screens:** Admins (list, create, edit, activate/deactivate, change role), Roles & permissions
  (list, create, edit, duplicate, activate/deactivate, delete, permission matrix with instant toggles),
  role and admin detail pages, Audit log.
* **Rules:** one role per admin; super admin always has everything and cannot be removed; at least one
  active super admin; no self-escalation; system roles cannot be deleted.
