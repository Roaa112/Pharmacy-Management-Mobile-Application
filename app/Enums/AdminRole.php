<?php

namespace App\Enums;

/**
 * The system roles that existed before roles moved to the database, and what each one could do
 * then (by module). RbacSynchronizer seeds them into `roles` with the equivalent fine-grained
 * permissions (PermissionRegistry::defaultsFor), so existing admins keep their access. From then
 * on roles and their permissions are edited from the dashboard: this enum is the starting point
 * only, never consulted for authorization.
 */
enum AdminRole: string
{
    case SuperAdmin = 'super_admin';
    case Manager = 'manager';
    case OrdersAgent = 'orders_agent';
    case HealthSpecialist = 'health_specialist';
    case ContentEditor = 'content_editor';
    case Accountant = 'accountant';
    case WarehouseManager = 'warehouse_manager';

    /** @return list<string> the module permissions (App\Enums\Permission) the role had */
    public function permissions(): array
    {
        return match ($this) {
            self::SuperAdmin => Permission::values(),
            // Everything except reading customers' health records and managing admins.
            self::Manager => array_values(array_diff(Permission::values(), [Permission::HealthData->value, Permission::Admins->value])),
            self::OrdersAgent => [Permission::Orders->value, Permission::Delivery->value, Permission::Users->value, Permission::Catalog->value],
            self::HealthSpecialist => [Permission::HealthData->value, Permission::HealthContent->value],
            self::ContentEditor => [Permission::Catalog->value, Permission::Marketing->value, Permission::HealthContent->value],
            self::Accountant => [Permission::Orders->value, Permission::Refunds->value, Permission::Payments->value, Permission::Reports->value, Permission::Loyalty->value, Permission::Purchasing->value],
            // Stock and procurement; sees orders to pick and pack them.
            self::WarehouseManager => [Permission::Inventory->value, Permission::Purchasing->value, Permission::Catalog->value, Permission::Orders->value],
        };
    }

    public function allows(string $permission): bool
    {
        return in_array($permission, $this->permissions(), true);
    }

    public function label(): string
    {
        return ucwords(str_replace('_', ' ', $this->value));
    }

    public function description(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Full access, including admins, roles and health records.',
            self::Manager => "Runs the store: everything except customers' health records and admin accounts.",
            self::OrdersAgent => 'Orders, delivery and customers.',
            self::HealthSpecialist => "Customers' health records and health content.",
            self::ContentEditor => 'Catalog, marketing and health content.',
            self::Accountant => 'Orders, refunds, payments, reports, loyalty and purchasing.',
            self::WarehouseManager => 'Stock, warehouses and purchasing.',
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
