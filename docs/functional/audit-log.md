# Audit log

Code: `App\Models\AdminAuditLog`, `app/Modules/Admin/AuditLogController`. Dashboard: **Operations ›
Admins & roles › Audit log** (`/dashboard/audit-log`, permission `audit_log.view`).

## 1. Purpose

A permanent record of sensitive admin actions: who did what, to which record, with which values,
from where.

## 2. Record

`admin_audit_logs`: `admin_id` (null for system actions), `action`, `subject_type` (model class
basename) + `subject_id`, `changes` (JSON: before/after or the relevant values), `ip`, `user_agent`
(≤ 255 chars), `created_at`. Written with `AdminAuditLog::record($action, $subject, $changes)`;
queued jobs pass the admin who started the work. Rows **cannot be updated or deleted** (the model
throws).

## 3. Screen

Newest first, 50 per page, filters: action prefix (e.g. `refund.` or `loyalty.`) and admin. The
promotion page also shows the last 30 entries of that promotion.

## 4. Actions recorded (from the code)

| Area | Actions |
|---|---|
| Admins & roles | `admin.create`, `admin.update`, `admin.role_change`, `admin.activate`, `admin.deactivate`, `role.create`, `role.update`, `role.permissions`, `role.activate`, `role.deactivate`, `role.delete` |
| Customers | `customer.activate`, `customer.deactivate`, `contact_message.replied`, `contact_message.reply_resent` |
| Orders | `order.status`, `order.payment_status` |
| Payments | `payment.cod_collected`, `payment.refresh`, `cod.settle`, `payment_method.create`, `payment_method.update`, `file.payment_receipt` (receipt opened) |
| Returns & refunds | `return.accept`, `return.reject`, `refund.request`, `refund.process`, `refund.reconcile`, `refund.resolve`, `refund.cancel` |
| Delivery | `delivery.zone.create/update/delete`, `delivery.rate.create/update/delete`, `delivery.provider.create/update`, `delivery.status_map.save/delete`, `city.create/update`, `area.create/update`, `shipment.create`, `shipment.assign`, `shipment.event`, `shipment.cod_settled` |
| Inventory | `inventory.adjust`, `inventory.damage`, `inventory.write_off`, `inventory.inspect`, `inventory.sku`, `warehouse.create/update`, `transfer.request/approve/dispatch/receive/close/cancel`, `count.start`, `count.complete` |
| Purchasing | `supplier.create/update`, `supplier.invoice`, `supplier.payment`, `supplier.return`, `supplier.return_dispatch`, `purchase_order.create/ordered/receive/close/cancel` |
| Marketing | `promotion.created/updated/activated/deactivated/duplicated/archived`, `campaign.created/updated/archived`, `coupon.created/updated/activated/deactivated/archived`, `flash_sale.created/updated/activated/deactivated/deleted`, `banner.created/updated/deleted`, `opening_ad.created/updated/deleted` |
| Loyalty | `loyalty.adjust`, `loyalty.settings`, `loyalty.reward_create/update/activate/deactivate/delete`, `loyalty.reward_redeemed`, `loyalty.redemption_approved/fulfilled/cancelled/expired`, `points.redeem`, `points.redeem_multiple` |
| Health | `health.view` (every health-data page view), `health_content.vaccine.create/update/delete`, `health_content.pregnancy.create/update/delete`, `health_rules.create/update/delete/reset`, `health_settings.update` |
| Settings | `settings.update` (before/after) |
| Import & export | `data.import_upload`, `data.import_confirm`, `data.import`, `data.import_cancel`, `data.import_failed`, `data.import_download`, `data.export` (flagged `sensitive` for sensitive entities), `data.export_download` |

## 5. Not audited

Product, category, brand and health-issue create/edit/delete; stock-count line recording and
count cancellation; admin sign-in/sign-out; customer-side actions (customers are not admins; their
actions are visible in the domain history tables such as `order_status_histories`).

## 6. Related histories (not the audit log)

`order_status_histories`, `payment_status_histories`, `payment_events`, `shipment_events`,
`stock_movements`, `loyalty_ledger` record the full history of their domain, including system and
customer actors.
