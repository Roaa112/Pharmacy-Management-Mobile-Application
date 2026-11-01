<?php

namespace App\Enums;

/**
 * Admin roles and what each may do. Roles are protected code (a security boundary); which admin
 * has which role is managed from the dashboard by an admin with the "admins" permission.
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

    /** @return list<string> */
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

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
