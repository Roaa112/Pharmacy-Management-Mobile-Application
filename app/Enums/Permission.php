<?php

namespace App\Enums;

/** Dashboard permissions; each is registered as a Gate ability with the same name. */
enum Permission: string
{
    case Orders = 'orders';
    case Refunds = 'refunds';
    case Users = 'users';
    case HealthData = 'health_data';
    case HealthContent = 'health_content';
    case Settings = 'settings';
    case Delivery = 'delivery';
    case Payments = 'payments';
    case Reports = 'reports';
    case Catalog = 'catalog';
    case Marketing = 'marketing';
    case Loyalty = 'loyalty';
    case Admins = 'admins';
    // Stock: warehouses, adjustments, damage, inspections, transfers, counts.
    case Inventory = 'inventory';
    // Suppliers, purchase orders, receiving, supplier invoices, payments and returns.
    case Purchasing = 'purchasing';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
