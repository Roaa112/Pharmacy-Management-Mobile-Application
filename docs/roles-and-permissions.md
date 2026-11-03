# Roles and permissions

Code: `app/Modules/Admin` (`Authorization/PermissionRegistry`, `Authorization/RbacSynchronizer`,
`Services/RoleService`, `Services/AdminAccountService`, `RoleController`, `AdminUserController`),
models `Role`, `Permission`, `Admin`. Dashboard: **Operations › Roles & permissions** and
**Operations › Admins & roles**.

## Model

* `permissions` (slug, name, module, description, active) — one row per capability the code checks.
  The list lives in `PermissionRegistry` (a permission only means something where code checks it) and
  is copied to the table by `RbacSynchronizer` (migration, `php artisan permissions:sync` after a deploy).
  A permission seen for the first time is given to the system roles whose defaults include it; after
  that the admins decide and a sync never re-adds what they removed. Permissions dropped from the code
  are deactivated, not deleted.
* `roles` (slug, name, description, system, super admin, active) and `permission_role`.
* An admin has **one** role, `admins.role` = `roles.slug` (foreign key; role slugs never change). This is
  the existing architecture (one role per admin) and was kept: no authentication code changed.
* Every request reads the admin's role and its active permissions from the database, so a change to a
  role applies on the admin's next request. An inactive account or an inactive role has no permissions.

## Checks

* Every permission slug is a Gate ability (`can:orders.cancel`, `@can('orders.cancel')`,
  `Gate::authorize`). The module names used before (`orders`, `payments`, …) remain abilities and mean
  "may open the module" (its `.view` permission), so existing `@can('payments')` checks keep working.
* Routes (`routes/web.php`): reading a section needs its `.view` permission; every change needs its own
  permission (resource routes are split per action). Some checks depend on the data and are made in
  the controller or service: changing an order to *canceled* needs `orders.cancel`; changing a role
  needs `roles.assign`; each import/export entity checks its own permission.
* Hiding menu items and buttons is a convenience only; the server refuses every action without its
  permission (tests: `RolesAndPermissionsTest::test_sensitive_actions_are_refused_server_side…`).

## Protections (server side)

* The **super admin** role always has every permission; it cannot be edited (except its name and
  description, by a super admin), deactivated or deleted.
* There is always at least one active admin with an active super admin role: the last one cannot be
  demoted or deactivated (checked under row locks).
* Only a super admin can create, edit, or assign super admins.
* An admin who is not a super admin can only edit roles whose permissions they all hold, can only
  grant permissions they hold, can only assign roles within their own permissions, and can never
  change their own role or deactivate their own account. Nobody can grant themselves anything.
* System roles cannot be deleted; no role can be deleted while admins have it. A duplicated role starts
  inactive.
* Every change is audited: `role.create/update/permissions/activate/deactivate/delete`,
  `admin.create/update/role_change/activate/deactivate` with before/after, admin, IP and user agent.

## Migration and existing access

`2026_11_11_000001_create_roles_and_permissions` creates the seven original roles as system roles.
Each gets every fine-grained permission of the modules it had (`PermissionRegistry::defaultsFor`), so
nobody gained or lost access to anything that existed. Import/export are new capabilities and are
granted on purpose only: Manager (catalog, inventory, suppliers, locations, rewards, orders export),
Warehouse manager (inventory import/export, products and suppliers export, suppliers import),
Content editor (products export, categories and brands import/export), Accountant (orders, payments,
refunds, point transactions and suppliers export). Customers export is super admin only until granted.

## Permissions by module

| Module | Permissions |
|---|---|
| Orders | orders.view, orders.update_status, orders.cancel, orders.export |
| Customers | customers.view, customers.activate, customers.export, contact_messages.view, contact_messages.delete |
| Returns & refunds | refunds.view, refunds.approve, refunds.create, refunds.process, refunds.export |
| Payments | payments.view, payments.verify, payments.collect, payments.settle_cod, payment_methods.manage, payments.export |
| Delivery | delivery.view, delivery.manage_zones, delivery.manage_providers, delivery.manage_shipments, locations.manage, locations.import, locations.export |
| Products & catalog | products.view/create/update/delete/import/export, categories.view/manage/import/export, brands.view/manage/import/export, health_issues.view/manage |
| Inventory | inventory.view, inventory.adjust, inventory.transfer, inventory.count, warehouses.manage, inventory.import, inventory.export |
| Purchasing | purchasing.view, suppliers.manage, supplier_finance.manage, purchase_orders.manage, purchase_orders.receive, suppliers.import, suppliers.export |
| Marketing | marketing.view, promotions.manage, campaigns.manage, coupons.manage, flash_sales.manage, banners.manage |
| Loyalty | loyalty.view, loyalty.manage, loyalty.adjust_points, loyalty.manage_rewards, loyalty.settings, loyalty_rewards.import, loyalty_rewards.export, loyalty_transactions.export |
| Reports | reports.view |
| Health | health_data.view, health_content.view, health_content.manage |
| Settings | settings.view, settings.update |
| Admins & roles | admins.view, admins.create, admins.update, roles.view, roles.manage, roles.assign, audit_log.view |

## Screens

* **Roles & permissions**: roles with admin count, permission count, status; edit, duplicate,
  activate/deactivate, delete; the permission matrix (ticking a box saves it at once; filter box).
* **Role form**: name, description, status, permissions grouped by module with search, select/clear
  all and per group; permissions the editor does not hold are locked.
* **Role page**: its admins and permissions. **Admin page**: role and effective permissions.
