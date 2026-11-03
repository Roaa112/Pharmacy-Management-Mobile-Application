<?php

namespace App\Modules\Admin\Authorization;

use App\Enums\AdminRole;

/**
 * Every capability the code checks. Permissions are rows in `permissions` (synced from here by
 * RbacSynchronizer); which role holds which permission is data, edited from the dashboard.
 *
 * A permission only means something where the code checks it, so the list lives with the code.
 * Module keys are the historical dashboard permissions (App\Enums\Permission): `can:orders` and
 * `@can('orders')` keep working and mean "may open the module" (its view permission).
 */
final class PermissionRegistry
{
    /** module => [label, the permission that opens the module] */
    public const MODULES = [
        'orders' => ['Orders', 'orders.view'],
        'users' => ['Customers', 'customers.view'],
        'refunds' => ['Returns & refunds', 'refunds.view'],
        'payments' => ['Payments', 'payments.view'],
        'delivery' => ['Delivery', 'delivery.view'],
        'catalog' => ['Products & catalog', 'products.view'],
        'inventory' => ['Inventory', 'inventory.view'],
        'purchasing' => ['Purchasing', 'purchasing.view'],
        'marketing' => ['Marketing', 'marketing.view'],
        'loyalty' => ['Loyalty', 'loyalty.view'],
        'reports' => ['Reports', 'reports.view'],
        'health_data' => ['Health data', 'health_data.view'],
        'health_content' => ['Health content', 'health_content.view'],
        'settings' => ['Settings', 'settings.view'],
        'admins' => ['Admins & roles', 'admins.view'],
    ];

    /**
     * Import / export permissions are new capabilities: system roles do not get them from their
     * old module permission, only from IMPORT_EXPORT_DEFAULTS (super admin has everything).
     */
    private const DATA_TRANSFER = ['import', 'export'];

    /** slug => [module, name, description] */
    public static function all(): array
    {
        return [
            // Orders
            'orders.view' => ['orders', 'View orders', 'Order list and order details.'],
            'orders.update_status' => ['orders', 'Change order status', 'Confirm, prepare, ship and complete orders.'],
            'orders.cancel' => ['orders', 'Cancel orders', 'Cancel an order (stock, payment and points are reversed).'],
            'orders.export' => ['orders', 'Export orders', 'Download orders and order lines.'],

            // Customers
            'customers.view' => ['users', 'View customers', 'Customer list and profiles (never health records).'],
            'customers.activate' => ['users', 'Activate / deactivate customers', 'Sign a customer out and block or restore access.'],
            'customers.export' => ['users', 'Export customers', 'Download customer contact data (personal data).'],
            'contact_messages.view' => ['users', 'View contact messages', 'Messages sent from the app.'],
            'contact_messages.reply' => ['users', 'Reply to contact messages', 'Answer a customer (sent to the app as a notification).'],
            'contact_messages.delete' =>['users', 'Delete contact messages', 'Remove a contact message.'],

            // Returns & refunds
            'refunds.view' => ['refunds', 'View returns & refunds', 'Return requests, refunds and their history.'],
            'refunds.approve' => ['refunds', 'Decide return requests', 'Accept or reject a customer return.'],
            'refunds.create' => ['refunds', 'Create refunds', 'Start a refund on a payment.'],
            'refunds.process' => ['refunds', 'Process refunds', 'Pay out, reconcile, resolve or cancel refunds.'],
            'refunds.export' => ['refunds', 'Export refunds', 'Download refunds.'],

            // Payments
            'payments.view' => ['payments', 'View payments', 'Payments, payment events, receipts and reports.'],
            'payments.verify' => ['payments', 'Verify payments', 'Change the payment status of an order (manual transfers).'],
            'payments.collect' => ['payments', 'Collect / refresh payments', 'Record collections and query the provider.'],
            'payments.settle_cod' => ['payments', 'Settle cash on delivery', 'Settle courier cash.'],
            'payment_methods.manage' => ['payments', 'Manage payment methods', 'Create and edit payment methods.'],
            'payments.export' => ['payments', 'Export payments', 'Download payments (financial data).'],

            // Delivery
            'delivery.view' => ['delivery', 'View delivery', 'Shipments, zones, providers, cities and areas.'],
            'delivery.manage_zones' => ['delivery', 'Manage delivery zones', 'Zones and delivery rates.'],
            'delivery.manage_providers' => ['delivery', 'Manage delivery providers', 'Providers and status maps.'],
            'delivery.manage_shipments' => ['delivery', 'Manage shipments', 'Create, assign and update shipments, settle shipment cash.'],
            'locations.manage' => ['delivery', 'Manage cities & areas', 'Create and edit cities and areas.'],
            'locations.import' => ['delivery', 'Import cities & areas', 'Create or update cities and areas from a file.'],
            'locations.export' => ['delivery', 'Export cities & areas', 'Download cities and areas.'],

            // Catalog
            'products.view' => ['catalog', 'View products', 'Product list and details.'],
            'products.create' => ['catalog', 'Create products', 'Add products.'],
            'products.update' => ['catalog', 'Edit products', 'Edit products, sizes and images.'],
            'products.delete' => ['catalog', 'Delete products', 'Remove products from the shop (kept in history).'],
            'products.import' => ['catalog', 'Import products', 'Create or update products and sizes from a file.'],
            'products.export' => ['catalog', 'Export products', 'Download products and sizes.'],
            'categories.view' => ['catalog', 'View categories', 'Category list.'],
            'categories.manage' => ['catalog', 'Manage categories', 'Create, edit and delete categories.'],
            'categories.import' => ['catalog', 'Import categories', 'Create or update categories from a file.'],
            'categories.export' => ['catalog', 'Export categories', 'Download categories.'],
            'brands.view' => ['catalog', 'View brands', 'Brand list.'],
            'brands.manage' => ['catalog', 'Manage brands', 'Create, edit and delete brands.'],
            'brands.import' => ['catalog', 'Import brands', 'Create or update brands from a file.'],
            'brands.export' => ['catalog', 'Export brands', 'Download brands.'],
            'health_issues.view' => ['catalog', 'View health issues', 'Health issue list.'],
            'health_issues.manage' => ['catalog', 'Manage health issues', 'Create, edit and delete health issues.'],

            // Inventory
            'inventory.view' => ['inventory', 'View stock', 'Stock overview, movements and inventory reports.'],
            'inventory.adjust' => ['inventory', 'Adjust stock', 'Adjustments, damage, inspections and SKU settings.'],
            'inventory.transfer' => ['inventory', 'Transfer stock', 'Create and move transfers between warehouses.'],
            'inventory.count' => ['inventory', 'Count stock', 'Stock counts and their corrections.'],
            'warehouses.manage' => ['inventory', 'Manage warehouses', 'Warehouses and locations.'],
            'inventory.import' => ['inventory', 'Import stock', 'Stock adjustments from a file (recorded as movements).'],
            'inventory.export' => ['inventory', 'Export stock', 'Download stock per warehouse.'],

            // Purchasing
            'purchasing.view' => ['purchasing', 'View purchasing', 'Suppliers and purchase orders.'],
            'suppliers.manage' => ['purchasing', 'Manage suppliers', 'Create and edit suppliers.'],
            'supplier_finance.manage' => ['purchasing', 'Supplier invoices, payments & returns', 'Record supplier invoices, payments and returns.'],
            'purchase_orders.manage' => ['purchasing', 'Manage purchase orders', 'Create, order, close and cancel purchase orders.'],
            'purchase_orders.receive' => ['purchasing', 'Receive purchase orders', 'Receive goods into stock.'],
            'suppliers.import' => ['purchasing', 'Import suppliers', 'Create or update suppliers from a file.'],
            'suppliers.export' => ['purchasing', 'Export suppliers', 'Download suppliers.'],

            // Marketing
            'marketing.view' => ['marketing', 'View marketing', 'Marketing overview, promotions, coupons, banners and ads.'],
            'promotions.manage' => ['marketing', 'Manage promotions', 'Create, edit, toggle and simulate promotions.'],
            'campaigns.manage' => ['marketing', 'Manage campaigns', 'Create and edit campaigns.'],
            'coupons.manage' => ['marketing', 'Manage coupons', 'Create, edit and toggle coupons.'],
            'flash_sales.manage' => ['marketing', 'Manage flash sales', 'Create, edit and toggle flash sales.'],
            'banners.manage' => ['marketing', 'Manage banners & opening ads', 'Banners and opening ads.'],

            // Loyalty
            'loyalty.view' => ['loyalty', 'View loyalty', 'Points, transactions, rewards and redemptions.'],
            'loyalty.manage' => ['loyalty', 'Manage redemptions', 'Redeem order points, redeem rewards for a customer, fulfil and cancel redemptions.'],
            'loyalty.adjust_points' => ['loyalty', 'Adjust points', 'Add or remove points by hand (always with a reason).'],
            'loyalty.manage_rewards' => ['loyalty', 'Manage rewards', 'Create, edit, activate and delete rewards.'],
            'loyalty.settings' => ['loyalty', 'Loyalty settings', 'Earning, expiry and redemption rules.'],
            'loyalty_rewards.import' => ['loyalty', 'Import rewards', 'Create or update rewards from a file.'],
            'loyalty_rewards.export' => ['loyalty', 'Export rewards', 'Download the rewards catalog.'],
            'loyalty_transactions.export' => ['loyalty', 'Export point transactions', 'Download the points ledger.'],

            // Reports
            'reports.view' => ['reports', 'View reports', 'Sales, returns, coupons and loyalty reports.'],

            // Health
            'health_data.view' => ['health_data', 'View customer health records', 'Personal health measurements (every view is audited).'],
            'health_content.view' => ['health_content', 'View health content', 'Vaccination schedule and pregnancy weeks.'],
            'health_content.manage' => ['health_content', 'Manage health content', 'Edit the vaccination schedule and pregnancy weeks.'],

            // Settings
            'settings.view' => ['settings', 'View settings', 'Store settings.'],
            'settings.update' => ['settings', 'Change settings', 'Contact details, legal texts and store policies.'],

            // Admins & roles
            'admins.view' => ['admins', 'View admins', 'Dashboard accounts and their roles.'],
            'admins.create' => ['admins', 'Create admins', 'Add dashboard accounts.'],
            'admins.update' => ['admins', 'Edit admins', 'Edit, activate and deactivate dashboard accounts.'],
            'roles.view' => ['admins', 'View roles', 'Roles and the permission matrix.'],
            'roles.manage' => ['admins', 'Manage roles', 'Create, edit, duplicate, activate and delete roles and their permissions.'],
            'roles.assign' => ['admins', 'Assign roles', 'Change which role a dashboard account has.'],
            'audit_log.view' => ['admins', 'View audit log', 'Every sensitive admin action.'],
        ];
    }

    /** Import/export defaults of the system roles (everything else comes from their old modules). */
    private const IMPORT_EXPORT_DEFAULTS = [
        'manager' => [
            'products.import', 'products.export', 'categories.import', 'categories.export', 'brands.import', 'brands.export',
            'inventory.import', 'inventory.export', 'suppliers.import', 'suppliers.export', 'locations.import', 'locations.export',
            'loyalty_rewards.import', 'loyalty_rewards.export', 'orders.export',
        ],
        'warehouse_manager' => ['inventory.import', 'inventory.export', 'products.export', 'suppliers.import', 'suppliers.export'],
        'content_editor' => ['products.export', 'categories.import', 'categories.export', 'brands.import', 'brands.export'],
        'accountant' => ['orders.export', 'payments.export', 'refunds.export', 'loyalty_transactions.export', 'suppliers.export'],
    ];

    public static function slugs(): array
    {
        return array_keys(self::all());
    }

    public static function exists(string $slug): bool
    {
        return array_key_exists($slug, self::all());
    }

    public static function moduleLabel(string $module): string
    {
        return self::MODULES[$module][0] ?? ucfirst(str_replace('_', ' ', $module));
    }

    /**
     * What a system role starts with: every permission of the modules its old definition had
     * (so nobody loses access when roles moved to the database), minus import/export, plus its
     * import/export defaults.
     *
     * @return list<string>
     */
    public static function defaultsFor(AdminRole $role): array
    {
        $modules = $role->permissions();

        $slugs = array_keys(array_filter(self::all(), function (array $definition, string $slug) use ($modules) {
            return in_array($definition[0], $modules, true) && !self::isDataTransfer($slug);
        }, ARRAY_FILTER_USE_BOTH));

        return array_values(array_unique([...$slugs, ...(self::IMPORT_EXPORT_DEFAULTS[$role->value] ?? [])]));
    }

    public static function isDataTransfer(string $slug): bool
    {
        return in_array(substr($slug, strrpos($slug, '.') + 1), self::DATA_TRANSFER, true);
    }
}
