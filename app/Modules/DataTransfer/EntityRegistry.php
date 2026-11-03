<?php

namespace App\Modules\DataTransfer;

use App\Models\Admin;
use App\Modules\DataTransfer\Entities;
use App\Modules\DataTransfer\Entities\DataEntity;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * The entities the import & export centre offers (docs/import-export.md explains each decision).
 * Deliberately absent: admins, roles and permissions (security boundary, managed on their screens),
 * audit logs, health records, sessions, tokens, jobs, cache, migrations and other framework tables.
 */
final class EntityRegistry
{
    private const ENTITIES = [
        Entities\ProductEntity::class,
        Entities\CategoryEntity::class,
        Entities\BrandEntity::class,
        Entities\InventoryEntity::class,
        Entities\SupplierEntity::class,
        Entities\CityEntity::class,
        Entities\AreaEntity::class,
        Entities\RewardEntity::class,
        Entities\CustomerEntity::class,
        Entities\OrderEntity::class,
        Entities\OrderItemEntity::class,
        Entities\PaymentEntity::class,
        Entities\RefundEntity::class,
        Entities\LoyaltyTransactionEntity::class,
    ];

    /** The dashboard page each entity is imported and exported from (route name). */
    public const PAGES = [
        'products' => 'dashboard.products.index',
        'categories' => 'dashboard.categories.index',
        'brands' => 'dashboard.brands.index',
        'inventory' => 'dashboard.inventory.index',
        'suppliers' => 'dashboard.suppliers.index',
        'cities' => 'dashboard.cities.index',
        'areas' => 'dashboard.cities.index',
        'loyalty_rewards' => 'dashboard.loyalty.rewards.index',
        'customers' => 'dashboard.customers.index',
        'orders' => 'dashboard.orders.index',
        'order_items' => 'dashboard.orders.index',
        'payments' => 'dashboard.payments.index',
        'refunds' => 'dashboard.refunds.index',
        'loyalty_transactions' => 'dashboard.loyalty.transactions',
    ];

    public static function pageUrl(string $key): string
    {
        return route(self::PAGES[$key] ?? 'dashboard.home');
    }

    /** May $admin import or export this entity at all (history and run pages). */
    public static function visibleTo(Admin $admin, DataEntity $entity): bool
    {
        return ($entity->canImport() && $admin->hasPermission($entity->importPermission()))
            || ($entity->canExport() && $admin->hasPermission($entity->exportPermission()));
    }

    /** @return array<string, DataEntity> */
    public static function all(): array
    {
        $entities = [];
        foreach (self::ENTITIES as $class) {
            $entity = app($class);
            $entities[$entity->key()] = $entity;
        }

        return $entities;
    }

    public static function find(string $key): ?DataEntity
    {
        return self::all()[$key] ?? null;
    }

    /** The entity, or 404. */
    public static function get(string $key): DataEntity
    {
        return self::find($key) ?? abort(404);
    }

    /** @return array<string, DataEntity> entities $admin may import */
    public static function importable(Admin $admin): array
    {
        return array_filter(self::all(), fn (DataEntity $e) => $e->canImport() && $admin->hasPermission($e->importPermission()));
    }

    /** @return array<string, DataEntity> entities $admin may export */
    public static function exportable(Admin $admin): array
    {
        return array_filter(self::all(), fn (DataEntity $e) => $e->canExport() && $admin->hasPermission($e->exportPermission()));
    }

    /** Server-side check: 403 unless $admin holds the entity's import (or export) permission. */
    public static function authorize(Admin $admin, DataEntity $entity, string $action): void
    {
        $permission = $action === 'import' ? $entity->importPermission() : $entity->exportPermission();

        if ($permission === null || !$admin->hasPermission($permission)) {
            throw new HttpResponseException(response('This action is unauthorized.', 403));
        }
    }
}
